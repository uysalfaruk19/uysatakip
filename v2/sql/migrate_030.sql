-- ═══════════════════════════════════════════════════════════════
-- migrate_030.sql — İş emri fable-018 (MySQL 8 / MariaDB, canlı uysa_v2)
-- Kokpit /m Akış feed'i: müşteri olay akışı (push'un kalıcı karşılığı) + okundu kesimi.
--
-- Ekler:
--   1) customer_events tablosu: her müşteri-yüzlü olay tek satır —
--      type (menu_yayin|talep_cevap|siparis_durum|malzeme_durum), title/body, url (app-içi hedef).
--      Push atılamasa bile (sessiz saat/token yok) olay buraya yazılır; badge tek gerçek kaynak.
--   2) customer_users.feed_seen_at DATETIME NULL: Akış ekranı okundu kesimi (rozet bundan sayar).
--
-- İDEMPOTENT: CREATE TABLE IF NOT EXISTS; kolon için information_schema kontrolü (PREPARE "yoksa ekle").
-- Tekrar çalıştırmak güvenli.
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_030.sql
--   veya docker: docker exec -i <mysql> mysql -u <user> -p<pass> uysa_v2 < sql/migrate_030.sql
-- ═══════════════════════════════════════════════════════════════

-- ── 1) customer_events (müşteri olay akışı) ─
CREATE TABLE IF NOT EXISTS `customer_events` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `type`        VARCHAR(32)  NOT NULL COMMENT 'menu_yayin|talep_cevap|siparis_durum|malzeme_durum',
  `title`       VARCHAR(200) NOT NULL,
  `body`        VARCHAR(300)          DEFAULT NULL,
  `url`         VARCHAR(200) NOT NULL DEFAULT '' COMMENT 'app-içi hedef, /m/... göreli',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ce_customer` (`customer_id`, `created_at`),
  CONSTRAINT `fk_ce_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2) customer_users.feed_seen_at (yoksa ekle) ─
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_users' AND COLUMN_NAME = 'feed_seen_at');
SET @sql := IF(@has = 0,
  "ALTER TABLE `customer_users` ADD COLUMN `feed_seen_at` DATETIME DEFAULT NULL COMMENT 'Akış okundu kesimi (fable-018)' AFTER `last_login`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
