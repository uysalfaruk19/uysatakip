<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Parasut;
use Uysa\Repo;

/**
 * fable-048 B — GERÇEK (fatura bazlı) kâr/zarar.
 *   Parasut::parseSalesInvoices  : sales_invoices JSON:API → sade satır (PÜR, mock; ağ YOK)
 *   Repo::satisFaturaIsle        : idempotent upsert (parasut_id UNIQUE) + müşteri eşleşmesi
 *   Repo::satisFaturaOzet        : kapsam/gecikme/eşleşmemiş gelir dürüstlüğü
 *   Repo::karAnaliziFatura       : gelir = kesilen faturalar, gider DEĞİŞMEZ
 *   Repo::karAnalizi             : ÜRETİM modu ESKİ sonucu birebir korur (regresyon)
 */
final class FaturaBazliKarTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
        $this->repo->setBugun('2026-07-25');
    }

    /** Mock Paraşüt sales_invoices sayfası. */
    private function resp(array $rows, array $contacts = []): array
    {
        $inc = [];
        foreach ($contacts as $id => $ad) {
            $inc[] = ['type' => 'contacts', 'id' => (string) $id, 'attributes' => ['name' => $ad]];
        }
        return ['data' => $rows, 'included' => $inc];
    }

    private function inv(string $id, string $gun, float $gross, float $vat, string $contact, array $ek = []): array
    {
        return ['id' => $id, 'type' => 'sales_invoices', 'attributes' => array_merge([
            'issue_date' => $gun, 'item_type' => 'invoice', 'invoice_no' => 'UYS' . $id,
            'gross_total' => $gross, 'total_vat' => $vat, 'net_total' => $gross + $vat,
            'description' => '',
        ], $ek), 'relationships' => ['contact' => ['data' => ['id' => $contact, 'type' => 'contacts']]]];
    }

    private function musteri(int $id, string $ad, string $parasutId, string $kategori = 'uretim'): void
    {
        $this->pdo->prepare('INSERT INTO customers (id, name, unit_price, category, parasut_id) VALUES (?, ?, 100, ?, ?)')
            ->execute([$id, $ad, $kategori, $parasutId]);
    }

    // ══ PÜR parser ═══════════════════════════════════════════════════
    public function testParseSalesInvoicesTemelAlanlar(): void
    {
        $r = Parasut::parseSalesInvoices($this->resp([
            $this->inv('9001', '2026-07-08', 100000.0, 10000.0, 'c-100', ['description' => '01.07.2026-07.07.2026 yemek']),
        ], ['c-100' => 'DEMİR ÇELİK AŞ']), '2026-07');

        $this->assertCount(1, $r['invoices']);
        $f = $r['invoices'][0];
        $this->assertSame('9001', $f['parasut_id']);
        $this->assertSame('2026-07-08', $f['fatura_tarihi']);
        $this->assertSame('c-100', $f['contact_id']);
        $this->assertSame('DEMİR ÇELİK AŞ', $f['contact_ad'], 'included contact adı çözülür');
        $this->assertEqualsWithDelta(100000.0, $f['net_tutar'], 0.01, 'KDV HARİÇ (kâr/zarar geliri)');
        $this->assertEqualsWithDelta(10000.0, $f['kdv'], 0.01);
        $this->assertEqualsWithDelta(110000.0, $f['toplam'], 0.01, 'KDV DAHİL genel toplam');
        $this->assertSame('2026-07-01', $f['donem_bas'], 'açıklamadan dönem çıkarıldı');
        $this->assertSame('2026-07-07', $f['donem_son']);
    }

    public function testParseIadeIptalVeFaturaDisiAyiklanir(): void
    {
        $r = Parasut::parseSalesInvoices($this->resp([
            $this->inv('1', '2026-07-05', 1000.0, 100.0, 'c-1'),
            $this->inv('2', '2026-07-06', 500.0, 50.0, 'c-1', ['item_type' => 'refund']),
            $this->inv('3', '2026-07-07', 700.0, 70.0, 'c-1', ['refund_of_id' => '1']),
            $this->inv('4', '2026-07-08', 900.0, 90.0, 'c-1', ['cancelled_at' => '2026-07-09T10:00:00Z']),
            $this->inv('5', '2026-07-09', 300.0, 30.0, 'c-1', ['item_type' => 'estimate']),
            $this->inv('6', '2026-07-10', 800.0, 80.0, 'c-1', ['item_type' => 'export_invoice']),
        ]), '2026-07');

        $this->assertCount(2, $r['invoices'], 'sadece invoice + export_invoice');
        $this->assertSame(['1', '6'], array_column($r['invoices'], 'parasut_id'));
        $this->assertSame(3, $r['iade'], 'refund + refund_of_id + iptal');
        $this->assertSame(1, $r['atlanan'], 'teklif (estimate) gelir değil');
    }

    public function testParseAySuzgeciVeEskiyeGecti(): void
    {
        $r = Parasut::parseSalesInvoices($this->resp([
            $this->inv('1', '2026-08-02', 1000.0, 100.0, 'c-1'), // gelecek ay → atla (sayılmadan)
            $this->inv('2', '2026-07-20', 2000.0, 200.0, 'c-1'),
            $this->inv('3', '2026-06-28', 3000.0, 300.0, 'c-1'), // eskiye geçti → dur
            $this->inv('4', '2026-06-27', 4000.0, 400.0, 'c-1'),
        ]), '2026-07');

        $this->assertSame(['2'], array_column($r['invoices'], 'parasut_id'));
        $this->assertTrue($r['eskiye_gecti'], '-issue_date sıralı → sonraki sayfaya gerek yok');
    }

    public function testParseNetTotalYoksaGrossArtiKdv(): void
    {
        $rows = [$this->inv('7', '2026-07-05', 1000.0, 180.0, 'c-1')];
        unset($rows[0]['attributes']['net_total']);
        $r = Parasut::parseSalesInvoices($this->resp($rows), '2026-07');
        $this->assertEqualsWithDelta(1180.0, $r['invoices'][0]['toplam'], 0.01);
        $this->assertEqualsWithDelta(1000.0, $r['invoices'][0]['net_tutar'], 0.01);
    }

    public function testDonemCikarTahminYok(): void
    {
        $this->assertSame(['bas' => '2026-07-01', 'son' => '2026-07-07'], Parasut::donemCikar('01.07.2026 - 07.07.2026 dönemi'));
        $this->assertSame(['bas' => '2026-07-08', 'son' => '2026-07-14'], Parasut::donemCikar('2026-07-08 – 2026-07-14'));
        $this->assertSame(['bas' => null, 'son' => null], Parasut::donemCikar('Temmuz ayı yemek hizmeti'));
        $this->assertSame(['bas' => null, 'son' => null], Parasut::donemCikar(''));
    }

    // ══ Upsert: idempotent + müşteri eşleşmesi ═══════════════════════
    public function testUpsertIdempotent(): void
    {
        $this->musteri(1, 'DEMİR ÇELİK AŞ', 'c-100');
        $liste = Parasut::parseSalesInvoices($this->resp([
            $this->inv('9001', '2026-07-08', 100000.0, 10000.0, 'c-100'),
            $this->inv('9002', '2026-07-15', 80000.0, 8000.0, 'c-100'),
        ]), '2026-07')['invoices'];

        $r1 = $this->repo->satisFaturaIsle($liste);
        $this->assertSame(2, $r1['yeni']);
        $this->assertSame(2, $r1['eslesen']);
        $this->assertSame(0, $r1['eslesmemis']);
        $this->assertEqualsWithDelta(180000.0, $r1['tutar'], 0.01);

        $r2 = $this->repo->satisFaturaIsle($liste);   // 2. koşu: aynı veri
        $this->assertSame(0, $r2['yeni'], 'mükerrer YOK');
        $this->assertSame(0, $r2['guncellenen']);
        $this->assertSame(2, $r2['mevcut']);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM satis_faturasi')->fetchColumn());
    }

    public function testUpsertDegisenFaturaGuncellenir(): void
    {
        $this->musteri(1, 'DEMİR ÇELİK AŞ', 'c-100');
        $this->repo->satisFaturaIsle(Parasut::parseSalesInvoices($this->resp([
            $this->inv('9001', '2026-07-08', 100000.0, 10000.0, 'c-100'),
        ]), '2026-07')['invoices']);

        // Paraşüt'te tutar düzeltildi → ayna güncellenir, satır SAYISI değişmez
        $r = $this->repo->satisFaturaIsle(Parasut::parseSalesInvoices($this->resp([
            $this->inv('9001', '2026-07-08', 120000.0, 12000.0, 'c-100'),
        ]), '2026-07')['invoices']);
        $this->assertSame(1, $r['guncellenen']);
        $this->assertSame(0, $r['yeni']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM satis_faturasi')->fetchColumn());
        $this->assertEqualsWithDelta(120000.0, (float) $this->pdo->query('SELECT net_tutar FROM satis_faturasi')->fetchColumn(), 0.01);
    }

    public function testEslesmeyenFaturaAyriDurur(): void
    {
        $this->musteri(1, 'DEMİR ÇELİK AŞ', 'c-100');
        $r = $this->repo->satisFaturaIsle(Parasut::parseSalesInvoices($this->resp([
            $this->inv('9001', '2026-07-08', 100000.0, 10000.0, 'c-100'),
            $this->inv('9009', '2026-07-19', 20000.0, 2000.0, 'c-777'),
        ], ['c-777' => 'KANTİN İŞLETMESİ']), '2026-07')['invoices']);

        $this->assertSame(1, $r['eslesmemis']);
        $ozet = $this->repo->satisFaturaOzet('2026-07');
        $this->assertEqualsWithDelta(100000.0, $ozet['eslesen_net'], 0.01);
        $this->assertEqualsWithDelta(20000.0, $ozet['eslesmemis_net'], 0.01, 'gizlenmez, uydurulmaz');
        $this->assertSame(1, $ozet['eslesmemis_adet']);
        $this->assertSame('KANTİN İŞLETMESİ', $ozet['eslesmemis'][0]['ad']);
        $this->assertEqualsWithDelta(120000.0, $ozet['net'], 0.01, 'toplam = eşleşen + eşleşmeyen');
    }

    public function testSonradanEslestirilenMusteriIkinciSenkrondaBaglanir(): void
    {
        $liste = Parasut::parseSalesInvoices($this->resp([
            $this->inv('9001', '2026-07-08', 100000.0, 10000.0, 'c-100'),
        ], ['c-100' => 'DEMİR ÇELİK AŞ']), '2026-07')['invoices'];
        $this->repo->satisFaturaIsle($liste);
        $this->assertSame(1, $this->repo->satisFaturaOzet('2026-07')['eslesmemis_adet']);

        $this->musteri(1, 'DEMİR ÇELİK AŞ', 'c-100');   // Ömer cariyi eşleştirdi
        $r = $this->repo->satisFaturaIsle($liste);
        $this->assertSame(1, $r['guncellenen']);
        $ozet = $this->repo->satisFaturaOzet('2026-07');
        $this->assertSame(0, $ozet['eslesmemis_adet']);
        $this->assertEqualsWithDelta(100000.0, $ozet['per_customer'][1], 0.01);
    }

    public function testKimliksizVeyaTarihsizYazilmaz(): void
    {
        $r = $this->repo->satisFaturaIsle([
            ['parasut_id' => '', 'fatura_tarihi' => '2026-07-08', 'net_tutar' => 5000],
            ['parasut_id' => 'x-1', 'fatura_tarihi' => '', 'net_tutar' => 5000],
            ['parasut_id' => 'x-2', 'fatura_tarihi' => 'abc', 'net_tutar' => 5000],
        ]);
        $this->assertSame(0, $r['yeni']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM satis_faturasi')->fetchColumn());
    }

    // ══ Kapsam / gecikme uyarısı (Ömer 7 günde bir keser) ════════════
    public function testKapsamVeGecikmeUyarisi(): void
    {
        $this->musteri(1, 'DEMİR ÇELİK AŞ', 'c-100');
        // Son fatura 14 Tem'e kadarki dönemi kapsıyor; bugün 25 Tem → 11 gün faturalanmadı
        $this->repo->satisFaturaIsle(Parasut::parseSalesInvoices($this->resp([
            $this->inv('9002', '2026-07-15', 80000.0, 8000.0, 'c-100', ['description' => '08.07.2026-14.07.2026']),
            $this->inv('9001', '2026-07-08', 100000.0, 10000.0, 'c-100', ['description' => '01.07.2026-07.07.2026']),
        ]), '2026-07')['invoices']);

        $o = $this->repo->satisFaturaOzet('2026-07');
        $this->assertSame(2, $o['adet']);
        $this->assertSame('2026-07-08', $o['ilk_fatura']);
        $this->assertSame('2026-07-15', $o['son_fatura']);
        $this->assertSame('2026-07-14', $o['kapsam_son'], 'dönem sonu fatura tarihinden önce olabilir');
        $this->assertSame(11, $o['gecikme_gun'], '25 Tem − 14 Tem');
        $this->assertTrue($o['uyari']);
    }

    public function testGecikmeEsigiSiniri(): void
    {
        $this->musteri(1, 'DEMİR ÇELİK AŞ', 'c-100');
        // 22 Tem'e kadar kapsandı → 3 gün (eşik 3) → uyarı VAR
        $this->repo->satisFaturaIsle(Parasut::parseSalesInvoices($this->resp([
            $this->inv('9003', '2026-07-23', 50000.0, 5000.0, 'c-100', ['description' => '15.07.2026-22.07.2026']),
        ]), '2026-07')['invoices']);
        $this->assertSame(3, $this->repo->satisFaturaOzet('2026-07')['gecikme_gun']);
        $this->assertTrue($this->repo->satisFaturaOzet('2026-07')['uyari'], 'eşik 3 → tam sınırda uyarır');

        // 24 Tem'e kadar kapsandı → 1 gün → uyarı YOK
        $this->repo->satisFaturaIsle(Parasut::parseSalesInvoices($this->resp([
            $this->inv('9004', '2026-07-25', 20000.0, 2000.0, 'c-100', ['description' => '23.07.2026-24.07.2026']),
        ]), '2026-07')['invoices']);
        $o = $this->repo->satisFaturaOzet('2026-07');
        $this->assertSame(1, $o['gecikme_gun']);
        $this->assertFalse($o['uyari']);
    }

    public function testGecmisAydaReferansAySonu(): void
    {
        $this->musteri(1, 'DEMİR ÇELİK AŞ', 'c-100');
        $this->repo->satisFaturaIsle(Parasut::parseSalesInvoices($this->resp([
            $this->inv('8001', '2026-06-30', 300000.0, 30000.0, 'c-100', ['description' => '01.06.2026-30.06.2026']),
        ]), '2026-06')['invoices']);
        $o = $this->repo->satisFaturaOzet('2026-06');
        $this->assertSame(0, $o['gecikme_gun'], 'geçmiş ay tam kapsandı');
        $this->assertFalse($o['uyari']);
    }

    public function testFaturaYokBosOzet(): void
    {
        $o = $this->repo->satisFaturaOzet('2026-07');
        $this->assertSame(0, $o['adet']);
        $this->assertEqualsWithDelta(0.0, $o['net'], 0.001);
        $this->assertNull($o['son_fatura']);
        $this->assertNull($o['gecikme_gun']);
        $this->assertFalse($o['uyari'], 'veri yoksa UYARI da UYDURULMAZ');
    }

    // ══ Kâr analizi: fatura modu vs üretim modu ══════════════════════
    /** Ortak sahne: 1 üretim müşterisi (üretim + gider + fatura). */
    private function sahne(): void
    {
        $this->musteri(1, 'DEMİR ÇELİK AŞ', 'c-100');
        foreach (['2026-07-01', '2026-07-02', '2026-07-03'] as $g) {
            $this->repo->upsertProduction(1, $g, 100, 200.0, 'ogle'); // 3×20.000 = 60.000 tahakkuk
        }
        $this->pdo->prepare("INSERT INTO transactions (type, category, tx_date, amount, description, source, alloc_type)
                             VALUES ('gider', 'Gıda', '2026-07-05', 25000, 'ÖRS GIDA · INV', 'parasut', 'genel')")->execute();
        $this->repo->satisFaturaIsle(Parasut::parseSalesInvoices($this->resp([
            $this->inv('9001', '2026-07-08', 42000.0, 4200.0, 'c-100', ['description' => '01.07.2026-07.07.2026']),
        ]), '2026-07')['invoices']);
    }

    public function testFaturaModundaGelirFaturaToplami(): void
    {
        $this->sahne();
        $ka = $this->repo->karAnaliziFatura('2026-07');
        $this->assertSame('fatura', $ka['kaynak']);
        $this->assertEqualsWithDelta(42000.0, $ka['uretim']['gelir'], 0.01, 'gelir = Σ kesilen fatura (KDV hariç)');
        $this->assertEqualsWithDelta(25000.0, $ka['uretim']['gider'], 0.01, 'gider DEĞİŞMEZ (fatura bazlı)');
        $this->assertEqualsWithDelta(17000.0, $ka['uretim']['net'], 0.01, '42000 − 25000');
        $this->assertSame(1, $ka['uretim']['rows'][0]['fatura_adedi']);
        $this->assertEqualsWithDelta(42000.0, $ka['toplam_gelir'], 0.01);
    }

    public function testUretimModuBirebirKorunurRegresyon(): void
    {
        $this->sahne();
        $ka = $this->repo->karAnalizi('2026-07');
        $this->assertSame('uretim', $ka['kaynak']);
        $this->assertEqualsWithDelta(60000.0, $ka['uretim']['gelir'], 0.01, 'tahakkuk cirosu (production.amount)');
        $this->assertEqualsWithDelta(25000.0, $ka['uretim']['gider'], 0.01);
        $this->assertEqualsWithDelta(35000.0, $ka['uretim']['net'], 0.01);
        // Fatura tablosu ÜRETİM modunu hiç etkilemez: faturaları silsek de aynı sonuç
        $this->pdo->exec('DELETE FROM satis_faturasi');
        $ka2 = $this->repo->karAnalizi('2026-07');
        $this->assertEqualsWithDelta($ka['toplam_net'], $ka2['toplam_net'], 0.001);
        $this->assertEqualsWithDelta($ka['uretim']['gelir'], $ka2['uretim']['gelir'], 0.001);
    }

    public function testFaturaModundaMtdKirpmasiYok(): void
    {
        $this->musteri(1, 'DEMİR ÇELİK AŞ', 'c-100');
        // Bugün 25 Tem; 28 Tem tarihli fatura (ileri tarihli) fatura modunda GELİRE GİRER
        $this->repo->satisFaturaIsle(Parasut::parseSalesInvoices($this->resp([
            $this->inv('9010', '2026-07-28', 90000.0, 9000.0, 'c-100'),
        ]), '2026-07')['invoices']);
        $ka = $this->repo->karAnaliziFatura('2026-07');
        $this->assertEqualsWithDelta(90000.0, $ka['uretim']['gelir'], 0.01, 'fatura tarihi gerçekleşmiş olay → MTD kırpılmaz');

        // fable-048f (Ömer): üretim modu da artık TÜM AYI kapsar → ileri tarihli üretim GELİRE girer
        $this->repo->upsertProduction(1, '2026-07-28', 100, 200.0, 'ogle');
        $this->assertEqualsWithDelta(20000.0, $this->repo->karAnalizi('2026-07')['uretim']['gelir'], 0.01);
    }

    /**
     * fable-048c (Fable denetimi — DAVRANIŞ DEĞİŞTİ): faturası HENÜZ kesilmemiş müşteri
     * kâr tablosuna hiç girmez (ne geliri ne maliyeti). Eski davranışta girip "gelir 0 /
     * maliyet var" diye SUNİ ZARAR üretiyordu: canlıda CANTAŞ+Marmara ay sonu faturalandığı
     * için Temmuz −%3,9 zarar görünüyordu. Artık ayrı 'faturasiz_musteri' listesinde bildirilir.
     */
    public function testFaturasiKesilmemisMusteriHesabaGirmez(): void
    {
        $this->sahne();
        $this->musteri(2, 'MARMARA LOJİSTİK', 'c-200');
        $this->repo->upsertProduction(2, '2026-07-02', 50, 200.0, 'ogle');

        $ka = $this->repo->karAnaliziFatura('2026-07');
        foreach ($ka['uretim']['rows'] as $r) {
            $this->assertNotSame('MARMARA LOJİSTİK', $r['name'], 'faturasız müşteri kâr satırı açmaz');
        }
        $adlar = array_column($ka['faturasiz_musteri'] ?? [], 'name');
        $this->assertContains('MARMARA LOJİSTİK', $adlar, 'faturasız müşteri AYRI listede bildirilir (gizlenmez)');
        $this->assertGreaterThan(0.0, $ka['toplam_net'], 'faturalanan işin kârı suni zarara dönüşmez');
    }

    public function testEslesmemisGelirToplamaAyriGirer(): void
    {
        $this->sahne();
        $this->repo->satisFaturaIsle(Parasut::parseSalesInvoices($this->resp([
            $this->inv('9099', '2026-07-19', 8000.0, 800.0, 'c-777'),
        ], ['c-777' => 'KANTİN']), '2026-07')['invoices']);

        $ka = $this->repo->karAnaliziFatura('2026-07');
        $this->assertEqualsWithDelta(8000.0, $ka['eslesmemis_gelir'], 0.01);
        $this->assertEqualsWithDelta(42000.0, $ka['uretim']['gelir'], 0.01, 'müşteri satırına KARIŞMAZ');
        $this->assertEqualsWithDelta(50000.0, $ka['toplam_gelir'], 0.01, '42000 + 8000');
        $this->assertEqualsWithDelta(25000.0, $ka['toplam_gelir'] - $ka['toplam_net'], 0.01, 'toplam gider tutarlı');
    }

    // ══ Kaynak seçici ════════════════════════════════════════════════
    public function testKaynakSecici(): void
    {
        $this->sahne();
        $this->assertSame('fatura', $this->repo->karAnaliziKaynak('2026-07')['kaynak'], 'varsayılan = fatura');
        $this->assertSame('fatura', $this->repo->karAnaliziKaynak('2026-07', 'fatura')['kaynak']);
        $this->assertSame('uretim', $this->repo->karAnaliziKaynak('2026-07', 'uretim')['kaynak']);
        $this->assertSame('fatura', $this->repo->karAnaliziKaynak('2026-07', 'saçmalık')['kaynak'], 'bilinmeyen → varsayılan');
    }

    public function testKaynakVarsayilaniAyardanDegisir(): void
    {
        $this->sahne();
        $this->pdo->prepare("UPDATE ayar SET deger = 'uretim' WHERE anahtar = 'kar_kaynak_varsayilan'")->execute();
        $this->assertSame('uretim', $this->repo->karAnaliziKaynak('2026-07')['kaynak'], 'koda gömülü değil');
    }

    // ══ Taşıma tarafı: satış = kesilen fatura, maliyet tahakkuk (değişmez) ══
    public function testTasimaFaturaModu(): void
    {
        $this->pdo->prepare('INSERT INTO customers (id, name, unit_price, category, maliyet_birim, parasut_id)
                             VALUES (9, ?, 150, ?, 100, ?)')->execute(['TAŞIMA MÜŞTERİ', 'tasima', 'c-900']);
        $this->repo->upsertProduction(9, '2026-07-03', 100, 150.0, 'ogle'); // tahakkuk satış 15.000 / alış 10.000
        $this->repo->satisFaturaIsle(Parasut::parseSalesInvoices($this->resp([
            $this->inv('9500', '2026-07-10', 12000.0, 1200.0, 'c-900'),
        ]), '2026-07')['invoices']);

        $fat = $this->repo->karAnaliziFatura('2026-07');
        $this->assertCount(1, $fat['tasima']['rows']);
        $this->assertEqualsWithDelta(12000.0, $fat['tasima']['satis'], 0.01, 'satış = kesilen fatura');
        $this->assertEqualsWithDelta(10000.0, $fat['tasima']['alis'], 0.01, 'alış maliyeti DEĞİŞMEZ');
        $this->assertEqualsWithDelta(2000.0, $fat['tasima']['net'], 0.01);

        $ure = $this->repo->karAnalizi('2026-07');
        $this->assertEqualsWithDelta(15000.0, $ure['tasima']['satis'], 0.01, 'üretim modu tahakkuk satışı korur');
    }

    // ══ Migration uygulanmadan ekran ÇÖKMEZ (tahakkuka düşer, gizlemez) ══
    public function testTabloYokTahakkukaDuser(): void
    {
        $this->sahne();
        $this->pdo->exec('DROP TABLE satis_faturasi');   // migrate_047 uygulanmamış canlı senaryosu
        $o = $this->repo->satisFaturaOzet('2026-07');
        $this->assertTrue($o['tablo_yok']);

        $ka = $this->repo->karAnaliziKaynak('2026-07', 'fatura');
        $this->assertSame('uretim', $ka['kaynak'], 'fatura istendi ama veri yok → tahakkuk');
        $this->assertTrue($ka['fatura_devre_disi'], 'ekran bunu AÇIKÇA yazar');
        $this->assertEqualsWithDelta(60000.0, $ka['uretim']['gelir'], 0.01);
    }

    // ══ Türkçe karakter + sınır girdileri ════════════════════════════
    public function testTurkceKarakterVeSifirTutar(): void
    {
        $this->musteri(1, 'ÇAĞRI GIDA İŞLETMELERİ', 'c-ç1');
        $r = $this->repo->satisFaturaIsle(Parasut::parseSalesInvoices($this->resp([
            $this->inv('9001', '2026-07-08', 0.0, 0.0, 'c-ç1', ['description' => 'İptal öncesi sıfır tutarlı']),
        ], ['c-ç1' => 'ÇAĞRI GIDA İŞLETMELERİ']), '2026-07')['invoices']);
        $this->assertSame(1, $r['yeni'], 'sıfır tutarlı fatura da kayda girer (iz kalır)');
        $o = $this->repo->satisFaturaOzet('2026-07');
        $this->assertEqualsWithDelta(0.0, $o['net'], 0.001);
        $this->assertSame(1, $o['adet']);
        $ad = (string) $this->pdo->query('SELECT contact_ad FROM satis_faturasi')->fetchColumn();
        $this->assertSame('ÇAĞRI GIDA İŞLETMELERİ', $ad, 'Türkçe karakter bozulmaz');
    }
}
