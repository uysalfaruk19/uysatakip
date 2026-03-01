-- ═══════════════════════════════════════════════════════════════
-- UYSA ERP — MySQL Schema v3.0
-- Railway / MySQL 8.0 compatible
-- ─────────────────────────────────────────────────────────────
-- YENİLİKLER v3.0:
--   + uysa_users   — Kullanıcı yönetimi (superadmin / editor / user / viewer)
--   + uysa_files   — Dosya yönetimi (soft-delete, audit)
--   + uysa_audit   — Gelişmiş audit log (kim neyi sildi/değiştirdi)
--   ~ uysa_storage — Değişmedi (v2.1 uyumlu)
--   ~ uysa_backups — Değişmedi (v2.1 uyumlu)
--   ~ uysa_logs    — Değişmedi (v2.1 legacy uyumlu)
-- ═══════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS `uysa_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `uysa_db`;

-- ── Ana Depolama (localStorage ↔ MySQL sync) ──────────────────
CREATE TABLE IF NOT EXISTS `uysa_storage` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `store_key`   VARCHAR(255)     NOT NULL,
  `store_value` MEDIUMTEXT       NOT NULL,
  `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_store_key` (`store_key`),
  KEY `idx_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Yedekler ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `uysa_backups` (
  `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `backup_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `key_count`   INT UNSIGNED     NOT NULL DEFAULT 0,
  `size_bytes`  INT UNSIGNED     NOT NULL DEFAULT 0,
  `trigger_by`  VARCHAR(50)      NOT NULL DEFAULT 'auto',
  `snapshot`    LONGTEXT         NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_backup_at` (`backup_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Legacy İşlem Logları ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `uysa_logs` (
  `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `action`     VARCHAR(50)      NOT NULL,
  `store_key`  VARCHAR(255)     NOT NULL DEFAULT '',
  `ip_addr`    VARCHAR(45)      NOT NULL DEFAULT '',
  `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_action`     (`action`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Gelişmiş Audit Log ────────────────────────────────────────
-- Kim neyi ne zaman yaptı? Silme, ekleme, giriş, dosya işlemleri
CREATE TABLE IF NOT EXISTS `uysa_audit` (
  `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `action`     VARCHAR(100)     NOT NULL COMMENT 'delete_key, login, file_upload, user_add vb.',
  `module`     VARCHAR(50)      NOT NULL DEFAULT '' COMMENT 'STORAGE, FILE, AUTH, USER_MGMT, FINANS vb.',
  `detail`     TEXT             COMMENT 'İşlem detayı, silinene nesnenin adı/değeri',
  `username`   VARCHAR(100)     NOT NULL DEFAULT '',
  `ip_addr`    VARCHAR(45)      NOT NULL DEFAULT '',
  `user_agent` VARCHAR(500)     NOT NULL DEFAULT '',
  `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_action`     (`action`),
  KEY `idx_username`   (`username`),
  KEY `idx_module`     (`module`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Kullanıcılar ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `uysa_users` (
  `id`            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(100)   NOT NULL,
  `password_hash` VARCHAR(500)   NOT NULL  COMMENT 'bcrypt hash',
  `phone`         VARCHAR(20)    NOT NULL DEFAULT '',
  `display_name`  VARCHAR(100)   NOT NULL DEFAULT '',
  `role`          ENUM('superadmin','editor','user','viewer') NOT NULL DEFAULT 'user',
  `permissions`   JSON           COMMENT '["all"] veya ["read","write"] gibi',
  `active`        TINYINT(1)     NOT NULL DEFAULT 1,
  `last_login`    DATETIME,
  `created_by`    VARCHAR(100)   NOT NULL DEFAULT 'system',
  `created_at`    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Dosyalar ──────────────────────────────────────────────────
-- Tüm yüklenen dosyalar; soft-delete ile silme izlenir
CREATE TABLE IF NOT EXISTS `uysa_files` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_name`     VARCHAR(255)    NOT NULL  COMMENT 'Sunucudaki güvenli ad',
  `original_name` VARCHAR(255)    NOT NULL  COMMENT 'Kullanıcının orijinal dosya adı',
  `category`      VARCHAR(100)    NOT NULL DEFAULT 'diger'
                  COMMENT 'teklif, sozlesme, fatura, irsaliye, personel, sirket, musteri, diger',
  `notes`         TEXT            COMMENT 'Kullanıcı notu',
  `mime_type`     VARCHAR(100)    NOT NULL DEFAULT '',
  `file_size`     INT UNSIGNED    NOT NULL DEFAULT 0,
  `file_path`     VARCHAR(500)    NOT NULL  COMMENT 'Sunucu mutlak yolu',
  `uploaded_by`   VARCHAR(100)    NOT NULL DEFAULT '',
  `doc_date`      DATE            COMMENT 'Dokümanın tarihi (fatura tarihi vb.)',
  `deleted_at`    DATETIME        COMMENT 'Soft delete: ne zaman silindi',
  `deleted_by`    VARCHAR(100)    NOT NULL DEFAULT '' COMMENT 'Silen kullanıcı adı',
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category`    (`category`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  KEY `idx_created_at`  (`created_at`),
  KEY `idx_deleted_at`  (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════
-- VARSAYILAN KULLANICILAR
-- ════════════════════════════════════════════════════════════

-- NOT: Şifreler PHP'de bcrypt ile hashlenir.
-- Bu INSERT'ler ilk kurulumda çalışmaz (hash dinamik üretilir).
-- PHP API'si ilk istek sırasında bu kullanıcıları otomatik oluşturur.

-- Örnek: Manuel oluşturma (PHP bcrypt hash ile):
-- INSERT INTO uysa_users (username, password_hash, phone, display_name, role, permissions, created_by)
-- VALUES
--   ('OFU',  '<bcrypt_hash_of_05321608119>', '05321608119', 'OFU',  'superadmin', '["all"]',            'system'),
--   ('Azim', '<bcrypt_hash_of_Azim2024!>',   '',            'Azim', 'user',       '["read","write"]',   'OFU');

-- ════════════════════════════════════════════════════════════
-- YARDIMCI VIEW'LAR
-- ════════════════════════════════════════════════════════════

-- Silinen dosyalar raporu
CREATE OR REPLACE VIEW `v_deleted_files` AS
  SELECT
    f.id, f.original_name, f.category, f.file_size,
    f.uploaded_by, f.created_at AS upload_date,
    f.deleted_at, f.deleted_by
  FROM uysa_files f
  WHERE f.deleted_at IS NOT NULL
  ORDER BY f.deleted_at DESC;

-- Audit log özet (son 30 günlük)
CREATE OR REPLACE VIEW `v_audit_recent` AS
  SELECT
    a.id, a.created_at, a.username, a.action, a.module, a.detail, a.ip_addr
  FROM uysa_audit a
  WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
  ORDER BY a.created_at DESC;

-- Kullanıcı aktivite özeti
CREATE OR REPLACE VIEW `v_user_activity` AS
  SELECT
    u.username, u.display_name, u.role, u.last_login,
    COUNT(a.id) AS total_actions,
    SUM(CASE WHEN a.action LIKE '%delete%' THEN 1 ELSE 0 END) AS delete_count
  FROM uysa_users u
  LEFT JOIN uysa_audit a ON a.username = u.username
  GROUP BY u.id, u.username, u.display_name, u.role, u.last_login;
