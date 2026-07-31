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
  tevkifat_kodu TEXT,                           -- fable-024: KDV tevkifat kodu (dolu=tevkifatlı, örn '604'); boş=tevkifat yok
  tevkifat_oran REAL,                           -- fable-024: tevkifat oranı (KDV'nin %'si, örn 50.00)
  fatura_vade_gun INTEGER NOT NULL DEFAULT 1,   -- fable-024: fatura vadesi = issue + N gün (PENDORYA 7)
  fatura_mail TEXT,                             -- fable-024: fatura mail paylaşım adresleri (virgülle çoklu; boş başlar)
  fatura_bolusum TEXT,                          -- fable-024: aylık faturayı N contact'a bölme config'i (JSON [{key,ad}]; key=ayar contact id anahtarı). CANTAŞ 3'lü; boş=tek fatura
  fatura_kisi_haftaici INTEGER DEFAULT NULL,    -- fable-040: hafta içi SABİT fatura kişisi (üretim ≠ fatura; NULL=kural yok, ciro=üretim). CANTAŞ 50 üretim/70 fatura. Cmt-Paz uygulanmaz.
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

-- ── fable-051: ALT FİRMA bölüşüm deseni (MySQL eşi migrate_048.sql) ────────────────────
-- Bir müşterinin faturası N ayrı ŞİRKETE kesiliyorsa (CANTAŞ = HC Isıtma · İç-Dış · Bakır)
-- fatura kesiminde 3'lü bölüşüm bu DESENDEN gün gün hesaplanır (günlük giriş ekranı YOK).
-- Desen: hafta içi sabit kotalı firmalar (haftaici_sabit) sıra ile pay alır, KALAN varsayılana;
--        cumartesi/pazar TAMAMI varsayılana (Ömer kuralı). Hesap FATURA kişisi üzerinden.
-- haftaici_sabit NULL = kalanı alan firma (varsayılan). Değerler burada → koda gömülü değil.
-- kod = customers.fatura_bolusum JSON'daki key ile aynı → bölüşüm kutuları otomatik dolar.
-- SİLME YOK: aktif=0 ile pasifleşir (geçmiş fatura izi bozulmaz).
CREATE TABLE IF NOT EXISTS musteri_altfirma (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id        INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  kod                TEXT NOT NULL,                   -- ör. 'fatura_cantas_hc'
  ad                 TEXT NOT NULL,                   -- ör. 'HC Isıtma'
  parasut_contact_id TEXT,                            -- boşsa ayar tablosundaki key'den çözülür
  varsayilan         INTEGER NOT NULL DEFAULT 0,      -- 1 = kalan + hafta sonu bu firmaya (CANTAŞ→HC)
  haftaici_sabit     INTEGER,                         -- hafta içi günlük sabit kota (NULL = kalanı alır)
  sira               INTEGER NOT NULL DEFAULT 0,
  aktif              INTEGER NOT NULL DEFAULT 1,
  created_at         TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(customer_id, kod)
);
CREATE INDEX IF NOT EXISTS idx_maf_cust ON musteri_altfirma(customer_id, aktif, sira);

-- fable-059: İSTİSNA günlerde firma kırılımı ELLE girilir (15 Tem resmi tatili gibi günlerde
-- desen bilemez: o günün 36 kişisi hangi şirkete ait?). YALNIZ elle girilen günler yazılır —
-- kaydı olmayan gün desenle hesaplanır. Toplam, günün FATURA kişisine eşit olmak ZORUNDA
-- (aksi hâlde kayıt reddedilir); tamamı 0 girilirse satırlar silinir → gün desene döner.
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

