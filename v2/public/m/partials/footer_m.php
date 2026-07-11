<?php
/** @var string $active */
$active = $active ?? '';
$tabs = [
  'panel'   => ['Panel', 'bi-house-check'],
  'siparis' => ['Sipariş', 'bi-plus-square'],
  'menu'    => ['Menü', 'bi-card-list'],
  'malzeme' => ['Malzeme', 'bi-box-seam'],
  'cari'    => ['Cari', 'bi-receipt'],
  'talep'   => ['Talep', 'bi-chat-left-text'],
];
?>
  </section><!-- /.screen-stack -->
</main>
<nav class="bottom-tabs">
<?php foreach ($tabs as $key => [$label, $icon]): ?>
  <a class="tab-item <?= $active === $key ? 'active' : '' ?>" href="<?= $key ?>.php">
    <i class="bi <?= $icon ?>"></i><?= $label ?>
  </a>
<?php endforeach; ?>
</nav>
<script src="/assets/app.js?v=<?= filemtime(__DIR__ . '/../../assets/app.js') ?>"></script>
<script>window.UYSA_NATIVE_CONTEXT = {authenticated: true, guard: 'customer', pushEndpoint: '/m/push-register.php'};</script>
<script src="/assets/push.js?v=<?= filemtime(__DIR__ . '/../../assets/push.js') ?>"></script>
</body>
</html>
