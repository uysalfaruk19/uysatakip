<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Uysa\CustomerAuth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Push;
use Uysa\Repo;

$cu = CustomerAuth::requireCustomer();
$cid = (int) $cu['customer_id'];
$pdo = Db::pdo();
$repo = new Repo($pdo);

$meals = ['sabah' => 'Sabah', 'ogle' => 'Öğle', 'aksam' => 'Akşam', 'kumanya' => 'Kumanya'];
$cutoff = Helpers::orderCutoffLabel();

$tomorrow = date('Y-m-d', strtotime('+1 day'));
$date = (string) ($_GET['date'] ?? $tomorrow);
if (!Helpers::isDate($date)) {
    $date = $tomorrow;
}
$meal = (string) ($_GET['meal'] ?? 'ogle');
if (!isset($meals[$meal])) {
    $meal = 'ogle';
}

$flash = '';
$flashOk = true;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $pDate = (string) ($_POST['date'] ?? $date);
        $pMeal = (string) ($_POST['meal'] ?? $meal);
        if (!Helpers::isDate($pDate)) {
            $pDate = $tomorrow;
        }
        if (!isset($meals[$pMeal])) {
            $pMeal = 'ogle';
        }
        $date = $pDate;
        $meal = $pMeal;
        if (!Helpers::orderEditable($pDate)) {
            $flash = $pDate === $tomorrow
                ? "Yarın için değişiklik saati ($cutoff) geçti."
                : "Değişiklik süresi doldu (bir gün önce saat $cutoff).";
            $flashOk = false;
        } else {
            $persons = max(0, (int) ($_POST['persons'] ?? 0));
            $repo->upsertOrder($cid, $pDate, $pMeal, $persons, 'gonderildi', 'musteri');
            uysa_audit('musteri_siparis', $cu['username'], (string) $cid, "$pDate $pMeal $persons", client_ip());
            $flash = $persons > 0
                ? "Siparişiniz alındı: $persons kişi · onay bekliyor."
                : 'Sipariş sıfırlandı (0 kişi).';
            // opus-021: adminlere push ("<müşteri> yarın <n> kişi") — hata siparişi KIRMAZ
            try {
                $gunTxt = $pDate === $tomorrow ? 'yarın' : gun_label_tr($pDate);
                (new Push($pdo))->toAdmins('Yeni sipariş', "{$cu['customer_name']} $gunTxt $persons kişi", ['url' => '/siparisler.php'], 'siparis');
            } catch (\Throwable) {
            }
        }
    }
}

$order = $repo->customerOrder($cid, $date, $meal);
$editable = Helpers::orderEditable($date);
$current = $order ? (int) $order['persons'] : 0;
// opus-019: bu gün için henüz sipariş yoksa, önceki günkü sayı DEFAULT gelir
// (müşteri dokunmazsa aynen gönderilir). Sipariş varsa onun sayısı gösterilir.
$lastPersons = $current > 0 ? 0 : $repo->lastPersonsFor($cid, $meal, $date);
$defaultPersons = $current > 0 ? $current : $lastPersons;
$deadlineTs = Helpers::orderDeadline($date);
$history = $repo->customerOrders($cid, 20);

$statusMap = [
    'gonderildi' => ['badge-warn', 'bi-clock', 'Onay bekliyor'],
    'onaylandi'  => ['badge-ok', 'bi-check2-circle', 'Onaylandı'],
    'reddedildi' => ['badge-neg', 'bi-x-circle', 'Reddedildi'],
    'taslak'     => ['badge-blue', 'bi-pencil', 'Taslak'],
];

$prevDay = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDay = date('Y-m-d', strtotime($date . ' +1 day'));

