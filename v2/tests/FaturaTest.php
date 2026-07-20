<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\ParasutYaz;
use Uysa\Repo;

/**
 * fable-024 — Paraşüt satış faturası + e-Fatura (irsaliyeden haftalık / üretimden aylık).
 *
 * 🔒 GERÇEK Paraşüt çağrısı YOK: ağ katmanı enjekte edilir, "kaç kez neyle çağrıldı" ÖLÇÜLÜR.
 *    En kritik test: fatura şalteri kapalıyken HTTP katmanı HİÇ çağrılmaz.
 */
final class FaturaTest extends TestCase
{
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

    /** Sahte ağ katmanı: her çağrıyı kaydeder, sıradaki hazır yanıtı döndürür. */
    private function http(array $yanitlar): callable
    {
        $i = 0;
        return function (string $method, string $path, ?array $body) use (&$i, $yanitlar): array {
            $this->cagrilar[] = ['method' => $method, 'path' => $path, 'body' => $body];
            $y = $yanitlar[$i] ?? ['net' => 'ok', 'status' => 200, 'data' => []];
            $i++;
            return $y;
        };
    }

    /** parasut_id + tevkifat + vade + alias/mail ile müşteri kur. */
    private function musteri(string $ad, float $price, array $opt = []): int
    {
        $cid = seed_customer($this->pdo, $ad, $price);
        $this->pdo->prepare(
            'UPDATE customers SET parasut_id = ?, irsaliye_aktif = ?, tevkifat_kodu = ?, tevkifat_oran = ?,
                fatura_vade_gun = ?, edespatch_alias = ?, fatura_mail = ?, fatura_bolusum = ? WHERE id = ?'
        )->execute([
            $opt['parasut_id'] ?? '1060083802',
            ($opt['irsaliye_aktif'] ?? true) ? 1 : 0,
            $opt['tevkifat_kodu'] ?? null,
            $opt['tevkifat_oran'] ?? null,
            $opt['vade_gun'] ?? 1,
            $opt['alias'] ?? null,
            $opt['mail'] ?? null,
            $opt['bolusum'] ?? null,
            $cid,
        ]);
        return $cid;
    }

    /** Bir gün için kesilmiş irsaliye kaydı (faturalanmamış) ekle. */
    private function irsaliye(int $cid, string $gun, array $meals, string $no): void
    {
        $kalemler = [];
        $toplam = 0;
        $urun = ['ogle' => '1063984872', 'aksam' => '1063985050', 'kumanya' => '1063985150'];
        foreach ($meals as $og => $m) {
            if ($m > 0) {
                $kalemler[] = ['ogun' => $og, 'urun_id' => $urun[$og], 'miktar' => $m];
                $toplam += $m;
            }
        }
        $this->repo->irsaliyeLogKaydet($cid, $gun, [
            'durum' => 'kesildi', 'despatch_no' => $no, 'parasut_doc_id' => 'DOC' . $no,
            'kalemler' => $kalemler, 'toplam_kisi' => $toplam, 'gonderim' => 'gonderildi',
        ]);
    }

    private function createYanit(string $id = '9001', string $no = 'UY02026000001234', array $detailIds = ['501', '502', '503']): array
    {
        return ['net' => 'ok', 'status' => 201, 'data' => ['data' => [
            'id' => $id,
            'attributes' => ['invoice_no' => $no, 'item_type' => 'invoice'],
            'relationships' => ['details' => ['data' => array_map(
                static fn($d) => ['id' => (string) $d, 'type' => 'sales_invoice_details'], $detailIds
            )]],
        ]]];
    }

    private function jobYanit(string $status = 'done'): array
    {
        return ['net' => 'ok', 'status' => 200, 'data' => ['data' => ['id' => 'JOB1', 'attributes' => ['status' => $status]]]];
    }

    private function eDocYanit(bool $var = true): array
    {
        $rel = $var ? ['active_e_document' => ['data' => ['id' => 'EDOC1', 'type' => 'e_invoices']]] : [];
        return ['net' => 'ok', 'status' => 200, 'data' => ['data' => ['id' => '9001', 'relationships' => $rel]]];
    }

