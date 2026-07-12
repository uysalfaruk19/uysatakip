<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Env;
use Uysa\Helpers;
use Uysa\Push;
use Uysa\Repo;

$u = Auth::requireLogin();
$pdo = Db::pdo();
$repo = new Repo($pdo);

$types = [
    'talep'   => 'Talep',
    'sikayet' => 'Şikayet',
    'oneri'   => 'Öneri',
    'menu'    => 'Menü',
    'mesaj'   => 'Mesaj',
];
$flash = '';
$flashOk = true;
$openId = isset($_GET['r']) ? (int) $_GET['r'] : 0;

/** Admin cevap foto eki: finans.php whitelist deseni. */
function handleAdminTalepUpload(array $file, Repo $repo, string $by, string &$err): ?int
{
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $err = 'Fotoğraf yüklenemedi.';
        return null;
    }
    $maxMb = Env::int('UPLOAD_MAX_MB', 25);
    if ($file['size'] > $maxMb * 1024 * 1024) {
        $err = "Dosya $maxMb MB limitini aşıyor.";
        return null;
    }
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    $orig = basename((string) $file['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        $err = "İzin verilmeyen uzantı: .$ext";
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedMime, true)) {
        $err = "İzin verilmeyen dosya türü: $mime";
        return null;
    }
    $dir = Env::get('UPLOAD_DIR') ?: __DIR__ . '/uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $safe = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $safe)) {
        $err = 'Dosya kaydedilemedi.';
        return null;
    }
    return $repo->addFile($safe, $orig, $mime, (int) $file['size'], $by, 'talep');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $reqId = (int) ($_POST['request_id'] ?? 0);
        $req = $reqId > 0 ? $repo->requestById($reqId) : null;
        if (!$req) {
            $flash = 'Talep bulunamadı.';
            $flashOk = false;
        } elseif ($action === 'reply') {
            $err = '';
            $fileId = handleAdminTalepUpload($_FILES['photo'] ?? [], $repo, $u['username'], $err);
            $body = trim((string) ($_POST['body'] ?? ''));
            if ($err !== '') {
                $flash = $err;
                $flashOk = false;
            } elseif ($body === '' && $fileId === null) {
                $flash = 'Cevap veya fotoğraf ekleyin.';
                $flashOk = false;
            } else {
                $repo->replyRequest($reqId, mb_substr($body, 0, 2000), $fileId);
                uysa_audit('admin_talep_cevap', $u['username'], (string) $reqId, null, client_ip());
                $flash = 'Cevabınız gönderildi.';
                // opus-021: müşteriye push — hata cevabı KIRMAZ
                try {
                    (new Push($pdo))->toCustomer((int) $req['customer_id'], 'Talebinize cevap geldi', (string) $req['subject'], ['url' => '/m/talep.php?r=' . $reqId], 'talep_cevap');
                } catch (\Throwable) {
                }
            }
            $openId = $reqId;
        } elseif ($action === 'status') {
            $status = ($_POST['status'] ?? '') === 'cozuldu' ? 'cozuldu' : 'acik';
            $repo->setRequestStatus($reqId, $status);
            uysa_audit('admin_talep_durum', $u['username'], (string) $reqId, $status, client_ip());
            $flash = $status === 'cozuldu' ? 'Talep çözüldü olarak işaretlendi.' : 'Talep yeniden açıldı.';
            // opus-021: durum değişikliği de müşteriye bildirilir
            try {
                (new Push($pdo))->toCustomer((int) $req['customer_id'], 'Talebinize cevap geldi', (string) $req['subject'], ['url' => '/m/talep.php?r=' . $reqId], 'talep_cevap');
            } catch (\Throwable) {
            }
            $openId = $reqId;
        }
    }
}

// ── Filtreler ──
$fType = (string) ($_GET['tur'] ?? '');
if (!isset($types[$fType])) {
    $fType = '';
}
$fStatus = (string) ($_GET['durum'] ?? '');
if (!in_array($fStatus, ['acik', 'cozuldu'], true)) {
    $fStatus = '';
}
$fCust = (int) ($_GET['musteri'] ?? 0);

$detail = null;
$messages = [];
if ($openId > 0) {
    $detail = $repo->requestById($openId);
    if ($detail) {
        $messages = $repo->requestMessages($openId);
    }
}

$statusMap = [
    'acik'    => ['badge-warn', 'bi-clock', 'Açık'],
    'cozuldu' => ['badge-ok', 'bi-check2-circle', 'Çözüldü'],
];

