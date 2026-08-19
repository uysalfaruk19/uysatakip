<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

/**
 * fable-087 (Ömer, 19 Ağu): "bildirim uygulama ikonunda görünüyor ama girince okuyacak yer yok."
 * Yöneticiye giden bildirimlerin OKUMA ekranı. (bildirim.php = gönderme ekranı, ayrı.)
 * Sayfa açılınca bildirimler okundu sayılır → üst bardaki rozet sıfırlanır.
 */

$u = Auth::requireLogin();
$repo = new Repo(Db::pdo());
if (!Auth::isAdmin($u)) {
    header('Location: bugun.php');
    exit;
}

$liste = $repo->yoneticiBildirimleri(60);
$yeni = $repo->okunmamisBildirim((int) $u['uid']);   // okundu işaretlemeden ÖNCE oku
$repo->bildirimleriOkundu((int) $u['uid']);

/** Bildirim türüne göre ikon + renk. */
function f087_gorunum(string $kind): array
{
    return match ($kind) {
        'kritik'      => ['bi-exclamation-triangle-fill', 'var(--red)'],
        'reminder'    => ['bi-alarm-fill', 'var(--primary)'],
        'siparis'     => ['bi-bag-check-fill', 'var(--green)'],
        'menu'        => ['bi-card-checklist', 'var(--primary)'],
        'talep_yeni',
        'talep_cevap' => ['bi-chat-left-text-fill', 'var(--primary)'],
        default       => ['bi-bell-fill', 'var(--muted)'],
    };
}

/** '2026-08-19 13:00:03' → '19 Ağustos · 13:00' (bugünse 'Bugün · 13:00'). */
function f087_zaman(string $ts): string
{
    $t = strtotime($ts);
    if ($t === false) {
        return $ts;
    }
    $aylar = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
        'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $gun = date('Y-m-d', $t);
    if ($gun === date('Y-m-d')) {
        return 'Bugün · ' . date('H:i', $t);
    }
    if ($gun === date('Y-m-d', strtotime('-1 day'))) {
        return 'Dün · ' . date('H:i', $t);
    }
    return ((int) date('j', $t)) . ' ' . ($aylar[(int) date('n', $t)] ?? '') . ' · ' . date('H:i', $t);
}

$eyebrow = $yeni > 0 ? $yeni . ' yeni bildirim' : 'tümü okundu';
$pageTitle = 'Bildirimler';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-bell"></i> BİLDİRİMLER</div>
        <?php if (!$liste): ?>
          <div class="empty-state">Henüz bildirim yok.</div>
        <?php else: ?>
          <?php foreach ($liste as $i => $b):
            [$ikon, $renk] = f087_gorunum((string) $b['kind']);
            $yeniMi = $i < $yeni;   // liste en yeniden sıralı → ilk $yeni tanesi okunmamış
          ?>
          <div class="gt-satir-detay" style="display:block;padding:10px 0;border-bottom:1px solid var(--line-2)<?= $yeniMi ? ';background:#f4f8ff;margin:0 -14px;padding-left:14px;padding-right:14px' : '' ?>">
            <div style="display:flex;gap:10px;align-items:flex-start">
              <i class="bi <?= $ikon ?>" style="color:<?= $renk ?>;font-size:16px;margin-top:2px"></i>
              <div style="flex:1;min-width:0">
                <div style="font-weight:600;font-size:13.5px;line-height:1.35">
                  <?= Helpers::e((string) $b['title']) ?>
                  <?php if ($yeniMi): ?><span style="color:var(--red);font-size:11px;font-weight:700"> • YENİ</span><?php endif; ?>
                </div>
                <div style="font-size:12.5px;color:var(--text);margin-top:3px;line-height:1.45">
                  <?= Helpers::e((string) $b['body']) ?>
                </div>
                <div class="row-meta" style="margin-top:4px">
                  <?= Helpers::e(f087_zaman((string) $b['created_at'])) ?>
                  <?php if ((int) $b['suppressed'] === 1): ?>
                    · <span style="color:var(--red)">sessiz saatte bastırıldı, telefona düşmedi</span>
                  <?php elseif ((int) $b['sent'] === 0): ?>
                    · <span style="color:var(--red)">hiçbir cihaza gönderilemedi</span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <div class="gt-note">son <?= count($liste) ?> bildirim gösteriliyor</div>
        <?php endif; ?>
      </div>
<?php require __DIR__ . '/partials/footer.php'; ?>
