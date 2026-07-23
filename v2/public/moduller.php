<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Helpers;

/**
 * fable-030 — Diğer modüller: (+) menüsü sadeleşince (Ömer: "çok kalabalık") az kullanılan
 * girişler buraya taşındı. Hiçbir sayfa silinmedi — erişim tek seviye derine indi.
 */

$u = Auth::requireLogin();

$gruplar = [
    'Operasyon' => [
        ['mutfak.php', 'bi-fire', 'Mutfak görünümü', 'Günün üretim listesi'],
        ['sevkiyat.php', 'bi-truck', 'Sevkiyat / teslimat', 'Teslimat takibi'],
        ['haccp.php', 'bi-clipboard2-check', 'HACCP kontrol', 'Gıda güvenliği kayıtları'],
    ],
    'Yönetim' => [
        ['musteriler.php?yeni=1', 'bi-person-plus', 'Müşteri ekle', 'Yeni müşteri kartı'],
        ['musteri-giris.php?yeni=1', 'bi-person-badge', 'Müşteri girişi oluştur', 'App kullanıcısı aç'],
        ['teklifler.php', 'bi-briefcase', 'Teklifler', 'Teklif hazırla / PDF'],
        ['tedarikciler.php', 'bi-shop-window', 'Tedarikçiler', 'Tedarikçi kartları'],
        ['bildirim.php', 'bi-bell', 'Bildirim gönder', 'Müşteri app push'],
    ],
    'Muhasebe & kayıt' => [
        ['parasut.php', 'bi-shield-check', 'Paraşüt cari', 'Muhasebe bakiyeleri'],
        ['islemler.php', 'bi-list-check', 'İşlem kaydı', 'Denetim izi (audit)'],
    ],
];

$pageTitle = 'Diğer modüller';
$eyebrow = 'Az kullanılan girişler — hepsi burada';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <?php foreach ($gruplar as $baslik => $items): ?>
      <div class="section-head"><h2><?= Helpers::e($baslik) ?></h2></div>
      <div class="mod-grid">
        <?php foreach ($items as [$href, $ico, $ad, $aciklama]): ?>
        <a class="mod-card" href="<?= Helpers::e($href) ?>">
          <div class="mico"><i class="bi <?= Helpers::e($ico) ?>"></i></div>
          <div class="mt"><?= Helpers::e($ad) ?></div>
          <div class="md"><?= Helpers::e($aciklama) ?></div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
