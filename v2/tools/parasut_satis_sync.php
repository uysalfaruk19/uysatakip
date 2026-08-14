<?php
declare(strict_types=1);

/**
 * fable-048 — Paraşüt SATIŞ FATURASI senkronu (SALT-OKUMA).
 *
 * Ömer faturaları Paraşüt'ten kesiyor (Kokpit fatura şalteri KAPALI) → GERÇEK gelir orada.
 * Bu araç ayın kesilen satış faturalarını okur ve satis_faturasi tablosuna işler.
 * PARAŞÜT'E HİÇBİR YAZMA YOK — yalnız GET (parasut-onay-kuralı).
 * Mükerrer kalkanı: satis_faturasi.parasut_id UNIQUE (aynı fatura ikinci kez girmez;
 * değişmişse güncellenir). İade/iptal faturalar ayıklanır, sayısı raporlanır.
 *
 * Kullanım (konteynerde; kredensiyal şifreli dosyadan çözülür):
 *   php tools/parasut_satis_sync.php              # bu ay
 *   php tools/parasut_satis_sync.php 2026-06      # belirli ay
 *   php tools/parasut_satis_sync.php 2026-07 --kuru   # PROVA: okur, DB'ye YAZMAZ
 *
 * Cron (VPS host) — gider senkronuyla AYNI saatler, 5 dk sonra (aynı anda kotayı zorlamasın):
 *   40 7,17 * * * docker exec uysatakip-v2 php /var/www/html/tools/parasut_satis_sync.php
 *   # ay devri: ayın 1'inde ÖNCEKİ ayı da bir kez tazele (ay sonu faturaları geç girer)
 *   50 7 1 * *   docker exec uysatakip-v2 php /var/www/html/tools/parasut_satis_sync.php $(date -d 'yesterday' +\%Y-\%m)
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Uysa\Db;
use Uysa\Parasut;
use Uysa\Repo;

$args = array_slice($argv, 1);
$kuru = in_array('--kuru', $args, true);
$args = array_values(array_filter($args, static fn(string $a): bool => $a !== '--kuru'));
$ay = $args[0] ?? date('Y-m');

if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
    fwrite(STDERR, "ay=YYYY-MM bekleniyor\n");
    exit(2);
}
if (!Parasut::configured()) {
    fwrite(STDERR, "Paraşüt kredensiyali yok/çözülemedi.\n");
    exit(3);
}

try {
    $si = Parasut::salesInvoicesForMonth($ay);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Paraşüt okuma hatası: ' . $e->getMessage() . "\n");
    exit(4);
}

$adet = count($si['invoices']);
$brut = 0.0;
foreach ($si['invoices'] as $f) {
    $brut += (float) $f['net_tutar'];
}

if ($kuru) {
    printf("PROVA (yazma YOK) — %s: %d satış faturası · ₺%s (KDV hariç)%s%s\n",
        $ay, $adet, number_format($brut, 2, ',', '.'),
        $si['iade'] > 0 ? ' · ' . $si['iade'] . ' iade/iptal ayıklandı' : '',
        $si['atlanan'] > 0 ? ' · ' . $si['atlanan'] . ' fatura-dışı belge atlandı' : '');
    foreach (array_slice($si['invoices'], 0, 10) as $f) {
        printf("  %s  %-16s %-28s ₺%s\n", $f['fatura_tarihi'], $f['fatura_no'],
            mb_substr((string) $f['contact_ad'], 0, 28), number_format((float) $f['net_tutar'], 2, ',', '.'));
    }
    exit(0);
}

$repo = new Repo(Db::pdo());

// fable-076 (Ömer: "yeni müşteri eklediğimde otomatik senkron olsun") — ÖZ-ONARIM:
// Müşteri kaydında oto-bağlama denenir; ama Ömer önce Kokpit'e müşteriyi ekleyip cariyi
// SONRA Paraşüt'te açarsa o an aday yoktur ve bağ hiç kurulmaz. Senkron her koştuğunda
// bağsız aktif müşteriler için tekrar dener → sıra ne olursa olsun kendini toparlar.
// Yine KÖR BAĞLAMA YOK: tek aday varsa bağlar, birden çoksa dokunmaz ve raporlar.
$otoBagli = [];
$otoSecim = [];
try {
    $cariler = $repo->parasutCariListesi(static fn(): array => Parasut::contacts(), true);
    // array_merge — "+" sayısal anahtarlarda ikinci diziyi YUTAR (taşıma müşterileri düşerdi).
    $hepsi = array_merge($repo->listCustomersByCategory('uretim'), $repo->listCustomersByCategory('tasima'));
    foreach ($hepsi as $c) {
        $cid = (int) $c['id'];
        if ($repo->parasutBagliMi($cid)) {
            continue;
        }
        $s = $repo->parasutCariOtoBagla($cid, (string) $c['name'], $cariler);
        if ($s['durum'] === 'baglandi') {
            $otoBagli[] = $c['name'] . ' → ' . $s['ad'];
            uysa_audit('parasut_cari_otobagla', 'cron', (string) $cid,
                json_encode(['parasut_id' => $s['parasut_id'], 'ad' => $s['ad']], JSON_UNESCAPED_UNICODE), '');
        } elseif ($s['durum'] === 'secim') {
            $otoSecim[] = $c['name'] . ' (' . count($s['adaylar']) . ' aday)';
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'uyarı: cari oto-bağlama atlandı (' . $e->getMessage() . ")\n");
}

// fable-049d: tedarikçi carileri (account_type=supplier) — bunlara kesilen fatura ALIŞ İADESİDİR.
// Ağ hatası olursa iade tespiti ad eşleşmesine düşer (senkron durmaz).
$tedContactIds = [];
try {
    $tedContactIds = Uysa\Parasut::supplierContactIds();
} catch (\Throwable $e) {
    fwrite(STDERR, "uyarı: tedarikçi carileri okunamadı (" . $e->getMessage() . ") — iade tespiti ad bazlı
");
}
$sonuc = $repo->satisFaturaIsle($si['invoices'], $tedContactIds);
$ozet = $repo->satisFaturaOzet($ay);

uysa_audit('satis_parasut', 'cron', $ay, json_encode([
    'okunan' => $adet, 'iade' => $si['iade'], 'atlanan' => $si['atlanan'],
    'yeni' => $sonuc['yeni'], 'guncellenen' => $sonuc['guncellenen'], 'mevcut' => $sonuc['mevcut'],
    'tutar' => $sonuc['tutar'], 'eslesmemis' => $sonuc['eslesmemis'],
], JSON_UNESCAPED_UNICODE), '');

printf("Paraşüt satış senkronu (%s): %d yeni · %d güncellendi · %d değişmedi · ₺%s (KDV hariç)%s%s\n",
    $ay, $sonuc['yeni'], $sonuc['guncellenen'], $sonuc['mevcut'],
    number_format($sonuc['tutar'], 2, ',', '.'),
    $si['iade'] > 0 ? ' · ' . $si['iade'] . ' iade/iptal ATLANDI' : '',
    $si['atlanan'] > 0 ? ' · ' . $si['atlanan'] . ' fatura-dışı belge atlandı' : '');

// fable-076: bu koşuda kurulan/kurulamayan cari bağları — sessiz kalma.
if ($otoBagli) {
    printf("🔗 %d müşteri Paraşüt carisine OTO-BAĞLANDI: %s\n", count($otoBagli), implode(' · ', $otoBagli));
}
if ($otoSecim) {
    printf("❓ %d müşteride birden çok aday cari var, BAĞLANMADI (Müşteriler > kart > Paraşüt carisi): %s\n",
        count($otoSecim), implode(' · ', $otoSecim));
}

// fable-049d: tedarikçiye kesilen fatura = ALIŞ İADESİ → gelire değil, gidere (negatif) yazıldı.
if ((int) ($sonuc['alis_iadesi'] ?? 0) > 0) {
    printf("↩ %d ALIŞ İADESİ (₺%s) tedarikçi giderinden DÜŞÜLDÜ (gelire yazılmadı).
",
        $sonuc['alis_iadesi'], number_format((float) $sonuc['alis_iadesi_tutar'], 2, ',', '.'));
}

if ($sonuc['eslesmemis'] > 0) {
    printf("⚠ %d fatura Kokpit müşterisiyle EŞLEŞMEDİ (₺%s) — kâr/zararda 'eşleşmemiş gelir' olarak ayrı durur.\n",
        $ozet['eslesmemis_adet'], number_format($ozet['eslesmemis_net'], 2, ',', '.'));
    foreach (array_slice($ozet['eslesmemis'], 0, 5) as $e) {
        printf("   · %s — ₺%s (%d fatura)\n", $e['ad'], number_format($e['net'], 2, ',', '.'), $e['adet']);
    }
    echo "   Çözüm: Müşteriler ekranından ilgili cariyi Paraşüt kaydıyla eşleştir (customers.parasut_id).\n";
}

if ($ozet['kapsam_son'] !== null) {
    printf("Kapsanan dönem: %s'e kadar (son fatura %s)%s\n",
        date('d.m.Y', strtotime((string) $ozet['kapsam_son'])),
        date('d.m.Y', strtotime((string) $ozet['son_fatura'])),
        $ozet['uyari'] ? sprintf(' · ⚠ %d gündür fatura kesilmemiş — sonrası henüz faturalanmadı', (int) $ozet['gecikme_gun']) : '');
}
