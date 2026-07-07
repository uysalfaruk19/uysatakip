<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Uysa\CustomerAuth;
use Uysa\Db;
use Uysa\Env;
use Uysa\Helpers;
use Uysa\Repo;

$cu = CustomerAuth::requireCustomer();
$cid = (int) $cu['customer_id'];
$pdo = Db::pdo();
$repo = new Repo($pdo);

// opus-019: menü + öneri türleri eklendi.
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

/** Talep/mesaj foto eki: finans.php ile aynı whitelist deseni. Hata → $err, null döner. */
function handleTalepUpload(array $file, Repo $repo, string $by, string &$err): ?int
{
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null; // ek yok — sorun değil
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
    $dir = Env::get('UPLOAD_DIR') ?: dirname(__DIR__) . '/uploads';
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
        $err = '';
        $fileId = handleTalepUpload($_FILES['photo'] ?? [], $repo, $cu['username'], $err);
        if ($err !== '') {
            $flash = $err;
            $flashOk = false;
        } elseif ($action === 'reply') {
            $reqId = (int) ($_POST['request_id'] ?? 0);
            $req = $repo->requestForCustomer($reqId, $cid); // IDOR guard
            $msg = trim((string) ($_POST['body'] ?? ''));
            if (!$req) {
                $flash = 'Talep bulunamadı.';
                $flashOk = false;
            } elseif ($msg === '' && $fileId === null) {
                $flash = 'Mesaj veya fotoğraf ekleyin.';
                $flashOk = false;
            } else {
                $repo->addRequestMessage($reqId, 'musteri', mb_substr($msg, 0, 2000), $fileId);
                if ($req['status'] === 'cozuldu') {
                    $repo->setRequestStatus($reqId, 'acik');
                }
                uysa_audit('musteri_talep_mesaj', $cu['username'], (string) $cid, (string) $reqId, client_ip());
                $flash = 'Mesajınız gönderildi.';
                $openId = $reqId;
            }
        } else { // yeni talep
            $type = (string) ($_POST['type'] ?? 'talep');
            if (!isset($types[$type])) {
                $type = 'talep';
            }
            $subject = trim((string) ($_POST['subject'] ?? ''));
            $msg = trim((string) ($_POST['body'] ?? ''));
            if ($subject === '') {
                $flash = 'Konu boş olamaz.';
                $flashOk = false;
            } else {
                $reqId = $repo->createRequest($cid, $cu['cuid'] ?? null, $type, mb_substr($subject, 0, 200));
                if ($msg !== '' || $fileId !== null) {
                    $repo->addRequestMessage($reqId, 'musteri', mb_substr($msg, 0, 2000), $fileId);
                }
                uysa_audit('musteri_talep_yeni', $cu['username'], (string) $cid, (string) $reqId, client_ip());
                $flash = 'Talebiniz açıldı.';
                $openId = $reqId;
            }
        }
    }
}

$requests = $repo->customerRequests($cid);
$thread = null;
$messages = [];
if ($openId > 0) {
    $thread = $repo->requestForCustomer($openId, $cid); // IDOR guard
    if ($thread) {
        $messages = $repo->requestMessages($openId);
    }
}

$statusMap = [
    'acik'    => ['badge-warn', 'bi-clock', 'Açık'],
    'cozuldu' => ['badge-ok', 'bi-check2-circle', 'Çözüldü'],
];

