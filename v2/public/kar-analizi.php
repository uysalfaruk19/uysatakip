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

$tab = (string) ($_GET['tab'] ?? 'uretim');
if (!in_array($tab, ['uretim', 'tasima'], true)) {
    $tab = 'uretim';
}

$ka = $repo->karAnalizi($month);
$nk = $repo->netKarlilik($month);
$gida = $repo->gidaCostOzet($month); // fable-039: kişi başı gıda maliyeti (görünüm katmanı)

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
      // fable-039: karne artık sekmeye göre AYRIK (üretim | taşıma).
      $karne = [];
      if ($tab === 'uretim') {
          foreach ($ka['uretim']['rows'] as $r) {
              $karne[] = ['id' => $r['customer_id'], 'name' => $r['name'], 'gelir' => (float) $r['gelir'], 'net' => (float) $r['net'], 'marj' => (float) $r['marj'], 'tasima' => false];
          }
      } else {
          foreach ($ka['tasima']['rows'] as $r) {
              $karne[] = ['id' => $r['customer_id'], 'name' => $r['name'], 'gelir' => (float) $r['satis'], 'net' => (float) $r['net'], 'marj' => (float) $r['marj'], 'tasima' => true];
          }
      }
      usort($karne, static fn($a, $b) => $b['net'] <=> $a['net']);
      $netMax = 0.0;
      foreach ($karne as $k) { $netMax = max($netMax, abs($k['net'])); }
      $karneCiroToplam = 0.0;
      foreach ($karne as $k) { $karneCiroToplam += $k['gelir']; }
      ?>
      <div class="cardx card-pad">
        <form method="get" class="gt-date">
          <input type="hidden" name="tab" value="<?= Helpers::e($tab) ?>">
          <div class="dt" style="position:relative">
            <b><?= Helpers::e(ay_label_tr($month)) ?></b>
            <span><?= $tab === 'tasima' ? 'taşıma kâr/zarar' : 'üretim kâr/zarar + gıda maliyeti' ?></span>
            <input type="month" name="ay" value="<?= Helpers::e($month) ?>" onchange="this.form.submit()"
                   aria-label="Ay seç" style="position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer">
          </div>
        </form>
      </div>

      <!-- fable-039: Kâr/Zarar → Üretim | Taşıma ayrımı -->
      <div class="segmented" style="margin-bottom:12px">
        <a class="chip <?= $tab === 'uretim' ? 'active' : '' ?>" href="kar-analizi.php?ay=<?= $month ?>&tab=uretim">Üretim</a>
        <a class="chip <?= $tab === 'tasima' ? 'active' : '' ?>" href="kar-analizi.php?ay=<?= $month ?>&tab=tasima">Taşıma</a>
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

      <?php if ($tab === 'uretim'): ?>
      <?php // fable-039: KİŞİ BAŞI GIDA MALİYETİ — büyük rakam + tıklayınca kırılım ?>
      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-basket3-fill"></i> KİŞİ BAŞI GIDA MALİYETİ</div>
        <div class="gt-pulse tap-card" role="button" tabindex="0" onclick="toggleGida()" style="cursor:<?= $gida['kirilimlar'] ? 'pointer' : 'default' ?>">
          <div class="gt-pulse-n"><?= $gida['kisi_basi'] > 0 ? '₺' . Helpers::money($gida['kisi_basi']) : '—' ?></div>
          <div class="gt-pulse-l">1 kişilik gıda maliyeti · gıda alımları ₺<?= Helpers::money($gida['toplam']) ?> · üretim <?= number_format($gida['kisi_toplam'], 0, ',', '.') ?> kişi<?= $gida['kirilimlar'] ? ' <i class="bi bi-chevron-down chev"></i>' : '' ?></div>
        </div>
        <?php if ($gida['kirilimlar']): ?>
        <div id="gida-kirilim" style="display:none;margin-top:6px">
          <?php foreach ($gida['kirilimlar'] as $g): $w = max(4, (int) round($g['oran'] * 100)); ?>
            <div class="gt-kr">
              <div class="gt-kr-head">
                <div class="gt-kr-firm">
                  <div class="gt-kr-ad"><?= Helpers::e($g['ad']) ?></div>
                  <div class="gt-kr-sub">kişi başı ₺<?= Helpers::money($g['kisi_basi']) ?></div>
                </div>
                <div class="gt-kr-val bad">₺<?= Helpers::money($g['tutar']) ?><small><?= number_format($g['oran'] * 100, 1, ',', '.') ?>%</small></div>
              </div>
              <div class="gt-bar"><i class="bad" style="width: <?= $w ?>%"></i></div>
            </div>
          <?php endforeach; ?>
          <div class="gt-note">gıda kırılımları · tedarikçi eşlemesi <a href="tedarikci-eslestirme.php" style="text-decoration:underline">Maliyet eşleştirme</a>'den değişir</div>
        </div>
        <?php else: ?>
        <div class="gt-note">Gıda kırılımı için tedarikçi eşlemesi yok. <a href="tedarikci-eslestirme.php" style="text-decoration:underline">Maliyet eşleştirme</a>'den ayarla.</div>
        <?php endif; ?>
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
        <?php // fable-039: karnenin EN ALTINA toplam ciro satırı (taşıma bölümündeki toplam kalıbı) ?>
        <div class="gt-kr" style="border-top:2px solid var(--line-2)">
          <div class="gt-kr-head">
            <div class="gt-kr-firm"><div class="gt-kr-ad">Toplam ciro</div></div>
            <div class="gt-kr-val">₺<?= Helpers::money($karneCiroToplam) ?></div>
          </div>
        </div>
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
                <td><a href="rapor.php?musteri=<?= (int) $r['customer_id'] ?>&ay=<?= $month ?>" style="color:var(--primary);font-weight:700"><?= Helpers::e($r['name']) ?></a><?php
                  // fable-040: fatura kişi kuralı olan müşteride "üretim · fatura" rozeti (gelir fatura kişisinden)
                  if (($r['fatura_kisi'] ?? null) !== null): ?><span class="text-muted" style="display:block;font-size:11px;font-weight:600"><?= $r['uretim_gunluk'] !== null ? (int) $r['uretim_gunluk'] : '—' ?> üretim · <?= (int) $r['fatura_kisi'] ?> fatura <span style="opacity:.7">(hafta içi/gün)</span></span><?php endif; ?></td>
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
      <?php endif; // tab uretim ?>

      <!-- TAŞIMA P&L -->
      <?php if ($tab === 'tasima'): ?>
      <?php if (!$ka['tasima']['rows']): ?>
        <div class="cardx card-pad"><div class="empty-state">Bu ay taşıma kaydı yok.</div></div>
      <?php else: ?>
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
      <?php endif; // tasima rows ?>
      <?php endif; // tab tasima ?>

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
      <script>
        // fable-039: gıda maliyeti kartı → kırılım aç/kapat
        function toggleGida(){
          var el = document.getElementById('gida-kirilim');
          if (!el) return;
          el.style.display = el.style.display === 'none' ? '' : 'none';
          var card = document.querySelector('[onclick="toggleGida()"]');
          if (card) card.classList.toggle('open', el.style.display !== 'none');
        }
      </script>
<?php require __DIR__ . '/partials/footer.php'; ?>
