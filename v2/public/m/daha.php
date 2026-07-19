<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Uysa\CustomerAuth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

$cu = CustomerAuth::requireCustomer();
$cid = (int) $cu['customer_id'];
$repo = new Repo(Db::pdo());

// Açık talep / süren malzeme sayıları (panel.php ile aynı kaynak — rozet olarak gösterilir).
$openTalep = 0;
foreach ($repo->customerRequests($cid) as $r) {
    if ($r['status'] === 'acik') {
        $openTalep++;
    }
}
$openSupply = 0;
foreach ($repo->supplyRequestsForCustomer($cid) as $sr) {
    if ($sr['status'] !== 'teslim') {
        $openSupply++;
    }
}

// fable-018: bardan kalkan ama erişilebilir kalan sayfalar tek listede (URL kırılmaz).
$items = [
    ['malzeme.php', 'bi-box-seam', 'Malzeme talebi', $openSupply > 0 ? $openSupply . ' sürüyor' : null],
    ['talep.php', 'bi-chat-left-text', 'Talepler & iletişim', $openTalep > 0 ? $openTalep . ' açık' : null],
    ['cari.php', 'bi-wallet2', 'Cari hesap', null],
];

$eyebrow = Helpers::e($cu['customer_name']) . ' · Daha';
$pageTitle = 'Daha';
$active = 'daha';
require __DIR__ . '/partials/header_m.php';
?>
      <div class="list-groupx">
        <?php foreach ($items as [$href, $icon, $label, $meta]): ?>
          <a class="flow-item" style="grid-template-columns: 34px minmax(0,1fr) auto" href="<?= $href ?>">
            <span class="feed-ico"><i class="bi <?= $icon ?>"></i></span>
            <div style="min-width:0"><strong><?= Helpers::e($label) ?></strong></div>
            <?php if ($meta !== null): ?>
              <span class="badge-soft badge-warn"><?= Helpers::e($meta) ?></span>
            <?php else: ?>
              <i class="bi bi-chevron-right row-meta"></i>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>

      <a class="btn-action btn-secondaryx btn-full mt-3" href="logout.php"><i class="bi bi-box-arrow-right"></i> Çıkış yap</a>
<?php require __DIR__ . '/partials/footer_m.php'; ?>
