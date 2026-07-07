<?php
declare(strict_types=1);

/**
 * /api/musteri/malzeme — sarf malzeme talebi (IDOR scope: daima oturumdaki customer_id).
 *   GET  → katalog + KENDİ hakedişi + geçmiş talepler
 *   POST → yeni talep { items:{supply_item_id:miktar,...}, note?, csrf }
 */

require __DIR__ . '/_boot_m.php';

use Uysa\Helpers;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw = (array) ($body['items'] ?? []);
    $items = [];
    foreach ($raw as $itemId => $qty) {
        $q = (float) $qty;
        if ((int) $itemId > 0 && $q > 0) {
            $items[(int) $itemId] = $q;
        }
    }
    if (!$items) {
        Helpers::json(['ok' => false, 'error' => 'En az bir kalem için miktar girin'], 400);
    }
    $note = isset($body['note']) ? mb_substr(trim((string) $body['note']), 0, 500) : null;
    $reqId = $repo->createSupplyRequest($cid, $items, $cu['cuid'] ?? null, $note);
    uysa_audit('musteri_malzeme', $cu['username'] ?? '', (string) $cid, (string) $reqId, $ip);
    Helpers::json(['ok' => true, 'request_id' => $reqId]);
}

// GET
$ent = $repo->getEntitlements($cid);
$katalog = [];
foreach ($repo->listSupplyItems(true) as $it) {
    $katalog[] = [
        'id'     => (int) $it['id'],
        'ad'     => $it['ad'],
        'birim'  => $it['birim'],
        'hakkin' => $ent[(int) $it['id']] ?? 0.0,
    ];
}
$gecmis = [];
foreach ($repo->supplyRequestsForCustomer($cid) as $r) {
    $gecmis[] = [
        'id'        => (int) $r['id'],
        'tarih'     => $r['request_date'],
        'durum'     => $r['status'],
        'kalem'     => (int) $r['item_count'],
        'kalemler'  => $repo->supplyRequestItems((int) $r['id']),
    ];
}
Helpers::json(['ok' => true, 'katalog' => $katalog, 'gecmis' => $gecmis]);
