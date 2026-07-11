<?php
declare(strict_types=1);

/**
 * opus-021 — 14:30 sayı hatırlatma push'u (cron: 30 14 * * 1-5, kurulum Fable).
 * Kullanım: php tools/push_reminder.php
 *
 * İDEMPOTENT — her çağrıda güvenli:
 *  - Sadece cihazı (push_token) olan müşteriler değerlendirilir.
 *  - Yarına sipariş/üretim kaydı olan müşteri atlanır (sayısını girmiş).
 *  - Aynı gün push_log'da reminder kaydı olan müşteriye TEKRAR atılmaz (günde 1).
 * APNs yapılandırılmamışsa sessiz no-op (yine loglanır) — cron hata üretmez.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI'dan çalıştırın: php tools/push_reminder.php\n");
}

require __DIR__ . '/../src/bootstrap.php';

use Uysa\Db;
use Uysa\Push;

$push = new Push(Db::pdo());
$r = $push->gunlukHatirlatma();

printf(
    "[%s] push_reminder: %d müşteriye gönderildi · %d sayısını girmiş (atlandı) · %d bugün zaten hatırlatıldı\n",
    date('Y-m-d H:i:s'),
    $r['pushed'],
    $r['skipped_entered'],
    $r['skipped_dup']
);
