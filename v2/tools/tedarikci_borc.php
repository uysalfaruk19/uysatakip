<?php

declare(strict_types=1);

/**
 * fable-105: Bir tedarikçinin güncel borcunu TEK SAYI olarak basar (ekstre nöbetçisi kullanır).
 * Borç tanımı `borclarimListe()` ile birebir aynı (devir + fatura − ödeme) — nöbetçinin
 * karşılaştırdığı rakam, Cari > Borçlarım ekranındaki rakamla aynı kaynaktan gelsin diye.
 *
 * Kullanım: php tedarikci_borc.php "POLATOĞLU"     → 39361.72
 * Bulunamazsa boş basar (nöbetçi bunu "okunamadı" sayar).
 */

require __DIR__ . '/../src/bootstrap.php';

$ara = trim((string) ($argv[1] ?? ''));
if ($ara === '') {
    exit(1);
}

$repo = new Uysa\Repo(Uysa\Db::pdo());
$araUp = mb_strtoupper($ara, 'UTF-8');

foreach ($repo->borclarimListe() as $g) {
    $ad = mb_strtoupper((string) $g['label'], 'UTF-8');
    if (mb_strpos($ad, $araUp) !== false) {
        printf("%.2f\n", (float) $g['kalan']);
        exit(0);
    }
}
exit(1);
