<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * opus-020 — Kokpit bot API'sinin dayandığı Repo davranışları:
 *   publishedMenuItems (GET /api/menu), customerCiroMap (POST /api/gider ciro-oranlı dağıtım),
 *   customerNetKarlilik + customerMonthProduction (GET /api/musteri).
 * (Endpoint HTTP/token/401/422 davranışı gerçek php -S + curl smoke ile doğrulanır.)
 */
final class BotApiTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    private function seedMenu(string $title, string $start, string $end, string $status): int
    {
        $this->pdo->prepare(
            'INSERT INTO menu (title, date_start, date_end, audience, status) VALUES (?, ?, ?, ?, ?)'
        )->execute([$title, $start, $end, 'all', $status]);
        return (int) $this->pdo->lastInsertId();
    }

    // ── /api/menu: yalnız yayınlanmış menü, tarih + öğün filtreli ──
    public function testPublishedMenuItemsRangeVeOgun(): void
    {
        $pub = $this->seedMenu('Temmuz 1. hafta', '2026-07-06', '2026-07-12', 'published');
        $this->repo->upsertMenuItem($pub, '2026-07-06', 'ogle', "Mercimek\nTavuk sote\nPilav");
        $this->repo->upsertMenuItem($pub, '2026-07-06', 'aksam', "Ezogelin\nKöfte");
        $this->repo->upsertMenuItem($pub, '2026-07-07', 'ogle', "Domates çorba\nNohut");

        $draft = $this->seedMenu('Taslak', '2026-07-06', '2026-07-12', 'draft');
        $this->repo->upsertMenuItem($draft, '2026-07-06', 'ogle', "GÖRÜNMEZ");

        // Tek gün, tüm öğünler → 2 kalem (draft hariç)
        $day = $this->repo->publishedMenuItems('2026-07-06', '2026-07-06');
        $this->assertCount(2, $day);
        $dishes = array_column($day, 'dishes');
        $this->assertStringContainsString('Tavuk sote', implode('|', $dishes));
        $this->assertStringNotContainsString('GÖRÜNMEZ', implode('|', $dishes), 'taslak menü sızmamalı');

        // Öğün filtresi → sadece öğle
        $ogle = $this->repo->publishedMenuItems('2026-07-06', '2026-07-06', 'ogle');
        $this->assertCount(1, $ogle);
        $this->assertSame('ogle', $ogle[0]['meal']);

        // Hafta aralığı → 3 kalem
        $week = $this->repo->publishedMenuItems('2026-07-06', '2026-07-12');
        $this->assertCount(3, $week);
    }

    // ── /api/gider: ciro-oranlı dağıtım temeli (customerCiroMap) ──
    public function testCiroMapGiderDagitimTemeli(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 100, 1.0, 'ogle'); // ciro 100
        $this->repo->upsertProduction($b, '2026-07-01', 300, 1.0, 'ogle'); // ciro 300

        $ciro = $this->repo->customerCiroMap('2026-07');
        $this->assertEqualsWithDelta(100.0, $ciro[$a], 0.001);
        $this->assertEqualsWithDelta(300.0, $ciro[$b], 0.001);

        // 400 TL genel gider → A %25 (100), B %75 (300) (endpoint'in yaptığı split)
        $this->repo->addTransaction('gider', 400.0, '2026-07-05', null, 'genel', null, null, null, 'genel');
        $d = $this->repo->giderDagitim('2026-07');
        $this->assertEqualsWithDelta(100.0, $d['per_customer'][$a], 0.001);
        $this->assertEqualsWithDelta(300.0, $d['per_customer'][$b], 0.001);
    }

    // ── /api/musteri: bu ay net kâr = ciro − payGider − payPersonel ──
    public function testMusteriBuAyNetKarlilik(): void
    {
        $a = seed_customer($this->pdo, 'Cantaş', 10.0);
        $this->repo->upsertProduction($a, '2026-07-02', 50, 10.0, 'ogle'); // ciro 500
        $this->repo->addTransaction('gider', 200.0, '2026-07-03', null, 'A gideri', null, null, null, 'musteri', [$a]);

        $prod = $this->repo->customerMonthProduction($a, '2026-07');
        $this->assertSame(50, $prod['persons']);
        $this->assertEqualsWithDelta(500.0, $prod['amount'], 0.001);

        $nk = $this->repo->customerNetKarlilik($a, '2026-07');
        $this->assertEqualsWithDelta(500.0, $nk['ciro'], 0.001);
        $this->assertEqualsWithDelta(200.0, $nk['pay_gider'], 0.001);
        $this->assertEqualsWithDelta(300.0, $nk['net'], 0.001, 'net = 500 - 200');
    }
}
