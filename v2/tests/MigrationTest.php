<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Migrasyon entegrasyon testi — SQLite fixture'ı üzerinde gerçek migrate_v1.php'yi
 * alt süreç olarak çalıştırır ve sonucu (reconciliation + DB) doğrular.
 * Mojibake onarımı + anomali atlama + tutar denkliği burada kanıtlanır.
 */
final class MigrationTest extends TestCase
{
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/uysa_mig_' . bin2hex(random_bytes(4)) . '.sqlite';
        $pdo = new PDO('sqlite:' . $this->dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec((string) file_get_contents(__DIR__ . '/../sql/schema_sqlite.sql'));

        // v1 kaynak tablosu + fixture
        $pdo->exec('CREATE TABLE uysa_storage (store_key TEXT PRIMARY KEY, store_value TEXT NOT NULL)');

        $gunluk = [
            ['musteri' => 'CANTAÅž', 'tarih' => '2026-07-01', 'kisi' => 10, 'fiyat' => 328, 'tutar' => 3280, 'not' => ''],
            ['musteri' => 'CANTAÅž', 'tarih' => '2026-07-02', 'kisi' => 20, 'fiyat' => 328, 'tutar' => 6560, 'not' => ''],
            ['musteri' => 'OPAK', 'tarih' => '2026-07-01', 'kisi' => 5, 'fiyat' => 250, 'tutar' => 1250, 'not' => ''],
            // anomali: boş müşteri adı — atlanmalı, RAPORLANMALI
            ['musteri' => '', 'tarih' => '2026-07-01', 'kisi' => 4, 'fiyat' => 250, 'tutar' => 1000, 'not' => ''],
        ];
        $ins = $pdo->prepare('INSERT INTO uysa_storage (store_key, store_value) VALUES (?, ?)');
        $ins->execute(['uysa_gunluk_uretim', json_encode($gunluk, JSON_UNESCAPED_UNICODE)]);
        $ins->execute(['uysa_customers_v1', json_encode(['customers' => ['CANTAÅž', 'OPAK']], JSON_UNESCAPED_UNICODE)]);
        $ins->execute(['uysa_gelirler', json_encode([['musteri' => 'OPAK', 'tarih' => '2026-07-01', 'tutar' => 1250, 'aciklama' => 'test']], JSON_UNESCAPED_UNICODE)]);
        $pdo = null;
    }

    protected function tearDown(): void
    {
        @unlink($this->dbFile);
    }

    public function testMigrationReconcilesAndFixesMojibake(): void
    {
        $php = PHP_BINARY;
        $script = __DIR__ . '/../tools/migrate_v1.php';
        $env = 'DB_DRIVER=sqlite DB_NAME=' . escapeshellarg($this->dbFile) . ' API_TOKEN=t';
        // Windows'ta putenv üzerinden geçireceğiz (proc_open env)
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $procEnv = array_merge($_ENV, getenv(), [
            'DB_DRIVER' => 'sqlite',
            'DB_NAME'   => $this->dbFile,
            'API_TOKEN' => 't',
            'V1_DB_NAME' => $this->dbFile,
        ]);
        $proc = proc_open([$php, $script], $descriptor, $pipes, null, $procEnv);
        $this->assertIsResource($proc);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $this->assertSame(0, $code, "migrate exit 0 olmalı. STDERR: $err\nSTDOUT: $out");
        $this->assertStringContainsString('TUTUYOR', $out, 'reconciliation denkliği');
        $this->assertStringContainsString('MİGRASYON TAMAM', $out);

        // DB doğrulaması
        $pdo = new PDO('sqlite:' . $this->dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $prodCount = (int) $pdo->query('SELECT COUNT(*) FROM production')->fetchColumn();
        $prodSum = (float) $pdo->query('SELECT COALESCE(SUM(amount),0) FROM production')->fetchColumn();
        $this->assertSame(3, $prodCount, '3 geçerli üretim satırı (1 anomali atlandı)');
        $this->assertEqualsWithDelta(11090.0, $prodSum, 0.001, '3280+6560+1250');

        // Mojibake onarıldı mı?
        $names = $pdo->query('SELECT name FROM customers ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('CANTAŞ', $names, 'CANTAÅž → CANTAŞ onarıldı');
        $this->assertNotContains('CANTAÅž', $names, 'bozuk ad kalmamalı');
    }
}
