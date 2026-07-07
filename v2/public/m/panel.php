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

$month = date('Y-m');
$ay = $repo->customerMonthProduction($cid, $month);

$tomorrow = date('Y-m-d', strtotime('+1 day'));
$order = $repo->customerOrder($cid, $tomorrow, 'ogle');
$cutoff = Helpers::orderCutoffLabel();
$tomorrowEditable = Helpers::orderEditable($tomorrow);

$today = Helpers::today();
// Bugünün menüsü: SADECE bu müşteriye yayınlanmış menülerden (IDOR scope) öğle kalemi.
$menuText = '';
foreach ($repo->menusForCustomer($cid) as $m) {
    foreach ($repo->menuItems((int) $m['id']) as $it) {
        if ($it['item_date'] === $today && $it['meal'] === 'ogle' && trim((string) $it['dishes']) !== '') {
            $menuText = (string) $it['dishes'];
            break 2;
        }
    }
}

$openReqs = $repo->customerRequests($cid);
$openCount = 0;
foreach ($openReqs as $r) {
    if ($r['status'] === 'acik') {
        $openCount++;
    }
}
// Bekleyen (açık/hazırlanan) malzeme talebi rozeti
$openSupply = 0;
foreach ($repo->supplyRequestsForCustomer($cid) as $sr) {
    if ($sr['status'] !== 'teslim') {
        $openSupply++;
    }
}

$statusMap = [
    'gonderildi'  => ['badge-warn', 'bi-clock', 'Gönderildi'],
    'onaylandi'   => ['badge-ok', 'bi-check2-circle', 'Onaylandı'],
    'reddedildi'  => ['badge-warn', 'bi-x-circle', 'Reddedildi'],
    'taslak'      => ['badge-blue', 'bi-pencil', 'Taslak'],
];

$eyebrow = Helpers::e($cu['customer_name']) . ' Yemek Paneli';
$pageTitle = 'Merhaba ' . (($cu['display_name'] ?: $cu['username']));
$active = 'panel';
require __DIR__ . '/partials/header_m.php';
?>
      <div class="summary-grid">
        <div class="summary-card"><p class="label"><?= Helpers::e(ay_label_tr($month)) ?> kişi</p><p class="metric"><?= number_format($ay['persons'], 0, ',', '.') ?></p></div>
        <div class="summary-card"><p class="label"><?= Helpers::e(ay_label_tr($month)) ?> tutar</p><p class="metric">₺ <?= Helpers::money($ay['amount']) ?></p></div>
      </div>

      <div class="cardx card-pad">
        <div class="d-flex align-items-start justify-content-between gap-2">
          <div>
            <p class="label">Yarının siparişi (<?= Helpers::e(gun_label_tr($tomorrow)) ?>)</p>
            <?php if ($order): [$bc, $bi, $bt] = $statusMap[$order['status']] ?? ['badge-blue', 'bi-clock', $order['status']]; ?>
              <h2><?= (int) $order['persons'] ?> kişi · Öğle</h2>
              <p class="row-meta">Durum: <?= Helpers::e($bt) ?></p>
            <?php else: ?>
              <h2>Henüz girilmedi</h2>
              <p class="row-meta">Yarın için kişi sayısı bildirin.</p>
            <?php endif; ?>
          </div>
          <?php if ($order): ?>
            <span class="badge-soft <?= $bc ?>"><i class="bi <?= $bi ?>"></i> <?= Helpers::e($bt) ?></span>
          <?php else: ?>
            <a class="btn-action btn-primaryx" href="siparis.php"><i class="bi bi-plus"></i> Gir</a>
          <?php endif; ?>
        </div>
        <?php if ($tomorrowEditable): ?>
          <p class="row-meta mt-2"><i class="bi bi-clock-history"></i> Değişiklik saati: bugün <?= Helpers::e($cutoff) ?>'a kadar. <a href="siparis.php" style="text-decoration:underline">Sayı gir / değiştir</a></p>
        <?php else: ?>
          <p class="row-meta mt-2"><i class="bi bi-lock"></i> Yarın için değişiklik saati (<?= Helpers::e($cutoff) ?>) geçti — sayı kilitlendi.</p>
        <?php endif; ?>
      </div>

      <div class="meal-card">
        <p class="label">Günün menüsü (<?= Helpers::e(gun_label_tr($today)) ?>)</p>
        <?php if ($menuText !== ''): ?>
          <h2><?= Helpers::e($menuText) ?></h2>
        <?php else: ?>
          <h2 class="text-muted">Menü yakında</h2>
          <p class="row-meta">Yayınlanan menü burada görünecek.</p>
        <?php endif; ?>
      </div>

      <?php if ($openCount > 0): ?>
        <a class="cardx card-pad" href="talep.php" style="display:block">
          <div class="d-flex align-items-center justify-content-between gap-2">
            <div><p class="label">Talepler</p><h2><?= $openCount ?> açık talep</h2></div>
            <span class="badge-soft badge-warn"><i class="bi bi-chat-left-text"></i> Görüntüle</span>
          </div>
        </a>
      <?php else: ?>
        <div class="empty-state">
          Açık talebiniz yok.
          <div class="mt-3"><a class="btn-action btn-secondaryx" href="talep.php">Talep aç</a></div>
        </div>
      <?php endif; ?>

      <a class="cardx card-pad" href="malzeme.php" style="display:block">
        <div class="d-flex align-items-center justify-content-between gap-2">
          <div><p class="label">Sarf malzeme</p><h2 style="margin:0"><?= $openSupply > 0 ? $openSupply . ' talep sürüyor' : 'Malzeme iste' ?></h2></div>
          <span class="badge-soft <?= $openSupply > 0 ? 'badge-warn' : 'badge-blue' ?>"><i class="bi bi-box-seam"></i> <?= $openSupply > 0 ? 'Görüntüle' : 'Talep aç' ?></span>
        </div>
      </a>
<?php require __DIR__ . '/partials/footer_m.php'; ?>
