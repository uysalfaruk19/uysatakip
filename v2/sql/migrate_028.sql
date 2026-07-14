-- fable-007: teklif öğün bazlı fiyat cetveli + giriş metni alanları.
-- fiyat_json: {"kahvalti":..,"ogle":..,"aksam":..,"ara":..} (KDV hariç, TL). giris_metni: matbu override.
ALTER TABLE `teklif`
  ADD COLUMN `fiyat_json`  VARCHAR(500)  DEFAULT NULL AFTER `birim_fiyat`,
  ADD COLUMN `giris_metni` VARCHAR(1500) DEFAULT NULL AFTER `fiyat_json`;
