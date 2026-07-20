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

// fable-023a: ekran artık 3 öğünü (öğlen/akşam/kumanya) birlikte yönetiyor.
// $meal yalnız geriye dönük tekil çağrılarda (yok) değil, öğün etiketleri için kullanılır.
$mealLabels = ['ogle' => 'Öğlen', 'aksam' => 'Akşam', 'kumanya' => 'Kumanya'];
$date = (string) ($_GET['date'] ?? Helpers::today());
if (!Helpers::isDate($date)) {
    $date = Helpers::today();
}
// fable-022: rapor "Eksik · Gir" derin bağlantısı — gelinen müşteri satırı vurgulanır
$focus = (int) ($_GET['focus'] ?? 0);
$flash = '';
$flashOk = true;

// ── Kaydet ───────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $postDate = (string) ($_POST['date'] ?? $date);
        $date = Helpers::isDate($postDate) ? $postDate : Helpers::today();
        $persons = $_POST['persons'] ?? [];
        // fable-023a: kırılım penceresinden gelen öğün alanları + "elle düzenlendi" bayrağı.
        $postMeals = [
            'ogle' => (array) ($_POST['meal_ogle'] ?? []),
            'aksam' => (array) ($_POST['meal_aksam'] ?? []),
            'kumanya' => (array) ($_POST['meal_kumanya'] ?? []),
        ];
        $editedFlags = (array) ($_POST['meal_edited'] ?? []);
        // Toplam kutusu doğrudan değiştirilmişse fark öğlene yazılır → mevcut kırılım lazım.
        $before = [];
        foreach ($repo->dayGridAllMeals($date) as $r) {
            $before[(int) $r['customer_id']] = $r;
        }
        $saved = 0;
        $pdo->beginTransaction();
        try {
            foreach ($repo->activeCustomers() as $c) {
                $cid = (int) $c['id'];
                $cur = $before[$cid] ?? ['ogle' => 0, 'aksam' => 0, 'kumanya' => 0];
                if (!empty($editedFlags[$cid])) {
                    $meals = [
                        'ogle' => Repo::normalizePersons($postMeals['ogle'][$cid] ?? 0),
                        'aksam' => Repo::normalizePersons($postMeals['aksam'][$cid] ?? 0),
                        'kumanya' => Repo::normalizePersons($postMeals['kumanya'][$cid] ?? 0),
                    ];
                } else {
                    $meals = Repo::mealsFromTotal($persons[$cid] ?? 0, $cur);
                }
                $toplam = $meals['ogle'] + $meals['aksam'] + $meals['kumanya'];
                // opus-017: girilen günün ayına ait fiyat (ay-bazlı; current default değil)
                $price = $toplam > 0 ? $repo->priceFor($cid, substr($date, 0, 7))['unit_price'] : 0.0;
                // Bağlı alanlar atomik: 3 öğün tek transaction içinde tek metotla yazılır/silinir.
                $repo->saveDayMeals($cid, $date, $meals, $price, 'uysa');
                if ($toplam > 0) {
                    $saved++;
                }
            }
            $pdo->commit();
            uysa_audit('uretim_kaydet', $u['username'], $date, json_encode(['n' => $saved]), client_ip());
            $flash = "Kaydedildi · $saved müşteri";
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $flash = 'Kayıt hatası.';
            $flashOk = false;
        }
    }
}

// ── Dünü kopyala ─────────────────────────────────────────────
$copyValues = null;
if (isset($_GET['copy'])) {
    // fable-023a: kaynak gün öğün farkı gözetmeden bulunur (sadece akşam kaydı olan gün de gelsin)
    $prev = $repo->previousProductionDate($date, null);
    if ($prev !== null) {
        $copyValues = [];
        foreach ($repo->dayGridAllMeals($prev) as $r) {
            $copyValues[(int) $r['customer_id']] = $r;
        }
        $flash = date('d.m.Y', strtotime($prev)) . " tarihinden kopyalandı — kontrol edip Kaydet'e basın.";
    } else {
        $flash = 'Kopyalanacak önceki gün yok.';
        $flashOk = false;
    }
}

