-- ═══════════════════════════════════════════════════════════════
-- migrate_046.sql — İş emri fable-047 (MySQL 8 / MariaDB, canlı uysa_v2)
-- RESMİ TATİL takvimi: tatil öncesi 3 gün uyarı + ay kapanışı "tatil davranışı" analizi.
--
--   tarih UNIQUE → aynı gün iki kez girilemez; seed dosyası INSERT IGNORE ile idempotent.
--   tur: 'resmi' (ulusal) | 'dini' (bayram günü) | 'arefe' (yarım gün).
--   yarim_gun=1 → tam tatil SAYILMAZ: sayı girilir ama düşük olabilir; analiz bunu bilir.
--   SİLME YOK — aktif=0 ile pasifleşir (iz kalır; geçmiş tatil karşılaştırması bozulmaz).
--
-- İDEMPOTENT: CREATE TABLE IF NOT EXISTS (tekrar çalıştırmak güvenli).
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_046.sql
--   ardından seed:  mysql -u <user> -p uysa_v2 < <scratchpad>/resmi_tatil_seed.sql
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `resmi_tatil` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tarih`      DATE         NOT NULL,
  `ad`         VARCHAR(120) NOT NULL,
  `tur`        ENUM('resmi','dini','arefe') NOT NULL DEFAULT 'resmi',
  `yarim_gun`  TINYINT(1)   NOT NULL DEFAULT 0,
  `aktif`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rt_tarih` (`tarih`),
  KEY `idx_rt_aktif` (`aktif`, `tarih`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ay kapanışı "anormal sayı davranışı" eşiği (%) — koda gömülmez, Ömer değiştirebilir.
INSERT IGNORE INTO `ayar` (`anahtar`, `deger`) VALUES ('kapanis_sapma_esik', '40');

-- Uygulama sonrası DOĞRULA (Fable):
--   SHOW CREATE TABLE resmi_tatil;                 -- tarih UNIQUE, tur ENUM, aktif default 1
--   SELECT COUNT(*) FROM resmi_tatil;              -- seed sonrası 19
--   SELECT * FROM resmi_tatil ORDER BY tarih LIMIT 3;
