-- fable-018: Kokpit /m Akış feed'i (SQLite). customer_events + customer_users.feed_seen_at.
-- Bir kez uygulanır (SQLite ALTER ADD COLUMN idempotent değil — kolon varsa hata verir, zaten eklenmiştir).
CREATE TABLE IF NOT EXISTS customer_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  type TEXT NOT NULL,                 -- menu_yayin|talep_cevap|siparis_durum|malzeme_durum
  title TEXT NOT NULL,
  body TEXT,
  url TEXT NOT NULL DEFAULT '',       -- app-içi hedef, /m/... göreli
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ce_customer ON customer_events(customer_id, created_at);

ALTER TABLE customer_users ADD COLUMN feed_seen_at TEXT;
