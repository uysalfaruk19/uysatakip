-- ═══════════════════════════════════════════════════════════════
-- migrate_031.sql — İş emri fable-023b (MySQL 8 / MariaDB, canlı uysa_v2)
-- "İrsaliyelendir": Bugün ekranından Paraşüt'e toplu e-İrsaliye kesimi.
--
-- Ekler:
--   1) parasut_irsaliye_log: her (müşteri, gün) için TEK kesim kaydı.
--      UNIQUE(customer_id, gun) = mükerrer kalkanının VERİTABANI kilidi (çift tık / iki cihaz).
--      Kayıt SİLİNMEZ — kesilen belge resmi e-İrsaliye, audit izi kalıcıdır.
--      durum: kesildi | hata | bilinmiyor  ('bilinmiyor' = timeout; belge kesilmiş OLABİLİR,
--      asla otomatik yeniden denenmez — insan Paraşüt'ten doğrular; idempotency standardı.)
--   2) customers.irsaliye_aktif: 1=irsaliye kesilir, 0=kapsam dışı (CANTAŞ + Marmara Teknik
--      aylık faturadan gidiyor — Ömer C3).
--   3) ayar: taşıyıcı bilgisi + öğün→Paraşüt ürün id eşlemesi (koda GÖMÜLMEZ, buradan okunur).
--   4) customers.parasut_id doldurma (canlı keşifte doğrulanan eşleşme).
--      Paraşüt id'leri SABİT yazılır; ada göre otomatik eşleştirme YOK (BOMİ→LODİ dersi:
--      Paraşüt'teki ad değişmiş olabilir). WHERE ölçütü yalnız KOKPİT adıdır ve zaten
--      parasut_id boş olan satıra yazar (mevcut eşleşmeyi ezmez).
--
-- İDEMPOTENT: CREATE TABLE IF NOT EXISTS · kolon için information_schema kontrolü ·
-- INSERT IGNORE · koşullu UPDATE. Tekrar çalıştırmak güvenli.
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_031.sql
--
-- 🔒 NOT: Bu migrasyon Paraşüt'e HİÇBİR ŞEY yazmaz. Kesim ana şalteri env
--    PARASUT_IRSALIYE_AKTIF (canlıda 0) — şema hazır olsa da kod POST atmaz.
-- ═══════════════════════════════════════════════════════════════

-- ── 1) parasut_irsaliye_log ─
CREATE TABLE IF NOT EXISTS `parasut_irsaliye_log` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`    INT UNSIGNED NOT NULL,
  `gun`            DATE         NOT NULL COMMENT 'irsaliye günü (issue_date)',
  `parasut_doc_id` VARCHAR(40)           DEFAULT NULL COMMENT 'shipment_documents.id',
  `despatch_no`    VARCHAR(64)           DEFAULT NULL COMMENT 'Paraşüt otomatik seri no',
  `kalemler`       TEXT                  DEFAULT NULL COMMENT 'JSON: [{ogun,urun_id,miktar}]',
  `toplam_kisi`    INT          NOT NULL DEFAULT 0,
  `durum`          VARCHAR(16)  NOT NULL DEFAULT 'hata' COMMENT 'kesildi|hata|bilinmiyor',
  `hata_mesaj`     VARCHAR(500)          DEFAULT NULL,
  `tasiyici_ok`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'dönen belgede plaka/şoför işlendi mi',
  `entered_by`     VARCHAR(64)  NOT NULL DEFAULT '',
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_irsaliye_cust_gun` (`customer_id`, `gun`),
  KEY `idx_irsaliye_gun` (`gun`),
  CONSTRAINT `fk_irsaliye_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2) customers.irsaliye_aktif (yoksa ekle) ─
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'irsaliye_aktif');
SET @sql := IF(@has = 0,
  "ALTER TABLE `customers` ADD COLUMN `irsaliye_aktif` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0=irsaliye kapsamı dışı (fable-023b)' AFTER `parasut_sync_at`",
  "DO 0");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 3) Ayarlar: taşıyıcı + öğün→ürün eşlemesi (canlı emsalden; ayardan değiştirilebilir) ─
INSERT IGNORE INTO `ayar` (`anahtar`, `deger`) VALUES
  ('irsaliye_plaka', '41BEM936'),
  ('irsaliye_sofor_ad', 'UFUK BALTACI'),
  ('irsaliye_sofor_tckn', '23354463864'),
  ('irsaliye_urun_ogle', '1063984872'),
  ('irsaliye_urun_aksam', '1063985050'),
  ('irsaliye_urun_kumanya', '1063985150');

-- ── 4) Paraşüt cari eşleşmesi (yalnız boş olanlara yazılır) ─
-- ⚠️ WHERE ölçütü KOKPİT'teki müşteri adıdır ve TAM eşleşmedir (LIKE yok: yanlış müşteriye
--    irsaliye kesmek geri dönülmezdir). Canlıda ad farklıysa o satır boş kalır → UI'da
--    "Paraşüt eşleşmesi yok" görünür ve müşteri seçilemez (sessiz atlama yok).
-- Uygulama sonrası DOĞRULA (Fable):
--   SELECT id, name, parasut_id, irsaliye_aktif FROM customers WHERE is_active = 1 ORDER BY name;
-- Beklenen: 6 müşteride parasut_id dolu, CANTAŞ + Marmara Teknik'te irsaliye_aktif = 0.
UPDATE `customers` SET `parasut_id` = '1060083895' WHERE `name` = 'BOMİ'           AND (`parasut_id` IS NULL OR `parasut_id` = '');
UPDATE `customers` SET `parasut_id` = '1060083802' WHERE `name` = 'PENDORYA'       AND (`parasut_id` IS NULL OR `parasut_id` = '');
UPDATE `customers` SET `parasut_id` = '1060083275' WHERE `name` = 'CEOTHERM'       AND (`parasut_id` IS NULL OR `parasut_id` = '');
UPDATE `customers` SET `parasut_id` = '1060083336' WHERE `name` = 'ERMETAL'        AND (`parasut_id` IS NULL OR `parasut_id` = '');
UPDATE `customers` SET `parasut_id` = '1060083480' WHERE `name` = 'OPAK'           AND (`parasut_id` IS NULL OR `parasut_id` = '');
UPDATE `customers` SET `parasut_id` = '1060083959' WHERE `name` = 'TALAY LOJİSTİK' AND (`parasut_id` IS NULL OR `parasut_id` = '');

-- Kapsam dışı (aylık faturadan gidiyor — irsaliye kesilmez)
UPDATE `customers` SET `irsaliye_aktif` = 0 WHERE `name` IN ('CANTAŞ', 'Marmara Teknik', 'MARMARA TEKNİK');
