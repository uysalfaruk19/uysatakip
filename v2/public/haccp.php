<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

// fable-003 (cateringkolay HACCP panosu esinli): günlük gıda güvenliği kontrolleri.
// Mobil-pratik: sabit noktalara tek dokunuş değer/uygunluk girişi, şahit numune 72 saat takibi.
$u = Auth::requireLogin();
$pdo = Db::pdo();
$repo = new Repo($pdo);

// Sıcaklık noktaları (hedef aralık etikette — bilgi amaçlı, giriş serbest)
$tempPoints = [
    'Soğuk Oda 1'      => '0–4 °C',
    'Soğuk Oda 2'      => '0–4 °C',
    'Derin Dondurucu'  => '−18 °C ve altı',
    'Kuzine (Pişirme)' => '≥ 75 °C merkez',
    'Servis Bankosu'   => '≥ 63 °C sıcak servis',
];
$hygienePoints = [
    'Bulaşıkhane / Bulaşık makinesi',
    'Personel hijyeni (bone, eldiven, önlük)',
    'Zemin ve tezgâh temizliği',
    'Çöp alanı',
    'WC / lavabo',
];

$date = (string) ($_GET['date'] ?? Helpers::today());
if (!Helpers::isDate($date)) {
    $date = Helpers::today();
}
$flash = '';
$flashOk = true;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $pDate = (string) ($_POST['date'] ?? $date);
        $date = Helpers::isDate($pDate) ? $pDate : Helpers::today();
        if ($action === 'sicaklik') {
            $nokta = (string) ($_POST['nokta'] ?? '');
            $deger = trim((string) ($_POST['deger'] ?? ''));
            if (isset($tempPoints[$nokta]) && $deger !== '') {
                $repo->addHaccpLog($date, 'sicaklik', $nokta, mb_substr($deger, 0, 40), null, null, $u['username']);
                uysa_audit('haccp_sicaklik', $u['username'], $nokta, $deger, client_ip());
                $flash = "$nokta ölçümü kaydedildi: $deger °C";
            } else {
                $flash = 'Nokta seçin ve değer girin.';
                $flashOk = false;
            }
        } elseif ($action === 'hijyen') {
            $nokta = (string) ($_POST['nokta'] ?? '');
            $uygun = (string) ($_POST['uygun'] ?? '') === '1';
            $note = trim((string) ($_POST['note'] ?? '')) ?: null;
            if (in_array($nokta, $hygienePoints, true)) {
                $repo->addHaccpLog($date, 'hijyen', $nokta, null, $uygun, $note ? mb_substr($note, 0, 300) : null, $u['username']);
                uysa_audit('haccp_hijyen', $u['username'], $nokta, $uygun ? 'uygun' : 'UYGUNSUZ', client_ip());
                $flash = "$nokta: " . ($uygun ? 'uygun ✓' : 'UYGUNSUZ işaretlendi');
                $flashOk = $uygun;
            }
        } elseif ($action === 'numune') {
            $yemek = trim((string) ($_POST['yemek'] ?? ''));
            if ($yemek !== '') {
                $id = $repo->addHaccpLog($date, 'numune', mb_substr($yemek, 0, 80), null, null, null, $u['username']);
                uysa_audit('haccp_numune', $u['username'], (string) $id, $yemek, client_ip());
                $flash = "Şahit numune alındı: $yemek (#N-$id)";
            } else {
                $flash = 'Yemek adı girin.';
                $flashOk = false;
            }
        } elseif ($action === 'imha') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($repo->haccpDisposeSample($id)) {
                uysa_audit('haccp_imha', $u['username'], (string) $id, null, client_ip());
                $flash = "Numune #N-$id imha edildi.";
            } else {
                $flash = 'Numune bulunamadı.';
                $flashOk = false;
            }
        } elseif ($action === 'malkabul') {
            $urun = trim((string) ($_POST['urun'] ?? ''));
            $deger = trim((string) ($_POST['deger'] ?? ''));
            $uygun = (string) ($_POST['uygun'] ?? '1') === '1';
            $note = trim((string) ($_POST['note'] ?? '')) ?: null;
            if ($urun !== '') {
                $repo->addHaccpLog($date, 'malkabul', mb_substr($urun, 0, 80), $deger !== '' ? mb_substr($deger, 0, 40) : null, $uygun, $note ? mb_substr($note, 0, 300) : null, $u['username']);
                uysa_audit('haccp_malkabul', $u['username'], $urun, $uygun ? 'uygun' : 'RED', client_ip());
                $flash = "Mal kabul kaydedildi: $urun";
            } else {
                $flash = 'Ürün/tedarikçi girin.';
                $flashOk = false;
            }
        }
    }
}

