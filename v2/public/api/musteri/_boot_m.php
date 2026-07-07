<?php
declare(strict_types=1);

/**
 * Müşteri API ortak bootstrap: AYRI müşteri oturumu (uysa_musteri) + rate-limit + JSON.
 * Her müşteri endpoint'i bunu include eder; $pdo, $repo, $cu, $cid, $body hazır gelir.
 * IDOR: $cid daima oturumdaki customer_id — endpoint'ler bununla scope'lar. İç personel
 * oturumu (uysa_kokpit) BURADA geçersiz; sadece customer_users girişi kabul edilir.
 */

require __DIR__ . '/../../../src/bootstrap.php';

use Uysa\CustomerAuth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\RateLimiter;
use Uysa\Repo;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

CustomerAuth::startSession();

try {
    $pdo = Db::pdo();
} catch (\Throwable $e) {
    Helpers::json(['ok' => false, 'error' => 'Veritabanı bağlantı hatası'], 503);
}
$repo = new Repo($pdo);
$ip = client_ip();

$cu = CustomerAuth::customer();
if (!$cu) {
    Helpers::json(['ok' => false, 'error' => 'Oturum gerekli — müşteri girişi yapın'], 401);
}
$cid = (int) $cu['customer_id'];

// Oturum başına rate-limit (kötüye kullanım koruması)
$rl = new RateLimiter($pdo, 60, 300, 300);
$rlKey = 'mapi:' . md5($cid . ':' . $ip);
$limit = $rl->attempt($rlKey);
if (!$limit['allowed']) {
    header('Retry-After: ' . $limit['retry_after']);
    Helpers::json(['ok' => false, 'error' => 'Çok fazla istek, bekleyin', 'retry_after' => $limit['retry_after']], 429);
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    $body = [];
}

// Cookie tabanlı oturum → POST'ta CSRF zorunlu (X-CSRF-Token header veya body/form 'csrf').
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['csrf'] ?? ($_POST['csrf'] ?? null));
    if (!Helpers::csrfCheck(is_string($token) ? $token : null)) {
        Helpers::json(['ok' => false, 'error' => 'CSRF doğrulaması başarısız'], 403);
    }
}
