<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Env;
use Uysa\Helpers;
use Uysa\Repo;

$u = Auth::requireLogin();
$pdo = Db::pdo();
$repo = new Repo($pdo);

$month = (string) ($_GET['ay'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$filter = $_GET['tur'] ?? 'all';
if (!in_array($filter, ['all', 'gelir', 'gider'], true)) {
    $filter = 'all';
}
$flash = '';
$flashOk = true;

$CATS = ['Et/Tavuk', 'Sebze-Meyve', 'Kuru Gıda', 'Ambalaj', 'Yakıt', 'Personel', 'Kira', 'Diğer'];

// ── Hızlı ekle ───────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $type = in_array($_POST['type'] ?? '', ['gelir', 'gider'], true) ? $_POST['type'] : 'gider';
        $amount = (float) str_replace([',', '₺', ' '], ['.', '', ''], (string) ($_POST['amount'] ?? '0'));
        $txDate = (string) ($_POST['tx_date'] ?? Helpers::today());
        if (!Helpers::isDate($txDate)) {
            $txDate = Helpers::today();
        }
        $category = trim((string) ($_POST['category'] ?? ''));
        $desc = trim((string) ($_POST['description'] ?? ''));
        $customerId = (int) ($_POST['customer_id'] ?? 0) ?: null;
        $supplierId = (int) ($_POST['supplier_id'] ?? 0) ?: null;

        if ($amount <= 0) {
            $flash = 'Tutar sıfırdan büyük olmalı.';
            $flashOk = false;
        } else {
            $fileId = null;
            if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $fileId = handleInvoiceUpload($_FILES['photo'], $repo, $u['username'], $flash);
                if ($flash !== '') {
                    $flashOk = false;
                }
            }
            if ($flashOk) {
                $repo->addTransaction($type, $amount, $txDate, $category ?: null, $desc ?: null, $customerId, $supplierId, $fileId);
                uysa_audit('finans_ekle', $u['username'], $type, json_encode(['t' => $amount]), client_ip());
                $flash = ucfirst($type) . ' eklendi · ₺ ' . Helpers::money($amount);
                $month = substr($txDate, 0, 7);
            }
        }
    }
}

function handleInvoiceUpload(array $file, Repo $repo, string $by, string &$flash): ?int
{
    $maxMb = Env::int('UPLOAD_MAX_MB', 25);
    if ($file['size'] > $maxMb * 1024 * 1024) {
        $flash = "Dosya $maxMb MB limitini aşıyor.";
        return null;
    }
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    $orig = basename($file['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        $flash = "İzin verilmeyen uzantı: .$ext";
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedMime, true)) {
        $flash = "İzin verilmeyen dosya türü: $mime";
        return null;
    }
    $dir = Env::get('UPLOAD_DIR') ?: __DIR__ . '/uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $safe = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $safe)) {
        $flash = 'Dosya kaydedilemedi.';
        return null;
    }
    return $repo->addFile($safe, $orig, $mime, (int) $file['size'], $by, 'fatura');
}

$fin = $repo->monthFinanceTotals($month);
$tasimaTot = $repo->monthTasimaTotals($month);
$txs = $repo->transactionsForMonth($month);
$customers = $repo->activeCustomers();
$suppliers = $repo->activeSuppliers();

// Filtre + güne göre grupla
$byDay = [];
foreach ($txs as $t) {
    if ($filter !== 'all' && $t['type'] !== $filter) {
        continue;
    }
    $byDay[$t['tx_date']][] = $t;
}

