-- ═══════════════════════════════════════════════════════════════
-- migrate_017.sql — İş emri opus-017 (MySQL 8 / MariaDB, canlı uysa_v2)
-- Müşteri AY-BAZLI fiyat (reaktif): customer_price tablosu + SEED.
--
-- Ekler:
--   1) customer_price(customer_id, ay, unit_price, maliyet_birim, tasima_sabit_gider),
--      UNIQUE(customer_id, ay). priceFor(cid,ay) = o ay > carry-forward > current default.
--   2) SEED: her müşteri×ay (production'ı olan) için MEVCUT snapshot fiyatını yazar
--      (o ay production.unit_price_snap'ın BASKIN değeri = en çok kişiyi kapsayan snap).
--      Böylece hiçbir rakam DEĞİŞMEZ (June=235 korunur); sonra Ömer o ayı düzeltir.
--
-- İDEMPOTENT: CREATE TABLE IF NOT EXISTS + SEED'de NOT EXISTS (var olan ay/müşteri atlanır).
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_017.sql
--   veya docker: docker exec -i <mysql> mysql -u <user> -p<pass> uysa_v2 < sql/migrate_017.sql
-- ═══════════════════════════════════════════════════════════════

-- ── 1) customer_price tablosu ───────────────────────────────────
CREATE TABLE IF NOT EXISTS `customer_price` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`        INT UNSIGNED NOT NULL,
  `ay`                 CHAR(7)      NOT NULL COMMENT 'YYYY-MM',
  `unit_price`         DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'üretim: kişi başı; taşıma: satış birim',
  `maliyet_birim`      DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'taşıma: alış birim (üretimde 0)',
  `tasima_sabit_gider` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'taşıma: aylık sabit gider (üretimde 0)',
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customer_price` (`customer_id`,`ay`),
  KEY `idx_cp_cust_ay` (`customer_id`,`ay`),
  CONSTRAINT `fk_cp_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2) SEED: mevcut production aylarından fiyat doldur (idempotent, rakam DEĞİŞMEZ) ─
-- unit_price = o müşteri×ay production.unit_price_snap'ın BASKIN değeri (en çok kişiyi kapsayan;
--   eşitlikte en yüksek snap). maliyet_birim/tasima_sabit_gider = customers current (aya özel geçmiş yok).
INSERT INTO `customer_price` (`customer_id`, `ay`, `unit_price`, `maliyet_birim`, `tasima_sabit_gider`)
SELECT g.customer_id, g.ay,
  (SELECT p2.unit_price_snap FROM production p2
     WHERE p2.customer_id = g.customer_id AND SUBSTR(p2.prod_date,1,7) = g.ay
     GROUP BY p2.unit_price_snap
     ORDER BY SUM(p2.persons) DESC, p2.unit_price_snap DESC LIMIT 1) AS unit_price,
  COALESCE(c.maliyet_birim, 0), COALESCE(c.tasima_sabit_gider, 0)
FROM (SELECT DISTINCT customer_id, SUBSTR(prod_date,1,7) AS ay FROM production) g
JOIN customers c ON c.id = g.customer_id
WHERE NOT EXISTS (
  SELECT 1 FROM customer_price cp WHERE cp.customer_id = g.customer_id AND cp.ay = g.ay
);
