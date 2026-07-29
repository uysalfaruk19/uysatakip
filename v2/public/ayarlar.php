<?php

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Mail;
use Uysa\ParasutPdf;
use Uysa\Repo;

/**
 * fable-052 — Belge maili ayarları + gönderim kuyruğu görünürlüğü.
 *
 * Paraşüt'ün paylaşım ucu müşteriye ZIP (PDF + imzalı UBL zarfı) yolluyor; ek formatını
 * seçtiren seçenek YOK. Varsayılan artık: PDF'i indir → UYSA'nın kendi mailinden tek PDF gönder.
 * Eski Paraşüt paylaşımı KOD OLARAK DURUYOR (rollback yolu) — buradan geri alınabilir.
 */

$u = Auth::requireLogin();
if (!Auth::isAdmin($u)) {
    header('Location: index.php');
    exit;
}
$repo = new Repo(Db::pdo());

$flash = '';
$flashOk = true;
$tabloYok = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } else {
        $secim = ($_POST['paylasim_yontemi'] ?? '') === 'parasut' ? 'parasut' : 'uysa_mail';
        try {
            $repo->ayarSet('paylasim_yontemi', $secim);
            uysa_audit('ayar_paylasim_yontemi', $u['username'], $secim, '', client_ip());
            $flash = $secim === 'uysa_mail'
                ? 'Kaydedildi: belgeler UYSA mailinden TEK PDF olarak gidecek.'
                : 'Kaydedildi: Paraşüt paylaşımına dönüldü — müşteriye ZIP gider.';
        } catch (\Throwable $e) {
            $flash = 'Kaydedilemedi: ' . $e->getMessage();
            $flashOk = false;
        }
    }
}

$yontem = 'uysa_mail';
$ozet = ['bekliyor' => 0, 'gonderildi' => 0, 'hata' => 0];
$kuyruk = [];
try {
    $yontem = $repo->paylasimYontemi();
    $ozet = $repo->mailKuyrukOzet();
    $kuyruk = $repo->mailKuyrukSon(30);
} catch (\Throwable $e) {
    $tabloYok = true;
}

$smtpOk = Mail::yapilandirilmis();
$parasutOk = ParasutPdf::yapilandirilmis();

$durumEtiket = ['bekliyor' => 'sırada', 'gonderildi' => 'gönderildi', 'hata' => 'hata'];

