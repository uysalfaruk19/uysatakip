<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

final class RepoTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    public function testUpsertProductionComputesAmount(): void
    {
        $id = seed_customer($this->pdo, 'CANTAŞ', 328.00);
        $res = $this->repo->upsertProduction($id, '2026-07-03', 450, 328.00);
        $this->assertSame(147600.0, $res['amount'], 'tutar = kişi × birim fiyat');

        $tot = $this->repo->dayTotals('2026-07-03');
        $this->assertSame(450, (int) $tot['persons']);
        $this->assertEqualsWithDelta(147600.0, (float) $tot['amount'], 0.001);
    }

    public function testUpsertProductionIsIdempotentOnUniqueKey(): void
    {
        $id = seed_customer($this->pdo, 'OPAK', 250.00);
        $this->repo->upsertProduction($id, '2026-07-03', 280, 250.00);
        $this->repo->upsertProduction($id, '2026-07-03', 300, 250.00); // aynı gün tekrar → güncelle

        $rows = (int) $this->pdo->query('SELECT COUNT(*) FROM production')->fetchColumn();
        $this->assertSame(1, $rows, 'UNIQUE(customer,date,meal): tek satır kalmalı');

        $tot = $this->repo->dayTotals('2026-07-03');
        $this->assertSame(300, (int) $tot['persons'], 'son değer geçerli');
        $this->assertEqualsWithDelta(75000.0, (float) $tot['amount'], 0.001);
    }

    public function testCustomerBalance(): void
    {
        $id = seed_customer($this->pdo, 'ERMETAL', 200.00);
        $this->repo->addCari('customer', $id, '2026-07-01', 'borc', 50000);
        $this->repo->addCari('customer', $id, '2026-07-05', 'alacak', 20000); // tahsilat
        $this->assertEqualsWithDelta(30000.0, $this->repo->customerBalance($id), 0.001);
    }

    public function testMonthFinanceTotals(): void
    {
        $this->repo->addTransaction('gelir', 100000, '2026-07-03', 'uretim', null);
        $this->repo->addTransaction('gider', 40000, '2026-07-04', 'Et/Tavuk', null);
        $this->repo->addTransaction('gider', 10000, '2026-06-30', 'Kira', null); // başka ay
        $fin = $this->repo->monthFinanceTotals('2026-07');
        $this->assertEqualsWithDelta(100000.0, $fin['gelir'], 0.001);
        $this->assertEqualsWithDelta(40000.0, $fin['gider'], 0.001);
        $this->assertEqualsWithDelta(60000.0, $fin['net'], 0.001);
    }

    public function testDayGridMarksMissingCustomers(): void
    {
        $a = seed_customer($this->pdo, 'CANTAŞ', 328);
        seed_customer($this->pdo, 'OPAK', 250);
        $this->repo->upsertProduction($a, '2026-07-03', 450, 328);
        $grid = $this->repo->dayGrid('2026-07-03');
        $this->assertCount(2, $grid);
        $byName = [];
        foreach ($grid as $r) {
            $byName[$r['name']] = $r['persons'];
        }
        $this->assertSame(450, (int) $byName['CANTAŞ']);
        $this->assertNull($byName['OPAK'], 'girilmeyen müşteri NULL (eksik işareti)');
    }
}
