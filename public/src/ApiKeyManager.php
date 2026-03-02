<?php
/**
 * UYSA ERP — API Key Manager v1.0
 * Güvenli API key üretimi, doğrulama ve yönetimi
 * Dosya: public/src/ApiKeyManager.php
 */
declare(strict_types=1);

class ApiKeyManager
{
    private PDO    $pdo;
    private string $prefix;

    public function __construct(PDO $pdo, string $prefix = 'uysa')
    {
        $this->pdo    = $pdo;
        $this->prefix = $prefix;
        $this->ensureTable();
    }

    // ── API Key Üret ─────────────────────────────────────────
    /**
     * @param  array{name:string, owner:string, role:string, scopes:array, expires_days:int} $opts
     * @return array{key:string, id:int}
     */
    public function create(array $opts): array
    {
        $rawKey   = $this->prefix . '_' . bin2hex(random_bytes(24));  // 48 hex = 192-bit
        $keyHash  = $this->hash($rawKey);
        $expiresAt = null;

        if (!empty($opts['expires_days'])) {
            $expiresAt = date('Y-m-d H:i:s', time() + $opts['expires_days'] * 86400);
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO uysa_api_keys
              (key_hash, key_prefix, name, owner, role, scopes, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $keyHash,
            substr($rawKey, 0, 12) . '…',
            $opts['name']   ?? 'API Key',
            $opts['owner']  ?? 'system',
            $opts['role']   ?? 'viewer',
            json_encode($opts['scopes'] ?? ['read']),
            $expiresAt,
        ]);

        return ['key' => $rawKey, 'id' => (int)$this->pdo->lastInsertId()];
    }

    // ── API Key Doğrula ──────────────────────────────────────
    /**
     * @return array|null  Başarılıysa key kaydı, değilse null
     */
    public function verify(string $rawKey): ?array
    {
        $keyHash = $this->hash($rawKey);
        $stmt    = $this->pdo->prepare("
            SELECT * FROM uysa_api_keys
            WHERE key_hash = ?
              AND is_active = 1
              AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([$keyHash]);
        $row = $stmt->fetch();

        if (!$row) return null;

        // Kullanım sayısını artır
        $this->pdo->prepare(
            'UPDATE uysa_api_keys SET uses_count = uses_count + 1, last_used_at = NOW() WHERE id = ?'
        )->execute([$row['id']]);

        $row['scopes'] = json_decode($row['scopes'], true) ?? [];
        return $row;
    }

    // ── Scope Kontrolü ───────────────────────────────────────
    public function hasScope(array $keyRecord, string $scope): bool
    {
        return in_array($scope, $keyRecord['scopes'], true)
            || in_array('*', $keyRecord['scopes'], true);
    }

    // ── Key İptal Et ─────────────────────────────────────────
    public function revoke(int $keyId): void
    {
        $this->pdo->prepare(
            'UPDATE uysa_api_keys SET is_active = 0 WHERE id = ?'
        )->execute([$keyId]);
    }

    // ── Key Listele ──────────────────────────────────────────
    public function list(string $owner): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, key_prefix, name, role, scopes, uses_count, last_used_at, expires_at, created_at
            FROM uysa_api_keys
            WHERE owner = ? AND is_active = 1
            ORDER BY created_at DESC
        ");
        $stmt->execute([$owner]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['scopes'] = json_decode($r['scopes'], true) ?? [];
        }
        return $rows;
    }

    // ── Private ──────────────────────────────────────────────
    private function hash(string $key): string
    {
        return hash('sha3-256', $key);
    }

    private function ensureTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS `uysa_api_keys` (
              `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
              `key_hash`     VARCHAR(64)     NOT NULL COMMENT 'SHA3-256 hash of the raw key',
              `key_prefix`   VARCHAR(20)     NOT NULL,
              `name`         VARCHAR(100)    NOT NULL DEFAULT 'API Key',
              `owner`        VARCHAR(100)    NOT NULL DEFAULT 'system',
              `role`         VARCHAR(50)     NOT NULL DEFAULT 'viewer',
              `scopes`       JSON            NOT NULL,
              `is_active`    TINYINT(1)      NOT NULL DEFAULT 1,
              `uses_count`   INT UNSIGNED    NOT NULL DEFAULT 0,
              `last_used_at` DATETIME                 DEFAULT NULL,
              `expires_at`   DATETIME                 DEFAULT NULL,
              `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_key_hash` (`key_hash`),
              KEY `idx_owner_active` (`owner`, `is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}
