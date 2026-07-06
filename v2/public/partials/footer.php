<?php
/** @var string $active */
$active = $active ?? '';
// Broşür alt navigasyonu: 4 ana sekme + ortada turuncu (+) FAB.
$tabs = [
  'bugun'      => ['Bugün', 'bi-house-door'],
  'musteriler' => ['Müşteri', 'bi-people'],
  '__fab__'    => null,
  'rapor'      => ['Kâr/Zarar', 'bi-graph-up-arrow'],
  'finans'     => ['Finans', 'bi-wallet2'],
];
?>
  </section><!-- /.screen-stack -->
</main>
<nav class="bottom-tabs">
<?php foreach ($tabs as $key => $meta): ?>
  <?php if ($key === '__fab__'): ?>
    <div class="fab-slot">
      <button class="fab" type="button" aria-label="Hızlı işlem" onclick="toggleFabMenu()"><i class="bi bi-plus-lg"></i></button>
    </div>
  <?php else: [$label, $icon] = $meta; ?>
    <a class="tab-item <?= $active === $key ? 'active' : '' ?>" href="<?= $key ?>.php">
      <i class="bi <?= $icon ?>"></i><?= $label ?>
    </a>
  <?php endif; ?>
<?php endforeach; ?>
</nav>

<div class="fab-backdrop" id="fab-backdrop" onclick="toggleFabMenu()"></div>
<div class="fab-menu" id="fab-menu" role="menu">
  <a href="bugun.php"><i class="bi bi-calendar2-check"></i> Üretim gir</a>
  <a href="finans.php"><i class="bi bi-plus-slash-minus"></i> Gider / Gelir ekle</a>
  <a href="cari.php"><i class="bi bi-cash-coin"></i> Tahsilat / Cari</a>
  <a href="musteriler.php?yeni=1"><i class="bi bi-person-plus"></i> Müşteri ekle</a>
  <a href="parasut.php"><i class="bi bi-shield-check"></i> Paraşüt cari (muhasebe)</a>
</div>

<script src="assets/app.js"></script>
</body>
</html>
