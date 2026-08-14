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
 * fable-037 — Tedarikçi satırında ayrıca FATURA bazlı mod: tek tek fatura, tedarikçi
 * eşleşmesinden farklı müşterilere dağıtılabilir (fatura eşleşmesi en üst öncelik).
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
                // fable-039: müşteri dağıtımı + gıda kırılımı ATOMİK tek POST (bağlı alanlar birlikte)
                $gidaKir = trim((string) ($_POST['gida_kirilim'] ?? ''));
                $pdo->beginTransaction();
                try {
                    $repo->tedarikciEslestirmeKaydet($firma, $ids);
                    $repo->tedarikciGidaKaydet($firma, $gidaKir !== '' ? $gidaKir : null);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                uysa_audit('tedarikci_eslestir', $u['username'], $firma, count($ids) . ' müşteri · gıda:' . ($gidaKir ?: '-'), client_ip());
                $ok = true;
                $msg = $ids ? ($firma . ' → ' . count($ids) . ' müşteriye eşlendi.') : ($firma . ' eşleşmesi kaldırıldı.');
            }
        } elseif ($action === 'fatura_kaydet') {
            $txId = (int) ($_POST['tx_id'] ?? 0);
            if ($txId > 0) {
                $repo->faturaEslestirmeKaydet($txId, $ids);
                uysa_audit('fatura_eslestir', $u['username'], (string) $txId, (string) count($ids) . ' müşteri', client_ip());
                $ok = true;
                $msg = $ids ? ('Fatura → ' . count($ids) . ' müşteriye özel dağıtıldı.') : ('Fatura özel dağıtımı kaldırıldı (tedarikçi kuralına döndü).');
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
$gidaKirilimlar = $repo->gidaKirilimlar();   // fable-039: gıda kırılım listesi (parametrik)
$gidaMap = $repo->tedarikciGidaMap();         // normAnahtar → kirilim_kod
$faturaListeleri = $repo->faturaListeleri(6);   // firmaKey → [faturalar]
$faturaMap = $repo->faturaEslestirmeler();       // tx_id → [customer_id,...]
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
        genel havuzda (ciro oranı) kalır. <strong>Fatura bazlı</strong> modda tek bir faturayı farklı
        müşterilere ayrıca dağıtabilirsin — o fatura tedarikçi kuralını ezer. Değiştirene kadar kalıcı.
      </div>

      <div class="section-head"><h2>Tedarikçi eşleştirme</h2><span class="text-muted" style="font-size:12px">son 6 ay · <?= count($firmalar) ?> firma</span></div>
      <?php if (!$firmalar): ?>
        <div class="empty-state">Son 6 ayda gider faturası yok. Paraşüt gideri senkronlanınca firmalar burada listelenir.</div>
      <?php else: ?>
        <div class="cardx card-pad">
          <?php foreach ($firmalar as $i => $f):
            $sel = $tedMap[$f['key']] ?? [];
            $faturalar = $faturaListeleri[$f['key']] ?? [];
            $ozelSayi = 0;
            foreach ($faturalar as $fx) {
                if (!empty($faturaMap[$fx['id']])) {
                    $ozelSayi++;
                }
            }
            $uid = 'ted' . $i; ?>
            <div class="esl-block" style="border-bottom:1px solid var(--line,#e5e9ef);padding:12px 0">
              <div class="row-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px">
                <strong><?= Helpers::e($f['label']) ?></strong>
                <span class="text-muted" style="font-size:12px">· <?= (int) $f['adet'] ?> fatura · ₺ <?= Helpers::money((float) $f['toplam']) ?></span>
                <?php if ($ozelSayi > 0): ?>
                  <span class="badge-ozel" style="font-size:11px;font-weight:600;color:#b45309;background:#fef3c7;border-radius:6px;padding:2px 8px"><?= $ozelSayi ?> faturada özel dağıtım</span>
                <?php endif; ?>
              </div>

              <div class="mode-switch" style="display:inline-flex;border:1px solid var(--line,#dce1e8);border-radius:8px;overflow:hidden;margin-bottom:10px;font-size:13px">
                <button type="button" class="mode-btn is-active" data-mode="ted" data-uid="<?= $uid ?>" onclick="eslMode('<?= $uid ?>','ted')" style="border:0;padding:6px 12px;background:var(--brand,#1e3a5f);color:#fff;cursor:pointer">Tedarikçi bazlı</button>
                <button type="button" class="mode-btn" data-mode="fatura" data-uid="<?= $uid ?>" onclick="eslMode('<?= $uid ?>','fatura')" style="border:0;padding:6px 12px;background:#fff;color:var(--brand,#1e3a5f);cursor:pointer">Fatura bazlı</button>
              </div>

              <!-- Tedarikçi bazlı (VARSAYILAN) -->
              <div class="mode-pane" id="<?= $uid ?>-ted">
                <form method="post" class="esl-row">
                  <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
                  <input type="hidden" name="action" value="ted_kaydet">
                  <input type="hidden" name="firma" value="<?= Helpers::e($f['label']) ?>">
                  <div class="chip-wrap" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px">
                    <?php foreach ($customers as $c): ?>
                      <label class="chip" style="display:inline-flex;align-items:center;gap:6px;font-size:14px;border:1px solid var(--line,#dce1e8);border-radius:8px;padding:6px 10px">
                        <input type="checkbox" name="musteri[]" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $sel, true) ? 'checked' : '' ?>>
                        <?= Helpers::e($c['name']) ?>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  <?php // fable-039: gıda cost kırılımı — "girmez" + kırılım listesi (parametrik) ?>
                  <?php $gsel = $gidaMap[$f['key']] ?? null; ?>
                  <div class="field" style="margin-bottom:8px;max-width:320px">
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Gıda kırılımı</label>
                    <select class="selectx" name="gida_kirilim" style="width:100%">
                      <?php // fable-079: "karar verilmedi" (boş) ile "gıda DEĞİL" (_haric) ayrı şeyler.
                            // Boş kalan tedarikçi Kâr/Zarar'da UYARI üretir — gıda tedarikçisi
                            // haritaya girmeyi unutulursa maliyet sessizce düşük çıkmasın diye.
                            // '_haric' seçilince o tedarikçi bir daha uyarı vermez. ?>
                      <option value="" <?= $gsel === null ? 'selected' : '' ?>>— karar verilmedi (uyarır) —</option>
                      <option value="<?= Repo::GIDA_HARIC ?>" <?= $gsel === Repo::GIDA_HARIC ? 'selected' : '' ?>>Gıda değil — hesaba girmesin (uyarma)</option>
                      <?php foreach ($gidaKirilimlar as $gk): ?>
                        <option value="<?= Helpers::e($gk['kod']) ?>" <?= $gsel === $gk['kod'] ? 'selected' : '' ?>><?= Helpers::e($gk['ad']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <button class="btn-action btn-primaryx" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
                </form>
              </div>

              <!-- Fatura bazlı -->
              <div class="mode-pane" id="<?= $uid ?>-fatura" hidden>
                <?php if (!$faturalar): ?>
                  <div class="empty-state" style="padding:10px 0">Bu tedarikçinin son 6 ayda faturası yok.</div>
                <?php else: ?>
                  <div class="text-muted" style="font-size:12px;margin-bottom:8px">Her fatura ayrı dağıtılır — müşteri seçmezsen o fatura tedarikçi kuralına döner.</div>
                  <?php foreach ($faturalar as $fx):
                    $fsel = $faturaMap[$fx['id']] ?? []; ?>
                    <form method="post" class="fatura-row" style="border:1px solid var(--line,#eef1f5);border-radius:10px;padding:10px;margin-bottom:8px;<?= $fsel ? 'background:#fffbeb' : '' ?>">
                      <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
                      <input type="hidden" name="action" value="fatura_kaydet">
                      <input type="hidden" name="tx_id" value="<?= (int) $fx['id'] ?>">
                      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;font-size:13px">
                        <strong><?= Helpers::e(date('d.m.Y', strtotime($fx['tx_date']))) ?></strong>
                        <span style="font-weight:600">₺ <?= Helpers::money((float) $fx['amount']) ?></span>
                        <?php if ($fx['no'] !== ''): ?><span class="text-muted" style="font-size:12px">· <?= Helpers::e($fx['no']) ?></span><?php endif; ?>
                        <?php if ($fsel): ?><span class="badge-ozel" style="font-size:11px;font-weight:600;color:#b45309;background:#fef3c7;border-radius:6px;padding:2px 8px">özel dağıtım</span><?php endif; ?>
                      </div>
                      <div class="chip-wrap" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px">
                        <?php foreach ($customers as $c): ?>
                          <label class="chip" style="display:inline-flex;align-items:center;gap:6px;font-size:14px;border:1px solid var(--line,#dce1e8);border-radius:8px;padding:6px 10px">
                            <input type="checkbox" name="musteri[]" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $fsel, true) ? 'checked' : '' ?>>
                            <?= Helpers::e($c['name']) ?>
                          </label>
                        <?php endforeach; ?>
                      </div>
                      <button class="btn-action btn-primaryx" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
                    </form>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
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

      <script>
      function eslMode(uid, mode) {
        document.getElementById(uid + '-ted').hidden = (mode !== 'ted');
        document.getElementById(uid + '-fatura').hidden = (mode !== 'fatura');
        document.querySelectorAll('.mode-btn[data-uid="' + uid + '"]').forEach(function (b) {
          var on = b.dataset.mode === mode;
          b.classList.toggle('is-active', on);
          b.style.background = on ? 'var(--brand,#1e3a5f)' : '#fff';
          b.style.color = on ? '#fff' : 'var(--brand,#1e3a5f)';
        });
      }
      </script>
<?php require __DIR__ . '/partials/footer.php'; ?>
