-- ═══════════════════════════════════════════════════════════════
-- migrate_023.sql — İş emri opus-021 (MySQL 8 / MariaDB, canlı uysa_v2)
-- Push otomasyonu: admin push kaydı + push_log (gönderim geçmişi) + sessiz saat ayarları.
--
-- Ekler:
--   1) push_tokens.user_id INT UNSIGNED NULL (admin users.id) + idx_push_user.
--   2) push_log tablosu: her gönderim (olay/manuel/hatırlatma) tek satır —
--      kind (menu|talep_cevap|talep_yeni|siparis|reminder|manuel), hedef (customer_id/user_id),
--      ref (mükerrer koruması, ör. 'menu:12'), title/body, sent/dead sayıları, suppressed (sessiz saat).
--   3) ayar seed: push_quiet_start=21:00, push_quiet_end=07:00 (Ömer düzenleyebilir).
--
-- İDEMPOTENT: kolon/index için information_schema kontrolü (PREPARE/EXECUTE "yoksa ekle"),
-- CREATE TABLE IF NOT EXISTS, INSERT IGNORE. Tekrar çalıştırmak güvenli.
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_023.sql
--   veya docker: docker exec -i <mysql> mysql -u <user> -p<pass> uysa_v2 < sql/migrate_023.sql
-- ═══════════════════════════════════════════════════════════════

-- ── 1) push_tokens.user_id (yoksa ekle) ─
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_tokens' AND COLUMN_NAME = 'user_id');
SET @sql := IF(@has = 0,
  "ALTER TABLE `push_tokens` ADD COLUMN `user_id` INT UNSIGNED DEFAULT NULL COMMENT 'admin users.id (opus-021)' AFTER `cuid`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @hasix := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_tokens' AND INDEX_NAME = 'idx_push_user');
SET @sql := IF(@hasix = 0,
  "ALTER TABLE `push_tokens` ADD KEY `idx_push_user` (`user_id`)",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 2) push_log (gönderim geçmişi + mükerrer/günde-1 koruması buradan sorgulanır) ─
CREATE TABLE IF NOT EXISTS `push_log` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`        VARCHAR(20)  NOT NULL COMMENT 'menu|talep_cevap|talep_yeni|siparis|reminder|manuel',
  `customer_id` INT UNSIGNED          DEFAULT NULL COMMENT 'hedef müşteri (toCustomer); toAll/toAdmins NULL',
  `user_id`     INT UNSIGNED          DEFAULT NULL COMMENT 'hedef admin (tek admin hedefli gönderim için)',
  `ref`         VARCHAR(64)           DEFAULT NULL COMMENT 'mükerrer anahtarı, ör. menu:12',
  `title`       VARCHAR(120) NOT NULL,
  `body`        VARCHAR(500) NOT NULL,
  `sent`        INT          NOT NULL DEFAULT 0 COMMENT 'başarılı cihaz sayısı',
  `dead`        INT          NOT NULL DEFAULT 0 COMMENT 'silinen ölü token sayısı',
  `suppressed`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'sessiz saatte bastırıldı',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pl_kind_cust` (`kind`, `customer_id`, `created_at`),
  KEY `idx_pl_ref` (`ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3) Sessiz saat ayarları (varsa dokunma — Ömer düzenlemiş olabilir) ─
INSERT IGNORE INTO `ayar` (`anahtar`, `deger`) VALUES
  ('push_quiet_start', '21:00'),
  ('push_quiet_end', '07:00');
