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
// ?musteri=X gelince (Paraşüt/eski linkler) Alacaklarım varsayılan kalır — işlev kaybı yok.
$sekme = ((string) ($_GET['sekme'] ?? '')) === 'borclarim' ? 'borclarim' : 'alacaklar';
$tedKey = trim((string) ($_GET['ted'] ?? ''));

$customers = $repo->activeCustomers();
$cid = (int) ($_GET['musteri'] ?? ($customers[0]['id'] ?? 0));
$month = (string) ($_GET['ay'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$flash = '';
$flashOk = true;

// ── POST ─────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? 'tahsilat');
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
    } elseif ($action === 'ted_odeme') {
        // Tedarikçiye ödeme (kısmi/tam) veya negatif düzeltme.
        $sekme = 'borclarim';
        $tedKey = trim((string) ($_POST['ted'] ?? ''));
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
        $newId = $repo->tedarikciOdemeEkle($tedKey, $tarih, $tutar, $note);
        if ($newId > 0) {
            uysa_audit('tedarikci_odeme', $u['username'], $tedKey, json_encode(['t' => $tutar, 'tarih' => $tarih]), client_ip());
            $flash = ($tutar < 0 ? 'Düzeltme' : 'Ödeme') . ' işlendi · ₺ ' . Helpers::money(abs($tutar));
        } else {
            $flash = 'Geçerli tutar girin.';
            $flashOk = false;
        }
    } elseif ($action === 'ted_devir') {
        // Devir (açılış) bakiyesi — elle bir kere; güncellenebilir.
        $sekme = 'borclarim';
        $tedKey = trim((string) ($_POST['ted'] ?? ''));
        $label = trim((string) ($_POST['label'] ?? ''));
        $tutar = Helpers::parseMoney((string) ($_POST['tutar'] ?? '0'));
        $repo->tedarikciDevirKaydet($tedKey, $label, $tutar);
        uysa_audit('tedarikci_devir', $u['username'], $tedKey, json_encode(['t' => $tutar]), client_ip());
        $flash = 'Devir bakiyesi kaydedildi · ₺ ' . Helpers::money($tutar);
    } else {
        // Müşteri tahsilatı (mevcut işlev — değişmez).
        $cid = (int) ($_POST['customer_id'] ?? $cid);
        $amount = Helpers::parseMoney((string) ($_POST['amount'] ?? '0'));
        $txDate = (string) ($_POST['entry_date'] ?? Helpers::today());
        if (!Helpers::isDate($txDate)) {
            $txDate = Helpers::today();
        }
        if ($amount > 0 && $cid > 0) {
            $repo->addCari('customer', $cid, $txDate, 'alacak', $amount, trim((string) ($_POST['note'] ?? 'Tahsilat')));
            uysa_audit('tahsilat', $u['username'], (string) $cid, json_encode(['t' => $amount]), client_ip());
            $flash = 'Tahsilat kaydedildi · ₺ ' . Helpers::money($amount);
            $month = substr($txDate, 0, 7);
        } else {
            $flash = 'Geçerli tutar girin.';
            $flashOk = false;
        }
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
    $detay = $tedKey !== '' ? $repo->borclarimDetay($tedKey) : null;
    if ($detay === null):
        $liste = $repo->borclarimListe();
        $toplamKalan = 0.0;
        $toplamFatura = 0.0;
        $toplamOdenen = 0.0;
        foreach ($liste as $b) {
            $toplamKalan += $b['kalan'];
            $toplamFatura += $b['fatura'] + $b['devir'];
            $toplamOdenen += $b['odenen'];
        }
?>
        <div class="summary-grid">
          <div class="summary-card"><p class="label">Toplam borç (kalan)</p><p class="metric <?= $toplamKalan > 0 ? 'neg' : '' ?>">₺ <?= Helpers::money($toplamKalan) ?></p></div>
          <div class="summary-card"><p class="label">Ödenen</p><p class="metric">₺ <?= Helpers::money($toplamOdenen) ?></p></div>
          <div class="summary-card wide"><p class="label">Tedarikçi</p><p class="metric"><?= count($liste) ?> firma · fatura+devir ₺ <?= Helpers::money($toplamFatura) ?></p>
            <span class="delta"><i class="bi bi-info-circle"></i> Aydan bağımsız · tüm zaman kümülatif</span></div>
        </div>

        <div class="cardx card-pad">
          <div class="gt-h"><i class="bi bi-arrow-up-right-circle"></i> BORÇLARIM <span class="text-muted" style="font-size:12px;font-weight:600">(tedarikçi bazlı · Personel/Taşıma hariç)</span></div>
          <?php if (!$liste): ?>
            <div class="empty-state">Kayıtlı gider faturası olan tedarikçi yok.</div>
          <?php else: ?>
            <?php foreach ($liste as $b):
                $toplam = $b['fatura'] + $b['devir'];
                $oran = $toplam > 0 ? max(0.0, min(1.0, $b['odenen'] / $toplam)) : ($b['kalan'] <= 0 ? 1.0 : 0.0);
                $w = round($oran * 100);
                $borclu = $b['kalan'] > 0.005;
            ?>
            <a class="gt-kr<?= $borclu ? ' warn' : '' ?>" href="cari.php?sekme=borclarim&ted=<?= rawurlencode($b['key']) ?>" style="display:block">
              <div class="gt-kr-head">
                <div class="gt-rank"><?= Helpers::e(mb_strtoupper(mb_substr((string) $b['label'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
                <div class="gt-kr-firm">
                  <div class="gt-kr-ad"><?= Helpers::e($b['label']) ?></div>
                  <div class="gt-kr-sub">fatura ₺<?= Helpers::money($b['fatura'] + $b['devir']) ?> · ödenen ₺<?= Helpers::money($b['odenen']) ?></div>
                </div>
                <div class="gt-kr-val <?= $borclu ? 'bad' : 'ok' ?>">₺<?= Helpers::money($b['kalan']) ?><small><?= $borclu ? 'kalan' : 'kapandı' ?></small></div>
              </div>
              <div class="gt-bar"><i class="<?= $borclu ? '' : 'bad' ?>" style="width: <?= $borclu ? $w : 100 ?>%"></i></div>
            </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
<?php else: // ── Tedarikçi DETAY ──
    $borclu = $detay['kalan'] > 0.005;
?>
        <div class="segmented">
          <a class="chip" href="cari.php?sekme=borclarim"><i class="bi bi-chevron-left"></i> Tüm tedarikçiler</a>
        </div>

        <div class="summary-grid">
          <div class="summary-card"><p class="label">Toplam fatura<?= $detay['devir'] != 0.0 ? ' + devir' : '' ?></p><p class="metric">₺ <?= Helpers::money($detay['fatura'] + $detay['devir']) ?></p></div>
          <div class="summary-card"><p class="label">Ödenen</p><p class="metric">₺ <?= Helpers::money($detay['odenen']) ?></p></div>
          <div class="summary-card wide"><p class="label"><?= Helpers::e($detay['label']) ?> · KALAN</p>
            <p class="metric <?= $borclu ? 'neg' : '' ?>">₺ <?= Helpers::money($detay['kalan']) ?></p>
            <span class="delta"><i class="bi bi-receipt"></i> <?= (int) $detay['adet'] ?> fatura · aydan bağımsız</span></div>
        </div>

        <div class="cardx card-pad">
          <div class="gt-h"><i class="bi bi-cash-stack"></i> ÖDEME İŞLE</div>
          <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
            <input type="hidden" name="action" value="ted_odeme">
            <input type="hidden" name="ted" value="<?= Helpers::e($detay['key']) ?>">
            <div class="field"><label>Tutar (₺)</label><input class="inputx" name="tutar" inputmode="decimal" placeholder="0,00" required></div>
            <div class="field"><label>Tarih</label><input class="inputx" type="date" name="odeme_tarihi" value="<?= Helpers::e(Helpers::today()) ?>"></div>
            <div class="field"><label>Not</label><input class="inputx" name="note" placeholder="ör. havale"></div>
            <label class="check-row" style="grid-column:1/-1"><input type="checkbox" name="duzeltme" value="1"> Düzeltme (geri alma — negatif kayıt)</label>
            <button class="btn-action btn-primaryx btn-full" type="submit"><i class="bi bi-check2"></i> Ödemeyi işle</button>
          </form>
        </div>

        <div class="cardx card-pad">
          <div class="gt-h"><i class="bi bi-clock-history"></i> ÖDEME GEÇMİŞİ</div>
          <?php if (!$detay['odemeler']): ?>
            <div class="empty-state">Henüz ödeme yok.</div>
          <?php else: ?>
            <table class="tablex">
              <thead><tr><th>Tarih</th><th>Not</th><th class="num">Tutar</th></tr></thead>
              <tbody>
              <?php foreach ($detay['odemeler'] as $o): $neg = (float) $o['tutar'] < 0; ?>
                <tr>
                  <td><?= Helpers::e(date('d.m.Y', strtotime((string) $o['odeme_tarihi']))) ?></td>
                  <td><?= Helpers::e((string) ($o['note'] ?? '') ?: ($neg ? 'Düzeltme' : 'Ödeme')) ?></td>
                  <td class="num" style="color:<?= $neg ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money((float) $o['tutar']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="cardx card-pad">
          <div class="gt-h"><i class="bi bi-receipt"></i> FATURA DÖKÜMÜ <span class="text-muted" style="font-size:12px;font-weight:600">(tüm zaman)</span></div>
          <?php if (!$detay['faturalar']): ?>
            <div class="empty-state">Bu tedarikçide gider faturası yok (yalnız devir/ödeme).</div>
          <?php else: ?>
            <table class="tablex">
              <thead><tr><th>Tarih</th><th>Açıklama</th><th class="num">Tutar</th></tr></thead>
              <tbody>
              <?php foreach ($detay['faturalar'] as $f): ?>
                <tr>
                  <td><?= Helpers::e(date('d.m.y', strtotime($f['tx_date']))) ?></td>
                  <td><?= Helpers::e($f['no'] ?: ($f['kategori'] ?: 'Fatura')) ?></td>
                  <td class="num">₺ <?= Helpers::money($f['amount']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="cardx card-pad">
          <div class="gt-h"><i class="bi bi-pencil-square"></i> DEVİR (AÇILIŞ) BAKİYESİ <span class="text-muted" style="font-size:12px;font-weight:600">(sistemde olmayan eski borç · elle)</span></div>
          <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
            <input type="hidden" name="action" value="ted_devir">
            <input type="hidden" name="ted" value="<?= Helpers::e($detay['key']) ?>">
            <input type="hidden" name="label" value="<?= Helpers::e($detay['label']) ?>">
            <div class="field"><label>Devir bakiyesi (₺)</label><input class="inputx" name="tutar" inputmode="decimal" value="<?= $detay['devir'] != 0.0 ? Helpers::money($detay['devir']) : '' ?>" placeholder="0,00"></div>
            <button class="btn-action btn-full" type="submit"><i class="bi bi-check2"></i> Devri kaydet</button>
          </form>
        </div>
<?php endif; ?>

<?php else: ?>
<?php
    // ── ALACAKLARIM (mevcut müşteri cari/tahsilat — DEĞİŞMEZ; Paraşüt bakiye SALT-OKUMA yan yana) ──
    $cust = $cid ? $repo->customer($cid) : null;
    $balance = $cust ? $repo->customerBalance($cid) : 0.0;
    $statement = $cust ? $repo->customerStatement($cid, $month) : [];
    $ayUretim = $cust ? $repo->customerMonthProduction($cid, $month) : ['persons' => 0, 'amount' => 0.0, 'cnt' => 0];
    $ayKisi = $ayUretim['persons'];
    $net = $cust ? $repo->customerNetKarlilik($cid, $month) : null;
?>
      <?php if (!$cust): ?>
        <div class="empty-state">Müşteri bulunamadı.</div>
      <?php else: ?>
        <div class="summary-grid">
          <div class="summary-card"><p class="label">Birim fiyat</p><p class="metric">₺ <?= Helpers::money((float) $cust['unit_price']) ?></p></div>
          <div class="summary-card"><p class="label">Kokpit bakiye</p><p class="metric <?= $balance < 0 ? 'neg' : '' ?>">₺ <?= Helpers::money($balance) ?></p></div>
          <?php if (($cust['parasut_bakiye'] ?? null) !== null): ?>
          <div class="summary-card tint-blue"><p class="label">Paraşüt bakiyesi (muhasebe)</p>
            <p class="metric <?= (float) $cust['parasut_bakiye'] < 0 ? 'neg' : '' ?>">₺ <?= Helpers::money((float) $cust['parasut_bakiye']) ?></p>
            <span class="delta"><i class="bi bi-shield-check"></i> son senkron <?= $cust['parasut_sync_at'] ? Helpers::e(date('d.m.Y H:i', strtotime((string) $cust['parasut_sync_at']))) : '—' ?></span></div>
          <?php endif; ?>
          <div class="summary-card wide"><p class="label"><?= Helpers::e(ay_label_tr($month)) ?> üretim (faturalanacak)</p>
            <p class="metric"><?= number_format($ayKisi, 0, ',', '.') ?> kişi · ₺ <?= Helpers::money($ayUretim['amount']) ?></p>
            <span class="delta"><i class="bi bi-check2-circle"></i> <?= $balance >= 0 ? 'Alacak (bize borçlu)' : 'Fazla ödeme' ?></span></div>
        </div>

        <div class="segmented">
          <?php foreach (array_slice($customers, 0, 6) as $c): ?>
            <a class="chip <?= $cid === (int) $c['id'] ? 'active' : '' ?>" href="cari.php?musteri=<?= (int) $c['id'] ?>&ay=<?= $month ?>"><?= Helpers::e($c['name']) ?></a>
          <?php endforeach; ?>
        </div>

        <?php if ($net): ?>
        <div class="cardx card-pad">
          <div class="gt-h"><i class="bi bi-cash-coin"></i> NET KÂR <span class="text-muted" style="font-size:12px;font-weight:600">(personel + gider düşülmüş · <?= Helpers::e(ay_label_tr($month)) ?>)</span></div>
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
        </div>
        <?php endif; ?>

        <div class="cardx card-pad">
          <div class="gt-h"><i class="bi bi-receipt"></i> <?= Helpers::e(ay_label_tr($month)) ?> EKSTRESİ</div>
          <?php if (!$statement): ?>
            <div class="empty-state">Bu ay hareket yok.</div>
          <?php else: ?>
            <table class="tablex">
              <thead><tr><th>Tarih</th><th>Açıklama</th><th class="num">Tutar</th></tr></thead>
              <tbody>
              <?php foreach ($statement as $s): $isBorc = $s['direction'] === 'borc'; ?>
                <tr>
                  <td><?= Helpers::e(date('d.m', strtotime($s['entry_date']))) ?></td>
                  <td><?= Helpers::e($s['note'] ?: ($isBorc ? 'Üretim' : 'Tahsilat')) ?></td>
                  <td class="num <?= $isBorc ? '' : 'amount in' ?>">₺ <?= Helpers::money((float) $s['amount']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="cardx card-pad">
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
        </div>
      <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
