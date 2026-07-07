-- ═══════════════════════════════════════════════════════════════
-- migrate_006.sql — İş emri opus-006 (MySQL 8 / MariaDB, canlı uysa_v2)
-- Müşteri kategorisi (üretim/taşıma) + taşıma aylık karlılık + tx.source (Paraşüt hazır).
--
-- İDEMPOTENT: tekrar çalıştırılabilir. MySQL 8'de "ADD COLUMN IF NOT EXISTS" yok →
-- information_schema kontrolü + PREPARE/EXECUTE ile "yoksa ekle" deseni.
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_006.sql
-- ═══════════════════════════════════════════════════════════════

-- ── 1) customers.category ENUM('uretim','tasima') DEFAULT 'uretim' ─
SET @has_cat := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'category');
SET @sql := IF(@has_cat = 0,
  "ALTER TABLE `customers` ADD COLUMN `category` ENUM('uretim','tasima') NOT NULL DEFAULT 'uretim' AFTER `unit_price`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
-- Mevcut tüm müşteriler zaten DEFAULT 'uretim' alır; ekstra UPDATE gerekmez.

-- ── 2) transactions.source VARCHAR(20) DEFAULT 'manuel' (Paraşüt hazır, kullanılmıyor) ─
SET @has_src := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'source');
SET @sql := IF(@has_src = 0,
  "ALTER TABLE `transactions` ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'manuel' AFTER `file_id`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 3) tasima_aylik tablosu (CREATE IF NOT EXISTS → zaten idempotent) ─
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
