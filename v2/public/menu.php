<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\MenuPdf;
use Uysa\Push;
use Uysa\Repo;
use Uysa\XlsxMenu;

$u = Auth::requireLogin();
$pdo = Db::pdo();
$repo = new Repo($pdo);

$meals = ['sabah' => 'Sabah', 'ogle' => 'Öğle', 'aksam' => 'Akşam', 'gece' => 'Gece', 'kumanya' => 'Kumanya'];
$flash = '';
$flashOk = true;
$editId = (int) ($_GET['edit'] ?? 0) ?: null;
$formOpen = isset($_GET['yeni']);
$preview = null; // Excel yükleme önizlemesi (excel_preview sonrası)

// ── Excel indir (export): seçili menü → haftalık grid xlsx (header'dan ÖNCE) ──
if (isset($_GET['export'])) {
    $mid = (int) $_GET['export'];
    $m = $repo->menu($mid);
    if ($m) {
        $bin = XlsxMenu::write((string) $m['title'], $repo->menuItems($mid));
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="menu-' . (int) $mid . '.xlsx"');
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;
    }
    header('Location: menu.php');
    exit;
}

// ── PDF indir (export): seçili menü → grid PDF (header'dan ÖNCE, opus-019) ──
if (isset($_GET['pdf'])) {
    $mid = (int) $_GET['pdf'];
    $m = $repo->menu($mid);
    if ($m) {
        $bin = MenuPdf::write((string) $m['title'], $repo->menuItems($mid), (string) $m['date_start'], (string) $m['date_end']);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="menu-' . (int) $mid . '.pdf"');
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;
    }
    header('Location: menu.php');
    exit;
}

/**
 * Yüklenen menü xlsx'ini güvenli doğrula + parse et. Hata → $err doldurur, [] döner.
 * @return array{title:string,days:array} veya hata durumunda ['title'=>'','days'=>[]]
 */