$eyebrow = Helpers::e($cu['customer_name']) . ' · Sipariş';
$pageTitle = 'Sipariş ver';
$active = 'siparis';
require __DIR__ . '/partials/header_m.php';
?>
      <div class="date-row">
        <a class="icon-btn" href="siparis.php?date=<?= $prevDay ?>&meal=<?= $meal ?>" aria-label="Önceki gün"><i class="bi bi-chevron-left"></i></a>
        <form method="get" class="date-pill">
          <i class="bi bi-calendar2-week"></i>
          <input type="date" name="date" value="<?= Helpers::e($date) ?>" min="<?= $tomorrow ?>" onchange="this.form.submit()">
          <input type="hidden" name="meal" value="<?= Helpers::e($meal) ?>">
        </form>
        <a class="icon-btn" href="siparis.php?date=<?= $nextDay ?>&meal=<?= $meal ?>" aria-label="Sonraki gün"><i class="bi bi-chevron-right"></i></a>
      </div>

      <div class="segmented mt-2">
        <?php foreach ($meals as $mk => $ml): ?>
          <a class="chip <?= $mk === $meal ? 'active' : '' ?>" href="siparis.php?date=<?= Helpers::e($date) ?>&meal=<?= $mk ?>"><?= Helpers::e($ml) ?></a>
        <?php endforeach; ?>
      </div>

      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?> mt-2"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <div class="cardx card-pad mt-2">
        <div class="d-flex align-items-center justify-between gap-2">
          <div>
            <p class="label"><?= Helpers::e(gun_label_tr($date)) ?> · <?= Helpers::e($meals[$meal]) ?></p>
            <h2 style="margin:0"><?= $current > 0 ? $current . ' kişi' : 'Henüz girilmedi' ?></h2>
          </div>
          <?php if ($order): [$bc, $bi, $bt] = $statusMap[$order['status']] ?? ['badge-blue', 'bi-clock', $order['status']]; ?>
            <span class="badge-soft <?= $bc ?>"><i class="bi <?= $bi ?>"></i> <?= Helpers::e($bt) ?></span>
          <?php endif; ?>
        </div>

        <?php if ($editable): ?>
          <form method="post" class="mt-3">
            <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
            <input type="hidden" name="date" value="<?= Helpers::e($date) ?>">
            <input type="hidden" name="meal" value="<?= Helpers::e($meal) ?>">
            <div class="field"><label>Kişi sayısı</label>
              <div class="counter">
                <button class="step-btn" type="button" data-step="-5">−</button>
                <input class="count-input" inputmode="numeric" type="number" min="0" name="persons" value="<?= $defaultPersons > 0 ? $defaultPersons : '' ?>" placeholder="0">
                <button class="step-btn" type="button" data-step="5">+</button>
              </div>
            </div>
            <?php if ($current === 0 && $lastPersons > 0): ?>
              <p class="row-meta mt-1"><i class="bi bi-arrow-repeat"></i> Önceki gününüz <strong><?= $lastPersons ?> kişi</strong> — değiştirmezseniz aynı sayı gönderilir.</p>
            <?php endif; ?>
            <button class="btn-action btn-primaryx btn-full mt-3" type="submit"><i class="bi bi-send"></i> Siparişi gönder</button>
            <p class="row-meta mt-2"><i class="bi bi-arrow-counterclockwise"></i> 0 kişi gönderirseniz bu öğünün siparişi sıfırlanır.</p>
            <p class="row-meta mt-2"><i class="bi bi-info-circle"></i> Son değişiklik: <?= date('d.m.Y H:i', $deadlineTs) ?>'a kadar.</p>
          </form>
        <?php else: ?>
          <div class="hint-card mt-3"><i class="bi bi-lock"></i>
            <?php if ($date === $tomorrow): ?>Yarın için değişiklik saati (<?= Helpers::e($cutoff) ?>) geçti — sipariş kilitlendi.<?php else: ?>Bu günün siparişi kilitlendi (son değişiklik <?= date('d.m.Y H:i', $deadlineTs) ?> idi).<?php endif; ?>
            Değişiklik için UYSA ile iletişime geçin.</div>
        <?php endif; ?>
      </div>

      <div class="section-head mt-3"><h2>Geçmiş siparişler</h2></div>
      <?php if (!$history): ?>
        <div class="empty-state">Henüz sipariş girmediniz.</div>
      <?php else: ?>
        <div class="list-groupx">
          <?php foreach ($history as $h): [$bc, $bi, $bt] = $statusMap[$h['status']] ?? ['badge-blue', 'bi-clock', $h['status']]; ?>
            <div class="flow-item" style="grid-template-columns: minmax(0,1fr) auto">
              <div style="min-width:0">
                <div class="row-title"><strong><?= Helpers::e(gun_label_tr($h['order_date'])) ?></strong></div>
                <p class="row-meta"><?= (int) $h['persons'] ?> kişi · <?= Helpers::e($meals[$h['meal']] ?? $h['meal']) ?></p>
              </div>
              <span class="badge-soft <?= $bc ?>"><i class="bi <?= $bi ?>"></i> <?= Helpers::e($bt) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer_m.php'; ?>
