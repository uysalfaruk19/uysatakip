<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Uysa\CustomerAuth;
use Uysa\Db;
use Uysa\Env;
use Uysa\Repo;

// Müşteri dosya servisi: SADECE kendi talep mesajına ekli foto (IDOR scope). Başka firmanınki 404.
$cu = CustomerAuth::requireCustomer();
$cid = (int) $cu['customer_id'];
$repo = new Repo(Db::pdo());

$id = (int) ($_GET['id'] ?? 0);
$file = $id > 0 ? $repo->customerFile($id, $cid) : null;
if (!$file) {
    http_response_code(404);
    echo 'Dosya bulunamadı.';
    return;
}

$dir = Env::get('UPLOAD_DIR') ?: dirname(__DIR__) . '/uploads';
$path = $dir . '/' . basename((string) $file['filename']);
if (!is_file($path)) {
    http_response_code(404);
    echo 'Dosya diskte yok.';
    return;
}
header('Content-Type: ' . (string) ($file['mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . rawurlencode((string) $file['original']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($path);
