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

$defaultMonth = date('Y-m'); // içinde bulunduğumuz ay
$month = (string) ($_GET['ay'] ?? $defaultMonth);
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = $defaultMonth;
}
$prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));
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
    } elseif ($action === 'personel_ay') {
        $pid = (int) ($_POST['personel_id'] ?? 0);
        $ay = (string) ($_POST['ay'] ?? $month);
        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
            $ay = $month;
        }
        $gun = Helpers::parseMoney((string) ($_POST['calisma_gunu'] ?? '30'));
        $odendi = isset($_POST['maas_odendi']);
        $odemeTarihi = trim((string) ($_POST['odeme_tarihi'] ?? ''));
        $odemeTarihi = ($odemeTarihi !== '' && Helpers::isDate($odemeTarihi)) ? $odemeTarihi : null;
        if ($pid <= 0) {
            $flash = 'Personel seçilemedi.';
            $flashOk = false;
        } else {
            $repo->setPersonelMaasAy($pid, $ay, $gun, $odendi, $odemeTarihi);
            $p = $repo->personel($pid);
            uysa_audit('personel_maas_ay', $u['username'], (string) $pid, json_encode(['ay' => $ay, 'gun' => $gun, 'odendi' => $odendi]), client_ip());
            $flash = ($p['ad'] ?? 'Personel') . ' için ' . ay_label_tr($ay) . ' maaş kaydı güncellendi.';
            $month = $ay;
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
    } elseif ($action === 'gider_duzelt' || $action === 'gider_sil') {
        // fable-017: yanlış girilen avans/gider kaydını düzelt veya sil (önceden imkânsızdı).
        $gid = (int) ($_POST['gider_id'] ?? 0);
        if ($action === 'gider_sil') {
            $r = $repo->deletePersonelGider($gid);
            if (!$r) {
                $flash = 'Kayıt bulunamadı.';
                $flashOk = false;
            } else {
                uysa_audit('personel_gider_sil', $u['username'], (string) $gid,
                    json_encode(['ad' => $r['ad'], 'tur' => $r['tur'], 'tutar' => $r['tutar']]), client_ip());
                $flash = ($TUR_ETIKET[$r['tur']] ?? $r['tur']) . ' kaydı silindi · ₺ ' . Helpers::money($r['tutar']);
            }
        } else {
            $tutar = Helpers::parseMoney((string) ($_POST['tutar'] ?? '0'));
            $tarih = (string) ($_POST['tarih'] ?? '');
            $tarih = Helpers::isDate($tarih) ? $tarih : Helpers::today();
            $aciklama = trim((string) ($_POST['aciklama'] ?? '')) ?: null;
            if ($tutar <= 0) {
                $flash = 'Tutar sıfırdan büyük olmalı.';
                $flashOk = false;
            } else {
                $r = $repo->updatePersonelGider($gid, $tutar, $tarih, $aciklama);
                if (!$r) {
                    $flash = 'Kayıt bulunamadı.';
                    $flashOk = false;
                } else {
                    uysa_audit('personel_gider_duzelt', $u['username'], (string) $gid,
                        json_encode(['ad' => $r['ad'], 'eski' => $r['eski'], 'yeni' => $r['yeni']]), client_ip());
                    $flash = sprintf('%s düzeltildi%s: ₺ %s → ₺ %s',
                        $TUR_ETIKET[$r['tur']] ?? $r['tur'], $r['ad'] ? ' · ' . $r['ad'] : '',
                        Helpers::money($r['eski']), Helpers::money($r['yeni']));
                    $month = substr($tarih, 0, 7);
                }
            }
        }
    } elseif ($action === 'cikis') {
        // fable-015: işten çıkış — tarih + pasife düşür; o ayın maaşı kıst, kıdem donar.
        $pid = (int) ($_POST['personel_id'] ?? 0);
        $tarih = trim((string) ($_POST['cikis_tarihi'] ?? ''));
        $geriAl = isset($_POST['geri_al']);
        $p = $pid > 0 ? $repo->personel($pid) : null;
        if (!$p) {
            $flash = 'Personel bulunamadı.';
            $flashOk = false;
        } elseif (!$geriAl && !Helpers::isDate($tarih)) {
            $flash = 'Çıkış tarihi geçerli olmalı.';
            $flashOk = false;
        } elseif (!$geriAl && ($p['ise_giris'] ?? null) && $tarih < substr((string) $p['ise_giris'], 0, 10)) {
            $flash = 'Çıkış tarihi işe giriş tarihinden önce olamaz.';
            $flashOk = false;
        } else {
            $r = $repo->setPersonelCikis($pid, $geriAl ? null : $tarih);
            uysa_audit($geriAl ? 'personel_cikis_geri' : 'personel_cikis', $u['username'], (string) $pid,
                json_encode(['cikis' => $r['cikis'], 'kist_gun' => $r['kist_gun'], 'kidem' => $r['kidem']]), client_ip());
            $flash = $geriAl
                ? $r['ad'] . ' çıkışı geri alındı — yeniden aktif.'
                : sprintf('%s çıkış verildi (%s): pasife düştü · %s kıst maaşı %s gün = ₺ %s · biriken kıdem ₺ %s (fesihte ödenecek).',
                    $r['ad'], $tarih, ay_label_tr(substr($tarih, 0, 7)),
                    rtrim(rtrim(number_format($r['kist_gun'], 2, ',', '.'), '0'), ','),
                    Helpers::money($r['kist_maas']), Helpers::money($r['kidem']));
            if (!$geriAl) {
                $month = substr($tarih, 0, 7);
            }
        }
    } elseif ($action === 'pasif') {
        $pid = (int) ($_POST['id'] ?? 0);
        if ($pid > 0) {
            $repo->setPersonelActive($pid, false);
            $flash = 'Personel pasifleştirildi.';
        }
    }
}

