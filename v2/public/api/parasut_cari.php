<?php
declare(strict_types=1);

/**
 * /api/parasut_cari — Paraşüt cari bakiye ALICI endpoint (VPS'te çalışır).
 *
 * 🔒 Bu endpoint Paraşüt'e HİÇBİR çağrı YAPMAZ. Paraşüt kredensiyalleri VPS'te YOKTUR
 *    (Ömer güvenlik tercihi). Senkron YEREL çalışır (tools/parasut_sync.php), buraya
 *    yalnızca eşleşme + bakiye SONUÇLARINI X-UYSA-Token ile POST eder.
 *
 * POST gövde:
 *   { "eslesmeler": [
 *       { "customer_id": 5, "parasut_id": "123", "parasut_bakiye": 12500.50 },
 *       { "ad": "CANTAŞ", "vergi_no": "1234567890", "parasut_id": "88", "parasut_bakiye": -300 }
 *   ] }
 *   - customer_id verilirse doğrudan o müşteriye yazılır.
 *   - yoksa parasut_id > vergi_no > ad-normalize ile Kokpit müşterisi bulunur.
 *   - eşleşmeyenler RAPORLANIR (oto müşteri OLUŞTURULMAZ).
 * Yanıt: { ok, yazilan:[...], eslesmeyen:[...], toplam }.
 *
 * GET: son senkron durumu + Paraşüt bakiyesi bağlı müşteriler.
 */

require __DIR__ . '/_boot.php';

use Uysa\Helpers;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── GET: senkron durumu ──────────────────────────────────────
if ($method === 'GET') {
    $last = $repo->lastParasutSync();
    Helpers::json([
        'ok'          => true,
        'son_senkron' => $last ? [
            'zaman'  => $last['created_at'],
            'kaynak' => $last['actor'],
            'ozet'   => json_decode((string) $last['detail'], true),
        ] : null,
        'musteriler'  => array_map(static function (array $r): array {
            return [
                'id'      => (int) $r['id'],
                'ad'      => $r['name'],
                'bakiye'  => $r['parasut_bakiye'] !== null ? (float) $r['parasut_bakiye'] : null,
                'senkron' => $r['parasut_sync_at'],
            ];
        }, $repo->customersWithParasut()),
    ]);
}

if ($method !== 'POST') {
    Helpers::json(['ok' => false, 'error' => 'POST gerekli'], 405);
}

$rows = $body['eslesmeler'] ?? null;
if (!is_array($rows) || $rows === []) {
    Helpers::json(['ok' => false, 'error' => 'eslesmeler dizisi gerekli'], 400);
}

$now = date('Y-m-d H:i:s');
$candidates = $repo->parasutCandidates(true);
$yazilan = [];
$eslesmeyen = [];

$pdo->beginTransaction();
try {
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $bakiye   = isset($row['parasut_bakiye']) && is_numeric($row['parasut_bakiye']) ? (float) $row['parasut_bakiye'] : null;
        $parasutId = trim((string) ($row['parasut_id'] ?? '')) ?: null;
        $ad       = trim((string) ($row['ad'] ?? ''));

        if ($bakiye === null) {
            $eslesmeyen[] = ['girdi' => $ad ?: ($parasutId ?? '?'), 'sebep' => 'bakiye_yok'];
            continue;
        }

        // Müşteri çöz: 1) doğrudan customer_id, 2) parasut_id/vergi/ad ile eşleştir
        $customerId = null;
        $reason = 'customer_id';
        if (isset($row['customer_id']) && (int) $row['customer_id'] > 0) {
            $cid = (int) $row['customer_id'];
            if ($repo->customer($cid) !== null) {
                $customerId = $cid;
            }
        }
        if ($customerId === null) {
            $match = $repo->matchCustomerByTaxOrName([
                'parasut_id' => $parasutId ?? '',
                'name'       => $ad,
                'tax_number' => (string) ($row['vergi_no'] ?? ''),
            ], $candidates);
            $customerId = $match['customer_id'];
            $reason = $match['reason'];
        }

        if ($customerId === null) {
            $eslesmeyen[] = ['girdi' => $ad ?: ($parasutId ?? '?'), 'bakiye' => $bakiye, 'sebep' => 'eslesmedi'];
            continue;
        }

        $repo->setParasutInfo($customerId, $parasutId, $bakiye, $now);
        $yazilan[] = ['customer_id' => $customerId, 'bakiye' => $bakiye, 'yontem' => $reason];
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    error_log('[UYSA v2 api/parasut_cari] ' . $e->getMessage());
    Helpers::json(['ok' => false, 'error' => 'Kayıt hatası'], 500);
}

uysa_audit(
    'parasut_cari',
    $actor,
    null,
    json_encode(['yazilan' => count($yazilan), 'eslesmeyen' => $eslesmeyen], JSON_UNESCAPED_UNICODE),
    $ip
);

Helpers::json([
    'ok'         => true,
    'toplam'     => count($rows),
    'yazilan'    => $yazilan,
    'eslesmeyen' => $eslesmeyen,
]);
