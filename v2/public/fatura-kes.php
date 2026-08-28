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

/**
 * fable-082b (Ömer, 21 Ağu: "21'in faturalarını kestik ama kâr/zararda 14 gözüküyor"):
 * Kesim sonrası satış senkronu. Kâr/Zarar ekranı satis_faturasi tablosunu okur; o tablo Paraşüt
 * senkronundan beslenir. Kesimden sonra cron koşana kadar ekran eski kapsamı gösteriyordu.
 * fable-082'de bu senkron YALNIZ toplu kesim yoluna eklenmişti; ekran ise faturaları TEK TEK
 * kesiyor (kes+tek) → o yolda hiç çalışmadı, 41 dk cron beklendi. Artık İKİ YOL DA burayı çağırır.
 * Hata olursa kesim sonucu ETKİLENMEZ (cron nasılsa yakalar), yalnız loga düşer.
 */
$kesimSonrasiSenkron = static function (Repo $repo, string $son, int $basarili): void {
    if ($basarili <= 0) {
        return;
    }
    try {
        $si = Uysa\Parasut::salesInvoicesForMonth(substr($son, 0, 7));
        $repo->satisFaturaIsle($si['invoices'], Uysa\Parasut::supplierContactIds());
    } catch (\Throwable $e) {
        error_log('[UYSA v2 fatura-kes] kesim sonrası satış senkronu atlandı: ' . $e->getMessage());
    }
};

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

    // ── fable-091: ekstra kalem + otomatik kesim şalteri ──────────────────────
    // Bunlar klasik form POST'u (JSON değil) ve PRG ile biter — F5'te mükerrer kayıt olmasın.
    // JSON uçlarından ÖNCE yakalanır: onların bas/son doğrulaması bu formlarda yok.
    if ($action === 'ekstra_ekle' || $action === 'ekstra_sil' || $action === 'oto_salter') {
        $don = 'fatura-kes.php?bas=' . urlencode((string) ($_POST['bas'] ?? date('Y-m-01')))
             . '&son=' . urlencode((string) ($_POST['son'] ?? date('Y-m-t')));
        if ($action === 'oto_salter') {
            $repo->ayarSet('fatura_oto_kesim', $repo->ayar('fatura_oto_kesim', '1') === '1' ? '0' : '1');
            uysa_audit('fatura_oto_salter', (string) $u['username'], date('Y-m-d'),
                json_encode(['yeni' => $repo->ayar('fatura_oto_kesim', '1')], JSON_UNESCAPED_UNICODE), client_ip());
            header('Location: ' . $don . '#ekstra');
            exit;
        }
        if ($action === 'ekstra_sil') {
            $repo->ekstraKalemSil((int) ($_POST['id'] ?? 0));
            header('Location: ' . $don . '#ekstra');
            exit;
        }
        $cid = (int) ($_POST['customer_id'] ?? 0);
        $ay = (string) ($_POST['ay'] ?? date('Y-m'));
        $ad = trim((string) ($_POST['ad'] ?? ''));
        $adet = (float) str_replace(',', '.', (string) ($_POST['adet'] ?? '1'));
        $birim = (float) str_replace(',', '.', (string) ($_POST['birim_fiyat'] ?? '0'));
        if ($cid > 0 && $ad !== '' && $adet > 0 && $birim > 0 && preg_match('/^\d{4}-\d{2}$/', $ay)) {
            $repo->ekstraKalemEkle($cid, $ay, [
                'ad' => $ad, 'adet' => $adet, 'birim_fiyat' => $birim,
                'kdv_orani' => (float) str_replace(',', '.', (string) ($_POST['kdv_orani'] ?? '10')),
                'aciklama' => trim((string) ($_POST['aciklama'] ?? '')),
                'entered_by' => (string) $u['username'],
            ]);
        }
        header('Location: ' . $don . '#ekstra');
        exit;
    }

    $bas = (string) ($body['bas'] ?? '');
    $son = (string) ($body['son'] ?? '');
    if (!Helpers::isDate($bas) || !Helpers::isDate($son) || $bas > $son) {
        Helpers::json(['ok' => false, 'error' => 'Geçersiz dönem.'], 400);
    }
    $kapali = !ParasutYaz::faturaAktif();

    // fable-065: liste artık müşteri başına BİRDEN ÇOK satır içerebilir (BOMİ = yemek + sabit
    // kalem) → anahtar customer_id DEĞİL, satir_key ('12' müşteri satırı · 's3' sabit kalem).
    $adaylar = [];
    foreach ($repo->faturaAdaylari($bas, $son) as $a) {
        $adaylar[(string) $a['satir_key']] = $a;
    }

    // ── HAZIRLA: seçimi doğrula + tutar/gövde göster + onay imzası üret ──
    if ($action === 'hazirla') {
        $secim = is_array($body['secim'] ?? null) ? $body['secim'] : [];
        $yaz = new ParasutYaz($repo);
        $satirlar = [];
        $plan = [];
        $genelNet = 0.0;
        foreach ($secim as $s) {
            $key = (string) ($s['key'] ?? ($s['customer_id'] ?? ''));
            $a = $adaylar[$key] ?? null;
            $cid = (int) ($a['customer_id'] ?? ($s['customer_id'] ?? 0));
            if ($a === null || !$a['secilebilir']) {
                $satirlar[] = ['key' => $key, 'customer_id' => $cid, 'name' => $a['name'] ?? ('#' . $key),
                    'ok' => false, 'sebep' => $a['sebep'] ?? 'Müşteri listede yok.'];
                continue;
            }
            if ($a['tip'] === 'sabit') {
                // fable-065: üretimden BAĞIMSIZ sabit kalem — 1 adet × birim, kalemin KENDİ KDV'si.
                $hesap = Repo::sabitFaturaHesap((float) $a['birim'], (float) $a['kdv_orani']);
                $vade = date('Y-m-d', strtotime($a['donem_son'] . ' +' . max(0, (int) $a['vade_gun']) . ' day'));
                $kontroller = [['ok' => true, 'txt' => 'Sabit kalem — tutar üretimden bağımsız: 1 adet × ₺'
                    . Helpers::money((float) $a['birim']) . ' + KDV %' . rtrim(rtrim(number_format((float) $a['kdv_orani'], 2), '0'), '.')
                    . ' = ₺' . Helpers::money($hesap['net']) . '.']];
                $kontroller[] = ['ok' => true, 'txt' => 'Bu ay (' . date('m.Y', strtotime($a['donem_son']))
                    . ') için bu kalemden fatura kesilmemiş — mükerrer riski yok.'];
                $plan[] = ['key' => $key, 'customer_id' => $cid, 'tip' => 'sabit',
                    'kalem_id' => (int) $a['kalem_id'], 'ay' => (string) $a['ay']];
                $genelNet += $hesap['net'];
                $satirlar[] = [
                    'key' => $key, 'customer_id' => $cid, 'name' => $a['name'], 'ok' => true, 'tip' => 'sabit',
                    'kontroller' => $kontroller,
                    'kalem_ad' => (string) $a['kalem_ad'], 'adet' => 1, 'birim' => (float) $a['birim'],
                    'kdv_orani' => (float) $a['kdv_orani'], 'hesap' => $hesap, 'vade' => $vade,
                    'donem' => date('m.Y', strtotime($a['donem_son'])),
                    'govde' => $yaz->sabitGovde((string) $a['parasut_id'], (string) $a['donem_son'],
                        (string) $a['urun_id'], (float) $a['birim'], (float) $a['kdv_orani'],
                        (int) $a['vade_gun'], (string) $a['kalem_ad']),
                ];
            } elseif ($a['tip'] === 'irsaliye') {
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
                // ── fable-029 SİSTEM KONTROLLERİ — fable-091'de Repo'ya taşındı ──
                // Otomatik kesim (tools/fatura_oto_kes.php) AYNI kontrolleri çağırır. Mantık iki
                // yere kopyalanırsa ekran ile otomatik zamanla ayrışır ve "ekranda kırmızı görünen
                // şey otomatikte kesilir" olur. Fark davranışta: burada kırmızı UYARIDIR (Ömer
                // bakıp yine de kesebilir), otomatik kesimde KESMEME sebebidir.
                $irsGunSet = [];
                foreach ($gunler as $g) {
                    $irsGunSet[$g['gun']] = true;
                }
                $kontroller = $repo->faturaKontrolleriIrsaliye($cid, $bas, $son, (int) $p['toplam'], $irsGunSet);

                $plan[] = ['key' => $key, 'customer_id' => $cid, 'tip' => 'irsaliye'];
                $genelNet += $p['hesap']['net'];
                $satirlar[] = [
                    'key' => $key,
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
                    $govdeler[$pt['ad']] = $yaz->aylikGovde($pt['contact_id'], (string) ($a['donem_son'] ?? $son), $kisi, (float) $a['birim'], (int) $a['vade_gun'], $pt['ad'] . ' — yemek hizmet bedeli');
                }
                // ── fable-029: aylık sistem kontrolleri ──
                // fable-055: kontroller de AY dönemine bakar (ekrandaki haftalık aralığa değil),
                // yoksa "kayıtsız gün" listesi ayın sadece bir haftasını denetlerdi.
                $kBas = (string) ($a['donem_bas'] ?? $bas);
                $kSon = (string) ($a['donem_son'] ?? $son);
                $gunlerAy = $repo->customerMealsRange($cid, $kBas, $kSon);
                // fable-051: bölüşümlü müşteride hedef = dönemin FATURA kişisi (fable-040 kuralı
                // varsa üretimden farklı: CANTAŞ 50/70). Yoksa eskisi gibi üretim adedi.
                $hedefAdet = (($a['bolusum'] ?? null) && (int) ($a['fatura_adet'] ?? 0) > 0)
                    ? (int) $a['fatura_adet'] : (int) $a['adet'];
                // fable-091: kontroller Repo'da — otomatik kesim de aynı kapılardan geçer.
                $kontroller = $repo->faturaKontrolleriAylik($cid, $kBas, $kSon, $sumKisi, $hedefAdet, $bas);

                $plan[] = ['key' => $key, 'customer_id' => $cid, 'tip' => 'aylik', 'parts' => $parts];
                $genelNet += $altNet;
                $satirlar[] = [
                    'key' => $key,
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
    if ($action === 'kes' && trim((string) ($body['tek'] ?? '')) !== '') {
        $tek = trim((string) $body['tek']);   // fable-065: satir_key ('12' | 's3')
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
            if ($item === null && (string) ($p['key'] ?? '') === $tek) {
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
        $cid = (int) ($item['customer_id'] ?? 0);
        $ad = $a['name'] ?? ('#' . $tek);
        $sonuclar = [];
        $basarili = 0;
        if (($item['tip'] ?? '') === 'sabit') {
            // fable-065: dönem EKRANDAKİ dönem değil, kalemin AYI (mükerrer kalkanı da ona bakar).
            $r = $yaz->createFixedInvoice((int) ($item['kalem_id'] ?? 0), (string) ($item['ay'] ?? ''),
                ['onay' => $imza, 'actor' => (string) $u['username']]);
            if ($r['ok']) {
                $basarili++;
            }
            $sonuclar[] = ['customer_id' => $cid, 'name' => $ad, 'ok' => $r['ok'], 'durum' => $r['durum'],
                'mesaj' => $r['mesaj'], 'fatura_no' => $r['fatura_no'], 'resmilestirme' => $r['resmilestirme'],
                'mail' => $r['mail'], 'net' => $r['net']];
        } elseif (($item['tip'] ?? '') === 'irsaliye') {
            $r = $yaz->createSalesInvoice($cid, $bas, $son, ['onay' => $imza, 'actor' => (string) $u['username']]);
            if ($r['ok']) {
                $basarili++;
            }
            $sonuclar[] = ['customer_id' => $cid, 'name' => $ad, 'ok' => $r['ok'], 'durum' => $r['durum'],
                'mesaj' => $r['mesaj'], 'fatura_no' => $r['fatura_no'], 'resmilestirme' => $r['resmilestirme'],
                'mail' => $r['mail'], 'net' => $r['net']];
        } else {
            // fable-055: aylık faturanın dönemi EKRANDAKİ dönem değil, ayın tamamıdır —
            // haftalık turda seçilse bile fatura "01.07 - 31.07 dönemi" olarak kesilir.
            $aBas = (string) ($a['donem_bas'] ?? $bas);
            $aSon = (string) ($a['donem_son'] ?? $son);
            foreach ((array) ($item['parts'] ?? []) as $pt) {
                if ((int) ($pt['kisi'] ?? 0) <= 0) {
                    continue; // 0 kişi = fatura kesilmez (onay özetinde görünür, sessiz değil)
                }
                $r = $yaz->createMonthlyInvoice($cid, $aBas, $aSon, $pt, ['onay' => $imza, 'actor' => (string) $u['username']]);
                if ($r['ok']) {
                    $basarili++;
                }
                $sonuclar[] = ['customer_id' => $cid, 'name' => $ad . ' · ' . ($pt['ad'] ?? ''),
                    'ok' => $r['ok'], 'durum' => $r['durum'], 'mesaj' => $r['mesaj'],
                    'fatura_no' => $r['fatura_no'], 'resmilestirme' => $r['resmilestirme'], 'mail' => $r['mail'], 'net' => $r['net']];
            }
        }
        uysa_audit('fatura_kesim', (string) $u['username'], $bas . '..' . $son, json_encode([
            'tek' => $tek, 'basarili' => $basarili, 'toplam' => count($sonuclar), 'kapali' => $kapali,
        ], JSON_UNESCAPED_UNICODE), client_ip());
        $kesimSonrasiSenkron($repo, $son, $basarili);   // fable-082b: bu yol atlanmıştı
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
            $a = $adaylar[(string) ($item['key'] ?? $cid)] ?? null;
            $ad = $a['name'] ?? ('#' . $cid);
            if (($item['tip'] ?? '') === 'sabit') {
                $r = $yaz->createFixedInvoice((int) ($item['kalem_id'] ?? 0), (string) ($item['ay'] ?? ''),
                    ['onay' => $imza, 'actor' => (string) $u['username']]);
                if ($r['ok']) {
                    $basarili++;
                }
                $sonuclar[] = ['customer_id' => $cid, 'name' => $ad, 'ok' => $r['ok'], 'durum' => $r['durum'],
                    'mesaj' => $r['mesaj'], 'fatura_no' => $r['fatura_no'], 'resmilestirme' => $r['resmilestirme'],
                    'mail' => $r['mail'], 'net' => $r['net']];
            } elseif (($item['tip'] ?? '') === 'irsaliye') {
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

        // fable-082 (Ömer, 14 Ağu: "kâr/zararda fatura kesildiğini anlamamış, bilgi notunda öyle
        // yazıyor"): Kâr/Zarar ekranı satis_faturasi tablosunu okur, o tablo Paraşüt satış
        // senkronundan (günde 2 cron) beslenir. Kesim yaptıktan sonra senkron koşana kadar ekran
        // "N gündür fatura kesilmemiş" demeye devam ediyordu — kullanıcı senkronu BEKLEMEMELİ.
        // Kesim başarılıysa aynı ay hemen senkronlanır; hata olursa kesim sonucu ETKİLENMEZ
        // (cron nasılsa yakalar), yalnız loga düşer.
        $kesimSonrasiSenkron($repo, $son, $basarili);

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
      <?php
      // aksiyon-faz7: karar rakamı — kaç fatura kesilebilir ve tutarı ne. Seçilemeyenlerin
      // SEBEBİ zaten satırında yazıyor; burada sadece kaç tanesinin beklediği söyleniyor.
      // Aday satırında 'tutar' alanı YOK; kişi ('toplam') ve birim fiyat ('birim') var —
      // tutar bunlardan türetilir. Birim fiyatı olmayan satır tutara katılmaz (yalan rakam olurdu).
      $adayTutar = 0.0;
      $adayKisi = 0;
      $secilemez = 0;
      foreach ($adaylar as $a) {
          if (!empty($a['secilebilir'])) {
              $adayKisi += (int) ($a['toplam'] ?? 0);
              $adayTutar += (float) ($a['toplam'] ?? 0) * (float) ($a['birim'] ?? 0);
          } else {
              $secilemez++;
          }
      }
      $kararRakam = $secilebilirSayi . ' fatura';
      $kararAlt = $adayTutar > 0
          ? ('₺' . number_format(round($adayTutar), 0, ',', '.') . ' · ' . number_format($adayKisi, 0, ',', '.') . ' kişi')
          : 'kesilmeye hazır';
      $durumMetin = $secilemez > 0 ? ($secilemez . ' aday kesilemiyor') : '';
      $durumNot = $secilemez > 0 ? 'sebebi satırında yazılı (cari bağı, tutar, dönem)' : '';
      $durumBtn = null;
      require __DIR__ . '/partials/karar.php';
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
          // fable-054: aylık müşteri (CANTAŞ/Marmara) AY SONUNDA kesilir — dönem ayın son
          // gününe kadar olmalı. "Bu ay" bugüne kadar verdiği için ayın 30'unda bakınca
          // son gün eksik görünüyordu. Ay ortasında tıklanırsa planlı günler de girer:
          // seçildiğinde uyarı çıkar (aşağıdaki data-uyar).
          'Ay tamamı'   => [date('Y-m-01'), date('Y-m-t')],
      ];
      ?>
      <div class="ftr-kisayol">
        <?php foreach ($kisayollar as $ad => [$kb, $ks]): $aktif = ($kb === $bas && $ks === $son); ?>
          <a class="chip <?= $aktif ? 'active' : '' ?>" href="fatura-kes.php?bas=<?= $kb ?>&son=<?= $ks ?>"><?= $ad ?></a>
        <?php endforeach; ?>
      </div>
      <?php // fable-054: dönem bugünü aşıyorsa henüz GERÇEKLEŞMEMİŞ (planlı) günler de sayıya
            // girer — ay ortasında "Ay tamamı" tıklanırsa fazla fatura kesilmesin diye uyarılır. ?>
      <?php if ($son > Helpers::today()): ?>
        <div class="uyari-kutu" style="margin:10px 0">
          <i class="bi bi-exclamation-triangle"></i>
          Dönem sonu <strong><?= Helpers::e(date('d.m.Y', strtotime($son))) ?></strong> —
          bugünden ileri. <?= (int) round((strtotime($son) - strtotime(Helpers::today())) / 86400) ?> günlük
          <strong>planlı</strong> üretim de sayıya dahil. Ay sonu kesiminde normaldir;
          ay ortasındaysan dönem sonunu bugüne çek.
        </div>
      <?php endif; ?>
      <form method="get" class="date-row" style="gap:8px;flex-wrap:wrap">
        <div class="date-pill"><i class="bi bi-calendar2-week"></i>
          <input type="date" name="bas" value="<?= Helpers::e($bas) ?>" onchange="this.form.submit()"></div>
        <div class="date-pill"><span style="color:var(--muted)">→</span>
          <input type="date" name="son" value="<?= Helpers::e($son) ?>" onchange="this.form.submit()"></div>
      </form>

      <?php
      // ── fable-091: OTOMATİK KESİM durumu + EKSTRA KALEMLER ───────────────────
      // Ömer (28 Ağu): "irsaliyesiz olan faturalara ekstra eklenecek alan kurgula, ay içinde
      // ekstralara yazayım." Ekstra yalnız aylık (irsaliyesiz) müşterilere girilir; ay sonu
      // otomatik kesiminde AYRI bir faturaya dönüşür (bölüşümlü müşteride hangi parçaya
      // gireceği belirsiz kalmasın diye ana cariye tek fatura).
      $otoAcik = $repo->ayar('fatura_oto_kesim', '1') === '1';
      $ekstraUrun = trim((string) $repo->ayar('ekstra_urun_id', ''));
      $aylikMusteriler = $pdo->query(
          'SELECT id, name, fatura_oto_kesim FROM customers WHERE is_active = 1 AND irsaliye_aktif = 0 ORDER BY name'
      )->fetchAll();
      $ekstraAy = date('Y-m', strtotime($son));
      ?>
      <div class="cardx card-pad" id="ekstra">
        <div class="head-row">
          <h2>Otomatik kesim &amp; ekstra kalemler</h2>
          <span class="row-meta">
            <?= $otoAcik ? '🟢 Otomatik kesim AÇIK' : '🔴 Otomatik kesim KAPALI' ?>
          </span>
        </div>
        <p class="row-meta" style="margin:0 0 10px">
          Faturalar <strong>ayın 7 · 14 · 21 · 28 ve son günü</strong> saat <strong>13:30</strong>'da
          otomatik kesilir (13:05'te "kesilecek" bildirimi düşer). İrsaliyeleri eksik olan gün
          kesim yapılmaz. CANTAŞ otomatiğe dahil değildir — elle kesilir.
        </p>
        <form method="post" style="margin:0 0 14px">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <input type="hidden" name="action" value="oto_salter">
          <input type="hidden" name="bas" value="<?= Helpers::e($bas) ?>">
          <input type="hidden" name="son" value="<?= Helpers::e($son) ?>">
          <button type="submit" class="btn <?= $otoAcik ? 'btn-ghost' : 'btn-primary' ?>">
            <?= $otoAcik ? 'Otomatik kesimi KAPAT' : 'Otomatik kesimi AÇ' ?>
          </button>
        </form>

        <?php if ($ekstraUrun === ''): ?>
          <div class="uyari-kutu" style="margin:0 0 12px">
            <i class="bi bi-exclamation-triangle"></i>
            <strong>Ekstra ürün id tanımlı değil.</strong> Paraşüt kalemsiz fatura kabul etmiyor;
            ekstra kalem girilebilir ama ürün id girilene kadar <strong>faturaya dönüşmez</strong>.
            Ayarlardan <code>ekstra_urun_id</code> değerine Paraşüt'teki hizmet ürününün id'sini yazın.
          </div>
        <?php endif; ?>

        <?php foreach ($aylikMusteriler as $am):
            $amId = (int) $am['id'];
            $kalemler = $repo->ekstraKalemler($amId, $ekstraAy);
        ?>
          <div style="border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:10px">
            <div class="head-row" style="margin-bottom:8px">
              <strong><?= Helpers::e((string) $am['name']) ?></strong>
              <span class="row-meta">
                <?= Helpers::e(date('m.Y', strtotime($ekstraAy . '-01'))) ?> ·
                <?= (int) $am['fatura_oto_kesim'] === 1 ? 'ay sonu otomatik' : 'elle kesilir' ?>
              </span>
            </div>
            <?php if ($kalemler): ?>
              <table class="tbl" style="margin-bottom:8px">
                <thead><tr><th>Kalem</th><th class="num">Adet</th><th class="num">Birim</th>
                  <th class="num">KDV</th><th class="num">Tutar</th><th>Durum</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($kalemler as $k):
                    $tutar = round((float) $k['adet'] * (float) $k['birim_fiyat'], 2);
                    $faturali = $k['fatura_log_id'] !== null;
                ?>
                  <tr>
                    <td><?= Helpers::e((string) $k['ad']) ?></td>
                    <td class="num"><?= rtrim(rtrim(number_format((float) $k['adet'], 2, ',', '.'), '0'), ',') ?></td>
                    <td class="num">₺<?= Helpers::money((float) $k['birim_fiyat']) ?></td>
                    <td class="num">%<?= rtrim(rtrim(number_format((float) $k['kdv_orani'], 2), '0'), '.') ?></td>
                    <td class="num">₺<?= Helpers::money($tutar) ?></td>
                    <td><?= $faturali ? '<span class="row-meta">faturalandı</span>' : 'bekliyor' ?></td>
                    <td class="num">
                      <?php if (!$faturali): ?>
                        <form method="post" style="display:inline">
                          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
                          <input type="hidden" name="action" value="ekstra_sil">
                          <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                          <input type="hidden" name="bas" value="<?= Helpers::e($bas) ?>">
                          <input type="hidden" name="son" value="<?= Helpers::e($son) ?>">
                          <button type="submit" class="btn btn-ghost btn-sm" title="Sil"><i class="bi bi-trash"></i></button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <div class="row-meta" style="margin-bottom:8px">Bu ay ekstra kalem girilmemiş.</div>
            <?php endif; ?>
            <form method="post" class="date-row" style="gap:8px;flex-wrap:wrap;align-items:flex-end">
              <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
              <input type="hidden" name="action" value="ekstra_ekle">
              <input type="hidden" name="customer_id" value="<?= $amId ?>">
              <input type="hidden" name="ay" value="<?= Helpers::e($ekstraAy) ?>">
              <input type="hidden" name="bas" value="<?= Helpers::e($bas) ?>">
              <input type="hidden" name="son" value="<?= Helpers::e($son) ?>">
              <input type="text" name="ad" placeholder="Kalem adı" required style="min-width:180px;font-size:16px">
              <input type="text" name="adet" value="1" placeholder="Adet" style="width:80px;font-size:16px" inputmode="decimal">
              <input type="text" name="birim_fiyat" placeholder="Birim ₺" required style="width:120px;font-size:16px" inputmode="decimal">
              <input type="text" name="kdv_orani" value="10" placeholder="KDV %" style="width:80px;font-size:16px" inputmode="decimal">
              <button type="submit" class="btn btn-primary">Ekstra ekle</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>

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
                 data-key="<?= Helpers::e((string) $a['satir_key']) ?>"
                 data-tip="<?= Helpers::e($a['tip']) ?>" data-adet="<?= $hedefAdet ?>">
              <label class="ftr-head">
                <input type="checkbox" class="ftr-pick" value="<?= Helpers::e((string) $a['satir_key']) ?>" <?= $a['secilebilir'] ? '' : 'disabled' ?>>
                <span class="ftr-name"><?= Helpers::e($a['name']) ?></span>
                <?php if ($a['tip'] === 'aylik'): ?><span class="badge-soft badge-blue">AYLIK</span><?php endif; ?>
                <?php // fable-065: sabit kalem AYLIK rozetinden ayırt edilsin (farklı akış, farklı KDV) ?>
                <?php if ($a['tip'] === 'sabit'): ?><span class="badge-soft badge-warn">SABİT</span><?php endif; ?>
                <?php if ($tevk): ?><span class="badge-soft badge-warn">TEVKİFATLI (<?= Helpers::e($a['tevkifat_kodu']) ?> · %<?= rtrim(rtrim(number_format((float) $a['tevkifat_oran'], 2), '0'), '.') ?>)</span><?php endif; ?>
              </label>
              <div class="ftr-meta">
                <?php if ($a['tip'] === 'sabit'): ?>
                  <span class="badge-soft badge-blue"><?= Helpers::e(ay_label_tr((string) $a['ay'])) ?> · sabit tutar</span>
                  1 adet × ₺<?= Helpers::money((float) $a['birim']) ?>
                  + KDV %<?= rtrim(rtrim(number_format((float) $a['kdv_orani'], 2), '0'), '.') ?>
                  = <strong>₺<?= Helpers::money((float) $a['net']) ?></strong>
                  <?php if (($a['aciklama'] ?? '') !== ''): ?>
                    <span class="ftr-not"><?= Helpers::e((string) $a['aciklama']) ?></span>
                  <?php endif; ?>
                <?php elseif ($a['tip'] === 'irsaliye'): ?>
                  <?= (int) $a['irsaliye_sayisi'] ?> irsaliye · Öğlen <?= (int) $a['ogle'] ?> / Akşam <?= (int) $a['aksam'] ?> / Kumanya <?= (int) $a['kumanya'] ?> · <strong><?= (int) $a['toplam'] ?></strong> kişi × ₺<?= Helpers::money((float) $a['birim']) ?>
                <?php else: ?>
                  <?php // fable-054 (Ömer: "dönem üretimi eksik gözüküyor"): FATURAYA ESAS rakam
                        // öne alınır — CANTAŞ'ta üretim 50, fatura 70 kişiden (fable-040) ve
                        // ekranda önce 1180 görünmesi "eksik" izlenimi veriyordu. Üretim adedi
                        // yine gösterilir (gizlenmez) ama parantezde, ikincil. ?>
                  <?php // fable-055: aylık müşteri ekrandaki dönemden BAĞIMSIZ — ayın tamamı ?>
                  <span class="badge-soft badge-blue"><?= Helpers::e(ay_label_tr(substr((string) ($a['donem_bas'] ?? $bas), 0, 7))) ?> · tam ay</span>
                  Faturaya esas: <strong><?= $hedefAdet > 0 ? $hedefAdet : (int) $a['adet'] ?></strong> kişi
                  × ₺<?= Helpers::money((float) $a['birim']) ?>
                  = <strong>₺<?= Helpers::money((($hedefAdet > 0 ? $hedefAdet : (int) $a['adet'])) * (float) $a['birim']) ?></strong>
                  <?php if ($hedefAdet > 0 && $hedefAdet !== (int) $a['adet']): ?>
                    <span class="ftr-uretim">(dönem üretimi <?= (int) $a['adet'] ?> kişi)</span>
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
                  <?php
                  // fable-059: ay içinde ELLE girilen kırılım günleri (istisna günler) — ay sonunda
                  // Ömer neyin desenle, neyin elle hesaplandığını görsün. Günler title'da.
                  $elleD = $a['altfirma_elle'] ?? ['gunler' => [], 'bayat' => []];
                  $elleGun = $elleD['gunler'] ?? [];
                  $elleBayat = $elleD['bayat'] ?? [];
                  ?>
                  <?php if ($desenVar): ?>
                    <p class="ftr-bol-not">Rakamlar firma deseninden geldi<?= $elleGun ? ' (elle girilen günler hariç)' : '' ?> — hafta içi sabit paylar + kalan
                      varsayılan firmaya, cumartesi/pazar tamamı varsayılan firmaya. Yanlışsa elle düzeltebilirsin.</p>
                  <?php endif; ?>
                  <?php if ($elleGun): ?>
                    <p class="ftr-bol-not" title="<?= Helpers::e(implode(' · ', array_map(
                        static fn(string $g): string => date('d.m.Y', strtotime($g)),
                        $elleGun
                    ))) ?>"><i class="bi bi-pencil-square"></i>
                      <strong><?= count($elleGun) ?> günde elle kırılım var</strong>
                      (<?= Helpers::e(implode(' · ', array_map(
                          static fn(string $g): string => date('d.m', strtotime($g)),
                          array_slice($elleGun, 0, 6)
                      ))) ?><?= count($elleGun) > 6 ? ' …' : '' ?>) — o günler desenle değil,
                      Bugün ekranından girdiğin sayılarla hesaplandı.</p>
                  <?php endif; ?>
                  <?php if ($elleBayat): ?>
                    <div class="ftr-bol-uyari warn">Elle kırılım girildikten SONRA gün sayısı değişmiş:
                      <?= Helpers::e(implode(' · ', array_map(
                          static fn(string $g): string => date('d.m.Y', strtotime($g)),
                          $elleBayat
                      ))) ?> — fark varsayılan firmaya yazıldı. Doğrusunu Bugün ekranından tekrar girin.</div>
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
