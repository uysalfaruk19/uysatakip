<?php
declare(strict_types=1);

/**
 * PHP yerleşik sunucu yönlendiricisi — HEM geliştirme HEM ÜRETİM.
 * Çalıştır:  php -S 0.0.0.0:8080 -t public router.php
 * - /api/uretim → public/api/uretim.php (pretty URL)
 * - /eski/*     → v1 arşivi (salt-okunur; yazma eylemleri 403)
 * - .php sayfalar → doğrudan require (public/ kökünden)
 * - statik dosyalar (css/js/img) → doğrudan servis
 *
 * Üretimde container CMD bu router'ı kullanır (v1 ile aynı php -S deseni). Apache'ye
 * taşınırsa public/.htaccess aynı /api rewrite'ını yapar (yedek).
 */

$pub = __DIR__ . '/public';
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

// ── v1 ARŞİV (salt-okunur) — /eski ──────────────────────────────
// v1 SPA /eski/index.html'den çalışır; uysa_api.php YAZMA eylemleri 403.
// v1 kendi DB'sini (uysa_db) okur; v2 uysa_v2 kullanır → arşiv canlı görünür ama dokunulamaz.
if ($uri === '/eski' || $uri === '/eski/') {
    header('Location: /eski/index.html');
    return true;
}
if (str_starts_with($uri, '/eski/')) {
    $eskiBase = realpath(__DIR__ . '/eski');
    if ($eskiBase === false) {
        http_response_code(404);
        echo 'Arşiv yok';
        return true;
    }
    $rel = ltrim(rawurldecode(substr($uri, strlen('/eski/'))), '/');
    if ($rel === 'uysa_api.php') {
        $writes = ['set', 'setBulk', 'delete', 'backup', 'backupRestore', 'userSave', 'fileUpload', 'fileDelete', 'auditLog'];
        if (in_array(trim($_GET['action'] ?? ''), $writes, true)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'v1 arşiv salt-okunur. Yeni sistem: / ve /api'], JSON_UNESCAPED_UNICODE);
            return true;
        }
        chdir($eskiBase);
        require $eskiBase . '/uysa_api.php';
        return true;
    }
    $p = realpath($eskiBase . '/' . $rel);
    if ($p !== false && str_starts_with($p, $eskiBase) && is_file($p)) {
        if (str_ends_with($p, '.php')) {
            chdir($eskiBase);
            require $p;
            return true;
        }
        $mimes = [
            'css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp', 'gif' => 'image/gif', 'ico' => 'image/x-icon',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'json' => 'application/json',
            'html' => 'text/html', 'htm' => 'text/html', 'map' => 'application/json',
        ];
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream') . '; charset=utf-8');
        header('Content-Length: ' . (string) filesize($p));
        readfile($p);
        return true;
    }
    http_response_code(404);
    echo 'Bulunamadı';
    return true;
}

// Müşteri API (oturumlu): /api/musteri/siparis → public/api/musteri/siparis.php
if (preg_match('#^/api/musteri/([a-z_]+)/?$#', $uri, $m)) {
    $f = $pub . '/api/musteri/' . $m[1] . '.php';
    if (is_file($f)) {
        require $f;
        return true;
    }
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Bilinmeyen endpoint'], JSON_UNESCAPED_UNICODE);
    return true;
}

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