$customers = $repo->activeCustomers();
$csrf = Helpers::csrfToken();
$eyebrow = 'Müşteri talepleri';
$pageTitle = $detail ? 'Talep detayı' : 'Talepler';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <?php if (!$detail): ?>
      <div class="quick-tiles">
        <a class="q-tile" href="malzeme.php"><i class="bi bi-box-seam"></i> Malzeme talepleri</a>
      </div>
      <?php endif; ?>

      <?php if ($detail): ?>
        <a class="btn-action btn-secondaryx mb-2" href="talepler.php"><i class="bi bi-arrow-left"></i> Tüm talepler</a>
        <div class="cardx card-pad">
          <div class="d-flex align-items-center justify-between gap-2 mb-2">
            <div style="min-width:0">
              <p class="label"><?= Helpers::e($types[$detail['type']] ?? $detail['type']) ?> · <?= Helpers::e($detail['customer_name']) ?></p>
              <h2 style="margin:0"><?= Helpers::e($detail['subject']) ?></h2>
            </div>
            <?php [$bc, $bi, $bt] = $statusMap[$detail['status']] ?? ['badge-blue', 'bi-clock', $detail['status']]; ?>
            <span class="badge-soft <?= $bc ?>"><i class="bi <?= $bi ?>"></i> <?= Helpers::e($bt) ?></span>
          </div>
          <div class="msg-thread">
            <?php if (!$messages): ?><p class="row-meta">Henüz mesaj yok.</p><?php endif; ?>
            <?php foreach ($messages as $m): $mine = $m['sender'] === 'uysa'; ?>
              <div class="msg <?= $mine ? 'mine' : 'theirs' ?>">
                <?php if (trim((string) $m['body']) !== ''): ?><p class="msg-body"><?= nl2br(Helpers::e($m['body'])) ?></p><?php endif; ?>
                <?php if (!empty($m['file_id'])): $isImg = str_starts_with((string) $m['file_mime'], 'image/'); ?>
                  <a class="msg-attach" href="dosya.php?id=<?= (int) $m['file_id'] ?>" target="_blank" rel="noopener">
                    <?php if ($isImg): ?>
                      <img src="dosya.php?id=<?= (int) $m['file_id'] ?>" alt="Ek" style="max-width:200px;max-height:200px;border-radius:10px;display:block">
                    <?php else: ?>
                      <span class="badge-soft badge-blue"><i class="bi bi-paperclip"></i> <?= Helpers::e($m['file_orig'] ?: 'Ek') ?></span>
                    <?php endif; ?>
                  </a>
                <?php endif; ?>
                <span class="msg-meta"><?= $mine ? 'UYSA' : Helpers::e($detail['customer_name']) ?> · <?= Helpers::e(substr((string) $m['created_at'], 0, 16)) ?></span>
              </div>
            <?php endforeach; ?>
          </div>

          <form method="post" enctype="multipart/form-data" class="mt-3">
            <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="request_id" value="<?= (int) $detail['id'] ?>">
            <div class="field"><label>Cevap</label><textarea class="textareax" name="body" placeholder="Müşteriye cevabınız..."></textarea></div>
            <div class="field"><label>Fotoğraf ekle (opsiyonel)</label><input class="inputx" type="file" name="photo" accept="image/*,.pdf"></div>
            <button class="btn-action btn-primaryx btn-full mt-2" type="submit"><i class="bi bi-send"></i> Cevabı gönder</button>
          </form>
        </div>

        <form method="post" class="actions-row mt-3">
          <input type="hidden" name="csrf" value="<?= Helpers::e($csrf) ?>">
          <input type="hidden" name="action" value="status">
          <input type="hidden" name="request_id" value="<?= (int) $detail['id'] ?>">
          <?php if ($detail['status'] !== 'cozuldu'): ?>
            <input type="hidden" name="status" value="cozuldu">
            <button class="btn-action btn-secondaryx btn-full" type="submit"><i class="bi bi-check2-circle"></i> Çözüldü olarak işaretle</button>
          <?php else: ?>
            <input type="hidden" name="status" value="acik">
            <button class="btn-action btn-secondaryx btn-full" type="submit"><i class="bi bi-arrow-counterclockwise"></i> Yeniden aç</button>
          <?php endif; ?>
        </form>

      <?php else: ?>
        <form method="get" class="cardx card-pad">
          <div class="form-grid">
            <div class="field"><label>Tür</label>
              <select class="selectx" name="tur" onchange="this.form.submit()">
                <option value="">Tümü</option>
                <?php foreach ($types as $tk => $tl): ?><option value="<?= $tk ?>" <?= $fType === $tk ? 'selected' : '' ?>><?= Helpers::e($tl) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="field"><label>Durum</label>
              <select class="selectx" name="durum" onchange="this.form.submit()">
                <option value="">Tümü</option>
                <option value="acik" <?= $fStatus === 'acik' ? 'selected' : '' ?>>Açık</option>
                <option value="cozuldu" <?= $fStatus === 'cozuldu' ? 'selected' : '' ?>>Çözüldü</option>
              </select>
            </div>
            <div class="field"><label>Müşteri</label>
              <select class="selectx" name="musteri" onchange="this.form.submit()">
                <option value="0">Tümü</option>
                <?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $fCust === (int) $c['id'] ? 'selected' : '' ?>><?= Helpers::e($c['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
        </form>

        <?php
        $rows = $repo->allRequests(['type' => $fType, 'status' => $fStatus, 'customer_id' => $fCust ?: null]);
        ?>
        <div class="section-head mt-3"><h2>Talepler</h2><span class="text-muted" style="font-size:12px"><?= count($rows) ?> kayıt</span></div>
        <?php if (!$rows): ?>
          <div class="empty-state">Filtreye uyan talep yok.</div>
        <?php else: ?>
          <div class="list-groupx">
            <?php foreach ($rows as $r): [$bc, $bi, $bt] = $statusMap[$r['status']] ?? ['badge-blue', 'bi-clock', $r['status']]; ?>
              <a class="flow-item" style="grid-template-columns: minmax(0,1fr) auto" href="talepler.php?r=<?= (int) $r['id'] ?>">
                <div style="min-width:0">
                  <strong><?= Helpers::e($r['subject']) ?></strong>
                  <p class="row-meta"><?= Helpers::e($r['customer_name']) ?> · <?= Helpers::e($types[$r['type']] ?? $r['type']) ?> · <?= (int) $r['msg_count'] ?> mesaj · <?= Helpers::e(substr((string) ($r['last_msg_at'] ?: $r['created_at']), 0, 10)) ?></p>
                </div>
                <span class="badge-soft <?= $bc ?>"><i class="bi <?= $bi ?>"></i> <?= Helpers::e($bt) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
