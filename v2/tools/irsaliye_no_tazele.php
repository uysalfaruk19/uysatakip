<?php

declare(strict_types=1);

/**
 * fable-072 — GECİKMELİ e-İRSALİYE NUMARASINI YAKALA (salt-okuma).
 *
 * Kanıt: e-İrsaliye numarası (`despatch_no`) GİB'den kesimden BİRKAÇ SANİYE SONRA doğuyor.
 * Kesim anında sorgulanınca boş dönüyor, sistem bir daha bakmıyordu → 66 kesilmiş irsaliyenin
 * 54'ünde numara boş kaldı ve faturaya yazacak numara elde olmadı.
 *
 * Bu script `durum='kesildi'` + numarası BOŞ + belge id'si DOLU kayıtları alır,
 * her biri için Paraşüt'ten `GET /shipment_documents/{id}` → `attributes.despatch_no` okur ve
 * doluysa YEREL loga yazar. Paraşüt'e HİÇBİR ŞEY YAZMAZ (fatura/irsaliye KESMEZ).
 *
 * Hız sınırı gerçek (canlıda HTTP 429): çağrılar arası ~800 ms + 429'da üstel geri çekilme
 * (2s/4s/8s, 3 deneme); yine olmazsa o kayıt ATLANIR — script çökmez, çıkış kodu 0 kalır.
 *
 * Kullanım:
 *   php tools/irsaliye_no_tazele.php            # son 15 gün
 *   php tools/irsaliye_no_tazele.php --gun=30   # son 30 gün
 *   php tools/irsaliye_no_tazele.php --tum      # sınırsız (geçmişi de tarar)
 *   php tools/irsaliye_no_tazele.php --limit=50 # en fazla 50 kayıt sor
 *
 * fable-080: numara tazelemeden ÖNCE gün uzlaştırması koşar (Paraşüt'te kesilip
 * Kokpit'e yazılamamış irsaliyeyi yakalar — mükerrer kesim kalkanı).
 *
 * Cron: her 20 dakikada bir → docker exec uysatakip-v2 php /var/www/html/tools/irsaliye_no_tazele.php
 * Kill-switch: cron satırını sil (script tek başına zararsız, salt-okuma).
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Uysa\Db;
use Uysa\ParasutYaz;
use Uysa\Repo;

$gun = 15;
$tum = false;
$limit = 200;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--tum') {
        $tum = true;
    } elseif (preg_match('/^--gun=(\d+)$/', $arg, $m)) {
        $gun = max(1, (int) $m[1]);
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, (int) $m[1]);
    } else {
        fwrite(STDERR, "Bilinmeyen argüman: $arg\n");
        exit(2);
    }
}

$pdo = Db::pdo();
$repo = new Repo($pdo);

// fable-080 (14 Ağu vakası): Paraşüt'te KESİLMİŞ ama Kokpit'e YAZILAMAMIŞ irsaliye kalabilir
// (yanıt kaybolursa — o gün konteyner yeniden başladığı için CEOTHERM böyle kayboldu ve ekranda
// "kesilmedi" göründü; tekrar basılsa MÜKERRER e-İrsaliye olacaktı). Numara tazelemeden ÖNCE
// bugünü ve dünü uzlaştır: Paraşüt gerçeği neyse Kokpit onu bilsin.
$uzlas = new ParasutYaz($repo);
foreach ([date('Y-m-d'), date('Y-m-d', strtotime('-1 day'))] as $uGun) {
    foreach ($uzlas->gunUzlastir($uGun) as $satir) {
        echo "UZLAŞTIRMA: $satir
";
        uysa_audit('irsaliye_uzlastirma', 'cron', $uGun, $satir, 'local');
    }
}

// fable-081: FATURA numarasi da kesim aninda bos kaliyor (irsaliyedeki desenin aynisi).
// 14 Agu 2. hafta kesiminde 12 fatura numarasi elle dolduruldu — bir daha elle yapilmasin.
$fr = (new ParasutYaz($repo))->faturaNolariTazele(100);
if ($fr['bulundu'] > 0 || $fr['hata'] > 0) {
    printf("Fatura no: tarandi %d · bulundu %d · hala bos %d · hata %d
",
        $fr['tarandi'], $fr['bulundu'], $fr['bos'], $fr['hata']);
    if ($fr['bulundu'] > 0) {
        uysa_audit('fatura_no_tazele', 'cron', date('Y-m-d'), json_encode($fr, JSON_UNESCAPED_UNICODE), 'local');
    }
}

$gunden = $tum ? null : date('Y-m-d', strtotime('-' . $gun . ' day'));
$eksik = $repo->despatchNosuEksikIrsaliyeler($gunden, $limit);

if (!$eksik) {
    echo 'Numarası boş irsaliye yok (' . ($tum ? 'tüm zaman' : 'son ' . $gun . ' gün') . ") — iş yok.\n";
    exit(0);
}

$yaz = new ParasutYaz($repo);
$r = $yaz->despatchNolariTazele($eksik, $limit);

printf(
    "%s · tarandı %d · numara bulundu %d · Paraşüt'te hâlâ boş %d · hata/atlandı %d\n",
    $tum ? 'tüm zaman' : 'son ' . $gun . ' gün',
    $r['tarandi'],
    $r['bulundu'],
    $r['bos'],
    $r['hata']
);

if ($r['bulundu'] > 0) {
    uysa_audit('irsaliye_no_tazele', 'cron', date('Y-m-d'), json_encode($r, JSON_UNESCAPED_UNICODE), 'local');
}

exit(0);
