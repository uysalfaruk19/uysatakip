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
                // fable-057 (Ömer: "tatil günlerinde yemek yemiyor"): RESMİ TATİLDE hafta içi
                // fatura kişisi kuralı uygulanmaz — o gün girilen sayı neyse fatura ondan.
                $fk = ($c['fatura_kisi_haftaici'] !== null && !$repo->tatilMi($date))
                    ? (int) $c['fatura_kisi_haftaici'] : null;
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
                                // fable-057: kopyalanan gün resmi tatilse fatura kişisi kuralı geçmez
                                $gunFk = $repo->tatilMi($gun) ? null : $fk;
                                $repo->saveDayMeals((int) $r['customer_id'], $gun, $meals, $price, 'uysa', $gunFk);
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

        // ── fable-059 (Ömer: "15 temmuzdaki sayısı ona göre gireyim hangi firmaya istiyorsam") ──
        // Firma kırılımına ELLE giriş. Üretim kaydı BİTTİKTEN sonra çalışır: hedef (günün fatura
        // kişisi) o anki kayıttan doğar → aynı POST'ta hem sayı hem kırılım değişse bile ikisi
        // tutarlı olur. Toplam hedefe eşit değilse Repo REDDEDER (sessiz yanlış kayıt yok).
        $altCid = (int) ($_POST['altfirma_cid'] ?? 0);
        if ($flashOk && $altCid > 0) {
            $gelen = [];
            if (empty($_POST['altfirma_oto'])) { // "Otomatiğe dön" → boş dizi = satırları sil
                foreach ((array) ($_POST['altfirma'] ?? []) as $kod => $v) {
                    $gelen[(string) $kod] = Repo::normalizePersons(is_scalar($v) ? $v : 0);
                }
            }
            try {
                $repo->saveGunAltFirma($altCid, $date, $gelen);
                uysa_audit('altfirma_kirilim', $u['username'], $date, json_encode([
                    'cid' => $altCid, 'kirilim' => $gelen,
                ], JSON_UNESCAPED_UNICODE), client_ip());
                $flash .= array_sum($gelen) > 0
                    ? ' · Firma kırılımı elle kaydedildi.'
                    : ' · Firma kırılımı otomatiğe (desene) döndü.';
            } catch (\InvalidArgumentException $e) {
                $flash .= ' · FİRMA KIRILIMI KAYDEDİLMEDİ: ' . $e->getMessage();
                $flashOk = false;
            } catch (\Throwable $e) {
                error_log('[UYSA v2 bugun altfirma] ' . $e->getMessage());
                $flash .= ' · Firma kırılımı kaydedilemedi (kayıt hatası).';
                $flashOk = false;
            }
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
// fable-057 (ekran ayağı): resmi tatilde hafta içi FATURA KİŞİSİ kuralı uygulanmaz — kayıt
// tarafı bunu zaten yapıyordu (POST), ekran hâlâ 70 kişiden ciro/kırılım gösteriyordu.
// aksiyon-faz2: sapma uyarısı eşiği yüzde olarak ayardan gelir (zaman/eşik kuralları koda
// gömülmez). Varsayılan %30 — bu eşiğin altındaki fark normal dalgalanma sayılır.
$sapmaEsigi = max(0.05, $repo->ayarNum('bugun_sapma_esigi_yuzde', 30.0) / 100);
$tatilGunu = $repo->tatilMi($date);
// aksiyon-faz2: kişi başı TOPLAM maliyet (gıda + personel + diğer), ay başından bugüne.
// Tahmini kâr bundan türer; hiçbiri hesaplanamıyorsa 0 kalır ve kâr satırı basılmaz.
$kbAy = substr($date, 0, 7);
$kisiBasiMaliyet = 0.0;
try {
    $kisiBasiMaliyet = (float) $repo->gidaCostOzet($kbAy)['kisi_basi']
        + (float) $repo->personelCostOzetUretim($kbAy)['kisi_basi']
        + (float) $repo->digerCostOzet($kbAy)['kisi_basi'];
} catch (\Throwable $e) {
    $kisiBasiMaliyet = 0.0;   // maliyet hesabı bu ekranı ASLA çökertmez
}
$tatilAd = '';
if ($tatilGunu) {
    $tl = $repo->resmiTatiller(true, $date, $date);
    $tatilAd = $tl ? (string) $tl[0]['ad'] : '';
}
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
    $billVal = Repo::faturaKisiToplam($val, $tatilGunu ? null : $fkRow, $date);
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
    // fable-051: ALT FİRMA bölüşümü — o günün fatura kişisine desen uygulanarak hesaplanır.
    // fable-059: o güne ELLE kırılım girildiyse desen değil O kayıt geçerlidir (istisna günler).
    // Alt firması tanımlı olmayan müşteride satır görünümü hiç değişmez.
    $altFirmalar = $repo->altFirmalar($cid);
    $elleKirilim = $altFirmalar ? $repo->gunAltFirmaKirilim($cid, $date) : [];
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
        $pay = $elleKirilim
            ? Repo::altFirmaElleDagit($billVal, $elleKirilim, $altFirmalar)
            : Repo::altFirmaGunDagit($billVal, $date, $altFirmalar);
        foreach ($altFirmalar as $af) {
            if (($pay[$af['kod']] ?? 0) > 0) {
                $parts[] = $pay[$af['kod']] . ' ' . $af['ad'];
            }
        }
        $altLabel = implode(' · ', $parts);
    }
    // aksiyon-faz2: ÖNERİ + SAPMA. Boş satıra sistemin bildiği sayı önerilir (son 4 haftanın
    // aynı günü); girilmiş satırda sayı ortalamadan eşik kadar saparsa TEK satır uyarı çıkar.
    // Sessizlik kuralı: her şey normalse hiçbir rozet/uyarı basılmaz.
    $on = $repo->onerilenKisi($cid, $date);
    $oneri = null;
    $sapma = null;
    // Resmî tatilde sayı meşru olarak düşer (fable-057: hafta içi fatura kişisi kuralı da
    // uygulanmaz) — o gün sapma uyarısı YANLIŞ POZİTİF olur, hiç basılmaz. Öneri de anlamsız.
    if ($on !== null && !$tatilGunu) {
        if ($val === 0) {
            $oneri = $on['oneri'];
        } elseif ($on['ortalama'] > 0) {
            $fark = ($val - $on['ortalama']) / $on['ortalama'];
            if (abs($fark) >= $sapmaEsigi) {
                $sapma = ['yuzde' => $fark * 100, 'ortalama' => $on['ortalama']];
            }
        }
    }
    $rowsData[] = [
        'cid' => $cid, 'name' => $r['name'], 'price' => $price, 'val' => $val, 'amt' => $amt,
        'meals' => $meals, 'split' => $splitLabel, 'fk' => $fkRow, // fable-040: fatura kişi kuralı
        'alt' => $altLabel, 'altfirma' => $altFirmalar,            // fable-051: gün kırılımı
        'elle' => $elleKirilim,                                    // fable-059: elle giriş kaydı
        'oneri' => $oneri, 'sapma' => $sapma,                      // aksiyon-faz2
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

      <!-- aksiyon-faz10 (Ömer: "değişiklik çok az"): YERLEŞİM yeniden kuruldu, katman
           eklemek yetmedi. Artık ekran ÜÇ BLOK: (1) tek büyük ciro + yanında tahmini kâr,
           (2) akıllı durum + tek eylem, (3) sade müşteri listesi. Kart başlığı, ilerleme
           çubuğu ve 3 mini stat KALDIRILDI — aynı bilgi tek ince satırda ve akıllı durum
           satırında zaten var; ekranın yarısını kaplamalarının karşılığı yoktu. -->
      <div class="cardx card-pad gt-nabiz-sm gt-nabiz-sade">
        <div class="gt-pulse gt-pulse-yan">
          <div class="gt-pulse-sol">
          <?php // aksiyon-faz10: bu ekranda ciro KURUŞSUZ — kuruş, büyük punto rakamı ikinci
                // satıra kırıyordu. Hesap değişmedi, yalnız gösterim yuvarlanıyor. ?>
          <div class="gt-pulse-n">₺<span id="sum-amount" data-tamsayi="1"><?= number_format(round($sumA), 0, ',', '.') ?></span></div>
          <div class="gt-pulse-l"><?= $date === Helpers::today() ? 'bugünkü ciro' : Helpers::e(date('d.m', strtotime($date))) . ' cirosu' ?></div>
          </div>
          <div class="gt-pulse-sag"><?php
            // aksiyon-faz2: TAHMİNİ KÂR — ciro − (gerçek kişi × ay başından bugüne kişi başı
            // maliyet). "tahmini" etiketi ZORUNLU: gider faturaları geç geldiği için ay ortasında
            // maliyet düşük, kâr şişkin görünür (bilinen davranış, bug değil). Maliyet
            // hesaplanamıyorsa (ay başı, gider yok) satır HİÇ basılmaz — sıfır kâr yazmak yalan olur.
            if ($kisiBasiMaliyet > 0 && $sumP > 0):
              $tahminiKar = $sumA - ($sumP * $kisiBasiMaliyet);
              $marj = $sumA > 0 ? ($tahminiKar / $sumA) * 100 : 0; ?>
            <span class="gt-tahmin">tahmini kâr<b>₺<?= Helpers::money($tahminiKar) ?> · %<?= number_format($marj, 0, ',', '.') ?></b><small>ay başından bugüne maliyetle</small></span>
          <?php endif; ?></div>
        </div>
        <?php // Üç mini stat kartı yerine tek ince satır — aynı üç rakam, ekranın onda biri kadar yer.
              // "girildi" sayacı JS'in güncellediği #sum-filled ile aynı düğüm (recalc bozulmasın). ?>
        <p class="gt-sade-satir">
          <span id="sum-persons"><?= number_format($sumP, 0, ',', '.') ?></span> kişi
          · <b id="sum-filled"><?= $filled ?>/<?= $total ?> girildi</b>
          <?php if ($irsaliyeGirilen > 0): ?> · <?= $irsaliyeKesildi ?>/<?= $irsaliyeGirilen ?> irsaliye<?php endif; ?>
        </p>
      </div>

      <?php // fable-040: günlük ciro nabzı fatura kişisinden — kural yalnız hafta içi (Pzt–Cum) uygulanır ?>
      <script>window.BUGUN_HAFTA_ICI = <?= ((int) date('N', strtotime($date)) <= 5) ? 'true' : 'false' ?>;
        // fable-057/059: resmi tatilde hafta içi fatura kişisi kuralı UYGULANMAZ → o gün hedef
        // girilen sayının kendisidir (elle firma kırılımı da bu hedefi bölmek zorunda).
        window.BUGUN_TATIL = <?= $tatilGunu ? 'true' : 'false' ?>;</script>
      <?php // aksiyon-faz4: GÜN ŞERİDİ — aynı günün üç görünümü tek yerde. Mutfak ve Sevkiyat
            // (+) menüsünde "yeni kayıt" işlerinin arasında duruyordu; ikisi de GÜNÜN işi. ?>
      <nav class="gun-serit" aria-label="Gün görünümleri">
        <a class="active" href="bugun.php?date=<?= Helpers::e($date) ?>">Giriş</a>
        <a href="mutfak.php?date=<?= Helpers::e($date) ?>">Mutfak</a>
        <a href="sevkiyat.php?date=<?= Helpers::e($date) ?>">Sevkiyat</a>
      </nav>

      <?php
      // aksiyon-faz2: AKILLI DURUM SATIRI — ekranın "ne bekliyor + tek dokunuş" katı.
      // Sessizlik kuralı: bekleyen yoksa satır hiç basılmaz.
      $bekleyenSayi = 0;
      $onerilebilir = 0;
      $fiyatsizBekleyen = 0;   // önerisi var ama fiyatı yok → sebebi DOĞRU söylenmeli
      foreach ($rowsData as $rd) {
          if ($rd['val'] === 0) {
              $bekleyenSayi++;
              if ($rd['oneri'] === null) {
                  continue;
              }
              if ($rd['price'] > 0) {
                  $onerilebilir++;
              } else {
                  $fiyatsizBekleyen++;
              }
          }
      }
      // Kesim: o günün siparişi bir gün önce 16:00'da kilitlenir. Geçmişse geri sayım gösterilmez
      // (geçmiş bir eşiği saymak kullanıcıya yalan söyler).
      $kesimKalan = '';
      $kalanSn = Helpers::orderDeadline($date) - time();
      if ($kalanSn > 0) {
          $sa = intdiv($kalanSn, 3600);
          $dk = intdiv($kalanSn % 3600, 60);
          $kesimKalan = $sa > 0 ? ('kesime ' . $sa . 'sa ' . $dk . 'dk') : ('kesime ' . $dk . 'dk');
      }
      ?>
      <?php if ($bekleyenSayi > 0): ?>
      <div class="cardx card-pad akilli-durum">
        <div class="ad-metin">
          <b><?= $bekleyenSayi ?> müşteri bekliyor</b><?= $kesimKalan !== '' ? ' · ' . Helpers::e($kesimKalan) : '' ?>
          <?php if ($onerilebilir === 0): ?>
          <span class="ad-not"><?= $fiyatsizBekleyen > 0
              ? ($fiyatsizBekleyen . ' müşterinin aylık fiyatı girilmemiş — önce fiyat')
              : 'geçmiş veri yetersiz — öneri yok' ?></span>
          <?php endif; ?>
        </div>
        <?php if ($onerilebilir > 0): ?>
        <button type="button" class="btn-action btn-primaryx" id="oneri-toplu">Önerilenleri onayla</button>
        <?php endif; ?>
      </div>
      <?php endif; ?>

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
            // fable-059: o güne ELLE girilmiş kırılım (kod => kişi). Boşsa öznitelik basılmaz →
            // pencere "otomatik (desen)" rozetiyle açılır.
            $elleAttr = $r['elle'] ? htmlspecialchars(json_encode($r['elle'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) : '';
          ?>
            <div class="customer-row <?= $missing ? 'missing' : '' ?> <?= $isFocus ? 'is-focus' : '' ?>"<?= $isFocus ? ' id="focus-row"' : '' ?> data-price="<?= $r['price'] ?>" data-cid="<?= $r['cid'] ?>" data-name="<?= Helpers::e($r['name']) ?>" data-base="<?= $base ?>" data-fatura-kisi="<?= $r['fk'] !== null ? (int) $r['fk'] : '' ?>"<?= $altAttr !== '' ? ' data-altfirma="' . $altAttr . '"' : '' ?><?= $elleAttr !== '' ? ' data-altfirma-elle="' . $elleAttr . '"' : '' ?>>
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
                <p class="row-meta"><span class="row-amt"><?= $missing ? ($r['oneri'] !== null ? 'önerilen ' . (int) $r['oneri'] : 'girilmedi') : '' ?></span><?php
                  // aksiyon-faz2: tek dokunuşluk onay, önerinin hemen yanında. Fiyatı olmayan
                  // müşteride PASİF — sayı girilse de ciro 0 çıkardı; sebebi buton başlığında.
                  if ($missing && $r['oneri'] !== null): $fiyatsiz = $r['price'] <= 0; ?><button type="button" class="oneri-onay" data-oneri-onay="<?= (int) $r['oneri'] ?>"<?= $fiyatsiz ? ' disabled aria-disabled="true" title="Aylık fiyatı girilmemiş — önce fiyat girilmeli"' : ' aria-label="Öneriyi onayla"' ?>><i class="bi bi-check-lg" aria-hidden="true"></i> onayla</button><?php endif; ?><?php
                  // aksiyon-faz2: sapma uyarısı — YALNIZ sapan satırda. "%32 düşük — son 4 hafta ortalaması 85"
                  if ($r['sapma'] !== null): ?><span class="oneri-sapma">%<?= number_format(abs($r['sapma']['yuzde']), 0, ',', '.') ?> <?= $r['sapma']['yuzde'] < 0 ? 'düşük' : 'yüksek' ?> — son 4 hafta ortalaması <?= (int) round($r['sapma']['ortalama']) ?></span><?php endif; ?><span class="meal-split"<?= $r['split'] === '' ? ' hidden' : '' ?>><?= Helpers::e($r['split']) ?></span><?php if ($r['altfirma']): ?><span class="alt-split<?= $r['elle'] ? ' is-elle' : '' ?>"<?= $r['alt'] === '' ? ' hidden' : '' ?> title="<?= $r['elle'] ? 'Firma kırılımı bu güne ELLE girildi — değiştirmek için müşteri adına dokun' : 'Fatura bölüşümü (firma deseni) — elle girmek için müşteri adına dokun' ?>"><?= Helpers::e($r['alt']) ?></span><?php endif; ?></p>
              </div>
              <div class="counter">
                <button class="step-btn" type="button" data-step="-5">−</button>
                <input class="count-input" inputmode="numeric" type="number" min="0" name="persons[<?= $r['cid'] ?>]" value="<?= $r['val'] > 0 ? $r['val'] : '' ?>"<?= $r['oneri'] !== null ? ' placeholder="' . (int) $r['oneri'] . '" data-oneri="' . (int) $r['oneri'] . '"' : '' ?>>
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
          <?php // fable-059 (Ömer): pencere artık DÜZENLENEBİLİR — istisna günlerde (resmi tatil,
                // özel iş) hangi şirkete kaç kişi yazılacağını Ömer elle girer. Toplam o günün
                // FATURA kişisine eşit olmadan Kaydet açılmaz (ay sonu 3 ayrı e-Fatura). ?>
          <div class="firm-split" id="meal-modal-firms" hidden>
            <div class="firm-split-head">
              <span class="firm-badge" id="firm-badge">otomatik (desen)</span>
            </div>
            <?php if ($tatilGunu): ?>
              <?php // Hedefin neden 70 değil 36 olduğu pencerede yazsın (fable-057 kuralı) ?>
              <p class="firm-tatil"><i class="bi bi-calendar-x"></i> Bu gün <strong>resmi tatil</strong><?= $tatilAd !== '' ? ' (' . Helpers::e($tatilAd) . ')' : '' ?> — hafta içi fatura kişisi kuralı uygulanmaz; hedef, güne girilen sayının kendisidir.</p>
            <?php endif; ?>
            <div class="firm-split-list" id="meal-modal-firmlist"></div>
            <p class="firm-total" id="firm-total">Toplam: <strong>0</strong> / hedef <strong>0</strong> kişi</p>
            <p class="firm-warn" id="firm-warn" hidden></p>
            <p class="firm-hint" id="firm-hint">Boş bırakırsan dağılım firma desenine göre otomatik
              hesaplanır (hafta içi sabit paylar, kalan varsayılan firmaya; hafta sonu tamamı
              varsayılana). İstisna günlerde sayıları elle gir — faturada bu rakamlar kullanılır.</p>
            <button type="button" class="firm-oto" id="firm-oto" hidden>
              <i class="bi bi-arrow-counterclockwise"></i> Otomatiğe dön (deseni kullan)</button>
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
