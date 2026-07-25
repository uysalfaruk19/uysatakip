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
<?php
      // fable-034: GTO dili — AYIN NABZI + MÜŞTERİ KARNESİ (mockup birebir)
      $gider = $ka['toplam_gelir'] - $ka['toplam_net'];
      $scalePct = $ka['toplam_gelir'] > 0 ? (int) round($ka['toplam_net'] / $ka['toplam_gelir'] * 100) : 0;
      if ($scalePct < 4 && $ka['toplam_net'] > 0) { $scalePct = 4; }
      if ($scalePct < 0) { $scalePct = 0; }
      // Karne: üretim + taşıma satırlarını tek listede birleştir, net'e göre sırala
      $karne = [];
      foreach ($ka['uretim']['rows'] as $r) {
          $karne[] = ['id' => $r['customer_id'], 'name' => $r['name'], 'gelir' => (float) $r['gelir'], 'net' => (float) $r['net'], 'marj' => (float) $r['marj'], 'tasima' => false];
      }
      foreach ($ka['tasima']['rows'] as $r) {
          $karne[] = ['id' => $r['customer_id'], 'name' => $r['name'], 'gelir' => (float) $r['satis'], 'net' => (float) $r['net'], 'marj' => (float) $r['marj'], 'tasima' => true];
      }
      usort($karne, static fn($a, $b) => $b['net'] <=> $a['net']);
      $netMax = 0.0;
      foreach ($karne as $k) { $netMax = max($netMax, abs($k['net'])); }
      ?>
      <div class="cardx card-pad">
        <form method="get" class="gt-date">
          <div class="dt" style="position:relative">
            <b><?= Helpers::e(ay_label_tr($month)) ?></b>
            <span>üretim + taşıma kâr/zarar</span>
            <input type="month" name="ay" value="<?= Helpers::e($month) ?>" onchange="this.form.submit()"
                   aria-label="Ay seç" style="position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer">
          </div>
        </form>
      </div>

      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-broadcast"></i> AYIN NABZI</div>
        <div class="gt-pulse">
          <div class="gt-pulse-n <?= $ka['toplam_net'] < 0 ? 'bad' : 'ok' ?>">₺<?= Helpers::money($ka['toplam_net']) ?></div>
          <div class="gt-pulse-l">net kâr · marj <?= marj_pct($ka['toplam_marj']) ?></div>
        </div>
        <div class="gt-scale">
          <div class="row"><span class="gl">Gelir ₺<?= Helpers::money($ka['toplam_gelir']) ?></span><span class="gd">Gider ₺<?= Helpers::money($gider) ?></span></div>
          <div class="gt-track deficit"><div class="gt-fill left" style="width: <?= $scalePct ?>%"></div></div>
        </div>
        <div class="gt-mini">
          <div><div class="gt-mn <?= $ka['uretim']['net'] < 0 ? 'bad' : 'ok' ?>">₺<?= number_format(round($ka['uretim']['net']), 0, ',', '.') ?></div><div class="gt-ml">Üretim kârı</div></div>
          <div><div class="gt-mn <?= $ka['tasima']['net'] < 0 ? 'bad' : 'ok' ?>">₺<?= number_format(round($ka['tasima']['net']), 0, ',', '.') ?></div><div class="gt-ml">Taşıma kârı</div></div>
          <div><div class="gt-mn"><?= marj_pct($ka['toplam_marj']) ?></div><div class="gt-ml">Toplam marj</div></div>
        </div>
      </div>

      <?php if ($karne): ?>
      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-clipboard-data"></i> MÜŞTERİ KARNESİ</div>
        <?php foreach ($karne as $k): $w = $netMax > 0 ? max(4, (int) round(abs($k['net']) / $netMax * 100)) : 4; $bad = $k['net'] < 0; ?>
          <a class="gt-kr<?= $bad ? ' warn' : '' ?>" href="rapor.php?musteri=<?= (int) $k['id'] ?>&ay=<?= $month ?>" style="display:block">
            <div class="gt-kr-head">
              <div class="gt-rank"><?= Helpers::e(mb_strtoupper(mb_substr($k['name'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
              <div class="gt-kr-firm">
                <div class="gt-kr-ad"><?= Helpers::e($k['name']) ?></div>
                <div class="gt-kr-sub">gelir ₺<?= Helpers::money($k['gelir']) ?><?= $k['tasima'] ? ' · taşıma' : '' ?></div>
              </div>
              <div class="gt-kr-val <?= $bad ? 'bad' : 'ok' ?>"><?= $bad ? '−' : '' ?>₺<?= Helpers::money(abs($k['net'])) ?><small>marj <?= marj_pct($k['marj']) ?></small></div>
            </div>
            <div class="gt-bar"><i class="<?= $bad ? 'bad' : '' ?>" style="width: <?= $w ?>%"></i></div>
          </a>
        <?php endforeach; ?>
        <div class="gt-note">satıra dokun → o müşterinin aylık dökümü açılır</div>
      </div>
      <?php endif; ?>

      <!-- ÜRETİM P&L -->
      <div class="section-head"><h2>Üretim</h2><span class="text-muted" style="font-size:12px">gelir − gider − personel = net</span></div>
      <div class="cardx card-pad">
        <?php if (!$ka['uretim']['rows']): ?>
          <div class="empty-state">Bu ay üretim kaydı yok.</div>
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