-- ── fable-065: SABİT AYLIK FATURA KALEMİ (MySQL eşi migrate_052.sql) ──────────────────
-- Yemek faturasından AYRI, üretimden BAĞIMSIZ, her ay AYNI tutarda kesilen kalem
-- (BOMİ → "PERSONEL HİZMET"; ileride kira/hizmet bedeli de olabilir). GENEL özellik:
-- kalem tanımlanmamış müşteride hiçbir davranış değişmez.
-- KDV kalemin KENDİ oranından (hizmet %20 — yemek %10 DEĞİL). SİLME YOK: aktif=0.
-- Aynı ay + aynı kalem iki kez kesilemez → kalkan parasut_fatura_log(tip='sabit', sabit_kalem_id).
CREATE TABLE IF NOT EXISTS musteri_sabit_fatura (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id        INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  ad                 TEXT NOT NULL,                   -- ör. 'Personel hizmeti'
  parasut_product_id TEXT,                            -- Paraşüt ürün id (ör. PERSONEL HİZMET)
  parasut_contact_id TEXT,                            -- boşsa müşterinin KENDİ carisi kullanılır
  birim_fiyat        REAL NOT NULL DEFAULT 0,         -- 1 adet × bu tutar (KDV hariç)
  kdv_orani          REAL NOT NULL DEFAULT 20,
  aciklama           TEXT,
  aktif              INTEGER NOT NULL DEFAULT 1,
  created_at         TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(customer_id, ad)
);
CREATE INDEX IF NOT EXISTS idx_msf_cust ON musteri_sabit_fatura(customer_id, aktif, id);

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
  parasut_id TEXT UNIQUE,                       -- fable-030: Paraşüt fatura id (ei- önekli=gelen kutusu; mükerrer kalkanı)
  qty REAL,                                     -- fable-031b: fatura satır adedi (taşıma alış birimi için)
  net_amount REAL,                              -- fable-031b: KDV hariç tutar
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
  ('sgk_tesvik_orani', '0.175'),    -- %5 teşvikli işveren SGK (bilgi amaçlı)
  ('kapanis_sapma_esik', '40');     -- fable-047: ay kapanışı anormal sayı sapma eşiği (%)

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

-- ── fable-035: Tedarikçi/Personel → müşteri MALİYET EŞLEŞTİRMESİ ──────────
-- Eşleşen tedarikçi faturaları / personel yüklü maliyeti seçili müşterilere o ayki KİŞİ
-- oranında dağılır; tablolar boşken tüm dağıtım eskisiyle birebir (regresyon garantisi).
CREATE TABLE IF NOT EXISTS tedarikci_musteri_map (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tedarikci TEXT NOT NULL,                    -- normalize firma anahtarı (Repo::normTedarikci)
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(tedarikci, customer_id)
);
CREATE INDEX IF NOT EXISTS idx_tmm_ted ON tedarikci_musteri_map(tedarikci);

CREATE TABLE IF NOT EXISTS personel_musteri_map (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  personel_id INTEGER NOT NULL REFERENCES personel(id) ON DELETE CASCADE,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(personel_id, customer_id)
);
CREATE INDEX IF NOT EXISTS idx_pmm_pers ON personel_musteri_map(personel_id);

-- ── fable-037: FATURA → müşteri MALİYET EŞLEŞTİRMESİ ─────────────────────
-- Tek bir gider faturası (tx), tedarikçi eşleşmesinden FARKLI müşterilere kişi oranında
-- dağıtılabilsin; en ÜST öncelik katmanı. Tablo boşken tüm dağıtım fable-035 ile birebir.
CREATE TABLE IF NOT EXISTS fatura_musteri_map (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tx_id INTEGER NOT NULL REFERENCES transactions(id) ON DELETE CASCADE,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(tx_id, customer_id)
);
CREATE INDEX IF NOT EXISTS idx_fmm_tx ON fatura_musteri_map(tx_id);

