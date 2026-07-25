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

      <?php // fable-047 (Ömer kompaktlık tercihi): 4 kart alt alta ekranı yiyordu → tek kart, 3'lü mini ?>
      <div class="cardx card-pad">
        <div class="gt-pulse">
          <div class="gt-pulse-n<?= $sum['toplam_net'] < 0 ? ' bad' : '' ?>" style="font-size:30px">₺<?= Helpers::money((float) $sum['toplam_net']) ?></div>
          <div class="gt-pulse-l">net kâr · <?= close_badge_label($kapanis['status']) ?> · <?= (int) $sum['warning_count'] ?> uyarı</div>
        </div>
        <div class="gt-mini">
          <div><div class="gt-mn">₺<?= Helpers::money((float) $sum['toplam_gelir']) ?></div><div class="gt-ml">Toplam gelir</div></div>
          <div><div class="gt-mn"><?= (int) $sum['production_days'] ?></div><div class="gt-ml">Üretim günü</div></div>
          <div><div class="gt-mn"><?= number_format((float) $sum['persons'], 0, ',', '.') ?></div><div class="gt-ml">Toplam kişi</div></div>
        </div>
      </div>

      <div class="section-head"><div class="gt-h" style="margin:0"><i class="bi bi-check2-square"></i> KONTROL LİSTESİ</div><span class="text-muted" style="font-size:12px"><?= Helpers::e(ay_label_tr($month)) ?></span></div>
      <?php // fable-047 (Ömer): analiz edilmiş maddeler — satıra dokun, detay AŞAĞI açılsın. ?>
      <div class="cardx card-pad">
        <?php foreach ($kapanis['checks'] as $check):
          $rows = $check['rows'] ?? [];
          $acilir = $rows !== []; ?>
          <?php if ($acilir): ?>
          <details class="gt-satir">
            <summary>
              <div class="gt-kr<?= $check['status'] === 'ok' ? '' : ' warn' ?>">
                <div class="gt-kr-head">
                  <div class="gt-rank"><i class="bi <?= $check['status'] === 'ok' ? 'bi-check2' : ($check['status'] === 'info' ? 'bi-info-circle' : 'bi-exclamation-triangle') ?>"></i></div>
                  <div class="gt-kr-firm">
                    <div class="gt-kr-ad"><?= Helpers::e($check['label']) ?></div>
                    <div class="gt-kr-sub"><?= Helpers::e($check['detail']) ?></div>
                  </div>
                  <div class="gt-kr-val"><?= count($rows) ?><small>detay</small></div>
                </div>
              </div>
            </summary>
            <div class="gt-satir-detay">
              <?php if (($check['rows_baslik'] ?? '') !== ''): ?>
                <p class="row-meta" style="font-weight:700;margin-bottom:6px"><?= Helpers::e($check['rows_baslik']) ?></p>
              <?php endif; ?>
              <?php foreach ($rows as $r): ?>
                <div class="gt-kr" style="padding:8px 0">
                  <div class="gt-kr-firm">
                    <div class="gt-kr-ad" style="font-size:13px"><?= Helpers::e((string) ($r['ad'] ?? '')) ?></div>
                    <?php if (($r['meta'] ?? '') !== ''): ?><div class="gt-kr-sub"><?= Helpers::e((string) $r['meta']) ?></div><?php endif; ?>
                  </div>
                  <?php if (($r['deger'] ?? '') !== ''): ?><div class="gt-kr-val"><?= Helpers::e((string) $r['deger']) ?></div><?php endif; ?>
                  <?php if (($r['link'] ?? '') !== ''): ?>
                    <a class="badge-soft" style="margin-left:8px" href="<?= Helpers::e((string) $r['link']) ?>"><?= Helpers::e((string) ($r['link_ad'] ?? 'Aç')) ?></a>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
              <?php if (($check['link'] ?? '') !== ''): ?>
                <a class="btn-action btn-secondaryx" style="margin-top:8px" href="<?= Helpers::e($check['link']) ?>"><i class="bi bi-box-arrow-up-right"></i> Sayfaya git</a>
              <?php endif; ?>
            </div>
          </details>
          <?php else: ?>
          <div class="gt-kr<?= $check['status'] === 'ok' ? '' : ' warn' ?>" style="padding:11px 2px">
            <div class="gt-kr-head">
              <div class="gt-rank"><i class="bi <?= $check['status'] === 'ok' ? 'bi-check2' : ($check['status'] === 'info' ? 'bi-info-circle' : 'bi-exclamation-triangle') ?>"></i></div>
              <div class="gt-kr-firm">
                <div class="gt-kr-ad"><?= Helpers::e($check['label']) ?></div>
                <div class="gt-kr-sub"><?= Helpers::e($check['detail']) ?></div>
              </div>
              <?php if (($check['link'] ?? '') !== ''): ?>
                <a class="badge-soft <?= close_badge_class($check['status']) ?>" href="<?= Helpers::e($check['link']) ?>"><?= close_badge_label($check['status']) ?></a>
              <?php else: ?>
                <span class="badge-soft <?= close_badge_class($check['status']) ?>"><?= close_badge_label($check['status']) ?></span>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-clipboard-data"></i> AY ÖZETİ</div>
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
        <div class="gt-h"><i class="bi bi-graph-down-arrow"></i> NEGATİF MÜŞTERİ KÂRI</div>
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
        <div class="gt-h"><i class="bi bi-person-dash"></i> BU AY KAYDI OLMAYAN AKTİF MÜŞTERİLER</div>
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