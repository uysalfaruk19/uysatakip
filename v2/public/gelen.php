<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

/**
 * aksiyon-faz3 — GELEN: müşteriden gelen her şey TEK kuyrukta.
 *
 * Bugüne kadar sipariş onayı (siparisler.php) ve talepler (talepler.php) ayrı ekranlardı;
 * patron için ikisi de aynı şey: cevap bekleyen iş. Bu ekran ikisini bekleme yaşına göre
 * tek listede toplar. Tür farkı YALNIZ sol kenardaki şeritten anlaşılır (filtre çubuğu yok).
 *
 * Eski ekranlar KALDIRILMADI: haftalık cetvel, talep filtreleri, cevap kutusu ve mevcut
 * push deep-link'leri (/siparisler.php, /talepler.php?r=) orada yaşamaya devam ediyor.
 * Buradan "Cevapla" o ekrandaki detaya götürür — yeni bir cevap kutusu uydurulmadı.
 */
$u = Auth::requireLogin();
$pdo = Db::pdo();
$repo = new Repo($pdo);

$flash = '';
$flashOk = true;

// Sipariş onay/ret — siparisler.php ile AYNI akış (CSRF + audit + müşteri olayı).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $o = $orderId > 0 ? $repo->orderById($orderId) : null;
        if (!$o) {
            $flash = 'Sipariş bulunamadı.';
            $flashOk = false;
        } elseif (($o['status'] ?? '') !== 'gonderildi') {
            // Çift POST / tarayıcıda geri + tekrar gönder: sipariş zaten karara bağlanmış.
            $flash = 'Bu sipariş zaten ' . ($o['status'] === 'onaylandi' ? 'onaylanmış' : 'reddedilmiş') . '. Düzeltme Bugün ekranından yapılır.';
            $flashOk = false;
        } elseif ($action === 'approve') {
            $res = $repo->approveOrder($orderId);
            if ($res) {
                uysa_audit('siparis_onayla', $u['username'], (string) $orderId, json_encode($res), client_ip());
                $flash = 'Onaylandı → üretime yazıldı: ' . $res['persons'] . ' kişi · ₺ ' . Helpers::money($res['amount']);
                $repo->addCustomerEvent((int) $res['customer_id'], 'siparis_durum', 'Siparişiniz onaylandı',
                    gun_label_tr((string) $o['order_date']) . ' · ' . (int) $res['persons'] . ' kişi',
                    '/m/siparis.php?date=' . (string) $o['order_date']);
            } else {
                $flash = 'Sipariş onaylanamadı.';
                $flashOk = false;
            }
        } elseif ($action === 'reject') {
            if ($repo->rejectOrder($orderId)) {
                uysa_audit('siparis_reddet', $u['username'], (string) $orderId, null, client_ip());
                $flash = 'Sipariş reddedildi.';
                $repo->addCustomerEvent((int) $o['customer_id'], 'siparis_durum', 'Siparişiniz reddedildi',
                    gun_label_tr((string) $o['order_date']) . ' · ' . (int) $o['persons'] . ' kişi',
                    '/m/siparis.php?date=' . (string) $o['order_date']);
            } else {
                $flash = 'Sipariş bulunamadı.';
                $flashOk = false;
            }
        }
    }
}

$meals = ['sabah' => 'Sabah', 'ogle' => 'Öğle', 'aksam' => 'Akşam', 'gece' => 'Gece', 'kumanya' => 'Kumanya'];

