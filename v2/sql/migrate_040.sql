-- fable-037: FATURA bazlı → müşteri MALİYET EŞLEŞTİRMESİ (Ömer).
-- Amaç: belirli bir tedarikçi FATURASI (transactions satırı), tedarikçi eşleşmesinden
--   FARKLI müşterilere o ayki KİŞİ SAYISI oranında dağıtılabilsin (kişi 0 → eşit böl).
--   En ÜST öncelik katmanı: fatura eşleşmesi > elle alloc_type='musteri' > tedarikçi eşleşmesi > genel havuz.
-- Regresyon: tablo boşken tüm dağıtım fable-035 sonucuyla BİREBİR (giderDagitim'de en üste eklenir).
-- Eşleştirme "değiştirilene kadar" kalıcı (tablo). Idempotent (IF NOT EXISTS).
--
-- FK tipleri INT UNSIGNED — canlı şemadan teyitli (SHOW COLUMNS / schema_v2.sql):
--   transactions.id = INT UNSIGNED, customers.id = INT UNSIGNED. (fable-035b errno 150 dersi:
--   FK tipleri referans kolonuyla BİREBİR aynı olmalı, yoksa errno 150.)

CREATE TABLE IF NOT EXISTS `fatura_musteri_map` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `tx_id`       INT UNSIGNED NOT NULL COMMENT 'transactions.id (gider faturası)',
  `customer_id` INT UNSIGNED NOT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_fmm` (`tx_id`, `customer_id`),
  KEY `idx_fmm_tx` (`tx_id`),
  CONSTRAINT `fk_fmm_tx`   FOREIGN KEY (`tx_id`)       REFERENCES `transactions`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fmm_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
