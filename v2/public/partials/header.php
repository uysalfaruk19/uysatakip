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
      <?php // fable-108 (Ömer, 31 Ağu): "bildirime basınca tüm ekranı kaplıyor, küçült; tekrar
            // basınca kapansın." Zil artık ayrı sayfaya GİTMİYOR — küçük açılır panel açıyor.
            // Tam liste hâlâ bildirimler.php'de (paneldeki "Tümünü gör").
      $f108Liste = [];
      try { $f108Liste = (new Repo(Db::pdo()))->yoneticiBildirimleri(8); }
      catch (\Throwable $e) { $f108Liste = []; }   // panel sayfayı ASLA çökertmez
      ?>
      <div class="bildirim-sarmal">
        <button type="button" class="icon-btn" id="bildirimZil" aria-label="Bildirimler"
                aria-expanded="false" aria-controls="bildirimPanel">
          <i class="bi bi-bell"></i>
          <?php if ($f087Yeni > 0): ?>
          <span class="bildirim-rozet"><?= $f087Yeni > 99 ? '99+' : $f087Yeni ?></span>
          <?php endif; ?>
        </button>
        <div class="bildirim-panel" id="bildirimPanel" hidden>
          <div class="bildirim-panel-bas">
            <strong>Bildirimler</strong>
            <?php if ($f087Yeni > 0): ?><span class="bildirim-yeni"><?= $f087Yeni ?> yeni</span><?php endif; ?>
          </div>
          <?php if (!$f108Liste): ?>
            <div class="bildirim-bos">Bildirim yok</div>
          <?php else: foreach ($f108Liste as $b): ?>
            <div class="bildirim-satir">
              <div class="bildirim-baslik"><?= Helpers::e((string) ($b['title'] ?? '')) ?></div>
              <?php if (!empty($b['body'])): ?>
              <div class="bildirim-govde"><?= Helpers::e(mb_substr((string) $b['body'], 0, 110)) ?></div>
              <?php endif; ?>
              <div class="bildirim-zaman"><?= Helpers::e(date('d.m H:i', (int) strtotime((string) ($b['created_at'] ?? 'now')))) ?></div>
            </div>
          <?php endforeach; endif; ?>
          <a class="bildirim-tumu" href="bildirimler.php">Tümünü gör</a>
        </div>
      </div>
      <?php endif; ?>
      <a class="icon-btn" href="logout.php" aria-label="Çıkış"><i class="bi bi-box-arrow-right"></i></a>
    </div>
  </header>
  <section class="screen-stack">
