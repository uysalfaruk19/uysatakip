<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Helpers;

$u = Auth::requireLogin();

// Yol haritasındaki (henüz doldurulmamış) modüller — temiz placeholder, kırık ekran YOK.
$MODULES = [
    'siparisler' => ['Siparişler', 'bi-basket', 'Müşteri sipariş akışı ve onay kuyruğu. Müşteri uygulaması (F2) ile birlikte açılır.'],
    'stok'       => ['Stok Durumu', 'bi-box-seam', 'Malzeme giriş/çıkış, anlık stok ve kritik stok uyarıları.'],
    'recete'     => ['Reçete & Maliyet', 'bi-clipboard2-data', 'Reçete × gramaj → porsiyon maliyeti → müşteri karlılığı.'],
    'personel'   => ['Personel Giderleri', 'bi-person-badge', 'Maaş, prim ve personel giderleri takibi, raporlara yansıması.'],
    'profil'     => ['Profil', 'bi-person-gear', 'Kullanıcı ayarları, şifre değiştirme ve tercih yönetimi.'],
];

$key = (string) ($_GET['m'] ?? '');
$mod = $MODULES[$key] ?? ['Modül', 'bi-hourglass-split', 'Bu bölüm hazırlanıyor.'];
[$title, $icon, $desc] = $mod;

$eyebrow = 'Yol haritası';
$pageTitle = $title;
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <div class="cardx card-pad">
        <div class="soon-hero">
          <div class="big-ico"><i class="bi <?= Helpers::e($icon) ?>"></i></div>
          <div>
            <h2 style="margin-bottom:6px"><?= Helpers::e($title) ?></h2>
            <p class="text-muted" style="font-size:13.5px; line-height:1.45"><?= Helpers::e($desc) ?></p>
          </div>
          <span class="badge-soft badge-warn"><i class="bi bi-hammer"></i> Hazırlanıyor</span>
          <a class="btn-action btn-secondaryx" href="bugun.php"><i class="bi bi-arrow-left"></i> Panele dön</a>
        </div>
      </div>
<?php require __DIR__ . '/partials/footer.php'; ?>
