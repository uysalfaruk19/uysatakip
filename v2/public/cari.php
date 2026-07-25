<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

$u = Auth::requireLogin();
$pdo = Db::pdo();
$repo = new Repo($pdo);

// fable-045: iki sekme — BORÇLARIM (tedarikçi bazlı, aydan bağımsız) | ALACAKLARIM (müşteri cari).
// fable-046 (Ömer): her iki listede de satıra dokununca AYNI SAYFADA açılır (personel.php avans
// deseni: native <details>/<summary>, JS yok). ?ted= / ?musteri= artık sayfa NAVİGASYONU değil,
// yalnız "hangi blok açık gelsin" bilgisi — derin bağlantılar (parasut.php) aynen çalışır.
$sekme = ((string) ($_GET['sekme'] ?? '')) === 'borclarim' ? 'borclarim' : 'alacaklar';
$tedKey = Repo::normTedarikci((string) ($_GET['ted'] ?? ''));
$acikMusteri = (int) ($_GET['musteri'] ?? 0);

$month = (string) ($_GET['ay'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

// fable-046: POST-redirect-GET. Sayfa yenilemede ödeme/tahsilatın TEKRAR gönderilmesi
// (F5 → çift ödeme) böylece imkânsız; flash mesajı oturumda taşınır, açık blok URL'de.
$flash = (string) ($_SESSION['cari_flash'] ?? '');
$flashOk = (bool) ($_SESSION['cari_flash_ok'] ?? true);
unset($_SESSION['cari_flash'], $_SESSION['cari_flash_ok']);

/** Flash bırak + aynı yere dön (çift POST kalkanı). */
$redirect = static function (string $qs, string $msg, bool $ok = true): void {
    $_SESSION['cari_flash'] = $msg;
    $_SESSION['cari_flash_ok'] = $ok;
    header('Location: cari.php?' . $qs);
    exit;
};

// ── POST ─────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? 'tahsilat');
    $postTed = Repo::normTedarikci((string) ($_POST['ted'] ?? ''));
    $postCid = (int) ($_POST['customer_id'] ?? 0);
    $geriBorc = 'sekme=borclarim&ted=' . rawurlencode($postTed) . '#t-' . md5($postTed);
    $geriAlacak = 'sekme=alacaklar&ay=' . rawurlencode($month) . '&musteri=' . $postCid . '#m-' . $postCid;

    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $redirect($action === 'tahsilat' ? $geriAlacak : $geriBorc, 'Oturum doğrulaması başarısız.', false);
    } elseif ($action === 'ted_odeme') {
        // Tedarikçiye ödeme (kısmi/tam) veya negatif düzeltme.
        $tutar = Helpers::parseMoney((string) ($_POST['tutar'] ?? '0'));
        $duzeltme = isset($_POST['duzeltme']); // işaretliyse negatif kayıt (geri alma)
        if ($duzeltme) {
            $tutar = -abs($tutar);
        }
        $tarih = (string) ($_POST['odeme_tarihi'] ?? Helpers::today());
        if (!Helpers::isDate($tarih)) {
            $tarih = Helpers::today();
        }
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        $newId = $repo->tedarikciOdemeEkle($postTed, $tarih, $tutar, $note);
        if ($newId > 0) {
            uysa_audit('tedarikci_odeme', $u['username'], $postTed, json_encode(['t' => $tutar, 'tarih' => $tarih]), client_ip());
            $redirect($geriBorc, ($tutar < 0 ? 'Düzeltme' : 'Ödeme') . ' işlendi · ₺ ' . Helpers::money(abs($tutar)));
        }
        $redirect($geriBorc, 'Geçerli tutar girin.', false);
    } elseif ($action === 'ted_devir') {
        // Devir (açılış) bakiyesi — elle bir kere; güncellenebilir.
        $label = trim((string) ($_POST['label'] ?? ''));
        $tutar = Helpers::parseMoney((string) ($_POST['tutar'] ?? '0'));
        $repo->tedarikciDevirKaydet($postTed, $label, $tutar);
        uysa_audit('tedarikci_devir', $u['username'], $postTed, json_encode(['t' => $tutar]), client_ip());
        $redirect($geriBorc, 'Devir bakiyesi kaydedildi · ₺ ' . Helpers::money($tutar));
    } else {
        // Müşteri tahsilatı (mevcut işlev — hesap mantığı değişmedi).
        $amount = Helpers::parseMoney((string) ($_POST['amount'] ?? '0'));
        $txDate = (string) ($_POST['entry_date'] ?? Helpers::today());
        if (!Helpers::isDate($txDate)) {
            $txDate = Helpers::today();
        }
        if ($amount > 0 && $postCid > 0) {
            $repo->addCari('customer', $postCid, $txDate, 'alacak', $amount, trim((string) ($_POST['note'] ?? 'Tahsilat')));
            uysa_audit('tahsilat', $u['username'], (string) $postCid, json_encode(['t' => $amount]), client_ip());
            // Tahsilat hangi aya girildiyse ekstre o ayı göstersin (mevcut davranış korundu).
            $geriAlacak = 'sekme=alacaklar&ay=' . rawurlencode(substr($txDate, 0, 7)) . '&musteri=' . $postCid . '#m-' . $postCid;
            $redirect($geriAlacak, 'Tahsilat kaydedildi · ₺ ' . Helpers::money($amount));
        }
        $redirect($geriAlacak, 'Geçerli tutar girin.', false);
    }
}

