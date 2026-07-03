<?php
/** @var string $active */
$active = $active ?? '';
$tabs = [
  'bugun'  => ['Bugün', 'bi-calendar2-check', false],
  'finans' => ['Finans', 'bi-wallet2', false],
  'cari'   => ['Cari', 'bi-people', false],
  'menu'   => ['Menü', 'bi-clipboard2-data', true],
  'rapor'  => ['Rapor', 'bi-graph-up', true],
];
?>
  </section><!-- /.screen-stack -->
</main>
<nav class="bottom-tabs">
<?php foreach ($tabs as $key => [$label, $icon, $soon]): ?>
  <a class="tab-item <?= $active === $key ? 'active' : '' ?>" href="<?= $key ?>.php">
    <i class="bi <?= $icon ?>"></i><?= $label ?><?php if ($soon): ?><span class="soon">F2</span><?php endif; ?>
  </a>
<?php endforeach; ?>
</nav>
<script src="assets/app.js"></script>
</body>
</html>
