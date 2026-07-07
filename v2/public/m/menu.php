<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Uysa\CustomerAuth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

use Uysa\MenuPdf;

$cu = CustomerAuth::requireCustomer();
$cid = (int) $cu['customer_id'];
$pdo = Db::pdo();
$repo = new Repo($pdo);

// opus-019: müşteri EN FAZLA 1 AY GERİ görebilir (date_end >= bugün−1 ay).
$minEnd = date('Y-m-d', strtotime('-1 month'));

// PDF indir (header'dan ÖNCE) — SADECE bu müşteriye görünür + 1 ay sınırı içindeki menü (IDOR scope).
if (isset($_GET['pdf'])) {
    $mid = (int) $_GET['pdf'];
    $m = $repo->menuForCustomer($cid, $mid, $minEnd);
    if ($m) {
        $bin = MenuPdf::write((string) $m['title'], $repo->menuItems($mid), (string) $m['date_start'], (string) $m['date_end']);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="menu-' . (int) $mid . '.pdf"');
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;
    }
    header('Location: menu.php');
    exit;
}

// SADECE bu müşteriye yayınlanmış menüler (all-audience VEYA menu_target'ta) — IDOR scope.
$menus = $repo->menusForCustomer($cid, $minEnd);
$meals = ['sabah' => 'Sabah', 'ogle' => 'Öğle', 'aksam' => 'Akşam', 'gece' => 'Gece', 'kumanya' => 'Kumanya'];

$eyebrow = Helpers::e($cu['customer_name']) . ' · Menü';
$pageTitle = 'Menü';
$active = 'menu';
require __DIR__ . '/partials/header_m.php';
?>
      <?php if (!$menus): ?>
        <div class="empty-state">
          Yayınlanan menü yok.
          <div class="row-meta mt-2">UYSA size menü yayınladığında burada görünecek.</div>
        </div>
      <?php else: ?>
        <?php foreach ($menus as $m): $items = $repo->menuItems((int) $m['id']); ?>
          <div class="cardx card-pad mb-3">
            <div class="d-flex align-items-center justify-between gap-2 mb-2">
              <div style="min-width:0">
                <p class="label" style="margin:0">Günün Menüsü</p>
                <h2 style="margin:2px 0 0"><?= Helpers::e($m['title']) ?></h2>
              </div>
              <span class="badge-soft badge-ok"><i class="bi bi-broadcast"></i> Yayında</span>
            </div>
            <p class="row-meta mb-2"><i class="bi bi-calendar2-week"></i> <?= date('d.m', strtotime($m['date_start'])) ?> – <?= date('d.m.Y', strtotime($m['date_end'])) ?></p>
            <a class="btn-action btn-secondaryx btn-full mb-2" href="menu.php?pdf=<?= (int) $m['id'] ?>"><i class="bi bi-file-earmark-pdf"></i> PDF İndir</a>
            <?php if (!$items): ?>
              <div class="empty-state">Bu menüde gün eklenmemiş.</div>
            <?php else: ?>
              <div class="screen-stack">
                <?php foreach ($items as $it): ?>
                  <div class="meal-card">
                    <p class="label"><?= Helpers::e(gun_label_tr($it['item_date'])) ?> · <?= Helpers::e($meals[$it['meal']] ?? $it['meal']) ?></p>
                    <h2 style="margin:2px 0 0; font-size:16px"><?= Helpers::e($it['dishes']) ?></h2>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer_m.php'; ?>
