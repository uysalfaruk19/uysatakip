<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;

/**
 * Native app (kokpit-ios) push token kaydı — ADMİN oturumu zorunlu (opus-021).
 * m/push-register.php deseninin admin'lisi. Body: {"token":"<apns hex>","platform":"ios"}
 */
Auth::startSession();
$u = Auth::user();
if (!$u) {
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
$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$seenAt = date('Y-m-d H:i:s'); // push_log ile aynı Europe/Istanbul saat ekseni
// Token sahipliği admin'e geçer: customer_id/cuid temizlenir (aynı cihaz iki guard'a kayıtlıysa çift push olmasın)
$onConf = $sqlite
    ? 'ON CONFLICT(token) DO UPDATE SET user_id = excluded.user_id, customer_id = NULL, cuid = NULL, last_seen = excluded.last_seen'
    : 'ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), customer_id = NULL, cuid = NULL, last_seen = VALUES(last_seen)';
$pdo->prepare(
    "INSERT INTO push_tokens (platform, token, user_id, last_seen) VALUES (?, ?, ?, ?) $onConf"
)->execute([$platform, strtolower($token), (int) $u['uid'], $seenAt]);

Helpers::json(['ok' => true]);
