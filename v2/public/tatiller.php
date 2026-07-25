<?php

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

$u = Auth::requireLogin();
$repo = new Repo(Db::pdo());

$TURLER = ['resmi' => 'Resmî', 'dini' => 'Dinî bayram', 'arefe' => 'Arefe (yarım gün)'];
$flash = '';
$flashOk = true;

// ── Kaydet / pasifleştir ─────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'pasif') {
                $repo->setResmiTatilAktif((int) ($_POST['id'] ?? 0), false);
                uysa_audit('tatil_pasif', $u['username'], (string) ($_POST['id'] ?? ''), '', client_ip());
                $flash = 'Tatil pasifleştirildi (kayıt silinmez, iz kalır).';
            } elseif ($action === 'aktif') {
                $repo->setResmiTatilAktif((int) ($_POST['id'] ?? 0), true);
                $flash = 'Tatil yeniden aktifleştirildi.';
            } else {
                $id = $repo->upsertResmiTatil(
                    (string) ($_POST['tarih'] ?? ''),
                    (string) ($_POST['ad'] ?? ''),
                    in_array($_POST['tur'] ?? 'resmi', ['resmi', 'dini', 'arefe'], true) ? (string) $_POST['tur'] : 'resmi',
                    !empty($_POST['yarim_gun'])
                );
                uysa_audit('tatil_kaydet', $u['username'], (string) ($_POST['tarih'] ?? ''), (string) ($_POST['ad'] ?? ''), client_ip());
                $flash = 'Tatil kaydedildi.';
            }
        } catch (\Throwable $e) {
            $flash = 'Kaydedilemedi: ' . $e->getMessage();
            $flashOk = false;
        }
    }
}

$hepsi = $repo->resmiTatiller(false);
usort($hepsi, static fn(array $a, array $b) => strcmp((string) $b['tarih'], (string) $a['tarih']));
$bugun = Helpers::today();
$gunAdi = ['Mon' => 'Pazartesi', 'Tue' => 'Salı', 'Wed' => 'Çarşamba', 'Thu' => 'Perşembe', 'Fri' => 'Cuma', 'Sat' => 'Cumartesi', 'Sun' => 'Pazar'];

$gelecek = array_values(array_filter($hepsi, static fn(array $t) => (string) $t['tarih'] >= $bugun && (int) $t['aktif'] === 1));
usort($gelecek, static fn(array $a, array $b) => strcmp((string) $a['tarih'], (string) $b['tarih']));

$eyebrow = 'Takvim';
$pageTitle = 'Resmi tatiller';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-calendar-event"></i> YAKLAŞAN TATİL</div>
        <?php if (!$gelecek): ?>
          <div class="empty-state">Kayıtlı yaklaşan tatil yok.</div>
        <?php else: $y = $gelecek[0]; $kalan = (int) floor((strtotime((string) $y['tarih']) - strtotime($bugun)) / 86400); ?>
          <div class="gt-pulse">
            <div class="gt-pulse-n" style="font-size:26px"><?= Helpers::e((string) $y['ad']) ?></div>
            <div class="gt-pulse-l"><?= Helpers::e(date('d.m.Y', strtotime((string) $y['tarih']))) ?>
              · <?= Helpers::e($gunAdi[date('D', strtotime((string) $y['tarih']))] ?? '') ?>
              · <?= $kalan === 0 ? 'bugün' : $kalan . ' gün kaldı' ?></div>
          </div>
          <p class="row-meta" style="text-align:center;margin-top:8px">
            <i class="bi bi-bell"></i> Tatilden 3 gün önce otomatik hatırlatma gelir (çalışan/çalışmayan müşteriler + sipariş güncelleme).
          </p>
        <?php endif; ?>
      </div>

      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-plus-circle"></i> TATİL EKLE / GÜNCELLE</div>
        <form method="post" class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <div class="field"><label>Tarih</label>
            <input class="inputx" type="date" name="tarih" value="<?= Helpers::e($bugun) ?>" required>
          </div>
          <div class="field"><label>Adı</label>
            <input class="inputx" name="ad" placeholder="Kurban Bayramı 1. gün" required>
          </div>
          <div class="field"><label>Tür</label>
            <select class="inputx" name="tur">
              <?php foreach ($TURLER as $k => $v): ?>
                <option value="<?= $k ?>"><?= Helpers::e($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <label style="display:flex;align-items:center;gap:8px;font-weight:700;margin-top:22px">
            <input type="checkbox" name="yarim_gun" value="1"> Yarım gün
          </label>
          <button class="btn-action btn-primaryx" type="submit" style="align-self:end"><i class="bi bi-check2"></i> Kaydet</button>
        </form>
        <p class="row-meta" style="margin-top:8px">Aynı tarih tekrar girilirse üzerine yazılır (mükerrer kayıt oluşmaz). Dinî bayramlar hicri takvimle kaydığı için her yıl elle girilir.</p>
      </div>

      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-calendar3"></i> TATİL LİSTESİ
          <span class="gt-hr"><?= count($hepsi) ?> kayıt</span></div>
        <?php if (!$hepsi): ?>
          <div class="empty-state">Henüz tatil girilmemiş.</div>
        <?php else: foreach ($hepsi as $t):
          $gecmis = (string) $t['tarih'] < $bugun;
          $pasif = (int) $t['aktif'] !== 1; ?>
          <div class="gt-kr<?= $pasif ? ' warn' : '' ?>" style="opacity:<?= $gecmis && !$pasif ? '.65' : '1' ?>">
            <div class="gt-kr-head">
              <div class="gt-rank"><i class="bi <?= (string) $t['tur'] === 'dini' ? 'bi-moon-stars' : ((string) $t['tur'] === 'arefe' ? 'bi-hourglass-split' : 'bi-flag') ?>"></i></div>
              <div class="gt-kr-firm">
                <div class="gt-kr-ad"><?= Helpers::e((string) $t['ad']) ?><?= $pasif ? ' · pasif' : '' ?></div>
                <div class="gt-kr-sub"><?= Helpers::e(date('d.m.Y', strtotime((string) $t['tarih']))) ?>
                  · <?= Helpers::e($gunAdi[date('D', strtotime((string) $t['tarih']))] ?? '') ?>
                  · <?= Helpers::e($TURLER[(string) $t['tur']] ?? (string) $t['tur']) ?><?= (int) $t['yarim_gun'] === 1 ? ' · yarım gün' : '' ?></div>
              </div>
              <form method="post" style="margin:0">
                <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <input type="hidden" name="action" value="<?= $pasif ? 'aktif' : 'pasif' ?>">
                <button class="badge-soft" type="submit"><?= $pasif ? 'Aktifleştir' : 'Pasifleştir' ?></button>
              </form>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
<?php require __DIR__ . '/partials/footer.php'; ?>
