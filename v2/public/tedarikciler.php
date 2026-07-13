<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

// fable-003 (cateringkolay Tedarikçiler esinli): tedarikçi kartları + son alışlar.
// suppliers tablosu zaten vardı (finans gider bağlantısı) — burada yönetim ekranı.
$u = Auth::requireLogin();
$pdo = Db::pdo();
$repo = new Repo($pdo);

$flash = '';
$flashOk = true;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'kaydet') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $contact = trim((string) ($_POST['contact'] ?? '')) ?: null;
            $id = (int) ($_POST['id'] ?? 0) ?: null;
            if ($name === '') {
                $flash = 'Tedarikçi adı zorunlu.';
                $flashOk = false;
            } else {
                try {
                    $sid = $repo->upsertSupplier(mb_substr($name, 0, 120), $contact ? mb_substr($contact, 0, 200) : null, $id);
                    uysa_audit('tedarikci_kaydet', $u['username'], (string) $sid, $name, client_ip());
                    $flash = 'Tedarikçi kaydedildi: ' . $name;
                } catch (\Throwable) {
                    $flash = 'Kayıt hatası (ad benzersiz olmalı).';
                    $flashOk = false;
                }
            }
        } elseif ($action === 'aktif') {
            $repo->setSupplierActive((int) ($_POST['id'] ?? 0), (int) ($_POST['active'] ?? 1) === 1);
            $flash = 'Tedarikçi güncellendi.';
        }
    }
}

$list = $repo->listSuppliers(false);
$editItem = isset($_GET['edit']) ? $repo->supplierById((int) $_GET['edit']) : null;
$openId = (int) ($_GET['gecmis'] ?? 0);
$csrf = Helpers::csrfToken();

$pageTitle = 'Tedarikçiler';
$eyebrow = 'Tedarikçi kartları & son alışlar';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <div class="fab-sheet">
        <h2><?= $editItem ? 'Tedarikçi düzenle' : 'Yeni tedarikçi' ?></h2>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
          <input type="hidden" name="action" value="kaydet">
          <?php if ($editItem): ?><input type="hidden" name="id" value="<?= (int) $editItem['id'] ?>"><?php endif; ?>
          <div class="field"><label>Ad</label>
            <input class="inputx" name="name" maxlength="120" required value="<?= Helpers::e($editItem['name'] ?? '') ?>" placeholder="ör. X Gıda Toptan"></div>
          <div class="field"><label>İletişim (telefon / yetkili, opsiyonel)</label>
            <input class="inputx" name="contact" maxlength="200" value="<?= Helpers::e($editItem['contact'] ?? '') ?>" placeholder="ör. Mehmet Bey 0532 ..."></div>
          <div class="actions-row">
            <?php if ($editItem): ?><a class="btn-action btn-ghost flex-fill" href="tedarikciler.php">Vazgeç</a><?php endif; ?>
            <button class="btn-action btn-primaryx flex-fill" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
          </div>
        </form>
      </div>

      <div class="section-head mt-3"><h2>Tedarikçiler</h2><span class="text-muted" style="font-size:12px"><?= count($list) ?> kayıt</span></div>
      <?php if (!$list): ?>
        <div class="empty-state">Henüz tedarikçi yok. Stok girişinde tedarikçi seçebilmek için ekleyin.</div>
      <?php else: ?>
        <div class="cardx card-pad">
          <?php foreach ($list as $s): $pasif = (int) $s['is_active'] !== 1; ?>
            <div class="customer-row" style="<?= $pasif ? 'opacity:.55' : '' ?>">
              <div style="min-width:0">
                <div class="row-title"><span class="status-dot"></span><strong><?= Helpers::e($s['name']) ?></strong> <?php if ($pasif): ?><span class="badge-soft badge-warn">Pasif</span><?php endif; ?></div>
                <?php if ($s['contact']): ?><p class="row-meta"><?= Helpers::e($s['contact']) ?></p><?php endif; ?>
              </div>
              <div class="actions-row" style="justify-content:flex-end">
                <a class="icon-btn" href="tedarikciler.php?gecmis=<?= $openId === (int) $s['id'] ? 0 : (int) $s['id'] ?>" aria-label="Son alışlar"><i class="bi bi-clock-history"></i></a>
                <a class="icon-btn" href="tedarikciler.php?edit=<?= (int) $s['id'] ?>" aria-label="Düzenle"><i class="bi bi-pencil"></i></a>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
                  <input type="hidden" name="action" value="aktif">
                  <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <input type="hidden" name="active" value="<?= $pasif ? 1 : 0 ?>">
                  <button class="icon-btn" type="submit" aria-label="<?= $pasif ? 'Aktifleştir' : 'Pasifleştir' ?>"><i class="bi <?= $pasif ? 'bi-arrow-counterclockwise' : 'bi-archive' ?>"></i></button>
                </form>
              </div>
            </div>
            <?php if ($openId === (int) $s['id']): $hist = $repo->supplierHistory((int) $s['id']); ?>
              <div class="hint-card mb-2">
                <strong>Son alışlar</strong>
                <?php if (!$hist): ?>
                  <p class="row-meta">Kayıtlı alış yok (stok girişinde tedarikçi seçilirse burada görünür).</p>
                <?php else: foreach ($hist as $h): ?>
                  <p class="row-meta"><?= Helpers::e(date('d.m.Y', strtotime((string) $h['d']))) ?> · <?= Helpers::e((string) $h['what']) ?><?= $h['quantity'] !== null ? ' · ' . Helpers::money((float) $h['quantity']) . ' ' . Helpers::e((string) $h['unit']) : '' ?><?= $h['amount'] !== null ? ' · ₺ ' . Helpers::money((float) $h['amount']) : '' ?></p>
                <?php endforeach; endif; ?>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
