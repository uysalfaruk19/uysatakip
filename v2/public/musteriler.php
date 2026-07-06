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
                $pdo->beginTransaction();
                $cid = $repo->upsertCustomer($name, $unitPrice, $category, $id);
                if ($category === 'tasima') {
                    $adet = Helpers::parseMoney((string) ($_POST['adet'] ?? '0'));
                    $alis = Helpers::parseMoney((string) ($_POST['birim_alis'] ?? '0'));
                    $satis = Helpers::parseMoney((string) ($_POST['birim_satis'] ?? '0'));
                    $gider = Helpers::parseMoney((string) ($_POST['sabit_gider'] ?? '0'));
                    $note = trim((string) ($_POST['note'] ?? '')) ?: null;
                    if ($adet > 0 || $alis > 0 || $satis > 0 || $gider > 0) {
                        $repo->upsertTasimaAylik($cid, $postMonth, $adet, $alis, $satis, $gider, $note);
                    }
                }
                $pdo->commit();
                uysa_audit('musteri_kaydet', $u['username'], (string) $cid, json_encode(['cat' => $category]), client_ip());
                $flash = 'Müşteri kaydedildi · ' . $name;
                $month = $postMonth;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $flash = 'Kayıt hatası (ad benzersiz olmalı).';
                $flashOk = false;
                $formOpen = true;
            }
        }
    }
}

$uretim = $repo->listCustomersByCategory('uretim');
$tasima = $repo->listCustomersByCategory('tasima');

// Düzenlenen müşteri (form ön-dolum)
$edit = $editId ? $repo->customer($editId) : null;
$editTasima = ($edit && $edit['category'] === 'tasima') ? $repo->tasimaAylik($editId, $month) : null;
$fName = $edit['name'] ?? '';
$fCat = $edit['category'] ?? 'uretim';
$fPrice = $edit ? (float) $edit['unit_price'] : 0.0;
$fAdet = $editTasima ? (float) $editTasima['adet'] : 0.0;
$fAlis = $editTasima ? (float) $editTasima['birim_alis'] : 0.0;
$fSatis = $editTasima ? (float) $editTasima['birim_satis'] : 0.0;
$fGider = $editTasima ? (float) $editTasima['sabit_gider'] : 0.0;
$fBrut = $fAdet * ($fSatis - $fAlis);
$fNet = $fBrut - $fGider;
$fNote = $editTasima['note'] ?? '';

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

          <div class="field"><label>Birim fiyat (₺ / kişi)</label>
            <input class="inputx" name="unit_price" inputmode="decimal" value="<?= $fPrice > 0 ? Helpers::money($fPrice) : '' ?>" placeholder="0,00">
          </div>

          <div id="tasima-fields" style="<?= $fCat === 'tasima' ? '' : 'display:none' ?>; display:grid; gap:11px;">
            <div class="text-muted" style="font-size:12px;font-weight:600"><?= Helpers::e(ay_label_tr($month)) ?> — adet × (satış − alış)</div>
            <div class="field"><label>Adet (o ay satılan yemek)</label>
              <input class="inputx" name="adet" id="f-adet" inputmode="decimal" value="<?= $fAdet > 0 ? Helpers::money($fAdet) : '' ?>" placeholder="0" oninput="calcKar()">
            </div>
            <div class="field"><label>Birim ALIŞ (₺ / adet — tedarik)</label>
              <input class="inputx" name="birim_alis" id="f-alis" inputmode="decimal" value="<?= $fAlis > 0 ? Helpers::money($fAlis) : '' ?>" placeholder="0,00" oninput="calcKar()">
            </div>
            <div class="field"><label>Birim SATIŞ (₺ / adet — müşteriye)</label>
              <input class="inputx" name="birim_satis" id="f-satis" inputmode="decimal" value="<?= $fSatis > 0 ? Helpers::money($fSatis) : '' ?>" placeholder="0,00" oninput="calcKar()">
            </div>
            <div class="field"><label>Aylık sabit gider (₺ — opsiyonel)</label>
              <input class="inputx" name="sabit_gider" id="f-gider" inputmode="decimal" value="<?= $fGider > 0 ? Helpers::money($fGider) : '' ?>" placeholder="0,00" oninput="calcKar()">
            </div>
            <div class="field"><label>Not (opsiyonel)</label>
              <input class="inputx" name="note" value="<?= Helpers::e($fNote) ?>" placeholder="ör. 2 araç, şoför dahil">
            </div>
            <div class="summary-grid">
              <div class="summary-card tint-blue"><p class="label">Toplam alış</p><p class="metric small" id="alis-live">₺ <?= Helpers::money($fAdet * $fAlis) ?></p></div>
              <div class="summary-card tint-blue"><p class="label">Toplam satış</p><p class="metric small" id="satis-live">₺ <?= Helpers::money($fAdet * $fSatis) ?></p></div>
              <div class="summary-card tint-orange"><p class="label">Brüt kâr (adet×(satış−alış))</p><p class="metric small" id="brut-live">₺ <?= Helpers::money($fBrut) ?></p></div>
              <div class="summary-card tint-green"><p class="label">Net kâr (brüt − sabit)</p><p class="metric small" id="kar-live">₺ <?= Helpers::money($fNet) ?></p></div>
            </div>
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
              <div class="row-title"><span class="status-dot"></span><strong><?= Helpers::e($c['name']) ?></strong></div>
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
            $t = $repo->tasimaAylik((int) $c['id'], $month);
            $kar = $t ? (float) $t['kar'] : 0.0; ?>
          <div class="customer-row">
            <div>
              <div class="row-title"><span class="status-dot"></span><strong><?= Helpers::e($c['name']) ?></strong>
                <?php if ($t): ?><span class="badge-soft <?= $kar >= 0 ? 'badge-ok' : 'badge-neg' ?>">₺ <?= Helpers::money($kar) ?> kâr</span><?php endif; ?>
              </div>
              <p class="row-meta">
                <?php if ($t): ?><?= number_format((float) $t['adet'], 0, ',', '.') ?> adet · Satış ₺ <?= Helpers::money((float) $t['toplam_satis']) ?> · Alış ₺ <?= Helpers::money((float) $t['toplam_alis']) ?>
                <?php else: ?>Bu ay kâr girişi yok<?php endif; ?>
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
        }
        function parseTL(v){ return parseFloat(String(v).replace(/\./g,'').replace(',', '.')) || 0; }
        function tl(n){ return '₺ ' + n.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}); }
        function calcKar(){
          var adet = parseTL(document.getElementById('f-adet').value);
          var alis = parseTL(document.getElementById('f-alis').value);
          var satis = parseTL(document.getElementById('f-satis').value);
          var g = parseTL(document.getElementById('f-gider').value);
          var brut = adet * (satis - alis);
          document.getElementById('alis-live').textContent = tl(adet * alis);
          document.getElementById('satis-live').textContent = tl(adet * satis);
          document.getElementById('brut-live').textContent = tl(brut);
          document.getElementById('kar-live').textContent = tl(brut - g);
        }
      </script>
<?php require __DIR__ . '/partials/footer.php'; ?>
