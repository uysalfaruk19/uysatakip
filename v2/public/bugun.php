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

// Bar grafik verisi (girilen firmalar × kişi), broşür "Bugün Sipariş Veren Firmalar".
$barRows = [];
$barMax = 0;
foreach ($rowsData as $r) {
    if ($r['val'] > 0) {
        $barRows[] = ['name' => $r['name'], 'val' => $r['val']];
        $barMax = max($barMax, $r['val']);
    }
}
usort($barRows, static fn($a, $b) => $b['val'] <=> $a['val']);
$barRows = array_slice($barRows, 0, 6);

$pageTitle = 'Panel';
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

      <div class="stat-stack">
        <div class="stat-card stat-green">
          <div class="ico"><i class="bi bi-cash-stack"></i></div>
          <div class="txt">
            <p class="lbl">Bugünkü toplam ciro</p>
            <p class="val">₺ <span id="sum-amount"><?= Helpers::money($sumA) ?></span></p>
          </div>
        </div>
        <div class="stat-card stat-orange">
          <div class="ico"><i class="bi bi-people-fill"></i></div>
          <div class="txt">
            <p class="lbl">Bugünkü toplam kişi</p>
            <p class="val" id="sum-persons"><?= number_format($sumP, 0, ',', '.') ?></p>
          </div>
        </div>
        <div class="stat-card stat-blue">
          <div class="ico"><i class="bi bi-shop"></i></div>
          <div class="txt">
            <p class="lbl">Giriş yapılan müşteri</p>
            <p class="val"><span id="sum-filled"><?= $filled ?>/<?= $total ?></span></p>
          </div>
        </div>
      </div>

      <div class="section-head"><h2>Bölümler</h2></div>
      <div class="mod-grid">
        <a class="mod-card i-green" href="musteriler.php">
          <div class="mico"><i class="bi bi-people"></i></div>
          <div class="mt">Müşteriler</div>
          <div class="md">Üretim / taşıma, birim fiyat, kâr</div>
        </a>
        <a class="mod-card i-blue" href="rapor.php">
          <div class="mico"><i class="bi bi-graph-up-arrow"></i></div>
          <div class="mt">Kâr / Zarar</div>
          <div class="md">Aylık rapor + müşteri drill-down</div>
        </a>
        <a class="mod-card" href="finans.php">
          <div class="mico"><i class="bi bi-receipt"></i></div>
          <div class="mt">Aylık Faturalar</div>
          <div class="md">Gelir / gider akışı</div>
        </a>
        <a class="mod-card i-green" href="cari.php">
          <div class="mico"><i class="bi bi-cash-coin"></i></div>
          <div class="mt">Cari</div>
          <div class="md">Bakiye ve tahsilat</div>
        </a>
        <a class="mod-card soon i-amber" href="yakinda.php?m=siparisler">
          <span class="soon-chip">yakında</span>
          <div class="mico"><i class="bi bi-basket"></i></div>
          <div class="mt">Siparişler</div>
          <div class="md">Onay kuyruğu (F2)</div>
        </a>
        <a class="mod-card soon i-blue" href="yakinda.php?m=stok">
          <span class="soon-chip">yakında</span>
          <div class="mico"><i class="bi bi-box-seam"></i></div>
          <div class="mt">Stok Durumu</div>
          <div class="md">Malzeme giriş/çıkış</div>
        </a>
        <a class="mod-card soon" href="menu.php">
          <span class="soon-chip">yakında</span>
          <div class="mico"><i class="bi bi-clipboard2-data"></i></div>
          <div class="mt">Reçete & Maliyet</div>
          <div class="md">Porsiyon maliyeti</div>
        </a>
        <a class="mod-card soon i-amber" href="yakinda.php?m=personel">
          <span class="soon-chip">yakında</span>
          <div class="mico"><i class="bi bi-person-badge"></i></div>
          <div class="mt">Personel Gideri</div>
          <div class="md">Maaş / prim takibi</div>
        </a>
      </div>

      <?php if ($barRows): ?>
      <div class="cardx card-pad">
        <h2>Bugün üretim veren firmalar</h2>
        <div class="barchart">
          <?php foreach ($barRows as $b): $w = $barMax > 0 ? max(4, round($b['val'] / $barMax * 100)) : 4; ?>
            <div class="bar-row">
              <span class="bar-name"><?= Helpers::e($b['name']) ?></span>
              <span class="bar-track"><span class="bar-fill" style="width: <?= $w ?>%"></span></span>
              <span class="bar-val"><?= number_format($b['val'], 0, ',', '.') ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

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
