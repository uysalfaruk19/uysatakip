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

// fable-075 (Ömer, 14 Ağu): karne TÜM müşterileri kapsar; 'tumu' varsayılan.
$tab = (string) ($_GET['tab'] ?? 'tumu');
if (!in_array($tab, ['tumu', 'uretim', 'tasima'], true)) {
    $tab = 'tumu';
}

// fable-048 (Ömer): "kâr zararı kestiğim faturalardan yap, gerçek veri olsun".
// VERİ KAYNAĞI seçici — Üretim|Taşıma sekmesinden AYRI eksen; seçim URL'de taşınır.
//   fatura = kesilen satış faturaları (GERÇEK, varsayılan) · uretim = production tahakkuku (eski hesap)
$kaynak = (string) ($_GET['kaynak'] ?? '');
// fable-070 (Ömer): aylar arası geçiş Bugün ekranındaki gün geçişi gibi ‹ › oklarıyla.
$prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));

// fable-078 (Ömer, 14 Ağu): varsayılan İLGİLİ AY; yanındaki butonla YILLIK toplam.
// Yıllık, karAnaliziYil ile AYNI yapıyı döndürdüğü için ekran aynı bileşenlerle çizilir.
$donem = ($_GET['donem'] ?? '') === 'yil' ? 'yil' : 'ay';
$yil = substr($month, 0, 4);
$prevYil = (string) ((int) $yil - 1);
$nextYil = (string) ((int) $yil + 1);

$ka = $donem === 'yil'
    ? $repo->karAnaliziYil($yil, $kaynak !== '' ? $kaynak : null)
    : $repo->karAnaliziKaynak($month, $kaynak !== '' ? $kaynak : null);
$kaynak = (string) $ka['kaynak'];
$fbilgi = $ka['fatura'] ?? null;  // fatura modunda kapsam bilgisi (dürüstlük satırı) — yıllıkta null
/** Sayfa bağlantılarında ay+sekme+kaynak+dönem birlikte taşınır (yenilemede seçim kaybolmasın). */
$qs = static fn(array $ov = []): string => http_build_query(array_merge(
    ['ay' => $month, 'tab' => $tab, 'kaynak' => $kaynak, 'donem' => $donem], $ov));

// Kişi başı maliyet kartları AYLIK kavramdır (o ayın kişi sayısına bölünür) — yıllıkta gösterilmez.
$nk = $donem === 'yil' ? ['net' => $ka['toplam_net']] : $repo->netKarlilik($month);
$gida = $donem === 'yil' ? null : $repo->gidaCostOzet($month); // fable-039: kişi başı gıda maliyeti

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

