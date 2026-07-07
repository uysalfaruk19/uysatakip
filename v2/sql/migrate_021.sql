-- kokpit-ios: push bildirim cihaz token kaydi (musteri app; ilerde admin de eklenebilir)
CREATE TABLE IF NOT EXISTS `push_tokens` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform`     VARCHAR(16)  NOT NULL DEFAULT 'ios',
  `token`        VARCHAR(255) NOT NULL,
  `customer_id`  INT UNSIGNED          DEFAULT NULL,
  `cuid`         INT UNSIGNED          DEFAULT NULL COMMENT 'customer_users.id',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_push_token` (`token`),
  KEY `idx_push_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
