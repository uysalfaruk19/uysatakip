<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Uysa\CustomerAuth;
use Uysa\Db;
use Uysa\Helpers;

/**
 * Native app (kokpit-ios) push token kaydı — müşteri oturumu zorunlu.
 * Body: {"token":"<apns hex>","platform":"ios"}
 */
CustomerAuth::startSession();
$c = CustomerAuth::customer();
if (!$c) {
    Helpers::json(['ok' => false, 'error' => 'Giriş gerekli'], 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Helpers::json(['ok' => false, 'error' => 'POST bekleniyor'], 405);
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
$token = is_array($body) ? trim((string) ($body['token'] ?? '')) : '';
$platform = is_array($body) ? strtolower(trim((string) ($body['platform'] ?? 'ios'))) : 'ios';

if (!preg_match('/^[0-9a-f]{32,200}$/i', $token) || !in_array($platform, ['ios', 'android'], true)) {
    Helpers::json(['ok' => false, 'error' => 'Geçersiz token'], 422);
}

$pdo = Db::pdo();
$pdo->prepare(
    'INSERT INTO push_tokens (platform, token, customer_id, cuid)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE customer_id = VALUES(customer_id), cuid = VALUES(cuid), last_seen = NOW()'
)->execute([$platform, strtolower($token), $c['customer_id'], $c['cuid']]);

Helpers::json(['ok' => true]);
