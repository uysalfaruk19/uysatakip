<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Env;
use Uysa\Repo;

// Admin dosya servisi: personel oturumu şart. Talep/fatura fotolarını gösterir.
$u = Auth::requireLogin();
$repo = new Repo(Db::pdo());

$id = (int) ($_GET['id'] ?? 0);
$file = $id > 0 ? $repo->fileById($id) : null;
if (!$file) {
    http_response_code(404);
    echo 'Dosya bulunamadı.';
    return;
}
uysa_serve_file($file);
return;

function uysa_serve_file(array $file): void
{
    $dir = Env::get('UPLOAD_DIR') ?: __DIR__ . '/uploads';
    $path = $dir . '/' . basename((string) $file['filename']);
    if (!is_file($path)) {
        http_response_code(404);
        echo 'Dosya diskte yok.';
        return;
    }
    $mime = (string) ($file['mime'] ?: 'application/octet-stream');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: inline; filename="' . rawurlencode((string) $file['original']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    readfile($path);
}
