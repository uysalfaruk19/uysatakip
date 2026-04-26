-- ═══════════════════════════════════════════════════════════════
-- UYSA ERP — Complete Schema v5.0
-- MySQL 8.0+
-- ─────────────────────────────────────────────────────────────
-- Bu dosya TÜM tabloları içerir (v4 temel + v5 ERP modülleri)
-- Railway / cPanel'e tek seferde import edilebilir
-- ═══════════════════════════════════════════════════════════════

-- ══════════════════════════════════════════════════════════════
-- TEMEL TABLOLAR (v4 — Core)
-- ══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `uysa_storage` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `store_key`   VARCHAR(255)     NOT NULL,
  `store_value` MEDIUMTEXT       NOT NULL,
  `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_store_key` (`store_key`),
  KEY `idx_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `uysa_backups` (
  `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `backup_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `key_count`   INT UNSIGNED     NOT NULL DEFAULT 0,
  `size_bytes`  INT UNSIGNED     NOT NULL DEFAULT 0,
  `trigger_by`  VARCHAR(100)     NOT NULL DEFAULT 'auto',
  `snapshot`    LONGTEXT         NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_backup_at` (`backup_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `uysa_users` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `username`         VARCHAR(50)   NOT NULL,
  `password`         VARCHAR(255)  NOT NULL,
  `role`             ENUM('superadmin','editor','user','viewer') NOT NULL DEFAULT 'user',
  `display_name`     VARCHAR(100)           DEFAULT NULL,
  `email`            VARCHAR(255)           DEFAULT NULL,
  `last_login`       DATETIME               DEFAULT NULL,
  `failed_attempts`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`     DATETIME               DEFAULT NULL,
  `is_active`        TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_role`           (`role`),
  KEY `idx_active`         (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `uysa_files` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename`    VARCHAR(255)    NOT NULL,
  `original`    VARCHAR(255)    NOT NULL,
  `mime`        VARCHAR(100)    NOT NULL,
  `size_bytes`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `uploaded_by` VARCHAR(100)             DEFAULT NULL,
  `category`    VARCHAR(100)             DEFAULT NULL,
  `date`        DATE                     DEFAULT NULL,
  `deleted_at`  DATETIME                 DEFAULT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category`),
  KEY `idx_deleted`  (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `uysa_audit` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action`      VARCHAR(100)    NOT NULL,
  `actor`       VARCHAR(100)             DEFAULT NULL,
  `target_key`  VARCHAR(255)             DEFAULT NULL,
  `detail`      TEXT                     DEFAULT NULL,
  `ip_addr`     VARCHAR(45)     NOT NULL DEFAULT '',
  `user_agent`  VARCHAR(500)             DEFAULT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_action`  (`action`),
  KEY `idx_actor`   (`actor`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `uysa_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action`     VARCHAR(50)     NOT NULL,
  `store_key`  VARCHAR(255)    NOT NULL DEFAULT '',
  `ip_addr`    VARCHAR(45)     NOT NULL DEFAULT '',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_action`  (`action`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `uysa_rate_limits` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`          VARCHAR(255)    NOT NULL,
  `attempted_at` INT UNSIGNED    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_key_time` (`key`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `uysa_rate_locks` (
  `key`          VARCHAR(255) NOT NULL,
  `locked_until` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `uysa_api_keys` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key_hash`     VARCHAR(64)  NOT NULL,
  `key_prefix`   VARCHAR(20)  NOT NULL,
  `name`         VARCHAR(100) NOT NULL DEFAULT 'API Key',
  `owner`        VARCHAR(100) NOT NULL DEFAULT 'system',
  `role`         VARCHAR(50)  NOT NULL DEFAULT 'viewer',
  `scopes`       JSON                  DEFAULT NULL,
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `uses_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `last_used_at` DATETIME              DEFAULT NULL,
  `expires_at`   DATETIME              DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key_hash` (`key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `uysa_sessions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `jti`        VARCHAR(64)     NOT NULL,
  `username`   VARCHAR(50)     NOT NULL,
  `ip_addr`    VARCHAR(45)     NOT NULL DEFAULT '',
  `user_agent` VARCHAR(500)             DEFAULT NULL,
  `issued_at`  DATETIME        NOT NULL,
  `expires_at` DATETIME        NOT NULL,
  `revoked`    TINYINT(1)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_jti` (`jti`),
  KEY `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══════════════════════════════════════════════════════════════
-- FİNANS & MUHASEBE
-- ══════════════════════════════════════════════════════════════

-- Hesap Planı (Tek Düzen Hesap Planı uyumlu)
CREATE TABLE IF NOT EXISTS `uysa_accounts` (
  `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(20)     NOT NULL COMMENT 'Hesap kodu (ör: 100, 100.01)',
  `name`       VARCHAR(200)    NOT NULL COMMENT 'Hesap adı',
  `type`       ENUM('asset','liability','equity','income','expense')
                               NOT NULL COMMENT 'Hesap türü',
  `parent_id`  INT UNSIGNED             DEFAULT NULL,
  `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_type` (`type`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Hesap Planı — Tek Düzen';

-- Yevmiye Kayıtları (Journal Entries)
CREATE TABLE IF NOT EXISTS `uysa_journal_entries` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entry_no`    VARCHAR(30)     NOT NULL COMMENT 'Fiş numarası',
  `date`        DATE            NOT NULL,
  `description` VARCHAR(500)             DEFAULT NULL,
  `status`      ENUM('draft','posted','void') NOT NULL DEFAULT 'draft',
  `created_by`  VARCHAR(100)             DEFAULT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entry_no` (`entry_no`),
  KEY `idx_date` (`date`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Yevmiye Fişleri';

-- Yevmiye Satırları (Çift Taraflı Kayıt)
CREATE TABLE IF NOT EXISTS `uysa_journal_lines` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `entry_id`    BIGINT UNSIGNED  NOT NULL,
  `account_id`  INT UNSIGNED     NOT NULL,
  `debit`       DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
  `credit`      DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
  `description` VARCHAR(300)              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_entry` (`entry_id`),
  KEY `idx_account` (`account_id`),
  CONSTRAINT `fk_jl_entry` FOREIGN KEY (`entry_id`) REFERENCES `uysa_journal_entries`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jl_account` FOREIGN KEY (`account_id`) REFERENCES `uysa_accounts`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Yevmiye Fiş Satırları — Borç/Alacak';

-- Faturalar
CREATE TABLE IF NOT EXISTS `uysa_invoices` (
  `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `invoice_no`   VARCHAR(30)      NOT NULL,
  `type`         ENUM('sales','purchase') NOT NULL,
  `customer_id`  BIGINT UNSIGNED           DEFAULT NULL,
  `supplier_id`  INT UNSIGNED              DEFAULT NULL,
  `date`         DATE             NOT NULL,
  `due_date`     DATE                      DEFAULT NULL,
  `subtotal`     DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
  `tax_rate`     DECIMAL(5,2)     NOT NULL DEFAULT 20.00 COMMENT 'KDV oranı %',
  `tax_amount`   DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
  `total`        DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
  `status`       ENUM('draft','sent','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
  `notes`        TEXT                      DEFAULT NULL,
  `created_by`   VARCHAR(100)              DEFAULT NULL,
  `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invoice_no` (`invoice_no`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_date` (`date`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_supplier` (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Faturalar — Satış & Alış';

-- Fatura Satırları
CREATE TABLE IF NOT EXISTS `uysa_invoice_lines` (
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
  KEY `idx_invoice` (`invoice_id`),
  CONSTRAINT `fk_il_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `uysa_invoices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ödeme Kayıtları
CREATE TABLE IF NOT EXISTS `uysa_payments` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `invoice_id`  BIGINT UNSIGNED           DEFAULT NULL,
  `amount`      DECIMAL(15,2)    NOT NULL,
  `method`      ENUM('cash','bank','credit_card','check','other') NOT NULL DEFAULT 'cash',
  `date`        DATE             NOT NULL,
  `reference`   VARCHAR(100)              DEFAULT NULL,
  `notes`       VARCHAR(500)              DEFAULT NULL,
  `bank_account_id` INT UNSIGNED          DEFAULT NULL,
  `created_by`  VARCHAR(100)              DEFAULT NULL,
  `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_invoice` (`invoice_id`),
  KEY `idx_date` (`date`),
  KEY `idx_method` (`method`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Banka Hesapları
CREATE TABLE IF NOT EXISTS `uysa_bank_accounts` (
  `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100)     NOT NULL,
  `bank_name`   VARCHAR(100)     NOT NULL,
  `iban`        VARCHAR(34)               DEFAULT NULL,
  `currency`    VARCHAR(3)       NOT NULL DEFAULT 'TRY',
  `balance`     DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
  `is_active`   TINYINT(1)       NOT NULL DEFAULT 1,
  `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Banka Hareketleri
CREATE TABLE IF NOT EXISTS `uysa_bank_transactions` (
  `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `bank_account_id` INT UNSIGNED     NOT NULL,
  `type`            ENUM('deposit','withdrawal') NOT NULL,
  `amount`          DECIMAL(15,2)    NOT NULL,
  `description`     VARCHAR(500)              DEFAULT NULL,
  `date`            DATE             NOT NULL,
  `reference`       VARCHAR(100)              DEFAULT NULL,
  `reconciled`      TINYINT(1)       NOT NULL DEFAULT 0,
  `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bank` (`bank_account_id`),
  KEY `idx_date` (`date`),
  CONSTRAINT `fk_bt_bank` FOREIGN KEY (`bank_account_id`) REFERENCES `uysa_bank_accounts`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══════════════════════════════════════════════════════════════
-- STOK & DEPO
-- ══════════════════════════════════════════════════════════════

-- Depolar
CREATE TABLE IF NOT EXISTS `uysa_warehouses` (
  `id`         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100)     NOT NULL,
  `location`   VARCHAR(300)              DEFAULT NULL,
  `manager`    VARCHAR(100)              DEFAULT NULL,
  `is_active`  TINYINT(1)       NOT NULL DEFAULT 1,
  `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tedarikçiler
CREATE TABLE IF NOT EXISTS `uysa_suppliers` (
  `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(200)     NOT NULL,
  `contact_person`  VARCHAR(100)              DEFAULT NULL,
  `phone`           VARCHAR(20)               DEFAULT NULL,
  `email`           VARCHAR(255)              DEFAULT NULL,
  `address`         TEXT                      DEFAULT NULL,
  `tax_no`          VARCHAR(20)               DEFAULT NULL,
  `payment_terms`   INT UNSIGNED     NOT NULL DEFAULT 30 COMMENT 'Vade (gün)',
  `rating`          TINYINT UNSIGNED          DEFAULT NULL COMMENT '1-5 puan',
  `is_active`       TINYINT(1)       NOT NULL DEFAULT 1,
  `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ürünler / Malzemeler
CREATE TABLE IF NOT EXISTS `uysa_products` (
  `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `sku`           VARCHAR(50)               DEFAULT NULL COMMENT 'Stok Kodu',
  `barcode`       VARCHAR(50)               DEFAULT NULL,
  `name`          VARCHAR(200)     NOT NULL,
  `category`      VARCHAR(100)              DEFAULT NULL,
  `unit`          VARCHAR(20)      NOT NULL DEFAULT 'kg' COMMENT 'Birim (kg, lt, adet, paket)',
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
  UNIQUE KEY `uk_sku` (`sku`),
  KEY `idx_barcode` (`barcode`),
  KEY `idx_category` (`category`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_low_stock` (`current_stock`, `min_stock`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stok Hareketleri
CREATE TABLE IF NOT EXISTS `uysa_stock_movements` (
  `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `product_id`      BIGINT UNSIGNED  NOT NULL,
  `warehouse_id`    INT UNSIGNED     NOT NULL,
  `type`            ENUM('in','out','transfer','adjustment') NOT NULL,
  `quantity`         DECIMAL(12,3)   NOT NULL,
  `unit_cost`       DECIMAL(15,2)             DEFAULT NULL,
  `reference_type`  VARCHAR(50)               DEFAULT NULL COMMENT 'purchase_order, invoice, manual',
  `reference_id`    BIGINT UNSIGNED           DEFAULT NULL,
  `to_warehouse_id` INT UNSIGNED              DEFAULT NULL COMMENT 'Transfer hedef depo',
  `notes`           VARCHAR(500)              DEFAULT NULL,
  `created_by`      VARCHAR(100)              DEFAULT NULL,
  `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_warehouse` (`warehouse_id`),
  KEY `idx_type` (`type`),
  KEY `idx_date` (`created_at`),
  CONSTRAINT `fk_sm_product` FOREIGN KEY (`product_id`) REFERENCES `uysa_products`(`id`),
  CONSTRAINT `fk_sm_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `uysa_warehouses`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Satın Alma Siparişleri
CREATE TABLE IF NOT EXISTS `uysa_purchase_orders` (
  `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `order_no`       VARCHAR(30)      NOT NULL,
  `supplier_id`    INT UNSIGNED     NOT NULL,
  `warehouse_id`   INT UNSIGNED     NOT NULL,
  `date`           DATE             NOT NULL,
  `expected_date`  DATE                      DEFAULT NULL,
  `status`         ENUM('draft','sent','partial','received','cancelled') NOT NULL DEFAULT 'draft',
  `total`          DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
  `notes`          TEXT                      DEFAULT NULL,
  `created_by`     VARCHAR(100)              DEFAULT NULL,
  `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_status` (`status`),
  KEY `idx_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Satın Alma Sipariş Satırları
CREATE TABLE IF NOT EXISTS `uysa_purchase_order_lines` (
  `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `order_id`      BIGINT UNSIGNED  NOT NULL,
  `product_id`    BIGINT UNSIGNED  NOT NULL,
  `quantity`      DECIMAL(12,3)    NOT NULL,
  `received_qty`  DECIMAL(12,3)    NOT NULL DEFAULT 0.000,
  `unit_price`    DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
  `total`         DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  CONSTRAINT `fk_pol_order` FOREIGN KEY (`order_id`) REFERENCES `uysa_purchase_orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lot / Parti Takibi
CREATE TABLE IF NOT EXISTS `uysa_lots` (
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
  KEY `idx_expiry` (`expiry_date`),
  KEY `idx_lot` (`lot_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══════════════════════════════════════════════════════════════
-- İK & BORDRO
-- ══════════════════════════════════════════════════════════════

-- Personel
CREATE TABLE IF NOT EXISTS `uysa_employees` (
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
  `salary_type`   ENUM('monthly','hourly') NOT NULL DEFAULT 'monthly',
  `shift_group`   VARCHAR(50)               DEFAULT NULL,
  `manager_id`    INT UNSIGNED              DEFAULT NULL,
  `status`        ENUM('active','passive','left') NOT NULL DEFAULT 'active',
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_employee_no` (`employee_no`),
  KEY `idx_department` (`department`),
  KEY `idx_status` (`status`),
  KEY `idx_manager` (`manager_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- İzin Tipleri
CREATE TABLE IF NOT EXISTS `uysa_leave_types` (
  `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(100)     NOT NULL,
  `default_days` INT UNSIGNED     NOT NULL DEFAULT 14,
  `is_paid`      TINYINT(1)       NOT NULL DEFAULT 1,
  `is_active`    TINYINT(1)       NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Varsayılan İzin Tipleri
INSERT IGNORE INTO `uysa_leave_types` (`id`, `name`, `default_days`, `is_paid`) VALUES
  (1, 'Yıllık İzin', 14, 1),
  (2, 'Hastalık İzni', 0, 1),
  (3, 'Ücretsiz İzin', 0, 0),
  (4, 'Evlilik İzni', 3, 1),
  (5, 'Doğum İzni', 56, 1),
  (6, 'Babalık İzni', 5, 1),
  (7, 'Ölüm İzni', 3, 1),
  (8, 'Mazeret İzni', 0, 1);

-- İzin Talepleri
CREATE TABLE IF NOT EXISTS `uysa_leave_requests` (
  `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `employee_id`    INT UNSIGNED     NOT NULL,
  `leave_type_id`  INT UNSIGNED     NOT NULL,
  `start_date`     DATE             NOT NULL,
  `end_date`       DATE             NOT NULL,
  `days`           DECIMAL(4,1)     NOT NULL,
  `status`         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by`    VARCHAR(100)              DEFAULT NULL,
  `notes`          VARCHAR(500)              DEFAULT NULL,
  `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_status` (`status`),
  KEY `idx_dates` (`start_date`, `end_date`),
  CONSTRAINT `fk_lr_employee` FOREIGN KEY (`employee_id`) REFERENCES `uysa_employees`(`id`),
  CONSTRAINT `fk_lr_type` FOREIGN KEY (`leave_type_id`) REFERENCES `uysa_leave_types`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Puantaj / Yoklama
CREATE TABLE IF NOT EXISTS `uysa_attendance` (
  `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `employee_id`     INT UNSIGNED     NOT NULL,
  `date`            DATE             NOT NULL,
  `check_in`        TIME                      DEFAULT NULL,
  `check_out`       TIME                      DEFAULT NULL,
  `status`          ENUM('present','absent','late','half_day','holiday','leave') NOT NULL DEFAULT 'present',
  `overtime_hours`  DECIMAL(4,1)     NOT NULL DEFAULT 0.0,
  `notes`           VARCHAR(300)              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_emp_date` (`employee_id`, `date`),
  KEY `idx_date` (`date`),
  CONSTRAINT `fk_att_employee` FOREIGN KEY (`employee_id`) REFERENCES `uysa_employees`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vardiya Tanımları
CREATE TABLE IF NOT EXISTS `uysa_shifts` (
  `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(50)      NOT NULL,
  `start_time`     TIME             NOT NULL,
  `end_time`       TIME             NOT NULL,
  `break_minutes`  INT UNSIGNED     NOT NULL DEFAULT 60,
  `is_active`      TINYINT(1)       NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Varsayılan Vardiyalar
INSERT IGNORE INTO `uysa_shifts` (`id`, `name`, `start_time`, `end_time`, `break_minutes`) VALUES
  (1, 'Sabah', '06:00', '14:00', 30),
  (2, 'Öğle', '14:00', '22:00', 30),
  (3, 'Gece', '22:00', '06:00', 30),
  (4, 'Tam Gün', '08:00', '17:00', 60);

-- Vardiya Atamaları
CREATE TABLE IF NOT EXISTS `uysa_shift_assignments` (
  `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `employee_id`  INT UNSIGNED     NOT NULL,
  `shift_id`     INT UNSIGNED     NOT NULL,
  `date`         DATE             NOT NULL,
  `created_by`   VARCHAR(100)              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_emp_date` (`employee_id`, `date`),
  KEY `idx_date` (`date`),
  CONSTRAINT `fk_sa_employee` FOREIGN KEY (`employee_id`) REFERENCES `uysa_employees`(`id`),
  CONSTRAINT `fk_sa_shift` FOREIGN KEY (`shift_id`) REFERENCES `uysa_shifts`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bordro
CREATE TABLE IF NOT EXISTS `uysa_payroll` (
  `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `employee_id`     INT UNSIGNED     NOT NULL,
  `period_year`     SMALLINT UNSIGNED NOT NULL,
  `period_month`    TINYINT UNSIGNED  NOT NULL,
  `gross_salary`    DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  `sgk_employee`    DECIMAL(12,2)    NOT NULL DEFAULT 0.00 COMMENT 'SGK İşçi Payı %14',
  `sgk_employer`    DECIMAL(12,2)    NOT NULL DEFAULT 0.00 COMMENT 'SGK İşveren Payı %20.5',
  `income_tax`      DECIMAL(12,2)    NOT NULL DEFAULT 0.00 COMMENT 'Gelir Vergisi',
  `stamp_tax`       DECIMAL(12,2)    NOT NULL DEFAULT 0.00 COMMENT 'Damga Vergisi %0.759',
  `net_salary`      DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  `overtime_pay`    DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  `deductions`      DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  `bonuses`         DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  `status`          ENUM('draft','approved','paid') NOT NULL DEFAULT 'draft',
  `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_emp_period` (`employee_id`, `period_year`, `period_month`),
  KEY `idx_period` (`period_year`, `period_month`),
  CONSTRAINT `fk_pr_employee` FOREIGN KEY (`employee_id`) REFERENCES `uysa_employees`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Performans Değerlendirme
CREATE TABLE IF NOT EXISTS `uysa_performance_reviews` (
  `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `employee_id`   INT UNSIGNED     NOT NULL,
  `reviewer_id`   INT UNSIGNED              DEFAULT NULL,
  `period`        VARCHAR(20)      NOT NULL COMMENT 'ör: 2026-Q1',
  `score`         TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100 puan',
  `strengths`     TEXT                      DEFAULT NULL,
  `improvements`  TEXT                      DEFAULT NULL,
  `goals`         TEXT                      DEFAULT NULL,
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_period` (`period`),
  CONSTRAINT `fk_prv_employee` FOREIGN KEY (`employee_id`) REFERENCES `uysa_employees`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eğitimler
CREATE TABLE IF NOT EXISTS `uysa_trainings` (
  `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `title`           VARCHAR(200)     NOT NULL,
  `description`     TEXT                      DEFAULT NULL,
  `trainer`         VARCHAR(100)              DEFAULT NULL,
  `date`            DATE             NOT NULL,
  `duration_hours`  DECIMAL(4,1)     NOT NULL DEFAULT 1.0,
  `category`        VARCHAR(100)              DEFAULT NULL,
  `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eğitim Katılımcıları
CREATE TABLE IF NOT EXISTS `uysa_training_participants` (
  `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `training_id`   INT UNSIGNED     NOT NULL,
  `employee_id`   INT UNSIGNED     NOT NULL,
  `status`        ENUM('registered','completed','absent') NOT NULL DEFAULT 'registered',
  `score`         TINYINT UNSIGNED          DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_train_emp` (`training_id`, `employee_id`),
  CONSTRAINT `fk_tp_training` FOREIGN KEY (`training_id`) REFERENCES `uysa_trainings`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tp_employee` FOREIGN KEY (`employee_id`) REFERENCES `uysa_employees`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══════════════════════════════════════════════════════════════
-- PORTAL & ENTEGRASYON
-- ══════════════════════════════════════════════════════════════

-- Müşteriler (Genişletilmiş)
CREATE TABLE IF NOT EXISTS `uysa_customers` (
  `id`                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`                VARCHAR(200)     NOT NULL,
  `contact_person`      VARCHAR(100)              DEFAULT NULL,
  `phone`               VARCHAR(20)               DEFAULT NULL,
  `email`               VARCHAR(255)              DEFAULT NULL,
  `address`             TEXT                      DEFAULT NULL,
  `tax_no`              VARCHAR(20)               DEFAULT NULL,
  `sector`              VARCHAR(100)              DEFAULT NULL,
  `credit_limit`        DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
  `balance`             DECIMAL(15,2)    NOT NULL DEFAULT 0.00 COMMENT 'Cari bakiye',
  `portal_username`     VARCHAR(50)               DEFAULT NULL,
  `portal_password_hash` VARCHAR(255)             DEFAULT NULL,
  `portal_active`       TINYINT(1)       NOT NULL DEFAULT 0,
  `is_active`           TINYINT(1)       NOT NULL DEFAULT 1,
  `created_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_portal_user` (`portal_username`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Müşteri Siparişleri
CREATE TABLE IF NOT EXISTS `uysa_customer_orders` (
  `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `order_no`       VARCHAR(30)      NOT NULL,
  `customer_id`    BIGINT UNSIGNED  NOT NULL,
  `date`           DATE             NOT NULL,
  `delivery_date`  DATE                      DEFAULT NULL,
  `status`         ENUM('pending','confirmed','preparing','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
  `total`          DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
  `notes`          TEXT                      DEFAULT NULL,
  `created_by`     VARCHAR(100)              DEFAULT NULL,
  `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_status` (`status`),
  KEY `idx_date` (`date`),
  CONSTRAINT `fk_co_customer` FOREIGN KEY (`customer_id`) REFERENCES `uysa_customers`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Müşteri Sipariş Satırları
CREATE TABLE IF NOT EXISTS `uysa_customer_order_lines` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `order_id`    BIGINT UNSIGNED  NOT NULL,
  `product_id`  BIGINT UNSIGNED           DEFAULT NULL,
  `description` VARCHAR(500)     NOT NULL,
  `quantity`    DECIMAL(12,3)    NOT NULL DEFAULT 1.000,
  `unit_price`  DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
  `total`       DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  CONSTRAINT `fk_col_order` FOREIGN KEY (`order_id`) REFERENCES `uysa_customer_orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webhooks
CREATE TABLE IF NOT EXISTS `uysa_webhooks` (
  `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `url`             VARCHAR(500)     NOT NULL,
  `events`          JSON             NOT NULL COMMENT '["invoice.created","order.created"]',
  `secret`          VARCHAR(100)     NOT NULL,
  `is_active`       TINYINT(1)       NOT NULL DEFAULT 1,
  `last_triggered`  DATETIME                  DEFAULT NULL,
  `fail_count`      INT UNSIGNED     NOT NULL DEFAULT 0,
  `created_by`      VARCHAR(100)              DEFAULT NULL,
  `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webhook Log
CREATE TABLE IF NOT EXISTS `uysa_webhook_logs` (
  `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `webhook_id`      INT UNSIGNED     NOT NULL,
  `event`           VARCHAR(100)     NOT NULL,
  `payload`         TEXT             NOT NULL,
  `response_code`   INT                       DEFAULT NULL,
  `response_body`   TEXT                      DEFAULT NULL,
  `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_webhook` (`webhook_id`),
  KEY `idx_event` (`event`),
  CONSTRAINT `fk_wl_webhook` FOREIGN KEY (`webhook_id`) REFERENCES `uysa_webhooks`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2FA (İki Faktörlü Doğrulama)
CREATE TABLE IF NOT EXISTS `uysa_2fa` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED     NOT NULL,
  `secret`        VARCHAR(64)      NOT NULL,
  `is_enabled`    TINYINT(1)       NOT NULL DEFAULT 0,
  `backup_codes`  JSON                      DEFAULT NULL,
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`),
  CONSTRAINT `fk_2fa_user` FOREIGN KEY (`user_id`) REFERENCES `uysa_users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══════════════════════════════════════════════════════════════
-- AI ASISTAN & TELEGRAM
-- ══════════════════════════════════════════════════════════════

-- AI Sohbet Geçmişi
CREATE TABLE IF NOT EXISTS `uysa_ai_chats` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user`        VARCHAR(100)     NOT NULL,
  `source`      ENUM('erp','telegram','api') NOT NULL DEFAULT 'erp',
  `role`        ENUM('user','assistant')     NOT NULL,
  `message`     TEXT             NOT NULL,
  `context`     JSON                      DEFAULT NULL COMMENT 'ERP verisi bağlamı',
  `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user`),
  KEY `idx_source` (`source`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Telegram Bot Kullanıcı Eşleme
CREATE TABLE IF NOT EXISTS `uysa_telegram_users` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `telegram_id`   BIGINT           NOT NULL,
  `telegram_username` VARCHAR(100)          DEFAULT NULL,
  `uysa_user_id`  INT UNSIGNED              DEFAULT NULL,
  `is_verified`   TINYINT(1)       NOT NULL DEFAULT 0,
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_telegram` (`telegram_id`),
  KEY `idx_uysa_user` (`uysa_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══════════════════════════════════════════════════════════════
-- COZBIMESKİ DOMAIN TABLOLARI (Catering ERP)
-- ══════════════════════════════════════════════════════════════

-- Yemek Kataloğu
CREATE TABLE IF NOT EXISTS `uysa_dishes` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `code`          VARCHAR(20)      NOT NULL,
  `name`          VARCHAR(150)     NOT NULL,
  `category`      ENUM('ana_yemek','corba','salata','tatli','meyve','icecek','ara_sicak','kahvalti','diger') NOT NULL DEFAULT 'ana_yemek',
  `sub_category`  VARCHAR(50)               DEFAULT NULL COMMENT 'et, tavuk, sebze, bakliyat vb.',
  `unit`          VARCHAR(20)      NOT NULL DEFAULT 'porsiyon',
  `portion_gram`  DECIMAL(10,2)             DEFAULT NULL,
  `calorie`       INT UNSIGNED              DEFAULT NULL,
  `allergens`     VARCHAR(500)              DEFAULT NULL COMMENT 'JSON array: ["gluten","süt",...]',
  `dish_price`    DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
  `cost_price`    DECIMAL(10,2)             DEFAULT NULL,
  `is_active`     TINYINT(1)       NOT NULL DEFAULT 1,
  `notes`         TEXT                      DEFAULT NULL,
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dish_code` (`code`),
  KEY `idx_dish_category` (`category`),
  KEY `idx_dish_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reçete Satırları (yemek malzemeleri)
CREATE TABLE IF NOT EXISTS `uysa_recipe_lines` (
  `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `dish_id`       INT UNSIGNED     NOT NULL,
  `ingredient`    VARCHAR(150)     NOT NULL,
  `quantity`      DECIMAL(10,3)    NOT NULL DEFAULT 0.000 COMMENT 'gram/kişi veya kg/kişi',
  `unit`          VARCHAR(20)      NOT NULL DEFAULT 'gram',
  `unit_price`    DECIMAL(10,4)             DEFAULT NULL COMMENT '₺/kg',
  `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `notes`         VARCHAR(255)              DEFAULT NULL,
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rl_dish` (`dish_id`),
  KEY `idx_rl_ingredient` (`ingredient`),
  CONSTRAINT `fk_rl_dish` FOREIGN KEY (`dish_id`) REFERENCES `uysa_dishes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Menü Planları (haftalık/günlük)
CREATE TABLE IF NOT EXISTS `uysa_menu_plans` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `plan_date`     DATE             NOT NULL,
  `meal_type`     ENUM('ogle','aksam','sabah','gece','kumanya') NOT NULL DEFAULT 'ogle',
  `customer_id`   BIGINT UNSIGNED            DEFAULT NULL COMMENT 'NULL=genel menü',
  `week_no`       TINYINT UNSIGNED          DEFAULT NULL,
  `status`        ENUM('draft','approved','served') NOT NULL DEFAULT 'draft',
  `created_by`    VARCHAR(100)              DEFAULT NULL,
  `notes`         TEXT                      DEFAULT NULL,
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_menu_date_meal_cust` (`plan_date`, `meal_type`, `customer_id`),
  KEY `idx_mp_date` (`plan_date`),
  KEY `idx_mp_customer` (`customer_id`),
  KEY `idx_mp_week` (`week_no`),
  CONSTRAINT `fk_mp_customer` FOREIGN KEY (`customer_id`) REFERENCES `uysa_customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Menü Plan Kalemleri
CREATE TABLE IF NOT EXISTS `uysa_menu_plan_items` (
  `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `plan_id`       INT UNSIGNED     NOT NULL,
  `dish_id`       INT UNSIGNED     NOT NULL,
  `slot`          ENUM('corba','ana','yan','salata','tatli','meyve','icecek','diger') NOT NULL DEFAULT 'ana',
  `portion_count` INT UNSIGNED              DEFAULT NULL,
  `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_mpi_plan` (`plan_id`),
  KEY `idx_mpi_dish` (`dish_id`),
  CONSTRAINT `fk_mpi_plan` FOREIGN KEY (`plan_id`) REFERENCES `uysa_menu_plans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mpi_dish` FOREIGN KEY (`dish_id`) REFERENCES `uysa_dishes` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cari Hesaplar (COZBIMESKİ parti/cari)
CREATE TABLE IF NOT EXISTS `uysa_parties` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `code`          VARCHAR(20)      NOT NULL,
  `name`          VARCHAR(200)     NOT NULL,
  `type`          ENUM('musteri','tedarikci','personel','diger') NOT NULL DEFAULT 'musteri',
  `tax_no`        VARCHAR(11)               DEFAULT NULL,
  `tax_office`    VARCHAR(100)              DEFAULT NULL,
  `address`       TEXT                      DEFAULT NULL,
  `city`          VARCHAR(50)               DEFAULT NULL,
  `phone`         VARCHAR(30)               DEFAULT NULL,
  `email`         VARCHAR(255)              DEFAULT NULL,
  `iban`          VARCHAR(34)               DEFAULT NULL,
  `balance`       DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
  `credit_limit`  DECIMAL(15,2)             DEFAULT NULL,
  `is_active`     TINYINT(1)       NOT NULL DEFAULT 1,
  `notes`         TEXT                      DEFAULT NULL,
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_party_code` (`code`),
  KEY `idx_party_type` (`type`),
  KEY `idx_party_active` (`is_active`),
  KEY `idx_party_tax` (`tax_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Yemek Eşleşme Matrisi (hangi yemekler birlikte iyi gider)
CREATE TABLE IF NOT EXISTS `uysa_dish_pairings` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `dish_a_id`     INT UNSIGNED     NOT NULL,
  `dish_b_id`     INT UNSIGNED     NOT NULL,
  `score`         TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1-10 uyumluluk skoru',
  `reason`        VARCHAR(255)              DEFAULT NULL,
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pair` (`dish_a_id`, `dish_b_id`),
  KEY `idx_dp_b` (`dish_b_id`),
  CONSTRAINT `fk_dp_a` FOREIGN KEY (`dish_a_id`) REFERENCES `uysa_dishes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dp_b` FOREIGN KEY (`dish_b_id`) REFERENCES `uysa_dishes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Teslimat/Sefer Takibi
CREATE TABLE IF NOT EXISTS `uysa_deliveries` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `delivery_no`   VARCHAR(30)      NOT NULL,
  `delivery_date` DATE             NOT NULL,
  `customer_id`   BIGINT UNSIGNED            DEFAULT NULL,
  `meal_type`     ENUM('ogle','aksam','sabah','gece','kumanya') NOT NULL DEFAULT 'ogle',
  `vehicle`       VARCHAR(50)               DEFAULT NULL,
  `driver`        VARCHAR(100)              DEFAULT NULL,
  `portion_count` INT UNSIGNED     NOT NULL DEFAULT 0,
  `departure_time` TIME                     DEFAULT NULL,
  `arrival_time`  TIME                      DEFAULT NULL,
  `temperature`   DECIMAL(4,1)              DEFAULT NULL COMMENT 'Teslim sıcaklığı °C',
  `status`        ENUM('planned','in_transit','delivered','cancelled') NOT NULL DEFAULT 'planned',
  `recipient`     VARCHAR(100)              DEFAULT NULL COMMENT 'Teslim alan kişi',
  `signature`     TINYINT(1)       NOT NULL DEFAULT 0,
  `notes`         TEXT                      DEFAULT NULL,
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_delivery_no` (`delivery_no`),
  KEY `idx_del_date` (`delivery_date`),
  KEY `idx_del_customer` (`customer_id`),
  KEY `idx_del_status` (`status`),
  CONSTRAINT `fk_del_customer` FOREIGN KEY (`customer_id`) REFERENCES `uysa_customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- HACCP Sıcaklık & Kontrol Kayıtları
CREATE TABLE IF NOT EXISTS `uysa_haccp_logs` (
  `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `log_date`      DATE             NOT NULL,
  `log_time`      TIME                      DEFAULT NULL,
  `check_point`   VARCHAR(100)     NOT NULL COMMENT 'depo, uretim, servis, arac vb.',
  `check_type`    ENUM('temperature','cleaning','pest','quality','other') NOT NULL DEFAULT 'temperature',
  `value`         DECIMAL(6,2)              DEFAULT NULL COMMENT 'Sıcaklık değeri °C',
  `min_limit`     DECIMAL(6,2)              DEFAULT NULL,
  `max_limit`     DECIMAL(6,2)              DEFAULT NULL,
  `is_ok`         TINYINT(1)       NOT NULL DEFAULT 1,
  `corrective_action` TEXT                  DEFAULT NULL,
  `checked_by`    VARCHAR(100)              DEFAULT NULL,
  `equipment`     VARCHAR(100)              DEFAULT NULL COMMENT 'Buzdolabı no, cihaz adı',
  `photo`         VARCHAR(255)              DEFAULT NULL COMMENT 'Fotoğraf dosya yolu',
  `notes`         TEXT                      DEFAULT NULL,
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_haccp_date` (`log_date`),
  KEY `idx_haccp_point` (`check_point`),
  KEY `idx_haccp_type` (`check_type`),
  KEY `idx_haccp_ok` (`is_ok`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══════════════════════════════════════════════════════════════
-- VIEWS
-- ══════════════════════════════════════════════════════════════

-- Düşük stok uyarısı
CREATE OR REPLACE VIEW `v_low_stock` AS
  SELECT p.id, p.sku, p.name, p.category, p.unit,
         p.current_stock, p.min_stock, p.unit_cost,
         s.name AS supplier_name, w.name AS warehouse_name
  FROM uysa_products p
  LEFT JOIN uysa_suppliers s ON p.supplier_id = s.id
  LEFT JOIN (SELECT product_id, warehouse_id FROM uysa_stock_movements GROUP BY product_id, warehouse_id) sm ON sm.product_id = p.id
  LEFT JOIN uysa_warehouses w ON sm.warehouse_id = w.id
  WHERE p.current_stock <= p.min_stock AND p.min_stock > 0 AND p.is_active = 1;

-- Vadesi geçmiş faturalar
CREATE OR REPLACE VIEW `v_overdue_invoices` AS
  SELECT i.*, c.name AS customer_name, s.name AS supplier_name
  FROM uysa_invoices i
  LEFT JOIN uysa_customers c ON i.customer_id = c.id
  LEFT JOIN uysa_suppliers s ON i.supplier_id = s.id
  WHERE i.status IN ('sent','overdue') AND i.due_date < CURDATE();

-- Personel özet
CREATE OR REPLACE VIEW `v_employee_summary` AS
  SELECT e.*,
    (SELECT COUNT(*) FROM uysa_leave_requests lr WHERE lr.employee_id = e.id AND lr.status = 'approved' AND YEAR(lr.start_date) = YEAR(CURDATE())) AS used_leave_days,
    (SELECT MAX(a.date) FROM uysa_attendance a WHERE a.employee_id = e.id) AS last_attendance
  FROM uysa_employees e WHERE e.status = 'active';
