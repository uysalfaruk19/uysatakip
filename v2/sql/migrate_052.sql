-- ═══════════════════════════════════════════════════════════════
-- migrate_052.sql — İş emri fable-065 (MySQL 8 / MariaDB, canlı uysa_v2)
-- SABİT AYLIK FATURA KALEMİ: bir müşteriye yemek faturasından AYRI, üretimden BAĞIMSIZ,
-- her ay AYNI tutarda kesilen kalem (BOMİ → "PERSONEL HİZMET"; ileride kira/hizmet bedeli).
--
--   Sorun (Ömer, 31 Tem): BOMİ'nin personel hizmet faturası her ayın son günü Paraşüt'ten
--   ELLE kesiliyordu (30.04 · 21.05 · 30.06 · 31.07). Kokpit bu kalemi hiç bilmediği için
--   Fatura Kes ekranında görünmüyor, ay sonunda UNUTULMA riski taşıyordu.
--
--   Çözüm: kalem burada TANIMLANIR (koda gömülü DEĞİL — tutar/KDV/ürün id ekrandan girilir),
--   ay kapanınca Fatura Kes ekranında "SABİT" rozetli AYRI aday satırı olarak çıkar.
--
-- 🔑 GENEL ÖZELLİK — BOMİ'ye özel kod YOK. Kalem eklenmemiş müşteride hiçbir davranış değişmez.
-- 🔒 AYNI AY + AYNI KALEM İKİ KEZ KESİLEMEZ: kesim izi mevcut `parasut_fatura_log` tablosunda
--    tutulur (tip='sabit', alt_ad=kalem adı, sabit_kalem_id=kalem id). Kesimden ÖNCE o ayın
--    kaydı aranır; varsa HİÇBİR HTTP çağrısı yapılmaz (Temmuz zaten elle kesildi: UY02026000000145).
-- 🔒 SİLME YOK — kalem `aktif=0` ile pasifleşir (geçmiş fatura izi bozulmaz).
--
-- ⚠️ FK tipi INT UNSIGNED — customers.id ile BİREBİR aynı olmalı (errno 150 dersi, migrate_039/043/046/047/048).
-- İDEMPOTENT: CREATE TABLE IF NOT EXISTS + kolon ekleme koşullu (tekrar çalıştırmak güvenli).
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_052.sql
-- ═══════════════════════════════════════════════════════════════

