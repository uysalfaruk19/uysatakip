<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Uysa\CustomerAuth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\RateLimiter;
use Uysa\Remember;

CustomerAuth::startSession();
if (!CustomerAuth::customer()) {
    // Kalıcı giriş (app): remember cookie geçerliyse şifresiz devam
    try {
        $pdoR = Db::pdo();
        $cuidR = Remember::forCustomer($pdoR)->consume();
        if ($cuidR !== null) {
            (new CustomerAuth($pdoR))->loginById($cuidR);
        }
    } catch (\Throwable) {
    }
}
if (CustomerAuth::customer()) {
    header('Location: panel.php');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $error = 'Oturum süresi doldu, tekrar deneyin.';
    } else {
        $pdo = Db::pdo();
        $ip = client_ip();
        $username = trim((string) ($_POST['username'] ?? ''));
        $rl = new RateLimiter($pdo, 8, 600, 900);
        $key = 'mlogin:' . md5($username . ':' . $ip);
        $limit = $rl->attempt($key);
        if (!$limit['allowed']) {
            $error = 'Çok fazla deneme. ' . (int) ceil($limit['retry_after'] / 60) . ' dk bekleyin.';
        } else {
            $auth = new CustomerAuth($pdo);
            $cu = $auth->login($username, (string) ($_POST['password'] ?? ''));
            if ($cu) {
                $rl->reset($key);
                Remember::forCustomer($pdo)->issue((int) $cu['id']);
                uysa_audit('musteri_login', $username, (string) $cu['customer_id'], null, $ip);
                header('Location: panel.php');
                exit;
            }
            uysa_audit('musteri_login_fail', $username, null, null, $ip);
            $error = 'Kullanıcı adı veya şifre hatalı.';
        }
    }
}
$csrf = Helpers::csrfToken();
?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#eff8f4">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title>UYSA Müşteri · Giriş</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="/assets/app.css?v=<?= filemtime(__DIR__ . '/../assets/app.css') ?>" rel="stylesheet">
</head>
<body class="customer-page">
<main class="login-shell">
  <div class="login-logo">U</div>
  <h1>UYSA Müşteri</h1>
  <p class="eyebrow mb-4">Firmanız için sipariş, menü, cari ekstre ve talep tek ekranda.</p>
  <?php if ($error): ?><div class="flash err mb-3"><?= Helpers::e($error) ?></div><?php endif; ?>
  <section class="cardx card-pad">
    <form method="post" autocomplete="off" class="form-grid">
      <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
      <div class="field"><label>Kullanıcı adı</label><input class="inputx" name="username" autocapitalize="none" required></div>
      <div class="field"><label>Şifre</label><input class="inputx" type="password" name="password" required></div>
      <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-box-arrow-in-right"></i> Giriş yap</button>
    </form>
  </section>
  <div class="hint-card mt-3">
    UYSA personeli misiniz? <a href="/login.php" style="text-decoration:underline">Kokpit girişi</a>.
  </div>
</main>
</body>
</html>
