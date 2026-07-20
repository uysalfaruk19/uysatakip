-- fable-023e: Kesilen e-İrsaliyenin müşteriye OTOMATİK MAİL paylaşımı (Ömer, 2026-07-21).
--
-- Paraşüt 'sharings' ucu canlı kanıtlandı (OPAK belgesi → faruk@uysayemek.com.tr, email_status
-- 'sent'; 'portal' parametresi zorunlu). Mail SADECE gönderimi (GİB resmileşmesi) başarılı
-- belgeler için atılır — resmileşmemiş belge müşteriye maillenmez.
--
-- customers.irsaliye_mail: virgülle çoklu adres olabilir. Boş = mail istemiyor.
-- Idempotent.

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'irsaliye_mail');
SET @s := IF(@c = 0,
  "ALTER TABLE `customers` ADD COLUMN `irsaliye_mail` VARCHAR(255) NULL COMMENT 'e-İrsaliye mail paylaşım adresleri, virgülle çoklu (fable-023e)'",
  "SELECT 'irsaliye_mail zaten var'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parasut_irsaliye_log' AND COLUMN_NAME = 'mail');
SET @s := IF(@c = 0,
  "ALTER TABLE `parasut_irsaliye_log` ADD COLUMN `mail` VARCHAR(16) NOT NULL DEFAULT 'yok' COMMENT 'gonderildi|hata|yok (fable-023e)'",
  "SELECT 'mail zaten var'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Ömer'in verdiği adresler (2026-07-21):
UPDATE `customers` SET `irsaliye_mail` = 'tuncay.gedik@talay.com'
  WHERE `name` = 'TALAY LOJİSTİK' AND (`irsaliye_mail` IS NULL OR `irsaliye_mail` = '');
UPDATE `customers` SET `irsaliye_mail` = 'yasemin.camyar@pendorya.com'
  WHERE `name` = 'PENDORYA' AND (`irsaliye_mail` IS NULL OR `irsaliye_mail` = '');

-- Doğrulama:
--   SELECT name, irsaliye_mail FROM customers WHERE irsaliye_aktif = 1;
