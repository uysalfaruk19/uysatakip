-- kokpit-ios: müşteri iletişim e-postası (isim=contact, numara=phone zaten var).
ALTER TABLE `customers` ADD COLUMN `email` VARCHAR(120) DEFAULT NULL AFTER `phone`;
