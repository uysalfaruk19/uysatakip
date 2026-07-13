-- fable-005: yemekhaneci teklif motoru alanlari (SQLite). Her kolon ayri ALTER (SQLite kisiti).
ALTER TABLE teklif ADD COLUMN yetkili TEXT;
ALTER TABLE teklif ADD COLUMN telefon TEXT;
ALTER TABLE teklif ADD COLUMN email TEXT;
ALTER TABLE teklif ADD COLUMN ogun_sayisi INTEGER NOT NULL DEFAULT 1;
ALTER TABLE teklif ADD COLUMN cumartesi INTEGER NOT NULL DEFAULT 0;
ALTER TABLE teklif ADD COLUMN sehir TEXT;
ALTER TABLE teklif ADD COLUMN ilce TEXT;
ALTER TABLE teklif ADD COLUMN segment TEXT CHECK(segment IN ('ekonomik','genel','premium'));
ALTER TABLE teklif ADD COLUMN menu_json TEXT;
ALTER TABLE teklif ADD COLUMN personel_json TEXT;
ALTER TABLE teklif ADD COLUMN ekipman TEXT;
