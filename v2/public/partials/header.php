<?php
/** @var string $pageTitle */
/** @var string $eyebrow */
/** @var array $u  current user */
use Uysa\Helpers;
$pageTitle = $pageTitle ?? 'UYSA Kokpit';
// Airbnb dili: saate göre selamlama (mockup: "Günaydın Ömer 👋"). Sayfa kendi eyebrow'unu
// verirse (bağlamsal alt-başlık) o kullanılır; bugun.php vermez → selamlama görünür.
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
<link href="assets/bootstrap-icons.css?v=<?= filemtime(__DIR__ . '/../assets/bootstrap-icons.css') ?>" rel="stylesheet">
<link href="assets/app.css?v=<?= filemtime(__DIR__ . '/../assets/app.css') ?>" rel="stylesheet">
</head>
<body class="admin-page"><!-- fable-025: sabit-kabuk deseni (alt bar titremesi) admin'e de uygulandı -->
<main class="app-shell">
  <header class="topbar">
    <div class="brand-copy">
      <p class="eyebrow"><?= Helpers::e($eyebrow) ?></p>
      <h1><?= Helpers::e($pageTitle) ?></h1>
    </div>
    <a class="icon-btn" href="logout.php" aria-label="Çıkış"><i class="bi bi-box-arrow-right"></i></a>
  </header>
  <section class="screen-stack">
