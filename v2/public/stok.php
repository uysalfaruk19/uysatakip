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

$flash = '';
$flashOk = true;
$search = trim((string) ($_GET['q'] ?? ''));
$formOpen = isset($_GET['hareket']);
$esikId = (int) ($_GET['esik'] ?? 0) ?: null;

// ── POST işlemleri (CSRF) ────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } elseif ($action === 'hareket') {
        $iid = (int) ($_POST['ingredient_id'] ?? 0);
        $dir = ($_POST['direction'] ?? 'giris') === 'cikis' ? 'cikis' : 'giris';
        $qty = (float) str_replace(',', '.', (string) ($_POST['quantity'] ?? '0'));
        $date = (string) ($_POST['move_date'] ?? Helpers::today());
        if (!Helpers::isDate($date)) {
            $date = Helpers::today();
        }
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        if ($iid > 0 && $qty > 0) {
            $repo->addStockMove($iid, $date, $dir, $qty, null, $note);
            uysa_audit('stok_hareket', $u['username'], (string) $iid, json_encode(['d' => $dir, 'q' => $qty]), client_ip());
            $flash = ($dir === 'giris' ? 'Giriş' : 'Çıkış') . ' kaydedildi — stok güncellendi.';
        } else {
            $flash = 'Malzeme ve miktar (0\'dan büyük) gerekli.';
            $flashOk = false;
            $formOpen = true;
        }
    } elseif ($action === 'esik') {
        $iid = (int) ($_POST['ingredient_id'] ?? 0);
        $min = (float) str_replace(',', '.', (string) ($_POST['min_stok'] ?? '0'));
        if ($iid > 0) {
            $repo->setIngredientMinStock($iid, max(0.0, $min));
            uysa_audit('stok_esik', $u['username'], (string) $iid, json_encode(['m' => $min]), client_ip());
            $flash = 'Kritik eşik güncellendi.';
        }
    }
}

$levels = $repo->stockLevels($search ?: null);
$critical = $repo->criticalStock();
$ingredients = $repo->listIngredients();
$moves = $repo->recentStockMoves(12);

$totalIng = count($ingredients);
$critCount = count($critical);

