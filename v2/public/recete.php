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

// Görünüm: reçete detay (?recete=ID) · malzeme fiyat listesi (?malzemeler=1) · reçete listesi (varsayılan)
$recipeId = (int) ($_GET['recete'] ?? 0) ?: null;
$view = isset($_GET['malzemeler']) ? 'malzemeler' : ($recipeId ? 'detay' : 'liste');
$search = trim((string) ($_GET['q'] ?? ''));

// ── POST işlemleri (CSRF) ────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } elseif ($action === 'fiyat') {
        $iid = (int) ($_POST['ingredient_id'] ?? 0);
        $price = Helpers::parseMoney((string) ($_POST['price'] ?? '0'));
        if ($iid > 0) {
            $repo->upsertIngredientPrice($iid, $price);
            uysa_audit('malzeme_fiyat', $u['username'], (string) $iid, json_encode(['p' => $price]), client_ip());
            $flash = 'Fiyat güncellendi — maliyet yeniden hesaplandı.';
        }
    } elseif ($action === 'recete_yeni') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $cat = trim((string) ($_POST['category'] ?? '')) ?: null;
        if ($name === '') {
            $flash = 'Reçete adı zorunlu.';
            $flashOk = false;
        } else {
            try {
                $recipeId = $repo->upsertRecipe($name, $cat);
                uysa_audit('recete_yeni', $u['username'], (string) $recipeId, null, client_ip());
                $flash = 'Reçete oluşturuldu · ' . $name;
                $view = 'detay';
            } catch (\Throwable $e) {
                $flash = 'Kayıt hatası (reçete adı benzersiz olmalı).';
                $flashOk = false;
            }
        }
    } elseif ($action === 'recete_kalem') {
        $rid = (int) ($_POST['recipe_id'] ?? 0);
        $iid = (int) ($_POST['ingredient_id'] ?? 0);
        $grams = (float) str_replace(',', '.', (string) ($_POST['grams'] ?? '0'));
        if ($rid > 0 && $iid > 0 && $grams > 0) {
            $repo->upsertRecipeItem($rid, $iid, $grams);
            uysa_audit('recete_kalem', $u['username'], "$rid:$iid", json_encode(['g' => $grams]), client_ip());
            $flash = 'Malzeme eklendi / gramaj güncellendi.';
            $recipeId = $rid;
            $view = 'detay';
        } else {
            $flash = 'Malzeme ve gramaj (0\'dan büyük) gerekli.';
            $flashOk = false;
            $recipeId = $rid ?: $recipeId;
            $view = 'detay';
        }
    } elseif ($action === 'kalem_sil') {
        $rid = (int) ($_POST['recipe_id'] ?? 0);
        $itemId = (int) ($_POST['item_id'] ?? 0);
        if ($rid > 0 && $itemId > 0) {
            $repo->deleteRecipeItem($itemId, $rid);
            $flash = 'Malzeme çıkarıldı.';
            $recipeId = $rid;
            $view = 'detay';
        }
    }
}

$pageTitle = 'Reçete & Maliyet';
$eyebrow = 'Porsiyon maliyeti';
$active = '';