$eyebrow = 'Fatura / irsaliye müşteriye nasıl gitsin';
$pageTitle = 'Belge maili ayarları';
$active = '';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <?php if ($tabloYok): ?>
      <div class="flash err">Mail kuyruğu tablosu bulunamadı — <code>sql/migrate_049.sql</code> henüz uygulanmamış.
        Kokpit bu durumda ESKİ (Paraşüt paylaşımı) akışını sürdürür; belge kaybı olmaz.</div>
      <?php endif; ?>

      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-envelope-paper"></i> GÖNDERİM YÖNTEMİ</div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <label class="irs-row" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
            <input type="radio" name="paylasim_yontemi" value="uysa_mail"<?= $yontem === 'uysa_mail' ? ' checked' : '' ?> style="margin-top:3px">
            <span style="flex:1">
              <span class="irs-name">UYSA mailinden tek PDF <span class="badge-soft">önerilen</span></span>
              <span class="row-meta" style="display:block;margin-top:2px">Belgenin PDF'i Paraşüt'ten indirilir ve
                <strong>fatura@uysayemek.com.tr</strong> üzerinden ek olarak gönderilir. Müşteri tek bir PDF görür.</span>
            </span>
          </label>
          <label class="irs-row" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
            <input type="radio" name="paylasim_yontemi" value="parasut"<?= $yontem === 'parasut' ? ' checked' : '' ?> style="margin-top:3px">
            <span style="flex:1">
              <span class="irs-name">Paraşüt paylaşımı</span>
              <span class="row-meta" style="display:block;margin-top:2px">Paraşüt kendi maili ile gönderir —
                <strong>ZIP gönderir</strong> (PDF + imzalı XML zarfı birlikte). Müşteri dosyayı açmakta zorlanır.</span>
            </span>
          </label>
          <div class="actions-row mt-3">
            <button class="btn-action btn-primaryx" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
          </div>
        </form>
        <p class="row-meta" style="margin-top:8px">
          Belge kesildiği anda mail sıraya girer ve hemen bir kez denenir. PDF, belge resmileşene
          kadar hazır olmayabilir — hazır olmadıysa <strong>5 dakikada bir</strong> otomatik tekrar denenir.
          Kimseye iki kez mail gitmez (her belge kuyrukta tektir).
        </p>
      </div>

      <div class="summary-grid">
        <div class="summary-card<?= $ozet['bekliyor'] > 0 ? ' tint-orange' : '' ?>"><p class="label">Sırada</p>
          <p class="metric small"><?= (int) $ozet['bekliyor'] ?></p>
          <span class="delta" style="color:inherit;opacity:.65">gönderilmeyi bekliyor</span></div>
        <div class="summary-card tint-green"><p class="label">Gönderildi</p>
          <p class="metric small"><?= (int) $ozet['gonderildi'] ?></p></div>
        <div class="summary-card<?= $ozet['hata'] > 0 ? ' tint-red' : '' ?>"><p class="label">Hata</p>
          <p class="metric small"><?= (int) $ozet['hata'] ?></p>
          <span class="delta" style="color:<?= $ozet['hata'] > 0 ? '#b42318' : 'inherit' ?>;opacity:<?= $ozet['hata'] > 0 ? '1' : '.65' ?>"><?= $ozet['hata'] > 0 ? 'elle gönderim gerekir' : 'sorun yok' ?></span></div>
      </div>

      <?php if (!$smtpOk || !$parasutOk): ?>
      <div class="flash err">
        <?php if (!$smtpOk): ?>Mail sunucusu ayarı eksik (SMTP_HOST / SMTP_USER / SMTP_PASS). <?php endif; ?>
        <?php if (!$parasutOk): ?>Paraşüt bağlantısı bu sunucuda çözülemiyor (PDF indirilemez). <?php endif; ?>
        Bu durumda gönderim <strong>denenmiyor</strong>; kayıtlar sırada bekler, kaybolmaz.
      </div>
      <?php endif; ?>

      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-list-check"></i> SON GÖNDERİMLER
          <span class="gt-hr"><?= count($kuyruk) ?> kayıt</span></div>
        <?php if (!$kuyruk): ?>
          <div class="empty-state">Henüz mail kuyruğuna kayıt düşmedi.</div>
        <?php else: foreach ($kuyruk as $k):
            $d = (string) $k['durum'];
            $cls = $d === 'gonderildi' ? '' : ($d === 'hata' ? ' warn' : '');
            $belge = trim((string) ($k['belge_no'] ?? '')) !== '' ? (string) $k['belge_no'] : (string) $k['kaynak_id'];
        ?>
          <div class="gt-kr<?= $cls ?>">
            <div class="gt-kr-head">
              <div class="gt-rank"><i class="bi <?= (string) $k['tur'] === 'fatura' ? 'bi-receipt' : 'bi-truck' ?>"></i></div>
              <div class="gt-kr-firm">
                <div class="gt-kr-ad"><?= Helpers::e((string) ($k['musteri'] ?? '—')) ?>
                  · <?= Helpers::e($durumEtiket[$d] ?? $d) ?></div>
                <div class="gt-kr-sub"><?= Helpers::e((string) $k['tur']) ?> <?= Helpers::e($belge) ?>
                  · <?= Helpers::e((string) $k['alici']) ?>
                  <?php if ((int) $k['deneme'] > 0): ?> · <?= (int) $k['deneme'] ?> deneme<?php endif; ?>
                  <?php if (($k['gonderim_at'] ?? null) !== null): ?> · <?= Helpers::e(date('d.m.Y H:i', strtotime((string) $k['gonderim_at']))) ?><?php endif; ?>
                </div>
                <?php if (trim((string) ($k['son_hata'] ?? '')) !== '' && $d !== 'gonderildi'): ?>
                <div class="gt-kr-sub" style="color:#b42318"><?= Helpers::e((string) $k['son_hata']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; endif; ?>
        <p class="row-meta" style="margin-top:8px">Kayıtlar silinmez — gönderim izi kalıcıdır.</p>
      </div>
<?php require __DIR__ . '/partials/footer.php'; ?>
