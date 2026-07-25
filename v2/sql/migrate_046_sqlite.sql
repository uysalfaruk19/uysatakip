-- migrate_046_sqlite.sql — fable-047 SQLite eşi (test/CI; üretim MySQL migrate_046.sql).
-- Tablo schema_sqlite.sql'de de tanımlıdır (yeni DB doğrudan alır); bu dosya yalnız ESKİ bir
-- sqlite dosyasını yükseltmek içindir. "table already exists" verirse GÜVENLE yok sayılır.

CREATE TABLE IF NOT EXISTS resmi_tatil (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tarih      TEXT NOT NULL UNIQUE,
  ad         TEXT NOT NULL,
  tur        TEXT NOT NULL DEFAULT 'resmi' CHECK(tur IN ('resmi','dini','arefe')),
  yarim_gun  INTEGER NOT NULL DEFAULT 0,
  aktif      INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rt_aktif ON resmi_tatil(aktif, tarih);
