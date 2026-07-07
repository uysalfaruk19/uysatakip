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

    public function testKumanyaMealAccepted(): void
    {
        $id = seed_customer($this->pdo, 'TALAY', 235.00);
        // Aynı gün 3 öğün AYRI satır (öğün kırılımı senaryosu)
        $this->repo->upsertProduction($id, '2026-07-01', 140, 235, 'ogle');
        $this->repo->upsertProduction($id, '2026-07-01', 48, 235, 'aksam');
        $this->repo->upsertProduction($id, '2026-07-01', 12, 235, 'kumanya');
        $rows = (int) $this->pdo->query("SELECT COUNT(*) FROM production WHERE meal='kumanya'")->fetchColumn();
        $this->assertSame(1, $rows, 'kumanya öğünü kabul edilmeli (ENUM/CHECK)');
        $all = (int) $this->pdo->query('SELECT COUNT(*) FROM production')->fetchColumn();
        $this->assertSame(3, $all, '3 öğün = 3 satır (UNIQUE customer,date,meal)');
    }

    public function testCustomerMonthProduction(): void
    {
        $id = seed_customer($this->pdo, 'CANTAŞ', 328);
        $this->repo->upsertProduction($id, '2026-07-01', 10, 328, 'ogle');
        $this->repo->upsertProduction($id, '2026-07-15', 20, 328, 'ogle');
        $this->repo->upsertProduction($id, '2026-06-30', 5, 328, 'ogle'); // başka ay
        $m = $this->repo->customerMonthProduction($id, '2026-07');
        $this->assertSame(30, $m['persons']);
        $this->assertEqualsWithDelta(9840.0, $m['amount'], 0.001, '(10+20)*328');
        $this->assertSame(2, $m['cnt']);
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

    // ── opus-006: müşteri kategorisi + taşıma kâr + drill-down ──
    public function testUpsertCustomerCategoryGroups(): void
    {
        $this->repo->upsertCustomer('CANTAŞ', 328.0, 'uretim');
        $this->repo->upsertCustomer('X LOJİSTİK', 0.0, 'tasima');
        $this->repo->upsertCustomer('OPAK', 250.0); // varsayılan üretim

        $uretim = $this->repo->listCustomersByCategory('uretim');
        $tasima = $this->repo->listCustomersByCategory('tasima');
        $this->assertCount(2, $uretim, 'CANTAŞ + OPAK üretim');
        $this->assertCount(1, $tasima, 'X LOJİSTİK taşıma');
        $this->assertSame('X LOJİSTİK', $tasima[0]['name']);
        $this->assertSame('tasima', $tasima[0]['category']);
    }

    public function testUpsertCustomerEditByIdChangesCategory(): void
    {
        $id = $this->repo->upsertCustomer('DENEME', 100.0, 'uretim');
        $same = $this->repo->upsertCustomer('DENEME AŞ', 120.0, 'tasima', $id);
        $this->assertSame($id, $same, 'aynı id güncellenir');
        $c = $this->repo->customer($id);
        $this->assertSame('DENEME AŞ', $c['name']);
        $this->assertSame('tasima', $c['category']);
        $this->assertEqualsWithDelta(120.0, (float) $c['unit_price'], 0.001);
        $rows = (int) $this->pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        $this->assertSame(1, $rows, 'yeni satır oluşmaz (düzenleme)');
    }

    public function testSetCustomerActiveHidesFromList(): void
    {
        $id = $this->repo->upsertCustomer('PASIF AŞ', 0.0, 'tasima');
        $this->assertCount(1, $this->repo->listCustomersByCategory('tasima'));
        $this->repo->setCustomerActive($id, false);
        $this->assertCount(0, $this->repo->listCustomersByCategory('tasima'), 'pasif liste dışı');
        $this->assertNotNull($this->repo->customer($id), 'kayıt silinmez (FK bütünlüğü)');
    }

    // ── opus-013: taşıma = 4-alan kart + adet(production)×(satış−alış)−sabit ──
    public function testTasimaProfitFromProductionCounts(): void
    {
        // Taşıma kartı: satış(unit_price) 120, alış(maliyet_birim) 80, sabit 500, not
        $id = $this->repo->upsertCustomer('KARGO AŞ', 120.0, 'tasima', null, null, null, null, 80.0, 500.0, '2 araç');
        // adet Bugün sayımlarından: 07-01 öğle 60 + 07-10 öğle 40 = 100
        $this->repo->upsertProduction($id, '2026-07-01', 60, 120.0, 'ogle');
        $this->repo->upsertProduction($id, '2026-07-10', 40, 120.0, 'ogle');
        // brüt = 100×(120−80)=4000; net = 4000−500=3500
        $this->assertEqualsWithDelta(3500.0, $this->repo->tasimaKar($id, '2026-07'), 0.001);

        $rec = $this->repo->tasimaProfit($id, '2026-07');
        $this->assertEqualsWithDelta(100.0, (float) $rec['adet'], 0.001, 'adet = production.persons toplamı');
        $this->assertEqualsWithDelta(8000.0, (float) $rec['toplam_alis'], 0.001);
        $this->assertEqualsWithDelta(12000.0, (float) $rec['toplam_satis'], 0.001);
        $this->assertEqualsWithDelta(4000.0, (float) $rec['brut'], 0.001);
        $this->assertEqualsWithDelta(3500.0, (float) $rec['net'], 0.001);
        $this->assertEqualsWithDelta(3500.0, (float) $rec['kar'], 0.001, 'kar = net (geriye dönük)');
        $this->assertSame('2 araç', $rec['note']);

        // Başka ay adet toplamına girmez (07 sadece 100)
        $this->repo->upsertProduction($id, '2026-06-30', 999, 120.0, 'ogle');
        $this->assertEqualsWithDelta(100.0, (float) $this->repo->tasimaProfit($id, '2026-07')['adet'], 0.001);

        // Sabit gider 0 → net = brüt
        $id2 = $this->repo->upsertCustomer('KARGO2', 120.0, 'tasima', null, null, null, null, 80.0, 0.0);
        $this->repo->upsertProduction($id2, '2026-07-01', 100, 120.0, 'ogle');
        $this->assertEqualsWithDelta(4000.0, $this->repo->tasimaKar($id2, '2026-07'), 0.001, 'sabit gider yok → net=brüt');
    }

    public function testMonthTasimaTotals(): void
    {
        // A: satış 160, alış 100, sabit 60000; adet 1000 → brüt 60000, net 0
        $a = $this->repo->upsertCustomer('KARGO A', 160.0, 'tasima', null, null, null, null, 100.0, 60000.0);
        $this->repo->upsertProduction($a, '2026-07-01', 1000, 160.0, 'ogle');
        // B: satış 180, alış 100, sabit 90000; adet 1000 → brüt 80000, net -10000 (zarar)
        $b = $this->repo->upsertCustomer('KARGO B', 180.0, 'tasima', null, null, null, null, 100.0, 90000.0);
        $this->repo->upsertProduction($b, '2026-07-01', 1000, 180.0, 'ogle');
        // başka ay (toplama girmemeli)
        $this->repo->upsertProduction($a, '2026-06-15', 500, 160.0, 'ogle');
        $tot = $this->repo->monthTasimaTotals('2026-07');
        $this->assertEqualsWithDelta(2000.0, $tot['adet'], 0.001, '1000+1000');
        $this->assertEqualsWithDelta(200000.0, $tot['alis'], 0.001, '(1000×100)+(1000×100)');
        $this->assertEqualsWithDelta(340000.0, $tot['satis'], 0.001, '(1000×160)+(1000×180)');
        $this->assertEqualsWithDelta(150000.0, $tot['gider'], 0.001);
        $this->assertEqualsWithDelta(140000.0, $tot['brut'], 0.001, '60000+80000');
        $this->assertEqualsWithDelta(-10000.0, $tot['net'], 0.001, '0 + (-10000)');
    }

    public function testNetKarlilikExcludesTasimaFromUretimCiro(): void
    {
        // ÜRETİM müşterisi (kişi×fiyat): ciro 50×100 = 5000
        $u = seed_customer($this->pdo, 'ÜRETİM AŞ', 100.0);
        $this->repo->upsertProduction($u, '2026-07-01', 50, 100.0, 'ogle');
        // TAŞIMA müşterisi: satış 120, alış 80; adet 100 → production tutarı 12000 ama üretim cirosu DEĞİL
        $t = $this->repo->upsertCustomer('TAŞIMA AŞ', 120.0, 'tasima', null, null, null, null, 80.0, 0.0);
        $this->repo->upsertProduction($t, '2026-07-01', 100, 120.0, 'ogle');

        $nk = $this->repo->netKarlilik('2026-07');
        $this->assertEqualsWithDelta(5000.0, $nk['ciro'], 0.001, 'üretim cirosu taşımayı içermez (12000 hariç)');
        $this->assertEqualsWithDelta(4000.0, $nk['tasima_kar'], 0.001, '100×(120−80)=4000');
        // net = 5000 - 0 - 0 + 4000 = 9000
        $this->assertEqualsWithDelta(9000.0, $nk['net'], 0.001);
        // monthUretimCiro doğrudan da taşımasız
        $this->assertEqualsWithDelta(5000.0, $this->repo->monthUretimCiro('2026-07'), 0.001);
    }

    public function testCustomerDailyGridBreakdownAndScope(): void
    {
        $a = seed_customer($this->pdo, 'CANTAŞ', 328);
        $b = seed_customer($this->pdo, 'OPAK', 250);
        // A: 07-01 öğle 10 + akşam 5 + kumanya 4 ; 07-02 öğle 20
        $this->repo->upsertProduction($a, '2026-07-01', 10, 328, 'ogle');
        $this->repo->upsertProduction($a, '2026-07-01', 5, 328, 'aksam');
        $this->repo->upsertProduction($a, '2026-07-01', 4, 328, 'kumanya');
        $this->repo->upsertProduction($a, '2026-07-02', 20, 328, 'ogle');
        // B: başka müşteri — A'nın grid'inde görünmemeli
        $this->repo->upsertProduction($b, '2026-07-01', 99, 250, 'ogle');
        $this->repo->upsertProduction($a, '2026-06-30', 7, 328, 'ogle'); // başka ay

        $grid = $this->repo->customerDailyGrid($a, '2026-07');
        $this->assertCount(2, $grid, 'temmuz 2 gün (haziran hariç)');

        $day1 = $grid[0];
        $this->assertSame('2026-07-01', $day1['gun']);
        $this->assertSame(10, (int) $day1['ogle']);
        $this->assertSame(5, (int) $day1['aksam']);
        $this->assertSame(4, (int) $day1['kumanya']);
        $this->assertSame(19, (int) $day1['kisi'], 'gün toplam 10+5+4');
        $this->assertEqualsWithDelta(19 * 328.0, (float) $day1['tutar'], 0.001);

        $day2 = $grid[1];
        $this->assertSame(20, (int) $day2['kisi']);
        // Scope: B'nin 99 kişisi A'nın toplamına karışmadı
        $allKisi = array_sum(array_column($grid, 'kisi'));
        $this->assertSame(39, $allKisi, 'sadece A: 19 + 20');
    }

    public function testMonthProductionByCustomerHasIdAndCategory(): void
    {
        $a = seed_customer($this->pdo, 'CANTAŞ', 328);
        $this->repo->upsertProduction($a, '2026-07-01', 10, 328, 'ogle');
        // Taşıma müşterisi de production'a düşer ama kategori filtresiyle ayrılır
        $t = $this->repo->upsertCustomer('KARGO', 120.0, 'tasima', null, null, null, null, 80.0, 0.0);
        $this->repo->upsertProduction($t, '2026-07-01', 5, 120.0, 'ogle');

        $rows = $this->repo->monthProductionByCustomer('2026-07');
        $this->assertCount(2, $rows, 'filtre yok → üretim + taşıma');
        $uretim = $this->repo->monthProductionByCustomer('2026-07', 'uretim');
        $this->assertCount(1, $uretim, 'kategori filtresi: sadece üretim');
        $this->assertSame($a, (int) $uretim[0]['customer_id'], 'drill-down için id döner');
        $this->assertSame('uretim', $uretim[0]['category']);
    }
}
