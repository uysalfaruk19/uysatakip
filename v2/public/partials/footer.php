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
// aksiyon-faz4: (+) menüsündeki Gelen rozeti — cevap bekleyen sipariş + açık talep sayısı.
// Sayaç bu menüyü ASLA çökertmez; hata olursa rozet basılmaz (sessizce yok sayılır).
$fabGelenSayi = 0;
try {
    if (isset($repo) && $repo instanceof Uysa\Repo) {
        $fabGelenSayi = count($repo->pendingOrders()) + count($repo->allRequests(['status' => 'acik']));
    }
} catch (\Throwable $e) {
    $fabGelenSayi = 0;
}
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
  <?php // fable-048a (Ömer): hızlı erişimde/alt barda olmayan işler burada — Fatura Kes
        // hızlı erişimden buraya taşındı; mutfak/sevkiyat da boş kalmasın diye eklendi. ?>
  <?php // aksiyon-faz4: (+) yalnız "gelen iş" ve "yeni kayıt" için. Mutfak ve Sevkiyat GÜNÜN
        // işleri → Bugün ekranının gün şeridine taşındı; Ay kapanışı ay sonu işi → Finans'ta.
        // Hiçbir sayfa silinmedi, erişim yeri değişti. ?>
  <a href="gelen.php"><i class="bi bi-inbox"></i> Gelen<?php
    if (!empty($fabGelenSayi)): ?> <span class="fab-rozet"><?= (int) $fabGelenSayi ?></span><?php endif; ?></a>
  <a href="fatura-kes.php"><i class="bi bi-receipt-cutoff"></i> Fatura Kes</a>
  <a href="cari.php?sekme=borclarim"><i class="bi bi-arrow-up-right-circle"></i> Borçlarım</a>
  <a href="musteriler.php?yeni=1"><i class="bi bi-person-plus"></i> Müşteri ekle</a>
  <a href="moduller.php"><i class="bi bi-grid-3x3-gap"></i> Diğer modüller…</a>
</div>

<script src="assets/app.js?v=<?= filemtime(__DIR__ . '/../assets/app.js') ?>"></script>
<script>window.UYSA_NATIVE_CONTEXT = {authenticated: true, guard: 'admin', pushEndpoint: '/push-register.php'};</script>
<script src="assets/push.js?v=<?= filemtime(__DIR__ . '/../assets/push.js') ?>"></script>
</body>
</html>
