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

$meals = ['sabah' => 'Sabah', 'ogle' => 'Öğle', 'aksam' => 'Akşam', 'gece' => 'Gece', 'kumanya' => 'Kumanya'];
$flash = '';
$flashOk = true;
$editId = (int) ($_GET['edit'] ?? 0) ?: null;
$formOpen = isset($_GET['yeni']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $action = (string) ($_POST['action'] ?? 'save_menu');
        $menuId = (int) ($_POST['menu_id'] ?? 0) ?: null;

        if ($action === 'save_menu') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $dStart = (string) ($_POST['date_start'] ?? '');
            $dEnd = (string) ($_POST['date_end'] ?? '');
            $audience = ($_POST['audience'] ?? 'all') === 'selected' ? 'selected' : 'all';
            $targets = array_map('intval', (array) ($_POST['targets'] ?? []));
            if ($title === '' || !Helpers::isDate($dStart) || !Helpers::isDate($dEnd)) {
                $flash = 'Başlık ve geçerli tarih aralığı zorunlu.';
                $flashOk = false;
                $formOpen = true;
            } elseif ($dEnd < $dStart) {
                $flash = 'Bitiş tarihi başlangıçtan önce olamaz.';
                $flashOk = false;
                $formOpen = true;
            } else {
                try {
                    $pdo->beginTransaction();
                    $mid = $repo->upsertMenu($title, $dStart, $dEnd, $audience, $menuId);
                    $repo->setMenuAudience($mid, $audience, $targets);
                    $pdo->commit();
                    uysa_audit('menu_kaydet', $u['username'], (string) $mid, json_encode(['aud' => $audience]), client_ip());
                    $flash = 'Menü kaydedildi · ' . $title;
                    $editId = $mid; // kaydettikten sonra gün/öğün eklemeye geç
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $flash = 'Menü kaydedilemedi.';
                    $flashOk = false;
                    $formOpen = true;
                }
            }
        } elseif ($action === 'add_item' && $menuId) {
            $itemDate = (string) ($_POST['item_date'] ?? '');
            $meal = (string) ($_POST['meal'] ?? 'ogle');
            $dishes = trim((string) ($_POST['dishes'] ?? ''));
            if (!Helpers::isDate($itemDate) || $dishes === '') {
                $flash = 'Gün ve yemek listesi zorunlu.';
                $flashOk = false;
            } else {
                $repo->upsertMenuItem($menuId, $itemDate, $meal, mb_substr($dishes, 0, 1000));
                $flash = 'Gün eklendi.';
            }
            $editId = $menuId;
        } elseif ($action === 'del_item' && $menuId) {
            $repo->deleteMenuItem((int) ($_POST['item_id'] ?? 0), $menuId);
            $flash = 'Gün silindi.';
            $editId = $menuId;
        } elseif ($action === 'publish' && $menuId) {
            $repo->publishMenu($menuId, true);
            uysa_audit('menu_yayinla', $u['username'], (string) $menuId, null, client_ip());
            $flash = 'Menü yayınlandı — hedef müşteriler görebilir.';
            $editId = $menuId;
        } elseif ($action === 'unpublish' && $menuId) {
            $repo->publishMenu($menuId, false);
            $flash = 'Menü taslağa alındı.';
            $editId = $menuId;
        }
    }
}

$menus = $repo->listMenus();
$uretimCustomers = $repo->listCustomersByCategory('uretim');

$edit = $editId ? $repo->menu($editId) : null;
$editItems = $edit ? $repo->menuItems($editId) : [];
$editTargets = $edit ? $repo->menuTargets($editId) : [];
$fTitle = $edit['title'] ?? '';
$fStart = $edit['date_start'] ?? Helpers::today();
$fEnd = $edit['date_end'] ?? date('Y-m-d', strtotime('+6 day'));
$fAud = $edit['audience'] ?? 'all';
if ($edit) {
    $formOpen = false; // düzenlemede header formu ayrı kart, item yönetimi altında
}

$csrf = Helpers::csrfToken();
$eyebrow = 'Menü yayınlama';
$pageTitle = $edit ? 'Menü düzenle' : 'Menüler';
$active = 'menu';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

