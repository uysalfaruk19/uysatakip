<?php
/**
 * UYSA ERP — Rate Limiter v1.0
 * PDO (MySQL) tabanlı sliding-window rate limiter
 * Dosya: public/src/RateLimiter.php
 */
declare(strict_types=1);

class RateLimiter
{
    private PDO    $pdo;
    private int    $maxAttempts;
    private int    $windowSeconds;
    private int    $lockSeconds;

    public function __construct(
        PDO $pdo,
        int $maxAttempts   = 10,
        int $windowSeconds = 600,   // 10 dakika
        int $lockSeconds   = 900    // 15 dakika kilit
    ) {
        $this->pdo           = $pdo;
        $this->maxAttempts   = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        $this->lockSeconds   = $lockSeconds;
        $this->ensureTable();
    }

    // ── Deneme Kaydet & Kontrol ──────────────────────────────
    /**
     * @return array{allowed:bool, remaining:int, retry_after:int}
     */
    public function attempt(string $key): array
    {
        $this->cleanup();

        // Kilitli mi?
        $lock = $this->getLock($key);
        if ($lock) {
            $retryAfter = $lock['locked_until'] - time();
            return ['allowed' => false, 'remaining' => 0, 'retry_after' => max(0, $retryAfter)];
        }

        // Mevcut penceredeki deneme sayısı
        $count = $this->countAttempts($key);

        if ($count >= $this->maxAttempts) {
            // Kilitle
            $this->lock($key);
            return ['allowed' => false, 'remaining' => 0, 'retry_after' => $this->lockSeconds];
        }

        // Deneme kaydet
        $this->record($key);
        $remaining = max(0, $this->maxAttempts - $count - 1);
        return ['allowed' => true, 'remaining' => $remaining, 'retry_after' => 0];
    }

    // ── Başarılı → Sayacı Sıfırla ────────────────────────────
    public function reset(string $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM uysa_rate_limits WHERE `key` = ?');
        $stmt->execute([$key]);

        $stmt = $this->pdo->prepare('DELETE FROM uysa_rate_locks WHERE `key` = ?');
        $stmt->execute([$key]);
    }

    // ── Durum Sorgula ────────────────────────────────────────
    public function status(string $key): array
    {
        $lock = $this->getLock($key);
        if ($lock) {
            return [
                'locked'      => true,
                'retry_after' => max(0, $lock['locked_until'] - time()),
                'attempts'    => $this->maxAttempts,
                'remaining'   => 0,
            ];
        }
        $count = $this->countAttempts($key);
        return [
            'locked'      => false,
            'retry_after' => 0,
            'attempts'    => $count,
            'remaining'   => max(0, $this->maxAttempts - $count),
        ];
    }

    // ── Private Yardımcılar ──────────────────────────────────
    private function countAttempts(string $key): int
    {
        $since = time() - $this->windowSeconds;
        $stmt  = $this->pdo->prepare(
            'SELECT COUNT(*) FROM uysa_rate_limits WHERE `key` = ? AND attempted_at > ?'
        );
        $stmt->execute([$key, $since]);
        return (int)$stmt->fetchColumn();
    }

    private function record(string $key): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO uysa_rate_limits (`key`, attempted_at) VALUES (?, ?)'
        );
        $stmt->execute([$key, time()]);
    }

    private function getLock(string $key): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT locked_until FROM uysa_rate_locks WHERE `key` = ? AND locked_until > ?'
        );
        $stmt->execute([$key, time()]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function lock(string $key): void
    {
        $until = time() + $this->lockSeconds;
        $stmt  = $this->pdo->prepare(
            'INSERT INTO uysa_rate_locks (`key`, locked_until)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        );
        $stmt->execute([$key, $until]);
    }

    private function cleanup(): void
    {
        // Eski kayıtları temizle (her 100 istekte bir)
        if (rand(1, 100) === 1) {
            $old = time() - $this->windowSeconds;
            $this->pdo->exec("DELETE FROM uysa_rate_limits WHERE attempted_at < {$old}");
            $this->pdo->exec("DELETE FROM uysa_rate_locks WHERE locked_until < " . time());
        }
    }

    private function ensureTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS `uysa_rate_limits` (
              `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `key`          VARCHAR(255)    NOT NULL,
              `attempted_at` INT UNSIGNED    NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_key_time` (`key`, `attempted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS `uysa_rate_locks` (
              `key`          VARCHAR(255) NOT NULL,
              `locked_until` INT UNSIGNED NOT NULL,
              PRIMARY KEY (`key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}
