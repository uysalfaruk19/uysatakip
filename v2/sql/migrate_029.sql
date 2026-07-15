-- fable-015: personel işten çıkış tarihi.
-- Dolu = kişi pasif; çıkış ayının maaşı kıst hesaplanır (takvimCalismaGunu), kıdem o tarihte donar.
-- Idempotent değil — bir kez uygulanır (MySQL'de ADD COLUMN IF NOT EXISTS yok; hata verirse zaten vardır).
ALTER TABLE `personel`
  ADD COLUMN `ise_cikis` DATE DEFAULT NULL COMMENT 'işten çıkış; dolu=pasif, o ay kıst maaş, kıdem donar'
  AFTER `ise_giris`;
