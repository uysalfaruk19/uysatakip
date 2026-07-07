-- kokpit-ios: kalici giris ("beni hatirla") tokenlari — musteri + personel
CREATE TABLE IF NOT EXISTS `remember_tokens` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`           VARCHAR(16)  NOT NULL COMMENT 'customer|admin',
  `user_id`        INT UNSIGNED NOT NULL COMMENT 'customer_users.id veya users.id',
  `selector`       CHAR(24)     NOT NULL,
  `validator_hash` CHAR(64)     NOT NULL,
  `expires_at`     DATETIME     NOT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rt_selector` (`selector`),
  KEY `idx_rt_kind_user` (`kind`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
