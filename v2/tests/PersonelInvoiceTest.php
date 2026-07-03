<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/** opus-009 — Personel Giderleri + Faturalar (aylık müşteri faturası) + net karlılık. */
final class PersonelInvoiceTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    // ── Personel Giderleri ────────────────────────────────────
    public function testUpsertPersonelEditById(): void
    {
        $id = $this->repo->upsertPersonel('Ahmet Yılmaz', 'Aşçı', 42000.0);
        $same = $this->repo->upsertPersonel('Ahmet Yılmaz', 'Aşçıbaşı', 48000.0, $id);
        $this->assertSame($id, $same, 'aynı id güncellenir, yeni satır olmaz');

        $p = $this->repo->personel($id);
        $this->assertSame('Aşçıbaşı', $p['gorev']);
        $this->assertEqualsWithDelta(48000.0, (float) $p['aylik_ucret'], 0.001);
        $rows = (int) $this->pdo->query('SELECT COUNT(*) FROM personel')->fetchColumn();
        $this->assertSame(1, $rows);
    }

    public function testSetPersonelActiveHidesFromList(): void
    {
        $id = $this->repo->upsertPersonel('Pasif Kişi', 'Bulaşıkçı', 30000.0);
        $this->assertCount(1, $this->repo->listPersonel());
        $this->repo->setPersonelActive($id, false);
        $this->assertCount(0, $this->repo->listPersonel(), 'pasif liste dışı');
        $this->assertNotNull($this->repo->personel($id), 'kayıt silinmez (geçmiş bütünlüğü)');
    }

    public function testMonthPersonelTotalAndBreakdown(): void
    {
        $a = $this->repo->upsertPersonel('Ahmet', 'Aşçı', 40000.0);
        $b = $this->repo->upsertPersonel('Mehmet', 'Şoför', 35000.0);
        $this->repo->addPersonelGider($a, '2026-07-05', 'maas', 40000.0);
        $this->repo->addPersonelGider($b, '2026-07-05', 'maas', 35000.0);
        $this->repo->addPersonelGider($a, '2026-07-15', 'prim', 5000.0);
        $this->repo->addPersonelGider(null, '2026-07-20', 'sgk', 12000.0); // toplu, kişisiz
        $this->repo->addPersonelGider($b, '2026-06-30', 'maas', 35000.0);  // başka ay

        $this->assertEqualsWithDelta(92000.0, $this->repo->monthPersonelTotal('2026-07'), 0.001, '40000+35000+5000+12000');

        $byType = $this->repo->monthPersonelByType('2026-07');
        $this->assertEqualsWithDelta(75000.0, $byType['maas'], 0.001);
        $this->assertEqualsWithDelta(5000.0, $byType['prim'], 0.001);
        $this->assertEqualsWithDelta(12000.0, $byType['sgk'], 0.001);
    }

    public function testAddPersonelGiderRejectsBadType(): void
    {
        $a = $this->repo->upsertPersonel('Ahmet', 'Aşçı', 40000.0);
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->addPersonelGider($a, '2026-07-05', 'ikramiye', 1000.0);
    }

    public function testPersonelGiderSurvivesPersonelDeactivate(): void
    {
        $a = $this->repo->upsertPersonel('Ahmet', 'Aşçı', 40000.0);
        $this->repo->addPersonelGider($a, '2026-07-05', 'maas', 40000.0);
        $this->repo->setPersonelActive($a, false);
        // Pasifleştirme gideri silmez; aylık toplam korunur.
        $this->assertEqualsWithDelta(40000.0, $this->repo->monthPersonelTotal('2026-07'), 0.001);
    }

    // ── Faturalar (aylık müşteri faturası) ────────────────────
    public function testCustomerInvoiceMatchesProductionTotal(): void
    {
        $c = seed_customer($this->pdo, 'CANTAŞ', 328.0);
        $this->repo->upsertProduction($c, '2026-07-01', 10, 328.0, 'ogle');   // 3280
        $this->repo->upsertProduction($c, '2026-07-01', 5, 328.0, 'aksam');   // 1640
        $this->repo->upsertProduction($c, '2026-07-02', 20, 328.0, 'ogle');   // 6560
        $this->repo->upsertProduction($c, '2026-06-30', 99, 328.0, 'ogle');   // başka ay

        $inv = $this->repo->customerInvoice($c, '2026-07'); // KDV yok
        // ara toplam = o ay production tutar toplamı
        $prodTotal = $this->repo->customerMonthProduction($c, '2026-07')['amount'];
        $this->assertEqualsWithDelta($prodTotal, $inv['ara_toplam'], 0.001, 'fatura ara toplam = production toplamı');
        $this->assertEqualsWithDelta(11480.0, $inv['ara_toplam'], 0.001, '3280+1640+6560');
        $this->assertSame(35, $inv['persons'], '10+5+20');
        $this->assertSame(2, $inv['gun']);
        // KDV yok → genel = ara
        $this->assertEqualsWithDelta(11480.0, $inv['genel_toplam'], 0.001);
    }

    public function testCustomerInvoiceKdvOptional(): void
    {
        $c = seed_customer($this->pdo, 'OPAK', 250.0);
        $this->repo->upsertProduction($c, '2026-07-01', 100, 250.0, 'ogle'); // 25000

        $noKdv = $this->repo->customerInvoice($c, '2026-07', 0.0);
        $this->assertEqualsWithDelta(25000.0, $noKdv['genel_toplam'], 0.001);

        $kdv20 = $this->repo->customerInvoice($c, '2026-07', 20.0);
        $this->assertEqualsWithDelta(25000.0, $kdv20['ara_toplam'], 0.001);
        $this->assertEqualsWithDelta(5000.0, $kdv20['kdv_tutar'], 0.001);
        $this->assertEqualsWithDelta(30000.0, $kdv20['genel_toplam'], 0.001, 'ara × 1.20');
    }

    public function testSaveFaturaUpsert(): void
    {
        $c = seed_customer($this->pdo, 'CANTAŞ', 328.0);
        $id1 = $this->repo->saveFatura($c, '2026-07', 10000.0, 0.0, 10000.0, 'taslak');
        $id2 = $this->repo->saveFatura($c, '2026-07', 12000.0, 20.0, 14400.0, 'kesildi'); // aynı ay → güncelle
        $this->assertSame($id1, $id2, 'UNIQUE(customer,ay): tek kayıt güncellenir');

        $rows = (int) $this->pdo->query('SELECT COUNT(*) FROM fatura')->fetchColumn();
        $this->assertSame(1, $rows);

        $list = $this->repo->listFaturalar($c);
        $this->assertCount(1, $list);
        $this->assertEqualsWithDelta(14400.0, (float) $list[0]['genel_toplam'], 0.001);
        $this->assertSame('kesildi', $list[0]['durum']);
        $this->assertSame('CANTAŞ', $list[0]['customer_name']);
    }

    // ── Net karlılık entegrasyonu ─────────────────────────────
    public function testNetKarlilikIncludesPersonel(): void
    {
        $c = seed_customer($this->pdo, 'CANTAŞ', 328.0);
        $this->repo->upsertProduction($c, '2026-07-01', 100, 328.0, 'ogle'); // ciro 32800

        $this->repo->addTransaction('gider', 8000.0, '2026-07-02', 'Et/Tavuk', null);   // hammadde
        $this->repo->addTransaction('gider', 9999.0, '2026-07-03', 'Personel', null);   // HARİÇ (personel_gider tek kaynak)
        $this->repo->addPersonelGider(null, '2026-07-05', 'maas', 15000.0);              // personel

        $t = $this->repo->upsertCustomer('KARGO', 0.0, 'tasima');
        $this->repo->upsertTasimaAylik($t, '2026-07', 50000.0, 20000.0);                 // taşıma sabit gider 20000

        $nk = $this->repo->netKarlilik('2026-07');
        $this->assertEqualsWithDelta(32800.0, $nk['ciro'], 0.001);
        $this->assertEqualsWithDelta(8000.0, $nk['hammadde'], 0.001, 'Personel kategorisi hariç');
        $this->assertEqualsWithDelta(15000.0, $nk['personel'], 0.001, 'personel gideri görünür');
        $this->assertEqualsWithDelta(20000.0, $nk['tasima_gider'], 0.001);
        // net = 32800 - 8000 - 15000 - 20000 = -10200
        $this->assertEqualsWithDelta(-10200.0, $nk['net'], 0.001);
    }
}
