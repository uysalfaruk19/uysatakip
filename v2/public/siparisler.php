<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

$u = Auth::requireLogin();
$pdo = Db::pdo();
$repo = new Repo($pdo);

$meals = ['sabah' => 'Sabah', 'ogle' => 'Öğle', 'aksam' => 'Akşam', 'gece' => 'Gece', 'kumanya' => 'Kumanya'];
$flash = '';
$flashOk = true;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($action === 'approve') {
            $res = $repo->approveOrder($orderId);
            if ($res) {
                uysa_audit('siparis_onayla', $u['username'], (string) $orderId, json_encode($res), client_ip());
                $flash = 'Onaylandı → üretime yazıldı: ' . $res['persons'] . ' kişi · ₺ ' . Helpers::money($res['amount']);
            } else {
                $flash = 'Sipariş bulunamadı.';
                $flashOk = false;
            }
        } elseif ($action === 'reject') {
            if ($repo->rejectOrder($orderId)) {
                uysa_audit('siparis_reddet', $u['username'], (string) $orderId, null, client_ip());
                $flash = 'Sipariş reddedildi.';
            } else {
                $flash = 'Sipariş bulunamadı.';
                $flashOk = false;
            }
        }
    }
}

$pending = $repo->pendingOrders();
$decided = $repo->decidedOrders(20);

// fable-001 (cateringkolay "Yemek Girişi Cetvel" esinli): haftalık müşteri × gün matrisi.
$view = (string) ($_GET['view'] ?? 'kuyruk');
if (!in_array($view, ['kuyruk', 'hafta'], true)) {
    $view = 'kuyruk';
}
$wDate = (string) ($_GET['w'] ?? date('Y-m-d'));
if (!Helpers::isDate($wDate)) {
    $wDate = date('Y-m-d');
}
$dowW = (int) date('N', strtotime($wDate));
$weekStart = date('Y-m-d', strtotime($wDate . ' -' . ($dowW - 1) . ' day'));
$weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 day'));
$matrix = [];      // customer_name => [date => persons]
$dayTotals = [];   // date => persons
if ($view === 'hafta') {
    foreach ($repo->ordersMatrix($weekStart, $weekEnd) as $row) {
        $matrix[$row['customer_name']][$row['order_date']] = (int) $row['persons'];
        $dayTotals[$row['order_date']] = ($dayTotals[$row['order_date']] ?? 0) + (int) $row['persons'];
    }
}
$gunKisa = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'];

$statusMap = [
    'onaylandi'  => ['badge-ok', 'bi-check2-circle', 'Onaylandı'],
    'reddedildi' => ['badge-neg', 'bi-x-circle', 'Reddedildi'],
];

$csrf = Helpers::csrfToken();
$pageTitle = 'Siparişler';
$eyebrow = 'Müşteri onay kuyruğu';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <div class="segmented">
        <a class="chip <?= $view === 'kuyruk' ? 'active' : '' ?>" href="siparisler.php">Onay kuyruğu<?= $pending ? ' (' . count($pending) . ')' : '' ?></a>
        <a class="chip <?= $view === 'hafta' ? 'active' : '' ?>" href="siparisler.php?view=hafta">Haftalık cetvel</a>
      </div>

<?php if ($view === 'hafta'): ?>
      <div class="cardx card-pad mt-2">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
          <a class="icon-btn" href="siparisler.php?view=hafta&w=<?= date('Y-m-d', strtotime($weekStart . ' -7 day')) ?>" aria-label="Önceki hafta"><i class="bi bi-chevron-left"></i></a>
          <p class="label" style="margin:0"><?= date('d.m', strtotime($weekStart)) ?> – <?= date('d.m.Y', strtotime($weekEnd)) ?></p>
          <a class="icon-btn" href="siparisler.php?view=hafta&w=<?= date('Y-m-d', strtotime($weekStart . ' +7 day')) ?>" aria-label="Sonraki hafta"><i class="bi bi-chevron-right"></i></a>
        </div>
        <?php if (!$matrix): ?>
          <div class="empty-state">Bu hafta sipariş girişi yok.</div>
        <?php else: ?>
          <div style="overflow-x:auto">
            <table class="cetvel-table">
              <thead><tr><th>Müşteri</th>
                <?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime($weekStart . " +$i day")); ?>
                  <th class="<?= $d === date('Y-m-d') ? 'today' : '' ?>"><?= $gunKisa[$i] ?><br><span><?= date('d.m', strtotime($d)) ?></span></th>
                <?php endfor; ?>
                <th>Σ</th></tr></thead>
              <tbody>
                <?php foreach ($matrix as $cname => $row): $rowSum = array_sum($row); ?>
                  <tr><td><?= Helpers::e($cname) ?></td>
                    <?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime($weekStart . " +$i day")); ?>
                      <td class="<?= $d === date('Y-m-d') ? 'today' : '' ?>"><?= isset($row[$d]) ? number_format($row[$d], 0, ',', '.') : '·' ?></td>
                    <?php endfor; ?>
                    <td><strong><?= number_format($rowSum, 0, ',', '.') ?></strong></td></tr>
                <?php endforeach; ?>
                <tr class="total-row"><td>Toplam</td>
                  <?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime($weekStart . " +$i day")); ?>
                    <td><?= isset($dayTotals[$d]) ? number_format($dayTotals[$d], 0, ',', '.') : '·' ?></td>
                  <?php endfor; ?>
                  <td><strong><?= number_format(array_sum($dayTotals), 0, ',', '.') ?></strong></td></tr>
              </tbody>
            </table>
          </div>
          <p class="row-meta mt-2"><i class="bi bi-info-circle"></i> Reddedilenler hariç tüm girişler (onaylı + onay bekleyen), öğünler toplanmış.</p>
        <?php endif; ?>
      </div>
