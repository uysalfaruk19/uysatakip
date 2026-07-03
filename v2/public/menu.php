<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;

$u = Auth::requireLogin();
$eyebrow = 'Faz 2';
$pageTitle = 'Menü & Maliyet';
$active = 'menu';
require __DIR__ . '/partials/header.php';
?>
      <div class="cardx card-pad">
        <h2>Menü & Maliyet</h2>
        <div class="empty-state">
          Bu modül <strong>Faz 2</strong>'de açılıyor.<br>
          Reçete × gramaj → porsiyon maliyeti → müşteri karlılık + menü yayınlama.
          <div class="mt-3"><span class="badge-soft badge-warn">F2</span></div>
        </div>
        <p class="row-meta mt-3">Reçete ve hammadde fiyatları migrasyonla taşındı; şema hazır.</p>
      </div>
<?php require __DIR__ . '/partials/footer.php'; ?>