// fable-015: seçili ayda kadroda olanlar. Çıkış verilen kişi PASİF olur ama çıkış ayının kıst
// maaşını hak eder → o ay listede kalmalı; sonraki aylarda düşer. Girişi sonraki ayda olan görünmez.
$ayBas = $month . '-01';
$ayBitis = date('Y-m-t', strtotime($ayBas));
$personeller = array_values(array_filter(
    $repo->listPersonel(false),
    static function (array $p) use ($ayBas, $ayBitis): bool {
        $g = (string) ($p['ise_giris'] ?? '');
        $c = (string) ($p['ise_cikis'] ?? '');
        if ($g !== '' && substr($g, 0, 10) > $ayBitis) {
            return false;                                   // henüz işe girmemiş
        }
        if ($c !== '') {
            return substr($c, 0, 10) >= $ayBas;             // çıkmış → yalnız çıkış ayına kadar
        }
        return (int) $p['is_active'] === 1;                 // çıkışsız pasifler gizli (eski davranış)
    }
));
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
    $maas = $repo->personelMaasAy($pid, $month);
    $y = $repo->personelYukluMaliyet($pid, $month);
    $k = $repo->kidemBirikim($pid, $month);
    $a = $repo->personelAtama($pid);
    $yukluToplam += $y['yuklu_toplam'];
    $pInfo[$pid] = ['yuklu' => $y, 'kidem' => $k, 'atama' => $a, 'maas' => $maas, 'avans' => $repo->personelAvansAy($pid, $month)];
}
$ucretToplam = 0.0;
$maasToplam = 0.0;
$maasOdenen = 0.0;
foreach ($personeller as $p) {
    $ucretToplam += (float) $p['aylik_ucret'];
    $mi = $pInfo[(int) $p['id']]['maas'];
    $maasToplam += (float) $mi['hesaplanan_maas'];
    // fable-017: Ödenen = maaş ödendiyse TAMAMI (avans zaten netten düşülerek verildi);
    // ödenmediyse verilen AVANS kadarı — o para kişinin eline geçti, bekleyenden düşmeli.
    // min(): avans maaşı aşarsa fazlası bu ayın ödemesi değil (sonraki aya sarkar).
    $maasOdenen += $mi['maas_odendi']
        ? (float) $mi['hesaplanan_maas']
        : min((float) $pInfo[(int) $p['id']]['avans'], (float) $mi['hesaplanan_maas']);
}
$maasBekleyen = max(0.0, $maasToplam - $maasOdenen);
$pct = static fn(float $o): string => number_format($o * 100, 1, ',', '.');

