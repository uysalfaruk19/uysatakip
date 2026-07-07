<?php
declare(strict_types=1);

namespace Uysa;

use PDO;

/** Oturum tabanlı iç kullanıcı kimliği (bcrypt). */
final class Auth
{
    public function __construct(private PDO $pdo)
    {
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $name = Env::get('SESSION_NAME', 'uysa_kokpit');
        session_name((string) $name);
        // HTTPS tespiti: doğrudan TLS VEYA reverse proxy (traefik) X-Forwarded-Proto
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $https,
        ]);
        session_start();
    }

    /** Kullanıcı adı+şifre doğrula, oturuma yaz. Başarısızsa null. */
    public function login(string $username, string $password): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
        $st->execute([$username]);
        $user = $st->fetch();

        // Timing-attack azaltma: kullanıcı yoksa da gerçek bir bcrypt doğrulaması yapılır
        // (aşağıdaki hash gerçek password_hash('...', BCRYPT, cost=12) çıktısıdır)
        $dummy = '$2y$12$aBtE2nt2RGExYW89REDD/OEGwALSTvubXDTPSGtUIod.aolyX5WmG';
        $hash = $user ? $user['password'] : $dummy;
        if (!$user || !password_verify($password, $hash)) {
            return null;
        }
        $this->pdo->prepare('UPDATE users SET last_login = ' . $this->now() . ' WHERE id = ?')
            ->execute([$user['id']]);

        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['display_name'] = $user['display_name'];
        return $user;
    }

    private function now(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "datetime('now')" : 'NOW()';
    }

    public static function user(): ?array
    {
        if (empty($_SESSION['uid'])) {
            return null;
        }
        return [
            'uid'          => $_SESSION['uid'],
            'username'     => $_SESSION['username'] ?? '',
            'role'         => $_SESSION['role'] ?? 'user',
            'display_name' => $_SESSION['display_name'] ?? '',
        ];
    }

    public static function requireLogin(): array
    {
        self::startSession();
        $u = self::user();
        if (!$u) {
            header('Location: login.php');
            exit;
        }
        return $u;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    }

    public function createUser(string $username, string $password, string $role = 'user', ?string $displayName = null): int
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->pdo->prepare(
            'INSERT INTO users (username, password, role, display_name) VALUES (?, ?, ?, ?)'
        )->execute([$username, $hash, $role, $displayName]);
        return (int) $this->pdo->lastInsertId();
    }
}
