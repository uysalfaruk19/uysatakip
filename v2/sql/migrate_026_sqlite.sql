-- fable-003: demo esinli operasyon modülleri (SQLite).
ALTER TABLE stock_moves ADD COLUMN skt TEXT;
ALTER TABLE stock_moves ADD COLUMN supplier_id INTEGER REFERENCES suppliers(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS haccp_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  log_date TEXT NOT NULL,
  kind TEXT NOT NULL CHECK(kind IN ('sicaklik','hijyen','numune','malkabul')),
  nokta TEXT NOT NULL,
  deger TEXT,
  uygun INTEGER,
  note TEXT,
  imha_at TEXT,
  created_by TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS teklif (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  firma TEXT NOT NULL,
  kisi INTEGER,
  birim_fiyat REAL,
  note TEXT,
  durum TEXT NOT NULL DEFAULT 'taslak' CHECK(durum IN ('taslak','gonderildi','kabul','red')),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS teslimat (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  teslim_date TEXT NOT NULL,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  status TEXT NOT NULL DEFAULT 'bekliyor' CHECK(status IN ('bekliyor','yolda','teslim')),
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(teslim_date, customer_id)
);
