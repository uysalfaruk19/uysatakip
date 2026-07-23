<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

// fable-003 (cateringkolay "Mutfak Görünümü" esinli): seçili gün hangi öğün,
// hangi menü, hangi müşteri kaç kişi — mutfağın günlük iş emri, salt-okunur.
$u = Auth::requireLogin();
$pdo = Db::pdo();
$repo = new Repo($pdo);

$date = (string) ($_GET['date'] ?? Helpers::today());
if (!Helpers::isDate($date)) {
    $date = Helpers::today();
}
$prevDay = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDay = date('Y-m-d', strtotime($date . ' +1 day'));

$meals = ['sabah' => 'Sabah', 'ogle' => 'Öğle', 'aksam' => 'Akşam', 'gece' => 'Gece', 'kumanya' => 'Kumanya'];
$kitchen = $repo->kitchenDay($date);
$menuItems = $repo->publishedMenuItems($date, $date); // gün×öğün yemekleri (tüm yayınlanmış menüler)
$menuByMeal = [];
foreach ($menuItems as $mi) {
    $menuByMeal[$mi['meal']][] = $mi;
}
$grandTotal = 0;
foreach ($kitchen as $k) {
    $grandTotal += $k['total'];
}

$pageTitle = 'Mutfak Görünümü';
$eyebrow = 'Günlük üretim iş emri';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <div class="date-row">
        <a class="icon-btn" href="mutfak.php?date=<?= $prevDay ?>" aria-label="Önceki gün"><i class="bi bi-chevron-left"></i></a>
        <form method="get" class="date-pill">
          <i class="bi bi-calendar2-week"></i>
          <input type="date" name="date" value="<?= Helpers::e($date) ?>" onchange="this.form.submit()">
        </form>
        <a class="icon-btn" href="mutfak.php?date=<?= $nextDay ?>" aria-label="Sonraki gün"><i class="bi bi-chevron-right"></i></a>
      </div>

      <div class="stat-stack">
        <div class="stat-card stat-orange">
          <div class="ico"><i class="bi bi-fire"></i></div>
          <div class="txt">
            <p class="lbl"><?= Helpers::e(gun_label_tr($date)) ?> · toplam porsiyon</p>
            <p class="val"><?= number_format($grandTotal, 0, ',', '.') ?></p>
          </div>
        </div>
      </div>

      <?php if (!$kitchen && !$menuByMeal): ?>
        <div class="empty-state">
        <div class="es-ico"><i class="bi bi-fire"></i></div>
        Bu gün için üretim girişi ve yayınlanmış menü yok.</div>
      <?php endif; ?>

      <?php foreach ($meals as $mk => $ml):
          $hasProd = isset($kitchen[$mk]);
          $hasMenu = isset($menuByMeal[$mk]);
          if (!$hasProd && !$hasMenu) {
              continue;
          } ?>
        <div class="cardx card-pad mb-2">
          <div class="d-flex align-items-center justify-between gap-2 mb-2">
            <h2 style="margin:0"><?= Helpers::e($ml) ?></h2>
            <?php if ($hasProd): ?>
              <span class="badge-soft badge-ok"><i class="bi bi-people"></i> <?= number_format($kitchen[$mk]['total'], 0, ',', '.') ?> porsiyon</span>
            <?php endif; ?>
          </div>
          <?php if ($hasMenu): foreach ($menuByMeal[$mk] as $mi): ?>
            <div class="meal-card mb-2">
              <p class="label"><?= Helpers::e($mi['menu_title']) ?></p>
              <h2 style="margin:2px 0 0;font-size:15px;white-space:pre-line"><?= Helpers::e($mi['dishes']) ?></h2>
            </div>
          <?php endforeach; else: ?>
            <p class="row-meta mb-2"><i class="bi bi-info-circle"></i> Bu öğüne yayınlanmış menü yok.</p>
          <?php endif; ?>
          <?php if ($hasProd): ?>
            <?php foreach ($kitchen[$mk]['customers'] as $c): ?>
              <div class="supply-row">
                <div class="s-name"><?= Helpers::e($c['name']) ?></div>
                <div style="text-align:right"><strong><?= number_format($c['persons'], 0, ',', '.') ?></strong> <span class="s-meta">kişi</span></div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="row-meta"><i class="bi bi-exclamation-circle"></i> Üretim sayısı girilmemiş — <a href="bugun.php?date=<?= Helpers::e($date) ?>" style="text-decoration:underline">Bugün ekranından girin</a>.</p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <div class="hint-card mt-2">
        Bu ekran salt-okunur iş emridir: sayılar <strong>Bugün</strong> ekranından, yemekler <strong>Menü</strong>'den beslenir.
      </div>
<?php require __DIR__ . '/partials/footer.php'; ?>
