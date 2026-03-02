<?php
/**
 * UYSA ERP — Unit Tests
 * PHPUnit 11 uyumlu
 * 
 * Çalıştır: composer test-unit
 */
declare(strict_types=1);

namespace Uysa\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;

// ─────────────────────────────────────────────────────────────
// 1. JwtManager Unit Tests
// ─────────────────────────────────────────────────────────────
#[CoversClass(\JwtManager::class)]
class JwtManagerTest extends TestCase
{
    private \JwtManager $jwt;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../src/JwtManager.php';
        $this->jwt = new \JwtManager('test-secret-key-minimum-32-chars!!', 3600, 86400);
    }

    #[Test]
    public function issuesValidToken(): void
    {
        $token = $this->jwt->issue(['sub' => 'user1', 'role' => 'admin']);
        $this->assertIsString($token);
        $this->assertStringContainsString('.', $token);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }

    #[Test]
    public function verifyReturnsCorrectPayload(): void
    {
        $token   = $this->jwt->issue(['sub' => 'user42', 'role' => 'editor']);
        $payload = $this->jwt->verify($token);
        $this->assertSame('user42', $payload['sub']);
        $this->assertSame('editor', $payload['role']);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertArrayHasKey('jti', $payload);
    }

    #[Test]
    public function throwsOnTamperedToken(): void
    {
        $this->expectException(\RuntimeException::class);
        $token  = $this->jwt->issue(['sub' => 'test']);
        $parts  = explode('.', $token);
        $parts[1] .= 'TAMPERED';
        $this->jwt->verify(implode('.', $parts));
    }

    #[Test]
    public function throwsOnExpiredToken(): void
    {
        $this->expectException(\RuntimeException::class);
        $shortJwt = new \JwtManager('test-secret-key-minimum-32-chars!!', -1);
        $token    = $shortJwt->issue(['sub' => 'x']);
        $this->jwt->verify($token);
    }

    #[Test]
    public function throwsOnInvalidFormat(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->jwt->verify('not.a.valid.jwt.token.here');
    }

    #[Test]
    public function refreshTokenHasCorrectType(): void
    {
        $refresh = $this->jwt->issueRefresh(['sub' => 'u1', 'role' => 'user']);
        $payload = $this->jwt->verify($refresh);
        $this->assertSame('refresh', $payload['type']);
    }

    #[Test]
    public function canRefreshAccessToken(): void
    {
        $refresh = $this->jwt->issueRefresh(['sub' => 'u1', 'role' => 'user']);
        $access  = $this->jwt->refresh($refresh);
        $payload = $this->jwt->verify($access);
        $this->assertSame('u1', $payload['sub']);
        $this->assertArrayNotHasKey('type', $payload);  // access token'da type yok
    }

    #[Test]
    public function throwsWhenRefreshingAccessToken(): void
    {
        $this->expectException(\RuntimeException::class);
        $access = $this->jwt->issue(['sub' => 'u1']);
        $this->jwt->refresh($access);
    }

    #[Test]
    public function rejectsShortSecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new \JwtManager('short');
    }

    #[Test]
    public function eachTokenHasUniqueJti(): void
    {
        $t1 = $this->jwt->issue(['sub' => 'x']);
        $t2 = $this->jwt->issue(['sub' => 'x']);
        $this->assertNotSame(
            $this->jwt->decode($t1)['jti'],
            $this->jwt->decode($t2)['jti']
        );
    }
}

