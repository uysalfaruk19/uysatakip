-- ═══════════════════════════════════════════════════════════════
-- migrate_047.sql — İş emri fable-048 (MySQL 8 / MariaDB, canlı uysa_v2)
-- GERÇEK (fatura bazlı) kâr/zarar: KESİLEN SATIŞ FATURALARI aynası.
--
--   Kaynak: Paraşüt /sales_invoices — SALT-OKUMA senkron (tools/parasut_satis_sync.php).
--   parasut_id UNIQUE = mükerrer kalkanı (aynı fatura iki kez girmez; senkron idempotent).
--   customer_id: fatura contact'ı ↔ customers.parasut_id eşleşmesi; EŞLEŞMEZSE NULL kalır
--     (kayıt yine de girer, ekranda "eşleşmemiş gelir" olarak AYRI gösterilir — gizleme/uydurma yok).
--   net_tutar = KDV HARİÇ mal/hizmet toplamı → kâr/zarar geliri BUNDAN hesaplanır
--     (üretim tahakkuk cirosu da KDV hariç; iki mod aynı bazda karşılaştırılabilir).
--   toplam    = KDV DAHİL genel toplam (Paraşüt net_total) — bilgi amaçlı, cari/tahsilat dili.
--   donem_bas/donem_son: faturanın kapsadığı hizmet dönemi (Paraşüt description'ından çıkarsa;
--     çıkmazsa NULL — "kapsanan dönem" bilgisi fatura tarihine düşer).
--
-- ⚠️ FK tipi INT UNSIGNED — customers.id ile BİREBİR aynı olmalı (errno 150 dersi, migrate_039/043/046).
-- İDEMPOTENT: CREATE TABLE IF NOT EXISTS (tekrar çalıştırmak güvenli).
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_047.sql
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `satis_faturasi` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `parasut_id`    VARCHAR(48)   NOT NULL,               -- Paraşüt sales_invoice id (mükerrer kalkanı)
  `customer_id`   INT UNSIGNED  NULL,                   -- NULL = eşleşmemiş gelir (contact Kokpit'te yok)
  `contact_id`    VARCHAR(48)   NULL,                   -- Paraşüt contact id (sonradan eşleştirme için saklanır)
  `contact_ad`    VARCHAR(200)  NULL,                   -- fatura üzerindeki cari adı (eşleşmeyende ekranda gösterilir)
  `fatura_no`     VARCHAR(60)   NULL,
  `fatura_tarihi` DATE          NOT NULL,
  `donem_bas`     DATE          NULL,
  `donem_son`     DATE          NULL,
  `net_tutar`     DECIMAL(12,2) NOT NULL DEFAULT 0,     -- KDV HARİÇ (kâr/zarar geliri)
  `kdv`           DECIMAL(12,2) NOT NULL DEFAULT 0,
  `toplam`        DECIMAL(12,2) NOT NULL DEFAULT 0,     -- KDV DAHİL genel toplam
  `aciklama`      VARCHAR(300)  NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sf_parasut` (`parasut_id`),
  KEY `idx_sf_tarih` (`fatura_tarihi`),
  KEY `idx_sf_cust` (`customer_id`, `fatura_tarihi`),
  CONSTRAINT `fk_sf_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kâr/Zarar varsayılan veri kaynağı: 'fatura' (gerçek) | 'uretim' (tahakkuk). Koda gömülmez.
INSERT IGNORE INTO `ayar` (`anahtar`, `deger`) VALUES ('kar_kaynak_varsayilan', 'fatura');
-- Son faturadan bu yana kaç gün geçince "bu tarihten sonrası henüz faturalanmadı" uyarısı çıksın.
INSERT IGNORE INTO `ayar` (`anahtar`, `deger`) VALUES ('fatura_gecikme_uyari_gun', '3');

-- Uygulama sonrası DOĞRULA (Fable):
--   SHOW CREATE TABLE satis_faturasi;      -- parasut_id UNIQUE + customer_id FK (INT UNSIGNED)
--   SELECT COUNT(*) FROM satis_faturasi;   -- ilk senkrondan önce 0
--   php tools/parasut_satis_sync.php 2026-07 --kuru     -- SALT-OKUMA prova (yazmadan özet)
