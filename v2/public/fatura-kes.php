<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\ParasutYaz;
use Uysa\Repo;

/**
 * fable-024 — "Fatura Kes": kesilen irsaliyelerden (haftalık) / üretimden (aylık) Paraşüt satış
 * faturası + e-Fatura. Hem sayfa (GET) hem JSON uç (POST: hazirla / kes / cozum).
 *
 * 🔒 İki kapı: (1) ana şalter PARASUT_FATURA_AKTIF — kapalıysa kesim ÇAĞRILMAZ, önizleme döner;
 * (2) ParasutYaz içindeki bağımsız şalter + tek-kullanımlık onay imzası (çift tık kalkanı).
 * Onaylanan PLAN session'da tutulur; 'kes' onu uygular (araya müşteri/tutar sıkıştırılamaz).
 */

$u = Auth::requireLogin();
$pdo = Db::pdo();
$repo = new Repo($pdo);
$isAdmin = Auth::isAdmin($u);

/** Aylık adayın (bölüşümlü/tekil) fatura parçalarını kur — contact id DAİMA sunucuda çözülür. */
$aylikParts = static function (array $a, array $dagilim) use ($repo): array {
    $parts = [];
    if ($a['bolusum'] !== null) {
        foreach ($a['bolusum'] as $b) {
            $parts[] = [
                'contact_id' => $b['contact_id'],
                'ad'         => $b['ad'],
                'kisi'       => Repo::normalizePersons($dagilim[$b['key']] ?? 0),
            ];
        }
    } else {
        $parts[] = ['contact_id' => $a['parasut_id'], 'ad' => $a['name'], 'kisi' => (int) $a['adet']];
    }
    return $parts;
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ══════════════ POST: JSON işlemleri ══════════════
if ($method === 'POST') {
    if (!$isAdmin) {
        Helpers::json(['ok' => false, 'error' => 'Bu işlem için yetkiniz yok.'], 403);
    }
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    if (!Helpers::csrfCheck(isset($body['csrf']) ? (string) $body['csrf'] : null)) {
        Helpers::json(['ok' => false, 'error' => 'Oturum doğrulaması başarısız.'], 403);
    }
    $action = (string) ($body['action'] ?? '');
    $bas = (string) ($body['bas'] ?? '');
    $son = (string) ($body['son'] ?? '');
    if (!Helpers::isDate($bas) || !Helpers::isDate($son) || $bas > $son) {
        Helpers::json(['ok' => false, 'error' => 'Geçersiz dönem.'], 400);
    }
    $kapali = !ParasutYaz::faturaAktif();

    $adaylar = [];
    foreach ($repo->faturaAdaylari($bas, $son) as $a) {
        $adaylar[(int) $a['customer_id']] = $a;
    }

    // ── HAZIRLA: seçimi doğrula + tutar/gövde göster + onay imzası üret ──
    if ($action === 'hazirla') {
        $secim = is_array($body['secim'] ?? null) ? $body['secim'] : [];
        $yaz = new ParasutYaz($repo);
        $satirlar = [];
        $plan = [];
        $genelNet = 0.0;
        foreach ($secim as $s) {
            $cid = (int) ($s['customer_id'] ?? 0);
            $a = $adaylar[$cid] ?? null;
            if ($a === null || !$a['secilebilir']) {
                $satirlar[] = ['customer_id' => $cid, 'name' => $a['name'] ?? ('#' . $cid), 'ok' => false,
                    'sebep' => $a['sebep'] ?? 'Müşteri listede yok.'];
                continue;
            }
            if ($a['tip'] === 'irsaliye') {
                $p = $yaz->faturaOnizleme($cid, $bas, $son);
                if (!$p['ok']) {
                    $satirlar[] = ['customer_id' => $cid, 'name' => $a['name'], 'ok' => false, 'sebep' => $p['mesaj']];
                    continue;
                }
                // fable-028: gün gün döküm — fatura KESİLEN İRSALİYELERDEN kesilir, tablo da
                // birebir o irsaliye loglarından kurulur (netleştirme: gördüğün = faturalanan).
                $gunler = [];
                foreach ($repo->faturaAdayIrsaliyeler($cid, $bas, $son) as $log) {
                    $g = ['gun' => (string) $log['gun'], 'irsaliye' => (string) ($log['despatch_no'] ?? ''),
                        'ogle' => 0, 'aksam' => 0, 'kumanya' => 0, 'toplam' => (int) $log['toplam_kisi']];
                    foreach ((array) json_decode((string) ($log['kalemler'] ?? '[]'), true) as $kk) {
                        $og = (string) ($kk['ogun'] ?? '');
                        if (isset($g[$og])) {
                            $g[$og] += (int) ($kk['miktar'] ?? 0);
                        }
                    }
                    $gunler[] = $g;
                }
                // ── fable-029: SİSTEM KONTROLLERİ — Ömer'in elle yaptığı doğrulamalar otomatik ──
                $kontroller = [];
                $uretim = $repo->customerMealsRange($cid, $bas, $son);
                $irsGunSet = [];
                foreach ($gunler as $g) {
                    $irsGunSet[$g['gun']] = true;
                }
                $irssiz = [];
                $uretimTop = 0;
                foreach ($uretim as $ur) {
                    $uretimTop += (int) $ur['toplam'];
                    if ((int) $ur['toplam'] > 0 && !isset($irsGunSet[$ur['gun']])) {
                        $irssiz[] = date('d.m', strtotime($ur['gun']));
                    }
                }
                if ($irssiz) {
                    $kontroller[] = ['ok' => false, 'txt' => 'Üretim kaydı olup KESİLMİŞ İRSALİYESİ OLMAYAN gün: '
                        . implode(', ', $irssiz) . ' — bu günler faturaya GİRMEYECEK.'];
                } else {
                    $kontroller[] = ['ok' => true, 'txt' => 'Dönemdeki tüm üretim günlerinin irsaliyesi kesilmiş ve faturada (' . count($gunler) . ' gün).'];
                }
                if ($uretimTop !== (int) $p['toplam']) {
                    $kontroller[] = ['ok' => false, 'txt' => 'Fatura toplamı (' . (int) $p['toplam']
                        . ' kişi) üretim kayıtları toplamından (' . $uretimTop . ') FARKLI — fark ' . ($uretimTop - (int) $p['toplam']) . ' kişi.'];
                } else {
                    $kontroller[] = ['ok' => true, 'txt' => 'Fatura toplamı üretim kayıtlarıyla birebir (' . $uretimTop . ' kişi).'];
                }
                $sonF = $repo->sonKesilenFatura($cid, $bas);
                if ($sonF !== null && $sonF['kisi'] > 0) {
                    $farkYuzde = (int) round((($p['toplam'] - $sonF['kisi']) / $sonF['kisi']) * 100);
                    $kiyasTxt = 'Önceki fatura (' . date('d.m', strtotime($sonF['donem_bas'])) . '–' . date('d.m', strtotime($sonF['donem_son']))
                        . '): ' . $sonF['kisi'] . ' kişi · ₺' . number_format($sonF['tutar'], 2, ',', '.')
                        . ' — bu dönem ' . (int) $p['toplam'] . ' kişi (' . ($farkYuzde >= 0 ? '+' : '') . $farkYuzde . '%).';
                    $kontroller[] = ['ok' => abs($farkYuzde) <= 25, 'txt' => $kiyasTxt];
                }

                $plan[] = ['customer_id' => $cid, 'tip' => 'irsaliye'];
                $genelNet += $p['hesap']['net'];
                $satirlar[] = [
                    'customer_id' => $cid, 'name' => $a['name'], 'ok' => true, 'tip' => 'irsaliye',
                    'gunler' => $gunler,
                    'kontroller' => $kontroller,
                    'kalemler' => array_map(static fn(array $k): array => [
                        'ad' => ParasutYaz::OGUN_ETIKET[$k['ogun']] ?? $k['ogun'],
                        'miktar' => $k['miktar'], 'birim' => $k['birim'],
                    ], $p['kalemler']),
                    'hesap' => $p['hesap'], 'toplam' => $p['toplam'], 'vade' => $p['vade'],
                    'despatch_nolar' => $p['despatch_nolar'],
                    'tevkifat' => $a['tevkifat_kodu'] !== '' ? ($a['tevkifat_kodu'] . ' · %' . rtrim(rtrim(number_format((float) $a['tevkifat_oran'], 2), '0'), '.')) : '',
                    'govde' => $p['govde'],
                ];
            } else {
                $dagilim = is_array($s['dagilim'] ?? null) ? $s['dagilim'] : [];
                $parts = $aylikParts($a, $dagilim);
                $partOut = [];
                $sumKisi = 0;
                $altNet = 0.0;
                $govdeler = [];
                foreach ($parts as $pi => $pt) {
                    $kisi = (int) $pt['kisi'];
                    $sumKisi += $kisi;
                    $h = ParasutYaz::faturaHesap([['miktar' => $kisi, 'birim' => (float) $a['birim']]], 10.0, null);
                    $altNet += $h['net'];
                    $partOut[] = ['ad' => $pt['ad'], 'kisi' => $kisi, 'net' => $h['net']];
                    $govdeler[$pt['ad']] = $yaz->aylikGovde($pt['contact_id'], $son, $kisi, (float) $a['birim'], (int) $a['vade_gun'], $pt['ad'] . ' — yemek hizmet bedeli');
                }
                // ── fable-029: aylık sistem kontrolleri ──
                $kontroller = [];
                $gunlerAy = $repo->customerMealsRange($cid, $bas, $son);
                $kayitliSet = [];
                foreach ($gunlerAy as $g) {
                    if ((int) $g['toplam'] > 0) {
                        $kayitliSet[$g['gun']] = true;
                    }
                }
                // Dönem içi GEÇMİŞ hafta içi günlerden kaydı olmayanlar (eksik gün → fatura düşük kalır)
                $eksikGun = [];
                for ($d = strtotime($bas); $d <= min(strtotime($son), strtotime('yesterday')); $d += 86400) {
                    if ((int) date('N', $d) <= 5 && !isset($kayitliSet[date('Y-m-d', $d)])) {
                        $eksikGun[] = date('d.m', $d);
                    }
                }
                if ($eksikGun) {
                    $kontroller[] = ['ok' => false, 'txt' => 'Kayıtsız hafta içi gün: ' . implode(', ', $eksikGun)
                        . ' — bu günler toplama girmiyor (eksikse önce Bugün ekranından girin).'];
                } else {
                    $kontroller[] = ['ok' => true, 'txt' => 'Dönemin geçmiş hafta içi günlerinin tamamı kayıtlı (' . count($kayitliSet) . ' gün).'];
                }
                // fable-051: bölüşümlü müşteride hedef = dönemin FATURA kişisi (fable-040 kuralı
                // varsa üretimden farklı: CANTAŞ 50/70). Yoksa eskisi gibi üretim adedi.
                $hedefAdet = (($a['bolusum'] ?? null) && (int) ($a['fatura_adet'] ?? 0) > 0)
                    ? (int) $a['fatura_adet'] : (int) $a['adet'];
                if ($sumKisi !== $hedefAdet) {
                    $kontroller[] = ['ok' => false, 'txt' => 'Bölüşüm toplamı (' . $sumKisi . ') sistem toplamından ('
                        . $hedefAdet . ') farklı — fark ' . ($sumKisi - $hedefAdet) . ' kişi.'];
                } else {
                    $kontroller[] = ['ok' => true, 'txt' => 'Bölüşüm toplamı sistem toplamıyla birebir (' . $sumKisi . ' kişi).'];
                }
                $sonF = $repo->sonKesilenFatura($cid, $bas);
                if ($sonF !== null && $sonF['kisi'] > 0) {
                    $farkYuzde = (int) round((($sumKisi - $sonF['kisi']) / $sonF['kisi']) * 100);
                    $kontroller[] = ['ok' => abs($farkYuzde) <= 25, 'txt' => 'Önceki fatura dönemi ('
                        . date('d.m', strtotime($sonF['donem_bas'])) . '–' . date('d.m', strtotime($sonF['donem_son'])) . '): '
                        . $sonF['kisi'] . ' kişi — bu dönem ' . $sumKisi . ' kişi (' . ($farkYuzde >= 0 ? '+' : '') . $farkYuzde . '%).'];
                }

                $plan[] = ['customer_id' => $cid, 'tip' => 'aylik', 'parts' => $parts];
                $genelNet += $altNet;
                $satirlar[] = [
                    'customer_id' => $cid, 'name' => $a['name'], 'ok' => true, 'tip' => 'aylik',
                    // fable-028: aylıkta döküm = dönem içi GİRİLEN sayılar (fatura bunların toplamı)
                    'gunler' => $gunlerAy,
                    'kontroller' => $kontroller,
                    // fable-028b: belge önizlemesi için gerçek vade (dönem sonu + müşteri vade günü)
                    'vade' => date('Y-m-d', strtotime($son . ' +' . max(0, (int) $a['vade_gun']) . ' day')),
                    'adet' => (int) $a['adet'], 'birim' => (float) $a['birim'],
                    'parts' => $partOut, 'sum_kisi' => $sumKisi, 'net' => $altNet,
                    'fark' => $sumKisi - (int) $a['adet'],
                    'govde' => $govdeler,
                ];
            }
        }

        $onay = '';
        if ($plan) {
            $onay = ParasutYaz::onayImzaUret();
            $_SESSION['fatura_onay'] = ['token' => $onay, 'bas' => $bas, 'son' => $son,
                'plan' => $plan, 'exp' => time() + 600];
        }
        Helpers::json(['ok' => true, 'bas' => $bas, 'son' => $son, 'kapali' => $kapali, 'onay' => $onay,
            'satirlar' => $satirlar, 'gecerli_sayi' => count($plan), 'genel_net' => $genelNet]);
    }

    // ── KES-TEK: onaylı plandan TEK müşteriyi faturala (fable-026 canlı ilerleme kalıbı).
    // İmza tüketilmez; müşterinin plan kalemi kesimden ÖNCE plandan düşülür (çift tık kalkanı
    // müşteri bazında). Aylık müşteride o müşterinin TÜM parçaları (örn. CANTAŞ 3 fatura) bu
    // istekte kesilir — sonuç dizisi parça parça döner.
    if ($action === 'kes' && (int) ($body['tek'] ?? 0) > 0) {
        $tek = (int) $body['tek'];
        $oturum = $_SESSION['fatura_onay'] ?? null;
        $imza = (string) ($body['onay'] ?? '');
        if (!is_array($oturum) || (int) ($oturum['exp'] ?? 0) < time()
            || (string) ($oturum['bas'] ?? '') !== $bas || (string) ($oturum['son'] ?? '') !== $son
            || $imza === '' || !hash_equals((string) ($oturum['token'] ?? ''), $imza)) {
            Helpers::json(['ok' => false, 'error' => 'Onay süresi doldu veya geçersiz — baştan seçin.'], 403);
        }
        $plan = (array) ($oturum['plan'] ?? []);
        $item = null;
        $kalanPlan = [];
        foreach ($plan as $p) {
            if ($item === null && (int) ($p['customer_id'] ?? 0) === $tek) {
                $item = $p;
                continue;
            }
            $kalanPlan[] = $p;
        }
        if ($item === null) {
            Helpers::json(['ok' => false, 'error' => 'Bu müşteri onaylı planda yok ya da zaten işlendi.'], 403);
        }
        // Claim-first: kalem plandan düşülür; plan boşalınca oturum kapanır.
        if ($kalanPlan) {
            $_SESSION['fatura_onay']['plan'] = $kalanPlan;
        } else {
            unset($_SESSION['fatura_onay']);
        }

        $yaz = new ParasutYaz($repo, (string) $oturum['token']);
        $a = $adaylar[$tek] ?? null;
        $ad = $a['name'] ?? ('#' . $tek);
        $sonuclar = [];
        $basarili = 0;
        if (($item['tip'] ?? '') === 'irsaliye') {
            $r = $yaz->createSalesInvoice($tek, $bas, $son, ['onay' => $imza, 'actor' => (string) $u['username']]);
            if ($r['ok']) {
                $basarili++;
            }
            $sonuclar[] = ['customer_id' => $tek, 'name' => $ad, 'ok' => $r['ok'], 'durum' => $r['durum'],
                'mesaj' => $r['mesaj'], 'fatura_no' => $r['fatura_no'], 'resmilestirme' => $r['resmilestirme'],
                'mail' => $r['mail'], 'net' => $r['net']];
        } else {
            foreach ((array) ($item['parts'] ?? []) as $pt) {
                if ((int) ($pt['kisi'] ?? 0) <= 0) {
                    continue; // 0 kişi = fatura kesilmez (onay özetinde görünür, sessiz değil)
                }
                $r = $yaz->createMonthlyInvoice($tek, $bas, $son, $pt, ['onay' => $imza, 'actor' => (string) $u['username']]);
                if ($r['ok']) {
                    $basarili++;
                }
                $sonuclar[] = ['customer_id' => $tek, 'name' => $ad . ' · ' . ($pt['ad'] ?? ''),
                    'ok' => $r['ok'], 'durum' => $r['durum'], 'mesaj' => $r['mesaj'],
                    'fatura_no' => $r['fatura_no'], 'resmilestirme' => $r['resmilestirme'], 'mail' => $r['mail'], 'net' => $r['net']];
            }
        }
        uysa_audit('fatura_kesim', (string) $u['username'], $bas . '..' . $son, json_encode([
            'tek' => $tek, 'basarili' => $basarili, 'toplam' => count($sonuclar), 'kapali' => $kapali,
        ], JSON_UNESCAPED_UNICODE), client_ip());
        Helpers::json(['ok' => true, 'kapali' => $kapali, 'basarili' => $basarili, 'sonuclar' => $sonuclar]);
    }

    // ── KES: onay imzasını tüket + Paraşüt'e kes (şalter kapalıysa ParasutYaz zaten POST atmaz) ──
    if ($action === 'kes') {
        $oturum = $_SESSION['fatura_onay'] ?? null;
        unset($_SESSION['fatura_onay']); // TEK KULLANIMLIK: çift tık ikinci isteği burada ölür
        $imza = (string) ($body['onay'] ?? '');
        if (!is_array($oturum) || (int) ($oturum['exp'] ?? 0) < time()
            || (string) ($oturum['bas'] ?? '') !== $bas || (string) ($oturum['son'] ?? '') !== $son
            || $imza === '' || !hash_equals((string) ($oturum['token'] ?? ''), $imza)) {
            Helpers::json(['ok' => false, 'error' => 'Onay süresi doldu veya geçersiz — baştan seçin.'], 403);
        }
        $yaz = new ParasutYaz($repo, (string) $oturum['token']);
        $sonuclar = [];
        $basarili = 0;
        foreach ((array) ($oturum['plan'] ?? []) as $item) {
            $cid = (int) ($item['customer_id'] ?? 0);
            $a = $adaylar[$cid] ?? null;
            $ad = $a['name'] ?? ('#' . $cid);
            if (($item['tip'] ?? '') === 'irsaliye') {
                $r = $yaz->createSalesInvoice($cid, $bas, $son, ['onay' => $imza, 'actor' => (string) $u['username']]);
                if ($r['ok']) {
                    $basarili++;
                }
                $sonuclar[] = ['customer_id' => $cid, 'name' => $ad, 'ok' => $r['ok'], 'durum' => $r['durum'],
                    'mesaj' => $r['mesaj'], 'fatura_no' => $r['fatura_no'], 'resmilestirme' => $r['resmilestirme'],
                    'mail' => $r['mail'], 'net' => $r['net']];
            } else {
                foreach ((array) ($item['parts'] ?? []) as $pt) {
                    if ((int) ($pt['kisi'] ?? 0) <= 0) {
                        continue; // 0 kişi = fatura kesilmez (sessiz değil: onay özetinde görünür)
                    }
                    $r = $yaz->createMonthlyInvoice($cid, $bas, $son, $pt, ['onay' => $imza, 'actor' => (string) $u['username']]);
                    if ($r['ok']) {
                        $basarili++;
                    }
                    $sonuclar[] = ['customer_id' => $cid, 'name' => $ad . ' · ' . ($pt['ad'] ?? ''),
                        'ok' => $r['ok'], 'durum' => $r['durum'], 'mesaj' => $r['mesaj'],
                        'fatura_no' => $r['fatura_no'], 'resmilestirme' => $r['resmilestirme'], 'mail' => $r['mail'], 'net' => $r['net']];
                }
            }
        }
        uysa_audit('fatura_kesim', (string) $u['username'], $bas . '..' . $son, json_encode([
            'basarili' => $basarili, 'toplam' => count($sonuclar), 'kapali' => $kapali,
        ], JSON_UNESCAPED_UNICODE), client_ip());
        Helpers::json(['ok' => true, 'kapali' => $kapali, 'basarili' => $basarili, 'sonuclar' => $sonuclar]);
    }

    Helpers::json(['ok' => false, 'error' => 'Bilinmeyen işlem.'], 400);
}

// ══════════════ GET: sayfa ══════════════
$bas = (string) ($_GET['bas'] ?? '');
$son = (string) ($_GET['son'] ?? '');
if (!Helpers::isDate($son)) {
    $son = Helpers::today();
}
if (!Helpers::isDate($bas)) {
    $bas = date('Y-m-d', strtotime($son . ' -6 day'));
}
if ($bas > $son) {
    $bas = $son;
}

$adaylar = [];
$secilebilirSayi = 0;
try {
    $adaylar = $repo->faturaAdaylari($bas, $son);
    foreach ($adaylar as $a) {
        if ($a['secilebilir']) {
            $secilebilirSayi++;
        }
    }
} catch (\Throwable $e) {
    error_log('[UYSA v2 fatura-kes] adaylar okunamadı: ' . $e->getMessage());
}
$kapali = !ParasutYaz::faturaAktif();

$pageTitle = 'Fatura Kes';
$eyebrow = 'Paraşüt satış faturası + e-Fatura';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <?php if (!$isAdmin): ?>
        <div class="flash err">Bu ekran yalnız yetkili kullanıcıya açıktır.</div>
      <?php else: ?>
      <?php if ($kapali): ?>
        <div class="flash" style="background:var(--amber-tint,#fff4e0);color:#8a5a00"><i class="bi bi-shield-lock"></i>
          Fatura kesimi <strong>KAPALI</strong> (PARASUT_FATURA_AKTIF=0). Ekran çalışır, seçim + tutar + kuru deneme gösterilir; Paraşüt'e YAZILMAZ.</div>
      <?php endif; ?>

      <?php
      // fable-029: dönem kısayolları — tarih seçmeyle uğraşmadan tek tık
      $pzt = date('Y-m-d', strtotime('monday this week'));
      $kisayollar = [
          'Geçen hafta' => [date('Y-m-d', strtotime($pzt . ' -7 day')), date('Y-m-d', strtotime($pzt . ' -1 day'))],
          'Bu hafta'    => [$pzt, Helpers::today()],
          'Geçen ay'    => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
          'Bu ay'       => [date('Y-m-01'), Helpers::today()],
      ];
      ?>
      <div class="ftr-kisayol">
        <?php foreach ($kisayollar as $ad => [$kb, $ks]): $aktif = ($kb === $bas && $ks === $son); ?>
          <a class="chip <?= $aktif ? 'active' : '' ?>" href="fatura-kes.php?bas=<?= $kb ?>&son=<?= $ks ?>"><?= $ad ?></a>
        <?php endforeach; ?>
      </div>
      <form method="get" class="date-row" style="gap:8px;flex-wrap:wrap">
        <div class="date-pill"><i class="bi bi-calendar2-week"></i>
          <input type="date" name="bas" value="<?= Helpers::e($bas) ?>" onchange="this.form.submit()"></div>
        <div class="date-pill"><span style="color:var(--muted)">→</span>
          <input type="date" name="son" value="<?= Helpers::e($son) ?>" onchange="this.form.submit()"></div>
      </form>

      <div class="cardx card-pad" id="ftr-step-secim">
        <div class="head-row"><h2>Faturalanacak müşteriler</h2>
          <span class="row-meta" id="ftr-count"><?= $secilebilirSayi ?> seçilebilir</span></div>
        <?php if (!$adaylar): ?>
          <div class="empty-state">Bu dönemde faturalanacak müşteri yok.</div>
        <?php else: ?>
          <label class="ftr-all"><input type="checkbox" id="ftr-all"> Tümünü seç</label>
          <div class="ftr-list">
          <?php foreach ($adaylar as $a):
              $tevk = $a['tip'] === 'irsaliye' && ($a['tevkifat_kodu'] ?? '') !== '';
              $sonBol = $a['son_bolusum'] ?? null;
          ?>
            <?php
            // fable-051: bölüşümlü aylık müşteride hedef = dönemin FATURA kişisi (fable-040:
            // CANTAŞ üretim 50 / fatura 70). Bölüşümsüz aylıkta hedef eskisi gibi üretim adedi.
            $bolusumlu = $a['tip'] === 'aylik' && ($a['bolusum'] ?? null);
            $hedefAdet = $a['tip'] !== 'aylik' ? 0
                : (($bolusumlu && (int) ($a['fatura_adet'] ?? 0) > 0) ? (int) $a['fatura_adet'] : (int) $a['adet']);
            ?>
            <div class="ftr-row <?= $a['secilebilir'] ? '' : 'disabled' ?>" data-cid="<?= (int) $a['customer_id'] ?>"
                 data-tip="<?= Helpers::e($a['tip']) ?>" data-adet="<?= $hedefAdet ?>">
              <label class="ftr-head">
                <input type="checkbox" class="ftr-pick" value="<?= (int) $a['customer_id'] ?>" <?= $a['secilebilir'] ? '' : 'disabled' ?>>
                <span class="ftr-name"><?= Helpers::e($a['name']) ?></span>
                <?php if ($a['tip'] === 'aylik'): ?><span class="badge-soft badge-blue">AYLIK</span><?php endif; ?>
                <?php if ($tevk): ?><span class="badge-soft badge-warn">TEVKİFATLI (<?= Helpers::e($a['tevkifat_kodu']) ?> · %<?= rtrim(rtrim(number_format((float) $a['tevkifat_oran'], 2), '0'), '.') ?>)</span><?php endif; ?>
              </label>
              <div class="ftr-meta">
                <?php if ($a['tip'] === 'irsaliye'): ?>
                  <?= (int) $a['irsaliye_sayisi'] ?> irsaliye · Öğlen <?= (int) $a['ogle'] ?> / Akşam <?= (int) $a['aksam'] ?> / Kumanya <?= (int) $a['kumanya'] ?> · <strong><?= (int) $a['toplam'] ?></strong> kişi × ₺<?= Helpers::money((float) $a['birim']) ?>
                <?php else: ?>
                  Dönem üretimi: <strong><?= (int) $a['adet'] ?></strong> kişi × ₺<?= Helpers::money((float) $a['birim']) ?>
                  <?php // fable-051: fatura kişisi üretimden farklıysa (fable-040 kuralı) ikisi de görünür — hangi rakamdan fatura kesildiği gizlenmez ?>
                  <?php if ($hedefAdet > 0 && $hedefAdet !== (int) $a['adet']): ?>
                    · <strong>fatura <?= $hedefAdet ?></strong> kişi
                  <?php endif; ?>
                <?php endif; ?>
              </div>
              <?php if (!$a['secilebilir']): ?>
                <div class="ftr-why"><i class="bi bi-lock"></i> <?= Helpers::e($a['sebep']) ?></div>
              <?php elseif ($a['tip'] === 'aylik' && ($a['bolusum'] ?? null)): ?>
                <?php
                // fable-051: bölüşüm varsayılanı DESENDEN gelir (gün gün: hafta içi sabit kotalar +
                // kalan varsayılan firmaya, cumartesi/pazar tamamı varsayılana; hesap FATURA kişisinden).
                // Desen yoksa (alt firma tanımsız) eski davranış: son faturalanan ayın oranları.
                $altD = $a['altfirma'] ?? [];
                // Alt firma kodu ↔ bölüşüm key'i; eşleşmezse Paraşüt cari id'ye düşülür.
                $altByContact = [];
                foreach ($altD as $k => $v) {
                    if (($v['contact_id'] ?? '') !== '') {
                        $altByContact[$v['contact_id']] = $k; // contact id => alt firma kodu
                    }
                }
                $desenTop = 0;
                foreach ($altD as $v) {
                    $desenTop += (int) $v['kisi'];
                }
                $desenVar = $desenTop > 0;
                $sonTop = $sonBol !== null ? array_sum($sonBol) : 0;
                // Bölüşüm kutularına GİREN varsayılanlar + hangi alt firma eşleşemedi (payı kaybolur)
                $satirlarBol = [];
                $eslesenKod = [];
                foreach ($a['bolusum'] as $b) {
                    $kod = isset($altD[$b['key']]) ? $b['key'] : ($altByContact[$b['contact_id']] ?? null);
                    $alt = $kod !== null ? $altD[$kod] : null;
                    if ($desenVar) {
                        $def = $alt !== null ? (int) $alt['kisi'] : 0;
                        if ($kod !== null) {
                            $eslesenKod[] = $kod;
                        }
                    } else {
                        $def = $sonBol !== null && $sonTop > 0 && isset($sonBol[$b['contact_id']])
                            ? (int) round((int) $a['adet'] * $sonBol[$b['contact_id']] / $sonTop) : 0;
                    }
                    $satirlarBol[] = ['ad' => $b['ad'], 'key' => $b['key'], 'def' => $def];
                }
                // Desende olup fatura bölüşümünde KARŞILIĞI OLMAYAN firma = sessizce kaybolan pay.
                // Rakam yanlış görünmektense uyar (veri güvenilirliği kuralı).
                $kayipFirma = [];
                if ($desenVar) {
                    foreach ($altD as $k => $v) {
                        if (!in_array($k, $eslesenKod, true) && (int) $v['kisi'] > 0) {
                            $kayipFirma[] = $v['ad'] . ' (' . (int) $v['kisi'] . ')';
                        }
                    }
                }
                ?>
                <div class="ftr-bolusum" hidden>
                  <div class="ftr-bol-head">Bölüşüm (toplam = <span class="ftr-bol-hedef"><?= $desenVar ? $desenTop : (int) $a['adet'] ?></span> kişi;
                    varsayılan = <?= $desenVar ? 'firma deseni (gün gün hesaplandı)' : 'son ayın oranları' ?>):</div>
                  <?php foreach ($satirlarBol as $sb): ?>
                    <label class="ftr-bol-row"><span><?= Helpers::e($sb['ad']) ?></span>
                      <input type="number" class="ftr-bol-in" data-key="<?= Helpers::e($sb['key']) ?>" min="0" value="<?= (int) $sb['def'] ?>" inputmode="numeric"></label>
                  <?php endforeach; ?>
                  <?php if ($desenVar): ?>
                    <p class="ftr-bol-not">Rakamlar firma deseninden geldi — hafta içi sabit paylar + kalan
                      varsayılan firmaya, cumartesi/pazar tamamı varsayılan firmaya. Yanlışsa elle düzeltebilirsin.</p>
                  <?php endif; ?>
                  <?php if ($kayipFirma): ?>
                    <div class="ftr-bol-uyari warn">Fatura bölüşümünde karşılığı olmayan alt firma:
                      <?= Helpers::e(implode(' · ', $kayipFirma)) ?> — bu paylar faturaya GİRMEZ.
                      Alt firma kodunu müşteri kartındaki bölüşüm anahtarıyla eşleyin.</div>
                  <?php else: ?>
                    <div class="ftr-bol-uyari" hidden></div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          </div>
          <div class="ftr-actions">
            <button type="button" class="btn-action btn-primaryx" id="ftr-next" disabled><i class="bi bi-arrow-right"></i> Devam — onay özeti</button>
          </div>
        <?php endif; ?>
      </div>

      <div class="cardx card-pad" id="ftr-step-onay" hidden>
        <div class="head-row"><h2>Onay özeti</h2><span class="row-meta" id="ftr-onay-genel"></span></div>
        <div id="ftr-ozet"></div>
        <details class="ftr-govde-wrap"><summary>Paraşüt'e gidecek JSON (kuru deneme)</summary>
          <pre id="ftr-govde" class="ftr-govde"></pre></details>
        <p class="ftr-uyari"><i class="bi bi-exclamation-triangle"></i> Fatura kesimi <strong>geri dönülmez</strong> resmi belge üretir. Onaylamadan önce tutarları kontrol edin.</p>
        <div class="ftr-actions">
          <button type="button" class="btn-action" id="ftr-back"><i class="bi bi-arrow-left"></i> Geri</button>
          <button type="button" class="btn-action btn-primaryx" id="ftr-cut"><i class="bi bi-receipt"></i> Faturaları Kes</button>
        </div>
      </div>

      <div class="cardx card-pad" id="ftr-step-sonuc" hidden>
        <div class="head-row"><h2>Sonuç</h2></div>
        <div id="ftr-sonuc"></div>
        <div class="ftr-actions">
          <button type="button" class="btn-action" id="ftr-yeni"><i class="bi bi-arrow-repeat"></i> Yeni seçim</button>
          <button type="button" class="btn-action btn-primaryx" id="ftr-retry" hidden><i class="bi bi-arrow-clockwise"></i> Başarısızları tekrar dene</button>
        </div>
      </div>

      <script>window.FTR = {csrf: <?= json_encode(Helpers::csrfToken()) ?>, bas: <?= json_encode($bas) ?>, son: <?= json_encode($son) ?>, kapali: <?= $kapali ? 'true' : 'false' ?>};</script>
      <script src="assets/fatura.js?v=<?= filemtime(__DIR__ . '/assets/fatura.js') ?>"></script>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
