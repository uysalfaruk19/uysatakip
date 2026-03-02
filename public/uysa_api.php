<?php
/**
 * UYSA ERP — Secured API v4.0
 * JWT + API Key + Rate Limiting + Error Handling
 * Dosya: public/uysa_api.php
 */
declare(strict_types=1);

// ── Hata Raporlama (production: sadece log) ───────────────────
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ── .env Loader ───────────────────────────────────────────────
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
        if (!getenv($k)) putenv("$k=$v");
        if (!isset($_ENV[$k])) $_ENV[$k] = $v;
    }
}

// ── Konfigürasyon ─────────────────────────────────────────────
define('DB_HOST',    getenv('DB_HOST')    ?: (getenv('MYSQLHOST')     ?: '127.0.0.1'));
define('DB_PORT',    getenv('DB_PORT')    ?: (getenv('MYSQLPORT')     ?: '3306'));
define('DB_NAME',    getenv('DB_NAME')    ?: (getenv('MYSQLDATABASE') ?: 'uysa_db'));
define('DB_USER',    getenv('DB_USER')    ?: (getenv('MYSQLUSER')     ?: 'root'));
define('DB_PASS',    getenv('DB_PASS')    ?: (getenv('MYSQLPASSWORD') ?: ''));
define('API_TOKEN',  getenv('API_TOKEN')  ?: 'change-me-in-env');
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'change-jwt-secret-minimum-32-chars-here!!');
define('BACKUP_MAX', (int)(getenv('BACKUP_MAX') ?: 30));
define('UPLOAD_DIR', getenv('UPLOAD_DIR') ?: __DIR__ . '/uploads');
define('UPLOAD_MAX_MB', (int)(getenv('UPLOAD_MAX_MB') ?: 25));

// ── Güvenlik Başlıkları ───────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// ── Güvenli CORS ──────────────────────────────────────────────
$allowedOrigins = array_filter(array_map('trim', explode(',',
    getenv('CORS_ORIGINS') ?: 'https://uysatakip.production.up.railway.app,http://localhost,http://127.0.0.1'
)));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: ' . ($allowedOrigins[0] ?? 'null'));
}
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-UYSA-Token, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

// ── Yardımcı: JSON yanıt ─────────────────────────────────────
function jsonResponse(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Sınıf autoloader (basit) ─────────────────────────────────
spl_autoload_register(function (string $class) {
    $file = __DIR__ . '/src/' . $class . '.php';
    if (file_exists($file)) require_once $file;
});
// Fallback: proje kök src/
spl_autoload_register(function (string $class) {
    $file = dirname(__DIR__) . '/src/' . $class . '.php';
    if (file_exists($file)) require_once $file;
});

// ── Veritabanı Bağlantısı ─────────────────────────────────────
try {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT
         . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);
} catch (PDOException $e) {
    error_log('[UYSA] DB Error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Veritabanı bağlantı hatası'], 503);
}

