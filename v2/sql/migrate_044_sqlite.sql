-- migrate_044_sqlite.sql — fable-045 SQLite eşi (test/CI; üretim MySQL migrate_044.sql).
-- Tablolar schema_sqlite.sql'de de tanımlıdır (yeni DB doğrudan alır); bu dosya yalnız ESKİ bir
-- sqlite dosyasını yükseltmek içindir. "table already exists" verirse GÜVENLE yok sayılır.

CREATE TABLE IF NOT EXISTS tedarikci_odeme (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tedarikci     TEXT NOT NULL,
  odeme_tarihi  TEXT NOT NULL,
  tutar         REAL NOT NULL,
  note          TEXT,
  created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_to_ted ON tedarikci_odeme(tedarikci);

CREATE TABLE IF NOT EXISTS tedarikci_devir (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tedarikci   TEXT NOT NULL UNIQUE,
  label       TEXT NOT NULL DEFAULT '',
  tutar       REAL NOT NULL DEFAULT 0,
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
