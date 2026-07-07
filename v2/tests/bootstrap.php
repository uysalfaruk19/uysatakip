<?php
declare(strict_types=1);

/** Test bootstrap — SQLite in-memory, üretim MySQL şemasından bağımsız çalışır. */

putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=:memory:');
putenv('API_TOKEN=test-token-123');
$_ENV['DB_DRIVER'] = 'sqlite';

require __DIR__ . '/../src/bootstrap.php';

use Uysa\Db;

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
