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
    } elseif (($_POST['action'] ?? '') === 'aylik_fiyat') {
        // opus-017: bir ayın fiyatını düzenle → o ay production güncellenir (her yere yansır).
        $pid = (int) ($_POST['id'] ?? 0);
        $fiyatAy = (string) ($_POST['fiyat_ay'] ?? $month);
        if (!preg_match('/^\d{4}-\d{2}$/', $fiyatAy)) {
            $fiyatAy = $month;
        }
        $cust = $pid > 0 ? $repo->customer($pid) : null;
        if ($cust) {
            $unitPrice = Helpers::parseMoney((string) ($_POST['ay_unit_price'] ?? '0'));
            $maliyet = null; $gider = null;
            if (($cust['category'] ?? 'uretim') === 'tasima') {
                $maliyet = Helpers::parseMoney((string) ($_POST['ay_maliyet_birim'] ?? '0'));
                $gider = Helpers::parseMoney((string) ($_POST['ay_sabit_gider'] ?? '0'));
            }
            try {
                $repo->setCustomerPrice($pid, $fiyatAy, $unitPrice, $maliyet, $gider);
                uysa_audit('musteri_aylik_fiyat', $u['username'], (string) $pid,
                    json_encode(['ay' => $fiyatAy, 'fiyat' => $unitPrice]), client_ip());
                $flash = ay_label_tr($fiyatAy) . ' fiyatı güncellendi · o ayın cirosu/analizi her yerde yenilendi.';
                $month = $fiyatAy;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flash = 'Aylık fiyat kaydedilemedi.';
                $flashOk = false;
            }
            $formOpen = true;
            $_GET['edit'] = (string) $pid;
            $editId = $pid;
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
                $contact = trim((string) ($_POST['contact'] ?? '')) ?: null;
                $phone = trim((string) ($_POST['phone'] ?? '')) ?: null;
                $email = trim((string) ($_POST['email'] ?? '')) ?: null;
                $cid = $repo->upsertCustomer($name, $unitPrice, $category, $id, $contact, $phone, null, $maliyet, $gider, $tnot, $email);
                // Reaktif ilke: karttaki fiyat da seçili aydan itibaren AY-BAZLI uygulanır.
                // Yoksa ay kaydı olan müşteride carry-forward current default'u her zaman
                // ezdiğinden karttan girilen fiyat hiçbir hesaba yansımaz (ölü alan tuzağı).
                $fiyatNotu = '';
                if ($unitPrice > 0) {
                    $cur = $repo->priceFor($cid, $postMonth);
                    $degisti = abs($cur['unit_price'] - $unitPrice) > 0.009
                        || ($category === 'tasima'
                            && (abs($cur['maliyet_birim'] - (float) $maliyet) > 0.009
                                || abs($cur['tasima_sabit_gider'] - (float) $gider) > 0.009));
                    if ($degisti) {
                        $repo->setCustomerPrice($cid, $postMonth, $unitPrice, $maliyet, $gider);
                        uysa_audit('musteri_aylik_fiyat', $u['username'], (string) $cid,
                            json_encode(['ay' => $postMonth, 'fiyat' => $unitPrice, 'kaynak' => 'kart']), client_ip());
                        $fiyatNotu = ' · ' . ay_label_tr($postMonth) . ' fiyatı güncellendi (bu aydan itibaren geçerli)';
                    }
                }
                uysa_audit('musteri_kaydet', $u['username'], (string) $cid, json_encode(['cat' => $category]), client_ip());
                $flash = 'Müşteri kaydedildi · ' . $name . $fiyatNotu;
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

// Düzenlenen müşteri (form ön-dolum)
$edit = $editId ? $repo->customer($editId) : null;
$fName = $edit['name'] ?? '';
$fCat = $edit['category'] ?? 'uretim';
$fContact = $edit['contact'] ?? '';
$fPhone = $edit['phone'] ?? '';
$fEmail = $edit['email'] ?? '';
// opus-017: seçilen ayın ay-bazlı fiyatı (o ay > carry-forward > current default).
// Kart da bu değeri gösterir — ekranda görünen fiyat = hesaplarda geçerli fiyat.
$ayFiyat = $edit ? $repo->priceFor($editId, $month) : null;
$fPrice = $ayFiyat ? (float) $ayFiyat['unit_price'] : 0.0;           // satış
$fAlis = $ayFiyat ? (float) $ayFiyat['maliyet_birim'] : 0.0;         // alış
$fGider = $ayFiyat ? (float) $ayFiyat['tasima_sabit_gider'] : 0.0;   // sabit gider
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

          <div class="field"><label>Yetkili kişi</label>
            <input class="inputx" name="contact" value="<?= Helpers::e($fContact) ?>" autocapitalize="words" placeholder="ör. Ahmet Yılmaz">
          </div>
          <div class="field"><label>Telefon</label>
            <input class="inputx" type="tel" name="phone" value="<?= Helpers::e($fPhone) ?>" inputmode="tel" placeholder="05xx xxx xx xx">
          </div>
          <div class="field"><label>E-posta</label>
            <input class="inputx" type="email" name="email" value="<?= Helpers::e($fEmail) ?>" inputmode="email" autocapitalize="none" placeholder="ornek@firma.com">
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
            <p class="text-muted" style="font-size:11px;margin:4px 0 0"><strong><?= Helpers::e(ay_label_tr($month)) ?></strong> fiyatı — değiştirirsen bu aydan itibaren geçerli olur; geçmiş ayları aşağıdaki <em>Aylık fiyat</em> bölümünden düzelt.</p>
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

      <?php if ($edit): ?>
      <!-- opus-017: AYLIK FİYAT — o ayın fiyatını gör/düzenle; kaydedince o ay her yerde güncellenir -->
      <div class="cardx card-pad" id="aylik-fiyat">
        <h2>Aylık fiyat</h2>
        <p class="text-muted" style="font-size:12px">
          Fiyatlar aya göre değişir (zam). Bir ayın fiyatını değiştirince <strong>o ayın</strong>
          cirosu, kâr analizi ve carisi her yerde güncellenir; diğer aylar sabit kalır.
        </p>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <input type="hidden" name="action" value="aylik_fiyat">
          <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
          <div class="field"><label>Ay</label>
            <input class="inputx" type="month" name="fiyat_ay" value="<?= Helpers::e($month) ?>"
                   onchange="window.location='musteriler.php?edit=<?= (int) $edit['id'] ?>&ay='+this.value">
          </div>
          <div class="field">
            <label><?= $fCat === 'tasima' ? 'Birim fiyat — SATIŞ (₺ / adet)' : 'Birim fiyat (₺ / kişi)' ?></label>
            <input class="inputx" name="ay_unit_price" inputmode="decimal"
                   value="<?= Helpers::money((float) $ayFiyat['unit_price']) ?>" placeholder="0,00">
          </div>
          <?php if ($fCat === 'tasima'): ?>
          <div class="field"><label>Maliyet birim (₺ — alış)</label>
            <input class="inputx" name="ay_maliyet_birim" inputmode="decimal"
                   value="<?= Helpers::money((float) $ayFiyat['maliyet_birim']) ?>" placeholder="0,00">
          </div>
          <div class="field"><label>Aylık sabit gider (₺ — opsiyonel)</label>
            <input class="inputx" name="ay_sabit_gider" inputmode="decimal"
                   value="<?= Helpers::money((float) $ayFiyat['tasima_sabit_gider']) ?>" placeholder="0,00">
          </div>
          <?php endif; ?>
          <div class="hint-card" style="margin:0">
            Bu fiyat değişince <strong><?= Helpers::e(ay_label_tr($month)) ?></strong> her yerde güncellenir.
          </div>
          <div class="actions-row">
            <button class="btn-action btn-primaryx flex-fill" type="submit"><i class="bi bi-check2"></i> <?= Helpers::e(ay_label_tr($month)) ?> fiyatını kaydet</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <!-- ÜRETİM müşterileri -->
      <div class="section-head"><h2>Üretim müşterileri</h2><span class="text-muted" style="font-size:12px"><?= count($uretim) ?> firma</span></div>
      <div class="cardx card-pad">
        <?php if (!$uretim): ?>
          <div class="empty-state">
            <div class="es-ico"><i class="bi bi-people"></i></div>
            Üretim müşterisi yok.</div>
        <?php else: foreach ($uretim as $c): ?>
          <div class="customer-row">
            <div>
              <div class="row-title"><span class="status-dot"></span><strong><?= Helpers::e($c['name']) ?></strong>
                <?php if (($c['parasut_bakiye'] ?? null) !== null): ?><span class="badge-soft <?= (float) $c['parasut_bakiye'] < 0 ? 'badge-neg' : 'badge-ok' ?>" title="Paraşüt muhasebe bakiyesi">Paraşüt ₺ <?= Helpers::money((float) $c['parasut_bakiye']) ?></span><?php endif; ?>
              </div>
              <p class="row-meta">₺ <?= Helpers::money($repo->priceFor((int) $c['id'], $month)['unit_price']) ?> kişi başı · <?= Helpers::e(ay_label_tr($month)) ?></p>
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
          <div class="empty-state">
            <div class="es-ico"><i class="bi bi-people"></i></div>
            Taşıma müşterisi yok.</div>
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
