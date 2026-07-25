<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * fable-039 — Kişi başı GIDA MALİYETİ kırılımları.
 * Gıda alım faturaları tedarikçi bazında kırılıma eşlenir; kişi başı gıda maliyeti + kırılım
 * oranları çıkar. Eşleşmeyen tedarikçi gıda costuna GİRMEZ ve karAnalizi/netKarlilik sayılarını
 * ETKİLEMEZ (ayrı görünüm katmanı). Kişi = o ay ÜRETİM kategorisi müşterilerin toplam kişisi.
 */
final class GidaCostTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    private function parasutGider(string $firma, string $tarih, float $tutar, string $kategori = 'Gıda'): int
    {
        $this->pdo->prepare(
            "INSERT INTO transactions (type, category, tx_date, amount, description, source, alloc_type)
             VALUES ('gider', ?, ?, ?, ?, 'parasut', 'genel')"
        )->execute([$kategori, $tarih, $tutar, $firma . ' · INV']);
        return (int) $this->pdo->lastInsertId();
    }

    private function seedTasima(string $name, float $price): int
    {
        $this->pdo->prepare("INSERT INTO customers (name, unit_price, category) VALUES (?, ?, 'tasima')")->execute([$name, $price]);
        return (int) $this->pdo->lastInsertId();
    }

    // ── Ömer örneği: ÖRS 1000 + ATILGAN 500, üretim 100 kişi → kişi başı 15,00; oran 66,7/33,3 ──
    public function testKisiBasiVeOranlar(): void
    {
        $a = seed_customer($this->pdo, 'CANTAŞ', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 100, 1.0, 'ogle'); // 100 üretim kişi
        $this->parasutGider('ÖRS GIDA', '2026-07-05', 1000.0);
        $this->parasutGider('ATILGAN ET', '2026-07-06', 500.0);
        $this->repo->tedarikciGidaKaydet('ÖRS GIDA', 'kuru_gida');
        $this->repo->tedarikciGidaKaydet('ATILGAN ET', 'kirmizi_et');

        $o = $this->repo->gidaCostOzet('2026-07');
        $this->assertEqualsWithDelta(1500.0, $o['toplam'], 0.001);
        $this->assertSame(100, $o['kisi_toplam']);
        $this->assertEqualsWithDelta(15.0, $o['kisi_basi'], 0.001, '1500 / 100 kişi = 15,00');

        $byKod = [];
        foreach ($o['kirilimlar'] as $r) {
            $byKod[$r['kod']] = $r;
        }
        $this->assertEqualsWithDelta(1000.0, $byKod['kuru_gida']['tutar'], 0.001);
        $this->assertEqualsWithDelta(0.6667, $byKod['kuru_gida']['oran'], 0.001, 'kuru gıda %66,7');
        $this->assertEqualsWithDelta(10.0, $byKod['kuru_gida']['kisi_basi'], 0.001);
        $this->assertEqualsWithDelta(500.0, $byKod['kirmizi_et']['tutar'], 0.001);
        $this->assertEqualsWithDelta(0.3333, $byKod['kirmizi_et']['oran'], 0.001, 'kırmızı et %33,3');
        // sıra: kuru_gida (1) kırmızı_et'ten (2) önce
        $this->assertSame('kuru_gida', $o['kirilimlar'][0]['kod']);
    }

    // ── Eşleşmesiz tedarikçi gıda costuna GİRMEZ ─────────────────
    public function testEslesmesizTedarikciDisi(): void
    {
        $a = seed_customer($this->pdo, 'CANTAŞ', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 50, 1.0, 'ogle');
        $this->parasutGider('ÖRS GIDA', '2026-07-05', 1000.0);
        $this->parasutGider('BİLİNMEYEN FİRMA', '2026-07-06', 9999.0); // eşleşme YOK
        $this->repo->tedarikciGidaKaydet('ÖRS GIDA', 'kuru_gida');

        $o = $this->repo->gidaCostOzet('2026-07');
        $this->assertEqualsWithDelta(1000.0, $o['toplam'], 0.001, 'sadece eşli tedarikçi girer');
        $this->assertCount(1, $o['kirilimlar']);
    }

    // ── Kırılım eşleşmesi değişince özet değişir ─────────────────
    public function testKirilimDegisinceOzetDegisir(): void
    {
        $a = seed_customer($this->pdo, 'CANTAŞ', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 100, 1.0, 'ogle');
        $this->parasutGider('ÖRS GIDA', '2026-07-05', 800.0);
        $this->repo->tedarikciGidaKaydet('ÖRS GIDA', 'kuru_gida');
        $o1 = $this->repo->gidaCostOzet('2026-07');
        $this->assertSame('kuru_gida', $o1['kirilimlar'][0]['kod']);

        // kırılımı değiştir → beyaz_et
        $this->repo->tedarikciGidaKaydet('ÖRS GIDA', 'beyaz_et');
        $o2 = $this->repo->gidaCostOzet('2026-07');
        $this->assertSame('beyaz_et', $o2['kirilimlar'][0]['kod']);
        $this->assertEqualsWithDelta(800.0, $o2['toplam'], 0.001);

        // eşleşmeyi kaldır → gıda costu boşalır
        $this->repo->tedarikciGidaKaydet('ÖRS GIDA', null);
        $o3 = $this->repo->gidaCostOzet('2026-07');
        $this->assertEqualsWithDelta(0.0, $o3['toplam'], 0.001);
        $this->assertSame([], $o3['kirilimlar']);
    }

    // ── Üretim/taşıma kişi ayrımı: taşıma müşterisi kişisi SAYILMAZ ─
    public function testUretimTasimaKisiAyrimi(): void
    {
        $u = seed_customer($this->pdo, 'ÜRETİM A', 1.0);
        $t = $this->seedTasima('TAŞIMA B', 1.0);
        $this->repo->upsertProduction($u, '2026-07-01', 40, 1.0, 'ogle'); // üretim 40 kişi
        $this->repo->upsertProduction($t, '2026-07-01', 60, 1.0, 'ogle'); // taşıma 60 kişi (sayılmaz)
        $this->parasutGider('ÖRS GIDA', '2026-07-05', 400.0);
        $this->repo->tedarikciGidaKaydet('ÖRS GIDA', 'kuru_gida');

        $o = $this->repo->gidaCostOzet('2026-07');
        $this->assertSame(40, $o['kisi_toplam'], 'sadece üretim kişisi');
        $this->assertEqualsWithDelta(10.0, $o['kisi_basi'], 0.001, '400 / 40 = 10');
    }

    // ── Kişi 0 → sıfıra bölme yok, kişi başı 0 ───────────────────
    public function testKisiSifirBolmeYok(): void
    {
        $this->parasutGider('ÖRS GIDA', '2026-07-05', 500.0); // o ay üretim yok → kişi 0
        $this->repo->tedarikciGidaKaydet('ÖRS GIDA', 'kuru_gida');

        $o = $this->repo->gidaCostOzet('2026-07');
        $this->assertSame(0, $o['kisi_toplam']);
        $this->assertEqualsWithDelta(0.0, $o['kisi_basi'], 0.001);
        $this->assertEqualsWithDelta(500.0, $o['toplam'], 0.001);
        $this->assertEqualsWithDelta(0.0, $o['kirilimlar'][0]['kisi_basi'], 0.001);
    }

    // ── Kaydet: geçersiz kod → gıda costu dışı; boş = kaldır ─────
    public function testKaydetGecerlilik(): void
    {
        $this->repo->tedarikciGidaKaydet('ÖRS GIDA', 'kuru_gida');
        $this->assertSame('kuru_gida', $this->repo->tedarikciGidaMap()[Repo::normTedarikci('ÖRS GIDA')]);
        // geçersiz kod → kaldırılır (satır yok)
        $this->repo->tedarikciGidaKaydet('ÖRS GIDA', 'olmayan_kod');
        $this->assertArrayNotHasKey(Repo::normTedarikci('ÖRS GIDA'), $this->repo->tedarikciGidaMap());
        // TR normalize: 'örs gıda' ile 'ÖRS GIDA' aynı anahtar
        $this->repo->tedarikciGidaKaydet('örs gıda', 'hal');
        $this->assertSame('hal', $this->repo->tedarikciGidaMap()[Repo::normTedarikci('ÖRS GIDA')]);
    }

    // ── REGRESYON: map boşken gıda cost 0 + karAnalizi/netKarlilik DEĞİŞMEZ ─
    public function testMapBosRegresyon(): void
    {
        $c = seed_customer($this->pdo, 'CANTAŞ', 328.0);
        $this->repo->upsertProduction($c, '2026-07-01', 100, 328.0, 'ogle');
        $this->parasutGider('ÖRS GIDA', '2026-07-05', 5000.0);

        // map boş → gıda cost 0
        $o0 = $this->repo->gidaCostOzet('2026-07');
        $this->assertEqualsWithDelta(0.0, $o0['toplam'], 0.001);
        $this->assertSame([], $o0['kirilimlar']);

        $kaOnce = $this->repo->karAnalizi('2026-07');
        $nkOnce = $this->repo->netKarlilik('2026-07');

        // gıda eşleşmesi EKLE → karAnalizi/netKarlilik AYNI kalmalı (ayrı görünüm katmanı)
        $this->repo->tedarikciGidaKaydet('ÖRS GIDA', 'kuru_gida');
        $kaSonra = $this->repo->karAnalizi('2026-07');
        $nkSonra = $this->repo->netKarlilik('2026-07');

        $this->assertEqualsWithDelta($kaOnce['toplam_net'], $kaSonra['toplam_net'], 0.001, 'karAnalizi gıda mapten etkilenmez');
        $this->assertEqualsWithDelta($kaOnce['uretim']['gider'], $kaSonra['uretim']['gider'], 0.001);
        $this->assertEqualsWithDelta($nkOnce['net'], $nkSonra['net'], 0.001, 'netKarlilik gıda mapten etkilenmez');

        // ama gıda cost artık dolu
        $o1 = $this->repo->gidaCostOzet('2026-07');
        $this->assertEqualsWithDelta(5000.0, $o1['toplam'], 0.001);
        $this->assertEqualsWithDelta(50.0, $o1['kisi_basi'], 0.001);
    }

    // ── Varsayılan yükleyici: anahtar kelimeyle eşle, idempotent, elle seçimi ezmez ─
    public function testVarsayilanlariYukle(): void
    {
        // Canlıdaki gibi UZUN unvanlar
        $this->parasutGider('ÖRS GROUP GAYRİMENKUL YATIRIM İNŞAAT GIDA OTO SAN VE TİC AŞ', '2026-07-05', 100.0);
        $this->parasutGider('ATILGAN KIRMIZI ET LTD ŞTİ', '2026-07-06', 100.0);
        $this->parasutGider('İSTANBUL HALK EKMEK SAN', '2026-07-07', 100.0);
        $this->parasutGider('POLATOĞLU PASTANESİ', '2026-07-08', 100.0);
        $this->parasutGider('RASTGELE NAKLİYE AŞ', '2026-07-09', 100.0); // eşleşmesiz

        $applied = $this->repo->gidaKirilimVarsayilanlariYukle();
        $map = $this->repo->tedarikciGidaMap();
        $this->assertSame('kuru_gida', $map[Repo::normTedarikci('ÖRS GROUP GAYRİMENKUL YATIRIM İNŞAAT GIDA OTO SAN VE TİC AŞ')]);
        $this->assertSame('kirmizi_et', $map[Repo::normTedarikci('ATILGAN KIRMIZI ET LTD ŞTİ')]);
        $this->assertSame('ekmek', $map[Repo::normTedarikci('İSTANBUL HALK EKMEK SAN')]);
        $this->assertSame('tatli', $map[Repo::normTedarikci('POLATOĞLU PASTANESİ')]);
        $this->assertArrayNotHasKey(Repo::normTedarikci('RASTGELE NAKLİYE AŞ'), $map, 'eşleşmesiz firma girmez');
        $this->assertCount(4, $applied);

        // Idempotent: 2. çalıştırma yeni ekleme yapmaz
        $applied2 = $this->repo->gidaKirilimVarsayilanlariYukle();
        $this->assertCount(0, $applied2, '2. koşuda yeni ekleme yok');

        // Elle seçimi EZMEZ: ÖRS'yi elle beyaz_et yap, yeniden yükle → korunur
        $this->repo->tedarikciGidaKaydet('ÖRS GROUP GAYRİMENKUL YATIRIM İNŞAAT GIDA OTO SAN VE TİC AŞ', 'beyaz_et');
        $this->repo->gidaKirilimVarsayilanlariYukle();
        $map2 = $this->repo->tedarikciGidaMap();
        $this->assertSame('beyaz_et', $map2[Repo::normTedarikci('ÖRS GROUP GAYRİMENKUL YATIRIM İNŞAAT GIDA OTO SAN VE TİC AŞ')], 'elle seçim korunur');
    }
}
