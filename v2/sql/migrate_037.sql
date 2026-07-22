-- fable-031: Taşıma alış tedarikçisi (Ömer: "KIRMIZI 1 faturalarını taşıma alışa işle;
-- fatura varsa matbu kabul etme"). Anahtar ayarda — koda gömülmez, Ömer değiştirebilir.
-- Idempotent.

INSERT IGNORE INTO `ayar` (`anahtar`, `deger`) VALUES ('tasima_alis_tedarikci', 'KIRMIZI 1');

-- Mevcut senkronlanmış KIRMIZI 1 kayıtlarını 'Taşıma alış' kategorisine çevir
-- (bundan sonrakileri senkron kendisi etiketler).
UPDATE `transactions` SET `category` = 'Taşıma alış'
 WHERE `type` = 'gider' AND `source` = 'parasut'
   AND `description` LIKE 'KIRMIZI 1%' AND (`category` IS NULL OR `category` <> 'Taşıma alış');

-- Doğrulama: SELECT category, COUNT(*), SUM(amount) FROM transactions
--   WHERE source='parasut' GROUP BY category;
