-- ═══════════════════════════════════════════════════════════════
-- migrate_011.sql — İş emri opus-011 (MySQL 8 / MariaDB, canlı uysa_v2)
-- Taşıma karlılığı: aylık satış/sabit-gider modelinden adet×(satış−alış) modeline geçiş.
--
-- YENİ ALANLAR: adet, birim_alis, birim_satis. sabit_gider ZATEN var (opsiyonel kalır).
-- Eski satis_fiyati alanı BIRAKILIR (idempotent, veri yok) — kod artık birim_satis kullanır.
--
-- İDEMPOTENT: tekrar çalıştırılabilir. MySQL 8'de "ADD COLUMN IF NOT EXISTS" yok →
-- information_schema kontrolü + PREPARE/EXECUTE ile "yoksa ekle" deseni.
-- ÖN KONTROL (Fable): SELECT COUNT(*) FROM tasima_aylik;  (boşsa tamamen temiz geçiş)
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_011.sql
--   veya docker: docker exec -i <mysql> mysql -u <user> -p<pass> uysa_v2 < sql/migrate_011.sql
-- ═══════════════════════════════════════════════════════════════

-- ── 1) tasima_aylik.adet DECIMAL(12,2) DEFAULT 0 (o ay satılan yemek adedi) ─
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasima_aylik' AND COLUMN_NAME = 'adet');
SET @sql := IF(@has = 0,
  "ALTER TABLE `tasima_aylik` ADD COLUMN `adet` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'o ay satılan yemek adedi' AFTER `ay`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 2) tasima_aylik.birim_alis DECIMAL(12,2) DEFAULT 0 (adet başı alış/tedarik) ─
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasima_aylik' AND COLUMN_NAME = 'birim_alis');
SET @sql := IF(@has = 0,
  "ALTER TABLE `tasima_aylik` ADD COLUMN `birim_alis` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'adet başı alış/tedarik TL' AFTER `adet`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 3) tasima_aylik.birim_satis DECIMAL(12,2) DEFAULT 0 (adet başı satış) ─
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasima_aylik' AND COLUMN_NAME = 'birim_satis');
SET @sql := IF(@has = 0,
  "ALTER TABLE `tasima_aylik` ADD COLUMN `birim_satis` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'adet başı satış TL' AFTER `birim_alis`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- sabit_gider zaten migrate_006'da var (opsiyonel, DEFAULT 0). satis_fiyati BIRAKILIR (kod kullanmaz).
