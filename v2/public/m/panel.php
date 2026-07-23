<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Uysa\CustomerAuth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

$cu = CustomerAuth::requireCustomer();
$cid = (int) $cu['customer_id'];
$pdo = Db::pdo();
$repo = new Repo($pdo);

$today = Helpers::today();
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// fable-001: aylık kişi/tutar kaldırıldı (tutar = muhasebe, idari işler görmesin).
// fable-002: sayı kaynağı birleşik (production + orders) — UYSA'nın girdiği sayı da görünür.
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime($weekStart . ' +5 day')); // Pzt–Cmt
$rangeEnd = max($weekEnd, $tomorrow); // yarın haftaya taşarsa (Cmt/Paz) da kapsansın
$byDay = $repo->customerDailyCounts($cid, $weekStart, $rangeEnd);
$todayPersons = $byDay[$today] ?? 0;
$tomorrowPersons = $byDay[$tomorrow] ?? null;
$order = $repo->customerOrder($cid, $tomorrow, 'ogle'); // müşterinin KENDİ siparişi (durum için)

// fable-019: "Yarının sayısı" kartı birleşik kaynağa geçer + sayı devri (gösterim varsayılanı).
// Öncelik: kendi order'ı > birleşik sayı (UYSA planı, 0 dahil gerçek kayıt) > devir (son servis günü).
// $tomorrowPersons null = hiç kayıt yok → devir; 0/>0 = gerçek kayıt → aynen gösterilir (devir YOK).
$tomorrowCarry = ($order === null && $tomorrowPersons === null)
    ? $repo->lastKnownDailyCount($cid, $tomorrow)
    : null;
$cutoff = Helpers::orderCutoffLabel();
$tomorrowEditable = Helpers::orderEditable($tomorrow);

// Günün menüsü: SADECE bu müşteriye yayınlanmış menülerden (IDOR scope).
// fable-001: öğle boşsa günün İLK dolu öğünü gösterilir (öğle-dışı müşteriler boş görmesin).
// fable-019: bugün menü yoksa İLERİYE bak (en fazla 7 gün) — servissiz günde (pazar) yaklaşan menü.
$mealsTr = ['sabah' => 'Sabah', 'ogle' => 'Öğle', 'aksam' => 'Akşam', 'gece' => 'Gece', 'kumanya' => 'Kumanya'];
$menuText = '';
$menuMeal = '';
$menuDay = $today;
$menuAhead = false;
$menuFound = $repo->menuForCustomerFrom($cid, $today, 7);
if ($menuFound) {
    $menuDay = $menuFound['date'];
    $menuAhead = $menuFound['ahead'];
    $menuMeal = isset($menuFound['items']['ogle']) ? 'ogle' : (string) array_key_first($menuFound['items']);
    $menuText = $menuFound['items'][$menuMeal];
}

$openReqs = $repo->customerRequests($cid);
$openCount = 0;
foreach ($openReqs as $r) {
    if ($r['status'] === 'acik') {
        $openCount++;
    }
}
// Bekleyen (açık/hazırlanan) malzeme talebi rozeti
$openSupply = 0;
foreach ($repo->supplyRequestsForCustomer($cid) as $sr) {
    if ($sr['status'] !== 'teslim') {
        $openSupply++;
    }
}

$statusMap = [
    'gonderildi'  => ['badge-warn', 'bi-clock', 'Gönderildi'],
    'onaylandi'   => ['badge-ok', 'bi-check2-circle', 'Onaylandı'],
    'reddedildi'  => ['badge-warn', 'bi-x-circle', 'Reddedildi'],
    'taslak'      => ['badge-blue', 'bi-pencil', 'Taslak'],
];

$gunKisa = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt'];

