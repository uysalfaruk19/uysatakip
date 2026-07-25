-- migrate_042_sqlite.sql — fable-040 SQLite eşi (test/CI; üretim MySQL migrate_042.sql).
-- SQLite'ta "kolon yoksa ekle" koşullu çalışmaz → fatura_kisi_haftaici kolonu
-- schema_sqlite.sql'de tanımlıdır; buradaki ALTER yalnız ESKİ bir sqlite dosyasını yükseltmek
-- içindir ve "duplicate column name" hatası verirse GÜVENLE yok sayılır (kolon zaten var demektir).

ALTER TABLE customers ADD COLUMN fatura_kisi_haftaici INTEGER DEFAULT NULL;

-- Canlı seed eşi (test fixture'ları kendi müşterisini kurar; burası yalnız elle sqlite yükseltme için):
UPDATE customers SET fatura_kisi_haftaici = 70 WHERE name = 'CANTAŞ';
