<?php
declare(strict_types=1);

/**
 * GET /api/musteri/talepler[?request_id=12] — kendi taleplerin (liste) veya bir talebin
 * mesaj dizisi (scope'lu). request_id başka müşteriye aitse 404.
 */

require __DIR__ . '/_boot_m.php';

use Uysa\Helpers;

if (!empty($_GET['request_id'])) {
    $reqId = (int) $_GET['request_id'];
    $req = $repo->requestForCustomer($reqId, $cid); // IDOR guard
    if (!$req) {
        Helpers::json(['ok' => false, 'error' => 'Talep bulunamadı'], 404);
    }
    $messages = $repo->requestMessages($reqId);
    Helpers::json(['ok' => true, 'talep' => $req, 'mesajlar' => $messages]);
}

$rows = $repo->customerRequests($cid);
Helpers::json(['ok' => true, 'talepler' => $rows]);
