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
        $amount = Helpers::parseMoney((string) ($_POST['amount'] ?? '0'));
        // Gider → tarih otomatik bugün (form basit). Gelir → seçilen tarih (Paraşüt gelene kadar).
        $txDate = (string) ($_POST['tx_date'] ?? Helpers::today());
        if ($type === 'gider' || !Helpers::isDate($txDate)) {
            $txDate = Helpers::today();
        }
        $category = trim((string) ($_POST['category'] ?? ''));
        $desc = trim((string) ($_POST['description'] ?? ''));
        $customerId = (int) ($_POST['customer_id'] ?? 0) ?: null;
        $supplierId = (int) ($_POST['supplier_id'] ?? 0) ?: null;
        // opus-015: gider dağıtım hedefi (genel / seçili müşteri(ler))
        $allocType = in_array($_POST['alloc_type'] ?? 'genel', ['genel', 'musteri'], true) ? $_POST['alloc_type'] : 'genel';
        $allocIds = array_map('intval', (array) ($_POST['alloc_customer'] ?? []));

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
                $repo->addTransaction($type, $amount, $txDate, $category ?: null, $desc ?: null, $customerId, $supplierId, $fileId, $allocType, $allocIds);
                uysa_audit('finans_ekle', $u['username'], $type, json_encode(['t' => $amount, 'alloc' => $allocType]), client_ip());
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
$nk = $repo->netKarlilik($month);
$kidemTot = $repo->kidemToplamYukumluluk($month);
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

      <div class="quick-tiles">
        <a class="q-tile" href="faturalar.php"><i class="bi bi-receipt"></i> Faturalar</a>
        <a class="q-tile" href="cari.php"><i class="bi bi-cash-coin"></i> Cari & tahsilat</a>
      </div>

      <form method="get" class="date-row">
        <div class="date-pill"><i class="bi bi-calendar2-week"></i>
          <input type="month" name="ay" value="<?= Helpers::e($month) ?>" onchange="this.form.submit()">
        </div>
      </form>

      <?php
      // fable-011: üst kartlar artık OPERASYONEL aylık kâr (Ömer isteği: işlenmiş satışlar
      // gelirde, personel + girilen giderler giderde). Sadece transactions'a bakan eski
      // "nakit" görünümü boş kalıyordu. Kaynak: üretim cirosu + taşıma + elle işlemler.
      $gelirTop = $nk['ciro'] + $tasimaTot['satis'] + $fin['gelir'];
      $giderTop = $nk['personel'] + $nk['hammadde'] + $tasimaTot['alis'] + $tasimaTot['gider'];
      $netTop = $gelirTop - $giderTop;
      ?>
      <div class="summary-grid">
        <div class="summary-card tint-green"><p class="label">Gelir (satışlar)</p><p class="metric">₺ <?= Helpers::money($gelirTop) ?></p></div>
        <div class="summary-card tint-orange"><p class="label">Gider (personel+işletme)</p><p class="metric">₺ <?= Helpers::money($giderTop) ?></p></div>
        <div class="summary-card wide"><p class="label">Net kâr</p><p class="metric <?= $netTop < 0 ? 'neg' : '' ?>">₺ <?= Helpers::money($netTop) ?></p></div>
      </div>
      <p class="row-meta" style="margin:-4px 2px 4px">
        <i class="bi bi-info-circle"></i>
        Gelir = üretim satışları ₺<?= Helpers::money($nk['ciro']) ?><?= $tasimaTot['satis'] > 0 ? ' + taşıma ₺' . Helpers::money($tasimaTot['satis']) : '' ?><?= $fin['gelir'] > 0 ? ' + elle ₺' . Helpers::money($fin['gelir']) : '' ?>.
        Gider = personel ₺<?= Helpers::money($nk['personel']) ?><?= $nk['hammadde'] > 0 ? ' + işletme ₺' . Helpers::money($nk['hammadde']) : '' ?>. Elle gider ekledikçe artar.
      </p>

      <?php if ($tasimaTot['satis'] > 0 || $tasimaTot['alis'] > 0 || $tasimaTot['gider'] > 0): ?>
      <div class="cardx card-pad">
        <h2>Taşıma karlılığı <span class="text-muted" style="font-size:12px;font-weight:600">(adet×(satış−alış), üretim cirosundan ayrı)</span></h2>
        <table class="tablex">
          <tbody>
            <tr><td>Taşıma toplam satış</td><td class="num">₺ <?= Helpers::money($tasimaTot['satis']) ?></td></tr>
            <tr><td>Taşıma toplam alış</td><td class="num">− ₺ <?= Helpers::money($tasimaTot['alis']) ?></td></tr>
            <tr><td>Brüt kâr</td><td class="num">₺ <?= Helpers::money($tasimaTot['brut']) ?></td></tr>
            <tr><td>Sabit gider</td><td class="num">− ₺ <?= Helpers::money($tasimaTot['gider']) ?></td></tr>
            <tr class="is-total"><td>Taşıma net kâr</td><td class="num" style="color:<?= $tasimaTot['net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($tasimaTot['net']) ?></td></tr>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <?php if ($nk['ciro'] > 0 || $nk['personel'] > 0 || $nk['hammadde'] > 0 || $nk['tasima_kar'] != 0): ?>
      <div class="cardx card-pad">
        <h2>Kâr dökümü <span class="text-muted" style="font-size:12px;font-weight:600">(üstteki net kârın kalemleri)</span></h2>
        <table class="tablex">
          <tbody>
            <tr><td>Üretim cirosu</td><td class="num">₺ <?= Helpers::money($nk['ciro']) ?></td></tr>
            <tr><td>Hammadde / işletme gideri</td><td class="num">− ₺ <?= Helpers::money($nk['hammadde']) ?></td></tr>
            <tr><td>Personel gideri (yüklü işveren maliyeti, dağıtılmış)</td><td class="num">− ₺ <?= Helpers::money($nk['personel']) ?></td></tr>
            <tr><td>Taşıma net kâr (adet×(satış−alış)−sabit)</td><td class="num" style="color:<?= $nk['tasima_kar'] < 0 ? 'var(--red)' : 'var(--green)' ?>"><?= $nk['tasima_kar'] < 0 ? '− ₺ ' . Helpers::money(abs($nk['tasima_kar'])) : '+ ₺ ' . Helpers::money($nk['tasima_kar']) ?></td></tr>
            <tr class="is-total"><td>Net karlılık</td><td class="num" style="color:<?= $nk['net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($nk['net']) ?></td></tr>
          </tbody>
        </table>
        <?php if ($kidemTot['birikim'] > 0): ?>
        <p class="row-meta" style="margin-top:8px"><i class="bi bi-piggy-bank"></i> Biriken kıdem yükümlülüğü (bilanço dışı borç, fesihte ödenir): <strong>₺ <?= Helpers::money($kidemTot['birikim']) ?></strong> · bu ay tahakkuk +₺ <?= Helpers::money($kidemTot['bu_ay_tahakkuk']) ?></p>
        <?php endif; ?>
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
        <h2>Gider ekle</h2>
        <form method="post" enctype="multipart/form-data" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <div class="segmented">
            <button class="chip active" type="button" onclick="setType(this,'gider')">Gider</button>
            <button class="chip" type="button" onclick="setType(this,'gelir')">Gelir <span class="text-muted" style="font-size:11px">(Paraşüt)</span></button>
          </div>
          <input type="hidden" name="type" id="tx-type" value="gider">

          <div class="field"><label>Tutar (₺)</label><input class="inputx" name="amount" inputmode="decimal" placeholder="0,00" required autofocus></div>

          <!-- opus-015: gider → neresi için (ciro oranında dağılır) -->
          <div class="gider-only">
            <div class="field"><label>Neresi için</label>
              <div class="segmented">
                <button class="chip active" type="button" onclick="setAlloc(this,'genel')">Genel (tüm müşteriler)</button>
                <button class="chip" type="button" onclick="setAlloc(this,'musteri')">Belirli müşteri</button>
              </div>
              <input type="hidden" name="alloc_type" id="alloc-type" value="genel">
              <p class="row-meta" id="alloc-hint">Tüm müşterilere ay cirosu oranında dağıtılır.</p>
            </div>
            <div class="field" id="alloc-customers" style="display:none">
              <label>Müşteri(ler) seç</label>
              <div class="check-list">
                <?php foreach ($customers as $c): ?>
                  <label class="check-row"><input type="checkbox" name="alloc_customer[]" value="<?= (int) $c['id'] ?>"> <?= Helpers::e($c['name']) ?></label>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Gelir (ikincil — Paraşüt gelene kadar elle) -->
          <div class="gelir-only" style="display:none">
            <div class="field"><label>Tarih</label><input class="inputx" type="date" name="tx_date" value="<?= Helpers::e(Helpers::today()) ?>"></div>
            <div class="field"><label>Müşteri</label>
              <select class="selectx" name="customer_id"><option value="">—</option>
                <?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>"><?= Helpers::e($c['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="field"><label>Kategori</label>
              <select class="selectx" name="category"><option value="">—</option>
                <?php foreach ($CATS as $c): ?><option><?= Helpers::e($c) ?></option><?php endforeach; ?>
              </select>
            </div>
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
          document.querySelectorAll('.gider-only').forEach(function(e){e.style.display = v === 'gider' ? '' : 'none';});
          document.querySelectorAll('.gelir-only').forEach(function(e){e.style.display = v === 'gelir' ? '' : 'none';});
          var h = document.querySelector('#add-sheet h2'); if (h) h.textContent = v === 'gider' ? 'Gider ekle' : 'Gelir ekle';
        }
        function setAlloc(btn, v){
          document.getElementById('alloc-type').value = v;
          btn.parentNode.querySelectorAll('.chip').forEach(function(c){c.classList.remove('active');});
          btn.classList.add('active');
          document.getElementById('alloc-customers').style.display = v === 'musteri' ? '' : 'none';
          document.getElementById('alloc-hint').textContent = v === 'musteri'
            ? 'Seçilen müşteri(ler)e kendi ciroları oranında dağıtılır.'
            : 'Tüm müşterilere ay cirosu oranında dağıtılır.';
        }
      </script>
<?php require __DIR__ . '/partials/footer.php'; ?>
