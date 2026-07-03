<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

$u = Auth::requireLogin();
$repo = new Repo(Db::pdo());

$month = (string) ($_GET['ay'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$rows = $repo->monthProductionByCustomer($month);
$fin = $repo->monthFinanceTotals($month);
$toplamCiro = 0.0; $toplamKisi = 0;
foreach ($rows as $r) {
    $toplamCiro += (float) $r['ciro'];
    $toplamKisi += (int) $r['persons'];
}

$eyebrow = ay_label_tr($month);
$pageTitle = 'Rapor';
$active = 'rapor';
require __DIR__ . '/partials/header.php';
?>
      <form method="get" class="date-row">
        <div class="date-pill"><i class="bi bi-calendar2-week"></i>
          <input type="month" name="ay" value="<?= Helpers::e($month) ?>" onchange="this.form.submit()">
        </div>
      </form>

      <div class="summary-grid">
        <div class="summary-card"><p class="label">Toplam kişi</p><p class="metric"><?= number_format($toplamKisi, 0, ',', '.') ?></p></div>
        <div class="summary-card"><p class="label">Ciro</p><p class="metric">₺ <?= Helpers::money($toplamCiro) ?></p></div>
        <div class="summary-card wide"><p class="label">Finans net</p><p class="metric <?= $fin['net'] < 0 ? 'neg' : '' ?>">₺ <?= Helpers::money($fin['net']) ?></p></div>
      </div>

      <div class="cardx card-pad">
        <h2>Müşteri × <?= Helpers::e(ay_label_tr($month)) ?></h2>
        <?php if (!$rows): ?>
          <div class="empty-state">Bu ay üretim kaydı yok.</div>
        <?php else: ?>
          <table class="tablex">
            <thead><tr><th>Müşteri</th><th class="num">Gün</th><th class="num">Kişi</th><th class="num">Ciro</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= Helpers::e($r['name']) ?></td>
                <td class="num"><?= (int) $r['gun'] ?></td>
                <td class="num"><?= number_format((int) $r['persons'], 0, ',', '.') ?></td>
                <td class="num">₺ <?= Helpers::money((float) $r['ciro']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <div class="ai-card">
        <span class="badge-soft badge-warn">F2</span>
        <p class="mt-2 row-meta">Kâr trendi grafiği, maliyet/kişi ve <strong>gün sonu AI özeti</strong> Faz 2'de bu ekrana ekleniyor.</p>
      </div>
<?php require __DIR__ . '/partials/footer.php'; ?>