function handleMenuXlsx(array $file, string &$err): array
{
    $empty = ['title' => '', 'days' => []];
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $err = 'Dosya yüklenemedi.';
        return $empty;
    }
    $maxMb = \Uysa\Env::int('UPLOAD_MAX_MB', 25);
    if ($file['size'] > $maxMb * 1024 * 1024) {
        $err = "Dosya $maxMb MB limitini aşıyor.";
        return $empty;
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        $err = 'Yalnızca .xlsx dosyası yüklenebilir.';
        return $empty;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($file['tmp_name']);
    $okMime = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip', 'application/x-zip-compressed', 'application/octet-stream',
    ];
    if (!in_array($mime, $okMime, true)) {
        $err = "İzin verilmeyen dosya türü: $mime";
        return $empty;
    }
    try {
        return XlsxMenu::read($file['tmp_name']);
    } catch (\Throwable $e) {
        $err = 'Excel okunamadı: ' . $e->getMessage();
        return $empty;
    }
}

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
            // opus-021: hedef müşterilere push — mükerrer koruması Push içinde; hata yayını KIRMAZ
            try {
                $m = $repo->menu($menuId);
                if ($m) {
                    $targets = $m['audience'] === 'selected'
                        ? $repo->menuTargets($menuId)
                        : array_map(static fn (array $c): int => (int) $c['id'], $repo->activeCustomers());
                    $pr = (new Push($pdo))->menuYayinlandi($menuId, (string) $m['title'], $targets);
                    if ($pr['pushed'] > 0) {
                        $flash .= " · {$pr['pushed']} müşteriye bildirim gitti.";
                    }
                }
            } catch (\Throwable) {
                // push hatası menü yayınını engellemez
            }
            $editId = $menuId;
        } elseif ($action === 'unpublish' && $menuId) {
            $repo->publishMenu($menuId, false);
            $flash = 'Menü taslağa alındı.';
            $editId = $menuId;
        } elseif ($action === 'excel_preview') {
            // Excel yükle → parse → önizleme (henüz DB'ye yazılmaz)
            $targetMenu = (int) ($_POST['target_menu'] ?? 0); // 0 = yeni menü
            $err = '';
            $parsed = handleMenuXlsx($_FILES['xlsx'] ?? [], $err);
            if ($err !== '') {
                $flash = $err;
                $flashOk = false;
                $editId = $targetMenu ?: null;
            } elseif (!$parsed['days']) {
                $flash = 'Excel içinde tarihli menü bulunamadı. Format: her tarih sütununun altında yemekler.';
                $flashOk = false;
                $editId = $targetMenu ?: null;
            } else {
                $dates = array_column($parsed['days'], 'date');
                sort($dates);
                $existing = [];
                if ($targetMenu > 0) {
                    foreach ($repo->menuItems($targetMenu) as $it) {
                        $existing[$it['item_date']] = true;
                    }
                }
                $overwrite = array_values(array_intersect($dates, array_keys($existing)));
                $preview = [
                    'target_menu' => $targetMenu,
                    'title'       => $parsed['title'] !== '' ? $parsed['title'] : 'İçe aktarılan menü',
                    'days'        => $parsed['days'],
                    'date_start'  => $dates[0],
                    'date_end'    => end($dates),
                    'overwrite'   => $overwrite,
                ];
                $editId = $targetMenu ?: null;
            }
        } elseif ($action === 'excel_import') {
            // Önizleme onayı → menü oluştur/güncelle + kalemleri upsert
            $days = json_decode((string) ($_POST['days_json'] ?? ''), true);
            $title = trim((string) ($_POST['title'] ?? ''));
            $targetMenu = (int) ($_POST['target_menu'] ?? 0);
            if (!is_array($days) || !$days) {
                $flash = 'İçe aktarılacak veri bulunamadı.';
                $flashOk = false;
            } else {
                $dates = array_values(array_filter(array_column($days, 'date'), [Helpers::class, 'isDate']));
                sort($dates);
                try {
                    $pdo->beginTransaction();
                    if ($targetMenu > 0 && $repo->menu($targetMenu)) {
                        $mid = $targetMenu;
                    } else {
                        $mid = $repo->upsertMenu(
                            $title !== '' ? $title : 'İçe aktarılan menü',
                            $dates[0] ?? Helpers::today(),
                            end($dates) ?: Helpers::today(),
                            'all'
                        );
                    }
                    $count = $repo->importMenuItems($mid, $days);
                    $pdo->commit();
                    uysa_audit('menu_excel_import', $u['username'], (string) $mid, json_encode(['gun' => $count]), client_ip());
                    $flash = $count . ' gün Excel\'den içe aktarıldı.';
                    $editId = $mid;
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $flash = 'İçe aktarma başarısız oldu.';
                    $flashOk = false;
                }
            }
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

      <div class="quick-tiles">
        <a class="q-tile" href="recete.php"><i class="bi bi-clipboard2-data"></i> Reçete & maliyet</a>
        <a class="q-tile" href="stok.php"><i class="bi bi-box-seam"></i> Stok durumu</a>
      </div>

<?php if ($preview): ?>
      <div class="cardx card-pad">
        <h2><i class="bi bi-file-earmark-excel"></i> Excel önizleme</h2>
        <p class="row-meta">
          <strong><?= count($preview['days']) ?> gün</strong> bulundu ·
          <?= date('d.m.Y', strtotime($preview['date_start'])) ?> – <?= date('d.m.Y', strtotime($preview['date_end'])) ?>
          <?= $preview['target_menu'] ? ' · bu menüye eklenecek' : ' · yeni taslak menü oluşturulacak' ?>
        </p>
        <?php if ($preview['overwrite']): ?>
          <div class="flash err" style="margin:8px 0"><i class="bi bi-exclamation-triangle"></i>
            <?= count($preview['overwrite']) ?> gün zaten var, ÜZERİNE YAZILACAK:
            <?= Helpers::e(implode(', ', array_map(static fn($d) => date('d.m', strtotime($d)), $preview['overwrite']))) ?>
          </div>
        <?php endif; ?>

        <div class="list-groupx" style="max-height:280px;overflow:auto;margin:10px 0">
          <?php foreach ($preview['days'] as $d): ?>
            <div class="flow-item">
              <div>
                <strong><?= Helpers::e(gun_label_tr($d['date'])) ?></strong>
                <p class="row-meta"><?= Helpers::e(implode(' · ', $d['dishes'])) ?></p>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <input type="hidden" name="action" value="excel_import">
          <input type="hidden" name="target_menu" value="<?= (int) $preview['target_menu'] ?>">
          <input type="hidden" name="days_json" value="<?= Helpers::e(json_encode($preview['days'], JSON_UNESCAPED_UNICODE)) ?>">
          <?php if (!$preview['target_menu']): ?>
            <div class="field"><label>Menü başlığı</label>
              <input class="inputx" name="title" value="<?= Helpers::e($preview['title']) ?>" required>
            </div>
          <?php endif; ?>
          <div class="actions-row">
            <a class="btn-action btn-ghost flex-fill" href="menu.php<?= $preview['target_menu'] ? '?edit=' . (int) $preview['target_menu'] : '' ?>">Vazgeç</a>
            <button class="btn-action btn-primaryx flex-fill" type="submit"><i class="bi bi-check2"></i> İçe al</button>
          </div>
        </form>
      </div>
<?php require __DIR__ . '/partials/footer.php'; exit; endif; ?>

<?php if ($edit): ?>
      <a class="btn-action btn-secondaryx mb-2" href="menu.php"><i class="bi bi-arrow-left"></i> Tüm menüler</a>

      <!-- Excel indir / bu menüye yükle -->
      <div class="cardx card-pad">
        <div class="actions-row">
          <a class="btn-action btn-secondaryx flex-fill" href="menu.php?export=<?= (int) $edit['id'] ?>"><i class="bi bi-download"></i> Excel indir</a>
          <a class="btn-action btn-secondaryx flex-fill" href="menu.php?pdf=<?= (int) $edit['id'] ?>"><i class="bi bi-file-earmark-pdf"></i> PDF indir</a>
          <button class="btn-action btn-secondaryx flex-fill" type="button" onclick="document.getElementById('xlsx-up-edit').style.display=(document.getElementById('xlsx-up-edit').style.display==='none'?'block':'none')"><i class="bi bi-upload"></i> Excel yükle</button>
        </div>
        <form method="post" enctype="multipart/form-data" class="form-grid" id="xlsx-up-edit" style="display:none;margin-top:10px">
          <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
          <input type="hidden" name="action" value="excel_preview">
          <input type="hidden" name="target_menu" value="<?= (int) $edit['id'] ?>">
          <p class="row-meta">Haftalık grid xlsx yükle → bu menüye eklenir (aynı gün üzerine yazılır).</p>
          <input class="inputx" type="file" name="xlsx" accept=".xlsx" required>
          <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-eye"></i> Önizle</button>
        </form>
      </div>

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
                  <h2 style="margin:2px 0 0; font-size:15px"><?= nl2br(Helpers::e($it['dishes'])) ?></h2>
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
        <div class="cardx card-pad mt-2">
          <form method="post" enctype="multipart/form-data" class="form-grid">
            <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
            <input type="hidden" name="action" value="excel_preview">
            <input type="hidden" name="target_menu" value="0">
            <label style="font-weight:600"><i class="bi bi-file-earmark-excel"></i> Excel'den menü yükle</label>
            <p class="row-meta">Haftalık grid xlsx (Ömer'in menü dosyası) → önizle → yeni taslak menü oluşur.</p>
            <input class="inputx" type="file" name="xlsx" accept=".xlsx" required>
            <button class="btn-action btn-secondaryx btn-full" type="submit"><i class="bi bi-upload"></i> Yükle & önizle</button>
          </form>
        </div>
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
