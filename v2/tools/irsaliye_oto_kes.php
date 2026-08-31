<?php

declare(strict_types=1);

/**
 * fable-086 (Ömer, 18 Ağu): "13:00'te hatırlatma, 16:00'da hâlâ kesilmediyse OTOMATİK kessin."
 *
 * ⚠️ Bu script GERİ DÖNÜLMEZ iş yapar: e-İrsaliye GİB'e gider, iptali ayrı bir süreçtir.
 * Standart kural "geri dönülmez katmana otomatik müdahale yok" idi; Ömer 18 Ağu'da bilerek
 * bu istisnayı istedi. Bu yüzden kesim DAR KAPIDAN geçirilir:
 *
 *   1) Yalnız o gün ÜRETİM KAYDI OLAN müşteriler (yemek gerçekten çıkmış).
 *   2) Yalnız irsaliyeAdaylari'nda 'secilebilir' olanlar — cari/adres/kilit sorunu olan
 *      müşteriye DOKUNULMAZ, ekranın kesmeyi reddettiği hiçbir şey otomatik kesilmez.
 *   3) Mükerrer kalkanı ParasutYaz içinde (yerel log + Paraşüt sorgusu + cari teyidi).
 *   4) Şalter: ayar `irsaliye_oto_kesim` = 0 yapılırsa hiç kesmez (kill-switch).
 *   5) Sonuç HER HÂLDE bildirilir — kesilen de, kesilemeyen de push olarak düşer.
 *
 * SAAT NOTU: Ömer önce 23:00 dedi, sonra 16:00'ya çekti; 28 Ağu'da 12:30'a aldı — kesim artık
 * ASIL YOL (hatırlatma değil). Sıra tersine döndü: önce oto kesim (12:30), sonra 16:00'da
 * Paraşüt'ten sonuç kontrolü (irsaliye_parasut_dogrula.php). 13:00 hatırlatması kaldırıldı:
 * kesim ondan önce olduğu için "hatırlat" adımı gürültüye dönüşüyordu.
 *
 * Kullanım:  php tools/irsaliye_oto_kes.php [--kuru]
 * Cron (host UTC; TR 12:30 = UTC 09:30):
 *   30 9 * * * root docker exec uysatakip-v2 php /var/www/html/tools/irsaliye_oto_kes.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI'dan çalıştırın: php tools/irsaliye_oto_kes.php\n");
}

require __DIR__ . '/../src/bootstrap.php';

use Uysa\Db;
use Uysa\ParasutYaz;
use Uysa\Push;
use Uysa\Repo;

$kuru = in_array('--kuru', array_slice($argv, 1), true);
$pdo = Db::pdo();
$repo = new Repo($pdo);
$gun = date('Y-m-d');
$damga = date('Y-m-d H:i:s');

if (!ParasutYaz::aktif()) {
    printf("[%s] oto_kes: ana şalter KAPALI (PARASUT_IRSALIYE_AKTIF) — işlem yok.\n", $damga);
    exit(0);
}
if ($repo->ayar('irsaliye_oto_kesim', '1') !== '1') {
    printf("[%s] oto_kes: otomatik kesim ayardan KAPATILMIŞ (irsaliye_oto_kesim=0) — işlem yok.\n", $damga);
    exit(0);
}

// O gün üretim kaydı olan ve irsaliyesi HENÜZ kesilmemiş müşteriler
$st = $pdo->prepare(
    "SELECT c.id
     FROM customers c
     JOIN production p ON p.customer_id = c.id AND p.prod_date = ?
     LEFT JOIN parasut_irsaliye_log l
            ON l.customer_id = c.id AND l.gun = ? AND l.durum = 'kesildi'
     WHERE c.is_active = 1 AND c.irsaliye_aktif = 1 AND l.id IS NULL
     GROUP BY c.id
     HAVING COALESCE(SUM(p.persons), 0) > 0"
);
$st->execute([$gun, $gun]);
$hedefIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

if (!$hedefIds) {
    printf("[%s] oto_kes: %s — kesilmemiş irsaliye YOK.\n", $damga, $gun);
    exit(0);
}

// Ekranın kullandığı aday listesi: 'secilebilir' olmayanı OTOMATİK KESMEYİZ.
$adaylar = [];
foreach ($repo->irsaliyeAdaylari($gun) as $a) {
    $adaylar[(int) $a['customer_id']] = $a;
}

$kesilecek = [];
$atlanan = [];
foreach ($hedefIds as $cid) {
    $a = $adaylar[$cid] ?? null;
    if ($a === null) {
        $atlanan[] = ['ad' => '#' . $cid, 'sebep' => 'aday listesinde yok'];
        continue;
    }
    if (empty($a['secilebilir'])) {
        $atlanan[] = ['ad' => (string) $a['name'], 'sebep' => (string) ($a['sebep'] ?? 'kesilemez')];
        continue;
    }
    $kesilecek[] = $a;
}

printf("[%s] oto_kes: %s — kesilecek %d · atlanan %d\n", $damga, $gun, count($kesilecek), count($atlanan));
foreach ($atlanan as $x) {
    printf("  ATLANDI  %-18s %s\n", mb_substr($x['ad'], 0, 18), $x['sebep']);
}

if ($kuru) {
    foreach ($kesilecek as $a) {
        printf("  [PROVA]  %-18s öğle %d · akşam %d · kumanya %d\n", mb_substr((string) $a['name'], 0, 18),
            (int) $a['ogle'], (int) $a['aksam'], (int) $a['kumanya']);
    }
    echo "  [PROVA] hiçbir irsaliye kesilmedi.\n";
    exit(0);
}

$onay = bin2hex(random_bytes(16));
$yaz = new ParasutYaz($repo, $onay);
$basarili = [];
$basarisiz = [];
foreach ($kesilecek as $a) {
    $cid = (int) $a['customer_id'];
    $r = $yaz->createShipmentDocument($cid, $gun, [
        'ogle' => (int) $a['ogle'], 'aksam' => (int) $a['aksam'], 'kumanya' => (int) $a['kumanya'],
    ], ['onay' => $onay, 'actor' => 'oto-kesim']);
    $ad = (string) $a['name'];
    if (!empty($r['ok'])) {
        $basarili[] = $ad;
        printf("  KESİLDİ  %-18s %s\n", mb_substr($ad, 0, 18), (string) ($r['despatch_no'] ?? '—'));
    } else {
        $basarisiz[] = ['ad' => $ad, 'mesaj' => (string) ($r['mesaj'] ?? $r['durum'] ?? 'bilinmeyen hata')];
        printf("  HATA     %-18s %s\n", mb_substr($ad, 0, 18), (string) ($r['mesaj'] ?? ''));
    }
    uysa_audit('irsaliye_oto_kesim', 'cron', $gun, json_encode([
        'musteri' => $ad, 'ok' => !empty($r['ok']), 'durum' => $r['durum'] ?? '',
        'no' => $r['despatch_no'] ?? '',
    ], JSON_UNESCAPED_UNICODE), 'local');
    sleep(2);   // Paraşüt hız sınırı
}

// Sonuç HER HÂLDE bildirilir — sessiz otomatik kesim olmaz.
$parca = [];
if ($basarili) {
    $parca[] = count($basarili) . ' kesildi: ' . implode(' · ', $basarili);
}
if ($basarisiz) {
    $parca[] = count($basarisiz) . ' KESİLEMEDİ: '
        . implode(' · ', array_map(static fn(array $x): string => $x['ad'] . ' (' . $x['mesaj'] . ')', $basarisiz));
}
if ($atlanan) {
    $parca[] = count($atlanan) . ' atlandı: '
        . implode(' · ', array_map(static fn(array $x): string => $x['ad'] . ' (' . $x['sebep'] . ')', $atlanan));
}

$push = new Push($pdo);
$baslik = $basarisiz || $atlanan
    ? 'İrsaliyeler kesildi (' . count($basarili) . ') · ' . (count($basarisiz) + count($atlanan)) . ' sorunlu'
    : 'İrsaliyeler kesildi (' . count($basarili) . ')';
$r = $push->toAdmins($baslik, date('d.m.Y', (int) strtotime($gun)) . ' · ' . implode(' | ', $parca),
    ['url' => '/bugun.php'], 'kritik', 'irsaliye_oto:' . $gun);
printf("  Bildirim: %d cihaz · %d gönderildi\n", (int) $r['devices'], (int) $r['sent']);

exit($basarisiz ? 1 : 0);