-- ── 1) Sabit aylık fatura kalemleri ──
CREATE TABLE IF NOT EXISTS `musteri_sabit_fatura` (
  `id`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `customer_id`         INT UNSIGNED  NOT NULL,
  `ad`                  VARCHAR(120)  NOT NULL,                -- fatura kaleminin adı (ör. 'Personel hizmeti')
  `parasut_product_id`  VARCHAR(48)   DEFAULT NULL,            -- Paraşüt ürün id (ör. PERSONEL HİZMET)
  `parasut_contact_id`  VARCHAR(48)   DEFAULT NULL,            -- boşsa müşterinin KENDİ carisi (customers.parasut_id)
  `birim_fiyat`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,   -- 1 adet × bu tutar (KDV hariç)
  `kdv_orani`           DECIMAL(5,2)  NOT NULL DEFAULT 20.00,  -- yemek %10 DEĞİL — hizmette %20
  `aciklama`            VARCHAR(200)  DEFAULT NULL,            -- ekranda görünen not (faturaya girmez)
  `aktif`               TINYINT(1)    NOT NULL DEFAULT 1,      -- silme yok; 0 = pasif
  `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_msf_cust_ad` (`customer_id`, `ad`),
  KEY `idx_msf_cust` (`customer_id`, `aktif`, `id`),
  CONSTRAINT `fk_msf_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2) Kesim izi: parasut_fatura_log'a kalem bağı (idempotency anahtarı) ──
-- alt_ad zaten kalem adını taşır; sabit_kalem_id kalem YENİDEN ADLANDIRILSA da kalkanı korur.
SET @s = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parasut_fatura_log'
      AND COLUMN_NAME = 'sabit_kalem_id') = 0,
  "ALTER TABLE `parasut_fatura_log`
     ADD COLUMN `sabit_kalem_id` INT UNSIGNED NULL COMMENT 'tip=sabit ise musteri_sabit_fatura.id (fable-065)',
     ADD KEY `idx_fatura_sabit` (`sabit_kalem_id`, `donem_son`)",
  "SELECT 'sabit_kalem_id zaten var'"));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- `tip` kolonu VARCHAR(16) — şema değişikliği GEREKMEZ, yalnız açıklama tazelenir.
ALTER TABLE `parasut_fatura_log`
  MODIFY COLUMN `tip` VARCHAR(16) NOT NULL DEFAULT 'irsaliye'
  COMMENT 'irsaliye|aylik|sabit (fable-065: sabit = üretimden bağımsız sabit tutarlı kalem)';

-- ── Uygulama sonrası DOĞRULA (Fable) ──
--   SHOW CREATE TABLE musteri_sabit_fatura;   -- UNIQUE(customer_id,ad) + FK customers (INT UNSIGNED)
--   SHOW COLUMNS FROM parasut_fatura_log LIKE 'sabit_kalem_id';   -- 1 satır dönmeli
--   SELECT COUNT(*) FROM musteri_sabit_fatura;                    -- 0 (tablo boş başlar)
--
-- ══════════════════════════════════════════════════════════════════════════
-- ── BOMİ SEED (Fable canlıda uygular — burada BİLEREK çalıştırılmıyor) ──
--    Canlı Paraşüt değerleri: cari LODİ SAĞLIK LOJİSTİK A.Ş. = 1060083895 (= customers.parasut_id,
--    o yüzden parasut_contact_id BOŞ bırakılır), ürün PERSONEL HİZMET = 1066391424,
--    1 adet × 48.208,83 · KDV %20 = 57.850,60.
--
-- INSERT IGNORE INTO `musteri_sabit_fatura`
--   (`customer_id`, `ad`, `parasut_product_id`, `parasut_contact_id`, `birim_fiyat`, `kdv_orani`, `aciklama`)
-- SELECT c.`id`, 'Personel hizmeti', '1066391424', NULL, 48208.83, 20.00,
--        'Yemek faturasından ayrı; üretimden bağımsız, her ay aynı tutar.'
--   FROM `customers` c WHERE c.`name` = 'BOMİ';
--
-- ── TEMMUZ 2026 KALKANI (Fable canlıda uygular — fatura ZATEN elle kesildi) ──
--    Bu kayıt olmadan Kokpit Temmuz'u "kesilmemiş" sanar ve MÜKERRER e-Fatura riski doğar.
--    donem_son = ayın SON günü (kalkan bu tarihe bakar), durum='kesildi'.
--
-- INSERT INTO `parasut_fatura_log`
--   (`customer_id`, `donem_bas`, `donem_son`, `tip`, `sabit_kalem_id`, `parasut_contact_id`,
--    `fatura_no`, `alt_ad`, `toplam_kisi`, `toplam_tutar`, `durum`, `resmilestirme`, `mail`, `entered_by`)
-- SELECT k.`customer_id`, '2026-07-01', '2026-07-31', 'sabit', k.`id`, '1060083895',
--        'UY02026000000145', k.`ad`, 1, 57850.60, 'kesildi', 'gonderildi', 'yok', 'elle-parasut'
--   FROM `musteri_sabit_fatura` k
--   JOIN `customers` c ON c.`id` = k.`customer_id`
--  WHERE c.`name` = 'BOMİ' AND k.`ad` = 'Personel hizmeti';
--
-- Seed sonrası DOĞRULA (ekrandan): Kokpit → Fatura Kes → "Ay tamamı" (Temmuz)
--   · BOMİ satırı yemek (irsaliye) adayı olarak AYNEN durmalı,
--   · altında "BOMİ · Personel hizmeti" SABİT rozetli satır çıkmalı ve
--     "Bu ay kesildi (UY02026000000145)" sebebiyle SEÇİLEMEZ olmalı.
--   Ağustos'ta (31.08.2026'dan itibaren) aynı satır seçilebilir hâle gelir.
-- ══════════════════════════════════════════════════════════════════════════