$eyebrow = 'Cari hesap';
$pageTitle = $sekme === 'borclarim' ? 'Borçlarım' : 'Alacaklarım';
$active = 'cari';
require __DIR__ . '/partials/header.php';

/** Sekme linki (query bağımsız). */
$sekmeUrl = static fn(string $s): string => 'cari.php?sekme=' . $s;
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>

      <div class="segmented">
        <a class="chip <?= $sekme === 'borclarim' ? 'active' : '' ?>" href="<?= $sekmeUrl('borclarim') ?>"><i class="bi bi-arrow-up-right-circle"></i> Borçlarım</a>
        <a class="chip <?= $sekme === 'alacaklar' ? 'active' : '' ?>" href="<?= $sekmeUrl('alacaklar') ?>"><i class="bi bi-arrow-down-left-circle"></i> Alacaklarım</a>
      </div>

<?php if ($sekme === 'borclarim'): ?>
<?php
    // ── BORÇLARIM (tedarikçi bazlı, AYDAN BAĞIMSIZ kümülatif) ──
    // fable-046: tek geçişte TÜM detaylar (eskiden satır başına 1 tam tarama vardı).
    $detaylar = $repo->borclarimDetayTumu();
    $toplamKalan = 0.0;
    $toplamFatura = 0.0;
    $toplamOdenen = 0.0;
    foreach ($detaylar as $b) {
        $toplamKalan += $b['kalan'];
        $toplamFatura += $b['fatura'] + $b['devir'];
        $toplamOdenen += $b['odenen'];
    }
