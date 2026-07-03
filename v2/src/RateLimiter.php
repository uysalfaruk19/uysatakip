<?php
declare(strict_types=1);

namespace Uysa;

use PDO;

/**
 * Sliding-window rate limiter (v1 deseni). MySQL + SQLite uyumlu.
 * Login/token endpoint'lerinde brute-force koruması.
 */
final class RateLimiter
{
    public function __construct(
        private PDO $pdo,
        private int $max = 10,
        private int $window = 600,
        private int $lock = 900
    ) {
    }

    public function attempt(string $key): array
    {
        $now = time();

        // Kilitli mi?
        $st = $this->pdo->prepare('SELECT locked_until FROM rate_locks WHERE rl_key = ?');
        $st->execute([$key]);
        $lockedUntil = $st->fetchColumn();
        if ($lockedUntil !== false && (int) $lockedUntil > $now) {
            return ['allowed' => false, 'remaining' => 0, 'retry_after' => (int) $lockedUntil - $now];
        }

        // Pencere içi denemeleri say
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM rate_limits WHERE rl_key = ? AND attempted_at > ?');
        $st->execute([$key, $now - $this->window]);
        $count = (int) $st->fetchColumn();

        if ($count >= $this->max) {
            $this->pdo->prepare(
                'INSERT INTO rate_locks (rl_key, locked_until) VALUES (?, ?) '
                . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                    ? 'ON CONFLICT(rl_key) DO UPDATE SET locked_until = excluded.locked_until'
                    : 'ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')
            )->execute([$key, $now + $this->lock]);
            return ['allowed' => false, 'remaining' => 0, 'retry_after' => $this->lock];
        }

        $this->pdo->prepare('INSERT INTO rate_limits (rl_key, attempted_at) VALUES (?, ?)')
            ->execute([$key, $now]);

        return ['allowed' => true, 'remaining' => $this->max - $count - 1, 'retry_after' => 0];
    }

    public function reset(string $key): void
    {
        $this->pdo->prepare('DELETE FROM rate_limits WHERE rl_key = ?')->execute([$key]);
        $this->pdo->prepare('DELETE FROM rate_locks WHERE rl_key = ?')->execute([$key]);
    }
}