    // ══ EN KRİTİK: şalter kapalı → HİÇ HTTP çağrısı + hiç fatura_log ═══════════════
    public function testSalterKapaliykenHicHttpCagrisiVeLogYok(): void
    {
        $this->salter(false);
        $cid = $this->musteri('PENDORYA', 205.0, ['tevkifat_kodu' => '604', 'tevkifat_oran' => 50.0, 'vade_gun' => 7]);
        $this->irsaliye($cid, '2026-07-14', ['ogle' => 175, 'aksam' => 175, 'kumanya' => 56], 'UU1');

        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([$this->createYanit()]));
        $r = $yaz->createSalesInvoice($cid, '2026-07-08', '2026-07-14', ['onay' => 'imza', 'actor' => 'uysal']);

        $this->assertFalse($r['ok']);
        $this->assertSame('kapali', $r['durum']);
        $this->assertSame([], $this->cagrilar, 'ŞALTER KAPALI: ağ katmanı HİÇ çağrılmamalı');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM parasut_fatura_log')->fetchColumn());
    }

    public function testFaturaSalteriVarsayilanKapali(): void
    {
        putenv('PARASUT_FATURA_AKTIF');
        unset($_ENV['PARASUT_FATURA_AKTIF']);
        $this->assertFalse(ParasutYaz::faturaAktif(), 'env tanımsızsa fatura KAPALI');
        foreach (['0', 'false', '', 'evet'] as $v) {
            putenv('PARASUT_FATURA_AKTIF=' . $v);
            $_ENV['PARASUT_FATURA_AKTIF'] = $v;
            $this->assertFalse(ParasutYaz::faturaAktif(), "'$v' kapalı sayılmalı");
        }
    }

    public function testOnayImzasiYoksaCagriYok(): void
    {
        $cid = $this->musteri('PENDORYA', 205.0);
        $this->irsaliye($cid, '2026-07-14', ['ogle' => 25], 'UU1');
        $r = (new ParasutYaz($this->repo, null, $this->http([$this->createYanit()])))
            ->createSalesInvoice($cid, '2026-07-08', '2026-07-14', ['onay' => 'x']);
        $this->assertSame('onaysiz', $r['durum']);
        $this->assertSame([], $this->cagrilar);
    }

    // ══ TUTAR MATEMATİĞİ — TSKB 14 Tem GERÇEK RAKAMLARI ════════════════════════════
    public function testTevkifatTutarMatematigiTskbGercek(): void
    {
        // 175 öğlen + 175 akşam + 56 kumanya, hepsi 205; KDV %10, tevkifat %50 (604)
        $h = ParasutYaz::faturaHesap(
            [['miktar' => 175, 'birim' => 205.0], ['miktar' => 175, 'birim' => 205.0], ['miktar' => 56, 'birim' => 205.0]],
            10.0,
            50.0
        );
        $this->assertSame(83230.0, $h['brut'], 'brüt = 406 × 205');
        $this->assertSame(8323.0, $h['kdv'], 'KDV %10');
        $this->assertSame(4161.5, $h['tevkifat'], 'tevkifat = KDV × %50');
        $this->assertSame(87391.5, $h['net'], 'net = brüt + KDV − tevkifat');
    }

    public function testTevkifatsizTutar(): void
    {
        // LODİ 375 öğlen × 245, tevkifat YOK
        $h = ParasutYaz::faturaHesap([['miktar' => 375, 'birim' => 245.0]], 10.0, null);
        $this->assertSame(91875.0, $h['brut']);
        $this->assertSame(9187.5, $h['kdv']);
        $this->assertSame(0.0, $h['tevkifat']);
        $this->assertSame(101062.5, $h['net']);
    }

    // ══ KALEM KURULUMU: dönem irsaliye toplamı → öğün kalemleri ════════════════════
    public function testPeriyodKalemleriUcOgunVeVatWithholding(): void
    {
        // 7 gün, her gün 25/25/8 → dönem 175/175/56
        $cid = $this->musteri('PENDORYA', 205.0, ['tevkifat_kodu' => '604', 'tevkifat_oran' => 50.0, 'vade_gun' => 7, 'alias' => 'urn:mail:defaultpk@tskb.com.tr']);
        for ($d = 8; $d <= 14; $d++) {
            $this->irsaliye($cid, sprintf('2026-07-%02d', $d), ['ogle' => 25, 'aksam' => 25, 'kumanya' => 8], 'UU' . $d);
        }
        $p = $this->repo->faturaAdaylari('2026-07-08', '2026-07-14');
        $this->assertCount(1, $p);
        $this->assertSame('irsaliye', $p[0]['tip']);
        $this->assertSame(175, $p[0]['ogle']);
        $this->assertSame(175, $p[0]['aksam']);
        $this->assertSame(56, $p[0]['kumanya']);
        $this->assertSame(406, $p[0]['toplam']);
        $this->assertSame(7, $p[0]['irsaliye_sayisi']);
        $this->assertSame('604', $p[0]['tevkifat_kodu']);

        // Kesim gövdesi: 3 kalem, her birinde vat_withholding_rate 50, tevkifat kalem
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            $this->createYanit(), $this->jobYanit('done'), $this->eDocYanit(true),
        ]));
        $r = $yaz->createSalesInvoice($cid, '2026-07-08', '2026-07-14', ['onay' => 'imza', 'actor' => 'uysal']);
        $this->assertTrue($r['ok'], $r['mesaj']);
        $this->assertSame('kesildi', $r['durum']);
        $this->assertSame(87391.5, $r['net']);

        $post = $this->postBody('/sales_invoices?include=details');
        $d = $post['data'];
        $this->assertSame('sales_invoices', $d['type']);
        $this->assertSame('invoice', $d['attributes']['item_type']);
        $this->assertSame('2026-07-14', $d['attributes']['issue_date']);
        $this->assertSame('2026-07-21', $d['attributes']['due_date'], 'vade = issue + 7 (PENDORYA)');
        $this->assertSame('1060083802', $d['relationships']['contact']['data']['id']);
        $details = $d['relationships']['details']['data'];
        $this->assertCount(3, $details);
        $map = [];
        foreach ($details as $x) {
            $map[$x['relationships']['product']['data']['id']] = $x['attributes'];
            $this->assertSame(50.0, $x['attributes']['vat_withholding_rate'], 'tevkifatlı kalem oranı');
            $this->assertSame(10.0, $x['attributes']['vat_rate']);
            $this->assertSame(205.0, $x['attributes']['unit_price']);
        }
        $this->assertSame(175, $map['1063984872']['quantity']);
        $this->assertSame(175, $map['1063985050']['quantity']);
        $this->assertSame(56, $map['1063985150']['quantity']);
        // shipment_documents bağı gönderildi (7 belge)
        $this->assertArrayHasKey('shipment_documents', $d['relationships']);
        $this->assertCount(7, $d['relationships']['shipment_documents']['data']);
    }

    public function testTevkifatsizMusteriTekKalemVeParamsizEfatura(): void
    {
        // LODİ 375 öğlen × 245, tevkifat yok
        $cid = $this->musteri('BOMİ', 245.0, ['parasut_id' => '1060083895', 'alias' => 'urn:mail:finance.trpk@bomigroup.com']);
        for ($d = 8; $d <= 14; $d++) {
            $this->irsaliye($cid, sprintf('2026-07-%02d', $d), ['ogle' => 75], 'B' . $d); // 75×5? seed 7 gün → 525; test sadece tek kalem
        }
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            $this->createYanit('7001', 'UY7', ['777']), $this->jobYanit('done'), $this->eDocYanit(true),
        ]));
        $r = $yaz->createSalesInvoice($cid, '2026-07-08', '2026-07-14', ['onay' => 'imza', 'actor' => 'uysal']);
        $this->assertTrue($r['ok'], $r['mesaj']);

        $post = $this->postBody('/sales_invoices?include=details');
        $details = $post['data']['relationships']['details']['data'];
        $this->assertCount(1, $details, 'tek öğün → tek kalem');
        $this->assertArrayNotHasKey('vat_withholding_rate', $details[0]['attributes'], 'tevkifatsız kalemde oran yok');

        // e-Fatura gövdesi: scenario commercial + to + PARAMSIZ (tevkifat yok)
        $ef = $this->postBody('/e_invoices');
        $this->assertSame('commercial', $ef['data']['attributes']['scenario']);
        $this->assertSame('urn:mail:finance.trpk@bomigroup.com', $ef['data']['attributes']['to']);
        $this->assertArrayNotHasKey('vat_withholding_params', $ef['data']['attributes'], 'tevkifatsız → params yok');
        $this->assertSame('7001', $ef['data']['relationships']['invoice']['data']['id']);
    }

    public function testTevkifatliEfaturaVatWithholdingParams(): void
    {
        $cid = $this->musteri('PENDORYA', 205.0, ['tevkifat_kodu' => '604', 'tevkifat_oran' => 50.0, 'vade_gun' => 7, 'alias' => 'urn:mail:defaultpk@tskb.com.tr']);
        $this->irsaliye($cid, '2026-07-14', ['ogle' => 25, 'aksam' => 25, 'kumanya' => 8], 'UU1');
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            $this->createYanit('9001', 'UY9', ['501', '502', '503']), $this->jobYanit('done'), $this->eDocYanit(true),
        ]));
        $yaz->createSalesInvoice($cid, '2026-07-08', '2026-07-14', ['onay' => 'imza', 'actor' => 'uysal']);

        $ef = $this->postBody('/e_invoices');
        $params = $ef['data']['attributes']['vat_withholding_params'];
        $this->assertCount(3, $params, 'her kalem için tevkifat kodu');
        foreach ($params as $p) {
            $this->assertSame('604', $p['vat_withholding_code']);
            $this->assertIsInt($p['detail_id'], 'detail_id integer (spec)');
        }
        $this->assertSame([501, 502, 503], array_column($params, 'detail_id'));
    }

    // ══ FATURALANAN İRSALİYE İKİNCİ DÖNEMDE LİSTEYE GELMEZ ══════════════════════════
    public function testFaturalananIrsaliyelerListedenDuser(): void
    {
        $cid = $this->musteri('PENDORYA', 205.0, ['alias' => 'urn:mail:defaultpk@tskb.com.tr']);
        $this->irsaliye($cid, '2026-07-14', ['ogle' => 25, 'aksam' => 25, 'kumanya' => 8], 'UU1');
        $this->assertCount(1, $this->repo->faturaAdaylari('2026-07-08', '2026-07-14'));

        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            $this->createYanit(), $this->jobYanit('done'), $this->eDocYanit(true),
        ]));
        $r = $yaz->createSalesInvoice($cid, '2026-07-08', '2026-07-14', ['onay' => 'imza', 'actor' => 'uysal']);
        $this->assertTrue($r['ok'], $r['mesaj']);

        // İrsaliye satırı fatura_log_id ile işaretlendi → aday havuzundan düştü
        $this->assertCount(0, $this->repo->faturaAdaylari('2026-07-08', '2026-07-14'),
            'faturalanan irsaliye ikinci kez listelenmez');
        $log = $this->repo->irsaliyeLog($cid, '2026-07-14');
        $this->assertNotNull($log['fatura_log_id']);
    }

    // ══ TIMEOUT → RETRY YOK, durum bilinmiyor, irsaliye KİLİTLİ kalır ═══════════════
    public function testTimeoutSonrasiRetryYokVeKilitlenir(): void
    {
        $cid = $this->musteri('PENDORYA', 205.0, ['alias' => 'urn:mail:defaultpk@tskb.com.tr']);
        $this->irsaliye($cid, '2026-07-14', ['ogle' => 25], 'UU1');
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            ['net' => 'timeout', 'status' => 0, 'data' => [], 'error' => 'timeout'],
            $this->createYanit(), // ASLA tüketilmemeli
        ]));
        $r = $yaz->createSalesInvoice($cid, '2026-07-08', '2026-07-14', ['onay' => 'imza', 'actor' => 'uysal']);
        $this->assertFalse($r['ok']);
        $this->assertSame('bilinmiyor', $r['durum']);
        $this->assertStringContainsString('TEKRAR DENEMEYİN', $r['mesaj']);
        $postSayisi = count(array_filter($this->cagrilar, static fn($c) => $c['method'] === 'POST' && $c['path'] === '/sales_invoices?include=details'));
        $this->assertSame(1, $postSayisi, 'timeout sonrası POST tekrarlanmaz');

        // İrsaliye kilitli kaldı (belge kesilmiş olabilir) → ikinci denemede aday çıkmaz
        $adaylar = $this->repo->faturaAdaylari('2026-07-08', '2026-07-14');
        $this->assertCount(1, $adaylar);
        $this->assertFalse($adaylar[0]['secilebilir'], 'bilinmiyor kilidi');
        $this->assertStringContainsString('belirsiz', $adaylar[0]['sebep']);
    }

    public function testFaturaKesildiAmaResmilesmeHatasiDogruLoglanir(): void
    {
        $cid = $this->musteri('PENDORYA', 205.0, ['alias' => 'urn:mail:defaultpk@tskb.com.tr']);
        $this->irsaliye($cid, '2026-07-14', ['ogle' => 25], 'UU1');
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            $this->createYanit(),                                  // fatura KESİLDİ
            ['net' => 'ok', 'status' => 422, 'data' => ['errors' => [['detail' => 'GİB reddetti']]], 'error' => ''], // e-Fatura POST hata
        ]));
        $r = $yaz->createSalesInvoice($cid, '2026-07-08', '2026-07-14', ['onay' => 'imza', 'actor' => 'uysal']);
        $this->assertTrue($r['ok'], 'fatura kesildi (resmileşme ayrı adım)');
        $this->assertSame('kesildi', $r['durum']);
        $this->assertSame('hata', $r['resmilestirme']);
        $this->assertStringContainsString('resmileş', $r['mesaj']);

        $log = $this->pdo->query('SELECT * FROM parasut_fatura_log ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame('kesildi', $log['durum']);
        $this->assertSame('hata', $log['resmilestirme']);
        $this->assertNotEmpty($log['hata_mesaj'], 'resmileşme hatasının sebebi loglanır');
        // Fatura VAR → irsaliye listeden düşer (kilit değil, gerçekten faturalandı)
        $this->assertCount(0, $this->repo->faturaAdaylari('2026-07-08', '2026-07-14'));
    }

    public function testParasut4xxHataClaimGeriAlinir(): void
    {
        $cid = $this->musteri('PENDORYA', 205.0);
        $this->irsaliye($cid, '2026-07-14', ['ogle' => 25], 'UU1');
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            ['net' => 'ok', 'status' => 422, 'data' => ['errors' => [['detail' => 'Cari bulunamadı']]], 'error' => ''],
        ]));
        $r = $yaz->createSalesInvoice($cid, '2026-07-08', '2026-07-14', ['onay' => 'imza', 'actor' => 'uysal']);
        $this->assertFalse($r['ok']);
        $this->assertSame('hata', $r['durum']);
        $this->assertStringContainsString('Cari bulunamadı', $r['mesaj']);
        // Claim geri alındı → irsaliye tekrar aday
        $log = $this->repo->irsaliyeLog($cid, '2026-07-14');
        $this->assertNull($log['fatura_log_id'], 'hata → irsaliye faturaya bağlı kalmaz');
        $adaylar = $this->repo->faturaAdaylari('2026-07-08', '2026-07-14');
        $this->assertTrue($adaylar[0]['secilebilir'], 'hata alan dönem tekrar denenebilir');
    }

    public function testKapsamDisiMusteriIrsaliyeliFaturaKesmez(): void
    {
        // irsaliye_aktif=0 müşteri createSalesInvoice ile kesilmez (yerel red, ağa çıkmaz)
        $cid = $this->musteri('CANTAŞ', 328.0, ['irsaliye_aktif' => false, 'parasut_id' => '1062205016']);
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([$this->createYanit()]));
        $r = $yaz->createSalesInvoice($cid, '2026-06-01', '2026-06-30', ['onay' => 'imza', 'actor' => 'uysal']);
        $this->assertSame('kapsam_disi', $r['durum']);
        $this->assertSame([], $this->cagrilar);
    }

    // ══ AYLIK AKIŞ — CANTAŞ 3'lü bölüşüm + Marmara eşleşme yok ══════════════════════
    public function testAylikCantasAdaylariBolusumlu(): void
    {
        $bolusum = '[{"key":"fatura_cantas_icdis","ad":"CANTAŞ İç-Dış"},{"key":"fatura_cantas_hc","ad":"CANTAŞ HC Isıtma"},{"key":"fatura_cantas_bakir","ad":"CANTAŞ Bakır"}]';
        $cid = $this->musteri('CANTAŞ', 328.0, ['irsaliye_aktif' => false, 'parasut_id' => '', 'bolusum' => $bolusum, 'vade_gun' => 7]);
        // ayar contact id'leri (schema seed'inde var; testte tekrar garanti)
        $this->repo->ayarSet('fatura_cantas_icdis', '1062205016');
        $this->repo->ayarSet('fatura_cantas_hc', '1062205054');
        $this->repo->ayarSet('fatura_cantas_bakir', '1062204894');
        // Haziran üretimi (23 iş günü × 70 = 1610 civarı; basit: 3 gün × 70)
        for ($d = 1; $d <= 3; $d++) {
            $this->repo->saveDayMeals($cid, sprintf('2026-06-%02d', $d), ['ogle' => 70, 'aksam' => 0, 'kumanya' => 0], 328.0);
        }
        $adaylar = $this->repo->faturaAdaylari('2026-06-01', '2026-06-30');
        $this->assertCount(1, $adaylar);
        $a = $adaylar[0];
        $this->assertSame('aylik', $a['tip']);
        $this->assertSame(210, $a['adet'], '3 × 70');
        $this->assertTrue($a['secilebilir']);
        $this->assertCount(3, $a['bolusum']);
        $this->assertSame('1062205016', $a['bolusum'][0]['contact_id']);
    }

    public function testAylikFaturaTekKalemShipmentBagiYok(): void
    {
        $cid = $this->musteri('CANTAŞ', 328.0, ['irsaliye_aktif' => false, 'parasut_id' => '']);
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            ['net' => 'ok', 'status' => 201, 'data' => ['data' => ['id' => '8001', 'attributes' => ['invoice_no' => 'UY8']]]],
        ]));
        $r = $yaz->createMonthlyInvoice($cid, '2026-06-01', '2026-06-30',
            ['contact_id' => '1062205016', 'ad' => 'CANTAŞ İç-Dış', 'kisi' => 720], ['onay' => 'imza', 'actor' => 'uysal']);
        $this->assertTrue($r['ok'], $r['mesaj']);
        $this->assertSame('kesildi', $r['durum']);
        // net = 720 × 328 × 1.1
        $this->assertSame(round(720 * 328 * 1.1, 2), $r['net']);

        $post = $this->postBody('/sales_invoices');
        $d = $post['data'];
        $this->assertSame('1062205016', $d['relationships']['contact']['data']['id'], 'doğru alt-firma contact');
        $this->assertCount(1, $d['relationships']['details']['data'], 'aylık tek kalem');
        $this->assertSame(720, $d['relationships']['details']['data'][0]['attributes']['quantity']);
        $this->assertArrayNotHasKey('shipment_documents', $d['relationships'], 'aylıkta irsaliye bağı GÖNDERİLMEZ');
        $this->assertArrayNotHasKey('vat_withholding_rate', $d['relationships']['details']['data'][0]['attributes'], 'aylıkta tevkifat yok');
        // e-Fatura resmileştirme denenmez (alias yok) → tek HTTP çağrısı
        $this->assertCount(1, $this->cagrilar);
        $this->assertSame('yok', $r['resmilestirme']);
    }

    public function testAylikUcFaturaDogruContactlaraVeDonemKilidi(): void
    {
        $bolusum = '[{"key":"fatura_cantas_icdis","ad":"İç-Dış"},{"key":"fatura_cantas_hc","ad":"HC"},{"key":"fatura_cantas_bakir","ad":"Bakır"}]';
        $cid = $this->musteri('CANTAŞ', 328.0, ['irsaliye_aktif' => false, 'parasut_id' => '', 'bolusum' => $bolusum]);
        $this->repo->ayarSet('fatura_cantas_icdis', '1062205016');
        $this->repo->ayarSet('fatura_cantas_hc', '1062205054');
        $this->repo->ayarSet('fatura_cantas_bakir', '1062204894');
        $parts = [
            ['contact_id' => '1062205016', 'ad' => 'İç-Dış', 'kisi' => 720],
            ['contact_id' => '1062205054', 'ad' => 'HC', 'kisi' => 669],
            ['contact_id' => '1062204894', 'ad' => 'Bakır', 'kisi' => 220],
        ];
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            ['net' => 'ok', 'status' => 201, 'data' => ['data' => ['id' => 'A1', 'attributes' => ['invoice_no' => 'UY-A1']]]],
            ['net' => 'ok', 'status' => 201, 'data' => ['data' => ['id' => 'A2', 'attributes' => ['invoice_no' => 'UY-A2']]]],
            ['net' => 'ok', 'status' => 201, 'data' => ['data' => ['id' => 'A3', 'attributes' => ['invoice_no' => 'UY-A3']]]],
        ]));
        foreach ($parts as $p) {
            $r = $yaz->createMonthlyInvoice($cid, '2026-06-01', '2026-06-30', $p, ['onay' => 'imza', 'actor' => 'uysal']);
            $this->assertTrue($r['ok'], $r['mesaj']);
        }
        $contacts = [];
        foreach ($this->cagrilar as $c) {
            $contacts[] = $c['body']['data']['relationships']['contact']['data']['id'];
        }
        $this->assertSame(['1062205016', '1062205054', '1062204894'], $contacts, '3 fatura 3 ayrı contact');
        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM parasut_fatura_log WHERE durum=\'kesildi\'')->fetchColumn());

        // Aynı müşteri + dönem ikinci kez seçilemez (aylık kesildi kilidi)
        $this->repo->saveDayMeals($cid, '2026-06-15', ['ogle' => 70, 'aksam' => 0, 'kumanya' => 0], 328.0);
        $adaylar = $this->repo->faturaAdaylari('2026-06-01', '2026-06-30');
        $this->assertFalse($adaylar[0]['secilebilir']);
        $this->assertStringContainsString('faturaland', $adaylar[0]['sebep']);
    }

    public function testMarmaraTeknikEslesmeYokKilidi(): void
    {
        $cid = $this->musteri('Marmara Teknik', 235.0, ['irsaliye_aktif' => false, 'parasut_id' => '']);
        $this->repo->saveDayMeals($cid, '2026-06-01', ['ogle' => 19, 'aksam' => 0, 'kumanya' => 0], 235.0);
        $adaylar = $this->repo->faturaAdaylari('2026-06-01', '2026-06-30');
        $this->assertCount(1, $adaylar);
        $this->assertSame('aylik', $adaylar[0]['tip']);
        $this->assertFalse($adaylar[0]['secilebilir']);
        $this->assertStringContainsString('eşleşmesi yok', $adaylar[0]['sebep']);

        // Kesmeye çalışırsa da yerel red (contact yok)
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([$this->createYanit()]));
        $r = $yaz->createMonthlyInvoice($cid, '2026-06-01', '2026-06-30', ['contact_id' => '', 'kisi' => 19], ['onay' => 'imza']);
        $this->assertSame('eslesme_yok', $r['durum']);
        $this->assertSame([], $this->cagrilar);
    }

    // ══ Kıran girdiler ═════════════════════════════════════════════════════════════
    public function testTurkceKarakterVeGecersizDonem(): void
    {
        $cid = $this->musteri('ŞIĞIRCI GIDA — ÖĞÜN A.Ş.', 205.0, ['alias' => 'urn:mail:x@y.com']);
        $this->irsaliye($cid, '2026-07-14', ['ogle' => 3], 'UU1');
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            $this->createYanit(), $this->jobYanit('done'), $this->eDocYanit(true),
        ]));
        $r = $yaz->createSalesInvoice($cid, '2026-07-08', '2026-07-14', ['onay' => 'imza', 'actor' => 'uysal']);
        $this->assertTrue($r['ok'], $r['mesaj']);

        $bozuk = $yaz->createSalesInvoice($cid, '14.07.2026', '2026-07-14', ['onay' => 'imza']);
        $this->assertSame('hata', $bozuk['durum']);
        $tersDonem = (new ParasutYaz($this->repo, 'imza', $this->http([])))
            ->createSalesInvoice($cid, '2026-07-20', '2026-07-14', ['onay' => 'imza']);
        $this->assertSame('hata', $tersDonem['durum'], 'bas > son reddedilir');
    }

    public function testFaturalanacakIrsaliyeYoksaAgaCikmaz(): void
    {
        $cid = $this->musteri('PENDORYA', 205.0);
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([$this->createYanit()]));
        $r = $yaz->createSalesInvoice($cid, '2026-07-08', '2026-07-14', ['onay' => 'imza', 'actor' => 'uysal']);
        $this->assertSame('faturalanacak_yok', $r['durum']);
        $this->assertSame([], $this->cagrilar);
    }

    // ── yardımcı: belirli path'e giden POST gövdesi ──
    private function postBody(string $path): array
    {
        foreach ($this->cagrilar as $c) {
            if ($c['method'] === 'POST' && $c['path'] === $path) {
                return $c['body'];
            }
        }
        $this->fail("POST $path çağrısı bulunamadı");
    }
}
