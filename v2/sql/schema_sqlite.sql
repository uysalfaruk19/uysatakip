-- UYSA Kokpit v2 — SQLite şeması (SADECE test/CI için).
-- Üretim şeması sql/schema_v2.sql (MySQL utf8mb4). Bu dosya onunla eş tutulur.
-- ENUM -> TEXT + CHECK; AUTO_INCREMENT -> INTEGER PRIMARY KEY AUTOINCREMENT.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  password TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'user' CHECK(role IN ('superadmin','editor','user','viewer')),
  display_name TEXT,
  last_login TEXT,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS customers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  unit_price REAL NOT NULL DEFAULT 0,
  contact TEXT,
  phone TEXT,
  contract_note TEXT,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS customer_users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  username TEXT NOT NULL UNIQUE,
  password_bcrypt TEXT NOT NULL,
  display_name TEXT,
  phone TEXT,
  role TEXT NOT NULL DEFAULT 'owner' CHECK(role IN ('owner','staff')),
  is_active INTEGER NOT NULL DEFAULT 1,
  last_login TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  order_date TEXT NOT NULL,
  meal TEXT NOT NULL DEFAULT 'ogle' CHECK(meal IN ('sabah','ogle','aksam','gece','kumanya')),
  persons INTEGER NOT NULL DEFAULT 0,
  menu_type TEXT,
  status TEXT NOT NULL DEFAULT 'gonderildi' CHECK(status IN ('taslak','gonderildi','onaylandi','reddedildi')),
  entered_by TEXT NOT NULL DEFAULT 'uysa' CHECK(entered_by IN ('musteri','uysa','bot')),
  note TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(customer_id, order_date, meal)
);

CREATE TABLE IF NOT EXISTS production (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  prod_date TEXT NOT NULL,
  meal TEXT NOT NULL DEFAULT 'ogle' CHECK(meal IN ('sabah','ogle','aksam','gece','kumanya')),
  persons INTEGER NOT NULL DEFAULT 0,
  unit_price_snap REAL NOT NULL DEFAULT 0,
  amount REAL NOT NULL DEFAULT 0,
  order_id INTEGER REFERENCES orders(id) ON DELETE SET NULL,
  note TEXT,
  entered_by TEXT NOT NULL DEFAULT 'uysa' CHECK(entered_by IN ('musteri','uysa','bot')),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(customer_id, prod_date, meal)
);

CREATE TABLE IF NOT EXISTS requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  customer_user_id INTEGER,
  type TEXT NOT NULL DEFAULT 'talep' CHECK(type IN ('talep','sikayet','mesaj')),
  subject TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'acik' CHECK(status IN ('acik','cozuldu')),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS request_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  request_id INTEGER NOT NULL REFERENCES requests(id) ON DELETE CASCADE,
  sender TEXT NOT NULL CHECK(sender IN ('musteri','uysa')),
  body TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS announcements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  body TEXT NOT NULL,
  publish_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  audience TEXT NOT NULL DEFAULT 'hepsi'
);

CREATE TABLE IF NOT EXISTS files (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  filename TEXT NOT NULL,
  original TEXT NOT NULL,
  mime TEXT NOT NULL,
  size_bytes INTEGER NOT NULL DEFAULT 0,
  uploaded_by TEXT,
  category TEXT,
  deleted_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS suppliers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  contact TEXT,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL CHECK(type IN ('gelir','gider')),
  category TEXT,
  tx_date TEXT NOT NULL,
  amount REAL NOT NULL DEFAULT 0,
  customer_id INTEGER REFERENCES customers(id) ON DELETE SET NULL,
  supplier_id INTEGER REFERENCES suppliers(id) ON DELETE SET NULL,
  description TEXT,
  file_id INTEGER REFERENCES files(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cari_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  party_type TEXT NOT NULL CHECK(party_type IN ('customer','supplier')),
  party_id INTEGER NOT NULL,
  entry_date TEXT NOT NULL,
  direction TEXT NOT NULL CHECK(direction IN ('borc','alacak')),
  amount REAL NOT NULL DEFAULT 0,
  note TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ingredients (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  unit TEXT NOT NULL DEFAULT 'kg',
  price_per_unit REAL NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS recipes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  category TEXT,
  portion_note TEXT
);

CREATE TABLE IF NOT EXISTS recipe_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  recipe_id INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
  ingredient_id INTEGER NOT NULL REFERENCES ingredients(id) ON DELETE CASCADE,
  grams REAL NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS menu_days (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  menu_date TEXT NOT NULL,
  meal TEXT NOT NULL DEFAULT 'ogle' CHECK(meal IN ('sabah','ogle','aksam','gece','kumanya')),
  menu_type TEXT NOT NULL DEFAULT 'standart',
  recipe_id INTEGER REFERENCES recipes(id) ON DELETE SET NULL,
  is_published INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS audit (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  action TEXT NOT NULL,
  actor TEXT,
  target_key TEXT,
  detail TEXT,
  ip_addr TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rate_limits (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  rl_key TEXT NOT NULL,
  attempted_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS rate_locks (
  rl_key TEXT PRIMARY KEY,
  locked_until INTEGER NOT NULL
);
