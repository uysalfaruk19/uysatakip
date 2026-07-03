-- ═══════════════════════════════════════════════════════════════
-- UYSA Kokpit (ERP v2) — İlişkisel Şema  (Faz 1, M6 tabloları dahil)
-- MySQL 8 / MariaDB 10.4+ · utf8mb4 · DECIMAL(12,2) · prepared-statement uyumlu
-- İş emri: opus-004 · Mimari: vault/projeler/uysa-erp-v2/mimari.md (rev-2)
--
-- Not: v1 (uysa_storage key-value) tablolarına DOKUNULMAZ; readonly arşiv.
-- Bu şema aynı veritabanında (uysa_db) yeni tablolar olarak yaşar.
-- ═══════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── Kullanıcılar (iç: UYSA personeli) — v1 deseni devralınır ───
CREATE TABLE IF NOT EXISTS `users` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`     VARCHAR(50)  NOT NULL,
  `password`     VARCHAR(255) NOT NULL COMMENT 'bcrypt (cost=12)',
  `role`         ENUM('superadmin','editor','user','viewer') NOT NULL DEFAULT 'user',
  `display_name` VARCHAR(100)          DEFAULT NULL,
  `last_login`   DATETIME              DEFAULT NULL,
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Müşteriler (catering firmaları) ───────────────────────────
CREATE TABLE IF NOT EXISTS `customers` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(150) NOT NULL,
  `unit_price`    DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'kişi başı TL',
  `category`      ENUM('uretim','tasima') NOT NULL DEFAULT 'uretim' COMMENT 'üretim (yemek) / taşıma müşterisi',
  `contact`       VARCHAR(255)          DEFAULT NULL,
  `phone`         VARCHAR(40)           DEFAULT NULL,
  `contract_note` VARCHAR(500)          DEFAULT NULL,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customer_name` (`name`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Taşıma müşterisi aylık karlılık (satış − sabit gider) ─────
-- Taşıma müşterisinin üretim (kişi×fiyat) modeli yok; kâr AYLIK sözleşme:
-- satis_fiyati (aylık hakediş) − sabit_gider (araç/yakıt/şoför vb.) = kâr (türetilmiş).
CREATE TABLE IF NOT EXISTS `tasima_aylik` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`  INT UNSIGNED NOT NULL,
  `ay`           CHAR(7)      NOT NULL COMMENT 'YYYY-MM',
  `satis_fiyati` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'aylık satış/hakediş TL',
  `sabit_gider`  DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'aylık sabit gider TL',
  `note`         VARCHAR(500)          DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tasima_ay` (`customer_id`,`ay`),
  CONSTRAINT `fk_tasima_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Müşteri uygulaması kullanıcıları (M6, dış) — F1'de sadece şema ─
CREATE TABLE IF NOT EXISTS `customer_users` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`      INT UNSIGNED NOT NULL,
  `username`         VARCHAR(60)  NOT NULL,
  `password_bcrypt`  VARCHAR(255) NOT NULL,
  `display_name`     VARCHAR(100)          DEFAULT NULL,
  `phone`            VARCHAR(40)           DEFAULT NULL,
  `role`             ENUM('owner','staff') NOT NULL DEFAULT 'owner',
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login`       DATETIME              DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cu_username` (`username`),
  KEY `idx_cu_customer` (`customer_id`),
  CONSTRAINT `fk_cu_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Siparişler (M6 onay kuyruğu kaynağı) — F1'de bot/elle giriş de buradan akabilir ─
CREATE TABLE IF NOT EXISTS `orders` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `order_date`  DATE         NOT NULL,
  `meal`        ENUM('sabah','ogle','aksam','gece','kumanya') NOT NULL DEFAULT 'ogle',
  `persons`     INT UNSIGNED NOT NULL DEFAULT 0,
  `menu_type`   VARCHAR(40)           DEFAULT NULL,
  `status`      ENUM('taslak','gonderildi','onaylandi','reddedildi') NOT NULL DEFAULT 'gonderildi',
  `entered_by`  ENUM('musteri','uysa','bot') NOT NULL DEFAULT 'uysa',
  `note`        VARCHAR(500)          DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order` (`customer_id`,`order_date`,`meal`),
  CONSTRAINT `fk_order_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Üretim (tek gerçek kaynak: fatura/rapor buradan) ──────────
CREATE TABLE IF NOT EXISTS `production` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`      INT UNSIGNED NOT NULL,
  `prod_date`        DATE         NOT NULL,
  `meal`             ENUM('sabah','ogle','aksam','gece','kumanya') NOT NULL DEFAULT 'ogle',
  `persons`          INT UNSIGNED NOT NULL DEFAULT 0,
  `unit_price_snap`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `amount`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `order_id`         INT UNSIGNED          DEFAULT NULL,
  `note`             VARCHAR(500)          DEFAULT NULL,
  `entered_by`       ENUM('musteri','uysa','bot') NOT NULL DEFAULT 'uysa',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_production` (`customer_id`,`prod_date`,`meal`),
  KEY `idx_prod_date` (`prod_date`),
  CONSTRAINT `fk_prod_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_prod_order`    FOREIGN KEY (`order_id`)    REFERENCES `orders` (`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Talepler / şikayet / mesaj (M6) ───────────────────────────
CREATE TABLE IF NOT EXISTS `requests` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`      INT UNSIGNED NOT NULL,
  `customer_user_id` INT UNSIGNED          DEFAULT NULL,
  `type`             ENUM('talep','sikayet','mesaj') NOT NULL DEFAULT 'talep',
  `subject`          VARCHAR(200) NOT NULL,
  `status`           ENUM('acik','cozuldu') NOT NULL DEFAULT 'acik',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_req_customer` (`customer_id`),
  KEY `idx_req_status` (`status`),
  CONSTRAINT `fk_req_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `request_messages` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` INT UNSIGNED NOT NULL,
  `sender`     ENUM('musteri','uysa') NOT NULL,
  `body`       TEXT         NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rm_request` (`request_id`),
  CONSTRAINT `fk_rm_request` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Duyurular (M6) ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `announcements` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(200) NOT NULL,
  `body`       TEXT         NOT NULL,
  `publish_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `audience`   VARCHAR(50)  NOT NULL DEFAULT 'hepsi' COMMENT 'hepsi | customer_id',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Dosyalar (fatura foto vb.) — v1 deseni ────────────────────
CREATE TABLE IF NOT EXISTS `files` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename`    VARCHAR(255) NOT NULL COMMENT 'güvenli ad: ts_hex.ext',
  `original`    VARCHAR(255) NOT NULL,
  `mime`        VARCHAR(100) NOT NULL,
  `size_bytes`  INT UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_by` VARCHAR(100)          DEFAULT NULL,
  `category`    VARCHAR(100)          DEFAULT NULL,
  `deleted_at`  DATETIME              DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_files_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Tedarikçiler ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `suppliers` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(200) NOT NULL,
  `contact`    VARCHAR(255)          DEFAULT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_supplier_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Finans: gelir/gider ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `transactions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`        ENUM('gelir','gider') NOT NULL,
  `category`    VARCHAR(80)           DEFAULT NULL,
  `tx_date`     DATE         NOT NULL,
  `amount`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `customer_id` INT UNSIGNED          DEFAULT NULL,
  `supplier_id` INT UNSIGNED          DEFAULT NULL,
  `description` VARCHAR(500)          DEFAULT NULL,
  `file_id`     INT UNSIGNED          DEFAULT NULL,
  -- TODO (Paraşüt): dış-kaynak senkron alanı. Bu turda kullanılmıyor; 'manuel'/'parasut'.
  `source`      VARCHAR(20)  NOT NULL DEFAULT 'manuel',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tx_date` (`tx_date`),
  KEY `idx_tx_type` (`type`),
  CONSTRAINT `fk_tx_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tx_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tx_file`     FOREIGN KEY (`file_id`)     REFERENCES `files` (`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Cari hareketler (alacak/borç, tahsilat) ───────────────────
-- direction: borc = tarafın bize borcu artar (üretim) · alacak = tahsilat/ödeme
CREATE TABLE IF NOT EXISTS `cari_entries` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_type` ENUM('customer','supplier') NOT NULL,
  `party_id`   INT UNSIGNED NOT NULL,
  `entry_date` DATE         NOT NULL,
  `direction`  ENUM('borc','alacak') NOT NULL,
  `amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `note`       VARCHAR(500)          DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cari_party` (`party_type`,`party_id`),
  KEY `idx_cari_date` (`entry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Menü & Maliyet (M4, F2) — şema F1'de hazır ────────────────
CREATE TABLE IF NOT EXISTS `ingredients` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(150) NOT NULL,
  `unit`           VARCHAR(20)  NOT NULL DEFAULT 'kg',
  `price_per_unit` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `min_stok`       DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'kritik stok eşiği (0 = uyarı yok)',
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ingredient` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recipes` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(200) NOT NULL,
  `category`    VARCHAR(80)           DEFAULT NULL,
  `portion_note` VARCHAR(200)         DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_recipe` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recipe_items` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipe_id`     INT UNSIGNED NOT NULL,
  `ingredient_id` INT UNSIGNED NOT NULL,
  `grams`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_ri_recipe` (`recipe_id`),
  CONSTRAINT `fk_ri_recipe`     FOREIGN KEY (`recipe_id`)     REFERENCES `recipes` (`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_ri_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `menu_days` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `menu_date`    DATE         NOT NULL,
  `meal`         ENUM('sabah','ogle','aksam','gece','kumanya') NOT NULL DEFAULT 'ogle',
  `menu_type`    VARCHAR(40)  NOT NULL DEFAULT 'standart',
  `recipe_id`    INT UNSIGNED          DEFAULT NULL,
  `is_published` TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_menu_date` (`menu_date`),
  CONSTRAINT `fk_md_recipe` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Stok hareketleri (M4 — Stok Durumu) ───────────────────────
-- Mevcut stok = Σ(giris) − Σ(cikis) malzeme başına. Kritik = stok < ingredients.min_stok (>0).
CREATE TABLE IF NOT EXISTS `stock_moves` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ingredient_id` INT UNSIGNED NOT NULL,
  `move_date`     DATE         NOT NULL,
  `direction`     ENUM('giris','cikis') NOT NULL,
  `quantity`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `unit`          VARCHAR(20)  NOT NULL DEFAULT 'kg',
  `note`          VARCHAR(500)          DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sm_ingredient` (`ingredient_id`),
  KEY `idx_sm_date` (`move_date`),
  CONSTRAINT `fk_sm_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Denetim + oran sınırlama (v1 güvenlik desenleri) ──────────
CREATE TABLE IF NOT EXISTS `audit` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action`     VARCHAR(100)    NOT NULL,
  `actor`      VARCHAR(100)             DEFAULT NULL,
  `target_key` VARCHAR(255)             DEFAULT NULL,
  `detail`     TEXT                     DEFAULT NULL,
  `ip_addr`    VARCHAR(45)     NOT NULL DEFAULT '',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rl_key`       VARCHAR(255)    NOT NULL,
  `attempted_at` INT UNSIGNED    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rl_key_time` (`rl_key`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rate_locks` (
  `rl_key`       VARCHAR(255) NOT NULL,
  `locked_until` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`rl_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
