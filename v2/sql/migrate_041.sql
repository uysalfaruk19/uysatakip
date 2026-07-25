-- fable-039: Kişi başı GIDA MALİYETİ kırılımları (Ömer, 2026-07-25).
-- Amaç: gıda alım faturaları tedarikçi bazında kırılıma (kuru gıda / kırmızı et / hal /
--   beyaz et / ekmek / tatlı) eşlensin; kişi başı gıda maliyeti ve kırılım oranları çıksın.
--   Eşleşmeyen tedarikçi gıda costuna GİRMEZ (sadece görünüm katmanı — karAnalizi/netKarlilik
--   sayıları DEĞİŞMEZ). Kırılım listesi PARAMETRİK (ileride eklenebilir).
--
-- Tedarikçi anahtarı = giderFirmaOzet firma adının NORMALİZE hali (Repo::normTedarikci:
--   trim + TR-uyumlu upper). VARCHAR(190): utf8mb4 index 190*4=760B < 767B limiti.
-- FK/tip disiplini (migrate_039/040 dersi, errno 150): tedarikci_gida_map.kirilim_kod →
--   gida_kirilim.kod; kod UNIQUE + AYNI charset/collation olmalı (utf8mb4_unicode_ci).
-- Idempotent (IF NOT EXISTS). Tedarikçi→kırılım VARSAYILANLARI burada DEĞİL — canlı firma
--   adları transactions.description'dan geldiği için PHP tarafında idempotent yüklenir:
--   Repo::gidaKirilimVarsayilanlariYukle() (Fable deploy'da bir kez CLI ile çağırır).

CREATE TABLE IF NOT EXISTS `gida_kirilim` (
  `id`   INT AUTO_INCREMENT PRIMARY KEY,
  `kod`  VARCHAR(40) NOT NULL COMMENT 'stabil kod (kuru_gida, kirmizi_et, ...)',
  `ad`   VARCHAR(80) NOT NULL COMMENT 'Türkçe görünen ad',
  `sira` INT NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_gk_kod` (`kod`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `gida_kirilim` (`kod`, `ad`, `sira`) VALUES
  ('kuru_gida', 'Kuru gıda',        1),
  ('kirmizi_et', 'Kırmızı et',      2),
  ('hal',        'Hal (sebze-meyve)', 3),
  ('beyaz_et',   'Beyaz et',        4),
  ('ekmek',      'Ekmek',           5),
  ('tatli',      'Tatlı',           6)
ON DUPLICATE KEY UPDATE `ad` = VALUES(`ad`), `sira` = VALUES(`sira`);

CREATE TABLE IF NOT EXISTS `tedarikci_gida_map` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `tedarikci`   VARCHAR(190) NOT NULL COMMENT 'normalize firma anahtarı (Repo::normTedarikci)',
  `kirilim_kod` VARCHAR(40) DEFAULT NULL COMMENT 'gida_kirilim.kod; NULL/satır yok = gıda costu DIŞI',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_tgm_ted` (`tedarikci`),
  KEY `idx_tgm_kod` (`kirilim_kod`),
  CONSTRAINT `fk_tgm_kod` FOREIGN KEY (`kirilim_kod`) REFERENCES `gida_kirilim`(`kod`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
