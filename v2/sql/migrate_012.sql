-- ═══════════════════════════════════════════════════════════════
-- migrate_012.sql — İş emri opus-012 (MySQL 8 / MariaDB, canlı uysa_v2)
-- Paraşüt (muhasebe) cari bakiye SALT-OKUMA → Kokpit. customers'a 3 alan ekler.
--
-- 🔒 Bu alanlar Paraşüt'ten OKUNAN veriyi tutar; Kokpit'in kendi cari hesabını EZMEZ,
--    yanında referans olarak durur. Paraşüt'e yazma YOK (opus-013, emsal+onay).
--
-- İDEMPOTENT: tekrar çalıştırılabilir. MySQL 8'de "ADD COLUMN IF NOT EXISTS" yok →
-- information_schema kontrolü + PREPARE/EXECUTE ile "yoksa ekle" deseni.
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_012.sql
--   veya docker: docker exec -i <mysql> mysql -u <user> -p<pass> uysa_v2 < sql/migrate_012.sql
-- ═══════════════════════════════════════════════════════════════

-- ── 1) customers.parasut_id VARCHAR(40) NULL (Paraşüt contact id) ─
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'parasut_id');
SET @sql := IF(@has = 0,
  "ALTER TABLE `customers` ADD COLUMN `parasut_id` VARCHAR(40) DEFAULT NULL COMMENT 'Paraşüt contact id (eşleşince yazılır)' AFTER `contract_note`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 2) customers.parasut_bakiye DECIMAL(14,2) NULL (Paraşüt cari bakiye) ─
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'parasut_bakiye');
SET @sql := IF(@has = 0,
  "ALTER TABLE `customers` ADD COLUMN `parasut_bakiye` DECIMAL(14,2) DEFAULT NULL COMMENT 'Paraşüt güncel cari bakiye (okuma)' AFTER `parasut_id`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 3) customers.parasut_sync_at DATETIME NULL (son senkron) ─
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'parasut_sync_at');
SET @sql := IF(@has = 0,
  "ALTER TABLE `customers` ADD COLUMN `parasut_sync_at` DATETIME DEFAULT NULL COMMENT 'son Paraşüt senkron zamanı' AFTER `parasut_bakiye`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 4) parasut_id üzerinde arama indeksi (yoksa ekle) ─
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND INDEX_NAME = 'idx_parasut_id');
SET @sql := IF(@has = 0,
  "ALTER TABLE `customers` ADD KEY `idx_parasut_id` (`parasut_id`)",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
