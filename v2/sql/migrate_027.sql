-- fable-005: yemekhaneci teklif motoru alanlari (markali "Yemekhaneci formatinda" teklif PDF'i icin).
-- Mevcut teklif tablosuna musteri/hizmet/menu/personel detay kolonlari eklenir.
ALTER TABLE `teklif`
  ADD COLUMN `yetkili`       VARCHAR(120)  DEFAULT NULL AFTER `firma`,
  ADD COLUMN `telefon`       VARCHAR(40)   DEFAULT NULL AFTER `yetkili`,
  ADD COLUMN `email`         VARCHAR(120)  DEFAULT NULL AFTER `telefon`,
  ADD COLUMN `ogun_sayisi`   TINYINT       NOT NULL DEFAULT 1 AFTER `kisi`,
  ADD COLUMN `cumartesi`     TINYINT(1)    NOT NULL DEFAULT 0 AFTER `ogun_sayisi`,
  ADD COLUMN `sehir`         VARCHAR(80)   DEFAULT NULL AFTER `cumartesi`,
  ADD COLUMN `ilce`          VARCHAR(80)   DEFAULT NULL AFTER `sehir`,
  ADD COLUMN `segment`       ENUM('ekonomik','genel','premium') DEFAULT NULL AFTER `ilce`,
  ADD COLUMN `menu_json`     VARCHAR(1000) DEFAULT NULL AFTER `segment`,
  ADD COLUMN `personel_json` VARCHAR(300)  DEFAULT NULL AFTER `menu_json`,
  ADD COLUMN `ekipman`       VARCHAR(500)  DEFAULT NULL AFTER `personel_json`;
