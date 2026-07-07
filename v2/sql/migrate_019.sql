-- ═══════════════════════════════════════════════════════════════
-- migrate_019.sql — İş emri opus-019 (MySQL 8 / MariaDB, canlı uysa_v2)
-- Müşteri app iyileştirmeleri: talep türü 'menu'+'oneri' + talep mesajına foto eki.
--
-- Ekler:
--   1) requests.type ENUM'a 'menu' + 'oneri' (mevcut talep/sikayet/mesaj korunur).
--   2) request_messages.file_id INT NULL (foto eki → files.id) + FK (ON DELETE SET NULL).
--
-- İDEMPOTENT: ENUM MODIFY tekrar çalıştırılabilir; kolon/FK için information_schema kontrolü
-- (MySQL 8'de "ADD COLUMN/CONSTRAINT IF NOT EXISTS" yok → PREPARE/EXECUTE "yoksa ekle").
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_019.sql
--   veya docker: docker exec -i <mysql> mysql -u <user> -p<pass> uysa_v2 < sql/migrate_019.sql
-- ═══════════════════════════════════════════════════════════════

-- ── 1) requests.type ENUM'a 'menu' + 'oneri' ekle (mevcut değerler korunur) ─
ALTER TABLE `requests`
  MODIFY COLUMN `type` ENUM('talep','sikayet','mesaj','menu','oneri') NOT NULL DEFAULT 'talep';

-- ── 2) request_messages.file_id INT NULL (foto eki) ─
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'request_messages' AND COLUMN_NAME = 'file_id');
SET @sql := IF(@has = 0,
  "ALTER TABLE `request_messages` ADD COLUMN `file_id` INT UNSIGNED DEFAULT NULL COMMENT 'opus-019: foto eki (files.id)' AFTER `body`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 3) request_messages → files FK (yoksa ekle) ─
SET @hasfk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'request_messages' AND CONSTRAINT_NAME = 'fk_rm_file');
SET @sql := IF(@hasfk = 0,
  "ALTER TABLE `request_messages` ADD CONSTRAINT `fk_rm_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
