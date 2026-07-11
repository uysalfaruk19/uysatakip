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

$flash = '';
$flashOk = true;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $miktarlar = (array) ($_POST['miktar'] ?? []);
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        $items = [];
        foreach ($miktarlar as $itemId => $qty) {
            $q = (float) str_replace(',', '.', (string) $qty);
            if ($q > 0) {
                $items[(int) $itemId] = $q;
            }
        }
        if (!$items) {
            $flash = 'En az bir malzeme için miktar girin.';
            $flashOk = false;
        } else {
            $reqId = $repo->createSupplyRequest($cid, $items, $cu['cuid'] ?? null, $note ? mb_substr($note, 0, 500) : null);
            uysa_audit('musteri_malzeme', $cu['username'], (string) $cid, (string) $reqId, client_ip());
            $flash = 'Talebiniz alındı — UYSA hazırlayacak.';
        }
    }
}

$catalog = $repo->listSupplyItems(true);
$ent = $repo->getEntitlements($cid);          // KENDİ hakedişi (scope)
$history = $repo->supplyRequestsForCustomer($cid);

$statusMap = [
    'acik'       => ['badge-warn', 'bi-hourglass-split', 'Hazırlanıyor'],
    'hazirlandi' => ['badge-blue', 'bi-box-seam', 'Hazır'],
    'teslim'     => ['badge-ok', 'bi-check2-circle', 'Teslim edildi'],
];

$eyebrow = Helpers::e($cu['customer_name']) . ' · Malzeme';
$pageTitle = 'Sarf malzeme';
$active = 'malzeme';
require __DIR__ . '/partials/header_m.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <div class="cardx card-pad">
        <h2>Malzeme iste</h2>
        <p class="row-meta mb-2">İhtiyaç duyduğunuz kalemlere miktar girin. "Hakkın" etiketi tanımlı hakediş miktarınızı gösterir.</p>
        <?php if (!$catalog): ?>
          <div class="empty-state">Katalog boş.</div>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
            <?php foreach ($catalog as $it): $hak = $ent[(int) $it['id']] ?? 0.0; ?>
              <div class="supply-row">
                <div>
                  <div class="s-name"><?= Helpers::e($it['ad']) ?></div>
                  <div class="s-meta"><?= Helpers::e($it['birim']) ?><?php if ($hak > 0): ?> · <span class="ent-tag">Hakkın: <?= Helpers::money($hak) ?> <?= Helpers::e($it['birim']) ?></span><?php endif; ?></div>
                </div>
                <input class="inputx qty-input" inputmode="decimal" name="miktar[<?= (int) $it['id'] ?>]" value="" placeholder="0">
              </div>
            <?php endforeach; ?>
            <div class="field mt-2"><label>Not (opsiyonel)</label>
              <input class="inputx" name="note" maxlength="500" placeholder="ör. cuma sabahına kadar">
            </div>
            <button class="btn-action btn-primaryx btn-full mt-2" type="submit"><i class="bi bi-send"></i> Talebi gönder</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="section-head mt-3"><h2>Geçmiş talepler</h2></div>
      <?php if (!$history): ?>
        <div class="empty-state">Henüz malzeme talebiniz yok.</div>
      <?php else: ?>
        <div class="list-groupx">
          <?php foreach ($history as $r): [$bc, $bi, $bt] = $statusMap[$r['status']] ?? ['badge-blue', 'bi-clock', $r['status']]; ?>
            <div class="cardx card-pad">
              <div class="d-flex align-items-center justify-between gap-2 mb-2">
                <div style="min-width:0">
                  <div class="row-title"><strong><?= Helpers::e(gun_label_tr($r['request_date'])) ?></strong></div>
                  <p class="row-meta"><?= (int) $r['item_count'] ?> kalem</p>
                </div>
                <span class="badge-soft <?= $bc ?>"><i class="bi <?= $bi ?>"></i> <?= $bt ?></span>
              </div>
              <?php foreach ($repo->supplyRequestItems((int) $r['id']) as $it): ?>
                <div class="supply-row"><div class="s-name"><?= Helpers::e($it['ad']) ?></div><div style="text-align:right"><strong><?= Helpers::money((float) $it['miktar']) ?></strong> <span class="s-meta"><?= Helpers::e($it['birim']) ?></span></div></div>
              <?php endforeach; ?>
              <?php if ($r['note']): ?><p class="row-meta mt-2"><i class="bi bi-chat-left-text"></i> <?= Helpers::e($r['note']) ?></p><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer_m.php'; ?>
