<?php
declare(strict_types=1);

/**
 * Şema kurulumu + varsayılan kullanıcı seed.
 * Kullanım:
 *   php tools/setup_db.php                 (şema + seed)
 *   php tools/setup_db.php --seed-only
 *
 * Kullanıcılar .env'den okunur (koda gömülü şifre YOK):
 *   ADMIN_USER / ADMIN_PASS   (OFU süperadmin)
 *   STAFF_USER / STAFF_PASS   (Azim, editor)
 * Verilmezse güçlü rastgele şifre üretilir ve EKRANA yazılır (bir kez).
 */

require __DIR__ . '/../src/bootstrap.php';

use Uysa\Db;
use Uysa\Env;
use Uysa\Auth;

$seedOnly = in_array('--seed-only', $argv, true);
$pdo = Db::pdo();

if (!$seedOnly) {
    echo "Şema uygulanıyor (" . Db::driver() . ")...\n";
    Db::applySchema($pdo);
    echo "Şema tamam.\n";
}

$auth = new Auth($pdo);

function seedUser(PDO $pdo, Auth $auth, string $username, string $role, string $display, ?string $pass): void
{
    $st = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $st->execute([$username]);
    if ($st->fetchColumn() !== false) {
        echo "  = $username zaten var, atlandı.\n";
        return;
    }
    $generated = false;
    if ($pass === null || $pass === '') {
        $pass = bin2hex(random_bytes(6)); // 12 hex char
        $generated = true;
    }
    $auth->createUser($username, $pass, $role, $display);
    echo "  + $username ($role) oluşturuldu" . ($generated ? " — ŞİFRE: $pass  (kaydedin!)" : '') . "\n";
}

echo "Kullanıcılar seed ediliyor...\n";
seedUser($pdo, $auth, Env::get('ADMIN_USER', 'OFU'), 'superadmin', 'Ömer Faruk Uysal', Env::get('ADMIN_PASS'));
seedUser($pdo, $auth, Env::get('STAFF_USER', 'Azim'), 'editor', 'Azim', Env::get('STAFF_PASS'));
echo "Bitti.\n";
