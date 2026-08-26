<?php
/**
 * aksiyon-faz7 — KARAR RAKAMI + AKILLI DURUM (ortak bileşen).
 *
 * Aksiyon deseninin ilk iki kuralı her ekranda aynı görünsün diye tek yerde yaşar:
 *   1) ekranın en üstünde tek büyük rakam — "buraya neden girdim"in cevabı
 *   2) altında tek satır durum + o satırı kapatan tek buton
 *
 * Kullanım (header.php'den HEMEN sonra):
 *   $kararRakam = '7 kalem'; $kararAlt = 'sipariş edilmesi gerekiyor';
 *   $durumMetin = '3 kalem kritik'; $durumNot = '...'; $durumBtn = ['Listeyi kopyala', 'stok.php?kopya=1'];
 *   require __DIR__ . '/partials/karar.php';
 *
 * SESSİZLİK KURALI: $durumMetin boşsa durum satırı HİÇ basılmaz — sorun yoksa ekran susar.
 *
 * @var string      $kararRakam
 * @var string      $kararAlt
 * @var string      $durumMetin
 * @var string      $durumNot
 * @var array|null  $durumBtn  [etiket, href]
 */

use Uysa\Helpers;

$kararRakam = $kararRakam ?? '';
$kararAlt = $kararAlt ?? '';
$durumMetin = $durumMetin ?? '';
$durumNot = $durumNot ?? '';
$durumBtn = $durumBtn ?? null;
?>
<?php if ($kararRakam !== ''): ?>
      <div class="cardx card-pad gt-nabiz-sm">
        <div class="gt-pulse">
          <div class="gt-pulse-n"><?= Helpers::e($kararRakam) ?></div>
          <?php if ($kararAlt !== ''): ?><div class="gt-pulse-l"><?= Helpers::e($kararAlt) ?></div><?php endif; ?>
        </div>
      </div>
<?php endif; ?>
<?php if ($durumMetin !== ''): ?>
      <div class="cardx card-pad akilli-durum">
        <div class="ad-metin">
          <b><?= Helpers::e($durumMetin) ?></b>
          <?php if ($durumNot !== ''): ?><span class="ad-not"><?= Helpers::e($durumNot) ?></span><?php endif; ?>
        </div>
        <?php if ($durumBtn): ?>
        <a class="btn-action btn-primaryx" href="<?= Helpers::e($durumBtn[1]) ?>"><?= Helpers::e($durumBtn[0]) ?></a>
        <?php endif; ?>
      </div>
<?php endif; ?>
<?php
// Bir sonraki ekranın yanlışlıkla aynı rakamı basmaması için değişkenler temizlenir.
$kararRakam = $kararAlt = $durumMetin = $durumNot = '';
$durumBtn = null;
