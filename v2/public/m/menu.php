<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Uysa\CustomerAuth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\MenuPdf;
use Uysa\Push;
use Uysa\Repo;

$cu = CustomerAuth::requireCustomer();
$cid = (int) $cu['customer_id'];
$pdo = Db::pdo();
$repo = new Repo($pdo);

// opus-019: müşteri EN FAZLA 1 AY GERİ görebilir (date_end >= bugün−1 ay).
$minEnd = date('Y-m-d', strtotime('-1 month'));

// PDF indir (header'dan ÖNCE) — SADECE bu müşteriye görünür + 1 ay sınırı içindeki menü (IDOR scope).
if (isset($_GET['pdf'])) {
    $mid = (int) $_GET['pdf'];
    $m = $repo->menuForCustomer($cid, $mid, $minEnd);
    if ($m) {
        $bin = MenuPdf::write((string) $m['title'], $repo->menuItems($mid), (string) $m['date_start'], (string) $m['date_end']);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="menu-' . (int) $mid . '.pdf"');
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;
    }
    header('Location: menu.php');
    exit;
}

$today = Helpers::today();
$minChange = date('Y-m-d', strtotime('+3 day')); // fable-001: menü değişikliği en az 3 gün sonrası
$flash = '';
$flashOk = true;

// fable-001: menü değişikliği talebi — kalem kalem (çorba/ana/pilav/salata/tatlı), tip 'menu' request.
$kalemler = [
    'corba'  => 'Çorba',
    'ana'    => 'Ana Yemek',
    'pilav'  => 'Pilav / Makarna',
    'salata' => 'Salata / Meze',
    'tatli'  => 'Tatlı / İçecek',
];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'menu_degisiklik') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $mDate = (string) ($_POST['change_date'] ?? '');
        // kalem her zaman dizi olmalı (string POST → TypeError koruması)
        $kalemPost = is_array($_POST['kalem'] ?? null) ? $_POST['kalem'] : [];
        $lines = [];
        foreach ($kalemler as $kk => $kl) {
            $v = trim((string) ($kalemPost[$kk] ?? ''));
            if ($v !== '') {
                $lines[] = $kl . ': ' . mb_substr($v, 0, 200);
            }
        }
        if (!Helpers::isDate($mDate) || $mDate < $minChange) {
            $flash = 'Tarih en az 3 gün sonrası olmalı (' . date('d.m.Y', strtotime($minChange)) . ' ve sonrası).';
            $flashOk = false;
        } elseif (!$lines) {
            $flash = 'En az bir kalem için istediğiniz yemeği yazın.';
            $flashOk = false;
        } else {
            $subject = 'Menü değişikliği · ' . date('d.m.Y', strtotime($mDate));
            $reqId = $repo->createRequest($cid, $cu['cuid'] ?? null, 'menu', $subject);
            $noteExtra = trim((string) ($_POST['change_note'] ?? ''));
            $body = implode("\n", $lines) . ($noteExtra !== '' ? "\n\nNot: " . mb_substr($noteExtra, 0, 500) : '');
            $repo->addRequestMessage($reqId, 'musteri', mb_substr($body, 0, 2000));
            uysa_audit('musteri_menu_degisiklik', $cu['username'], (string) $cid, (string) $reqId, client_ip());
            $flash = 'Menü değişikliği talebiniz gönderildi — UYSA değerlendirecek.';
            try {
                (new Push($pdo))->toAdmins('Menü değişikliği: ' . $cu['customer_name'], $subject, ['url' => '/talepler.php?r=' . $reqId], 'talep_yeni');
            } catch (\Throwable) {
            }
        }
    }
}

// SADECE bu müşteriye yayınlanmış menüler (all-audience VEYA menu_target'ta) — IDOR scope.
$menus = $repo->menusForCustomer($cid, $minEnd);
$meals = ['sabah' => 'Sabah', 'ogle' => 'Öğle', 'aksam' => 'Akşam', 'gece' => 'Gece', 'kumanya' => 'Kumanya'];

// fable-001: tüm menülerin günleri TEK akışta, tarihe göre ARTAN — açılışta BUGÜNE kaydırılır
// (yukarı = geçmiş, aşağı = ileri). Aynı tarih+öğün iki menüde varsa ikisi de listelenir.
$days = [];
foreach ($menus as $m) {
    foreach ($repo->menuItems((int) $m['id']) as $it) {
        if (trim((string) $it['dishes']) === '') {
            continue;
        }
        $days[$it['item_date']][] = ['meal' => (string) $it['meal'], 'dishes' => (string) $it['dishes']];
    }
}
ksort($days);
// Bugün yoksa kaydırma hedefi = bugünden sonraki ilk gün.
$anchorDate = '';
foreach (array_keys($days) as $d) {
    if ($d >= $today) {
        $anchorDate = $d;
        break;
    }
}