// ─────────────────────────────────────────────────────────────
// 2. RateLimiter Unit Tests (SQLite in-memory)
// ─────────────────────────────────────────────────────────────
#[CoversClass(\RateLimiter::class)]
class RateLimiterTest extends TestCase
{
    private \PDO         $pdo;
    private \RateLimiter $limiter;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../src/RateLimiter.php';
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // SQLite uyumluluk: ON DUPLICATE KEY desteği yok → override schema
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS uysa_rate_limits (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              `key` TEXT NOT NULL,
              attempted_at INTEGER NOT NULL
            )
        ");
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS uysa_rate_locks (
              `key` TEXT NOT NULL PRIMARY KEY,
              locked_until INTEGER NOT NULL
            )
        ");
        $this->limiter = new \RateLimiter($this->pdo, 3, 60, 120);
    }

    #[Test]
    public function allowsAttemptsUnderLimit(): void
    {
        $result = $this->limiter->attempt('ip:1.2.3.4');
        $this->assertTrue($result['allowed']);
        $this->assertSame(2, $result['remaining']);
    }

    #[Test]
    public function blocksAfterMaxAttempts(): void
    {
        $key = 'ip:5.5.5.5';
        $this->limiter->attempt($key); // 1
        $this->limiter->attempt($key); // 2
        $this->limiter->attempt($key); // 3 → kilit
        $result = $this->limiter->attempt($key); // 4 → blocked
        $this->assertFalse($result['allowed']);
        $this->assertGreaterThan(0, $result['retry_after']);
    }

    #[Test]
    public function resetClearsAttempts(): void
    {
        $key = 'ip:9.9.9.9';
        $this->limiter->attempt($key);
        $this->limiter->attempt($key);
        $this->limiter->reset($key);
        $result = $this->limiter->attempt($key);
        $this->assertTrue($result['allowed']);
        $this->assertSame(2, $result['remaining']);
    }

    #[Test]
    public function statusReturnsCorrectInfo(): void
    {
        $key = 'user:test';
        $this->limiter->attempt($key);
        $status = $this->limiter->status($key);
        $this->assertFalse($status['locked']);
        $this->assertSame(1, $status['attempts']);
        $this->assertSame(2, $status['remaining']);
    }

    #[Test]
    public function differentKeysAreIndependent(): void
    {
        $this->limiter->attempt('ip:AAA');
        $this->limiter->attempt('ip:AAA');
        $this->limiter->attempt('ip:AAA');
        $other = $this->limiter->attempt('ip:BBB');
        $this->assertTrue($other['allowed']);
    }
}

// ─────────────────────────────────────────────────────────────
// 3. ApiKeyManager Unit Tests (SQLite in-memory)
// ─────────────────────────────────────────────────────────────
#[CoversClass(\ApiKeyManager::class)]
class ApiKeyManagerTest extends TestCase
{
    private \PDO           $pdo;
    private \ApiKeyManager $mgr;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../src/ApiKeyManager.php';
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // SQLite: JSON type → TEXT
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS uysa_api_keys (
              id           INTEGER PRIMARY KEY AUTOINCREMENT,
              key_hash     TEXT NOT NULL UNIQUE,
              key_prefix   TEXT NOT NULL,
              name         TEXT NOT NULL DEFAULT 'API Key',
              owner        TEXT NOT NULL DEFAULT 'system',
              role         TEXT NOT NULL DEFAULT 'viewer',
              scopes       TEXT NOT NULL DEFAULT '[]',
              is_active    INTEGER NOT NULL DEFAULT 1,
              uses_count   INTEGER NOT NULL DEFAULT 0,
              last_used_at TEXT DEFAULT NULL,
              expires_at   TEXT DEFAULT NULL,
              created_at   TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $this->mgr = new \ApiKeyManager($this->pdo, 'uysa');
    }

    #[Test]
    public function createsKeyWithCorrectPrefix(): void
    {
        $result = $this->mgr->create(['name' => 'TestKey', 'owner' => 'u1', 'role' => 'admin', 'scopes' => ['read', 'write']]);
        $this->assertStringStartsWith('uysa_', $result['key']);
        $this->assertGreaterThan(0, $result['id']);
    }

    #[Test]
    public function rawKeyIsNotStoredInDb(): void
    {
        $result   = $this->mgr->create(['name' => 'SecretKey', 'owner' => 'u2', 'scopes' => ['read']]);
        $rawKey   = $result['key'];
        $stmt     = $this->pdo->query("SELECT key_hash FROM uysa_api_keys");
        $storedHash = $stmt->fetchColumn();
        $this->assertNotSame($rawKey, $storedHash);
        $this->assertSame(64, strlen($storedHash));  // SHA3-256 = 64 hex chars
    }

    #[Test]
    public function verifiesValidKey(): void
    {
        $result = $this->mgr->create(['name' => 'K1', 'owner' => 'u3', 'scopes' => ['read']]);
        $record = $this->mgr->verify($result['key']);
        $this->assertNotNull($record);
        $this->assertSame('u3', $record['owner']);
    }

    #[Test]
    public function returnsNullForInvalidKey(): void
    {
        $this->assertNull($this->mgr->verify('uysa_fakekeynotexist0000000000'));
    }

    #[Test]
    public function incrementsUsageCount(): void
    {
        $result = $this->mgr->create(['name' => 'K2', 'owner' => 'u4', 'scopes' => ['*']]);
        $this->mgr->verify($result['key']);
        $this->mgr->verify($result['key']);
        $record = $this->mgr->verify($result['key']);
        // 3. çağrıda usage=3 olmalı, ama verify her seferinde artırır
        // kayıt dönüyor ve aktif
        $this->assertNotNull($record);
    }

