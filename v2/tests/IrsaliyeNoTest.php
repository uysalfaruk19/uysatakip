<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\ParasutYaz;
use Uysa\Repo;

/**
 * fable-072 — Gecikmeli e-İrsaliye numarası + numaraların faturaya yazılması.
 *
 * 🔒 GERÇEK Paraşüt çağrısı YOK: ağ katmanı enjekte edilir, hangi yola kaç kez gidildiği ÖLÇÜLÜR.
 *    Hiçbir test gerçek fatura/irsaliye kesmez (şalter testte açılır ama ağ sahtedir).
 */
final class IrsaliyeNoTest extends TestCase
{
    private const IBAN_NOT = 'Ödeme: HALKBANK — IBAN TR75 0001 2001 3200 0010 1011 01 (UYSA YEMEK HİZMETLERİ SAN. VE TİC. LTD. ŞTİ.)';

    private PDO $pdo;
    private Repo $repo;
    /** @var array<int,array{method:string,path:string,body:?array}> */
    private array $cagrilar = [];

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
        $this->cagrilar = [];
        $this->salter(true);
    }

    protected function tearDown(): void
    {
        putenv('PARASUT_FATURA_AKTIF');
        unset($_ENV['PARASUT_FATURA_AKTIF']);
    }

    private function salter(bool $acik): void
    {
        $v = $acik ? '1' : '0';
        putenv('PARASUT_FATURA_AKTIF=' . $v);
        $_ENV['PARASUT_FATURA_AKTIF'] = $v;
    }

    /**
     * Sahte ağ: yola göre cevap veren yönlendirici.
     * @param callable(string,string,?array):array $yonlendir
     */
    private function http(callable $yonlendir): callable
    {
        return function (string $method, string $path, ?array $body) use ($yonlendir): array {
            $this->cagrilar[] = ['method' => $method, 'path' => $path, 'body' => $body];
            return $yonlendir($method, $path, $body);
        };
    }

    /** GET /shipment_documents/{id} yanıtı — $nolar: docId => numara ('' = Paraşüt'te de boş). */
    private function belgeYonlendirici(array $nolar, array $ozel = []): callable
    {
        return function (string $method, string $path, ?array $body) use ($nolar, $ozel): array {
            if ($method === 'GET' && str_starts_with($path, '/shipment_documents/')) {
                $docId = substr($path, strlen('/shipment_documents/'));
                if (isset($ozel[$docId])) {
                    return $ozel[$docId];
                }
                $no = (string) ($nolar[$docId] ?? '');
                return ['net' => 'ok', 'status' => 200, 'data' => ['data' => [
                    'id' => $docId, 'attributes' => ['despatch_no' => $no],
                ]]];
            }
            if ($method === 'POST' && str_starts_with($path, '/sales_invoices')) {
                return ['net' => 'ok', 'status' => 201, 'data' => ['data' => [
                    'id' => '9001',
                    'attributes' => ['invoice_no' => 'UY02026000000135', 'item_type' => 'invoice'],
                    'relationships' => ['details' => ['data' => [['id' => '501', 'type' => 'sales_invoice_details']]]],
                ]]];
            }
            return ['net' => 'ok', 'status' => 200, 'data' => []];
        };
    }

    private function musteri(string $ad = 'CEOTHERM', array $opt = []): int
    {
        $cid = seed_customer($this->pdo, $ad, 200.0);
        $this->pdo->prepare(
            'UPDATE customers SET parasut_id = ?, irsaliye_aktif = ?, fatura_vade_gun = ? WHERE id = ?'
        )->execute([
            $opt['parasut_id'] ?? '1060083802',
            ($opt['irsaliye_aktif'] ?? true) ? 1 : 0,
            $opt['vade_gun'] ?? 7,
            $cid,
        ]);
        return $cid;
    }

    /** Kesilmiş irsaliye kaydı (numara boş bırakılabilir → gecikmeli numara senaryosu). */
    private function irsaliye(int $cid, string $gun, int $kisi, string $no, string $docId = ''): int
    {
        $this->repo->irsaliyeLogKaydet($cid, $gun, [
            'durum' => 'kesildi',
            'despatch_no' => $no !== '' ? $no : null,
            'parasut_doc_id' => $docId !== '' ? $docId : null,
            'kalemler' => [['ogun' => 'ogle', 'urun_id' => '1063984872', 'miktar' => $kisi]],
            'toplam_kisi' => $kisi, 'gonderim' => 'gonderildi',
        ]);
        return (int) $this->repo->irsaliyeLog($cid, $gun)['id'];
    }

    private function urunAyarlari(): void
    {
        $this->repo->ayarSet('irsaliye_urun_ogle', '1063984872');
    }

    private function ibanNotu(): void
    {
        $this->repo->ayarSet('fatura_notu', self::IBAN_NOT);
    }

    private function getSayisi(): int
    {
        return count(array_filter($this->cagrilar, static fn(array $c): bool => $c['method'] === 'GET'));
    }

    // ══ 1) TAZELEME: boş numaralı kayıtlar bulunur ve doldurulur ════════════════════
    public function testBosNumaraliKayitlarBulunurVeDoldurulur(): void
    {
        $cid = $this->musteri();
        $dolu = $this->irsaliye($cid, '2026-07-23', 100, 'UU02026000000597', 'DOC1');
        $bos1 = $this->irsaliye($cid, '2026-07-24', 110, '', 'DOC2');
        $bos2 = $this->irsaliye($cid, '2026-07-27', 120, '', 'DOC3');
        $belgesiz = $this->irsaliye($cid, '2026-07-28', 130, '', ''); // doc_id yok → sorulamaz

        $eksik = $this->repo->despatchNosuEksikIrsaliyeler(null);
        self::assertCount(2, $eksik, 'yalnız belge id\'si olan BOŞ kayıtlar taranmalı');
        self::assertSame([$bos2, $bos1], array_column($eksik, 'id'), 'yeni→eski sıralanmalı');

        $yaz = new ParasutYaz($this->repo, null, $this->http($this->belgeYonlendirici([
            'DOC2' => 'UU02026000000603', 'DOC3' => 'UU02026000000609',
        ])));
        $r = $yaz->despatchNolariTazele($eksik);

        self::assertSame(['tarandi' => 2, 'bulundu' => 2, 'bos' => 0, 'hata' => 0], $r);
        self::assertSame('UU02026000000603', $this->repo->irsaliyeLog($cid, '2026-07-24')['despatch_no']);
        self::assertSame('UU02026000000609', $this->repo->irsaliyeLog($cid, '2026-07-27')['despatch_no']);
        self::assertSame('UU02026000000597', $this->repo->irsaliyeLog($cid, '2026-07-23')['despatch_no'], 'dolu kayda dokunulmamalı');
        self::assertNull($this->repo->irsaliyeLog($cid, '2026-07-28')['despatch_no']);
        self::assertSame(2, $this->getSayisi(), 'kayıt başına tek GET');
        unset($dolu, $belgesiz);
    }

    public function testParasuttHalaBosDonerseHataSayilmaz(): void
    {
        $cid = $this->musteri();
        $this->irsaliye($cid, '2026-07-24', 110, '', 'DOC2');

        $yaz = new ParasutYaz($this->repo, null, $this->http($this->belgeYonlendirici(['DOC2' => ''])));
        $r = $yaz->despatchNolariTazele($this->repo->despatchNosuEksikIrsaliyeler(null));

        self::assertSame(['tarandi' => 1, 'bulundu' => 0, 'bos' => 1, 'hata' => 0], $r);
        self::assertNull($this->repo->irsaliyeLog($cid, '2026-07-24')['despatch_no']);
    }

    // ══ 2) 429: çökmez, o kaydı atlar, sıradakine devam eder ════════════════════════
    public function test429daCokmezKaydiAtlarDigerineDevamEder(): void
    {
        $cid = $this->musteri();
        $this->irsaliye($cid, '2026-07-24', 110, '', 'DOC429');
        $this->irsaliye($cid, '2026-07-27', 120, '', 'DOCOK');

        $yaz = new ParasutYaz($this->repo, null, $this->http($this->belgeYonlendirici(
            ['DOCOK' => 'UU02026000000609'],
            ['DOC429' => ['net' => 'ok', 'status' => 429, 'data' => [], 'error' => 'rate limit']]
        )));
        $r = $yaz->despatchNolariTazele($this->repo->despatchNosuEksikIrsaliyeler(null));

        self::assertSame(2, $r['tarandi']);
        self::assertSame(1, $r['bulundu'], '429 alan kayıt atlanır, diğeri yazılır');
        self::assertSame(1, $r['hata']);
        self::assertNull($this->repo->irsaliyeLog($cid, '2026-07-24')['despatch_no']);
        self::assertSame('UU02026000000609', $this->repo->irsaliyeLog($cid, '2026-07-27')['despatch_no']);
        self::assertSame(4, $this->getSayisi(), '429 kaydı 3 kez denendi + diğeri 1 kez = 4 GET');
    }

    public function testAgHatasiCokmez(): void
    {
        $cid = $this->musteri();
        $this->irsaliye($cid, '2026-07-24', 110, '', 'DOCX');

        $yaz = new ParasutYaz($this->repo, null, $this->http(static fn(): array =>
            ['net' => 'timeout', 'status' => 0, 'data' => [], 'error' => 'zaman aşımı']));
        $r = $yaz->despatchNolariTazele($this->repo->despatchNosuEksikIrsaliyeler(null));

        self::assertSame(['tarandi' => 1, 'bulundu' => 0, 'bos' => 0, 'hata' => 1], $r);
        self::assertNull($this->repo->irsaliyeLog($cid, '2026-07-24')['despatch_no']);
    }

    // ══ 3) Dolu numaranın ÜZERİNE YAZILMAZ (resmi belge izi) ════════════════════════
    public function testDoluNumaraninUzerineYazilmaz(): void
    {
        $cid = $this->musteri();
        $id = $this->irsaliye($cid, '2026-07-23', 100, 'UU02026000000597', 'DOC1');

        self::assertFalse($this->repo->despatchNoDoldur($id, 'YANLIS123'));
        self::assertSame('UU02026000000597', $this->repo->irsaliyeLog($cid, '2026-07-23')['despatch_no']);
        self::assertFalse($this->repo->despatchNoDoldur($id, '  '), 'boş numara yazılmaz');
    }

    public function testGunSiniriTaramayiDaraltir(): void
    {
        $cid = $this->musteri();
        $this->irsaliye($cid, '2026-07-01', 100, '', 'ESKI');
        $this->irsaliye($cid, '2026-07-28', 100, '', 'YENI');

        $ids = array_column($this->repo->despatchNosuEksikIrsaliyeler('2026-07-20'), 'parasut_doc_id');
        self::assertSame(['YENI'], $ids);
        self::assertCount(2, $this->repo->despatchNosuEksikIrsaliyeler(null), '--tum sınırsız tarar');
    }

    // ══ 4) FATURA: print_note'a irsaliye satırı ═════════════════════════════════════
    /** @return array{0:ParasutYaz,1:int} */
    private function faturaKurulumu(array $gunler): array
    {
        $this->urunAyarlari();
        $this->ibanNotu();
        $cid = $this->musteri();
        foreach ($gunler as $gun => $d) {
            $this->irsaliye($cid, $gun, (int) $d['kisi'], (string) $d['no'], (string) ($d['doc'] ?? ''));
        }
        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http($this->belgeYonlendirici([])));
        return [$yaz, $cid];
    }

    private function kesilenGovde(ParasutYaz $yaz, int $cid, string $bas, string $son): array
    {
        $r = $yaz->createSalesInvoice($cid, $bas, $son, ['onay' => 'TOKEN', 'actor' => 'uysal']);
        self::assertTrue($r['ok'], $r['mesaj']);
        foreach ($this->cagrilar as $c) {
            if ($c['method'] === 'POST' && str_starts_with($c['path'], '/sales_invoices')) {
                return $c['body']['data'];
            }
        }
        self::fail('POST /sales_invoices çağrısı yok');
    }

    public function testPrintNoteIbanKorunurIrsaliyeSatiriEklenir(): void
    {
        [$yaz, $cid] = $this->faturaKurulumu([
            '2026-07-27' => ['kisi' => 100, 'no' => 'UU02026000000618'],
            '2026-07-28' => ['kisi' => 110, 'no' => 'UU02026000000624'],
            '2026-07-29' => ['kisi' => 120, 'no' => 'UU02026000000631'],
        ]);
        $g = $this->kesilenGovde($yaz, $cid, '2026-07-27', '2026-07-31');
        $not = (string) $g['attributes']['print_note'];

        self::assertStringContainsString(self::IBAN_NOT, $not, 'fable-061 IBAN notu korunmalı');
        self::assertSame(self::IBAN_NOT . "\n"
            . 'İrsaliyeler: UU02026000000618, UU02026000000624, UU02026000000631', $not);
        self::assertStringStartsWith(self::IBAN_NOT, $not, 'irsaliye satırı İKİNCİ satır olmalı');
    }

    public function testNumarasizGunlerAtlanirSiraTarihe(): void
    {
        [$yaz, $cid] = $this->faturaKurulumu([
            '2026-07-29' => ['kisi' => 120, 'no' => 'UU02026000000631'],
            '2026-07-27' => ['kisi' => 100, 'no' => 'UU02026000000618'],
            '2026-07-28' => ['kisi' => 110, 'no' => ''], // numara doğmamış, belge id de yok
        ]);
        $g = $this->kesilenGovde($yaz, $cid, '2026-07-27', '2026-07-31');

        self::assertStringContainsString('İrsaliyeler: UU02026000000618, UU02026000000631',
            (string) $g['attributes']['print_note'], 'numarasız gün atlanır, kalanlar TARİH sırasında');
        self::assertSame(330, array_sum(array_map(
            static fn(array $d): int => (int) $d['attributes']['quantity'],
            $g['relationships']['details']['data']
        )), 'numarasız gün faturadan DÜŞMEZ — sadece nottan çıkar');
    }

    public function testHicNumaraYoksaIrsaliyeSatiriHicYok(): void
    {
        [$yaz, $cid] = $this->faturaKurulumu([
            '2026-07-27' => ['kisi' => 100, 'no' => ''],
            '2026-07-28' => ['kisi' => 110, 'no' => ''],
        ]);
        $g = $this->kesilenGovde($yaz, $cid, '2026-07-27', '2026-07-31');

        self::assertSame(self::IBAN_NOT, (string) $g['attributes']['print_note']);
        self::assertStringNotContainsString('İrsaliyeler', (string) $g['attributes']['print_note']);
    }

    public function testUzunListe500KarakterdeKisaltilir(): void
    {
        $gunler = [];
        for ($i = 1; $i <= 25; $i++) {
            $gunler[sprintf('2026-06-%02d', $i)] = ['kisi' => 100, 'no' => sprintf('UU020260000006%02d', $i)];
        }
        [$yaz, $cid] = $this->faturaKurulumu($gunler);
        $g = $this->kesilenGovde($yaz, $cid, '2026-06-01', '2026-06-30');
        $not = (string) $g['attributes']['print_note'];

        self::assertLessThanOrEqual(500, mb_strlen($not, 'UTF-8'), 'print_note 500 karakteri aşmamalı');
        self::assertStringContainsString(self::IBAN_NOT, $not, 'kısaltmada IBAN kaybolmamalı');
        self::assertMatchesRegularExpression('/… ve \d+ irsaliye daha$/u', $not);
        self::assertStringContainsString('UU02026000000601', $not, 'ilk numaralar yazılmalı');
    }

    // ══ 5) REGRESYON: aylık ve sabit faturada irsaliye satırı YOK ═══════════════════
    public function testAylikFaturadaIrsaliyeSatiriYok(): void
    {
        $this->urunAyarlari();
        $this->ibanNotu();
        $cid = $this->musteri('CANTAŞ', ['irsaliye_aktif' => false]);
        // Aylık müşteride bile irsaliye logu bulunsa dahi nota GİRMEMELİ.
        $this->irsaliye($cid, '2026-07-27', 100, 'UU02026000000618');

        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http($this->belgeYonlendirici([])));
        $r = $yaz->createMonthlyInvoice($cid, '2026-07-01', '2026-07-31',
            ['contact_id' => '1060083802', 'ad' => 'CANTAŞ', 'kisi' => 500], ['onay' => 'TOKEN', 'actor' => 'uysal']);
        self::assertTrue($r['ok'], $r['mesaj']);

        $g = $this->cagrilar[0]['body']['data'];
        self::assertSame(self::IBAN_NOT, (string) $g['attributes']['print_note']);
        self::assertStringNotContainsString('İrsaliyeler', (string) $g['attributes']['print_note']);
        self::assertSame(0, $this->getSayisi(), 'aylık akışta belge sorgusu yapılmamalı');
    }

    public function testAylikMusteriyeIrsaliyeliKesimDenenirseAgaCikilmaz(): void
    {
        $this->urunAyarlari();
        $cid = $this->musteri('CANTAŞ', ['irsaliye_aktif' => false]);
        $this->irsaliye($cid, '2026-07-27', 100, '', 'DOC27'); // eski dönemden kalmış numarasız kayıt

        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http($this->belgeYonlendirici(['DOC27' => 'UU1'])));
        $r = $yaz->createSalesInvoice($cid, '2026-07-27', '2026-07-31', ['onay' => 'TOKEN', 'actor' => 'uysal']);

        self::assertSame('kapsam_disi', $r['durum']);
        self::assertSame([], $this->cagrilar, 'kapsam dışı müşteride HİÇ ağ çağrısı olmamalı');
    }

    public function testSabitKalemFaturasindaIrsaliyeSatiriYok(): void
    {
        $this->ibanNotu();
        $cid = $this->musteri('BOMİ', ['irsaliye_aktif' => false]);
        $this->irsaliye($cid, '2026-07-27', 100, 'UU02026000000618');
        $kid = $this->repo->upsertSabitFaturaKalem($cid, 'Personel hizmeti', 48208.83, 20.0, '1066391424');

        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http($this->belgeYonlendirici([])));
        $r = $yaz->createFixedInvoice($kid, '2026-07', ['onay' => 'TOKEN', 'actor' => 'uysal']);
        self::assertTrue($r['ok'], $r['mesaj']);

        $g = $this->cagrilar[0]['body']['data'];
        self::assertSame(self::IBAN_NOT, (string) $g['attributes']['print_note']);
        self::assertStringNotContainsString('İrsaliyeler', (string) $g['attributes']['print_note']);
        self::assertSame(0, $this->getSayisi());
    }

    // ══ 6) KESİM ANI: eksik numaralar önce tazelenir, hata kesimi DÜŞÜRMEZ ══════════
    public function testKesimOncesiEksikNumaralarTazelenirVeFaturayaYazilir(): void
    {
        [$yaz, $cid] = $this->faturaKurulumu([
            '2026-07-27' => ['kisi' => 100, 'no' => 'UU02026000000618'],
            '2026-07-28' => ['kisi' => 110, 'no' => '', 'doc' => 'DOC28'],
        ]);
        // Yeni yönlendirici: DOC28 artık numarayı veriyor (GİB numarayı sonradan doğurdu).
        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http($this->belgeYonlendirici(['DOC28' => 'UU02026000000624'])));
        $g = $this->kesilenGovde($yaz, $cid, '2026-07-27', '2026-07-31');

        self::assertSame(1, $this->getSayisi(), 'yalnız numarası eksik olan gün sorulmalı');
        self::assertStringContainsString('/shipment_documents/DOC28', $this->cagrilar[0]['path']);
        self::assertStringContainsString('İrsaliyeler: UU02026000000618, UU02026000000624',
            (string) $g['attributes']['print_note']);
        self::assertSame('UU02026000000624', $this->repo->irsaliyeLog($cid, '2026-07-28')['despatch_no'],
            'bulunan numara loga da yazılmalı');
    }

    public function testTazelemeCokerseFaturaYINE_DE_Kesilir(): void
    {
        $this->urunAyarlari();
        $this->ibanNotu();
        $cid = $this->musteri();
        $this->irsaliye($cid, '2026-07-27', 100, 'UU02026000000618');
        $this->irsaliye($cid, '2026-07-28', 110, '', 'DOC429');

        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http($this->belgeYonlendirici(
            [],
            ['DOC429' => ['net' => 'ok', 'status' => 429, 'data' => [], 'error' => 'rate limit']]
        )));
        $g = $this->kesilenGovde($yaz, $cid, '2026-07-27', '2026-07-31');

        self::assertSame(3, $this->getSayisi(), '429 → 3 deneme, sonra pes edilir');
        self::assertSame(self::IBAN_NOT . "\nİrsaliyeler: UU02026000000618",
            (string) $g['attributes']['print_note'], 'numarası bulunamayan gün nota girmez, fatura kesilir');
        self::assertSame('kesildi', (string) $this->pdo->query(
            'SELECT durum FROM parasut_fatura_log ORDER BY id DESC LIMIT 1')->fetchColumn());
    }

    // ══ 7) GÖRÜNÜRLÜK: fatura listesi satırında numaralar ═══════════════════════════
    public function testFaturaGecmisiIrsaliyeNumaralariniGosterir(): void
    {
        [$yaz, $cid] = $this->faturaKurulumu([
            '2026-07-27' => ['kisi' => 100, 'no' => 'UU02026000000618'],
            '2026-07-28' => ['kisi' => 110, 'no' => 'UU02026000000624'],
        ]);
        $this->kesilenGovde($yaz, $cid, '2026-07-27', '2026-07-31');

        $liste = $this->repo->parasutFaturaGecmisi(10);
        self::assertCount(1, $liste);
        self::assertSame('UY02026000000135', $liste[0]['fatura_no']);
        self::assertSame('CEOTHERM', $liste[0]['customer_name']);
        self::assertSame(['UU02026000000618', 'UU02026000000624'], $liste[0]['irsaliyeler'],
            '"135 nolu fatura → irsaliyeleri şunlar" — bağlı irsaliyeler tarih sırasında');
        self::assertSame([], $this->repo->parasutFaturaGecmisi(10, '2026-06'), 'ay filtresi çalışmalı');
    }

    // ══ 8) KIRAN GİRDİLER ══════════════════════════════════════════════════════════
    public function testBozukGirdilerCokertmez(): void
    {
        $yaz = new ParasutYaz($this->repo, null, $this->http($this->belgeYonlendirici([])));

        self::assertSame(['tarandi' => 0, 'bulundu' => 0, 'bos' => 0, 'hata' => 0], $yaz->despatchNolariTazele([]));
        self::assertSame(['tarandi' => 0, 'bulundu' => 0, 'bos' => 0, 'hata' => 0], $yaz->despatchNolariTazele([
            ['id' => 0, 'parasut_doc_id' => 'DOC'], ['id' => 5, 'parasut_doc_id' => ''],
        ]));
        self::assertSame(0, $this->getSayisi(), 'geçersiz kayıt için ağa çıkılmaz');
        self::assertFalse($this->repo->despatchNoDoldur(0, 'X'));
        self::assertFalse($this->repo->despatchNoDoldur(999, 'X'), 'olmayan kayıt sessizce false');
        self::assertSame([], $this->repo->faturaIrsaliyeNolari(999));
    }

    public function testTurkceKarakterliNotVeLimit(): void
    {
        // IBAN notu tek başına 500'ü doldurursa irsaliye satırı EKLENMEZ (taşma yok).
        $uzun = str_repeat('ÇĞİÖŞÜçğıöşü ', 45); // ~540 karakter, Türkçe
        $this->repo->ayarSet('fatura_notu', trim($uzun));
        $this->urunAyarlari();
        $cid = $this->musteri();
        $this->irsaliye($cid, '2026-07-27', 100, 'UU02026000000618');

        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http($this->belgeYonlendirici([])));
        $g = $this->kesilenGovde($yaz, $cid, '2026-07-27', '2026-07-31');

        self::assertStringNotContainsString('İrsaliyeler', (string) $g['attributes']['print_note'],
            'not zaten sınırı doldurmuşsa irsaliye satırı eklenmez');
        self::assertSame(trim($uzun), (string) $g['attributes']['print_note'], 'mevcut not BOZULMAZ');
    }
}
