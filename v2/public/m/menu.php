<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Uysa\CustomerAuth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

$cu = CustomerAuth::requireCustomer();
$cid = (int) $cu['customer_id'];
$pdo = Db::pdo();
$repo = new Repo($pdo);

$from = Helpers::today();
$to = date('Y-m-d', strtotime('+6 day'));
$rows = $repo->publishedMenu($from, $to, 'ogle');

// Güne göre grupla
$byDay = [];
foreach ($rows as $r) {
    $byDay[$r['menu_date']][] = $r['recipe_name'];
}

$eyebrow = Helpers::e($cu['customer_name']) . ' · Menü';
$pageTitle = 'Haftalık menü';
$active = 'menu';
require __DIR__ . '/partials/header_m.php';
?>
      <p class="eyebrow mb-2" style="margin-top:0"><?= Helpers::e(gun_label_tr($from)) ?> – <?= Helpers::e(gun_label_tr($to)) ?> · Öğle</p>

      <?php if (!$byDay): ?>
        <div class="empty-state">
          Yayınlanan menü yok.
          <div class="row-meta mt-2">UYSA menüyü yayınladığında burada görünecek.</div>
        </div>
      <?php else: ?>
        <div class="screen-stack">
          <?php foreach ($byDay as $day => $items): ?>
            <div class="meal-card cardx card-pad">
              <p class="label"><?= Helpers::e(gun_label_tr($day)) ?></p>
              <h2 style="margin:0"><?= Helpers::e(implode(' · ', array_filter($items))) ?></h2>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer_m.php'; ?>
