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

$customers = $repo->activeCustomers();
$cid = (int) ($_GET['musteri'] ?? ($customers[0]['id'] ?? 0));
$month = (string) ($_GET['ay'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$flash = '';
$flashOk = true;

// ── Tahsilat ekle ────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $cid = (int) ($_POST['customer_id'] ?? $cid);
        $amount = Helpers::parseMoney((string) ($_POST['amount'] ?? '0'));
        $txDate = (string) ($_POST['entry_date'] ?? Helpers::today());
        if (!Helpers::isDate($txDate)) {
            $txDate = Helpers::today();
        }
        if ($amount > 0 && $cid > 0) {
            $repo->addCari('customer', $cid, $txDate, 'alacak', $amount, trim((string) ($_POST['note'] ?? 'Tahsilat')));
            uysa_audit('tahsilat', $u['username'], (string) $cid, json_encode(['t' => $amount]), client_ip());
            $flash = 'Tahsilat kaydedildi · ₺ ' . Helpers::money($amount);
            $month = substr($txDate, 0, 7);
        } else {
            $flash = 'Geçerli tutar girin.';
            $flashOk = false;
        }
    }
}

$cust = $cid ? $repo->customer($cid) : null;
$balance = $cust ? $repo->customerBalance($cid) : 0.0;
$statement = $cust ? $repo->customerStatement($cid, $month) : [];
// Bu ayki üretim = faturalanacak tutar (production tek gerçek kaynak).
// TODO (F2): bu tutardan fatura üret → cari_entries'e 'borc' yaz (onay akışı). Şimdilik
// sadece gösterilir; migrasyondaki alacaklar cari borcu oluşturuyor, üretim otomatik borca dönmüyor.
$ayUretim = $cust ? $repo->customerMonthProduction($cid, $month) : ['persons' => 0, 'amount' => 0.0, 'cnt' => 0];
$ayKisi = $ayUretim['persons'];
$net = $cust ? $repo->customerNetKarlilik($cid, $month) : null;

$eyebrow = 'Müşteri kartı';
$pageTitle = $cust ? $cust['name'] : 'Cari';
$active = 'cari';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <?php if (!$cust): ?>
        <div class="empty-state">Müşteri bulunamadı.</div>
      <?php else: ?>
        <div class="summary-grid">
          <div class="summary-card"><p class="label">Birim fiyat</p><p class="metric">₺ <?= Helpers::money((float) $cust['unit_price']) ?></p></div>
          <div class="summary-card"><p class="label">Kokpit bakiye</p><p class="metric <?= $balance < 0 ? 'neg' : '' ?>">₺ <?= Helpers::money($balance) ?></p></div>
          <?php if (($cust['parasut_bakiye'] ?? null) !== null): ?>
          <div class="summary-card tint-blue"><p class="label">Paraşüt bakiyesi (muhasebe)</p>
            <p class="metric <?= (float) $cust['parasut_bakiye'] < 0 ? 'neg' : '' ?>">₺ <?= Helpers::money((float) $cust['parasut_bakiye']) ?></p>
            <span class="delta"><i class="bi bi-shield-check"></i> son senkron <?= $cust['parasut_sync_at'] ? Helpers::e(date('d.m.Y H:i', strtotime((string) $cust['parasut_sync_at']))) : '—' ?></span></div>
          <?php endif; ?>
          <div class="summary-card wide"><p class="label"><?= Helpers::e(ay_label_tr($month)) ?> üretim (faturalanacak)</p>
            <p class="metric"><?= number_format($ayKisi, 0, ',', '.') ?> kişi · ₺ <?= Helpers::money($ayUretim['amount']) ?></p>
            <span class="delta"><i class="bi bi-check2-circle"></i> <?= $balance >= 0 ? 'Alacak (bize borçlu)' : 'Fazla ödeme' ?></span></div>
        </div>

        <div class="segmented">
          <?php foreach (array_slice($customers, 0, 6) as $c): ?>
            <a class="chip <?= $cid === (int) $c['id'] ? 'active' : '' ?>" href="cari.php?musteri=<?= (int) $c['id'] ?>&ay=<?= $month ?>"><?= Helpers::e($c['name']) ?></a>
          <?php endforeach; ?>
        </div>

        <?php if ($net): ?>
        <div class="cardx card-pad">
          <h2>Net kâr <span class="text-muted" style="font-size:12px;font-weight:600">(personel + gider düşülmüş · <?= Helpers::e(ay_label_tr($month)) ?>)</span></h2>
          <table class="tablex">
            <tbody>
              <?php if ($net['category'] === 'tasima'): ?>
                <tr><td>Taşıma satış</td><td class="num">₺ <?= Helpers::money($net['ciro']) ?></td></tr>
                <tr><td>Taşıma alış</td><td class="num">− ₺ <?= Helpers::money($net['alis']) ?></td></tr>
                <tr><td>Sabit gider</td><td class="num">− ₺ <?= Helpers::money($net['sabit']) ?></td></tr>
              <?php else: ?>
                <tr><td>Üretim cirosu</td><td class="num">₺ <?= Helpers::money($net['ciro']) ?></td></tr>
              <?php endif; ?>
              <tr><td>Payına düşen gider (ciro oranlı)</td><td class="num">− ₺ <?= Helpers::money($net['pay_gider']) ?></td></tr>
              <tr><td>Payına düşen personel</td><td class="num">− ₺ <?= Helpers::money($net['pay_personel']) ?></td></tr>
              <tr class="is-total"><td>Net kâr</td><td class="num" style="color:<?= $net['net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($net['net']) ?></td></tr>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <div class="cardx card-pad">
          <h2><?= Helpers::e(ay_label_tr($month)) ?> ekstresi</h2>
          <?php if (!$statement): ?>
            <div class="empty-state">Bu ay hareket yok.</div>
          <?php else: ?>
            <table class="tablex">
              <thead><tr><th>Tarih</th><th>Açıklama</th><th class="num">Tutar</th></tr></thead>
              <tbody>
              <?php foreach ($statement as $s): $isBorc = $s['direction'] === 'borc'; ?>
                <tr>
                  <td><?= Helpers::e(date('d.m', strtotime($s['entry_date']))) ?></td>
                  <td><?= Helpers::e($s['note'] ?: ($isBorc ? 'Üretim' : 'Tahsilat')) ?></td>
                  <td class="num <?= $isBorc ? '' : 'amount in' ?>">₺ <?= Helpers::money((float) $s['amount']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="cardx card-pad">
          <h2>Tahsilat ekle</h2>
          <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
            <input type="hidden" name="customer_id" value="<?= $cid ?>">
            <div class="field"><label>Tutar (₺)</label><input class="inputx" name="amount" inputmode="decimal" placeholder="0,00" required></div>
            <div class="field"><label>Tarih</label><input class="inputx" type="date" name="entry_date" value="<?= Helpers::e(Helpers::today()) ?>"></div>
            <div class="field"><label>Not</label><input class="inputx" name="note" placeholder="ör. havale"></div>
            <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-check2"></i> Tahsilatı kaydet</button>
          </form>
        </div>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
