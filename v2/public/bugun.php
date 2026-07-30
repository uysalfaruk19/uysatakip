<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

$u = Auth::requireLogin();
$pdo = Db::pdo();
$repo = new Repo($pdo);

// fable-023a: ekran artık 3 öğünü (öğlen/akşam/kumanya) birlikte yönetiyor.
// $meal yalnız geriye dönük tekil çağrılarda (yok) değil, öğün etiketleri için kullanılır.
$mealLabels = ['ogle' => 'Öğlen', 'aksam' => 'Akşam', 'kumanya' => 'Kumanya'];
$date = (string) ($_GET['date'] ?? Helpers::today());
if (!Helpers::isDate($date)) {
    $date = Helpers::today();
}
// fable-022: rapor "Eksik · Gir" derin bağlantısı — gelinen müşteri satırı vurgulanır
$focus = (int) ($_GET['focus'] ?? 0);
$flash = '';
$flashOk = true;

// ── Kaydet ───────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $postDate = (string) ($_POST['date'] ?? $date);
        $date = Helpers::isDate($postDate) ? $postDate : Helpers::today();
        $persons = $_POST['persons'] ?? [];
        // fable-023a: kırılım penceresinden gelen öğün alanları + "elle düzenlendi" bayrağı.
        $postMeals = [
            'ogle' => (array) ($_POST['meal_ogle'] ?? []),
            'aksam' => (array) ($_POST['meal_aksam'] ?? []),
            'kumanya' => (array) ($_POST['meal_kumanya'] ?? []),
        ];
        $editedFlags = (array) ($_POST['meal_edited'] ?? []);
        // Toplam kutusu doğrudan değiştirilmişse fark öğlene yazılır → mevcut kırılım lazım.
        $before = [];
        foreach ($repo->dayGridAllMeals($date) as $r) {
            $before[(int) $r['customer_id']] = $r;
        }
        $saved = 0;
        $pdo->beginTransaction();
        try {
            foreach ($repo->activeCustomers() as $c) {
                $cid = (int) $c['id'];
                $cur = $before[$cid] ?? ['ogle' => 0, 'aksam' => 0, 'kumanya' => 0];
                if (!empty($editedFlags[$cid])) {
                    $meals = [
                        'ogle' => Repo::normalizePersons($postMeals['ogle'][$cid] ?? 0),
                        'aksam' => Repo::normalizePersons($postMeals['aksam'][$cid] ?? 0),
                        'kumanya' => Repo::normalizePersons($postMeals['kumanya'][$cid] ?? 0),
                    ];
                } else {
                    // fable-027c: gün için hiç kayıt yoksa taban = son kayıtlı günün kırılımı —
                    // yeni güne "58" yazınca 25/25/8 dağılımı korunur (PENDORYA tek-öğün dersi).
                    $curTop = (int) ($cur['ogle'] ?? 0) + (int) ($cur['aksam'] ?? 0) + (int) ($cur['kumanya'] ?? 0);
                    if ($curTop === 0) {
                        $son = $repo->lastKnownMeals($cid, $date);
                        if ($son !== null) {
                            $cur = $son;
                        }
                    }
                    $meals = Repo::mealsFromTotal($persons[$cid] ?? 0, $cur);
                }
                $toplam = $meals['ogle'] + $meals['aksam'] + $meals['kumanya'];
                // opus-017: girilen günün ayına ait fiyat (ay-bazlı; current default değil)
                $price = $toplam > 0 ? $repo->priceFor($cid, substr($date, 0, 7))['unit_price'] : 0.0;
                // Bağlı alanlar atomik: 3 öğün tek transaction içinde tek metotla yazılır/silinir.
                // fable-040: fatura kişi kuralı (hafta içi) → ciro fatura kişisinden (persons gerçek).
                $fk = $c['fatura_kisi_haftaici'] !== null ? (int) $c['fatura_kisi_haftaici'] : null;
                $repo->saveDayMeals($cid, $date, $meals, $price, 'uysa', $fk);
                if ($toplam > 0) {
                    $saved++;
                }
            }
            $pdo->commit();
            uysa_audit('uretim_kaydet', $u['username'], $date, json_encode(['n' => $saved]), client_ip());
            $flash = "Kaydedildi · $saved müşteri";

            // ── fable-027 (Ömer): "Haftaya kopyala" — bugünün sayıları haftanın KALAN hafta içi
            // günlerine yazılır (Salı–Cuma vb; sadece İLERİ günler — geçmiş gün asla ezilmez).
            // Önce yukarıdaki normal kayıt koşar: ekranda yazılı ama kaydedilmemiş sayı kaybolmaz.
            if (isset($_POST['hafta_kopyala'])) {
                $dow = (int) date('N', strtotime($date));
                $hedefler = [];
                for ($d = $dow + 1; $d <= 5; $d++) {
                    $hedefler[] = date('Y-m-d', strtotime($date . ' +' . ($d - $dow) . ' day'));
                }
                if (!$hedefler) {
                    $flash .= ' · Kopyalanacak hafta içi gün kalmadı.';
                } else {
                    $kaynak = $repo->dayGridAllMeals($date);
                    $pdo->beginTransaction();
                    try {
                        $nMusteri = 0;
                        foreach ($kaynak as $r) {
                            if ((int) $r['toplam'] <= 0) {
                                continue; // bugün sayısı olmayan müşteri ileri günlere yazılmaz/silinmez
                            }
                            $nMusteri++;
                            $meals = ['ogle' => (int) $r['ogle'], 'aksam' => (int) $r['aksam'], 'kumanya' => (int) $r['kumanya']];
                            $fk = $r['fatura_kisi'] ?? null; // fable-040: hedef günler hep hafta içi (Pzt–Cum)
                            foreach ($hedefler as $gun) {
                                $price = $repo->priceFor((int) $r['customer_id'], substr($gun, 0, 7))['unit_price'];
                                $repo->saveDayMeals((int) $r['customer_id'], $gun, $meals, $price, 'uysa', $fk);
                            }
                        }
                        $pdo->commit();
                        uysa_audit('uretim_hafta_kopyala', $u['username'], $date, json_encode([
                            'hedef' => $hedefler, 'n' => $nMusteri,
                        ], JSON_UNESCAPED_UNICODE), client_ip());
                        $gunTr = ['Tue' => 'Sal', 'Wed' => 'Çar', 'Thu' => 'Per', 'Fri' => 'Cum'];
                        $flash .= ' · ' . count($hedefler) . ' güne kopyalandı ('
                            . ($gunTr[date('D', strtotime($hedefler[0]))] ?? '') . '–'
                            . ($gunTr[date('D', strtotime(end($hedefler)))] ?? '') . ", $nMusteri müşteri).";
                    } catch (\Throwable $e) {
                        $pdo->rollBack();
                        $flash .= ' · Hafta kopyalama HATALI (bugün kaydedildi).';
                        $flashOk = false;
                    }
                }
            }
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $flash = 'Kayıt hatası.';
            $flashOk = false;
        }
    }
}

