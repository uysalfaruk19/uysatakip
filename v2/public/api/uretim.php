<?php
declare(strict_types=1);

/**
 * POST /api/uretim  — bot/iç üretim girişi.
 * Girdi (biri):
 *   { "text": "cantaş 450 opak 280" }            (serbest — OFUclaw)
 *   { "customer": "cantas", "persons": 450, "date": "2026-07-03", "meal": "ogle", "price": 328 }
 * Fuzzy müşteri adı eşleşme (Türkçe karaktersiz/mojibake toleranslı; min skor 0.72).
 * Opsiyonel "price" (0-100000) → unit_price_snap (yoksa müşteri birim fiyatı).
 * Yanıt: eşleşenler + eşleşmeyenler + gün toplamı + eksik müşteri listesi.
 */

require __DIR__ . '/_boot.php';

use Uysa\Helpers;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Helpers::json(['ok' => false, 'error' => 'POST gerekli'], 405);
}

$date = (string) ($body['date'] ?? Helpers::today());
if (!Helpers::isDate($date)) {
    Helpers::json(['ok' => false, 'error' => 'Geçersiz tarih (YYYY-MM-DD)'], 400);
}
$meal = $body['meal'] ?? 'ogle';
if (!in_array($meal, ['sabah', 'ogle', 'aksam', 'gece', 'kumanya'], true)) {
    $meal = 'ogle';
}

// Opsiyonel fiyat override (0-100000). Yoksa müşteri birim fiyatı kullanılır.
$priceOverride = null;
if (isset($body['price']) && is_numeric($body['price'])) {
    $priceOverride = (float) $body['price'];
    if ($priceOverride < 0 || $priceOverride > 100000) {
        Helpers::json(['ok' => false, 'error' => 'price 0-100000 aralığında olmalı'], 400);
    }
}

/**
 * "cantaş 450 opak 280 talay lojistik 45" → [['cantaş',450],...].
 * Sondaki sayısız isim (ör. "cantaş 450 opak") → $trailing'e (rapor için).
 */
function parsePairs(string $text, ?array &$trailing = null): array
{
    $tokens = preg_split('/\s+/u', trim($text)) ?: [];
    $pairs = [];
    $nameBuf = [];
    foreach ($tokens as $tok) {
        $num = str_replace('.', '', $tok);
        if ($num !== '' && ctype_digit($num)) {
            if ($nameBuf) {
                $pairs[] = [implode(' ', $nameBuf), (int) $num];
                $nameBuf = [];
            }
            // sayı ama önünde isim yok → yok say (başıboş rakam)
        } else {
            $nameBuf[] = $tok;
        }
    }
    if ($nameBuf) {
        $trailing = $nameBuf; // sayısız artık isim(ler)
    }
    return $pairs;
}

// Girdi normalize → [ [name, persons], ... ]
$requests = [];
$unmatched = [];
$trailing = null;
if (isset($body['text']) && is_string($body['text']) && trim($body['text']) !== '') {
    $requests = parsePairs($body['text'], $trailing);
    if ($trailing) {
        $unmatched[] = ['girdi' => implode(' ', $trailing), 'sebep' => 'sayi_girilmedi'];
    }
} elseif (isset($body['customer'])) {
    $requests[] = [(string) $body['customer'], (int) ($body['persons'] ?? 0)];
} else {
    Helpers::json(['ok' => false, 'error' => 'text veya customer alanı gerekli'], 400);
}

$candidates = $repo->customerNameMap(true);
$entries = [];

$pdo->beginTransaction();
try {
    foreach ($requests as [$rawName, $persons]) {
        $match = Helpers::matchCustomer($rawName, $candidates);
        if ($match['id'] === null) {
            $u = ['girdi' => $rawName, 'sebep' => $match['reason'] === 'dusuk_skor' ? 'dusuk_skor' : 'musteri_eslesmedi'];
            if (!empty($match['aday'])) {
                $u['en_yakin'] = $match['aday'];
                $u['skor'] = round($match['score'], 2);
            }
            $unmatched[] = $u;
            continue;
        }
        $cust = $repo->customer($match['id']);
        $unitPrice = $priceOverride ?? (float) $cust['unit_price'];
        $res = $repo->upsertProduction(
            $match['id'], $date, $persons, $unitPrice, $meal, 'bot'
        );
        $entries[] = [
            'girdi'       => $rawName,
            'musteri'     => $match['name'],
            'kisi'        => $persons,
            'birim_fiyat' => $unitPrice,
            'tutar'       => $res['amount'],
            'eslesme'     => round($match['score'], 2),
            'yontem'      => $match['reason'],
        ];
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    error_log('[UYSA v2 api/uretim] ' . $e->getMessage());
    Helpers::json(['ok' => false, 'error' => 'Kayıt hatası'], 500);
}

uysa_audit('api_uretim', $actor, $date, json_encode(['n' => count($entries)], JSON_UNESCAPED_UNICODE), $ip);

// Gün toplamı + eksik müşteriler
$tot = $repo->dayTotals($date, $meal);
$grid = $repo->dayGrid($date, $meal);
$eksik = [];
foreach ($grid as $row) {
    if ($row['persons'] === null) {
        $eksik[] = $row['name'];
    }
}

Helpers::json([
    'ok'          => true,
    'tarih'       => $date,
    'ogun'        => $meal,
    'kayitlar'    => $entries,
    'eslesmeyen'  => $unmatched,
    'gun_toplam'  => ['kisi' => (int) $tot['persons'], 'tutar' => (float) $tot['amount']],
    'eksik'       => $eksik,
]);