$eyebrow = Helpers::e($cu['customer_name']) . ' Yemek Paneli';
$pageTitle = 'Merhaba ' . (($cu['display_name'] ?: $cu['username']));
$active = 'panel';
require __DIR__ . '/partials/header_m.php';
?>
      <?php /* fable-001: günün menüsü EN ÜSTTE (Ömer: "günün menüsü sayının üstünde olsun") */ ?>
      <div class="meal-card">
        <p class="label"><?= $menuAhead ? 'Sıradaki menü' : 'Günün menüsü' ?> (<?= Helpers::e(gun_label_tr($menuDay)) ?><?= $menuMeal !== '' && $menuMeal !== 'ogle' ? ' · ' . Helpers::e($mealsTr[$menuMeal] ?? $menuMeal) : '' ?>)</p>
        <?php if ($menuText !== ''): ?>
          <h2><?= Helpers::e($menuText) ?></h2>
          <p class="row-meta mt-1"><a href="menu.php" style="text-decoration:underline">Tüm menüyü gör</a></p>
        <?php else: ?>
          <h2 class="text-muted">Menü yakında</h2>
          <p class="row-meta">Yayınlanan menü burada görünecek. <a href="menu.php" style="text-decoration:underline">Menü sayfası</a></p>
        <?php endif; ?>
      </div>

      <div class="summary-grid">
        <div class="summary-card"><p class="label">Bugün · kişi</p><p class="metric"><?= number_format($todayPersons, 0, ',', '.') ?></p></div>
        <div class="summary-card"><p class="label">Yarın · kişi</p><p class="metric"><?= $tomorrowPersons !== null ? number_format($tomorrowPersons, 0, ',', '.') : '—' ?></p></div>
      </div>

      <?php /* fable-001: Pzt–Cmt haftalık şerit — müşteri kendi sayısını görür */ ?>
      <div class="cardx card-pad">
        <p class="label">Bu hafta (Pzt–Cmt) · yemek sayılarınız</p>
        <?php /* fable-002: şerit tıklanabilir — güne dokununca Sayı Bildir'de o gün açılır */ ?>
        <div class="week-strip">
          <?php for ($i = 0; $i < 6; $i++): $d = date('Y-m-d', strtotime($weekStart . " +$i day"));
              $locked = !Helpers::orderEditable($d); ?>
            <a class="week-day <?= $d === $today ? 'today' : '' ?> <?= $locked ? 'locked' : '' ?>" href="siparis.php?date=<?= $d ?>">
              <span class="wd-name"><?= $gunKisa[$i] ?></span>
              <span class="wd-date"><?= date('d.m', strtotime($d)) ?></span>
              <span class="wd-count"><?= isset($byDay[$d]) ? number_format($byDay[$d], 0, ',', '.') : '·' ?></span>
            </a>
          <?php endfor; ?>
        </div>
        <a class="btn-action btn-primaryx btn-full mt-3" href="siparis.php"><i class="bi bi-plus-square"></i> Yemek sayısı bildir</a>
      </div>

      <div class="cardx card-pad">
        <div class="d-flex align-items-start justify-content-between gap-2">
          <div>
            <p class="label">Yarının sayısı (<?= Helpers::e(gun_label_tr($tomorrow)) ?>)</p>
            <?php if ($order): [$bc, $bi, $bt] = $statusMap[$order['status']] ?? ['badge-blue', 'bi-clock', $order['status']]; ?>
              <h2><?= (int) $order['persons'] ?> kişi · Öğle</h2>
              <p class="row-meta">Durum: <?= Helpers::e($bt) ?></p>
            <?php elseif ($tomorrowPersons !== null): /* fable-019: kendi order'ı yok ama UYSA planı var → birleşik sayı görünür */ ?>
              <h2><?= number_format($tomorrowPersons, 0, ',', '.') ?> kişi</h2>
              <p class="row-meta"><i class="bi bi-clipboard-check"></i> Kaynak: UYSA planı</p>
            <?php elseif ($tomorrowCarry !== null): /* fable-019: hiç kayıt yok → son servis gününden devir (SADECE gösterim, kayıt yazılmaz) */ ?>
              <h2><?= number_format($tomorrowCarry['persons'], 0, ',', '.') ?> kişi</h2>
              <p class="row-meta"><i class="bi bi-arrow-repeat"></i> Otomatik: dünkü ile aynı (<?= number_format($tomorrowCarry['persons'], 0, ',', '.') ?>) · değişiklik için bildirin</p>
            <?php else: ?>
              <h2>Henüz girilmedi</h2>
              <p class="row-meta">Yarın için kişi sayısı bildirin.</p>
            <?php endif; ?>
          </div>
          <?php if ($order): ?>
            <span class="badge-soft <?= $bc ?>"><i class="bi <?= $bi ?>"></i> <?= Helpers::e($bt) ?></span>
          <?php else: ?>
            <a class="btn-action btn-primaryx" href="siparis.php"><i class="bi bi-plus"></i> Sayı bildir</a>
          <?php endif; ?>
        </div>
        <?php if ($tomorrowEditable): ?>
          <p class="row-meta mt-2"><i class="bi bi-clock-history"></i> Değişiklik saati: bugün <?= Helpers::e($cutoff) ?>'a kadar. <a href="siparis.php" style="text-decoration:underline">Sayı gir / değiştir</a></p>
        <?php else: ?>
          <p class="row-meta mt-2"><i class="bi bi-lock"></i> Yarın için değişiklik saati (<?= Helpers::e($cutoff) ?>) geçti — sayı kilitlendi.</p>
        <?php endif; ?>
      </div>

      <?php if ($openCount > 0): ?>
        <a class="cardx card-pad" href="talep.php" style="display:block">
          <div class="d-flex align-items-center justify-content-between gap-2">
            <div><p class="label">Talepler</p><h2><?= $openCount ?> açık talep</h2></div>
            <span class="badge-soft badge-warn"><i class="bi bi-chat-left-text"></i> Görüntüle</span>
          </div>
        </a>
      <?php else: ?>
        <div class="empty-state">
          <div class="es-ico"><i class="bi bi-chat-left-text"></i></div>
          Açık talebiniz yok.
          <div class="mt-3"><a class="btn-action btn-secondaryx" href="talep.php">Talep aç</a></div>
        </div>
      <?php endif; ?>

      <a class="cardx card-pad" href="malzeme.php" style="display:block">
        <div class="d-flex align-items-center justify-content-between gap-2">
          <div><p class="label">Sarf malzeme</p><h2 style="margin:0"><?= $openSupply > 0 ? $openSupply . ' talep sürüyor' : 'Malzeme iste' ?></h2></div>
          <span class="badge-soft <?= $openSupply > 0 ? 'badge-warn' : 'badge-blue' ?>"><i class="bi bi-box-seam"></i> <?= $openSupply > 0 ? 'Görüntüle' : 'Malzeme talebi' ?></span>
        </div>
      </a>
<?php require __DIR__ . '/partials/footer_m.php'; ?>
