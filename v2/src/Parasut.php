<?php
declare(strict_types=1);

namespace Uysa;

/**
 * Paraşüt SALT-OKUMA istemcisi (opus-012).
 *
 * 🔒 ALTIN KURAL (BAĞLAYICI — vault/projeler/parasut-entegrasyon):
 *   Bu sınıf Paraşüt VERİ API'sine (/v4/{company}/...) YALNIZCA GET yapar.
 *   Fatura/cari YAZMA (POST/PUT/DELETE) YOK — o iş opus-013, emsal→öneri→Ömer onayı akışıyla.
 *   Buradaki TEK POST çağrısı OAuth2 token endpoint'inedir (/oauth/token); bu kimlik
 *   doğrulamadır, muhasebe verisine yazma DEĞİL. Veri uçlarına sadece get()/contacts() erişir.
 *
 * Kredensiyaller koda GÖMÜLMEZ — Env::get ile .env(.v2)'den okunur:
 *   PARASUT_CLIENT_ID, PARASUT_CLIENT_SECRET, PARASUT_USERNAME, PARASUT_PASSWORD, PARASUT_COMPANY_ID
 */
final class Parasut
{
    private const OAUTH_URL = 'https://api.parasut.com/oauth/token';
    private const API_BASE  = 'https://api.parasut.com/v4';
    private const REDIRECT  = 'urn:ietf:wg:oauth:2.0:oob';
    private const TIMEOUT   = 20;
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 100; // güvenlik freni (sonsuz sayfalamayı önle)

    /** @var array<string,mixed>|null bellek-içi token cache (istek boyunca) */
    private static $memToken = null;

    /**
     * Env'de tüm Paraşüt kredensiyalleri tanımlı mı? (parasut.php bunu kontrol edip
     * eksikse kullanıcıya "creds .env.v2'de yok" der; ağ çağrısı denemez.)
     */
    public static function configured(): bool
    {
        foreach (['PARASUT_CLIENT_ID', 'PARASUT_CLIENT_SECRET', 'PARASUT_USERNAME', 'PARASUT_PASSWORD', 'PARASUT_COMPANY_ID'] as $k) {
            if ((string) Env::get($k, '') === '') {
                return false;
            }
        }
        return true;
    }

    /** Token cache dosya yolu (kalıcı; uploads ile aynı mantık). */
    private static function tokenCachePath(): string
    {
        $dir = (string) Env::get('UPLOAD_DIR', '');
        if ($dir === '' || !is_dir($dir)) {
            $dir = dirname(__DIR__) . '/storage';
            if (!is_dir($dir)) {
                @mkdir($dir, 0770, true);
            }
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            // Son çare: sistem temp (yazılabilir olduğu garanti).
            $dir = sys_get_temp_dir();
        }
        return rtrim($dir, '/\\') . '/parasut_token.json';
    }

    /**
     * Geçerli Bearer access_token döndür (cache + gerekirse yenile/al).
     * Sıra: bellek → dosya cache (süresi dolmadıysa) → refresh_token → password grant.
     */
    public static function token(): string
    {
        if (is_array(self::$memToken) && self::tokenFresh(self::$memToken)) {
            return (string) self::$memToken['access_token'];
        }

        $cache = self::readTokenCache();
        if (is_array($cache) && self::tokenFresh($cache)) {
            self::$memToken = $cache;
            return (string) $cache['access_token'];
        }

        // Süresi geçmiş ama refresh_token varsa → yenile.
        if (is_array($cache) && !empty($cache['refresh_token'])) {
            $refreshed = self::requestToken([
                'grant_type'    => 'refresh_token',
                'client_id'     => (string) Env::get('PARASUT_CLIENT_ID', ''),
                'client_secret' => (string) Env::get('PARASUT_CLIENT_SECRET', ''),
                'refresh_token' => (string) $cache['refresh_token'],
            ]);
            if ($refreshed !== null) {
                return (string) $refreshed['access_token'];
            }
        }

        // İlk kez / refresh başarısız → password grant.
        $fresh = self::requestToken([
            'grant_type'    => 'password',
            'client_id'     => (string) Env::get('PARASUT_CLIENT_ID', ''),
            'client_secret' => (string) Env::get('PARASUT_CLIENT_SECRET', ''),
            'username'      => (string) Env::get('PARASUT_USERNAME', ''),
            'password'      => (string) Env::get('PARASUT_PASSWORD', ''),
            'redirect_uri'  => self::REDIRECT,
        ]);
        if ($fresh === null) {
            throw new \RuntimeException('Paraşüt kimlik doğrulama başarısız (creds/ağ).');
        }
        return (string) $fresh['access_token'];
    }

