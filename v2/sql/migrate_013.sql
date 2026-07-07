-- ═══════════════════════════════════════════════════════════════
-- migrate_013.sql — İş emri opus-013 (MySQL 8 / MariaDB, canlı uysa_v2)
-- Taşıma modeli 3. revizyon: 4-alan kart + adet(production)×(satış−alış)−sabit.
--
-- customers'a 3 YENİ ALAN ekler (taşıma kartı standing değerleri):
--   maliyet_birim       → taşıma alış birim fiyatı (unit_price = satış)
--   tasima_sabit_gider  → aylık sabit gider (opsiyonel)
--   tasima_not          → not (opsiyonel)
-- adet KARTTA YOK: adet = o müşterinin o ay production.persons TOPLAMI (Bugün sayımı).
-- tasima_aylik tablosu ARTIK KULLANILMIYOR (opus-011 modeli terk) — tablo DÜŞÜRÜLMEZ, atıl kalır.
--
-- İDEMPOTENT: tekrar çalıştırılabilir. MySQL 8'de "ADD COLUMN IF NOT EXISTS" yok →
-- information_schema kontrolü + PREPARE/EXECUTE ile "yoksa ekle" deseni.
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_013.sql
--   veya docker: docker exec -i <mysql> mysql -u <user> -p<pass> uysa_v2 < sql/migrate_013.sql
-- ═══════════════════════════════════════════════════════════════

-- ── 1) customers.maliyet_birim DECIMAL(12,2) DEFAULT 0 (taşıma alış birim fiyatı) ─
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'maliyet_birim');
SET @sql := IF(@has = 0,
  "ALTER TABLE `customers` ADD COLUMN `maliyet_birim` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'taşıma: alış birim fiyatı TL (opus-013)' AFTER `contract_note`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 2) customers.tasima_sabit_gider DECIMAL(12,2) DEFAULT 0 (aylık sabit gider, opsiyonel) ─
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'tasima_sabit_gider');
SET @sql := IF(@has = 0,
  "ALTER TABLE `customers` ADD COLUMN `tasima_sabit_gider` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'taşıma: aylık sabit gider TL (opsiyonel)' AFTER `maliyet_birim`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 3) customers.tasima_not VARCHAR(500) NULL (not, opsiyonel) ─
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'tasima_not');
SET @sql := IF(@has = 0,
  "ALTER TABLE `customers` ADD COLUMN `tasima_not` VARCHAR(500) DEFAULT NULL COMMENT 'taşıma: not (opsiyonel)' AFTER `tasima_sabit_gider`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- tasima_aylik tablosu DÜŞÜRÜLMEZ (atıl bırakılır). Kod ondan okumaz/yazmaz.
