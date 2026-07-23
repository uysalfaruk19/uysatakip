<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Uysa\CustomerAuth;
use Uysa\Db;
use Uysa\Env;
use Uysa\Helpers;
use Uysa\Repo;

$cu = CustomerAuth::requireCustomer();
$cid = (int) $cu['customer_id'];
$pdo = Db::pdo();
$repo = new Repo($pdo);

// opus-019: Paraşüt entegrasyonu öncesi gerçek bakiye KAPALI. CARI_LIVE=1 olunca açılır.
$cariLive = in_array(strtolower((string) Env::get('CARI_LIVE', '0')), ['1', 'true', 'yes', 'on'], true);

$ay = (string) ($_GET['ay'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
    $ay = date('Y-m');
}

$flash = '';
$flashOk = true;

// "Ekstre Talep Et" → requests kaydı (admin görür). Yalnız talep-modunda.
if (!$cariLive && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } elseif (($_POST['action'] ?? '') === 'ekstre_talep') {
        $reqId = $repo->createRequest($cid, $cu['cuid'] ?? null, 'talep', 'Cari ekstre talebi (' . ay_label_tr($ay) . ')');
        $repo->addRequestMessage($reqId, 'musteri', 'Cari hesap ekstresi talep ediyorum. Dönem: ' . ay_label_tr($ay) . '.');
        uysa_audit('musteri_ekstre_talep', $cu['username'], (string) $cid, (string) $reqId, client_ip());
        $flash = 'Ekstre talebiniz alındı — UYSA en kısa sürede iletecek. Taleplerim ekranından takip edebilirsiniz.';
    }
}

$eyebrow = Helpers::e($cu['customer_name']) . ' · Cari';
$pageTitle = 'Cari hesap';
$active = 'daha'; // fable-018: Cari bardan kalktı → Daha altında

if ($cariLive) {
    // ── Gerçek bakiye/ekstre (Paraşüt sonrası) ──
    $rows = $repo->customerLedger($cid, $ay);
    $balance = $repo->customerBalance($cid);
    $month = $repo->customerMonthProduction($cid, $ay);
    $prevAy = date('Y-m', strtotime($ay . '-01 -1 month'));
    $nextAy = date('Y-m', strtotime($ay . '-01 +1 month'));
    require __DIR__ . '/partials/header_m.php';
    ?>
      <div class="date-row">
        <a class="icon-btn" href="cari.php?ay=<?= $prevAy ?>" aria-label="Önceki ay"><i class="bi bi-chevron-left"></i></a>
        <span class="date-pill"><i class="bi bi-calendar3"></i> <?= Helpers::e(ay_label_tr($ay)) ?></span>
        <a class="icon-btn" href="cari.php?ay=<?= $nextAy ?>" aria-label="Sonraki ay"><i class="bi bi-chevron-right"></i></a>
      </div>

      <div class="summary-grid mt-2">
        <div class="summary-card <?= $balance > 0 ? 'tint-orange' : 'tint-green' ?>">
          <p class="label">Güncel bakiye</p>
          <p class="metric <?= $balance > 0 ? '' : 'pos' ?>">₺ <?= Helpers::money(abs($balance)) ?></p>
          <p class="row-meta"><?= $balance > 0 ? 'Ödenecek tutar' : ($balance < 0 ? 'Alacaklısınız' : 'Bakiye sıfır') ?></p>
        </div>
        <div class="summary-card">
          <p class="label"><?= Helpers::e(ay_label_tr($ay)) ?> üretim</p>
          <p class="metric small"><?= number_format($month['persons'], 0, ',', '.') ?> kişi · ₺ <?= Helpers::money($month['amount']) ?></p>
        </div>
      </div>

      <div class="section-head mt-3"><h2>Hareketler</h2></div>
      <?php if (!$rows): ?>
        <div class="empty-state">
        <div class="es-ico"><i class="bi bi-cash-coin"></i></div>
        Bu ay hareket yok.</div>
      <?php else: ?>
        <div class="list-groupx">
          <?php foreach ($rows as $r): $out = $r['kind'] === 'borc'; ?>
            <div class="flow-item">
              <span class="flow-icon <?= $out ? 'out' : '' ?>"><i class="bi <?= $out ? 'bi-basket' : 'bi-cash-coin' ?>"></i></span>
              <div style="min-width:0">
                <strong><?= Helpers::e($r['label']) ?></strong>
                <p class="row-meta"><?= Helpers::e(gun_label_tr($r['entry_date'])) ?></p>
              </div>
              <span class="amount <?= $out ? 'out' : 'in' ?>"><?= $out ? '+' : '−' ?> ₺ <?= Helpers::money($r['amount']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="row-meta mt-2"><i class="bi bi-info-circle"></i> "+" borç (üretim), "−" tahsilat.</p>
      <?php endif; ?>
<?php
    require __DIR__ . '/partials/footer_m.php';
    return;
}

// ── Talep modu (Paraşüt öncesi): bakiye yok, "Ekstre Talep Et" ──
require __DIR__ . '/partials/header_m.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <div class="cardx card-pad">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="flow-icon"><i class="bi bi-cash-coin"></i></span>
          <h2 style="margin:0">Cari hesap</h2>
        </div>
        <p class="row-meta">Cari hesabınız (bakiye ve ekstre) yakında — <strong>Paraşüt muhasebe entegrasyonu</strong> ile otomatik gelecek. O zamana kadar güncel ekstrenizi buradan talep edebilirsiniz.</p>
        <form method="post" class="mt-3">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <input type="hidden" name="action" value="ekstre_talep">
          <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-file-earmark-text"></i> Ekstre Talep Et</button>
        </form>
      </div>

      <div class="hint-card mt-2"><i class="bi bi-info-circle"></i> Talebiniz UYSA'ya iletilir; ekstreniz hazırlanınca <a href="talep.php" style="text-decoration:underline">Taleplerim</a> ekranından iletilir.</div>
<?php require __DIR__ . '/partials/footer_m.php'; ?>