<?php require __DIR__ . '/partials/footer.php'; return; endif; ?>

      <div class="stat-stack">
        <div class="stat-card stat-orange">
          <div class="ico"><i class="bi bi-hourglass-split"></i></div>
          <div class="txt">
            <p class="lbl">Onay bekleyen</p>
            <p class="val"><?= count($pending) ?></p>
          </div>
        </div>
      </div>

      <div class="section-head mt-3"><h2>Onay kuyruğu</h2></div>
      <?php if (!$pending): ?>
        <div class="empty-state">Onay bekleyen müşteri siparişi yok.</div>
      <?php else: ?>
        <div class="list-groupx">
          <?php foreach ($pending as $o): $amt = (int) $o['persons'] * (float) $o['unit_price']; ?>
            <div class="cardx card-pad">
              <div class="d-flex align-items-center justify-between gap-2">
                <div style="min-width:0">
                  <div class="row-title"><strong><?= Helpers::e($o['customer_name']) ?></strong></div>
                  <p class="row-meta"><?= Helpers::e(gun_label_tr($o['order_date'])) ?> · <?= Helpers::e($meals[$o['meal']] ?? $o['meal']) ?> · kaynak: <?= Helpers::e($o['entered_by']) ?></p>
                </div>
                <div style="text-align:right">
                  <div class="amount"><?= (int) $o['persons'] ?> kişi</div>
                  <p class="row-meta">₺ <?= Helpers::money($amt) ?></p>
                </div>
              </div>
              <div class="actions-row mt-3">
                <form method="post" class="flex-fill">
                  <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
                  <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                  <input type="hidden" name="action" value="reject">
                  <button class="btn-action btn-secondaryx btn-full" type="submit"><i class="bi bi-x-lg"></i> Reddet</button>
                </form>
                <form method="post" class="flex-fill">
                  <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
                  <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                  <input type="hidden" name="action" value="approve">
                  <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-check2"></i> Onayla</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="section-head mt-3"><h2>Geçmiş</h2></div>
      <?php if (!$decided): ?>
        <div class="empty-state">Henüz karar verilmiş sipariş yok.</div>
      <?php else: ?>
        <div class="list-groupx">
          <?php foreach ($decided as $o): [$bc, $bi, $bt] = $statusMap[$o['status']] ?? ['badge-blue', 'bi-clock', $o['status']]; ?>
            <div class="flow-item" style="grid-template-columns: minmax(0,1fr) auto">
              <div style="min-width:0">
                <div class="row-title"><strong><?= Helpers::e($o['customer_name']) ?></strong></div>
                <p class="row-meta"><?= Helpers::e(gun_label_tr($o['order_date'])) ?> · <?= (int) $o['persons'] ?> kişi · <?= Helpers::e($meals[$o['meal']] ?? $o['meal']) ?></p>
              </div>
              <span class="badge-soft <?= $bc ?>"><i class="bi <?= $bi ?>"></i> <?= Helpers::e($bt) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="hint-card mt-3">
        Onaylanan sipariş üretime (fiyat sabitlenerek) yazılır ve <strong>Bugün</strong> ekranında görünür. Müşteri "Onaylandı" rozetini kendi uygulamasında görür.
      </div>
<?php require __DIR__ . '/partials/footer.php'; ?>
