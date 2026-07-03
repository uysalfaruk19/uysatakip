<?php
declare(strict_types=1);

/**
 * PHP yerleşik GELİŞTİRME sunucusu yönlendiricisi + üretim referansı.
 * Çalıştır:  php -S 127.0.0.1:8099 -t public router.php
 * - /api/uretim → public/api/uretim.php (pretty URL)
 * - .php sayfalar → doğrudan require (public/ kökünden)
 * - statik dosyalar (css/js/img) → doğrudan servis
 *
 * ÜRETİM: web kökü = public/. Pretty /api/* için Apache rewrite public/.htaccess'te
 * (aynı mantık); Nginx/traefik'te eşdeğer `try_files`/rewrite kurulur. Bu router yalnız
 * geliştirme sunucusu içindir; üretimde çalışmaz.
 */

$pub = __DIR__ . '/public';
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

// Pretty API: /api/uretim → public/api/uretim.php
if (preg_match('#^/api/([a-z_]+)/?$#', $uri, $m)) {
    $f = $pub . '/api/' . $m[1] . '.php';
    if (is_file($f)) {
        require $f;
        return true;
    }
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Bilinmeyen endpoint'], JSON_UNESCAPED_UNICODE);
    return true;
}

if ($uri === '/' || $uri === '') {
    require $pub . '/index.php';
    return true;
}

$path = realpath($pub . $uri);
$base = realpath($pub);
// Güvenlik: yalnız public/ altındaki dosyalar
if ($path !== false && $base !== false && str_starts_with($path, $base) && is_file($path)) {
    if (str_ends_with($path, '.php')) {
        require $path;
        return true;
    }
    // Statik dosyayı doğrudan servis et (yerleşik sunucunun return-false davranışına güvenme)
    $mimes = [
        'css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'json' => 'application/json',
    ];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream') . '; charset=utf-8');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    return true;
}

http_response_code(404);
echo 'Bulunamadı';
return true;
