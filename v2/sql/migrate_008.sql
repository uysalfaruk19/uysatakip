-- ═══════════════════════════════════════════════════════════════
-- migrate_008.sql — İş emri opus-008 (MySQL 8 / MariaDB, canlı uysa_v2)
-- Stok Durumu + Reçete & Maliyet (M4).
--   1) ingredients.min_stok kolonu (kritik stok eşiği, DEFAULT 0)
--   2) stock_moves tablosu (malzeme giriş/çıkış)
--
-- İDEMPOTENT: tekrar çalıştırılabilir. Kolon ekleme için information_schema
-- kontrolü + PREPARE/EXECUTE (MySQL 8'de "ADD COLUMN IF NOT EXISTS" yok);
-- tablo için CREATE TABLE IF NOT EXISTS. Mevcut veriye dokunmaz.
-- Uygulama (Fable): docker exec -i <container> mysql -u <user> -p uysa_v2 < sql/migrate_008.sql
-- ═══════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── 1) ingredients.min_stok DECIMAL(12,2) DEFAULT 0 (kritik eşik) ─
SET @has_min := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ingredients' AND COLUMN_NAME = 'min_stok');
SET @sql := IF(@has_min = 0,
  "ALTER TABLE `ingredients` ADD COLUMN `min_stok` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `price_per_unit`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 2) stock_moves tablosu (CREATE IF NOT EXISTS → idempotent) ─
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

SET FOREIGN_KEY_CHECKS = 1;
