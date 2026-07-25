-- ═══════════════════════════════════════════════════════════════
-- migrate_042.sql — İş emri fable-040 (MySQL 8 / MariaDB, canlı uysa_v2)
-- Müşteriye özel FATURA KİŞİ KURALI: ÜRETİM ≠ FATURA (CANTAŞ 50 üretim / 70 fatura).
--
-- production.persons = GERÇEK üretim (maliyet / kişi başı / gıda maliyeti bundan).
-- FATURA KİŞİSİ (customers.fatura_kisi_haftaici) VARSA hafta içi (Pzt–Cum) günlerde CİRO
--   bu sayıdan (production.amount fatura kişisi × fiyat yazılır) → SUM(amount) okuyan HER yer
--   (günlük ciro, customerCiroMap, rapor, kar-analizi gelir, aylık fatura) otomatik doğru.
-- NULL = kural yok (ciro = üretim). Cumartesi/pazar kural UYGULANMAZ (gerçek sayı).
--
-- İDEMPOTENT: kolon için information_schema kontrolü (tekrar çalıştırmak güvenli).
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_042.sql
--
-- 🔒 NOT: Bu migrasyon yalnız ŞEMA ekler. CANTAŞ'ın kural değeri + Temmuz geçmiş düzeltmesi
--    AYRI dosyalarda (aşağıdaki 'canlı seed' + sql/fix_cantas_temmuz_070.sql) — Fable uygular.
-- ═══════════════════════════════════════════════════════════════

-- ── customers.fatura_kisi_haftaici (yoksa ekle) ─
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'fatura_kisi_haftaici');
SET @sql := IF(@has = 0,
  "ALTER TABLE `customers` ADD COLUMN `fatura_kisi_haftaici` INT UNSIGNED DEFAULT NULL COMMENT 'fable-040: hafta içi SABİT fatura kişisi (üretim ≠ fatura; NULL=kural yok). CANTAŞ 50/70; cmt-paz uygulanmaz' AFTER `fatura_bolusum`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── Canlı seed: CANTAŞ hafta içi fatura kişisi = 70 (Ömer onaylı) ─
-- Ada göre TAM eşleşme (yanlış müşteriye kural yazmamak için). Kural yalnız ciroyu etkiler;
-- persons (gerçek üretim) DEĞİŞMEZ. Geçmiş Temmuz amount düzeltmesi ayrı: fix_cantas_temmuz_070.sql
UPDATE `customers` SET `fatura_kisi_haftaici` = 70 WHERE `name` = 'CANTAŞ';

-- Uygulama sonrası DOĞRULA (Fable):
--   SELECT id, name, fatura_kisi_haftaici FROM customers WHERE fatura_kisi_haftaici IS NOT NULL;
--   Beklenen: CANTAŞ → 70 (tek satır).
