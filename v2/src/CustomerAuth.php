<?php
declare(strict_types=1);

namespace Uysa;

use PDO;

/**
 * Müşteri uygulaması kimliği (customer_users, bcrypt) — İÇ personelden TAMAMEN AYRI guard.
 * Ayrı oturum adı (uysa_musteri) → personel oturumu (uysa_kokpit) ile karışmaz.
 * Oturumda customer_id tutulur; HER müşteri sorgusu bununla scope'lanır (IDOR koruması).
 */
final class CustomerAuth
{
    public function __construct(private PDO $pdo)
    {
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $name = Env::get('CUSTOMER_SESSION_NAME', 'uysa_musteri');
        session_name((string) $name);
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $https,
        ]);
        session_start();
    }

    /** customer_users doğrula, oturuma customer_id yaz. Başarısızsa null. */
    public function login(string $username, string $password): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT cu.*, c.name AS customer_name, c.is_active AS customer_active
             FROM customer_users cu
             JOIN customers c ON c.id = cu.customer_id
             WHERE cu.username = ? AND cu.is_active = 1'
        );
        $st->execute([$username]);
        $cu = $st->fetch();

        // Timing-attack azaltma: kullanıcı yoksa da gerçek bir bcrypt doğrulaması yapılır.
        $dummy = '$2y$12$aBtE2nt2RGExYW89REDD/OEGwALSTvubXDTPSGtUIod.aolyX5WmG';
        $hash = $cu ? $cu['password_bcrypt'] : $dummy;
        if (!$cu || (int) $cu['customer_active'] !== 1 || !password_verify($password, $hash)) {
            return null;
        }
        $this->pdo->prepare('UPDATE customer_users SET last_login = ' . $this->now() . ' WHERE id = ?')
            ->execute([$cu['id']]);

        $_SESSION['cuid'] = (int) $cu['id'];
        $_SESSION['customer_id'] = (int) $cu['customer_id'];
        $_SESSION['cu_username'] = $cu['username'];
        $_SESSION['cu_display'] = $cu['display_name'];
        $_SESSION['cu_customer_name'] = $cu['customer_name'];
        return $cu;
    }

    /** Kalıcı giriş (remember token) doğrulanınca şifresiz oturum aç — login() ile aynı oturum alanları. */
    public function loginById(int $cuid): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT cu.*, c.name AS customer_name, c.is_active AS customer_active
             FROM customer_users cu
             JOIN customers c ON c.id = cu.customer_id
             WHERE cu.id = ? AND cu.is_active = 1'
        );
        $st->execute([$cuid]);
        $cu = $st->fetch();
        if (!$cu || (int) $cu['customer_active'] !== 1) {
            return null;
        }
        $this->pdo->prepare('UPDATE customer_users SET last_login = ' . $this->now() . ' WHERE id = ?')
            ->execute([$cu['id']]);
        $_SESSION['cuid'] = (int) $cu['id'];
        $_SESSION['customer_id'] = (int) $cu['customer_id'];
        $_SESSION['cu_username'] = $cu['username'];
        $_SESSION['cu_display'] = $cu['display_name'];
        $_SESSION['cu_customer_name'] = $cu['customer_name'];
        return $cu;
    }

    private function now(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "datetime('now')" : 'NOW()';
    }

    /** Oturumdaki müşteri bağlamı (customer_id garanti). Giriş yoksa null. */
    public static function customer(): ?array
    {
        if (empty($_SESSION['cuid']) || empty($_SESSION['customer_id'])) {
            return null;
        }
        return [
            'cuid'          => (int) $_SESSION['cuid'],
            'customer_id'   => (int) $_SESSION['customer_id'],
            'username'      => $_SESSION['cu_username'] ?? '',
            'display_name'  => $_SESSION['cu_display'] ?? '',
            'customer_name' => $_SESSION['cu_customer_name'] ?? '',
        ];
    }

    /** Sayfa guard: giriş yoksa önce kalıcı giriş (remember) dene, o da yoksa müşteri login'e yönlendir. */
    public static function requireCustomer(): array
    {
        self::startSession();
        $c = self::customer();
        if (!$c) {
            try {
                $pdo = Db::pdo();
                $cuid = Remember::forCustomer($pdo)->consume();
                if ($cuid !== null && (new self($pdo))->loginById($cuid)) {
                    $c = self::customer();
                }
            } catch (\Throwable) {
                // DB erişilemezse sessizce login'e düş
            }
        }
        if (!$c) {
            header('Location: login.php');
            exit;
        }
        return $c;
    }

    public static function logout(): void
    {
        self::startSession();
        try {
            Remember::forCustomer(Db::pdo())->clear();
        } catch (\Throwable) {
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    }

    /** Seed/yönetim: bir müşteriye bağlı customer_user oluştur (idempotent değil — çağıran kontrol eder). */
    public function createCustomerUser(int $customerId, string $username, string $password, ?string $displayName = null, ?string $phone = null, string $role = 'owner'): int
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->pdo->prepare(
            'INSERT INTO customer_users (customer_id, username, password_bcrypt, display_name, phone, role)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$customerId, $username, $hash, $displayName, $phone, $role]);
        return (int) $this->pdo->lastInsertId();
    }

    public function customerUserByUsername(string $username): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM customer_users WHERE username = ?');
        $st->execute([$username]);
        return $st->fetch() ?: null;
    }
}
