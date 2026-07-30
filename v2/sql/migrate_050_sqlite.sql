-- migrate_050_sqlite.sql — fable-059 SQLite eşi (test/CI; üretim MySQL migrate_050.sql).
-- Tablo schema_sqlite.sql'de de tanımlıdır (yeni DB doğrudan alır); bu dosya yalnız ESKİ bir
-- sqlite dosyasını yükseltmek içindir. "table already exists" verirse GÜVENLE yok sayılır.

CREATE TABLE IF NOT EXISTS uretim_altfirma (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER NOT NULL,
  prod_date   TEXT    NOT NULL,
  altfirma_id INTEGER NOT NULL REFERENCES musteri_altfirma(id) ON DELETE CASCADE,
  kisi        INTEGER NOT NULL,
  created_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(customer_id, prod_date, altfirma_id)
);
CREATE INDEX IF NOT EXISTS idx_ua_cust_gun ON uretim_altfirma(customer_id, prod_date);
