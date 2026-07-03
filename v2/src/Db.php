<?php
declare(strict_types=1);

namespace Uysa;

use PDO;
use PDOException;

/**
 * PDO bağlantı fabrikası. Üretim = MySQL/MariaDB (utf8mb4).
 * Test/CI = SQLite (DB_DRIVER=sqlite, DB_NAME=:memory: veya dosya).
 * Şema uygulama: applySchema() doğru .sql dosyasını çalıştırır.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        Env::load();
        $driver = Env::get('DB_DRIVER', 'mysql');

        try {
            if ($driver === 'sqlite') {
                $name = Env::get('DB_NAME', ':memory:');
                self::$pdo = new PDO('sqlite:' . $name, null, null, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                self::$pdo->exec('PRAGMA foreign_keys = ON');
            } else {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    Env::get('DB_HOST', '127.0.0.1'),
                    Env::get('DB_PORT', '3306'),
                    Env::get('DB_NAME', 'uysa_db')
                );
                self::$pdo = new PDO($dsn, Env::get('DB_USER', 'root'), Env::get('DB_PASS', ''), [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
                ]);
            }
        } catch (PDOException $e) {
            error_log('[UYSA v2] DB error: ' . $e->getMessage());
            throw $e;
        }
        return self::$pdo;
    }

    public static function driver(): string
    {
        return self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** Test/kurulum için: bağlantıyı elle enjekte et (SQLite in-memory paylaşımı). */
    public static function setPdo(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    /** Doğru şema dosyasını çalıştır (mysql/sqlite). */
    public static function applySchema(?PDO $pdo = null): void
    {
        $pdo ??= self::pdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $file = dirname(__DIR__) . '/sql/' . ($driver === 'sqlite' ? 'schema_sqlite.sql' : 'schema_v2.sql');
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException("Şema dosyası okunamadı: $file");
        }
        // SQLite: PDO tek exec ile çoklu ifadeyi işler; MySQL de öyle (emulate kapalı olsa da DDL grubu çalışır).
        $pdo->exec($sql);
    }
}
