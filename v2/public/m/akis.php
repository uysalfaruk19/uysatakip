<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Uysa\CustomerAuth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

$cu = CustomerAuth::requireCustomer();
$cid = (int) $cu['customer_id'];
$repo = new Repo(Db::pdo());

// fable-018: sayfa açılınca okundu kesimi şimdiye çekilir (Akış rozeti bu customer_user için sıfırlanır).
$repo->markFeedSeen((int) $cu['cuid']);

$perPage = 50;
$offset = max(0, (int) ($_GET['o'] ?? 0));
$events = $repo->customerEvents($cid, $perPage, $offset);

// fable-018: type → ikon (Akış satır ikonu, para YOK — müşteri yüzü).
$iconMap = [
    'menu_yayin'    => 'bi-card-list',
    'talep_cevap'   => 'bi-chat-left-text',
    'siparis_durum' => 'bi-clipboard-check',
    'malzeme_durum' => 'bi-box-seam',
];

$eyebrow = Helpers::e($cu['customer_name']) . ' · Akış';
$pageTitle = 'Akış';
$active = 'akis';
require __DIR__ . '/partials/header_m.php';
?>
      <?php if (!$events && $offset === 0): ?>
        <div class="empty-state">
          Henüz olay yok — siparişleriniz ve talepleriniz burada görünecek.
        </div>
      <?php else: ?>
        <div class="list-groupx">
          <?php foreach ($events as $e): $ico = $iconMap[$e['type']] ?? 'bi-bell'; ?>
            <a class="flow-item" style="grid-template-columns: 34px minmax(0,1fr) auto" href="<?= Helpers::e((string) $e['url']) ?>">
              <span class="feed-ico"><i class="bi <?= $ico ?>"></i></span>
              <div style="min-width:0">
                <strong><?= Helpers::e((string) $e['title']) ?></strong>
                <?php if (trim((string) ($e['body'] ?? '')) !== ''): ?>
                  <p class="row-meta"><?= Helpers::e((string) $e['body']) ?></p>
                <?php endif; ?>
              </div>
              <span class="row-meta" style="white-space:nowrap"><?= Helpers::e(zaman_kisa_tr((string) $e['created_at'])) ?></span>
            </a>
          <?php endforeach; ?>
        </div>

        <?php if (count($events) === $perPage): ?>
          <a class="btn-action btn-secondaryx btn-full mt-3" href="akis.php?o=<?= $offset + $perPage ?>">Daha eski olaylar</a>
        <?php elseif ($offset > 0): ?>
          <a class="btn-action btn-ghost btn-full mt-3" href="akis.php">En başa dön</a>
        <?php endif; ?>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer_m.php'; ?>
