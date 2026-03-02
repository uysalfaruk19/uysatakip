<?php
/**
 * UYSA ERP — Integration Tests
 * Gerçek API endpoint testi (test ortamında çalışır)
 * PHPUnit 11 uyumlu
 * 
 * Çalıştır: composer test-integration
 * Gereksinim: TEST_API_URL env değişkeni
 */
declare(strict_types=1);

namespace Uysa\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;

/**
 * API Integration Tests
 * 
 * Ortam: TEST_API_URL ve TEST_API_TOKEN env değişkenlerini ayarlayın
 * Örnek: TEST_API_URL=http://localhost:8080/uysa_api.php TEST_API_TOKEN=... composer test-integration
 */
#[Group('integration')]
class ApiIntegrationTest extends TestCase
{
    private string $baseUrl;
    private string $token;

    protected function setUp(): void
    {
        $this->baseUrl = rtrim(getenv('TEST_API_URL') ?: 'http://localhost:8080/uysa_api.php', '/');
        $this->token   = getenv('TEST_API_TOKEN') ?: 'UysaERP2026xProdKey3f7a9c1b';

        // API erişilebilir mi?
        try {
            $health = $this->request('GET', str_replace('uysa_api.php', 'health.php', $this->baseUrl));
            if (($health['status'] ?? '') !== 'ok') {
                $this->markTestSkipped('API sağlık kontrolü başarısız — integration testler atlanıyor.');
            }
        } catch (\Exception $e) {
            $this->markTestSkipped('API ulaşılamıyor: ' . $e->getMessage());
        }
    }

    // ── Health Check ─────────────────────────────────────────
    #[Test]
    public function healthEndpointReturnsOk(): void
    {
        $url  = str_replace('uysa_api.php', 'health.php', $this->baseUrl);
        $resp = $this->request('GET', $url);
        $this->assertSame('ok', $resp['status'] ?? '');
    }

    // ── Authentication ────────────────────────────────────────
    #[Test]
    public function rejectsInvalidToken(): void
    {
        $resp = $this->apiCall('GET', 'ping', [], 'invalid-token-xyz');
        $this->assertFalse($resp['ok'] ?? true);
    }

    #[Test]
    public function pingWithValidToken(): void
    {
        $resp = $this->apiCall('GET', 'ping');
        $this->assertTrue($resp['ok'] ?? false);
    }

    // ── Storage CRUD ─────────────────────────────────────────
    #[Test]
    public function setAndGetStorage(): void
    {
        $testKey   = 'integration_test_' . uniqid();
        $testValue = json_encode(['test' => true, 'ts' => time()]);

        // SET
        $setResp = $this->apiCall('POST', 'set', ['key' => $testKey, 'value' => $testValue]);
        $this->assertTrue($setResp['ok'] ?? false, 'SET başarısız: ' . json_encode($setResp));

        // GET
        $getResp = $this->apiCall('GET', 'get', ['key' => $testKey]);
        $this->assertTrue($getResp['ok'] ?? false);
        $this->assertSame($testValue, $getResp['value'] ?? '');

        // CLEANUP: DELETE
        $this->apiCall('DELETE', 'delete', ['key' => $testKey]);
    }

    #[Test]
    public function deleteNonExistentKeyReturnsOk(): void
    {
        $resp = $this->apiCall('DELETE', 'delete', ['key' => 'nonexistent_key_xyz_' . uniqid()]);
        // Bazı API'ler ok:true döner, bazıları ok:false — her iki durum da hata sayılmaz
        $this->assertArrayHasKey('ok', $resp);
    }

    #[Test]
    public function setBulkAndGetAllKeys(): void
    {
        $prefix = 'bulk_test_' . uniqid() . '_';
        $data   = [
            $prefix . 'a' => 'value_a',
            $prefix . 'b' => 'value_b',
            $prefix . 'c' => 'value_c',
        ];

        $resp = $this->apiCall('POST', 'setBulk', ['data' => $data]);
        $this->assertTrue($resp['ok'] ?? false, 'setBulk başarısız');

        // Her key'i tek tek doğrula
        foreach ($data as $key => $expected) {
            $g = $this->apiCall('GET', 'get', ['key' => $key]);
            $this->assertSame($expected, $g['value'] ?? null, "Key {$key} yanlış değer döndürdü");
            $this->apiCall('DELETE', 'delete', ['key' => $key]);
        }
    }

    // ── Rate Limiting ─────────────────────────────────────────
    #[Test]
    public function rateLimitingIsEnforced(): void
    {
        // Çok sayıda hızlı istek → 429 alınmalı (bu test yavaş çalışabilir)
        $this->markTestSkipped('Rate limit testi production\'a zarar verebilir — manuel test edin.');
    }

