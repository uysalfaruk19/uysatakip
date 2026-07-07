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
$prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));
$kapanis = $repo->ayKapanis($month);
$sum = $kapanis['summary'];

function close_badge_class(string $status): string
{
    return match ($status) {
        'ok' => 'badge-ok',
        'fail' => 'badge-neg',
        default => 'badge-warn',
    };
}

function close_badge_label(string $status): string
{
    return match ($status) {
        'ok' => 'Tamam',
        'fail' => 'Eksik',
        default => 'Dikkat',
    };
}

function close_pct(float $m): string
{
    return number_format($m * 100, 1, ',', '.') . '%';
}

$eyebrow = ay_label_tr($month);
$pageTitle = 'Ay Kapanışı';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <div class="date-row">
        <a class="icon-btn" href="ay-kapanisi.php?ay=<?= $prevMonth ?>" aria-label="Önceki ay"><i class="bi bi-chevron-left"></i></a>
        <form method="get" class="date-pill">
          <i class="bi bi-calendar2-month"></i>
          <input type="month" name="ay" value="<?= Helpers::e($month) ?>" onchange="this.form.submit()">
        </form>
        <a class="icon-btn" href="ay-kapanisi.php?ay=<?= $nextMonth ?>" aria-label="Sonraki ay"><i class="bi bi-chevron-right"></i></a>
      </div>

      <div class="summary-grid">
        <div class="summary-card <?= $kapanis['status'] === 'ok' ? 'tint-green' : ($kapanis['status'] === 'fail' ? 'tint-orange' : 'tint-blue') ?>">
          <p class="label">Kapanış durumu</p>
          <p class="metric small"><?= close_badge_label($kapanis['status']) ?> · <?= (int) $sum['warning_count'] ?> uyarı</p>
        </div>
        <div class="summary-card <?= $sum['toplam_net'] < 0 ? 'tint-orange' : 'tint-green' ?>">
          <p class="label">Net kâr</p>
          <p class="metric <?= $sum['toplam_net'] < 0 ? 'neg' : '' ?>">₺ <?= Helpers::money((float) $sum['toplam_net']) ?></p>
        </div>
        <div class="summary-card">
          <p class="label">Toplam gelir</p>
          <p class="metric">₺ <?= Helpers::money((float) $sum['toplam_gelir']) ?></p>
        </div>
        <div class="summary-card">
          <p class="label">Üretim</p>
          <p class="metric small"><?= (int) $sum['production_days'] ?> gün · <?= number_format((float) $sum['persons'], 0, ',', '.') ?> kişi</p>
        </div>
      </div>

      <div class="section-head"><h2>Kontrol listesi</h2><span class="text-muted" style="font-size:12px"><?= Helpers::e(ay_label_tr($month)) ?></span></div>
      <div class="list-groupx">
        <?php foreach ($kapanis['checks'] as $check): ?>
          <div class="flow-item">
            <span class="flow-icon <?= $check['status'] === 'fail' ? 'out' : '' ?>"><i class="bi <?= $check['status'] === 'ok' ? 'bi-check2' : 'bi-exclamation-triangle' ?>"></i></span>
            <div>
              <strong><?= Helpers::e($check['label']) ?></strong>
              <p class="row-meta"><?= Helpers::e($check['detail']) ?></p>
            </div>
            <?php if ($check['link'] !== ''): ?>
              <a class="badge-soft <?= close_badge_class($check['status']) ?>" href="<?= Helpers::e($check['link']) ?>"><?= close_badge_label($check['status']) ?></a>
            <?php else: ?>
              <span class="badge-soft <?= close_badge_class($check['status']) ?>"><?= close_badge_label($check['status']) ?></span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="cardx card-pad">
        <h2>Ay özeti</h2>
        <table class="tablex">
          <tbody>
            <tr><td>Üretim cirosu</td><td class="num">₺ <?= Helpers::money((float) $sum['production_amount']) ?></td></tr>
            <tr><td>Nakit net</td><td class="num" style="color:<?= $sum['nakit_net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money((float) $sum['nakit_net']) ?></td></tr>
            <tr><td>Personel maliyeti</td><td class="num">₺ <?= Helpers::money((float) $sum['personel']) ?></td></tr>
            <tr><td>Biriken kıdem yükü</td><td class="num">₺ <?= Helpers::money((float) $sum['kidem_birikim']) ?></td></tr>
            <tr><td>Finans hareketi</td><td class="num"><?= (int) $sum['transaction_count'] ?> kayıt</td></tr>
            <tr class="is-total"><td>Net marj</td><td class="num"><?= close_pct((float) $sum['toplam_marj']) ?></td></tr>
          </tbody>
        </table>
      </div>

      <?php if ($kapanis['negative_customers']): ?>
      <div class="cardx card-pad">
        <h2>Negatif müşteri kârı</h2>
        <table class="tablex">
          <tbody>
          <?php foreach (array_slice($kapanis['negative_customers'], 0, 8) as $r): ?>
            <tr>
              <td><a href="rapor.php?musteri=<?= (int) $r['customer_id'] ?>&ay=<?= $month ?>" style="color:var(--primary);font-weight:800"><?= Helpers::e($r['name']) ?></a></td>
              <td class="num" style="color:var(--red)">₺ <?= Helpers::money((float) $r['net']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <a class="btn-action btn-secondaryx btn-full mt-3" href="kar-analizi.php?ay=<?= $month ?>"><i class="bi bi-pie-chart"></i> Kâr analizine git</a>
      </div>
      <?php endif; ?>

      <?php if ($kapanis['no_production_customers']): ?>
      <div class="cardx card-pad">
        <h2>Bu ay kaydı olmayan aktif müşteriler</h2>
        <div class="list-groupx">
          <?php foreach (array_slice($kapanis['no_production_customers'], 0, 10) as $c): ?>
            <div class="customer-row missing" style="grid-template-columns:minmax(0,1fr) auto">
              <div><div class="row-title"><span class="status-dot warn"></span><strong><?= Helpers::e($c['name']) ?></strong></div><p class="row-meta"><?= $c['category'] === 'tasima' ? 'Taşıma' : 'Üretim' ?></p></div>
              <a class="badge-soft badge-warn" href="bugun.php?date=<?= $month ?>-01">Gir</a>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>