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
    getenv('CORS_ORIGINS') ?: 'https://uysatakip-production-04a2.up.railway.app,https://uysatakip.production.up.railway.app,http://localhost,http://127.0.0.1'
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
    error_log('[UYSA] DB Error: ' . $e->getMessage() . ' | DSN: mysql:host=' . DB_HOST . ':' . DB_PORT . ' db=' . DB_NAME . ' user=' . DB_USER);
    jsonResponse([
        'ok'    => false,
        'error' => 'Veritabanı bağlantı hatası',
        'debug' => [
            'host' => DB_HOST,
            'port' => DB_PORT,
            'name' => DB_NAME,
            'user' => DB_USER,
            'hint' => $e->getMessage(),
        ]
    ], 503);
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
    // Mevcut DB'ye eksik kolonları ekle (ALTER TABLE IF NOT EXISTS kolonu yoksa)
    try {
        // MySQL 5.7 compatible — information_schema column check instead of IF NOT EXISTS
        $migrCols = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uysa_users'"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_active',    $migrCols)) $pdo->exec("ALTER TABLE `uysa_users` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1");
        if (!in_array('display_name', $migrCols)) $pdo->exec("ALTER TABLE `uysa_users` ADD COLUMN `display_name` VARCHAR(100) DEFAULT NULL");
        if (!in_array('last_login',   $migrCols)) $pdo->exec("ALTER TABLE `uysa_users` ADD COLUMN `last_login` DATETIME DEFAULT NULL");
    } catch (\Throwable $ignored) {}

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

    // ── v5.0 Finans Tabloları ─────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_accounts` (
        `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
        `code`       VARCHAR(20)     NOT NULL,
        `name`       VARCHAR(200)    NOT NULL,
        `type`       VARCHAR(20)     NOT NULL DEFAULT 'asset',
        `parent_id`  INT UNSIGNED             DEFAULT NULL,
        `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,
        `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_code` (`code`),
        KEY `idx_type` (`type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_journal_entries` (
        `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `entry_no`    VARCHAR(30)     NOT NULL,
        `date`        DATE            NOT NULL,
        `description` VARCHAR(500)             DEFAULT NULL,
        `status`      VARCHAR(20)     NOT NULL DEFAULT 'draft',
        `created_by`  VARCHAR(100)             DEFAULT NULL,
        `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_entry_no` (`entry_no`),
        KEY `idx_date` (`date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_journal_lines` (
        `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `entry_id`    BIGINT UNSIGNED  NOT NULL,
        `account_id`  INT UNSIGNED     NOT NULL,
        `debit`       DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        `credit`      DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        `description` VARCHAR(300)              DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_entry` (`entry_id`),
        KEY `idx_account` (`account_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_invoices` (
        `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `invoice_no`   VARCHAR(30)      NOT NULL,
        `type`         VARCHAR(20)      NOT NULL DEFAULT 'sales',
        `customer_id`  BIGINT UNSIGNED           DEFAULT NULL,
        `supplier_id`  INT UNSIGNED              DEFAULT NULL,
        `date`         DATE             NOT NULL,
        `due_date`     DATE                      DEFAULT NULL,
        `subtotal`     DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        `tax_rate`     DECIMAL(5,2)     NOT NULL DEFAULT 20.00,
        `tax_amount`   DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        `total`        DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        `status`       VARCHAR(20)      NOT NULL DEFAULT 'draft',
        `notes`        TEXT                      DEFAULT NULL,
        `created_by`   VARCHAR(100)              DEFAULT NULL,
        `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_invoice_no` (`invoice_no`),
        KEY `idx_type` (`type`),
        KEY `idx_status` (`status`),
        KEY `idx_date` (`date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_invoice_lines` (
        `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `invoice_id`  BIGINT UNSIGNED  NOT NULL,
        `product_id`  BIGINT UNSIGNED           DEFAULT NULL,
        `description` VARCHAR(500)     NOT NULL,
        `quantity`    DECIMAL(12,3)    NOT NULL DEFAULT 1.000,
        `unit`        VARCHAR(20)               DEFAULT 'adet',
        `unit_price`  DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        `tax_rate`    DECIMAL(5,2)     NOT NULL DEFAULT 20.00,
        `total`       DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        PRIMARY KEY (`id`),
        KEY `idx_invoice` (`invoice_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_payments` (
        `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `invoice_id`  BIGINT UNSIGNED           DEFAULT NULL,
        `amount`      DECIMAL(15,2)    NOT NULL,
        `method`      VARCHAR(20)      NOT NULL DEFAULT 'cash',
        `date`        DATE             NOT NULL,
        `reference`   VARCHAR(100)              DEFAULT NULL,
        `notes`       VARCHAR(500)              DEFAULT NULL,
        `bank_account_id` INT UNSIGNED          DEFAULT NULL,
        `created_by`  VARCHAR(100)              DEFAULT NULL,
        `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_invoice` (`invoice_id`),
        KEY `idx_date` (`date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_bank_accounts` (
        `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        `name`        VARCHAR(100)     NOT NULL,
        `bank_name`   VARCHAR(100)     NOT NULL,
        `iban`        VARCHAR(34)               DEFAULT NULL,
        `currency`    VARCHAR(3)       NOT NULL DEFAULT 'TRY',
        `balance`     DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        `is_active`   TINYINT(1)       NOT NULL DEFAULT 1,
        `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_bank_transactions` (
        `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `bank_account_id` INT UNSIGNED     NOT NULL,
        `type`            VARCHAR(20)      NOT NULL,
        `amount`          DECIMAL(15,2)    NOT NULL,
        `description`     VARCHAR(500)              DEFAULT NULL,
        `date`            DATE             NOT NULL,
        `reference`       VARCHAR(100)              DEFAULT NULL,
        `reconciled`      TINYINT(1)       NOT NULL DEFAULT 0,
        `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_bank` (`bank_account_id`),
        KEY `idx_date` (`date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── v5.0 Stok & Depo Tabloları ──────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_warehouses` (
        `id`         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        `name`       VARCHAR(100)     NOT NULL,
        `location`   VARCHAR(300)              DEFAULT NULL,
        `manager`    VARCHAR(100)              DEFAULT NULL,
        `is_active`  TINYINT(1)       NOT NULL DEFAULT 1,
        `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_suppliers` (
        `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        `name`            VARCHAR(200)     NOT NULL,
        `contact_person`  VARCHAR(100)              DEFAULT NULL,
        `phone`           VARCHAR(20)               DEFAULT NULL,
        `email`           VARCHAR(255)              DEFAULT NULL,
        `address`         TEXT                      DEFAULT NULL,
        `tax_no`          VARCHAR(20)               DEFAULT NULL,
        `payment_terms`   INT UNSIGNED     NOT NULL DEFAULT 30,
        `rating`          TINYINT UNSIGNED          DEFAULT NULL,
        `is_active`       TINYINT(1)       NOT NULL DEFAULT 1,
        `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_products` (
        `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `sku`           VARCHAR(50)               DEFAULT NULL,
        `barcode`       VARCHAR(50)               DEFAULT NULL,
        `name`          VARCHAR(200)     NOT NULL,
        `category`      VARCHAR(100)              DEFAULT NULL,
        `unit`          VARCHAR(20)      NOT NULL DEFAULT 'kg',
        `min_stock`     DECIMAL(12,3)    NOT NULL DEFAULT 0.000,
        `max_stock`     DECIMAL(12,3)    NOT NULL DEFAULT 0.000,
        `current_stock` DECIMAL(12,3)    NOT NULL DEFAULT 0.000,
        `unit_cost`     DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        `unit_price`    DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        `supplier_id`   INT UNSIGNED              DEFAULT NULL,
        `is_active`     TINYINT(1)       NOT NULL DEFAULT 1,
        `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_barcode` (`barcode`),
        KEY `idx_category` (`category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_stock_movements` (
        `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `product_id`      BIGINT UNSIGNED  NOT NULL,
        `warehouse_id`    INT UNSIGNED     NOT NULL,
        `type`            VARCHAR(20)      NOT NULL,
        `quantity`        DECIMAL(12,3)    NOT NULL,
        `unit_cost`       DECIMAL(15,2)             DEFAULT NULL,
        `reference_type`  VARCHAR(50)               DEFAULT NULL,
        `reference_id`    BIGINT UNSIGNED           DEFAULT NULL,
        `to_warehouse_id` INT UNSIGNED              DEFAULT NULL,
        `notes`           VARCHAR(500)              DEFAULT NULL,
        `created_by`      VARCHAR(100)              DEFAULT NULL,
        `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_product` (`product_id`),
        KEY `idx_warehouse` (`warehouse_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_purchase_orders` (
        `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `order_no`       VARCHAR(30)      NOT NULL,
        `supplier_id`    INT UNSIGNED     NOT NULL,
        `warehouse_id`   INT UNSIGNED     NOT NULL,
        `date`           DATE             NOT NULL,
        `expected_date`  DATE                      DEFAULT NULL,
        `status`         VARCHAR(20)      NOT NULL DEFAULT 'draft',
        `total`          DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        `notes`          TEXT                      DEFAULT NULL,
        `created_by`     VARCHAR(100)              DEFAULT NULL,
        `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_order_no` (`order_no`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_purchase_order_lines` (
        `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `order_id`      BIGINT UNSIGNED  NOT NULL,
        `product_id`    BIGINT UNSIGNED  NOT NULL,
        `quantity`      DECIMAL(12,3)    NOT NULL,
        `received_qty`  DECIMAL(12,3)    NOT NULL DEFAULT 0.000,
        `unit_price`    DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        `total`         DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        PRIMARY KEY (`id`),
        KEY `idx_order` (`order_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_lots` (
        `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `product_id`      BIGINT UNSIGNED  NOT NULL,
        `lot_number`      VARCHAR(50)      NOT NULL,
        `production_date` DATE                      DEFAULT NULL,
        `expiry_date`     DATE                      DEFAULT NULL,
        `quantity`        DECIMAL(12,3)    NOT NULL DEFAULT 0.000,
        `warehouse_id`    INT UNSIGNED              DEFAULT NULL,
        `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_product` (`product_id`),
        KEY `idx_expiry` (`expiry_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── v5.0 İK & Bordro Tabloları ──────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_employees` (
        `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        `employee_no`   VARCHAR(20)      NOT NULL,
        `first_name`    VARCHAR(50)      NOT NULL,
        `last_name`     VARCHAR(50)      NOT NULL,
        `tc_no`         VARCHAR(11)               DEFAULT NULL,
        `phone`         VARCHAR(20)               DEFAULT NULL,
        `email`         VARCHAR(255)              DEFAULT NULL,
        `department`    VARCHAR(100)              DEFAULT NULL,
        `position`      VARCHAR(100)              DEFAULT NULL,
        `hire_date`     DATE             NOT NULL,
        `salary`        DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
        `salary_type`   VARCHAR(20)      NOT NULL DEFAULT 'monthly',
        `shift_group`   VARCHAR(50)               DEFAULT NULL,
        `manager_id`    INT UNSIGNED              DEFAULT NULL,
        `status`        VARCHAR(20)      NOT NULL DEFAULT 'active',
        `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_employee_no` (`employee_no`),
        KEY `idx_department` (`department`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_leave_types` (
        `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        `name`         VARCHAR(100)     NOT NULL,
        `default_days` INT UNSIGNED     NOT NULL DEFAULT 14,
        `is_paid`      TINYINT(1)       NOT NULL DEFAULT 1,
        `is_active`    TINYINT(1)       NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_leave_requests` (
        `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `employee_id`    INT UNSIGNED     NOT NULL,
        `leave_type_id`  INT UNSIGNED     NOT NULL,
        `start_date`     DATE             NOT NULL,
        `end_date`       DATE             NOT NULL,
        `days`           DECIMAL(4,1)     NOT NULL,
        `status`         VARCHAR(20)      NOT NULL DEFAULT 'pending',
        `approved_by`    VARCHAR(100)              DEFAULT NULL,
        `notes`          VARCHAR(500)              DEFAULT NULL,
        `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_employee` (`employee_id`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_attendance` (
        `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `employee_id`     INT UNSIGNED     NOT NULL,
        `date`            DATE             NOT NULL,
        `check_in`        TIME                      DEFAULT NULL,
        `check_out`       TIME                      DEFAULT NULL,
        `status`          VARCHAR(20)      NOT NULL DEFAULT 'present',
        `overtime_hours`  DECIMAL(4,1)     NOT NULL DEFAULT 0.0,
        `notes`           VARCHAR(300)              DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_emp_date` (`employee_id`, `date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_shifts` (
        `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        `name`           VARCHAR(50)      NOT NULL,
        `start_time`     TIME             NOT NULL,
        `end_time`       TIME             NOT NULL,
        `break_minutes`  INT UNSIGNED     NOT NULL DEFAULT 60,
        `is_active`      TINYINT(1)       NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_shift_assignments` (
        `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `employee_id`  INT UNSIGNED     NOT NULL,
        `shift_id`     INT UNSIGNED     NOT NULL,
        `date`         DATE             NOT NULL,
        `created_by`   VARCHAR(100)              DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_emp_date` (`employee_id`, `date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_payroll` (
        `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `employee_id`     INT UNSIGNED     NOT NULL,
        `period_year`     SMALLINT UNSIGNED NOT NULL,
        `period_month`    TINYINT UNSIGNED  NOT NULL,
        `gross_salary`    DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
        `sgk_employee`    DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
        `sgk_employer`    DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
        `income_tax`      DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
        `stamp_tax`       DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
        `net_salary`      DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
        `overtime_pay`    DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
        `deductions`      DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
        `bonuses`         DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
        `status`          VARCHAR(20)      NOT NULL DEFAULT 'draft',
        `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_emp_period` (`employee_id`, `period_year`, `period_month`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_performance_reviews` (
        `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `employee_id`   INT UNSIGNED     NOT NULL,
        `reviewer_id`   INT UNSIGNED              DEFAULT NULL,
        `period`        VARCHAR(20)      NOT NULL,
        `score`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `strengths`     TEXT                      DEFAULT NULL,
        `improvements`  TEXT                      DEFAULT NULL,
        `goals`         TEXT                      DEFAULT NULL,
        `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_employee` (`employee_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_trainings` (
        `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        `title`           VARCHAR(200)     NOT NULL,
        `description`     TEXT                      DEFAULT NULL,
        `trainer`         VARCHAR(100)              DEFAULT NULL,
        `date`            DATE             NOT NULL,
        `duration_hours`  DECIMAL(4,1)     NOT NULL DEFAULT 1.0,
        `category`        VARCHAR(100)              DEFAULT NULL,
        `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_training_participants` (
        `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `training_id`   INT UNSIGNED     NOT NULL,
        `employee_id`   INT UNSIGNED     NOT NULL,
        `status`        VARCHAR(20)      NOT NULL DEFAULT 'registered',
        `score`         TINYINT UNSIGNED          DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_train_emp` (`training_id`, `employee_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── v5.0 Portal & Entegrasyon Tabloları ─────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_customers` (
        `id`                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `name`                VARCHAR(200)     NOT NULL,
        `contact_person`      VARCHAR(100)              DEFAULT NULL,
        `phone`               VARCHAR(20)               DEFAULT NULL,
        `email`               VARCHAR(255)              DEFAULT NULL,
        `address`             TEXT                      DEFAULT NULL,
        `tax_no`              VARCHAR(20)               DEFAULT NULL,
        `sector`              VARCHAR(100)              DEFAULT NULL,
        `credit_limit`        DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        `balance`             DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        `portal_username`     VARCHAR(50)               DEFAULT NULL,
        `portal_password_hash` VARCHAR(255)             DEFAULT NULL,
        `portal_active`       TINYINT(1)       NOT NULL DEFAULT 0,
        `is_active`           TINYINT(1)       NOT NULL DEFAULT 1,
        `created_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_customer_orders` (
        `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `order_no`       VARCHAR(30)      NOT NULL,
        `customer_id`    BIGINT UNSIGNED  NOT NULL,
        `date`           DATE             NOT NULL,
        `delivery_date`  DATE                      DEFAULT NULL,
        `status`         VARCHAR(30)      NOT NULL DEFAULT 'pending',
        `total`          DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        `notes`          TEXT                      DEFAULT NULL,
        `created_by`     VARCHAR(100)              DEFAULT NULL,
        `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_order_no` (`order_no`),
        KEY `idx_customer` (`customer_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_customer_order_lines` (
        `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `order_id`    BIGINT UNSIGNED  NOT NULL,
        `product_id`  BIGINT UNSIGNED           DEFAULT NULL,
        `description` VARCHAR(500)     NOT NULL,
        `quantity`    DECIMAL(12,3)    NOT NULL DEFAULT 1.000,
        `unit_price`  DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        `total`       DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
        PRIMARY KEY (`id`),
        KEY `idx_order` (`order_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_webhooks` (
        `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        `url`             VARCHAR(500)     NOT NULL,
        `events`          LONGTEXT         NOT NULL,
        `secret`          VARCHAR(100)     NOT NULL,
        `is_active`       TINYINT(1)       NOT NULL DEFAULT 1,
        `last_triggered`  DATETIME                  DEFAULT NULL,
        `fail_count`      INT UNSIGNED     NOT NULL DEFAULT 0,
        `created_by`      VARCHAR(100)              DEFAULT NULL,
        `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_webhook_logs` (
        `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `webhook_id`      INT UNSIGNED     NOT NULL,
        `event`           VARCHAR(100)     NOT NULL,
        `payload`         TEXT             NOT NULL,
        `response_code`   INT                       DEFAULT NULL,
        `response_body`   TEXT                      DEFAULT NULL,
        `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_webhook` (`webhook_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_2fa` (
        `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        `user_id`       INT UNSIGNED     NOT NULL,
        `secret`        VARCHAR(64)      NOT NULL,
        `is_enabled`    TINYINT(1)       NOT NULL DEFAULT 0,
        `backup_codes`  LONGTEXT                  DEFAULT NULL,
        `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── v5.0 AI Device Auth ─────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_ai_device_auth` (
        `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        `user_id`         INT UNSIGNED     NOT NULL,
        `username`        VARCHAR(100)     NOT NULL,
        `device_code`     VARCHAR(20)      NOT NULL,
        `session_token`   VARCHAR(128)              DEFAULT NULL,
        `provider`        VARCHAR(30)      NOT NULL DEFAULT 'anthropic',
        `status`          ENUM('pending','approved','expired','failed') NOT NULL DEFAULT 'pending',
        `expires_at`      DATETIME         NOT NULL,
        `approved_at`     DATETIME                  DEFAULT NULL,
        `ip_address`      VARCHAR(45)               DEFAULT NULL,
        `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_device_code` (`device_code`),
        KEY `idx_user_status` (`user_id`, `status`),
        KEY `idx_session` (`session_token`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── v5.0 AI Chat Tablosu ─────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_ai_chats` (
        `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `user`        VARCHAR(100)     NOT NULL,
        `source`      VARCHAR(20)      NOT NULL DEFAULT 'erp',
        `role`        VARCHAR(20)      NOT NULL,
        `message`     TEXT             NOT NULL,
        `context`     LONGTEXT                  DEFAULT NULL,
        `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user` (`user`),
        KEY `idx_source` (`source`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `uysa_telegram_users` (
        `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        `telegram_id`   BIGINT           NOT NULL,
        `telegram_username` VARCHAR(100)          DEFAULT NULL,
        `uysa_user_id`  INT UNSIGNED              DEFAULT NULL,
        `is_verified`   TINYINT(1)       NOT NULL DEFAULT 0,
        `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_telegram` (`telegram_id`)
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
        `scopes`       LONGTEXT     NOT NULL,
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

try { ensureSchema($pdo); } catch (\Throwable $e) { error_log('[UYSA] Schema init error: ' . $e->getMessage()); }

// ── Inline Fallback Stubs (Railway güvenliği için) ───────────
// Eğer src/ dosyaları yoksa stub class'lar devreye girer
if (!class_exists('JwtManager')) {
    if (file_exists(__DIR__ . '/src/JwtManager.php')) {
        require_once __DIR__ . '/src/JwtManager.php';
    } else {
        class JwtManager {
            private string $secret;
            public function __construct(string $s) { $this->secret = $s; }
            public function issue(array $p, int $ttl = 3600): string {
                $h = base64_encode(json_encode(['alg'=>'HS256','typ'=>'JWT']));
                $p['exp'] = time() + $ttl; $p['iat'] = time();
                $pl = base64_encode(json_encode($p));
                $sig = base64_encode(hash_hmac('sha256', "$h.$pl", $this->secret, true));
                return "$h.$pl.$sig";
            }
            public function verify(string $token): array {
                $parts = explode('.', $token);
                if (count($parts) !== 3) throw new \RuntimeException('Invalid JWT');
                [$h, $pl, $sig] = $parts;
                $expected = base64_encode(hash_hmac('sha256', "$h.$pl", $this->secret, true));
                if (!hash_equals($expected, $sig)) throw new \RuntimeException('Invalid signature');
                $payload = json_decode(base64_decode($pl), true);
                if (($payload['exp'] ?? 0) < time()) throw new \RuntimeException('Token expired');
                return $payload;
            }
            public function refresh(string $token, int $ttl = 3600): string {
                $payload = $this->verify($token);
                unset($payload['exp'], $payload['iat']);
                return $this->issue($payload, $ttl);
            }
        }
    }
}
if (!class_exists('RateLimiter')) {
    if (file_exists(__DIR__ . '/src/RateLimiter.php')) {
        require_once __DIR__ . '/src/RateLimiter.php';
    } else {
        class RateLimiter {
            private \PDO $pdo;
            public function __construct(\PDO $p, int $max=10, int $w=600, int $lock=900) { $this->pdo = $p; }
            public function attempt(string $key): array {
                return ['allowed' => true, 'remaining' => 9, 'retry_after' => 0];
            }
            public function reset(string $key): void {}
        }
    }
}
if (!class_exists('ApiKeyManager')) {
    if (file_exists(__DIR__ . '/src/ApiKeyManager.php')) {
        require_once __DIR__ . '/src/ApiKeyManager.php';
    } else {
        class ApiKeyManager {
            private \PDO $pdo;
            public function __construct(\PDO $p, string $pfx='uysa') { $this->pdo = $p; }
            public function verify(string $key): ?array { return null; }
            public function create(string $name, string $owner, string $role, array $scopes, ?string $expires=null): array {
                return ['key' => '', 'prefix' => '', 'name' => $name];
            }
            public function list(string $owner=''): array { return []; }
            public function revoke(int $id): bool { return true; }
        }
    }
}

// ── JWT Manager ───────────────────────────────────────────────
$jwtManager = new JwtManager(JWT_SECRET);

// ── Rate Limiter ──────────────────────────────────────────────
$rateLimiter = new RateLimiter($pdo, 10, 600, 900);

// ── API Key Manager ───────────────────────────────────────────
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
$publicActions = ['fileDownload', 'ping', 'health', 'stats', 'getToken', 'userAuth', 'portal.login', 'ai.authLogin', 'ai.bridgeProxy'];

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
$AUTH_RATE_ACTIONS = ['getToken', 'userAuth', 'ai.authLogin', 'apiKeyCreate', 'userSave', 'ai.providerAuthStart', 'ai.verifyDevice'];

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

// ── Health ──────────────────────────────────────────────────
case 'health':
    try {
        $pdo->query('SELECT 1');
        $uploadsOk = is_dir(UPLOAD_DIR) || @mkdir(UPLOAD_DIR, 0755, true);
        jsonResponse([
            'ok' => true,
            'db' => 'ok',
            'uploads_dir' => UPLOAD_DIR,
            'uploads_writable' => $uploadsOk && is_writable(UPLOAD_DIR),
            'time' => date('c'),
        ]);
    } catch (\Throwable $e) {
        jsonResponse(['ok' => false, 'db' => 'fail', 'error' => 'health check failed'], 503);
    }

// ── Stats ───────────────────────────────────────────────────
case 'stats':
    try {
        $counts = [
            'storage_keys' => (int)$pdo->query('SELECT COUNT(*) FROM uysa_storage')->fetchColumn(),
            'files'        => (int)$pdo->query('SELECT COUNT(*) FROM uysa_files')->fetchColumn(),
            'users'        => (int)$pdo->query('SELECT COUNT(*) FROM uysa_users')->fetchColumn(),
            'audits'       => (int)$pdo->query('SELECT COUNT(*) FROM uysa_audit')->fetchColumn(),
            'backups'      => (int)$pdo->query('SELECT COUNT(*) FROM uysa_backups')->fetchColumn(),
        ];
        jsonResponse(['ok' => true, 'counts' => $counts, 'time' => date('c')]);
    } catch (\Throwable $e) {
        jsonResponse(['ok' => false, 'error' => 'stats failed'], 500);
    }

// ── AI Provider Device Auth: Başlat ──────────────────────────
case 'ai.providerAuthStart':
    // ERP login gerekli
    if (!$actor) {
        jsonResponse(['ok' => false, 'error' => 'ERP oturumu gerekli'], 401);
    }

    // Kullanıcı bilgilerini al
    $stmt = $pdo->prepare("SELECT id, username FROM uysa_users WHERE username = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$actor]);
    $erpUser = $stmt->fetch();
    if (!$erpUser) {
        jsonResponse(['ok' => false, 'error' => 'Geçersiz ERP kullanıcısı'], 401);
    }

    // Mevcut aktif auth varsa onu döndür
    $existing = $pdo->prepare("SELECT * FROM uysa_ai_device_auth
        WHERE user_id = ? AND status = 'approved' AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $existing->execute([$erpUser['id']]);
    $activeAuth = $existing->fetch();
    if ($activeAuth) {
        jsonResponse([
            'ok'       => true,
            'status'   => 'approved',
            'provider' => $activeAuth['provider'],
            'session_token' => $activeAuth['session_token'],
            'message'  => 'AI bağlantısı zaten aktif',
        ]);
    }

    // Pending olanı iptal et
    $pdo->prepare("UPDATE uysa_ai_device_auth SET status = 'expired'
        WHERE user_id = ? AND status = 'pending'")->execute([$erpUser['id']]);

    // Yeni device code üret
    $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6) . '-' . substr(bin2hex(random_bytes(4)), 0, 6));
    $sessionToken = bin2hex(random_bytes(32));
    $expiresIn = 600; // 10 dakika
    $provider = strtolower(getenv('AI_PROVIDER') ?: 'anthropic');

    require_once __DIR__ . '/src/modules/TelegramBot.php';
    $aiCfg = getAIProvider();
    $providerName = $aiCfg['provider'] === 'openai' ? 'OpenAI' : 'Claude (Anthropic)';

    $pdo->prepare("INSERT INTO uysa_ai_device_auth
        (user_id, username, device_code, session_token, provider, status, expires_at, ip_address)
        VALUES (?, ?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL ? SECOND), ?)")
        ->execute([$erpUser['id'], $erpUser['username'], $code, $sessionToken, $provider, $expiresIn, $clientIp]);

    $deviceId = $pdo->lastInsertId();
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
             . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $verifyUrl = $baseUrl . '/uysa_api.php?action=ai.verifyDevice';

    auditLog($pdo, 'ai_device_auth_start', $actor, null, json_encode([
        'device_id' => $deviceId, 'provider' => $provider
    ]), $clientIp);

    jsonResponse([
        'ok'               => true,
        'status'           => 'pending',
        'device_code'      => $code,
        'device_id'        => (int)$deviceId,
        'provider'         => $providerName,
        'verification_url' => $verifyUrl,
        'expires_in'       => $expiresIn,
        'poll_interval'    => 3,
    ]);

// ── AI Provider Device Auth: Durum Sorgula ───────────────────
case 'ai.providerAuthStatus':
    if (!$actor) {
        jsonResponse(['ok' => false, 'error' => 'ERP oturumu gerekli'], 401);
    }

    $deviceId = (int)($body['device_id'] ?? $_GET['device_id'] ?? 0);
    if (!$deviceId) {
        jsonResponse(['ok' => false, 'error' => 'device_id gerekli'], 400);
    }

    // Expire olmuşları güncelle
    $pdo->exec("UPDATE uysa_ai_device_auth SET status = 'expired'
        WHERE status = 'pending' AND expires_at < NOW()");

    $stmt = $pdo->prepare("SELECT status, session_token, provider, approved_at
        FROM uysa_ai_device_auth WHERE id = ? AND username = ?");
    $stmt->execute([$deviceId, $actor]);
    $row = $stmt->fetch();

    if (!$row) {
        jsonResponse(['ok' => false, 'error' => 'Device auth bulunamadı'], 404);
    }

    $result = ['ok' => true, 'status' => $row['status']];
    if ($row['status'] === 'approved') {
        $result['session_token'] = $row['session_token'];
        $result['provider'] = $row['provider'];
        $result['message'] = 'Bağlantı tamamlandı';
    } elseif ($row['status'] === 'expired') {
        $result['message'] = 'Kod süresi doldu. Yeniden başlatın.';
    } elseif ($row['status'] === 'pending') {
        $result['message'] = 'Bağlantı bekleniyor…';
    }
    jsonResponse($result);

// ── AI Provider Device Auth: Onayla (Verification Page) ──────
case 'ai.verifyDevice':
    // Bu endpoint HTML sayfası döndürür (GET) veya onay işler (POST)
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Verification sayfası göster
        $prefillCode = htmlspecialchars($_GET['code'] ?? '', ENT_QUOTES);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>UYSA AI Bağlantı Onayı</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Inter,system-ui,sans-serif;background:linear-gradient(135deg,#1a56db 0%,#0b3aa6 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;border-radius:16px;padding:40px 36px;width:380px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.3);text-align:center}
h1{font-size:20px;color:#1e293b;margin-bottom:6px}
.sub{color:#64748b;font-size:13px;margin-bottom:24px}
label{display:block;text-align:left;font-weight:600;font-size:12px;color:#374151;margin-bottom:4px}
input{width:100%;padding:12px 16px;border:2px solid #d1d5db;border-radius:10px;font-size:18px;text-align:center;letter-spacing:4px;font-weight:700;font-family:monospace;outline:none;text-transform:uppercase}
input:focus{border-color:#1a56db}
.btn{width:100%;padding:14px;background:linear-gradient(135deg,#1a56db,#7c3aed);color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;margin-top:16px;transition:transform .1s}
.btn:hover{transform:scale(1.02)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.msg{margin-top:16px;padding:10px;border-radius:8px;font-size:13px;display:none}
.msg.ok{display:block;background:#dcfce7;color:#166534;border:1px solid #86efac}
.msg.err{display:block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.logo{font-size:28px;margin-bottom:16px}
</style></head><body>
<div class="card">
  <div class="logo">🔗</div>
  <h1>AI Bağlantı Onayı</h1>
  <p class="sub">UYSA ERP AI asistanını bağlamak için widget\'teki kodu girin.</p>
  <form id="vf" onsubmit="return doVerify(event)">
    <label>Doğrulama Kodu</label>
    <input id="codeInput" maxlength="9" placeholder="XXXX-XXXX" value="' . $prefillCode . '" autofocus required>
    <button class="btn" type="submit" id="submitBtn">Bağlantıyı Onayla</button>
  </form>
  <div id="msg" class="msg"></div>
</div>
<script>
async function doVerify(e){
  e.preventDefault();
  var btn=document.getElementById("submitBtn");
  var msg=document.getElementById("msg");
  btn.disabled=true; btn.textContent="Onaylanıyor...";
  msg.className="msg"; msg.style.display="none";
  try{
    var code=document.getElementById("codeInput").value.trim().toUpperCase();
    var r=await fetch("/uysa_api.php?action=ai.verifyDevice",{
      method:"POST",
      headers:{"Content-Type":"application/json"},
      body:JSON.stringify({device_code:code})
    });
    var d=await r.json();
    if(d.ok){
      msg.className="msg ok"; msg.textContent="✅ "+d.message; msg.style.display="block";
      btn.textContent="Bağlantı Tamamlandı";
      setTimeout(function(){ try{window.close();}catch(e){} },2000);
    } else {
      msg.className="msg err"; msg.textContent=d.error||"Hata oluştu"; msg.style.display="block";
      btn.disabled=false; btn.textContent="Bağlantıyı Onayla";
    }
  }catch(e){
    msg.className="msg err"; msg.textContent="Bağlantı hatası"; msg.style.display="block";
    btn.disabled=false; btn.textContent="Bağlantıyı Onayla";
  }
  return false;
}
</script></body></html>';
        exit;
    }

    // POST: Kodu doğrula ve onayla
    $deviceCode = strtoupper(trim($body['device_code'] ?? ''));
    if (!$deviceCode) {
        jsonResponse(['ok' => false, 'error' => 'Kod gerekli'], 400);
    }

    // Expire olmuşları güncelle
    $pdo->exec("UPDATE uysa_ai_device_auth SET status = 'expired'
        WHERE status = 'pending' AND expires_at < NOW()");

    $stmt = $pdo->prepare("SELECT * FROM uysa_ai_device_auth
        WHERE device_code = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$deviceCode]);
    $authRow = $stmt->fetch();

    if (!$authRow) {
        jsonResponse(['ok' => false, 'error' => 'Geçersiz veya süresi dolmuş kod. Yeni kod alın.'], 404);
    }

    // AI provider kontrolü - API key var mı?
    require_once __DIR__ . '/src/modules/TelegramBot.php';
    $aiCfg = getAIProvider();
    if (!$aiCfg['key']) {
        $pdo->prepare("UPDATE uysa_ai_device_auth SET status = 'failed' WHERE id = ?")
            ->execute([$authRow['id']]);
        jsonResponse(['ok' => false, 'error' => 'AI servisi yapılandırılmamış. Yöneticinize başvurun.'], 503);
    }

    // Onayla
    $pdo->prepare("UPDATE uysa_ai_device_auth SET status = 'approved', approved_at = NOW()
        WHERE id = ?")->execute([$authRow['id']]);

    auditLog($pdo, 'ai_device_auth_approved', $authRow['username'], null, json_encode([
        'device_id' => $authRow['id'], 'provider' => $authRow['provider']
    ]), $clientIp);

    jsonResponse([
        'ok'      => true,
        'message' => 'AI bağlantısı başarıyla tamamlandı. Widget\'a dönebilirsiniz.',
    ]);

// ── AI Provider Auth: Çıkış ──────────────────────────────────
case 'ai.providerAuthLogout':
    if (!$actor) {
        jsonResponse(['ok' => false, 'error' => 'ERP oturumu gerekli'], 401);
    }
    $pdo->prepare("UPDATE uysa_ai_device_auth SET status = 'expired'
        WHERE username = ? AND status IN ('pending','approved')")->execute([$actor]);
    auditLog($pdo, 'ai_device_auth_logout', $actor, null, null, $clientIp);
    jsonResponse(['ok' => true, 'message' => 'AI bağlantısı kaldırıldı']);

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
    $stmt = $pdo->prepare("SELECT * FROM uysa_users WHERE username = ?");
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
    if (!is_array($data)) { jsonResponse(['ok' => false, 'error' => 'data array gerekli'], 400); }
    if (empty($data)) { jsonResponse(['ok' => true, 'saved' => 0, 'count' => 0]); }
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
        jsonResponse(['ok' => true, 'saved' => count($data), 'count' => count($data)]);
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
    // Optional filters for performance
    $prefix = sanitizeInput($_GET['prefix'] ?? '', 255);
    $limit  = min((int)($_GET['limit'] ?? 0), 5000);
    $offset = max((int)($_GET['offset'] ?? 0), 0);

    if ($prefix !== '') {
        $sql = "SELECT store_key, store_value, updated_at FROM uysa_storage WHERE store_key LIKE ? ORDER BY store_key";
        $params = [$prefix . '%'];
        if ($limit > 0) { $sql .= " LIMIT {$limit} OFFSET {$offset}"; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } else {
        $sql = "SELECT store_key, store_value, updated_at FROM uysa_storage ORDER BY store_key";
        if ($limit > 0) { $sql .= " LIMIT {$limit} OFFSET {$offset}"; }
        $rows = $pdo->query($sql)->fetchAll();
    }

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
    $stmt = $pdo->prepare("SELECT * FROM uysa_users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    $dummy = '$2y$10$invalidhashfortimingattackprevention000000000000000000';
    $hash  = $user ? $user['password'] : $dummy;
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
    try {
        // Dynamic SHOW COLUMNS — MySQL 5.7+ compatible, no crash on missing columns
        $colStmt = $pdo->query('SHOW COLUMNS FROM `uysa_users`');
        $existingCols = array_column($colStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $sel = ['`id`', '`username`', '`role`', '`created_at`'];
        $sel[] = in_array('display_name', $existingCols) ? '`display_name`' : "'' as `display_name`";
        $sel[] = in_array('last_login',   $existingCols) ? '`last_login`'   : 'NULL as `last_login`';
        $sel[] = in_array('is_active',    $existingCols) ? '`is_active`'    : '1 as `is_active`';
        $rows  = $pdo->query('SELECT ' . implode(', ', $sel) . ' FROM `uysa_users` ORDER BY `created_at` DESC')->fetchAll();
        jsonResponse(['ok' => true, 'users' => $rows]);
    } catch (\Throwable $e) {
        error_log('[UYSA] userList error: ' . $e->getMessage());
        jsonResponse(['ok' => false, 'error' => 'Kullanici listesi alinamadi: ' . $e->getMessage()], 500);
    }

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
    $stmt  = $pdo->prepare("SELECT * FROM uysa_audit ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$limit]);
    jsonResponse(['ok' => true, 'logs' => $stmt->fetchAll()]);

// ── File List ───────────────────────────────────────────────
case 'fileList':
    $category = sanitizeInput($_GET['category'] ?? $body['category'] ?? '', 100);
    $date     = sanitizeInput($_GET['date'] ?? $body['date'] ?? '', 20);
    $includeDeleted = (($_GET['includeDeleted'] ?? $body['includeDeleted'] ?? false) ? true : false);
    $limit = min((int)($_GET['limit'] ?? $body['limit'] ?? 200), 500);

    $where = [];
    $params = [];
    if ($category !== '') { $where[] = 'category = ?'; $params[] = $category; }
    if ($date !== '')     { $where[] = '`date` = ?';   $params[] = $date; }
    if (!$includeDeleted) { $where[] = 'deleted_at IS NULL'; }

    $sql = 'SELECT id, filename, original, mime, size_bytes, uploaded_by, category, `date`, deleted_at, created_at FROM uysa_files';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY created_at DESC LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['ok' => true, 'files' => $stmt->fetchAll()]);

// ── File Download (public) ───────────────────────────────────
case 'fileDownload':
    $filename = sanitizeInput($_GET['filename'] ?? '', 255);
    if (!$filename) jsonResponse(['ok' => false, 'error' => 'filename gerekli'], 400);

    $stmt = $pdo->prepare('SELECT original, mime, size_bytes, deleted_at FROM uysa_files WHERE filename = ? LIMIT 1');
    $stmt->execute([$filename]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(['ok' => false, 'error' => 'Dosya bulunamadı'], 404);
    if ($row['deleted_at'] !== null) jsonResponse(['ok' => false, 'error' => 'Dosya silinmiş'], 410);

    $path = rtrim(UPLOAD_DIR, '/') . '/' . $filename;
    if (!is_file($path)) jsonResponse(['ok' => false, 'error' => 'Dosya disk üzerinde bulunamadı'], 404);

    header_remove('Content-Type');
    header('Content-Type: ' . ($row['mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: attachment; filename="' . addslashes($row['original'] ?: $filename) . '"');
    readfile($path);
    exit;

// ── File Delete (soft delete) ────────────────────────────────
case 'fileDelete':
    $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
    if (!$id) jsonResponse(['ok' => false, 'error' => 'id gerekli'], 400);
    $pdo->prepare('UPDATE uysa_files SET deleted_at = NOW() WHERE id = ?')->execute([$id]);
    auditLog($pdo, 'file_delete', $actor, (string)$id, null, $clientIp);
    jsonResponse(['ok' => true]);

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

// ── Telegram Webhook ─────────────────────────────────────────
case 'telegram.webhook':
    require_once __DIR__ . '/src/modules/TelegramBot.php';
    handleTelegramWebhook($pdo, $body);
    jsonResponse(['ok' => true]);

// ── Telegram Bot Kurulumu (superadmin) ───────────────────────
case 'telegram.setup':
    if (($authedUser['role'] ?? '') !== 'superadmin') {
        jsonResponse(['ok' => false, 'error' => 'Yetki yok'], 403);
    }
    $botToken = getenv('TELEGRAM_BOT_TOKEN') ?: '';
    if (!$botToken) {
        jsonResponse(['ok' => false, 'error' => 'TELEGRAM_BOT_TOKEN env tanımlı değil'], 400);
    }
    $webhookUrl = sanitizeInput($body['webhook_url'] ?? '', 500);
    if (!$webhookUrl) {
        jsonResponse(['ok' => false, 'error' => 'webhook_url gerekli'], 400);
    }
    // Telegram API'ye webhook kaydet
    $ch = curl_init("https://api.telegram.org/bot{$botToken}/setWebhook");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['url' => $webhookUrl]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);
    jsonResponse(['ok' => $result['ok'] ?? false, 'result' => $result]);

// ── AI Chat (ERP içi asistan — Device Auth + Provider-Agnostic) ──
case 'ai.chat':
    if (!$actor) {
        jsonResponse(['ok' => false, 'error' => 'ERP oturumu gerekli'], 401);
    }
    $question = sanitizeInput($body['message'] ?? '', 2000);
    if (!$question) {
        jsonResponse(['ok' => false, 'error' => 'message gerekli'], 400);
    }

    // Device auth kontrolü
    $authCheck = $pdo->prepare("SELECT id, provider FROM uysa_ai_device_auth
        WHERE username = ? AND status = 'approved' AND expires_at > NOW()
        ORDER BY id DESC LIMIT 1");
    $authCheck->execute([$actor]);
    $deviceAuth = $authCheck->fetch();
    if (!$deviceAuth) {
        jsonResponse(['ok' => false, 'error' => 'ai_auth_required', 'message' => 'AI bağlantısı gerekli. Widget üzerinden bağlantı kurun.'], 403);
    }

    require_once __DIR__ . '/src/modules/TelegramBot.php';

    $aiCfg = getAIProvider();
    if (!$aiCfg['key']) {
        jsonResponse(['ok' => false, 'error' => 'AI servisi şu anda kullanılamıyor. Lütfen yöneticinize başvurun.'], 503);
    }

    // Kullanıcı mesajını kaydet
    $pdo->prepare("INSERT INTO uysa_ai_chats (user, source, role, message) VALUES (?, 'erp', 'user', ?)")
        ->execute([$actor, $question]);

    // Son mesaj geçmişi (bağlam için)
    $history = $pdo->prepare("SELECT role, message FROM uysa_ai_chats
                              WHERE user = ? AND source = 'erp' ORDER BY created_at DESC LIMIT 10");
    $history->execute([$actor]);
    $historyRows = array_reverse($history->fetchAll());

    // ERP bağlamı
    $context = buildERPContext($pdo);

    // Mesaj geçmişi oluştur
    $messages = [];
    foreach ($historyRows as $h) {
        $messages[] = ['role' => $h['role'], 'content' => $h['message']];
    }
    if (empty($messages) || end($messages)['content'] !== $question) {
        $messages[] = ['role' => 'user', 'content' => $question];
    }

    $systemPrompt = "Sen UYSA ERP sisteminin AI asistanısın. Yemek sektörü (food service) ERP'si hakkında sorulara yanıt veriyorsun. "
                  . "Türkçe yanıt ver. Markdown formatı kullan. Kısa ve öz yanıtlar ver. "
                  . "Kullanıcının ERP verileri hakkında sorularını yanıtla. "
                  . "Güncel ERP verileri:\n\n{$context}";

    // Sunucu API anahtarıyla AI çağrısı
    $aiResponse = callAI($question, $systemPrompt, $messages);

    if (!$aiResponse) {
        jsonResponse(['ok' => false, 'error' => 'AI yanıt veremedi'], 503);
    }

    // Yanıtı kaydet
    $pdo->prepare("INSERT INTO uysa_ai_chats (user, source, role, message) VALUES (?, 'erp', 'assistant', ?)")
        ->execute([$actor, $aiResponse]);

    jsonResponse(['ok' => true, 'response' => $aiResponse, 'provider' => $aiCfg['provider']]);

// ── AI Sohbet Geçmişi ────────────────────────────────────────
case 'ai.history':
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $source = sanitizeInput($_GET['source'] ?? '', 20);
    $where = "user = ?";
    $params = [$actor];
    if ($source) {
        $where .= " AND source = ?";
        $params[] = $source;
    }
    $stmt = $pdo->prepare("SELECT id, role, message, source, created_at FROM uysa_ai_chats
                           WHERE {$where} ORDER BY created_at DESC LIMIT {$limit}");
    $stmt->execute($params);
    jsonResponse(['ok' => true, 'messages' => array_reverse($stmt->fetchAll())]);

// ── AI Bridge Proxy (secret'lar backend'de kalır) ────────────
case 'ai.bridgeProxy':
    // Auth: bridge secret server-side, rate limit yeterli
    $bridgeUrl = getenv('AI_BRIDGE_URL') ?: 'https://srv1516979.hstgr.cloud/ai-bridge';
    $bridgeSecret = getenv('AI_BRIDGE_SECRET') ?: 'c11a3f1069ef86290a14d6213410c58084c2e164af5ba684b8f45926d49e9dad';
    $path = sanitizeInput($body['path'] ?? '', 200);
    $bMethod = strtoupper(sanitizeInput($body['method'] ?? 'GET', 10));
    $bBody = $body['body'] ?? null;
    if (!$path) jsonResponse(['ok' => false, 'error' => 'path gerekli'], 400);

    $ch = curl_init($bridgeUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Bridge-Secret: ' . $bridgeSecret
        ],
        CURLOPT_CUSTOMREQUEST => $bMethod,
    ]);
    if ($bBody && $bMethod !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($bBody));
    }
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) jsonResponse(['ok' => false, 'error' => 'Bridge bağlantı hatası: ' . $err], 502);
    $data = json_decode($resp, true);
    jsonResponse($data ?: ['ok' => false, 'error' => 'Bridge yanıt hatası'], $httpCode ?: 200);

// ── Default: 404 ─────────────────────────────────────────────
default:
    // ── Modül Router ─────────────────────────────────────────
    // fin.*, inv.*, hr.*, portal.*, ai.* prefix'li action'lar ilgili modüle yönlendirilir
    $moduleMap = [
        'fin.'    => ['file' => 'modules/FinanceModule.php',   'handler' => 'handleFinanceAction'],
        'inv.'    => ['file' => 'modules/InventoryModule.php', 'handler' => 'handleInventoryAction'],
        'hr.'     => ['file' => 'modules/HRModule.php',        'handler' => 'handleHRAction'],
        'portal.' => ['file' => 'modules/PortalModule.php',    'handler' => 'handlePortalAction'],
        'cat.'    => ['file' => 'modules/CateringModule.php',  'handler' => 'handleCateringAction'],
    ];

    $handled = false;
    foreach ($moduleMap as $prefix => $mod) {
        if (str_starts_with($action, $prefix)) {
            $modFile = __DIR__ . '/src/' . $mod['file'];
            if (!file_exists($modFile)) {
                jsonResponse(['ok' => false, 'error' => "Modül dosyası bulunamadı: {$mod['file']}"], 500);
            }
            require_once $modFile;
            $handler = $mod['handler'];
            if (function_exists($handler)) {
                $handler($action, $pdo, $body, $authedUser, $clientIp);
            } else {
                jsonResponse(['ok' => false, 'error' => "Modül handler bulunamadı: {$handler}"], 500);
            }
            $handled = true;
            break;
        }
    }

    if (!$handled) {
        jsonResponse(['ok' => false, 'error' => "Bilinmeyen action: {$action}"], 404);
    }
}
