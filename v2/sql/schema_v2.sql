-- ═══════════════════════════════════════════════════════════════
-- UYSA Kokpit (ERP v2) — İlişkisel Şema  (Faz 1, M6 tabloları dahil)
-- MySQL 8 / MariaDB 10.4+ · utf8mb4 · DECIMAL(12,2) · prepared-statement uyumlu
-- İş emri: opus-004 · Mimari: vault/projeler/uysa-erp-v2/mimari.md (rev-2)
--
-- Not: v1 (uysa_storage key-value) tablolarına DOKUNULMAZ; readonly arşiv.
-- Bu şema aynı veritabanında (uysa_db) yeni tablolar olarak yaşar.
-- ═══════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── Kullanıcılar (iç: UYSA personeli) — v1 deseni devralınır ───
CREATE TABLE IF NOT EXISTS `users` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`     VARCHAR(50)  NOT NULL,
  `password`     VARCHAR(255) NOT NULL COMMENT 'bcrypt (cost=12)',
  `role`         ENUM('superadmin','editor','user','viewer') NOT NULL DEFAULT 'user',
  `display_name` VARCHAR(100)          DEFAULT NULL,
  `last_login`   DATETIME              DEFAULT NULL,
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Müşteriler (catering firmaları) ───────────────────────────
CREATE TABLE IF NOT EXISTS `customers` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(150) NOT NULL,
  `unit_price`    DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'kişi başı TL',
  `category`      ENUM('uretim','tasima') NOT NULL DEFAULT 'uretim' COMMENT 'üretim (yemek) / taşıma müşterisi',
  `contact`       VARCHAR(255)          DEFAULT NULL,
  `phone`         VARCHAR(40)           DEFAULT NULL,
  `email`         VARCHAR(120)          DEFAULT NULL,
  `contract_note` VARCHAR(500)          DEFAULT NULL,
  `maliyet_birim`      DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'taşıma: alış birim fiyatı TL (opus-013)',
  `tasima_sabit_gider` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'taşıma: aylık sabit gider TL (opsiyonel)',
  `tasima_not`         VARCHAR(500)          DEFAULT NULL COMMENT 'taşıma: not (opsiyonel)',
  `parasut_id`         VARCHAR(40)           DEFAULT NULL COMMENT 'Paraşüt contact id (opus-012, eşleşince yazılır)',
  `parasut_bakiye`     DECIMAL(14,2)         DEFAULT NULL COMMENT 'Paraşüt güncel cari bakiye (SALT-OKUMA muhasebe)',
  `parasut_sync_at`    DATETIME              DEFAULT NULL COMMENT 'son Paraşüt senkron zamanı',
  `irsaliye_aktif`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0=irsaliye kapsamı dışı (fable-023b)',
  `sevk_adres`         VARCHAR(255)          DEFAULT NULL COMMENT 'irsaliye sevk adresi (fable-023c)',
  `sevk_il`            VARCHAR(60)           DEFAULT NULL,
  `sevk_ilce`          VARCHAR(60)           DEFAULT NULL,
  `edespatch_alias`    VARCHAR(120)          DEFAULT NULL COMMENT 'GİB e-İrsaliye alıcı kutusu (fable-023d)',
  `irsaliye_mail`      VARCHAR(255)          DEFAULT NULL COMMENT 'e-İrsaliye mail paylaşım adresleri (fable-023e)',
  `tevkifat_kodu`      VARCHAR(10)           DEFAULT NULL COMMENT 'KDV tevkifat kodu (dolu=tevkifatlı, örn 604); boş=yok (fable-024)',
  `tevkifat_oran`      DECIMAL(5,2)          DEFAULT NULL COMMENT 'tevkifat oranı (KDV %si, örn 50.00) (fable-024)',
  `fatura_vade_gun`    INT          NOT NULL DEFAULT 1 COMMENT 'fatura vadesi = issue + N gün (fable-024)',
  `fatura_mail`        VARCHAR(255)          DEFAULT NULL COMMENT 'fatura mail paylaşım adresleri (fable-024)',
  `fatura_bolusum`     TEXT                  DEFAULT NULL COMMENT 'aylık faturayı N contact a bölme config JSON [{key,ad}] (fable-024)',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customer_name` (`name`),
  KEY `idx_active` (`is_active`),
  KEY `idx_parasut_id` (`parasut_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Müşteri AY-BAZLI fiyat (opus-017) ─────────────────────────
-- Bir ayın fiyatını değiştirince o ay her yerde güncellenir; tarihsel doğruluk korunur.
-- priceFor(cid, ay) = o ay > carry-forward (önceki ay) > customers current default.
CREATE TABLE IF NOT EXISTS `customer_price` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`        INT UNSIGNED NOT NULL,
  `ay`                 CHAR(7)      NOT NULL COMMENT 'YYYY-MM',
  `unit_price`         DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'üretim: kişi başı; taşıma: satış birim',
  `maliyet_birim`      DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'taşıma: alış birim (üretimde 0)',
  `tasima_sabit_gider` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'taşıma: aylık sabit gider (üretimde 0)',
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customer_price` (`customer_id`,`ay`),
  KEY `idx_cp_cust_ay` (`customer_id`,`ay`),
  CONSTRAINT `fk_cp_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Taşıma müşterisi aylık karlılık — ATIL (opus-013'te terk edildi) ──────────
-- ARTIK KULLANILMIYOR: taşıma kartı customers'ta (unit_price/maliyet_birim/
-- tasima_sabit_gider/tasima_not), adet = production.persons toplamı.
-- Tablo DÜŞÜRÜLMEDİ (veri kaybı riski) ama kod ondan okumaz/yazmaz.
CREATE TABLE IF NOT EXISTS `tasima_aylik` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`  INT UNSIGNED NOT NULL,
  `ay`           CHAR(7)      NOT NULL COMMENT 'YYYY-MM',
  `adet`         DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'o ay satılan yemek adedi',
  `birim_alis`   DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'adet başı alış/tedarik TL',
  `birim_satis`  DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'adet başı satış TL',
  `sabit_gider`  DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'opsiyonel aylık sabit gider TL',
  `note`         VARCHAR(500)          DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tasima_ay` (`customer_id`,`ay`),
  CONSTRAINT `fk_tasima_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Müşteri uygulaması kullanıcıları (M6, dış) — F1'de sadece şema ─
CREATE TABLE IF NOT EXISTS `customer_users` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`      INT UNSIGNED NOT NULL,
  `username`         VARCHAR(60)  NOT NULL,
  `password_bcrypt`  VARCHAR(255) NOT NULL,
  `display_name`     VARCHAR(100)          DEFAULT NULL,
  `phone`            VARCHAR(40)           DEFAULT NULL,
  `role`             ENUM('owner','staff') NOT NULL DEFAULT 'owner',
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login`       DATETIME              DEFAULT NULL,
  `feed_seen_at`     DATETIME              DEFAULT NULL COMMENT 'Akış (customer_events) okundu kesimi (fable-018)',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cu_username` (`username`),
  KEY `idx_cu_customer` (`customer_id`),
  CONSTRAINT `fk_cu_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Siparişler (M6 onay kuyruğu kaynağı) — F1'de bot/elle giriş de buradan akabilir ─
CREATE TABLE IF NOT EXISTS `orders` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `order_date`  DATE         NOT NULL,
  `meal`        ENUM('sabah','ogle','aksam','gece','kumanya') NOT NULL DEFAULT 'ogle',
  `persons`     INT UNSIGNED NOT NULL DEFAULT 0,
  `menu_type`   VARCHAR(40)           DEFAULT NULL,
  `status`      ENUM('taslak','gonderildi','onaylandi','reddedildi') NOT NULL DEFAULT 'gonderildi',
  `entered_by`  ENUM('musteri','uysa','bot') NOT NULL DEFAULT 'uysa',
  `note`        VARCHAR(500)          DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order` (`customer_id`,`order_date`,`meal`),
  CONSTRAINT `fk_order_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Üretim (tek gerçek kaynak: fatura/rapor buradan) ──────────
CREATE TABLE IF NOT EXISTS `production` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`      INT UNSIGNED NOT NULL,
  `prod_date`        DATE         NOT NULL,
  `meal`             ENUM('sabah','ogle','aksam','gece','kumanya') NOT NULL DEFAULT 'ogle',
  `persons`          INT UNSIGNED NOT NULL DEFAULT 0,
  `unit_price_snap`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `amount`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `order_id`         INT UNSIGNED          DEFAULT NULL,
  `note`             VARCHAR(500)          DEFAULT NULL,
  `entered_by`       ENUM('musteri','uysa','bot') NOT NULL DEFAULT 'uysa',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_production` (`customer_id`,`prod_date`,`meal`),
  KEY `idx_prod_date` (`prod_date`),
  CONSTRAINT `fk_prod_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_prod_order`    FOREIGN KEY (`order_id`)    REFERENCES `orders` (`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Dosyalar (fatura foto, talep foto vb.) — request_messages FK'inden ÖNCE tanımlı olmalı ─
CREATE TABLE IF NOT EXISTS `files` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename`    VARCHAR(255) NOT NULL COMMENT 'güvenli ad: ts_hex.ext',
  `original`    VARCHAR(255) NOT NULL,
  `mime`        VARCHAR(100) NOT NULL,
  `size_bytes`  INT UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_by` VARCHAR(100)          DEFAULT NULL,
  `category`    VARCHAR(100)          DEFAULT NULL,
  `deleted_at`  DATETIME              DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_files_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Talepler / şikayet / mesaj (M6) ───────────────────────────
CREATE TABLE IF NOT EXISTS `requests` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`      INT UNSIGNED NOT NULL,
  `customer_user_id` INT UNSIGNED          DEFAULT NULL,
  `type`             ENUM('talep','sikayet','mesaj','menu','oneri') NOT NULL DEFAULT 'talep',
  `subject`          VARCHAR(200) NOT NULL,
  `status`           ENUM('acik','cozuldu') NOT NULL DEFAULT 'acik',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_req_customer` (`customer_id`),
  KEY `idx_req_status` (`status`),
  CONSTRAINT `fk_req_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `request_messages` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` INT UNSIGNED NOT NULL,
  `sender`     ENUM('musteri','uysa') NOT NULL,
  `body`       TEXT         NOT NULL,
  `file_id`    INT UNSIGNED          DEFAULT NULL COMMENT 'opus-019: foto eki (files.id)',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rm_request` (`request_id`),
  CONSTRAINT `fk_rm_request` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rm_file`    FOREIGN KEY (`file_id`)    REFERENCES `files` (`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Duyurular (M6) ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `announcements` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(200) NOT NULL,
  `body`       TEXT         NOT NULL,
  `publish_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `audience`   VARCHAR(50)  NOT NULL DEFAULT 'hepsi' COMMENT 'hepsi | customer_id',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Tedarikçiler ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `suppliers` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(200) NOT NULL,
  `contact`    VARCHAR(255)          DEFAULT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_supplier_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Finans: gelir/gider ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `transactions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`        ENUM('gelir','gider') NOT NULL,
  `category`    VARCHAR(80)           DEFAULT NULL,
  `tx_date`     DATE         NOT NULL,
  `amount`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `customer_id` INT UNSIGNED          DEFAULT NULL,
  `supplier_id` INT UNSIGNED          DEFAULT NULL,
  `description` VARCHAR(500)          DEFAULT NULL,
  `file_id`     INT UNSIGNED          DEFAULT NULL,
  -- fable-030: dış-kaynak senkron alanları ('manuel'/'parasut'; parasut_id = mükerrer kalkanı)
  `source`      VARCHAR(20)  NOT NULL DEFAULT 'manuel',
  `parasut_id`  VARCHAR(48)           DEFAULT NULL COMMENT 'Paraşüt fatura id (ei- önekli=gelen kutusu)',
  `qty`         DECIMAL(12,2)         DEFAULT NULL COMMENT 'fatura satır adedi (fable-031b)',
  `net_amount`  DECIMAL(12,2)         DEFAULT NULL COMMENT 'KDV hariç tutar (fable-031b)',
  -- opus-015: gider dağıtım hedefi. 'genel'=tüm müşterilere ciro oranlı; 'musteri'=transaction_customer hedefleri.
  `alloc_type`  VARCHAR(10)  NOT NULL DEFAULT 'genel',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tx_date` (`tx_date`),
  KEY `idx_tx_type` (`type`),
  CONSTRAINT `fk_tx_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tx_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tx_file`     FOREIGN KEY (`file_id`)     REFERENCES `files` (`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Gider → müşteri dağıtım hedefi (opus-015, alloc_type='musteri' iken) ──
CREATE TABLE IF NOT EXISTS `transaction_customer` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id` INT UNSIGNED NOT NULL,
  `customer_id`    INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tc` (`transaction_id`,`customer_id`),
  KEY `idx_tc_tx` (`transaction_id`),
  CONSTRAINT `fk_tc_tx`       FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tc_customer` FOREIGN KEY (`customer_id`)    REFERENCES `customers` (`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Cari hareketler (alacak/borç, tahsilat) ───────────────────
-- direction: borc = tarafın bize borcu artar (üretim) · alacak = tahsilat/ödeme
CREATE TABLE IF NOT EXISTS `cari_entries` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_type` ENUM('customer','supplier') NOT NULL,
  `party_id`   INT UNSIGNED NOT NULL,
  `entry_date` DATE         NOT NULL,
  `direction`  ENUM('borc','alacak') NOT NULL,
  `amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `note`       VARCHAR(500)          DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cari_party` (`party_type`,`party_id`),
  KEY `idx_cari_date` (`entry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Menü & Maliyet (M4, F2) — şema F1'de hazır ────────────────
CREATE TABLE IF NOT EXISTS `ingredients` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(150) NOT NULL,
  `unit`           VARCHAR(20)  NOT NULL DEFAULT 'kg',
  `price_per_unit` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `min_stok`       DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'kritik stok eşiği (0 = uyarı yok)',
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ingredient` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recipes` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(200) NOT NULL,
  `category`    VARCHAR(80)           DEFAULT NULL,
  `portion_note` VARCHAR(200)         DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_recipe` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recipe_items` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipe_id`     INT UNSIGNED NOT NULL,
  `ingredient_id` INT UNSIGNED NOT NULL,
  `grams`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_ri_recipe` (`recipe_id`),
  CONSTRAINT `fk_ri_recipe`     FOREIGN KEY (`recipe_id`)     REFERENCES `recipes` (`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_ri_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `menu_days` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `menu_date`    DATE         NOT NULL,
  `meal`         ENUM('sabah','ogle','aksam','gece','kumanya') NOT NULL DEFAULT 'ogle',
  `menu_type`    VARCHAR(40)  NOT NULL DEFAULT 'standart',
  `recipe_id`    INT UNSIGNED          DEFAULT NULL,
  `is_published` TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_menu_date` (`menu_date`),
  CONSTRAINT `fk_md_recipe` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Stok hareketleri (M4 — Stok Durumu) ───────────────────────
-- Mevcut stok = Σ(giris) − Σ(cikis) malzeme başına. Kritik = stok < ingredients.min_stok (>0).
CREATE TABLE IF NOT EXISTS `stock_moves` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ingredient_id` INT UNSIGNED NOT NULL,
  `move_date`     DATE         NOT NULL,
  `direction`     ENUM('giris','cikis') NOT NULL,
  `quantity`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `unit`          VARCHAR(20)  NOT NULL DEFAULT 'kg',
  `skt`           DATE                  DEFAULT NULL,
  `supplier_id`   INT UNSIGNED          DEFAULT NULL,
  `note`          VARCHAR(500)          DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sm_ingredient` (`ingredient_id`),
  KEY `idx_sm_date` (`move_date`),
  CONSTRAINT `fk_sm_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sm_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── fable-003: HACCP günlük kontrol / teklif / teslimat ─────────
CREATE TABLE IF NOT EXISTS `haccp_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `log_date`   DATE         NOT NULL,
  `kind`       ENUM('sicaklik','hijyen','numune','malkabul') NOT NULL,
  `nokta`      VARCHAR(80)  NOT NULL,
  `deger`      VARCHAR(40)           DEFAULT NULL,
  `uygun`      TINYINT(1)            DEFAULT NULL,
  `note`       VARCHAR(300)          DEFAULT NULL,
  `imha_at`    DATETIME              DEFAULT NULL,
  `created_by` VARCHAR(40)           DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_haccp_date` (`log_date`),
  KEY `idx_haccp_kind` (`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `teklif` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `firma`         VARCHAR(120) NOT NULL,
  `yetkili`       VARCHAR(120)          DEFAULT NULL,
  `telefon`       VARCHAR(40)           DEFAULT NULL,
  `email`         VARCHAR(120)          DEFAULT NULL,
  `kisi`          INT                   DEFAULT NULL,
  `ogun_sayisi`   TINYINT      NOT NULL DEFAULT 1,
  `cumartesi`     TINYINT(1)   NOT NULL DEFAULT 0,
  `sehir`         VARCHAR(80)           DEFAULT NULL,
  `ilce`          VARCHAR(80)           DEFAULT NULL,
  `segment`       ENUM('ekonomik','genel','premium') DEFAULT NULL,
  `menu_json`     VARCHAR(1000)         DEFAULT NULL,
  `personel_json` VARCHAR(300)          DEFAULT NULL,
  `ekipman`       VARCHAR(500)          DEFAULT NULL,
  `birim_fiyat`   DECIMAL(10,2)         DEFAULT NULL,
  `fiyat_json`    VARCHAR(500)          DEFAULT NULL,
  `giris_metni`   VARCHAR(1500)         DEFAULT NULL,
  `note`          VARCHAR(500)          DEFAULT NULL,
  `durum`         ENUM('taslak','gonderildi','kabul','red') NOT NULL DEFAULT 'taslak',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_teklif_durum` (`durum`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `teslimat` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `teslim_date` DATE         NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `status`      ENUM('bekliyor','yolda','teslim') NOT NULL DEFAULT 'bekliyor',
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_teslimat` (`teslim_date`,`customer_id`),
  CONSTRAINT `fk_teslimat_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Personel (maaş/prim/gider takibi — opus-009) ──────────────
CREATE TABLE IF NOT EXISTS `personel` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ad`            VARCHAR(150) NOT NULL,
  `gorev`         VARCHAR(120)          DEFAULT NULL,
  `aylik_ucret`   DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'brüt aylık ücret TL',
  `ise_giris`     DATE                  DEFAULT NULL COMMENT 'kıdem başlangıcı (opus-014)',
  `ise_cikis`     DATE                  DEFAULT NULL COMMENT 'işten çıkış; dolu=pasif, o ay kıst maaş, kıdem donar (fable-015)',
  `diger_maliyet` DECIMAL(12,2)         DEFAULT NULL COMMENT 'override tutar; NULL=ayar oranından (opus-014)',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_personel_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Personel gider kayıtları (maaş/prim/avans/sgk/diğer). personel_id NULL = kişiye
-- bağlı olmayan toplu gider (ör. toplu SGK). Aylık toplam finans net karlılığa yansır.
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

-- ── Faturalar (aylık müşteri faturası — production'dan üretilir) ─
-- Üret+kaydet: ara_toplam (o ay üretim tutarı) + KDV opsiyonel = genel_toplam.
-- source: 'manuel' | 'parasut' (e-fatura entegrasyonu F-sonra; alan hazır).
-- Personel aylik maas plani: calisilan gun, otomatik hesaplanan maas ve odendi durumu.
CREATE TABLE IF NOT EXISTS `personel_maas_ay` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `personel_id`    INT UNSIGNED NOT NULL,
  `ay`             CHAR(7)      NOT NULL COMMENT 'YYYY-MM',
  `calisma_gunu`   DECIMAL(5,2) NOT NULL DEFAULT 30.00,
  `maas_odendi`    TINYINT(1)   NOT NULL DEFAULT 0,
  `odeme_tarihi`   DATE                  DEFAULT NULL,
  `gider_id`       INT UNSIGNED          DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_personel_maas_ay` (`personel_id`,`ay`),
  KEY `idx_pma_ay` (`ay`),
  KEY `idx_pma_gider` (`gider_id`),
  CONSTRAINT `fk_pma_personel` FOREIGN KEY (`personel_id`) REFERENCES `personel` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pma_gider` FOREIGN KEY (`gider_id`) REFERENCES `personel_gider` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `fatura` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`  INT UNSIGNED NOT NULL,
  `ay`           CHAR(7)      NOT NULL COMMENT 'YYYY-MM',
  `ara_toplam`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `kdv_oran`     DECIMAL(5,2)  NOT NULL DEFAULT 0.00 COMMENT 'KDV %; 0 = KDV yok',
  `genel_toplam` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `durum`        ENUM('taslak','kesildi') NOT NULL DEFAULT 'taslak',
  `source`       VARCHAR(20)  NOT NULL DEFAULT 'manuel',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fatura` (`customer_id`,`ay`),
  CONSTRAINT `fk_fatura_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Denetim + oran sınırlama (v1 güvenlik desenleri) ──────────
CREATE TABLE IF NOT EXISTS `audit` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action`     VARCHAR(100)    NOT NULL,
  `actor`      VARCHAR(100)             DEFAULT NULL,
  `target_key` VARCHAR(255)             DEFAULT NULL,
  `detail`     TEXT                     DEFAULT NULL,
  `ip_addr`    VARCHAR(45)     NOT NULL DEFAULT '',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rl_key`       VARCHAR(255)    NOT NULL,
  `attempted_at` INT UNSIGNED    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rl_key_time` (`rl_key`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rate_locks` (
  `rl_key`       VARCHAR(255) NOT NULL,
  `locked_until` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`rl_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Yayınlanan menü (opus-010, müşteri-yüzü; menu_days'ten AYRI) ─
-- Admin menü oluşturur, hedef (tümü / seçili müşteriler) seçer, yayınlar.
-- Müşteri SADECE kendine yayınlanan menüyü görür (menusForCustomer scope).
CREATE TABLE IF NOT EXISTS `menu` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(150) NOT NULL,
  `date_start` DATE         NOT NULL,
  `date_end`   DATE         NOT NULL,
  `audience`   ENUM('all','selected') NOT NULL DEFAULT 'all',
  `status`     ENUM('draft','published') NOT NULL DEFAULT 'draft',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_menu_status` (`status`,`date_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `menu_item` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `menu_id`   INT UNSIGNED NOT NULL,
  `item_date` DATE         NOT NULL,
  `meal`      ENUM('sabah','ogle','aksam','gece','kumanya') NOT NULL DEFAULT 'ogle',
  `dishes`    TEXT                  DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_menu_item` (`menu_id`,`item_date`,`meal`),
  CONSTRAINT `fk_menu_item_menu` FOREIGN KEY (`menu_id`) REFERENCES `menu` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `menu_target` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `menu_id`     INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_menu_target` (`menu_id`,`customer_id`),
  KEY `idx_mt_customer` (`customer_id`),
  CONSTRAINT `fk_mt_menu` FOREIGN KEY (`menu_id`) REFERENCES `menu` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mt_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Malzeme talebi (sarf malzeme; katalog + müşteri talebi) ─────
CREATE TABLE IF NOT EXISTS `supply_item` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ad`         VARCHAR(120) NOT NULL,
  `birim`      VARCHAR(20)  NOT NULL DEFAULT 'adet',
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order` INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_supply_ad` (`ad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supply_request` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`      INT UNSIGNED NOT NULL,
  `customer_user_id` INT UNSIGNED          DEFAULT NULL,
  `request_date`     DATE         NOT NULL,
  `status`           ENUM('acik','hazirlandi','teslim') NOT NULL DEFAULT 'acik',
  `note`             VARCHAR(500)          DEFAULT NULL,
  `free_text`        VARCHAR(1000)         DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sr_customer` (`customer_id`),
  KEY `idx_sr_status` (`status`),
  CONSTRAINT `fk_sr_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supply_request_item` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`     INT UNSIGNED NOT NULL,
  `supply_item_id` INT UNSIGNED NOT NULL,
  `miktar`         DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sri` (`request_id`,`supply_item_id`),
  KEY `idx_sri_item` (`supply_item_id`),
  CONSTRAINT `fk_sri_request` FOREIGN KEY (`request_id`) REFERENCES `supply_request` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sri_item` FOREIGN KEY (`supply_item_id`) REFERENCES `supply_item` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Müşteri × malzeme standing hakediş (her müşterinin her kalemden hakkı; müşteri talepte referans görür).
CREATE TABLE IF NOT EXISTS `supply_entitlement` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`    INT UNSIGNED NOT NULL,
  `supply_item_id` INT UNSIGNED NOT NULL,
  `miktar`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entitlement` (`customer_id`,`supply_item_id`),
  KEY `idx_se_item` (`supply_item_id`),
  CONSTRAINT `fk_se_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_se_item` FOREIGN KEY (`supply_item_id`) REFERENCES `supply_item` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sarf malzeme başlangıç kataloğu (Ömer düzenler). UNIQUE(ad) → INSERT IGNORE idempotent.
INSERT IGNORE INTO `supply_item` (`ad`, `birim`, `sort_order`) VALUES
  ('Ayçiçek Yağı', 'litre', 10),
  ('Sirke', 'litre', 20),
  ('Ketçap', 'adet', 30),
  ('Mayonez', 'adet', 40),
  ('Bulaşık Deterjanı', 'litre', 50),
  ('Peçete', 'paket', 60),
  ('Tuz', 'kg', 70),
  ('Karabiber', 'paket', 80),
  ('Çöp Poşeti', 'paket', 90),
  ('Eldiven', 'kutu', 100);

-- ── Ayar (anahtar-değer): mevzuat oranları, Ömer'ce düzenlenebilir (opus-014) ─
CREATE TABLE IF NOT EXISTS `ayar` (
  `anahtar` VARCHAR(60)  NOT NULL,
  `deger`   VARCHAR(100) NOT NULL,
  PRIMARY KEY (`anahtar`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `ayar` (`anahtar`, `deger`) VALUES
  ('sgk_isveren_orani', '0.225'),
  ('kidem_tavan', '64948.77'),
  ('kidem_aylik_bolen', '12'),
  ('diger_maliyet_oran', '0'),
  ('sgk_tesvik_orani', '0.175');

-- ── Personel → müşteri dağıtım ataması (opus-014) ─
CREATE TABLE IF NOT EXISTS `personel_musteri` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `personel_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED          DEFAULT NULL,
  `genel`       TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pm` (`personel_id`,`customer_id`),
  KEY `idx_pm_personel` (`personel_id`),
  CONSTRAINT `fk_pm_personel` FOREIGN KEY (`personel_id`) REFERENCES `personel` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pm_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Push cihaz token'ları (migrate_021 + opus-021 user_id): müşteri app + admin ─
CREATE TABLE IF NOT EXISTS `push_tokens` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform`     VARCHAR(16)  NOT NULL DEFAULT 'ios',
  `token`        VARCHAR(255) NOT NULL,
  `customer_id`  INT UNSIGNED          DEFAULT NULL,
  `cuid`         INT UNSIGNED          DEFAULT NULL COMMENT 'customer_users.id',
  `user_id`      INT UNSIGNED          DEFAULT NULL COMMENT 'admin users.id (opus-021)',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_push_token` (`token`),
  KEY `idx_push_customer` (`customer_id`),
  KEY `idx_push_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Push gönderim geçmişi (opus-021): olay/manuel/hatırlatma; mükerrer koruması ref'ten ─
CREATE TABLE IF NOT EXISTS `push_log` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`        VARCHAR(20)  NOT NULL COMMENT 'menu|talep_cevap|talep_yeni|siparis|reminder|manuel',
  `customer_id` INT UNSIGNED          DEFAULT NULL,
  `user_id`     INT UNSIGNED          DEFAULT NULL,
  `ref`         VARCHAR(64)           DEFAULT NULL COMMENT 'mükerrer anahtarı, ör. menu:12',
  `title`       VARCHAR(120) NOT NULL,
  `body`        VARCHAR(500) NOT NULL,
  `sent`        INT          NOT NULL DEFAULT 0,
  `dead`        INT          NOT NULL DEFAULT 0,
  `suppressed`  TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pl_kind_cust` (`kind`, `customer_id`, `created_at`),
  KEY `idx_pl_ref` (`ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Müşteri olay akışı (fable-018): push'un kalıcı karşılığı; müşteri app "Akış" ekranı ─
CREATE TABLE IF NOT EXISTS `customer_events` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `type`        VARCHAR(32)  NOT NULL COMMENT 'menu_yayin|talep_cevap|siparis_durum|malzeme_durum',
  `title`       VARCHAR(200) NOT NULL,
  `body`        VARCHAR(300)          DEFAULT NULL,
  `url`         VARCHAR(200) NOT NULL DEFAULT '' COMMENT 'app-içi hedef, /m/... göreli',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ce_customer` (`customer_id`, `created_at`),
  CONSTRAINT `fk_ce_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `ayar` (`anahtar`, `deger`) VALUES
  ('push_quiet_start', '21:00'),
  ('push_quiet_end', '07:00');

-- ── Paraşüt e-İrsaliye kesim kaydı (fable-023b) ─
-- UNIQUE(customer_id,gun) = mükerrer kalkanının DB kilidi. Kayıt SİLİNMEZ (resmi belge izi).
-- durum 'bilinmiyor' = timeout; belge kesilmiş OLABİLİR → otomatik yeniden deneme YOK.
CREATE TABLE IF NOT EXISTS `parasut_irsaliye_log` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`    INT UNSIGNED NOT NULL,
  `gun`            DATE         NOT NULL COMMENT 'irsaliye günü (issue_date)',
  `parasut_doc_id` VARCHAR(40)           DEFAULT NULL COMMENT 'shipment_documents.id',
  `despatch_no`    VARCHAR(64)           DEFAULT NULL COMMENT 'Paraşüt otomatik seri no',
  `kalemler`       TEXT                  DEFAULT NULL COMMENT 'JSON: [{ogun,urun_id,miktar}]',
  `toplam_kisi`    INT          NOT NULL DEFAULT 0,
  `durum`          VARCHAR(16)  NOT NULL DEFAULT 'hata' COMMENT 'kesildi|hata|bilinmiyor|iptal',
  `hata_mesaj`     VARCHAR(500)          DEFAULT NULL,
  `tasiyici_ok`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'dönen belgede plaka/şoför işlendi mi',
  `gonderim`       VARCHAR(16)  NOT NULL DEFAULT 'yok' COMMENT 'gonderildi|hata|yok (fable-023d)',
  `mail`           VARCHAR(16)  NOT NULL DEFAULT 'yok' COMMENT 'gonderildi|hata|yok (fable-023e)',
  `fatura_log_id`  INT UNSIGNED          DEFAULT NULL COMMENT 'faturalanınca parasut_fatura_log.id (aday havuzundan düşer, fable-024)',
  `entered_by`     VARCHAR(64)  NOT NULL DEFAULT '',
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_irsaliye_cust_gun` (`customer_id`, `gun`),
  KEY `idx_irsaliye_gun` (`gun`),
  KEY `idx_irsaliye_fatura` (`fatura_log_id`),
  CONSTRAINT `fk_irsaliye_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Paraşüt satış faturası kesim kaydı (fable-024) ─
-- Kesilen irsaliyelerin DÖNEM toplamından (ya da aylık üretimden) satış faturası + e-Fatura.
-- UNIQUE YOK: aynı müşteriye ay içinde birden çok fatura olabilir. Kayıt SİLİNMEZ (resmi belge izi).
-- Mükerrer kalkanı: faturalanan irsaliye satırları fatura_log_id ile işaretlenir + onay imzası +
-- 'bilinmiyor' (timeout) kilidi. durum 'bilinmiyor' = belge kesilmiş OLABİLİR (retry YOK).
CREATE TABLE IF NOT EXISTS `parasut_fatura_log` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`        INT UNSIGNED NOT NULL,
  `donem_bas`          DATE         NOT NULL COMMENT 'dönem başlangıç',
  `donem_son`          DATE         NOT NULL COMMENT 'dönem bitiş (= issue_date)',
  `tip`                VARCHAR(16)  NOT NULL DEFAULT 'irsaliye' COMMENT 'irsaliye|aylik',
  `parasut_contact_id` VARCHAR(40)           DEFAULT NULL COMMENT 'faturanın kesildiği contact',
  `parasut_fatura_id`  VARCHAR(40)           DEFAULT NULL COMMENT 'sales_invoices.id',
  `fatura_no`          VARCHAR(64)           DEFAULT NULL COMMENT 'Paraşüt otomatik seri no',
  `alt_ad`             VARCHAR(120)          DEFAULT NULL COMMENT 'aylık bölüşümde alt-firma adı',
  `kalemler`           TEXT                  DEFAULT NULL COMMENT 'JSON: [{ogun,urun_id,miktar,birim_fiyat}]',
  `toplam_kisi`        INT          NOT NULL DEFAULT 0,
  `toplam_tutar`       DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'net (tahsil edilecek) = brüt + KDV − tevkifat',
  `durum`              VARCHAR(16)  NOT NULL DEFAULT 'hata' COMMENT 'kesildi|hata|bilinmiyor|iptal',
  `resmilestirme`      VARCHAR(16)  NOT NULL DEFAULT 'yok' COMMENT 'gonderildi|hata|yok (e-Fatura)',
  `mail`               VARCHAR(16)  NOT NULL DEFAULT 'yok' COMMENT 'gonderildi|hata|yok',
  `hata_mesaj`         VARCHAR(500)          DEFAULT NULL,
  `entered_by`         VARCHAR(64)  NOT NULL DEFAULT '',
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fatura_cust` (`customer_id`, `donem_son`),
  CONSTRAINT `fk_pfaturalog_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Taşıyıcı bilgisi + öğün→Paraşüt ürün eşlemesi (koda gömülmez; ayardan değişir)
INSERT IGNORE INTO `ayar` (`anahtar`, `deger`) VALUES
  ('irsaliye_plaka', '41BEM936'),
  ('irsaliye_sofor_ad', 'UFUK BALTACI'),
  ('irsaliye_sofor_tckn', '23354463864'),
  ('irsaliye_urun_ogle', '1063984872'),
  ('irsaliye_urun_aksam', '1063985050'),
  ('irsaliye_urun_kumanya', '1063985150');

-- fable-024: aylık bölüşüm contact id'leri (CANTAŞ 3 tüzel kişi) + fatura↔irsaliye bağı şalteri.
INSERT IGNORE INTO `ayar` (`anahtar`, `deger`) VALUES
  ('fatura_cantas_icdis', '1062205016'),
  ('fatura_cantas_bakir', '1062204894'),
  ('fatura_cantas_hc',    '1062205054'),
  ('fatura_irsaliye_bagla', '1');

SET FOREIGN_KEY_CHECKS = 1;
