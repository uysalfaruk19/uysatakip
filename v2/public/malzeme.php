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

$tab = (string) ($_GET['tab'] ?? 'kuyruk');
if (!in_array($tab, ['kuyruk', 'katalog', 'hakedis'], true)) {
    $tab = 'kuyruk';
}
$flash = '';
$flashOk = true;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'status') {
            $reqId = (int) ($_POST['request_id'] ?? 0);
            $status = (string) ($_POST['status'] ?? '');
            if (in_array($status, ['acik', 'hazirlandi', 'teslim'], true) && $repo->supplyRequestById($reqId)) {
                $repo->setSupplyRequestStatus($reqId, $status);
                uysa_audit('malzeme_durum', $u['username'], (string) $reqId, $status, client_ip());
                $flash = 'Talep durumu güncellendi: ' . $status;
            } else {
                $flash = 'Talep bulunamadı.';
                $flashOk = false;
            }
            $tab = 'kuyruk';
        } elseif ($action === 'save_item') {
            $ad = trim((string) ($_POST['ad'] ?? ''));
            $birim = trim((string) ($_POST['birim'] ?? 'adet')) ?: 'adet';
            $sort = (int) ($_POST['sort_order'] ?? 0);
            $id = (int) ($_POST['id'] ?? 0) ?: null;
            if ($ad === '') {
                $flash = 'Malzeme adı zorunlu.';
                $flashOk = false;
            } else {
                try {
                    $repo->upsertSupplyItem($ad, $birim, $id, $sort);
                    $flash = 'Katalog kaydedildi · ' . $ad;
                } catch (\Throwable $e) {
                    $flash = 'Kayıt hatası (ad benzersiz olmalı).';
                    $flashOk = false;
                }
            }
            $tab = 'katalog';
        } elseif ($action === 'item_active') {
            $repo->setSupplyItemActive((int) ($_POST['id'] ?? 0), (int) ($_POST['active'] ?? 1) === 1);
            $flash = 'Katalog güncellendi.';
            $tab = 'katalog';
        } elseif ($action === 'save_entitlement') {
            $custId = (int) ($_POST['customer_id'] ?? 0);
            $miktarlar = (array) ($_POST['miktar'] ?? []);
            if ($custId > 0 && $repo->customer($custId)) {
                $repo->upsertEntitlementsBulk($custId, $miktarlar);
                uysa_audit('malzeme_hakedis', $u['username'], (string) $custId, null, client_ip());
                $flash = 'Hakedişler kaydedildi.';
            } else {
                $flash = 'Müşteri seçin.';
                $flashOk = false;
            }
            $tab = 'hakedis';
            $_GET['musteri'] = (string) $custId;
        }
    }
}

$openCount = $repo->openSupplyRequestsCount();
$csrf = Helpers::csrfToken();
$statusMap = [
    'acik'       => ['badge-warn', 'bi-hourglass-split', 'Açık'],
    'hazirlandi' => ['badge-blue', 'bi-box-seam', 'Hazırlandı'],
    'teslim'     => ['badge-ok', 'bi-check2-circle', 'Teslim'],
];

$eyebrow = 'Sarf malzeme';
$pageTitle = 'Malzeme Talepleri';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <div class="segmented">
        <a class="chip <?= $tab === 'kuyruk' ? 'active' : '' ?>" href="malzeme.php?tab=kuyruk">Talepler<?= $openCount > 0 ? ' (' . $openCount . ')' : '' ?></a>
        <a class="chip <?= $tab === 'katalog' ? 'active' : '' ?>" href="malzeme.php?tab=katalog">Katalog</a>
        <a class="chip <?= $tab === 'hakedis' ? 'active' : '' ?>" href="malzeme.php?tab=hakedis">Hakedişler</a>
      </div>

