-- fable-035: Tedarikçi→müşteri ve Personel→müşteri MALİYET EŞLEŞTİRMELERİ (Ömer).
-- Amaç: bir tedarikçinin faturaları / bir personelin yüklü maliyeti, seçili müşterilere
--   o ayki KİŞİ SAYISI oranında dağılsın (30/70 kişi → 30/70 TL). Eşleşmeyenler mevcut
--   genel havuzda kalır (regresyon: tablolar boşken tüm sayılar eskisiyle birebir aynı).
-- Eşleştirme "değiştirilene kadar" kalıcı (tablo). Idempotent (IF NOT EXISTS).
--
-- Tedarikçi anahtarı = giderFirmaOzet firma adının NORMALİZE hali (Repo::normTedarikci:
--   trim + TR-uyumlu upper). VARCHAR(190): utf8mb4 index 190*4=760B < 767B limiti.

CREATE TABLE IF NOT EXISTS `tedarikci_musteri_map` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `tedarikci`   VARCHAR(190) NOT NULL COMMENT 'normalize firma anahtarı (Repo::normTedarikci)',
  `customer_id` INT UNSIGNED NOT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_ted_cust` (`tedarikci`, `customer_id`),
  KEY `idx_tmm_ted` (`tedarikci`),
  CONSTRAINT `fk_tmm_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `personel_musteri_map` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `personel_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_pers_cust` (`personel_id`, `customer_id`),
  KEY `idx_pmm_pers` (`personel_id`),
  CONSTRAINT `fk_pmm_pers` FOREIGN KEY (`personel_id`) REFERENCES `personel`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pmm_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
