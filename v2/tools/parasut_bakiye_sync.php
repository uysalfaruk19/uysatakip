<?php
declare(strict_types=1);

/**
 * fable-046 — Paraşüt CARİ BAKİYE senkronu (SALT-OKUMA).
 *
 * Ömer: "Paraşüt'te müşteriler sekmesinden cari bakiyeyi senkronize götür; oraya tahsilat
 * girdiğimde Kokpit'e de belirli aralıklarla güncellesin."
 *
 * Paraşüt cari'si bağlı (parasut_id dolu) her AKTİF müşterinin GET /contacts/{id} bakiyesini
 * okur, customers.parasut_bakiye + parasut_sync_at alanlarına yazar. Cari > Alacaklarım ekranı
 * bu cache'i okur — sayfa açılışında Paraşüt'e GİTMEZ (hız + rate-limit güvenliği).
 *
 * 🔒 Paraşüt'e HİÇBİR YAZMA YOK (parasut-onay-kuralı): yalnız GET; OAuth token çağrısı hariç.
 * Bakiye gelmezse (alan yok / hata) o müşterinin cache'i KORUNUR — 0 uydurulmaz.
 *
 * Kullanım (konteynerde; kredensiyal şifreli dosyadan çözülür):
 *   php tools/parasut_bakiye_sync.php
 * Cron (VPS host — gider senkronuyla aynı sıklık, 5 dk sonra ki çakışmasın):
 *   40 7,17 * * * docker exec uysatakip-v2 php /var/www/html/tools/parasut_bakiye_sync.php
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Uysa\Db;
use Uysa\Parasut;
use Uysa\Repo;

if (!Parasut::configured()) {
    fwrite(STDERR, "Paraşüt kredensiyali yok/çözülemedi.\n");
    exit(3);
}

$repo = new Repo(Db::pdo());

// Rate limit disiplini: contact başına tek GET + aralarında kısa nefes (Paraşüt 429 backoff'u
// Parasut::get içinde; buradaki bekleme onu tetiklememek için).
$sonuc = $repo->parasutBakiyeSenkron(static function (string $parasutId): ?array {
    usleep(250000); // 250 ms → sn'de en fazla 4 istek
    return Parasut::contactBalance($parasutId);
});

uysa_audit('parasut_bakiye_sync', 'cron', '', json_encode([
    'hedef' => $sonuc['hedef'], 'guncel' => $sonuc['guncel'],
    'atlanan' => $sonuc['atlanan'], 'hata' => $sonuc['hata'],
], JSON_UNESCAPED_UNICODE), '');

printf("Paraşüt bakiye senkronu: %d müşteri · %d güncellendi · %d bakiye gelmedi (cache korundu) · %d hata\n",
    $sonuc['hedef'], $sonuc['guncel'], $sonuc['atlanan'], $sonuc['hata']);
foreach ($sonuc['detay'] as $d) {
    fwrite(STDERR, '  · ' . $d . "\n");
}

exit($sonuc['hata'] > 0 ? 1 : 0);
