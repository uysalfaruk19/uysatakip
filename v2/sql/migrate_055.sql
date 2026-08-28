-- fable-091 düzeltmesi (Ömer, 28 Ağu): "ek ürün açmıyoruz ya, boş güne yemek sayısı
-- ekleyeceğim. Ekstra eklemeyi kaldır, direkt sayı ekleyelim müşteri ekranından."
--
-- Ekstra kalem yaklaşımı YANLIŞ çözümdü: Ömer'in ihtiyacı ayrı bir fatura kalemi değil,
-- ÜRETİM KAYDI (boş güne yemek sayısı — Temmuz'daki 15 Temmuz'a 36 kişi eklenmesi gibi).
-- Sayı Müşteriler ekranından girilir, fatura zaten o sayıdan doğar. Kalem/ürün gerekmiyor.
--
-- Tablo kullanılmadan kaldırılıyor (tek test kaydı vardı, o da silinmişti).
DROP TABLE IF EXISTS musteri_ekstra_kalem;
DELETE FROM ayar WHERE anahtar = 'ekstra_urun_id';
-- NOT: customers.fatura_oto_kesim ve ayar.fatura_oto_kesim KALIR — otomatik kesimin şalterleri.