$pageTitle = 'Personel Giderleri';
$eyebrow = 'Maaş / çalışma günü · ' . ay_label_tr($month);
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <form method="get" class="date-row">
        <a class="btn-action btn-ghost" href="personel.php?ay=<?= Helpers::e($prevMonth) ?>"><i class="bi bi-chevron-left"></i></a>
        <div class="date-pill"><i class="bi bi-calendar2-week"></i>
          <input type="month" name="ay" value="<?= Helpers::e($month) ?>" onchange="this.form.submit()">
        </div>
        <a class="btn-action btn-ghost" href="personel.php?ay=<?= Helpers::e($nextMonth) ?>"><i class="bi bi-chevron-right"></i></a>
      </form>

      <div class="stat-stack">
        <div class="stat-card stat-orange">
          <div class="ico"><i class="bi bi-cash-stack"></i></div>
          <div class="txt">
            <p class="lbl">Seçili ay işveren maliyeti <span style="font-size:11px;font-weight:600">(çalışma gününe göre)</span></p>
            <p class="val">₺ <?= Helpers::money($yukluToplam) ?></p>
          </div>
        </div>
        <div class="stat-card stat-blue">
          <div class="ico"><i class="bi bi-people-fill"></i></div>
          <div class="txt">
            <p class="lbl">Hesaplanan brüt maaş</p>
            <p class="val">₺ <?= Helpers::money($maasToplam) ?> <span style="font-size:14px;font-weight:600">· <?= count($personeller) ?> kişi</span></p>
          </div>
        </div>
        <div class="stat-card stat-orange">
          <div class="ico"><i class="bi bi-piggy-bank"></i></div>
          <div class="txt">
            <p class="lbl">Maaş ödeme durumu <span style="font-size:11px;font-weight:600">(ödendi / bekleyen · avans dahil)</span></p>
            <p class="val">₺ <?= Helpers::money($maasOdenen) ?> <span style="font-size:14px;font-weight:600">/ ₺ <?= Helpers::money($maasBekleyen) ?></span></p>
          </div>
        </div>
      </div>

      <div class="hint-card" style="margin-bottom:12px">
        Çalışma günü 30 bazlıdır: 27 gün girersen maaş 27/30 hesaplanır. Ödendi işaretlenince bu aya otomatik maaş gideri yazılır.
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
          <div class="empty-state">
            <div class="es-ico"><i class="bi bi-person-badge"></i></div>
            Henüz personel yok. <a href="personel.php?ay=<?= $month ?>&yeni=1" style="color:var(--primary);font-weight:700">Personel ekle →</a></div>
        <?php else: foreach ($personeller as $p): $pid = (int) $p['id']; $info = $pInfo[$pid]; $y = $info['yuklu']; $k = $info['kidem']; $at = $info['atama']; $maas = $info['maas'];
          $atamaLbl = $at['genel'] ? 'Genel (hacme oranlı)' : ($at['customer_ids'] ? count($at['customer_ids']) . ' müşteri (eşit)' : 'atanmamış');
          $paid = (bool) $maas['maas_odendi'];
          $odemeTarihi = $maas['odeme_tarihi'] ?: date('Y-m-t', strtotime($month . '-01'));
          $gunKesinti = max(0.0, (float) $p['aylik_ucret'] - (float) $maas['hesaplanan_maas']);
          $netOde = max(0.0, (float) $maas['hesaplanan_maas'] - $info['avans']); ?>
          <details class="personel-detail" style="border-bottom:1px solid var(--border);padding:8px 0">
            <summary class="customer-row" style="align-items:center;cursor:pointer;list-style:none">
              <div style="min-width:0;flex:1">
                <div class="row-title"><span class="status-dot"></span><strong><?= Helpers::e($p['ad']) ?></strong><?php
                  if (($p['ise_cikis'] ?? null) !== null): ?> <span class="row-meta" style="color:var(--red);font-weight:700"><i class="bi bi-box-arrow-right"></i> çıkış <?= Helpers::e(substr((string) $p['ise_cikis'], 0, 10)) ?></span><?php endif; ?></div>
                <p class="row-meta"><?= $p['gorev'] ? Helpers::e($p['gorev']) . ' · ' : '' ?><?= Helpers::e(ay_label_tr($month)) ?> · <?= Helpers::money((float) $maas['calisma_gunu']) ?> gün · <?= $paid ? 'ödendi' : 'öde ₺ ' . Helpers::money($netOde) ?></p>
              </div>
              <span class="row-meta" style="font-weight:700;color:var(--primary)">detay</span>
            </summary>
            <div style="padding:10px 4px 4px 24px">
              <form method="post" class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-bottom:10px">
                <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
                <input type="hidden" name="action" value="personel_ay">
                <input type="hidden" name="personel_id" value="<?= $pid ?>">
                <input type="hidden" name="ay" value="<?= Helpers::e($month) ?>">
                <div class="field"><label>Çalışma günü</label>
                  <input class="inputx" name="calisma_gunu" inputmode="decimal" value="<?= Helpers::money((float) $maas['calisma_gunu']) ?>">
                </div>
                <div class="field"><label>Ödeme tarihi</label>
                  <input class="inputx" type="date" name="odeme_tarihi" value="<?= Helpers::e($odemeTarihi) ?>">
                </div>
                <label style="display:flex;align-items:center;gap:8px;font-weight:700;margin-top:22px">
                  <input type="checkbox" name="maas_odendi" value="1" <?= $paid ? 'checked' : '' ?>> Maaşı ödendi
                </label>
                <button class="btn-action btn-primaryx" type="submit" style="align-self:end"><i class="bi bi-check2"></i> Kaydet</button>
              </form>
              <table class="tablex" style="margin-top:6px;font-size:13px">
                <tbody>
                  <tr><td>Aylık maaş</td><td class="num">₺ <?= Helpers::money((float) $p['aylik_ucret']) ?></td></tr>
                  <?php if ($gunKesinti > 0): ?>
                  <tr><td>Gün kesintisi (<?= Helpers::money((float) $maas['eksik_gun']) ?> eksik gün)</td><td class="num" style="color:var(--red)">− ₺ <?= Helpers::money($gunKesinti) ?></td></tr>
                  <?php endif; ?>
                  <?php if ($info['avans'] > 0): ?>
                  <tr><td>Avans kesintisi</td><td class="num" style="color:var(--red)">− ₺ <?= Helpers::money($info['avans']) ?></td></tr>
                  <?php endif; ?>
                  <tr class="is-total"><td><?= Helpers::e(ay_label_tr($month)) ?> ödenecek</td><td class="num">₺ <?= Helpers::money($netOde) ?></td></tr>
                </tbody>
              </table>
              <?php if ($info['avans'] > (float) $maas['hesaplanan_maas']): ?>
              <p class="row-meta" style="color:var(--red);margin-top:4px">Avans maaşı aştı — fark ₺ <?= Helpers::money($info['avans'] - (float) $maas['hesaplanan_maas']) ?> sonraki aya sarkar.</p>
              <?php endif; ?>
              <details style="margin-top:10px">
                <summary style="cursor:pointer;list-style:none;font-weight:700;font-size:12px;color:var(--primary)"><i class="bi bi-calculator"></i> İşveren maliyeti detayı</summary>
                <p class="row-meta" style="margin:6px 0 0"><?= Helpers::e($atamaLbl) ?></p>
                <table class="tablex" style="margin-top:6px;font-size:12px">
                  <tbody>
                    <tr><td>Brüt (çalışma gününe göre)</td><td class="num">₺ <?= Helpers::money($y['brut']) ?></td></tr>
                    <tr><td>İşveren SGK (%<?= $pct($y['sgk_orani']) ?>)</td><td class="num">+ ₺ <?= Helpers::money($y['sgk_isveren']) ?></td></tr>
                    <tr><td>Kıdem aylık tahakkuk<?= $y['tavan_uygulandi'] ? ' (tavan)' : '' ?></td><td class="num">+ ₺ <?= Helpers::money($y['kidem_aylik']) ?></td></tr>
                    <tr><td>Diğer maliyet</td><td class="num">+ ₺ <?= Helpers::money($y['diger']) ?></td></tr>
                    <tr class="is-total"><td>Yüklü aylık maliyet</td><td class="num">₺ <?= Helpers::money($y['yuklu_toplam']) ?></td></tr>
                    <tr><td>Biriken kıdem (<?= (int) $k['ay_sayisi'] ?> ay)</td><td class="num">₺ <?= Helpers::money($k['birikim']) ?></td></tr>
                  </tbody>
                </table>
              </details>
              <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:10px;padding:10px;border:1px solid var(--border);border-radius:10px">
                <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
                <input type="hidden" name="action" value="gider">
                <input type="hidden" name="personel_id" value="<?= $pid ?>">
                <input type="hidden" name="tur" value="avans">
                <div class="field" style="flex:1;min-width:110px;margin:0"><label>Avans (₺)</label>
                  <input class="inputx" name="tutar" inputmode="decimal" placeholder="0,00" required>
                </div>
                <div class="field" style="flex:1;min-width:130px;margin:0"><label>Tarih</label>
                  <input class="inputx" type="date" name="tarih" value="<?= Helpers::e(Helpers::today()) ?>">
                </div>
                <button class="btn-action btn-primaryx" type="submit"><i class="bi bi-cash-coin"></i> Avans ekle</button>
              </form>
              <?php $cikisT = ($p['ise_cikis'] ?? null) !== null ? substr((string) $p['ise_cikis'], 0, 10) : null; ?>
              <?php if ($cikisT === null): ?>
                <!-- fable-015: Çıkış bildir — tarih + pasife düşür; o ayın maaşı kıst, kıdem donar -->
                <details style="margin-top:10px">
                  <summary style="cursor:pointer;list-style:none;font-weight:700;font-size:12px;color:var(--red)"><i class="bi bi-box-arrow-right"></i> Çıkış bildir</summary>
                  <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:8px;padding:10px;border:1px solid var(--border);border-radius:10px"
                        onsubmit="return confirm('<?= Helpers::e($p['ad']) ?> için çıkış veriliyor: pasife düşecek, o ayın maaşı çıkış gününe göre hesaplanacak. Onaylıyor musun?')">
                    <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
                    <input type="hidden" name="action" value="cikis">
                    <input type="hidden" name="personel_id" value="<?= $pid ?>">
                    <div class="field" style="flex:1;min-width:130px;margin:0"><label>Çıkış tarihi</label>
                      <input class="inputx" type="date" name="cikis_tarihi" value="<?= Helpers::e(Helpers::today()) ?>"
                             <?= ($p['ise_giris'] ?? null) ? 'min="' . Helpers::e(substr((string) $p['ise_giris'], 0, 10)) . '"' : '' ?> required>
                    </div>
                    <button class="btn-action btn-ghost" type="submit" style="color:var(--red)"><i class="bi bi-box-arrow-right"></i> Çıkışı kaydet</button>
                  </form>
                  <p class="row-meta" style="margin-top:6px">Çıkışta: <strong>aktif → pasif</strong>, çıkış ayının maaşı
                    <strong>çıkış gününe kadar</strong> hesaplanır (15'inde çıkarsa 15/30), biriken kıdem o tarihte donar
                    (₺ <?= Helpers::money($k['birikim']) ?> fesihte ödenecek).</p>
                </details>
              <?php else: ?>
                <p class="row-meta" style="margin-top:10px;color:var(--red)"><i class="bi bi-box-arrow-right"></i>
                  <strong>Çıkış verildi: <?= Helpers::e($cikisT) ?></strong> · <?= Helpers::e(ay_label_tr(substr($cikisT, 0, 7))) ?>
                  kıst maaşı ₺ <?= Helpers::money((float) $maas['hesaplanan_maas']) ?> · fesih kıdemi ₺ <?= Helpers::money($k['birikim']) ?>
                  (<?= (int) $k['ay_sayisi'] ?> ay).</p>
              <?php endif; ?>
              <div class="actions-row" style="margin-top:8px">
                <a class="btn-action btn-ghost flex-fill" href="personel.php?ay=<?= $month ?>&duzenle=<?= $pid ?>"><i class="bi bi-pencil"></i> Düzenle</a>
                <?php if ($cikisT !== null): ?>
                <form method="post" onsubmit="return confirm('<?= Helpers::e($p['ad']) ?> çıkışı geri alınsın mı? (yeniden aktif olur)')" class="flex-fill">
                  <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
                  <input type="hidden" name="action" value="cikis">
                  <input type="hidden" name="personel_id" value="<?= $pid ?>">
                  <input type="hidden" name="geri_al" value="1">
                  <button class="btn-action btn-ghost" type="submit" style="width:100%"><i class="bi bi-arrow-counterclockwise"></i> Çıkışı geri al</button>
                <?php else: ?>
                <form method="post" onsubmit="return confirm('Personel pasifleştirilsin mi? (gider geçmişi korunur)')" class="flex-fill">
                  <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
                  <input type="hidden" name="action" value="pasif">
                  <input type="hidden" name="id" value="<?= $pid ?>">
                  <button class="btn-action btn-ghost" type="submit" style="color:var(--red);width:100%"><i class="bi bi-person-dash"></i> Pasif</button>
                <?php endif; ?>
                </form>
              </div>
            </div>
          </details>
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
            <!-- fable-017: yanlış tutar girildiyse silip yeniden girme — buradan düzelt -->
            <details style="margin:-4px 0 8px 38px">
              <summary style="cursor:pointer;list-style:none;font-size:12px;font-weight:700;color:var(--primary)">düzelt / sil</summary>
              <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:6px">
                <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
                <input type="hidden" name="action" value="gider_duzelt">
                <input type="hidden" name="gider_id" value="<?= (int) $g['id'] ?>">
                <div class="field" style="flex:1;min-width:100px;margin:0"><label>Tutar (₺)</label>
                  <input class="inputx" name="tutar" inputmode="decimal" value="<?= Helpers::e(Helpers::money((float) $g['tutar'])) ?>" required>
                </div>
                <div class="field" style="flex:1;min-width:130px;margin:0"><label>Tarih</label>
                  <input class="inputx" type="date" name="tarih" value="<?= Helpers::e(substr((string) $g['tarih'], 0, 10)) ?>">
                </div>
                <div class="field" style="flex:1;min-width:120px;margin:0"><label>Açıklama</label>
                  <input class="inputx" name="aciklama" value="<?= Helpers::e((string) ($g['aciklama'] ?? '')) ?>">
                </div>
                <button class="btn-action btn-primaryx" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
              </form>
              <form method="post" style="margin-top:6px" onsubmit="return confirm('Bu kayıt silinsin mi? (geri alınamaz)')">
                <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
                <input type="hidden" name="action" value="gider_sil">
                <input type="hidden" name="gider_id" value="<?= (int) $g['id'] ?>">
                <button class="btn-action btn-ghost" type="submit" style="color:var(--red)"><i class="bi bi-trash3"></i> Sil</button>
              </form>
            </details>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="hint-card mt-3">
        Personel gideri, Kâr/Zarar ekranındaki <strong>net karlılık</strong> hesabına ayrı kalem olarak yansır (finans nakit akışından bağımsız).
      </div>
<?php require __DIR__ . '/partials/footer.php'; ?>
