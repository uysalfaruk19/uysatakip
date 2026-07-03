-- ═══════════════════════════════════════════════════════════════
-- migrate_009.sql — İş emri opus-009 (MySQL 8 / MariaDB, canlı uysa_v2)
-- Personel Giderleri + Faturalar.
--   1) personel tablosu (ad, görev, aylık ücret)
--   2) personel_gider tablosu (maaş/prim/avans/sgk/diğer)
--   3) fatura tablosu (aylık müşteri faturası; production'dan üretilir)
--
-- İDEMPOTENT: sadece yeni tablo → CREATE TABLE IF NOT EXISTS. Mevcut veriye
-- dokunmaz, tekrar çalıştırılabilir.
-- Uygulama (Fable): docker exec -i <container> mysql -u <user> -p uysa_v2 < sql/migrate_009.sql
-- ═══════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── 1) personel ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `personel` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ad`          VARCHAR(150) NOT NULL,
  `gorev`       VARCHAR(120)          DEFAULT NULL,
  `aylik_ucret` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_personel_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2) personel_gider ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `personel_gider` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `personel_id` INT UNSIGNED          DEFAULT NULL,
  `tarih`       DATE         NOT NULL,
  `tur`         ENUM('maas','prim','avans','sgk','diger') NOT NULL DEFAULT 'maas',
  `tutar`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `aciklama`    VARCHAR(500)          DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pg_personel` (`personel_id`),
  KEY `idx_pg_tarih` (`tarih`),
  CONSTRAINT `fk_pg_personel` FOREIGN KEY (`personel_id`) REFERENCES `personel` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3) fatura (aylık müşteri faturası) ──────────────────────
CREATE TABLE IF NOT EXISTS `fatura` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`  INT UNSIGNED NOT NULL,
  `ay`           CHAR(7)      NOT NULL,
  `ara_toplam`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `kdv_oran`     DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  `genel_toplam` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `durum`        ENUM('taslak','kesildi') NOT NULL DEFAULT 'taslak',
  `source`       VARCHAR(20)  NOT NULL DEFAULT 'manuel',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fatura` (`customer_id`,`ay`),
  CONSTRAINT `fk_fatura_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