<?php if ($tab === 'kuyruk'):
    $acik = $repo->openSupplyRequests('acik');
    $hazir = $repo->openSupplyRequests('hazirlandi');
    ?>
      <div class="stat-stack mt-2">
        <div class="stat-card stat-orange">
          <div class="ico"><i class="bi bi-hourglass-split"></i></div>
          <div class="txt"><p class="lbl">Açık talep</p><p class="val"><?= count($acik) ?></p></div>
        </div>
      </div>

      <div class="section-head mt-3"><h2>Açık talepler</h2></div>
      <?php if (!$acik): ?>
        <div class="empty-state">Açık malzeme talebi yok.</div>
      <?php else: foreach ($acik as $r): ?>
        <div class="cardx card-pad mb-2">
          <div class="d-flex align-items-center justify-between gap-2">
            <div style="min-width:0">
              <div class="row-title"><strong><?= Helpers::e($r['customer_name']) ?></strong></div>
              <p class="row-meta"><?= Helpers::e(gun_label_tr($r['request_date'])) ?> · <?= (int) $r['item_count'] ?> kalem</p>
            </div>
            <?php [$bc, $bi, $bt] = $statusMap[$r['status']]; ?>
            <span class="badge-soft <?= $bc ?>"><i class="bi <?= $bi ?>"></i> <?= $bt ?></span>
          </div>
          <div class="mt-2">
            <?php foreach ($repo->supplyRequestItems((int) $r['id']) as $it): ?>
              <div class="supply-row"><div class="s-name"><?= Helpers::e($it['ad']) ?></div><div style="text-align:right"><strong><?= Helpers::money((float) $it['miktar']) ?></strong> <span class="s-meta"><?= Helpers::e($it['birim']) ?></span></div></div>
            <?php endforeach; ?>
          </div>
          <?php if ($r['note']): ?><p class="row-meta mt-2"><i class="bi bi-chat-left-text"></i> <?= Helpers::e($r['note']) ?></p><?php endif; ?>
          <form method="post" class="mt-2">
            <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
            <input type="hidden" name="status" value="hazirlandi">
            <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-box-seam"></i> Hazırlandı işaretle</button>
          </form>
        </div>
      <?php endforeach; endif; ?>

      <?php if ($hazir): ?>
        <div class="section-head mt-3"><h2>Hazırlanan (teslim bekliyor)</h2></div>
        <?php foreach ($hazir as $r): ?>
          <div class="cardx card-pad mb-2">
            <div class="d-flex align-items-center justify-between gap-2">
              <div style="min-width:0">
                <div class="row-title"><strong><?= Helpers::e($r['customer_name']) ?></strong></div>
                <p class="row-meta"><?= (int) $r['item_count'] ?> kalem · <?= Helpers::e(gun_label_tr($r['request_date'])) ?></p>
              </div>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
                <input type="hidden" name="status" value="teslim">
                <button class="btn-action btn-secondaryx" type="submit"><i class="bi bi-check2"></i> Teslim</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

