<?php
declare(strict_types=1);

/**
 * tools/parasut_sync.php — Paraşüt cari SALT-OKUMA senkron (YEREL çalışır).
 *
 * 🔒 MİMARİ (Ömer güvenlik tercihi): Paraşüt kredensiyalleri VPS'e KONMAZ; sadece Ömer'in
 *    PC'sinde durur. Bu araç YEREL çalışır: Paraşüt'ten cari bakiyeleri OKUR (GET),
 *    sonucu Kokpit'e /api/parasut_cari ile X-UYSA-Token'la POST eder. VPS Paraşüt cred görmez.
 *    Paraşüt'e HİÇBİR yazma yapılmaz (opus-013 ayrı iş).
 *
 * Kullanım:
 *   php tools/parasut_sync.php --dry-run     # sadece çek + göster (Kokpit'e yazma yok)
 *   php tools/parasut_sync.php               # çek + Kokpit'e gönder (bakiye yaz)
 *
 * Kredensiyal kaynağı (sırayla):
 *   1) Ortam değişkenleri: PARASUT_CLIENT_ID/CLIENT_SECRET/USERNAME/PASSWORD/COMPANY_ID
 *   2) Dosya: D:\claude\secrets\parasut.txt  (veya PARASUT_SECRETS_FILE ile özel yol)
 *      Esnek format — JSON veya satır satır KEY=VALUE:
 *        client_id=... / client_secret=... / username=... / password=... / company_id=...
 *   Kokpit hedefi: KOKPIT_URL (ör. https://uysatakip...), API_TOKEN (X-UYSA-Token).
 *   Bunlar da secrets dosyasında kokpit_url= / api_token= olarak verilebilir.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Uysa\Parasut;

/**
 * Esnek secrets çözümleyici: önce JSON dener, olmazsa KEY=VALUE satırları.
 * Anahtarları normalize eder (küçük harf, boşluk/tire → alt çizgi) → esnek eşleşme.
 * @return array<string,string> normalize edilmiş anahtar => değer
 */
function parasut_parse_secrets(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $out = [];
    $json = json_decode($raw, true);
    if (is_array($json)) {
        foreach ($json as $k => $v) {
            if (is_scalar($v)) {
                $out[parasut_norm_key((string) $k)] = trim((string) $v);
            }
        }
        return $out;
    }
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $out[parasut_norm_key($k)] = trim($v, " \t\"'");
    }
    return $out;
}

/** Anahtar normalize: 'PARASUT_CLIENT_ID' / 'Client ID' / 'client-id' → 'client_id'. */
function parasut_norm_key(string $k): string
{
    $k = strtolower(trim($k));
    $k = preg_replace('/^parasut[_\s-]*/', '', $k) ?? $k;
    $k = preg_replace('/[\s-]+/', '_', $k) ?? $k;
    return $k;
}

/**
 * Kredensiyalleri env + secrets dosyasından çöz ve putenv ile Parasut sınıfına aktar.
 * @return array{ok:bool, source:string, missing:array<int,string>}
 */
function parasut_load_creds(): array
{
    $need = [
        'client_id'     => 'PARASUT_CLIENT_ID',
        'client_secret' => 'PARASUT_CLIENT_SECRET',
        'username'      => 'PARASUT_USERNAME',
        'password'      => 'PARASUT_PASSWORD',
        'company_id'    => 'PARASUT_COMPANY_ID',
    ];
    $source = 'env';
    $secrets = [];
    // env'de eksik varsa dosyadan tamamla
    $envMissing = false;
    foreach ($need as $env) {
        if ((string) (getenv($env) ?: ($_ENV[$env] ?? '')) === '') {
            $envMissing = true;
        }
    }
    if ($envMissing) {
        $file = getenv('PARASUT_SECRETS_FILE') ?: 'D:\\claude\\secrets\\parasut.txt';
        if (is_file($file)) {
            $secrets = parasut_parse_secrets((string) @file_get_contents($file));
            $source = 'secrets:' . $file;
        }
    }
    $missing = [];
    foreach ($need as $norm => $env) {
        $val = (string) (getenv($env) ?: ($_ENV[$env] ?? ''));
        if ($val === '' && isset($secrets[$norm]) && $secrets[$norm] !== '') {
            $val = $secrets[$norm];
            putenv("$env=$val");
            $_ENV[$env] = $val;
        }
        if ($val === '') {
            $missing[] = $env;
        }
    }
    // Kokpit hedefi de secrets'tan tamamlanabilir
    foreach (['kokpit_url' => 'KOKPIT_URL', 'api_token' => 'API_TOKEN'] as $norm => $env) {
        if ((string) (getenv($env) ?: ($_ENV[$env] ?? '')) === '' && isset($secrets[$norm])) {
            putenv("$env=" . $secrets[$norm]);
            $_ENV[$env] = $secrets[$norm];
        }
    }
    return ['ok' => $missing === [], 'source' => $source, 'missing' => $missing];
}