// ── Dünü kopyala ─────────────────────────────────────────────
// fable-026b (Ömer, 22 Tem): PAZARTESİ için kaynak CUMA'dır — pazar günü genelde sadece
// PENDORYA çalıştığı için "önceki gün" pazarı bulup diğer müşterileri boş getiriyordu.
// fable-027b (Ömer): CUMARTESİ/PAZAR için kaynak GEÇEN HAFTANIN AYNI GÜNÜ (cmt→cmt, paz→paz) —
// hafta sonu kadrosu hafta içinden farklı, cuma sayısını kopyalamak yanlış olurdu.
$dowHedef = (int) date('N', strtotime($date));
$hedefPzt = $dowHedef === 1;
$hedefHaftaSonu = $dowHedef >= 6;
$copyValues = null;
if (isset($_GET['copy'])) {
    $prev = null;
    $aday = null;
    if ($hedefPzt) {
        $aday = date('Y-m-d', strtotime($date . ' -3 day')); // cuma
    } elseif ($hedefHaftaSonu) {
        $aday = date('Y-m-d', strtotime($date . ' -7 day')); // geçen haftanın aynı günü
    }
    if ($aday !== null) {
        foreach ($repo->dayGridAllMeals($aday) as $r) {
            if ((int) $r['toplam'] > 0) { $prev = $aday; break; }
        }
    }
    // fable-023a: kaynak gün öğün farkı gözetmeden bulunur (sadece akşam kaydı olan gün de gelsin)
    $prev = $prev ?? $repo->previousProductionDate($date, null);
    if ($prev !== null) {
        $copyValues = [];
        foreach ($repo->dayGridAllMeals($prev) as $r) {
            $copyValues[(int) $r['customer_id']] = $r;
        }
        $flash = date('d.m.Y', strtotime($prev)) . " tarihinden kopyalandı — kontrol edip Kaydet'e basın.";
    } else {
        $flash = 'Kopyalanacak önceki gün yok.';
        $flashOk = false;
    }
}

// fable-023a: 3 öğünü toplayan tek kaynak — akşam/kumanya artık sayaçlara ve ciroya giriyor.
$grid = $repo->dayGridAllMeals($date);

