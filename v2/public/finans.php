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
// fable-031: 'firma' = gider FİRMA özeti görünümü (kronolojiğe alternatif)
if (!in_array($filter, ['all', 'gelir', 'gider', 'firma'], true)) {
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
    } elseif (($_POST['form'] ?? '') === 'parasut_cek') {
        // ── fable-030: Paraşüt'ten gider getir (SALT-OKUMA; Hikari deseni) ──
        $ayCek = (string) ($_POST['ay'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $ayCek)) {
            $ayCek = date('Y-m');
        }
        try {
            $pb = \Uysa\Parasut::purchaseBillsForMonth($ayCek);
            $ei = \Uysa\Parasut::inboundEInvoicesForMonth($ayCek);
            $eiBekleyen = array_values(array_filter($ei['bills'], static fn(array $b) => empty($b['iceri_alinmis'])));
            $s = $repo->parasutGiderIsle(array_merge($pb, $eiBekleyen));
            uysa_audit('gider_parasut', $u['username'], $ayCek, json_encode([
                'yeni' => $s['yeni'], 'mevcut' => $s['mevcut'], 'tutar' => $s['tutar'], 'iade' => $ei['iade'],
            ], JSON_UNESCAPED_UNICODE), client_ip());
            $flash = 'Paraşüt (' . date('F Y', strtotime($ayCek . '-01')) . '): ' . $s['yeni'] . ' yeni gider · ₺'
                . number_format($s['tutar'], 2, ',', '.') . ' · ' . $s['mevcut'] . ' zaten vardı'
                . ($ei['iade'] > 0 ? ' · ' . $ei['iade'] . ' iade ATLANDI (elle düşün)' : '');
        } catch (\Throwable $e) {
            $flash = 'Paraşüt okunamadı: ' . $e->getMessage();
            $flashOk = false;
        }
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
$txs = $repo->transactionsForMonth($month);
$customers = $repo->activeCustomers();
$suppliers = $repo->activeSuppliers();

// Filtre + güne göre grupla
$byDay = [];
foreach ($txs as $t) {
    if ($filter !== 'all' && $filter !== 'firma' && $t['type'] !== $filter) {
        continue;
    }
    $byDay[$t['tx_date']][] = $t;
}
// fable-031: firma görünümü — gider firma özeti (kim bana ne kesiyor)
$firmaOzet = $filter === 'firma' ? $repo->giderFirmaOzet($month) : [];

$eyebrow = ay_label_tr($month);
$pageTitle = 'Finans';
$active = 'finans';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

<?php
      // fable-011: üst kartlar artık OPERASYONEL aylık kâr (Ömer isteği: işlenmiş satışlar
      // gelirde, personel + girilen giderler giderde). Sadece transactions'a bakan eski
      // "nakit" görünümü boş kalıyordu. Kaynak: üretim cirosu + taşıma + elle işlemler.
      $gelirTop = $nk['ciro'] + $tasimaTot['satis'] + $fin['gelir'];
      $giderTop = $nk['personel'] + $nk['hammadde'] + $tasimaTot['alis'] + $tasimaTot['gider'];
      $netTop = $gelirTop - $giderTop;
      ?>
      <div class="cardx card-pad">
        <form method="get" class="gt-date">
          <div class="dt" style="position:relative">
            <b><?= Helpers::e(ay_label_tr($month)) ?></b>
            <span>gelir / gider özeti</span>
            <input type="month" name="ay" value="<?= Helpers::e($month) ?>" onchange="this.form.submit()"
                   aria-label="Ay seç" style="position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer">
          </div>
        </form>
      </div>

      <!-- fable-034: AYIN ÖZETİ (mockup birebir) — 3 mini + aksiyonlar. Gelir/Gider mini'ye
           dokununca kalem dökümü açılır (fable-012 toggleFin korunur). -->
      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-broadcast"></i> AYIN ÖZETİ</div>
        <div class="gt-mini" style="margin-top:2px">
          <div class="tap-card" role="button" tabindex="0" onclick="toggleFin('fin-gelir')">
            <div class="gt-mn ok">₺<?= number_format(round($gelirTop), 0, ',', '.') ?></div><div class="gt-ml">Gelir <i class="bi bi-chevron-down chev"></i></div>
          </div>
          <div class="tap-card" role="button" tabindex="0" onclick="toggleFin('fin-gider')">
            <div class="gt-mn bad">₺<?= number_format(round($giderTop), 0, ',', '.') ?></div><div class="gt-ml">Gider <i class="bi bi-chevron-down chev"></i></div>
          </div>
          <div>
            <div class="gt-mn"><?= $netTop < 0 ? '−' : '' ?>₺<?= number_format(round(abs($netTop)), 0, ',', '.') ?></div><div class="gt-ml">Net</div>
          </div>
        </div>
        <div class="gt-acts" style="margin-top:13px;display:flex;gap:9px">
          <?php if (Auth::isAdmin($u)): ?>
          <form method="post" style="flex:1;display:flex">
            <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
            <input type="hidden" name="form" value="parasut_cek">
            <input type="hidden" name="ay" value="<?= Helpers::e($month) ?>">
            <button class="btn-action btn-secondaryx flex-fill" type="submit" style="white-space:nowrap;font-size:12.5px;padding-inline:8px;gap:6px"><i class="bi bi-cloud-download"></i> Paraşüt'ten çek</button>
          </form>
          <?php endif; ?>
          <button class="btn-action btn-primaryx flex-fill" type="button" onclick="toggleSheet('add-sheet')" style="white-space:nowrap;font-size:12.5px;padding-inline:8px;gap:6px"><i class="bi bi-plus-lg"></i> Gelir/gider ekle</button>
        </div>
      </div>

      <?php // fable-034b (denetim): modül çipleri artık tarih+AYIN ÖZETİ açılışının ALTINDA ?>
      <div class="quick-tiles">
        <a class="q-tile" href="faturalar.php"><i class="bi bi-receipt"></i> Faturalar</a>
        <a class="q-tile" href="cari.php"><i class="bi bi-cash-coin"></i> Cari &amp; tahsilat</a>
        <?php if (Auth::isAdmin($u)): ?>
        <a class="q-tile" href="fatura-kes.php"><i class="bi bi-receipt-cutoff"></i> Fatura Kes</a>
        <?php endif; ?>
      </div>

      <div class="cardx card-pad fin-detay" id="fin-gelir" style="display:none">
        <h2>Gelir özeti</h2>
        <table class="tablex"><tbody>
          <tr><td>Üretim satışları (işlenmiş)</td><td class="num">₺ <?= Helpers::money($nk['ciro']) ?></td></tr>
          <?php if ($tasimaTot['satis'] > 0): ?><tr><td>Taşıma satış</td><td class="num">₺ <?= Helpers::money($tasimaTot['satis']) ?></td></tr><?php endif; ?>
          <?php if ($fin['gelir'] > 0): ?><tr><td>Elle girilen gelir</td><td class="num">₺ <?= Helpers::money($fin['gelir']) ?></td></tr><?php endif; ?>
          <tr class="is-total"><td>Toplam gelir</td><td class="num">₺ <?= Helpers::money($gelirTop) ?></td></tr>
        </tbody></table>
        <p class="row-meta" style="margin-top:6px"><i class="bi bi-info-circle"></i> Üretim satışları o ay onaylanan/işlenen sayılardan otomatik gelir. <a href="rapor.php?ay=<?= $month ?>" style="text-decoration:underline">Firma bazlı detay</a></p>
      </div>

      <div class="cardx card-pad fin-detay" id="fin-gider" style="display:none">
        <h2>Gider özeti</h2>
        <table class="tablex"><tbody>
          <tr><td>Personel (yüklü işveren maliyeti)</td><td class="num">₺ <?= Helpers::money($nk['personel']) ?></td></tr>
          <?php if ($nk['hammadde'] > 0): ?><tr><td>İşletme / hammadde giderleri</td><td class="num">₺ <?= Helpers::money($nk['hammadde']) ?></td></tr><?php endif; ?>
          <?php if ($tasimaTot['alis'] > 0): ?><tr><td>Taşıma alış</td><td class="num">₺ <?= Helpers::money($tasimaTot['alis']) ?></td></tr><?php endif; ?>
          <?php if ($tasimaTot['gider'] > 0): ?><tr><td>Taşıma sabit gider</td><td class="num">₺ <?= Helpers::money($tasimaTot['gider']) ?></td></tr><?php endif; ?>
          <tr class="is-total"><td>Toplam gider</td><td class="num">₺ <?= Helpers::money($giderTop) ?></td></tr>
        </tbody></table>
        <p class="row-meta" style="margin-top:6px"><i class="bi bi-info-circle"></i> Personel = tüm aktif personelin bu ayki yüklü maliyeti. Yeni gider eklemek için aşağıdaki <strong>+</strong> ile "Gider ekle".</p>
      </div>

      <?php /* fable-013: Taşıma karlılığı + Kâr dökümü kartları Finans'tan kaldırıldı;
         detay Kâr/Zarar'da (rapor.php). Finans = özet sayfası. */ ?>
      <a class="cardx card-pad" href="rapor.php?ay=<?= $month ?>" style="display:flex;align-items:center;justify-content:space-between;gap:8px">
        <div><h2 style="margin:0">Kâr / Zarar detayı</h2><p class="row-meta" style="margin:2px 0 0">Firma bazlı ciro, taşıma kâr/zarar, net karlılık dökümü</p></div>
        <i class="bi bi-chevron-right" style="font-size:18px;color:var(--primary)"></i>
      </a>

      <?php
      // fable-034: GİDER — FİRMA KARNESİ (mockup birebir). Her zaman görünür (kim bana ne kesiyor).
      $firmaKarne = $repo->giderFirmaOzet($month);
      $firmaMax = 0.0;
      foreach ($firmaKarne as $f) { $firmaMax = max($firmaMax, (float) $f['toplam']); }
      ?>
      <?php if ($firmaKarne): ?>
      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-buildings-fill"></i> GİDER — FİRMA KARNESİ <a class="gt-hr" href="#add-sheet" onclick="giderEsle();return false"><i class="bi bi-plus-lg"></i> Gider eşle</a></div>
        <?php foreach (array_slice($firmaKarne, 0, 6) as $f): $w = $firmaMax > 0 ? max(4, (int) round((float) $f['toplam'] / $firmaMax * 100)) : 4; ?>
          <div class="gt-kr">
            <div class="gt-kr-head">
              <div class="gt-rank"><?= Helpers::e(mb_strtoupper(mb_substr((string) $f['firma'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
              <div class="gt-kr-firm">
                <div class="gt-kr-ad"><?= Helpers::e($f['firma']) ?></div>
                <div class="gt-kr-sub"><?= (int) $f['adet'] ?> fatura</div>
              </div>
              <div class="gt-kr-val bad">₺<?= Helpers::money((float) $f['toplam']) ?></div>
            </div>
            <div class="gt-bar"><i class="bad" style="width: <?= $w ?>%"></i></div>
          </div>
        <?php endforeach; ?>
        <div class="gt-note">detay için firma bazında filtresine bak</div>
      </div>
      <?php endif; ?>

      <div class="section-head"><div class="gt-h" style="margin:0"><i class="bi bi-list-ul"></i> SON HAREKETLER</div></div>
      <div class="segmented">
        <a class="chip <?= $filter === 'all' ? 'active' : '' ?>" href="finans.php?ay=<?= $month ?>&tur=all">Tümü</a>
        <a class="chip <?= $filter === 'gelir' ? 'active' : '' ?>" href="finans.php?ay=<?= $month ?>&tur=gelir">Gelir</a>
        <a class="chip <?= $filter === 'gider' ? 'active' : '' ?>" href="finans.php?ay=<?= $month ?>&tur=gider">Gider</a>
        <?php // fable-031 (Ömer): "firma firma bana ne fatura kesiyor" — aylık tedarikçi özeti ?>
        <a class="chip <?= $filter === 'firma' ? 'active' : '' ?>" href="finans.php?ay=<?= $month ?>&tur=firma">Firma bazında</a>
      </div>

      <?php if ($filter === 'firma'): // fable-031: gider FİRMA özeti ?>
        <div class="cardx card-pad">
          <h2>Gider — firma bazında <span class="text-muted" style="font-size:12px;font-weight:600">(<?= Helpers::e(ay_label_tr($month)) ?>)</span></h2>
          <?php if (!$firmaOzet): ?>
            <div class="empty-state">Bu ay gider kaydı yok.</div>
          <?php else: $fTop = 0.0; ?>
            <div style="overflow-x:auto"><table class="mini-cal">
              <thead><tr><th>Firma</th><th>Fatura</th><th>Toplam</th></tr></thead>
              <tbody>
              <?php foreach ($firmaOzet as $f): $fTop += $f['toplam']; ?>
                <tr><td><?= Helpers::e($f['firma']) ?></td>
                    <td><?= (int) $f['adet'] ?></td>
                    <td>₺ <?= Helpers::money($f['toplam']) ?></td></tr>
              <?php endforeach; ?>
                <tr class="is-total"><td>Toplam</td><td></td><td>₺ <?= Helpers::money($fTop) ?></td></tr>
              </tbody>
            </table></div>
          <?php endif; ?>
        </div>
      <?php elseif (!$byDay): ?>
        <div class="empty-state">
          Bu ay henüz kayıt yok.
          <div class="mt-3"><button class="btn-action btn-secondaryx" type="button" onclick="toggleSheet('add-sheet')">Gider / gelir ekle</button></div>
        </div>
      <?php else: // fable-034: gün gün son hareketler (mockup gt-tx) — tek kart ?>
        <div class="cardx card-pad">
          <?php foreach ($byDay as $day => $items): ?>
            <div class="gt-day"><?= Helpers::e(gun_label_tr($day)) ?></div>
            <?php foreach ($items as $t):
                $isGelir = $t['type'] === 'gelir';
                $party = $t['customer_name'] ?: $t['supplier_name'] ?: '';
                $meta = $t['description'] ?: ($party ? ($isGelir ? 'Müşteri: ' : 'Tedarikçi: ') . $party : '');
                if ($t['file_id']) { $meta = trim($meta . ' · fatura fotoğrafı eklendi', ' ·'); }
                $baslik = ($t['category'] ?: ($isGelir ? 'Gelir' : 'Gider')) . ($party ? ' · ' . $party : '');
            ?>
              <div class="gt-tx">
                <div class="gt-tx-ic <?= $isGelir ? 'in' : 'out' ?>"><i class="bi <?= $isGelir ? 'bi-arrow-down-left' : 'bi-arrow-up-right' ?>"></i></div>
                <div class="gt-tn"><b><?= Helpers::e($baslik) ?></b><span><?= Helpers::e($meta ?: '—') ?></span></div>
                <div class="gt-tv <?= $isGelir ? 'in' : 'out' ?>"><?= $isGelir ? '+' : '−' ?>₺<?= Helpers::money((float) $t['amount']) ?></div>
              </div>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php // fable-029b (Ömer): "finansta gider ekle gözükmesin" — form varsayılan GİZLİ,
            // üstteki "+ Ekle" çipiyle açılır (gizleme stili hiç yoktu, form hep açık duruyordu) ?>
      <div class="fab-sheet" id="add-sheet" style="display:none">
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
        // fable-012: Gelir/Gider kartı → kısa özet aç/kapat (akordeon)
        function toggleFin(id){
          var el = document.getElementById(id);
          if (!el) return;
          var other = document.getElementById(id === 'fin-gelir' ? 'fin-gider' : 'fin-gelir');
          if (other) other.style.display = 'none';
          el.style.display = el.style.display === 'none' ? '' : 'none';
          document.querySelectorAll('.tap-card').forEach(function(c){ c.classList.remove('open'); });
          if (el.style.display !== 'none') {
            var card = document.querySelector('[onclick="toggleFin(\'' + id + '\')"]');
            if (card) card.classList.add('open');
          }
        }
        // fable-034d (Ömer): "+ Gider eşle" — gider ekle formunu doğrudan "belirli müşteriye
        // eşle" modunda açar (gideri müşteriye/tedarikçiye bağlama akışı tek dokunuş).
        function giderEsle(){
          var s = document.getElementById('add-sheet'); if (!s) return;
          s.style.display = 'block';
          var tG = s.querySelector('button[onclick*="setType(this,\'gider\')"]'); if (tG) setType(tG, 'gider');
          var aM = s.querySelector('button[onclick*="setAlloc(this,\'musteri\')"]'); if (aM) setAlloc(aM, 'musteri');
          s.scrollIntoView({ behavior: 'smooth', block: 'start' });
          var amt = s.querySelector('input[name=amount]'); if (amt) amt.focus();
        }
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