$pageTitle = 'Stok Durumu';
$eyebrow = 'Malzeme stok takibi';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <div class="stat-stack">
        <div class="stat-card <?= $critCount > 0 ? 'stat-orange' : 'stat-green' ?>">
          <div class="ico"><i class="bi bi-<?= $critCount > 0 ? 'exclamation-triangle-fill' : 'check2-circle' ?>"></i></div>
          <div class="txt">
            <p class="lbl">Kritik stok (eşik altı)</p>
            <p class="val"><?= $critCount ?> <span style="font-size:14px;font-weight:600">malzeme</span></p>
          </div>
        </div>
        <div class="stat-card stat-blue">
          <div class="ico"><i class="bi bi-box-seam"></i></div>
          <div class="txt">
            <p class="lbl">Takip edilen malzeme</p>
            <p class="val"><?= $totalIng ?></p>
          </div>
        </div>
      </div>

      <?php if ($critical): ?>
      <div class="cardx card-pad" style="border-color:var(--red-tint);background:var(--red-tint)">
        <h2 style="color:#b42318"><i class="bi bi-exclamation-triangle-fill"></i> Kritik stok uyarısı</h2>
        <div style="overflow-x:auto">
        <table class="tablex">
          <thead><tr><th>Malzeme</th><th class="num">Stok</th><th class="num">Eşik</th></tr></thead>
          <tbody>
          <?php foreach ($critical as $c): ?>
            <tr>
              <td><strong><?= Helpers::e($c['name']) ?></strong></td>
              <td class="num" style="color:var(--red);font-weight:850"><?= Helpers::money((float) $c['stok']) ?> <?= Helpers::e($c['unit']) ?></td>
              <td class="num"><?= Helpers::money((float) $c['min_stok']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
      <?php endif; ?>

      <!-- Stok hareketi ekle -->
      <div class="fab-sheet" id="hareket-form" style="<?= $formOpen ? '' : 'display:none' ?>">
        <h2>Stok hareketi ekle</h2>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <input type="hidden" name="action" value="hareket">
          <div class="field"><label>Malzeme</label>
            <select class="selectx" name="ingredient_id" required>
              <option value="">— seç —</option>
              <?php foreach ($ingredients as $ing): ?>
                <option value="<?= (int) $ing['id'] ?>"><?= Helpers::e($ing['name']) ?> (<?= Helpers::e($ing['unit']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Yön</label>
            <div class="segmented">
              <button class="chip active" type="button" data-dir="giris" onclick="setDir(this,'giris')">Giriş</button>
              <button class="chip" type="button" data-dir="cikis" onclick="setDir(this,'cikis')">Çıkış</button>
            </div>
            <input type="hidden" name="direction" id="dir-input" value="giris">
          </div>
          <div class="field"><label>Miktar</label>
            <input class="inputx" name="quantity" inputmode="decimal" placeholder="ör. 25" required>
          </div>
          <div class="field"><label>Tarih</label>
            <input class="inputx" type="date" name="move_date" value="<?= Helpers::e(Helpers::today()) ?>">
          </div>
          <div class="field"><label>Not (opsiyonel)</label>
            <input class="inputx" name="note" placeholder="ör. tedarikçi teslimatı">
          </div>
          <div class="actions-row">
            <a class="btn-action btn-ghost flex-fill" href="stok.php">Vazgeç</a>
            <button class="btn-action btn-primaryx flex-fill" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
          </div>
        </form>
      </div>
      <?php if (!$formOpen): ?>
        <a class="btn-action btn-primaryx btn-full" href="stok.php?hareket=1"><i class="bi bi-plus-slash-minus"></i> Stok hareketi ekle</a>
      <?php endif; ?>

      <form method="get" class="date-row">
        <div class="date-pill" style="flex:1"><i class="bi bi-search"></i>
          <input type="text" name="q" value="<?= Helpers::e($search) ?>" placeholder="Malzeme ara…" style="flex:1">
        </div>
        <button class="btn-action btn-secondaryx" type="submit">Ara</button>
      </form>

      <div class="section-head"><h2>Güncel stok</h2><span class="text-muted" style="font-size:12px"><?= count($levels) ?> malzeme</span></div>
      <div class="cardx card-pad">
        <?php if (!$levels): ?>
          <div class="empty-state"><?= $search ? 'Eşleşen malzeme yok.' : 'Malzeme yok.' ?></div>
        <?php else: foreach ($levels as $l): $iid = (int) $l['id']; $stok = (float) $l['stok']; $min = (float) $l['min_stok'];
          $crit = $min > 0 && $stok < $min; ?>
          <div class="customer-row <?= $crit ? 'missing' : '' ?>">
            <div>
              <div class="row-title"><span class="status-dot <?= $crit ? 'warn' : '' ?>"></span><strong><?= Helpers::e($l['name']) ?></strong>
                <?php if ($crit): ?><span class="badge-soft badge-neg">kritik</span><?php endif; ?>
              </div>
              <p class="row-meta">Eşik: <?= $min > 0 ? Helpers::money($min) . ' ' . Helpers::e($l['unit']) : '—' ?></p>
            </div>
            <?php if ($esikId === $iid): ?>
              <form method="post" class="actions-row" style="justify-content:flex-end">
                <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
                <input type="hidden" name="action" value="esik">
                <input type="hidden" name="ingredient_id" value="<?= $iid ?>">
                <input class="inputx" name="min_stok" inputmode="decimal" value="<?= $min > 0 ? Helpers::money($min) : '' ?>" placeholder="eşik" style="min-height:38px;padding:6px 8px;text-align:right;max-width:90px" autofocus>
                <button class="icon-btn" type="submit" aria-label="Kaydet" style="width:38px;min-height:38px"><i class="bi bi-check2"></i></button>
              </form>
            <?php else: ?>
              <div style="text-align:right">
                <div class="amount <?= $crit ? 'out' : 'in' ?>"><?= Helpers::money($stok) ?> <span style="font-size:11px;font-weight:600"><?= Helpers::e($l['unit']) ?></span></div>
                <a class="row-meta" href="stok.php?esik=<?= $iid ?><?= $search ? '&q=' . urlencode($search) : '' ?>" style="color:var(--primary);font-weight:700;margin-top:2px;display:inline-block"><i class="bi bi-sliders"></i> eşik</a>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <?php if ($moves): ?>
      <div class="section-head"><h2>Son hareketler</h2></div>
      <div class="cardx card-pad">
        <div class="list-groupx">
          <?php foreach ($moves as $mv): $in = $mv['direction'] === 'giris'; ?>
            <div class="flow-item">
              <div class="flow-icon <?= $in ? '' : 'out' ?>"><i class="bi bi-<?= $in ? 'box-arrow-in-down' : 'box-arrow-up' ?>"></i></div>
              <div style="min-width:0">
                <div class="row-title"><strong><?= Helpers::e($mv['ingredient_name']) ?></strong></div>
                <p class="row-meta"><?= Helpers::e(date('d.m.Y', strtotime($mv['move_date']))) ?><?= $mv['note'] ? ' · ' . Helpers::e($mv['note']) : '' ?></p>
              </div>
              <div class="amount <?= $in ? 'in' : 'out' ?>"><?= $in ? '+' : '−' ?><?= Helpers::money((float) $mv['quantity']) ?> <?= Helpers::e($mv['unit']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <script>
        function setDir(btn, dir){
          document.getElementById('dir-input').value = dir;
          btn.parentNode.querySelectorAll('.chip').forEach(function(c){c.classList.remove('active');});
          btn.classList.add('active');
        }
      </script>
<?php require __DIR__ . '/partials/footer.php'; ?>