$eyebrow = ay_label_tr($month);
$pageTitle = 'Finans';
$active = 'finans';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <form method="get" class="date-row">
        <div class="date-pill"><i class="bi bi-calendar2-week"></i>
          <input type="month" name="ay" value="<?= Helpers::e($month) ?>" onchange="this.form.submit()">
        </div>
      </form>

      <div class="summary-grid">
        <div class="summary-card tint-green"><p class="label">Gelir</p><p class="metric">₺ <?= Helpers::money($fin['gelir']) ?></p></div>
        <div class="summary-card tint-orange"><p class="label">Gider</p><p class="metric">₺ <?= Helpers::money($fin['gider']) ?></p></div>
        <div class="summary-card wide"><p class="label">Net nakit</p><p class="metric <?= $fin['net'] < 0 ? 'neg' : '' ?>">₺ <?= Helpers::money($fin['net']) ?></p></div>
      </div>

      <?php if ($tasimaTot['satis'] > 0 || $tasimaTot['gider'] > 0): ?>
      <div class="cardx card-pad">
        <h2>Taşıma karlılığı <span class="text-muted" style="font-size:12px;font-weight:600">(üretim cirosundan ayrı)</span></h2>
        <table class="tablex">
          <tbody>
            <tr><td>Taşıma satış / hakediş</td><td class="num">₺ <?= Helpers::money($tasimaTot['satis']) ?></td></tr>
            <tr><td>Taşıma sabit gider</td><td class="num">₺ <?= Helpers::money($tasimaTot['gider']) ?></td></tr>
            <tr class="is-total"><td>Taşıma kâr</td><td class="num" style="color:<?= $tasimaTot['kar'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($tasimaTot['kar']) ?></td></tr>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <div class="segmented">
        <a class="chip <?= $filter === 'all' ? 'active' : '' ?>" href="finans.php?ay=<?= $month ?>&tur=all">Tümü</a>
        <a class="chip <?= $filter === 'gelir' ? 'active' : '' ?>" href="finans.php?ay=<?= $month ?>&tur=gelir">Gelir</a>
        <a class="chip <?= $filter === 'gider' ? 'active' : '' ?>" href="finans.php?ay=<?= $month ?>&tur=gider">Gider</a>
      </div>

      <?php if (!$byDay): ?>
        <div class="empty-state">
          Bu ay henüz kayıt yok.
          <div class="mt-3"><button class="btn-action btn-secondaryx" type="button" onclick="toggleSheet('add-sheet')">Gider / gelir ekle</button></div>
        </div>
      <?php else: ?>
        <?php foreach ($byDay as $day => $items): ?>
          <div class="cardx card-pad">
            <h2><?= Helpers::e(gun_label_tr($day)) ?></h2>
            <div class="list-groupx">
              <?php foreach ($items as $t):
                  $isGelir = $t['type'] === 'gelir';
                  $party = $t['customer_name'] ?: $t['supplier_name'] ?: '';
                  $meta = $t['description'] ?: ($party ? ($isGelir ? 'Müşteri: ' : 'Tedarikçi: ') . $party : '');
                  if ($t['file_id']) { $meta = trim($meta . ' · fatura fotoğrafı eklendi', ' ·'); }
              ?>
                <div class="flow-item">
                  <span class="flow-icon <?= $isGelir ? '' : 'out' ?>"><i class="bi <?= $isGelir ? 'bi-arrow-down-left' : 'bi-arrow-up-right' ?>"></i></span>
                  <div>
                    <strong><?= Helpers::e($t['category'] ?: ($isGelir ? 'Gelir' : 'Gider')) ?><?= $party ? ' · ' . Helpers::e($party) : '' ?></strong>
                    <p class="row-meta"><?= Helpers::e($meta ?: '—') ?></p>
                  </div>
                  <span class="amount <?= $isGelir ? 'in' : 'out' ?>">₺ <?= Helpers::money((float) $t['amount']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="fab-sheet" id="add-sheet">
        <h2>Hızlı ekle</h2>
        <form method="post" enctype="multipart/form-data" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <div class="segmented">
            <button class="chip active" type="button" onclick="setType(this,'gider')">Gider</button>
            <button class="chip" type="button" onclick="setType(this,'gelir')">Gelir</button>
          </div>
          <input type="hidden" name="type" id="tx-type" value="gider">
          <div class="field"><label>Tutar (₺)</label><input class="inputx" name="amount" inputmode="decimal" placeholder="0,00" required></div>
          <div class="field"><label>Tarih</label><input class="inputx" type="date" name="tx_date" value="<?= Helpers::e(Helpers::today()) ?>"></div>
          <div class="field"><label>Kategori</label>
            <select class="selectx" name="category"><option value="">—</option>
              <?php foreach ($CATS as $c): ?><option><?= Helpers::e($c) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Müşteri (gelir)</label>
            <select class="selectx" name="customer_id"><option value="">—</option>
              <?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>"><?= Helpers::e($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Tedarikçi (gider)</label>
            <select class="selectx" name="supplier_id"><option value="">—</option>
              <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>"><?= Helpers::e($s['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Açıklama</label><input class="inputx" name="description" placeholder="opsiyonel"></div>
          <div class="field"><label>Fatura foto (jpg/png/pdf)</label><input class="inputx" type="file" name="photo" accept="image/*,.pdf"></div>
          <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-plus-lg"></i> Kaydet</button>
        </form>
      </div>

      <script>
        function setType(btn, v){
          document.getElementById('tx-type').value = v;
          btn.parentNode.querySelectorAll('.chip').forEach(function(c){c.classList.remove('active');});
          btn.classList.add('active');
        }
      </script>
<?php require __DIR__ . '/partials/footer.php'; ?>
