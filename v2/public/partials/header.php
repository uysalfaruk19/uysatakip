<?php
/** @var string $pageTitle */
/** @var string $eyebrow */
/** @var array $u  current user */
use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;
$pageTitle = $pageTitle ?? 'UYSA Kokpit';
// fable-034: GTO dili — üst bar lacivert; selamlama saat bazlı (mockup "Günaydın Ömer 👋").
$h = (int) date('G');
$selam = $h < 6 ? 'İyi geceler' : ($h < 11 ? 'Günaydın' : ($h < 18 ? 'İyi günler' : 'İyi akşamlar'));
$eyebrow = $eyebrow ?? ($selam . ' ' . ($u['display_name'] ?: $u['username']) . ' 👋');
?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f2f6fa">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title>UYSA Kokpit · <?= Helpers::e($pageTitle) ?></title>
<link href="/assets/bootstrap-icons.css" rel="stylesheet">
<link href="assets/app.css?v=<?= filemtime(__DIR__ . '/../assets/app.css') ?>" rel="stylesheet">
</head>
<body class="admin-page"><!-- fable-025: sabit-kabuk deseni (alt bar titremesi) admin'e de uygulandı -->
<main class="app-shell">
  <header class="topbar">
    <div class="d-flex align-items-center gap-3">
      <div class="brand-mark">U</div>
      <div class="brand-copy">
        <?php if (!empty($homeBrand)): // fable-034b: anasayfa markalı — marka üstte, selamlama altta (mockup) ?>
        <h1><?= Helpers::e($pageTitle) ?></h1>
        <p class="eyebrow"><?= Helpers::e($eyebrow) ?></p>
        <?php else: ?>
        <p class="eyebrow"><?= Helpers::e($eyebrow) ?></p>
        <h1><?= Helpers::e($pageTitle) ?></h1>
        <?php endif; ?>
      </div>
    </div>
    <?php // fable-087 (Ömer, 19 Ağu): bildirim uygulama ikonunda görünüyordu ama içeride
          // okunacak yer yoktu. Çıkışın yanına zil + okunmamış rozeti. Yalnız yöneticide. ?>
    <div class="d-flex align-items-center gap-2">
      <?php if (Auth::isAdmin($u)):
        $f087Yeni = 0;
        try { $f087Yeni = (new Repo(Db::pdo()))->okunmamisBildirim((int) $u['uid']); }
        catch (\Throwable $e) { $f087Yeni = 0; }   // bildirim sayacı sayfayı ASLA çökertmez
      ?>
      <a class="icon-btn" href="bildirimler.php" aria-label="Bildirimler" style="position:relative">
        <i class="bi bi-bell"></i>
        <?php if ($f087Yeni > 0): ?>
        <span style="position:absolute;top:-2px;right:-2px;min-width:17px;height:17px;padding:0 4px;
                     background:var(--red);color:#fff;border-radius:9px;font-size:10.5px;font-weight:700;
                     line-height:17px;text-align:center"><?= $f087Yeni > 99 ? '99+' : $f087Yeni ?></span>
        <?php endif; ?>
      </a>
      <?php endif; ?>
      <a class="icon-btn" href="logout.php" aria-label="Çıkış"><i class="bi bi-box-arrow-right"></i></a>
    </div>
  </header>
  <section class="screen-stack">