// Sunucu-tarafı ilk render toplamları
$sumP = 0; $sumA = 0.0; $filled = 0;
$rowsData = [];
$priceMonth = substr($date, 0, 7);
foreach ($grid as $r) {
    $cid = (int) $r['customer_id'];
    $src = $copyValues[$cid] ?? $r;
    $meals = [
        'ogle' => (int) ($src['ogle'] ?? 0),
        'aksam' => (int) ($src['aksam'] ?? 0),
        'kumanya' => (int) ($src['kumanya'] ?? 0),
    ];
    $val = $meals['ogle'] + $meals['aksam'] + $meals['kumanya'];
    // opus-017: bu ayın fiyatı (ay-bazlı) — girilmiş satırlar zaten snapshot'lı, boşlar bu fiyatı gösterir
    $price = $repo->priceFor($cid, $priceMonth)['unit_price'];
    // fable-040: günlük ciro FATURA kişisinden (hafta içi kural varsa 70), toplam kişi GERÇEK (50).
    $fkRow = $r['fatura_kisi'] ?? null;
    $billVal = Repo::faturaKisiToplam($val, $fkRow, $date);
    $amt = $billVal * $price;
    if ($val > 0) { $sumP += $val; $sumA += $amt; $filled++; }
    // Kırılım etiketi yalnız akşam/kumanya varken görünür (tek öğünlü müşteride gürültü olmasın)
    $splitLabel = '';
    if ($meals['aksam'] > 0 || $meals['kumanya'] > 0) {
        $parts = [];
        foreach ($mealLabels as $mk => $ml) {
            if ($meals[$mk] > 0) {
                $parts[] = $meals[$mk] . ' ' . mb_strtolower($ml, 'UTF-8');
            }
        }
        $splitLabel = implode(' · ', $parts);
    }
    // fable-051: ALT FİRMA bölüşümü — SALT GÖSTERİM (giriş yok; düzenleme yeri Fatura Kes).
    // Kaydedilmez, o günün fatura kişisine desen uygulanarak ANLIK hesaplanır.
    // Alt firması tanımlı olmayan müşteride satır görünümü hiç değişmez.
    $altFirmalar = $repo->altFirmalar($cid);
    // Etiket dar satıra sığsın: alt firma adı müşteri adıyla başlıyorsa o ön ek atılır
    // ("CANTAŞ İç-Dış" → "İç-Dış"); "HC Isıtma" gibi bağımsız adlar aynen kalır.
    foreach ($altFirmalar as $i => $af) {
        $kisa = $af['ad'];
        if (mb_stripos($kisa, $r['name'], 0, 'UTF-8') === 0 && mb_strlen($kisa, 'UTF-8') > mb_strlen($r['name'], 'UTF-8') + 1) {
            $kisa = trim(mb_substr($kisa, mb_strlen($r['name'], 'UTF-8'), null, 'UTF-8'));
        }
        $altFirmalar[$i]['ad'] = $kisa;
    }
    $altLabel = '';
    if ($altFirmalar && $val > 0) {
        $parts = [];
        $pay = Repo::altFirmaGunDagit($billVal, $date, $altFirmalar);
        foreach ($altFirmalar as $af) {
            if (($pay[$af['kod']] ?? 0) > 0) {
                $parts[] = $pay[$af['kod']] . ' ' . $af['ad'];
            }
        }
        $altLabel = implode(' · ', $parts);
    }
    $rowsData[] = [
        'cid' => $cid, 'name' => $r['name'], 'price' => $price, 'val' => $val, 'amt' => $amt,
        'meals' => $meals, 'split' => $splitLabel, 'fk' => $fkRow, // fable-040: fatura kişi kuralı
        'alt' => $altLabel, 'altfirma' => $altFirmalar,            // fable-051: salt gösterim
    ];
}
$total = count($rowsData);

// fable-023b: İrsaliyelendir — o günün adayları (seçilemeyen de listelenir, SEBEBİYLE).
// Şalter kapalıyken de ekran çalışır; kesim yerine önizleme gösterilir (rozet uyarır).
$irsaliyeAdaylari = [];
$irsaliyeSecilebilir = 0;
$irsaliyeGirilen = 0;
// Canlı muhasebeye yazan işlem → yalnız yetkili kullanıcı görür (sunucuda da ayrı kapı var).
$irsaliyeYetkili = Auth::isAdmin($u);
try {
    $irsaliyeAdaylari = $repo->irsaliyeAdaylari($date);
    foreach ($irsaliyeAdaylari as $a) {
        if ($a['toplam'] > 0) {
            $irsaliyeGirilen++;
        }
        if ($a['secilebilir']) {
            $irsaliyeSecilebilir++;
        }
    }
} catch (\Throwable $e) {
    // migrate_031 henüz uygulanmadıysa (kolon/tablo yok) ana ekran ÇALIŞMAYA DEVAM eder;
    // yalnız İrsaliyelendir görünmez. "İşleyen düzeni bozma" kuralı.
    error_log('[UYSA v2 bugun] irsaliye adayları okunamadı: ' . $e->getMessage());
    $irsaliyeYetkili = false;
}
$irsaliyeAcik = \Uysa\ParasutYaz::aktif();