// fable-023a: 3 öğünü toplayan tek kaynak — akşam/kumanya artık sayaçlara ve ciroya giriyor.
$grid = $repo->dayGridAllMeals($date);

// Sunucu-tarafı ilk render toplamları
$sumP = 0; $sumA = 0.0; $filled = 0;
$rowsData = [];
$priceMonth = substr($date, 0, 7);
foreach ($grid as $r) {
    $cid = (int) $r['customer_id'];
    $src = $copyValues[$cid] ?? $r;
    $meals = [
        'ogle' => (int) ($src['ogle'] ?? 0),
        'aksam' => (int) ($src['aksam'] ?? 0),
        'kumanya' => (int) ($src['kumanya'] ?? 0),
    ];
    $val = $meals['ogle'] + $meals['aksam'] + $meals['kumanya'];
    // opus-017: bu ayın fiyatı (ay-bazlı) — girilmiş satırlar zaten snapshot'lı, boşlar bu fiyatı gösterir
    $price = $repo->priceFor($cid, $priceMonth)['unit_price'];
    $amt = $val * $price;
    if ($val > 0) { $sumP += $val; $sumA += $amt; $filled++; }
    // Kırılım etiketi yalnız akşam/kumanya varken görünür (tek öğünlü müşteride gürültü olmasın)
    $splitLabel = '';
    if ($meals['aksam'] > 0 || $meals['kumanya'] > 0) {
        $parts = [];
        foreach ($mealLabels as $mk => $ml) {
            if ($meals[$mk] > 0) {
                $parts[] = $meals[$mk] . ' ' . mb_strtolower($ml, 'UTF-8');
            }
        }
        $splitLabel = implode(' · ', $parts);
    }
    $rowsData[] = [
        'cid' => $cid, 'name' => $r['name'], 'price' => $price, 'val' => $val, 'amt' => $amt,
        'meals' => $meals, 'split' => $splitLabel,
    ];
}
$total = count($rowsData);

// fable-023b: İrsaliyelendir — o günün adayları (seçilemeyen de listelenir, SEBEBİYLE).
// Şalter kapalıyken de ekran çalışır; kesim yerine önizleme gösterilir (rozet uyarır).
$irsaliyeAdaylari = [];
$irsaliyeSecilebilir = 0;
$irsaliyeGirilen = 0;
// Canlı muhasebeye yazan işlem → yalnız yetkili kullanıcı görür (sunucuda da ayrı kapı var).
$irsaliyeYetkili = Auth::isAdmin($u);
try {
    $irsaliyeAdaylari = $repo->irsaliyeAdaylari($date);
    foreach ($irsaliyeAdaylari as $a) {
        if ($a['toplam'] > 0) {
            $irsaliyeGirilen++;
        }
        if ($a['secilebilir']) {
            $irsaliyeSecilebilir++;
        }
    }
} catch (\Throwable $e) {
    // migrate_031 henüz uygulanmadıysa (kolon/tablo yok) ana ekran ÇALIŞMAYA DEVAM eder;
    // yalnız İrsaliyelendir görünmez. "İşleyen düzeni bozma" kuralı.
    error_log('[UYSA v2 bugun] irsaliye adayları okunamadı: ' . $e->getMessage());
    $irsaliyeYetkili = false;
}
$irsaliyeAcik = \Uysa\ParasutYaz::aktif();

$prevDay = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDay = date('Y-m-d', strtotime($date . ' +1 day'));
$pendingCount = $repo->pendingOrdersCount();
$critCount = count($repo->criticalStock());
$supplyCount = $repo->openSupplyRequestsCount();
$openReqCount = $repo->openRequestsCount();

// Bar grafik verisi (girilen firmalar × kişi), broşür "Bugün Sipariş Veren Firmalar".
$barRows = [];
$barMax = 0;
foreach ($rowsData as $r) {
    if ($r['val'] > 0) {
        $barRows[] = ['name' => $r['name'], 'val' => $r['val']];
        $barMax = max($barMax, $r['val']);
    }
}
usort($barRows, static fn($a, $b) => $b['val'] <=> $a['val']);
$barRows = array_slice($barRows, 0, 6);