// ══════════════════════════════════════════════════════════════
// DETAY: bir reçetenin malzeme×gramaj + porsiyon maliyeti + karlılık
// ══════════════════════════════════════════════════════════════
if ($view === 'detay' && $recipeId) {
    $recipe = $repo->recipe($recipeId);
    if (!$recipe) {
        header('Location: recete.php');
        exit;
    }
    $items = $repo->recipeItems($recipeId);
    $cost = $repo->recipeCost($recipeId);
    $ingredients = $repo->listIngredients();
    $uretim = $repo->listCustomersByCategory('uretim');
    $pageTitle = $recipe['name'];
    $eyebrow = 'Reçete · porsiyon maliyeti';
    require __DIR__ . '/partials/header.php';
    ?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>
      <a class="btn-action btn-ghost" href="recete.php"><i class="bi bi-arrow-left"></i> Reçetelere dön</a>

      <div class="summary-grid">
        <div class="summary-card wide tint-orange">
          <p class="label">Porsiyon maliyeti (Σ gram/1000 × birim fiyat)</p>
          <p class="metric">₺ <?= Helpers::money($cost) ?></p>
        </div>
      </div>

      <div class="cardx card-pad">
        <h2>Malzemeler <span class="text-muted" style="font-size:12px;font-weight:600">(<?= count($items) ?> kalem)</span></h2>
        <?php if (!$items): ?>
          <div class="empty-state">
            <div class="es-ico"><i class="bi bi-clipboard2-check"></i></div>
            Bu reçetede henüz malzeme yok. Aşağıdan ekleyin.</div>
        <?php else: ?>
          <div style="overflow-x:auto">
          <table class="tablex">
            <thead><tr><th>Malzeme</th><th class="num">Gram</th><th class="num">₺/kg</th><th class="num">Maliyet</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
              <tr>
                <td><?= Helpers::e($it['name']) ?></td>
                <td class="num"><?= number_format((float) $it['grams'], 0, ',', '.') ?></td>
                <td class="num"><?= Helpers::money((float) $it['price_per_unit']) ?></td>
                <td class="num">₺ <?= Helpers::money((float) $it['line_cost']) ?></td>
                <td class="num">
                  <form method="post" style="display:inline" onsubmit="return confirm('Bu malzeme çıkarılsın mı?');">
                    <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
                    <input type="hidden" name="action" value="kalem_sil">
                    <input type="hidden" name="recipe_id" value="<?= $recipeId ?>">
                    <input type="hidden" name="item_id" value="<?= (int) $it['item_id'] ?>">
                    <button class="icon-btn" type="submit" aria-label="Çıkar" style="width:34px;min-height:34px"><i class="bi bi-x-lg"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
              <tr class="is-total"><td>Porsiyon toplam</td><td></td><td></td><td class="num">₺ <?= Helpers::money($cost) ?></td><td></td></tr>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>

      <div class="fab-sheet">
        <h2>Malzeme ekle / gramaj güncelle</h2>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <input type="hidden" name="action" value="recete_kalem">
          <input type="hidden" name="recipe_id" value="<?= $recipeId ?>">
          <div class="field"><label>Malzeme</label>
            <select class="selectx" name="ingredient_id" required>
              <option value="">— seç —</option>
              <?php foreach ($ingredients as $ing): ?>
                <option value="<?= (int) $ing['id'] ?>"><?= Helpers::e($ing['name']) ?> (₺ <?= Helpers::money((float) $ing['price_per_unit']) ?>/<?= Helpers::e($ing['unit']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Gramaj (porsiyon başı, g)</label>
            <input class="inputx" name="grams" inputmode="decimal" placeholder="ör. 150" required>
          </div>
          <button class="btn-action btn-primaryx" type="submit"><i class="bi bi-plus-lg"></i> Ekle / güncelle</button>
        </form>
      </div>

      <?php if ($uretim): ?>
      <div class="section-head"><h2>Müşteri karlılığı</h2><span class="text-muted" style="font-size:12px">birim fiyat − maliyet</span></div>
      <div class="cardx card-pad">
        <p class="row-meta" style="margin-bottom:10px">Bu porsiyon maliyetine göre üretim müşterisi birim kârı (kişi başı fiyat − maliyet).</p>
        <div style="overflow-x:auto">
        <table class="tablex">
          <thead><tr><th>Müşteri</th><th class="num">Birim fiyat</th><th class="num">Maliyet</th><th class="num">Birim kâr</th></tr></thead>
          <tbody>
          <?php foreach ($uretim as $c): $price = (float) $c['unit_price']; $kar = $price - $cost; ?>
            <tr>
              <td><?= Helpers::e($c['name']) ?></td>
              <td class="num">₺ <?= Helpers::money($price) ?></td>
              <td class="num">₺ <?= Helpers::money($cost) ?></td>
              <td class="num" style="color:<?= $kar < 0 ? 'var(--red)' : 'var(--green)' ?>;font-weight:800">₺ <?= Helpers::money($kar) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
      <?php endif; ?>
<?php
    require __DIR__ . '/partials/footer.php';
    return;
}

// ══════════════════════════════════════════════════════════════
// MALZEMELER: fiyat listesi + inline fiyat düzenle
// ══════════════════════════════════════════════════════════════
if ($view === 'malzemeler') {
    $ingredients = $repo->listIngredients($search ?: null);
    $editId = (int) ($_GET['edit'] ?? 0) ?: null;
    $eyebrow = 'Malzeme fiyat listesi';
    $pageTitle = 'Malzemeler';
    require __DIR__ . '/partials/header.php';
    ?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>
      <a class="btn-action btn-ghost" href="recete.php"><i class="bi bi-arrow-left"></i> Reçetelere dön</a>

      <form method="get" class="date-row">
        <input type="hidden" name="malzemeler" value="1">
        <div class="date-pill" style="flex:1"><i class="bi bi-search"></i>
          <input type="text" name="q" value="<?= Helpers::e($search) ?>" placeholder="Malzeme ara…" style="flex:1">
        </div>
        <button class="btn-action btn-secondaryx" type="submit">Ara</button>
      </form>

      <div class="cardx card-pad">
        <h2>Malzeme fiyatları <span class="text-muted" style="font-size:12px;font-weight:600">(<?= count($ingredients) ?>)</span></h2>
        <?php if (!$ingredients): ?>
          <div class="empty-state">
            <div class="es-ico"><i class="bi bi-box-seam"></i></div>
            Malzeme bulunamadı.</div>
        <?php else: foreach ($ingredients as $ing): $iid = (int) $ing['id']; ?>
          <div class="customer-row">
            <div>
              <div class="row-title"><span class="status-dot"></span><strong><?= Helpers::e($ing['name']) ?></strong></div>
              <p class="row-meta">₺ <?= Helpers::money((float) $ing['price_per_unit']) ?> / <?= Helpers::e($ing['unit']) ?></p>
            </div>
            <?php if ($editId === $iid): ?>
              <form method="post" class="actions-row" style="justify-content:flex-end">
                <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
                <input type="hidden" name="action" value="fiyat">
                <input type="hidden" name="ingredient_id" value="<?= $iid ?>">
                <input class="inputx" name="price" inputmode="decimal" value="<?= Helpers::money((float) $ing['price_per_unit']) ?>" style="min-height:38px;padding:6px 8px;text-align:right" autofocus>
                <button class="icon-btn" type="submit" aria-label="Kaydet" style="width:38px;min-height:38px"><i class="bi bi-check2"></i></button>
              </form>
            <?php else: ?>
              <div class="actions-row" style="justify-content:flex-end">
                <a class="icon-btn" href="recete.php?malzemeler=1&edit=<?= $iid ?><?= $search ? '&q=' . urlencode($search) : '' ?>" aria-label="Fiyat düzenle"><i class="bi bi-pencil"></i></a>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
<?php
    require __DIR__ . '/partials/footer.php';
    return;
}

// ══════════════════════════════════════════════════════════════
// LİSTE: reçeteler + porsiyon maliyeti (adına tıkla → detay)
// ══════════════════════════════════════════════════════════════
$recipes = $repo->listRecipes($search ?: null);
$ingCount = (int) $pdo->query('SELECT COUNT(*) FROM ingredients')->fetchColumn();
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <div class="mod-grid" style="margin-bottom:2px">
        <a class="mod-card i-blue" href="recete.php?malzemeler=1">
          <div class="mico"><i class="bi bi-basket3"></i></div>
          <div class="mt">Malzeme fiyatları</div>
          <div class="md"><?= $ingCount ?> malzeme · fiyat düzenle</div>
        </a>
        <a class="mod-card i-green" href="stok.php">
          <div class="mico"><i class="bi bi-box-seam"></i></div>
          <div class="mt">Stok Durumu</div>
          <div class="md">Giriş/çıkış, kritik uyarı</div>
        </a>
      </div>

      <form method="get" class="date-row">
        <div class="date-pill" style="flex:1"><i class="bi bi-search"></i>
          <input type="text" name="q" value="<?= Helpers::e($search) ?>" placeholder="Reçete ara…" style="flex:1">
        </div>
        <button class="btn-action btn-secondaryx" type="submit">Ara</button>
      </form>

      <div class="fab-sheet" id="yeni-recete" style="display:none">
        <h2>Yeni reçete</h2>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <input type="hidden" name="action" value="recete_yeni">
          <div class="field"><label>Reçete adı</label>
            <input class="inputx" name="name" required autocapitalize="words" placeholder="ör. Etli Nohut">
          </div>
          <div class="field"><label>Kategori (opsiyonel)</label>
            <input class="inputx" name="category" placeholder="ör. ana / çorba / yan">
          </div>
          <button class="btn-action btn-primaryx" type="submit"><i class="bi bi-check2"></i> Oluştur</button>
        </form>
      </div>
      <button class="btn-action btn-primaryx btn-full" type="button" onclick="toggleSheet('yeni-recete')"><i class="bi bi-plus-lg"></i> Yeni reçete</button>

      <div class="section-head"><h2>Reçeteler</h2><span class="text-muted" style="font-size:12px"><?= count($recipes) ?> reçete · adına tıkla</span></div>
      <div class="cardx card-pad">
        <?php if (!$recipes): ?>
          <div class="empty-state">
            <div class="es-ico"><i class="bi bi-clipboard2-check"></i></div>
            <?= $search ? 'Eşleşen reçete yok.' : 'Henüz reçete yok.' ?></div>
        <?php else: foreach ($recipes as $r): ?>
          <a class="customer-row" href="recete.php?recete=<?= (int) $r['id'] ?>" style="color:inherit">
            <div>
              <div class="row-title"><span class="status-dot"></span><strong><?= Helpers::e($r['name']) ?></strong></div>
              <p class="row-meta"><?= (int) $r['item_count'] ?> malzeme<?= $r['category'] ? ' · ' . Helpers::e($r['category']) : '' ?></p>
            </div>
            <div style="text-align:right">
              <div class="amount"><?= (float) $r['cost'] > 0 ? '₺ ' . Helpers::money((float) $r['cost']) : '—' ?></div>
              <p class="row-meta" style="margin-top:2px">porsiyon</p>
            </div>
          </a>
        <?php endforeach; endif; ?>
      </div>
<?php require __DIR__ . '/partials/footer.php'; ?>
