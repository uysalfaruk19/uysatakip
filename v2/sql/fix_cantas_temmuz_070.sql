-- ═══════════════════════════════════════════════════════════════
-- fix_cantas_temmuz_070.sql — fable-040 GEÇMİŞ DÜZELTME (MySQL, canlı uysa_v2)
-- CANTAŞ Temmuz (ve ileri) HAFTA İÇİ 'ogle' satırlarının cirosunu fatura kişisine (70) çeker.
--   persons = 50 (GERÇEK üretim) DEĞİŞMEZ · amount = 70 × unit_price_snap (fatura cirosu).
-- Kural sadece hafta içi (Pzt–Cum). Cumartesi/pazar DOKUNULMAZ (gerçek sayı = gerçek ciro).
--
-- ⚠️ Bu dosya CANLI production.amount'u değiştirir → Fable ELLE, YEDEK sonrası uygular.
--    migrate_042.sql (şema + kural seed) ÖNCE koşmalı.
-- Önce yedek:  mysqldump ... uysa_v2 production > yedek_production_$(date +%F).sql
-- Uygula:      mysql -u <user> -p uysa_v2 < sql/fix_cantas_temmuz_070.sql
--
-- İdempotent: tekrar koşmak aynı sonucu verir (amount hep 70 × unit_price_snap'e set edilir).
-- ═══════════════════════════════════════════════════════════════

-- CANTAŞ id'yi ADDAN çöz (yanlış id'ye yazmamak için — geri dönülmez veri).
SET @cid := (SELECT id FROM customers WHERE name = 'CANTAŞ' LIMIT 1);

-- ── ÖNCE (denetim): düzeltilecek satırlar + mevcut/beklenen ciro ─
SELECT prod_date, persons, unit_price_snap, amount AS mevcut_amount,
       ROUND(70 * unit_price_snap, 2) AS yeni_amount
FROM production
WHERE customer_id = @cid AND meal = 'ogle' AND persons > 0
  AND prod_date >= '2026-07-01'
  AND DAYOFWEEK(prod_date) BETWEEN 2 AND 6   -- 1=Paz,7=Cmt → 2..6 = Pzt–Cum
ORDER BY prod_date;

-- ── DÜZELTME: hafta içi öğle amount = 70 × birim (persons DOKUNULMAZ) ─
UPDATE production
SET amount = ROUND(70 * unit_price_snap, 2)
WHERE customer_id = @cid AND meal = 'ogle' AND persons > 0
  AND prod_date >= '2026-07-01'
  AND DAYOFWEEK(prod_date) BETWEEN 2 AND 6;

-- ── SONRA (denetim): Temmuz CANTAŞ günlük ciro (hafta içi 70×fiyat olmalı) ─
SELECT prod_date, persons, unit_price_snap, amount
FROM production
WHERE customer_id = @cid AND substr(prod_date,1,7) = '2026-07'
ORDER BY prod_date, meal;
