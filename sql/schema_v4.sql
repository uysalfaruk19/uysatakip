-- ═══════════════════════════════════════════════════════════════
-- UYSA ERP — Enhanced Schema v4.0
-- MySQL 8.0 + PostgreSQL 15 compatible (separate files)
-- ─────────────────────────────────────────────────────────────
-- YENİLİKLER v4.0:
--   + uysa_rate_limits  — Rate limiting (sliding window)
--   + uysa_rate_locks   — IP/user kilitleme
--   + uysa_api_keys     — API key yönetimi
--   + uysa_sessions     — JWT session izleme
--   ~ uysa_users        — Güçlü şifre politikası
--   ~ uysa_audit        — Genişletildi (ip_addr, user_agent)
-- ═══════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS `uysa_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `uysa_db`;

-- ── Ana Depolama ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `uysa_storage` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `store_key`   VARCHAR(255)     NOT NULL,
  `store_value` MEDIUMTEXT       NOT NULL,
  `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_store_key` (`store_key`),
  KEY `idx_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='localStorage ↔ MySQL sync';

-- ── Yedekler ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `uysa_backups` (
  `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `backup_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `key_count`   INT UNSIGNED     NOT NULL DEFAULT 0,
  `size_bytes`  INT UNSIGNED     NOT NULL DEFAULT 0,
  `trigger_by`  VARCHAR(100)     NOT NULL DEFAULT 'auto',
  `snapshot`    LONGTEXT         NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_backup_at` (`backup_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Kullanıcılar ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `uysa_users` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `username`         VARCHAR(50)   NOT NULL,
  `password`         VARCHAR(255)  NOT NULL       COMMENT 'bcrypt hash (cost=12)',
  `role`             ENUM('superadmin','editor','user','viewer')
                                   NOT NULL DEFAULT 'user',
  `display_name`     VARCHAR(100)           DEFAULT NULL,
  `email`            VARCHAR(255)           DEFAULT NULL,
  `last_login`       DATETIME               DEFAULT NULL,
  `failed_attempts`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`     DATETIME               DEFAULT NULL,
  `is_active`        TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_email`    (`email`),
  KEY `idx_role`           (`role`),
  KEY `idx_active`         (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Dosyalar ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `uysa_files` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename`    VARCHAR(255)    NOT NULL COMMENT 'Güvenli dosya adı (timestamp_hex.ext)',
  `original`    VARCHAR(255)    NOT NULL COMMENT 'Orijinal dosya adı',
  `mime`        VARCHAR(100)    NOT NULL,
  `size_bytes`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `uploaded_by` VARCHAR(100)             DEFAULT NULL,
  `category`    VARCHAR(100)             DEFAULT NULL,
  `date`        DATE                     DEFAULT NULL,
  `deleted_at`  DATETIME                 DEFAULT NULL COMMENT 'Soft delete',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category`),
  KEY `idx_deleted`  (`deleted_at`),
  KEY `idx_uploader` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Gelişmiş Audit Log ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `uysa_audit` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action`      VARCHAR(100)    NOT NULL,
  `actor`       VARCHAR(100)             DEFAULT NULL,
  `target_key`  VARCHAR(255)             DEFAULT NULL,
  `detail`      TEXT                     DEFAULT NULL,
  `ip_addr`     VARCHAR(45)     NOT NULL DEFAULT '',
  `user_agent`  VARCHAR(500)             DEFAULT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_action`  (`action`),
  KEY `idx_actor`   (`actor`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Legacy Loglar ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `uysa_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action`     VARCHAR(50)     NOT NULL,
  `store_key`  VARCHAR(255)    NOT NULL DEFAULT '',
  `ip_addr`    VARCHAR(45)     NOT NULL DEFAULT '',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_action`  (`action`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Rate Limiting ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `uysa_rate_limits` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`          VARCHAR(255)    NOT NULL COMMENT 'ip:x.x.x.x veya login:md5',
  `attempted_at` INT UNSIGNED    NOT NULL COMMENT 'Unix timestamp',
  PRIMARY KEY (`id`),
  KEY `idx_key_time` (`key`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Sliding window rate limit kayıtları';

CREATE TABLE IF NOT EXISTS `uysa_rate_locks` (
  `key`          VARCHAR(255) NOT NULL,
  `locked_until` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Kilitli IP/kullanıcı kayıtları';

-- ── API Keys ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `uysa_api_keys` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key_hash`     VARCHAR(64)  NOT NULL COMMENT 'SHA3-256 of raw key',
  `key_prefix`   VARCHAR(20)  NOT NULL COMMENT 'İlk 12 karakter + …',
  `name`         VARCHAR(100) NOT NULL DEFAULT 'API Key',
  `owner`        VARCHAR(100) NOT NULL DEFAULT 'system',
  `role`         VARCHAR(50)  NOT NULL DEFAULT 'viewer',
  `scopes`       JSON         NOT NULL DEFAULT ('["read"]'),
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `uses_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `last_used_at` DATETIME              DEFAULT NULL,
  `expires_at`   DATETIME              DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key_hash`       (`key_hash`),
  KEY `idx_owner_active` (`owner`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── JWT Session Tracking ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `uysa_sessions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `jti`        VARCHAR(64)     NOT NULL COMMENT 'JWT ID (jti claim)',
  `username`   VARCHAR(50)     NOT NULL,
  `ip_addr`    VARCHAR(45)     NOT NULL DEFAULT '',
  `user_agent` VARCHAR(500)             DEFAULT NULL,
  `issued_at`  DATETIME        NOT NULL,
  `expires_at` DATETIME        NOT NULL,
  `revoked`    TINYINT(1)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_jti`      (`jti`),
  KEY `idx_username`       (`username`),
  KEY `idx_expires_revoked`(`expires_at`, `revoked`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='JWT token takibi — token iptali için';

-- ── Varsayılan Superadmin ─────────────────────────────────────
-- Şifre: şifreni .env'de API_TOKEN ile ayarla, bu hash örnek.
-- İlk kurulumda manuel değiştir.
INSERT IGNORE INTO `uysa_users` (username, password, role, display_name)
VALUES (
  'admin',
  '$2y$12$placeholder.change.this.hash.immediately.admin.setup.xx',
  'superadmin',
  'Sistem Yöneticisi'
);

-- ── Views ────────────────────────────────────────────────────
CREATE OR REPLACE VIEW `v_recent_audit` AS
  SELECT a.id, a.action, a.actor, a.target_key, a.ip_addr, a.created_at
  FROM uysa_audit a
  ORDER BY a.created_at DESC
  LIMIT 1000;

CREATE OR REPLACE VIEW `v_active_api_keys` AS
  SELECT id, key_prefix, name, owner, role, scopes, uses_count, last_used_at, expires_at, created_at
  FROM uysa_api_keys
  WHERE is_active = 1
    AND (expires_at IS NULL OR expires_at > NOW());

-- ── Temizleme Stored Procedure ───────────────────────────────
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS `sp_cleanup` ()
BEGIN
  -- Eski rate limit kayıtlarını sil (1 saattan eski)
  DELETE FROM uysa_rate_limits WHERE attempted_at < UNIX_TIMESTAMP(NOW() - INTERVAL 1 HOUR);
  -- Süresi geçmiş kilitleri sil
  DELETE FROM uysa_rate_locks WHERE locked_until < UNIX_TIMESTAMP(NOW());
  -- Süresi geçmiş JWT session'ları temizle
  DELETE FROM uysa_sessions WHERE expires_at < NOW() - INTERVAL 7 DAY;
  -- 1000'den fazla audit log tutma
  DELETE FROM uysa_audit WHERE id NOT IN (
    SELECT id FROM (SELECT id FROM uysa_audit ORDER BY created_at DESC LIMIT 10000) t
  );
END //
DELIMITER ;

-- ── Event: Günlük Temizlik ────────────────────────────────────
-- Railway/production ortamında aktif etmek için:
-- SET GLOBAL event_scheduler = ON;
CREATE EVENT IF NOT EXISTS `ev_daily_cleanup`
  ON SCHEDULE EVERY 1 DAY
  STARTS CURRENT_TIMESTAMP
  DO CALL sp_cleanup();
