<?php
declare(strict_types=1);

/**
 * fable-030 — Paraşüt GİDER senkronu (SALT-OKUMA; Hikari kanıtlı deseni).
 *
 * Paraşüt'ten ayın alış faturalarını (içeri alınmış purchase_bills + e-fatura GELEN KUTUSU)
 * okur, Kokpit transactions'a 'gider' olarak işler. Paraşüt'e HİÇBİR yazma yok.
 * Mükerrer kalkanı: transactions.parasut_id UNIQUE + ei-/pb çift-sayım köprüsü.
 *
 * Kullanım (konteynerde; kredensiyal şifreli dosyadan çözülür):
 *   php tools/parasut_gider_sync.php            # bu ay
 *   php tools/parasut_gider_sync.php 2026-06    # belirli ay
 * Cron (VPS host): 35 7,17 * * * docker exec uysatakip-v2 php /var/www/html/tools/parasut_gider_sync.php
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Uysa\Db;
use Uysa\Parasut;
use Uysa\Repo;

$ay = $argv[1] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
    fwrite(STDERR, "ay=YYYY-MM bekleniyor\n");
    exit(2);
}

if (!Parasut::configured()) {
    fwrite(STDERR, "Paraşüt kredensiyali yok/çözülemedi.\n");
    exit(3);
}

$repo = new Repo(Db::pdo());

try {
    $pb = Parasut::purchaseBillsForMonth($ay);
    $ei = Parasut::inboundEInvoicesForMonth($ay);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Paraşüt okuma hatası: ' . $e->getMessage() . "\n");
    exit(4);
}

// İçeri alınmış e-faturalar pb yolundan gelir → kutu listesinden atlanır (çift sayım köprüsü).
$eiBekleyen = array_values(array_filter($ei['bills'], static fn(array $b) => empty($b['iceri_alinmis'])));
$sonuc = $repo->parasutGiderIsle(array_merge($pb, $eiBekleyen));

uysa_audit('gider_parasut', 'cron', $ay, json_encode([
    'pb' => count($pb), 'kutu' => count($eiBekleyen), 'iade' => $ei['iade'],
    'yeni' => $sonuc['yeni'], 'mevcut' => $sonuc['mevcut'], 'tutar' => $sonuc['tutar'],
], JSON_UNESCAPED_UNICODE), '');

printf("Paraşüt gider senkronu (%s): %d yeni gider · ₺%s · %d zaten vardı · kaynak: %d içeri-alınmış + %d gelen-kutusu%s\n",
    $ay, $sonuc['yeni'], number_format($sonuc['tutar'], 2, ',', '.'), $sonuc['mevcut'],
    count($pb), count($eiBekleyen), $ei['iade'] > 0 ? ' · ' . $ei['iade'] . ' iade ATLANDI (elle düşün)' : '');

// fable-044: gider tx'lerinin ÜRÜN SATIRLARINI (UBL) gider_kalem'e idempotent yaz (top-10 + birim fiyat).
try {
    $kalem = $repo->giderKalemSenkron($ay);
    uysa_audit('gider_kalem_parasut', 'cron', $ay, json_encode($kalem, JSON_UNESCAPED_UNICODE), '');
    printf("Ürün kalemi senkronu (%s): %d aday e-fatura · %d işlendi (%d satır) · %d zaten vardı · %d UBL okunamadı\n",
        $ay, $kalem['aday'], $kalem['islenen'], $kalem['kalem'], $kalem['atlanan'], $kalem['okunamadi']);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Ürün kalemi senkron hatası (gider senkronu tamam, sadece kalem atlandı): ' . $e->getMessage() . "\n");
}
