-- migrate_031_sqlite.sql — fable-023b SQLite eşi (test/CI; üretim MySQL migrate_031.sql).
-- SQLite'ta "kolon yoksa ekle" koşullu çalışmaz → irsaliye_aktif kolonu schema_sqlite.sql'de
-- tanımlıdır; buradaki ALTER yalnız ESKİ bir sqlite dosyasını yükseltmek içindir ve
-- "duplicate column name" hatası verirse GÜVENLE yok sayılır (kolon zaten var demektir).

CREATE TABLE IF NOT EXISTS parasut_irsaliye_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  gun TEXT NOT NULL,
  parasut_doc_id TEXT,
  despatch_no TEXT,
  kalemler TEXT,
  toplam_kisi INTEGER NOT NULL DEFAULT 0,
  durum TEXT NOT NULL DEFAULT 'hata' CHECK(durum IN ('kesildi','hata','bilinmiyor')),
  hata_mesaj TEXT,
  tasiyici_ok INTEGER NOT NULL DEFAULT 0,
  entered_by TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(customer_id, gun)
);
CREATE INDEX IF NOT EXISTS idx_irsaliye_gun ON parasut_irsaliye_log(gun);

ALTER TABLE customers ADD COLUMN irsaliye_aktif INTEGER NOT NULL DEFAULT 1;

INSERT OR IGNORE INTO ayar (anahtar, deger) VALUES
  ('irsaliye_plaka', '41BEM936'),
  ('irsaliye_sofor_ad', 'UFUK BALTACI'),
  ('irsaliye_sofor_tckn', '23354463864'),
  ('irsaliye_urun_ogle', '1063984872'),
  ('irsaliye_urun_aksam', '1063985050'),
  ('irsaliye_urun_kumanya', '1063985150');
