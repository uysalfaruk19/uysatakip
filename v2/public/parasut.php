<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Parasut;
use Uysa\Repo;

/**
 * Paraşüt cari (muhasebe) — SALT-OKUMA durum ekranı (opus-012).
 * 🔒 Bu sayfa Paraşüt'ten VERİ ÇEKMEZ (buton yok). Senkron YEREL çalışır (Ömer'in PC'sinde
 *    `php tools/parasut_sync.php`); creds VPS'e konmaz. Burası sadece son senkron durumunu +
 *    eşleşmeyen raporunu GÖSTERİR.
 */

$u = Auth::requireLogin();
$pdo = Db::pdo();
$repo = new Repo($pdo);

$last = $repo->lastParasutSync();
$lastOzet = $last ? (json_decode((string) $last['detail'], true) ?: []) : [];
$eslesmeyen = is_array($lastOzet['eslesmeyen'] ?? null) ? $lastOzet['eslesmeyen'] : [];
$bagli = $repo->customersWithParasut();
$vpsHasCreds = Parasut::configured(); // VPS'te normalde FALSE (cred yerelde) — bilgilendirme

$eyebrow = 'Muhasebe · SALT-OKUMA';
$pageTitle = 'Paraşüt cari';
$active = 'parasut';
require __DIR__ . '/partials/header.php';
?>
      <div class="cardx card-pad">
        <h2><i class="bi bi-shield-lock"></i> Nasıl çalışır</h2>
        <p class="text-muted" style="font-size:13px;line-height:1.5">
          Paraşüt bilgileriniz <strong>sunucuya konmaz</strong>. Senkron sizin bilgisayarınızda çalışır:
          <code>php tools/parasut_sync.php</code> Paraşüt'ten cari bakiyeleri <strong>okur</strong> ve buraya
          yalnızca sonuç rakamlarını gönderir. Bu ekran veri <em>çekmez</em>, sadece son durumu gösterir.
          Paraşüt'e hiçbir yazma/fatura işlemi yapılmaz.
          <?php if ($vpsHasCreds): ?><br><span class="badge-soft badge-neg">Uyarı: bu sunucuda Paraşüt cred tanımlı — mimari gereği yerelde olmalı.</span><?php endif; ?>
        </p>
      </div>

      <div class="summary-grid">
        <div class="summary-card"><p class="label">Son senkron</p>
          <p class="metric small"><?= $last ? Helpers::e(date('d.m.Y H:i', strtotime((string) $last['created_at']))) : '—' ?></p>
          <span class="delta"><?= $last ? Helpers::e((string) ($last['actor'] ?? '')) : 'henüz senkron yok' ?></span></div>
        <div class="summary-card tint-green"><p class="label">Bağlı müşteri</p>
          <p class="metric small"><?= count($bagli) ?></p></div>
        <div class="summary-card <?= $eslesmeyen ? 'tint-orange' : '' ?>"><p class="label">Eşleşmeyen (son senkron)</p>
          <p class="metric small"><?= count($eslesmeyen) ?></p></div>
      </div>

      <?php if ($eslesmeyen): ?>
      <div class="section-head"><h2>Eşleşmeyenler</h2><span class="text-muted" style="font-size:12px">elle bağla/ekle · oto oluşturma yok</span></div>
      <div class="cardx card-pad">
        <table class="tablex">
          <thead><tr><th>Paraşüt firması</th><th class="num">Bakiye</th><th>Sebep</th></tr></thead>
          <tbody>
          <?php foreach ($eslesmeyen as $e): ?>
            <tr>
              <td><?= Helpers::e((string) ($e['girdi'] ?? '?')) ?></td>
              <td class="num"><?= isset($e['bakiye']) ? '₺ ' . Helpers::money((float) $e['bakiye']) : '—' ?></td>
              <td><span class="badge-soft"><?= Helpers::e((string) ($e['sebep'] ?? '')) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p class="text-muted" style="font-size:11px;margin-top:8px">Bu firmalar Paraşüt'te var ama Kokpit'te eşleşmedi. Müşteriyi Kokpit'e ekleyin/adı hizalayın; sonraki senkronda bağlanır.</p>
      </div>
      <?php endif; ?>

      <div class="section-head"><h2>Paraşüt bakiyesi bağlı müşteriler</h2><span class="text-muted" style="font-size:12px"><?= count($bagli) ?> firma</span></div>
      <div class="cardx card-pad">
        <?php if (!$bagli): ?>
          <div class="empty-state">Henüz senkron yok. Ömer'in PC'sinde <code>php tools/parasut_sync.php</code> çalıştırılınca burada listelenir.</div>
        <?php else: ?>
        <table class="tablex">
          <thead><tr><th>Müşteri</th><th class="num">Paraşüt bakiye</th><th>Son senkron</th></tr></thead>
          <tbody>
          <?php foreach ($bagli as $b): $bk = (float) $b['parasut_bakiye']; ?>
            <tr>
              <td><a href="cari.php?musteri=<?= (int) $b['id'] ?>"><?= Helpers::e($b['name']) ?></a></td>
              <td class="num <?= $bk < 0 ? 'neg' : '' ?>">₺ <?= Helpers::money($bk) ?></td>
              <td><?= $b['parasut_sync_at'] ? Helpers::e(date('d.m.Y H:i', strtotime((string) $b['parasut_sync_at']))) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
<?php require __DIR__ . '/partials/footer.php'; ?>