/** Çekilen contact'ları Kokpit /api/parasut_cari'ye gönder. @return array yanıt */
function parasut_push_to_kokpit(array $contacts): array
{
    $base = rtrim((string) (getenv('KOKPIT_URL') ?: ($_ENV['KOKPIT_URL'] ?? '')), '/');
    $token = (string) (getenv('API_TOKEN') ?: ($_ENV['API_TOKEN'] ?? ''));
    if ($base === '' || $token === '') {
        return ['ok' => false, 'error' => 'KOKPIT_URL / API_TOKEN tanımlı değil (env veya secrets).'];
    }
    $eslesmeler = array_map(static function (array $c): array {
        return [
            'ad'             => $c['name'],
            'vergi_no'       => $c['tax_number'],
            'parasut_id'     => $c['parasut_id'],
            'parasut_bakiye' => $c['balance'],
        ];
    }, $contacts);

    $ch = curl_init($base . '/api/parasut_cari');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['eslesmeler' => $eslesmeler], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-UYSA-Token: ' . $token],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return ['ok' => false, 'error' => 'Kokpit bağlantı hatası: ' . $err];
    }
    $d = json_decode((string) $body, true);
    if (!is_array($d)) {
        return ['ok' => false, 'error' => 'Kokpit yanıtı çözümlenemedi (HTTP ' . $status . ').'];
    }
    return $d;
}

function parasut_main(array $argv): int
{
    $dryRun = in_array('--dry-run', $argv, true);
    fwrite(STDOUT, "═══ Paraşüt cari senkron (SALT-OKUMA) " . ($dryRun ? "[DRY-RUN]" : "[CANLI]") . " ═══\n");

    $creds = parasut_load_creds();
    if (!$creds['ok']) {
        fwrite(STDERR, "HATA: Paraşüt kredensiyali eksik: " . implode(', ', $creds['missing']) . "\n");
        fwrite(STDERR, "  Kaynak denendi: {$creds['source']}\n");
        fwrite(STDERR, "  Çözüm: D:\\claude\\secrets\\parasut.txt (client_id=.. vb.) veya PARASUT_* env.\n");
        return 2;
    }
    fwrite(STDOUT, "Kredensiyal kaynağı: {$creds['source']}\n");

    try {
        $contacts = Parasut::contacts();
    } catch (\Throwable $e) {
        fwrite(STDERR, "HATA: Paraşüt okuma başarısız: " . $e->getMessage() . "\n");
        return 3;
    }
    fwrite(STDOUT, count($contacts) . " müşteri cari çekildi (Paraşüt).\n\n");

    // Önizleme tablosu
    foreach ($contacts as $c) {
        fwrite(STDOUT, sprintf(
            "  %-40s bakiye: %14s  vergi:%-12s id:%s\n",
            mb_substr($c['name'], 0, 40),
            number_format($c['balance'], 2, ',', '.'),
            $c['tax_number'] !== '' ? $c['tax_number'] : '-',
            $c['parasut_id']
        ));
    }
    fwrite(STDOUT, "\n");

    if ($dryRun) {
        fwrite(STDOUT, "DRY-RUN: Kokpit'e yazılmadı. Gerçek senkron için --dry-run'sız çalıştır.\n");
        return 0;
    }

    $resp = parasut_push_to_kokpit($contacts);
    if (empty($resp['ok'])) {
        fwrite(STDERR, "HATA: Kokpit'e gönderim başarısız: " . ($resp['error'] ?? 'bilinmeyen') . "\n");
        return 4;
    }
    $yazilan = is_array($resp['yazilan'] ?? null) ? count($resp['yazilan']) : 0;
    $eslesmeyen = is_array($resp['eslesmeyen'] ?? null) ? $resp['eslesmeyen'] : [];
    fwrite(STDOUT, "Kokpit'e yazıldı: {$yazilan} müşteri bakiyesi güncellendi.\n");
    if ($eslesmeyen) {
        fwrite(STDOUT, "\nEŞLEŞMEYENLER (elle bağla/ekle — oto oluşturma YOK):\n");
        foreach ($eslesmeyen as $u) {
            fwrite(STDOUT, "  - " . ($u['girdi'] ?? '?') . " (" . ($u['sebep'] ?? '') . ")\n");
        }
    }
    return 0;
}

// Sadece doğrudan CLI çağrısında main çalışsın (test include ederken side-effect yok).
if (PHP_SAPI === 'cli' && isset($argv) && basename((string) ($argv[0] ?? '')) === 'parasut_sync.php') {
    exit(parasut_main($argv));
}
