<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

// fable-003 (cateringkolay Teslimatlarım esinli): günün sevkiyatı — üretimi olan
// müşteriler, tek dokunuşla bekliyor → yolda → teslim.
$u = Auth::requireLogin();
$pdo = Db::pdo();
$repo = new Repo($pdo);

$date = (string) ($_GET['date'] ?? Helpers::today());
if (!Helpers::isDate($date)) {
    $date = Helpers::today();
}
$flash = '';
$flashOk = true;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $pDate = (string) ($_POST['date'] ?? $date);
        $date = Helpers::isDate($pDate) ? $pDate : Helpers::today();
        $cid = (int) ($_POST['customer_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');
        if ($cid > 0 && in_array($status, ['bekliyor', 'yolda', 'teslim'], true) && $repo->customer($cid)) {
            $repo->setDeliveryStatus($date, $cid, $status);
            uysa_audit('sevkiyat_durum', $u['username'], "$date/$cid", $status, client_ip());
            $flash = 'Durum güncellendi: ' . ['bekliyor' => 'Bekliyor', 'yolda' => 'Yolda', 'teslim' => 'Teslim edildi'][$status];
        } else {
            $flash = 'Geçersiz istek.';
            $flashOk = false;
        }
    }
}

$rows = $repo->deliveriesForDate($date);
$done = count(array_filter($rows, static fn ($r) => $r['status'] === 'teslim'));
$total = count($rows);
$csrf = Helpers::csrfToken();
$prevDay = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDay = date('Y-m-d', strtotime($date . ' +1 day'));

$statusMap = [
    'bekliyor' => ['badge-warn', 'bi-hourglass-split', 'Bekliyor'],
    'yolda'    => ['badge-blue', 'bi-truck', 'Yolda'],
    'teslim'   => ['badge-ok', 'bi-check2-circle', 'Teslim'],
];
// Tek dokunuş: sıradaki mantıklı adım
$nextStep = ['bekliyor' => 'yolda', 'yolda' => 'teslim'];
$nextLabel = ['bekliyor' => 'Yola çıktı', 'yolda' => 'Teslim edildi'];
$nextIcon = ['bekliyor' => 'bi-truck', 'yolda' => 'bi-check2'];

$pageTitle = 'Sevkiyat';
$eyebrow = 'Günün teslimatları';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <div class="date-row">
        <a class="icon-btn" href="sevkiyat.php?date=<?= $prevDay ?>" aria-label="Önceki gün"><i class="bi bi-chevron-left"></i></a>
        <form method="get" class="date-pill">
          <i class="bi bi-calendar2-week"></i>
          <input type="date" name="date" value="<?= Helpers::e($date) ?>" onchange="this.form.submit()">
        </form>
        <a class="icon-btn" href="sevkiyat.php?date=<?= $nextDay ?>" aria-label="Sonraki gün"><i class="bi bi-chevron-right"></i></a>
      </div>

      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <div class="stat-stack">
        <div class="stat-card <?= $total > 0 && $done >= $total ? 'stat-green' : 'stat-blue' ?>">
          <div class="ico"><i class="bi bi-truck"></i></div>
          <div class="txt">
            <p class="lbl"><?= Helpers::e(gun_label_tr($date)) ?> · teslim edilen</p>
            <p class="val"><?= $done ?>/<?= $total ?></p>
          </div>
        </div>
      </div>

      <?php if (!$rows): ?>
        <div class="empty-state">
          <div class="es-ico"><i class="bi bi-truck"></i></div>
          Bu gün için üretim girişi yok — sevkiyat listesi üretimden oluşur.</div>
      <?php else: ?>
        <div class="list-groupx">
          <?php foreach ($rows as $r): [$bc, $bi, $bt] = $statusMap[$r['status']]; ?>
            <div class="cardx card-pad">
              <div class="d-flex align-items-center justify-between gap-2">
                <div style="min-width:0">
                  <div class="row-title"><strong><?= Helpers::e($r['name']) ?></strong></div>
                  <p class="row-meta"><?= number_format($r['persons'], 0, ',', '.') ?> kişi</p>
                </div>
                <span class="badge-soft <?= $bc ?>"><i class="bi <?= $bi ?>"></i> <?= $bt ?></span>
              </div>
              <?php if (isset($nextStep[$r['status']])): ?>
                <div class="actions-row mt-2">
                  <?php if ($r['status'] === 'yolda'): ?>
                    <form method="post" class="flex-fill">
                      <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
                      <input type="hidden" name="date" value="<?= Helpers::e($date) ?>">
                      <input type="hidden" name="customer_id" value="<?= (int) $r['customer_id'] ?>">
                      <input type="hidden" name="status" value="bekliyor">
                      <button class="btn-action btn-ghost btn-full" type="submit"><i class="bi bi-arrow-counterclockwise"></i> Geri al</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" class="flex-fill">
                    <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
                    <input type="hidden" name="date" value="<?= Helpers::e($date) ?>">
                    <input type="hidden" name="customer_id" value="<?= (int) $r['customer_id'] ?>">
                    <input type="hidden" name="status" value="<?= $nextStep[$r['status']] ?>">
                    <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi <?= $nextIcon[$r['status']] ?>"></i> <?= $nextLabel[$r['status']] ?></button>
                  </form>
                </div>
              <?php else: ?>
                <form method="post" class="mt-2">
                  <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
                  <input type="hidden" name="date" value="<?= Helpers::e($date) ?>">
                  <input type="hidden" name="customer_id" value="<?= (int) $r['customer_id'] ?>">
                  <input type="hidden" name="status" value="yolda">
                  <button class="btn-action btn-ghost btn-full" type="submit"><i class="bi bi-arrow-counterclockwise"></i> Geri al (yolda)</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
