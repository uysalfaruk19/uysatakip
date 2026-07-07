<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Helpers;

$u = Auth::requireLogin();

// Yol haritasındaki (henüz doldurulmamış) GELECEK özellikler — temiz placeholder, kırık ekran YOK.
// Broşür menü seti (kiosk hariç) tamamlandı; buradaki modüllerin hepsi CANLI. Burası artık
// yalnızca ileride açılacak özellikler için (ör. profil).
$MODULES = [
    'profil' => ['Profil', 'bi-person-gear', 'Kullanıcı ayarları, şifre değiştirme ve tercih yönetimi.'],
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
