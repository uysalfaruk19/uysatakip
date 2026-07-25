<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * fable-045 — BORÇLARIM (tedarikçi bazlı, AYDAN BAĞIMSIZ kümülatif) testleri.
 * Borç(tedarikçi) = devir + Σ(tüm zaman gider faturaları) − Σ(ödemeler).
 */
final class BorclarimTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    /** supplier oluştur → id. */
    private function seedSupplier(string $name): int
    {
        $this->pdo->prepare('INSERT INTO suppliers (name) VALUES (?)')->execute([$name]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Elle gider faturası (tedarikçi seçili) ekle. */
    private function giderElle(int $supplierId, string $date, float $amount, string $category = 'Gıda'): int
    {
        $this->pdo->prepare(
            "INSERT INTO transactions (type, category, tx_date, amount, supplier_id, source, description)
             VALUES ('gider', ?, ?, ?, ?, 'manuel', ?)"
        )->execute([$category, $date, $amount, $supplierId, 'Elle fatura']);
        return (int) $this->pdo->lastInsertId();
    }

    /** Paraşüt gideri (firma = description ' · ' öncesi). */
    private function giderParasut(string $firma, string $date, float $amount, string $no = 'F1'): void
    {
        $this->pdo->prepare(
            "INSERT INTO transactions (type, category, tx_date, amount, source, description, parasut_id)
             VALUES ('gider', 'Gıda', ?, ?, 'parasut', ?, ?)"
        )->execute([$date, $amount, $firma . ' · ' . $no, 'p-' . bin2hex(random_bytes(3))]);
    }

    private function byKey(array $liste, string $key): ?array
    {
        foreach ($liste as $r) {
            if ($r['key'] === $key) {
                return $r;
            }
        }
        return null;
    }

    // ── 1) Temel borç: fatura − ödeme + devir ──────────────────────
    public function testBorcHesabiFaturaOdemeDevir(): void
    {
        $s = $this->seedSupplier('METRO');
        $this->giderElle($s, '2026-07-01', 1000.0);
        $this->giderElle($s, '2026-07-10', 500.0);
        $key = Repo::normTedarikci('METRO');
        $this->repo->tedarikciDevirKaydet($key, 'METRO', 300.0);
        $this->repo->tedarikciOdemeEkle($key, '2026-07-15', 600.0);

        $row = $this->byKey($this->repo->borclarimListe(), $key);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(1500.0, $row['fatura'], 0.001);
        $this->assertEqualsWithDelta(300.0, $row['devir'], 0.001);
        $this->assertEqualsWithDelta(600.0, $row['odenen'], 0.001);
        // kalan = 300 + 1500 − 600 = 1200
        $this->assertEqualsWithDelta(1200.0, $row['kalan'], 0.001);
    }

    // ── 2) Kısmi ödeme akışı: kalan azalır, tam ödeme kapatır ──────
    public function testKismiVeTamOdeme(): void
    {
        $s = $this->seedSupplier('BOMİ');
        $this->giderElle($s, '2026-06-05', 800.0);
        $key = Repo::normTedarikci('BOMİ');

        $this->repo->tedarikciOdemeEkle($key, '2026-06-10', 300.0);
        $this->assertEqualsWithDelta(500.0, $this->repo->borclarimDetay($key)['kalan'], 0.001);

        $this->repo->tedarikciOdemeEkle($key, '2026-06-20', 500.0);
        $d = $this->repo->borclarimDetay($key);
        $this->assertEqualsWithDelta(0.0, $d['kalan'], 0.001, 'tam ödeme → borç kapanır');
        $this->assertCount(2, $d['odemeler']);
    }

    // ── 3) Negatif düzeltme (silme yok) ───────────────────────────
    public function testNegatifDuzeltme(): void
    {
        $s = $this->seedSupplier('ÖRS');
        $this->giderElle($s, '2026-07-01', 1000.0);
        $key = Repo::normTedarikci('ÖRS');

        $this->repo->tedarikciOdemeEkle($key, '2026-07-05', 400.0);
        $this->repo->tedarikciOdemeEkle($key, '2026-07-06', -400.0); // yanlış ödemeyi geri al

        $d = $this->repo->borclarimDetay($key);
        $this->assertEqualsWithDelta(0.0, $d['odenen'], 0.001, 'ödeme + düzeltme = 0 net');
        $this->assertEqualsWithDelta(1000.0, $d['kalan'], 0.001, 'borç ilk haline döner');
        $this->assertCount(2, $d['odemeler'], 'iki kayıt da durur (silme yok)');
    }

    // ── 4) AYDAN BAĞIMSIZLIK: farklı aylardaki faturalar hep sayılır ─
    public function testAydanBagimsizlik(): void
    {
        $s = $this->seedSupplier('TEKİNBEY');
        $this->giderElle($s, '2026-05-01', 200.0);
        $this->giderElle($s, '2026-06-01', 300.0);
        $this->giderElle($s, '2026-07-01', 500.0);
        $key = Repo::normTedarikci('TEKİNBEY');

        // borclarimListe/Detay AY parametresi ALMAZ → tüm zaman toplanır (1000).
        $this->assertEqualsWithDelta(1000.0, $this->repo->borclarimDetay($key)['fatura'], 0.001);
        $row = $this->byKey($this->repo->borclarimListe(), $key);
        $this->assertEqualsWithDelta(1000.0, $row['kalan'], 0.001, 'ay filtresi YOK → 3 ay toplam');
    }

    // ── 5) Devir upsert: elle bir kere, güncellenir, tek satır ─────
    public function testDevirUpsert(): void
    {
        $key = Repo::normTedarikci('YENİFİRMA');
        $this->repo->tedarikciDevirKaydet($key, 'YENİFİRMA', 100.0);
        $this->repo->tedarikciDevirKaydet($key, 'YENİFİRMA', 250.0); // güncelle

        $cnt = (int) $this->pdo->query('SELECT COUNT(*) FROM tedarikci_devir')->fetchColumn();
        $this->assertSame(1, $cnt, 'tek satır (UNIQUE tedarikci)');
        $this->assertEqualsWithDelta(250.0, $this->repo->borclarimDetay($key)['devir'], 0.001, 'son değer geçerli');
    }

    // ── 6) Yetim borç: yalnız devri olan tedarikçi listede görünür ─
    public function testDevirOnlySupplierListelenir(): void
    {
        $key = Repo::normTedarikci('ESKİ BORÇ AŞ');
        $this->repo->tedarikciDevirKaydet($key, 'ESKİ BORÇ AŞ', 750.0);

        $row = $this->byKey($this->repo->borclarimListe(), $key);
        $this->assertNotNull($row, 'faturasız devir tedarikçisi gizlenmez');
        $this->assertEqualsWithDelta(750.0, $row['kalan'], 0.001);
        $this->assertSame('ESKİ BORÇ AŞ', $row['label'], 'devir label görünür');
    }

    // ── 7) normTedarikci hizası: fatura + ödeme aynı anahtarda buluşur ─
    public function testNormTedarikciHizasi(): void
    {
        // Paraşüt faturası 'Kırmızı Et' · ödeme 'KIRMIZI ET' → aynı norm anahtar.
        $this->giderParasut('Kırmızı Et', '2026-07-01', 900.0);
        $key = Repo::normTedarikci('KIRMIZI ET');
        $this->repo->tedarikciOdemeEkle('kırmızı et', '2026-07-10', 400.0); // farklı yazım, aynı anahtar

        $d = $this->repo->borclarimDetay($key);
        $this->assertEqualsWithDelta(900.0, $d['fatura'], 0.001);
        $this->assertEqualsWithDelta(400.0, $d['odenen'], 0.001, 'i/İ toleranslı norm anahtar');
        $this->assertEqualsWithDelta(500.0, $d['kalan'], 0.001);
    }

    // ── 8) Personel/Taşıma alış borca GİRMEZ (kendi akışı var) ─────
    /** fable-049 (Ömer: "her fatura borçtur"): Taşıma alış borca dahil; yalnız Personel hariç. */
    public function testYalnizPersonelHaric(): void
    {
        $s = $this->seedSupplier('KIRMIZI');
        $this->giderElle($s, '2026-07-01', 1000.0, 'Taşıma alış');
        $this->pdo->prepare("INSERT INTO transactions (type, category, tx_date, amount, source, description) VALUES ('gider','Personel','2026-07-01', 5000, 'manuel', 'maaş')")->execute();
        $this->giderElle($s, '2026-07-02', 200.0, 'Gıda');

        $key = Repo::normTedarikci('KIRMIZI');
        $row = $this->byKey($this->repo->borclarimListe(), $key);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(1200.0, $row['fatura'], 0.001, 'Gıda 200 + Taşıma alış 1000 — her fatura borç (fable-049)');
        // Personel kategorisi hiç tedarikçi üretmez
        $this->assertNull($this->byKey($this->repo->borclarimListe(), Repo::normTedarikci('maaş')));
    }

    // ── 9) Sıfır tutar ödeme reddedilir ───────────────────────────
    public function testSifirOdemeReddedilir(): void
    {
        $key = Repo::normTedarikci('X');
        $this->assertSame(0, $this->repo->tedarikciOdemeEkle($key, '2026-07-01', 0.0));
        $this->assertSame(0, $this->repo->tedarikciOdemeEkle('', '2026-07-01', 100.0), 'boş anahtar reddedilir');
    }
}