$eyebrow = Helpers::e($cu['customer_name']) . ' · Menü';
$pageTitle = 'Menü';
$active = 'menu';
require __DIR__ . '/partials/header_m.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <?php /* fable-001: EN ÜSTTE menü değişikliği talebi (Ömer istedi) — akordeon */ ?>
      <details class="cardx card-pad" <?= (!$flashOk && isset($_POST['action'])) ? 'open' : '' ?>>
        <summary style="cursor:pointer;font-weight:600"><i class="bi bi-arrow-repeat"></i> Menü değişikliği talep et</summary>
        <form method="post" class="form-grid mt-2">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <input type="hidden" name="action" value="menu_degisiklik">
          <div class="field"><label>Hangi gün? (en az 3 gün sonrası)</label>
            <input class="inputx" type="date" name="change_date" min="<?= $minChange ?>" value="<?= Helpers::e((string) ($_POST['change_date'] ?? '')) ?>" required>
          </div>
          <?php foreach ($kalemler as $kk => $kl): ?>
            <div class="field"><label><?= Helpers::e($kl) ?></label>
              <input class="inputx" name="kalem[<?= $kk ?>]" maxlength="200" placeholder="İstediğiniz <?= Helpers::e(mb_strtolower($kl, 'UTF-8')) ?> (boş bırakılabilir)" value="<?= Helpers::e((string) ((is_array($_POST['kalem'] ?? null) ? $_POST['kalem'] : [])[$kk] ?? '')) ?>">
            </div>
          <?php endforeach; ?>
          <div class="field"><label>Not (opsiyonel)</label>
            <input class="inputx" name="change_note" maxlength="500" placeholder="ör. o gün misafir grubumuz var">
          </div>
          <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-send"></i> Talebi gönder</button>
        </form>
      </details>

      <?php if (!$menus): ?>
        <div class="empty-state">
          Yayınlanan menü yok.
          <div class="row-meta mt-2">UYSA size menü yayınladığında burada görünecek.</div>
        </div>
      <?php else: ?>
        <?php /* PDF indirme — target=_blank + download: webview'i kilitlemez (fable-001: "X yok" sorunu) */ ?>
        <div class="cardx card-pad">
          <p class="label mb-2">Menü PDF (takvim düzeni)</p>
          <?php foreach ($menus as $m): ?>
            <a class="btn-action btn-secondaryx btn-full mb-2" href="menu.php?pdf=<?= (int) $m['id'] ?>" target="_blank" download="menu-<?= (int) $m['id'] ?>.pdf">
              <i class="bi bi-file-earmark-pdf"></i> <?= Helpers::e($m['title']) ?> · <?= date('d.m', strtotime($m['date_start'])) ?>–<?= date('d.m.Y', strtotime($m['date_end'])) ?>
            </a>
          <?php endforeach; ?>
        </div>

        <?php if (!$days): ?>
          <div class="empty-state">Menülerde gün eklenmemiş.</div>
        <?php else: ?>
          <div class="section-head mt-2"><h2>Günlere göre menü</h2><span class="text-muted" style="font-size:12px">yukarı: geçmiş · aşağı: ileri</span></div>
          <div class="screen-stack">
            <?php foreach ($days as $d => $items): $isToday = $d === $today; ?>
              <div class="meal-card <?= $isToday ? 'today-card' : '' ?>" <?= $d === $anchorDate ? 'id="menu-today"' : '' ?> style="scroll-margin-top:80px">
                <p class="label"><?= Helpers::e(gun_label_tr($d)) ?></p>
                <?php foreach ($items as $it): ?>
                  <h2 style="margin:2px 0 0; font-size:16px"><?php if (count($items) > 1): ?><span class="text-muted" style="font-size:12px"><?= Helpers::e($meals[$it['meal']] ?? $it['meal']) ?> · </span><?php endif; ?><?= Helpers::e($it['dishes']) ?></h2>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if ($anchorDate !== ''): ?>
            <script>document.addEventListener('DOMContentLoaded',function(){var el=document.getElementById('menu-today');if(el)el.scrollIntoView({block:'start'});});</script>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer_m.php'; ?>
