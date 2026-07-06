<?php
declare(strict_types=1);

/**
 * GET /api/musteri/menu — bu müşteriye YAYINLANMIŞ menüler (IDOR scope).
 * audience='all' VEYA menu_target'ta customer_id VAR olan yayınlanmış, süresi geçmemiş menüler.
 * Müşteri A, sadece-B-hedefli menüyü GÖRMEZ.
 */

require __DIR__ . '/_boot_m.php';

use Uysa\Helpers;

$menus = $repo->menusForCustomer($cid);
$out = [];
foreach ($menus as $m) {
    $items = [];
    foreach ($repo->menuItems((int) $m['id']) as $it) {
        $items[] = [
            'tarih'   => $it['item_date'],
            'ogun'    => $it['meal'],
            'yemekler' => $it['dishes'],
        ];
    }
    $out[] = [
        'id'         => (int) $m['id'],
        'baslik'     => $m['title'],
        'date_start' => $m['date_start'],
        'date_end'   => $m['date_end'],
        'gunler'     => $items,
    ];
}
Helpers::json(['ok' => true, 'menu' => $out]);
