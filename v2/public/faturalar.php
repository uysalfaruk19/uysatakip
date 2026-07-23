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

$month = (string) ($_GET['ay'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$custId = (int) ($_GET['musteri'] ?? 0) ?: null;
$kdvOran = max(0.0, (float) str_replace(',', '.', (string) ($_GET['kdv'] ?? '0')));
$flash = '';
$flashOk = true;

// ── Faturayı kaydet (üret + kaydet) ──────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $cid = (int) ($_POST['musteri'] ?? 0);
        $ay = (string) ($_POST['ay'] ?? $month);
        $kdv = max(0.0, (float) str_replace(',', '.', (string) ($_POST['kdv'] ?? '0')));
        $durum = ($_POST['durum'] ?? 'taslak') === 'kesildi' ? 'kesildi' : 'taslak';
        if ($cid > 0 && preg_match('/^\d{4}-\d{2}$/', $ay)) {
            $inv = $repo->customerInvoice($cid, $ay, $kdv);
            if ($inv['ara_toplam'] <= 0) {
                $flash = 'Bu müşterinin o ay üretimi yok — fatura üretilemez.';
                $flashOk = false;
            } else {
                $repo->saveFatura($cid, $ay, $inv['ara_toplam'], $kdv, $inv['genel_toplam'], $durum);
                uysa_audit('fatura_kaydet', $u['username'], $cid . ':' . $ay, json_encode(['g' => $inv['genel_toplam'], 'd' => $durum]), client_ip());
                $flash = 'Fatura kaydedildi (' . ($durum === 'kesildi' ? 'kesildi' : 'taslak') . ') · ₺ ' . Helpers::money($inv['genel_toplam']);
            }
            $month = $ay;
            $custId = $cid;
            $kdvOran = $kdv;
        }
    }
}

$customers = $repo->listCustomersByCategory('uretim');
$cust = $custId ? $repo->customer($custId) : null;
$invoice = $cust ? $repo->customerInvoice($custId, $month, $kdvOran) : null;
$faturalar = $repo->listFaturalar();

