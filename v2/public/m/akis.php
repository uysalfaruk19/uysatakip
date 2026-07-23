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
          <div class="es-ico"><i class="bi bi-bell"></i></div>
          Henüz olay yok — siparişleriniz ve talepleriniz burada görünecek.
        </div>
      <?php else: /* fable-032 tur2: gerçek dikey zaman çizgisi (nokta + çizgi + zaman meta) */ ?>
        <div class="feed-timeline">
          <?php foreach ($events as $e): $ico = $iconMap[$e['type']] ?? 'bi-bell'; ?>
            <a class="feed-node" href="<?= Helpers::e((string) $e['url']) ?>">
              <span class="feed-ico"><i class="bi <?= $ico ?>"></i></span>
              <div class="feed-body">
                <div class="feed-top">
                  <strong><?= Helpers::e((string) $e['title']) ?></strong>
                  <span class="feed-time"><?= Helpers::e(zaman_kisa_tr((string) $e['created_at'])) ?></span>
                </div>
                <?php if (trim((string) ($e['body'] ?? '')) !== ''): ?>
                  <p class="row-meta"><?= Helpers::e((string) $e['body']) ?></p>
                <?php endif; ?>
              </div>
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
