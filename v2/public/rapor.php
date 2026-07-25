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
        $t = $repo->tasimaProfit($drillId, $month);
        $adet = (float) $t['adet'];
        $brut = (float) $t['brut'];
        $net = (float) $t['net'];
        $trend = $repo->customerMonthlyProfit($drillId);
        $nk = $repo->customerNetKarlilik($drillId, $month);
        ?>
        <a class="btn-action btn-ghost" href="rapor.php?ay=<?= $month ?>"><i class="bi bi-arrow-left"></i> Rapora dön</a>
        <div class="summary-grid">
          <div class="summary-card tint-orange"><p class="label">Adet (bu ay, Bugün sayımı)</p><p class="metric"><?= number_format($adet, 0, ',', '.') ?></p></div>
          <div class="summary-card tint-blue"><p class="label">Birim alış / satış</p><p class="metric small">₺ <?= Helpers::money((float) $t['alis']) ?> / ₺ <?= Helpers::money((float) $t['satis']) ?></p></div>
          <div class="summary-card tint-blue"><p class="label">Toplam alış</p><p class="metric small">₺ <?= Helpers::money((float) $t['toplam_alis']) ?></p></div>
          <div class="summary-card tint-blue"><p class="label">Toplam satış</p><p class="metric small">₺ <?= Helpers::money((float) $t['toplam_satis']) ?></p></div>
          <div class="summary-card tint-orange"><p class="label">Brüt kâr · sabit gider</p><p class="metric small">₺ <?= Helpers::money($brut) ?> · ₺ <?= Helpers::money((float) $t['sabit']) ?></p></div>
          <div class="summary-card tint-orange"><p class="label">Pay: gider · personel</p><p class="metric small">₺ <?= Helpers::money($nk['pay_gider']) ?> · ₺ <?= Helpers::money($nk['pay_personel']) ?></p></div>
          <div class="summary-card wide tint-green"><p class="label">Net kâr (satış − alış − sabit − gider payı − personel payı)</p><p class="metric <?= $nk['net'] < 0 ? 'neg' : 'pos' ?>">₺ <?= Helpers::money($nk['net']) ?></p></div>
        </div>
        <?php if ($adet <= 0): ?>
          <div class="empty-state">Bu ay <strong>Bugün</strong> ekranında bu müşteriye sayım girilmemiş (adet 0). <a href="bugun.php" style="color:var(--primary);font-weight:700">Bugün'e git →</a></div>
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
        // ÜRETİM: gün gün öğün kırılımı — fable-020: haftalık gruplu + eksik gün görünür
        $grid = $repo->customerWeeklyGrid($drillId, $month);
        $sumKisi = $grid['kisi']; $sumTutar = $grid['tutar'];
        $nk = $repo->customerNetKarlilik($drillId, $month);
        // fable-020: bar grafik + boş durum için kayıtlı günleri düzleştir (kaynak = weekly grid)
        $recordedDays = []; $barMax = 0;
        foreach ($grid['weeks'] as $wk) {
            foreach ($wk['days'] as $dd) {
                if ($dd['recorded']) { $recordedDays[] = $dd; $barMax = max($barMax, (int) $dd['kisi']); }
            }
        }
        // fable-020: hafta ayracı için kısa Türkçe ay (14–19 Tem)
        $ayKisa = [1 => 'Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
        $missingCount = (int) $grid['missing'];
        ?>
        <a class="btn-action btn-ghost" href="rapor.php?ay=<?= $month ?>"><i class="bi bi-arrow-left"></i> Rapora dön</a>
        <div class="summary-grid">
          <div class="summary-card tint-orange"><p class="label">Ay toplam kişi</p><p class="metric"><?= number_format($sumKisi, 0, ',', '.') ?></p></div>
          <div class="summary-card tint-green"><p class="label">Ay cirosu</p><p class="metric">₺ <?= Helpers::money($sumTutar) ?></p></div>
          <div class="summary-card tint-blue"><p class="label">Pay: gider · personel</p><p class="metric small">₺ <?= Helpers::money($nk['pay_gider']) ?> · ₺ <?= Helpers::money($nk['pay_personel']) ?></p></div>
          <?php if ($missingCount > 0): ?>
            <a class="summary-card tint-red" href="#ilk-eksik" style="text-decoration:none"><p class="label">Bu ay eksik gün <span class="text-muted" style="font-weight:600">(bugüne kadar)</span></p><p class="metric neg"><?= $missingCount ?> <i class="bi bi-chevron-right" style="font-size:12px"></i></p></a>
          <?php else: ?>
            <div class="summary-card tint-green"><p class="label">Eksik gün</p><p class="metric pos" style="font-size:19px">Eksik gün yok</p></div>
          <?php endif; ?>
          <div class="summary-card wide tint-green"><p class="label">Net kâr (ciro − gider payı − personel payı)</p><p class="metric <?= $nk['net'] < 0 ? 'neg' : 'pos' ?>">₺ <?= Helpers::money($nk['net']) ?></p></div>
        </div>
        <div class="cardx card-pad">
          <h2>Gün gün öğün sayıları <span class="text-muted" style="font-size:12px;font-weight:600">(haftalık)</span></h2>
          <?php if (!$recordedDays): ?>
            <div class="empty-state">Bu ay üretim kaydı yok.</div>
          <?php else: ?>
            <div style="overflow-x:auto">
            <table class="mini-cal">
              <thead><tr><th>Gün</th><th>Öğle</th><th>Akşam</th><th>Kumanya</th><th>Kişi</th><th>Tutar</th></tr></thead>
              <tbody>
              <?php $anchored = false; foreach ($grid['weeks'] as $wk):
                $wkNo = (int) substr($wk['iso'], -2);
                $sd = (int) date('j', strtotime($wk['start']));
                $ed = (int) date('j', strtotime($wk['end']));
                $ayIdx = (int) date('n', strtotime($wk['end']));
                $rangeLbl = $sd . '–' . $ed . ' ' . ($ayKisa[$ayIdx] ?? ''); ?>
                <tr class="is-week"><td colspan="6">Hafta <?= $wkNo ?> · <?= Helpers::e($rangeLbl) ?><?php if ($wk['missing'] > 0): ?> · <span class="wk-eksik"><?= (int) $wk['missing'] ?> gün eksik</span><?php endif; ?></td></tr>
                <?php foreach ($wk['days'] as $r):
                  $isMissing = $r['missing'];
                  $rowId = '';
                  if ($isMissing && !$anchored) { $rowId = ' id="ilk-eksik"'; $anchored = true; }
                  // fable-020: kayıtsız günde nötr '·'; eksikte de kolonlar '—' (belirgin)
                  $ph = $isMissing ? '—' : '·'; ?>
                  <tr class="<?= $isMissing ? 'is-missing' : '' ?>"<?= $rowId ?>>
                    <?php // fable-020: gün kısaltması Türkçe (date('D') İngilizce basıyordu — UI TR kuralı)
                    $gunTrKisa = ['Mon' => 'Pzt', 'Tue' => 'Sal', 'Wed' => 'Çar', 'Thu' => 'Per', 'Fri' => 'Cum', 'Sat' => 'Cmt', 'Sun' => 'Paz']; ?>
                    <td><?= Helpers::e(date('d.m', strtotime($r['gun'])) . ' ' . ($gunTrKisa[date('D', strtotime($r['gun']))] ?? '')) ?><?php if ($isMissing): ?> <a class="eksik-badge" href="bugun.php?date=<?= Helpers::e($r['gun']) ?>&focus=<?= (int) $drillId ?>">Eksik · Gir <i class="bi bi-pencil-square" style="font-size:10px"></i></a><?php endif; ?></td>
                    <td><?= $r['recorded'] && $r['ogle'] ? number_format((int) $r['ogle'], 0, ',', '.') : $ph ?></td>
                    <td><?= $r['recorded'] && $r['aksam'] ? number_format((int) $r['aksam'], 0, ',', '.') : $ph ?></td>
                    <td><?= $r['recorded'] && $r['kumanya'] ? number_format((int) $r['kumanya'], 0, ',', '.') : $ph ?></td>
                    <td><?= $r['recorded'] ? '<strong>' . number_format((int) $r['kisi'], 0, ',', '.') . '</strong>' : $ph ?></td>
                    <td><?= $r['recorded'] ? '₺ ' . Helpers::money((float) $r['tutar']) : $ph ?></td>
                  </tr>
                <?php endforeach; ?>
                <tr class="is-subtotal"><td>Hafta <?= $wkNo ?> ara toplam</td><td></td><td></td><td></td><td><?= number_format((int) $wk['kisi'], 0, ',', '.') ?></td><td>₺ <?= Helpers::money((float) $wk['tutar']) ?></td></tr>
              <?php endforeach; ?>
                <tr class="is-total"><td>Ay toplam</td><td></td><td></td><td></td><td><?= number_format($sumKisi, 0, ',', '.') ?></td><td>₺ <?= Helpers::money($sumTutar) ?></td></tr>
              </tbody>
            </table>
            </div>
          <?php endif; ?>
        </div>
        <?php if ($recordedDays): ?>
        <div class="cardx card-pad">
          <h2>Günlük kişi trendi</h2>
          <div class="barchart">
            <?php foreach ($recordedDays as $r): $w = $barMax > 0 ? max(4, round((int) $r['kisi'] / $barMax * 100)) : 4; ?>
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
$rows = $repo->monthProductionByCustomer($month, 'uretim'); // taşıma HARİÇ (kategori ayrımı)
$fin = $repo->monthFinanceTotals($month);
$tasimaTot = $repo->monthTasimaTotals($month);
$nk = $repo->netKarlilik($month);
$kidemTot = $repo->kidemToplamYukumluluk($month); // fable-013: kıdem notu Finans'tan buraya taşındı
$tasimaList = $repo->listCustomersByCategory('tasima');
$toplamCiro = 0.0; $toplamKisi = 0;
foreach ($rows as $r) {
    $toplamCiro += (float) $r['ciro'];
    $toplamKisi += (int) $r['persons'];
}

require __DIR__ . '/partials/header.php';
?>
      <div class="quick-tiles">
        <a class="q-tile" href="kar-analizi.php"><i class="bi bi-pie-chart"></i> Kâr analizi (üretim/taşıma marj)</a>
      </div>

      <form method="get" class="date-row">
        <div class="date-pill"><i class="bi bi-calendar2-week"></i>
          <input type="month" name="ay" value="<?= Helpers::e($month) ?>" onchange="this.form.submit()">
        </div>
        <?php $mtdSpan = ay_span_tr($month); ?>
        <?php if ($mtdSpan): ?><span class="text-muted" style="font-size:12px;font-weight:600;align-self:center">üretim/gelir <?= Helpers::e($mtdSpan) ?> (ay içi)</span><?php endif; ?>
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
            <tr><td>Personel gideri (dağıtılmış · yüklü işveren maliyeti)</td><td class="num">− ₺ <?= Helpers::money($nk['personel']) ?></td></tr>
            <tr><td>Taşıma net kâr (adet×(satış−alış)−sabit)</td><td class="num" style="color:<?= $nk['tasima_kar'] < 0 ? 'var(--red)' : 'var(--green)' ?>"><?= $nk['tasima_kar'] < 0 ? '− ₺ ' . Helpers::money(abs($nk['tasima_kar'])) : '+ ₺ ' . Helpers::money($nk['tasima_kar']) ?></td></tr>
            <tr class="is-total"><td>Net karlılık</td><td class="num" style="color:<?= $nk['net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($nk['net']) ?></td></tr>
          </tbody>
        </table>
        <?php if ($kidemTot['birikim'] > 0): ?>
        <p class="row-meta" style="margin-top:8px"><i class="bi bi-piggy-bank"></i> Biriken kıdem yükümlülüğü (bilanço dışı borç, fesihte ödenir): <strong>₺ <?= Helpers::money($kidemTot['birikim']) ?></strong> · bu ay tahakkuk +₺ <?= Helpers::money($kidemTot['bu_ay_tahakkuk']) ?></p>
        <?php endif; ?>
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
          <?php foreach ($tasimaList as $c): $t = $repo->tasimaProfit((int) $c['id'], $month); if ((float) $t['adet'] <= 0) continue; $k = (float) $t['net']; ?>
            <tr>
              <td><a href="rapor.php?musteri=<?= (int) $c['id'] ?>&ay=<?= $month ?>" style="color:var(--primary);font-weight:750"><?= Helpers::e($c['name']) ?></a></td>
              <td class="num"><?= number_format((float) $t['adet'], 0, ',', '.') ?></td>
              <td class="num">₺ <?= Helpers::money((float) $t['toplam_alis']) ?></td>
              <td class="num">₺ <?= Helpers::money((float) $t['toplam_satis']) ?></td>
              <td class="num">₺ <?= Helpers::money((float) $t['sabit']) ?></td>
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
