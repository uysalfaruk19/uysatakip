-- opus-020b: Personel ay bazli maas calisma gunu / odendi takibi
CREATE TABLE IF NOT EXISTS `personel_maas_ay` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `personel_id`    INT UNSIGNED NOT NULL,
  `ay`             CHAR(7)      NOT NULL COMMENT 'YYYY-MM',
  `calisma_gunu`   DECIMAL(5,2) NOT NULL DEFAULT 30.00,
  `maas_odendi`    TINYINT(1)   NOT NULL DEFAULT 0,
  `odeme_tarihi`   DATE                  DEFAULT NULL,
  `gider_id`       INT UNSIGNED          DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_personel_maas_ay` (`personel_id`,`ay`),
  KEY `idx_pma_ay` (`ay`),
  KEY `idx_pma_gider` (`gider_id`),
  CONSTRAINT `fk_pma_personel` FOREIGN KEY (`personel_id`) REFERENCES `personel` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pma_gider` FOREIGN KEY (`gider_id`) REFERENCES `personel_gider` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
