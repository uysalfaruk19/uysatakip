-- fable-003: demo (cateringkolay) esinli operasyon modülleri.
-- 1) Stok girişine SKT + tedarikçi (SKT riski raporu + "son tedarikçi/son alış" için)
ALTER TABLE `stock_moves` ADD COLUMN `skt` DATE DEFAULT NULL AFTER `unit`;
ALTER TABLE `stock_moves` ADD COLUMN `supplier_id` INT UNSIGNED DEFAULT NULL AFTER `skt`;
ALTER TABLE `stock_moves` ADD CONSTRAINT `fk_sm_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL;

-- 2) HACCP günlük kontrol kaydı (sıcaklık / hijyen / şahit numune / mal kabul — tek tablo)
CREATE TABLE IF NOT EXISTS `haccp_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `log_date`   DATE         NOT NULL,
  `kind`       ENUM('sicaklik','hijyen','numune','malkabul') NOT NULL,
  `nokta`      VARCHAR(80)  NOT NULL,
  `deger`      VARCHAR(40)           DEFAULT NULL,
  `uygun`      TINYINT(1)            DEFAULT NULL,
  `note`       VARCHAR(300)          DEFAULT NULL,
  `imha_at`    DATETIME              DEFAULT NULL,
  `created_by` VARCHAR(40)           DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_haccp_date` (`log_date`),
  KEY `idx_haccp_kind` (`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Teklifler (müşteri adayı takibi)
CREATE TABLE IF NOT EXISTS `teklif` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `firma`       VARCHAR(120) NOT NULL,
  `kisi`        INT                   DEFAULT NULL,
  `birim_fiyat` DECIMAL(10,2)         DEFAULT NULL,
  `note`        VARCHAR(500)          DEFAULT NULL,
  `durum`       ENUM('taslak','gonderildi','kabul','red') NOT NULL DEFAULT 'taslak',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_teklif_durum` (`durum`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Teslimat/sevkiyat durumu (gün × müşteri; bekliyor→yolda→teslim)
CREATE TABLE IF NOT EXISTS `teslimat` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `teslim_date` DATE         NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `status`      ENUM('bekliyor','yolda','teslim') NOT NULL DEFAULT 'bekliyor',
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_teslimat` (`teslim_date`,`customer_id`),
  CONSTRAINT `fk_teslimat_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
