<?php
/**
 * UYSA Sunucu API v3.0 — Railway/Production Ready
 * Dosya: public/uysa_api.php
 * ─────────────────────────────────────────────────────────────
 * Yenilikler v3.0:
 *  - Dosya yükleme (fileUpload, fileList, fileDelete, fileDownload)
 *  - Kullanıcı kimlik doğrulama (userAuth, userList, userSave)
 *  - Gelişmiş audit log (auditLog, auditList) — kim neyi sildi
 *  - Silme işlemlerinde soft-delete (deleted_at alanı)
 *  - Tüm v2.1 işlevleri korundu
 */

// ── .env loader (lokal geliştirme için) ───────────────────────
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
// ── DB Konfigürasyonu (öncelik: Railway ENV > kendi sunucu > localhost) ──
define('DB_HOST',    getenv('DB_HOST')    ?: (getenv('MYSQLHOST')    ?: '78.135.65.2'));
define('DB_PORT',    getenv('DB_PORT')    ?: (getenv('MYSQLPORT')    ?: '3306'));
define('DB_NAME',    getenv('DB_NAME')    ?: (getenv('MYSQLDATABASE') ?: 'uysayeme_uysadb'));
define('DB_USER',    getenv('DB_USER')    ?: (getenv('MYSQLUSER')    ?: 'uysayeme_wp195'));
define('DB_PASS',    getenv('DB_PASS')    ?: (getenv('MYSQLPASSWORD') ?: 'UYS.faruk05321608119'));
define('API_TOKEN',  getenv('API_TOKEN')  ?: 'UysaERP2026xProdKey3f7a9c1b');
define('BACKUP_MAX', (int)(getenv('BACKUP_MAX') ?: 30));

// Dosya yükleme klasörü
define('UPLOAD_DIR', getenv('UPLOAD_DIR') ?: __DIR__ . '/uploads');
define('UPLOAD_MAX_MB', (int)(getenv('UPLOAD_MAX_MB') ?: 25));

// ── CORS & Headers ────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-UYSA-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

// ── Token kontrolü (fileDownload public erişime açık) ─────────
$action = trim($_GET['action'] ?? '');
$token  = $_SERVER['HTTP_X_UYSA_TOKEN'] ?? $_GET['token'] ?? '';

if ($action !== 'fileDownload' && $token !== API_TOKEN) {
    http_response_code(403);
    die(json_encode(['ok' => false, 'error' => 'Unauthorized']));
}

