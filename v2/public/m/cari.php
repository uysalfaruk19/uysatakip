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

$ay = (string) ($_GET['ay'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
    $ay = date('Y-m');
}

// SADECE kendi ekstresi (IDOR: $cid oturumdan)
$rows = $repo->customerLedger($cid, $ay);
$balance = $repo->customerBalance($cid);
$month = $repo->customerMonthProduction($cid, $ay);

$prevAy = date('Y-m', strtotime($ay . '-01 -1 month'));
$nextAy = date('Y-m', strtotime($ay . '-01 +1 month'));

$eyebrow = Helpers::e($cu['customer_name']) . ' · Cari';
$pageTitle = 'Cari ekstre';
$active = 'cari';
require __DIR__ . '/partials/header_m.php';
?>
      <div class="date-row">
        <a class="icon-btn" href="cari.php?ay=<?= $prevAy ?>" aria-label="Önceki ay"><i class="bi bi-chevron-left"></i></a>
        <span class="date-pill"><i class="bi bi-calendar3"></i> <?= Helpers::e(ay_label_tr($ay)) ?></span>
        <a class="icon-btn" href="cari.php?ay=<?= $nextAy ?>" aria-label="Sonraki ay"><i class="bi bi-chevron-right"></i></a>
      </div>

      <div class="summary-grid mt-2">
        <div class="summary-card <?= $balance > 0 ? 'tint-orange' : 'tint-green' ?>">
          <p class="label">Güncel bakiye</p>
          <p class="metric <?= $balance > 0 ? '' : 'pos' ?>">₺ <?= Helpers::money(abs($balance)) ?></p>
          <p class="row-meta"><?= $balance > 0 ? 'Ödenecek tutar' : ($balance < 0 ? 'Alacaklısınız' : 'Bakiye sıfır') ?></p>
        </div>
        <div class="summary-card">
          <p class="label"><?= Helpers::e(ay_label_tr($ay)) ?> üretim</p>
          <p class="metric small"><?= number_format($month['persons'], 0, ',', '.') ?> kişi · ₺ <?= Helpers::money($month['amount']) ?></p>
        </div>
      </div>

      <div class="section-head mt-3"><h2>Hareketler</h2></div>
      <?php if (!$rows): ?>
        <div class="empty-state">Bu ay hareket yok.</div>
      <?php else: ?>
        <div class="list-groupx">
          <?php foreach ($rows as $r): $out = $r['kind'] === 'borc'; ?>
            <div class="flow-item">
              <span class="flow-icon <?= $out ? 'out' : '' ?>"><i class="bi <?= $out ? 'bi-basket' : 'bi-cash-coin' ?>"></i></span>
              <div style="min-width:0">
                <strong><?= Helpers::e($r['label']) ?></strong>
                <p class="row-meta"><?= Helpers::e(gun_label_tr($r['entry_date'])) ?></p>
              </div>
              <span class="amount <?= $out ? 'out' : 'in' ?>"><?= $out ? '+' : '−' ?> ₺ <?= Helpers::money($r['amount']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="row-meta mt-2"><i class="bi bi-info-circle"></i> "+" borç (üretim), "−" tahsilat.</p>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer_m.php'; ?>
