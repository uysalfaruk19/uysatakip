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

$ka = $repo->karAnalizi($month);
$nk = $repo->netKarlilik($month);

/** Marjı yüzde göster. */
function marj_pct(float $m): string
{
    return number_format($m * 100, 1, ',', '.') . '%';
}

$eyebrow = ay_label_tr($month);
$pageTitle = 'Kâr Analizi';
$active = 'rapor';
require __DIR__ . '/partials/header.php';
?>
      <form method="get" class="date-row">
        <div class="date-pill"><i class="bi bi-calendar2-week"></i>
          <input type="month" name="ay" value="<?= Helpers::e($month) ?>" onchange="this.form.submit()">
        </div>
      </form>

      <div class="summary-grid">
        <div class="summary-card tint-green"><p class="label">Toplam gelir</p><p class="metric">₺ <?= Helpers::money($ka['toplam_gelir']) ?></p></div>
        <div class="summary-card <?= $ka['toplam_net'] < 0 ? 'tint-orange' : 'tint-green' ?>"><p class="label">Toplam net kâr</p><p class="metric <?= $ka['toplam_net'] < 0 ? 'neg' : '' ?>">₺ <?= Helpers::money($ka['toplam_net']) ?></p></div>
        <div class="summary-card wide"><p class="label">Toplam marj</p><p class="metric small"><?= marj_pct($ka['toplam_marj']) ?></p></div>
      </div>

      <!-- ÜRETİM P&L -->
      <div class="section-head"><h2>Üretim</h2><span class="text-muted" style="font-size:12px">gelir − gider − personel = net</span></div>
      <div class="cardx card-pad">
        <?php if (!$ka['uretim']['rows']): ?>
          <div class="empty-state">
            <div class="es-ico"><i class="bi bi-graph-up-arrow"></i></div>
            Bu ay üretim kaydı yok.</div>
        <?php else: ?>
          <div style="overflow-x:auto">
          <table class="tablex">
            <thead><tr><th>Müşteri</th><th class="num">Gelir</th><th class="num">Gider payı</th><th class="num">Personel payı</th><th class="num">Net</th><th class="num">Marj</th></tr></thead>
            <tbody>
            <?php foreach ($ka['uretim']['rows'] as $r): ?>
              <tr>
                <td><a href="rapor.php?musteri=<?= (int) $r['customer_id'] ?>&ay=<?= $month ?>" style="color:var(--primary);font-weight:700"><?= Helpers::e($r['name']) ?></a></td>
                <td class="num">₺ <?= Helpers::money($r['gelir']) ?></td>
                <td class="num">− ₺ <?= Helpers::money($r['gider']) ?></td>
                <td class="num">− ₺ <?= Helpers::money($r['personel']) ?></td>
                <td class="num" style="color:<?= $r['net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($r['net']) ?></td>
                <td class="num"><?= marj_pct($r['marj']) ?></td>
              </tr>
            <?php endforeach; ?>
              <tr class="is-total">
                <td>Üretim toplam</td>
                <td class="num">₺ <?= Helpers::money($ka['uretim']['gelir']) ?></td>
                <td class="num">− ₺ <?= Helpers::money($ka['uretim']['gider']) ?></td>
                <td class="num">− ₺ <?= Helpers::money($ka['uretim']['personel']) ?></td>
                <td class="num" style="color:<?= $ka['uretim']['net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($ka['uretim']['net']) ?></td>
                <td class="num"><?= marj_pct($ka['uretim']['marj']) ?></td>
              </tr>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- TAŞIMA P&L -->
      <?php if ($ka['tasima']['rows']): ?>
      <div class="section-head"><h2>Taşıma</h2><span class="text-muted" style="font-size:12px">satış − alış − sabit − gider − personel = net</span></div>
      <div class="cardx card-pad">
        <div style="overflow-x:auto">
        <table class="tablex">
          <thead><tr><th>Müşteri</th><th class="num">Satış</th><th class="num">Alış</th><th class="num">Sabit</th><th class="num">Gider</th><th class="num">Personel</th><th class="num">Net</th><th class="num">Marj</th></tr></thead>
          <tbody>
          <?php foreach ($ka['tasima']['rows'] as $r): ?>
            <tr>
              <td><a href="rapor.php?musteri=<?= (int) $r['customer_id'] ?>&ay=<?= $month ?>" style="color:var(--primary);font-weight:700"><?= Helpers::e($r['name']) ?></a></td>
              <td class="num">₺ <?= Helpers::money($r['satis']) ?></td>
              <td class="num">− ₺ <?= Helpers::money($r['alis']) ?></td>
              <td class="num">− ₺ <?= Helpers::money($r['sabit']) ?></td>
              <td class="num">− ₺ <?= Helpers::money($r['gider']) ?></td>
              <td class="num">− ₺ <?= Helpers::money($r['personel']) ?></td>
              <td class="num" style="color:<?= $r['net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($r['net']) ?></td>
              <td class="num"><?= marj_pct($r['marj']) ?></td>
            </tr>
          <?php endforeach; ?>
            <tr class="is-total">
              <td>Taşıma toplam</td>
              <td class="num">₺ <?= Helpers::money($ka['tasima']['satis']) ?></td>
              <td class="num">− ₺ <?= Helpers::money($ka['tasima']['alis']) ?></td>
              <td class="num">− ₺ <?= Helpers::money($ka['tasima']['sabit']) ?></td>
              <td class="num">− ₺ <?= Helpers::money($ka['tasima']['gider']) ?></td>
              <td class="num">− ₺ <?= Helpers::money($ka['tasima']['personel']) ?></td>
              <td class="num" style="color:<?= $ka['tasima']['net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($ka['tasima']['net']) ?></td>
              <td class="num"><?= marj_pct($ka['tasima']['marj']) ?></td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>
      <?php endif; ?>

      <!-- TOPLAM -->
      <div class="cardx card-pad">
        <h2>Toplam net kâr</h2>
        <table class="tablex">
          <tbody>
            <tr><td>Üretim net</td><td class="num" style="color:<?= $ka['uretim']['net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($ka['uretim']['net']) ?></td></tr>
            <tr><td>Taşıma net</td><td class="num" style="color:<?= $ka['tasima']['net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($ka['tasima']['net']) ?></td></tr>
            <?php if ($ka['dagitilmamis'] > 0): ?>
            <tr><td>Dağıtılmamış (atanmamış personel / genel gider)</td><td class="num">− ₺ <?= Helpers::money($ka['dagitilmamis']) ?></td></tr>
            <?php endif; ?>
            <tr class="is-total"><td>Toplam net kâr</td><td class="num" style="color:<?= $ka['toplam_net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($ka['toplam_net']) ?> · <?= marj_pct($ka['toplam_marj']) ?></td></tr>
          </tbody>
        </table>
        <p class="row-meta" style="margin-top:8px"><i class="bi bi-check2-circle"></i> Finans net karlılık ile birebir: ₺ <?= Helpers::money($nk['net']) ?></p>
      </div>
<?php require __DIR__ . '/partials/footer.php'; ?>
