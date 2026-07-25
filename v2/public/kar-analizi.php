<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

$u = Auth::requireLogin();
$repo = new Repo(Db::pdo());

$month = (string) ($_GET['ay'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$tab = (string) ($_GET['tab'] ?? 'uretim');
if (!in_array($tab, ['uretim', 'tasima'], true)) {
    $tab = 'uretim';
}

// fable-048 (Ömer): "kâr zararı kestiğim faturalardan yap, gerçek veri olsun".
// VERİ KAYNAĞI seçici — Üretim|Taşıma sekmesinden AYRI eksen; seçim URL'de taşınır.
//   fatura = kesilen satış faturaları (GERÇEK, varsayılan) · uretim = production tahakkuku (eski hesap)
$kaynak = (string) ($_GET['kaynak'] ?? '');
$ka = $repo->karAnaliziKaynak($month, $kaynak !== '' ? $kaynak : null);
$kaynak = (string) $ka['kaynak'];
$fbilgi = $ka['fatura'] ?? null;  // fatura modunda kapsam bilgisi (dürüstlük satırı)
/** Sayfa bağlantılarında ay+sekme+kaynak birlikte taşınır (yenilemede seçim kaybolmasın). */
$qs = static fn(array $ov = []): string => http_build_query(array_merge(
    ['ay' => $month, 'tab' => $tab, 'kaynak' => $kaynak], $ov));

$nk = $repo->netKarlilik($month);
$gida = $repo->gidaCostOzet($month); // fable-039: kişi başı gıda maliyeti (görünüm katmanı)

/** Marjı yüzde göster. */
function marj_pct(float $m): string
{
    return number_format($m * 100, 1, ',', '.') . '%';
}

/** fable-048: '2026-07-19' → '19 Temmuz' (UI Türkçe; date('F') İngilizce basıyordu). */
function f048_gun_tr(string $d): string
{
    $aylar = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
        'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $ts = strtotime($d);
    return $ts === false ? $d : ((int) date('j', $ts)) . ' ' . ($aylar[(int) date('n', $ts)] ?? '');
}

/** fable-044: miktarı sade göster (3 haneye kadar, gereksiz sıfırları at). 1250,000 → 1.250 */
function f044_miktar(float $m): string
{
    $s = number_format($m, 3, ',', '.');
    if (str_contains($s, ',')) {
        $s = rtrim(rtrim($s, '0'), ',');
    }
    return $s;
}

$eyebrow = ay_label_tr($month);
$pageTitle = 'Kâr Analizi';
$active = 'rapor';
require __DIR__ . '/partials/header.php';
?>
<?php
      // fable-034: GTO dili — AYIN NABZI + MÜŞTERİ KARNESİ (mockup birebir)
      $gider = $ka['toplam_gelir'] - $ka['toplam_net'];
      $scalePct = $ka['toplam_gelir'] > 0 ? (int) round($ka['toplam_net'] / $ka['toplam_gelir'] * 100) : 0;
      if ($scalePct < 4 && $ka['toplam_net'] > 0) { $scalePct = 4; }
      if ($scalePct < 0) { $scalePct = 0; }
      // fable-039: karne artık sekmeye göre AYRIK (üretim | taşıma).
      $karne = [];
      if ($tab === 'uretim') {
          foreach ($ka['uretim']['rows'] as $r) {
              $karne[] = ['id' => $r['customer_id'], 'name' => $r['name'], 'gelir' => (float) $r['gelir'], 'net' => (float) $r['net'], 'marj' => (float) $r['marj'], 'tasima' => false];
          }
      } else {
          foreach ($ka['tasima']['rows'] as $r) {
              $karne[] = ['id' => $r['customer_id'], 'name' => $r['name'], 'gelir' => (float) $r['satis'], 'net' => (float) $r['net'], 'marj' => (float) $r['marj'], 'tasima' => true];
          }
      }
      usort($karne, static fn($a, $b) => $b['net'] <=> $a['net']);
      $netMax = 0.0;
      foreach ($karne as $k) { $netMax = max($netMax, abs($k['net'])); }
      $karneCiroToplam = 0.0;
      foreach ($karne as $k) { $karneCiroToplam += $k['gelir']; }
      ?>
      <div class="cardx card-pad">
        <form method="get" class="gt-date">
          <input type="hidden" name="tab" value="<?= Helpers::e($tab) ?>">
          <input type="hidden" name="kaynak" value="<?= Helpers::e($kaynak) ?>">
          <div class="dt" style="position:relative">
            <b><?= Helpers::e(ay_label_tr($month)) ?></b>
            <?php $mtdSpan = $kaynak === 'uretim' ? ay_span_tr($month) : ''; // fable-048: fatura modunda MTD kırpması YOK ?>
            <span><?= $tab === 'tasima' ? 'taşıma kâr/zarar' : 'üretim kâr/zarar + gıda maliyeti' ?><?= $mtdSpan ? ' · ' . Helpers::e($mtdSpan) . ' (ay içi)' : '' ?></span>
            <input type="month" name="ay" value="<?= Helpers::e($month) ?>" onchange="this.form.submit()"
                   aria-label="Ay seç" style="position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer">
          </div>
        </form>
      </div>

      <?php // fable-048 (Ömer): VERİ KAYNAĞI — gerçek fatura mı, üretim tahakkuku mu ?>
      <div class="segmented" style="margin-bottom:8px">
        <a class="chip <?= $kaynak === 'fatura' ? 'active' : '' ?>" href="kar-analizi.php?<?= Helpers::e($qs(['kaynak' => 'fatura'])) ?>"><i class="bi bi-receipt"></i> Fatura (gerçek)</a>
        <a class="chip <?= $kaynak === 'uretim' ? 'active' : '' ?>" href="kar-analizi.php?<?= Helpers::e($qs(['kaynak' => 'uretim'])) ?>"><i class="bi bi-calculator"></i> Üretim (tahakkuk)</a>
      </div>

      <!-- fable-039: Kâr/Zarar → Üretim | Taşıma ayrımı -->
      <div class="segmented" style="margin-bottom:12px">
        <a class="chip <?= $tab === 'uretim' ? 'active' : '' ?>" href="kar-analizi.php?<?= Helpers::e($qs(['tab' => 'uretim'])) ?>">Üretim</a>
        <a class="chip <?= $tab === 'tasima' ? 'active' : '' ?>" href="kar-analizi.php?<?= Helpers::e($qs(['tab' => 'tasima'])) ?>">Taşıma</a>
      </div>

      <?php if (!empty($ka['fatura_devre_disi'])): // migrate_047 uygulanmadı → tahakkuka düşüldü, gizlenmiyor ?>
      <div class="cardx card-pad" style="padding-block:10px">
        <div style="font-size:12.5px;font-weight:600;color:var(--red)"><i class="bi bi-exclamation-triangle-fill"></i> Fatura bazlı kâr/zarar henüz kurulmadı (satış faturası tablosu yok).</div>
        <div class="row-meta" style="margin-top:4px">Aşağıdaki rakamlar ÜRETİM (tahakkuk) hesabıdır. Kurulum: <code>sql/migrate_047.sql</code> + <code>tools/parasut_satis_sync.php</code>.</div>
      </div>
      <?php endif; ?>

      <?php if ($kaynak === 'fatura'): // fable-048: FATURA KAPSAMI — eksikliği açıkça yaz (veri güvenilirliği) ?>
      <div class="cardx card-pad" style="padding-block:10px">
        <?php if ($fbilgi && $fbilgi['adet'] > 0): ?>
          <div style="font-size:12.5px;font-weight:600">
            <i class="bi bi-receipt" style="color:var(--primary)"></i>
            <?= (int) $fbilgi['adet'] ?> fatura · son fatura <?= Helpers::e(date('d.m.Y', strtotime((string) $fbilgi['son_fatura']))) ?>
            · kapsanan dönem 1–<?= Helpers::e(f048_gun_tr((string) $fbilgi['kapsam_son'])) ?>
          </div>
          <?php if ($fbilgi['uyari']): ?>
          <div class="row-meta" style="margin-top:5px;color:var(--red)">
            <i class="bi bi-info-circle-fill"></i> <?= (int) $fbilgi['gecikme_gun'] ?> gündür fatura kesilmemiş —
            <?= Helpers::e(date('d.m.Y', strtotime((string) $fbilgi['kapsam_son']))) ?> sonrası henüz faturalanmadı, bu ekrandaki gelir O KADARINI gösterir.
          </div>
          <?php endif; ?>
          <?php if ($fbilgi['eslesmemis_adet'] > 0): ?>
          <div class="row-meta" style="margin-top:5px">
            <i class="bi bi-question-circle"></i> <?= (int) $fbilgi['eslesmemis_adet'] ?> fatura Kokpit müşterisiyle eşleşmedi
            (₺<?= Helpers::money((float) $fbilgi['eslesmemis_net']) ?>) — aşağıda "eşleşmemiş gelir" olarak ayrı duruyor:
            <?php $adlar = [];
            foreach (array_slice($fbilgi['eslesmemis'], 0, 4) as $e) { $adlar[] = $e['ad'] . ' ₺' . Helpers::money((float) $e['net']); }
            echo Helpers::e(implode(' · ', $adlar));
            if (count($fbilgi['eslesmemis']) > 4) { echo ' · +' . (count($fbilgi['eslesmemis']) - 4); } ?>
          </div>
          <?php endif; ?>
        <?php else: ?>
          <div style="font-size:12.5px;font-weight:600"><i class="bi bi-exclamation-triangle" style="color:var(--red)"></i> Bu ay için kayıtlı satış faturası yok.</div>
          <div class="row-meta" style="margin-top:4px">Paraşüt satış senkronu henüz çalışmadıysa gelir boş görünür — gerçek rakam için
            <a href="kar-analizi.php?<?= Helpers::e($qs(['kaynak' => 'uretim'])) ?>" style="text-decoration:underline">Üretim (tahakkuk)</a> moduna bak.</div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-broadcast"></i> AYIN NABZI</div>
        <div class="gt-pulse">
          <div class="gt-pulse-n <?= $ka['toplam_net'] < 0 ? 'bad' : 'ok' ?>">₺<?= Helpers::money($ka['toplam_net']) ?></div>
          <div class="gt-pulse-l">net kâr · marj <?= marj_pct($ka['toplam_marj']) ?></div>
        </div>
        <div class="gt-scale">
          <div class="row"><span class="gl">Gelir ₺<?= Helpers::money($ka['toplam_gelir']) ?></span><span class="gd">Gider ₺<?= Helpers::money($gider) ?></span></div>
          <div class="gt-track deficit"><div class="gt-fill left" style="width: <?= $scalePct ?>%"></div></div>
        </div>
        <div class="gt-mini">
          <div><div class="gt-mn <?= $ka['uretim']['net'] < 0 ? 'bad' : 'ok' ?>">₺<?= number_format(round($ka['uretim']['net']), 0, ',', '.') ?></div><div class="gt-ml">Üretim kârı</div></div>
          <div><div class="gt-mn <?= $ka['tasima']['net'] < 0 ? 'bad' : 'ok' ?>">₺<?= number_format(round($ka['tasima']['net']), 0, ',', '.') ?></div><div class="gt-ml">Taşıma kârı</div></div>
          <div><div class="gt-mn"><?= marj_pct($ka['toplam_marj']) ?></div><div class="gt-ml">Toplam marj</div></div>
        </div>
      </div>

      <?php if ($tab === 'uretim'): ?>
      <?php // fable-039: KİŞİ BAŞI GIDA MALİYETİ — büyük rakam + tıklayınca kırılım ?>
      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-basket3-fill"></i> KİŞİ BAŞI GIDA MALİYETİ</div>
        <div class="gt-pulse tap-card" role="button" tabindex="0" onclick="toggleGida()" style="cursor:<?= $gida['kirilimlar'] ? 'pointer' : 'default' ?>">
          <div class="gt-pulse-n"><?= $gida['kisi_basi'] > 0 ? '₺' . Helpers::money($gida['kisi_basi']) : '—' ?></div>
          <div class="gt-pulse-l">1 kişilik gıda maliyeti · gıda alımları ₺<?= Helpers::money($gida['toplam']) ?> · üretim <?= number_format($gida['kisi_toplam'], 0, ',', '.') ?> kişi<?= $gida['kirilimlar'] ? ' <i class="bi bi-chevron-down chev"></i>' : '' ?></div>
        </div>
        <?php if ($gida['kirilimlar']): ?>
        <div id="gida-kirilim" style="display:none;margin-top:6px">
          <?php foreach ($gida['kirilimlar'] as $ki => $g): $w = max(4, (int) round($g['oran'] * 100));
            // fable-044: bu kırılımda en çok para harcanan ürün kalemleri (top-10 + birim fiyat).
            $uo = $repo->kirilimUrunOzet($month, $g['kod']);
            $krId = 'kir-' . $ki; ?>
            <div class="gt-kr gt-kr-exp" role="button" tabindex="0" onclick="toggleKir('<?= $krId ?>')" style="cursor:pointer">
              <div class="gt-kr-head">
                <div class="gt-kr-firm">
                  <div class="gt-kr-ad"><?= Helpers::e($g['ad']) ?> <i class="bi bi-chevron-down chev" style="font-size:.72em;opacity:.6"></i></div>
                  <div class="gt-kr-sub">kişi başı ₺<?= Helpers::money($g['kisi_basi']) ?></div>
                </div>
                <div class="gt-kr-val bad">₺<?= Helpers::money($g['tutar']) ?><small><?= number_format($g['oran'] * 100, 1, ',', '.') ?>%</small></div>
              </div>
              <div class="gt-bar"><i class="bad" style="width: <?= $w ?>%"></i></div>
              <div id="<?= $krId ?>" class="gt-kr-detay" style="display:none" onclick="event.stopPropagation()">
                <?php if ($uo['urunler']): ?>
                  <?php foreach ($uo['urunler'] as $ui => $p): ?>
                  <div class="uk-row">
                    <div class="uk-firm">
                      <div class="uk-ad"><span class="uk-no"><?= $ui + 1 ?>.</span> <?= Helpers::e($p['urun']) ?></div>
                      <div class="uk-sub"><?php
                        $parts = [];
                        if ($p['miktar'] !== null) {
                            $parts[] = f044_miktar((float) $p['miktar']) . ($p['birim'] ? ' ' . Helpers::e($p['birim']) : '');
                        }
                        if ($p['ort_birim_fiyat'] !== null) {
                            $parts[] = 'ort ₺' . Helpers::money((float) $p['ort_birim_fiyat']) . ($p['birim'] ? '/' . Helpers::e($p['birim']) : '');
                        }
                        $parts[] = $p['fatura_adedi'] . ' fatura';
                        echo implode(' · ', $parts);
                      ?></div>
                    </div>
                    <div class="uk-val">₺<?= Helpers::money((float) $p['tutar']) ?></div>
                  </div>
                  <?php endforeach; ?>
                  <?php if ($uo['urun_sayisi'] > count($uo['urunler'])): ?>
                  <div class="uk-more">+<?= $uo['urun_sayisi'] - count($uo['urunler']) ?> ürün daha (en çok harcanan <?= count($uo['urunler']) ?> gösteriliyor)</div>
                  <?php endif; ?>
                  <?php if ($uo['kapsanmayan'] > 0.5): ?>
                  <div class="uk-row uk-kaps">
                    <div class="uk-firm"><div class="uk-ad">Kapsanmayan</div><div class="uk-sub">satır detayı çekilemeyen fatura + KDV</div></div>
                    <div class="uk-val">₺<?= Helpers::money((float) $uo['kapsanmayan']) ?></div>
                  </div>
                  <?php endif; ?>
                <?php else: ?>
                  <div class="uk-empty">Satır detayı yok — bu kırılımın faturaları henüz kalem bazında çekilmedi (₺<?= Helpers::money((float) $uo['toplam']) ?> toplam).</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <div class="gt-note">gıda kırılımları · satıra dokun → en çok para harcanan ürünler · tedarikçi eşlemesi <a href="tedarikci-eslestirme.php" style="text-decoration:underline">Maliyet eşleştirme</a>'den değişir</div>
        </div>
        <style>
          .gt-kr-detay{margin-top:8px;padding-top:8px;border-top:1px dashed var(--line-2)}
          .uk-row{display:flex;align-items:flex-start;gap:8px;padding:5px 0;border-bottom:1px solid var(--line)}
          .uk-row:last-child{border-bottom:0}
          .uk-firm{flex:1;min-width:0}
          .uk-ad{font-size:13px;font-weight:600;word-break:break-word}
          .uk-no{opacity:.5;font-weight:400}
          .uk-sub{font-size:11px;opacity:.7;margin-top:1px}
          .uk-val{font-size:13px;font-weight:700;white-space:nowrap;flex-shrink:0}
          .uk-kaps .uk-ad,.uk-kaps .uk-val{opacity:.6;font-weight:600}
          .uk-more,.uk-empty{font-size:11px;opacity:.65;padding:6px 0 2px}
        </style>
        <?php else: ?>
        <div class="gt-note">Gıda kırılımı için tedarikçi eşlemesi yok. <a href="tedarikci-eslestirme.php" style="text-decoration:underline">Maliyet eşleştirme</a>'den ayarla.</div>
        <?php endif; ?>
      </div>

      <?php // fable-042 ek: KİŞİ BAŞI PERSONEL MALİYETİ — gıda kartıyla aynı desen, tek kalem (kırılım yok) ?>
      <?php $pc = $repo->personelCostOzetUretim($month); ?>
      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-people-fill"></i> KİŞİ BAŞI PERSONEL MALİYETİ</div>
        <div class="gt-pulse">
          <div class="gt-pulse-n"><?= $pc['kisi_basi'] > 0 ? '₺' . Helpers::money($pc['kisi_basi']) : '—' ?></div>
          <div class="gt-pulse-l">personel (işveren maliyeti) ₺<?= Helpers::money($pc['toplam']) ?> · üretim <?= number_format($pc['kisi_toplam'], 0, ',', '.') ?> kişi</div>
        </div>
      </div>

      <?php if ($karne): ?>
      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-clipboard-data"></i> MÜŞTERİ KARNESİ</div>
        <?php foreach ($karne as $k): $w = $netMax > 0 ? max(4, (int) round(abs($k['net']) / $netMax * 100)) : 4; $bad = $k['net'] < 0; ?>
          <a class="gt-kr<?= $bad ? ' warn' : '' ?>" href="rapor.php?musteri=<?= (int) $k['id'] ?>&ay=<?= $month ?>" style="display:block">
            <div class="gt-kr-head">
              <div class="gt-rank"><?= Helpers::e(mb_strtoupper(mb_substr($k['name'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
              <div class="gt-kr-firm">
                <div class="gt-kr-ad"><?= Helpers::e($k['name']) ?></div>
                <div class="gt-kr-sub">gelir ₺<?= Helpers::money($k['gelir']) ?><?= $k['tasima'] ? ' · taşıma' : '' ?></div>
              </div>
              <div class="gt-kr-val <?= $bad ? 'bad' : 'ok' ?>"><?= $bad ? '−' : '' ?>₺<?= Helpers::money(abs($k['net'])) ?><small>marj <?= marj_pct($k['marj']) ?></small></div>
            </div>
            <div class="gt-bar"><i class="<?= $bad ? 'bad' : '' ?>" style="width: <?= $w ?>%"></i></div>
          </a>
        <?php endforeach; ?>
        <?php // fable-039: karnenin EN ALTINA toplam ciro satırı (taşıma bölümündeki toplam kalıbı) ?>
        <div class="gt-kr" style="border-top:2px solid var(--line-2)">
          <div class="gt-kr-head">
            <div class="gt-kr-firm"><div class="gt-kr-ad">Toplam ciro</div></div>
            <div class="gt-kr-val">₺<?= Helpers::money($karneCiroToplam) ?></div>
          </div>
        </div>
        <div class="gt-note">satıra dokun → o müşterinin aylık dökümü açılır</div>
      </div>
      <?php endif; ?>

      <!-- ÜRETİM P&L -->
      <div class="section-head"><h2>Üretim</h2><span class="text-muted" style="font-size:12px"><?= $kaynak === 'fatura' ? 'fatura geliri' : 'gelir' ?> − gider − personel = net</span></div>
      <div class="cardx card-pad">
        <?php if (!$ka['uretim']['rows']): ?>
          <div class="empty-state"><?= $kaynak === 'fatura' ? 'Bu ay kesilmiş satış faturası yok.' : 'Bu ay üretim kaydı yok.' ?></div>
        <?php else: ?>
          <div style="overflow-x:auto">
          <table class="tablex">
            <thead><tr><th>Müşteri</th><th class="num">Gelir</th><th class="num">Gider payı</th><th class="num">Personel payı</th><th class="num">Net</th><th class="num">Marj</th></tr></thead>
            <tbody>
            <?php foreach ($ka['uretim']['rows'] as $r): ?>
              <tr>
                <td><a href="rapor.php?musteri=<?= (int) $r['customer_id'] ?>&ay=<?= $month ?>" style="color:var(--primary);font-weight:700"><?= Helpers::e($r['name']) ?></a><?php
                  // fable-040: fatura kişi kuralı olan müşteride "üretim · fatura" rozeti (gelir fatura kişisinden)
                  if (($r['fatura_kisi'] ?? null) !== null): ?><span class="text-muted" style="display:block;font-size:11px;font-weight:600"><?= $r['uretim_gunluk'] !== null ? (int) $r['uretim_gunluk'] : '—' ?> üretim · <?= (int) $r['fatura_kisi'] ?> fatura <span style="opacity:.7">(hafta içi/gün)</span></span><?php endif; ?>
                  <?php // fable-048: fatura modunda gelirin kaç faturadan geldiği (0 = bu ay hiç faturası yok)
                  if ($kaynak === 'fatura'): ?><span class="text-muted" style="display:block;font-size:11px;font-weight:600"><?= (int) ($r['fatura_adedi'] ?? 0) ?> fatura<?= (int) ($r['fatura_adedi'] ?? 0) === 0 ? ' · henüz kesilmedi' : '' ?></span><?php endif; ?></td>
                <td class="num">₺ <?= Helpers::money($r['gelir']) ?></td>
                <td class="num">− ₺ <?= Helpers::money($r['gider']) ?></td>
                <td class="num">− ₺ <?= Helpers::money($r['personel']) ?></td>
                <td class="num" style="color:<?= $r['net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($r['net']) ?></td>
                <td class="num"><?= marj_pct($r['marj']) ?></td>
              </tr>
            <?php endforeach; ?>
              <tr class="is-total">
                <td>Üretim toplam</td>
                <td class="num">₺ <?= Helpers::money($ka['uretim']['gelir']) ?></td>
                <td class="num">− ₺ <?= Helpers::money($ka['uretim']['gider']) ?></td>
                <td class="num">− ₺ <?= Helpers::money($ka['uretim']['personel']) ?></td>
                <td class="num" style="color:<?= $ka['uretim']['net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($ka['uretim']['net']) ?></td>
                <td class="num"><?= marj_pct($ka['uretim']['marj']) ?></td>
              </tr>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; // tab uretim ?>

      <!-- TAŞIMA P&L -->
      <?php if ($tab === 'tasima'): ?>
      <?php if (!$ka['tasima']['rows']): ?>
        <div class="cardx card-pad"><div class="empty-state">Bu ay taşıma kaydı yok.</div></div>
      <?php else: ?>
      <div class="section-head"><h2>Taşıma</h2><span class="text-muted" style="font-size:12px">satış − alış − sabit − gider − personel = net</span></div>
      <div class="cardx card-pad">
        <div style="overflow-x:auto">
        <table class="tablex">
          <thead><tr><th>Müşteri</th><th class="num">Satış</th><th class="num">Alış</th><th class="num">Sabit</th><th class="num">Gider</th><th class="num">Personel</th><th class="num">Net</th><th class="num">Marj</th></tr></thead>
          <tbody>
          <?php foreach ($ka['tasima']['rows'] as $r): ?>
            <tr>
              <td><a href="rapor.php?musteri=<?= (int) $r['customer_id'] ?>&ay=<?= $month ?>" style="color:var(--primary);font-weight:700"><?= Helpers::e($r['name']) ?></a></td>
              <td class="num">₺ <?= Helpers::money($r['satis']) ?></td>
              <td class="num">− ₺ <?= Helpers::money($r['alis']) ?></td>
              <td class="num">− ₺ <?= Helpers::money($r['sabit']) ?></td>
              <td class="num">− ₺ <?= Helpers::money($r['gider']) ?></td>
              <td class="num">− ₺ <?= Helpers::money($r['personel']) ?></td>
              <td class="num" style="color:<?= $r['net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($r['net']) ?></td>
              <td class="num"><?= marj_pct($r['marj']) ?></td>
            </tr>
          <?php endforeach; ?>
            <tr class="is-total">
              <td>Taşıma toplam</td>
              <td class="num">₺ <?= Helpers::money($ka['tasima']['satis']) ?></td>
              <td class="num">− ₺ <?= Helpers::money($ka['tasima']['alis']) ?></td>
              <td class="num">− ₺ <?= Helpers::money($ka['tasima']['sabit']) ?></td>
              <td class="num">− ₺ <?= Helpers::money($ka['tasima']['gider']) ?></td>
              <td class="num">− ₺ <?= Helpers::money($ka['tasima']['personel']) ?></td>
              <td class="num" style="color:<?= $ka['tasima']['net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($ka['tasima']['net']) ?></td>
              <td class="num"><?= marj_pct($ka['tasima']['marj']) ?></td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>
      <?php endif; // tasima rows ?>
      <?php endif; // tab tasima ?>

      <!-- TOPLAM -->
      <div class="cardx card-pad">
        <h2>Toplam net kâr</h2>
        <table class="tablex">
          <tbody>
            <tr><td>Üretim net</td><td class="num" style="color:<?= $ka['uretim']['net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($ka['uretim']['net']) ?></td></tr>
            <tr><td>Taşıma net</td><td class="num" style="color:<?= $ka['tasima']['net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($ka['tasima']['net']) ?></td></tr>
            <?php // fable-048: müşterisi eşleşmeyen faturalar hiçbir müşteriye karışmaz — AYRI satır ?>
            <?php if (($ka['eslesmemis_gelir'] ?? 0) > 0): ?>
            <tr><td>Eşleşmemiş gelir (Kokpit müşterisi olmayan cariler)</td><td class="num" style="color:var(--green)">+ ₺ <?= Helpers::money((float) $ka['eslesmemis_gelir']) ?></td></tr>
            <?php endif; ?>
            <?php if ($ka['dagitilmamis'] > 0): ?>
            <tr><td>Dağıtılmamış (atanmamış personel / genel gider)</td><td class="num">− ₺ <?= Helpers::money($ka['dagitilmamis']) ?></td></tr>
            <?php endif; ?>
            <tr class="is-total"><td>Toplam net kâr</td><td class="num" style="color:<?= $ka['toplam_net'] < 0 ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($ka['toplam_net']) ?> · <?= marj_pct($ka['toplam_marj']) ?></td></tr>
          </tbody>
        </table>
        <?php if ($kaynak === 'uretim'): ?>
        <p class="row-meta" style="margin-top:8px"><i class="bi bi-check2-circle"></i> Finans net karlılık ile birebir: ₺ <?= Helpers::money($nk['net']) ?></p>
        <?php else: ?>
        <p class="row-meta" style="margin-top:8px"><i class="bi bi-receipt"></i> Gelir = bu ay <strong>kesilen satış faturaları</strong> (KDV hariç);
          gider tarafı bize kesilen faturalar. Tahakkuk (üretim) hesabı:
          <a href="kar-analizi.php?<?= Helpers::e($qs(['kaynak' => 'uretim'])) ?>" style="text-decoration:underline">₺ <?= Helpers::money($nk['net']) ?></a>
          — fark, henüz faturalanmamış üretimden gelir.</p>
        <?php endif; ?>
      </div>
      <script>
        // fable-039: gıda maliyeti kartı → kırılım aç/kapat
        function toggleGida(){
          var el = document.getElementById('gida-kirilim');
          if (!el) return;
          el.style.display = el.style.display === 'none' ? '' : 'none';
          var card = document.querySelector('[onclick="toggleGida()"]');
          if (card) card.classList.toggle('open', el.style.display !== 'none');
        }
        // fable-044: kırılım satırı → ürün kalemi listesi aç/kapat
        function toggleKir(id){
          var el = document.getElementById(id);
          if (!el) return;
          var open = el.style.display === 'none';
          el.style.display = open ? '' : 'none';
          var row = el.closest('.gt-kr-exp');
          if (row) row.classList.toggle('open', open);
        }
      </script>
<?php require __DIR__ . '/partials/footer.php'; ?>
