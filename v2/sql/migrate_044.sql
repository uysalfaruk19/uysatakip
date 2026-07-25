-- ═══════════════════════════════════════════════════════════════
-- migrate_044.sql — İş emri fable-045 (MySQL 8 / MariaDB, canlı uysa_v2)
-- BORÇLARIM: tedarikçi bazlı borç takibi (AYDAN BAĞIMSIZ, kümülatif).
--   Borç(tedarikçi) = devir(elle bir kere) + Σ(tüm zaman gider faturaları) − Σ(ödemeler).
--   Anahtar = Repo::normTedarikci(txFirma); gider tx / ödeme / devir AYNI anahtarda hizalanır.
--
-- tedarikci VARCHAR(190) = tedarikci_musteri_map/tedarikci_gida_map ile AYNI tip/uzunluk
--   (normTedarikci 190'a keser; utf8mb4 index limiti güvenli).
-- 'note' kolonu: İş emrinde 'not' geçiyor ama MySQL'de NOT REZERVE KELİME → 'note' kullanıldı
--   (cari_entries.note ile tutarlı; SQLite'ta da NOT keyword). tutar DECIMAL negatif = düzeltme.
--
-- İDEMPOTENT: CREATE TABLE IF NOT EXISTS (tekrar çalıştırmak güvenli). Silme yok (audit izi).
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_044.sql
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `tedarikci_odeme` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `tedarikci`    VARCHAR(190)  NOT NULL,
  `odeme_tarihi` DATE          NOT NULL,
  `tutar`        DECIMAL(12,2) NOT NULL,
  `note`         VARCHAR(300)           DEFAULT NULL,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_to_ted` (`tedarikci`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tedarikci_devir` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `tedarikci`  VARCHAR(190)  NOT NULL,
  `label`      VARCHAR(190)  NOT NULL DEFAULT '',
  `tutar`      DECIMAL(12,2) NOT NULL DEFAULT 0,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_td_ted` (`tedarikci`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uygulama sonrası DOĞRULA (Fable):
--   SHOW CREATE TABLE tedarikci_odeme;  -- tutar DECIMAL(12,2), note VARCHAR(300), idx idx_to_ted
--   SHOW CREATE TABLE tedarikci_devir;  -- tedarikci UNIQUE (upsert), tutar DECIMAL(12,2)
