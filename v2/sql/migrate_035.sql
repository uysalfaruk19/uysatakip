-- fable-024: "Fatura Kes" — kesilen irsaliyelerden Paraşüt satış faturası + e-Fatura (Ömer, 2026-07-21).
--
-- Kapsam: (1) irsaliyeli 6 müşteri → dönem irsaliye toplamından haftalık fatura (tevkifat: PENDORYA).
--         (2) aylık müşteriler (irsaliye_aktif=0) → aylık üretim toplamı × birim; CANTAŞ 3'e bölünür.
-- ŞALTER: env PARASUT_FATURA_AKTIF (bu migration ŞALTERİ AÇMAZ — kod tanımsız=kapalı sayar).
-- Idempotent: tekrar çalıştırılabilir.

-- ── 1) customers: tevkifat + vade + fatura mail + aylık bölüşüm ──
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'tevkifat_kodu');
SET @s := IF(@c = 0,
  "ALTER TABLE `customers`
     ADD COLUMN `tevkifat_kodu` VARCHAR(10) NULL COMMENT 'KDV tevkifat kodu (604); boş=yok (fable-024)',
     ADD COLUMN `tevkifat_oran` DECIMAL(5,2) NULL COMMENT 'tevkifat oranı (KDV %si) (fable-024)',
     ADD COLUMN `fatura_vade_gun` INT NOT NULL DEFAULT 1 COMMENT 'fatura vadesi = issue + N gün (fable-024)',
     ADD COLUMN `fatura_mail` VARCHAR(255) NULL COMMENT 'fatura mail paylaşım adresleri (fable-024)',
     ADD COLUMN `fatura_bolusum` TEXT NULL COMMENT 'aylık bölüşüm config JSON [{key,ad}] (fable-024)'",
  "SELECT 'customers fatura kolonları zaten var'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 2) parasut_irsaliye_log: faturalanma işareti ──
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parasut_irsaliye_log' AND COLUMN_NAME = 'fatura_log_id');
SET @s := IF(@c = 0,
  "ALTER TABLE `parasut_irsaliye_log`
     ADD COLUMN `fatura_log_id` INT UNSIGNED NULL COMMENT 'faturalanınca parasut_fatura_log.id (fable-024)',
     ADD KEY `idx_irsaliye_fatura` (`fatura_log_id`)",
  "SELECT 'fatura_log_id zaten var'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 3) parasut_fatura_log tablosu (UNIQUE YOK: ay içinde çok fatura olabilir; kayıt SİLİNMEZ) ──
CREATE TABLE IF NOT EXISTS `parasut_fatura_log` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`        INT UNSIGNED NOT NULL,
  `donem_bas`          DATE         NOT NULL,
  `donem_son`          DATE         NOT NULL,
  `tip`                VARCHAR(16)  NOT NULL DEFAULT 'irsaliye' COMMENT 'irsaliye|aylik',
  `parasut_contact_id` VARCHAR(40)           DEFAULT NULL,
  `parasut_fatura_id`  VARCHAR(40)           DEFAULT NULL,
  `fatura_no`          VARCHAR(64)           DEFAULT NULL,
  `alt_ad`             VARCHAR(120)          DEFAULT NULL,
  `kalemler`           TEXT                  DEFAULT NULL,
  `toplam_kisi`        INT          NOT NULL DEFAULT 0,
  `toplam_tutar`       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `durum`              VARCHAR(16)  NOT NULL DEFAULT 'hata' COMMENT 'kesildi|hata|bilinmiyor|iptal',
  `resmilestirme`      VARCHAR(16)  NOT NULL DEFAULT 'yok' COMMENT 'gonderildi|hata|yok',
  `mail`               VARCHAR(16)  NOT NULL DEFAULT 'yok' COMMENT 'gonderildi|hata|yok',
  `hata_mesaj`         VARCHAR(500)          DEFAULT NULL,
  `entered_by`         VARCHAR(64)  NOT NULL DEFAULT '',
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fatura_cust` (`customer_id`, `donem_son`),
  CONSTRAINT `fk_fatura_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4) ayar: aylık bölüşüm contact id'leri + fatura↔irsaliye bağı şalteri ──
INSERT IGNORE INTO `ayar` (`anahtar`, `deger`) VALUES
  ('fatura_cantas_icdis', '1062205016'),
  ('fatura_cantas_bakir', '1062204894'),
  ('fatura_cantas_hc',    '1062205054'),
  ('fatura_irsaliye_bagla', '1');

-- ── 5) veri: PENDORYA tevkifatlı (604 / %50) + vade 7 (canlı TSKB faturasından) ──
UPDATE `customers` SET `tevkifat_kodu` = '604', `tevkifat_oran` = 50.00, `fatura_vade_gun` = 7
  WHERE `name` = 'PENDORYA' AND (`tevkifat_kodu` IS NULL OR `tevkifat_kodu` = '');

-- ── 6) veri: CANTAŞ aylık 3'lü bölüşüm (İç-Dış / HC / Bakır; contact id'leri ayar'dan) ──
UPDATE `customers`
   SET `fatura_bolusum` = '[{"key":"fatura_cantas_icdis","ad":"CANTAŞ İç-Dış"},{"key":"fatura_cantas_hc","ad":"CANTAŞ HC Isıtma"},{"key":"fatura_cantas_bakir","ad":"CANTAŞ Bakır"}]'
 WHERE `name` = 'CANTAŞ' AND (`fatura_bolusum` IS NULL OR `fatura_bolusum` = '');

-- fatura_mail HEPSİNDE BOŞ başlar (Ömer doldurur). CANTAŞ/Marmara aylık → irsaliye_aktif=0 olmalı:
UPDATE `customers` SET `irsaliye_aktif` = 0 WHERE `name` IN ('CANTAŞ', 'Marmara Teknik');

-- Doğrulama:
--   SELECT name, tevkifat_kodu, tevkifat_oran, fatura_vade_gun, irsaliye_aktif, fatura_bolusum
--   FROM customers WHERE name IN ('PENDORYA','CANTAŞ','Marmara Teknik');
