-- fable-061 (Ömer, 2026-07-31): faturada IBAN görünsün.
-- Paraşüt'te banka hesabı kayıtlı olmasına rağmen e-Fatura PDF'ine basılmıyordu
-- (TALAY UY02026000000132 PDF'i indirilip metni tarandı: IBAN/banka geçmiyor).
-- Çözüm: fatura gövdesinde `print_note` — metin ayar tablosundan gelir (koda gömülü değil).
-- İDEMPOTENT.

ALTER TABLE `ayar` MODIFY `deger` VARCHAR(500) NOT NULL;

INSERT INTO `ayar` (`anahtar`, `deger`) VALUES
  ('fatura_notu', 'Ödeme: HALKBANK — IBAN TR75 0001 2001 3200 0010 1011 01 (UYSA YEMEK HİZMETLERİ SAN. VE TİC. LTD. ŞTİ.)')
ON DUPLICATE KEY UPDATE `deger` = VALUES(`deger`);
