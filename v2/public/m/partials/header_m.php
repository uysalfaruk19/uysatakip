<?php
/** @var string $pageTitle */
/** @var string $eyebrow */
/** @var array $cu  müşteri bağlamı */
use Uysa\Helpers;
$pageTitle = $pageTitle ?? 'Müşteri Paneli';
$eyebrow = $eyebrow ?? (($cu['customer_name'] ?? '') . ' Yemek Paneli');
?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#eff8f4">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title>UYSA Müşteri · <?= Helpers::e($pageTitle) ?></title>
<link href="/assets/bootstrap-icons.css?v=<?= filemtime(__DIR__ . '/../../assets/bootstrap-icons.css') ?>" rel="stylesheet">
<link href="/assets/app.css?v=<?= filemtime(__DIR__ . '/../../assets/app.css') ?>" rel="stylesheet">
</head>
<body class="customer-page">
<main class="app-shell customer">
  <header class="topbar">
    <div class="brand-copy">
      <p class="eyebrow"><?= Helpers::e($eyebrow) ?></p>
      <h1><?= Helpers::e($pageTitle) ?></h1>
    </div>
    <a class="icon-btn" href="logout.php" aria-label="Çıkış"><i class="bi bi-box-arrow-right"></i></a>
  </header>
  <section class="screen-stack">
