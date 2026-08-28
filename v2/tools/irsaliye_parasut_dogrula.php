<?php

declare(strict_types=1);

/**
 * fable-090 (Ömer, 28 Ağu): "Her öğlen 12:30'da irsaliyeler otomatik kesilsin, 16:00'da sadece
 * kesildi mi diye PARAŞÜT kontrolü yapılsın — kesilmişse bildirim olmasın, kesilmemişse
 * 'Paraşüt'ten kontrol edildi, irsaliyeler kesilmemiş' diye bildirim gelsin."
 *
 * Bu script HİÇBİR ŞEY KESMEZ, HİÇBİR ŞEY YAZMAZ — salt okuma + gerekirse bildirim.
 *
 * NEDEN YEREL LOGA DEĞİL PARAŞÜT'E SORAR: 14 Ağustos'ta CEOTHERM'in irsaliyesi Paraşüt'e
 * gitti ve GİB'e ulaştı, ama tam o anda konteyner yeniden başladığı için Kokpit'e
 * yazılamadı — yerel log "kesilmedi" diyordu, gerçek "kesildi"ydi. Sonuç-izleyen bekçi
 * kaynağa bakar; yoksa doğru işi "yapılmamış" diye alarma çevirir.
 *
 * ÜÇ SONUÇ, ÜÇÜ DE AYRI:
 *   1. Hepsi kesilmiş           -> SUS (yalnız log). Ömer'in kuralı: çözülmüş işe bildirim yok.
 *   2. Eksik var                -> bildirim: hangi müşteri kesilmemiş.
 *   3. Paraşüt'e ULAŞILAMADI    -> bildirim. "Bakamadım" sessiz geçilirse bekçi sessizce ölür
 *                                  ve Ömer kontrol edildiğini sanır (hayalet süreç dersi).
 *
 * Kill-switch: ayar `irsaliye_dogrulama` = 0.
 *
 * Kullanım:  php tools/irsaliye_parasut_dogrula.php [--kuru] [--gun=YYYY-MM-DD]
 *   --gun: geçmiş bir günü elle kontrol etmek ve TESTİ SINAMAK için. "Bugün 0 bulundu"
 *          sonucu tek başına okumanın çalıştığını kanıtlamaz (bozuk okuma da 0 döner);
 *          irsaliyesi kesilmiş bir güne bakıp dolu sonuç görmek kanıtlar.
 * Cron (host UTC; TR 16:00 = UTC 13:00):
 *   0 13 * * * root docker exec uysatakip-v2 php /var/www/html/tools/irsaliye_parasut_dogrula.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI'dan çalıştırın: php tools/irsaliye_parasut_dogrula.php\n");
}

require __DIR__ . '/../src/bootstrap.php';

use Uysa\Db;
use Uysa\ParasutYaz;
use Uysa\Push;
use Uysa\Repo;

$args = array_slice($argv, 1);
$kuru = in_array('--kuru', $args, true);
$pdo = Db::pdo();
$repo = new Repo($pdo);
$gun = date('Y-m-d');
foreach ($args as $a) {
    if (str_starts_with($a, '--gun=')) {
        $v = substr($a, 6);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || $v !== date('Y-m-d', (int) strtotime($v))) {
            exit("Geçersiz --gun (YYYY-MM-DD bekleniyor): {$v}\n");
        }
        $gun = $v;
        $kuru = true;   // geçmiş gün elle inceleniyor — o güne ait bildirim ÜRETİLMEZ
    }
}
$damga = date('Y-m-d H:i:s');
$gunTr = date('d.m.Y', (int) strtotime($gun));

if ($repo->ayar('irsaliye_dogrulama', '1') !== '1') {
    printf("[%s] dogrula: ayardan KAPATILMIŞ (irsaliye_dogrulama=0) — işlem yok.\n", $damga);
    exit(0);
}

// O gün yemek ÇIKMIŞ ve irsaliyesi olması GEREKEN müşteriler (oto kesimle aynı kapı).
$st = $pdo->prepare(
    "SELECT c.id, c.name
     FROM customers c
     JOIN production p ON p.customer_id = c.id AND p.prod_date = ?
     WHERE c.is_active = 1 AND c.irsaliye_aktif = 1
     GROUP BY c.id, c.name
     HAVING COALESCE(SUM(p.persons), 0) > 0
     ORDER BY c.name"
);
$st->execute([$gun]);
$beklenen = [];
foreach ($st->fetchAll() as $r) {
    $beklenen[(int) $r['id']] = (string) $r['name'];
}

if (!$beklenen) {
    printf("[%s] dogrula: %s — üretim yok, irsaliye beklenmiyor.\n", $damga, $gun);
    exit(0);
}

$push = new Push($pdo);
$yaz = new ParasutYaz($repo, bin2hex(random_bytes(16)));
$parasut = $yaz->gunIrsaliyeSahipleri($gun);

// ── Durum 3: kaynağa ulaşılamadı ─────────────────────────────────────────
if ($parasut === null) {
    printf("[%s] dogrula: %s — PARAŞÜT'E ULAŞILAMADI (%d müşteri doğrulanamadı).\n",
        $damga, $gun, count($beklenen));
    if ($kuru) {
        echo "  [PROVA] bildirim gönderilmedi.\n";
        exit(2);
    }
    $r = $push->toAdmins(
        'İrsaliye kontrolü YAPILAMADI',
        $gunTr . ' · Paraşüt\'e ulaşılamadı, ' . count($beklenen)
            . ' müşterinin irsaliyesi doğrulanamadı. Lütfen elle kontrol edin.',
        ['url' => '/bugun.php'], 'kritik', 'irsaliye_dogrula_hata:' . $gun
    );
    printf("  Bildirim: %d cihaz · %d gönderildi\n", (int) $r['devices'], (int) $r['sent']);
    exit(2);
}

$eksik = [];
foreach ($beklenen as $cid => $ad) {
    if (!isset($parasut[$cid])) {
        $eksik[] = $ad;
    }
}

printf("[%s] dogrula: %s — beklenen %d · Paraşüt'te bulunan %d · eksik %d\n",
    $damga, $gun, count($beklenen), count($parasut), count($eksik));
foreach ($beklenen as $cid => $ad) {
    printf("  %-9s %-18s %s\n", isset($parasut[$cid]) ? 'KESİLMİŞ' : 'EKSİK', mb_substr($ad, 0, 18),
        (string) ($parasut[$cid] ?? '—'));
}

// ── Durum 1: hepsi yerinde → SUS ─────────────────────────────────────────
if (!$eksik) {
    printf("  Tümü kesilmiş — bildirim GÖNDERİLMEDİ (kural: çözülmüş işe bildirim yok).\n");
    exit(0);
}

// ── Durum 2: eksik var → bildir ──────────────────────────────────────────
if ($kuru) {
    printf("  [PROVA] bildirim gönderilmedi. Eksik: %s\n", implode(' · ', $eksik));
    exit(1);
}

$r = $push->toAdmins(
    'Paraşüt kontrol edildi: ' . count($eksik) . ' irsaliye kesilmemiş',
    $gunTr . ' · Paraşüt\'ten kontrol edildi, şu müşterilerin irsaliyesi kesilmemiş: '
        . implode(' · ', $eksik),
    ['url' => '/bugun.php'], 'kritik', 'irsaliye_dogrula:' . $gun
);
printf("  Bildirim: %d cihaz · %d gönderildi\n", (int) $r['devices'], (int) $r['sent']);

exit(1);
