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

$flash = '';
$flashOk = true;
$formOpen = isset($_GET['yeni']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
        $formOpen = true;
    } else {
        $action = (string) ($_POST['action'] ?? 'create');
        if ($action === 'create') {
            $customerId = (int) ($_POST['customer_id'] ?? 0);
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $display = trim((string) ($_POST['display_name'] ?? ''));
            $cust = $customerId > 0 ? $repo->customer($customerId) : null;
            if (!$cust) {
                $flash = 'Müşteri seçin.';
                $flashOk = false;
                $formOpen = true;
            } elseif (!preg_match('/^[a-z0-9._-]{3,40}$/i', $username)) {
                $flash = 'Kullanıcı adı 3-40 karakter (harf, rakam, . _ -) olmalı.';
                $flashOk = false;
                $formOpen = true;
            } elseif (strlen($password) < 6) {
                $flash = 'Şifre en az 6 karakter olmalı.';
                $flashOk = false;
                $formOpen = true;
            } else {
                $newId = $repo->createCustomerUser($customerId, $username, $password, $display ?: null);
                if ($newId === 0) {
                    $flash = 'Bu kullanıcı adı zaten alınmış.';
                    $flashOk = false;
                    $formOpen = true;
                } else {
                    uysa_audit('musteri_giris_olustur', $u['username'], (string) $newId, $username . ' → ' . $cust['name'], client_ip());
                    $flash = 'Giriş oluşturuldu: ' . $username . ' → ' . $cust['name'];
                }
            }
        } elseif ($action === 'reset') {
            $id = (int) ($_POST['id'] ?? 0);
            $password = (string) ($_POST['password'] ?? '');
            if ($id > 0 && strlen($password) >= 6) {
                $repo->resetCustomerUserPassword($id, $password);
                uysa_audit('musteri_giris_sifre', $u['username'], (string) $id, null, client_ip());
                $flash = 'Şifre güncellendi.';
            } else {
                $flash = 'Yeni şifre en az 6 karakter olmalı.';
                $flashOk = false;
            }
        } elseif ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            $active = (int) ($_POST['active'] ?? 0) === 1;
            if ($id > 0) {
                $repo->setCustomerUserActive($id, $active);
                uysa_audit('musteri_giris_' . ($active ? 'aktif' : 'pasif'), $u['username'], (string) $id, null, client_ip());
                $flash = $active ? 'Giriş yeniden aktifleştirildi.' : 'Giriş pasifleştirildi.';
            }
        }
    }
}

$users = $repo->listCustomerUsers();
$customers = $repo->activeCustomers();

$csrf = Helpers::csrfToken();
$eyebrow = 'Müşteri uygulaması erişimi';
$pageTitle = 'Müşteri Girişleri';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <?php if (!$formOpen): ?>
        <a class="btn-action btn-primaryx btn-full" href="musteri-giris.php?yeni=1"><i class="bi bi-person-badge"></i> Müşteri Girişi Oluştur</a>
      <?php endif; ?>

      <div class="fab-sheet" id="giris-form" style="<?= $formOpen ? '' : 'display:none' ?>">
        <h2>Yeni müşteri girişi</h2>
        <p class="text-muted" style="font-size:12px;margin-top:-4px">Müşteri firma kendi telefonundan bu kullanıcı adı/şifre ile <strong>/m</strong> uygulamasına girer; sadece kendi verisini görür.</p>
        <form method="post" class="form-grid" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
          <input type="hidden" name="action" value="create">

          <div class="field"><label>Müşteri firma</label>
            <select class="inputx" name="customer_id" required>
              <option value="">Seçin…</option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= (int) $c['id'] ?>"><?= Helpers::e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field"><label>Kullanıcı adı</label>
            <input class="inputx" name="username" autocapitalize="none" autocomplete="off" required placeholder="orn: cantas">
          </div>
          <div class="field"><label>Şifre (en az 6 karakter)</label>
            <input class="inputx" name="password" type="text" autocomplete="new-password" required placeholder="ilk şifre">
          </div>
          <div class="field"><label>Görünen ad (opsiyonel)</label>
            <input class="inputx" name="display_name" placeholder="ör. Cantaş A.Ş.">
          </div>

          <div class="actions-row">
            <a class="btn-action btn-ghost flex-fill" href="musteri-giris.php">Vazgeç</a>
            <button class="btn-action btn-primaryx flex-fill" type="submit"><i class="bi bi-check2"></i> Oluştur</button>
          </div>
        </form>
      </div>

      <div class="section-head mt-3"><h2>Mevcut girişler</h2><span class="text-muted" style="font-size:12px"><?= count($users) ?> hesap</span></div>
      <?php if (!$users): ?>
        <div class="empty-state">Henüz müşteri girişi oluşturulmadı.</div>
      <?php else: ?>
        <div class="cardx card-pad">
          <?php foreach ($users as $cu): $on = (int) $cu['is_active'] === 1; ?>
            <div class="customer-row">
              <div style="min-width:0">
                <div class="row-title">
                  <span class="status-dot" style="background:<?= $on ? '#16a34a' : '#9ca3af' ?>"></span>
                  <strong><?= Helpers::e($cu['username']) ?></strong>
                  <?php if (!$on): ?><span class="badge-soft badge-neg">Pasif</span><?php endif; ?>
                </div>
                <p class="row-meta"><?= Helpers::e($cu['customer_name']) ?><?php if ($cu['last_login']): ?> · son giriş <?= Helpers::e((string) $cu['last_login']) ?><?php endif; ?></p>
              </div>
              <div class="actions-row" style="justify-content:flex-end">
                <button class="icon-btn" type="button" aria-label="Şifre sıfırla" onclick="toggleReset(<?= (int) $cu['id'] ?>)"><i class="bi bi-key"></i></button>
                <form method="post" onsubmit="return confirm('<?= $on ? 'Bu giriş pasifleştirilsin mi?' : 'Bu giriş yeniden aktifleştirilsin mi?' ?>');" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int) $cu['id'] ?>">
                  <input type="hidden" name="active" value="<?= $on ? 0 : 1 ?>">
                  <button class="icon-btn" type="submit" aria-label="<?= $on ? 'Pasifleştir' : 'Aktifleştir' ?>"><i class="bi <?= $on ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i></button>
                </form>
              </div>
            </div>
            <form method="post" class="form-grid" id="reset-<?= (int) $cu['id'] ?>" style="display:none;margin:6px 0 14px">
              <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
              <input type="hidden" name="action" value="reset">
              <input type="hidden" name="id" value="<?= (int) $cu['id'] ?>">
              <div class="actions-row">
                <input class="inputx flex-fill" name="password" type="text" autocomplete="new-password" placeholder="yeni şifre (min 6)" required>
                <button class="btn-action btn-primaryx" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
              </div>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="hint-card mt-3">
        Müşteri uygulaması adresi: <strong>/m/login.php</strong>. Bir firmaya birden çok giriş açılabilir. Pasif giriş uygulamaya alınmaz.
      </div>

      <script>
        function toggleReset(id){
          var f = document.getElementById('reset-' + id);
          if (f) f.style.display = (f.style.display === 'none' || !f.style.display) ? 'block' : 'none';
        }
      </script>
<?php require __DIR__ . '/partials/footer.php'; ?>
