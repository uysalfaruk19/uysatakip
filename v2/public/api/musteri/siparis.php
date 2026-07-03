<?php
declare(strict_types=1);

/**
 * POST /api/musteri/siparis — müşteri kendi siparişini (kişi sayısı) girer/günceller.
 * Girdi: { date:"YYYY-MM-DD", meal:"ogle", persons:120, note?, csrf }
 * Scope: daima oturumdaki customer_id (IDOR yok). status=gonderildi, entered_by=musteri.
 * Son değişiklik: bir gün önce 16:00'a kadar (deadline geçtiyse 409).
 */

require __DIR__ . '/_boot_m.php';

use Uysa\Helpers;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Helpers::json(['ok' => false, 'error' => 'POST gerekli'], 405);
}

$date = (string) ($body['date'] ?? '');
if (!Helpers::isDate($date)) {
    Helpers::json(['ok' => false, 'error' => 'Geçersiz tarih (YYYY-MM-DD)'], 400);
}
$meal = (string) ($body['meal'] ?? 'ogle');
if (!in_array($meal, ['sabah', 'ogle', 'aksam', 'gece', 'kumanya'], true)) {
    $meal = 'ogle';
}
if (!Helpers::orderEditable($date)) {
    Helpers::json(['ok' => false, 'error' => 'Değişiklik süresi doldu (bir gün önce 16:00).'], 409);
}
$persons = (int) ($body['persons'] ?? 0);
if ($persons < 0 || $persons > 100000) {
    Helpers::json(['ok' => false, 'error' => 'Kişi sayısı 0-100000 aralığında olmalı'], 400);
}
$note = isset($body['note']) ? mb_substr(trim((string) $body['note']), 0, 500) : null;

$orderId = $repo->upsertOrder($cid, $date, $meal, $persons, 'gonderildi', 'musteri', null, $note);
uysa_audit('musteri_siparis', $cu['username'] ?? '', (string) $cid, json_encode(['d' => $date, 'm' => $meal, 'p' => $persons]), $ip);

Helpers::json([
    'ok'      => true,
    'siparis' => ['id' => $orderId, 'tarih' => $date, 'ogun' => $meal, 'kisi' => $persons, 'durum' => 'gonderildi'],
]);
