<?php
declare(strict_types=1);

/**
 * POST /api/uretim  — bot/iç üretim girişi.
 * Girdi (biri):
 *   { "text": "cantaş 450 opak 280" }            (serbest — OFUclaw)
 *   { "customer": "cantas", "persons": 450, "date": "2026-07-03", "meal": "ogle" }
 * Fuzzy müşteri adı eşleşme (Türkçe karaktersiz/mojibake toleranslı).
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
if (!in_array($meal, ['sabah', 'ogle', 'aksam', 'gece'], true)) {
    $meal = 'ogle';
}

/** "cantaş 450 opak 280 talay lojistik 45" → [['cantaş',450],['opak',280],['talay lojistik',45]] */
function parsePairs(string $text): array
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
        } else {
            $nameBuf[] = $tok;
        }
    }
    return $pairs;
}

// Girdi normalize → [ [name, persons], ... ]
$requests = [];
if (isset($body['text']) && is_string($body['text']) && trim($body['text']) !== '') {
    $requests = parsePairs($body['text']);
} elseif (isset($body['customer'])) {
    $requests[] = [(string) $body['customer'], (int) ($body['persons'] ?? 0)];
} else {
    Helpers::json(['ok' => false, 'error' => 'text veya customer alanı gerekli'], 400);
}

$candidates = $repo->customerNameMap(true);
$entries = [];
$unmatched = [];

$pdo->beginTransaction();
try {
    foreach ($requests as [$rawName, $persons]) {
        $match = Helpers::matchCustomer($rawName, $candidates);
        if ($match['id'] === null) {
            $unmatched[] = ['girdi' => $rawName, 'sebep' => 'musteri_eslesmedi'];
            continue;
        }
        $cust = $repo->customer($match['id']);
        $res = $repo->upsertProduction(
            $match['id'], $date, $persons, (float) $cust['unit_price'], $meal, 'bot'
        );
        $entries[] = [
            'girdi'       => $rawName,
            'musteri'     => $match['name'],
            'kisi'        => $persons,
            'birim_fiyat' => (float) $cust['unit_price'],
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
