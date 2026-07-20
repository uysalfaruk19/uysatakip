-- migrate_035_sqlite.sql — fable-024 SQLite eşi (test/CI; üretim MySQL migrate_035.sql).
-- SQLite'ta "kolon yoksa ekle" koşullu çalışmaz → kolonlar schema_sqlite.sql'de tanımlıdır;
-- buradaki ALTER yalnız ESKİ bir sqlite dosyasını yükseltmek içindir ve "duplicate column name"
-- hatası verirse GÜVENLE yok sayılır (kolon zaten var demektir).

CREATE TABLE IF NOT EXISTS parasut_fatura_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  donem_bas TEXT NOT NULL,
  donem_son TEXT NOT NULL,
  tip TEXT NOT NULL DEFAULT 'irsaliye' CHECK(tip IN ('irsaliye','aylik')),
  parasut_contact_id TEXT,
  parasut_fatura_id TEXT,
  fatura_no TEXT,
  alt_ad TEXT,
  kalemler TEXT,
  toplam_kisi INTEGER NOT NULL DEFAULT 0,
  toplam_tutar REAL NOT NULL DEFAULT 0,
  durum TEXT NOT NULL DEFAULT 'hata' CHECK(durum IN ('kesildi','hata','bilinmiyor','iptal')),
  resmilestirme TEXT NOT NULL DEFAULT 'yok' CHECK(resmilestirme IN ('gonderildi','hata','yok')),
  mail TEXT NOT NULL DEFAULT 'yok' CHECK(mail IN ('gonderildi','hata','yok')),
  hata_mesaj TEXT,
  entered_by TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_fatura_cust ON parasut_fatura_log(customer_id, donem_son);

ALTER TABLE parasut_irsaliye_log ADD COLUMN fatura_log_id INTEGER;
CREATE INDEX IF NOT EXISTS idx_irsaliye_fatura ON parasut_irsaliye_log(fatura_log_id);

ALTER TABLE customers ADD COLUMN tevkifat_kodu TEXT;
ALTER TABLE customers ADD COLUMN tevkifat_oran REAL;
ALTER TABLE customers ADD COLUMN fatura_vade_gun INTEGER NOT NULL DEFAULT 1;
ALTER TABLE customers ADD COLUMN fatura_mail TEXT;
ALTER TABLE customers ADD COLUMN fatura_bolusum TEXT;

INSERT OR IGNORE INTO ayar (anahtar, deger) VALUES
  ('fatura_cantas_icdis', '1062205016'),
  ('fatura_cantas_bakir', '1062204894'),
  ('fatura_cantas_hc',    '1062205054'),
  ('fatura_irsaliye_bagla', '1');
