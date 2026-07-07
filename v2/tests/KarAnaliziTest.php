<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * opus-015 — Gider ciro-oranlı dağıtım + müşteri bazlı net kâr + Kâr Analizi.
 * ciro (üretim: production.amount; taşıma: adet×birim_satis) oranında gider paylaşımı,
 * customerNetKarlilik = ciro − payGider − payPersonel (taşıma −alış−sabit),
 * karAnalizi toplam = netKarlilik net = Σ customerNet (BİREBİR).
 */
final class KarAnaliziTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    // ── Gider dağıtımı: genel = ciro oranlı ───────────────────
    public function testGiderDagitimGenelCiroOranli(): void
    {
        // A ciro 100 (100 kişi × 1), B ciro 300 (300 × 1) → %25 / %75
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 100, 1.0, 'ogle');
        $this->repo->upsertProduction($b, '2026-07-01', 300, 1.0, 'ogle');
        $this->repo->addTransaction('gider', 1000.0, '2026-07-05', null, 'genel gider', null, null, null, 'genel');

        $d = $this->repo->giderDagitim('2026-07');
        $this->assertEqualsWithDelta(250.0, $d['per_customer'][$a], 0.001, 'A %25');
        $this->assertEqualsWithDelta(750.0, $d['per_customer'][$b], 0.001, 'B %75');
        $this->assertEqualsWithDelta(0.0, $d['dagitilmamis'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $d['toplam'], 0.001);
    }

    // ── Gider dağıtımı: seçili tek müşteri = %100 ─────────────
    public function testGiderDagitimSeciliTekMusteri(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 100, 1.0, 'ogle');
        $this->repo->upsertProduction($b, '2026-07-01', 300, 1.0, 'ogle');
        $this->repo->addTransaction('gider', 500.0, '2026-07-05', null, 'sadece A', null, null, null, 'musteri', [$a]);

        $d = $this->repo->giderDagitim('2026-07');
        $this->assertEqualsWithDelta(500.0, $d['per_customer'][$a], 0.001, 'A %100');
        $this->assertArrayNotHasKey($b, $d['per_customer'], 'B pay almaz');
    }

    // ── Gider dağıtımı: seçili çok müşteri = kendi ciroları oranında ─
    public function testGiderDagitimSeciliCokMusteri(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 1.0);
        $c = seed_customer($this->pdo, 'C', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 100, 1.0, 'ogle'); // ciro 100
        $this->repo->upsertProduction($b, '2026-07-01', 300, 1.0, 'ogle'); // ciro 300
        $this->repo->upsertProduction($c, '2026-07-01', 999, 1.0, 'ogle'); // hedef değil
        // A+B hedef (toplam ciro 400) → A %25, B %75
        $this->repo->addTransaction('gider', 800.0, '2026-07-05', null, 'A ve B', null, null, null, 'musteri', [$a, $b]);

        $d = $this->repo->giderDagitim('2026-07');
        $this->assertEqualsWithDelta(200.0, $d['per_customer'][$a], 0.001, 'A 800×100/400');
        $this->assertEqualsWithDelta(600.0, $d['per_customer'][$b], 0.001, 'B 800×300/400');
        $this->assertArrayNotHasKey($c, $d['per_customer'], 'C hedef değil, pay almaz');
    }

    // ── Müşteri net kârı: üretim (ciro − payGider − payPersonel) ─
    public function testCustomerNetKarlilikUretim(): void
    {
        $a = seed_customer($this->pdo, 'A', 100.0);
        $this->repo->upsertProduction($a, '2026-07-01', 100, 100.0, 'ogle'); // ciro 10000
        $this->repo->addTransaction('gider', 2000.0, '2026-07-05', null, null, null, null, null, 'genel');
        // personel brüt 10000 genel → yüklü = 10000 + 2250 + 833.33 = 13083.33, tek üretim müşt. A'ya
        $pid = $this->repo->upsertPersonel('P', 'Aşçı', 10000.0);
        $this->repo->setPersonelAtama($pid, true);

        $n = $this->repo->customerNetKarlilik($a, '2026-07');
        $yuklu = $this->repo->personelYukluMaliyet($pid)['yuklu_toplam'];
        $this->assertEqualsWithDelta(10000.0, $n['ciro'], 0.001);
        $this->assertEqualsWithDelta(2000.0, $n['pay_gider'], 0.001, 'tek müşteri → tüm gider');
        $this->assertEqualsWithDelta($yuklu, $n['pay_personel'], 0.01);
        $this->assertEqualsWithDelta(10000.0 - 2000.0 - $yuklu, $n['net'], 0.01);
    }

    // ── Müşteri net kârı: taşıma (satış − alış − sabit − pay) ─
    public function testCustomerNetKarlilikTasima(): void
    {
        $t = $this->repo->upsertCustomer('KARGO', 120.0, 'tasima', null, null, null, null, 80.0, 5000.0);
        $this->repo->upsertProduction($t, '2026-07-01', 100, 120.0, 'ogle'); // adet 100
        // gider genel, tek müşteri (taşıma) → hepsi ona
        $this->repo->addTransaction('gider', 1000.0, '2026-07-05', null, null, null, null, null, 'genel');

        $n = $this->repo->customerNetKarlilik($t, '2026-07');
        $this->assertSame('tasima', $n['category']);
        $this->assertEqualsWithDelta(12000.0, $n['ciro'], 0.001, '100×120');
        $this->assertEqualsWithDelta(8000.0, $n['alis'], 0.001, '100×80');
        $this->assertEqualsWithDelta(5000.0, $n['sabit'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $n['pay_gider'], 0.001);
        // net = 12000 − 8000 − 5000 − 1000 − 0 = −2000
        $this->assertEqualsWithDelta(-2000.0, $n['net'], 0.001);
    }

    // ── Kâr Analizi: toplam = netKarlilik net = Σ customerNet (BİREBİR) ─
    public function testKarAnaliziToplamBirebir(): void
    {
        // ÜRETİM: CANTAŞ ciro 32800
        $c = seed_customer($this->pdo, 'CANTAŞ', 328.0);
        $this->repo->upsertProduction($c, '2026-07-01', 100, 328.0, 'ogle');
        // TAŞIMA: KARGO satış 120 alış 80 sabit 20000, adet 500 → satış 60000
        $t = $this->repo->upsertCustomer('KARGO', 120.0, 'tasima', null, null, null, null, 80.0, 20000.0);
        $this->repo->upsertProduction($t, '2026-07-10', 500, 120.0, 'ogle');
        // Giderler (genel + Personel HARİÇ)
        $this->repo->addTransaction('gider', 8000.0, '2026-07-02', 'Et/Tavuk', null, null, null, null, 'genel');
        $this->repo->addTransaction('gider', 9999.0, '2026-07-03', 'Personel', null, null, null, null, 'genel'); // hariç
        // Personel genel (üretim müşt. CANTAŞ'a düşer)
        $pid = $this->repo->upsertPersonel('Ahmet', 'Aşçı', 15000.0);
        $this->repo->setPersonelAtama($pid, true);

        $ka = $this->repo->karAnalizi('2026-07');
        $nk = $this->repo->netKarlilik('2026-07');

        // Σ customerNet (tüm müşteriler)
        $sum = 0.0;
        foreach ([$c, $t] as $cid) {
            $sum += $this->repo->customerNetKarlilik($cid, '2026-07')['net'];
        }

        $this->assertEqualsWithDelta($nk['net'], $ka['toplam_net'], 0.01, 'karAnalizi toplam = netKarlilik net');
        $this->assertEqualsWithDelta($sum, $ka['toplam_net'], 0.01, 'toplam = Σ customerNet (birebir)');
        $this->assertEqualsWithDelta(5175.0, $nk['net'], 0.01, '32800−8000−19625+0');
        $this->assertEqualsWithDelta(0.0, $ka['dagitilmamis'], 0.001, 'her şey dağıtıldı');

        // Grup ayrımı korundu
        $this->assertEqualsWithDelta(32800.0, $ka['uretim']['gelir'], 0.001);
        $this->assertEqualsWithDelta(60000.0, $ka['tasima']['satis'], 0.001);
        // marj = net/gelir
        $this->assertEqualsWithDelta($ka['uretim']['net'] / 32800.0, $ka['uretim']['marj'], 0.0001);
    }

    // ── addTransaction geriye dönük uyumlu (alloc_type default 'genel') ─
    public function testAddTransactionDefaultAllocGenel(): void
    {
        $id = $this->repo->addTransaction('gider', 100.0, '2026-07-01', 'Kira', 'x');
        $row = $this->pdo->query("SELECT alloc_type FROM transactions WHERE id = $id")->fetch();
        $this->assertSame('genel', $row['alloc_type']);
    }

    // ── Hedefsiz 'musteri' → genele düşer ─────────────────────
    public function testMusteriAllocWithoutTargetsFallsToGenel(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 100, 1.0, 'ogle');
        $id = $this->repo->addTransaction('gider', 300.0, '2026-07-05', null, null, null, null, null, 'musteri', []);
        $row = $this->pdo->query("SELECT alloc_type FROM transactions WHERE id = $id")->fetch();
        $this->assertSame('genel', $row['alloc_type'], 'hedef yok → genel');
        $d = $this->repo->giderDagitim('2026-07');
        $this->assertEqualsWithDelta(300.0, $d['per_customer'][$a], 0.001);
    }
}
