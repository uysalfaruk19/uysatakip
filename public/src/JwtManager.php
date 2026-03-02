<?php
/**
 * UYSA ERP — JWT Manager v1.0
 * HS256 tabanlı, stateless JWT token yönetimi
 * Dosya: public/src/JwtManager.php
 */
declare(strict_types=1);

class JwtManager
{
    private string $secret;
    private int    $ttl;       // saniye cinsinden token ömrü
    private int    $refreshTtl; // refresh token ömrü

    public function __construct(
        string $secret,
        int    $ttl        = 3600,   // 1 saat
        int    $refreshTtl = 604800  // 7 gün
    ) {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('JWT secret en az 32 karakter olmalı.');
        }
        $this->secret     = $secret;
        $this->ttl        = $ttl;
        $this->refreshTtl = $refreshTtl;
    }

    // ── Token Üret ───────────────────────────────────────────
    public function issue(array $payload): string
    {
        $now = time();
        $claims = array_merge($payload, [
            'iat' => $now,
            'exp' => $now + $this->ttl,
            'jti' => bin2hex(random_bytes(16)),
        ]);
        return $this->encode($claims);
    }

    // ── Refresh Token Üret ───────────────────────────────────
    public function issueRefresh(array $payload): string
    {
        $now = time();
        $claims = [
            'sub'  => $payload['sub']  ?? $payload['username'] ?? '',
            'role' => $payload['role'] ?? 'user',
            'type' => 'refresh',
            'iat'  => $now,
            'exp'  => $now + $this->refreshTtl,
            'jti'  => bin2hex(random_bytes(16)),
        ];
        return $this->encode($claims);
    }

    // ── Token Doğrula ────────────────────────────────────────
    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Geçersiz token formatı.');
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        $expected = $this->sign("{$headerB64}.{$payloadB64}");
        if (!hash_equals($expected, $sigB64)) {
            throw new \RuntimeException('Token imzası geçersiz.');
        }

        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Token payload okunamadı.');
        }

        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new \RuntimeException('Token süresi dolmuş.');
        }

        return $payload;
    }

    // ── Token Yenile (access token) ──────────────────────────
    public function refresh(string $refreshToken): string
    {
        $payload = $this->verify($refreshToken);
        if (($payload['type'] ?? '') !== 'refresh') {
            throw new \RuntimeException('Bu bir refresh token değil.');
        }
        return $this->issue([
            'sub'  => $payload['sub'],
            'role' => $payload['role'],
        ]);
    }

    // ── Token Çöz (doğrulamadan) ─────────────────────────────
    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return [];
        return json_decode($this->base64UrlDecode($parts[1]), true) ?? [];
    }

    // ── Private Yardımcılar ──────────────────────────────────
    private function encode(array $payload): string
    {
        $header  = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body    = $this->base64UrlEncode(json_encode($payload));
        $sig     = $this->sign("{$header}.{$body}");
        return "{$header}.{$body}.{$sig}";
    }

    private function sign(string $data): string
    {
        return $this->base64UrlEncode(
            hash_hmac('sha256', $data, $this->secret, true)
        );
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
