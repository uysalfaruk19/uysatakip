<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

// fable-003 (cateringkolay Teklifler esinli): müşteri adayı teklif takibi.
// Pratik: firma + kişi + fiyat + not; durum akışı taslak → gönderildi → kabul/red.
$u = Auth::requireLogin();
$pdo = Db::pdo();
$repo = new Repo($pdo);

$flash = '';
$flashOk = true;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'yeni') {
            $firma = trim((string) ($_POST['firma'] ?? ''));
            $kisi = (int) ($_POST['kisi'] ?? 0) ?: null;
            $fiyat = trim((string) ($_POST['birim_fiyat'] ?? ''));
            $fiyatF = $fiyat !== '' ? Helpers::parseMoney($fiyat) : null;
            $note = trim((string) ($_POST['note'] ?? '')) ?: null;
            if ($firma === '') {
                $flash = 'Firma adı zorunlu.';
                $flashOk = false;
            } else {
                $id = $repo->createTeklif(mb_substr($firma, 0, 120), $kisi, $fiyatF, $note ? mb_substr($note, 0, 500) : null);
                uysa_audit('teklif_yeni', $u['username'], (string) $id, $firma, client_ip());
                $flash = "Teklif kaydedildi: $firma";
            }
        } elseif ($action === 'durum') {
            $id = (int) ($_POST['id'] ?? 0);
            $durum = (string) ($_POST['durum'] ?? '');
            if (in_array($durum, Repo::TEKLIF_DURUM, true) && $repo->setTeklifDurum($id, $durum)) {
                uysa_audit('teklif_durum', $u['username'], (string) $id, $durum, client_ip());
                $flash = 'Teklif durumu güncellendi.';
            } else {
                $flash = 'Teklif bulunamadı.';
                $flashOk = false;
            }
        }
    }
}

$list = $repo->listTeklif();
$open = count(array_filter($list, static fn ($t) => in_array($t['durum'], ['taslak', 'gonderildi'], true)));
$csrf = Helpers::csrfToken();

$durumMap = [
    'taslak'     => ['badge-blue', 'bi-pencil', 'Taslak'],
    'gonderildi' => ['badge-warn', 'bi-send', 'Gönderildi'],
    'kabul'      => ['badge-ok', 'bi-check2-circle', 'Kabul'],
    'red'        => ['badge-neg', 'bi-x-circle', 'Red'],
];

$pageTitle = 'Teklifler';
$eyebrow = 'Müşteri adayı takibi';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <div class="stat-stack">
        <div class="stat-card stat-blue">
          <div class="ico"><i class="bi bi-briefcase"></i></div>
          <div class="txt"><p class="lbl">Açık teklif (taslak + gönderildi)</p><p class="val"><?= $open ?></p></div>
        </div>
      </div>

      <div class="fab-sheet">
        <h2>Yeni teklif</h2>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
          <input type="hidden" name="action" value="yeni">
          <div class="field"><label>Firma</label>
            <input class="inputx" name="firma" maxlength="120" required placeholder="ör. ABC Tekstil"></div>
          <div class="actions-row">
            <div class="field flex-fill"><label>Kişi / gün</label>
              <input class="inputx" name="kisi" type="number" inputmode="numeric" min="0" placeholder="ör. 80"></div>
            <div class="field flex-fill"><label>Birim fiyat ₺ (ops.)</label>
              <input class="inputx" name="birim_fiyat" inputmode="decimal" placeholder="ör. 320"></div>
          </div>
          <div class="field"><label>Not (yetkili, telefon, özel şart...)</label>
            <input class="inputx" name="note" maxlength="500" placeholder="ör. Ali Bey 0532... · servis dahil"></div>
          <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-plus-circle"></i> Kaydet</button>
        </form>
      </div>

      <div class="section-head mt-3"><h2>Teklifler</h2><span class="text-muted" style="font-size:12px"><?= count($list) ?> kayıt</span></div>
      <?php if (!$list): ?>
        <div class="empty-state">Henüz teklif yok — ilk teklifi yukarıdan ekleyin.</div>
      <?php else: ?>
        <div class="list-groupx">
          <?php foreach ($list as $t): [$bc, $bi, $bt] = $durumMap[$t['durum']]; ?>
            <div class="cardx card-pad">
              <div class="d-flex align-items-center justify-between gap-2">
                <div style="min-width:0">
                  <div class="row-title"><strong><?= Helpers::e($t['firma']) ?></strong></div>
                  <p class="row-meta">
                    <?= $t['kisi'] ? (int) $t['kisi'] . ' kişi/gün' : '' ?><?= $t['kisi'] && $t['birim_fiyat'] ? ' · ' : '' ?><?= $t['birim_fiyat'] ? '₺ ' . Helpers::money((float) $t['birim_fiyat']) : '' ?>
                    <?= ($t['kisi'] || $t['birim_fiyat']) ? ' · ' : '' ?><?= Helpers::e(date('d.m.Y', strtotime((string) $t['created_at']))) ?>
                  </p>
                  <?php if ($t['note']): ?><p class="row-meta"><i class="bi bi-chat-left-text"></i> <?= Helpers::e($t['note']) ?></p><?php endif; ?>
                </div>
                <span class="badge-soft <?= $bc ?>"><i class="bi <?= $bi ?>"></i> <?= $bt ?></span>
              </div>
              <?php
              // Sıradaki mantıklı adımlar: taslak→gönderildi; gönderildi→kabul/red
              $steps = $t['durum'] === 'taslak' ? ['gonderildi' => 'Gönderildi işaretle']
                  : ($t['durum'] === 'gonderildi' ? ['kabul' => 'Kabul ✓', 'red' => 'Red ✗'] : []);
              ?>
              <?php if ($steps): ?>
                <div class="actions-row mt-2">
                  <?php foreach ($steps as $dk => $dl): ?>
                    <form method="post" class="flex-fill">
                      <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
                      <input type="hidden" name="action" value="durum">
                      <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                      <input type="hidden" name="durum" value="<?= $dk ?>">
                      <button class="btn-action <?= $dk === 'red' ? 'btn-ghost' : 'btn-primaryx' ?> btn-full" type="submit"><?= $dl ?></button>
                    </form>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="hint-card mt-2">Kabul edilen teklifi <a href="musteriler.php?yeni=1" style="text-decoration:underline;font-weight:700">Müşteri ekle</a> ile sisteme alın.</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