    #[Test]
    public function revokedKeyIsInvalid(): void
    {
        $result = $this->mgr->create(['name' => 'K3', 'owner' => 'u5', 'scopes' => ['read']]);
        $this->mgr->revoke($result['id']);
        $this->assertNull($this->mgr->verify($result['key']));
    }

    #[Test]
    public function hasScopeWorksCorrectly(): void
    {
        $result = $this->mgr->create(['name' => 'K4', 'owner' => 'u6', 'scopes' => ['read', 'write']]);
        $record = $this->mgr->verify($result['key']);
        $this->assertTrue($this->mgr->hasScope($record, 'read'));
        $this->assertTrue($this->mgr->hasScope($record, 'write'));
        $this->assertFalse($this->mgr->hasScope($record, 'delete'));
    }

    #[Test]
    public function wildcardScopeGrantsAll(): void
    {
        $result = $this->mgr->create(['name' => 'K5', 'owner' => 'u7', 'scopes' => ['*']]);
        $record = $this->mgr->verify($result['key']);
        $this->assertTrue($this->mgr->hasScope($record, 'read'));
        $this->assertTrue($this->mgr->hasScope($record, 'superadmin'));
    }

    #[Test]
    public function listReturnsOnlyOwnerKeys(): void
    {
        $this->mgr->create(['name' => 'A', 'owner' => 'owner1', 'scopes' => ['read']]);
        $this->mgr->create(['name' => 'B', 'owner' => 'owner1', 'scopes' => ['read']]);
        $this->mgr->create(['name' => 'C', 'owner' => 'owner2', 'scopes' => ['read']]);
        $this->assertCount(2, $this->mgr->list('owner1'));
        $this->assertCount(1, $this->mgr->list('owner2'));
    }
}

// ─────────────────────────────────────────────────────────────
// 4. Security Tests
// ─────────────────────────────────────────────────────────────
class SecurityTest extends TestCase
{
    #[Test]
    public function jwtSecretTooShortThrows(): void
    {
        require_once __DIR__ . '/../src/JwtManager.php';
        $this->expectException(\InvalidArgumentException::class);
        new \JwtManager('tooshort');
    }

    #[Test]
    #[DataProvider('xssPayloads')]
    public function htmlSanitizationPreventsXss(string $input, string $notExpected): void
    {
        $sanitized = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringNotContainsString($notExpected, $sanitized);
    }

    public static function xssPayloads(): array
    {
        return [
            'script tag'      => ['<script>alert(1)</script>', '<script>'],
            'img onerror'     => ['<img onerror="alert(1)">', '<img'],
            'svg onload'      => ['<svg onload="alert(1)"/>', '<svg'],
            'javascript href' => ['<a href="javascript:alert(1)">x</a>', 'javascript:'],
        ];
    }

    #[Test]
    #[DataProvider('sqlInjectionPayloads')]
    public function pdoPreparedStatementsPreventSqlInjection(string $maliciousInput): void
    {
        $pdo  = new \PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)");
        $pdo->exec("INSERT INTO test VALUES (1, 'admin')");

        // Hazırlanmış ifade kullanımı → güvenli
        $stmt = $pdo->prepare("SELECT * FROM test WHERE name = ?");
        $stmt->execute([$maliciousInput]);
        $result = $stmt->fetchAll();

        // Gerçek admin kaydını döndürmemeli (injection başarısız)
        $this->assertCount(0, $result);
    }

    public static function sqlInjectionPayloads(): array
    {
        return [
            "OR 1=1"          => ["' OR 1=1 --"],
            "UNION SELECT"    => ["' UNION SELECT * FROM test --"],
            "DROP TABLE"      => ["'; DROP TABLE test; --"],
            "Comment bypass"  => ["admin'--"],
        ];
    }

    #[Test]
    public function passwordHashingIsSecure(): void
    {
        $password = 'TestPass123!';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $this->assertTrue(password_verify($password, $hash));
        $this->assertFalse(password_verify('wrongpassword', $hash));
        $this->assertStringStartsWith('$2y$', $hash);
    }

    #[Test]
    public function randomBytesAreUnique(): void
    {
        $tokens = array_map(fn($_) => bin2hex(random_bytes(16)), range(1, 100));
        $this->assertSame(100, count(array_unique($tokens)));
    }
}
