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
  <?php else: [$label, $icon] = $meta;
    // fable-041a (Ömer): Kâr/Zarar sekmesi artık kar-analizi'ne iner (Üretim|Taşıma + gıda
    // maliyeti orada); günlük üretim raporu (rapor.php) moduller'den erişilir.
    $href = $key === 'rapor' ? 'kar-analizi.php' : $key . '.php'; ?>
    <a class="tab-item <?= $active === $key ? 'active' : '' ?>" href="<?= $href ?>">
      <i class="bi <?= $icon ?>"></i><?= $label ?>
    </a>
  <?php endif; ?>
<?php endforeach; ?>
</nav>

<div class="fab-backdrop" id="fab-backdrop" onclick="toggleFabMenu()"></div>
<!-- fable-030 (Ömer: "+ çok kalabalık"): menü SIK işlere indi; kalan her şey "Diğer modüller"de.
     Kaldırılan link YOK — hepsi moduller.php'de yaşıyor (erişim kaybolmaz). -->
<div class="fab-menu" id="fab-menu" role="menu">
  <a href="bugun.php"><i class="bi bi-calendar2-check"></i> Üretim gir</a>
  <a href="fatura-kes.php"><i class="bi bi-receipt-cutoff"></i> Fatura Kes</a>
  <a href="finans.php"><i class="bi bi-plus-slash-minus"></i> Gider / Gelir</a>
  <a href="cari.php"><i class="bi bi-cash-coin"></i> Tahsilat / Cari</a>
  <a href="ay-kapanisi.php"><i class="bi bi-calendar2-check"></i> Ay kapanışı</a>
  <a href="moduller.php"><i class="bi bi-grid-3x3-gap"></i> Diğer modüller…</a>
</div>

<script src="assets/app.js?v=<?= filemtime(__DIR__ . '/../assets/app.js') ?>"></script>
<script>window.UYSA_NATIVE_CONTEXT = {authenticated: true, guard: 'admin', pushEndpoint: '/push-register.php'};</script>
<script src="assets/push.js?v=<?= filemtime(__DIR__ . '/../assets/push.js') ?>"></script>
</body>
</html>
