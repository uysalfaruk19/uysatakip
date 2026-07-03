<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\RateLimiter;

Auth::startSession();
if (Auth::user()) {
    header('Location: bugun.php');
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
        $key = 'login:' . md5($username . ':' . $ip);
        $limit = $rl->attempt($key);
        if (!$limit['allowed']) {
            $error = 'Çok fazla deneme. ' . (int) ceil($limit['retry_after'] / 60) . ' dk bekleyin.';
        } else {
            $auth = new Auth($pdo);
            $user = $auth->login($username, (string) ($_POST['password'] ?? ''));
            if ($user) {
                $rl->reset($key);
                uysa_audit('login', $username, null, null, $ip);
                header('Location: bugun.php');
                exit;
            }
            uysa_audit('login_fail', $username, null, null, $ip);
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
<meta name="theme-color" content="#0b1117">
<title>UYSA Kokpit · Giriş</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/app.css" rel="stylesheet">
</head>
<body>
<main class="login-shell">
  <div class="login-logo">U</div>
  <h1>UYSA Kokpit</h1>
  <p class="eyebrow mb-4">Personel ve müşteri aynı ekrandan giriş yapar; rolüne göre yönlendirilir.</p>
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
    Müşteri kullanıcısı girerse müşteri uygulamasına, UYSA personeli girerse iç kokpite gider.
  </div>
</main>
</body>
</html>