$logs = $repo->haccpLogsForDate($date);
$byKind = ['sicaklik' => [], 'hijyen' => [], 'numune' => [], 'malkabul' => []];
foreach ($logs as $l) {
    $byKind[$l['kind']][] = $l;
}
$samples = $repo->haccpActiveSamples();
$doneTemp = array_unique(array_column($byKind['sicaklik'], 'nokta'));
$doneHyg = array_unique(array_column($byKind['hijyen'], 'nokta'));
$doneCount = count($doneTemp) + count($doneHyg);
$targetCount = count($tempPoints) + count($hygienePoints);
$csrf = Helpers::csrfToken();
$prevDay = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDay = date('Y-m-d', strtotime($date . ' +1 day'));

$pageTitle = 'HACCP Kontrol';
$eyebrow = 'Günlük gıda güvenliği panosu';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <div class="date-row">
        <a class="icon-btn" href="haccp.php?date=<?= $prevDay ?>" aria-label="Önceki gün"><i class="bi bi-chevron-left"></i></a>
        <form method="get" class="date-pill">
          <i class="bi bi-calendar2-week"></i>
          <input type="date" name="date" value="<?= Helpers::e($date) ?>" onchange="this.form.submit()">
        </form>
        <a class="icon-btn" href="haccp.php?date=<?= $nextDay ?>" aria-label="Sonraki gün"><i class="bi bi-chevron-right"></i></a>
      </div>

      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <div class="stat-stack">
        <div class="stat-card <?= $doneCount >= $targetCount ? 'stat-green' : 'stat-orange' ?>">
          <div class="ico"><i class="bi bi-clipboard2-check"></i></div>
          <div class="txt">
            <p class="lbl"><?= Helpers::e(gun_label_tr($date)) ?> · kontrol durumu</p>
            <p class="val"><?= $doneCount ?>/<?= $targetCount ?> <span style="font-size:14px;font-weight:600">yapıldı</span></p>
          </div>
        </div>
      </div>

      <!-- Sıcaklık zinciri -->
      <div class="cardx card-pad mb-2">
        <h2><i class="bi bi-thermometer-half"></i> Sıcaklık ölçümleri</h2>
        <p class="row-meta mb-2">Günde en az 1 ölçüm — yapılmışlar ✓ ile işaretli.</p>
        <?php foreach ($tempPoints as $nokta => $hedef): $done = in_array($nokta, $doneTemp, true); ?>
          <form method="post" class="supply-row">
            <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
            <input type="hidden" name="action" value="sicaklik">
            <input type="hidden" name="date" value="<?= Helpers::e($date) ?>">
            <input type="hidden" name="nokta" value="<?= Helpers::e($nokta) ?>">
            <div>
              <div class="s-name"><?= $done ? '<i class="bi bi-check-circle-fill" style="color:var(--green)"></i> ' : '' ?><?= Helpers::e($nokta) ?></div>
              <div class="s-meta">hedef: <?= Helpers::e($hedef) ?></div>
            </div>
            <div class="actions-row" style="justify-content:flex-end">
              <input class="inputx qty-input" name="deger" inputmode="decimal" placeholder="°C" style="max-width:74px">
              <button class="icon-btn" type="submit" aria-label="Kaydet"><i class="bi bi-check2"></i></button>
            </div>
          </form>
        <?php endforeach; ?>
        <?php if ($byKind['sicaklik']): ?>
          <div class="mt-2">
            <?php foreach ($byKind['sicaklik'] as $l): ?>
              <p class="row-meta"><i class="bi bi-clock"></i> <?= Helpers::e(substr((string) $l['created_at'], 11, 5)) ?> · <?= Helpers::e($l['nokta']) ?>: <strong><?= Helpers::e((string) $l['deger']) ?> °C</strong></p>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Hijyen çeklist -->
      <div class="cardx card-pad mb-2">
        <h2><i class="bi bi-droplet"></i> Temizlik & hijyen</h2>
        <?php foreach ($hygienePoints as $nokta):
            $rec = null;
            foreach ($byKind['hijyen'] as $l) {
                if ($l['nokta'] === $nokta) {
                    $rec = $l; // günün son kaydı geçerli
                }
            } ?>
          <div class="supply-row">
            <div>
              <div class="s-name"><?= Helpers::e($nokta) ?></div>
              <?php if ($rec !== null): ?>
                <div class="s-meta"><?= (int) $rec['uygun'] === 1 ? '<span style="color:var(--green);font-weight:700">uygun ✓</span>' : '<span style="color:var(--red);font-weight:700">UYGUNSUZ ✗</span>' ?><?= $rec['note'] ? ' · ' . Helpers::e($rec['note']) : '' ?></div>
              <?php endif; ?>
            </div>
            <div class="actions-row" style="justify-content:flex-end">
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
                <input type="hidden" name="action" value="hijyen">
                <input type="hidden" name="date" value="<?= Helpers::e($date) ?>">
                <input type="hidden" name="nokta" value="<?= Helpers::e($nokta) ?>">
                <input type="hidden" name="uygun" value="1">
                <button class="icon-btn" type="submit" aria-label="Uygun" style="color:var(--green)"><i class="bi bi-hand-thumbs-up"></i></button>
              </form>
              <form method="post" style="display:inline" onsubmit="var n=prompt('Uygunsuzluk notu (ne görüldü?):'); if(n===null) return false; this.note.value=n; return true;">
                <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
                <input type="hidden" name="action" value="hijyen">
                <input type="hidden" name="date" value="<?= Helpers::e($date) ?>">
                <input type="hidden" name="nokta" value="<?= Helpers::e($nokta) ?>">
                <input type="hidden" name="uygun" value="0">
                <input type="hidden" name="note" value="">
                <button class="icon-btn" type="submit" aria-label="Uygunsuz" style="color:var(--red)"><i class="bi bi-hand-thumbs-down"></i></button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Mal kabul -->
      <div class="cardx card-pad mb-2">
        <h2><i class="bi bi-truck"></i> Mal kabul</h2>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
          <input type="hidden" name="action" value="malkabul">
          <input type="hidden" name="date" value="<?= Helpers::e($date) ?>">
          <div class="field"><label>Ürün / tedarikçi</label>
            <input class="inputx" name="urun" maxlength="80" placeholder="ör. Tavuk but — X Gıda"></div>
          <div class="actions-row">
            <div class="field flex-fill"><label>Ürün sıcaklığı (opsiyonel)</label>
              <input class="inputx" name="deger" inputmode="decimal" placeholder="°C"></div>
            <div class="field" style="width:130px"><label>Durum</label>
              <select class="selectx" name="uygun"><option value="1">Kabul</option><option value="0">Red</option></select></div>
          </div>
          <div class="field"><label>Not (opsiyonel)</label>
            <input class="inputx" name="note" maxlength="300" placeholder="ör. araç sıcaklığı, ambalaj durumu"></div>
          <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
        </form>
        <?php if ($byKind['malkabul']): ?>
          <div class="mt-2">
            <?php foreach ($byKind['malkabul'] as $l): ?>
              <div class="supply-row">
                <div><div class="s-name"><?= Helpers::e($l['nokta']) ?></div>
                  <div class="s-meta"><?= Helpers::e(substr((string) $l['created_at'], 11, 5)) ?><?= $l['deger'] ? ' · ' . Helpers::e((string) $l['deger']) . ' °C' : '' ?><?= $l['note'] ? ' · ' . Helpers::e($l['note']) : '' ?></div></div>
                <span class="badge-soft <?= (int) $l['uygun'] === 1 ? 'badge-ok' : 'badge-neg' ?>"><?= (int) $l['uygun'] === 1 ? 'Kabul' : 'Red' ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Şahit numune -->
      <div class="cardx card-pad mb-2">
        <h2><i class="bi bi-eyedropper"></i> Şahit numune</h2>
        <form method="post" class="actions-row">
          <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
          <input type="hidden" name="action" value="numune">
          <input type="hidden" name="date" value="<?= Helpers::e($date) ?>">
          <input class="inputx flex-fill" name="yemek" maxlength="80" placeholder="Yemek adı — ör. Mercimek Çorbası">
          <button class="btn-action btn-primaryx" type="submit"><i class="bi bi-plus"></i> Al</button>
        </form>
        <?php if (!$samples): ?>
          <div class="empty-state mt-2">Saklanan numune yok.</div>
        <?php else: ?>
          <div class="mt-2">
            <?php foreach ($samples as $s):
                $ageH = (int) floor((time() - strtotime((string) $s['created_at'])) / 3600);
                $disposable = $ageH >= 72; ?>
              <div class="supply-row">
                <div>
                  <div class="s-name"><?= Helpers::e($s['nokta']) ?> <span class="s-meta">#N-<?= (int) $s['id'] ?></span></div>
                  <div class="s-meta">Alındı: <?= Helpers::e(date('d.m.Y H:i', strtotime((string) $s['created_at']))) ?> · <?= $disposable ? '<span style="color:var(--green);font-weight:700">72 saat doldu — imha edilebilir</span>' : ($ageH < 1 ? 'yeni' : $ageH . ' saat oldu') ?></div>
                </div>
                <?php if ($disposable): ?>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
                    <input type="hidden" name="action" value="imha">
                    <input type="hidden" name="date" value="<?= Helpers::e($date) ?>">
                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                    <button class="btn-action btn-secondaryx" type="submit"><i class="bi bi-trash3"></i> İmha</button>
                  </form>
                <?php else: ?>
                  <span class="badge-soft badge-blue"><i class="bi bi-hourglass-split"></i> Saklanıyor</span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <p class="row-meta mt-2"><i class="bi bi-info-circle"></i> Kural: her öğünden numune al, en az 72 saat sakla, sonra imha et.</p>
      </div>
<?php require __DIR__ . '/partials/footer.php'; ?>