// ── Schema ────────────────────────────────────────────────────
function ensureSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_storage` (
        `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `store_key`   VARCHAR(255)    NOT NULL,
        `store_value` MEDIUMTEXT      NOT NULL,
        `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_store_key` (`store_key`),
        KEY `idx_updated` (`updated_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_backups` (
        `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `backup_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `key_count`  INT UNSIGNED  NOT NULL DEFAULT 0,
        `size_bytes` INT UNSIGNED  NOT NULL DEFAULT 0,
        `trigger_by` VARCHAR(50)   NOT NULL DEFAULT 'auto',
        `snapshot`   LONGTEXT      NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_backup_at` (`backup_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_logs` (
        `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `action`     VARCHAR(50)     NOT NULL,
        `store_key`  VARCHAR(255)    NOT NULL DEFAULT '',
        `ip_addr`    VARCHAR(45)     NOT NULL DEFAULT '',
        `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_action` (`action`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_audit` (
        `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `action`     VARCHAR(100)    NOT NULL,
        `actor`      VARCHAR(100)             DEFAULT NULL,
        `target_key` VARCHAR(255)             DEFAULT NULL,
        `detail`     TEXT                     DEFAULT NULL,
        `ip_addr`    VARCHAR(45)     NOT NULL DEFAULT '',
        `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_action`  (`action`),
        KEY `idx_actor`   (`actor`),
        KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_users` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `username`     VARCHAR(50)  NOT NULL,
        `password`     VARCHAR(255) NOT NULL,
        `role`         VARCHAR(50)  NOT NULL DEFAULT 'user',
        `display_name` VARCHAR(100)          DEFAULT NULL,
        `last_login`   DATETIME              DEFAULT NULL,
        `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
        `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_username` (`username`),
        KEY `idx_role` (`role`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_files` (
        `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `filename`     VARCHAR(255)    NOT NULL,
        `original`     VARCHAR(255)    NOT NULL,
        `mime`         VARCHAR(100)    NOT NULL,
        `size_bytes`   INT UNSIGNED    NOT NULL DEFAULT 0,
        `uploaded_by`  VARCHAR(100)             DEFAULT NULL,
        `category`     VARCHAR(100)             DEFAULT NULL,
        `date`         DATE                     DEFAULT NULL,
        `deleted_at`   DATETIME                 DEFAULT NULL,
        `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_category` (`category`),
        KEY `idx_deleted`  (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Rate limit tabloları
    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_rate_limits` (
        `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `key`          VARCHAR(255)    NOT NULL,
        `attempted_at` INT UNSIGNED    NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_key_time` (`key`, `attempted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_rate_locks` (
        `key`          VARCHAR(255) NOT NULL,
        `locked_until` INT UNSIGNED NOT NULL,
        PRIMARY KEY (`key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // API Keys tablosu
    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_api_keys` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `key_hash`     VARCHAR(64)  NOT NULL,
        `key_prefix`   VARCHAR(20)  NOT NULL,
        `name`         VARCHAR(100) NOT NULL DEFAULT 'API Key',
        `owner`        VARCHAR(100) NOT NULL DEFAULT 'system',
        `role`         VARCHAR(50)  NOT NULL DEFAULT 'viewer',
        `scopes`       JSON         NOT NULL,
        `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
        `uses_count`   INT UNSIGNED NOT NULL DEFAULT 0,
        `last_used_at` DATETIME              DEFAULT NULL,
        `expires_at`   DATETIME              DEFAULT NULL,
        `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_key_hash` (`key_hash`),
        KEY `idx_owner_active` (`owner`, `is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

ensureSchema($pdo);

// ── JWT Manager ───────────────────────────────────────────────
require_once __DIR__ . '/src/JwtManager.php';
$jwtManager = new JwtManager(JWT_SECRET);

// ── Rate Limiter ──────────────────────────────────────────────
require_once __DIR__ . '/src/RateLimiter.php';
$rateLimiter = new RateLimiter($pdo, 10, 600, 900);

// ── API Key Manager ───────────────────────────────────────────
require_once __DIR__ . '/src/ApiKeyManager.php';
$apiKeyManager = new ApiKeyManager($pdo, 'uysa');

// ── İstemci IP ────────────────────────────────────────────────
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR']
    ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]
    : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$clientIp = trim($clientIp);

// ── İstek Verisi ──────────────────────────────────────────────
$action = trim($_GET['action'] ?? '');
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ── Auth Bypass: fileDownload public ─────────────────────────
$publicActions = ['fileDownload', 'ping', 'health'];

// ── Kimlik Doğrulama ─────────────────────────────────────────
$authedUser = null;
$authMethod = null;

if (!in_array($action, $publicActions, true)) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $uysaToken  = $_SERVER['HTTP_X_UYSA_TOKEN']  ?? $_GET['token'] ?? '';

    // 1. Bearer JWT Token
    if (str_starts_with($authHeader, 'Bearer ')) {
        $jwt = substr($authHeader, 7);
        try {
            $payload    = $jwtManager->verify($jwt);
            $authedUser = $payload;
            $authMethod = 'jwt';
        } catch (\RuntimeException $e) {
            jsonResponse(['ok' => false, 'error' => 'JWT geçersiz: ' . $e->getMessage()], 401);
        }
    }
    // 2. API Key (X-UYSA-Token: uysa_...)
    elseif (str_starts_with($uysaToken, 'uysa_')) {
        $keyRecord = $apiKeyManager->verify($uysaToken);
        if (!$keyRecord) {
            jsonResponse(['ok' => false, 'error' => 'API key geçersiz veya süresi dolmuş'], 401);
        }
        $authedUser = ['sub' => $keyRecord['owner'], 'role' => $keyRecord['role'], 'scopes' => $keyRecord['scopes']];
        $authMethod = 'api_key';
    }
    // 3. Legacy API Token
    elseif ($uysaToken === API_TOKEN) {
        $authedUser = ['sub' => 'system', 'role' => 'superadmin', 'scopes' => ['*']];
        $authMethod = 'legacy';
    }
    else {
        jsonResponse(['ok' => false, 'error' => 'Kimlik doğrulama gerekli'], 403);
    }
}

// ── Rate Limiting — SADECE auth/güvenlik endpoint'leri ──────
// Normal veri işlemleri (setBulk, get, set vb.) kısıtlanmaz.
// Kısıtlanan: login, getToken, apiKeyCreate, userSave
$AUTH_RATE_ACTIONS = ['getToken', 'userAuth', 'apiKeyCreate', 'userSave'];

if (in_array($action, $AUTH_RATE_ACTIONS, true)) {
    $rateLimitKey = 'login:' . md5($clientIp . ':' . $action);
    $limit = $rateLimiter->attempt($rateLimitKey);
    if (!$limit['allowed']) {
        header('X-RateLimit-Limit: 10');
        header('X-RateLimit-Remaining: 0');
        header('Retry-After: ' . $limit['retry_after']);
        jsonResponse([
            'ok'          => false,
            'error'       => 'Çok fazla giriş denemesi. Lütfen bekleyin.',
            'retry_after' => $limit['retry_after'],
        ], 429);
    }
    header('X-RateLimit-Limit: 10');
    header('X-RateLimit-Remaining: ' . $limit['remaining']);
}

// ── Input Sanitize ────────────────────────────────────────────
function sanitizeInput(mixed $val, int $maxLen = 65535): mixed
{
    if (is_string($val)) {
        $val = mb_substr(trim($val), 0, $maxLen);
        return $val;
    }
    if (is_array($val)) {
        return array_map(fn($v) => sanitizeInput($v, $maxLen), $val);
    }
    return $val;
}

// ── Audit Log ─────────────────────────────────────────────────
function auditLog(PDO $pdo, string $action, ?string $actor, ?string $key, ?string $detail, string $ip): void
{
    try {
        $pdo->prepare("INSERT INTO uysa_audit (action, actor, target_key, detail, ip_addr)
                        VALUES (?, ?, ?, ?, ?)")
            ->execute([$action, $actor, $key, $detail, $ip]);
    } catch (\Throwable) {}
}

$actor = $authedUser['sub'] ?? 'anonymous';

// ═══════════════════════════════════════════════════════════════
// ACTIONS
// ═══════════════════════════════════════════════════════════════
switch ($action) {

// ── Ping ────────────────────────────────────────────────────
case 'ping':
    jsonResponse(['ok' => true, 'msg' => 'UYSA API v4.0', 'time' => date('c')]);

// ── JWT: Token Al ────────────────────────────────────────────
case 'getToken':
    $username = sanitizeInput($body['username'] ?? '', 50);
    $password = $body['password'] ?? '';

    if (!$username || !$password) {
        jsonResponse(['ok' => false, 'error' => 'Kullanıcı adı ve şifre gerekli'], 400);
    }

    // Login rate limit (kullanıcı bazlı)
    $loginKey = 'login:' . md5($username . ':' . $clientIp);
    $loginLimit = $rateLimiter->attempt($loginKey);
    if (!$loginLimit['allowed']) {
        auditLog($pdo, 'login_ratelimit', $username, null, null, $clientIp);
        jsonResponse([
            'ok'          => false,
            'error'       => 'Çok fazla başarısız giriş denemesi. Hesap geçici olarak kilitlendi.',
            'retry_after' => $loginLimit['retry_after'],
        ], 429);
    }

    // Kullanıcı doğrula
    $stmt = $pdo->prepare("SELECT * FROM uysa_users WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Timing attack mitigation
    $dummyHash = '$2y$10$invalidhashfortimingatk00000000000000000000000000000000';
    $hashToVerify = $user ? $user['password'] : $dummyHash;

    if (!$user || !password_verify($password, $hashToVerify)) {
        auditLog($pdo, 'login_fail', $username, null, json_encode(['ip' => $clientIp]), $clientIp);
        jsonResponse(['ok' => false, 'error' => 'Kullanıcı adı veya şifre hatalı'], 401);
    }

    // Başarılı giriş → rate limit sıfırla
    $rateLimiter->reset($loginKey);
    $pdo->prepare("UPDATE uysa_users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

    $tokenPayload = ['sub' => $user['username'], 'role' => $user['role'], 'uid' => $user['id']];
    $accessToken  = $jwtManager->issue($tokenPayload);
    $refreshToken = $jwtManager->issueRefresh($tokenPayload);

    auditLog($pdo, 'login_success', $username, null, json_encode(['method' => 'jwt']), $clientIp);
    jsonResponse([
        'ok'           => true,
        'access_token' => $accessToken,
        'refresh_token'=> $refreshToken,
        'expires_in'   => 3600,
        'user'         => ['username' => $user['username'], 'role' => $user['role'], 'display_name' => $user['display_name']],
    ]);

// ── JWT: Token Yenile ────────────────────────────────────────
case 'refreshToken':
    $refreshToken = $body['refresh_token'] ?? '';
    if (!$refreshToken) {
        jsonResponse(['ok' => false, 'error' => 'refresh_token gerekli'], 400);
    }
    try {
        $newAccess = $jwtManager->refresh($refreshToken);
        jsonResponse(['ok' => true, 'access_token' => $newAccess, 'expires_in' => 3600]);
    } catch (\RuntimeException $e) {
        jsonResponse(['ok' => false, 'error' => $e->getMessage()], 401);
    }

// ── API Key Yönetimi ─────────────────────────────────────────
case 'apiKeyCreate':
    if (($authedUser['role'] ?? '') !== 'superadmin') {
        jsonResponse(['ok' => false, 'error' => 'Yetki yok (superadmin gerekli)'], 403);
    }
    $opts = [
        'name'         => sanitizeInput($body['name'] ?? 'API Key', 100),
        'owner'        => sanitizeInput($body['owner'] ?? $actor, 100),
        'role'         => in_array($body['role'] ?? '', ['viewer', 'user', 'editor', 'superadmin'])
                            ? $body['role'] : 'viewer',
        'scopes'       => is_array($body['scopes'] ?? null) ? $body['scopes'] : ['read'],
        'expires_days' => (int)($body['expires_days'] ?? 365),
    ];
    $result = $apiKeyManager->create($opts);
    auditLog($pdo, 'api_key_create', $actor, null, json_encode(['name' => $opts['name']]), $clientIp);
    jsonResponse(['ok' => true, 'key' => $result['key'], 'id' => $result['id'],
                  'warning' => 'Bu key bir daha gösterilmeyecek. Güvenli yerde saklayın.']);

case 'apiKeyList':
    if (($authedUser['role'] ?? '') !== 'superadmin') {
        jsonResponse(['ok' => false, 'error' => 'Yetki yok'], 403);
    }
    $owner = sanitizeInput($body['owner'] ?? $actor, 100);
    jsonResponse(['ok' => true, 'keys' => $apiKeyManager->list($owner)]);

case 'apiKeyRevoke':
    if (($authedUser['role'] ?? '') !== 'superadmin') {
        jsonResponse(['ok' => false, 'error' => 'Yetki yok'], 403);
    }
    $keyId = (int)($body['id'] ?? 0);
    $apiKeyManager->revoke($keyId);
    auditLog($pdo, 'api_key_revoke', $actor, null, json_encode(['id' => $keyId]), $clientIp);
    jsonResponse(['ok' => true]);

// ── Storage: GET ─────────────────────────────────────────────
case 'get':
    $key = sanitizeInput($_GET['key'] ?? $body['key'] ?? '', 255);
    if (!$key) jsonResponse(['ok' => false, 'error' => 'key gerekli'], 400);
    $stmt = $pdo->prepare("SELECT store_value FROM uysa_storage WHERE store_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    if ($val === false) jsonResponse(['ok' => false, 'error' => 'Bulunamadı'], 404);
    jsonResponse(['ok' => true, 'value' => $val]);

// ── Storage: SET ─────────────────────────────────────────────
case 'set':
    $key = sanitizeInput($body['key'] ?? '', 255);
    $val = $body['value'] ?? null;
    if (!$key || $val === null) jsonResponse(['ok' => false, 'error' => 'key ve value gerekli'], 400);
    $val = is_string($val) ? $val : json_encode($val);
    $pdo->prepare("INSERT INTO uysa_storage (store_key, store_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE store_value = VALUES(store_value), updated_at = NOW()")
        ->execute([$key, $val]);
    auditLog($pdo, 'set', $actor, $key, null, $clientIp);
    jsonResponse(['ok' => true]);

// ── Storage: setBulk ─────────────────────────────────────────
case 'setBulk':
    $data = $body['items'] ?? $body['data'] ?? [];
    if (!is_array($data) || empty($data)) {
        jsonResponse(['ok' => false, 'error' => 'data (object) gerekli'], 400);
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO uysa_storage (store_key, store_value) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE store_value = VALUES(store_value), updated_at = NOW()");
        foreach ($data as $k => $v) {
            $k = sanitizeInput((string)$k, 255);
            $v = is_string($v) ? $v : json_encode($v);
            $stmt->execute([$k, $v]);
        }
        $pdo->commit();
        auditLog($pdo, 'setBulk', $actor, null, json_encode(['count' => count($data)]), $clientIp);
        jsonResponse(['ok' => true, 'count' => count($data)]);
    } catch (\Throwable $e) {
        $pdo->rollBack();
        error_log('[UYSA] setBulk error: ' . $e->getMessage());
        jsonResponse(['ok' => false, 'error' => 'Toplu kayıt başarısız'], 500);
    }

// ── Storage: DELETE ──────────────────────────────────────────
case 'delete':
    $key = sanitizeInput($body['key'] ?? $_GET['key'] ?? '', 255);
    if (!$key) jsonResponse(['ok' => false, 'error' => 'key gerekli'], 400);
    $pdo->prepare("DELETE FROM uysa_storage WHERE store_key = ?")->execute([$key]);
    auditLog($pdo, 'delete_key', $actor, $key, null, $clientIp);
    jsonResponse(['ok' => true]);

// ── Storage: getAll ──────────────────────────────────────────
case 'getAll':
    $rows = $pdo->query("SELECT store_key, store_value, updated_at FROM uysa_storage ORDER BY store_key")->fetchAll();
    $out  = [];
    foreach ($rows as $r) $out[$r['store_key']] = $r['store_value'];
    jsonResponse(['ok' => true, 'data' => $out, 'count' => count($out)]);

// ── Backup ────────────────────────────────────────────────────
case 'backup':
    $rows     = $pdo->query("SELECT store_key, store_value FROM uysa_storage")->fetchAll();
    $snapshot = json_encode($rows, JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO uysa_backups (key_count, size_bytes, trigger_by, snapshot)
                   VALUES (?, ?, ?, ?)")
        ->execute([count($rows), strlen($snapshot), $actor, $snapshot]);
    // Eski yedekleri temizle
    $pdo->exec("DELETE FROM uysa_backups WHERE id NOT IN (
                  SELECT id FROM (SELECT id FROM uysa_backups ORDER BY backup_at DESC LIMIT " . BACKUP_MAX . ") t
                )");
    auditLog($pdo, 'backup', $actor, null, json_encode(['keys' => count($rows)]), $clientIp);
    jsonResponse(['ok' => true, 'keys' => count($rows), 'size' => strlen($snapshot)]);

// ── Backup List ───────────────────────────────────────────────
case 'backupList':
    $rows = $pdo->query("SELECT id, backup_at, key_count, size_bytes, trigger_by FROM uysa_backups
                          ORDER BY backup_at DESC LIMIT 50")->fetchAll();
    jsonResponse(['ok' => true, 'backups' => $rows]);

// ── Backup Restore ────────────────────────────────────────────
case 'backupRestore':
    $id = (int)($body['id'] ?? 0);
    if (!$id) jsonResponse(['ok' => false, 'error' => 'id gerekli'], 400);
    $stmt = $pdo->prepare("SELECT snapshot FROM uysa_backups WHERE id = ?");
    $stmt->execute([$id]);
    $snap = $stmt->fetchColumn();
    if (!$snap) jsonResponse(['ok' => false, 'error' => 'Yedek bulunamadı'], 404);
    $rows = json_decode($snap, true);
    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM uysa_storage");
        $ins = $pdo->prepare("INSERT INTO uysa_storage (store_key, store_value) VALUES (?, ?)");
        foreach ($rows as $r) $ins->execute([$r['store_key'], $r['store_value']]);
        $pdo->commit();
        auditLog($pdo, 'backup_restore', $actor, null, json_encode(['backup_id' => $id, 'keys' => count($rows)]), $clientIp);
        jsonResponse(['ok' => true, 'restored' => count($rows)]);
    } catch (\Throwable $e) {
        $pdo->rollBack();
        jsonResponse(['ok' => false, 'error' => 'Geri yükleme başarısız: ' . $e->getMessage()], 500);
    }

// ── User Auth (legacy login for index.html) ───────────────────
case 'userAuth':
    $username = sanitizeInput($body['username'] ?? '', 50);
    $password = $body['password'] ?? '';
    if (!$username || !$password) {
        jsonResponse(['ok' => false, 'error' => 'Kullanıcı adı ve şifre gerekli'], 400);
    }
    $loginKey   = 'login:' . md5($username . ':' . $clientIp);
    $loginLimit = $rateLimiter->attempt($loginKey);
    if (!$loginLimit['allowed']) {
        auditLog($pdo, 'login_ratelimit', $username, null, null, $clientIp);
        jsonResponse(['ok' => false, 'error' => 'Çok fazla giriş denemesi. Bekleyin.', 'retry_after' => $loginLimit['retry_after']], 429);
    }
    $stmt = $pdo->prepare("SELECT * FROM uysa_users WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    $dummy = '$2y$10$invalidhashfortimingattackprevention000000000000000000';
    $hash  = $user ? $user['password'] : $dummy;
    usleep(500000); // 0.5 sn sabit bekleme (timing attack önlemi)
    if (!$user || !password_verify($password, $hash)) {
        auditLog($pdo, 'login_fail', $username, null, null, $clientIp);
        jsonResponse(['ok' => false, 'error' => 'Kullanıcı adı veya şifre hatalı'], 401);
    }
    $rateLimiter->reset($loginKey);
    $pdo->prepare("UPDATE uysa_users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
    auditLog($pdo, 'login_success', $username, null, null, $clientIp);
    jsonResponse(['ok' => true, 'user' => ['username' => $user['username'], 'role' => $user['role'], 'display_name' => $user['display_name']]]);

// ── User List ─────────────────────────────────────────────────
case 'userList':
    $rows = $pdo->query("SELECT id, username, role, display_name, last_login, is_active, created_at FROM uysa_users ORDER BY created_at DESC")->fetchAll();
    jsonResponse(['ok' => true, 'users' => $rows]);

// ── User Save ─────────────────────────────────────────────────
case 'userSave':
    if (!in_array($authedUser['role'] ?? '', ['superadmin', 'editor'], true)) {
        jsonResponse(['ok' => false, 'error' => 'Yetki yok'], 403);
    }
    $username    = sanitizeInput($body['username'] ?? '', 50);
    $password    = $body['password'] ?? '';
    $role        = in_array($body['role'] ?? '', ['superadmin', 'editor', 'user', 'viewer']) ? $body['role'] : 'user';
    $displayName = sanitizeInput($body['display_name'] ?? '', 100);

    if (!$username) jsonResponse(['ok' => false, 'error' => 'username gerekli'], 400);

    // Güçlü şifre kontrolü
    if ($password && strlen($password) < 8) {
        jsonResponse(['ok' => false, 'error' => 'Şifre en az 8 karakter olmalı'], 400);
    }

    $existing = $pdo->prepare("SELECT id FROM uysa_users WHERE username = ?");
    $existing->execute([$username]);
    $existId = $existing->fetchColumn();

    if ($existId) {
        $fields = ['role = ?', 'display_name = ?'];
        $params = [$role, $displayName];
        if ($password) {
            $fields[] = 'password = ?';
            $params[]  = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        }
        $params[] = $existId;
        $pdo->prepare("UPDATE uysa_users SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    } else {
        if (!$password) jsonResponse(['ok' => false, 'error' => 'Yeni kullanıcı için şifre gerekli'], 400);
        $pdo->prepare("INSERT INTO uysa_users (username, password, role, display_name) VALUES (?, ?, ?, ?)")
            ->execute([$username, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $role, $displayName]);
    }
    auditLog($pdo, 'user_save', $actor, $username, json_encode(['role' => $role]), $clientIp);
    jsonResponse(['ok' => true]);

// ── Audit Log ─────────────────────────────────────────────────
case 'auditLog':
    $logAction = sanitizeInput($body['action'] ?? '', 100);
    $logDetail = sanitizeInput($body['detail'] ?? '', 1000);
    $logKey    = sanitizeInput($body['key'] ?? '', 255);
    auditLog($pdo, $logAction, $actor, $logKey ?: null, $logDetail ?: null, $clientIp);
    jsonResponse(['ok' => true]);

case 'auditList':
    $limit = min((int)($_GET['limit'] ?? 100), 500);
    $rows  = $pdo->prepare("SELECT * FROM uysa_audit ORDER BY created_at DESC LIMIT ?")->execute([$limit]) ? null : null;
    $stmt  = $pdo->prepare("SELECT * FROM uysa_audit ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$limit]);
    jsonResponse(['ok' => true, 'logs' => $stmt->fetchAll()]);

// ── File Upload ───────────────────────────────────────────────
case 'fileUpload':
    if (!isset($_FILES['file'])) jsonResponse(['ok' => false, 'error' => 'Dosya gönderilmedi'], 400);
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) jsonResponse(['ok' => false, 'error' => 'Upload hatası: ' . $file['error']], 400);
    if ($file['size'] > UPLOAD_MAX_MB * 1024 * 1024) {
        jsonResponse(['ok' => false, 'error' => 'Dosya boyutu ' . UPLOAD_MAX_MB . 'MB limitini aşıyor'], 413);
    }

    $allowedExt  = ['pdf','doc','docx','xls','xlsx','ppt','pptx','jpg','jpeg','png','gif','webp','txt','csv','zip'];
    $allowedMime = [
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg','image/png','image/gif','image/webp',
        'text/plain','text/csv',
        'application/zip','application/x-zip-compressed',
    ];

    $origName = basename($file['name']);
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        jsonResponse(['ok' => false, 'error' => "İzin verilmeyen uzantı: .{$ext}"], 415);
    }

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, $allowedMime, true)) {
        jsonResponse(['ok' => false, 'error' => "İzin verilmeyen MIME türü: {$mimeType}"], 415);
    }

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $safeName  = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destPath  = UPLOAD_DIR . '/' . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        jsonResponse(['ok' => false, 'error' => 'Dosya kaydedilemedi'], 500);
    }

    $pdo->prepare("INSERT INTO uysa_files (filename, original, mime, size_bytes, uploaded_by, category, date)
                   VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$safeName, $origName, $mimeType, $file['size'],
                   sanitizeInput($body['uploadedBy'] ?? $actor, 100),
                   sanitizeInput($body['category'] ?? '', 100),
                   $body['date'] ?? null]);
    auditLog($pdo, 'file_upload', $actor, $origName, json_encode(['size' => $file['size']]), $clientIp);
    jsonResponse(['ok' => true, 'filename' => $safeName, 'original' => $origName]);

// ── Default: 404 ─────────────────────────────────────────────
default:
    jsonResponse(['ok' => false, 'error' => "Bilinmeyen action: {$action}"], 404);
}
