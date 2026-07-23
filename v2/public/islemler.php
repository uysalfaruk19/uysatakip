<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

// fable-003 (cateringkolay İşlem Kaydı esinli): audit tablosunun salt-okunur ekranı.
// "Kim, ne zaman, ne yaptı" — veri zaten toplanıyordu, görünür oldu.
$u = Auth::requireLogin();
$pdo = Db::pdo();
$repo = new Repo($pdo);

$q = trim((string) ($_GET['q'] ?? ''));
$rows = $repo->auditRecent(100, $q ?: null);

$pageTitle = 'İşlem Kaydı';
$eyebrow = 'Denetim izi (son 100)';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <form method="get" class="date-row">
        <div class="date-pill" style="flex:1"><i class="bi bi-search"></i>
          <input type="text" name="q" value="<?= Helpers::e($q) ?>" placeholder="İşlem veya kullanıcı ara…" style="flex:1">
        </div>
        <button class="btn-action btn-secondaryx" type="submit">Ara</button>
      </form>

      <?php if (!$rows): ?>
        <div class="empty-state">
          <div class="es-ico"><i class="bi bi-cash-coin"></i></div>
          <?= $q ? 'Eşleşen kayıt yok.' : 'Henüz işlem kaydı yok.' ?></div>
      <?php else: ?>
        <div class="cardx card-pad">
          <div class="list-groupx">
            <?php foreach ($rows as $r): ?>
              <div class="flow-item" style="grid-template-columns: minmax(0,1fr) auto">
                <div style="min-width:0">
                  <div class="row-title"><strong><?= Helpers::e($r['action']) ?></strong><?= $r['target_key'] ? ' <span class="s-meta">· ' . Helpers::e($r['target_key']) . '</span>' : '' ?></div>
                  <p class="row-meta"><?= Helpers::e((string) ($r['actor'] ?? '—')) ?><?= $r['detail'] ? ' · ' . Helpers::e(mb_substr((string) $r['detail'], 0, 80)) : '' ?></p>
                </div>
                <span class="s-meta" style="white-space:nowrap"><?= Helpers::e(date('d.m H:i', strtotime((string) $r['created_at']))) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
