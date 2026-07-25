<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * fable-035 — Tedarikçi/Personel → müşteri maliyet eşleştirmesi.
 * Eşleşen tedarikçi faturaları / personel yüklü maliyeti seçili müşterilere o ayki KİŞİ
 * SAYISI oranında dağılır (Ömer 30/70 örneği); eşleşmeyenler mevcut genel havuzda kalır
 * (regresyon: tablolar boşken tüm sayılar eskisiyle birebir).
 */
final class MaliyetEslestirmeTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    /** Paraşüt kaynaklı gider tx ekle (firma = description ' · ' öncesi). */
    private function parasutGider(string $firma, string $tarih, float $tutar, string $kategori = 'Gıda', string $alloc = 'genel'): int
    {
        $this->pdo->prepare(
            "INSERT INTO transactions (type, category, tx_date, amount, description, source, alloc_type)
             VALUES ('gider', ?, ?, ?, ?, 'parasut', ?)"
        )->execute([$kategori, $tarih, $tutar, $firma . ' · INV', $alloc]);
        return (int) $this->pdo->lastInsertId();
    }

    // ── Ömer örneği: 30/70 kişi → 100 TL'nin 30/70'i ─────────────
    public function testTedarikciKisiOrani3070(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 30, 1.0, 'ogle'); // 30 kişi
        $this->repo->upsertProduction($b, '2026-07-01', 70, 1.0, 'ogle'); // 70 kişi
        $this->parasutGider('AKSU GIDA', '2026-07-05', 100.0);
        $this->repo->tedarikciEslestirmeKaydet('AKSU GIDA', [$a, $b]);

        $d = $this->repo->giderDagitim('2026-07');
        $this->assertEqualsWithDelta(30.0, $d['per_customer'][$a], 0.001, 'A: 30 kişi → 30 TL');
        $this->assertEqualsWithDelta(70.0, $d['per_customer'][$b], 0.001, 'B: 70 kişi → 70 TL');
        $this->assertEqualsWithDelta(0.0, $d['dagitilmamis'], 0.001);
    }

    // ── Regresyon: eşleşme yokken CİRO oranı korunur (kişi değil) ─
    public function testEslesmesizGiderCiroRegresyon(): void
    {
        // Kişi eşit (100/100) ama ciro 100/300 → ciro yolu A=100/B=300 vermeli (kişi olsaydı 200/200).
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 3.0);
        $this->repo->upsertProduction($a, '2026-07-01', 100, 1.0, 'ogle'); // ciro 100
        $this->repo->upsertProduction($b, '2026-07-01', 100, 3.0, 'ogle'); // ciro 300
        $this->parasutGider('AKSU GIDA', '2026-07-05', 400.0); // eşleşme YOK

        $d = $this->repo->giderDagitim('2026-07');
        $this->assertEqualsWithDelta(100.0, $d['per_customer'][$a], 0.001, 'ciro oranı: A %25');
        $this->assertEqualsWithDelta(300.0, $d['per_customer'][$b], 0.001, 'ciro oranı: B %75');
    }

    // ── Elle 'musteri' seçimi tedarikçi eşleşmesini EZER ─────────
    public function testMusteriAllocOnceligi(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 30, 1.0, 'ogle');
        $this->repo->upsertProduction($b, '2026-07-01', 70, 1.0, 'ogle');
        // Aynı firma hem 'musteri' hedef [A] hem tedarikçi eşleşmesi [B] → musteri KAZANIR
        $tx = $this->parasutGider('AKSU GIDA', '2026-07-05', 100.0, 'Gıda', 'musteri');
        $this->pdo->prepare('INSERT INTO transaction_customer (transaction_id, customer_id) VALUES (?, ?)')->execute([$tx, $a]);
        $this->repo->tedarikciEslestirmeKaydet('AKSU GIDA', [$b]);

        $d = $this->repo->giderDagitim('2026-07');
        $this->assertEqualsWithDelta(100.0, $d['per_customer'][$a], 0.001, 'musteri hedef A → %100');
        $this->assertArrayNotHasKey($b, $d['per_customer'], 'tedarikçi eşleşmesi B ezildi');
    }

    // ── Kişi toplamı 0 → eşit böl ────────────────────────────────
    public function testTedarikciKisiSifirEsitBol(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 1.0);
        // O ay hiç üretim yok → kişi 0; eşleşen firma yine de dağıtılmalı (eşit)
        $this->parasutGider('AKSU GIDA', '2026-07-05', 100.0);
        $this->repo->tedarikciEslestirmeKaydet('AKSU GIDA', [$a, $b]);

        $d = $this->repo->giderDagitim('2026-07');
        $this->assertEqualsWithDelta(50.0, $d['per_customer'][$a], 0.001, 'kişi 0 → eşit');
        $this->assertEqualsWithDelta(50.0, $d['per_customer'][$b], 0.001, 'kişi 0 → eşit');
        $this->assertEqualsWithDelta(0.0, $d['dagitilmamis'], 0.001);
    }

    // ── Türkçe normalize: KIRMIZI 1 == kırmızı 1 == Kırmızı 1 ────
    public function testTurkceNormalize(): void
    {
        $this->assertSame('KIRMIZI 1', Repo::normTedarikci('kırmızı 1'));
        $this->assertSame('KIRMIZI 1', Repo::normTedarikci('KIRMIZI 1'));
        $this->assertSame('KIRMIZI 1', Repo::normTedarikci('  Kırmızı 1  '));
        $this->assertSame(Repo::normTedarikci('İstanbul'), Repo::normTedarikci('istanbul'));

        // Eşleşme case-insensitive çalışmalı: tx firma 'kırmızı 1', map 'KIRMIZI 1'
        $a = seed_customer($this->pdo, 'A', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 10, 1.0, 'ogle');
        $this->parasutGider('kırmızı 1', '2026-07-05', 200.0, 'Gıda');
        $this->repo->tedarikciEslestirmeKaydet('KIRMIZI 1', [$a]);

        $d = $this->repo->giderDagitim('2026-07');
        $this->assertEqualsWithDelta(200.0, $d['per_customer'][$a], 0.001, 'KIRMIZI 1 ~ kırmızı 1 eşleşti');
    }

    // ── Kaydet: sil+yaz, boş liste = eşleşmeyi kaldır ────────────
    public function testEslestirmeKaydetSilYaz(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 1.0);
        $this->repo->tedarikciEslestirmeKaydet('AKSU GIDA', [$a, $b]);
        $this->assertSame([$a, $b], $this->repo->tedarikciEslestirmeler()['AKSU GIDA']);
        // yeniden yaz → sadece A
        $this->repo->tedarikciEslestirmeKaydet('AKSU GIDA', [$a]);
        $this->assertSame([$a], $this->repo->tedarikciEslestirmeler()['AKSU GIDA']);
        // boş → kaldır
        $this->repo->tedarikciEslestirmeKaydet('AKSU GIDA', []);
        $this->assertArrayNotHasKey('AKSU GIDA', $this->repo->tedarikciEslestirmeler());
    }

    // ── PERSONEL 30/70 kişi oranı ────────────────────────────────
    public function testPersonelKisiOrani3070(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 30, 1.0, 'ogle');
        $this->repo->upsertProduction($b, '2026-07-01', 70, 1.0, 'ogle');
        $pid = $this->repo->upsertPersonel('Ahmet', 'Aşçı', 10000.0);
        $this->repo->personelEslestirmeKaydet($pid, [$a, $b]);
        $yuklu = $this->repo->personelYukluMaliyet($pid, '2026-07')['yuklu_toplam'];

        $d = $this->repo->personelDagitim('2026-07');
        $this->assertEqualsWithDelta($yuklu * 0.30, $d['per_customer'][$a], 0.01, 'A: 30 kişi');
        $this->assertEqualsWithDelta($yuklu * 0.70, $d['per_customer'][$b], 0.01, 'B: 70 kişi');
        $this->assertEqualsWithDelta($yuklu, $d['toplam'], 0.01);
        $this->assertEqualsWithDelta(0.0, $d['dagitilmamis'], 0.01);
    }

    // ── PERSONEL regresyon: eşleşme yokken 'genel' hacim oranı korunur ─
    public function testPersonelEslesmesizRegresyon(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 100, 1.0, 'ogle'); // hacim 100
        $this->repo->upsertProduction($b, '2026-07-01', 300, 1.0, 'ogle'); // hacim 300
        $pid = $this->repo->upsertPersonel('Ahmet', 'Aşçı', 10000.0);
        $this->repo->setPersonelAtama($pid, true); // genel — map YOK
        $yuklu = $this->repo->personelYukluMaliyet($pid, '2026-07')['yuklu_toplam'];

        $d = $this->repo->personelDagitim('2026-07');
        $this->assertEqualsWithDelta($yuklu * 0.25, $d['per_customer'][$a], 0.01, 'genel hacim: A %25');
        $this->assertEqualsWithDelta($yuklu * 0.75, $d['per_customer'][$b], 0.01, 'genel hacim: B %75');
    }

    // ── PERSONEL kişi 0 → eşit böl ───────────────────────────────
    public function testPersonelKisiSifirEsitBol(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 1.0);
        $pid = $this->repo->upsertPersonel('Ahmet', 'Aşçı', 10000.0);
        $this->repo->personelEslestirmeKaydet($pid, [$a, $b]); // ay üretimi yok → kişi 0
        $yuklu = $this->repo->personelYukluMaliyet($pid, '2026-07')['yuklu_toplam'];

        $d = $this->repo->personelDagitim('2026-07');
        $this->assertEqualsWithDelta($yuklu / 2, $d['per_customer'][$a], 0.01);
        $this->assertEqualsWithDelta($yuklu / 2, $d['per_customer'][$b], 0.01);
    }

    // ── Tedarikçi + personel eşleşmeleri birlikte: karAnalizi tutarlı ─
    public function testKarAnaliziEslesmeliTutarlilik(): void
    {
        $c = seed_customer($this->pdo, 'CANTAŞ', 328.0);
        $this->repo->upsertProduction($c, '2026-07-01', 100, 328.0, 'ogle'); // ciro 32800, kişi 100
        $d2 = seed_customer($this->pdo, 'OPAK', 250.0);
        $this->repo->upsertProduction($d2, '2026-07-02', 40, 250.0, 'ogle'); // ciro 10000, kişi 40
        // Tedarikçi eşleşmesi: AKSU → sadece CANTAŞ
        $this->parasutGider('AKSU GIDA', '2026-07-05', 5000.0, 'Gıda');
        $this->repo->tedarikciEslestirmeKaydet('AKSU GIDA', [$c]);
        // Eşleşmesiz genel gider (ciro havuzu)
        $this->parasutGider('BAK ET', '2026-07-06', 3000.0, 'Et');
        // Personel eşleşmesi: sadece OPAK
        $pid = $this->repo->upsertPersonel('Ahmet', 'Aşçı', 15000.0);
        $this->repo->personelEslestirmeKaydet($pid, [$d2]);

        $ka = $this->repo->karAnalizi('2026-07');
        $nk = $this->repo->netKarlilik('2026-07');

        $sum = 0.0;
        foreach ([$c, $d2] as $cid) {
            $sum += $this->repo->customerNetKarlilik($cid, '2026-07')['net'];
        }
        $this->assertEqualsWithDelta($nk['net'], $ka['toplam_net'], 0.01, 'karAnalizi toplam = netKarlilik');
        $this->assertEqualsWithDelta($sum, $ka['toplam_net'], 0.01, 'toplam = Σ customerNet (kırılım toplamı)');
        $this->assertEqualsWithDelta(0.0, $ka['dagitilmamis'], 0.01, 'her şey dağıtıldı');
        // AKSU tümü CANTAŞ'a, personel tümü OPAK'a
        $this->assertEqualsWithDelta(5000.0 + 3000.0 * (32800.0 / 42800.0), $this->repo->giderDagitim('2026-07')['per_customer'][$c], 0.01);
    }

    // ══ fable-037: FATURA bazlı eşleştirme ═══════════════════════════════════

    // ── Fatura eşleşmesi TEDARİKÇİ eşleşmesini EZER ──────────────
    public function testFaturaEzerTedarikci(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 30, 1.0, 'ogle');
        $this->repo->upsertProduction($b, '2026-07-01', 70, 1.0, 'ogle');
        // Tedarikçi AKSU → A'ya eşli; ama tek fatura B'ye özel dağıtılıyor.
        $tx = $this->parasutGider('AKSU GIDA', '2026-07-05', 100.0);
        $this->repo->tedarikciEslestirmeKaydet('AKSU GIDA', [$a]);
        $this->repo->faturaEslestirmeKaydet($tx, [$b]);

        $d = $this->repo->giderDagitim('2026-07');
        $this->assertEqualsWithDelta(100.0, $d['per_customer'][$b], 0.001, 'fatura hedef B → %100');
        $this->assertArrayNotHasKey($a, $d['per_customer'], 'tedarikçi eşleşmesi A ezildi');
    }

    // ── Fatura eşleşmesi KİŞİ oranlı 30/70 ───────────────────────
    public function testFaturaKisiOrani3070(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 30, 1.0, 'ogle');
        $this->repo->upsertProduction($b, '2026-07-01', 70, 1.0, 'ogle');
        $tx = $this->parasutGider('AKSU GIDA', '2026-07-05', 100.0);
        $this->repo->faturaEslestirmeKaydet($tx, [$a, $b]);

        $d = $this->repo->giderDagitim('2026-07');
        $this->assertEqualsWithDelta(30.0, $d['per_customer'][$a], 0.001, 'A: 30 kişi → 30 TL');
        $this->assertEqualsWithDelta(70.0, $d['per_customer'][$b], 0.001, 'B: 70 kişi → 70 TL');
        $this->assertEqualsWithDelta(0.0, $d['dagitilmamis'], 0.001);
    }

    // ── Eşleşmesi OLMAYAN faturalar tedarikçi kuralında kalır ────
    public function testFaturaOzelYoksaTedarikciKurali(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 30, 1.0, 'ogle');
        $this->repo->upsertProduction($b, '2026-07-01', 70, 1.0, 'ogle');
        // Aynı tedarikçiden 2 fatura; sadece BİRİ özel (B'ye). Diğeri tedarikçi kuralı (A).
        $tx1 = $this->parasutGider('AKSU GIDA', '2026-07-05', 100.0);
        $tx2 = $this->parasutGider('AKSU GIDA', '2026-07-06', 200.0);
        $this->repo->tedarikciEslestirmeKaydet('AKSU GIDA', [$a]); // tedarikçi → A
        $this->repo->faturaEslestirmeKaydet($tx1, [$b]);           // tx1 özel → B

        $d = $this->repo->giderDagitim('2026-07');
        // tx1 (100) → B özel; tx2 (200) → A tedarikçi kuralı
        $this->assertEqualsWithDelta(200.0, $d['per_customer'][$a], 0.001, 'tx2 tedarikçi kuralı A');
        $this->assertEqualsWithDelta(100.0, $d['per_customer'][$b], 0.001, 'tx1 özel B');
    }

    // ── alloc='musteri' + fatura eşleşmesi AYNI tx → fatura kazanır ─
    public function testFaturaEzerMusteriAlloc(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 30, 1.0, 'ogle');
        $this->repo->upsertProduction($b, '2026-07-01', 70, 1.0, 'ogle');
        // Elle 'musteri' hedef A (ciro oranlı) AMA aynı tx'e fatura eşleşmesi B (kişi oranlı) → fatura kazanır
        $tx = $this->parasutGider('AKSU GIDA', '2026-07-05', 100.0, 'Gıda', 'musteri');
        $this->pdo->prepare('INSERT INTO transaction_customer (transaction_id, customer_id) VALUES (?, ?)')->execute([$tx, $a]);
        $this->repo->faturaEslestirmeKaydet($tx, [$b]);

        $d = $this->repo->giderDagitim('2026-07');
        $this->assertEqualsWithDelta(100.0, $d['per_customer'][$b], 0.001, 'fatura katmanı B kazandı');
        $this->assertArrayNotHasKey($a, $d['per_customer'], 'elle musteri A ezildi');
    }

    // ── Fatura kişi 0 → eşit böl ─────────────────────────────────
    public function testFaturaKisiSifirEsitBol(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 1.0);
        $tx = $this->parasutGider('AKSU GIDA', '2026-07-05', 100.0); // o ay üretim yok → kişi 0
        $this->repo->faturaEslestirmeKaydet($tx, [$a, $b]);

        $d = $this->repo->giderDagitim('2026-07');
        $this->assertEqualsWithDelta(50.0, $d['per_customer'][$a], 0.001, 'kişi 0 → eşit');
        $this->assertEqualsWithDelta(50.0, $d['per_customer'][$b], 0.001, 'kişi 0 → eşit');
    }

    // ── REGRESYON: fatura_musteri_map BOŞken sonuç fable-035 ile birebir ─
    public function testFaturaBosRegresyon(): void
    {
        // testKarAnaliziEslesmeliTutarlilik senaryosunun aynısı — fatura tablosuna DOKUNMA.
        $c = seed_customer($this->pdo, 'CANTAŞ', 328.0);
        $this->repo->upsertProduction($c, '2026-07-01', 100, 328.0, 'ogle');
        $d2 = seed_customer($this->pdo, 'OPAK', 250.0);
        $this->repo->upsertProduction($d2, '2026-07-02', 40, 250.0, 'ogle');
        $this->parasutGider('AKSU GIDA', '2026-07-05', 5000.0, 'Gıda');
        $this->repo->tedarikciEslestirmeKaydet('AKSU GIDA', [$c]);
        $this->parasutGider('BAK ET', '2026-07-06', 3000.0, 'Et');

        // fatura tablosu BOŞ → fable-035 ile birebir aynı sonuç
        $this->assertSame([], $this->repo->faturaEslestirmeler(), 'fatura tablosu boş');
        $g = $this->repo->giderDagitim('2026-07');
        $this->assertEqualsWithDelta(5000.0 + 3000.0 * (32800.0 / 42800.0), $g['per_customer'][$c], 0.01, 'CANTAŞ fable-035 ile birebir');
        $this->assertEqualsWithDelta(3000.0 * (10000.0 / 42800.0), $g['per_customer'][$d2], 0.01, 'OPAK fable-035 ile birebir');
        $this->assertEqualsWithDelta(0.0, $g['dagitilmamis'], 0.01);
    }

    // ── faturaEslestirmeKaydet: sil+yaz, boş = kaldır; faturaEslestirmeler(ay) filtresi ─
    public function testFaturaKaydetSilYazVeAyFiltre(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $b = seed_customer($this->pdo, 'B', 1.0);
        $txTem = $this->parasutGider('AKSU GIDA', '2026-07-05', 100.0);
        $txAgu = $this->parasutGider('AKSU GIDA', '2026-08-05', 100.0);
        $this->repo->faturaEslestirmeKaydet($txTem, [$a, $b]);
        $this->repo->faturaEslestirmeKaydet($txAgu, [$a]);

        $this->assertSame([$a, $b], $this->repo->faturaEslestirmeler()[$txTem]);
        // ay filtresi: Temmuz sadece txTem döner
        $tem = $this->repo->faturaEslestirmeler('2026-07');
        $this->assertArrayHasKey($txTem, $tem);
        $this->assertArrayNotHasKey($txAgu, $tem, 'Ağustos tx Temmuz filtresinde yok');
        // yeniden yaz → sadece A
        $this->repo->faturaEslestirmeKaydet($txTem, [$a]);
        $this->assertSame([$a], $this->repo->faturaEslestirmeler()[$txTem]);
        // boş → kaldır
        $this->repo->faturaEslestirmeKaydet($txTem, []);
        $this->assertArrayNotHasKey($txTem, $this->repo->faturaEslestirmeler());
    }
}
