<?php

declare(strict_types=1);

/**
 * fable-047 — RESMİ TATİL ÖN UYARISI (Ömer: "her resmi tatilden 3 gün önce bana bildirim
 * göndersin — çalışan çalışmayan müşterileri netleştir, ekmek vs siparişlerini güncelle").
 *
 * 3 gün sonrası aktif bir resmi tatile denk geliyorsa uyarı metni üretir:
 *   · geçen benzer tatilde kim çalışmıştı / çalışmamıştı (production.persons)
 *   · o güne henüz sayı girilmemiş müşteriler
 *   · o tarihe düşen sipariş/teslimat (ekmek vb.) → iptal/güncelle hatırlatması
 *
 * Çıktı iki kanala gider:
 *   1) STDOUT  → cron bunu WhatsApp'a köprüler (Fable kurar)
 *   2) push    → admin cihazlarına (Push sınıfı varsa; yoksa sessiz atlanır)
 * İDEMPOTENT: aynı tatil için gün içinde ikinci kez çalışırsa TEKRAR GÖNDERMEZ (audit izi).
 *
 * Kullanım:  php tools/tatil_uyari.php [YYYY-MM-DD=bugün]
 * Cron:      0 9 * * * docker exec uysatakip-v2 php /var/www/html/tools/tatil_uyari.php
 * Kill-switch: cron dosyasını sil.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Uysa\Db;
use Uysa\Repo;

$bugun = $argv[1] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bugun)) {
    fwrite(STDERR, "Geçersiz tarih: $bugun\n");
    exit(2);
}

$pdo = Db::pdo();
$repo = new Repo($pdo);

$tatil = $repo->yaklasanTatil(3, $bugun);
if ($tatil === null) {
    echo "[$bugun] 3 gün sonrası için resmi tatil yok — uyarı üretilmedi.\n";
    exit(0);
}

$tarih = (string) $tatil['tarih'];
$anahtar = 'tatil_uyari:' . $tarih;

// İdempotent kalkan: bu tatil için daha önce uyarı üretilmişse tekrar gönderme.
$st = $pdo->prepare("SELECT COUNT(*) FROM audit WHERE action = 'tatil_uyari' AND target_key = ?");
$st->execute([$tarih]);
if ((int) $st->fetchColumn() > 0) {
    echo "[$bugun] $tarih tatili için uyarı ZATEN gönderilmiş — atlandı (idempotent).\n";
    exit(0);
}

$gunAdi = ['Mon' => 'Pazartesi', 'Tue' => 'Salı', 'Wed' => 'Çarşamba', 'Thu' => 'Perşembe', 'Fri' => 'Cuma', 'Sat' => 'Cumartesi', 'Sun' => 'Pazar'];
$d = $repo->tatilDavranis($tarih);
$sip = $repo->tatilSiparisleri($tarih);

$satirlar = [];
$satirlar[] = '🗓️ ' . $d['ad'] . ' — ' . date('d.m.Y', strtotime($tarih)) . ' ' . ($gunAdi[date('D', strtotime($tarih))] ?? '') . ' (3 gün kaldı)';
if ($d['yarim_gun']) {
    $satirlar[] = 'Yarım gün (arefe) — üretim genelde yapılır, sayıları teyit et.';
}
$satirlar[] = '';

// 1) Geçen benzer tatilde davranış
if (!$d['onceki_veri']) {
    $satirlar[] = 'Geçmiş tatil verisi yok — hangi müşteri çalışacak, elle teyit edilmeli.';
} else {
    $oncekiEt = $d['onceki'] ? (string) $d['onceki']['ad'] . ' (' . date('d.m.Y', strtotime((string) $d['onceki']['tarih'])) . ')' : 'geçen tatil';
    $calisanlar = [];
    $calismayanlar = [];
    foreach ($d['rows'] as $r) {
        if ((string) $r['gecmis'] === 'calisti') {
            $calisanlar[] = (string) $r['name'] . ' (' . (int) $r['onceki_kisi'] . ' kişi)';
        } else {
            $calismayanlar[] = (string) $r['name'];
        }
    }
    $satirlar[] = 'GEÇEN TATİLDE (' . $oncekiEt . '):';
    $satirlar[] = '• Çalışan: ' . ($calisanlar ? implode(', ', $calisanlar) : 'yok');
    $satirlar[] = '• Çalışmayan: ' . ($calismayanlar ? implode(', ', $calismayanlar) : 'yok');
}
$satirlar[] = '';

// 2) Bu tatile sayısı girilmemiş müşteriler
$kayitsizAdlar = [];
foreach ($d['rows'] as $r) {
    if (empty($r['kayit'])) {
        $kayitsizAdlar[] = (string) $r['name'];
    }
}
if ($kayitsizAdlar) {
    $satirlar[] = 'NETLEŞTİRİLECEK (bu tatile sayı girilmemiş): ' . implode(', ', $kayitsizAdlar);
} else {
    $satirlar[] = 'Tüm müşterilerin bu tatil için sayısı girilmiş ✔';
}

// 3) O tarihe düşen sipariş/teslimat — ekmek vb. güncelleme hatırlatması
$sipAdet = count($sip['siparis'] ?? []);
$tesAdet = count($sip['teslimat'] ?? []);
$satirlar[] = '';
if ($sipAdet > 0 || $tesAdet > 0) {
    $satirlar[] = 'SİPARİŞ/TESLİMAT: o güne ' . $sipAdet . ' sipariş · ' . $tesAdet . ' teslimat kayıtlı — çalışmayan müşteriler için iptal/güncelle.';
} else {
    $satirlar[] = 'SİPARİŞ HATIRLATMASI: ekmek vb. düzenli tedarikleri çalışmayan müşteriler için iptal etmeyi/güncellemeyi unutma.';
}

$mesaj = implode("\n", $satirlar);
echo $mesaj . "\n";

// Push (varsa) — sessiz best-effort, uyarı üretimini bloklamaz.
$pushSonuc = 'push yok';
try {
    if (class_exists('\\Uysa\\Push')) {
        $push = new \Uysa\Push($pdo);
        if (method_exists($push, 'adminBildir')) {
            $push->adminBildir($d['ad'] . ' — 3 gün kaldı', $kayitsizAdlar
                ? count($kayitsizAdlar) . ' müşterinin sayısı girilmemiş'
                : 'çalışan/çalışmayan müşterileri teyit et');
            $pushSonuc = 'push gönderildi';
        }
    }
} catch (\Throwable $e) {
    $pushSonuc = 'push hata: ' . $e->getMessage();
}

uysa_audit('tatil_uyari', 'cron', $tarih, json_encode([
    'ad' => $d['ad'], 'kayitsiz' => count($kayitsizAdlar), 'push' => $pushSonuc,
], JSON_UNESCAPED_UNICODE), 'local');

fwrite(STDERR, "[$bugun] uyarı üretildi ($tarih · $pushSonuc)\n");
exit(0);
