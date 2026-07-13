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
  <p class="fab-label">Günlük</p>
  <a href="bugun.php"><i class="bi bi-calendar2-check"></i> Üretim gir</a>
  <a href="finans.php"><i class="bi bi-plus-slash-minus"></i> Gider / Gelir ekle</a>
  <a href="cari.php"><i class="bi bi-cash-coin"></i> Tahsilat / Cari</a>
  <p class="fab-label">Operasyon</p><!-- fable-003: demo esinli modüller — ana ekran sade kaldı -->
  <a href="mutfak.php"><i class="bi bi-fire"></i> Mutfak görünümü</a>
  <a href="sevkiyat.php"><i class="bi bi-truck"></i> Sevkiyat / teslimat</a>
  <a href="haccp.php"><i class="bi bi-clipboard2-check"></i> HACCP kontrol</a>
  <p class="fab-label">Yönetim</p>
  <a href="musteriler.php?yeni=1"><i class="bi bi-person-plus"></i> Müşteri ekle</a>
  <a href="musteri-giris.php?yeni=1"><i class="bi bi-person-badge"></i> Müşteri girişi oluştur</a>
  <a href="teklifler.php"><i class="bi bi-briefcase"></i> Teklifler</a>
  <a href="tedarikciler.php"><i class="bi bi-shop-window"></i> Tedarikçiler</a>
  <a href="bildirim.php"><i class="bi bi-bell"></i> Bildirim gönder (app)</a>
  <p class="fab-label">Dönem &amp; muhasebe</p>
  <a href="ay-kapanisi.php"><i class="bi bi-calendar2-check"></i> Ay kapanışı</a>
  <a href="parasut.php"><i class="bi bi-shield-check"></i> Paraşüt cari (muhasebe)</a>
  <a href="islemler.php"><i class="bi bi-list-check"></i> İşlem kaydı</a>
</div>

<script src="assets/app.js?v=<?= filemtime(__DIR__ . '/../assets/app.js') ?>"></script>
<script>window.UYSA_NATIVE_CONTEXT = {authenticated: true, guard: 'admin', pushEndpoint: '/push-register.php'};</script>
<script src="assets/push.js?v=<?= filemtime(__DIR__ . '/../assets/push.js') ?>"></script>
</body>
</html>