$prevDay = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDay = date('Y-m-d', strtotime($date . ' +1 day'));
$pendingCount = $repo->pendingOrdersCount();
$critCount = count($repo->criticalStock());
$supplyCount = $repo->openSupplyRequestsCount();
$openReqCount = $repo->openRequestsCount();

// fable-034b (denetim): anasayfa üst barı MARKALI — "UYSA Kokpit" + saat bazlı selamlama (mockup).
$pageTitle = 'UYSA Kokpit';
$homeBrand = true;
$active = 'bugun';

// İrsaliye kesildi sayacı (mockup NABIZ mini) — basit COUNT, ağır sorgu yok.
$irsaliyeKesildi = 0;
try {
    $stK = $pdo->prepare("SELECT COUNT(*) FROM parasut_irsaliye_log WHERE gun = ? AND durum = 'kesildi'");
    $stK->execute([$date]);
    $irsaliyeKesildi = (int) $stK->fetchColumn();
} catch (\Throwable $e) {
    // migrate_031 yoksa sessiz 0 (ana ekran çalışmaya devam)
}
require __DIR__ . '/partials/header.php';
?>
<?php
        // fable-034: GTO dili — tarih şeridi kart içinde (mockup birebir)
        $gunTr = ['Mon' => 'Pazartesi', 'Tue' => 'Salı', 'Wed' => 'Çarşamba', 'Thu' => 'Perşembe', 'Fri' => 'Cuma', 'Sat' => 'Cumartesi', 'Sun' => 'Pazar'];
        $ayTr = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
        $ts = strtotime($date);
        $trDate = (int) date('j', $ts) . ' ' . $ayTr[(int) date('n', $ts)] . ' ' . ($gunTr[date('D', $ts)] ?? '');
        $bekleyenSayi = max(0, $total - $filled);
        $progPct = $total > 0 ? (int) round($filled / $total * 100) : 0;
        ?>
      <div class="cardx card-pad">
        <div class="gt-date">
          <a class="nav" href="bugun.php?date=<?= $prevDay ?>" aria-label="Önceki gün">‹</a>
          <form method="get" class="dt" style="position:relative">
            <b><?= Helpers::e($trDate) ?></b>
            <span><?= $date === Helpers::today() ? 'bugün · ' : '' ?><?= $filled ?>/<?= $total ?> müşteri girildi</span>
            <input type="date" name="date" value="<?= Helpers::e($date) ?>" onchange="this.form.submit()"
                   aria-label="Tarih seç" style="position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer">
          </form>
          <a class="nav" href="bugun.php?date=<?= $nextDay ?>" aria-label="Sonraki gün">›</a>
        </div>
      </div>

      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <!-- fable-034: GÜNÜN NABZI (mockup birebir) — koca ciro + ilerleme + 3 mini stat
           fable-046: .gt-nabiz-sm = kompakt sürüm (Ömer: ekranın çok yerini kaplıyordu).
           Sınıf SADECE bu kartta; kar-analizi/finans nabız kartları değişmedi. -->
      <div class="cardx card-pad gt-nabiz-sm">
        <div class="gt-h"><i class="bi bi-broadcast"></i> GÜNÜN NABZI</div>
        <div class="gt-pulse">
          <div class="gt-pulse-n">₺<span id="sum-amount"><?= Helpers::money($sumA) ?></span></div>
          <div class="gt-pulse-l"><?= $date === Helpers::today() ? 'bugünkü ciro' : Helpers::e(date('d.m', strtotime($date))) . ' cirosu' ?></div>
        </div>
        <div class="gt-prog">
          <div class="gt-prog-top"><span>Sayı girişi</span><b id="sum-filled"><?= $filled ?>/<?= $total ?> girildi</b></div>
          <div class="gt-track"><div class="gt-fill" style="width: <?= $progPct ?>%"></div></div>
        </div>
        <div class="gt-mini">
          <div>
            <div class="gt-mn" id="sum-persons"><?= number_format($sumP, 0, ',', '.') ?></div>
            <div class="gt-ml">Toplam kişi</div>
          </div>
          <div>
            <div class="gt-mn<?= $irsaliyeKesildi > 0 ? ' ok' : '' ?>"><?= $irsaliyeKesildi ?>/<?= $irsaliyeGirilen ?></div>
            <div class="gt-ml">İrsaliye kesildi</div>
          </div>
          <div>
            <div class="gt-mn<?= $bekleyenSayi > 0 ? ' bad' : ' ok' ?>"><?= $bekleyenSayi ?></div>
            <div class="gt-ml">Bekleyen</div>
          </div>
        </div>
      </div>

      <?php // fable-040: günlük ciro nabzı fatura kişisinden — kural yalnız hafta içi (Pzt–Cum) uygulanır ?>
      <script>window.BUGUN_HAFTA_ICI = <?= ((int) date('N', strtotime($date)) <= 5) ? 'true' : 'false' ?>;</script>
      <?php // fable-034b (denetim): mockup sırası → MÜŞTERİ SAYILARI önce, HIZLI ERİŞİM sonra (aşağıda) ?>
      <form method="post" id="bugun-form">
        <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
        <input type="hidden" name="date" value="<?= Helpers::e($date) ?>">
        <div class="cardx card-pad" id="musteri-sayilari" style="scroll-margin-top: 14px">
          <!-- fable-023b: başlık + İrsaliyelendir (Paraşüt e-İrsaliye); sayı girilmemişse pasif -->
          <div class="head-row">
            <div class="gt-h" style="margin:0"><i class="bi bi-people-fill"></i> MÜŞTERİ SAYILARI</div>
            <?php if ($irsaliyeYetkili): ?>
            <button type="button" class="btn-chip" id="irs-open"<?= $irsaliyeGirilen === 0 ? ' disabled' : '' ?>
              aria-haspopup="dialog" title="<?= $irsaliyeGirilen === 0 ? 'Bugün için sayı girilmemiş' : 'Seçtiğin müşterilere Paraşüt\'ten irsaliye kes' ?>">
              <i class="bi bi-truck"></i> İrsaliyelendir
            </button>
            <?php endif; ?>
          </div>
          <?php if (!$rowsData): ?>
            <div class="empty-state">Aktif müşteri yok.</div>
          <?php endif; ?>
          <?php foreach ($rowsData as $r): $missing = $r['val'] === 0; $isFocus = $focus > 0 && $r['cid'] === $focus;
            // fable-027c: toplam kutusunun TABAN kırılımı — dolu günde günün kendi kırılımı,
            // boş günde son kayıtlı günün kırılımı. JS bunu SABİT taban olarak kullanır; tuş tuş
            // yazarken ara değerler ("5" → "58") kırılımı bozamaz. Sunucu kuralıyla aynı.
            $taban = $r['meals'];
            if ($missing) {
                $son = $repo->lastKnownMeals((int) $r['cid'], $date);
                if ($son !== null) {
                    $taban = $son;
                }
            }
            $base = htmlspecialchars(json_encode([
                'ogle' => (int) $taban['ogle'], 'aksam' => (int) $taban['aksam'], 'kumanya' => (int) $taban['kumanya'],
            ]), ENT_QUOTES);
            // fable-051: satır sayısı değişince alt firma etiketi de canlı güncellensin
            // (ekran yalan söylemesin). JS Repo::altFirmaGunDagit ile AYNI kuralı uygular.
            // Alt firması yoksa öznitelik hiç basılmaz → o satırlarda HİÇBİR ŞEY değişmez.
            $altAttr = $r['altfirma'] ? htmlspecialchars(json_encode(array_map(
                static fn(array $f): array => ['kod' => $f['kod'], 'ad' => $f['ad'],
                    'varsayilan' => $f['varsayilan'], 'sabit' => $f['haftaici_sabit']],
                $r['altfirma']
            ), JSON_UNESCAPED_UNICODE), ENT_QUOTES) : '';
          ?>
            <div class="customer-row <?= $missing ? 'missing' : '' ?> <?= $isFocus ? 'is-focus' : '' ?>"<?= $isFocus ? ' id="focus-row"' : '' ?> data-price="<?= $r['price'] ?>" data-cid="<?= $r['cid'] ?>" data-name="<?= Helpers::e($r['name']) ?>" data-base="<?= $base ?>" data-fatura-kisi="<?= $r['fk'] !== null ? (int) $r['fk'] : '' ?>"<?= $altAttr !== '' ? ' data-altfirma="' . $altAttr . '"' : '' ?>>
              <div class="gt-rank" aria-hidden="true"><?= Helpers::e(mb_strtoupper(mb_substr($r['name'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
              <div class="cr-firm">
                <div class="row-title"><span class="status-dot <?= $missing ? 'warn' : '' ?>" hidden></span>
                  <!-- fable-023a: müşteri adı = öğün kırılımı penceresini açan buton -->
                  <button type="button" class="row-name-btn" data-meal-open aria-haspopup="dialog">
                    <strong><?= Helpers::e($r['name']) ?></strong><i class="bi bi-sliders2" aria-hidden="true"></i>
                  </button>
                </div>
                <!-- fable-029b/030 (Ömer): bu ekranda PARA GÖRÜNMEZ (birim fiyat + gün tutarı kaldırıldı;
                     sayım sırasında ekran başkalarına açık olabiliyor). "girilmedi" uyarısı + kırılım kalır. -->
                <p class="row-meta"><span class="row-amt"><?= $missing ? 'girilmedi' : '' ?></span><span class="meal-split"<?= $r['split'] === '' ? ' hidden' : '' ?>><?= Helpers::e($r['split']) ?></span><?php if ($r['altfirma']): ?><span class="alt-split"<?= $r['alt'] === '' ? ' hidden' : '' ?> title="Fatura bölüşümü (salt gösterim) — düzenleme: Fatura Kes"><?= Helpers::e($r['alt']) ?></span><?php endif; ?></p>
              </div>
              <div class="counter">
                <button class="step-btn" type="button" data-step="-5">−</button>
                <input class="count-input" inputmode="numeric" type="number" min="0" name="persons[<?= $r['cid'] ?>]" value="<?= $r['val'] > 0 ? $r['val'] : '' ?>">
                <button class="step-btn" type="button" data-step="5">+</button>
              </div>
              <input type="hidden" class="m-ogle" name="meal_ogle[<?= $r['cid'] ?>]" value="<?= $r['meals']['ogle'] ?>">
              <input type="hidden" class="m-aksam" name="meal_aksam[<?= $r['cid'] ?>]" value="<?= $r['meals']['aksam'] ?>">
              <input type="hidden" class="m-kumanya" name="meal_kumanya[<?= $r['cid'] ?>]" value="<?= $r['meals']['kumanya'] ?>">
              <input type="hidden" class="m-edited" name="meal_edited[<?= $r['cid'] ?>]" value="<?= $copyValues !== null ? 1 : 0 ?>">
            </div>
          <?php endforeach; ?>
        </div>

        <?php
        // fable-027b: kopya butonu etiketi güne göre; "Haftaya kopyala" yalnız Pzt–Per görünür
        // (Cum/Cmt/Paz'da kopyalanacak ileri hafta içi gün yok — buton yer kaplamasın).
        $kopyaEtiket = $hedefPzt ? 'Cumayı kopyala'
            : ($dowHedef === 6 ? 'Geçen cumartesiyi kopyala'
            : ($dowHedef === 7 ? 'Geçen pazarı kopyala' : 'Dünü kopyala'));
        $haftaGoster = $dowHedef <= 4;
        ?>
        <div class="actions-row actions-uc mt-3"><!-- fable-027: 3 buton tek sıra, kompakt -->
          <a class="btn-action btn-secondaryx flex-fill" href="bugun.php?date=<?= Helpers::e($date) ?>&copy=1"><i class="bi bi-copy"></i> <?= $kopyaEtiket ?></a>
          <?php if ($haftaGoster): ?>
          <button class="btn-action btn-secondaryx flex-fill" type="submit" name="hafta_kopyala" value="1"
            onclick="return confirm('Bugünün sayıları önce kaydedilir, sonra bu haftanın KALAN hafta içi günlerine kopyalanır (mevcut kayıtların üzerine yazılır). Devam?')">
            <i class="bi bi-calendar2-week"></i> Haftaya kopyala</button>
          <?php endif; ?>
          <button class="btn-action btn-primaryx flex-fill" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
        </div>
      </form>

      <!-- fable-034b (denetim): HIZLI ERİŞİM — mockup KOMPAKT grid (ikon + tek satır), müşteri listesinin ALTINDA.
           fable-046 (Ömer): Müşteriler ÇIKTI (alt sekme çubuğunda zaten var — mükerrer), Finans GİRDİ
           ("Gider — Firma Karnesi" oradan açılıyor). Sıra: Personel, Finans, Sipariş & Talep, Menü, Fatura Kes.
           Fatura Kes canlı muhasebeye yazar → yalnız admin görür (finans.php ile aynı kapı). -->
      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-grid-3x3-gap-fill"></i> HIZLI ERİŞİM</div>
        <div class="gt-mods">
          <a class="gt-mod" href="personel.php"><i class="bi bi-person-badge"></i>Personel</a>
          <a class="gt-mod" href="finans.php"><i class="bi bi-wallet2"></i>Finans</a>
          <?php $stRozet = $pendingCount + $openReqCount; // Ömer: Siparişler+Talepler tek kutu ?>
          <a class="gt-mod" href="siparisler.php"><?php if ($stRozet > 0): ?><span class="gt-mod-dot"><?= $stRozet ?></span><?php endif; ?><i class="bi bi-basket"></i>Sipariş &amp; Talep</a>
          <a class="gt-mod" href="menu.php"><i class="bi bi-card-list"></i>Menü</a>
          <?php // fable-048a (Ömer): Fatura Kes hızlı erişimden çıktı → "+" menüsünde ?>
        </div>
      </div>

      <!-- fable-023a: öğün kırılımı penceresi — satırdaki gizli alanları doldurur, sonra formu gönderir -->
      <div class="meal-modal" id="meal-modal" hidden>
        <div class="meal-backdrop" data-meal-close></div>
        <div class="meal-card" role="dialog" aria-modal="true" aria-labelledby="meal-modal-title">
          <div class="meal-head">
            <h3 id="meal-modal-title">Öğün kırılımı</h3>
            <button type="button" class="icon-btn" data-meal-close aria-label="Kapat"><i class="bi bi-x-lg"></i></button>
          </div>
          <p class="meal-sub" id="meal-modal-name"></p>
          <?php // fable-056 (Ömer): "CANTAŞ ve Marmara'ya tıklayınca öğün kırılımı değil FİRMA
                // kırılımı açılsın." Alt firması olan müşteride bu bölüm üstte ve ana içerik olur;
                // öğün alanları altta kalır (CANTAŞ/Marmara tek öğün çalışıyor, ama yetenek durur). ?>
          <div class="firm-split" id="meal-modal-firms" hidden>
            <div class="firm-split-list" id="meal-modal-firmlist"></div>
            <p class="meal-hint">Dağılım firma desenine göre otomatik hesaplanır (hafta içi sabit
              paylar, kalan varsayılan firmaya; hafta sonu tamamı varsayılana). Toplam kişiyi
              değiştirdiğinde anında güncellenir. Faturada da bu rakamlar kullanılır.</p>
          </div>
          <div class="meal-fields">
            <?php foreach ($mealLabels as $mk => $ml): ?>
              <label class="meal-field">
                <span><?= Helpers::e($ml) ?></span>
                <input class="inputx meal-input" id="meal-in-<?= $mk ?>" data-meal="<?= $mk ?>" type="number" inputmode="numeric" min="0" value="0">
              </label>
            <?php endforeach; ?>
          </div>
          <p class="meal-total">Toplam: <strong id="meal-modal-total">0</strong> kişi</p>
          <p class="meal-hint">Toplam kutusu doğrudan değiştirilirse fark öğlene yazılır; akşam ve kumanya korunur.</p>
          <div class="actions-row">
            <button type="button" class="btn-action btn-secondaryx flex-fill" data-meal-close>Vazgeç</button>
            <button type="button" class="btn-action btn-primaryx flex-fill" id="meal-save">Kaydet</button>
          </div>
        </div>
      </div>
      <?php if ($irsaliyeYetkili): ?>
      <!-- fable-023b: İrsaliyelendir — seçim → onay → sonuç (tek pencere, üç adım).
           Form DIŞINDA: buradaki kutucuklar üretim formuyla birlikte POST edilmez. -->
      <div class="meal-modal" id="irs-modal" hidden>
        <div class="meal-backdrop" data-irs-close></div>
        <div class="meal-card irs-card" role="dialog" aria-modal="true" aria-labelledby="irs-modal-title">
          <div class="meal-head">
            <h3 id="irs-modal-title">İrsaliyelendir</h3>
            <button type="button" class="icon-btn" data-irs-close aria-label="Kapat"><i class="bi bi-x-lg"></i></button>
          </div>
          <p class="meal-sub"><?= Helpers::e(date('d.m.Y', strtotime($date))) ?> · Paraşüt e-İrsaliye</p>
          <?php if (!$irsaliyeAcik): ?>
            <p class="irs-badge"><i class="bi bi-lock"></i> Kesim kapalı — Ömer onayı bekleniyor. Bu ekran yalnız <strong>önizleme</strong> yapar, Paraşüt'e hiçbir şey gönderilmez.</p>
          <?php endif; ?>

          <!-- adım 1: seçim -->
          <div id="irs-step-secim">
            <?php if (!$irsaliyeAdaylari): ?>
              <div class="empty-state">Aktif müşteri yok.</div>
            <?php else: ?>
            <div class="irs-tools">
              <label class="irs-check"><input type="checkbox" id="irs-all"<?= $irsaliyeSecilebilir === 0 ? ' disabled' : '' ?>> <span>Tümünü seç / kaldır</span></label>
              <span class="badge-soft" id="irs-count">0 seçili</span>
            </div>
            <div class="irs-list">
              <?php foreach ($irsaliyeAdaylari as $a):
                  $parts = [];
                  foreach ($mealLabels as $mk => $ml) {
                      if ($a[$mk] > 0) {
                          $parts[] = $a[$mk] . ' ' . mb_strtolower($ml, 'UTF-8');
                      }
                  }
                  $kirilim = $parts ? implode(' · ', $parts) : 'sayı yok';
                  // fable-052: kesilmiş belgenin mail durumu (UYSA kuyruğu) satırda görünsün
                  $mailNot = ['sirada' => ' · mail sırada', 'gonderildi' => ' · mail gönderildi',
                      'hata' => ' · MAİL HATASI'][$a['mail'] ?? 'yok'] ?? '';
              ?>
                <label class="irs-row <?= $a['secilebilir'] ? '' : 'is-off' ?>">
                  <input type="checkbox" class="irs-pick" value="<?= (int) $a['customer_id'] ?>"<?= $a['secilebilir'] ? '' : ' disabled' ?>>
                  <span class="irs-name"><?= Helpers::e($a['name']) ?></span>
                  <span class="irs-meta"><?= Helpers::e($kirilim) ?></span>
                  <?php if (!$a['secilebilir']): ?>
                    <span class="irs-why"><?= Helpers::e($a['sebep']) ?><?= $a['despatch_no'] ? ' · ' . Helpers::e((string) $a['despatch_no']) : '' ?><?= Helpers::e($mailNot) ?></span>
                  <?php endif; ?>
                  <?php if ($a['durum'] === 'bilinmiyor'): ?>
                    <!-- Timeout kilidi çıkmaz sokak olmasın: Paraşüt'ten bakan insan kararını yazar -->
                    <span class="irs-fix">
                      <button type="button" class="btn-mini" data-irs-cozum="kesilmedi" data-cid="<?= (int) $a['customer_id'] ?>">Paraşüt'te YOK — kilidi aç</button>
                      <button type="button" class="btn-mini" data-irs-cozum="kesildi" data-cid="<?= (int) $a['customer_id'] ?>">Paraşüt'te VAR — kesildi işaretle</button>
                    </span>
                  <?php endif; ?>
                </label>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="actions-row mt-3">
              <button type="button" class="btn-action btn-secondaryx flex-fill" data-irs-close>Vazgeç</button>
              <button type="button" class="btn-action btn-primaryx flex-fill" id="irs-next" disabled>Tamam</button>
            </div>
          </div>

          <!-- adım 2: onay -->
          <div id="irs-step-onay" hidden>
            <div id="irs-ozet"></div>
            <p class="irs-warn"><i class="bi bi-exclamation-triangle"></i> Paraşüt'te <strong>resmi e-İrsaliye</strong> oluşur ve GİB'e gider. Bu işlem geri alınamaz.</p>
            <details class="irs-json"><summary>Paraşüt'e gidecek veri (kuru deneme)</summary><pre id="irs-govde"></pre></details>
            <div class="actions-row mt-3">
              <button type="button" class="btn-action btn-secondaryx flex-fill" id="irs-back">Geri</button>
              <button type="button" class="btn-action btn-primaryx flex-fill" id="irs-cut">İrsaliyeleri Kes</button>
            </div>
          </div>

          <!-- adım 3: sonuç -->
          <div id="irs-step-sonuc" hidden>
            <div id="irs-sonuc"></div>
            <div class="actions-row mt-3">
              <button type="button" class="btn-action btn-secondaryx flex-fill" id="irs-retry" hidden>Başarısızları tekrar dene</button>
              <button type="button" class="btn-action btn-primaryx flex-fill" data-irs-close>Kapat</button>
            </div>
          </div>
        </div>
      </div>
      <script>window.IRS = {csrf: <?= json_encode(Helpers::csrfToken()) ?>, date: <?= json_encode($date) ?>, kapali: <?= $irsaliyeAcik ? 'false' : 'true' ?>};</script>
      <script src="assets/irsaliye.js?v=<?= filemtime(__DIR__ . '/assets/irsaliye.js') ?>"></script>
      <?php endif; ?>

      <?php if ($focus > 0): ?>
      <script>
        (function () {
          var row = document.getElementById('focus-row');
          if (!row) return;
          row.scrollIntoView({ block: 'center' });
          var inp = row.querySelector('.count-input');
          if (inp) inp.focus();
        })();
      </script>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
