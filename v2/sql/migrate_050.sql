-- ═══════════════════════════════════════════════════════════════
-- migrate_050.sql — İş emri fable-059 (MySQL 8 / MariaDB, canlı uysa_v2)
-- FİRMA KIRILIMINA ELLE GİRİŞ (istisna günler).
--
--   Sorun: alt firma bölüşümü (fable-051) DESENDEN hesaplanıyor — hafta içi sabit kotalar,
--   kalan varsayılana; hafta sonu tamamı varsayılana. Desen NORMAL günlerde doğru, İSTİSNA
--   günlerde değil: 15 Temmuz resmi tatilinde yemek verilmedi, o güne başka bir işin 36
--   kişilik maliyeti yazıldı — o 36 kişinin hangi şirkete ait olduğunu desen BİLEMEZ
--   (desen İç-Dış 30 / Bakır 6 / HC 0 dağıtıyordu; uydurma). Ay sonunda bu kırılım 3 AYRI
--   e-Faturaya bölündüğü için rakam yanlışsa YANLIŞ ŞİRKETE fatura kesilir — geri alınamaz.
--
--   Çözüm: Ömer o güne kırılımı ELLE girer (Bugün → müşteri satırı → Firma kırılımı).
--
-- 🔑 YALNIZ ELLE GİRİLEN GÜNLER YAZILIR. Kaydı olmayan gün = desen (mevcut davranış).
--    "Her günü tabloya yaz" tuzağından kaçınır: desen/kota değişince GEÇMİŞ kendiliğinden
--    düzelir, elle girilen istisna günler ise sabit kalır.
--
-- 🔒 Kayıt ancak günün FATURA kişisine (gunFaturaKisiMap — fable-040/057) EŞİTSE yazılır;
--    toplam tutmuyorsa ekran da sunucu da REDDEDER (sessiz yanlış kayıt yok).
--    Tamamı 0 girilirse satırlar SİLİNİR → gün otomatiğe (desene) döner.
--
-- ⚠️ FK tipi INT UNSIGNED — customers.id / musteri_altfirma.id ile BİREBİR aynı olmalı
--    (errno 150 dersi, migrate_039/043/046/047/048).
-- İDEMPOTENT: CREATE TABLE IF NOT EXISTS (tekrar çalıştırmak güvenli).
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_050.sql
-- ÖN KOŞUL: migrate_048.sql (musteri_altfirma) uygulanmış olmalı — FK ona bağlanır.
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `uretim_altfirma` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `prod_date`   DATE         NOT NULL,
  `altfirma_id` INT UNSIGNED NOT NULL,
  `kisi`        INT UNSIGNED NOT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ua_cust_gun_firma` (`customer_id`, `prod_date`, `altfirma_id`),
  KEY `idx_ua_cust_gun` (`customer_id`, `prod_date`),
  CONSTRAINT `fk_ua_altfirma` FOREIGN KEY (`altfirma_id`) REFERENCES `musteri_altfirma` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Uygulama sonrası DOĞRULA (Fable) ──
--   SHOW CREATE TABLE uretim_altfirma;   -- UNIQUE(customer_id,prod_date,altfirma_id) + FK musteri_altfirma
--   SELECT COUNT(*) FROM uretim_altfirma;   -- 0 (tablo boş başlar; desen aynen çalışmaya devam eder)
--
-- ── ÇIPA (uygulama sonrası ekrandan doğrulanır) ──
--   CANTAŞ Temmuz 2026 şu an 1.606 fatura kişisi → HC 690 · İç-Dış 690 · Bakır 226
--   (15 Tem resmi tatil, o gün 36 kişi — fable-057).
--   15 Tem'e elle "HC 36" girilince → HC 726 · İç-Dış 660 · Bakır 220 = TOPLAM YİNE 1.606.
--   (Toplam DEĞİŞMEZ, yalnız dağılım değişir — fatura tutarı birebir korunur.)
