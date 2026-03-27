<?php
// Health check endpoint — Railway healthcheck
header('Content-Type: application/json; charset=utf-8');

$health = [
    'status'  => 'ok',
    'service' => 'UYSA ERP v5.0',
    'time'    => date('Y-m-d H:i:s'),
    'php'     => PHP_VERSION,
];

// DB bağlantı kontrolü
$dbHost = getenv('DB_HOST') ?: getenv('MYSQLHOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: getenv('MYSQLPORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: getenv('MYSQLDATABASE') ?: 'uysa_db';
$dbUser = getenv('DB_USER') ?: getenv('MYSQLUSER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: getenv('MYSQLPASSWORD') ?: '';

try {
    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_TIMEOUT => 5]);
    $tables = $pdo->query("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = DATABASE()")->fetch();
    $health['database'] = [
        'status' => 'connected',
        'host'   => $dbHost,
        'tables' => (int)($tables['cnt'] ?? 0),
    ];
} catch (PDOException $e) {
    $health['status'] = 'degraded';
    $health['database'] = [
        'status' => 'error',
        'host'   => $dbHost,
        'port'   => $dbPort,
        'error'  => $e->getMessage(),
    ];
}

// AI provider kontrolü
$health['ai'] = [
    'provider'  => getenv('AI_PROVIDER') ?: 'not_set',
    'has_key'   => !empty(getenv('AI_API_KEY') ?: getenv('ANTHROPIC_API_KEY')),
];

echo json_encode($health, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
