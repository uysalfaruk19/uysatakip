-- ═══════════════════════════════════════════════════════════════
-- migrate_010.sql — İş emri opus-010 (MySQL 8 / MariaDB, canlı uysa_v2)
-- UYSA Kokpit: Menü Yayınlama (hedefli) + Malzeme Talebi.
--   A) menu / menu_item / menu_target  (müşteri-yüzü yayınlanan menü; menu_days'ten AYRI)
--   B) supply_item / supply_request / supply_request_item  (sarf malzeme katalog + talep)
--   + supply_item başlangıç kataloğu seed (INSERT IGNORE — idempotent).
--
-- İDEMPOTENT: yalnız yeni tablo → CREATE TABLE IF NOT EXISTS; seed → INSERT IGNORE
-- (uk_supply_ad). Mevcut veriye dokunmaz, tekrar çalıştırılabilir. menu_days'e DOKUNMAZ.
-- Uygulama (Fable): docker exec -i <container> mysql -u <user> -p uysa_v2 < sql/migrate_010.sql
-- ═══════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── A) Yayınlanan menü ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `menu` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(150) NOT NULL,
  `date_start` DATE         NOT NULL,
  `date_end`   DATE         NOT NULL,
  `audience`   ENUM('all','selected') NOT NULL DEFAULT 'all',
  `status`     ENUM('draft','published') NOT NULL DEFAULT 'draft',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_menu_status` (`status`,`date_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `menu_item` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `menu_id`   INT UNSIGNED NOT NULL,
  `item_date` DATE         NOT NULL,
  `meal`      ENUM('sabah','ogle','aksam','gece','kumanya') NOT NULL DEFAULT 'ogle',
  `dishes`    TEXT                  DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_menu_item` (`menu_id`,`item_date`,`meal`),
  CONSTRAINT `fk_menu_item_menu` FOREIGN KEY (`menu_id`) REFERENCES `menu` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `menu_target` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `menu_id`     INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_menu_target` (`menu_id`,`customer_id`),
  KEY `idx_mt_customer` (`customer_id`),
  CONSTRAINT `fk_mt_menu` FOREIGN KEY (`menu_id`) REFERENCES `menu` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mt_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── B) Malzeme talebi ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `supply_item` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ad`         VARCHAR(120) NOT NULL,
  `birim`      VARCHAR(20)  NOT NULL DEFAULT 'adet',
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order` INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_supply_ad` (`ad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supply_request` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`      INT UNSIGNED NOT NULL,
  `customer_user_id` INT UNSIGNED          DEFAULT NULL,
  `request_date`     DATE         NOT NULL,
  `status`           ENUM('acik','hazirlandi','teslim') NOT NULL DEFAULT 'acik',
  `note`             VARCHAR(500)          DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sr_customer` (`customer_id`),
  KEY `idx_sr_status` (`status`),
  CONSTRAINT `fk_sr_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supply_request_item` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`     INT UNSIGNED NOT NULL,
  `supply_item_id` INT UNSIGNED NOT NULL,
  `miktar`         DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sri` (`request_id`,`supply_item_id`),
  KEY `idx_sri_item` (`supply_item_id`),
  CONSTRAINT `fk_sri_request` FOREIGN KEY (`request_id`) REFERENCES `supply_request` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sri_item` FOREIGN KEY (`supply_item_id`) REFERENCES `supply_item` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Müşteri × malzeme standing hakediş (her müşterinin her kalemden hakkı).
CREATE TABLE IF NOT EXISTS `supply_entitlement` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`    INT UNSIGNED NOT NULL,
  `supply_item_id` INT UNSIGNED NOT NULL,
  `miktar`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entitlement` (`customer_id`,`supply_item_id`),
  KEY `idx_se_item` (`supply_item_id`),
  CONSTRAINT `fk_se_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_se_item` FOREIGN KEY (`supply_item_id`) REFERENCES `supply_item` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sarf malzeme başlangıç kataloğu (Ömer düzenler). UNIQUE(ad) → INSERT IGNORE idempotent.
INSERT IGNORE INTO `supply_item` (`ad`, `birim`, `sort_order`) VALUES
  ('Ayçiçek Yağı', 'litre', 10),
  ('Sirke', 'litre', 20),
  ('Ketçap', 'adet', 30),
  ('Mayonez', 'adet', 40),
  ('Bulaşık Deterjanı', 'litre', 50),
  ('Peçete', 'paket', 60),
  ('Tuz', 'kg', 70),
  ('Karabiber', 'paket', 80),
  ('Çöp Poşeti', 'paket', 90),
  ('Eldiven', 'kutu', 100);

SET FOREIGN_KEY_CHECKS = 1;
