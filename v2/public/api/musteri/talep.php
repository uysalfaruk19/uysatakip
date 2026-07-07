<?php
declare(strict_types=1);

/**
 * POST /api/musteri/talep — yeni talep aç VEYA mevcut talebe mesaj ekle (scope'lu).
 * Yeni talep : { type:"talep|sikayet|mesaj", subject:"...", body?:"...", csrf }
 * Mesaj ekle : { request_id:12, body:"...", csrf }  (talep sahibi müşteri değilse 404)
 */

require __DIR__ . '/_boot_m.php';

use Uysa\Helpers;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Helpers::json(['ok' => false, 'error' => 'POST gerekli'], 405);
}

$msg = trim((string) ($body['body'] ?? ''));

// ── Mevcut talebe mesaj ekle ──────────────────────────────────
if (!empty($body['request_id'])) {
    $reqId = (int) $body['request_id'];
    $req = $repo->requestForCustomer($reqId, $cid); // IDOR guard: sadece kendi talebi
    if (!$req) {
        Helpers::json(['ok' => false, 'error' => 'Talep bulunamadı'], 404);
    }
    if ($msg === '') {
        Helpers::json(['ok' => false, 'error' => 'Mesaj boş olamaz'], 400);
    }
    $repo->addRequestMessage($reqId, 'musteri', mb_substr($msg, 0, 2000));
    if ($req['status'] === 'cozuldu') {
        $repo->setRequestStatus($reqId, 'acik'); // yeni mesaj → yeniden aç
    }
    uysa_audit('musteri_talep_mesaj', $cu['username'] ?? '', (string) $cid, (string) $reqId, $ip);
    Helpers::json(['ok' => true, 'request_id' => $reqId]);
}

// ── Yeni talep ────────────────────────────────────────────────
$type = (string) ($body['type'] ?? 'talep');
if (!in_array($type, ['talep', 'sikayet', 'mesaj'], true)) {
    $type = 'talep';
}
$subject = trim((string) ($body['subject'] ?? ''));
if ($subject === '') {
    Helpers::json(['ok' => false, 'error' => 'Konu boş olamaz'], 400);
}
$reqId = $repo->createRequest($cid, $cu['cuid'] ?? null, $type, mb_substr($subject, 0, 200));
if ($msg !== '') {
    $repo->addRequestMessage($reqId, 'musteri', mb_substr($msg, 0, 2000));
}
uysa_audit('musteri_talep_yeni', $cu['username'] ?? '', (string) $cid, (string) $reqId, $ip);

Helpers::json(['ok' => true, 'request_id' => $reqId, 'type' => $type]);
