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
$editId = (int) ($_GET['duzenle'] ?? 0) ?: null;
$formOpen = isset($_GET['yeni']) || $editId !== null;
$giderOpen = isset($_GET['gider']);
$ayarOpen = isset($_GET['ayar']);

$TUR_ETIKET = ['maas' => 'Maaş', 'prim' => 'Prim', 'avans' => 'Avans', 'sgk' => 'SGK', 'diger' => 'Diğer'];

// ── POST işlemleri (CSRF) ────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } elseif ($action === 'personel') {
        $ad = trim((string) ($_POST['ad'] ?? ''));
        $gorev = trim((string) ($_POST['gorev'] ?? '')) ?: null;
        $ucret = Helpers::parseMoney((string) ($_POST['aylik_ucret'] ?? '0'));
        $iseGiris = trim((string) ($_POST['ise_giris'] ?? ''));
        $iseGiris = ($iseGiris !== '' && Helpers::isDate($iseGiris)) ? $iseGiris : null;
        $digerRaw = trim((string) ($_POST['diger_maliyet'] ?? ''));
        $digerMaliyet = $digerRaw === '' ? null : Helpers::parseMoney($digerRaw); // boş = default orandan
        $genel = isset($_POST['genel']);
        $musteriIds = array_map('intval', (array) ($_POST['musteri'] ?? []));
        $pid = (int) ($_POST['id'] ?? 0) ?: null;
        if ($ad === '') {
            $flash = 'Personel adı zorunlu.';
            $flashOk = false;
            $formOpen = true;
        } else {
            $newId = $repo->upsertPersonel($ad, $gorev, $ucret, $pid, $iseGiris, $digerMaliyet);
            $repo->setPersonelAtama($newId, $genel, $genel ? [] : $musteriIds);
            uysa_audit('personel_kaydet', $u['username'], (string) $newId, null, client_ip());
            $flash = $pid ? 'Personel güncellendi · ' . $ad : 'Personel eklendi · ' . $ad;
        }
    } elseif ($action === 'ayar') {
        // Mevzuat oranları (yüzde girdisi → orana çevrilir; tavan TL). Default mevzuat, Ömer düzenler.
        $sgk = Helpers::parseMoney((string) ($_POST['sgk_isveren_yuzde'] ?? '22,5')) / 100;
        $tavan = Helpers::parseMoney((string) ($_POST['kidem_tavan'] ?? '0'));
        $digerOran = Helpers::parseMoney((string) ($_POST['diger_maliyet_yuzde'] ?? '0')) / 100;
        $repo->ayarSet('sgk_isveren_orani', (string) round($sgk, 4));
        if ($tavan > 0) {
            $repo->ayarSet('kidem_tavan', (string) round($tavan, 2));
        }
        $repo->ayarSet('diger_maliyet_oran', (string) round($digerOran, 4));
        uysa_audit('personel_ayar', $u['username'], 'mevzuat', null, client_ip());
        $flash = 'Mevzuat oranları güncellendi.';
    } elseif ($action === 'gider') {
        $pid = (int) ($_POST['personel_id'] ?? 0) ?: null;
        $tur = (string) ($_POST['tur'] ?? 'maas');
        if (!in_array($tur, Repo::PERSONEL_GIDER_TUR, true)) {
            $tur = 'maas';
        }
        $tutar = Helpers::parseMoney((string) ($_POST['tutar'] ?? '0'));
        $tarih = (string) ($_POST['tarih'] ?? Helpers::today());
        if (!Helpers::isDate($tarih)) {
            $tarih = Helpers::today();
        }
        $aciklama = trim((string) ($_POST['aciklama'] ?? '')) ?: null;
        if ($tutar <= 0) {
            $flash = 'Tutar sıfırdan büyük olmalı.';
            $flashOk = false;
            $giderOpen = true;
        } else {
            $repo->addPersonelGider($pid, $tarih, $tur, $tutar, $aciklama);
            uysa_audit('personel_gider', $u['username'], (string) ($pid ?? 0), json_encode(['tur' => $tur, 't' => $tutar]), client_ip());
            $flash = ($TUR_ETIKET[$tur] ?? $tur) . ' gideri eklendi · ₺ ' . Helpers::money($tutar);
            $month = substr($tarih, 0, 7);
        }
    } elseif ($action === 'pasif') {
        $pid = (int) ($_POST['id'] ?? 0);
        if ($pid > 0) {
            $repo->setPersonelActive($pid, false);
            $flash = 'Personel pasifleştirildi.';
        }
    }
}

