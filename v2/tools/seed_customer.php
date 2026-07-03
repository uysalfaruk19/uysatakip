<?php
declare(strict_types=1);

/**
 * Müşteri uygulaması kullanıcısı oluştur/güncelle (idempotent).
 * Bir müşteri firmasını (customers) bulur/oluşturur ve ona bağlı bir customer_user açar.
 *
 * Kullanım:
 *   php tools/seed_customer.php --customer="CANTAŞ" --user=cantas --pass=GİZLİ [--price=328] [--display="Cantaş A.Ş."] [--category=uretim]
 *
 * Notlar:
 *  - Müşteri zaten varsa: id kullanılır; --price VERİLMEDİKÇE birim fiyat DEĞİŞMEZ.
 *  - Kullanıcı adı zaten varsa: şifre/müşteri bağı güncellenir (tekrar çalıştırılabilir).
 *  - Canlıda (Fable): docker exec -i <container> php tools/seed_customer.php ...
 */

require __DIR__ . '/../src/bootstrap.php';

use Uysa\CustomerAuth;
use Uysa\Db;
use Uysa\Repo;

$opts = getopt('', ['customer:', 'user:', 'pass:', 'price::', 'display::', 'category::']);
foreach (['customer', 'user', 'pass'] as $req) {
    if (empty($opts[$req])) {
        fwrite(STDERR, "HATA: --$req zorunlu.\n");
        fwrite(STDERR, "Kullanım: php tools/seed_customer.php --customer=\"Ad\" --user=kullanici --pass=sifre [--price=250] [--display=\"Ad\"] [--category=uretim]\n");
        exit(1);
    }
}

$name = (string) $opts['customer'];
$username = (string) $opts['user'];
$password = (string) $opts['pass'];
$display = (string) ($opts['display'] ?? $name);
$category = (string) ($opts['category'] ?? 'uretim');

$pdo = Db::pdo();
$repo = new Repo($pdo);
$auth = new CustomerAuth($pdo);

// ── Müşteri: bul (fiyatı koru) veya oluştur ───────────────────
$st = $pdo->prepare('SELECT id FROM customers WHERE name = ?');
$st->execute([$name]);
$found = $st->fetchColumn();
if ($found !== false) {
    $cid = (int) $found;
    if (isset($opts['price'])) {
        $repo->upsertCustomer($name, (float) $opts['price'], $category, $cid);
    }
} else {
    $cid = $repo->upsertCustomer($name, isset($opts['price']) ? (float) $opts['price'] : 0.0, $category);
}

// ── Kullanıcı: upsert ─────────────────────────────────────────
$existing = $auth->customerUserByUsername($username);
if ($existing) {
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare('UPDATE customer_users SET customer_id = ?, password_bcrypt = ?, display_name = ?, is_active = 1 WHERE id = ?')
        ->execute([$cid, $hash, $display, (int) $existing['id']]);
    fwrite(STDOUT, "Güncellendi: '$username' → müşteri '$name' (customer_id=$cid)\n");
} else {
    $uid = $auth->createCustomerUser($cid, $username, $password, $display, null, 'owner');
    fwrite(STDOUT, "Oluşturuldu: '$username' (id=$uid) → müşteri '$name' (customer_id=$cid)\n");
}