$pageTitle = 'Faturalar';
$eyebrow = 'Aylık müşteri faturası';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <style>
        @media print {
          body { background: #fff; }
          .topbar, .bottom-tabs, .fab-slot, .fab-backdrop, .fab-menu,
          .fatura-filtre, .fatura-actions, .flash, .section-head, .fatura-gecmis, .hint-card { display: none !important; }
          .app-shell { max-width: 100%; padding: 0; }
          .fatura-print { border: 0 !important; box-shadow: none !important; }
        }
      </style>

      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <form method="get" class="cardx card-pad fatura-filtre">
        <h2>Fatura oluştur</h2>
        <div class="form-grid">
          <div class="field"><label>Müşteri</label>
            <select class="selectx" name="musteri" onchange="this.form.submit()" required>
              <option value="">— müşteri seç —</option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $custId === (int) $c['id'] ? 'selected' : '' ?>><?= Helpers::e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Ay</label>
            <input class="inputx" type="month" name="ay" value="<?= Helpers::e($month) ?>" onchange="this.form.submit()">
          </div>
          <div class="field"><label>KDV oranı (%) — opsiyonel</label>
            <input class="inputx" name="kdv" inputmode="decimal" value="<?= $kdvOran > 0 ? rtrim(rtrim(number_format($kdvOran, 2, '.', ''), '0'), '.') : '' ?>" placeholder="0 (KDV yok)">
          </div>
          <button class="btn-action btn-secondaryx btn-full" type="submit"><i class="bi bi-arrow-repeat"></i> Faturayı hesapla</button>
        </div>
      </form>

      <?php if ($cust && $invoice): ?>
        <?php if ($invoice['ara_toplam'] <= 0): ?>
          <div class="empty-state">
            <div class="es-ico"><i class="bi bi-receipt"></i></div>
            <?= Helpers::e($cust['name']) ?> için <?= Helpers::e(ay_label_tr($month)) ?> ayında üretim kaydı yok. Fatura üretilemez.</div>
        <?php else: ?>
          <div class="cardx card-pad fatura-print">
            <div class="d-flex align-items-center justify-content-between" style="gap:10px;margin-bottom:12px">
              <div>
                <div class="brand-mark" style="width:36px;height:36px;font-size:16px">U</div>
              </div>
              <div style="text-align:right">
                <strong style="font-size:15px">UYSA Yemek Hizmetleri</strong>
                <p class="row-meta" style="margin:0">Aylık hizmet faturası</p>
              </div>
            </div>
            <table class="tablex" style="margin-bottom:10px">
              <tbody>
                <tr><td>Müşteri</td><td class="num"><strong><?= Helpers::e($cust['name']) ?></strong></td></tr>
                <tr><td>Dönem</td><td class="num"><?= Helpers::e(ay_label_tr($month)) ?></td></tr>
                <tr><td>Üretim günü / toplam kişi</td><td class="num"><?= $invoice['gun'] ?> gün · <?= number_format($invoice['persons'], 0, ',', '.') ?> kişi</td></tr>
              </tbody>
            </table>

            <div style="overflow-x:auto">
            <table class="mini-cal">
              <thead><tr><th>Gün</th><th>Öğle</th><th>Akşam</th><th>Kumanya</th><th>Kişi</th><th>Tutar</th></tr></thead>
              <tbody>
              <?php foreach ($invoice['lines'] as $r): ?>
                <tr>
                  <td><?= Helpers::e(date('d.m D', strtotime($r['gun']))) ?></td>
                  <td><?= $r['ogle'] ? number_format((int) $r['ogle'], 0, ',', '.') : '·' ?></td>
                  <td><?= $r['aksam'] ? number_format((int) $r['aksam'], 0, ',', '.') : '·' ?></td>
                  <td><?= $r['kumanya'] ? number_format((int) $r['kumanya'], 0, ',', '.') : '·' ?></td>
                  <td><strong><?= number_format((int) $r['kisi'], 0, ',', '.') ?></strong></td>
                  <td>₺ <?= Helpers::money((float) $r['tutar']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            </div>

            <table class="tablex" style="margin-top:12px">
              <tbody>
                <tr><td>Ara toplam</td><td class="num">₺ <?= Helpers::money($invoice['ara_toplam']) ?></td></tr>
                <?php if ($kdvOran > 0): ?>
                <tr><td>KDV (%<?= rtrim(rtrim(number_format($kdvOran, 2, ',', '.'), '0'), ',') ?>)</td><td class="num">₺ <?= Helpers::money($invoice['kdv_tutar']) ?></td></tr>
                <?php endif; ?>
                <tr class="is-total"><td>Genel toplam</td><td class="num">₺ <?= Helpers::money($invoice['genel_toplam']) ?></td></tr>
              </tbody>
            </table>
          </div>

          <div class="actions-row mt-3 fatura-actions">
            <button class="btn-action btn-secondaryx flex-fill" type="button" onclick="window.print()"><i class="bi bi-printer"></i> Yazdır</button>
            <form method="post" style="flex:1">
              <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
              <input type="hidden" name="musteri" value="<?= (int) $custId ?>">
              <input type="hidden" name="ay" value="<?= Helpers::e($month) ?>">
              <input type="hidden" name="kdv" value="<?= Helpers::e((string) $kdvOran) ?>">
              <input type="hidden" name="durum" value="kesildi">
              <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-check2-circle"></i> Fatura oluştur</button>
            </form>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <div class="section-head fatura-gecmis"><h2>Kesilen faturalar</h2></div>
      <div class="cardx card-pad fatura-gecmis">
        <?php if (!$faturalar): ?>
          <div class="empty-state">
            <div class="es-ico"><i class="bi bi-receipt"></i></div>
            Henüz fatura oluşturulmadı.</div>
        <?php else: ?>
          <table class="tablex">
            <thead><tr><th>Müşteri</th><th>Dönem</th><th class="num">Genel toplam</th><th>Durum</th></tr></thead>
            <tbody>
            <?php foreach ($faturalar as $f): ?>
              <tr>
                <td><a href="faturalar.php?musteri=<?= (int) $f['customer_id'] ?>&ay=<?= Helpers::e($f['ay']) ?><?= (float) $f['kdv_oran'] > 0 ? '&kdv=' . rtrim(rtrim(number_format((float) $f['kdv_oran'], 2, '.', ''), '0'), '.') : '' ?>" style="color:var(--primary);font-weight:700"><?= Helpers::e($f['customer_name']) ?></a></td>
                <td><?= Helpers::e(ay_label_tr($f['ay'])) ?></td>
                <td class="num">₺ <?= Helpers::money((float) $f['genel_toplam']) ?></td>
                <td><span class="badge-soft <?= $f['durum'] === 'kesildi' ? 'badge-warn' : '' ?>" style="<?= $f['durum'] === 'kesildi' ? '' : 'background:var(--surface-2);color:var(--muted)' ?>"><?= $f['durum'] === 'kesildi' ? 'Kesildi' : 'Taslak' ?></span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
<?php require __DIR__ . '/partials/footer.php'; ?>