<?php elseif ($tab === 'katalog'):
    $items = $repo->listSupplyItems(false);
    $editItem = isset($_GET['edit']) ? $repo->supplyItem((int) $_GET['edit']) : null;
    ?>
      <div class="fab-sheet mt-2">
        <h2><?= $editItem ? 'Malzeme düzenle' : 'Yeni malzeme' ?></h2>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
          <input type="hidden" name="action" value="save_item">
          <?php if ($editItem): ?><input type="hidden" name="id" value="<?= (int) $editItem['id'] ?>"><?php endif; ?>
          <div class="field"><label>Malzeme adı</label>
            <input class="inputx" name="ad" value="<?= Helpers::e($editItem['ad'] ?? '') ?>" required>
          </div>
          <div class="actions-row">
            <div class="field flex-fill"><label>Birim</label>
              <input class="inputx" name="birim" value="<?= Helpers::e($editItem['birim'] ?? 'adet') ?>" placeholder="adet / litre / kg">
            </div>
            <div class="field" style="width:96px"><label>Sıra</label>
              <input class="inputx" type="number" name="sort_order" value="<?= (int) ($editItem['sort_order'] ?? 0) ?>">
            </div>
          </div>
          <div class="actions-row">
            <?php if ($editItem): ?><a class="btn-action btn-ghost flex-fill" href="malzeme.php?tab=katalog">Vazgeç</a><?php endif; ?>
            <button class="btn-action btn-primaryx flex-fill" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
          </div>
        </form>
      </div>

      <div class="section-head mt-3"><h2>Katalog</h2><span class="text-muted" style="font-size:12px"><?= count($items) ?> kalem</span></div>
      <div class="cardx card-pad">
        <?php if (!$items): ?><div class="empty-state">Katalog boş.</div><?php endif; ?>
        <?php foreach ($items as $it): $pasif = (int) $it['is_active'] !== 1; ?>
          <div class="customer-row" style="<?= $pasif ? 'opacity:.55' : '' ?>">
            <div>
              <div class="row-title"><span class="status-dot"></span><strong><?= Helpers::e($it['ad']) ?></strong> <?php if ($pasif): ?><span class="badge-soft badge-warn">Pasif</span><?php endif; ?></div>
              <p class="row-meta">Birim: <?= Helpers::e($it['birim']) ?></p>
            </div>
            <div class="actions-row" style="justify-content:flex-end">
              <a class="icon-btn" href="malzeme.php?tab=katalog&edit=<?= (int) $it['id'] ?>" aria-label="Düzenle"><i class="bi bi-pencil"></i></a>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
                <input type="hidden" name="action" value="item_active">
                <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                <input type="hidden" name="active" value="<?= $pasif ? 1 : 0 ?>">
                <button class="icon-btn" type="submit" aria-label="<?= $pasif ? 'Aktifleştir' : 'Pasifleştir' ?>"><i class="bi <?= $pasif ? 'bi-arrow-counterclockwise' : 'bi-archive' ?>"></i></button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

<?php else: // hakedis
    $uretim = $repo->listCustomersByCategory('uretim');
    $selCust = (int) ($_GET['musteri'] ?? 0);
    $selCustomer = $selCust ? $repo->customer($selCust) : null;
    $catalog = $repo->listSupplyItems(true);
    $ent = $selCustomer ? $repo->getEntitlements($selCust) : [];
    ?>
      <div class="cardx card-pad mt-2">
        <form method="get" class="form-grid">
          <input type="hidden" name="tab" value="hakedis">
          <div class="field"><label>Müşteri seç (hakediş tanımla)</label>
            <select class="selectx" name="musteri" onchange="this.form.submit()">
              <option value="0">— Müşteri seçin —</option>
              <?php foreach ($uretim as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $selCust === (int) $c['id'] ? 'selected' : '' ?>><?= Helpers::e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      </div>

      <?php if ($selCustomer): ?>
        <div class="section-head mt-3"><h2><?= Helpers::e($selCustomer['name']) ?> hakedişleri</h2></div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
          <input type="hidden" name="action" value="save_entitlement">
          <input type="hidden" name="customer_id" value="<?= $selCust ?>">
          <div class="cardx card-pad">
            <p class="row-meta mb-2">Her kalemden bu müşterinin standing hakkı. Boş/0 = hakediş yok.</p>
            <?php foreach ($catalog as $it): $cur = $ent[(int) $it['id']] ?? 0.0; ?>
              <div class="supply-row">
                <div><div class="s-name"><?= Helpers::e($it['ad']) ?></div><div class="s-meta"><?= Helpers::e($it['birim']) ?></div></div>
                <input class="inputx qty-input" type="number" step="0.01" min="0" name="miktar[<?= (int) $it['id'] ?>]" value="<?= $cur > 0 ? rtrim(rtrim(number_format($cur, 2, '.', ''), '0'), '.') : '' ?>" placeholder="0">
              </div>
            <?php endforeach; ?>
          </div>
          <button class="btn-action btn-primaryx btn-full mt-3" type="submit"><i class="bi bi-check2"></i> Hakedişleri kaydet</button>
        </form>
      <?php else: ?>
        <div class="empty-state mt-2">Hakediş tanımlamak için yukarıdan müşteri seçin.</div>
      <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
