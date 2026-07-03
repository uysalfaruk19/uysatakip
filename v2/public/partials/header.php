<?php
/** @var string $pageTitle */
/** @var string $eyebrow */
/** @var array $u  current user */
use Uysa\Helpers;
$pageTitle = $pageTitle ?? 'UYSA Kokpit';
$eyebrow = $eyebrow ?? ('UYSA Kokpit · ' . ($u['display_name'] ?: $u['username']));
?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f2f6fa">
<title>UYSA Kokpit · <?= Helpers::e($pageTitle) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/app.css" rel="stylesheet">
</head>
<body>
<main class="app-shell">
  <header class="topbar">
    <div class="d-flex align-items-center gap-3">
      <div class="brand-mark">U</div>
      <div class="brand-copy">
        <p class="eyebrow"><?= Helpers::e($eyebrow) ?></p>
        <h1><?= Helpers::e($pageTitle) ?></h1>
      </div>
    </div>
    <a class="icon-btn" href="logout.php" aria-label="Çıkış"><i class="bi bi-box-arrow-right"></i></a>
  </header>
  <section class="screen-stack">
