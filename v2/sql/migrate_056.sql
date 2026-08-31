-- fable-107c (Ömer, 31 Ağu): "kahvaltısı sabit olacak, CANTAŞ metriklerini ona göre ayarla."
-- Müşteri bazlı KAHVALTI birim fiyatı. >0 ise aylık faturaya "Kahvaltı" kalemi OTOMATİK eklenir
-- (fatura sayısı kadar). CANTAŞ = 78 TL. Diğer müşterilerde 0 → davranış değişmez.
ALTER TABLE customers ADD COLUMN kahvalti_birim DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER unit_price;
UPDATE customers SET kahvalti_birim = 78.00 WHERE id = 3;
