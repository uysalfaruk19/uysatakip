<?php
declare(strict_types=1);

/**
 * GET /api/menu?gun=YYYY-MM-DD        → o günün yayınlanmış menüsü (dishes)
 * GET /api/menu?hafta=YYYY-MM-DD      → o tarihi kapsayan hafta (Pzt-Paz)
 * GET /api/menu?from=..&to=..         → serbest aralık
 * (parametresiz → bugün). Opsiyonel &ogun=ogle|sabah|... öğün filtresi.
 * Yayınlanmış (status='published') tüm menüler; müşteri-scope yok (Ömer/bot görünümü).
 */

require __DIR__ . '/_boot.php';

use Uysa\Helpers;

$gun   = (string) ($_GET['gun'] ?? '');
$hafta = (string) ($_GET['hafta'] ?? '');
$from  = (string) ($_GET['from'] ?? '');
$to    = (string) ($_GET['to'] ?? '');

$meal = $_GET['ogun'] ?? null;
if ($meal !== null && !in_array($meal, ['sabah', 'ogle', 'aksam', 'gece', 'kumanya'], true)) {
    $meal = null;
}

if ($hafta !== '') {
    if (!Helpers::isDate($hafta)) {
        Helpers::json(['ok' => false, 'error' => 'Geçersiz hafta tarihi (YYYY-MM-DD)'], 400);
    }
    $dow = (int) date('N', strtotime($hafta)); // 1=Pzt .. 7=Paz
    $from = date('Y-m-d', strtotime($hafta . ' -' . ($dow - 1) . ' days'));
    $to   = date('Y-m-d', strtotime($from . ' +6 days'));
} elseif ($gun !== '') {
    if (!Helpers::isDate($gun)) {
        Helpers::json(['ok' => false, 'error' => 'Geçersiz gün (YYYY-MM-DD)'], 400);
    }
    $from = $to = $gun;
} elseif ($from !== '' || $to !== '') {
    if (!Helpers::isDate($from) || !Helpers::isDate($to)) {
        Helpers::json(['ok' => false, 'error' => 'from ve to geçerli tarih olmalı (YYYY-MM-DD)'], 400);
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
} else {
    $from = $to = Helpers::today();
}

$gunler = [];
foreach ($repo->publishedMenuItems($from, $to, $meal) as $it) {
    $gunler[] = [
        'tarih'    => $it['item_date'],
        'ogun'     => $it['meal'],
        'yemekler' => $it['dishes'],
        'menu'     => $it['menu_title'],
    ];
}

Helpers::json([
    'ok'     => true,
    'aralik' => ['from' => $from, 'to' => $to],
    'ogun'   => $meal,
    'adet'   => count($gunler),
    'gunler' => $gunler,
]);
