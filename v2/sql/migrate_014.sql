-- ═══════════════════════════════════════════════════════════════
-- migrate_014.sql — İş emri opus-014 (MySQL 8 / MariaDB, canlı uysa_v2)
-- Personel: gerçek işveren maliyeti (brüt + SGK + kıdem tahakkuk + diğer),
-- müşteriye dağıtım ataması, ayar (mevzuat KV) tablosu.
--
-- Ekler:
--   1) ayar (anahtar-değer): SGK/kıdem/diğer oranları — default mevzuat, Ömer düzenler.
--   2) personel.ise_giris DATE NULL (kıdem başlangıcı) + personel.diger_maliyet DECIMAL NULL.
--   3) personel_musteri (personel → müşteri(ler)/genel dağıtım ataması).
--
-- İDEMPOTENT: tekrar çalıştırılabilir (CREATE IF NOT EXISTS + INSERT IGNORE +
--   information_schema "yoksa ekle" PREPARE/EXECUTE deseni).
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_014.sql
--   veya docker: docker exec -i <mysql> mysql -u <user> -p<pass> uysa_v2 < sql/migrate_014.sql
-- ═══════════════════════════════════════════════════════════════

-- ── 1) ayar (KV) tablosu + mevzuat seed (default, değiştirilebilir) ─
CREATE TABLE IF NOT EXISTS `ayar` (
  `anahtar` VARCHAR(60)  NOT NULL,
  `deger`   VARCHAR(100) NOT NULL,
  PRIMARY KEY (`anahtar`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `ayar` (`anahtar`, `deger`) VALUES
  ('sgk_isveren_orani', '0.225'),
  ('kidem_tavan', '64948.77'),
  ('kidem_aylik_bolen', '12'),
  ('diger_maliyet_oran', '0'),
  ('sgk_tesvik_orani', '0.175');

-- ── 2a) personel.ise_giris DATE NULL (kıdem başlangıcı) ─
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personel' AND COLUMN_NAME = 'ise_giris');
SET @sql := IF(@has = 0,
  "ALTER TABLE `personel` ADD COLUMN `ise_giris` DATE DEFAULT NULL COMMENT 'kıdem başlangıcı (opus-014)' AFTER `aylik_ucret`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 2b) personel.diger_maliyet DECIMAL(12,2) NULL (override tutar; NULL=ayar oranından) ─
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personel' AND COLUMN_NAME = 'diger_maliyet');
SET @sql := IF(@has = 0,
  "ALTER TABLE `personel` ADD COLUMN `diger_maliyet` DECIMAL(12,2) DEFAULT NULL COMMENT 'override tutar; NULL=ayar diger_maliyet_oran (opus-014)' AFTER `ise_giris`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 3) personel_musteri (dağıtım ataması) ─
CREATE TABLE IF NOT EXISTS `personel_musteri` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `personel_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED          DEFAULT NULL,
  `genel`       TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pm` (`personel_id`,`customer_id`),
  KEY `idx_pm_personel` (`personel_id`),
  CONSTRAINT `fk_pm_personel` FOREIGN KEY (`personel_id`) REFERENCES `personel` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pm_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
