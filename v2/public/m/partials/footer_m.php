<?php
/** @var string $active */
$active = $active ?? '';
// fable-001: "Sipariş" → "Sayı Bildir" (Ömer: açıklayıcı değildi); Cari bardan kalktı
// (muhasebesel bilgi idari işlerin gözünden uzak — sayfa hâlâ /m/cari.php'de erişilebilir).
$tabs = [
  'panel'   => ['Panel', 'bi-house-check'],
  'siparis' => ['Sayı Bildir', 'bi-plus-square'],
  'menu'    => ['Menü', 'bi-card-list'],
  'malzeme' => ['Malzeme', 'bi-box-seam'],
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