    /**
     * Bearer'lı GET — JSON:API gövdesini dizi olarak döndürür.
     * $path örn: "/contacts". $query örn: ['filter[account_type]' => 'customer'].
     * Ağ hatası/401/timeout → RuntimeException (çağıran yakalar, çökme yok).
     */
    public static function get(string $path, array $query = []): array
    {
        $company = (string) Env::get('PARASUT_COMPANY_ID', '');
        if ($company === '') {
            throw new \RuntimeException('PARASUT_COMPANY_ID tanımlı değil.');
        }
        $url = self::API_BASE . '/' . rawurlencode($company) . '/' . ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        $token = self::token();

        [$status, $body] = self::httpGet($url, ['Authorization: Bearer ' . $token, 'Accept: application/json']);

        if ($status === 401) {
            // Token bayatlamış olabilir → cache'i temizle, bir kez daha dene.
            self::$memToken = null;
            @unlink(self::tokenCachePath());
            $token = self::token();
            [$status, $body] = self::httpGet($url, ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Paraşüt GET hatası (HTTP ' . $status . ').');
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Paraşüt yanıtı çözümlenemedi.');
        }
        return $data;
    }

    /**
     * Tüm müşteri (account_type=customer) contact'ları — sayfalı çekilir, düzleştirilir.
     * @return array<int,array{parasut_id:string,name:string,balance:float,tax_number:string,tax_office:string,email:string,phone:string}>
     */
    public static function contacts(): array
    {
        $out = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $resp = self::get('/contacts', [
                'filter[account_type]' => 'customer',
                'page[size]'           => self::PAGE_SIZE,
                'page[number]'         => $page,
            ]);
            $batch = self::parseContacts($resp);
            foreach ($batch as $c) {
                $out[] = $c;
            }
            // Sayfa dolmadıysa son sayfadayız.
            if (count($resp['data'] ?? []) < self::PAGE_SIZE) {
                break;
            }
        }
        return $out;
    }

    /**
     * JSON:API contact yanıtını sade dizilere çevir (PÜR — ağ yok, test edilebilir).
     * $resp = ['data' => [ ['id'=>.., 'attributes'=>['name'=>.., 'balance'=>..]], ... ]].
     * @return array<int,array{parasut_id:string,name:string,balance:float,tax_number:string,tax_office:string,email:string,phone:string}>
     */
    public static function parseContacts(array $resp): array
    {
        $rows = $resp['data'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $a = $r['attributes'] ?? [];
            $a = is_array($a) ? $a : [];
            $out[] = [
                'parasut_id' => (string) ($r['id'] ?? ''),
                'name'       => trim((string) ($a['name'] ?? '')),
                'balance'    => (float) ($a['balance'] ?? 0),
                'tax_number' => trim((string) ($a['tax_number'] ?? '')),
                'tax_office' => trim((string) ($a['tax_office'] ?? '')),
                'email'      => trim((string) ($a['email'] ?? '')),
                'phone'      => trim((string) ($a['phone'] ?? '')),
            ];
        }
        return $out;
    }

    // ── iç yardımcılar ───────────────────────────────────────────

    /** Token cache'i (istenleşen 5 dk emniyet payıyla) hâlâ taze mi? */
    private static function tokenFresh(array $tok): bool
    {
        return !empty($tok['access_token'])
            && isset($tok['expires_at'])
            && (int) $tok['expires_at'] - 300 > time();
    }

    private static function readTokenCache(): ?array
    {
        $path = self::tokenCachePath();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    }

    private static function writeTokenCache(array $tok): void
    {
        $path = self::tokenCachePath();
        @file_put_contents($path, json_encode($tok), LOCK_EX);
        @chmod($path, 0600);
    }

    /**
     * OAuth token isteği (password / refresh_token grant). Başarılıysa cache'ler ve döndürür.
     * NOT: Bu tek POST kimlik doğrulama içindir; muhasebe verisine YAZMA değildir.
     */
    private static function requestToken(array $form): ?array
    {
        [$status, $body] = self::httpPostForm(self::OAUTH_URL, $form);
        if ($status < 200 || $status >= 300) {
            return null;
        }
        $d = json_decode($body, true);
        if (!is_array($d) || empty($d['access_token'])) {
            return null;
        }
        $expiresIn = (int) ($d['expires_in'] ?? 7200);
        $tok = [
            'access_token'  => (string) $d['access_token'],
            'refresh_token' => (string) ($d['refresh_token'] ?? ($form['refresh_token'] ?? '')),
            'expires_at'    => time() + max(60, $expiresIn),
        ];
        self::$memToken = $tok;
        self::writeTokenCache($tok);
        return $tok;
    }

    /**
     * @return array{0:int,1:string} [httpStatus, body]
     */
    private static function httpGet(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('Paraşüt bağlantı hatası: ' . ($err !== '' ? $err : 'timeout'));
        }
        return [$status, (string) $body];
    }

    /**
     * OAuth için form-encoded POST (kimlik doğrulama).
     * @return array{0:int,1:string} [httpStatus, body]
     */
    private static function httpPostForm(string $url, array $form): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($form),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            return [0, ''];
        }
        return [$status, (string) $body];
    }
}
