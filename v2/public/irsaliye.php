<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\ParasutYaz;
use Uysa\Repo;

/**
 * fable-023b — "İrsaliyelendir" uç noktası (Bugün ekranındaki pencerenin arkası).
 *
 * 🔒 İki kapı: (1) ana şalter PARASUT_IRSALIYE_AKTIF — kapalıysa burada da kesim ÇAĞRILMAZ,
 * yalnız önizleme döner; (2) ParasutYaz içindeki bağımsız şalter + onay imzası.
 * Onay imzası tek kullanımlıktır: 'hazirla' üretir (session), 'kes' tüketir (çift tık kalkanı).
 *
 * Oturum + CSRF zorunlu. Yalnız POST. Yanıt JSON.
 */

$u = Auth::requireLogin();
$pdo = Db::pdo();
$repo = new Repo($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Helpers::json(['ok' => false, 'error' => 'POST gerekli'], 405);
}
if (!Auth::isAdmin($u)) {
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
$date = (string) ($body['date'] ?? Helpers::today());
if (!Helpers::isDate($date)) {
    Helpers::json(['ok' => false, 'error' => 'Geçersiz tarih.'], 400);
}
$ids = [];
foreach ((array) ($body['ids'] ?? []) as $v) {
    $id = (int) $v;
    if ($id > 0) {
        $ids[$id] = $id; // tekilleştir (aynı id iki kez gelirse tek kesim)
    }
}
$ids = array_values($ids);

$adaylar = [];
foreach ($repo->irsaliyeAdaylari($date) as $a) {
    $adaylar[(int) $a['customer_id']] = $a;
}
$kapali = !ParasutYaz::aktif();

// ── 1) HAZIRLA: seçimi doğrula + gidecek gövdeyi göster + onay imzası üret ──
if ($action === 'hazirla') {
    if (!$ids) {
        Helpers::json(['ok' => false, 'error' => 'Hiç müşteri seçilmedi.'], 400);
    }
    $yaz = new ParasutYaz($repo);
    $satirlar = [];
    $gecerli = [];
    $toplamKisi = 0;
    foreach ($ids as $cid) {
        $a = $adaylar[$cid] ?? null;
        if ($a === null) {
            $satirlar[] = ['customer_id' => $cid, 'name' => '#' . $cid, 'ok' => false,
                'sebep' => 'Müşteri bu güne ait listede yok.', 'kalemler' => [], 'toplam' => 0, 'govde' => null];
            continue;
        }
        if (!$a['secilebilir']) {
            $satirlar[] = ['customer_id' => $cid, 'name' => $a['name'], 'ok' => false,
                'sebep' => $a['sebep'], 'kalemler' => [], 'toplam' => 0, 'govde' => null];
            continue;
        }
        $meals = ['ogle' => $a['ogle'], 'aksam' => $a['aksam'], 'kumanya' => $a['kumanya']];
        $p = $yaz->onizleme($cid, $date, $meals);
        if (!$p['ok']) {
            $satirlar[] = ['customer_id' => $cid, 'name' => $a['name'], 'ok' => false,
                'sebep' => $p['mesaj'], 'kalemler' => [], 'toplam' => 0, 'govde' => null];
            continue;
        }
        $gecerli[] = $cid;
        $toplamKisi += $p['toplam'];
        $satirlar[] = [
            'customer_id' => $cid,
            'name'        => $a['name'],
            'ok'          => true,
            'sebep'       => '',
            'kalemler'    => array_map(static fn(array $k): array => [
                'ogun'    => ParasutYaz::OGUN_ETIKET[$k['ogun']] ?? $k['ogun'],
                'urun_id' => $k['urun_id'],
                'miktar'  => $k['miktar'],
            ], $p['kalemler']),
            'toplam'      => $p['toplam'],
            'govde'       => $p['govde'],
        ];
    }

    $onay = '';
    if ($gecerli) {
        // Tek kullanımlık onay imzası — sadece BU tarih + BU müşteri kümesi için geçerli.
        $onay = ParasutYaz::onayImzaUret();
        $_SESSION['irsaliye_onay'] = [
            'token' => $onay,
            'date'  => $date,
            'ids'   => $gecerli,
            'exp'   => time() + 600, // 10 dk
        ];
    }
    Helpers::json([
        'ok' => true, 'tarih' => $date, 'kapali' => $kapali, 'onay' => $onay,
        'satirlar' => $satirlar, 'gecerli_sayi' => count($gecerli), 'toplam_kisi' => $toplamKisi,
    ]);
}

// ── 2) KES: onay imzasını tüket + Paraşüt'e kes (şalter kapalıysa ParasutYaz zaten POST atmaz) ──
if ($action === 'kes') {
    $oturum = $_SESSION['irsaliye_onay'] ?? null;
    unset($_SESSION['irsaliye_onay']); // TEK KULLANIMLIK: çift tık ikinci isteği burada ölür
    $imza = (string) ($body['onay'] ?? '');

    if (!is_array($oturum) || (int) ($oturum['exp'] ?? 0) < time()
        || (string) ($oturum['date'] ?? '') !== $date
        || $imza === '' || !hash_equals((string) ($oturum['token'] ?? ''), $imza)) {
        Helpers::json(['ok' => false, 'error' => 'Onay süresi doldu veya geçersiz — baştan seçin.'], 403);
    }
    // Kesim yalnız onay ekranında ONAYLANAN müşteri kümesi için yapılır (araya id sıkıştırılamaz).
    $izinli = array_map('intval', (array) ($oturum['ids'] ?? []));
    $hedef = array_values(array_intersect($ids ?: $izinli, $izinli));
    if (!$hedef) {
        Helpers::json(['ok' => false, 'error' => 'Onaylanan müşteri listesi boş.'], 400);
    }

    $yaz = new ParasutYaz($repo, (string) $oturum['token']);
    $sonuclar = [];
    $basarili = 0;
    foreach ($hedef as $cid) {
        $a = $adaylar[$cid] ?? null;
        if ($a === null) {
            $sonuclar[] = ['customer_id' => $cid, 'name' => '#' . $cid, 'ok' => false,
                'durum' => 'hata', 'mesaj' => 'Müşteri listede yok.'];
            continue;
        }
        $meals = ['ogle' => $a['ogle'], 'aksam' => $a['aksam'], 'kumanya' => $a['kumanya']];
        $r = $yaz->createShipmentDocument($cid, $date, $meals, [
            'onay'  => $imza,
            'actor' => (string) $u['username'],
        ]);
        if ($r['ok']) {
            $basarili++;
        }
        $sonuclar[] = [
            'customer_id' => $cid,
            'name'        => $a['name'],
            'ok'          => $r['ok'],
            'durum'       => $r['durum'],
            'mesaj'       => $r['mesaj'],
            'despatch_no' => $r['despatch_no'],
            'doc_id'      => $r['doc_id'],
            'parasut_ad'  => $r['parasut_ad'],
            'tasiyici_ok' => $r['tasiyici_ok'],
            'toplam'      => $r['toplam'],
        ];
    }

    uysa_audit('irsaliye_kesim', (string) $u['username'], $date, json_encode([
        'secilen' => count($hedef), 'basarili' => $basarili, 'kapali' => $kapali,
    ], JSON_UNESCAPED_UNICODE), client_ip());

    Helpers::json(['ok' => true, 'tarih' => $date, 'kapali' => $kapali,
        'basarili' => $basarili, 'sonuclar' => $sonuclar]);
}

// ── 3) ÇÖZÜM: "durum bilinmiyor" kilidini insan kararıyla kaldır ──
// Timeout sonrası kayıt kilitlenir (otomatik yeniden deneme YASAK). Kilit çıkmaz sokak
// olmasın diye Ömer Paraşüt'ten bakıp kararı buraya yazar; karar audit'e düşer.
if ($action === 'cozum') {
    $cid = (int) ($body['customer_id'] ?? 0);
    $karar = (string) ($body['karar'] ?? '');
    $log = $cid > 0 ? $repo->irsaliyeLog($cid, $date) : null;
    if ($log === null || (string) $log['durum'] !== 'bilinmiyor') {
        Helpers::json(['ok' => false, 'error' => 'Yalnız "durum bilinmiyor" kaydı çözülebilir.'], 400);
    }
    if (!in_array($karar, ['kesildi', 'kesilmedi'], true)) {
        Helpers::json(['ok' => false, 'error' => 'Geçersiz karar.'], 400);
    }
    $repo->irsaliyeLogKaydet($cid, $date, [
        'parasut_doc_id' => $log['parasut_doc_id'] ?? null,
        'despatch_no'    => $log['despatch_no'] ?? null,
        'toplam_kisi'    => (int) ($log['toplam_kisi'] ?? 0),
        'durum'          => $karar === 'kesildi' ? 'kesildi' : 'hata',
        'hata_mesaj'     => $karar === 'kesildi'
            ? 'Paraşüt\'ten doğrulandı: belge kesilmiş (elle işaretlendi).'
            : 'Paraşüt\'ten doğrulandı: belge YOK — tekrar denenebilir (elle açıldı).',
        'entered_by'     => (string) $u['username'],
    ]);
    uysa_audit('irsaliye_cozum', (string) $u['username'], $date, json_encode([
        'customer_id' => $cid, 'karar' => $karar,
    ], JSON_UNESCAPED_UNICODE), client_ip());
    Helpers::json(['ok' => true, 'karar' => $karar]);
}

Helpers::json(['ok' => false, 'error' => 'Bilinmeyen işlem.'], 400);
