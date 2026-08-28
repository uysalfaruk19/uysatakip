<?php

declare(strict_types=1);

/**
 * fable-102: Fiyat erozyonu bekçisi (Hikari deseni). Tedarikçi faturalarındaki ürün birim
 * fiyatları son N ayda eşiği aşarak zamlandıysa WhatsApp uyarısı üretir (stdout). Gönderimi
 * kabuk yapar. Maliyet artışını fatura toplamına bakmadan kalem bazında önceden görmek için.
 *
 * Kullanım: php fiyat_erozyon_bekci.php [ay=3] [esik=20]
 */

require __DIR__ . '/../src/bootstrap.php';

$ay = isset($argv[1]) ? max(1, (int) $argv[1]) : 3;
$esik = isset($argv[2]) ? (float) $argv[2] : 20.0;

$repo = new Uysa\Repo(Uysa\Db::pdo());
$liste = $repo->fiyatErozyonu($ay, $esik);

printf("[%s] fiyat erozyonu: son %d ay · eşik %%%s · %d ürün eşik üstü\n",
    date('Y-m-d'), $ay, rtrim(rtrim(number_format($esik, 1), '0'), '.'), count($liste));
foreach ($liste as $x) {
    printf("  %-28s %s→%s %s  +%%%s\n", mb_substr($x['urun'], 0, 28),
        number_format($x['min'], 2, ',', '.'), number_format($x['son'], 2, ',', '.'),
        $x['birim'], $x['artis']);
}

if (!$liste) {
    exit(0);   // eşik üstü zam yok = sus
}

$sat = ['UYSA fiyat uyarısı — son ' . $ay . ' ayda zamlanan tedarikçi ürünleri (>%'
    . rtrim(rtrim(number_format($esik, 1), '0'), '.') . '):'];
foreach (array_slice($liste, 0, 12) as $x) {
    $sat[] = sprintf('- %s: %s → %s %s (+%%%s)', mb_substr($x['urun'], 0, 26),
        number_format($x['min'], 2, ',', '.'), number_format($x['son'], 2, ',', '.'),
        $x['birim'], $x['artis']);
}
if (count($liste) > 12) {
    $sat[] = '...+' . (count($liste) - 12) . ' ürün daha';
}
echo "\n__WHATSAPP__\n" . implode("\n", $sat) . "\n";
exit(1);
