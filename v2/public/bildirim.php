<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Apns;
use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;

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
        $apns = new Apns();
        if ($title === '' || $body === '') {
            $flash = 'Başlık ve mesaj zorunlu.';
            $flashOk = false;
        } elseif (!$apns->configured()) {
            $flash = 'APNs yapılandırılmamış (.env: APNS_TEAM_ID / APNS_KEY_ID / APNS_KEY_FILE).';
            $flashOk = false;
        } else {
            if ($customerId > 0) {
                $st = $pdo->prepare("SELECT token FROM push_tokens WHERE platform = 'ios' AND customer_id = ?");
                $st->execute([$customerId]);
            } else {
                $st = $pdo->query("SELECT token FROM push_tokens WHERE platform = 'ios' AND customer_id IS NOT NULL");
            }
            $tokens = $st->fetchAll(PDO::FETCH_COLUMN);
            $sent = 0;
            $dead = 0;
            $failed = 0;
            foreach ($tokens as $t) {
                $r = $apns->send((string) $t, $title, $body);
                if ($r['ok']) {
                    $sent++;
                } elseif (in_array($r['reason'], ['BadDeviceToken', 'Unregistered', 'ExpiredToken'], true) || $r['status'] === 410) {
                    $pdo->prepare('DELETE FROM push_tokens WHERE token = ?')->execute([$t]);
                    $dead++;
                } else {
                    $failed++;
                }
            }
            uysa_audit('push_gonder', $u['username'], (string) $customerId, "sent=$sent dead=$dead failed=$failed", client_ip());
            if ($tokens === []) {
                $flash = 'Kayıtlı cihaz yok — müşteri app\'e girip bildirim iznini onayladığında burada görünür.';
                $flashOk = false;
            } else {
                $flash = "Gönderildi: $sent cihaz" . ($dead ? " · $dead ölü token temizlendi" : '') . ($failed ? " · $failed hata" : '');
                $flashOk = $failed === 0;
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

$pageTitle = 'Bildirim';
$eyebrow = 'Müşteri app push bildirimi';
$csrf = Helpers::csrfToken();
require __DIR__ . '/partials/header.php';
?>
<?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?> mb-3"><?= Helpers::e($flash) ?></div><?php endif; ?>

<section class="cardx card-pad mb-3">
  <h2 class="h6 mb-3"><i class="bi bi-bell"></i> Bildirim gönder <span class="text-muted">(kayıtlı cihaz: <?= $totalDevices ?>)</span></h2>
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

<?php $active = ''; require __DIR__ . '/partials/footer.php'; ?>