// ── Veritabanı ────────────────────────────────────────────────
try {
    $dsn = 'mysql:host=' . DB_HOST
         . ';port=' . DB_PORT
         . ';dbname=' . DB_NAME
         . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['ok' => false, 'error' => 'DB bağlantı hatası: ' . $e->getMessage()]));
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// ── Schema otomatik oluştur ───────────────────────────────────
function ensureSchema(PDO $pdo): void {

    // Ana depolama
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `uysa_storage` (
          `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `store_key`   VARCHAR(255)    NOT NULL,
          `store_value` MEDIUMTEXT      NOT NULL,
          `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_store_key` (`store_key`),
          KEY `idx_updated` (`updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Yedekler
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `uysa_backups` (
          `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
          `backup_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `key_count`  INT UNSIGNED  NOT NULL DEFAULT 0,
          `size_bytes` INT UNSIGNED  NOT NULL DEFAULT 0,
          `trigger_by` VARCHAR(50)   NOT NULL DEFAULT 'auto',
          `snapshot`   LONGTEXT      NOT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_backup_at` (`backup_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Audit log — kim neyi sildi, kim ne yaptı
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `uysa_audit` (
          `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `action`     VARCHAR(100)    NOT NULL,
          `module`     VARCHAR(50)     NOT NULL DEFAULT '',
          `detail`     TEXT,
          `username`   VARCHAR(100)    NOT NULL DEFAULT '',
          `ip_addr`    VARCHAR(45)     NOT NULL DEFAULT '',
          `user_agent` VARCHAR(500)    NOT NULL DEFAULT '',
          `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_action`     (`action`),
          KEY `idx_username`   (`username`),
          KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // İşlem logları (legacy compat)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `uysa_logs` (
          `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `action`     VARCHAR(50)     NOT NULL,
          `store_key`  VARCHAR(255)    NOT NULL DEFAULT '',
          `ip_addr`    VARCHAR(45)     NOT NULL DEFAULT '',
          `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_action`     (`action`),
          KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Kullanıcılar
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `uysa_users` (
          `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
          `username`      VARCHAR(100)  NOT NULL,
          `password_hash` VARCHAR(500)  NOT NULL,
          `phone`         VARCHAR(20)   NOT NULL DEFAULT '',
          `display_name`  VARCHAR(100)  NOT NULL DEFAULT '',
          `role`          ENUM('superadmin','editor','user','viewer') NOT NULL DEFAULT 'user',
          `permissions`   JSON,
          `active`        TINYINT(1)    NOT NULL DEFAULT 1,
          `last_login`    DATETIME,
          `created_by`    VARCHAR(100)  NOT NULL DEFAULT 'system',
          `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Dosyalar
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `uysa_files` (
          `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `file_name`    VARCHAR(255)    NOT NULL,
          `original_name`VARCHAR(255)    NOT NULL,
          `category`     VARCHAR(100)    NOT NULL DEFAULT 'diger',
          `notes`        TEXT,
          `mime_type`    VARCHAR(100)    NOT NULL DEFAULT '',
          `file_size`    INT UNSIGNED    NOT NULL DEFAULT 0,
          `file_path`    VARCHAR(500)    NOT NULL,
          `uploaded_by`  VARCHAR(100)    NOT NULL DEFAULT '',
          `doc_date`     DATE,
          `deleted_at`   DATETIME,
          `deleted_by`   VARCHAR(100)    NOT NULL DEFAULT '',
          `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_category`   (`category`),
          KEY `idx_uploaded_by`(`uploaded_by`),
          KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Uploads klasörü
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
        // .htaccess: sadece script erişimi
        @file_put_contents(UPLOAD_DIR . '/.htaccess', "Order Deny,Allow\nDeny from all\n");
    }

    // Varsayılan kullanıcıları ekle (sadece yoksa)
    $count = (int)$pdo->query("SELECT COUNT(*) FROM uysa_users")->fetchColumn();
    if ($count === 0) {
        // OFU — superadmin (şifre: 05321608119)
        $hash1 = password_hash('05321608119', PASSWORD_BCRYPT, ['cost' => 10]);
        $pdo->prepare("INSERT IGNORE INTO uysa_users (username,password_hash,phone,display_name,role,permissions,created_by)
            VALUES (?,?,?,?,?,?,?)")
            ->execute(['OFU', $hash1, '05321608119', 'OFU', 'superadmin', json_encode(['all']), 'system']);

        // Azim — standart kullanıcı (şifre: Azim2024!)
        $hash2 = password_hash('Azim2024!', PASSWORD_BCRYPT, ['cost' => 10]);
        $pdo->prepare("INSERT IGNORE INTO uysa_users (username,password_hash,phone,display_name,role,permissions,created_by)
            VALUES (?,?,?,?,?,?,?)")
            ->execute(['Azim', $hash2, '', 'Azim', 'user', json_encode(['read','write']), 'OFU']);
    }
}
ensureSchema($pdo);

// ── Helper: IP ────────────────────────────────────────────────
function getClientIp(): string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
       ?? $_SERVER['HTTP_CF_CONNECTING_IP']
       ?? $_SERVER['REMOTE_ADDR']
       ?? '';
    return substr(trim(explode(',', $ip)[0]), 0, 45);
}

// ── Legacy logger ─────────────────────────────────────────────
function logAction(PDO $pdo, string $action, string $key = ''): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO uysa_logs (action, store_key, ip_addr) VALUES (?,?,?)");
        $stmt->execute([$action, $key, getClientIp()]);
    } catch (Exception) {}
}

// ── Audit logger ──────────────────────────────────────────────
function auditLog(PDO $pdo, string $action, string $module, string $detail, string $username = ''): void {
    try {
        $ua   = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $stmt = $pdo->prepare("
            INSERT INTO uysa_audit (action, module, detail, username, ip_addr, user_agent)
            VALUES (?,?,?,?,?,?)
        ");
        $stmt->execute([$action, $module, $detail, $username, getClientIp(), $ua]);
    } catch (Exception) {}
}

// ── Route ─────────────────────────────────────────────────────
switch ($action) {

    // ════════════════════════════════════════════════
    // STORAGE (v2.1 uyumlu — tüm fonksiyonlar korundu)
    // ════════════════════════════════════════════════

    case 'getAll':
        $rows = $pdo->query("SELECT store_key, store_value FROM uysa_storage")->fetchAll();
        $data = [];
        foreach ($rows as $r) $data[$r['store_key']] = $r['store_value'];
        logAction($pdo, 'getAll');
        echo json_encode(['ok' => true, 'data' => $data, 'count' => count($data)]);
        break;

    case 'get':
        $key  = $body['key'] ?? $_GET['key'] ?? '';
        $stmt = $pdo->prepare("SELECT store_value FROM uysa_storage WHERE store_key = ?");
        $stmt->execute([$key]);
        $row  = $stmt->fetch();
        echo json_encode(['ok' => true, 'key' => $key, 'value' => $row ? $row['store_value'] : null]);
        break;

    case 'set':
        $key   = $body['key']   ?? '';
        $value = $body['value'] ?? '';
        $uname = $body['username'] ?? '';
        if (!$key) { echo json_encode(['ok' => false, 'error' => 'key boş']); break; }
        $stmt = $pdo->prepare("
            INSERT INTO uysa_storage (store_key, store_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE store_value = VALUES(store_value), updated_at = NOW()
        ");
        $stmt->execute([$key, $value]);
        logAction($pdo, 'set', $key);
        echo json_encode(['ok' => true, 'key' => $key]);
        break;

    case 'setBulk':
        $items = $body['items'] ?? [];
        $uname = $body['username'] ?? '';
        if (empty($items)) { echo json_encode(['ok' => false, 'error' => 'items boş']); break; }
        $stmt = $pdo->prepare("
            INSERT INTO uysa_storage (store_key, store_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE store_value = VALUES(store_value), updated_at = NOW()
        ");
        $saved = 0;
        $pdo->beginTransaction();
        try {
            foreach ($items as $key => $value) {
                if (!str_starts_with((string)$key, 'uysa')) continue;
                $stmt->execute([(string)$key, (string)$value]);
                $saved++;
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            break;
        }
        logAction($pdo, 'setBulk', "count:$saved");
        echo json_encode(['ok' => true, 'saved' => $saved]);
        break;

    case 'delete':
        $key   = $body['key']      ?? '';
        $uname = $body['username'] ?? 'api';
        $stmt  = $pdo->prepare("DELETE FROM uysa_storage WHERE store_key = ?");
        $stmt->execute([$key]);
        logAction($pdo, 'delete', $key);
        auditLog($pdo, 'delete_key', 'STORAGE', "Silinen anahtar: {$key}", $uname);
        echo json_encode(['ok' => true, 'deleted' => $stmt->rowCount()]);
        break;

    case 'backup':
        $trigger  = $body['trigger'] ?? 'auto';
        $uname    = $body['username'] ?? 'api';
        $rows     = $pdo->query("SELECT store_key, store_value FROM uysa_storage")->fetchAll();
        $snapshot = [];
        foreach ($rows as $r) $snapshot[$r['store_key']] = $r['store_value'];
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        $count = (int)$pdo->query("SELECT COUNT(*) FROM uysa_backups")->fetchColumn();
        if ($count >= BACKUP_MAX) {
            $pdo->exec("DELETE FROM uysa_backups ORDER BY backup_at ASC LIMIT " . ($count - BACKUP_MAX + 1));
        }
        $ins = $pdo->prepare("INSERT INTO uysa_backups (key_count, size_bytes, trigger_by, snapshot) VALUES (?,?,?,?)");
        $ins->execute([count($rows), strlen($json), $trigger, $json]);
        logAction($pdo, 'backup', "trigger:$trigger");
        auditLog($pdo, 'backup', 'SYSTEM', "Yedek alındı: {$trigger} ({" . count($rows) . "} key)", $uname);
        echo json_encode(['ok' => true, 'backup_id' => $pdo->lastInsertId(), 'keys' => count($rows), 'size_kb' => round(strlen($json)/1024,1), 'time' => date('Y-m-d H:i:s')]);
        break;

    case 'backupList':
        $rows = $pdo->query("SELECT id, backup_at, key_count, size_bytes, trigger_by FROM uysa_backups ORDER BY backup_at DESC LIMIT 30")->fetchAll();
        echo json_encode(['ok' => true, 'backups' => $rows]);
        break;

    case 'backupRestore':
        $bid  = (int)($body['id'] ?? 0);
        $uname = $body['username'] ?? 'api';
        $stmt = $pdo->prepare("SELECT snapshot FROM uysa_backups WHERE id = ?");
        $stmt->execute([$bid]);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(['ok' => false, 'error' => 'Yedek bulunamadı']); break; }
        $data = json_decode($row['snapshot'], true);
        $pdo->beginTransaction();
        try {
            $pdo->exec("TRUNCATE TABLE uysa_storage");
            $ins = $pdo->prepare("INSERT INTO uysa_storage (store_key, store_value) VALUES(?,?)");
            $cnt = 0;
            foreach ($data as $k => $v) { $ins->execute([$k, $v]); $cnt++; }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            break;
        }
        logAction($pdo, 'backupRestore', "id:$bid");
        auditLog($pdo, 'backupRestore', 'SYSTEM', "Yedek geri yüklendi: id={$bid}, {$cnt} key", $uname);
        echo json_encode(['ok' => true, 'restored' => $cnt]);
        break;

    case 'stats':
        $keyCount = (int)$pdo->query("SELECT COUNT(*) FROM uysa_storage")->fetchColumn();
        $dataSize = (int)$pdo->query("SELECT COALESCE(SUM(LENGTH(store_value)),0) FROM uysa_storage")->fetchColumn();
        $lastUpd  = $pdo->query("SELECT MAX(updated_at) FROM uysa_storage")->fetchColumn();
        $bkpCount = (int)$pdo->query("SELECT COUNT(*) FROM uysa_backups")->fetchColumn();
        $lastBkp  = $pdo->query("SELECT MAX(backup_at) FROM uysa_backups")->fetchColumn();
        $fileCount = (int)$pdo->query("SELECT COUNT(*) FROM uysa_files WHERE deleted_at IS NULL")->fetchColumn();
        echo json_encode([
            'ok' => true, 'key_count' => $keyCount, 'data_size_kb' => round($dataSize/1024,1),
            'last_update' => $lastUpd, 'backup_count' => $bkpCount, 'last_backup' => $lastBkp,
            'file_count' => $fileCount
        ]);
        break;

    case 'health':
        echo json_encode(['ok' => true, 'status' => 'healthy', 'db' => DB_NAME, 'time' => date('Y-m-d H:i:s'), 'version' => '3.0']);
        break;

    // ════════════════════════════════════════════════
    // AUDIT LOG
    // ════════════════════════════════════════════════

    case 'auditLog':
        $action_  = $body['action']   ?? 'unknown';
        $module_  = $body['module']   ?? '';
        $detail_  = $body['detail']   ?? '';
        $uname_   = $body['username'] ?? '';
        auditLog($pdo, $action_, $module_, $detail_, $uname_);
        echo json_encode(['ok' => true]);
        break;

    case 'auditList':
        $limit   = min((int)($_GET['limit'] ?? 200), 1000);
        $filter  = $_GET['filter'] ?? '';
        $search  = $_GET['search'] ?? '';
        $module  = $_GET['module'] ?? '';
        $where   = ['1=1'];
        $params  = [];
        if ($filter === 'delete') { $where[] = "action LIKE '%delete%' OR action LIKE '%sil%'"; }
        if ($filter === 'login')  { $where[] = "action LIKE '%login%' OR action LIKE '%logout%'"; }
        if ($filter === 'file')   { $where[] = "action LIKE '%file%'"; }
        if ($module) { $where[] = "module = ?"; $params[] = $module; }
        if ($search) { $where[] = "(detail LIKE ? OR username LIKE ? OR action LIKE ?)";
            $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
        $sql  = "SELECT * FROM uysa_audit WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT $limit";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        echo json_encode(['ok' => true, 'logs' => $rows, 'count' => count($rows)]);
        break;

    // ════════════════════════════════════════════════
    // DOSYA YÖNETİMİ
    // ════════════════════════════════════════════════

    case 'fileUpload':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) {
            echo json_encode(['ok' => false, 'error' => 'Dosya bulunamadı']); break;
        }
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'error' => 'Upload hatası: ' . $file['error']]); break;
        }
        $maxBytes = UPLOAD_MAX_MB * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            echo json_encode(['ok' => false, 'error' => 'Dosya çok büyük (max ' . UPLOAD_MAX_MB . ' MB)']); break;
        }

        // Güvenli dosya adı
        $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed   = ['pdf','doc','docx','xls','xlsx','ppt','pptx','jpg','jpeg','png','gif','webp','txt','csv','zip'];
        if (!in_array($ext, $allowed)) {
            echo json_encode(['ok' => false, 'error' => 'Bu dosya türü desteklenmiyor: .' . $ext]); break;
        }

        $safeName  = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destPath  = UPLOAD_DIR . '/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            echo json_encode(['ok' => false, 'error' => 'Dosya taşınamadı']); break;
        }

        $meta = json_decode($_POST['meta'] ?? '{}', true) ?? [];
        $uname = $meta['uploadedBy'] ?? 'unknown';
        $category = $meta['category'] ?? 'diger';
        $docDate = $meta['date'] ?? null;

        $ins = $pdo->prepare("
            INSERT INTO uysa_files (file_name, original_name, category, notes, mime_type, file_size, file_path, uploaded_by, doc_date)
            VALUES (?,?,?,?,?,?,?,?,?)
        ");
        $ins->execute([
            $safeName, $file['name'], $category,
            $meta['notes'] ?? '', $file['type'] ?? 'application/octet-stream',
            $file['size'], $destPath, $uname, $docDate ?: null
        ]);
        $fileId = $pdo->lastInsertId();
        auditLog($pdo, 'file_upload', 'FILE', "Dosya yüklendi: {$file['name']} ({$category}, " . round($file['size']/1024,1) . " KB)", $uname);
        echo json_encode(['ok' => true, 'file_id' => $fileId, 'file_name' => $safeName, 'path' => $safeName, 'size_kb' => round($file['size']/1024,1)]);
        break;

    case 'fileList':
        $category = $_GET['category'] ?? '';
        $search   = $_GET['search']   ?? '';
        $where    = ['deleted_at IS NULL'];
        $params   = [];
        if ($category) { $where[] = "category = ?"; $params[] = $category; }
        if ($search)   { $where[] = "(original_name LIKE ? OR notes LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
        $stmt = $pdo->prepare("SELECT id, file_name, original_name, category, notes, mime_type, file_size, uploaded_by, doc_date, created_at FROM uysa_files WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT 500");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        // download_url ekle
        foreach ($rows as &$r) {
            $r['download_url'] = 'uysa_api.php?action=fileDownload&id=' . $r['id'] . '&token=' . urlencode(API_TOKEN);
            $r['size_kb'] = round($r['file_size'] / 1024, 1);
        }
        unset($r);
        echo json_encode(['ok' => true, 'files' => $rows, 'count' => count($rows)]);
        break;

    case 'fileDownload':
        // Token query param ile kontrol (GET indirmesi için)
        $dlToken = $_GET['token'] ?? '';
        if ($dlToken !== API_TOKEN) { http_response_code(403); die('Unauthorized'); }
        $fid  = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM uysa_files WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$fid]);
        $file = $stmt->fetch();
        if (!$file || !file_exists($file['file_path'])) {
            http_response_code(404); header('Content-Type: application/json');
            die(json_encode(['ok' => false, 'error' => 'Dosya bulunamadı']));
        }
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Disposition: attachment; filename="' . addslashes($file['original_name']) . '"');
        header('Content-Length: ' . $file['file_size']);
        readfile($file['file_path']);
        exit;

    case 'fileDelete':
        $fid   = (int)($body['id'] ?? 0);
        $uname = $body['username'] ?? 'api';
        $stmt  = $pdo->prepare("SELECT original_name, file_path FROM uysa_files WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$fid]);
        $file  = $stmt->fetch();
        if (!$file) { echo json_encode(['ok' => false, 'error' => 'Dosya bulunamadı']); break; }

        // Soft delete
        $pdo->prepare("UPDATE uysa_files SET deleted_at = NOW(), deleted_by = ? WHERE id = ?")
            ->execute([$uname, $fid]);
        // Fiziksel dosyayı da sil
        if (file_exists($file['file_path'])) @unlink($file['file_path']);

        auditLog($pdo, 'file_delete', 'FILE', "Dosya silindi: {$file['original_name']} (id:{$fid})", $uname);
        echo json_encode(['ok' => true, 'deleted' => $file['original_name']]);
        break;

    // ════════════════════════════════════════════════
    // KULLANICI YÖNETİMİ
    // ════════════════════════════════════════════════

    case 'userAuth':
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        if (!$username || !$password) {
            echo json_encode(['ok' => false, 'error' => 'Kullanıcı adı/şifre gerekli']); break;
        }
        $stmt = $pdo->prepare("SELECT * FROM uysa_users WHERE username = ? AND active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            auditLog($pdo, 'login_failed', 'AUTH', "Başarısız giriş: {$username}", $username);
            echo json_encode(['ok' => false, 'error' => 'Kullanıcı adı veya şifre hatalı']); break;
        }
        // Son giriş güncelle
        $pdo->prepare("UPDATE uysa_users SET last_login = NOW() WHERE id = ?")
            ->execute([$user['id']]);
        auditLog($pdo, 'login', 'AUTH', "Başarılı giriş: {$username}", $username);
        echo json_encode(['ok' => true, 'user' => [
            'id' => $user['id'], 'username' => $user['username'],
            'display_name' => $user['display_name'], 'role' => $user['role'],
            'permissions' => json_decode($user['permissions'] ?? '[]', true)
        ]]);
        break;

    case 'userList':
        $stmt = $pdo->query("SELECT id,username,phone,display_name,role,active,last_login,created_by,created_at FROM uysa_users ORDER BY id ASC");
        echo json_encode(['ok' => true, 'users' => $stmt->fetchAll()]);
        break;

    case 'userSave':
        $uid    = (int)($body['id'] ?? 0);
        $uname  = trim($body['username'] ?? '');
        $pass   = $body['password'] ?? '';
        $display= trim($body['display_name'] ?? $uname);
        $phone  = trim($body['phone'] ?? '');
        $role   = $body['role'] ?? 'user';
        $active = isset($body['active']) ? (int)$body['active'] : 1;
        $by     = $body['created_by'] ?? 'api';
        $allowed_roles = ['superadmin','editor','user','viewer'];
        if (!in_array($role, $allowed_roles)) $role = 'user';

        if ($uid > 0) {
            // Güncelle
            if ($pass) {
                $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 10]);
                $pdo->prepare("UPDATE uysa_users SET password_hash=?,phone=?,display_name=?,role=?,active=? WHERE id=?")
                    ->execute([$hash, $phone, $display, $role, $active, $uid]);
            } else {
                $pdo->prepare("UPDATE uysa_users SET phone=?,display_name=?,role=?,active=? WHERE id=?")
                    ->execute([$phone, $display, $role, $active, $uid]);
            }
            auditLog($pdo, 'user_update', 'USER_MGMT', "Kullanıcı güncellendi: {$uname} (id:{$uid})", $by);
            echo json_encode(['ok' => true, 'action' => 'updated']);
        } else {
            // Yeni kullanıcı
            if (!$uname || !$pass) { echo json_encode(['ok' => false, 'error' => 'username ve password zorunlu']); break; }
            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 10]);
            $perms = $role === 'superadmin' ? ['all'] : ['read','write'];
            try {
                $pdo->prepare("INSERT INTO uysa_users (username,password_hash,phone,display_name,role,permissions,created_by) VALUES(?,?,?,?,?,?,?)")
                    ->execute([$uname, $hash, $phone, $display, $role, json_encode($perms), $by]);
                auditLog($pdo, 'user_add', 'USER_MGMT', "Yeni kullanıcı: {$uname} ({$role})", $by);
                echo json_encode(['ok' => true, 'action' => 'created', 'id' => $pdo->lastInsertId()]);
            } catch (PDOException $e) {
                echo json_encode(['ok' => false, 'error' => 'Bu kullanıcı adı zaten mevcut']);
            }
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Geçersiz action: ' . $action]);
}
