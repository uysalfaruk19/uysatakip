<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\RateLimiter;
use Uysa\Remember;

Auth::startSession();
if (!Auth::user()) {
    // Kalıcı giriş (app): remember cookie geçerliyse şifresiz devam
    try {
        $pdoR = Db::pdo();
        $uidR = Remember::forAdmin($pdoR)->consume();
        if ($uidR !== null) {
            (new Auth($pdoR))->loginById($uidR);
        }
    } catch (\Throwable) {
    }
}
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
                Remember::forAdmin($pdo)->issue((int) $user['id']);
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
<meta name="theme-color" content="#f2f6fa">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title>UYSA Kokpit · Giriş</title>
<link href="assets/bootstrap-icons.css?v=<?= filemtime(__DIR__ . '/assets/bootstrap-icons.css') ?>" rel="stylesheet">
<link href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>" rel="stylesheet">
</head>
<body>
<main class="login-shell">
  <div class="login-logo">U</div>
  <h1>UYSA Kokpit</h1>
  <p class="eyebrow mb-4">UYSA operasyon ekibi için güvenli giriş.</p>
  <div class="hint-card native-login-note mb-3" data-native-login-note hidden>
    <i class="bi bi-phone"></i><span></span>
  </div>
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
    Müşteri hesabınız mı var? <a href="/m/login.php" style="text-decoration:underline">Müşteri girişi</a>.
  </div>
</main>
<script>
(function () {
  var C = window.Capacitor;
  if (!C || typeof C.isNativePlatform !== 'function' || !C.isNativePlatform()) return;
  document.documentElement.classList.add('native-app');
  document.body.classList.add('native-app');
  var note = document.querySelector('[data-native-login-note]');
  if (!note) return;
  note.hidden = false;
  note.querySelector('span').textContent = localStorage.getItem('uysaNativeSeen:admin')
    ? 'Oturumunuzu yenileyip kaldığınız yerden devam edin.'
    : 'UYSA Kokpit\'e hoş geldiniz. Personel hesabınızla giriş yapın.';
})();
</script>
</body>
</html>
