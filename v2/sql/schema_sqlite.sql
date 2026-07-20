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
  category TEXT NOT NULL DEFAULT 'uretim' CHECK(category IN ('uretim','tasima')),
  contact TEXT,
  phone TEXT,
  email TEXT,
  contract_note TEXT,
  maliyet_birim REAL NOT NULL DEFAULT 0,        -- taşıma: alış birim fiyatı (opus-013)
  tasima_sabit_gider REAL NOT NULL DEFAULT 0,   -- taşıma: aylık sabit gider (opsiyonel)
  tasima_not TEXT,                              -- taşıma: not (opsiyonel)
  parasut_id TEXT,                              -- Paraşüt contact id (opus-012, eşleşince yazılır)
  parasut_bakiye REAL,                          -- Paraşüt güncel cari bakiye (SALT-OKUMA muhasebe)
  parasut_sync_at TEXT,                         -- son Paraşüt senkron zamanı
  irsaliye_aktif INTEGER NOT NULL DEFAULT 1,    -- fable-023b: 0 = irsaliye kapsamı dışı (aylık faturadan gider)
  sevk_adres TEXT,                              -- fable-023c: irsaliye sevk adresi (kesimde dışarı sorulmaz)
  sevk_il TEXT,
  sevk_ilce TEXT,
  edespatch_alias TEXT,                         -- fable-023d: GİB e-İrsaliye alıcı kutusu (legalize 'to')
  irsaliye_mail TEXT,                           -- fable-023e: e-İrsaliye mail paylaşım adresleri (virgülle çoklu)
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_parasut_id ON customers(parasut_id);

-- ── Müşteri AY-BAZLI fiyat (opus-017): bir ayın fiyatını değiştirince o ay her yerde güncellenir. ──
-- priceFor(cid, ay) = o ay > carry-forward (önceki ay) > customers current default.
-- üretim: unit_price (kişi başı) · taşıma: unit_price=satış + maliyet_birim=alış + tasima_sabit_gider.
CREATE TABLE IF NOT EXISTS customer_price (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  ay TEXT NOT NULL,                              -- 'YYYY-MM'
  unit_price REAL NOT NULL DEFAULT 0,            -- üretim: kişi başı; taşıma: satış birim
  maliyet_birim REAL NOT NULL DEFAULT 0,         -- taşıma: alış birim (üretimde 0)
  tasima_sabit_gider REAL NOT NULL DEFAULT 0,    -- taşıma: aylık sabit gider (üretimde 0)
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(customer_id, ay)
);
CREATE INDEX IF NOT EXISTS idx_cp_cust_ay ON customer_price(customer_id, ay);

