<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;
use Uysa\TeklifPdf;

// fable-003 (cateringkolay Teklifler esinli) + fable-005 (yemekhaneci teklif motoru):
// müşteri adayı teklif takibi + markalı "Yemekhaneci formatında" teklif PDF'i.
// Durum akışı: taslak → gönderildi → kabul/red. Zorunlu tek alan: firma.
$u = Auth::requireLogin();
$pdo = Db::pdo();
$repo = new Repo($pdo);

// ── PDF (header'dan ÖNCE) — teklif → yemekhaneci formatı PDF ──
// fable-008: inline=1 → görüntüleyici iframe'i içinde açılır (attachment yerine).
if (isset($_GET['pdf'])) {
    $tid = (int) $_GET['pdf'];
    $t = $repo->teklifById($tid);
    if ($t) {
        $bin = TeklifPdf::render($t);
        $disp = isset($_GET['inline']) ? 'inline' : 'attachment';
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $disp . '; filename="teklif-T' . $tid . '.pdf"');
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;
    }
    header('Location: teklifler.php');
    exit;
}

// ── fable-008: app-içi PDF görüntüleyici — üstte X (kapat) + Paylaş barı ──
// (WKWebView PDF'i sayfanın YERİNE açıp geri yolu bırakmıyordu; Ömer app'i kapatmak
// zorunda kalıyordu. iframe + kendi barımızla çözüldü; Paylaş = Web Share API, dosyayla.)
if (isset($_GET['goruntule'])) {
    $tid = (int) $_GET['goruntule'];
    if (!$repo->teklifById($tid)) {
        header('Location: teklifler.php');
        exit;
    }
    $pdfUrl = 'teklifler.php?pdf=' . $tid;
    ?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Teklif #T-<?= $tid ?> · PDF</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  html,body{margin:0;height:100%;background:#33373d;font-family:-apple-system,BlinkMacSystemFont,system-ui,sans-serif}
  .pdf-bar{position:fixed;top:0;left:0;right:0;height:calc(52px + env(safe-area-inset-top));padding-top:env(safe-area-inset-top);
    background:#0e6e74;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:8px;z-index:5}
  .pdf-bar a,.pdf-bar button{display:flex;align-items:center;gap:6px;background:none;border:0;color:#fff;
    font-size:15px;font-weight:700;padding:12px 16px;text-decoration:none;cursor:pointer}
  .pdf-bar .ttl{font-size:14px;font-weight:600;opacity:.9;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .pdf-frame{position:fixed;top:calc(52px + env(safe-area-inset-top));left:0;right:0;bottom:0;width:100%;border:0;
    height:calc(100% - 52px - env(safe-area-inset-top))}
</style>
</head>
<body>
  <div class="pdf-bar">
    <a href="teklifler.php" aria-label="Kapat"><i class="bi bi-x-lg"></i> Kapat</a>
    <span class="ttl">Teklif #T-<?= $tid ?></span>
    <button type="button" onclick="paylas()"><i class="bi bi-share"></i> Paylaş</button>
  </div>
  <iframe class="pdf-frame" src="<?= $pdfUrl ?>&inline=1"></iframe>
  <script>
    async function paylas(){
      var url = <?= json_encode($pdfUrl . '&inline=1') ?>;
      var fname = <?= json_encode('teklif-T' . $tid . '.pdf') ?>;
      try {
        var r = await fetch(url); var b = await r.blob();
        var f = new File([b], fname, {type: 'application/pdf'});
        if (navigator.canShare && navigator.canShare({files: [f]})) {
          await navigator.share({files: [f], title: fname});
          return;
        }
      } catch (e) { /* iptal veya desteksiz → indirmeye düş */ }
      location.href = <?= json_encode($pdfUrl) ?>; // fallback: indir
    }
  </script>
</body>
</html><?php
    exit;
}

$flash = '';
$flashOk = true;

/** POST → yemekhaneci teklif motoru alanlarını topla (menu_json/personel_json dahil). */
$buildFields = static function (array $p): array {
    $iv = static fn (string $k): int => (int) ($p[$k] ?? 0);
    $sv = static function (string $k, int $max) use ($p): ?string {
        $x = trim((string) ($p[$k] ?? ''));
        return $x !== '' ? mb_substr($x, 0, $max) : null;
    };

    $menu = [
        'corba'  => max(0, $iv('menu_corba')),
        'ana'    => max(0, $iv('menu_ana')),
        'yan'    => max(0, $iv('menu_yan')),
        'salata' => max(0, $iv('menu_salata')),
        'tatli'  => isset($p['menu_tatli']) ? 1 : 0,
        'icecek' => in_array($p['menu_icecek'] ?? '', ['ayran', 'yogurt', 'donusumlu', 'ikisi'], true) ? (string) $p['menu_icecek'] : '',
        'ekmek'  => isset($p['menu_ekmek']) ? 1 : 0,
    ];
    $menuHas = $menu['corba'] || $menu['ana'] || $menu['yan'] || $menu['salata'] || $menu['tatli'] || $menu['icecek'] !== '' || $menu['ekmek'];

    $pers = [
        'asci'     => max(0, $iv('pers_asci')),
        'servis'   => max(0, $iv('pers_servis')),
        'temizlik' => max(0, $iv('pers_temizlik')),
    ];
    $persHas = $pers['asci'] || $pers['servis'] || $pers['temizlik'];

    // fable-007: öğün bazlı fiyat cetveli (opsiyonel, KDV hariç) → fiyat_json
    $fv = static function (string $k) use ($p): ?float {
        $x = trim((string) ($p[$k] ?? ''));
        if ($x === '') {
            return null;
        }
        $n = Helpers::parseMoney($x);
        return $n > 0 ? $n : null;
    };
    $fiyat = [];
    foreach (['kahvalti', 'ogle', 'aksam', 'ara'] as $ogunKey) {
        $val = $fv('fiyat_' . $ogunKey);
        if ($val !== null) {
            $fiyat[$ogunKey] = $val;
        }
    }

    return [
        'fiyat_json'    => $fiyat !== [] ? json_encode($fiyat, JSON_UNESCAPED_UNICODE) : null,
        'giris_metni'   => $sv('giris_metni', 1500),
        'yetkili'       => $sv('yetkili', 120),
        'telefon'       => $sv('telefon', 40),
        'email'         => $sv('email', 120),
        'sehir'         => $sv('sehir', 80),
        'ilce'          => $sv('ilce', 80),
        'ogun_sayisi'   => max(1, min(9, $iv('ogun_sayisi') ?: 1)),
        'cumartesi'     => isset($p['cumartesi']) ? 1 : 0,
        'segment'       => in_array($p['segment'] ?? '', ['ekonomik', 'genel', 'premium'], true) ? (string) $p['segment'] : null,
        'menu_json'     => $menuHas ? json_encode($menu, JSON_UNESCAPED_UNICODE) : null,
        'personel_json' => $persHas ? json_encode($pers, JSON_UNESCAPED_UNICODE) : null,
        'ekipman'       => $sv('ekipman', 500),
    ];
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'yeni' || $action === 'guncelle') {
            $firma = trim((string) ($_POST['firma'] ?? ''));
            $kisi = (int) ($_POST['kisi'] ?? 0) ?: null;
            $fiyat = trim((string) ($_POST['birim_fiyat'] ?? ''));
            $fiyatF = $fiyat !== '' ? Helpers::parseMoney($fiyat) : null;
            $note = trim((string) ($_POST['note'] ?? '')) ?: null;
            $extra = $buildFields($_POST);
            if ($firma === '') {
                $flash = 'Firma adı zorunlu.';
                $flashOk = false;
            } elseif ($action === 'guncelle') {
                $id = (int) ($_POST['id'] ?? 0);
                $fields = array_merge($extra, [
                    'firma'       => mb_substr($firma, 0, 120),
                    'kisi'        => $kisi,
                    'birim_fiyat' => $fiyatF,
                    'note'        => $note ? mb_substr($note, 0, 500) : null,
                ]);
                if ($repo->updateTeklif($id, $fields)) {
                    uysa_audit('teklif_guncelle', $u['username'], (string) $id, $firma, client_ip());
                    $flash = "Teklif güncellendi: $firma";
                } else {
                    $flash = 'Teklif bulunamadı.';
                    $flashOk = false;
                }
            } else {
                $id = $repo->createTeklif(mb_substr($firma, 0, 120), $kisi, $fiyatF, $note ? mb_substr($note, 0, 500) : null, $extra);
                uysa_audit('teklif_yeni', $u['username'], (string) $id, $firma, client_ip());
                $flash = "Teklif kaydedildi: $firma";
            }
        } elseif ($action === 'durum') {
            $id = (int) ($_POST['id'] ?? 0);
            $durum = (string) ($_POST['durum'] ?? '');
            if (in_array($durum, Repo::TEKLIF_DURUM, true) && $repo->setTeklifDurum($id, $durum)) {
                uysa_audit('teklif_durum', $u['username'], (string) $id, $durum, client_ip());
                $flash = 'Teklif durumu güncellendi.';
            } else {
                $flash = 'Teklif bulunamadı.';
                $flashOk = false;
            }
        }
    }
}

