<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

/**
 * fable-035 — Maliyet eşleştirme (Ömer): tedarikçi faturaları ve personel yüklü maliyeti,
 * seçili müşterilere o ayki KİŞİ SAYISI oranında dağılsın. Eşleşmeyenler genel havuzda kalır.
 * Eşleşme "değiştirilene kadar" kalıcıdır. Admin ekranı. POST-redirect-GET.
 */

$u = Auth::requireLogin();
if (!Auth::isAdmin($u)) {
    header('Location: index.php');
    exit;
}
$pdo = Db::pdo();
$repo = new Repo($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ok = false;
    $msg = 'Oturum doğrulaması başarısız.';
    if (Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $action = (string) ($_POST['action'] ?? '');
        $ids = array_map('intval', (array) ($_POST['musteri'] ?? []));
        if ($action === 'ted_kaydet') {
            $firma = trim((string) ($_POST['firma'] ?? ''));
            if ($firma !== '') {
                $repo->tedarikciEslestirmeKaydet($firma, $ids);
                uysa_audit('tedarikci_eslestir', $u['username'], $firma, (string) count($ids) . ' müşteri', client_ip());
                $ok = true;
                $msg = $ids ? ($firma . ' → ' . count($ids) . ' müşteriye eşlendi.') : ($firma . ' eşleşmesi kaldırıldı.');
            }
        } elseif ($action === 'pers_kaydet') {
            $pid = (int) ($_POST['personel_id'] ?? 0);
            if ($pid > 0) {
                $repo->personelEslestirmeKaydet($pid, $ids);
                uysa_audit('personel_eslestir', $u['username'], (string) $pid, (string) count($ids) . ' müşteri', client_ip());
                $ok = true;
                $msg = $ids ? ('Personel → ' . count($ids) . ' müşteriye eşlendi.') : ('Personel eşleşmesi kaldırıldı.');
            }
        }
    }
    $_SESSION['esl_flash'] = ['ok' => $ok, 'msg' => $msg];
    header('Location: tedarikci-eslestirme.php');
    exit;
}

$flash = $_SESSION['esl_flash'] ?? null;
unset($_SESSION['esl_flash']);

$firmalar = $repo->distinctGiderFirmalar(6);
$tedMap = $repo->tedarikciEslestirmeler();
$personeller = $repo->listPersonel();
$persMap = $repo->personelEslestirmeler();
$customers = $repo->activeCustomers();
$csrf = Helpers::csrfToken();

$pageTitle = 'Maliyet eşleştirme';
$eyebrow = 'Tedarikçi & personel → müşteri dağıtımı';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flash['ok'] ? 'ok' : 'err' ?>"><?= Helpers::e($flash['msg']) ?></div><?php endif; ?>

      <div class="hint-card mb-2">
        Eşleşen tedarikçinin faturaları / personelin maliyeti, seçili müşterilere <strong>o ayki kişi
        sayısı</strong> oranında dağılır (ör. 30 kişi / 70 kişi → 100 TL'nin 30'u ve 70'i). Eşleşmeyenler
        genel havuzda (ciro oranı) kalır. Değiştirene kadar kalıcı.
      </div>

      <div class="section-head"><h2>Tedarikçi eşleştirme</h2><span class="text-muted" style="font-size:12px">son 6 ay · <?= count($firmalar) ?> firma</span></div>
      <?php if (!$firmalar): ?>
        <div class="empty-state">Son 6 ayda gider faturası yok. Paraşüt gideri senkronlanınca firmalar burada listelenir.</div>
      <?php else: ?>
        <div class="cardx card-pad">
          <?php foreach ($firmalar as $f):
            $sel = $tedMap[$f['key']] ?? []; ?>
            <form method="post" class="esl-row" style="border-bottom:1px solid var(--line,#e5e9ef);padding:12px 0">
              <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
              <input type="hidden" name="action" value="ted_kaydet">
              <input type="hidden" name="firma" value="<?= Helpers::e($f['label']) ?>">
              <div class="row-title" style="margin-bottom:8px">
                <strong><?= Helpers::e($f['label']) ?></strong>
                <span class="text-muted" style="font-size:12px">· <?= (int) $f['adet'] ?> fatura · ₺ <?= Helpers::money((float) $f['toplam']) ?></span>
              </div>
              <div class="chip-wrap" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px">
                <?php foreach ($customers as $c): ?>
                  <label class="chip" style="display:inline-flex;align-items:center;gap:6px;font-size:14px;border:1px solid var(--line,#dce1e8);border-radius:8px;padding:6px 10px">
                    <input type="checkbox" name="musteri[]" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $sel, true) ? 'checked' : '' ?>>
                    <?= Helpers::e($c['name']) ?>
                  </label>
                <?php endforeach; ?>
              </div>
              <button class="btn-action btn-primaryx" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="section-head mt-3"><h2>Personel eşleştirme</h2><span class="text-muted" style="font-size:12px"><?= count($personeller) ?> personel</span></div>
      <?php if (!$personeller): ?>
        <div class="empty-state">Aktif personel yok.</div>
      <?php else: ?>
        <div class="cardx card-pad">
          <?php foreach ($personeller as $p):
            $sel = $persMap[(int) $p['id']] ?? []; ?>
            <form method="post" class="esl-row" style="border-bottom:1px solid var(--line,#e5e9ef);padding:12px 0">
              <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
              <input type="hidden" name="action" value="pers_kaydet">
              <input type="hidden" name="personel_id" value="<?= (int) $p['id'] ?>">
              <div class="row-title" style="margin-bottom:8px">
                <strong><?= Helpers::e($p['ad']) ?></strong>
                <?php if ($p['gorev']): ?><span class="text-muted" style="font-size:12px">· <?= Helpers::e($p['gorev']) ?></span><?php endif; ?>
              </div>
              <div class="chip-wrap" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px">
                <?php foreach ($customers as $c): ?>
                  <label class="chip" style="display:inline-flex;align-items:center;gap:6px;font-size:14px;border:1px solid var(--line,#dce1e8);border-radius:8px;padding:6px 10px">
                    <input type="checkbox" name="musteri[]" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $sel, true) ? 'checked' : '' ?>>
                    <?= Helpers::e($c['name']) ?>
                  </label>
                <?php endforeach; ?>
              </div>
              <button class="btn-action btn-primaryx" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