$pageTitle = 'Panel';
$active = 'bugun';
require __DIR__ . '/partials/header.php';
?>
      <div class="date-row">
        <a class="icon-btn" href="bugun.php?date=<?= $prevDay ?>" aria-label="Önceki gün"><i class="bi bi-chevron-left"></i></a>
        <form method="get" class="date-pill">
          <i class="bi bi-calendar2-week"></i>
          <input type="date" name="date" value="<?= Helpers::e($date) ?>" onchange="this.form.submit()">
        </form>
        <a class="icon-btn" href="bugun.php?date=<?= $nextDay ?>" aria-label="Sonraki gün"><i class="bi bi-chevron-right"></i></a>
      </div>

      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <div class="stat-stack">
        <div class="stat-card stat-green">
          <div class="ico"><i class="bi bi-cash-stack"></i></div>
          <div class="txt">
            <p class="lbl"><?= $date === Helpers::today() ? 'Bugünkü toplam ciro' : Helpers::e(date('d.m', strtotime($date))) . ' toplam ciro' ?></p>
            <p class="val">₺ <span id="sum-amount"><?= Helpers::money($sumA) ?></span></p>
          </div>
        </div>
        <div class="stat-card stat-orange">
          <div class="ico"><i class="bi bi-people-fill"></i></div>
          <div class="txt">
            <p class="lbl"><?= $date === Helpers::today() ? 'Bugünkü toplam kişi' : Helpers::e(date('d.m', strtotime($date))) . ' toplam kişi' ?></p>
            <p class="val" id="sum-persons"><?= number_format($sumP, 0, ',', '.') ?></p>
          </div>
        </div>
        <a class="stat-card stat-blue" href="#musteri-sayilari" aria-label="Müşteri sayılarına git">
          <div class="ico"><i class="bi bi-shop"></i></div>
          <div class="txt">
            <p class="lbl">Giriş yapılan müşteri</p>
            <!-- fable-023a: JS recalc "x/y girildi" yazıyordu; ilk boyama da aynı olsun (zıplama yok) -->
            <p class="val"><span id="sum-filled"><?= $filled ?>/<?= $total ?> girildi</span></p>
          </div>
        </a>
      </div>

      <div class="section-head"><h2>Bölümler</h2></div>
      <div class="mod-grid">
        <a class="mod-card i-green" href="musteriler.php">
          <div class="mico"><i class="bi bi-people"></i></div>
          <div class="mt">Müşteriler</div>
          <div class="md">Üretim / taşıma, birim fiyat, kâr</div>
        </a>
        <a class="mod-card i-amber" href="siparisler.php">
          <?php if ($pendingCount > 0): ?><span class="soon-chip" style="background:var(--red-tint);color:var(--red)"><?= $pendingCount ?> yeni</span><?php endif; ?>
          <div class="mico"><i class="bi bi-basket"></i></div>
          <div class="mt">Siparişler</div>
          <div class="md">Müşteri onay kuyruğu</div>
        </a>
        <a class="mod-card i-amber" href="personel.php">
          <div class="mico"><i class="bi bi-person-badge"></i></div>
          <div class="mt">Personel Giderleri</div>
          <div class="md">Maaş / prim takibi</div>
        </a>
        <a class="mod-card i-green" href="menu.php">
          <div class="mico"><i class="bi bi-card-list"></i></div>
          <div class="mt">Menü</div>
          <div class="md">Menü oluştur + hedefli yayınla</div>
        </a>
        <a class="mod-card i-blue" href="talepler.php">
          <?php if ($openReqCount > 0): ?><span class="soon-chip" style="background:var(--red-tint);color:var(--red)"><?= $openReqCount ?> açık</span><?php endif; ?>
          <div class="mico"><i class="bi bi-chat-left-text"></i></div>
          <div class="mt">Talepler</div>
          <div class="md">Şikayet · öneri · menü · mesaj takip</div>
        </a>
        <?php if ($irsaliyeYetkili): ?>
        <a class="mod-card i-green" href="fatura-kes.php">
          <div class="mico"><i class="bi bi-receipt-cutoff"></i></div>
          <div class="mt">Fatura Kes</div>
          <div class="md">Kesilen irsaliyelerden Paraşüt faturası</div>
        </a>
        <?php endif; ?>
      </div>

      <?php if ($barRows): ?>
      <div class="cardx card-pad">
        <h2>Bugün üretim veren firmalar</h2>
        <div class="barchart">
          <?php foreach ($barRows as $b): $w = $barMax > 0 ? max(4, round($b['val'] / $barMax * 100)) : 4; ?>
            <div class="bar-row">
              <span class="bar-name"><?= Helpers::e($b['name']) ?></span>
              <span class="bar-track"><span class="bar-fill" style="width: <?= $w ?>%"></span></span>
              <span class="bar-val"><?= number_format($b['val'], 0, ',', '.') ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <form method="post" id="bugun-form">
        <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
        <input type="hidden" name="date" value="<?= Helpers::e($date) ?>">
        <div class="cardx card-pad" id="musteri-sayilari" style="scroll-margin-top: 14px">
          <!-- fable-023b: başlık + İrsaliyelendir (Paraşüt e-İrsaliye); sayı girilmemişse pasif -->
          <div class="head-row">
            <h2>Müşteri sayıları</h2>
            <?php if ($irsaliyeYetkili): ?>
            <button type="button" class="btn-chip" id="irs-open"<?= $irsaliyeGirilen === 0 ? ' disabled' : '' ?>
              aria-haspopup="dialog" title="<?= $irsaliyeGirilen === 0 ? 'Bugün için sayı girilmemiş' : 'Seçtiğin müşterilere Paraşüt\'ten irsaliye kes' ?>">
              <i class="bi bi-truck"></i> İrsaliyelendir
            </button>
            <?php endif; ?>
          </div>
          <?php if (!$rowsData): ?>
            <div class="empty-state">Aktif müşteri yok.</div>
          <?php endif; ?>
          <?php foreach ($rowsData as $r): $missing = $r['val'] === 0; $isFocus = $focus > 0 && $r['cid'] === $focus; ?>
            <div class="customer-row <?= $missing ? 'missing' : '' ?> <?= $isFocus ? 'is-focus' : '' ?>"<?= $isFocus ? ' id="focus-row"' : '' ?> data-price="<?= $r['price'] ?>" data-cid="<?= $r['cid'] ?>" data-name="<?= Helpers::e($r['name']) ?>">
              <div>
                <div class="row-title"><span class="status-dot <?= $missing ? 'warn' : '' ?>"></span>
                  <!-- fable-023a: müşteri adı = öğün kırılımı penceresini açan buton -->
                  <button type="button" class="row-name-btn" data-meal-open aria-haspopup="dialog">
                    <strong><?= Helpers::e($r['name']) ?></strong><i class="bi bi-sliders2" aria-hidden="true"></i>
                  </button>
                </div>
                <p class="row-meta">₺ <?= Helpers::money($r['price']) ?> kişi başı · <span class="row-amt"><?= $missing ? 'girilmedi' : '₺ ' . Helpers::money($r['amt']) ?></span><span class="meal-split"<?= $r['split'] === '' ? ' hidden' : '' ?>><?= Helpers::e($r['split']) ?></span></p>
              </div>
              <div class="counter">
                <button class="step-btn" type="button" data-step="-5">−</button>
                <input class="count-input" inputmode="numeric" type="number" min="0" name="persons[<?= $r['cid'] ?>]" value="<?= $r['val'] > 0 ? $r['val'] : '' ?>">
                <button class="step-btn" type="button" data-step="5">+</button>
              </div>
              <input type="hidden" class="m-ogle" name="meal_ogle[<?= $r['cid'] ?>]" value="<?= $r['meals']['ogle'] ?>">
              <input type="hidden" class="m-aksam" name="meal_aksam[<?= $r['cid'] ?>]" value="<?= $r['meals']['aksam'] ?>">
              <input type="hidden" class="m-kumanya" name="meal_kumanya[<?= $r['cid'] ?>]" value="<?= $r['meals']['kumanya'] ?>">
              <input type="hidden" class="m-edited" name="meal_edited[<?= $r['cid'] ?>]" value="<?= $copyValues !== null ? 1 : 0 ?>">
            </div>
          <?php endforeach; ?>
        </div>

        <div class="actions-row mt-3">
          <a class="btn-action btn-secondaryx flex-fill" href="bugun.php?date=<?= Helpers::e($date) ?>&copy=1"><i class="bi bi-copy"></i> Dünü kopyala</a>
          <button class="btn-action btn-primaryx flex-fill" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
        </div>
      </form>

      <!-- fable-023a: öğün kırılımı penceresi — satırdaki gizli alanları doldurur, sonra formu gönderir -->
      <div class="meal-modal" id="meal-modal" hidden>
        <div class="meal-backdrop" data-meal-close></div>
        <div class="meal-card" role="dialog" aria-modal="true" aria-labelledby="meal-modal-title">
          <div class="meal-head">
            <h3 id="meal-modal-title">Öğün kırılımı</h3>
            <button type="button" class="icon-btn" data-meal-close aria-label="Kapat"><i class="bi bi-x-lg"></i></button>
          </div>
          <p class="meal-sub" id="meal-modal-name"></p>
          <div class="meal-fields">
            <?php foreach ($mealLabels as $mk => $ml): ?>
              <label class="meal-field">
                <span><?= Helpers::e($ml) ?></span>
                <input class="inputx meal-input" id="meal-in-<?= $mk ?>" data-meal="<?= $mk ?>" type="number" inputmode="numeric" min="0" value="0">
              </label>
            <?php endforeach; ?>
          </div>
          <p class="meal-total">Toplam: <strong id="meal-modal-total">0</strong> kişi</p>
          <p class="meal-hint">Toplam kutusu doğrudan değiştirilirse fark öğlene yazılır; akşam ve kumanya korunur.</p>
          <div class="actions-row">
            <button type="button" class="btn-action btn-secondaryx flex-fill" data-meal-close>Vazgeç</button>
            <button type="button" class="btn-action btn-primaryx flex-fill" id="meal-save">Kaydet</button>
          </div>
        </div>
      </div>
      <?php if ($irsaliyeYetkili): ?>
      <!-- fable-023b: İrsaliyelendir — seçim → onay → sonuç (tek pencere, üç adım).
           Form DIŞINDA: buradaki kutucuklar üretim formuyla birlikte POST edilmez. -->
      <div class="meal-modal" id="irs-modal" hidden>
        <div class="meal-backdrop" data-irs-close></div>
        <div class="meal-card irs-card" role="dialog" aria-modal="true" aria-labelledby="irs-modal-title">
          <div class="meal-head">
            <h3 id="irs-modal-title">İrsaliyelendir</h3>
            <button type="button" class="icon-btn" data-irs-close aria-label="Kapat"><i class="bi bi-x-lg"></i></button>
          </div>
          <p class="meal-sub"><?= Helpers::e(date('d.m.Y', strtotime($date))) ?> · Paraşüt e-İrsaliye</p>
          <?php if (!$irsaliyeAcik): ?>
            <p class="irs-badge"><i class="bi bi-lock"></i> Kesim kapalı — Ömer onayı bekleniyor. Bu ekran yalnız <strong>önizleme</strong> yapar, Paraşüt'e hiçbir şey gönderilmez.</p>
          <?php endif; ?>

          <!-- adım 1: seçim -->
          <div id="irs-step-secim">
            <?php if (!$irsaliyeAdaylari): ?>
              <div class="empty-state">Aktif müşteri yok.</div>
            <?php else: ?>
            <div class="irs-tools">
              <label class="irs-check"><input type="checkbox" id="irs-all"<?= $irsaliyeSecilebilir === 0 ? ' disabled' : '' ?>> <span>Tümünü seç / kaldır</span></label>
              <span class="badge-soft" id="irs-count">0 seçili</span>
            </div>
            <div class="irs-list">
              <?php foreach ($irsaliyeAdaylari as $a):
                  $parts = [];
                  foreach ($mealLabels as $mk => $ml) {
                      if ($a[$mk] > 0) {
                          $parts[] = $a[$mk] . ' ' . mb_strtolower($ml, 'UTF-8');
                      }
                  }
                  $kirilim = $parts ? implode(' · ', $parts) : 'sayı yok';
              ?>
                <label class="irs-row <?= $a['secilebilir'] ? '' : 'is-off' ?>">
                  <input type="checkbox" class="irs-pick" value="<?= (int) $a['customer_id'] ?>"<?= $a['secilebilir'] ? '' : ' disabled' ?>>
                  <span class="irs-name"><?= Helpers::e($a['name']) ?></span>
                  <span class="irs-meta"><?= Helpers::e($kirilim) ?></span>
                  <?php if (!$a['secilebilir']): ?>
                    <span class="irs-why"><?= Helpers::e($a['sebep']) ?><?= $a['despatch_no'] ? ' · ' . Helpers::e((string) $a['despatch_no']) : '' ?></span>
                  <?php endif; ?>
                  <?php if ($a['durum'] === 'bilinmiyor'): ?>
                    <!-- Timeout kilidi çıkmaz sokak olmasın: Paraşüt'ten bakan insan kararını yazar -->
                    <span class="irs-fix">
                      <button type="button" class="btn-mini" data-irs-cozum="kesilmedi" data-cid="<?= (int) $a['customer_id'] ?>">Paraşüt'te YOK — kilidi aç</button>
                      <button type="button" class="btn-mini" data-irs-cozum="kesildi" data-cid="<?= (int) $a['customer_id'] ?>">Paraşüt'te VAR — kesildi işaretle</button>
                    </span>
                  <?php endif; ?>
                </label>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="actions-row mt-3">
              <button type="button" class="btn-action btn-secondaryx flex-fill" data-irs-close>Vazgeç</button>
              <button type="button" class="btn-action btn-primaryx flex-fill" id="irs-next" disabled>Tamam</button>
            </div>
          </div>

          <!-- adım 2: onay -->
          <div id="irs-step-onay" hidden>
            <div id="irs-ozet"></div>
            <p class="irs-warn"><i class="bi bi-exclamation-triangle"></i> Paraşüt'te <strong>resmi e-İrsaliye</strong> oluşur ve GİB'e gider. Bu işlem geri alınamaz.</p>
            <details class="irs-json"><summary>Paraşüt'e gidecek veri (kuru deneme)</summary><pre id="irs-govde"></pre></details>
            <div class="actions-row mt-3">
              <button type="button" class="btn-action btn-secondaryx flex-fill" id="irs-back">Geri</button>
              <button type="button" class="btn-action btn-primaryx flex-fill" id="irs-cut">İrsaliyeleri Kes</button>
            </div>
          </div>

          <!-- adım 3: sonuç -->
          <div id="irs-step-sonuc" hidden>
            <div id="irs-sonuc"></div>
            <div class="actions-row mt-3">
              <button type="button" class="btn-action btn-secondaryx flex-fill" id="irs-retry" hidden>Başarısızları tekrar dene</button>
              <button type="button" class="btn-action btn-primaryx flex-fill" data-irs-close>Kapat</button>
            </div>
          </div>
        </div>
      </div>
      <script>window.IRS = {csrf: <?= json_encode(Helpers::csrfToken()) ?>, date: <?= json_encode($date) ?>, kapali: <?= $irsaliyeAcik ? 'false' : 'true' ?>};</script>
      <script src="assets/irsaliye.js?v=<?= filemtime(__DIR__ . '/assets/irsaliye.js') ?>"></script>
      <?php endif; ?>

      <?php if ($focus > 0): ?>
      <script>
        (function () {
          var row = document.getElementById('focus-row');
          if (!row) return;
          row.scrollIntoView({ block: 'center' });
          var inp = row.querySelector('.count-input');
          if (inp) inp.focus();
        })();
      </script>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