// ── Tek kuyruk: bekleyen sipariş + açık talep, en eski önce ──
$kuyruk = [];
foreach ($repo->pendingOrders() as $o) {
    $kuyruk[] = [
        'tur'     => 'siparis',
        'id'      => (int) $o['id'],
        'musteri' => (string) $o['customer_name'],
        'baslik'  => gun_label_tr((string) $o['order_date']) . ' için ' . (int) $o['persons'] . ' kişi',
        'alt'     => ($meals[$o['meal']] ?? $o['meal']) . (($o['note'] ?? '') !== '' ? ' · ' . (string) $o['note'] : ''),
        'zaman'   => (string) $o['created_at'],
        'link'    => 'siparisler.php',
    ];
}
foreach ($repo->allRequests(['status' => 'acik']) as $r) {
    $kuyruk[] = [
        'tur'     => 'talep',
        'id'      => (int) $r['id'],
        'musteri' => (string) $r['customer_name'],
        'baslik'  => (string) $r['subject'],
        'alt'     => (int) $r['msg_count'] > 0 ? ((int) $r['msg_count'] . ' mesaj') : 'henüz cevaplanmadı',
        'zaman'   => (string) ($r['last_msg_at'] ?? $r['created_at']),
        'link'    => 'talepler.php?r=' . (int) $r['id'],
    ];
}
usort($kuyruk, static fn(array $a, array $b): int => strcmp($a['zaman'], $b['zaman']));

/** "dün 12:40" / "bugün 08:15" / "2 gün önce" — bekleme yaşı listenin asıl bilgisi. */
function gelen_yas(string $ts): string
{
    $t = strtotime($ts);
    if ($t === false) {
        return '';
    }
    $gunFark = (int) floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $t))) / 86400);
    if ($gunFark <= 0) {
        return 'bugün ' . date('H:i', $t);
    }
    if ($gunFark === 1) {
        return 'dün ' . date('H:i', $t);
    }
    return $gunFark . ' gün önce';
}

$enEski = $kuyruk ? (int) floor((time() - strtotime($kuyruk[0]['zaman'])) / 86400) : 0;
$csrf = Helpers::csrfToken();
$pageTitle = 'Gelen';
$eyebrow = $kuyruk ? (count($kuyruk) . ' bekleyen') : 'bekleyen yok';
$active = 'musteriler';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <?php // Karar rakamı: kaç iş bekliyor ve en eskisi ne kadar bekledi. ?>
      <div class="cardx card-pad gt-nabiz-sm">
        <div class="gt-pulse">
          <div class="gt-pulse-n"><?= count($kuyruk) ?></div>
          <div class="gt-pulse-l">bekleyen iş<?= $kuyruk && $enEski > 0 ? ' · en eskisi ' . $enEski . ' gündür' : '' ?></div>
        </div>
      </div>

      <?php if (!$kuyruk): ?>
      <div class="cardx card-pad empty-state">
        <p style="margin:0"><i class="bi bi-check2-circle" aria-hidden="true"></i> Cevap bekleyen sipariş veya talep yok.</p>
      </div>
      <?php else: ?>
      <div class="gelen-liste">
        <?php foreach ($kuyruk as $k): ?>
        <div class="cardx card-pad gelen-kart gelen-<?= $k['tur'] ?>">
          <div class="gelen-govde">
            <div class="gelen-ust">
              <strong><?= Helpers::e($k['musteri']) ?></strong>
              <span class="gelen-zaman"><?= Helpers::e(gelen_yas($k['zaman'])) ?></span>
            </div>
            <p class="gelen-baslik"><?= Helpers::e($k['baslik']) ?></p>
            <p class="row-meta"><?= Helpers::e($k['alt']) ?></p>
          </div>
          <div class="gelen-eylem">
            <?php if ($k['tur'] === 'siparis'): ?>
            <form method="post" class="gelen-form">
              <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
              <input type="hidden" name="order_id" value="<?= $k['id'] ?>">
              <button class="btn-action btn-primaryx" type="submit" name="action" value="approve">Onayla</button>
              <button class="btn-action btn-ghost" type="submit" name="action" value="reject">Reddet</button>
            </form>
            <?php else: ?>
            <a class="btn-action btn-secondaryx" href="<?= Helpers::e($k['link']) ?>">Cevapla</a>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
