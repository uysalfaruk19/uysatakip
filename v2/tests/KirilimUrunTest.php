<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Parasut;
use Uysa\Repo;

/**
 * fable-044 — Gıda kırılımının ÜRÜN KALEMLERİ (en çok para harcanan top-N + birim fiyat).
 * Repo::kirilimUrunOzet: kırılıma eşli tedarikçilerin o ayki tx kalemlerini ürün adına göre
 * grupla → Σtutar DESC top-N + ortalama birim fiyat (Σtutar/Σmiktar) + fatura adedi + kapsanmayan.
 * Repo::giderKalemSenkron: Paraşüt UBL satırlarını gider_kalem'e IDEMPOTENT yaz (mock fetcher).
 * Parasut::parseUblLines: namespace'li UBL InvoiceLine parse'ı (PÜR).
 */
final class KirilimUrunTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    /** Paraşüt kaynaklı gider tx ekle ('FIRMA · faturaNo' deseni). @return tx id */
    private function gider(string $firma, float $tutar, ?string $parasutId = null, string $tarih = '2026-07-10'): int
    {
        $this->pdo->prepare(
            "INSERT INTO transactions (type, category, tx_date, amount, description, source, parasut_id, alloc_type)
             VALUES ('gider', 'Gıda', ?, ?, ?, 'parasut', ?, 'genel')"
        )->execute([$tarih, $tutar, $firma . ' · INV', $parasutId]);
        return (int) $this->pdo->lastInsertId();
    }

    /** gider_kalem satırı ekle. */
    private function kalem(int $txId, string $urun, ?float $miktar, ?string $birim, ?float $bf, float $tutar): void
    {
        $this->pdo->prepare(
            'INSERT INTO gider_kalem (tx_id, urun, miktar, birim, birim_fiyat, tutar) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$txId, $urun, $miktar, $birim, $bf, $tutar]);
    }

    // ── PÜR parser: namespace'li UBL → satır dizisi (mock UBL) ─────────
    public function testParseUblLines(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
            . ' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
            . ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            . '<cac:InvoiceLine><cbc:InvoicedQuantity unitCode="KGM">100</cbc:InvoicedQuantity>'
            . '<cbc:LineExtensionAmount currencyID="TRY">5000.00</cbc:LineExtensionAmount>'
            . '<cac:Price><cbc:PriceAmount>50.00</cbc:PriceAmount></cac:Price>'
            . '<cac:Item><cbc:Name>DANA KIYMA</cbc:Name></cac:Item></cac:InvoiceLine>'
            . '<cac:InvoiceLine><cbc:InvoicedQuantity unitCode="ADET">3</cbc:InvoicedQuantity>'
            . '<cbc:LineExtensionAmount>1500</cbc:LineExtensionAmount>'
            . '<cac:Item><cbc:Name>KUZU İNCİK</cbc:Name></cac:Item></cac:InvoiceLine>'
            // adı olmayan satır (iskonto) → atlanır
            . '<cac:InvoiceLine><cbc:LineExtensionAmount>200</cbc:LineExtensionAmount>'
            . '<cac:Item><cbc:Name></cbc:Name></cac:Item></cac:InvoiceLine>'
            . '</Invoice>';

        $lines = Parasut::parseUblLines($xml);
        $this->assertCount(2, $lines, 'adsız satır atlanır');
        $this->assertSame('DANA KIYMA', $lines[0]['urun']);
        $this->assertEqualsWithDelta(100.0, $lines[0]['miktar'], 0.001);
        $this->assertSame('KGM', $lines[0]['birim']);
        $this->assertEqualsWithDelta(50.0, $lines[0]['birim_fiyat'], 0.001);
        $this->assertEqualsWithDelta(5000.0, $lines[0]['tutar'], 0.001);
        $this->assertSame('KUZU İNCİK', $lines[1]['urun'], 'Türkçe karakter korunur');
        $this->assertNull($lines[1]['birim_fiyat'], 'fiyat satırı yoksa null');
    }

    public function testParseUblBozukXmlBos(): void
    {
        $this->assertSame([], Parasut::parseUblLines('bu xml degil'));
        $this->assertSame([], Parasut::parseUblLines(''));
    }

    // ── Gruplama + top-N + fatura adedi ───────────────────────────────
    public function testGruplamaTopNVeFaturaAdedi(): void
    {
        $this->repo->tedarikciGidaKaydet('ATILGAN ET', 'kirmizi_et');
        $t1 = $this->gider('ATILGAN ET', 15000.0);
        $t2 = $this->gider('ATILGAN ET', 2000.0);
        // t1 kalemleri (Σ=15000): DANA 5000 + KUZU 6000 + DANA 3000 + TAVUK 1000
        $this->kalem($t1, 'DANA KIYMA', 100, 'KG', 50, 5000);
        $this->kalem($t1, 'KUZU BUT', 40, 'KG', 150, 6000);
        $this->kalem($t1, 'dana kıyma', 50, 'KG', 60, 3000); // aynı ürün, farklı yazım → TR-normalize birleşir
        $this->kalem($t1, 'TAVUK', 20, 'KG', 50, 1000);
        // t2 kalemi (Σ=1200): DANA 1200
        $this->kalem($t2, 'DANA KIYMA', 20, 'KG', 60, 1200);

        $o = $this->repo->kirilimUrunOzet('2026-07', 'kirmizi_et', 2);
        $this->assertSame('kirmizi_et', $o['kirilim_kod']);
        $this->assertSame('Kırmızı et', $o['kirilim_ad']);
        $this->assertSame(3, $o['urun_sayisi'], 'DANA + KUZU + TAVUK = 3 ayrı ürün');
        $this->assertCount(2, $o['urunler'], 'limit=2');

        // Sıra Σtutar DESC: DANA (9200) > KUZU (6000) > TAVUK (1000, limit dışı)
        $this->assertSame('DANA KIYMA', $o['urunler'][0]['urun']);
        $this->assertEqualsWithDelta(9200.0, $o['urunler'][0]['tutar'], 0.001, '5000+3000+1200');
        $this->assertSame(2, $o['urunler'][0]['fatura_adedi'], '2 farklı fatura (t1+t2)');
        $this->assertSame('KUZU BUT', $o['urunler'][1]['urun']);
        $this->assertSame(1, $o['urunler'][1]['fatura_adedi']);
    }

    // ── Ortalama birim fiyat = Σtutar/Σmiktar; miktar eksikse boş ─────
    public function testOrtBirimFiyat(): void
    {
        $this->repo->tedarikciGidaKaydet('ATILGAN ET', 'kirmizi_et');
        $t1 = $this->gider('ATILGAN ET', 10000.0);
        // DANA: 100kg@50=5000 + 50kg@60=3000 → Σ8000/150kg = 53,33/kg
        $this->kalem($t1, 'DANA KIYMA', 100, 'KG', 50, 5000);
        $this->kalem($t1, 'DANA KIYMA', 50, 'KG', 60, 3000);
        // PİRİNÇ: miktarı bilinmeyen (NULL) satır → ort birim fiyat BOŞ
        $this->kalem($t1, 'PİRİNÇ', null, null, null, 2000);

        $o = $this->repo->kirilimUrunOzet('2026-07', 'kirmizi_et');
        $by = [];
        foreach ($o['urunler'] as $u) { $by[$u['urun']] = $u; }
        $this->assertEqualsWithDelta(150.0, $by['DANA KIYMA']['miktar'], 0.001);
        $this->assertEqualsWithDelta(53.3333, $by['DANA KIYMA']['ort_birim_fiyat'], 0.001, '8000/150');
        $this->assertNull($by['PİRİNÇ']['miktar'], 'miktar bilinmiyor');
        $this->assertNull($by['PİRİNÇ']['ort_birim_fiyat'], 'miktar yoksa ort birim fiyat boş');
    }

    // ── Miktar KISMEN eksikse (bir satır NULL) ort birim fiyat GÜVENSİZ → boş ─
    public function testMiktarKismenEksikOrtBos(): void
    {
        $this->repo->tedarikciGidaKaydet('ATILGAN ET', 'kirmizi_et');
        $t1 = $this->gider('ATILGAN ET', 8000.0);
        $this->kalem($t1, 'DANA', 100, 'KG', 50, 5000); // miktar var
        $this->kalem($t1, 'DANA', null, null, null, 3000); // aynı ürün, miktar YOK

        $o = $this->repo->kirilimUrunOzet('2026-07', 'kirmizi_et');
        $this->assertEqualsWithDelta(8000.0, $o['urunler'][0]['tutar'], 0.001);
        $this->assertNull($o['urunler'][0]['miktar'], 'karışık miktar → toplam güvenilmez');
        $this->assertNull($o['urunler'][0]['ort_birim_fiyat']);
    }

    // ── Kapsanmayan = kırılım toplam − kalemli toplam (dürüstlük) ─────
    public function testKapsanmayan(): void
    {
        $this->repo->tedarikciGidaKaydet('ATILGAN ET', 'kirmizi_et');
        $t1 = $this->gider('ATILGAN ET', 15000.0); // kalemli
        $this->gider('ATILGAN ET', 5000.0);        // t2: satırı YOK (ÖRS gibi)
        $this->kalem($t1, 'DANA KIYMA', 100, 'KG', 50, 5000);
        $this->kalem($t1, 'KUZU BUT', 40, 'KG', 150, 6000);

        $o = $this->repo->kirilimUrunOzet('2026-07', 'kirmizi_et');
        $this->assertEqualsWithDelta(20000.0, $o['toplam'], 0.001, 'kırılım toplam = 15000+5000');
        $this->assertEqualsWithDelta(11000.0, $o['kalemli_toplam'], 0.001, '5000+6000');
        $this->assertEqualsWithDelta(9000.0, $o['kapsanmayan'], 0.001, '20000-11000');
    }

    // ── Kırılım eşleşmesine saygı: başka kırılımın kalemleri GİRMEZ ───
    public function testKirilimEslesmesineSaygi(): void
    {
        $this->repo->tedarikciGidaKaydet('ATILGAN ET', 'kirmizi_et');
        $this->repo->tedarikciGidaKaydet('ÖRS GIDA', 'kuru_gida');
        $tK = $this->gider('ATILGAN ET', 6000.0);
        $tO = $this->gider('ÖRS GIDA', 4000.0);
        $this->kalem($tK, 'DANA KIYMA', 100, 'KG', 50, 6000);
        $this->kalem($tO, 'PİRİNÇ', 200, 'KG', 20, 4000);

        $kirmizi = $this->repo->kirilimUrunOzet('2026-07', 'kirmizi_et');
        $this->assertCount(1, $kirmizi['urunler']);
        $this->assertSame('DANA KIYMA', $kirmizi['urunler'][0]['urun'], 'ÖRS pirinci kırmızı ete girmez');
        $this->assertEqualsWithDelta(6000.0, $kirmizi['toplam'], 0.001);

        $kuru = $this->repo->kirilimUrunOzet('2026-07', 'kuru_gida');
        $this->assertCount(1, $kuru['urunler']);
        $this->assertSame('PİRİNÇ', $kuru['urunler'][0]['urun']);
    }

    // ── REGRESYON: kalem tablosu BOŞken → urunler boş + kapsanmayan = toplam ─
    public function testKalemBosRegresyon(): void
    {
        $this->repo->tedarikciGidaKaydet('ÖRS GIDA', 'kuru_gida');
        $this->gider('ÖRS GIDA', 45000.0);

        $o = $this->repo->kirilimUrunOzet('2026-07', 'kuru_gida');
        $this->assertSame([], $o['urunler'], 'kalem yok → ürün listesi boş');
        $this->assertSame(0, $o['urun_sayisi']);
        $this->assertEqualsWithDelta(45000.0, $o['toplam'], 0.001);
        $this->assertEqualsWithDelta(45000.0, $o['kapsanmayan'], 0.001, 'tamamı kapsanmayan');

        // gidaCostOzet DEĞİŞMEDEN çalışıyor (kart aynı)
        $g = $this->repo->gidaCostOzet('2026-07');
        $this->assertEqualsWithDelta(45000.0, $g['toplam'], 0.001);
    }

    // ── Eşleşmesiz kırılım / hiç tx yok → boş ama tutarlı yapı ────────
    public function testTxYok(): void
    {
        $o = $this->repo->kirilimUrunOzet('2026-07', 'kirmizi_et');
        $this->assertEqualsWithDelta(0.0, $o['toplam'], 0.001);
        $this->assertEqualsWithDelta(0.0, $o['kapsanmayan'], 0.001);
        $this->assertSame([], $o['urunler']);
    }

    // ── Senkron: mock UBL fetcher ile idempotent yazma ────────────────
    public function testSenkronIdempotent(): void
    {
        $this->gider('ATILGAN ET', 15000.0, 'ei-1001');
        $this->gider('ÖRS GIDA', 4000.0, 'ei-1002');
        $this->gider('ELLE GİRİLEN', 999.0, null); // parasut_id yok → aday değil

        $cagri = [];
        $fetcher = function (string $eid) use (&$cagri): ?array {
            $cagri[] = $eid;
            if ($eid === '1001') {
                return [
                    ['urun' => 'DANA KIYMA', 'miktar' => 100.0, 'birim' => 'KG', 'birim_fiyat' => 50.0, 'tutar' => 5000.0],
                    ['urun' => 'KUZU BUT', 'miktar' => 40.0, 'birim' => 'KG', 'birim_fiyat' => 150.0, 'tutar' => 6000.0],
                ];
            }
            return [['urun' => 'PİRİNÇ', 'miktar' => 200.0, 'birim' => 'KG', 'birim_fiyat' => 20.0, 'tutar' => 4000.0]];
        };

        $r1 = $this->repo->giderKalemSenkron('2026-07', $fetcher);
        $this->assertSame(2, $r1['aday'], 'sadece ei- önekli 2 tx (elle girilen hariç)');
        $this->assertSame(2, $r1['islenen']);
        $this->assertSame(3, $r1['kalem'], '2 + 1 satır');
        $this->assertSame(['1001', '1002'], $cagri, "'ei-' öneki atılıp eInvoiceId ile çağrılır");
        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM gider_kalem')->fetchColumn());

        // 2. koşu: hepsi zaten var → hiç yeni satır, fetcher ÇAĞRILMAZ (idempotent)
        $cagri = [];
        $r2 = $this->repo->giderKalemSenkron('2026-07', $fetcher);
        $this->assertSame(2, $r2['atlanan']);
        $this->assertSame(0, $r2['islenen']);
        $this->assertSame(0, $r2['kalem']);
        $this->assertSame([], $cagri, 'kalemi olan tx için UBL yeniden çekilmez');
        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM gider_kalem')->fetchColumn(), 'mükerrer yok');
    }

    // ── Senkron: UBL okunamayan tx atlanır (kalem yazılmaz, sonra yeniden denenir) ─
    public function testSenkronOkunamayanRetryEdilebilir(): void
    {
        $this->gider('ATILGAN ET', 15000.0, 'ei-2001');

        // 1. koşu: UBL okunamadı (null) → yazma
        $bosFetcher = static fn(string $eid): ?array => null;
        $r1 = $this->repo->giderKalemSenkron('2026-07', $bosFetcher);
        $this->assertSame(1, $r1['okunamadi']);
        $this->assertSame(0, $r1['kalem']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM gider_kalem')->fetchColumn());

        // 2. koşu: UBL geldi → artık yazılır (retry çalışır, çünkü 1. koşu kalem yazmadı)
        $dolu = static fn(string $eid): ?array => [
            ['urun' => 'DANA', 'miktar' => 10.0, 'birim' => 'KG', 'birim_fiyat' => 100.0, 'tutar' => 1000.0],
        ];
        $r2 = $this->repo->giderKalemSenkron('2026-07', $dolu);
        $this->assertSame(1, $r2['islenen']);
        $this->assertSame(1, $r2['kalem']);
    }

    // ── Senkron + kirilimUrunOzet uçtan uca: senkronlanan kalemler kırılımda görünür ─
    public function testSenkronSonrasiOzet(): void
    {
        $this->repo->tedarikciGidaKaydet('ATILGAN ET', 'kirmizi_et');
        $this->gider('ATILGAN ET', 11000.0, 'ei-3001');
        $fetcher = static fn(string $eid): ?array => [
            ['urun' => 'DANA KIYMA', 'miktar' => 100.0, 'birim' => 'KG', 'birim_fiyat' => 50.0, 'tutar' => 5000.0],
            ['urun' => 'KUZU BUT', 'miktar' => 40.0, 'birim' => 'KG', 'birim_fiyat' => 150.0, 'tutar' => 6000.0],
        ];
        $this->repo->giderKalemSenkron('2026-07', $fetcher);

        $o = $this->repo->kirilimUrunOzet('2026-07', 'kirmizi_et');
        $this->assertCount(2, $o['urunler']);
        $this->assertEqualsWithDelta(6000.0, $o['urunler'][0]['tutar'], 0.001, 'KUZU BUT en pahalı');
        $this->assertEqualsWithDelta(0.0, $o['kapsanmayan'], 0.001, '11000 tam kapsandı');
    }
}
