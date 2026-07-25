<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * fable-043 — Elle girilen gider TEDARİKÇİ BAZLI.
 * Manuel gider + supplier_id dolu → firma karnesi / eşleştirme / gıda kırılımı tedarikçi ADIYLA
 * gruplar (txFirma tek kaynak). Supplier yoksa eski 'Elle girilen · kategori' fallback korunur
 * (regresyon). Gelir tarafı tedarikçisiz kalabilir.
 */
final class ElleGiderTedarikciTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    // ── ensureSupplier: bul-veya-oluştur, contact'a dokunmaz, TR-normalize dedup ──
    public function testEnsureSupplierBulVeyaOlustur(): void
    {
        $id1 = $this->repo->upsertSupplier('POLATOĞLU', 'Ali 555');
        $id2 = $this->repo->ensureSupplier('POLATOĞLU');
        $this->assertSame($id1, $id2, 'mevcut ad → aynı id');
        $sup = $this->repo->supplierById($id1);
        $this->assertSame('Ali 555', $sup['contact'], 'ensureSupplier contact\'ı EZMEZ');

        $id3 = $this->repo->ensureSupplier('KAR-UN-SAN');
        $this->assertGreaterThan(0, $id3);
        $this->assertNotSame($id1, $id3, 'yeni ad → yeni id');
    }

    public function testEnsureSupplierTrNormalizeDedup(): void
    {
        $id1 = $this->repo->ensureSupplier('Kırmızı Gıda');
        $id2 = $this->repo->ensureSupplier('KIRMIZI GIDA'); // TR-upper aynı anahtar
        $this->assertSame($id1, $id2, 'kırmızı == KIRMIZI (normTedarikci) → tek kayıt');
        $this->assertSame(0, $this->repo->ensureSupplier('   '), 'boş ad → 0');
    }

    // ── Firma karnesi: supplier'lı manuel gider tedarikçi ADIYLA görünür ──
    public function testGiderFirmaOzetSupplierAdi(): void
    {
        $sid = $this->repo->ensureSupplier('POLATOĞLU');
        $this->repo->addTransaction('gider', 1000.0, '2026-07-05', 'Gıda', 'et alımı', null, $sid);

        $ozet = $this->repo->giderFirmaOzet('2026-07');
        $firmalar = array_column($ozet, 'firma');
        $this->assertContains('POLATOĞLU', $firmalar, 'karnede tedarikçi adı');
        $this->assertNotContains('Elle girilen · Gıda', $firmalar, 'fallback etikete DÜŞMEZ');
    }

    // ── Regresyon: supplier YOKKEN eski 'Elle girilen · kategori' fallback ──
    public function testSupplierSizFallbackKorunur(): void
    {
        $this->repo->addTransaction('gider', 500.0, '2026-07-05', 'Kira', 'temmuz kira', null, null);

        $ozet = $this->repo->giderFirmaOzet('2026-07');
        $firmalar = array_column($ozet, 'firma');
        $this->assertContains('Elle girilen · Kira', $firmalar, 'tedarikçisiz → fallback');
    }

    // ── distinctGiderFirmalar da supplier adını verir (eşleştirme ekranı) ──
    public function testDistinctFirmalarSupplierAdi(): void
    {
        $sid = $this->repo->ensureSupplier('OGÜN GIDA');
        $this->repo->addTransaction('gider', 800.0, date('Y-m-05'), 'Gıda', null, null, $sid);

        $liste = $this->repo->distinctGiderFirmalar(6);
        $labels = array_column($liste, 'label');
        $this->assertContains('OGÜN GIDA', $labels);
    }

    // ── giderDagitim: supplier'lı manuel gider tedarikçi eşleşmesi üzerinden dağılır ──
    public function testGiderDagitimSupplierEslesme(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 30, 1.0, 'ogle');
        $this->repo->upsertProduction($b, '2026-07-01', 70, 1.0, 'ogle');

        $sid = $this->repo->ensureSupplier('AKSU GIDA');
        $this->repo->addTransaction('gider', 100.0, '2026-07-05', 'Gıda', null, null, $sid);
        // eşleştirme anahtarı normTedarikci(supplier adı) ile birebir
        $this->repo->tedarikciEslestirmeKaydet('AKSU GIDA', [$a, $b]);

        $d = $this->repo->giderDagitim('2026-07');
        $this->assertEqualsWithDelta(30.0, $d['per_customer'][$a], 0.001, 'A 30 kişi → 30 TL');
        $this->assertEqualsWithDelta(70.0, $d['per_customer'][$b], 0.001, 'B 70 kişi → 70 TL');
        $this->assertEqualsWithDelta(0.0, $d['dagitilmamis'], 0.001);
    }

    // ── Regresyon: supplier YOKKEN manuel gider eskisi gibi ciro havuzuna düşer ──
    public function testSupplierSizGiderCiroHavuzu(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 3.0);
        $this->repo->upsertProduction($a, '2026-07-01', 100, 1.0, 'ogle'); // ciro 100
        $this->repo->upsertProduction($b, '2026-07-01', 100, 3.0, 'ogle'); // ciro 300
        $this->repo->addTransaction('gider', 400.0, '2026-07-05', 'Gıda', null, null, null);

        $d = $this->repo->giderDagitim('2026-07');
        $this->assertEqualsWithDelta(100.0, $d['per_customer'][$a], 0.001);
        $this->assertEqualsWithDelta(300.0, $d['per_customer'][$b], 0.001);
    }
}
