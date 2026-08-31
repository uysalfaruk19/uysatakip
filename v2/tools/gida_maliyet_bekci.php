<?php

declare(strict_types=1);

/**
 * fable-100 (Ömer, 28 Ağu): ay sonu gıda maliyeti kapanış bekçisi. Bir ay kapandıktan sonra o
 * ayın kişi başı gıda maliyetini bir önceki ayla kıyaslar; ÇOK saparsa "eksik fatura olabilir"
 * uyarısı üretir. Amaç: bu ay 28 Ağu'da yaşanan "gıda/kişi 17,46 (Temmuz 75)" durumu — bir
 * kanalın faturaları hiç işlenmemişti — bir daha sessiz kalmasın, sistem kendisi söylesin.
 *
 * Çıktı: uyarı varsa metin (stdout), yoksa boş. Gönderimi kabuk yapar (WhatsApp).
 * Kullanım: php gida_maliyet_bekci.php [YYYY-MM]   (varsayılan: bir önceki ay)
 */

require __DIR__ . '/../src/bootstrap.php';

$p = Uysa\Db::pdo();

$ay = $argv[1] ?? date('Y-m', strtotime('first day of last month'));
if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
    fwrite(STDERR, "ay=YYYY-MM bekleniyor\n");
    exit(2);
}
$oncekiAy = date('Y-m', strtotime($ay . '-01 -1 month'));

// Gıda-map tedarikçileri
$harita = [];
foreach ($p->query('SELECT tedarikci FROM tedarikci_gida_map') as $r) {
    $harita[mb_strtoupper(trim((string) $r['tedarikci']), 'UTF-8')] = 1;
}

$gidaKisi = static function (string $ay) use ($p, $harita): array {
    $bas = $ay . '-01';
    $son = date('Y-m-t', strtotime($bas));
    $u = (int) $p->query("SELECT COALESCE(SUM(persons),0) FROM production WHERE prod_date BETWEEN '$bas' AND '$son'")->fetchColumn();
    $g = 0.0;
    foreach ($p->query("SELECT description, amount FROM transactions WHERE type='gider' AND tx_date BETWEEN '$bas' AND '$son'") as $r) {
        $ad = mb_strtoupper((string) $r['description'], 'UTF-8');
        foreach ($harita as $h => $_) {
            if ($h !== '' && mb_strpos($ad, $h) !== false) {
                $g += (float) $r['amount'];
                break;
            }
        }
    }
    return ['uretim' => $u, 'gida' => $g, 'kisi_basi' => $u > 0 ? $g / $u : 0.0];
};

$bu = $gidaKisi($ay);
$onceki = $gidaKisi($oncekiAy);

// Kıyas yalnız önceki ayda anlamlı veri varsa
if ($onceki['kisi_basi'] < 1 || $bu['uretim'] < 1) {
    printf("[%s] gıda bekçi: kıyas için yetersiz veri (bu %s / önceki %s kişi başı) — sessiz.\n",
        date('Y-m-d'), number_format($bu['kisi_basi'], 2), number_format($onceki['kisi_basi'], 2));
    exit(0);
}

$sapmaYuzde = (int) round((($bu['kisi_basi'] - $onceki['kisi_basi']) / $onceki['kisi_basi']) * 100);
$esik = 15;   // ±%15'ten fazla sapma = incele (Ömer 31 Ağu: Ağustos %22,9 düştü ve %25 eşiğinin
         // kıl payı altında kaldığı için uyarı vermeyecekti — eşik düşürüldü)

printf("[%s] gıda bekçi: %s kişi başı %s TL · %s (önceki) %s TL · sapma %+d%%\n",
    date('Y-m-d'), $ay, number_format($bu['kisi_basi'], 2, ',', '.'),
    $oncekiAy, number_format($onceki['kisi_basi'], 2, ',', '.'), $sapmaYuzde);

if (abs($sapmaYuzde) <= $esik) {
    printf("  eşik içinde (±%d%%) — sessiz.\n", $esik);
    exit(0);
}

// Sapma büyük → uyarı metni (WhatsApp'a). Düşükse "eksik fatura", yüksekse "kontrol et".
$yon = $sapmaYuzde < 0 ? 'DÜŞÜK — bir tedarikçi kanalının faturaları eksik olabilir' : 'YÜKSEK — kontrol edin';
$metin = sprintf(
    "UYSA gıda maliyeti uyarısı (%s):\nKişi başı gıda %s TL — bir önceki aya (%s TL) göre %+d%% %s.\nÜretim %d kişi · gıda %s TL.",
    date('m.Y', strtotime($ay . '-01')),
    number_format($bu['kisi_basi'], 2, ',', '.'),
    number_format($onceki['kisi_basi'], 2, ',', '.'),
    $sapmaYuzde, $yon,
    $bu['uretim'], number_format($bu['gida'], 2, ',', '.')
);
echo "\n__WHATSAPP__\n" . $metin . "\n";
exit(1);