// ── Düzenleme modu: ?edit=ID → form mevcut değerlerle dolar ──
$editId = (int) ($_GET['edit'] ?? 0) ?: null;
$ed = $editId ? $repo->teklifById($editId) : null;
$emenu = $ed && $ed['menu_json'] ? (json_decode((string) $ed['menu_json'], true) ?: []) : [];
$epers = $ed && $ed['personel_json'] ? (json_decode((string) $ed['personel_json'], true) ?: []) : [];
$efiyat = $ed && ($ed['fiyat_json'] ?? '') !== '' ? (json_decode((string) $ed['fiyat_json'], true) ?: []) : [];
$fv = static fn (string $k, string $def = ''): string => $ed !== null && isset($ed[$k]) && $ed[$k] !== null ? (string) $ed[$k] : $def;
$mv = static fn (string $k): string => isset($emenu[$k]) && (int) $emenu[$k] > 0 ? (string) (int) $emenu[$k] : '';
$pv = static fn (string $k): string => isset($epers[$k]) && (int) $epers[$k] > 0 ? (string) (int) $epers[$k] : '';
$fyv = static fn (string $k): string => isset($efiyat[$k]) && (float) $efiyat[$k] > 0 ? Helpers::money((float) $efiyat[$k]) : '';

$list = $repo->listTeklif();
$open = count(array_filter($list, static fn ($t) => in_array($t['durum'], ['taslak', 'gonderildi'], true)));
$csrf = Helpers::csrfToken();