// fable-078: yıllık moddayken üst başlıkta ay adı yazmaz (ekran o ayı göstermiyor).
$eyebrow = $donem === 'yil' ? $yil . ' yılı' : ay_label_tr($month);
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
      // fable-075 (Ömer, 14 Ağu): karne ARTIK TÜM MÜŞTERİLER — üretim + taşıma tek listede.
      // Ayrı "Üretim"/"Taşıma" P&L tabloları KALDIRILDI (karneyle birebir aynı veriydi);
      // kırılım her satırın kendi açılır özet panelinde. Yeni müşteri karneye KENDİLİĞİNDEN
      // düşer — liste karAnalizi satırlarından türer, elle ekleme yoktur.
      $karne = [];
      if ($tab === 'tumu' || $tab === 'uretim') {
          foreach ($ka['uretim']['rows'] as $r) {
              $karne[] = ['id' => $r['customer_id'], 'name' => $r['name'], 'gelir' => (float) $r['gelir'],
                  'net' => (float) $r['net'], 'marj' => (float) $r['marj'], 'tasima' => false,
                  'gider' => (float) $r['gider'], 'personel' => (float) $r['personel'],
                  'fatura_adedi' => (int) ($r['fatura_adedi'] ?? 0)];
          }
      }
      if ($tab === 'tumu' || $tab === 'tasima') {
          foreach ($ka['tasima']['rows'] as $r) {
              $karne[] = ['id' => $r['customer_id'], 'name' => $r['name'], 'gelir' => (float) $r['satis'],
                  'net' => (float) $r['net'], 'marj' => (float) $r['marj'], 'tasima' => true,
                  'alis' => (float) $r['alis'], 'sabit' => (float) $r['sabit'],
                  'gider' => (float) $r['gider'], 'personel' => (float) $r['personel'],
                  'fatura_adedi' => (int) ($r['fatura_adedi'] ?? 0)];
          }
      }
      usort($karne, static fn($a, $b) => $b['net'] <=> $a['net']);
      $netMax = 0.0;
      foreach ($karne as $k) { $netMax = max($netMax, abs($k['net'])); }
      $karneCiroToplam = 0.0;
      foreach ($karne as $k) { $karneCiroToplam += $k['gelir']; }
      ?>
      <div class="cardx card-pad">
        <?php // fable-078: AY / YIL ekseni. Yıl modunda ‹ › YIL gezinir, ay seçici kapanır. ?>
        <div class="gt-date">
          <a class="nav" href="kar-analizi.php?<?= Helpers::e($donem === 'yil'
              ? $qs(['ay' => $prevYil . substr($month, 4)]) : $qs(['ay' => $prevMonth])) ?>"
             aria-label="<?= $donem === 'yil' ? 'Önceki yıl' : 'Önceki ay' ?>">‹</a>
          <?php if ($donem === 'yil'): ?>
          <div class="dt">
            <b><?= Helpers::e($yil) ?></b>
            <span>yıllık kâr/zarar · <?= (int) $ka['aylar'] ?> ay<?= $yil === date('Y') ? ' (yıl başından bugüne)' : '' ?></span>
          </div>
          <?php else: ?>
          <form method="get" class="dt" style="position:relative">
            <input type="hidden" name="tab" value="<?= Helpers::e($tab) ?>">
            <input type="hidden" name="kaynak" value="<?= Helpers::e($kaynak) ?>">
            <input type="hidden" name="donem" value="ay">
            <b><?= Helpers::e(ay_label_tr($month)) ?></b>
            <?php $mtdSpan = $kaynak === 'uretim' ? ay_span_tr($month) : ''; // fable-048: fatura modunda MTD kırpması YOK ?>
            <span><?= $tab === 'tasima' ? 'taşıma kâr/zarar' : ($tab === 'uretim' ? 'üretim kâr/zarar + gıda maliyeti' : 'tüm müşteriler · kâr/zarar') ?><?= $mtdSpan ? ' · ' . Helpers::e($mtdSpan) . ' (ay içi)' : '' ?></span>
            <input type="month" name="ay" value="<?= Helpers::e($month) ?>" onchange="this.form.submit()"
                   aria-label="Ay seç" style="position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer">
          </form>
          <?php endif; ?>
          <a class="nav" href="kar-analizi.php?<?= Helpers::e($donem === 'yil'
              ? $qs(['ay' => $nextYil . substr($month, 4)]) : $qs(['ay' => $nextMonth])) ?>"
             aria-label="<?= $donem === 'yil' ? 'Sonraki yıl' : 'Sonraki ay' ?>">›</a>
        </div>
        <div class="segmented" style="margin-top:10px;margin-bottom:0">
          <a class="chip <?= $donem === 'ay' ? 'active' : '' ?>" href="kar-analizi.php?<?= Helpers::e($qs(['donem' => 'ay'])) ?>"><i class="bi bi-calendar3"></i> Aylık</a>
          <a class="chip <?= $donem === 'yil' ? 'active' : '' ?>" href="kar-analizi.php?<?= Helpers::e($qs(['donem' => 'yil'])) ?>"><i class="bi bi-calendar-range"></i> Yıllık</a>
        </div>
      </div>

      <?php // fable-048 (Ömer): VERİ KAYNAĞI — gerçek fatura mı, üretim tahakkuku mu ?>
      <div class="segmented" style="margin-bottom:8px">
        <a class="chip <?= $kaynak === 'fatura' ? 'active' : '' ?>" href="kar-analizi.php?<?= Helpers::e($qs(['kaynak' => 'fatura'])) ?>"><i class="bi bi-receipt"></i> Fatura (gerçek)</a>
        <a class="chip <?= $kaynak === 'uretim' ? 'active' : '' ?>" href="kar-analizi.php?<?= Helpers::e($qs(['kaynak' => 'uretim'])) ?>"><i class="bi bi-calculator"></i> Üretim (tahakkuk)</a>
      </div>

      <!-- fable-039: Kâr/Zarar → Üretim | Taşıma ayrımı -->
      <div class="segmented" style="margin-bottom:12px">
        <a class="chip <?= $tab === 'tumu' ? 'active' : '' ?>" href="kar-analizi.php?<?= Helpers::e($qs(['tab' => 'tumu'])) ?>">Tümü</a>
        <a class="chip <?= $tab === 'uretim' ? 'active' : '' ?>" href="kar-analizi.php?<?= Helpers::e($qs(['tab' => 'uretim'])) ?>">Üretim</a>
        <a class="chip <?= $tab === 'tasima' ? 'active' : '' ?>" href="kar-analizi.php?<?= Helpers::e($qs(['tab' => 'tasima'])) ?>">Taşıma</a>
      </div>

      <?php if (!empty($ka['fatura_devre_disi'])): // migrate_047 uygulanmadı → tahakkuka düşüldü, gizlenmiyor ?>
      <div class="cardx card-pad" style="padding-block:10px">
        <div style="font-size:12.5px;font-weight:600;color:var(--red)"><i class="bi bi-exclamation-triangle-fill"></i> Fatura bazlı kâr/zarar henüz kurulmadı (satış faturası tablosu yok).</div>
        <div class="row-meta" style="margin-top:4px">Aşağıdaki rakamlar ÜRETİM (tahakkuk) hesabıdır. Kurulum: <code>sql/migrate_047.sql</code> + <code>tools/parasut_satis_sync.php</code>.</div>
      </div>
      <?php endif; ?>

      <?php // fable-078: kapsam/gecikme AYLIK bilgidir (son fatura, kapsanan gün) —
           // yıllık toplamda yanıltır, gösterilmez. ?>
      <?php if ($donem === 'ay' && $kaynak === 'fatura'): // fable-048: FATURA KAPSAMI — eksikliği açıkça yaz (veri güvenilirliği) ?>
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
          <?php // fable-048c (Fable): faturası henüz kesilmemiş müşteri hesaba GİRMEZ — gizlemek
                // yerine burada bildirilir (yoksa "kârım neden düşük" sorusu cevapsız kalır). ?>
          <?php $fsz = $ka['faturasiz_musteri'] ?? []; if ($fsz): ?>
          <div class="row-meta" style="margin-top:5px">
            <i class="bi bi-hourglass-split"></i> <?= count($fsz) ?> müşteri bu ay HENÜZ faturalanmadı —
            hesaba katılmadı (ne geliri ne maliyeti):
            <?php $fa = [];
            foreach (array_slice($fsz, 0, 4) as $m) { $fa[] = $m['name'] . ' (maliyet ₺' . Helpers::money((float) $m['maliyet']) . ')'; }
            echo Helpers::e(implode(' · ', $fa));
            if (count($fsz) > 4) { echo ' · +' . (count($fsz) - 4); } ?>
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
        <div class="gt-h"><i class="bi bi-broadcast"></i> <?= $donem === 'yil' ? 'YILIN NABZI' : 'AYIN NABZI' ?></div>
        <div class="gt-pulse">
          <div class="gt-pulse-n <?= $ka['toplam_net'] < 0 ? 'bad' : 'ok' ?>">₺<?= Helpers::money($ka['toplam_net']) ?></div>
          <div class="gt-pulse-l">net kâr · marj <?= marj_pct($ka['toplam_marj']) ?></div>
        </div>
        <div class="gt-scale">
          <?php // fable-083 (Ömer, 15 Ağu): "gelir ve gidere tıklayınca nabzın detayları açılsın."
                // Gelir NEDEN o kadar, gider NEYDEN oluşuyor — rakamın kaynağı tek tıkla görünür. ?>
          <div class="row">
            <span class="gl tap-rakam" role="button" tabindex="0" onclick="toggleKart('nabiz-gelir')"
                  style="cursor:pointer">Gelir ₺<?= Helpers::money($ka['toplam_gelir']) ?> <i class="bi bi-chevron-down" style="font-size:10px"></i></span>
            <span class="gd tap-rakam" role="button" tabindex="0" onclick="toggleKart('nabiz-gider')"
                  style="cursor:pointer">Gider ₺<?= Helpers::money($gider) ?> <i class="bi bi-chevron-down" style="font-size:10px"></i></span>
          </div>
          <div class="gt-track deficit"><div class="gt-fill left" style="width: <?= $scalePct ?>%"></div></div>
        </div>

        <div id="nabiz-gelir-detay" style="display:none;margin-top:10px">
          <table class="tablex"><tbody>
            <tr><td>Üretim müşterilerine kesilen faturalar</td><td class="num">₺ <?= Helpers::money($ka['uretim']['gelir']) ?></td></tr>
            <tr><td>Taşıma müşterilerine kesilen faturalar</td><td class="num">₺ <?= Helpers::money($ka['tasima']['satis']) ?></td></tr>
            <?php if (($ka['eslesmemis_gelir'] ?? 0) > 0): ?>
            <tr><td>Kokpit müşterisi olmayan carilere kesilen</td><td class="num">₺ <?= Helpers::money((float) $ka['eslesmemis_gelir']) ?></td></tr>
            <?php endif; ?>
            <tr style="border-top:2px solid var(--line-2)"><td><strong>Toplam gelir</strong></td>
              <td class="num"><strong>₺ <?= Helpers::money($ka['toplam_gelir']) ?></strong></td></tr>
          </tbody></table>
          <p class="row-meta" style="margin-top:6px"><i class="bi bi-receipt"></i>
            <?= $kaynak === 'fatura'
                ? 'Kaynak: <strong>bizim kestiğimiz satış faturaları</strong> (KDV hariç), faturanın kapsadığı döneme göre.'
                : 'Kaynak: <strong>üretim tahakkuku</strong> (kişi × fiyat) — henüz faturalanmamışlar da dahil.' ?></p>
        </div>

        <div id="nabiz-gider-detay" style="display:none;margin-top:10px">
          <?php // Gider = gelir − net. Kalemleri karnedeki paylarla BİREBİR aynı kaynaktan gelir. ?>
          <table class="tablex"><tbody>
            <?php $pOran = (float) ($ka['personel_oran'] ?? 1.0); ?>
            <tr><td>Personel (işveren maliyeti)<?= $pOran < 0.999
                ? ' <span class="text-muted" style="font-size:11px">· ayın %'
                  . number_format($pOran * 100, 0) . '&#39;i (faturalanan döneme oranlandı)</span>'
                : '' ?></td>
              <td class="num">₺ <?= Helpers::money($ka['uretim']['personel'] + $ka['tasima']['personel']) ?></td></tr>
            <tr><td>Gıda / işletme giderleri (bize kesilen faturalar)</td><td class="num">₺ <?= Helpers::money($ka['uretim']['gider'] + $ka['tasima']['gider']) ?></td></tr>
            <?php if ($ka['tasima']['alis'] > 0): ?>
            <tr><td>Taşıma alışı (satın alınan yemek)</td><td class="num">₺ <?= Helpers::money($ka['tasima']['alis']) ?></td></tr>
            <?php endif; ?>
            <?php if ($ka['tasima']['sabit'] > 0): ?>
            <tr><td>Taşıma sabit gideri</td><td class="num">₺ <?= Helpers::money($ka['tasima']['sabit']) ?></td></tr>
            <?php endif; ?>
            <?php if ($ka['dagitilmamis'] > 0): ?>
            <tr><td>Dağıtılmamış <span class="text-muted" style="font-size:11px">(hiçbir müşteriye atanmamış personel/gider)</span></td>
              <td class="num">₺ <?= Helpers::money($ka['dagitilmamis']) ?></td></tr>
            <?php endif; ?>
            <tr style="border-top:2px solid var(--line-2)"><td><strong>Toplam gider</strong></td>
              <td class="num"><strong>₺ <?= Helpers::money($gider) ?></strong></td></tr>
          </tbody></table>
          <p class="row-meta" style="margin-top:6px"><i class="bi bi-info-circle"></i>
            Gider yalnız <strong>bize kesilen faturalar değildir</strong> — personel maliyeti de buraya girer
            (fatura gelmez, bordrodan gelir). En büyük kalem genelde personeldir.</p>
        </div>
        <div class="gt-mini">
          <div><div class="gt-mn <?= $ka['uretim']['net'] < 0 ? 'bad' : 'ok' ?>">₺<?= number_format(round($ka['uretim']['net']), 0, ',', '.') ?></div><div class="gt-ml">Üretim kârı</div></div>
          <div><div class="gt-mn <?= $ka['tasima']['net'] < 0 ? 'bad' : 'ok' ?>">₺<?= number_format(round($ka['tasima']['net']), 0, ',', '.') ?></div><div class="gt-ml">Taşıma kârı</div></div>
          <div><div class="gt-mn"><?= marj_pct($ka['toplam_marj']) ?></div><div class="gt-ml">Toplam marj</div></div>
        </div>
      </div>

      <?php // fable-078: kişi başı maliyet kartları AYLIK kavramdır (o ayın kişi sayısına
           // bölünür) — yıllık toplamda anlamı olmaz, gösterilmez. ?>
      <?php if ($donem === 'ay' && ($tab === 'tumu' || $tab === 'uretim')): ?>
      <?php // fable-039: KİŞİ BAŞI GIDA MALİYETİ — büyük rakam + tıklayınca kırılım ?>
      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-basket3-fill"></i> KİŞİ BAŞI GIDA MALİYETİ</div>
        <?php // fable-071 (Ömer): rakam GİRİŞTE gizli — karta dokununca açılır. ?>
        <div class="gt-pulse tap-card" role="button" tabindex="0" onclick="toggleGida()" style="cursor:pointer">
          <div class="gt-pulse-n gizli" id="gida-rakam"><?= $gida['kisi_basi'] > 0 ? '₺' . Helpers::money($gida['kisi_basi']) : '—' ?></div>
          <div class="gt-pulse-l">1 kişilik gıda maliyeti <i class="bi bi-chevron-down chev"></i></div>
        </div>
        <?php // fable-079 (Ömer, 14 Ağu: "uyarıyı koy"): gıda haritasında OLMAYAN tedarikçi
              // maliyeti SESSİZCE düşük gösterir (YOPA ₺18.871 böyle kaçmıştı). Karar verilmemiş
              // her tedarikçi burada görünür; "gıda değil" işaretlenenler bir daha çıkmaz.
              $gidaEksik = $repo->gidaHaritasiEksik($month);
              $gidaEksikTutar = array_sum(array_column($gidaEksik, 'tutar')); ?>
        <?php if ($gidaEksik): ?>
        <div class="row-meta" style="margin-top:8px;color:var(--red);line-height:1.5">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <strong><?= count($gidaEksik) ?> tedarikçi</strong> gıda haritasında yok
          (₺<?= Helpers::money($gidaEksikTutar) ?>) — <strong>bu tutar kişi başı maliyete GİRMİYOR</strong>.
          Gıdaysa eşleştir, değilse "Gıda değil" işaretle ki bir daha sorulmasın:
          <div style="margin-top:5px">
            <?php $ge = [];
            foreach (array_slice($gidaEksik, 0, 5) as $e) { $ge[] = $e['ad'] . ' ₺' . Helpers::money($e['tutar']); }
            echo Helpers::e(implode(' · ', $ge));
            if (count($gidaEksik) > 5) { echo ' · +' . (count($gidaEksik) - 5); } ?>
          </div>
          <a class="btn-action btn-ghost" style="margin-top:6px" href="tedarikci-eslestirme.php?ay=<?= Helpers::e($month) ?>">
            Tedarikçi eşleştirmeye git <i class="bi bi-arrow-right"></i></a>
        </div>
        <?php endif; ?>
        <?php if ($gida['kirilimlar']): ?>
        <div id="gida-kirilim" style="display:none;margin-top:6px">
          <div class="gt-pulse-l" style="margin-bottom:6px">gıda alımları ₺<?= Helpers::money($gida['toplam']) ?> · üretim <?= number_format($gida['kisi_toplam'], 0, ',', '.') ?> kişi</div>
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
        <div class="gt-pulse tap-card" role="button" tabindex="0" onclick="toggleKart('pers')" style="cursor:pointer">
          <div class="gt-pulse-n gizli" id="pers-rakam"><?= $pc['kisi_basi'] > 0 ? '₺' . Helpers::money($pc['kisi_basi']) : '—' ?></div>
          <div class="gt-pulse-l">1 kişilik personel maliyeti <i class="bi bi-chevron-down chev"></i></div>
        </div>
        <div id="pers-detay" style="display:none;margin-top:6px">
          <div class="gt-pulse-l">personel (işveren maliyeti) ₺<?= Helpers::money($pc['toplam']) ?> · üretim <?= number_format($pc['kisi_toplam'], 0, ',', '.') ?> kişi</div>
        </div>
      </div>

      <?php // fable-071 (Ömer): KİŞİ BAŞI DİĞER MALİYET — gıda/personel dışı giderler kategori kırılımıyla ?>
      <?php $dc = $repo->digerCostOzet($month); ?>
      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-receipt-cutoff"></i> KİŞİ BAŞI DİĞER MALİYET</div>
        <div class="gt-pulse tap-card" role="button" tabindex="0" onclick="toggleKart('diger')" style="cursor:pointer">
          <div class="gt-pulse-n gizli" id="diger-rakam"><?= $dc['kisi_basi'] > 0 ? '₺' . Helpers::money($dc['kisi_basi']) : '—' ?></div>
          <div class="gt-pulse-l">1 kişilik diğer maliyet <i class="bi bi-chevron-down chev"></i></div>
        </div>
        <div id="diger-detay" style="display:none;margin-top:6px">
          <div class="gt-pulse-l" style="margin-bottom:6px">toplam ₺<?= Helpers::money($dc['toplam']) ?> · üretim <?= number_format($dc['kisi_toplam'], 0, ',', '.') ?> kişi</div>
          <?php foreach ($dc['kirilimlar'] as $d): $w = max(4, (int) round($d['oran'] * 100)); ?>
            <div class="gt-kr">
              <div class="gt-kr-head">
                <div class="gt-kr-firm">
                  <div class="gt-kr-ad"><?= Helpers::e($d['ad']) ?></div>
                  <div class="gt-kr-sub">kişi başı ₺<?= Helpers::money($d['kisi_basi']) ?></div>
                </div>
                <div class="gt-kr-val">₺<?= Helpers::money($d['tutar']) ?><small><?= number_format($d['oran'] * 100, 0) ?>%</small></div>
              </div>
              <div class="gt-bar"><i style="width: <?= $w ?>%"></i></div>
            </div>
          <?php endforeach; ?>
          <?php if (!$dc['kirilimlar']): ?><div class="gt-pulse-l">Bu ayda gıda/personel dışı gider yok.</div><?php endif; ?>
        </div>
      </div>

      <?php endif; // fable-075: gıda/personel/gider kartları bloğu burada kapanır —
             // eskiden kaldırılan ÜRETİM P&L tablosunun sonunda kapanıyordu. ?>

      <?php if ($karne): ?>
      <div class="cardx card-pad">
        <div class="gt-h"><i class="bi bi-clipboard-data"></i> MÜŞTERİ KARNESİ<?= $donem === 'yil' ? ' · ' . Helpers::e($yil) : '' ?></div>
        <?php foreach ($karne as $k): $w = $netMax > 0 ? max(4, (int) round(abs($k['net']) / $netMax * 100)) : 4; $bad = $k['net'] < 0; ?>
          <?php // fable-075: satır artık SAYFA DEĞİŞTİRMİYOR — açılır özet. Detaya geçiş panelden. ?>
          <details class="gt-satir">
            <summary class="gt-kr<?= $bad ? ' warn' : '' ?>">
              <div class="gt-kr-head">
                <div class="gt-rank"><?= Helpers::e(mb_strtoupper(mb_substr($k['name'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
                <div class="gt-kr-firm">
                  <div class="gt-kr-ad"><?= Helpers::e($k['name']) ?><i class="bi bi-chevron-down gt-kr-ok"></i></div>
                  <div class="gt-kr-sub">gelir ₺<?= Helpers::money($k['gelir']) ?><?= $k['tasima'] ? ' · taşıma' : '' ?></div>
                </div>
                <div class="gt-kr-val <?= $bad ? 'bad' : 'ok' ?>"><?= $bad ? '−' : '' ?>₺<?= Helpers::money(abs($k['net'])) ?><small>marj <?= marj_pct($k['marj']) ?></small></div>
              </div>
              <div class="gt-bar"><i class="<?= $bad ? 'bad' : '' ?>" style="width: <?= $w ?>%"></i></div>
            </summary>
            <div class="gt-satir-detay">
              <table class="tablex">
                <tbody>
                <?php if ($k['tasima']): ?>
                  <tr><td>Satış</td><td class="num">₺ <?= Helpers::money($k['gelir']) ?></td></tr>
                  <tr><td>Alış (taşıma)</td><td class="num">− ₺ <?= Helpers::money($k['alis']) ?></td></tr>
                  <?php if ($k['sabit'] > 0): ?><tr><td>Sabit gider</td><td class="num">− ₺ <?= Helpers::money($k['sabit']) ?></td></tr><?php endif; ?>
                <?php else: ?>
                  <tr><td><?= $kaynak === 'fatura' ? 'Fatura geliri' : 'Gelir' ?></td><td class="num">₺ <?= Helpers::money($k['gelir']) ?></td></tr>
                <?php endif; ?>
                  <?php // fable-077 (Ömer 2 kez sordu: "taşımada gıda gideri neden var?"): TAŞIMA
                        // müşterisinde bu satırda GIDA YOKTUR — yemeği biz üretmiyoruz, alış zaten
                        // ayrı satırda. Buradaki tutar genel işletme giderinin (yakıt, telefon, harç)
                        // ciro oranıyla düşen payıdır. Etiket bunu söylemeliydi. ?>
                  <tr>
                    <td><?= $k['tasima'] ? 'Genel gider payı <span class="text-muted" style="font-size:11px">(ciro oranıyla)</span>' : 'Gıda / işletme gideri' ?></td>
                    <td class="num">− ₺ <?= Helpers::money($k['gider']) ?></td>
                  </tr>
                  <tr><td>Personel</td><td class="num">− ₺ <?= Helpers::money($k['personel']) ?></td></tr>
                  <tr style="border-top:2px solid var(--line-2)">
                    <td><strong>Net kâr</strong></td>
                    <td class="num"><strong style="color:<?= $bad ? 'var(--red)' : 'var(--green)' ?>">₺ <?= Helpers::money($k['net']) ?></strong> <span class="text-muted">(<?= marj_pct($k['marj']) ?>)</span></td>
                  </tr>
                </tbody>
              </table>
              <div class="gt-kr-alt">
                <?php // fable-075: fatura adedi YALNIZ fatura modunda anlamlı — tahakkukta bu alan
                      // dolmuyor, basılsa "0 fatura" diye YANLIŞ bilgi olurdu. ?>
                <span class="text-muted" style="font-size:12px"><?= $kaynak === 'fatura'
                    ? ((int) $k['fatura_adedi']) . ' fatura'
                    : 'tahakkuk (üretim) hesabı' ?></span>
                <a class="btn-action btn-ghost" href="rapor.php?musteri=<?= (int) $k['id'] ?>&ay=<?= $month ?>&geri=kar&kaynak=<?= Helpers::e($kaynak) ?>">Detay<?= $donem === 'yil' ? ' · ' . Helpers::e(ay_label_tr($month)) : '' ?> <i class="bi bi-arrow-right"></i></a>
              </div>
            </div>
          </details>
        <?php endforeach; ?>
        <?php // fable-039: karnenin EN ALTINA toplam ciro satırı (taşıma bölümündeki toplam kalıbı) ?>
        <div class="gt-kr" style="border-top:2px solid var(--line-2)">
          <div class="gt-kr-head">
            <div class="gt-kr-firm"><div class="gt-kr-ad">Toplam ciro</div></div>
            <div class="gt-kr-val">₺<?= Helpers::money($karneCiroToplam) ?></div>
          </div>
        </div>
        <div class="gt-note">satıra dokun → özet açılır · özetteki <strong>Detay</strong> ile <?= $donem === 'yil' ? Helpers::e(ay_label_tr($month)) . ' dökümüne' : 'aylık döküme' ?> gidilir</div>
      </div>
      <?php endif; ?>

      <?php // fable-075: ÜRETİM ve TAŞIMA P&L tabloları KALDIRILDI — ikisi de müşteri
           // karnesiyle birebir aynı veriyi gösteriyordu. Kırılım artık karne satırının
           // açılır özetinde; müşteri bazlı döküm rapor.php'de. ?>
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
        <?php if ($donem === 'yil'): ?>
        <p class="row-meta" style="margin-top:8px"><i class="bi bi-calendar-range"></i>
          <?= Helpers::e($yil) ?> yılının <?= (int) $ka['aylar'] ?> ayı toplandı<?= $yil === date('Y') ? ' (yıl başından bugüne)' : '' ?>.
          Aylık dökümü görmek için <a href="kar-analizi.php?<?= Helpers::e($qs(['donem' => 'ay'])) ?>" style="text-decoration:underline">Aylık</a>'a geç.</p>
        <?php elseif ($kaynak === 'uretim'): ?>
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
          var n  = document.getElementById('gida-rakam');
          var acik = false;
          if (el) { el.style.display = el.style.display === 'none' ? '' : 'none'; acik = el.style.display !== 'none'; }
          if (n) n.classList.toggle('gizli', !acik);   // fable-071: rakam da kartla birlikte açılır
          var card = document.querySelector('[onclick="toggleGida()"]');
          if (card) card.classList.toggle('open', acik);
        }
        // fable-071: personel + diğer maliyet kartları için ortak aç/kapa
        function toggleKart(ad){
          var d = document.getElementById(ad + '-detay');
          var n = document.getElementById(ad + '-rakam');
          if (!d) return;
          d.style.display = d.style.display === 'none' ? '' : 'none';
          var acik = d.style.display !== 'none';
          if (n) n.classList.toggle('gizli', !acik);
          var card = document.querySelector('[onclick="toggleKart(\'' + ad + '\')"]');
          if (card) card.classList.toggle('open', acik);
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
