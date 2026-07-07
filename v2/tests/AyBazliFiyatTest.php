<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * opus-017 — Müşteri AY-BAZLI fiyat (reaktif).
 * priceFor (o ay > carry-forward > current default); setCustomerPrice bir ayı değiştirince
 * o ay production.amount + ciro/analiz güncellenir, diğer aylar sabit; taşıma ay-bazlı;
 * SEED mevcut rakamları BOZMAZ (regresyon).
 */
final class AyBazliFiyatTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    // ── priceFor: o ay > carry-forward > current default ──────────
    public function testPriceForPrecedence(): void
    {
        $id = seed_customer($this->pdo, 'TALAY', 300.0);
        // Hiç customer_price yok → current default
        $this->assertEqualsWithDelta(300.0, $this->repo->priceFor($id, '2026-07')['unit_price'], 0.001);

        // June fiyatı = 256 → June o ay, Temmuz carry-forward (256), Mayıs default (300)
        $this->repo->setCustomerPrice($id, '2026-06', 256.0);
        $this->assertEqualsWithDelta(256.0, $this->repo->priceFor($id, '2026-06')['unit_price'], 0.001, 'o ay');
        $this->assertEqualsWithDelta(256.0, $this->repo->priceFor($id, '2026-07')['unit_price'], 0.001, 'carry-forward (önceki ay)');
        $this->assertEqualsWithDelta(300.0, $this->repo->priceFor($id, '2026-05')['unit_price'], 0.001, 'öncesi yok → current default');

        // Temmuz'a ayrı zam = 280 → Temmuz artık kendi fiyatı, Ağustos carry-forward 280
        $this->repo->setCustomerPrice($id, '2026-07', 280.0);
        $this->assertEqualsWithDelta(280.0, $this->repo->priceFor($id, '2026-07')['unit_price'], 0.001);
        $this->assertEqualsWithDelta(280.0, $this->repo->priceFor($id, '2026-08')['unit_price'], 0.001, 'carry-forward Temmuz');
        $this->assertEqualsWithDelta(256.0, $this->repo->priceFor($id, '2026-06')['unit_price'], 0.001, 'June sabit');
    }

    // ── setCustomerPrice: o ay production+ciro güncellenir, diğer ay sabit ─
    public function testSetCustomerPriceUpdatesMonthOnly(): void
    {
        $id = seed_customer($this->pdo, 'TALAY', 235.0);
        // June 100 kişi @235 = 23500; Temmuz 100 kişi @235 = 23500
        $this->repo->upsertProduction($id, '2026-06-05', 100, 235.0, 'ogle');
        $this->repo->upsertProduction($id, '2026-07-05', 100, 235.0, 'ogle');
        $this->assertEqualsWithDelta(23500.0, $this->repo->monthUretimCiro('2026-06'), 0.001);
        $this->assertEqualsWithDelta(23500.0, $this->repo->monthUretimCiro('2026-07'), 0.001);

        // June fiyatı 235 → 256: June cirosu artar (100×256), Temmuz DEĞİŞMEZ
        $this->repo->setCustomerPrice($id, '2026-06', 256.0);
        $this->assertEqualsWithDelta(25600.0, $this->repo->monthUretimCiro('2026-06'), 0.001, 'June güncellendi');
        $this->assertEqualsWithDelta(23500.0, $this->repo->monthUretimCiro('2026-07'), 0.001, 'Temmuz sabit');

        // June production satırı da snapshot güncel
        $m = $this->repo->customerMonthProduction($id, '2026-06');
        $this->assertEqualsWithDelta(25600.0, $m['amount'], 0.001);
        $snap = (float) $this->pdo->query(
            "SELECT unit_price_snap FROM production WHERE substr(prod_date,1,7)='2026-06'"
        )->fetchColumn();
        $this->assertEqualsWithDelta(256.0, $snap, 0.001, 'unit_price_snap yeni fiyat');
    }

    // ── Yeni giriş o ayın fiyatını kullanır (priceFor ile) ────────
    public function testNewEntryUsesMonthPrice(): void
    {
        $id = seed_customer($this->pdo, 'TALAY', 235.0);
        $this->repo->setCustomerPrice($id, '2026-07', 260.0);
        // Yeni Temmuz girişi (caller mantığı): priceFor o ayı verir
        $price = $this->repo->priceFor($id, '2026-07')['unit_price'];
        $this->assertEqualsWithDelta(260.0, $price, 0.001);
        $res = $this->repo->upsertProduction($id, '2026-07-20', 10, $price, 'ogle');
        $this->assertEqualsWithDelta(2600.0, $res['amount'], 0.001, '10×260');
    }

    // ── Taşıma ay-bazlı: satış/alış/sabit priceFor'dan ────────────
    public function testTasimaAyBazli(): void
    {
        // satış 120, alış 80, sabit 500 (current)
        $t = $this->repo->upsertCustomer('KARGO', 120.0, 'tasima', null, null, null, null, 80.0, 500.0);
        $this->repo->upsertProduction($t, '2026-07-01', 100, 120.0, 'ogle'); // adet 100
        // customer_price yok → current: net = 100×(120−80)−500 = 3500
        $this->assertEqualsWithDelta(3500.0, $this->repo->tasimaKar($t, '2026-07'), 0.001);

        // Temmuz zam: satış 140, alış 90, sabit 600 → net = 100×(140−90)−600 = 4400
        $this->repo->setCustomerPrice($t, '2026-07', 140.0, 90.0, 600.0);
        $p = $this->repo->tasimaProfit($t, '2026-07');
        $this->assertEqualsWithDelta(140.0, (float) $p['satis'], 0.001);
        $this->assertEqualsWithDelta(90.0, (float) $p['alis'], 0.001);
        $this->assertEqualsWithDelta(600.0, (float) $p['sabit'], 0.001);
        $this->assertEqualsWithDelta(4400.0, (float) $p['net'], 0.001);
        // production.amount taşıma satışıyla güncellendi (14000) → customerCiroMap tutar
        $this->assertEqualsWithDelta(14000.0, $this->repo->customerCiroMap('2026-07')[$t], 0.001);
    }

    // ── SEED: mevcut rakamlar DEĞİŞMEZ (regresyon) ────────────────
    public function testSeedDoesNotChangeExistingNumbers(): void
    {
        // Üretim müşterisi: June 100@235, Temmuz 100@235
        $u = seed_customer($this->pdo, 'TALAY', 235.0);
        $this->repo->upsertProduction($u, '2026-06-05', 100, 235.0, 'ogle');
        $this->repo->upsertProduction($u, '2026-07-05', 100, 235.0, 'ogle');
        // Taşıma müşterisi: Temmuz adet 100 @satış120
        $t = $this->repo->upsertCustomer('KARGO', 120.0, 'tasima', null, null, null, null, 80.0, 500.0);
        $this->repo->upsertProduction($t, '2026-07-01', 100, 120.0, 'ogle');
        $this->repo->addTransaction('gider', 3000.0, '2026-07-02', 'Et', null, null, null, null, 'genel');

        // SEED ÖNCESİ değerler
        $ciroJun = $this->repo->monthUretimCiro('2026-06');
        $ciroJul = $this->repo->monthUretimCiro('2026-07');
        $kaBefore = $this->repo->karAnalizi('2026-07')['toplam_net'];
        $tasimaBefore = $this->repo->tasimaKar($t, '2026-07');

        // SEED çalıştır
        $n = $this->repo->seedCustomerPricesFromProduction();
        $this->assertGreaterThan(0, $n, 'seed satır eklendi');

        // İkinci kez seed → idempotent (0 yeni satır)
        $this->assertSame(0, $this->repo->seedCustomerPricesFromProduction(), 'idempotent');

        // SEED SONRASI değerler AYNI
        $this->assertEqualsWithDelta($ciroJun, $this->repo->monthUretimCiro('2026-06'), 0.001, 'June sabit');
        $this->assertEqualsWithDelta($ciroJul, $this->repo->monthUretimCiro('2026-07'), 0.001, 'Temmuz sabit');
        $this->assertEqualsWithDelta($kaBefore, $this->repo->karAnalizi('2026-07')['toplam_net'], 0.01, 'kâr analizi sabit');
        $this->assertEqualsWithDelta($tasimaBefore, $this->repo->tasimaKar($t, '2026-07'), 0.001, 'taşıma sabit');

        // priceFor artık seed'lenmiş snapshot'ı döner (June=235)
        $this->assertEqualsWithDelta(235.0, $this->repo->priceFor($u, '2026-06')['unit_price'], 0.001);

        // Sonra June'u 256'ya çek → SADECE June değişir
        $this->repo->setCustomerPrice($u, '2026-06', 256.0);
        $this->assertEqualsWithDelta(25600.0, $this->repo->monthUretimCiro('2026-06'), 0.001, 'June güncel');
        $this->assertEqualsWithDelta($ciroJul, $this->repo->monthUretimCiro('2026-07'), 0.001, 'Temmuz hâlâ sabit');
    }

    // ── SEED baskın snapshot: ay içi karışık fiyatta çoğunluğu seçer ─
    public function testSeedPicksDominantSnapshot(): void
    {
        $id = seed_customer($this->pdo, 'MIX', 300.0);
        // Temmuz: 200 kişi @235 + 10 kişi @999 → baskın 235
        $this->repo->upsertProduction($id, '2026-07-01', 200, 235.0, 'ogle');
        $this->repo->upsertProduction($id, '2026-07-02', 10, 999.0, 'ogle');
        $this->repo->seedCustomerPricesFromProduction();
        $this->assertEqualsWithDelta(235.0, $this->repo->priceFor($id, '2026-07')['unit_price'], 0.001, 'en çok kişiyi kapsayan snap');
    }
}
