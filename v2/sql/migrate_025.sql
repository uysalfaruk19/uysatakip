-- fable-001: müşteri malzeme talebi serbest metin ("yazı kutucuğu yeterli" — katalog seçimi opsiyonel).
ALTER TABLE `supply_request` ADD COLUMN `free_text` VARCHAR(1000) DEFAULT NULL AFTER `note`;
