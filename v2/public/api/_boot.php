<?php
declare(strict_types=1);

/**
 * İç/bot API ortak bootstrap: X-UYSA-Token auth + rate-limit (v1 deseni).
 * Endpoint'ler bunu include eder; $pdo, $repo, $body hazır gelir.
 */

require __DIR__ . '/../../src/bootstrap.php';

use Uysa\Db;
use Uysa\Env;
use Uysa\Helpers;
use Uysa\Repo;
use Uysa\RateLimiter;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $pdo = Db::pdo();
} catch (\Throwable $e) {
    Helpers::json(['ok' => false, 'error' => 'Veritabanı bağlantı hatası'], 503);
}

$repo = new Repo($pdo);
$ip = client_ip();

// ── Kimlik: X-UYSA-Token == API_TOKEN ────────────────────────
$token = $_SERVER['HTTP_X_UYSA_TOKEN'] ?? ($_GET['token'] ?? '');
$apiToken = (string) Env::get('API_TOKEN', '');

// token endpoint'i başına rate-limit (brute-force koruması)
$rl = new RateLimiter($pdo, 30, 300, 600);
$limit = $rl->attempt('api:' . md5($ip));
if (!$limit['allowed']) {
    header('Retry-After: ' . $limit['retry_after']);
    Helpers::json(['ok' => false, 'error' => 'Çok fazla istek, bekleyin', 'retry_after' => $limit['retry_after']], 429);
}

if ($apiToken === '' || $apiToken === 'change-me-strong-random-token' || !hash_equals($apiToken, (string) $token)) {
    Helpers::json(['ok' => false, 'error' => 'Kimlik doğrulama gerekli (X-UYSA-Token)'], 401);
}
$rl->reset('api:' . md5($ip)); // geçerli token → sayaç sıfırla

$actor = 'bot/api';
$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    $body = [];
}
