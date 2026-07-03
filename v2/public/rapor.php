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
$drillId = (int) ($_GET['musteri'] ?? 0) ?: null;
$drill = $drillId ? $repo->customer($drillId) : null;

$pageTitle = $drill ? $drill['name'] : 'Kâr / Zarar';
$eyebrow = $drill ? ('Müşteri raporu · ' . ay_label_tr($month)) : ay_label_tr($month);
$active = 'rapor';

// ══════════════════════════════════════════════════════════════
// DRILL-DOWN: bir müşterinin ayı (üretim gün gün / taşıma kâr)
// ══════════════════════════════════════════════════════════════
if ($drill) {
    require __DIR__ . '/partials/header.php';

    if ($drill['category'] === 'tasima') {
        $t = $repo->tasimaAylik($drillId, $month);
        $kar = $t ? (float) $t['kar'] : 0.0;
        $trend = $repo->customerMonthlyProfit($drillId);
        ?>
        <a class="btn-action btn-ghost" href="rapor.php?ay=<?= $month ?>"><i class="bi bi-arrow-left"></i> Rapora dön</a>
        <div class="summary-grid">
          <div class="summary-card tint-blue"><p class="label">Satış / hakediş</p><p class="metric">₺ <?= Helpers::money($t ? (float) $t['satis_fiyati'] : 0) ?></p></div>
          <div class="summary-card tint-orange"><p class="label">Sabit gider</p><p class="metric">₺ <?= Helpers::money($t ? (float) $t['sabit_gider'] : 0) ?></p></div>
          <div class="summary-card wide tint-green"><p class="label">Aylık kâr (satış − gider)</p><p class="metric <?= $kar < 0 ? 'neg' : 'pos' ?>">₺ <?= Helpers::money($kar) ?></p></div>
        </div>
        <?php if (!$t): ?>
          <div class="empty-state">Bu ay için kâr girişi yok. <a href="musteriler.php?edit=<?= $drillId ?>&ay=<?= $month ?>" style="color:var(--primary);font-weight:700">Müşteriye ekle →</a></div>
        <?php endif; ?>
        <div class="cardx card-pad">
          <h2>Aylar trendi</h2>
          <?php if (!$trend): ?>
            <div class="empty-state">Henüz kâr geçmişi yok.</div>
          <?php else: ?>
            <table class="tablex">
              <thead><tr><th>Ay</th><th class="num">Satış</th><th class="num">Gider</th><th class="num">Kâr</th></tr></thead>
              <tbody>
              <?php foreach ($trend as $r): $k = (float) $r['kar']; ?>
                <tr>
                  <td><?= Helpers::e(ay_label_tr($r['ay'])) ?></td>
                  <td class="num">₺ <?= Helpers::money((float) $r['satis_fiyati']) ?></td>
                  <td class="num">₺ <?= Helpers::money((float) $r['sabit_gider']) ?></td>
                  <td class="num" style="color:<?= $k < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($k) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
        <?php
    } else {
        // ÜRETİM: gün gün öğün kırılımı
        $rows = $repo->customerDailyGrid($drillId, $month);
        $sumKisi = 0; $sumTutar = 0.0; $barMax = 0;
        foreach ($rows as $r) { $sumKisi += (int) $r['kisi']; $sumTutar += (float) $r['tutar']; $barMax = max($barMax, (int) $r['kisi']); }
        ?>
        <a class="btn-action btn-ghost" href="rapor.php?ay=<?= $month ?>"><i class="bi bi-arrow-left"></i> Rapora dön</a>
        <div class="summary-grid">
          <div class="summary-card tint-orange"><p class="label">Ay toplam kişi</p><p class="metric"><?= number_format($sumKisi, 0, ',', '.') ?></p></div>
          <div class="summary-card tint-green"><p class="label">Ay cirosu</p><p class="metric">₺ <?= Helpers::money($sumTutar) ?></p></div>
          <div class="summary-card wide"><p class="label">Üretim günü</p><p class="metric small"><?= count($rows) ?> gün</p></div>
        </div>
        <div class="cardx card-pad">
          <h2>Gün gün öğün sayıları</h2>
          <?php if (!$rows): ?>
            <div class="empty-state">Bu ay üretim kaydı yok.</div>
          <?php else: ?>
            <div style="overflow-x:auto">
            <table class="mini-cal">
              <thead><tr><th>Gün</th><th>Öğle</th><th>Akşam</th><th>Kumanya</th><th>Kişi</th><th>Tutar</th></tr></thead>
              <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?= Helpers::e(date('d.m D', strtotime($r['gun']))) ?></td>
                  <td><?= $r['ogle'] ? number_format((int) $r['ogle'], 0, ',', '.') : '·' ?></td>
                  <td><?= $r['aksam'] ? number_format((int) $r['aksam'], 0, ',', '.') : '·' ?></td>
                  <td><?= $r['kumanya'] ? number_format((int) $r['kumanya'], 0, ',', '.') : '·' ?></td>
                  <td><strong><?= number_format((int) $r['kisi'], 0, ',', '.') ?></strong></td>
                  <td>₺ <?= Helpers::money((float) $r['tutar']) ?></td>
                </tr>
              <?php endforeach; ?>
                <tr class="is-total"><td>Toplam</td><td></td><td></td><td></td><td><?= number_format($sumKisi, 0, ',', '.') ?></td><td>₺ <?= Helpers::money($sumTutar) ?></td></tr>
              </tbody>
            </table>
            </div>
          <?php endif; ?>
        </div>
        <?php if ($rows): ?>
        <div class="cardx card-pad">
          <h2>Günlük kişi trendi</h2>
          <div class="barchart">
            <?php foreach ($rows as $r): $w = $barMax > 0 ? max(4, round((int) $r['kisi'] / $barMax * 100)) : 4; ?>
              <div class="bar-row">
                <span class="bar-name"><?= Helpers::e(date('d.m', strtotime($r['gun']))) ?></span>
                <span class="bar-track"><span class="bar-fill" style="width: <?= $w ?>%"></span></span>
                <span class="bar-val"><?= number_format((int) $r['kisi'], 0, ',', '.') ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php
    }
    require __DIR__ . '/partials/footer.php';
    return;
}