$durumMap = [
    'taslak'     => ['badge-blue', 'bi-pencil', 'Taslak'],
    'gonderildi' => ['badge-warn', 'bi-send', 'Gönderildi'],
    'kabul'      => ['badge-ok', 'bi-check2-circle', 'Kabul'],
    'red'        => ['badge-neg', 'bi-x-circle', 'Red'],
];
$segmentOpts = ['' => '—', 'ekonomik' => 'Ekonomik', 'genel' => 'Genel', 'premium' => 'Premium'];
// fable-006 (Ömer): kefir kalktı; seçenekler Ayran · Yoğurt · Yoğurt/Ayran (dönüşümlü).
// 'ikisi' formdan kalktı ama eski kayıtlar için geçerli değer olarak kabul edilmeye devam eder.
$icecekOpts = ['' => '—', 'ayran' => 'Ayran', 'yogurt' => 'Yoğurt', 'donusumlu' => 'Yoğurt/Ayran (dönüşümlü)'];

$pageTitle = 'Teklifler';
$eyebrow = 'Müşteri adayı takibi';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <div class="stat-stack">
        <div class="stat-card stat-blue">
          <div class="ico"><i class="bi bi-briefcase"></i></div>
          <div class="txt"><p class="lbl">Açık teklif (taslak + gönderildi)</p><p class="val"><?= $open ?></p></div>
        </div>
      </div>

      <div class="fab-sheet">
        <h2><?= $ed ? 'Teklifi düzenle' : 'Yeni teklif' ?></h2>
        <form method="post" action="teklifler.php" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
          <input type="hidden" name="action" value="<?= $ed ? 'guncelle' : 'yeni' ?>">
          <?php if ($ed): ?><input type="hidden" name="id" value="<?= (int) $ed['id'] ?>"><?php endif; ?>
          <div class="field"><label>Firma</label>
            <input class="inputx" name="firma" maxlength="120" required placeholder="ör. ABC Tekstil" value="<?= Helpers::e($fv('firma')) ?>"></div>
          <div class="actions-row">
            <div class="field flex-fill"><label>Kişi / gün</label>
              <input class="inputx" name="kisi" type="number" inputmode="numeric" min="0" placeholder="ör. 80" value="<?= Helpers::e($fv('kisi')) ?>"></div>
            <div class="field flex-fill"><label>Tek fiyat ₺ (öğün fiyatları boşsa)</label>
              <input class="inputx" name="birim_fiyat" inputmode="decimal" placeholder="ör. 320" value="<?= $ed && $ed['birim_fiyat'] !== null ? Helpers::e(Helpers::money((float) $ed['birim_fiyat'])) : '' ?>"></div>
          </div>

          <details class="teklif-detay" <?= $ed && $efiyat ? 'open' : '' ?>>
            <summary style="cursor:pointer;font-weight:600;padding:6px 0"><i class="bi bi-cash-stack"></i> Fiyat cetveli &amp; giriş metni</summary>
            <p class="label mt-2" style="font-weight:600;font-size:13px">Öğün fiyatları (₺, KDV hariç · opsiyonel)</p>
            <div class="actions-row">
              <div class="field flex-fill"><label>Kahvaltı</label>
                <input class="inputx" name="fiyat_kahvalti" inputmode="decimal" placeholder="ör. 135" value="<?= Helpers::e($fyv('kahvalti')) ?>"></div>
              <div class="field flex-fill"><label>Öğle</label>
                <input class="inputx" name="fiyat_ogle" inputmode="decimal" placeholder="ör. 235" value="<?= Helpers::e($fyv('ogle')) ?>"></div>
              <div class="field flex-fill"><label>Akşam</label>
                <input class="inputx" name="fiyat_aksam" inputmode="decimal" placeholder="ör. 235" value="<?= Helpers::e($fyv('aksam')) ?>"></div>
              <div class="field flex-fill"><label>Ara Öğün</label>
                <input class="inputx" name="fiyat_ara" inputmode="decimal" placeholder="ör. 55" value="<?= Helpers::e($fyv('ara')) ?>"></div>
            </div>
            <div class="field"><label>Giriş metni (boşsa matbu genel metin kullanılır)</label>
              <textarea class="inputx" name="giris_metni" rows="3" maxlength="1500" placeholder="Kuruma özel giriş paragrafı — boş bırakılırsa standart metin yazılır."><?= Helpers::e($fv('giris_metni')) ?></textarea></div>
          </details>

          <details class="teklif-detay" <?= $ed ? 'open' : '' ?>>
            <summary style="cursor:pointer;font-weight:600;padding:6px 0"><i class="bi bi-sliders"></i> Detaylar (menü, personel, lokasyon)</summary>

            <div class="actions-row mt-2">
              <div class="field flex-fill"><label>Yetkili</label>
                <input class="inputx" name="yetkili" maxlength="120" placeholder="ör. Ali Bey" value="<?= Helpers::e($fv('yetkili')) ?>"></div>
              <div class="field flex-fill"><label>Telefon</label>
                <input class="inputx" name="telefon" maxlength="40" inputmode="tel" placeholder="0532..." value="<?= Helpers::e($fv('telefon')) ?>"></div>
            </div>
            <div class="field"><label>E-posta</label>
              <input class="inputx" name="email" type="email" maxlength="120" placeholder="ornek@firma.com" value="<?= Helpers::e($fv('email')) ?>"></div>
            <div class="actions-row">
              <div class="field flex-fill"><label>Şehir</label>
                <input class="inputx" name="sehir" maxlength="80" placeholder="Kocaeli" value="<?= Helpers::e($fv('sehir')) ?>"></div>
              <div class="field flex-fill"><label>İlçe</label>
                <input class="inputx" name="ilce" maxlength="80" placeholder="Gebze" value="<?= Helpers::e($fv('ilce')) ?>"></div>
            </div>
            <div class="actions-row">
              <div class="field flex-fill"><label>Günlük öğün</label>
                <input class="inputx" name="ogun_sayisi" type="number" min="1" max="9" placeholder="1" value="<?= Helpers::e($fv('ogun_sayisi')) ?>"></div>
              <div class="field flex-fill"><label>Paket</label>
                <select class="inputx" name="segment">
                  <?php foreach ($segmentOpts as $k => $lbl): ?>
                    <option value="<?= $k ?>" <?= $fv('segment') === $k ? 'selected' : '' ?>><?= Helpers::e($lbl) ?></option>
                  <?php endforeach; ?>
                </select></div>
            </div>
            <label class="chk-row" style="display:flex;align-items:center;gap:8px;margin:6px 0">
              <input type="checkbox" name="cumartesi" value="1" <?= $fv('cumartesi') === '1' ? 'checked' : '' ?>> Cumartesi dahil (haftada 6 gün)
            </label>

            <p class="label mt-2" style="font-weight:600;font-size:13px">Menü yapısı (günlük adet)</p>
            <div class="actions-row">
              <div class="field flex-fill"><label>Çorba</label>
                <input class="inputx" name="menu_corba" type="number" min="0" max="9" placeholder="0" value="<?= Helpers::e($mv('corba')) ?>"></div>
              <div class="field flex-fill"><label>Ana</label>
                <input class="inputx" name="menu_ana" type="number" min="0" max="9" placeholder="0" value="<?= Helpers::e($mv('ana')) ?>"></div>
              <div class="field flex-fill"><label>Yan</label>
                <input class="inputx" name="menu_yan" type="number" min="0" max="9" placeholder="0" value="<?= Helpers::e($mv('yan')) ?>"></div>
              <div class="field flex-fill"><label>Salata</label>
                <input class="inputx" name="menu_salata" type="number" min="0" max="9" placeholder="0" value="<?= Helpers::e($mv('salata')) ?>"></div>
            </div>
            <div class="actions-row">
              <div class="field flex-fill"><label>İçecek</label>
                <select class="inputx" name="menu_icecek">
                  <?php $eic = (string) ($emenu['icecek'] ?? ''); foreach ($icecekOpts as $k => $lbl): ?>
                    <option value="<?= $k ?>" <?= $eic === $k ? 'selected' : '' ?>><?= Helpers::e($lbl) ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div class="field flex-fill" style="justify-content:flex-end">
                <label class="chk-row" style="display:flex;align-items:center;gap:6px;margin-top:6px">
                  <input type="checkbox" name="menu_tatli" value="1" <?= !empty($emenu['tatli']) ? 'checked' : '' ?>> Tatlı/Meyve/Meşrubat</label>
                <label class="chk-row" style="display:flex;align-items:center;gap:6px;margin-top:4px">
                  <input type="checkbox" name="menu_ekmek" value="1" <?= !empty($emenu['ekmek']) ? 'checked' : '' ?>> Ekmek</label>
              </div>
            </div>

            <p class="label mt-2" style="font-weight:600;font-size:13px">Personel</p>
            <div class="actions-row">
              <div class="field flex-fill"><label>Aşçı</label>
                <input class="inputx" name="pers_asci" type="number" min="0" max="99" placeholder="0" value="<?= Helpers::e($pv('asci')) ?>"></div>
              <div class="field flex-fill"><label>Servis</label>
                <input class="inputx" name="pers_servis" type="number" min="0" max="99" placeholder="0" value="<?= Helpers::e($pv('servis')) ?>"></div>
              <div class="field flex-fill"><label>Temizlik</label>
                <input class="inputx" name="pers_temizlik" type="number" min="0" max="99" placeholder="0" value="<?= Helpers::e($pv('temizlik')) ?>"></div>
            </div>
            <div class="field"><label>Ekipman (ops.)</label>
              <input class="inputx" name="ekipman" maxlength="500" placeholder="ör. benmari, sulu tabak, çelik masa" value="<?= Helpers::e($fv('ekipman')) ?>"></div>
          </details>

          <div class="field"><label>Not (özel şart...)</label>
            <input class="inputx" name="note" maxlength="500" placeholder="ör. servis dahil · fiyat KDV hariç" value="<?= Helpers::e($fv('note')) ?>"></div>
          <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-<?= $ed ? 'save' : 'plus-circle' ?>"></i> <?= $ed ? 'Güncelle' : 'Kaydet' ?></button>
          <?php if ($ed): ?><a class="btn-action btn-ghost btn-full mt-2" href="teklifler.php"><i class="bi bi-x-lg"></i> Vazgeç</a><?php endif; ?>
        </form>
      </div>

      <div class="section-head mt-3"><h2>Teklifler</h2><span class="text-muted" style="font-size:12px"><?= count($list) ?> kayıt</span></div>
      <?php if (!$list): ?>
        <div class="empty-state">Henüz teklif yok — ilk teklifi yukarıdan ekleyin.</div>
      <?php else: ?>
        <div class="list-groupx">
          <?php foreach ($list as $t): [$bc, $bi, $bt] = $durumMap[$t['durum']]; ?>
            <div class="cardx card-pad">
              <div class="d-flex align-items-center justify-between gap-2">
                <div style="min-width:0">
                  <div class="row-title"><strong><?= Helpers::e($t['firma']) ?></strong></div>
                  <p class="row-meta">
                    <?= $t['kisi'] ? (int) $t['kisi'] . ' kişi/gün' : '' ?><?= $t['kisi'] && $t['birim_fiyat'] ? ' · ' : '' ?><?= $t['birim_fiyat'] ? '₺ ' . Helpers::money((float) $t['birim_fiyat']) : '' ?>
                    <?= ($t['kisi'] || $t['birim_fiyat']) ? ' · ' : '' ?><?= Helpers::e(date('d.m.Y', strtotime((string) $t['created_at']))) ?>
                  </p>
                  <?php if (!empty($t['yetkili']) || !empty($t['telefon'])): ?>
                    <p class="row-meta"><i class="bi bi-person"></i> <?= Helpers::e(trim((string) ($t['yetkili'] ?? '') . (($t['yetkili'] ?? '') && ($t['telefon'] ?? '') ? ' · ' : '') . (string) ($t['telefon'] ?? ''))) ?></p>
                  <?php endif; ?>
                  <?php if ($t['note']): ?><p class="row-meta"><i class="bi bi-chat-left-text"></i> <?= Helpers::e($t['note']) ?></p><?php endif; ?>
                </div>
                <span class="badge-soft <?= $bc ?>"><i class="bi <?= $bi ?>"></i> <?= $bt ?></span>
              </div>

              <div class="actions-row mt-2">
                <a class="btn-action btn-ghost flex-fill" href="teklifler.php?edit=<?= (int) $t['id'] ?>"><i class="bi bi-pencil"></i> Düzenle</a>
                <a class="btn-action btn-secondaryx flex-fill" href="teklifler.php?goruntule=<?= (int) $t['id'] ?>"><i class="bi bi-file-earmark-pdf"></i> PDF (Yemekhaneci)</a>
              </div>

              <?php
              // Sıradaki mantıklı adımlar: taslak→gönderildi; gönderildi→kabul/red
              $steps = $t['durum'] === 'taslak' ? ['gonderildi' => 'Gönderildi işaretle']
                  : ($t['durum'] === 'gonderildi' ? ['kabul' => 'Kabul ✓', 'red' => 'Red ✗'] : []);
              ?>
              <?php if ($steps): ?>
                <div class="actions-row mt-2">
                  <?php foreach ($steps as $dk => $dl): ?>
                    <form method="post" action="teklifler.php" class="flex-fill">
                      <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
                      <input type="hidden" name="action" value="durum">
                      <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                      <input type="hidden" name="durum" value="<?= $dk ?>">
                      <button class="btn-action <?= $dk === 'red' ? 'btn-ghost' : 'btn-primaryx' ?> btn-full" type="submit"><?= $dl ?></button>
                    </form>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="hint-card mt-2">Kabul edilen teklifi <a href="musteriler.php?yeni=1" style="text-decoration:underline;font-weight:700">Müşteri ekle</a> ile sisteme alın.</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
