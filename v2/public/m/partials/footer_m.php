<?php
/** @var string $active */
$active = $active ?? '';
// fable-018: 5-sekme yeniden düzeni — Bugün · Sayı Bildir · Menü · Akış · Daha.
// Malzeme/Talep/Cari bardan kalktı → "Daha" altında (sayfalar aynen erişilebilir, URL kırılmaz).
$tabs = [
  'panel'   => ['Bugün', 'bi-house-check'],
  'siparis' => ['Sayı Bildir', 'bi-plus-square'],
  'menu'    => ['Menü', 'bi-card-list'],
  'akis'    => ['Akış', 'bi-bell'],
  'daha'    => ['Daha', 'bi-grid'],
];

// fable-018: Akış okunmadı rozeti — badgeCountFor ile AYNI kaynak (customer_events); çift sayaç yok.
$feedBadge = 0;
try {
    $cuFooter = \Uysa\CustomerAuth::customer();
    if ($cuFooter) {
        $feedBadge = (new \Uysa\Repo(\Uysa\Db::pdo()))->feedUnseenCount((int) $cuFooter['customer_id'], (int) $cuFooter['cuid']);
    }
} catch (\Throwable) {
    $feedBadge = 0; // rozet hesabı sayfayı ASLA kırmaz
}
?>
  </section><!-- /.screen-stack -->
</main>
<nav class="bottom-tabs">
<?php foreach ($tabs as $key => [$label, $icon]): ?>
  <a class="tab-item <?= $active === $key ? 'active' : '' ?>" href="<?= $key ?>.php">
    <span class="tab-ico">
      <i class="bi <?= $icon ?>"></i>
      <?php if ($key === 'akis' && $feedBadge > 0): ?><span class="tab-badge"><?= $feedBadge >= 99 ? '99+' : $feedBadge ?></span><?php endif; ?>
    </span>
    <?= $label ?>
  </a>
<?php endforeach; ?>
</nav>
<script src="/assets/app.js?v=<?= filemtime(__DIR__ . '/../../assets/app.js') ?>"></script>
<script>window.UYSA_NATIVE_CONTEXT = {authenticated: true, guard: 'customer', pushEndpoint: '/m/push-register.php'};</script>
<script src="/assets/push.js?v=<?= filemtime(__DIR__ . '/../../assets/push.js') ?>"></script>
</body>
</html>
