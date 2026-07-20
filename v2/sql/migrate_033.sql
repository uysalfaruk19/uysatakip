-- fable-023d: e-İrsaliye GÖNDERİM (resmileştirme) alanları — ilk gerçek kesim gecesinin dersleri.
--
-- 1) customers.edespatch_alias: alıcının GİB posta kutusu (legalize 'to' parametresi).
--    Kesim gecesi kanıtı: OPAK'ın e-Fatura kutusu (defaultpk@opakambalaj.com.tr) e-İrsaliye
--    gönderiminde kabul edildi (UU02026000000590). Alias'lar e_invoice_inboxes filter[vkn]
--    sorgusundan alındı (2026-07-20 keşfi).
--    TALAY BİLEREK BOŞ: GİB'de 2 kutusu var (defaultpk@sarpintermodal.com + defaultpk@talay.com)
--    — hangisine gideceğini Ömer seçecek; boşken kesim yapılır ama gönderim adımı atlanır
--    ("elle gönder" uyarısıyla), yanlış kutuya resmi belge GÖNDERİLMEZ.
-- 2) parasut_irsaliye_log.gonderim: gonderildi | hata | yok (kesim ile gönderim ayrı adımlar).
--
-- Idempotent.

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'edespatch_alias');
SET @s := IF(@c = 0,
  "ALTER TABLE `customers` ADD COLUMN `edespatch_alias` VARCHAR(120) NULL COMMENT 'GİB e-İrsaliye alıcı kutusu (fable-023d)'",
  "SELECT 'edespatch_alias zaten var'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parasut_irsaliye_log' AND COLUMN_NAME = 'gonderim');
SET @s := IF(@c = 0,
  "ALTER TABLE `parasut_irsaliye_log` ADD COLUMN `gonderim` VARCHAR(16) NOT NULL DEFAULT 'yok' COMMENT 'gonderildi|hata|yok (fable-023d)'",
  "SELECT 'gonderim zaten var'");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

UPDATE `customers` SET `edespatch_alias` = 'urn:mail:defaultpk@opakambalaj.com.tr'
  WHERE `name` = 'OPAK' AND (`edespatch_alias` IS NULL OR `edespatch_alias` = '');
UPDATE `customers` SET `edespatch_alias` = 'urn:mail:finance.trpk@bomigroup.com'
  WHERE `name` = 'BOMİ' AND (`edespatch_alias` IS NULL OR `edespatch_alias` = '');
UPDATE `customers` SET `edespatch_alias` = 'urn:mail:defaultpk@tskb.com.tr'
  WHERE `name` = 'PENDORYA' AND (`edespatch_alias` IS NULL OR `edespatch_alias` = '');
UPDATE `customers` SET `edespatch_alias` = 'urn:mail:ecothermdefaultpk@isnet.com'
  WHERE `name` = 'CEOTHERM' AND (`edespatch_alias` IS NULL OR `edespatch_alias` = '');
UPDATE `customers` SET `edespatch_alias` = 'urn:mail:defaultpk@ermetalmadencilik.com.tr'
  WHERE `name` = 'ERMETAL' AND (`edespatch_alias` IS NULL OR `edespatch_alias` = '');
-- TALAY LOJİSTİK: bilerek boş (üstteki not).

-- Doğrulama:
--   SELECT name, edespatch_alias FROM customers WHERE irsaliye_aktif = 1;
