<?php

declare(strict_types=1);

/**
 * fable-097 (Ömer, 28 Ağu): "faturaları otomatik çektirt Kokpit'e işle hallet hepsini."
 *
 * EDM/mail kanalından gelen tedarikçi faturalarını Kokpit gider (transactions) tablosuna yazar.
 * Paraşüt gelen kutusu zaten otomatik akıyor; bu kanal (BALCI/ÖRS/YOPA/POLATOĞLU/AZİZ/İSTANBUL/
 * YOLPET) yalnız maile geliyordu, Ömer ay sonu elle giriyordu.
 *
 * Format Temmuz'un elle girilenleriyle BİREBİR:
 *   type='gider' · category='Tedarikçi faturası' · amount=KDV DAHİL toplam · tx_date=fatura tarihi
 *   parasut_id='xls-<FATURANO>' (idempotency) · description='<gıda-map adı> · <no>'
 *
 * ⚠️ Description'da gıda-map ANAHTARI kullanılır: gıda maliyeti eşleşmesi tedarikçi adını map
 * anahtarıyla substring karşılaştırıyor. Maildeki ad farklı yazıldığı için map anahtarı yazılır
 * ki fatura gıda maliyetine doğru düşsün.
 *
 * Kullanım: php edm_gider_isle.php <json> [--uygula]   (varsayılan KURU PROVA)
 */

require __DIR__ . '/../src/bootstrap.php';

$jsonYol = $argv[1] ?? '/var/www/gonder/edm_faturalar.json';
$uygula = in_array('--uygula', $argv, true);
$p = Uysa\Db::pdo();

$kayitlar = json_decode((string) file_get_contents($jsonYol), true);
if (!is_array($kayitlar)) {
    exit("JSON okunamadı: $jsonYol\n");
}

// Fatura öneki → (gıda-map tam anahtar VEYA gıda değilse kendi adı, kırılım)
// Map anahtarları tedarikci_gida_map'ten okunur; önek → ayırt edici token ile eşlenir.
$mapRows = [];
foreach ($p->query('SELECT tedarikci, kirilim_kod FROM tedarikci_gida_map') as $r) {
    $mapRows[trim((string) $r['tedarikci'])] = (string) $r['kirilim_kod'];
}
function mapBul(array $mapRows, string $token): ?array
{
    foreach ($mapRows as $ad => $kod) {
        if (mb_stripos($ad, $token) !== false) {
            return ['ad' => $ad, 'kod' => $kod];
        }
    }
    return null;
}
$onekMap = [
    'MM0' => mapBul($mapRows, 'BALCI'),
    'ORS' => mapBul($mapRows, 'ÖRS GROUP'),
    'YOP' => mapBul($mapRows, 'YOPA'),
    'BYY' => mapBul($mapRows, 'AZİZ BOZDEMİR'),
    'PLT' => mapBul($mapRows, 'POLATOĞLU'),
    'IHB' => mapBul($mapRows, 'İSTANBUL HALK'),
    'YPE' => ['ad' => 'YOLPET PETROL GIDA SANAYİ VE TİCARET LİMİTED ŞİRKETİ', 'kod' => null], // petrol, gıda değil
];

// gıda map (eşleşme testi için)
$gidaAnahtar = array_keys($mapRows);

$yazilacak = [];
$atla = [];
$mevcutSay = 0;
$kontrol = $p->prepare("SELECT COUNT(*) FROM transactions WHERE parasut_id = ?");

foreach ($kayitlar as $k) {
    $no = (string) ($k['no'] ?? '');
    $tarih = (string) ($k['tarih'] ?? '');
    $tutar = (float) ($k['tutar'] ?? 0);
    $onek = substr($no, 0, 3);
    $bilgi = $onekMap[$onek] ?? null;
    if ($no === '' || $tutar <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) {
        $atla[] = [$no, 'geçersiz veri'];
        continue;
    }
    if ($bilgi === null) {
        $atla[] = [$no, "tanınmayan önek '$onek' — elle bak"];
        continue;
    }
    $pid = 'xls-' . $no;
    $kontrol->execute([$pid]);
    if ((int) $kontrol->fetchColumn() > 0) {
        $mevcutSay++;
        continue; // idempotency: zaten var
    }
    $desc = $bilgi['ad'] . ' · ' . $no;
    // gıda maliyetine düşer mi? (description map anahtarını içeriyor mu)
    $up = mb_strtoupper($desc, 'UTF-8');
    $gida = false;
    foreach ($gidaAnahtar as $h) {
        if ($h !== '' && mb_strpos($up, mb_strtoupper($h, 'UTF-8')) !== false) {
            $gida = true;
            break;
        }
    }
    $yazilacak[] = ['no' => $no, 'tarih' => $tarih, 'tutar' => $tutar, 'desc' => $desc,
        'pid' => $pid, 'kirilim' => $bilgi['kod'], 'gida' => $gida];
}

$topGida = 0.0;
$topHepsi = 0.0;
printf("=== EDM GİDER İŞLEME %s ===\n", $uygula ? '(GERÇEK YAZIM)' : '(KURU PROVA)');
printf("  yazılacak: %d · zaten var: %d · atlanan: %d\n\n", count($yazilacak), $mevcutSay, count($atla));
foreach ($yazilacak as $y) {
    printf("  %s  %-20s %11s TL  %-11s %s\n", $y['tarih'], $y['no'],
        number_format($y['tutar'], 2, ',', '.'), $y['gida'] ? 'gıda:' . $y['kirilim'] : 'GIDA DEĞİL',
        mb_substr($y['desc'], 0, 30));
    $topHepsi += $y['tutar'];
    if ($y['gida']) {
        $topGida += $y['tutar'];
    }
}
foreach ($atla as $a) {
    printf("  ATLANDI %s: %s\n", $a[0], $a[1]);
}
printf("\n  Toplam yazılacak: %s TL (gıda sayılan: %s TL)\n",
    number_format($topHepsi, 2, ',', '.'), number_format($topGida, 2, ',', '.'));

if (!$uygula) {
    echo "\n  [KURU PROVA] hiçbir kayıt yazılmadı. Gerçek yazım: --uygula\n";
    exit(0);
}

$ins = $p->prepare(
    "INSERT INTO transactions (type, category, tx_date, amount, description, source, parasut_id, alloc_type)
     VALUES ('gider', 'Tedarikçi faturası', ?, ?, ?, 'mail', ?, 'genel')"
);
$n = 0;
$p->beginTransaction();
try {
    foreach ($yazilacak as $y) {
        $ins->execute([$y['tarih'], $y['tutar'], $y['desc'], $y['pid']]);
        $n++;
    }
    $p->commit();
} catch (\Throwable $e) {
    $p->rollBack();
    exit("\n  HATA — hiçbiri yazılmadı (geri alındı): " . $e->getMessage() . "\n");
}
uysa_audit('edm_gider_isle', 'uysal', date('Y-m'), json_encode([
    'yazilan' => $n, 'toplam' => $topHepsi, 'gida' => $topGida, 'kaynak' => 'mail/EDM',
], JSON_UNESCAPED_UNICODE), 'local');
printf("\n  ✓ %d fatura gidere yazıldı (%s TL).\n", $n, number_format($topHepsi, 2, ',', '.'));
