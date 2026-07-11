<?php
declare(strict_types=1);

/**
 * UYSA Kokpit v2 — ortak bootstrap.
 * PSR-4-benzeri basit autoloader + .env + PDO. Her giriş noktası bunu include eder.
 */

// İş saatleri TR'ye göre (sipariş kesimi 15:30, push sessiz saati, "bugün" hesabı).
// Container UTC — set edilmezse kesim fiilen 18:30 TR'de işler, gece 00-03 arası gün kayar.
date_default_timezone_set('Europe/Istanbul');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Uysa\\')) {
        return;
    }
    $rel = str_replace('Uysa\\', '', $class);
    $file = __DIR__ . '/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

\Uysa\Env::load();

if (!function_exists('uysa_pdo')) {
    function uysa_pdo(): \PDO
    {
        return \Uysa\Db::pdo();
    }
}

if (!function_exists('uysa_audit')) {
    function uysa_audit(string $action, ?string $actor, ?string $key = null, ?string $detail = null, string $ip = ''): void
    {
        try {
            \Uysa\Db::pdo()->prepare(
                'INSERT INTO audit (action, actor, target_key, detail, ip_addr) VALUES (?, ?, ?, ?, ?)'
            )->execute([$action, $actor, $key, $detail, $ip]);
        } catch (\Throwable) {
            // audit sessizce başarısız olmalı
        }
    }
}

if (!function_exists('ay_label_tr')) {
    /** 'YYYY-MM' → 'Temmuz 2026' */
    function ay_label_tr(string $ym): string
    {
        $aylar = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
            'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
        [$y, $m] = array_pad(explode('-', $ym), 2, '1');
        return ($aylar[(int) $m] ?? '') . ' ' . $y;
    }
}

if (!function_exists('gun_label_tr')) {
    /** 'YYYY-MM-DD' → '03 Temmuz Cuma' (bugün/dün kısayolu) */
    function gun_label_tr(string $d): string
    {
        $today = date('Y-m-d');
        if ($d === $today) {
            return 'Bugün';
        }
        if ($d === date('Y-m-d', strtotime('-1 day'))) {
            return 'Dün';
        }
        $aylar = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
            'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
        $gunler = ['Sunday' => 'Pazar', 'Monday' => 'Pazartesi', 'Tuesday' => 'Salı',
            'Wednesday' => 'Çarşamba', 'Thursday' => 'Perşembe', 'Friday' => 'Cuma', 'Saturday' => 'Cumartesi'];
        $ts = strtotime($d);
        return sprintf('%02d %s %s', (int) date('d', $ts), $aylar[(int) date('n', $ts)], $gunler[date('l', $ts)] ?? '');
    }
}

if (!function_exists('client_ip')) {
    /**
     * İstemci IP. TRUST_PROXY açıksa (reverse proxy/traefik arkası) X-Forwarded-For'un
     * EN SAĞDAKİ (proxy'nin eklediği, güvenilir) değeri; kapalıysa REMOTE_ADDR.
     * Not: rate-limit anahtarı için kullanılır — istemcinin XFF spoof'u ile atlatılmasını önler.
     */
    function client_ip(): string
    {
        $trust = in_array(strtolower((string) \Uysa\Env::get('TRUST_PROXY', '0')), ['1', 'true', 'yes', 'on'], true);
        if ($trust && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
            $ip = end($parts); // en sağdaki = proxy'nin gördüğü gerçek istemci
            if ($ip !== '') {
                return $ip;
            }
        }
        return trim((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    }
}