<?php if ($edit): ?>
      <a class="btn-action btn-secondaryx mb-2" href="menu.php"><i class="bi bi-arrow-left"></i> Tüm menüler</a>

      <!-- Menü başlığı + hedef -->
      <div class="cardx card-pad">
        <div class="d-flex align-items-center justify-between gap-2 mb-2">
          <h2 style="margin:0"><?= Helpers::e($edit['title']) ?></h2>
          <span class="badge-soft <?= $edit['status'] === 'published' ? 'badge-ok' : 'badge-warn' ?>">
            <i class="bi <?= $edit['status'] === 'published' ? 'bi-broadcast' : 'bi-pencil' ?>"></i>
            <?= $edit['status'] === 'published' ? 'Yayında' : 'Taslak' ?>
          </span>
        </div>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
          <input type="hidden" name="action" value="save_menu">
          <input type="hidden" name="menu_id" value="<?= (int) $edit['id'] ?>">
          <div class="field"><label>Başlık</label>
            <input class="inputx" name="title" value="<?= Helpers::e($fTitle) ?>" required>
          </div>
          <div class="actions-row">
            <div class="field flex-fill"><label>Başlangıç</label>
              <input class="inputx" type="date" name="date_start" value="<?= Helpers::e($fStart) ?>" required>
            </div>
            <div class="field flex-fill"><label>Bitiş</label>
              <input class="inputx" type="date" name="date_end" value="<?= Helpers::e($fEnd) ?>" required>
            </div>
          </div>
          <div class="field"><label>Hedef</label>
            <div class="segmented">
              <button class="chip <?= $fAud === 'all' ? 'active' : '' ?>" type="button" onclick="setAud(this,'all')">Tüm müşteriler</button>
              <button class="chip <?= $fAud === 'selected' ? 'active' : '' ?>" type="button" onclick="setAud(this,'selected')">Seçili müşteriler</button>
            </div>
            <input type="hidden" name="audience" id="aud-input" value="<?= Helpers::e($fAud) ?>">
          </div>
          <div id="targets-box" style="<?= $fAud === 'selected' ? '' : 'display:none' ?>">
            <p class="row-meta mb-2">Bu menüyü sadece işaretli müşteriler görür:</p>
            <div class="check-list">
              <?php foreach ($uretimCustomers as $c): ?>
                <label class="check-row">
                  <input type="checkbox" name="targets[]" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $editTargets, true) ? 'checked' : '' ?>>
                  <span><?= Helpers::e($c['name']) ?></span>
                </label>
              <?php endforeach; ?>
              <?php if (!$uretimCustomers): ?><p class="row-meta">Üretim müşterisi yok.</p><?php endif; ?>
            </div>
          </div>
          <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-check2"></i> Başlığı/hedefi kaydet</button>
        </form>
      </div>

      <!-- Gün × öğün yemek listesi -->
      <div class="section-head mt-3"><h2>Günler & yemekler</h2><span class="text-muted" style="font-size:12px"><?= count($editItems) ?> gün</span></div>
      <div class="cardx card-pad">
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
          <input type="hidden" name="action" value="add_item">
          <input type="hidden" name="menu_id" value="<?= (int) $edit['id'] ?>">
          <div class="actions-row">
            <div class="field flex-fill"><label>Gün</label>
              <input class="inputx" type="date" name="item_date" value="<?= Helpers::e($fStart) ?>" min="<?= Helpers::e($fStart) ?>" max="<?= Helpers::e($fEnd) ?>" required>
            </div>
            <div class="field" style="width:120px"><label>Öğün</label>
              <select class="selectx" name="meal">
                <?php foreach ($meals as $mk => $ml): ?><option value="<?= $mk ?>" <?= $mk === 'ogle' ? 'selected' : '' ?>><?= Helpers::e($ml) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="field"><label>Yemekler (virgülle)</label>
            <input class="inputx" name="dishes" placeholder="Mercimek Çorbası, Etli Nohut, Pilav, Salata" required>
          </div>
          <button class="btn-action btn-secondaryx btn-full" type="submit"><i class="bi bi-plus-lg"></i> Gün ekle / güncelle</button>
        </form>
      </div>

      <?php if (!$editItems): ?>
        <div class="empty-state">Henüz gün eklenmedi. Yukarıdan gün × öğün yemek listesi ekleyin.</div>
      <?php else: ?>
        <div class="list-groupx">
          <?php foreach ($editItems as $it): ?>
            <div class="cardx card-pad">
              <div class="d-flex align-items-start justify-between gap-2">
                <div style="min-width:0">
                  <p class="label"><?= Helpers::e(gun_label_tr($it['item_date'])) ?> · <?= Helpers::e($meals[$it['meal']] ?? $it['meal']) ?></p>
                  <h2 style="margin:2px 0 0; font-size:15px"><?= Helpers::e($it['dishes']) ?></h2>
                </div>
                <form method="post" onsubmit="return confirm('Bu gün silinsin mi?');" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
                  <input type="hidden" name="action" value="del_item">
                  <input type="hidden" name="menu_id" value="<?= (int) $edit['id'] ?>">
                  <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                  <button class="icon-btn" type="submit" aria-label="Sil"><i class="bi bi-trash"></i></button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Yayınla / taslağa al -->
      <div class="actions-row mt-3">
        <?php if ($edit['status'] === 'published'): ?>
          <form method="post" class="flex-fill">
            <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
            <input type="hidden" name="action" value="unpublish">
            <input type="hidden" name="menu_id" value="<?= (int) $edit['id'] ?>">
            <button class="btn-action btn-secondaryx btn-full" type="submit"><i class="bi bi-pause-circle"></i> Taslağa al</button>
          </form>
        <?php else: ?>
          <form method="post" class="flex-fill" onsubmit="return <?= $editItems ? 'true' : "confirm('Gün eklenmemiş menü yayınlansın mı?')" ?>;">
            <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
            <input type="hidden" name="action" value="publish">
            <input type="hidden" name="menu_id" value="<?= (int) $edit['id'] ?>">
            <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-broadcast"></i> Yayınla</button>
          </form>
        <?php endif; ?>
      </div>

