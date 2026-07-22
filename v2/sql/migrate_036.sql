-- fable-030: Paraşüt gider senkronu (Hikari deseni — Ömer: "giderleri Hikari gibi otomatik çeksin").
-- transactions.parasut_id: dış kaynak anahtarı (mükerrer kalkanı; 'ei-' önekli gelen-kutusu id'leri
-- ile purchase_bill id'leri çakışmaz). source kolonu şemada zaten vardı ('manuel'/'parasut').
-- Idempotent.

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'parasut_id');
SET @s := IF(@c = 0,
  "ALTER TABLE `transactions` ADD COLUMN `parasut_id` VARCHAR(48) DEFAULT NULL COMMENT 'Paraşüt fatura id (fable-030; ei- önekli=gelen kutusu)', ADD UNIQUE KEY `uk_tx_parasut` (`parasut_id`)",
  "SELECT 'parasut_id zaten var'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Doğrulama: SHOW COLUMNS FROM transactions LIKE 'parasut_id';
