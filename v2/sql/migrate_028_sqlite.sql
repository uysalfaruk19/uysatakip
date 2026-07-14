-- fable-007: teklif öğün bazlı fiyat cetveli + giriş metni (SQLite). Her kolon ayrı ALTER.
ALTER TABLE teklif ADD COLUMN fiyat_json TEXT;
ALTER TABLE teklif ADD COLUMN giris_metni TEXT;
