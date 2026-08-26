<?php
declare(strict_types=1);
/**
 * Lokal geliştirme için demo veri (SADECE dev — canlıya gitmez, .gitignore'da).
 * Aksiyon deseni ekranlarını gerçek veriyle görebilmek için: 6 müşteri, aylık fiyat,
 * son 5 haftanın üretimi (öneri algoritması buna bakar), bugünün kısmi girişi,
 * gıda giderleri (tahmini kâr), bekleyen sipariş + talep (Gelen ekranı).
 */
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Db;

// CANLI KORUMASI: bu script yalnız SQLite dev veritabanında çalışır. Üretimde (MySQL)
// çağrılırsa hiçbir şey yazmadan durur — demo müşteri/üretim kaydı canlıya sızmasın.
if (Uysa\Env::get('DB_DRIVER', 'mysql') !== 'sqlite') {
    fwrite(STDERR, "dev-seed: yalnız SQLite dev veritabanında çalışır (DB_DRIVER=sqlite). Durduruldu.
");
    exit(1);
}

$pdo = Db::pdo();
$today = new DateTimeImmutable('today');
$ay = $today->format('Y-m');

$musteriler = [
    ['BOMİ', 265.00, [75, 78, 72, 75, 74]],
    ['CANTAŞ', 260.00, [70, 68, 71, 70, 69]],
    ['ERMETAL', 240.00, [17, 16, 18, 17, 17]],
    ['MARMARA TEKNİK', 275.00, [70, 72, 68, 70, 71]],
    ['PENDORYA', 255.00, [58, 84, 86, 85, 85]],   // bugün belirgin düşük → anomali
    ['TALAY LOJİSTİK', 0.00, [45, 44, 46, 45, 45]], // fiyatı yok → onay pasif olmalı
];

$pdo->beginTransaction();

foreach ($musteriler as [$ad, $fiyat, $gecmis]) {
    $st = $pdo->prepare('SELECT id FROM customers WHERE name = ?');
    $st->execute([$ad]);
    $cid = (int) ($st->fetchColumn() ?: 0);
    if (!$cid) {
        $pdo->prepare('INSERT INTO customers (name, unit_price, category, irsaliye_aktif) VALUES (?, ?, ?, 1)')
            ->execute([$ad, $fiyat, 'uretim']);
        $cid = (int) $pdo->lastInsertId();
    }
    if ($fiyat > 0) {
        $pdo->prepare('INSERT OR IGNORE INTO customer_price (customer_id, ay, unit_price) VALUES (?, ?, ?)')
            ->execute([$cid, $ay, $fiyat]);
    }

    // Geçmiş 4 hafta, aynı gün (öneri = bunların ortalaması) + bugün (kısmi)
    foreach ([4, 3, 2, 1] as $i) {
        $g = $today->modify("-{$i} week")->format('Y-m-d');
        $kisi = $gecmis[$i];
        $pdo->prepare(
            'INSERT OR IGNORE INTO production (customer_id, prod_date, meal, persons, unit_price_snap, amount, entered_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$cid, $g, 'ogle', $kisi, $fiyat, $kisi * $fiyat, 'uysa']);
    }

    // Bugün: ilk dört müşteri girilmiş, son ikisi bekliyor (mockup'taki 4/6 durumu)
    if (in_array($ad, ['BOMİ', 'CANTAŞ', 'ERMETAL', 'PENDORYA'], true)) {
        $kisi = $gecmis[0];
        $pdo->prepare(
            'INSERT OR IGNORE INTO production (customer_id, prod_date, meal, persons, unit_price_snap, amount, entered_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$cid, $today->format('Y-m-d'), 'ogle', $kisi, $fiyat, $kisi * $fiyat, 'uysa']);
    }
}

// Gıda giderleri — kişi başı gıda maliyeti buradan hesaplanır
$giderler = [
    ['ÖRS GROUP', 'gıda', 218450.00],
    ['KIRMIZI 1', 'gıda', 187230.00],
    ['SÜTAŞ BAYİ', 'gıda', 96480.00],
];
foreach ($giderler as $i => [$firma, $kat, $tutar]) {
    $g = $today->modify('-' . (3 + $i) . ' day')->format('Y-m-d');
    $st = $pdo->prepare('SELECT id FROM transactions WHERE description = ? AND tx_date = ?');
    $st->execute([$firma, $g]);
    if (!$st->fetchColumn()) {
        $pdo->prepare('INSERT INTO transactions (type, category, tx_date, amount, description, source) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['gider', $kat, $g, $tutar, $firma, 'manuel']);
    }
}

$pdo->commit();

$say = fn(string $t): int => (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
printf(
    "Demo veri hazır: %d müşteri · %d üretim kaydı · %d gider · fiyat kaydı %d\n",
    $say('customers'),
    $say('production'),
    $say('transactions'),
    $say('customer_price')
);
