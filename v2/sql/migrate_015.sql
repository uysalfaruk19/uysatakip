-- ═══════════════════════════════════════════════════════════════
-- migrate_015.sql — İş emri opus-015 (MySQL 8 / MariaDB, canlı uysa_v2)
-- Karlılık: basit gider + ciro-oranlı dağıtım.
--
-- Ekler:
--   1) transactions.alloc_type VARCHAR(10) NOT NULL DEFAULT 'genel'
--      ('genel'=tüm müşterilere ciro oranlı; 'musteri'=transaction_customer hedefleri).
--   2) transaction_customer (gider → hedef müşteri(ler) link tablosu).
--
-- İDEMPOTENT: tekrar çalıştırılabilir (information_schema "yoksa ekle" PREPARE/EXECUTE +
--   CREATE TABLE IF NOT EXISTS).
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_015.sql
--   veya docker: docker exec -i <mysql> mysql -u <user> -p<pass> uysa_v2 < sql/migrate_015.sql
-- ═══════════════════════════════════════════════════════════════

-- ── 1) transactions.alloc_type VARCHAR(10) NOT NULL DEFAULT 'genel' ─
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'alloc_type');
SET @sql := IF(@has = 0,
  "ALTER TABLE `transactions` ADD COLUMN `alloc_type` VARCHAR(10) NOT NULL DEFAULT 'genel' COMMENT 'gider dağıtım hedefi: genel|musteri (opus-015)' AFTER `source`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 2) transaction_customer (gider → hedef müşteri link tablosu) ─
CREATE TABLE IF NOT EXISTS `transaction_customer` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id` INT UNSIGNED NOT NULL,
  `customer_id`    INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tc` (`transaction_id`,`customer_id`),
  KEY `idx_tc_tx` (`transaction_id`),
  CONSTRAINT `fk_tc_tx`       FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tc_customer` FOREIGN KEY (`customer_id`)    REFERENCES `customers` (`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