// ══════════════════════════════════════════════════════════════
// LİSTE: ay × müşteri (üretim ciro + taşıma kâr), müşteri adı = link
// ══════════════════════════════════════════════════════════════
$rows = $repo->monthProductionByCustomer($month);
$fin = $repo->monthFinanceTotals($month);
$tasimaTot = $repo->monthTasimaTotals($month);
$tasimaList = $repo->listCustomersByCategory('tasima');
$toplamCiro = 0.0; $toplamKisi = 0;
foreach ($rows as $r) {
    $toplamCiro += (float) $r['ciro'];
    $toplamKisi += (int) $r['persons'];
}

require __DIR__ . '/partials/header.php';
?>
      <form method="get" class="date-row">
        <div class="date-pill"><i class="bi bi-calendar2-week"></i>
          <input type="month" name="ay" value="<?= Helpers::e($month) ?>" onchange="this.form.submit()">
        </div>
      </form>

      <div class="summary-grid">
        <div class="summary-card tint-orange"><p class="label">Toplam kişi</p><p class="metric"><?= number_format($toplamKisi, 0, ',', '.') ?></p></div>
        <div class="summary-card tint-green"><p class="label">Üretim cirosu</p><p class="metric">₺ <?= Helpers::money($toplamCiro) ?></p></div>
        <div class="summary-card wide"><p class="label">Finans net nakit</p><p class="metric <?= $fin['net'] < 0 ? 'neg' : '' ?>">₺ <?= Helpers::money($fin['net']) ?></p></div>
      </div>

      <div class="section-head"><h2>Üretim müşterileri</h2><span class="text-muted" style="font-size:12px">adına tıkla → gün gün</span></div>
      <div class="cardx card-pad">
        <?php if (!$rows): ?>
          <div class="empty-state">Bu ay üretim kaydı yok.</div>
        <?php else: ?>
          <table class="tablex">
            <thead><tr><th>Müşteri</th><th class="num">Gün</th><th class="num">Kişi</th><th class="num">Ciro</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><a href="rapor.php?musteri=<?= (int) $r['customer_id'] ?>&ay=<?= $month ?>" style="color:var(--primary);font-weight:750"><?= Helpers::e($r['name']) ?> <i class="bi bi-chevron-right" style="font-size:10px"></i></a></td>
                <td class="num"><?= (int) $r['gun'] ?></td>
                <td class="num"><?= number_format((int) $r['persons'], 0, ',', '.') ?></td>
                <td class="num">₺ <?= Helpers::money((float) $r['ciro']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <?php if ($tasimaList): ?>
      <div class="section-head"><h2>Taşıma kâr/zarar</h2><span class="text-muted" style="font-size:12px"><?= Helpers::e(ay_label_tr($month)) ?></span></div>
      <div class="cardx card-pad">
        <table class="tablex">
          <thead><tr><th>Müşteri</th><th class="num">Satış</th><th class="num">Gider</th><th class="num">Kâr</th></tr></thead>
          <tbody>
          <?php foreach ($tasimaList as $c): $t = $repo->tasimaAylik((int) $c['id'], $month); if (!$t) continue; $k = (float) $t['kar']; ?>
            <tr>
              <td><a href="rapor.php?musteri=<?= (int) $c['id'] ?>&ay=<?= $month ?>" style="color:var(--primary);font-weight:750"><?= Helpers::e($c['name']) ?></a></td>
              <td class="num">₺ <?= Helpers::money((float) $t['satis_fiyati']) ?></td>
              <td class="num">₺ <?= Helpers::money((float) $t['sabit_gider']) ?></td>
              <td class="num" style="color:<?= $k < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($k) ?></td>
            </tr>
          <?php endforeach; ?>
            <tr class="is-total"><td>Taşıma toplam kâr</td><td class="num">₺ <?= Helpers::money($tasimaTot['satis']) ?></td><td class="num">₺ <?= Helpers::money($tasimaTot['gider']) ?></td><td class="num">₺ <?= Helpers::money($tasimaTot['kar']) ?></td></tr>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
