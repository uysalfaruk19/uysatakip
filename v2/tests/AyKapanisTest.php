<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

final class AyKapanisTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    public function testAyKapanisOkurVeEksikleriIsaretler(): void
    {
        $a = seed_customer($this->pdo, 'A', 100.0);
        seed_customer($this->pdo, 'B', 200.0);

        $this->repo->upsertProduction($a, '2026-07-01', 10, 100.0, 'ogle');
        $this->repo->addTransaction('gider', 200.0, '2026-07-05', 'Et/Tavuk', null, null, null, null, 'genel');

        $k = $this->repo->ayKapanis('2026-07');
        $byKey = [];
        foreach ($k['checks'] as $c) {
            $byKey[$c['key']] = $c;
        }

        $this->assertSame('warn', $k['status']);
        $this->assertSame('ok', $byKey['production']['status']);
        $this->assertSame('warn', $byKey['no_production']['status']);
        $this->assertSame('warn', $byKey['invoice']['status']);
        $this->assertCount(1, $k['no_production_customers']);
        $this->assertSame('B', $k['no_production_customers'][0]['name']);
        $this->assertEqualsWithDelta(1000.0, $k['summary']['toplam_gelir'], 0.001);
    }

    public function testAyKapanisSifirFiyatiFailYapar(): void
    {
        $a = seed_customer($this->pdo, 'A', 0.0);
        $this->repo->upsertProduction($a, '2026-07-01', 10, 0.0, 'ogle');

        $k = $this->repo->ayKapanis('2026-07');
        $byKey = [];
        foreach ($k['checks'] as $c) {
            $byKey[$c['key']] = $c;
        }

        $this->assertSame('fail', $k['status']);
        $this->assertSame('fail', $byKey['zero_price']['status']);
        $this->assertNotEmpty($k['zero_price_rows']);
    }
}