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
        $brut = $t ? (float) $t['brut'] : 0.0;
        $net = $t ? (float) $t['net'] : 0.0;
        $trend = $repo->customerMonthlyProfit($drillId);
        ?>
        <a class="btn-action btn-ghost" href="rapor.php?ay=<?= $month ?>"><i class="bi bi-arrow-left"></i> Rapora dön</a>
        <div class="summary-grid">
          <div class="summary-card tint-orange"><p class="label">Adet</p><p class="metric"><?= number_format($t ? (float) $t['adet'] : 0, 0, ',', '.') ?></p></div>
          <div class="summary-card tint-blue"><p class="label">Birim alış / satış</p><p class="metric small">₺ <?= Helpers::money($t ? (float) $t['birim_alis'] : 0) ?> / ₺ <?= Helpers::money($t ? (float) $t['birim_satis'] : 0) ?></p></div>
          <div class="summary-card tint-blue"><p class="label">Toplam alış</p><p class="metric small">₺ <?= Helpers::money($t ? (float) $t['toplam_alis'] : 0) ?></p></div>
          <div class="summary-card tint-blue"><p class="label">Toplam satış</p><p class="metric small">₺ <?= Helpers::money($t ? (float) $t['toplam_satis'] : 0) ?></p></div>
          <div class="summary-card tint-orange"><p class="label">Brüt kâr · sabit gider</p><p class="metric small">₺ <?= Helpers::money($brut) ?> · ₺ <?= Helpers::money($t ? (float) $t['sabit_gider'] : 0) ?></p></div>
          <div class="summary-card wide tint-green"><p class="label">Net kâr (brüt − sabit)</p><p class="metric <?= $net < 0 ? 'neg' : 'pos' ?>">₺ <?= Helpers::money($net) ?></p></div>
        </div>
        <?php if (!$t): ?>
          <div class="empty-state">Bu ay için kâr girişi yok. <a href="musteriler.php?edit=<?= $drillId ?>&ay=<?= $month ?>" style="color:var(--primary);font-weight:700">Müşteriye ekle →</a></div>
        <?php endif; ?>
        <div class="cardx card-pad">
          <h2>Aylar trendi</h2>
          <?php if (!$trend): ?>
            <div class="empty-state">Henüz kâr geçmişi yok.</div>
          <?php else: ?>
            <div style="overflow-x:auto">
            <table class="tablex">
              <thead><tr><th>Ay</th><th class="num">Adet</th><th class="num">Alış</th><th class="num">Satış</th><th class="num">Brüt</th><th class="num">Net</th></tr></thead>
              <tbody>
              <?php foreach ($trend as $r): $k = (float) $r['net']; ?>
                <tr>
                  <td><?= Helpers::e(ay_label_tr($r['ay'])) ?></td>
                  <td class="num"><?= number_format((float) $r['adet'], 0, ',', '.') ?></td>
                  <td class="num">₺ <?= Helpers::money((float) $r['toplam_alis']) ?></td>
                  <td class="num">₺ <?= Helpers::money((float) $r['toplam_satis']) ?></td>
                  <td class="num">₺ <?= Helpers::money((float) $r['brut']) ?></td>
                  <td class="num" style="color:<?= $k < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($k) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            </div>
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
$nk = $repo->netKarlilik($month);
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

      <div class="cardx card-pad">
        <h2>Net karlılık <span class="text-muted" style="font-size:12px;font-weight:600">(üretim − maliyet, ayrı kalemler)</span></h2>
        <table class="tablex">
          <tbody>
            <tr><td>Üretim cirosu</td><td class="num">₺ <?= Helpers::money($nk['ciro']) ?></td></tr>
            <tr><td>Hammadde / işletme gideri</td><td class="num">− ₺ <?= Helpers::money($nk['hammadde']) ?></td></tr>
            <tr><td>Personel gideri</td><td class="num">− ₺ <?= Helpers::money($nk['personel']) ?></td></tr>
            <tr><td>Taşıma net kâr (adet×(satış−alış)−sabit)</td><td class="num" style="color:<?= $nk['tasima_kar'] < 0 ? 'var(--red)' : 'var(--green)' ?>"><?= $nk['tasima_kar'] < 0 ? '− ₺ ' . Helpers::money(abs($nk['tasima_kar'])) : '+ ₺ ' . Helpers::money($nk['tasima_kar']) ?></td></tr>
            <tr class="is-total"><td>Net karlılık</td><td class="num" style="color:<?= $nk['net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($nk['net']) ?></td></tr>
          </tbody>
        </table>
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
        <div style="overflow-x:auto">
        <table class="tablex">
          <thead><tr><th>Müşteri</th><th class="num">Adet</th><th class="num">Alış</th><th class="num">Satış</th><th class="num">Sabit</th><th class="num">Net kâr</th></tr></thead>
          <tbody>
          <?php foreach ($tasimaList as $c): $t = $repo->tasimaAylik((int) $c['id'], $month); if (!$t) continue; $k = (float) $t['net']; ?>
            <tr>
              <td><a href="rapor.php?musteri=<?= (int) $c['id'] ?>&ay=<?= $month ?>" style="color:var(--primary);font-weight:750"><?= Helpers::e($c['name']) ?></a></td>
              <td class="num"><?= number_format((float) $t['adet'], 0, ',', '.') ?></td>
              <td class="num">₺ <?= Helpers::money((float) $t['toplam_alis']) ?></td>
              <td class="num">₺ <?= Helpers::money((float) $t['toplam_satis']) ?></td>
              <td class="num">₺ <?= Helpers::money((float) $t['sabit_gider']) ?></td>
              <td class="num" style="color:<?= $k < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($k) ?></td>
            </tr>
          <?php endforeach; ?>
            <tr class="is-total"><td>Taşıma toplam</td><td class="num"></td><td class="num">₺ <?= Helpers::money($tasimaTot['alis']) ?></td><td class="num">₺ <?= Helpers::money($tasimaTot['satis']) ?></td><td class="num">₺ <?= Helpers::money($tasimaTot['gider']) ?></td><td class="num">₺ <?= Helpers::money($tasimaTot['net']) ?></td></tr>
          </tbody>
        </table>
        </div>
      </div>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