$personeller = $repo->listPersonel();
$editP = $editId ? $repo->personel($editId) : null;
$editAtama = $editId ? $repo->personelAtama($editId) : ['genel' => false, 'customer_ids' => []];
$aylikToplam = $repo->monthPersonelTotal($month);
$byType = $repo->monthPersonelByType($month);
$giderler = $repo->monthPersonelGider($month);
$custList = $repo->activeCustomers();
$ayarlar = $repo->ayarlar();
$sgkOrani = (float) ($ayarlar['sgk_isveren_orani'] ?? 0.225);
$digerOran = (float) ($ayarlar['diger_maliyet_oran'] ?? 0);
$kidemTavan = (float) ($ayarlar['kidem_tavan'] ?? 64948.77);
$kidemTot = $repo->kidemToplamYukumluluk($month);

// Personel bazlı yüklü maliyet + kıdem birikimi + atama (kartlarda göster)
$yukluToplam = 0.0;
$pInfo = [];
foreach ($personeller as $p) {
    $pid = (int) $p['id'];
    $y = $repo->personelYukluMaliyet($pid);
    $k = $repo->kidemBirikim($pid, $month);
    $a = $repo->personelAtama($pid);
    $yukluToplam += $y['yuklu_toplam'];
    $pInfo[$pid] = ['yuklu' => $y, 'kidem' => $k, 'atama' => $a];
}
$ucretToplam = 0.0;
foreach ($personeller as $p) {
    $ucretToplam += (float) $p['aylik_ucret'];
}
$pct = static fn(float $o): string => number_format($o * 100, 1, ',', '.');

