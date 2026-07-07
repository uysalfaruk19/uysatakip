<?php
declare(strict_types=1);

namespace Uysa;

/**
 * APNs HTTP/2 push gönderici — FCM'siz, doğrudan Apple (token-based auth, .p8 anahtar).
 * Env: APNS_TEAM_ID, APNS_KEY_ID, APNS_KEY_FILE (.p8 yolu), APNS_TOPIC (bundle id), APNS_ENV (production|sandbox).
 * JWT ~50 dk cache'lenir (Apple kuralı: 20-60 dk arası yenile).
 */
final class Apns
{
    private string $teamId;
    private string $keyId;
    private string $keyFile;
    private string $topic;
    private string $host;

    public function __construct()
    {
        $this->teamId  = (string) Env::get('APNS_TEAM_ID', '');
        $this->keyId   = (string) Env::get('APNS_KEY_ID', '');
        $this->keyFile = (string) Env::get('APNS_KEY_FILE', '');
        $this->topic   = (string) Env::get('APNS_TOPIC', 'com.uysa.kokpit');
        $this->host    = Env::get('APNS_ENV', 'production') === 'sandbox'
            ? 'https://api.sandbox.push.apple.com'
            : 'https://api.push.apple.com';
    }

    public function configured(): bool
    {
        return $this->teamId !== '' && $this->keyId !== '' && $this->keyFile !== '' && is_readable($this->keyFile);
    }

    /**
     * Tek cihaza alert push. Dönen: ['ok'=>bool, 'status'=>int, 'reason'=>string]
     * reason 'BadDeviceToken'/'Unregistered' ise token DB'den silinmeli (çağıran yapar).
     */
    public function send(string $deviceToken, string $title, string $body, array $data = []): array
    {
        $payload = [
            'aps' => [
                'alert' => ['title' => $title, 'body' => $body],
                'sound' => 'default',
                'badge' => 1,
            ],
        ] + $data;

        $ch = curl_init($this->host . '/3/device/' . $deviceToken);
        curl_setopt_array($ch, [
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'authorization: bearer ' . $this->jwt(),
                'apns-topic: ' . $this->topic,
                'apns-push-type: alert',
                'apns-priority: 10',
                'content-type: application/json',
            ],
        ]);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['ok' => false, 'status' => 0, 'reason' => 'curl: ' . $err];
        }
        $reason = '';
        if ($status !== 200) {
            $j = json_decode((string) $resp, true);
            $reason = is_array($j) ? (string) ($j['reason'] ?? '') : (string) $resp;
        }
        return ['ok' => $status === 200, 'status' => $status, 'reason' => $reason];
    }

    /** ES256 JWT — süreç içi cache (~50 dk). */
    private function jwt(): string
    {
        static $cached = null, $cachedAt = 0;
        if ($cached !== null && (time() - $cachedAt) < 3000) {
            return $cached;
        }
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $header = $b64(json_encode(['alg' => 'ES256', 'kid' => $this->keyId]));
        $claims = $b64(json_encode(['iss' => $this->teamId, 'iat' => time()]));
        $input = $header . '.' . $claims;

        $key = openssl_pkey_get_private('file://' . $this->keyFile);
        if ($key === false) {
            throw new \RuntimeException('APNs .p8 anahtarı okunamadı: ' . $this->keyFile);
        }
        if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('APNs JWT imzalanamadı');
        }
        $cached = $input . '.' . $b64(self::derToRaw($der));
        $cachedAt = time();
        return $cached;
    }

    /** OpenSSL'in DER (SEQUENCE{INTEGER r, INTEGER s}) imzasını JWT'nin istediği ham 64 bayta çevir. */
    private static function derToRaw(string $der): string
    {
        $pos = 1; // 0x30 atla
        $len = ord($der[$pos++]);
        if ($len & 0x80) {
            $pos += $len & 0x7f;
        }
        $pos++; // 0x02
        $rlen = ord($der[$pos++]);
        $r = ltrim(substr($der, $pos, $rlen), "\x00");
        $pos += $rlen;
        $pos++; // 0x02
        $slen = ord($der[$pos++]);
        $s = ltrim(substr($der, $pos, $slen), "\x00");
        return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }
}
