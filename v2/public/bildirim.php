<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Push;

$u = Auth::requireLogin();
$pdo = Db::pdo();

$flash = '';
$flashOk = true;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum süresi doldu, tekrar deneyin.';
        $flashOk = false;
    } else {
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $push = new Push($pdo); // opus-021: tek kapı — ölü token temizliği + push_log içeride
        if ($title === '' || $body === '') {
            $flash = 'Başlık ve mesaj zorunlu.';
            $flashOk = false;
        } elseif (!$push->configured()) {
            $flash = 'APNs yapılandırılmamış (.env: APNS_TEAM_ID / APNS_KEY_ID / APNS_KEY_FILE).';
            $flashOk = false;
        } else {
            // kind='manuel' → sessiz saatten MUAF (Ömer bilerek gönderiyor)
            $r = $customerId > 0
                ? $push->toCustomer($customerId, $title, $body)
                : $push->toAll($title, $body);
            uysa_audit('push_gonder', $u['username'], (string) $customerId, "sent={$r['sent']} dead={$r['dead']} failed={$r['failed']}", client_ip());
            if ($r['devices'] === 0) {
                $flash = 'Kayıtlı cihaz yok — müşteri app\'e girip bildirim iznini onayladığında burada görünür.';
                $flashOk = false;
            } else {
                $flash = "Gönderildi: {$r['sent']} cihaz" . ($r['dead'] ? " · {$r['dead']} ölü token temizlendi" : '') . ($r['failed'] ? " · {$r['failed']} hata" : '');
                $flashOk = $r['failed'] === 0;
            }
        }
    }
}

// Müşteri listesi + kayıtlı cihaz sayıları
$customers = $pdo->query(
    'SELECT c.id, c.name, COUNT(pt.id) AS device_count
     FROM customers c
     LEFT JOIN push_tokens pt ON pt.customer_id = c.id
     WHERE c.is_active = 1
     GROUP BY c.id, c.name
     ORDER BY c.name'
)->fetchAll();
$totalDevices = (int) $pdo->query('SELECT COUNT(*) FROM push_tokens WHERE customer_id IS NOT NULL')->fetchColumn();
$adminDevices = (int) $pdo->query('SELECT COUNT(*) FROM push_tokens WHERE user_id IS NOT NULL')->fetchColumn();

// Son gönderimler (push_log son 50) — olay + manuel + hatırlatma geçmişi
$logs = $pdo->query(
    'SELECT pl.*, c.name AS customer_name
     FROM push_log pl LEFT JOIN customers c ON c.id = pl.customer_id
     ORDER BY pl.id DESC LIMIT 50'
)->fetchAll();
$kindLabels = [
    'menu'        => 'Menü yayını',
    'talep_cevap' => 'Talep cevabı',
    'talep_yeni'  => 'Yeni talep',
    'siparis'     => 'Sipariş',
    'reminder'    => 'Hatırlatma',
    'manuel'      => 'Elle',
];

$pageTitle = 'Bildirim';
$eyebrow = 'Müşteri app push bildirimi';
$csrf = Helpers::csrfToken();
require __DIR__ . '/partials/header.php';
?>
<?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?> mb-3"><?= Helpers::e($flash) ?></div><?php endif; ?>

<section class="cardx card-pad mb-3">
  <h2 class="h6 mb-3"><i class="bi bi-bell"></i> Bildirim gönder <span class="text-muted">(müşteri cihazı: <?= $totalDevices ?> · admin cihazı: <?= $adminDevices ?>)</span></h2>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
    <div class="field">
      <label>Alıcı</label>
      <select class="inputx" name="customer_id">
        <option value="0">Tüm müşteriler</option>
        <?php foreach ($customers as $c): ?>
        <option value="<?= (int) $c['id'] ?>"><?= Helpers::e($c['name']) ?> (<?= (int) $c['device_count'] ?> cihaz)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Başlık</label><input class="inputx" name="title" maxlength="80" required placeholder="Örn: Yarının menüsü yayında"></div>
    <div class="field"><label>Mesaj</label><textarea class="inputx" name="body" rows="3" maxlength="500" required placeholder="Bildirim metni"></textarea></div>
    <button class="btn-action btn-primaryx" type="submit" onclick="return confirm('Bildirim gönderilsin mi?')"><i class="bi bi-send"></i> Gönder</button>
  </form>
</section>

<section class="cardx card-pad mb-3">
  <h2 class="h6 mb-3"><i class="bi bi-clock-history"></i> Son gönderimler <span class="text-muted">(son 50 · otomatik olay bildirimleri dahil)</span></h2>
  <?php if (!$logs): ?>
    <div class="empty-state">
      <div class="es-ico"><i class="bi bi-bell"></i></div>
      Henüz gönderim yok.</div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tablex" style="width:100%;font-size:13px">
        <thead><tr><th>Tarih</th><th>Tür</th><th>Hedef</th><th>Başlık</th><th style="text-align:right">Gönderilen</th><th style="text-align:right">Ölü</th><th>Durum</th></tr></thead>
        <tbody>
          <?php foreach ($logs as $l): ?>
          <tr>
            <td style="white-space:nowrap"><?= Helpers::e(substr((string) $l['created_at'], 0, 16)) ?></td>
            <td><?= Helpers::e($kindLabels[$l['kind']] ?? $l['kind']) ?></td>
            <td><?php
                if (!empty($l['customer_name'])) {
                    echo Helpers::e($l['customer_name']);
                } elseif (in_array($l['kind'], ['talep_yeni', 'siparis'], true)) {
                    echo 'Adminler';
                } else {
                    echo 'Tüm müşteriler';
                }
            ?></td>
            <td><?= Helpers::e($l['title']) ?><?php if (trim((string) $l['body']) !== ''): ?> <span class="text-muted">· <?= Helpers::e(mb_substr((string) $l['body'], 0, 60)) ?></span><?php endif; ?></td>
            <td style="text-align:right"><?= (int) $l['sent'] ?></td>
            <td style="text-align:right"><?= (int) $l['dead'] ?></td>
            <td><?php if ((int) $l['suppressed'] === 1): ?><span class="badge-soft badge-warn"><i class="bi bi-moon"></i> Sessiz saat</span>
                <?php elseif ((int) $l['sent'] > 0): ?><span class="badge-soft badge-ok"><i class="bi bi-check2"></i> Gönderildi</span>
                <?php else: ?><span class="badge-soft badge-blue"><i class="bi bi-dash"></i> Cihaz yok</span><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php $active = ''; require __DIR__ . '/partials/footer.php'; ?>
