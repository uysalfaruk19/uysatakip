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

$month = (string) ($_GET['ay'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$flash = '';
$flashOk = true;
$formOpen = isset($_GET['yeni']) || isset($_GET['edit']);
$editId = (int) ($_GET['edit'] ?? 0) ?: null;

// ── Kaydet / pasifleştir ─────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
        $formOpen = true;
    } elseif (($_POST['action'] ?? '') === 'pasif') {
        $pid = (int) ($_POST['id'] ?? 0);
        if ($pid > 0) {
            $repo->setCustomerActive($pid, false);
            uysa_audit('musteri_pasif', $u['username'], (string) $pid, null, client_ip());
            $flash = 'Müşteri pasifleştirildi.';
        }
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = ($_POST['category'] ?? 'uretim') === 'tasima' ? 'tasima' : 'uretim';
        $unitPrice = Helpers::parseMoney((string) ($_POST['unit_price'] ?? '0'));
        $id = (int) ($_POST['id'] ?? 0) ?: null;
        $postMonth = (string) ($_POST['ay'] ?? $month);
        if (!preg_match('/^\d{4}-\d{2}$/', $postMonth)) {
            $postMonth = $month;
        }
        if ($name === '') {
            $flash = 'Müşteri adı zorunlu.';
            $flashOk = false;
            $formOpen = true;
        } else {
            try {
                // Taşıma kartı = 4 alan: unit_price (satış) + maliyet_birim (alış)
                // + tasima_sabit_gider (opsiyonel) + tasima_not (opsiyonel). adet KARTTA YOK
                // (adet = o ay production.persons toplamı, Bugün sayımlarından).
                $maliyet = null; $gider = null; $tnot = null;
                if ($category === 'tasima') {
                    $maliyet = Helpers::parseMoney((string) ($_POST['maliyet_birim'] ?? '0'));
                    $gider = Helpers::parseMoney((string) ($_POST['sabit_gider'] ?? '0'));
                    $tnot = trim((string) ($_POST['note'] ?? ''));
                }
                $cid = $repo->upsertCustomer($name, $unitPrice, $category, $id, null, null, null, $maliyet, $gider, $tnot);
                uysa_audit('musteri_kaydet', $u['username'], (string) $cid, json_encode(['cat' => $category]), client_ip());
                $flash = 'Müşteri kaydedildi · ' . $name;
                $month = $postMonth;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flash = 'Kayıt hatası (ad benzersiz olmalı).';
                $flashOk = false;
                $formOpen = true;
            }
        }
    }
}

$uretim = $repo->listCustomersByCategory('uretim');
$tasima = $repo->listCustomersByCategory('tasima');

// Düzenlenen müşteri (form ön-dolum) — taşıma kart değerleri customers'ta (standing)
$edit = $editId ? $repo->customer($editId) : null;
$fName = $edit['name'] ?? '';
$fCat = $edit['category'] ?? 'uretim';
$fPrice = $edit ? (float) $edit['unit_price'] : 0.0;                 // satış
$fAlis = $edit ? (float) ($edit['maliyet_birim'] ?? 0) : 0.0;        // alış
$fGider = $edit ? (float) ($edit['tasima_sabit_gider'] ?? 0) : 0.0;  // sabit gider
$fNote = $edit['tasima_not'] ?? '';
$fBirimKar = $fPrice - $fAlis;
// Bu ayki adet + net kâr (production'dan, bilgi amaçlı)
$fProfit = ($edit && $edit['category'] === 'tasima') ? $repo->tasimaProfit($editId, $month) : null;