$pageTitle = 'Personel Giderleri';
$eyebrow = 'Maaş / prim takibi · ' . ay_label_tr($month);
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <form method="get" class="date-row">
        <div class="date-pill"><i class="bi bi-calendar2-week"></i>
          <input type="month" name="ay" value="<?= Helpers::e($month) ?>" onchange="this.form.submit()">
        </div>
      </form>

      <div class="stat-stack">
        <div class="stat-card stat-orange">
          <div class="ico"><i class="bi bi-cash-stack"></i></div>
          <div class="txt">
            <p class="lbl">Yüklü işveren maliyeti / ay <span style="font-size:11px;font-weight:600">(brüt+SGK+kıdem+diğer)</span></p>
            <p class="val">₺ <?= Helpers::money($yukluToplam) ?></p>
          </div>
        </div>
        <div class="stat-card stat-blue">
          <div class="ico"><i class="bi bi-people-fill"></i></div>
          <div class="txt">
            <p class="lbl">Aktif personel · brüt</p>
            <p class="val"><?= count($personeller) ?> <span style="font-size:14px;font-weight:600">kişi · ₺ <?= Helpers::money($ucretToplam) ?>/ay</span></p>
          </div>
        </div>
        <div class="stat-card stat-orange">
          <div class="ico"><i class="bi bi-piggy-bank"></i></div>
          <div class="txt">
            <p class="lbl">Biriken kıdem yükümlülüğü <span style="font-size:11px;font-weight:600">(fesihte ödenir · bu ay +₺<?= Helpers::money($kidemTot['bu_ay_tahakkuk']) ?>)</span></p>
            <p class="val">₺ <?= Helpers::money($kidemTot['birikim']) ?></p>
          </div>
        </div>
      </div>

      <div class="hint-card" style="margin-bottom:12px">
        Ödenen (nakit gider) = brüt + işveren SGK + diğer · Tahakkuk (biriken borç) = aylık kıdem.
        Oranlar mevzuat varsayılanı, <a href="personel.php?ay=<?= $month ?>&ayar=1" style="color:var(--primary);font-weight:700">Mevzuat ayarları</a>'ndan değiştirilir.
      </div>

      <!-- Mevzuat ayarları (SGK / kıdem tavan / diğer maliyet oranı) -->
      <div class="fab-sheet" id="ayar-form" style="<?= $ayarOpen ? '' : 'display:none' ?>">
        <h2>Mevzuat ayarları</h2>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <input type="hidden" name="action" value="ayar">
          <div class="field"><label>İşveren SGK payı (%)</label>
            <input class="inputx" name="sgk_isveren_yuzde" inputmode="decimal" value="<?= $pct($sgkOrani) ?>" placeholder="22,5">
          </div>
          <div class="field"><label>Kıdem tazminatı aylık tavanı (₺)</label>
            <input class="inputx" name="kidem_tavan" inputmode="decimal" value="<?= Helpers::money($kidemTavan) ?>" placeholder="64.948,77">
          </div>
          <div class="field"><label>Diğer maliyet varsayılan oranı (%)</label>
            <input class="inputx" name="diger_maliyet_yuzde" inputmode="decimal" value="<?= $pct($digerOran) ?>" placeholder="0">
          </div>
          <div class="actions-row">
            <a class="btn-action btn-ghost flex-fill" href="personel.php?ay=<?= $month ?>">Vazgeç</a>
            <button class="btn-action btn-primaryx flex-fill" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
          </div>
        </form>
      </div>

      <?php if ($byType): ?>
      <div class="cardx card-pad">
        <h2>Tür kırılımı <span class="text-muted" style="font-size:12px;font-weight:600"><?= Helpers::e(ay_label_tr($month)) ?></span></h2>
        <table class="tablex">
          <tbody>
          <?php foreach ($TUR_ETIKET as $k => $lbl): if (empty($byType[$k])) continue; ?>
            <tr><td><?= Helpers::e($lbl) ?></td><td class="num">₺ <?= Helpers::money((float) $byType[$k]) ?></td></tr>
          <?php endforeach; ?>
            <tr class="is-total"><td>Toplam</td><td class="num">₺ <?= Helpers::money($aylikToplam) ?></td></tr>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <!-- Personel ekle / düzenle -->
      <div class="fab-sheet" id="personel-form" style="<?= $formOpen ? '' : 'display:none' ?>">
        <h2><?= $editP ? 'Personel düzenle' : 'Personel ekle' ?></h2>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <input type="hidden" name="action" value="personel">
          <?php if ($editP): ?><input type="hidden" name="id" value="<?= (int) $editP['id'] ?>"><?php endif; ?>
          <div class="field"><label>Ad soyad</label>
            <input class="inputx" name="ad" value="<?= Helpers::e($editP['ad'] ?? '') ?>" placeholder="ör. Ahmet Yılmaz" required>
          </div>
          <div class="field"><label>Görev</label>
            <input class="inputx" name="gorev" value="<?= Helpers::e($editP['gorev'] ?? '') ?>" placeholder="ör. Aşçı">
          </div>
          <div class="field"><label>Brüt aylık ücret (₺)</label>
            <input class="inputx" name="aylik_ucret" inputmode="decimal" value="<?= $editP ? Helpers::money((float) $editP['aylik_ucret']) : '' ?>" placeholder="0,00">
          </div>
          <div class="field"><label>İşe giriş tarihi (kıdem başlangıcı)</label>
            <input class="inputx" type="date" name="ise_giris" value="<?= Helpers::e($editP['ise_giris'] ?? '') ?>">
          </div>
          <div class="field"><label>Diğer maliyet (₺ · boş = varsayılan oran %<?= $pct($digerOran) ?>)</label>
            <input class="inputx" name="diger_maliyet" inputmode="decimal" value="<?= ($editP && $editP['diger_maliyet'] !== null) ? Helpers::money((float) $editP['diger_maliyet']) : '' ?>" placeholder="yemek/yol/ikramiye">
          </div>
          <div class="field"><label>Müşteri ataması (maliyet dağıtımı)</label>
            <label style="display:flex;align-items:center;gap:8px;font-weight:600;margin-bottom:6px">
              <input type="checkbox" name="genel" value="1" id="genel-cb" <?= $editAtama['genel'] ? 'checked' : '' ?> onchange="document.getElementById('musteri-list').style.display=this.checked?'none':''">
              Genel — tüm üretim müşterilerine hacme oranlı dağıt
            </label>
            <div id="musteri-list" style="<?= $editAtama['genel'] ? 'display:none' : '' ?>;max-height:180px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;padding:8px">
              <?php foreach ($custList as $c): ?>
                <label style="display:flex;align-items:center;gap:8px;padding:3px 0">
                  <input type="checkbox" name="musteri[]" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $editAtama['customer_ids'], true) ? 'checked' : '' ?>>
                  <?= Helpers::e($c['name']) ?> <span class="text-muted" style="font-size:11px"><?= $c['category'] === 'tasima' ? 'taşıma' : 'üretim' ?></span>
                </label>
              <?php endforeach; ?>
              <?php if (!$custList): ?><span class="text-muted" style="font-size:12px">Müşteri yok.</span><?php endif; ?>
            </div>
            <p class="row-meta" style="margin-top:4px">Seçili müşteri(ler): yüklü maliyet EŞİT bölünür. Genel: üretim hacmine oranlı.</p>
          </div>
          <div class="actions-row">
            <a class="btn-action btn-ghost flex-fill" href="personel.php?ay=<?= $month ?>">Vazgeç</a>
            <button class="btn-action btn-primaryx flex-fill" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
          </div>
        </form>
      </div>

      <!-- Gider ekle -->
      <div class="fab-sheet mt-3" id="gider-form" style="<?= $giderOpen ? '' : 'display:none' ?>">
        <h2>Gider ekle</h2>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <input type="hidden" name="action" value="gider">
          <div class="field"><label>Personel (opsiyonel — boş = toplu gider)</label>
            <select class="selectx" name="personel_id">
              <option value="">— toplu / kişisiz —</option>
              <?php foreach ($personeller as $p): ?>
                <option value="<?= (int) $p['id'] ?>"><?= Helpers::e($p['ad']) ?><?= $p['gorev'] ? ' (' . Helpers::e($p['gorev']) . ')' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Tür</label>
            <select class="selectx" name="tur">
              <?php foreach ($TUR_ETIKET as $k => $lbl): ?><option value="<?= $k ?>"><?= Helpers::e($lbl) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Tutar (₺)</label>
            <input class="inputx" name="tutar" inputmode="decimal" placeholder="0,00" required>
          </div>
          <div class="field"><label>Tarih</label>
            <input class="inputx" type="date" name="tarih" value="<?= Helpers::e(Helpers::today()) ?>">
          </div>
          <div class="field"><label>Açıklama (opsiyonel)</label>
            <input class="inputx" name="aciklama" placeholder="ör. Temmuz maaşı">
          </div>
          <div class="actions-row">
            <a class="btn-action btn-ghost flex-fill" href="personel.php?ay=<?= $month ?>">Vazgeç</a>
            <button class="btn-action btn-primaryx flex-fill" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
          </div>
        </form>
      </div>

      <?php if (!$formOpen && !$giderOpen && !$ayarOpen): ?>
      <div class="actions-row mt-3">
        <a class="btn-action btn-secondaryx flex-fill" href="personel.php?ay=<?= $month ?>&yeni=1"><i class="bi bi-person-plus"></i> Personel</a>
        <a class="btn-action btn-primaryx flex-fill" href="personel.php?ay=<?= $month ?>&gider=1"><i class="bi bi-plus-lg"></i> Gider ekle</a>
      </div>
      <?php endif; ?>

      <div class="section-head"><h2>Personel</h2><span class="text-muted" style="font-size:12px"><?= count($personeller) ?> kişi</span></div>
      <div class="cardx card-pad">
        <?php if (!$personeller): ?>
          <div class="empty-state">Henüz personel yok. <a href="personel.php?ay=<?= $month ?>&yeni=1" style="color:var(--primary);font-weight:700">Personel ekle →</a></div>
        <?php else: foreach ($personeller as $p): $pid = (int) $p['id']; $info = $pInfo[$pid]; $y = $info['yuklu']; $k = $info['kidem']; $at = $info['atama'];
          $atamaLbl = $at['genel'] ? 'Genel (hacme oranlı)' : ($at['customer_ids'] ? count($at['customer_ids']) . ' müşteri (eşit)' : 'atanmamış'); ?>
          <div class="customer-row" style="align-items:flex-start">
            <div style="min-width:0;flex:1">
              <div class="row-title"><span class="status-dot"></span><strong><?= Helpers::e($p['ad']) ?></strong></div>
              <p class="row-meta"><?= $p['gorev'] ? Helpers::e($p['gorev']) . ' · ' : '' ?>brüt ₺ <?= Helpers::money((float) $p['aylik_ucret']) ?>/ay · <?= Helpers::e($atamaLbl) ?></p>
              <table class="tablex" style="margin-top:6px;font-size:12px">
                <tbody>
                  <tr><td>Brüt (ödenen)</td><td class="num">₺ <?= Helpers::money($y['brut']) ?></td></tr>
                  <tr><td>İşveren SGK (%<?= $pct($y['sgk_orani']) ?>, ödenen)</td><td class="num">+ ₺ <?= Helpers::money($y['sgk_isveren']) ?></td></tr>
                  <tr><td>Kıdem aylık tahakkuk<?= $y['tavan_uygulandi'] ? ' (tavan)' : '' ?> (biriken borç)</td><td class="num">+ ₺ <?= Helpers::money($y['kidem_aylik']) ?></td></tr>
                  <tr><td>Diğer maliyet</td><td class="num">+ ₺ <?= Helpers::money($y['diger']) ?></td></tr>
                  <tr class="is-total"><td>Yüklü aylık maliyet</td><td class="num">₺ <?= Helpers::money($y['yuklu_toplam']) ?></td></tr>
                  <tr><td>Biriken kıdem (<?= (int) $k['ay_sayisi'] ?> ay)</td><td class="num">₺ <?= Helpers::money($k['birikim']) ?></td></tr>
                </tbody>
              </table>
            </div>
            <div style="text-align:right;flex-shrink:0;margin-left:8px">
              <a class="row-meta" href="personel.php?ay=<?= $month ?>&duzenle=<?= $pid ?>" style="color:var(--primary);font-weight:700;display:inline-block"><i class="bi bi-pencil"></i> düzenle</a>
              <form method="post" onsubmit="return confirm('Personel pasifleştirilsin mi? (gider geçmişi korunur)')" style="margin-top:4px">
                <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
                <input type="hidden" name="action" value="pasif">
                <input type="hidden" name="id" value="<?= $pid ?>">
                <button class="row-meta" type="submit" style="color:var(--red);font-weight:700;background:none;border:0;cursor:pointer;padding:0"><i class="bi bi-person-dash"></i> pasif</button>
              </form>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <?php if ($giderler): ?>
      <div class="section-head"><h2>Bu ayki giderler</h2></div>
      <div class="cardx card-pad">
        <div class="list-groupx">
          <?php foreach ($giderler as $g): ?>
            <div class="flow-item">
              <span class="flow-icon out"><i class="bi bi-cash"></i></span>
              <div style="min-width:0">
                <div class="row-title"><strong><?= Helpers::e($TUR_ETIKET[$g['tur']] ?? $g['tur']) ?><?= $g['personel_ad'] ? ' · ' . Helpers::e($g['personel_ad']) : ' · Toplu' ?></strong></div>
                <p class="row-meta"><?= Helpers::e(date('d.m.Y', strtotime($g['tarih']))) ?><?= $g['aciklama'] ? ' · ' . Helpers::e($g['aciklama']) : '' ?></p>
              </div>
              <span class="amount out">₺ <?= Helpers::money((float) $g['tutar']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="hint-card mt-3">
        Personel gideri, Kâr/Zarar ekranındaki <strong>net karlılık</strong> hesabına ayrı kalem olarak yansır (finans nakit akışından bağımsız).
      </div>
<?php require __DIR__ . '/partials/footer.php'; ?>