-- ── fable-039: Kişi başı GIDA MALİYETİ kırılımları ──────────────────────
-- Gıda alım faturaları tedarikçi bazında kırılıma eşlenir; eşleşmeyen tedarikçi gıda
-- costuna GİRMEZ (görünüm katmanı — karAnalizi/netKarlilik sayıları değişmez). Parametrik.
CREATE TABLE IF NOT EXISTS gida_kirilim (
  id   INTEGER PRIMARY KEY AUTOINCREMENT,
  kod  TEXT NOT NULL UNIQUE,               -- stabil kod (kuru_gida, kirmizi_et, ...)
  ad   TEXT NOT NULL,                      -- Türkçe görünen ad
  sira INTEGER NOT NULL DEFAULT 0
);
INSERT OR IGNORE INTO gida_kirilim (kod, ad, sira) VALUES
  ('kuru_gida', 'Kuru gıda', 1),
  ('kirmizi_et', 'Kırmızı et', 2),
  ('hal', 'Hal (sebze-meyve)', 3),
  ('beyaz_et', 'Beyaz et', 4),
  ('ekmek', 'Ekmek', 5),
  ('tatli', 'Tatlı', 6);

CREATE TABLE IF NOT EXISTS tedarikci_gida_map (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tedarikci   TEXT NOT NULL UNIQUE,        -- normalize firma anahtarı (Repo::normTedarikci)
  kirilim_kod TEXT REFERENCES gida_kirilim(kod) ON DELETE SET NULL ON UPDATE CASCADE, -- NULL/satır yok = gıda costu DIŞI
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tgm_kod ON tedarikci_gida_map(kirilim_kod);

-- fable-044: gider faturalarının ÜRÜN SATIRLARI (top-10 harcama + birim fiyat).
-- tx_id → transactions(id) ON DELETE CASCADE. Kaynak: Paraşüt UBL senkron VEYA CSV/elle yükleme.
CREATE TABLE IF NOT EXISTS gider_kalem (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tx_id       INTEGER NOT NULL REFERENCES transactions(id) ON DELETE CASCADE,
  urun        TEXT NOT NULL,
  miktar      REAL,                             -- NULL = miktar bilinmiyor (birim fiyat çıkmaz)
  birim       TEXT,                             -- KG / ADET / LT ... (UBL unitCode)
  birim_fiyat REAL,                             -- fatura satırındaki birim fiyat (bilgi amaçlı)
  tutar       REAL NOT NULL,                    -- KDV hariç satır tutarı (LineExtensionAmount)
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_gk_tx ON gider_kalem(tx_id);

-- ── fable-045: BORÇLARIM — tedarikçi bazlı borç takibi (AYDAN BAĞIMSIZ, kümülatif) ──────
-- Borç(tedarikçi) = devir(elle bir kere) + Σ(tüm zaman gider faturaları) − Σ(ödemeler).
-- Anahtar = Repo::normTedarikci(txFirma) — gider tx'leri, ödemeler ve devir AYNI anahtarda hizalanır.
-- Kayıt SİLİNMEZ: kısmi/tam ödeme = kayıt; düzeltme = NEGATİF tutarlı yeni kayıt (audit izi kalır).
CREATE TABLE IF NOT EXISTS tedarikci_odeme (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tedarikci     TEXT NOT NULL,                     -- normalize firma anahtarı (Repo::normTedarikci)
  odeme_tarihi  TEXT NOT NULL,                     -- 'YYYY-MM-DD'
  tutar         REAL NOT NULL,                     -- + ödeme / − düzeltme (silme yok)
  note          TEXT,                              -- ör. havale/nakit (MySQL 'not' rezerve → 'note')
  created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_to_ted ON tedarikci_odeme(tedarikci);

-- Tedarikçi başına OPSİYONEL devir bakiyesi (eski/sistemde olmayan faturaların açılış borcu;
-- elle bir kere girilir, güncellenebilir). tedarikci UNIQUE → tek satır/tedarikçi (upsert).
CREATE TABLE IF NOT EXISTS tedarikci_devir (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tedarikci   TEXT NOT NULL UNIQUE,                -- normalize firma anahtarı (Repo::normTedarikci)
  label       TEXT NOT NULL DEFAULT '',            -- görünen ad (faturasız/ödemesiz tedarikçi için)
  tutar       REAL NOT NULL DEFAULT 0,             -- açılış borcu
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── fable-047: RESMİ TATİL takvimi ─────────────────────────────────────────────────────
-- Tatil öncesi 3 gün uyarı (tools/tatil_uyari.php) + ay kapanışı "tatil davranışı" analizi.
-- tur: 'resmi' | 'dini' | 'arefe'; yarim_gun=1 → tam tatil sayılmaz (sayı girilir ama düşük).
-- SİLME YOK — aktif=0 ile pasifleşir (geçmiş tatil karşılaştırması bozulmasın).
CREATE TABLE IF NOT EXISTS resmi_tatil (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tarih      TEXT NOT NULL UNIQUE,               -- 'YYYY-MM-DD'
  ad         TEXT NOT NULL,
  tur        TEXT NOT NULL DEFAULT 'resmi' CHECK(tur IN ('resmi','dini','arefe')),
  yarim_gun  INTEGER NOT NULL DEFAULT 0,
  aktif      INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rt_aktif ON resmi_tatil(aktif, tarih);

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
  mail TEXT NOT NULL DEFAULT 'yok' CHECK(mail IN ('gonderildi','sirada','hata','yok')), -- fable-023e (+052 sirada)
  fatura_log_id INTEGER REFERENCES parasut_fatura_log(id) ON DELETE SET NULL, -- fable-024: faturalanınca işaretlenir → aday listeden düşer
  entered_by TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(customer_id, gun)
);
CREATE INDEX IF NOT EXISTS idx_irsaliye_gun ON parasut_irsaliye_log(gun);
CREATE INDEX IF NOT EXISTS idx_irsaliye_fatura ON parasut_irsaliye_log(fatura_log_id);

-- ── Paraşüt satış faturası kesim kaydı (fable-024) ─
-- Kesilen irsaliyelerin DÖNEM toplamından tek satış faturası + e-Fatura resmileştirme.
-- UNIQUE YOK: aynı müşteriye ay içinde birden çok fatura olabilir. Kayıt SİLİNMEZ (resmi belge izi).
-- Mükerrer kalkanı: faturalanan irsaliye satırları fatura_log_id ile işaretlenir (aday havuzundan düşer)
-- + onay imzası + 'bilinmiyor' (timeout) kilidi. durum 'bilinmiyor' = belge kesilmiş OLABİLİR.
CREATE TABLE IF NOT EXISTS parasut_fatura_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  donem_bas TEXT NOT NULL,                  -- 'YYYY-MM-DD' dönem başlangıç
  donem_son TEXT NOT NULL,                  -- 'YYYY-MM-DD' dönem bitiş (= issue_date)
  -- fable-065: 'sabit' = üretimden bağımsız, her ay aynı tutarlı kalem (musteri_sabit_fatura)
  tip TEXT NOT NULL DEFAULT 'irsaliye' CHECK(tip IN ('irsaliye','aylik','sabit')),
  sabit_kalem_id INTEGER,                   -- tip='sabit' ise musteri_sabit_fatura.id (mükerrer kalkanı)
  parasut_contact_id TEXT,                  -- faturanın kesildiği contact (irsaliyeli=customers.parasut_id; aylık bölüşümde alt-firma)
  parasut_fatura_id TEXT,                   -- sales_invoices.id
  fatura_no TEXT,                           -- Paraşüt otomatik seri no (UY...)
  alt_ad TEXT,                              -- aylık bölüşümde alt-firma adı (İç-Dış / HC / Bakır)
  kalemler TEXT,                            -- JSON: [{ogun,urun_id,miktar,birim_fiyat}]
  toplam_kisi INTEGER NOT NULL DEFAULT 0,
  toplam_tutar REAL NOT NULL DEFAULT 0,     -- net (tahsil edilecek) = brüt + KDV − tevkifat
  durum TEXT NOT NULL DEFAULT 'hata' CHECK(durum IN ('kesildi','hata','bilinmiyor','iptal')),
  resmilestirme TEXT NOT NULL DEFAULT 'yok' CHECK(resmilestirme IN ('gonderildi','hata','yok')), -- e-Fatura
  mail TEXT NOT NULL DEFAULT 'yok' CHECK(mail IN ('gonderildi','sirada','hata','yok')),
  hata_mesaj TEXT,
  entered_by TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_fatura_cust ON parasut_fatura_log(customer_id, donem_son);

-- fable-024: aylık bölüşüm contact id'leri (CANTAŞ 3 tüzel kişi — customers'ta YOK, koda gömülmez).
INSERT OR IGNORE INTO ayar (anahtar, deger) VALUES
  ('fatura_cantas_icdis', '1062205016'),
  ('fatura_cantas_bakir', '1062204894'),
  ('fatura_cantas_hc',    '1062205054'),
  ('fatura_irsaliye_bagla', '1');   -- fatura↔irsaliye bağı (shipment_documents) gönderilsin mi; 422 riskinde '0'

-- Taşıyıcı bilgisi + öğün→Paraşüt ürün eşlemesi (koda gömülmez; ayardan değişir)
INSERT OR IGNORE INTO ayar (anahtar, deger) VALUES
  ('irsaliye_plaka', '41BEM936'),
  ('irsaliye_sofor_ad', 'UFUK BALTACI'),
  ('irsaliye_sofor_tckn', '23354463864'),
  ('irsaliye_urun_ogle', '1063984872'),
  ('irsaliye_urun_aksam', '1063985050'),
  ('irsaliye_urun_kumanya', '1063985150');

-- ── fable-048: KESİLEN SATIŞ FATURALARI (gerçek gelir aynası; MySQL eşi migrate_047.sql) ──
-- Paraşüt /sales_invoices SALT-OKUMA senkronu buraya yazar. parasut_id UNIQUE = mükerrer kalkanı.
-- customer_id NULL = "eşleşmemiş gelir" (contact Kokpit müşterisi değil) — gizlenmez, ayrı gösterilir.
-- net_tutar KDV HARİÇ (kâr/zarar geliri; üretim cirosuyla aynı baz) · toplam KDV DAHİL (cari dili).
CREATE TABLE IF NOT EXISTS satis_faturasi (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  parasut_id    TEXT NOT NULL UNIQUE,
  customer_id   INTEGER REFERENCES customers(id) ON DELETE SET NULL,
  contact_id    TEXT,
  contact_ad    TEXT,
  fatura_no     TEXT,
  fatura_tarihi TEXT NOT NULL,                -- 'YYYY-MM-DD'
  donem_bas     TEXT,
  donem_son     TEXT,
  net_tutar     REAL NOT NULL DEFAULT 0,
  kdv           REAL NOT NULL DEFAULT 0,
  toplam        REAL NOT NULL DEFAULT 0,
  aciklama      TEXT,
  created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_sf_tarih ON satis_faturasi(fatura_tarihi);
CREATE INDEX IF NOT EXISTS idx_sf_cust ON satis_faturasi(customer_id, fatura_tarihi);

INSERT OR IGNORE INTO ayar (anahtar, deger) VALUES
  ('kar_kaynak_varsayilan', 'fatura'),
  ('fatura_gecikme_uyari_gun', '3');

-- ── fable-052: belge maili KUYRUĞU (MySQL eşi migrate_049.sql) ──
-- Paraşüt paylaşımı müşteriye ZIP yolluyor → belgenin PDF'i indirilip UYSA SMTP'sinden
-- TEK PDF olarak gönderilir. PDF belge resmileşmeden hazır olmayabilir → kuyruk + cron.
-- 🔒 UNIQUE(tur, kaynak_id) = mükerrer mail kalkanı. Kayıt SİLİNMEZ (gönderim izi).
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

-- Paylaşım yöntemi şalteri: 'uysa_mail' (varsayılan, tek PDF) | 'parasut' (eski, ZIP gider)
INSERT OR IGNORE INTO ayar (anahtar, deger) VALUES ('paylasim_yontemi', 'uysa_mail');
