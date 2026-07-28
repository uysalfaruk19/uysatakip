-- ═══════════════════════════════════════════════════════════════
-- migrate_048.sql — İş emri fable-051 (MySQL 8 / MariaDB, canlı uysa_v2)
-- ALT FİRMA bölüşüm DESENİ: bir müşterinin faturası N ayrı ŞİRKETE kesiliyorsa
-- (CANTAŞ = HC Isıtma · CANTAŞ İç-Dış · CANTAŞ Bakır) ay sonu 3'lü bölüşüm artık
-- Ömer'in Excel'de elle tuttuğu desenden GÜN GÜN hesaplanır; fatura penceresine DOLU gelir.
--
--   DESEN (parametrik — koda gömülü DEĞİL, aşağıdaki `haftaici_sabit` kolonunda):
--     · hafta içi → sabit kotalı firmalar `sira` düzeninde payını alır (İç-Dış 30, Bakır 10),
--       KALAN varsayılan firmaya (HC). Kişi azsa sondan kısılır; hiçbir firma NEGATİFE düşmez.
--     · cumartesi/pazar → TAMAMI varsayılan firmaya (Ömer'in açık kuralı: "cumartesiler HC").
--   Hesap FATURA kişisi üzerinden yapılır (fable-040: CANTAŞ hafta içi ciro 70 kişiden,
--   üretim persons 50'den değil) → günün fatura kişisi = production.amount / birim fiyat.
--
--   SİLME YOK — alt firma `aktif=0` ile pasifleşir (geçmiş fatura izi bozulmaz).
--   `musteri_altfirma.kod` = `customers.fatura_bolusum` JSON'daki `key` ile AYNI olmalı
--   (bölüşüm kutuları bu eşleşmeyle dolar; eşleşmezse Paraşüt contact id'ye düşülür).
--
-- ⚠️ FK tipi INT UNSIGNED — customers.id ile BİREBİR aynı olmalı (errno 150 dersi, migrate_039/043/046/047).
-- İDEMPOTENT: CREATE TABLE IF NOT EXISTS (tekrar çalıştırmak güvenli).
-- Uygulama (Fable): mysql -u <user> -p uysa_v2 < sql/migrate_048.sql
--
-- 🔒 NOT: Bu migrasyon yalnız ŞEMA kurar. CANTAŞ SEED'i BİLEREK ÇALIŞTIRILMIYOR —
--    canlı contact id'lerle (ayar: fatura_cantas_hc / _icdis / _bakir) Fable uygular.
--    Hazır seed en altta yorumda (varsayilan=1 → HC Isıtma; İç-Dış 30 · Bakır 10).
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `musteri_altfirma` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`         INT UNSIGNED NOT NULL,
  `kod`                 VARCHAR(40)  NOT NULL,             -- fatura_bolusum key'i ile aynı (ör. 'fatura_cantas_hc')
  `ad`                  VARCHAR(120) NOT NULL,             -- ekranda görünen ad (ör. 'HC Isıtma')
  `parasut_contact_id`  VARCHAR(48)  DEFAULT NULL,         -- boşsa ayar tablosundaki `kod` anahtarından çözülür
  `varsayilan`          TINYINT(1)   NOT NULL DEFAULT 0,   -- 1 = kalan + hafta sonu bu firmaya (CANTAŞ→HC)
  `haftaici_sabit`      INT UNSIGNED DEFAULT NULL,         -- hafta içi günlük SABİT kota (NULL = kalanı alan firma)
  `sira`                INT          NOT NULL DEFAULT 0,   -- kota dağıtım sırası (kişi azalınca SONDAKİ kısılır)
  `aktif`               TINYINT(1)   NOT NULL DEFAULT 1,   -- silme yok; 0 = pasif
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_maf_cust_kod` (`customer_id`, `kod`),
  KEY `idx_maf_cust` (`customer_id`, `aktif`, `sira`),
  CONSTRAINT `fk_maf_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Uygulama sonrası DOĞRULA (Fable) ──
--   SHOW CREATE TABLE musteri_altfirma;   -- UNIQUE(customer_id,kod), FK customers, haftaici_sabit NULL'lanabilir
--
-- ── CANTAŞ SEED (Fable canlıda uygular — burada BİLEREK çalıştırılmıyor) ──
-- INSERT IGNORE INTO `musteri_altfirma`
--   (`customer_id`, `kod`, `ad`, `parasut_contact_id`, `varsayilan`, `haftaici_sabit`, `sira`)
-- SELECT c.`id`, v.`kod`, v.`ad`, v.`cid`, v.`vars`, v.`sabit`, v.`sira`
--   FROM `customers` c
--   JOIN (SELECT 'fatura_cantas_icdis' AS kod, 'CANTAŞ İç-Dış' AS ad, '1062205016' AS cid, 0 AS vars,   30 AS sabit, 1 AS sira
--         UNION ALL SELECT 'fatura_cantas_bakir', 'CANTAŞ Bakır', '1062204894', 0,   10, 2
--         UNION ALL SELECT 'fatura_cantas_hc',    'HC Isıtma',    '1062205054', 1, NULL, 3) v
--  WHERE c.`name` = 'CANTAŞ';
--
-- Seed sonrası DOĞRULA — Temmuz 2026 çıpası (Ömer Excel'i): toplam 1.640 fatura kişisi
--   (₺537.920 / 328) → HC 720 · İç-Dış 690 · Bakır 230. Kokpit → Fatura Kes → "Geçen ay"
--   → CANTAŞ satırındaki bölüşüm kutuları bu üç rakamla DOLU gelmeli.