$eyebrow = 'Müşteri yönetimi';
$pageTitle = 'Müşteriler';
$active = 'musteriler';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <?php if (!$formOpen): ?>
        <a class="btn-action btn-primaryx btn-full" href="musteriler.php?yeni=1"><i class="bi bi-person-plus"></i> Müşteri ekle</a>
      <?php endif; ?>

      <div class="fab-sheet" id="musteri-form" style="<?= $formOpen ? '' : 'display:none' ?>">
        <h2><?= $edit ? 'Müşteri düzenle' : 'Yeni müşteri' ?></h2>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
          <input type="hidden" name="ay" value="<?= Helpers::e($month) ?>">

          <div class="field"><label>Müşteri adı</label>
            <input class="inputx" name="name" value="<?= Helpers::e($fName) ?>" required autocapitalize="words">
          </div>

          <div class="field"><label>Kategori</label>
            <div class="segmented">
              <button class="chip <?= $fCat === 'uretim' ? 'active' : '' ?>" type="button" data-cat="uretim" onclick="setCat(this,'uretim')">Üretim</button>
              <button class="chip <?= $fCat === 'tasima' ? 'active' : '' ?>" type="button" data-cat="tasima" onclick="setCat(this,'tasima')">Taşıma</button>
            </div>
            <input type="hidden" name="category" id="cat-input" value="<?= Helpers::e($fCat) ?>">
          </div>

          <div class="field"><label id="lbl-price"><span id="lbl-price-txt"><?= $fCat === 'tasima' ? 'Birim fiyat — SATIŞ (₺ / adet)' : 'Birim fiyat (₺ / kişi)' ?></span></label>
            <input class="inputx" name="unit_price" id="f-satis" inputmode="decimal" value="<?= $fPrice > 0 ? Helpers::money($fPrice) : '' ?>" placeholder="0,00" oninput="calcKar()">
          </div>

          <div id="tasima-fields" style="<?= $fCat === 'tasima' ? '' : 'display:none' ?>; display:grid; gap:11px;">
            <div class="text-muted" style="font-size:12px;font-weight:600">Taşıma kartı · aylık kâr = aydaki satış adedi × birim kâr − sabit gider</div>
            <div class="field"><label>Maliyet birim fiyat (₺ — alış / tedarik)</label>
              <input class="inputx" name="maliyet_birim" id="f-alis" inputmode="decimal" value="<?= $fAlis > 0 ? Helpers::money($fAlis) : '' ?>" placeholder="0,00" oninput="calcKar()">
            </div>
            <div class="field"><label>Aylık sabit gider (₺ — opsiyonel)</label>
              <input class="inputx" name="sabit_gider" id="f-gider" inputmode="decimal" value="<?= $fGider > 0 ? Helpers::money($fGider) : '' ?>" placeholder="0,00">
            </div>
            <div class="field"><label>Not (opsiyonel)</label>
              <input class="inputx" name="note" value="<?= Helpers::e($fNote) ?>" placeholder="ör. 2 araç, şoför dahil">
            </div>
            <div class="summary-grid">
              <div class="summary-card tint-blue"><p class="label">Birim satış</p><p class="metric small" id="satis-live">₺ <?= Helpers::money($fPrice) ?></p></div>
              <div class="summary-card tint-blue"><p class="label">Birim alış</p><p class="metric small" id="alis-live">₺ <?= Helpers::money($fAlis) ?></p></div>
              <div class="summary-card tint-orange"><p class="label">Birim kâr (satış − alış)</p><p class="metric small" id="birimkar-live">₺ <?= Helpers::money($fBirimKar) ?></p></div>
              <?php if ($fProfit): ?>
              <div class="summary-card tint-green"><p class="label"><?= Helpers::e(ay_label_tr($month)) ?> · <?= number_format((float) $fProfit['adet'], 0, ',', '.') ?> adet → net kâr</p><p class="metric small">₺ <?= Helpers::money((float) $fProfit['net']) ?></p></div>
              <?php endif; ?>
            </div>
            <p class="text-muted" style="font-size:11px">Adet buraya girilmez — <strong>Bugün</strong> ekranındaki günlük sayımlardan (o ayın toplamı) otomatik gelir.</p>
          </div>

          <div class="actions-row">
            <a class="btn-action btn-ghost flex-fill" href="musteriler.php">Vazgeç</a>
            <button class="btn-action btn-primaryx flex-fill" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
          </div>
        </form>
      </div>

      <!-- ÜRETİM müşterileri -->
      <div class="section-head"><h2>Üretim müşterileri</h2><span class="text-muted" style="font-size:12px"><?= count($uretim) ?> firma</span></div>
      <div class="cardx card-pad">
        <?php if (!$uretim): ?>
          <div class="empty-state">Üretim müşterisi yok.</div>
        <?php else: foreach ($uretim as $c): ?>
          <div class="customer-row">
            <div>
              <div class="row-title"><span class="status-dot"></span><strong><?= Helpers::e($c['name']) ?></strong>
                <?php if (($c['parasut_bakiye'] ?? null) !== null): ?><span class="badge-soft <?= (float) $c['parasut_bakiye'] < 0 ? 'badge-neg' : 'badge-ok' ?>" title="Paraşüt muhasebe bakiyesi">Paraşüt ₺ <?= Helpers::money((float) $c['parasut_bakiye']) ?></span><?php endif; ?>
              </div>
              <p class="row-meta">₺ <?= Helpers::money((float) $c['unit_price']) ?> kişi başı</p>
            </div>
            <div class="actions-row" style="justify-content:flex-end">
              <a class="icon-btn" href="musteriler.php?edit=<?= (int) $c['id'] ?>" aria-label="Düzenle"><i class="bi bi-pencil"></i></a>
              <form method="post" onsubmit="return confirm('Bu müşteri pasifleştirilsin mi?');" style="display:inline">
                <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
                <input type="hidden" name="action" value="pasif">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="icon-btn" type="submit" aria-label="Pasifleştir"><i class="bi bi-archive"></i></button>
              </form>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- TAŞIMA müşterileri -->
      <div class="section-head"><h2>Taşıma müşterileri</h2><span class="text-muted" style="font-size:12px"><?= Helpers::e(ay_label_tr($month)) ?></span></div>
      <div class="cardx card-pad">
        <?php if (!$tasima): ?>
          <div class="empty-state">Taşıma müşterisi yok.</div>
        <?php else: foreach ($tasima as $c):
            $t = $repo->tasimaProfit((int) $c['id'], $month);
            $adet = (float) $t['adet'];
            $kar = (float) $t['net']; ?>
          <div class="customer-row">
            <div>
              <div class="row-title"><span class="status-dot"></span><strong><?= Helpers::e($c['name']) ?></strong>
                <?php if ($adet > 0): ?><span class="badge-soft <?= $kar >= 0 ? 'badge-ok' : 'badge-neg' ?>">₺ <?= Helpers::money($kar) ?> kâr</span><?php endif; ?>
              </div>
              <p class="row-meta">
                Satış ₺ <?= Helpers::money((float) $t['satis']) ?> · Alış ₺ <?= Helpers::money((float) $t['alis']) ?> / adet
                <?php if ($adet > 0): ?>· <?= number_format($adet, 0, ',', '.') ?> adet (bu ay)<?php else: ?>· bu ay sayım yok<?php endif; ?>
              </p>
            </div>
            <div class="actions-row" style="justify-content:flex-end">
              <a class="icon-btn" href="rapor.php?musteri=<?= (int) $c['id'] ?>&ay=<?= $month ?>" aria-label="Rapor"><i class="bi bi-graph-up-arrow"></i></a>
              <a class="icon-btn" href="musteriler.php?edit=<?= (int) $c['id'] ?>&ay=<?= $month ?>" aria-label="Düzenle"><i class="bi bi-pencil"></i></a>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <script>
        function setCat(btn, cat){
          document.getElementById('cat-input').value = cat;
          btn.parentNode.querySelectorAll('.chip').forEach(function(c){c.classList.remove('active');});
          btn.classList.add('active');
          document.getElementById('tasima-fields').style.display = (cat === 'tasima') ? 'grid' : 'none';
          document.getElementById('lbl-price-txt').textContent =
            (cat === 'tasima') ? 'Birim fiyat — SATIŞ (₺ / adet)' : 'Birim fiyat (₺ / kişi)';
          calcKar();
        }
        function parseTL(v){ return parseFloat(String(v).replace(/\./g,'').replace(',', '.')) || 0; }
        function tl(n){ return '₺ ' + n.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}); }
        function calcKar(){
          var satis = parseTL(document.getElementById('f-satis').value);
          var alis = parseTL(document.getElementById('f-alis').value);
          document.getElementById('satis-live').textContent = tl(satis);
          document.getElementById('alis-live').textContent = tl(alis);
          document.getElementById('birimkar-live').textContent = tl(satis - alis);
        }
      </script>
<?php require __DIR__ . '/partials/footer.php'; ?>