<?php else: ?>
      <?php if (!$formOpen): ?>
        <a class="btn-action btn-primaryx btn-full" href="menu.php?yeni=1"><i class="bi bi-plus-lg"></i> Yeni menü</a>
      <?php endif; ?>

      <div class="fab-sheet" id="menu-form" style="<?= $formOpen ? '' : 'display:none' ?>">
        <h2>Yeni menü</h2>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
          <input type="hidden" name="action" value="save_menu">
          <div class="field"><label>Başlık</label>
            <input class="inputx" name="title" placeholder="ör. 6 Temmuz Haftası" required>
          </div>
          <div class="actions-row">
            <div class="field flex-fill"><label>Başlangıç</label>
              <input class="inputx" type="date" name="date_start" value="<?= Helpers::e($fStart) ?>" required>
            </div>
            <div class="field flex-fill"><label>Bitiş</label>
              <input class="inputx" type="date" name="date_end" value="<?= Helpers::e($fEnd) ?>" required>
            </div>
          </div>
          <div class="field"><label>Hedef</label>
            <div class="segmented">
              <button class="chip active" type="button" onclick="setAud(this,'all')">Tüm müşteriler</button>
              <button class="chip" type="button" onclick="setAud(this,'selected')">Seçili müşteriler</button>
            </div>
            <input type="hidden" name="audience" id="aud-input" value="all">
          </div>
          <div id="targets-box" style="display:none">
            <p class="row-meta mb-2">Bu menüyü sadece işaretli müşteriler görür:</p>
            <div class="check-list">
              <?php foreach ($uretimCustomers as $c): ?>
                <label class="check-row"><input type="checkbox" name="targets[]" value="<?= (int) $c['id'] ?>"><span><?= Helpers::e($c['name']) ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="actions-row">
            <a class="btn-action btn-ghost flex-fill" href="menu.php">Vazgeç</a>
            <button class="btn-action btn-primaryx flex-fill" type="submit"><i class="bi bi-check2"></i> Oluştur</button>
          </div>
        </form>
      </div>

      <div class="section-head mt-3"><h2>Menüler</h2><span class="text-muted" style="font-size:12px"><?= count($menus) ?> menü</span></div>
      <?php if (!$menus): ?>
        <div class="empty-state">Henüz menü yok. "Yeni menü" ile başlayın.</div>
      <?php else: ?>
        <div class="list-groupx">
          <?php foreach ($menus as $m): ?>
            <a class="cardx card-pad" href="menu.php?edit=<?= (int) $m['id'] ?>" style="display:block">
              <div class="d-flex align-items-center justify-between gap-2">
                <div style="min-width:0">
                  <div class="row-title"><strong><?= Helpers::e($m['title']) ?></strong></div>
                  <p class="row-meta">
                    <?= date('d.m', strtotime($m['date_start'])) ?> – <?= date('d.m.Y', strtotime($m['date_end'])) ?>
                    · <?= (int) $m['item_count'] ?> gün
                    · <?= $m['audience'] === 'all' ? 'Tüm müşteriler' : ((int) $m['target_count'] . ' müşteri') ?>
                  </p>
                </div>
                <span class="badge-soft <?= $m['status'] === 'published' ? 'badge-ok' : 'badge-warn' ?>">
                  <i class="bi <?= $m['status'] === 'published' ? 'bi-broadcast' : 'bi-pencil' ?>"></i>
                  <?= $m['status'] === 'published' ? 'Yayında' : 'Taslak' ?>
                </span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
<?php endif; ?>

      <script>
        function setAud(btn, aud){
          document.getElementById('aud-input').value = aud;
          btn.parentNode.querySelectorAll('.chip').forEach(function(c){c.classList.remove('active');});
          btn.classList.add('active');
          document.getElementById('targets-box').style.display = (aud === 'selected') ? 'block' : 'none';
        }
      </script>
<?php require __DIR__ . '/partials/footer.php'; ?>
