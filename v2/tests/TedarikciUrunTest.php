<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * fable-048 A — Gider FİRMA KARNESİ satırının ÜRÜNSEL kırılımı (Repo::tedarikciUrunOzet).
 * Tedarikçiye tıklayınca o firmadan alınan ürünler: Σtutar DESC top-N, Σmiktar + birim,
 * ortalama birim fiyat, fatura adedi, kapsanmayan (satırsız fatura + KDV payı).
 * Kapsam giderFirmaOzet ile BİREBİR — açılan detayın toplamı karne satırıyla aynı olmalı.
 */
final class TedarikciUrunTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    /** Paraşüt kaynaklı gider tx ('FIRMA · faturaNo' deseni). @return tx id */
    private function gider(string $firma, float $tutar, string $tarih = '2026-07-10', string $kategori = 'Gıda'): int
    {
        $this->pdo->prepare(
            "INSERT INTO transactions (type, category, tx_date, amount, description, source, alloc_type)
             VALUES ('gider', ?, ?, ?, ?, 'parasut', 'genel')"
        )->execute([$kategori, $tarih, $tutar, $firma . ' · INV']);
        return (int) $this->pdo->lastInsertId();
    }

    private function kalem(int $txId, string $urun, ?float $miktar, ?string $birim, ?float $bf, float $tutar): void
    {
        $this->pdo->prepare(
            'INSERT INTO gider_kalem (tx_id, urun, miktar, birim, birim_fiyat, tutar) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$txId, $urun, $miktar, $birim, $bf, $tutar]);
    }

    // ── Gruplama + Σtutar DESC + top-N + fatura adedi ─────────────────
    public function testGruplamaTopNVeFaturaAdedi(): void
    {
        $t1 = $this->gider('ATILGAN ET', 15000.0);
        $t2 = $this->gider('ATILGAN ET', 1200.0);
        $this->kalem($t1, 'DANA KIYMA', 100, 'KG', 50, 5000);
        $this->kalem($t1, 'KUZU BUT', 40, 'KG', 150, 6000);
        $this->kalem($t1, 'dana kıyma', 50, 'KG', 60, 3000); // TR-normalize → aynı ürün
        $this->kalem($t1, 'TAVUK', 20, 'KG', 50, 1000);
        $this->kalem($t2, 'DANA KIYMA', 20, 'KG', 60, 1200);

        $o = $this->repo->tedarikciUrunOzet('2026-07', 'ATILGAN ET', 2);
        $this->assertSame('ATILGAN ET', $o['tedarikci']);
        $this->assertSame(2, $o['fatura_adedi']);
        $this->assertSame(3, $o['urun_sayisi'], 'DANA + KUZU + TAVUK');
        $this->assertCount(2, $o['urunler'], 'limit=2');
        $this->assertSame('DANA KIYMA', $o['urunler'][0]['urun']);
        $this->assertEqualsWithDelta(9200.0, $o['urunler'][0]['tutar'], 0.001, '5000+3000+1200');
        $this->assertSame(2, $o['urunler'][0]['fatura_adedi'], 'iki ayrı faturada geçti');
        $this->assertSame('KUZU BUT', $o['urunler'][1]['urun']);
    }

    // ── Ortalama birim fiyat; miktar kısmen eksikse NULL (uydurma yok) ─
    public function testOrtBirimFiyatVeEksikMiktar(): void
    {
        $t1 = $this->gider('ÖRS GIDA', 10000.0);
        $this->kalem($t1, 'PİRİNÇ', 100, 'KG', 50, 5000);
        $this->kalem($t1, 'PİRİNÇ', 50, 'KG', 60, 3000);   // Σ8000 / 150kg = 53,33
        $this->kalem($t1, 'ŞEKER', null, null, null, 2000); // miktar yok → ort boş

        $o = $this->repo->tedarikciUrunOzet('2026-07', 'ÖRS GIDA');
        $by = [];
        foreach ($o['urunler'] as $u) {
            $by[$u['urun']] = $u;
        }
        $this->assertEqualsWithDelta(150.0, $by['PİRİNÇ']['miktar'], 0.001);
        $this->assertSame('KG', $by['PİRİNÇ']['birim']);
        $this->assertEqualsWithDelta(53.3333, $by['PİRİNÇ']['ort_birim_fiyat'], 0.001);
        $this->assertNull($by['ŞEKER']['miktar']);
        $this->assertNull($by['ŞEKER']['ort_birim_fiyat']);
    }

    // ── Kapsanmayan = tedarikçi ay toplamı − kalemli toplam ────────────
    public function testKapsanmayan(): void
    {
        $t1 = $this->gider('ATILGAN ET', 15000.0);
        $this->gider('ATILGAN ET', 5000.0); // satırı YOK
        $this->kalem($t1, 'DANA KIYMA', 100, 'KG', 50, 5000);
        $this->kalem($t1, 'KUZU BUT', 40, 'KG', 150, 6000);

        $o = $this->repo->tedarikciUrunOzet('2026-07', 'ATILGAN ET');
        $this->assertEqualsWithDelta(20000.0, $o['toplam'], 0.001);
        $this->assertEqualsWithDelta(11000.0, $o['kalemli_toplam'], 0.001);
        $this->assertEqualsWithDelta(9000.0, $o['kapsanmayan'], 0.001);
        $this->assertSame(1, $o['kalemli_fatura'], '2 faturadan 1 tanesinin satırı var');
    }

    // ── SENKRONİZASYON: detay toplamı karne satırıyla BİREBİR ─────────
    public function testToplamKarneSatiriylaBirebir(): void
    {
        $t1 = $this->gider('ATILGAN ET', 15000.0, '2026-07-03');
        $this->gider('ATILGAN ET', 5000.0, '2026-07-20');
        $this->gider('ÖRS GIDA', 4000.0, '2026-07-11');
        $this->kalem($t1, 'DANA KIYMA', 100, 'KG', 50, 5000);

        $karne = [];
        foreach ($this->repo->giderFirmaOzet('2026-07') as $f) {
            $karne[$f['firma']] = $f;
        }
        foreach ($karne as $firma => $f) {
            $o = $this->repo->tedarikciUrunOzet('2026-07', (string) $firma);
            $this->assertEqualsWithDelta((float) $f['toplam'], $o['toplam'], 0.001, "$firma toplamı karne ile aynı");
            $this->assertSame((int) $f['adet'], $o['fatura_adedi'], "$firma fatura adedi karne ile aynı");
        }
    }

    // ── Personel/Taşıma alış kategorileri de karnede var → detayda da olmalı ──
    public function testKategoriSuzgeciYokKarneyleAyniKapsam(): void
    {
        $this->gider('KIRMIZI 1', 30000.0, '2026-07-05', 'Taşıma alış');
        $o = $this->repo->tedarikciUrunOzet('2026-07', 'KIRMIZI 1');
        $this->assertEqualsWithDelta(30000.0, $o['toplam'], 0.001, 'Taşıma alış faturası da tedarikçi toplamında');
        $this->assertEqualsWithDelta(30000.0, $o['kapsanmayan'], 0.001);
    }

    // ── TR-normalize: 'Kırmızı 1' ile 'KIRMIZI 1' aynı tedarikçi ──────
    public function testTrNormalizeAnahtar(): void
    {
        $t1 = $this->gider('Kırmızı 1', 8000.0);
        $this->kalem($t1, 'YEMEK', 200, 'ADET', 40, 8000);

        $o = $this->repo->tedarikciUrunOzet('2026-07', 'KIRMIZI 1');
        $this->assertEqualsWithDelta(8000.0, $o['toplam'], 0.001, 'i/İ ve ı/I tuzağı çözülür');
        $this->assertCount(1, $o['urunler']);
    }

    // ── Elle girilen (supplier_id'li) gider de tedarikçi adıyla gelir ──
    public function testElleGirilenSupplier(): void
    {
        $sid = $this->repo->ensureSupplier('POLATOĞLU');
        $this->repo->addTransaction('gider', 2500.0, '2026-07-08', 'Gıda', 'et alımı', null, $sid);
        $txId = (int) $this->pdo->query("SELECT id FROM transactions WHERE amount = 2500")->fetchColumn();
        $this->kalem($txId, 'KUZU İNCİK', 10, 'KG', 250, 2500);

        $o = $this->repo->tedarikciUrunOzet('2026-07', 'POLATOĞLU');
        $this->assertEqualsWithDelta(2500.0, $o['toplam'], 0.001);
        $this->assertSame('KUZU İNCİK', $o['urunler'][0]['urun'], 'Türkçe karakter korunur');
    }

    // ── Başka tedarikçinin kalemi SIZMAZ ──────────────────────────────
    public function testBaskaTedarikciSizmaz(): void
    {
        $tA = $this->gider('ATILGAN ET', 6000.0);
        $tO = $this->gider('ÖRS GIDA', 4000.0);
        $this->kalem($tA, 'DANA KIYMA', 100, 'KG', 60, 6000);
        $this->kalem($tO, 'PİRİNÇ', 200, 'KG', 20, 4000);

        $o = $this->repo->tedarikciUrunOzet('2026-07', 'ATILGAN ET');
        $this->assertCount(1, $o['urunler']);
        $this->assertSame('DANA KIYMA', $o['urunler'][0]['urun']);
    }

    // ── Başka AY sızmaz ───────────────────────────────────────────────
    public function testBaskaAySizmaz(): void
    {
        $t1 = $this->gider('ATILGAN ET', 6000.0, '2026-07-10');
        $t2 = $this->gider('ATILGAN ET', 9000.0, '2026-06-10');
        $this->kalem($t1, 'DANA', 100, 'KG', 60, 6000);
        $this->kalem($t2, 'KUZU', 60, 'KG', 150, 9000);

        $o = $this->repo->tedarikciUrunOzet('2026-07', 'ATILGAN ET');
        $this->assertEqualsWithDelta(6000.0, $o['toplam'], 0.001);
        $this->assertCount(1, $o['urunler']);
        $this->assertSame('DANA', $o['urunler'][0]['urun']);
    }

    // ── Kalem YOK → boş liste + kapsanmayan = toplam (dürüstlük) ──────
    public function testKalemYok(): void
    {
        $this->gider('ÖRS GIDA', 45000.0);
        $o = $this->repo->tedarikciUrunOzet('2026-07', 'ÖRS GIDA');
        $this->assertSame([], $o['urunler']);
        $this->assertSame(0, $o['urun_sayisi']);
        $this->assertEqualsWithDelta(45000.0, $o['kapsanmayan'], 0.001);
    }

    // ── Bilinmeyen tedarikçi / boş anahtar → sıfır ama tutarlı yapı ───
    public function testBilinmeyenTedarikci(): void
    {
        $this->gider('ATILGAN ET', 1000.0);
        foreach (['YOK A.Ş.', '', '   '] as $key) {
            $o = $this->repo->tedarikciUrunOzet('2026-07', $key);
            $this->assertEqualsWithDelta(0.0, $o['toplam'], 0.001);
            $this->assertSame(0, $o['fatura_adedi']);
            $this->assertSame([], $o['urunler']);
            $this->assertEqualsWithDelta(0.0, $o['kapsanmayan'], 0.001);
        }
    }
}
