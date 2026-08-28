<?php

declare(strict_types=1);

/**
 * fable-091 (Ömer, 28 Ağu): "fatura otomatik kesim kuralım ama bu çok önemli olduğu için
 * tüm deseni anla." + düzeltmesi: "irsaliye kesilen her firma 7'şer günlük aralıkla
 * faturalandırılır — GÜN değil TARİH bazlı. Faturalar her ayın 7-14-21-28 ve ayın kaç gün
 * olduğuna bağlı olarak 30 veya 31'inde ay kapanışı yapılır."
 *
 * ⚠️ BU SCRIPT GERİ DÖNÜLMEZ İŞ YAPAR: e-Fatura GİB'e gider, iptali 8 günlük itiraz süreci.
 * Hafızadaki kural "Paraşüt'e onaysız/zamanlanmış yazma yok" idi; Ömer irsaliye için 18 Ağu'da,
 * fatura için 28 Ağu'da bilerek istisna verdi. Bu yüzden kapılar irsaliyeden DAHA DAR:
 *
 *   KAPI 0  Şalterler: PARASUT_FATURA_AKTIF + ayar irsaliye/fatura_oto_kesim + müşteri bazlı
 *           customers.fatura_oto_kesim (CANTAŞ 0 — 3 ayrı e-Faturaya bölünüyor, elle kalır).
 *   KAPI 1  Bugün kesim günü mü? (Repo::faturaDonemi — 7/14/21/28/ay sonu). Değilse hiç çalışmaz.
 *   KAPI 2  O günün İRSALİYE DOĞRULAMASI TEMİZ olmalı. Paraşüt'te eksik irsaliye varsa ya da
 *           Paraşüt'e ulaşılamıyorsa HİÇBİR ŞEY kesilmez — dönemin son günü eksik faturalanmasın.
 *   KAPI 3  Ekranın sistem kontrolleri (Repo::faturaKontrolleri*) — ekranda kırmızı UYARIDIR,
 *           burada KESME sebebidir. Kırmızısı olan müşteri atlanır ve bildirilir.
 *   KAPI 4  ParasutYaz'ın kendi kalkanları: onay imzası · claim-first irsaliye kilidi ·
 *           mükerrer kontrolü · ay kapanmadan aylık kesilmez.
 *
 * Aylık müşteriler (irsaliyesiz) YALNIZ ay kapanış gününde kesilir: aylık fatura + sabit kalem.
 * Yemek sayısı düzeltmesi Müşteriler ekranından yapılır (ek kalem/ürün YOK — Ömer 28 Ağu).
 *
 * Kullanım:  php tools/fatura_oto_kes.php [--kuru|--onbildirim] [--gun=YYYY-MM-DD]
 *   --kuru       : hiçbir şey kesmez, ne keseceğini yazar.
 *   --onbildirim : kuru koşar + "13:30'da şunlar kesilecek" özetini push eder (13:05 cron'u).
 *   --gun        : kesim gününü taklit et (test) — kesmez, bildirim de göndermez.
 * Cron (host UTC; TR 13:05 = UTC 10:05, TR 13:30 = UTC 10:30):
 *   5  10 * * * root ... fatura_oto_kes.php --onbildirim
 *   30 10 * * * root ... fatura_oto_kes.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI'dan çalıştırın: php tools/fatura_oto_kes.php\n");
}

require __DIR__ . '/../src/bootstrap.php';

use Uysa\Db;
use Uysa\ParasutYaz;
use Uysa\Push;
use Uysa\Repo;

$args = array_slice($argv, 1);
// --onbildirim: kesimden 25 dk önce "şunlar kesilecek" özetini push eder, HİÇBİR ŞEY KESMEZ.
// Ayrı script değil bayrak: aday/kontrol mantığı iki yere kopyalanırsa bildirimde görünen ile
// kesilen ayrışır — Ömer'e "şunlar kesilecek" deyip başka şey kesmek en kötü hata olurdu.
$onBildirim = in_array('--onbildirim', $args, true);
$kuru = $onBildirim || in_array('--kuru', $args, true);
$pdo = Db::pdo();
$repo = new Repo($pdo);
$bugun = date('Y-m-d');
foreach ($args as $a) {
    if (str_starts_with($a, '--gun=')) {
        $v = substr($a, 6);
        if ($v !== date('Y-m-d', (int) strtotime($v))) {
            exit("Geçersiz --gun: {$v}\n");
        }
        $bugun = $v;
        $kuru = true;          // taklit gün gerçek kesim yapmaz
        $onBildirim = false;   // ...ve gerçek bildirim de göndermez
    }
}
$damga = date('Y-m-d H:i:s');
$yaz = static fn(string $s): int => printf("%s", $s);

// ── KAPI 0: şalterler ────────────────────────────────────────────────────────
if (!ParasutYaz::faturaAktif()) {
    printf("[%s] fatura_oto: ana şalter KAPALI (PARASUT_FATURA_AKTIF) — işlem yok.\n", $damga);
    exit(0);
}
if ($repo->ayar('fatura_oto_kesim', '1') !== '1') {
    printf("[%s] fatura_oto: otomatik kesim ayardan KAPATILMIŞ (fatura_oto_kesim=0) — işlem yok.\n", $damga);
    exit(0);
}

// ── KAPI 1: bugün kesim günü mü? ─────────────────────────────────────────────
$donem = Repo::faturaDonemi($bugun);
if ($donem === null) {
    printf("[%s] fatura_oto: %s bir kesim günü değil (7/14/21/28/ay sonu) — işlem yok.\n", $damga, $bugun);
    exit(0);
}
$bas = $donem['bas'];
$son = $donem['son'];
$aySonu = $donem['tip'] === 'ay_sonu';
printf("[%s] fatura_oto: %s — dönem %s..%s (%s)%s\n", $damga, $bugun, $bas, $son,
    $aySonu ? 'AY KAPANIŞI' : 'haftalık', $kuru ? '  [KURU PROVA]' : '');

// ── KAPI 2: o günün irsaliye doğrulaması temiz mi? ───────────────────────────
// Sonuç-izleyen bekçi: yerel loga değil PARAŞÜT'e sorar (14 Ağu dersi — belge Paraşüt'e
// gidip Kokpit'e yazılamamıştı). Eksik irsaliye varken fatura kesilirse o gün faturaya
// hiç girmez ve fark bir daha kapanmaz.
$dogrulamaYaz = new ParasutYaz($repo, bin2hex(random_bytes(16)));
$parasutIrs = $dogrulamaYaz->gunIrsaliyeSahipleri($bugun);
$st = $pdo->prepare(
    "SELECT c.id, c.name FROM customers c
     JOIN production p ON p.customer_id = c.id AND p.prod_date = ?
     WHERE c.is_active = 1 AND c.irsaliye_aktif = 1
     GROUP BY c.id, c.name HAVING COALESCE(SUM(p.persons), 0) > 0"
);
$st->execute([$bugun]);
$bugunBeklenen = [];
foreach ($st->fetchAll() as $r) {
    $bugunBeklenen[(int) $r['id']] = (string) $r['name'];
}
$push = new Push($pdo);

if ($bugunBeklenen && $parasutIrs === null) {
    printf("  DURDU: Paraşüt'e ulaşılamadı — irsaliyeler doğrulanamadı, fatura KESİLMEDİ.\n");
    if (!$kuru) {
        $push->toAdmins('Fatura kesimi DURDU', date('d.m.Y', (int) strtotime($bugun))
            . ' · Paraşüt\'e ulaşılamadığı için irsaliyeler doğrulanamadı; otomatik fatura kesimi yapılmadı.',
            ['url' => '/fatura-kes.php'], 'kritik', 'fatura_oto_durdu:' . $bugun);
    }
    exit(2);
}
$irsEksik = [];
foreach ($bugunBeklenen as $cid => $ad) {
    if (!isset($parasutIrs[$cid])) {
        $irsEksik[] = $ad;
    }
}
if ($irsEksik) {
    printf("  DURDU: bugünün irsaliyesi eksik (%s) — fatura KESİLMEDİ.\n", implode(', ', $irsEksik));
    if (!$kuru) {
        $push->toAdmins('Fatura kesimi DURDU: eksik irsaliye',
            date('d.m.Y', (int) strtotime($bugun)) . ' · Şu müşterilerin bugünkü irsaliyesi Paraşüt\'te yok: '
            . implode(' · ', $irsEksik) . '. Önce irsaliyeleri kesin, sonra faturayı elle kesin.',
            ['url' => '/bugun.php'], 'kritik', 'fatura_oto_durdu:' . $bugun);
    }
    exit(2);
}

// ── Adaylar ──────────────────────────────────────────────────────────────────
$kesilecek = [];   // ['tip'=>..,'aday'=>..,'ozet'=>..]
$atlanan = [];     // ['ad'=>..,'sebep'=>..]

foreach ($repo->faturaAdaylari($bas, $son) as $a) {
    $cid = (int) $a['customer_id'];
    $ad = (string) $a['name'];
    $tip = (string) $a['tip'];

    // Aylık ve sabit kalemler YALNIZ ay kapanışında değerlendirilir. Bu eleme EN ÖNDE olmalı:
    // haftalık turda aylık müşteri "ay kapanınca kesilir" diye atlanan sayılırsa her hafta
    // bildirimde 3 gereksiz satır çıkar ve gerçek sorunlar arasında kaybolur.
    if (($tip === 'aylik' || $tip === 'sabit') && !$aySonu) {
        continue;
    }
    if (!$repo->faturaOtoAcik($cid)) {
        $atlanan[] = ['ad' => $ad, 'sebep' => 'otomatik kesim kapalı (elle kesilir)'];
        continue;
    }
    if (empty($a['secilebilir'])) {
        $atlanan[] = ['ad' => $ad . ($tip === 'sabit' ? ' · ' . $a['kalem_ad'] : ''),
            'sebep' => (string) ($a['sebep'] ?: 'kesilemez')];
        continue;
    }

    if ($tip === 'irsaliye') {
        $on = $dogrulamaYaz->faturaOnizleme($cid, $bas, $son);
        if (!$on['ok']) {
            $atlanan[] = ['ad' => $ad, 'sebep' => (string) $on['mesaj']];
            continue;
        }
        $irsGunSet = [];
        foreach ($repo->faturaAdayIrsaliyeler($cid, $bas, $son) as $log) {
            $irsGunSet[(string) $log['gun']] = true;
        }
        $kontroller = $repo->faturaKontrolleriIrsaliye($cid, $bas, $son, (int) $on['toplam'], $irsGunSet);
        $kirmizi = array_values(array_filter($kontroller, static fn(array $k): bool => !$k['ok']));
        if ($kirmizi) {
            $atlanan[] = ['ad' => $ad, 'sebep' => $kirmizi[0]['txt']];
            continue;
        }
        $kesilecek[] = ['tip' => 'irsaliye', 'cid' => $cid, 'ad' => $ad,
            'ozet' => (int) $on['toplam'] . ' kişi · ₺' . number_format((float) $on['hesap']['net'], 2, ',', '.'),
            'net' => (float) $on['hesap']['net']];
        continue;
    }

    if ($tip === 'sabit') {
        $hesap = Repo::sabitFaturaHesap((float) $a['birim'], (float) $a['kdv_orani']);
        $kesilecek[] = ['tip' => 'sabit', 'cid' => $cid, 'ad' => $ad . ' · ' . $a['kalem_ad'],
            'kalem_id' => (int) $a['kalem_id'], 'ay' => (string) $a['ay'],
            'ozet' => '₺' . number_format($hesap['net'], 2, ',', '.'), 'net' => (float) $hesap['net']];
        continue;
    }

    // ── aylık (irsaliyesiz) ──
    $aBas = (string) ($a['donem_bas'] ?? $bas);
    $aSon = (string) ($a['donem_son'] ?? $son);
    $parts = [];
    $sumKisi = 0;
    $altNet = 0.0;
    if (($a['bolusum'] ?? null)) {
        // Bölüşüm ekranda elle giriliyor; otomatikte DESENDEN gelir (altFirmaDagilim —
        // hafta içi sabit kotalar + kalan varsayılana; elle girilen günler deseni ezer).
        $dag = [];
        foreach ($repo->altFirmaDagilim($cid, $aBas, $aSon) as $kod => $v) {
            $dag[$kod] = (int) $v['kisi'];
        }
        foreach ($a['bolusum'] as $b) {
            $kisi = Repo::normalizePersons($dag[$b['key']] ?? 0);
            $sumKisi += $kisi;
            $parts[] = ['contact_id' => $b['contact_id'], 'ad' => (string) $b['ad'], 'kisi' => $kisi];
        }
    } else {
        $kisi = (int) $a['adet'];
        $sumKisi = $kisi;
        $parts[] = ['contact_id' => (string) $a['parasut_id'], 'ad' => $ad, 'kisi' => $kisi];
    }
    foreach ($parts as $pt) {
        $h = ParasutYaz::faturaHesap([['miktar' => (int) $pt['kisi'], 'birim' => (float) $a['birim']]], 10.0, null);
        $altNet += (float) $h['net'];
    }
    $hedefAdet = (($a['bolusum'] ?? null) && (int) ($a['fatura_adet'] ?? 0) > 0)
        ? (int) $a['fatura_adet'] : (int) $a['adet'];
    $kontroller = $repo->faturaKontrolleriAylik($cid, $aBas, $aSon, $sumKisi, $hedefAdet, $bas);
    $kirmizi = array_values(array_filter($kontroller, static fn(array $k): bool => !$k['ok']));
    if ($kirmizi) {
        $atlanan[] = ['ad' => $ad, 'sebep' => $kirmizi[0]['txt']];
        continue;
    }
    $kesilecek[] = ['tip' => 'aylik', 'cid' => $cid, 'ad' => $ad, 'bas' => $aBas, 'son' => $aSon,
        'parts' => $parts,
        'ozet' => $sumKisi . ' kişi · ' . count($parts) . ' parça · ₺' . number_format($altNet, 2, ',', '.'),
        'net' => $altNet];
}

$toplamNet = 0.0;
foreach ($kesilecek as $k) {
    $toplamNet += (float) $k['net'];
}
printf("  kesilecek %d · atlanan %d · toplam ₺%s\n", count($kesilecek), count($atlanan),
    number_format($toplamNet, 2, ',', '.'));
foreach ($kesilecek as $k) {
    printf("  %-8s %-26s %s\n", strtoupper($k['tip']), mb_substr($k['ad'], 0, 26), $k['ozet']);
}
foreach ($atlanan as $x) {
    printf("  ATLANDI  %-26s %s\n", mb_substr($x['ad'], 0, 26), $x['sebep']);
}

if ($onBildirim) {
    echo "  [ÖN BİLDİRİM] hiçbir fatura kesilmedi.\n";
    if (!$kesilecek && !$atlanan) {
        echo "  Kesilecek fatura yok — bildirim gönderilmedi.\n";
        exit(0);
    }
    $sat = array_map(static fn(array $k): string => $k['ad'] . ' (' . $k['ozet'] . ')', $kesilecek);
    $govde = date('d.m', (int) strtotime($bas)) . '–' . date('d.m.Y', (int) strtotime($son)) . ' · '
        . ($kesilecek
            ? count($kesilecek) . ' fatura, toplam ₺' . number_format($toplamNet, 2, ',', '.') . ': ' . implode(' · ', $sat)
            : 'kesilecek fatura yok');
    if ($atlanan) {
        $govde .= ' | ' . count($atlanan) . ' atlandı: '
            . implode(' · ', array_map(static fn(array $x): string => $x['ad'] . ' (' . $x['sebep'] . ')', $atlanan));
    }
    $govde .= ' — 13:30\'da otomatik kesilecek. İstemiyorsanız Fatura ekranından otomatik kesimi kapatın.';
    $r = $push->toAdmins('13:30\'da ' . count($kesilecek) . ' fatura kesilecek', $govde,
        ['url' => '/fatura-kes.php'], 'kritik', 'fatura_on:' . $bugun);
    printf("  Bildirim: %d cihaz · %d gönderildi\n", (int) $r['devices'], (int) $r['sent']);
    exit(0);
}
if ($kuru) {
    echo "  [KURU PROVA] hiçbir fatura kesilmedi.\n";
    exit(0);
}
if (!$kesilecek && !$atlanan) {
    printf("  Kesilecek fatura yok.\n");
    exit(0);
}

// ── KESİM ────────────────────────────────────────────────────────────────────
$onay = ParasutYaz::onayImzaUret();
$kesici = new ParasutYaz($repo, $onay);
$ctx = ['onay' => $onay, 'actor' => 'oto-fatura'];
$basarili = [];
$basarisiz = [];

foreach ($kesilecek as $k) {
    $sonuclar = [];
    if ($k['tip'] === 'irsaliye') {
        $sonuclar[] = [$k['ad'], $kesici->createSalesInvoice($k['cid'], $bas, $son, $ctx)];
    } elseif ($k['tip'] === 'sabit') {
        $sonuclar[] = [$k['ad'], $kesici->createFixedInvoice((int) $k['kalem_id'], (string) $k['ay'], $ctx)];
    } else {
        foreach ($k['parts'] as $pt) {
            if ((int) $pt['kisi'] <= 0) {
                continue;   // 0 kişi = fatura kesilmez
            }
            $sonuclar[] = [$k['ad'] . ' · ' . $pt['ad'],
                $kesici->createMonthlyInvoice($k['cid'], (string) $k['bas'], (string) $k['son'], $pt, $ctx)];
        }
    }
    foreach ($sonuclar as [$etiket, $r]) {
        if (!empty($r['ok'])) {
            $basarili[] = $etiket . ' (' . ($r['fatura_no'] ?: '—') . ')';
            printf("  KESİLDİ  %-26s %s\n", mb_substr($etiket, 0, 26), (string) ($r['fatura_no'] ?? '—'));
        } else {
            $basarisiz[] = ['ad' => $etiket, 'mesaj' => (string) ($r['mesaj'] ?? $r['durum'] ?? 'bilinmeyen hata')];
            printf("  HATA     %-26s %s\n", mb_substr($etiket, 0, 26), (string) ($r['mesaj'] ?? ''));
        }
        uysa_audit('fatura_oto_kesim', 'cron', $bas . '..' . $son, json_encode([
            'etiket' => $etiket, 'ok' => !empty($r['ok']), 'durum' => $r['durum'] ?? '',
            'fatura_no' => $r['fatura_no'] ?? '',
        ], JSON_UNESCAPED_UNICODE), 'local');
        sleep(2);   // Paraşüt hız sınırı
    }
}

// fable-082b: Kâr/Zarar ekranı satis_faturasi tablosundan besleniyor — kesimden sonra
// senkron çalışmazsa ekran cron'a kadar eski kapsamı gösterir.
if ($basarili) {
    try {
        $si = Uysa\Parasut::salesInvoicesForMonth(substr($son, 0, 7));
        $repo->satisFaturaIsle($si['invoices'], Uysa\Parasut::supplierContactIds());
    } catch (\Throwable $e) {
        error_log('[UYSA v2 fatura_oto] kesim sonrası satış senkronu atlandı: ' . $e->getMessage());
    }
}

// ── Sonuç bildirimi — HER HÂLDE ──────────────────────────────────────────────
$parca = [];
if ($basarili) {
    $parca[] = count($basarili) . ' kesildi: ' . implode(' · ', $basarili);
}
if ($basarisiz) {
    $parca[] = count($basarisiz) . ' KESİLEMEDİ: '
        . implode(' · ', array_map(static fn(array $x): string => $x['ad'] . ' (' . $x['mesaj'] . ')', $basarisiz));
}
if ($atlanan) {
    $parca[] = count($atlanan) . ' atlandı: '
        . implode(' · ', array_map(static fn(array $x): string => $x['ad'] . ' (' . $x['sebep'] . ')', $atlanan));
}
if ($aySonu && !$repo->faturaOtoAcik(3)) {
    $parca[] = 'CANTAŞ otomatiğe dahil DEĞİL — ay sonu faturasını elle kesin.';
}

$baslik = $basarisiz || $atlanan
    ? 'Otomatik fatura: ' . count($basarili) . ' kesildi, ' . (count($basarisiz) + count($atlanan)) . ' sorunlu'
    : 'Otomatik fatura: ' . count($basarili) . ' kesildi';
$r = $push->toAdmins($baslik,
    date('d.m', (int) strtotime($bas)) . '–' . date('d.m.Y', (int) strtotime($son)) . ' · ' . implode(' | ', $parca),
    ['url' => '/fatura-kes.php'], 'kritik', 'fatura_oto:' . $bugun);
printf("  Bildirim: %d cihaz · %d gönderildi\n", (int) $r['devices'], (int) $r['sent']);

exit($basarisiz ? 1 : 0);
