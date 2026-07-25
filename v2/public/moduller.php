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
        ['tedarikci-eslestirme.php', 'bi-diagram-3', 'Maliyet eşleştirme', 'Tedarikçi/personel → müşteri dağıtım'],
        ['rapor.php', 'bi-graph-up-arrow', 'Üretim raporu', 'Günlük/aylık üretim + eksik gün takibi'],
        ['tatiller.php', 'bi-calendar-event', 'Resmi tatiller', 'Tatil takvimi + 3 gün önce uyarı'],
    ],
];

$pageTitle = 'Diğer modüller';
$eyebrow = 'Az kullanılan girişler — hepsi burada';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <?php foreach ($gruplar as $baslik => $items): ?>
      <div class="section-head"><div class="gt-h" style="margin:0"><i class="bi bi-grid-3x3-gap"></i> <?= Helpers::e($baslik) ?></div></div>
      <div class="mod-grid">
        <?php foreach ($items as [$href, $ico, $ad, $aciklama]): ?>
        <a class="mod-card i-blue" href="<?= Helpers::e($href) ?>">
          <div class="mico"><i class="bi <?= Helpers::e($ico) ?>"></i></div>
          <div class="mt"><?= Helpers::e($ad) ?></div>
          <div class="md"><?= Helpers::e($aciklama) ?></div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
