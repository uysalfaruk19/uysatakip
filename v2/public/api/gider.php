<?php
declare(strict_types=1);

/**
 * POST /api/gider  — bot/iç gider girişi (ciro-oranlı dağıtım, opus-015).
 * Girdi:
 *   { "tutar": 500, "neresi_icin": "genel", "aciklama": "ambalaj" }
 *   { "tutar": 500, "neresi_icin": ["cantas","opak"], "aciklama": "..." }
 *   { "tutar": 500, "neresi_icin": "cantas" }         (tek müşteri)
 * neresi_icin:
 *   - "genel"                → o ay TÜM müşterilere ciro oranında.
 *   - müşteri adı / ad dizisi → fuzzy eşleşen müşteri(ler)e ciro oranında.
 * Fuzzy düşük güven / eşleşmeyen → 422 + "netleştir" (bot Ömer'e sorar, YAZMAZ).
 * Opsiyonel: date (YYYY-MM-DD, vars. bugün), kategori.
 * Yanıt: eklenen gider + bu giderin müşterilere ciro-oranlı dağılımı.
 */

require __DIR__ . '/_boot.php';

use Uysa\Helpers;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Helpers::json(['ok' => false, 'error' => 'POST gerekli'], 405);
}

// ── Tutar ─────────────────────────────────────────────
if (!isset($body['tutar']) || !is_numeric($body['tutar'])) {
    Helpers::json(['ok' => false, 'error' => 'tutar gerekli (sayı)', 'netlestir' => 'Gider tutarı kaç TL?'], 422);
}
$tutar = (float) $body['tutar'];
if ($tutar <= 0 || $tutar > 10000000) {
    Helpers::json(['ok' => false, 'error' => 'tutar 0-10.000.000 aralığında olmalı'], 400);
}

// ── Tarih ─────────────────────────────────────────────
$date = (string) ($body['date'] ?? Helpers::today());
if (!Helpers::isDate($date)) {
    Helpers::json(['ok' => false, 'error' => 'Geçersiz tarih (YYYY-MM-DD)'], 400);
}
$ay = substr($date, 0, 7);

$aciklama = isset($body['aciklama']) ? mb_substr((string) $body['aciklama'], 0, 500) : null;
// 'Personel' kategorisi giderDagitim'de HARİÇ tutulur (çift sayım) → bot bunu yazmasın.
$kategori = isset($body['kategori']) && $body['kategori'] !== '' ? (string) $body['kategori'] : null;
if ($kategori !== null && strcasecmp($kategori, 'Personel') === 0) {
    $kategori = null;
}

// ── neresi_icin çöz ───────────────────────────────────
if (!array_key_exists('neresi_icin', $body)) {
    Helpers::json([
        'ok' => false, 'error' => 'neresi_icin gerekli',
        'netlestir' => "Bu gider genel mi, yoksa belirli müşteri(ler) için mi? ('genel' veya müşteri adı)",
    ], 422);
}
$hedef = $body['neresi_icin'];

$allocType = 'genel';
$matchedIds = [];
$matchedNames = [];

$isGenel = is_string($hedef) && in_array(mb_strtolower(trim($hedef)), ['genel', 'hepsi', 'tumu', 'tümü'], true);

if (!$isGenel) {
    $names = is_array($hedef) ? $hedef : [$hedef];
    $names = array_values(array_filter(array_map(static fn($x) => trim((string) $x), $names), static fn($x) => $x !== ''));
    if (!$names) {
        Helpers::json([
            'ok' => false, 'error' => 'neresi_icin boş',
            'netlestir' => "Genel mi yoksa hangi müşteri(ler)? ('genel' veya müşteri adı)",
        ], 422);
    }
    $allocType = 'musteri';
    $candidates = $repo->customerNameMap(true);
    $belirsiz = [];
    foreach ($names as $raw) {
        $m = Helpers::matchCustomer($raw, $candidates);
        if ($m['id'] === null) {
            $item = ['girdi' => $raw, 'sebep' => $m['reason']];
            if (!empty($m['aday'])) {
                $item['en_yakin'] = $m['aday'];
                $item['skor'] = round((float) $m['score'], 2);
            }
            $belirsiz[] = $item;
        } elseif ($m['score'] < 1.0 && $m['reason'] !== 'tam') {
            // Tam eşleşme değil → yazmadan önce teyit iste (yanlış müşteriye yazma riski).
            $belirsiz[] = [
                'girdi' => $raw, 'sebep' => 'teyit_gerekli',
                'en_yakin' => $m['name'], 'skor' => round((float) $m['score'], 2),
            ];
        } else {
            $matchedIds[] = (int) $m['id'];
            $matchedNames[(int) $m['id']] = $m['name'];
        }
    }
    if ($belirsiz) {
        Helpers::json([
            'ok' => false, 'error' => 'Müşteri netleştirilmeli',
            'netlestir' => 'Şu müşteri(ler) net değil — tam adı doğrula, tahmin etme.',
            'belirsiz' => $belirsiz,
        ], 422);
    }
    $matchedIds = array_values(array_unique($matchedIds));
}

// ── Yaz ───────────────────────────────────────────────
try {
    $txId = $repo->addTransaction(
        'gider', $tutar, $date, $kategori, $aciklama,
        null, null, null, $allocType, $matchedIds
    );
} catch (\Throwable $e) {
    error_log('[UYSA v2 api/gider] ' . $e->getMessage());
    Helpers::json(['ok' => false, 'error' => 'Kayıt hatası'], 500);
}

uysa_audit('api_gider', $actor, $date, json_encode([
    'tutar' => $tutar, 'alloc' => $allocType, 'hedef' => array_values($matchedNames),
], JSON_UNESCAPED_UNICODE), $ip);

// ── Bu giderin ciro-oranlı dağılımını göster (aynı kural — giderDagitim mantığı) ──
$ciro = $repo->customerCiroMap($ay);
$nameMap = $repo->customerNameMap(false);
$targets = $allocType === 'musteri' ? $matchedIds : array_keys($ciro);
$dagitim = [];
$dagitilmamis = 0.0;
if (!$targets) {
    $dagitilmamis = $tutar; // o ay ciro/müşteri yok → dağıtılamadı
} else {
    $sub = 0.0;
    foreach ($targets as $cid) {
        $sub += $ciro[$cid] ?? 0.0;
    }
    if ($sub > 0) {
        foreach ($targets as $cid) {
            $pay = $tutar * (($ciro[$cid] ?? 0.0) / $sub);
            if ($pay > 0) {
                $dagitim[] = ['musteri' => $nameMap[$cid] ?? ('#' . $cid), 'pay' => round($pay, 2)];
            }
        }
    } else {
        $n = count($targets);
        foreach ($targets as $cid) {
            $dagitim[] = ['musteri' => $nameMap[$cid] ?? ('#' . $cid), 'pay' => round($tutar / $n, 2)];
        }
    }
}

Helpers::json([
    'ok'      => true,
    'gider_id' => $txId,
    'tutar'   => $tutar,
    'tarih'   => $date,
    'kapsam'  => $allocType === 'genel' ? 'genel' : 'musteri',
    'hedefler' => array_values($matchedNames),
    'dagitim' => $dagitim,
    'dagitilmamis' => round($dagitilmamis, 2),
]);
