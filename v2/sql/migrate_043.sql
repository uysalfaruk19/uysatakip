-- ═══════════════════════════════════════════════════════════════
-- migrate_043.sql — İş emri fable-044 (MySQL 8 / MariaDB, canlı uysa_v2)
-- gider_kalem: gider faturalarının ÜRÜN SATIRLARI (en çok para harcanan top-10 + birim fiyat).
--
-- Kaynak: Paraşüt e-fatura UBL satırları (otomatik senkron, Repo::giderKalemSenkron) VEYA
--   ÖRS gibi Paraşüt-dışı faturalar için CSV/elle yükleme (Fable). Tablo kaynaktan bağımsız.
-- tx_id INT UNSIGNED = transactions.id ile AYNI tip (aksi FK errno 150). ON DELETE CASCADE:
--   gider tx silinirse kalemleri de gider (yetim satır kalmaz).
--
-- İDEMPOTENT: CREATE TABLE IF NOT EXISTS (tekrar çalıştırmak güvenli).
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_043.sql
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `gider_kalem` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `tx_id`       INT UNSIGNED  NOT NULL,
  `urun`        VARCHAR(200)  NOT NULL,
  `miktar`      DECIMAL(12,3)          DEFAULT NULL,
  `birim`       VARCHAR(20)            DEFAULT NULL,
  `birim_fiyat` DECIMAL(12,2)          DEFAULT NULL,
  `tutar`       DECIMAL(12,2) NOT NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gk_tx` (`tx_id`),
  CONSTRAINT `fk_gk_tx` FOREIGN KEY (`tx_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uygulama sonrası DOĞRULA (Fable):
--   SHOW CREATE TABLE gider_kalem;  -- tx_id INT UNSIGNED + FK transactions(id) ON DELETE CASCADE
