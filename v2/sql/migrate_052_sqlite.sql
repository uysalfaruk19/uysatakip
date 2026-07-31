-- migrate_052_sqlite.sql — fable-065 SQLite eşi (test/CI; üretim MySQL migrate_052.sql).
-- Tablo + kolon schema_sqlite.sql'de de tanımlıdır (yeni DB doğrudan alır); bu dosya yalnız
-- ESKİ bir sqlite dosyasını yükseltmek içindir. "table already exists" / "duplicate column
-- name" verirse GÜVENLE yok sayılır.
--
-- ⚠️ SQLite'ta CHECK kısıtı ALTER ile değiştirilemez: eski bir sqlite dosyasında
--    parasut_fatura_log.tip CHECK'i ('irsaliye','aylik') olarak KALIR ve tip='sabit' INSERT'i
--    reddedilir. Test/CI daima schema_sqlite.sql'den TAZE DB kurar (tip'e 'sabit' dahildir),
--    bu yüzden pratikte sorun çıkmaz; eski bir dosyayı taşımak gerekirse tablo yeniden kurulur.

CREATE TABLE IF NOT EXISTS musteri_sabit_fatura (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id        INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  ad                 TEXT NOT NULL,
  parasut_product_id TEXT,
  parasut_contact_id TEXT,
  birim_fiyat        REAL NOT NULL DEFAULT 0,
  kdv_orani          REAL NOT NULL DEFAULT 20,
  aciklama           TEXT,
  aktif              INTEGER NOT NULL DEFAULT 1,
  created_at         TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(customer_id, ad)
);
CREATE INDEX IF NOT EXISTS idx_msf_cust ON musteri_sabit_fatura(customer_id, aktif, id);

ALTER TABLE parasut_fatura_log ADD COLUMN sabit_kalem_id INTEGER;
CREATE INDEX IF NOT EXISTS idx_fatura_sabit ON parasut_fatura_log(sabit_kalem_id, donem_son);
