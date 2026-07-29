-- ═══════════════════════════════════════════════════════════════
-- migrate_049.sql — İş emri fable-052 (MySQL 8 / MariaDB, canlı uysa_v2)
-- FATURA/İRSALİYE MAİLİ: Paraşüt paylaşımı (ZIP) yerine UYSA'nın kendi SMTP'sinden TEK PDF.
--
--   Sorun: Paraşüt `POST /sharings` müşteriye ZIP (PDF + imzalı UBL zarfı) yolluyor; ek
--   formatını seçtiren seçenek ne API'de ne arayüzde VAR (TALAY/PENDORYA bu yüzden ZIP aldı).
--   Çözüm: belgenin PDF'i Paraşüt'ten indirilir (src/ParasutPdf.php) → UYSA SMTP'sinden
--   tek PDF ek olarak gönderilir (src/Mail.php). Uçtan uca kanıtlandı (29 Tem, 250 OK).
--
--   ⚠️ DAYANIKLILIK: belge resmileşmeden PDF hazır OLMAYABİLİR → gönderim kuyruğa alınır,
--   cron (tools/mail_kuyruk.php, 5 dk) tekrar dener. Kesim anında da bir kez denenir.
--
--   🔒 UNIQUE(tur, kaynak_id) = MÜKERRER MAİL KALKANI — aynı belge iki kez maillenmez
--      (sipariş köprüsü idempotency standardı). Kayıt SİLİNMEZ (gönderim izi).
--
-- İDEMPOTENT: CREATE TABLE IF NOT EXISTS + INSERT IGNORE (tekrar çalıştırmak güvenli).
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_049.sql
-- ⚠️ FK YOK: kuyruk müşteri silinse bile gönderim izini korur; customer_id sadece referans.
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `mail_kuyruk` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tur`          ENUM('fatura','irsaliye') NOT NULL,
  `customer_id`  INT UNSIGNED NOT NULL,
  `kaynak_id`    VARCHAR(40)  NOT NULL COMMENT 'sales_invoice.id veya shipment_document.id',
  `belge_no`     VARCHAR(64)  DEFAULT NULL COMMENT 'fatura_no / despatch_no (konu + dosya adı)',
  `gun`          DATE         DEFAULT NULL COMMENT 'irsaliye günü (konu + dosya adı)',
  `alici`        VARCHAR(400) NOT NULL COMMENT 'customers.irsaliye_mail / fatura_mail (virgülle çoklu)',
  `durum`        ENUM('bekliyor','gonderildi','hata') NOT NULL DEFAULT 'bekliyor',
  `deneme`       INT          NOT NULL DEFAULT 0 COMMENT 'PDF hazır değilse artar; 8''de hata',
  `son_hata`     VARCHAR(500) DEFAULT NULL,
  `gonderim_at`  DATETIME     DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mk_tur_kaynak` (`tur`, `kaynak_id`),
  KEY `idx_mk_durum` (`durum`, `deneme`),
  KEY `idx_mk_cust` (`customer_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Paylaşım yöntemi şalteri (geri dönüşlü; Paraşüt paylaşım KODU SİLİNMEDİ) ──
--   'uysa_mail' (VARSAYILAN) = PDF indir + UYSA SMTP'sinden gönder (kuyruk üzerinden)
--   'parasut'               = eski davranış (POST /sharings — müşteriye ZIP gider)
-- Ekrandan değiştirilir: Kokpit → Diğer modüller → Belge maili ayarları (public/ayarlar.php).
INSERT IGNORE INTO `ayar` (`anahtar`, `deger`) VALUES ('paylasim_yontemi', 'uysa_mail');

-- ── Kesim kaydındaki `mail` alanı artık 'sirada' da olabilir (kuyruğa alındı) ──
-- Kolonlar zaten VARCHAR(16) — şema değişikliği GEREKMEZ, yalnız açıklama tazelenir.
ALTER TABLE `parasut_irsaliye_log`
  MODIFY COLUMN `mail` VARCHAR(16) NOT NULL DEFAULT 'yok'
  COMMENT 'gonderildi|sirada|hata|yok (fable-052: sirada = mail_kuyruk''ta bekliyor)';
ALTER TABLE `parasut_fatura_log`
  MODIFY COLUMN `mail` VARCHAR(16) NOT NULL DEFAULT 'yok'
  COMMENT 'gonderildi|sirada|hata|yok (fable-052: sirada = mail_kuyruk''ta bekliyor)';

-- ── Uygulama sonrası DOĞRULA (Fable) ──
--   SHOW CREATE TABLE mail_kuyruk;                       -- UNIQUE(tur,kaynak_id) görünmeli
--   SELECT deger FROM ayar WHERE anahtar='paylasim_yontemi';  -- 'uysa_mail'
--
-- ── ORTAM (canlı .env.v2'de ZATEN TANIMLI — repoya şifre yazılmaz) ──
--   SMTP_HOST · SMTP_PORT (465) · SMTP_USER · SMTP_PASS · SMTP_FROM_AD
--   Eksikse kuyruk hiç ağa çıkmaz, satırlar 'bekliyor' kalır (veri kaybı yok).
--
-- ── CRON (Fable kurar) ──
--   */5 * * * * php /var/www/html/tools/mail_kuyruk.php >> /var/log/uysa-mail-kuyruk.log 2>&1