?>
        <div class="summary-grid">
          <div class="summary-card"><p class="label">Toplam borç (kalan)</p><p class="metric <?= $toplamKalan > 0 ? 'neg' : '' ?>">₺ <?= Helpers::money($toplamKalan) ?></p></div>
          <div class="summary-card"><p class="label">Ödenen</p><p class="metric">₺ <?= Helpers::money($toplamOdenen) ?></p></div>
          <div class="summary-card wide"><p class="label">Tedarikçi</p><p class="metric"><?= count($detaylar) ?> firma · fatura+devir ₺ <?= Helpers::money($toplamFatura) ?></p>
            <span class="delta"><i class="bi bi-info-circle"></i> Aydan bağımsız · tüm zaman kümülatif</span></div>
        </div>

        <div class="cardx card-pad">
          <div class="gt-h"><i class="bi bi-arrow-up-right-circle"></i> BORÇLARIM <span class="text-muted" style="font-size:12px;font-weight:600">(tedarikçi bazlı · Personel/Taşıma hariç)</span></div>
          <?php if (!$detaylar): ?>
            <div class="empty-state">Kayıtlı gider faturası olan tedarikçi yok.</div>
          <?php else: ?>
            <?php foreach ($detaylar as $b):
                $toplam = $b['fatura'] + $b['devir'];
                $oran = $toplam > 0 ? max(0.0, min(1.0, $b['odenen'] / $toplam)) : ($b['kalan'] <= 0 ? 1.0 : 0.0);
                $w = round($oran * 100);
                $borclu = $b['kalan'] > 0.005;
                $anchor = 't-' . md5($b['key']);
            ?>
            <details class="gt-satir" id="<?= $anchor ?>"<?= $tedKey === $b['key'] ? ' open' : '' ?>>
              <summary>
                <div class="gt-kr<?= $borclu ? ' warn' : '' ?>">
                  <div class="gt-kr-head">
                    <div class="gt-rank"><?= Helpers::e(mb_strtoupper(mb_substr((string) $b['label'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
                    <div class="gt-kr-firm">
                      <div class="gt-kr-ad"><?= Helpers::e($b['label']) ?></div>
                      <div class="gt-kr-sub">fatura ₺<?= Helpers::money($b['fatura'] + $b['devir']) ?> · ödenen ₺<?= Helpers::money($b['odenen']) ?></div>
                    </div>
                    <div class="gt-kr-val <?= $borclu ? 'bad' : 'ok' ?>">₺<?= Helpers::money($b['kalan']) ?><small><?= $borclu ? 'kalan' : 'kapandı' ?></small></div>
                  </div>
                  <div class="gt-bar"><i class="<?= $borclu ? '' : 'bad' ?>" style="width: <?= $borclu ? $w : 100 ?>%"></i></div>
                </div>
              </summary>
              <div class="gt-satir-detay">
                <div class="gt-h"><i class="bi bi-cash-stack"></i> ÖDEME İŞLE</div>
                <form method="post" class="form-grid">
                  <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
                  <input type="hidden" name="action" value="ted_odeme">
                  <input type="hidden" name="ted" value="<?= Helpers::e($b['key']) ?>">
                  <div class="field"><label>Tutar (₺)</label><input class="inputx" name="tutar" inputmode="decimal" placeholder="0,00" required></div>
                  <div class="field"><label>Tarih</label><input class="inputx" type="date" name="odeme_tarihi" value="<?= Helpers::e(Helpers::today()) ?>"></div>
                  <div class="field"><label>Not</label><input class="inputx" name="note" placeholder="ör. havale"></div>
                  <label class="check-row" style="grid-column:1/-1"><input type="checkbox" name="duzeltme" value="1"> Düzeltme (geri alma — negatif kayıt)</label>
                  <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-check2"></i> Ödemeyi işle</button>
                </form>

                <div class="gt-h"><i class="bi bi-clock-history"></i> ÖDEME GEÇMİŞİ</div>
                <?php if (!$b['odemeler']): ?>
                  <div class="empty-state">Henüz ödeme yok.</div>
                <?php else: ?>
                  <table class="tablex">
                    <thead><tr><th>Tarih</th><th>Not</th><th class="num">Tutar</th></tr></thead>
                    <tbody>
                    <?php foreach ($b['odemeler'] as $o): $neg = (float) $o['tutar'] < 0; ?>
                      <tr>
                        <td><?= Helpers::e(date('d.m.Y', strtotime((string) $o['odeme_tarihi']))) ?></td>
                        <td><?= Helpers::e((string) ($o['note'] ?? '') ?: ($neg ? 'Düzeltme' : 'Ödeme')) ?></td>
                        <td class="num" style="color:<?= $neg ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money((float) $o['tutar']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>

                <div class="gt-h"><i class="bi bi-receipt"></i> FATURA DÖKÜMÜ <span class="text-muted" style="font-size:12px;font-weight:600">(tüm zaman · <?= (int) $b['adet'] ?> fatura)</span></div>
                <?php if (!$b['faturalar']): ?>
                  <div class="empty-state">Bu tedarikçide gider faturası yok (yalnız devir/ödeme).</div>
                <?php else: ?>
                  <table class="tablex">
                    <thead><tr><th>Tarih</th><th>Açıklama</th><th class="num">Tutar</th></tr></thead>
                    <tbody>
                    <?php foreach ($b['faturalar'] as $f): ?>
                      <tr>
                        <td><?= Helpers::e(date('d.m.y', strtotime($f['tx_date']))) ?></td>
                        <td><?= Helpers::e($f['no'] ?: ($f['kategori'] ?: 'Fatura')) ?></td>
                        <td class="num">₺ <?= Helpers::money($f['amount']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>

                <details class="mt-3">
                  <summary style="cursor:pointer;list-style:none;font-weight:700;font-size:12px;color:var(--primary)"><i class="bi bi-pencil-square"></i> Devir (açılış) bakiyesi — sistemde olmayan eski borç</summary>
                  <form method="post" class="form-grid" style="margin-top:8px">
                    <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
                    <input type="hidden" name="action" value="ted_devir">
                    <input type="hidden" name="ted" value="<?= Helpers::e($b['key']) ?>">
                    <input type="hidden" name="label" value="<?= Helpers::e($b['label']) ?>">
                    <div class="field"><label>Devir bakiyesi (₺)</label><input class="inputx" name="tutar" inputmode="decimal" value="<?= $b['devir'] != 0.0 ? Helpers::money($b['devir']) : '' ?>" placeholder="0,00"></div>
                    <button class="btn-action btn-full" type="submit"><i class="bi bi-check2"></i> Devri kaydet</button>
                  </form>
                </details>
              </div>
            </details>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

<?php else: ?>
<?php
    // ── ALACAKLARIM (müşteri cari/tahsilat) ──
    // fable-046: müşteri seçici çipler (sayfa yenileyen) KALKTI — her müşteri kendi satırında
    // açılır. Paraşüt bakiyesi CACHE'ten okunur (tools/parasut_bakiye_sync.php periyodik yazar);
    // bu ekran Paraşüt'e ANLIK ÇAĞRI YAPMAZ (hız + rate-limit güvenliği).
    // Sıra activeCustomers'ın SQL sırasıdır (ad ASC); parasut_bakiye/parasut_sync_at alanları
    // listCustomersByCategory'den gelir (activeCustomers bu iki kolonu seçmiyor).
    $parasutMap = [];
    foreach (array_merge($repo->listCustomersByCategory('uretim'), $repo->listCustomersByCategory('tasima')) as $r) {
        $parasutMap[(int) $r['id']] = $r;
    }
    $liste = [];
    foreach ($repo->activeCustomers() as $c) {
        $liste[] = $parasutMap[(int) $c['id']] ?? $c;
    }

    // Pay hesapları TEK sefer (customerNetKarlilik toplu çağrı deseni).
    $giderMap = $repo->giderDagitim($month)['per_customer'];
    $persMap = $repo->personelDagitim($month)['per_customer'];

    $satirlar = [];
    $toplamAlacak = 0.0;
    $toplamParasut = 0.0;
    $parasutAdet = 0;
    $sonSenkron = null;
    foreach ($liste as $c) {
        $cid = (int) $c['id'];
        $bakiye = $repo->customerBalance($cid);
        $toplamAlacak += $bakiye;
        $pb = $c['parasut_bakiye'] ?? null;
        if ($pb !== null) {
            $toplamParasut += (float) $pb;
            $parasutAdet++;
        }
        $sa = (string) ($c['parasut_sync_at'] ?? '');
        if ($sa !== '' && ($sonSenkron === null || $sa > $sonSenkron)) {
            $sonSenkron = $sa;
        }
        $satirlar[] = [
            'c' => $c,
            'cid' => $cid,
            'bakiye' => $bakiye,
            'fiyat' => (float) $repo->priceFor($cid, $month)['unit_price'],
            'uretim' => $repo->customerMonthProduction($cid, $month),
            'net' => $repo->customerNetKarlilik($cid, $month, $giderMap, $persMap),
            'ekstre' => $repo->customerStatement($cid, $month),
        ];
    }
?>
        <div class="summary-grid">
          <div class="summary-card"><p class="label">Toplam alacak</p><p class="metric <?= $toplamAlacak < 0 ? 'neg' : '' ?>">₺ <?= Helpers::money($toplamAlacak) ?></p></div>
          <div class="summary-card"><p class="label">Müşteri</p><p class="metric"><?= count($satirlar) ?></p></div>
          <?php if ($parasutAdet > 0): ?>
          <div class="summary-card wide tint-blue"><p class="label">Paraşüt bakiyesi (muhasebe · <?= $parasutAdet ?> müşteri)</p>
            <p class="metric <?= $toplamParasut < 0 ? 'neg' : '' ?>">₺ <?= Helpers::money($toplamParasut) ?></p>
            <span class="delta"><i class="bi bi-shield-check"></i> <?= $sonSenkron
                ? Helpers::e(date('d.m.Y H:i', strtotime($sonSenkron))) . "'te güncellendi"
                : 'henüz senkronlanmadı' ?></span></div>
          <?php else: ?>
          <div class="summary-card wide"><p class="label"><?= Helpers::e(ay_label_tr($month)) ?></p>
            <p class="metric">cari hareket</p>
            <span class="delta"><i class="bi bi-info-circle"></i> Paraşüt cari'si bağlı müşteri yok</span></div>
          <?php endif; ?>
        </div>

        <div class="cardx card-pad">
          <div class="gt-h"><i class="bi bi-arrow-down-left-circle"></i> ALACAKLARIM <span class="text-muted" style="font-size:12px;font-weight:600">(müşteri bazlı · <?= Helpers::e(ay_label_tr($month)) ?> ekstresi)</span></div>
          <?php if (!$satirlar): ?>
            <div class="empty-state">Aktif müşteri yok.</div>
          <?php else: ?>
            <?php foreach ($satirlar as $s):
                $c = $s['c'];
                $cid = $s['cid'];
                $bakiye = $s['bakiye'];
                $net = $s['net'];
                $alacakli = $bakiye > 0.005;
                $pb = $c['parasut_bakiye'] ?? null;
            ?>
            <details class="gt-satir" id="m-<?= $cid ?>"<?= $acikMusteri === $cid ? ' open' : '' ?>>
              <summary>
                <div class="gt-kr<?= $alacakli ? ' warn' : '' ?>">
                  <div class="gt-kr-head">
                    <div class="gt-rank"><?= Helpers::e(mb_strtoupper(mb_substr((string) $c['name'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
                    <div class="gt-kr-firm">
                      <div class="gt-kr-ad"><?= Helpers::e($c['name']) ?></div>
                      <div class="gt-kr-sub"><?= Helpers::e(ay_label_tr($month)) ?> · <?= number_format((int) $s['uretim']['persons'], 0, ',', '.') ?> kişi · ₺<?= Helpers::money((float) $s['uretim']['amount']) ?><?php
                        if ($pb !== null): ?> · Paraşüt ₺<?= Helpers::money((float) $pb) ?><?php endif; ?></div>
                    </div>
                    <div class="gt-kr-val <?= $alacakli ? 'bad' : 'ok' ?>">₺<?= Helpers::money($bakiye) ?><small><?= $alacakli ? 'alacak' : ($bakiye < -0.005 ? 'fazla ödeme' : 'kapandı') ?></small></div>
                  </div>
                </div>
              </summary>
              <div class="gt-satir-detay">
                <div class="gt-h"><i class="bi bi-plus-circle"></i> TAHSİLAT EKLE</div>
                <form method="post" class="form-grid">
                  <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
                  <input type="hidden" name="action" value="tahsilat">
                  <input type="hidden" name="customer_id" value="<?= $cid ?>">
                  <div class="field"><label>Tutar (₺)</label><input class="inputx" name="amount" inputmode="decimal" placeholder="0,00" required></div>
                  <div class="field"><label>Tarih</label><input class="inputx" type="date" name="entry_date" value="<?= Helpers::e(Helpers::today()) ?>"></div>
                  <div class="field"><label>Not</label><input class="inputx" name="note" placeholder="ör. havale"></div>
                  <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-check2"></i> Tahsilatı kaydet</button>
                </form>

                <table class="tablex" style="margin-top:12px">
                  <tbody>
                    <tr><td>Birim fiyat (<?= Helpers::e(ay_label_tr($month)) ?>)</td><td class="num">₺ <?= Helpers::money($s['fiyat']) ?></td></tr>
                    <tr><td>Kokpit bakiye</td><td class="num" style="color:<?= $bakiye < 0 ? 'var(--red)' : 'var(--text)' ?>">₺ <?= Helpers::money($bakiye) ?></td></tr>
                    <?php if ($pb !== null): ?>
                    <tr><td>Paraşüt bakiyesi (muhasebe)<?= ($c['parasut_sync_at'] ?? null)
                        ? ' · <span class="text-muted" style="font-size:11.5px">' . Helpers::e(date('d.m H:i', strtotime((string) $c['parasut_sync_at']))) . "'te güncellendi</span>"
                        : '' ?></td>
                      <td class="num">₺ <?= Helpers::money((float) $pb) ?></td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>

                <div class="gt-h"><i class="bi bi-cash-coin"></i> NET KÂR <span class="text-muted" style="font-size:12px;font-weight:600">(personel + gider düşülmüş)</span></div>
                <table class="tablex">
                  <tbody>
                    <?php if ($net['category'] === 'tasima'): ?>
                      <tr><td>Taşıma satış</td><td class="num">₺ <?= Helpers::money($net['ciro']) ?></td></tr>
                      <tr><td>Taşıma alış</td><td class="num">− ₺ <?= Helpers::money($net['alis']) ?></td></tr>
                      <tr><td>Sabit gider</td><td class="num">− ₺ <?= Helpers::money($net['sabit']) ?></td></tr>
                    <?php else: ?>
                      <tr><td>Üretim cirosu</td><td class="num">₺ <?= Helpers::money($net['ciro']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td>Payına düşen gider (ciro oranlı)</td><td class="num">− ₺ <?= Helpers::money($net['pay_gider']) ?></td></tr>
                    <tr><td>Payına düşen personel</td><td class="num">− ₺ <?= Helpers::money($net['pay_personel']) ?></td></tr>
                    <tr class="is-total"><td>Net kâr</td><td class="num" style="color:<?= $net['net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($net['net']) ?></td></tr>
                  </tbody>
                </table>

                <div class="gt-h"><i class="bi bi-receipt"></i> <?= Helpers::e(ay_label_tr($month)) ?> EKSTRESİ</div>
                <?php if (!$s['ekstre']): ?>
                  <div class="empty-state">Bu ay hareket yok.</div>
                <?php else: ?>
                  <table class="tablex">
                    <thead><tr><th>Tarih</th><th>Açıklama</th><th class="num">Tutar</th></tr></thead>
                    <tbody>
                    <?php foreach ($s['ekstre'] as $e): $isBorc = $e['direction'] === 'borc'; ?>
                      <tr>
                        <td><?= Helpers::e(date('d.m', strtotime($e['entry_date']))) ?></td>
                        <td><?= Helpers::e($e['note'] ?: ($isBorc ? 'Üretim' : 'Tahsilat')) ?></td>
                        <td class="num <?= $isBorc ? '' : 'amount in' ?>">₺ <?= Helpers::money((float) $e['amount']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
                <div class="actions-row mt-3">
                  <a class="btn-action btn-ghost flex-fill" href="rapor.php?musteri=<?= $cid ?>&ay=<?= Helpers::e($month) ?>"><i class="bi bi-graph-up-arrow"></i> Aylık rapor</a>
                </div>
              </div>
            </details>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
<?php endif; ?>
      <script>
      // fable-046: PARA formunda ÇİFT GÖNDERİM kalkanı. POST-redirect-GET F5'i kapatıyor;
      // bu da hızlı çift dokunuşu kapatır (aynı ödeme/tahsilat iki kez düşmesin).
      // Alan adlarına/id'lere dokunmaz — yalnız 2. submit'i iptal eder.
      document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f || f.method !== 'post') return;
        if (f.dataset.gonderildi === '1') { e.preventDefault(); return; }
        f.dataset.gonderildi = '1';
        var b = f.querySelector('button[type=submit]');
        if (b) { setTimeout(function () { b.disabled = true; }, 0); }
      }, true);
      </script>
<?php require __DIR__ . '/partials/footer.php'; ?>
