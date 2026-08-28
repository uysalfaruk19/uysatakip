-- fable-091 (Ömer, 28 Ağu): "fatura otomatik kesim kuralım... irsaliyesiz olan faturalara
-- ekstra eklenecek alan kurgula, ay içinde ekstralara yazayım; CANTAŞ hariç diğerlerini de
-- ay sonu otomatik kesmiş olursun."
--
-- 1) Müşteri bazlı otomatik kesim şalteri. CANTAŞ (3 ayrı e-Faturaya bölünüyor) HARİÇ tutulur;
--    ileride başka müşteri çıkarmak koda değil bu kolona dokunmakla olur.
ALTER TABLE customers ADD COLUMN fatura_oto_kesim TINYINT(1) NOT NULL DEFAULT 1;
UPDATE customers SET fatura_oto_kesim = 0 WHERE name LIKE '%CANTA%';

-- 2) Ekstra kalem: irsaliyesiz (aylık) müşterilerin ay içinde biriken tek seferlik kalemleri.
--    Ay sonu faturasına EK SATIR olarak girer. fatura_log_id dolduğu an bir daha faturalanmaz
--    (mükerrer kalkanı — sabit kalemdeki "aynı ay ikinci kez kesilmez" kuralının eşi).
CREATE TABLE IF NOT EXISTS musteri_ekstra_kalem (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id        INT UNSIGNED NOT NULL,
  ay                 CHAR(7)      NOT NULL,               -- YYYY-MM
  ad                 VARCHAR(120) NOT NULL,
  adet               DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  birim_fiyat        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  kdv_orani          DECIMAL(5,2)  NOT NULL DEFAULT 10.00,
  parasut_product_id VARCHAR(48)  NULL DEFAULT NULL,      -- boşsa ayar.ekstra_urun_id
  aciklama           VARCHAR(200) NULL DEFAULT NULL,
  fatura_log_id      INT UNSIGNED NULL DEFAULT NULL,      -- NULL = henüz faturalanmadı
  entered_by         VARCHAR(64)  NULL DEFAULT NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_ekstra_musteri_ay (customer_id, ay),
  KEY ix_ekstra_faturasiz (fatura_log_id),
  CONSTRAINT fk_ekstra_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Şalterler. ekstra_urun_id BOŞ bırakılıyor: Paraşüt kalemsiz fatura kabul etmiyor, ürün
--    Ömer tarafından bir kez seçilecek (Fatura ekranından). Boşken ekstra kalem faturaya
--    EKLENMEZ ve uyarı verilir — sessizce düşmez.
INSERT IGNORE INTO ayar (anahtar, deger) VALUES
  ('fatura_oto_kesim', '1'),
  ('ekstra_urun_id',   '');