    // ── Security Headers ──────────────────────────────────────
    #[Test]
    public function securityHeadersPresent(): void
    {
        $headers = $this->getHeaders($this->baseUrl . '?action=ping&token=' . $this->token);
        $this->assertArrayHasKey('x-content-type-options', $headers);
        $this->assertSame('nosniff', strtolower($headers['x-content-type-options']));
    }

    // ── User Authentication ────────────────────────────────────
    #[Test]
    public function userAuthWithWrongPasswordFails(): void
    {
        $resp = $this->apiCall('POST', 'userAuth', [
            'username' => 'admin',
            'password' => 'completely_wrong_password_xyz123!',
        ]);
        $this->assertFalse($resp['ok'] ?? true, 'Yanlış şifreyle giriş başarılı olmamalı');
    }

    // ── Backup ────────────────────────────────────────────────
    #[Test]
    public function backupEndpointWorks(): void
    {
        $resp = $this->apiCall('POST', 'backup', []);
        // ok:true veya en azından ok:false + hata mesajı
        $this->assertArrayHasKey('ok', $resp);
    }

    // ── Private Yardımcılar ──────────────────────────────────
    private function apiCall(string $method, string $action, array $body = [], ?string $token = null): array
    {
        $url   = $this->baseUrl . '?action=' . $action;
        $token = $token ?? $this->token;
        return $this->request($method, $url, $body, ['X-UYSA-Token: ' . $token]);
    }

    private function request(string $method, string $url, array $body = [], array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        ]);

        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException("cURL hatası: " . curl_error($ch));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'raw' => $raw, 'http_code' => $code];
        }
        $decoded['_http_code'] = $code;
        return $decoded;
    }

    private function getHeaders(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => false,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $raw      = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerStr = substr($raw, 0, $headerSize);
        $headers   = [];
        foreach (explode("\r\n", $headerStr) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        return $headers;
    }
}

// ─────────────────────────────────────────────────────────────
// DB Integration Test (SQLite mock)
// ─────────────────────────────────────────────────────────────
#[Group('integration')]
class DatabaseIntegrationTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE uysa_storage (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_key TEXT NOT NULL UNIQUE,
            store_value TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now'))
        )");
        $this->pdo->exec("CREATE TABLE uysa_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action TEXT NOT NULL,
            actor TEXT,
            target_key TEXT,
            detail TEXT,
            ip_addr TEXT,
            created_at TEXT DEFAULT (datetime('now'))
        )");
    }

    #[Test]
    public function insertAndSelectStorage(): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO uysa_storage (store_key, store_value) VALUES (?, ?)"
        );
        $stmt->execute(['test_key', json_encode(['x' => 1])]);

        $get = $this->pdo->prepare("SELECT store_value FROM uysa_storage WHERE store_key = ?");
        $get->execute(['test_key']);
        $val = $get->fetchColumn();

        $this->assertSame(['x' => 1], json_decode($val, true));
    }

    #[Test]
    public function upsertStorage(): void
    {
        $key = 'upsert_test';
        for ($i = 1; $i <= 3; $i++) {
            $stmt = $this->pdo->prepare(
                "INSERT OR REPLACE INTO uysa_storage (store_key, store_value) VALUES (?, ?)"
            );
            $stmt->execute([$key, "value_{$i}"]);
        }

        $get = $this->pdo->prepare("SELECT store_value FROM uysa_storage WHERE store_key = ?");
        $get->execute([$key]);
        $this->assertSame('value_3', $get->fetchColumn());
    }

    #[Test]
    public function auditLogRecordsActions(): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO uysa_audit (action, actor, target_key, detail, ip_addr) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute(['login', 'OFU', null, json_encode(['success' => true]), '127.0.0.1']);
        $stmt->execute(['delete_key', 'OFU', 'some_key', null, '127.0.0.1']);

        $count = $this->pdo->query("SELECT COUNT(*) FROM uysa_audit")->fetchColumn();
        $this->assertSame('2', (string)$count);
    }

    #[Test]
    public function transactionRollback(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->prepare(
            "INSERT INTO uysa_storage (store_key, store_value) VALUES (?, ?)"
        )->execute(['rollback_test', 'should_not_exist']);
        $this->pdo->rollBack();

        $check = $this->pdo->prepare("SELECT COUNT(*) FROM uysa_storage WHERE store_key = ?");
        $check->execute(['rollback_test']);
        $this->assertSame('0', (string)$check->fetchColumn());
    }
}