-- tasima_aylik: ATIL (opus-013'te terk; taşıma kartı customers'ta, adet production'dan)
CREATE TABLE IF NOT EXISTS tasima_aylik (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  ay TEXT NOT NULL,
  adet REAL NOT NULL DEFAULT 0,
  birim_alis REAL NOT NULL DEFAULT 0,
  birim_satis REAL NOT NULL DEFAULT 0,
  sabit_gider REAL NOT NULL DEFAULT 0,
  note TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(customer_id, ay)
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
  feed_seen_at TEXT,                        -- fable-018: Akış (customer_events) okundu kesimi; NULL=hiç açılmadı
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
  type TEXT NOT NULL DEFAULT 'talep' CHECK(type IN ('talep','sikayet','mesaj','menu','oneri')),
  subject TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'acik' CHECK(status IN ('acik','cozuldu')),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS request_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  request_id INTEGER NOT NULL REFERENCES requests(id) ON DELETE CASCADE,
  sender TEXT NOT NULL CHECK(sender IN ('musteri','uysa')),
  body TEXT NOT NULL,
  file_id INTEGER REFERENCES files(id) ON DELETE SET NULL,
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
  source TEXT NOT NULL DEFAULT 'manuel',
  -- opus-015: gider dağıtım hedefi. 'genel'=tüm müşterilere ciro oranlı; 'musteri'=transaction_customer'daki hedeflere ciro oranlı.
  alloc_type TEXT NOT NULL DEFAULT 'genel' CHECK(alloc_type IN ('genel','musteri')),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- opus-015: gider → belirli müşteri(ler) dağıtım hedefi (alloc_type='musteri' iken).
CREATE TABLE IF NOT EXISTS transaction_customer (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  transaction_id INTEGER NOT NULL REFERENCES transactions(id) ON DELETE CASCADE,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  UNIQUE(transaction_id, customer_id)
);
CREATE INDEX IF NOT EXISTS idx_tc_tx ON transaction_customer(transaction_id);

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
  min_stok REAL NOT NULL DEFAULT 0,
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

CREATE TABLE IF NOT EXISTS stock_moves (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ingredient_id INTEGER NOT NULL REFERENCES ingredients(id) ON DELETE CASCADE,
  move_date TEXT NOT NULL,
  direction TEXT NOT NULL CHECK(direction IN ('giris','cikis')),
  quantity REAL NOT NULL DEFAULT 0,
  unit TEXT NOT NULL DEFAULT 'kg',
  skt TEXT,                                                            -- fable-003: giriş SKT'si
  supplier_id INTEGER REFERENCES suppliers(id) ON DELETE SET NULL,     -- fable-003: mal kabul tedarikçisi
  note TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- fable-003: HACCP günlük kontrol kaydı (sıcaklık / hijyen / şahit numune / mal kabul)
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

-- fable-003: teklifler (müşteri adayı takibi)
CREATE TABLE IF NOT EXISTS teklif (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  firma TEXT NOT NULL,
  yetkili TEXT,
  telefon TEXT,
  email TEXT,
  kisi INTEGER,
  ogun_sayisi INTEGER NOT NULL DEFAULT 1,
  cumartesi INTEGER NOT NULL DEFAULT 0,
  sehir TEXT,
  ilce TEXT,
  segment TEXT CHECK(segment IN ('ekonomik','genel','premium')),
  menu_json TEXT,
  personel_json TEXT,
  ekipman TEXT,
  birim_fiyat REAL,
  fiyat_json TEXT,
  giris_metni TEXT,
  note TEXT,
  durum TEXT NOT NULL DEFAULT 'taslak' CHECK(durum IN ('taslak','gonderildi','kabul','red')),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- fable-003: teslimat/sevkiyat durumu (gün × müşteri)
CREATE TABLE IF NOT EXISTS teslimat (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  teslim_date TEXT NOT NULL,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  status TEXT NOT NULL DEFAULT 'bekliyor' CHECK(status IN ('bekliyor','yolda','teslim')),
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(teslim_date, customer_id)
);

CREATE TABLE IF NOT EXISTS personel (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ad TEXT NOT NULL,
  gorev TEXT,
  aylik_ucret REAL NOT NULL DEFAULT 0,     -- brüt aylık ücret TL
  ise_giris TEXT,                          -- kıdem başlangıcı (YYYY-MM-DD), NULL=bilinmiyor (opus-014)
  ise_cikis TEXT,                          -- işten çıkış (YYYY-MM-DD); dolu=pasif, o ay kıst maaş, kıdem donar (fable-015)
  diger_maliyet REAL,                      -- override tutar TL; NULL=ayar diger_maliyet_oran'dan (opus-014)
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS personel_gider (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  personel_id INTEGER REFERENCES personel(id) ON DELETE SET NULL,
  tarih TEXT NOT NULL,
  tur TEXT NOT NULL DEFAULT 'maas' CHECK(tur IN ('maas','prim','avans','sgk','diger')),
  tutar REAL NOT NULL DEFAULT 0,
  aciklama TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS personel_maas_ay (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  personel_id INTEGER NOT NULL REFERENCES personel(id) ON DELETE CASCADE,
  ay TEXT NOT NULL,
  calisma_gunu REAL NOT NULL DEFAULT 30,
  maas_odendi INTEGER NOT NULL DEFAULT 0,
  odeme_tarihi TEXT,
  gider_id INTEGER REFERENCES personel_gider(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(personel_id, ay)
);
CREATE INDEX IF NOT EXISTS idx_pma_ay ON personel_maas_ay(ay);
CREATE INDEX IF NOT EXISTS idx_pma_gider ON personel_maas_ay(gider_id);
CREATE TABLE IF NOT EXISTS fatura (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  ay TEXT NOT NULL,
  ara_toplam REAL NOT NULL DEFAULT 0,
  kdv_oran REAL NOT NULL DEFAULT 0,
  genel_toplam REAL NOT NULL DEFAULT 0,
  durum TEXT NOT NULL DEFAULT 'taslak' CHECK(durum IN ('taslak','kesildi')),
  source TEXT NOT NULL DEFAULT 'manuel',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(customer_id, ay)
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

-- ── Yayınlanan menü (opus-010, müşteri-yüzü; menu_days'ten AYRI) ──
CREATE TABLE IF NOT EXISTS menu (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  date_start TEXT NOT NULL,
  date_end TEXT NOT NULL,
  audience TEXT NOT NULL DEFAULT 'all' CHECK(audience IN ('all','selected')),
  status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published')),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS menu_item (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  menu_id INTEGER NOT NULL REFERENCES menu(id) ON DELETE CASCADE,
  item_date TEXT NOT NULL,
  meal TEXT NOT NULL DEFAULT 'ogle' CHECK(meal IN ('sabah','ogle','aksam','gece','kumanya')),
  dishes TEXT,
  UNIQUE(menu_id, item_date, meal)
);

CREATE TABLE IF NOT EXISTS menu_target (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  menu_id INTEGER NOT NULL REFERENCES menu(id) ON DELETE CASCADE,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  UNIQUE(menu_id, customer_id)
);

-- ── Malzeme talebi (sarf malzeme; katalog + müşteri talebi) ──
CREATE TABLE IF NOT EXISTS supply_item (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ad TEXT NOT NULL UNIQUE,
  birim TEXT NOT NULL DEFAULT 'adet',
  is_active INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS supply_request (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  customer_user_id INTEGER,
  request_date TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'acik' CHECK(status IN ('acik','hazirlandi','teslim')),
  note TEXT,
  free_text TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS supply_request_item (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  request_id INTEGER NOT NULL REFERENCES supply_request(id) ON DELETE CASCADE,
  supply_item_id INTEGER NOT NULL REFERENCES supply_item(id),
  miktar REAL NOT NULL DEFAULT 1,
  UNIQUE(request_id, supply_item_id)
);

-- Müşteri × malzeme standing hakediş (her müşterinin her kalemden hakkı).
CREATE TABLE IF NOT EXISTS supply_entitlement (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  supply_item_id INTEGER NOT NULL REFERENCES supply_item(id) ON DELETE CASCADE,
  miktar REAL NOT NULL DEFAULT 0,
  UNIQUE(customer_id, supply_item_id)
);

-- Sarf malzeme başlangıç kataloğu (Ömer düzenler). Boşsa seed (idempotent).
INSERT OR IGNORE INTO supply_item (ad, birim, sort_order) VALUES
  ('Ayçiçek Yağı', 'litre', 10),
  ('Sirke', 'litre', 20),
  ('Ketçap', 'adet', 30),
  ('Mayonez', 'adet', 40),
  ('Bulaşık Deterjanı', 'litre', 50),
  ('Peçete', 'paket', 60),
  ('Tuz', 'kg', 70),
  ('Karabiber', 'paket', 80),
  ('Çöp Poşeti', 'paket', 90),
  ('Eldiven', 'kutu', 100);

-- ── Ayar (anahtar-değer): mevzuat oranları, hepsi Ömer'ce düzenlenebilir (opus-014) ─
CREATE TABLE IF NOT EXISTS ayar (
  anahtar TEXT PRIMARY KEY,
  deger TEXT NOT NULL
);
INSERT OR IGNORE INTO ayar (anahtar, deger) VALUES
  ('sgk_isveren_orani', '0.225'),   -- işveren SGK payı (SGK+kısa vade+işsizlik), teşviksiz
  ('kidem_tavan', '64948.77'),      -- 2026 H1 kıdem tazminatı aylık tavanı TL
  ('kidem_aylik_bolen', '12'),      -- yılda 30 gün → aylık tahakkuk = min(brüt,tavan)/12
  ('diger_maliyet_oran', '0'),      -- yemek/yol/ikramiye default oran (Ömer girer)
  ('sgk_tesvik_orani', '0.175');    -- %5 teşvikli işveren SGK (bilgi amaçlı)

-- ── Personel → müşteri dağıtım ataması (opus-014) ─
-- genel=1 → o personelin yüklü maliyeti tüm üretim müşterilerine HACME oranlı dağıtılır (customer_id NULL).
-- genel=0 → her satır bir müşteri; birden fazla müşteriye EŞİT bölünür.
CREATE TABLE IF NOT EXISTS personel_musteri (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  personel_id INTEGER NOT NULL REFERENCES personel(id) ON DELETE CASCADE,
  customer_id INTEGER REFERENCES customers(id) ON DELETE CASCADE,
  genel INTEGER NOT NULL DEFAULT 0,
  UNIQUE(personel_id, customer_id)
);
CREATE INDEX IF NOT EXISTS idx_pm_personel ON personel_musteri(personel_id);

-- ── Push cihaz token'ları (migrate_021 + opus-021 user_id): müşteri app + admin ─
CREATE TABLE IF NOT EXISTS push_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  platform TEXT NOT NULL DEFAULT 'ios',
  token TEXT NOT NULL UNIQUE,
  customer_id INTEGER DEFAULT NULL,
  cuid INTEGER DEFAULT NULL,          -- customer_users.id
  user_id INTEGER DEFAULT NULL,       -- admin users.id (opus-021)
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_push_customer ON push_tokens(customer_id);
CREATE INDEX IF NOT EXISTS idx_push_user ON push_tokens(user_id);

-- ── Push gönderim geçmişi (opus-021): olay/manuel/hatırlatma; mükerrer koruması ref'ten ─
CREATE TABLE IF NOT EXISTS push_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind TEXT NOT NULL,                 -- menu|talep_cevap|talep_yeni|siparis|reminder|manuel
  customer_id INTEGER DEFAULT NULL,
  user_id INTEGER DEFAULT NULL,
  ref TEXT DEFAULT NULL,              -- mükerrer anahtarı, ör. menu:12
  title TEXT NOT NULL,
  body TEXT NOT NULL,
  sent INTEGER NOT NULL DEFAULT 0,
  dead INTEGER NOT NULL DEFAULT 0,
  suppressed INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_pl_kind_cust ON push_log(kind, customer_id, created_at);
CREATE INDEX IF NOT EXISTS idx_pl_ref ON push_log(ref);

-- ── Müşteri olay akışı (fable-018): push'un kalıcı karşılığı; müşteri app "Akış" ekranı ─
-- Push atılamasa bile (sessiz saat / token yok) olay buraya yazılır; badge tek gerçek kaynak.
CREATE TABLE IF NOT EXISTS customer_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  type TEXT NOT NULL,                 -- menu_yayin|talep_cevap|siparis_durum|malzeme_durum
  title TEXT NOT NULL,
  body TEXT,
  url TEXT NOT NULL DEFAULT '',       -- app-içi hedef, /m/... göreli (deep-link)
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ce_customer ON customer_events(customer_id, created_at);

INSERT OR IGNORE INTO ayar (anahtar, deger) VALUES
  ('push_quiet_start', '21:00'),
  ('push_quiet_end', '07:00');

-- ── Paraşüt e-İrsaliye kesim kaydı (fable-023b) ─
-- Her (müşteri, gün) için TEK satır: UNIQUE = mükerrer kalkanının DB kilidi.
-- Kayıt SİLİNMEZ (kesilen belge resmi e-İrsaliye, GİB'e gider — iz kalıcı).
-- durum 'bilinmiyor' = timeout; belge kesilmiş OLABİLİR → otomatik yeniden deneme YOK.
CREATE TABLE IF NOT EXISTS parasut_irsaliye_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  gun TEXT NOT NULL,                       -- 'YYYY-MM-DD' (issue_date)
  parasut_doc_id TEXT,                     -- shipment_documents.id
  despatch_no TEXT,                        -- Paraşüt otomatik seri no
  kalemler TEXT,                           -- JSON: [{ogun,urun_id,miktar}]
  toplam_kisi INTEGER NOT NULL DEFAULT 0,
  durum TEXT NOT NULL DEFAULT 'hata' CHECK(durum IN ('kesildi','hata','bilinmiyor','iptal')),
  hata_mesaj TEXT,
  tasiyici_ok INTEGER NOT NULL DEFAULT 0,  -- dönen belgede plaka/şoför işlendi mi
  gonderim TEXT NOT NULL DEFAULT 'yok' CHECK(gonderim IN ('gonderildi','hata','yok')), -- fable-023d
  mail TEXT NOT NULL DEFAULT 'yok' CHECK(mail IN ('gonderildi','hata','yok')),         -- fable-023e
  entered_by TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(customer_id, gun)
);
CREATE INDEX IF NOT EXISTS idx_irsaliye_gun ON parasut_irsaliye_log(gun);

-- Taşıyıcı bilgisi + öğün→Paraşüt ürün eşlemesi (koda gömülmez; ayardan değişir)
INSERT OR IGNORE INTO ayar (anahtar, deger) VALUES
  ('irsaliye_plaka', '41BEM936'),
  ('irsaliye_sofor_ad', 'UFUK BALTACI'),
  ('irsaliye_sofor_tckn', '23354463864'),
  ('irsaliye_urun_ogle', '1063984872'),
  ('irsaliye_urun_aksam', '1063985050'),
  ('irsaliye_urun_kumanya', '1063985150');
