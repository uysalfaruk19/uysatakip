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

$meal = 'ogle';
$date = (string) ($_GET['date'] ?? Helpers::today());
if (!Helpers::isDate($date)) {
    $date = Helpers::today();
}
$flash = '';
$flashOk = true;

// ── Kaydet ───────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $postDate = (string) ($_POST['date'] ?? $date);
        $date = Helpers::isDate($postDate) ? $postDate : Helpers::today();
        $persons = $_POST['persons'] ?? [];
        $saved = 0;
        $pdo->beginTransaction();
        try {
            foreach ($repo->activeCustomers() as $c) {
                $cid = (int) $c['id'];
                $p = (int) ($persons[$cid] ?? 0);
                if ($p > 0) {
                    $repo->upsertProduction($cid, $date, $p, (float) $c['unit_price'], $meal, 'uysa');
                    $saved++;
                } else {
                    $repo->deleteProduction($cid, $date, $meal);
                }
            }
            $pdo->commit();
            uysa_audit('uretim_kaydet', $u['username'], $date, json_encode(['n' => $saved]), client_ip());
            $flash = "Kaydedildi · $saved müşteri";
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $flash = 'Kayıt hatası.';
            $flashOk = false;
        }
    }
}

// ── Dünü kopyala ─────────────────────────────────────────────
$copyValues = null;
if (isset($_GET['copy'])) {
    $prev = $repo->previousProductionDate($date, $meal);
    if ($prev !== null) {
        $copyValues = $repo->productionPersonsByCustomer($prev, $meal);
        $flash = date('d.m.Y', strtotime($prev)) . " tarihinden kopyalandı — kontrol edip Kaydet'e basın.";
    } else {
        $flash = 'Kopyalanacak önceki gün yok.';
        $flashOk = false;
    }
}

$grid = $repo->dayGrid($date, $meal);
$existing = $repo->productionPersonsByCustomer($date, $meal);

// Sunucu-tarafı ilk render toplamları
$sumP = 0; $sumA = 0.0; $filled = 0;
$rowsData = [];
foreach ($grid as $r) {
    $cid = (int) $r['customer_id'];
    $val = $copyValues[$cid] ?? ($existing[$cid] ?? 0);
    $price = (float) $r['unit_price'];
    $amt = $val * $price;
    if ($val > 0) { $sumP += $val; $sumA += $amt; $filled++; }
    $rowsData[] = ['cid' => $cid, 'name' => $r['name'], 'price' => $price, 'val' => (int) $val, 'amt' => $amt];
}
$total = count($rowsData);

$prevDay = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDay = date('Y-m-d', strtotime($date . ' +1 day'));

$pageTitle = 'Bugün';
$active = 'bugun';
require __DIR__ . '/partials/header.php';
?>
      <div class="date-row">
        <a class="icon-btn" href="bugun.php?date=<?= $prevDay ?>" aria-label="Önceki gün"><i class="bi bi-chevron-left"></i></a>
        <form method="get" class="date-pill">
          <i class="bi bi-calendar2-week"></i>
          <input type="date" name="date" value="<?= Helpers::e($date) ?>" onchange="this.form.submit()">
        </form>
        <a class="icon-btn" href="bugun.php?date=<?= $nextDay ?>" aria-label="Sonraki gün"><i class="bi bi-chevron-right"></i></a>
      </div>

      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <div class="summary-grid">
        <div class="summary-card">
          <p class="label">Toplam kişi</p>
          <p class="metric" id="sum-persons"><?= number_format($sumP, 0, ',', '.') ?></p>
        </div>
        <div class="summary-card">
          <p class="label">Gün tutarı</p>
          <p class="metric">₺ <span id="sum-amount"><?= Helpers::money($sumA) ?></span></p>
          <span class="delta"><i class="bi bi-check2-circle"></i> <span id="sum-filled"><?= $filled ?>/<?= $total ?> girildi</span></span>
        </div>
      </div>

      <div class="hint-card">
        Bu veriler WhatsApp'tan da girilebilir: OFUclaw'a <strong>"cantaş 450 opak 280"</strong> yaz.
      </div>

      <form method="post" id="bugun-form">
        <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
        <input type="hidden" name="date" value="<?= Helpers::e($date) ?>">
        <div class="cardx card-pad">
          <h2>Müşteri sayıları</h2>
          <?php if (!$rowsData): ?>
            <div class="empty-state">Aktif müşteri yok.</div>
          <?php endif; ?>
          <?php foreach ($rowsData as $r): $missing = $r['val'] === 0; ?>
            <div class="customer-row <?= $missing ? 'missing' : '' ?>" data-price="<?= $r['price'] ?>">
              <div>
                <div class="row-title"><span class="status-dot <?= $missing ? 'warn' : '' ?>"></span><strong><?= Helpers::e($r['name']) ?></strong></div>
                <p class="row-meta">₺ <?= Helpers::money($r['price']) ?> kişi başı · <span class="row-amt"><?= $missing ? 'girilmedi' : '₺ ' . Helpers::money($r['amt']) ?></span></p>
              </div>
              <div class="counter">
                <button class="step-btn" type="button" data-step="-5">−</button>
                <input class="count-input" inputmode="numeric" type="number" min="0" name="persons[<?= $r['cid'] ?>]" value="<?= $r['val'] > 0 ? $r['val'] : '' ?>">
                <button class="step-btn" type="button" data-step="5">+</button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="actions-row mt-3">
          <a class="btn-action btn-secondaryx flex-fill" href="bugun.php?date=<?= Helpers::e($date) ?>&copy=1"><i class="bi bi-copy"></i> Dünü kopyala</a>
          <button class="btn-action btn-primaryx flex-fill" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
        </div>
      </form>
<?php require __DIR__ . '/partials/footer.php'; ?>
