-- fable-031b: Taşıma alış faturalarının SATIR verisi (Ömer: "birim fiyata bak detaylı").
-- qty = faturadaki toplam yemek adedi; net_amount = KDV HARİÇ tutar (taşıma kârı KDV'siz
-- satış birimiyle kıyaslandığı için birim = net_amount/qty elmayla elma olur; KIRMIZI kanıtı 175,00).
-- Idempotent.

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'qty');
SET @s := IF(@c = 0,
  "ALTER TABLE `transactions`
     ADD COLUMN `qty` DECIMAL(12,2) DEFAULT NULL COMMENT 'fatura satır adedi (fable-031b)',
     ADD COLUMN `net_amount` DECIMAL(12,2) DEFAULT NULL COMMENT 'KDV hariç tutar (fable-031b)'",
  "SELECT 'qty zaten var'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Mevcut 3 KIRMIZI 1 kaydına UBL'den okunan gerçek satır verileri (2026-07-23 kanıtı):
UPDATE `transactions` SET `qty` = 568, `net_amount` = 99400.00  WHERE `parasut_id` = 'ei-1071166305';
UPDATE `transactions` SET `qty` = 728, `net_amount` = 127400.00 WHERE `parasut_id` = 'ei-1071745176';
UPDATE `transactions` SET `qty` = 677, `net_amount` = 118475.00 WHERE `parasut_id` = 'ei-1072247861';
