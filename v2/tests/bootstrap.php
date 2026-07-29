<?php
declare(strict_types=1);

/** Test bootstrap — SQLite in-memory, üretim MySQL şemasından bağımsız çalışır. */

putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=:memory:');
putenv('API_TOKEN=test-token-123');
// fable-042: "bugün"ü uzak geleceğe dondur → mevcut testlerin tüm ayları GEÇMİŞ = TAM ay
// (cari-ay MTD kesimi tetiklenmez; birebir regresyon). MTD testleri Repo::setBugun ile override eder.
putenv('APP_TODAY=2099-12-31');
$_ENV['DB_DRIVER'] = 'sqlite';

require __DIR__ . '/../src/bootstrap.php';

use Uysa\Db;

// fable-052 GÜVENLİK AĞI: hiçbir test GERÇEK mail göndermez ve Paraşüt'e ÇIKMAZ.
// (Testler kendi mock'unu kurar; kuran unutsa bile buradaki reddeden katman tutar.)
Uysa\Mail::tasiyiciAta(static fn(array $z): array => ['ok' => false, 'mesaj' => 'TEST: SMTP kapalı']);
Uysa\ParasutPdf::agAta(
    static fn(string $path, array $query): ?array => null,
    static fn(string $url): ?string => null
);

/** Her test için taze şemalı SQLite PDO döndür ve Db'ye enjekte et. */
function fresh_db(): PDO
{
    Db::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    Db::setPdo($pdo);
    Db::applySchema($pdo);
    return $pdo;
}

function seed_customer(PDO $pdo, string $name, float $price): int
{
    $pdo->prepare('INSERT INTO customers (name, unit_price) VALUES (?, ?)')->execute([$name, $price]);
    return (int) $pdo->lastInsertId();
}