$eyebrow = Helpers::e($cu['customer_name']) . ' · Talep';
$pageTitle = $thread ? 'Talep detayı' : 'Talep & iletişim';
$active = 'talep';
require __DIR__ . '/partials/header_m.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <?php if ($thread): ?>
        <a class="btn-action btn-secondaryx mb-2" href="talep.php"><i class="bi bi-arrow-left"></i> Taleplerim</a>
        <div class="cardx card-pad">
          <div class="d-flex align-items-center justify-between gap-2 mb-2">
            <div style="min-width:0">
              <p class="label"><?= Helpers::e($types[$thread['type']] ?? $thread['type']) ?></p>
              <h2 style="margin:0"><?= Helpers::e($thread['subject']) ?></h2>
            </div>
            <?php [$bc, $bi, $bt] = $statusMap[$thread['status']] ?? ['badge-blue', 'bi-clock', $thread['status']]; ?>
            <span class="badge-soft <?= $bc ?>"><i class="bi <?= $bi ?>"></i> <?= Helpers::e($bt) ?></span>
          </div>
          <div class="msg-thread">
            <?php if (!$messages): ?>
              <p class="row-meta">Henüz mesaj yok.</p>
            <?php endif; ?>
            <?php foreach ($messages as $m): $mine = $m['sender'] === 'musteri'; ?>
              <div class="msg <?= $mine ? 'mine' : 'theirs' ?>">
                <?php if (trim((string) $m['body']) !== ''): ?><p class="msg-body"><?= nl2br(Helpers::e($m['body'])) ?></p><?php endif; ?>
                <?php if (!empty($m['file_id'])): $isImg = str_starts_with((string) $m['file_mime'], 'image/'); ?>
                  <a class="msg-attach" href="dosya.php?id=<?= (int) $m['file_id'] ?>" target="_blank" rel="noopener">
                    <?php if ($isImg): ?>
                      <img src="dosya.php?id=<?= (int) $m['file_id'] ?>" alt="Ek" style="max-width:180px;max-height:180px;border-radius:10px;display:block">
                    <?php else: ?>
                      <span class="badge-soft badge-blue"><i class="bi bi-paperclip"></i> <?= Helpers::e($m['file_orig'] ?: 'Ek') ?></span>
                    <?php endif; ?>
                  </a>
                <?php endif; ?>
                <span class="msg-meta"><?= $mine ? 'Siz' : 'UYSA' ?> · <?= Helpers::e(substr((string) $m['created_at'], 0, 16)) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <form method="post" enctype="multipart/form-data" class="mt-3">
            <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="request_id" value="<?= (int) $thread['id'] ?>">
            <div class="field"><textarea class="textareax" name="body" placeholder="Mesajınız..."></textarea></div>
            <div class="field"><label>Fotoğraf ekle (jpg/png/pdf, opsiyonel)</label><input class="inputx" type="file" name="photo" accept="image/*,.pdf"></div>
            <button class="btn-action btn-primaryx btn-full mt-2" type="submit"><i class="bi bi-send"></i> Gönder</button>
          </form>
        </div>
      <?php else: ?>
        <div class="cardx card-pad">
          <h2>Yeni talep</h2>
          <form method="post" enctype="multipart/form-data" class="form-grid">
            <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
            <input type="hidden" name="action" value="new">
            <div class="field"><label>Tür</label>
              <select class="selectx" name="type">
                <?php foreach ($types as $tk => $tl): ?><option value="<?= $tk ?>"><?= Helpers::e($tl) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="field"><label>Konu</label><input class="inputx" name="subject" maxlength="200" required></div>
            <div class="field"><label>Mesaj</label><textarea class="textareax" name="body" placeholder="Detay (isteğe bağlı)"></textarea></div>
            <div class="field"><label>Fotoğraf ekle (özellikle şikayet için, opsiyonel)</label><input class="inputx" type="file" name="photo" accept="image/*,.pdf"></div>
            <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-plus-circle"></i> Talep aç</button>
          </form>
        </div>

        <div class="section-head mt-3"><h2>Taleplerim</h2></div>
        <?php if (!$requests): ?>
          <div class="empty-state">Henüz talebiniz yok.</div>
        <?php else: ?>
          <div class="list-groupx">
            <?php foreach ($requests as $r): [$bc, $bi, $bt] = $statusMap[$r['status']] ?? ['badge-blue', 'bi-clock', $r['status']]; ?>
              <a class="flow-item" style="grid-template-columns: minmax(0,1fr) auto" href="talep.php?r=<?= (int) $r['id'] ?>">
                <div style="min-width:0">
                  <strong><?= Helpers::e($r['subject']) ?></strong>
                  <p class="row-meta"><?= Helpers::e($types[$r['type']] ?? $r['type']) ?> · <?= Helpers::e(substr((string) $r['created_at'], 0, 10)) ?></p>
                </div>
                <span class="badge-soft <?= $bc ?>"><i class="bi <?= $bi ?>"></i> <?= Helpers::e($bt) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
<?php require __DIR__ . '/partials/footer_m.php'; ?>
