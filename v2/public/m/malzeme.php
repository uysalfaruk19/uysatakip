<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Uysa\CustomerAuth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Push;
use Uysa\Repo;

$cu = CustomerAuth::requireCustomer();
$cid = (int) $cu['customer_id'];
$pdo = Db::pdo();
$repo = new Repo($pdo);

$flash = '';
$flashOk = true;

// fable-001: katalog seçimi kalktı — "yazı kutucuğu ve not kutucuğu yeterli" (Ömer).
// Müşteri ihtiyacını serbest metin yazar; UYSA tarafı talebi aynı kuyruğta görür.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $freeText = trim((string) ($_POST['free_text'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        if ($freeText === '') {
            $flash = 'İhtiyacınız olan malzemeleri yazın.';
            $flashOk = false;
        } else {
            $reqId = $repo->createSupplyRequest($cid, [], $cu['cuid'] ?? null, $note ? mb_substr($note, 0, 500) : null, null, mb_substr($freeText, 0, 1000));
            uysa_audit('musteri_malzeme', $cu['username'], (string) $cid, (string) $reqId, client_ip());
            $flash = 'Talebiniz alındı — UYSA hazırlayacak.';
            try {
                (new Push($pdo))->toAdmins('Malzeme talebi: ' . $cu['customer_name'], mb_substr($freeText, 0, 120), ['url' => '/malzeme.php'], 'talep_yeni');
            } catch (\Throwable) {
            }
        }
    }
}

$history = $repo->supplyRequestsForCustomer($cid);
// fable-001: "bu ay gönderilenler" — ay içi talepler tarihli (teslim edilenler işaretli).
$monthReqs = $repo->supplyRequestsForCustomerMonth($cid, date('Y-m'));

$statusMap = [
    'acik'       => ['badge-warn', 'bi-hourglass-split', 'Hazırlanıyor'],
    'hazirlandi' => ['badge-blue', 'bi-box-seam', 'Hazır'],
    'teslim'     => ['badge-ok', 'bi-check2-circle', 'Teslim edildi'],
];

$eyebrow = Helpers::e($cu['customer_name']) . ' · Malzeme';
$pageTitle = 'Malzeme talebi';
$active = 'daha'; // fable-018: Malzeme bardan kalktı → Daha altında
require __DIR__ . '/partials/header_m.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <div class="cardx card-pad">
        <h2>Malzeme iste</h2>
        <p class="row-meta mb-2">İhtiyacınız olan malzemeleri yazın (her satıra bir kalem yazabilirsiniz).</p>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <div class="field"><label>Malzemeler</label>
            <textarea class="textareax" name="free_text" maxlength="1000" rows="4" required placeholder="ör.&#10;Ayçiçek yağı 2 litre&#10;Peçete 5 paket&#10;Sirke 1 litre"></textarea>
          </div>
          <div class="field"><label>Not (opsiyonel)</label>
            <input class="inputx" name="note" maxlength="500" placeholder="ör. cuma sabahına kadar">
          </div>
          <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-send"></i> Talebi gönder</button>
        </form>
      </div>

      <div class="section-head mt-3"><h2>Bu ay gönderilenler (<?= Helpers::e(ay_label_tr(date('Y-m'))) ?>)</h2></div>
      <?php if (!$monthReqs): ?>
        <div class="empty-state">
        <div class="es-ico"><i class="bi bi-box-seam"></i></div>
        Bu ay malzeme talebi olmadı.</div>
      <?php else: ?>
        <div class="list-groupx">
          <?php foreach ($monthReqs as $r): [$bc, $bi, $bt] = $statusMap[$r['status']] ?? ['badge-blue', 'bi-clock', $r['status']]; ?>
            <div class="cardx card-pad">
              <div class="d-flex align-items-center justify-between gap-2 mb-2">
                <div class="row-title"><strong><?= Helpers::e(gun_label_tr($r['request_date'])) ?></strong></div>
                <span class="badge-soft <?= $bc ?>"><i class="bi <?= $bi ?>"></i> <?= $bt ?></span>
              </div>
              <?php if (!empty($r['free_text'])): ?>
                <p class="row-meta" style="white-space:pre-line"><?= Helpers::e($r['free_text']) ?></p>
              <?php endif; ?>
              <?php if ((int) $r['item_count'] > 0): foreach ($repo->supplyRequestItems((int) $r['id']) as $it): ?>
                <div class="supply-row"><div class="s-name"><?= Helpers::e($it['ad']) ?></div><div style="text-align:right"><strong><?= Helpers::money((float) $it['miktar']) ?></strong> <span class="s-meta"><?= Helpers::e($it['birim']) ?></span></div></div>
              <?php endforeach; endif; ?>
              <?php if ($r['note']): ?><p class="row-meta mt-2"><i class="bi bi-chat-left-text"></i> <?= Helpers::e($r['note']) ?></p><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="section-head mt-3"><h2>Geçmiş talepler</h2></div>
      <?php if (!$history): ?>
        <div class="empty-state">
        <div class="es-ico"><i class="bi bi-box-seam"></i></div>
        Henüz malzeme talebiniz yok.</div>
      <?php else: ?>
        <div class="list-groupx">
          <?php foreach ($history as $r): [$bc, $bi, $bt] = $statusMap[$r['status']] ?? ['badge-blue', 'bi-clock', $r['status']]; ?>
            <div class="cardx card-pad">
              <div class="d-flex align-items-center justify-between gap-2 mb-2">
                <div style="min-width:0">
                  <div class="row-title"><strong><?= Helpers::e(gun_label_tr($r['request_date'])) ?></strong></div>
                  <p class="row-meta"><?= (int) $r['item_count'] > 0 ? (int) $r['item_count'] . ' kalem' : 'Serbest liste' ?></p>
                </div>
                <span class="badge-soft <?= $bc ?>"><i class="bi <?= $bi ?>"></i> <?= $bt ?></span>
              </div>
              <?php if (!empty($r['free_text'])): ?>
                <p class="row-meta" style="white-space:pre-line"><?= Helpers::e($r['free_text']) ?></p>
              <?php endif; ?>
              <?php if ((int) $r['item_count'] > 0): foreach ($repo->supplyRequestItems((int) $r['id']) as $it): ?>
                <div class="supply-row"><div class="s-name"><?= Helpers::e($it['ad']) ?></div><div style="text-align:right"><strong><?= Helpers::money((float) $it['miktar']) ?></strong> <span class="s-meta"><?= Helpers::e($it['birim']) ?></span></div></div>
              <?php endforeach; endif; ?>
              <?php if ($r['note']): ?><p class="row-meta mt-2"><i class="bi bi-chat-left-text"></i> <?= Helpers::e($r['note']) ?></p><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer_m.php'; ?>
