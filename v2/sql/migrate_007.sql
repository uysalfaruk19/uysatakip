-- ═══════════════════════════════════════════════════════════════
-- migrate_007.sql — İş emri opus-007 (MySQL 8 / MariaDB, canlı uysa_v2)
-- Müşteri uygulaması (M6) + admin Siparişler modülü.
--
-- ŞEMA DEĞİŞİKLİĞİ YOK: M6 tabloları (customer_users, orders, requests,
-- request_messages) opus-004'te schema_v2.sql'e zaten eklendi. Bu betik
-- SADECE İNSAN GÜVENCESİ: canlı DB o tabloları henüz almadıysa oluşturur.
-- CREATE TABLE IF NOT EXISTS → idempotent, mevcut veriye dokunmaz.
--
-- Uygulama (Fable): docker exec -i <container> mysql -u <user> -p uysa_v2 < sql/migrate_007.sql
-- ═══════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

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

-- production.order_id (sipariş→üretim bağı) — opus-004'te var; yoksa ekle (idempotent).
SET @has_oid := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'production' AND COLUMN_NAME = 'order_id');
SET @sql := IF(@has_oid = 0,
  "ALTER TABLE `production` ADD COLUMN `order_id` INT UNSIGNED DEFAULT NULL AFTER `amount`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET FOREIGN_KEY_CHECKS = 1;
