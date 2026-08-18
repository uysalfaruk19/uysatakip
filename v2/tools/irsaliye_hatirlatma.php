<?php

declare(strict_types=1);

/**
 * fable-085 (Ömer, 18 Ağu): "günlük 23:00'te irsaliye kesilmediyse hâlâ bildirim düşsün."
 *
 * Gün sonunda İŞ YAPILMIŞ ama İRSALİYESİ KESİLMEMİŞ müşteri kaldıysa bildirim gönderir.
 * e-İrsaliye aynı gün kesilmek zorundadır; gün kapanınca telafisi yoktur — bu yüzden
 * "sessizce geçti" hâli en pahalı arıza sınıfıdır.
 *
 * NE ZAMAN UYARIR: o gün üretim kaydı OLAN (yani gerçekten yemek çıkmış), irsaliyesi aktif,
 * aktif müşterilerden log'unda 'kesildi' bulunmayanlar. Üretim kaydı yoksa o müşteri için
 * kesilecek bir şey yoktur → UYARMAZ (cumartesi/tatil yanlış alarmı olmaz).
 *
 * NE YAPMAZ: irsaliye KESMEZ. Geri dönülmez/üçüncü parti katmana otomatik müdahale yok —
 * karar insanda kalır (CLAUDE.md öz-onarım ilkesi (c)).
 *
 * İDEMPOTENT: aynı gün ikinci kez bildirim atmaz (push_log 'irsaliye_uyari' kaydı).
 * Bildirim 'kritik' türündedir → sessiz saat (21:00–07:00) muafiyeti vardır.
 *
 * Kullanım:  php tools/irsaliye_hatirlatma.php [--kuru]
 * Cron (VPS host UTC; TR 23:00 = UTC 20:00):
 *   0 20 * * * root docker exec uysatakip-v2 php /var/www/html/tools/irsaliye_hatirlatma.php
 * Kill-switch: cron dosyasını sil (script tek başına zararsız — yalnız okur ve bildirir).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI'dan çalıştırın: php tools/irsaliye_hatirlatma.php\n");
}

require __DIR__ . '/../src/bootstrap.php';

use Uysa\Db;
use Uysa\Push;
use Uysa\Repo;

$kuru = in_array('--kuru', array_slice($argv, 1), true);
$pdo = Db::pdo();
$repo = new Repo($pdo);
$gun = date('Y-m-d');

// O gün ÜRETİM KAYDI OLAN, irsaliyesi aktif, aktif müşterilerden irsaliyesi kesilmemiş olanlar.
$st = $pdo->prepare(
    "SELECT c.id, c.name, COALESCE(SUM(p.persons), 0) AS kisi
     FROM customers c
     JOIN production p ON p.customer_id = c.id AND p.prod_date = ?
     LEFT JOIN parasut_irsaliye_log l
            ON l.customer_id = c.id AND l.gun = ? AND l.durum = 'kesildi'
     WHERE c.is_active = 1 AND c.irsaliye_aktif = 1 AND l.id IS NULL
     GROUP BY c.id, c.name
     HAVING kisi > 0
     ORDER BY kisi DESC"
);
$st->execute([$gun, $gun]);
$eksik = $st->fetchAll();

if (!$eksik) {
    printf("[%s] irsaliye_hatirlatma: %s — kesilmemiş irsaliye YOK, bildirim gönderilmedi.\n",
        date('Y-m-d H:i:s'), $gun);
    exit(0);
}

$adlar = [];
$toplamKisi = 0;
foreach ($eksik as $e) {
    $adlar[] = (string) $e['name'];
    $toplamKisi += (int) $e['kisi'];
}
$ozet = implode(' · ', $adlar);

printf("[%s] irsaliye_hatirlatma: %s — %d müşteride irsaliye KESİLMEMİŞ (%d kişi): %s\n",
    date('Y-m-d H:i:s'), $gun, count($eksik), $toplamKisi, $ozet);

if ($kuru) {
    echo "  [PROVA] bildirim gönderilmedi.\n";
    exit(0);
}

// İdempotanlık: aynı gün bu uyarı bir kez gider.
$ref = 'irsaliye_uyari:' . $gun;
try {
    $var = $pdo->prepare("SELECT COUNT(*) FROM push_log WHERE ref = ? AND suppressed = 0");
    $var->execute([$ref]);
    if ((int) $var->fetchColumn() > 0) {
        echo "  Bugün zaten bildirildi — tekrar gönderilmedi.\n";
        exit(0);
    }
} catch (\PDOException $e) {
    // push_log yoksa/şema farklıysa bildirimi engelleme; tekrar riski, sessizlikten iyidir.
    error_log('[UYSA v2 irsaliye_hatirlatma] push_log okunamadı: ' . $e->getMessage());
}

$push = new Push($pdo);
$baslik = count($eksik) . ' müşteride irsaliye kesilmedi';
$govde = sprintf('%s · %d kişi. Gün kapanmadan kesilmeli: %s',
    date('d.m.Y', (int) strtotime($gun)), $toplamKisi, $ozet);

$r = $push->toAdmins($baslik, $govde, ['url' => '/bugun.php'], 'kritik', $ref);

printf("  Bildirim: %d cihaz · %d gönderildi%s%s\n",
    (int) $r['devices'], (int) $r['sent'],
    !empty($r['suppressed']) ? ' · SESSİZ SAATTE BASTIRILDI (beklenmiyordu)' : '',
    (int) $r['devices'] === 0 ? ' · UYARI: kayıtlı yönetici cihazı yok, bildirim kimseye gitmedi' : '');

uysa_audit('irsaliye_uyari', 'cron', $gun, json_encode([
    'musteri' => $adlar, 'kisi' => $toplamKisi, 'cihaz' => (int) $r['devices'], 'gonderilen' => (int) $r['sent'],
], JSON_UNESCAPED_UNICODE), 'local');

exit(0);
