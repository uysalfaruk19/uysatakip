<?php
declare(strict_types=1);

/**
 * GET /api/musteri/menu?from=YYYY-MM-DD&to=YYYY-MM-DD&meal=ogle — yayınlanan menü.
 * Menü tüm müşterilere ortak (yayın = is_published); kişisel veri yok, yine de oturum zorunlu.
 */

require __DIR__ . '/_boot_m.php';

use Uysa\Helpers;

$from = (string) ($_GET['from'] ?? Helpers::today());
$to = (string) ($_GET['to'] ?? date('Y-m-d', strtotime('+6 day')));
if (!Helpers::isDate($from)) {
    $from = Helpers::today();
}
if (!Helpers::isDate($to)) {
    $to = date('Y-m-d', strtotime($from . ' +6 day'));
}
$meal = (string) ($_GET['meal'] ?? 'ogle');
if (!in_array($meal, ['sabah', 'ogle', 'aksam', 'gece', 'kumanya'], true)) {
    $meal = 'ogle';
}

$rows = $repo->publishedMenu($from, $to, $meal);
Helpers::json(['ok' => true, 'from' => $from, 'to' => $to, 'ogun' => $meal, 'menu' => $rows]);
