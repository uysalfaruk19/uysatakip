-- fable-023c: İrsaliye sevk adresi Kokpit'te saklanır (Ömer, 2026-07-21).
--
-- NEDEN: kesim anında Paraşüt cari kartını okumak gereksiz bir kırılma noktasıydı — ilk canlı
-- denemede tam da orada patladı (HTTP 0, adres alınamadı → kesim yapılmadı). Adres her belgede
-- AYNI (geçmiş irsaliyelerle birebir doğrulandı), yani her seferinde uzaktan sormaya gerek yok.
-- Artık: önce Kokpit'teki kayıtlı adres kullanılır; boşsa Paraşüt'ten okunmaya çalışılır.
--
-- Idempotent: tekrar çalıştırılabilir.

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'sevk_adres');
SET @s := IF(@c = 0,
  "ALTER TABLE `customers`
     ADD COLUMN `sevk_adres` VARCHAR(255) NULL COMMENT 'İrsaliye sevk adresi (fable-023c)',
     ADD COLUMN `sevk_il` VARCHAR(60) NULL,
     ADD COLUMN `sevk_ilce` VARCHAR(60) NULL",
  "SELECT 'sevk_adres zaten var'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Paraşüt cari kartlarından okunan adresler (2026-07-21; geçmiş irsaliyelerle birebir aynı).
-- Boş olan satıra yazar — elle düzeltilmiş adresi EZMEZ.
UPDATE `customers` SET `sevk_adres` = 'DERİ OSB MAH., TABAKHANE CAD., 6, TUZLA, İSTANBUL',
  `sevk_il` = 'İSTANBUL', `sevk_ilce` = 'TUZLA'
  WHERE `name` = 'BOMİ' AND (`sevk_adres` IS NULL OR `sevk_adres` = '');

UPDATE `customers` SET `sevk_adres` = 'ÖMER AVNİ MAH., KARUN ÇIKMAZI SK. TSKB, 2/1, BEYOĞLU, İSTANBUL',
  `sevk_il` = 'İSTANBUL', `sevk_ilce` = 'BEYOĞLU'
  WHERE `name` = 'PENDORYA' AND (`sevk_adres` IS NULL OR `sevk_adres` = '');

UPDATE `customers` SET `sevk_adres` = 'ŞEKERPINAR MAH., AYÇİÇEK SK., 36 A, ÇAYIROVA, KOCAELİ',
  `sevk_il` = 'KOCAELİ', `sevk_ilce` = 'ÇAYIROVA'
  WHERE `name` = 'CEOTHERM' AND (`sevk_adres` IS NULL OR `sevk_adres` = '');

UPDATE `customers` SET `sevk_adres` = 'ŞEKERPINAR MAH., ÇİĞDEM SK., 14, ÇAYIROVA, KOCAELİ',
  `sevk_il` = 'KOCAELİ', `sevk_ilce` = 'ÇAYIROVA'
  WHERE `name` = 'ERMETAL' AND (`sevk_adres` IS NULL OR `sevk_adres` = '');

UPDATE `customers` SET `sevk_adres` = 'DERİ OSB MAH., TABAKHANE CAD. İSTANBUL DERİ OSB, 10/2, TUZLA, İSTANBUL',
  `sevk_il` = 'İSTANBUL', `sevk_ilce` = 'TUZLA'
  WHERE `name` = 'OPAK' AND (`sevk_adres` IS NULL OR `sevk_adres` = '');

UPDATE `customers` SET `sevk_adres` = 'TÜRKOBA MAH., KAYALAR CAD. SARP INTERMODAL, 10/1, BÜYÜKÇEKMECE, İSTANBUL',
  `sevk_il` = 'İSTANBUL', `sevk_ilce` = 'BÜYÜKÇEKMECE'
  WHERE `name` = 'TALAY LOJİSTİK' AND (`sevk_adres` IS NULL OR `sevk_adres` = '');

-- Doğrulama:
--   SELECT name, sevk_il, sevk_ilce, sevk_adres FROM customers WHERE irsaliye_aktif = 1;
