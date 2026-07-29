-- migrate_049_sqlite.sql — fable-052 SQLite eşi (test/CI; üretim MySQL migrate_049.sql).
-- Tablo schema_sqlite.sql'de de tanımlıdır (yeni DB doğrudan alır); bu dosya yalnız ESKİ bir
-- sqlite dosyasını yükseltmek içindir. "table already exists" verirse GÜVENLE yok sayılır.
--
-- NOT: parasut_*_log.mail alanındaki CHECK'e 'sirada' EKLENDİ (schema_sqlite.sql'de).
-- Eski bir sqlite dosyasında CHECK değiştirilemez (tablo yeniden kurulmadan) — dev DB'de
-- 'sirada' yazımı hata verirse DB'yi baştan kurun; üretim MySQL'de böyle bir kısıt yok.

CREATE TABLE IF NOT EXISTS mail_kuyruk (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tur         TEXT NOT NULL CHECK(tur IN ('fatura','irsaliye')),
  customer_id INTEGER NOT NULL,
  kaynak_id   TEXT NOT NULL,
  belge_no    TEXT,
  gun         TEXT,
  alici       TEXT NOT NULL,
  durum       TEXT NOT NULL DEFAULT 'bekliyor' CHECK(durum IN ('bekliyor','gonderildi','hata')),
  deneme      INTEGER NOT NULL DEFAULT 0,
  son_hata    TEXT,
  gonderim_at TEXT,
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(tur, kaynak_id)
);
CREATE INDEX IF NOT EXISTS idx_mk_durum ON mail_kuyruk(durum, deneme);
CREATE INDEX IF NOT EXISTS idx_mk_cust ON mail_kuyruk(customer_id, created_at);

INSERT OR IGNORE INTO ayar (anahtar, deger) VALUES ('paylasim_yontemi', 'uysa_mail');
