<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\ParasutYaz;
use Uysa\Repo;

/**
 * fable-023b — Paraşüt e-İrsaliye kesimi.
 *
 * 🔒 GERÇEK Paraşüt çağrısı YOK: ağ katmanı enjekte edilir ve her testte "kaç kez, neyle
 *    çağrıldı" ÖLÇÜLÜR. En kritik test: ana şalter kapalıyken HTTP katmanı HİÇ çağrılmaz.
 */
final class IrsaliyeTest extends TestCase
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
        $this->salter(true); // testlerin çoğu "şalter AÇIK" varsayar; kapalı testi ayrıca var
    }

    protected function tearDown(): void
    {
        putenv('PARASUT_IRSALIYE_AKTIF');
        unset($_ENV['PARASUT_IRSALIYE_AKTIF']);
    }

    private function salter(bool $acik): void
    {
        $v = $acik ? '1' : '0';
        putenv('PARASUT_IRSALIYE_AKTIF=' . $v);
        $_ENV['PARASUT_IRSALIYE_AKTIF'] = $v;
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

    private function contactYanit(string $ad = 'LODİ SAĞLIK LOJİSTİK A.Ş.'): array
    {
        return ['net' => 'ok', 'status' => 200, 'data' => ['data' => ['id' => '1060083895',
            'attributes' => ['name' => $ad, 'address' => 'Şerifali Mah. 1. Sk. No:5', 'city' => 'İstanbul', 'district' => 'Ümraniye']]]];
    }

    private function bosListe(): array
    {
        return ['net' => 'ok', 'status' => 200, 'data' => ['data' => []]];
    }

    private function createYanit(bool $tasiyiciIsledi = true, string $no = 'UU02026000000584'): array
    {
        $attr = ['issue_date' => '2026-07-20', 'despatch_no' => $no];
        if ($tasiyiciIsledi) {
            $attr['plate_number'] = '41BEM936';
            $attr['drivers_info'] = [['tckn' => '23354463864', 'full_name' => 'UFUK BALTACI']];
        }
        return ['net' => 'ok', 'status' => 201, 'data' => ['data' => ['id' => '9911', 'attributes' => $attr]]];
    }

    private function musteri(string $ad, ?string $parasutId = '1060083895', bool $irsaliyeAktif = true): int
    {
        $cid = seed_customer($this->pdo, $ad, 245.0);
        $this->pdo->prepare('UPDATE customers SET parasut_id = ?, irsaliye_aktif = ? WHERE id = ?')
            ->execute([$parasutId, $irsaliyeAktif ? 1 : 0, $cid]);
        return $cid;
    }

    // ══ 1) EN KRİTİK: şalter kapalı → HİÇ HTTP çağrısı yok ════════
    public function testSalterKapaliykenHicHttpCagrisiYapilmaz(): void
    {
        $this->salter(false);
        $cid = $this->musteri('BOMİ');
        $this->repo->saveDayMeals($cid, '2026-07-20', ['ogle' => 75, 'aksam' => 0, 'kumanya' => 0], 245.0);

        $yaz = new ParasutYaz($this->repo, 'gecerli-imza', $this->http([$this->contactYanit()]));
        $r = $yaz->createShipmentDocument($cid, '2026-07-20', ['ogle' => 75], ['onay' => 'gecerli-imza']);

        $this->assertFalse($r['ok']);
        $this->assertSame('kapali', $r['durum']);
        $this->assertSame([], $this->cagrilar, 'ŞALTER KAPALI: ağ katmanı HİÇ çağrılmamalı');
        $this->assertNull($this->repo->irsaliyeLog($cid, '2026-07-20'), 'kapalıyken log da yazılmaz');
    }

    public function testSalterVarsayilanKapali(): void
    {
        putenv('PARASUT_IRSALIYE_AKTIF');
        unset($_ENV['PARASUT_IRSALIYE_AKTIF']);
        $this->assertFalse(ParasutYaz::aktif(), 'env tanımsızsa kesim KAPALI sayılır');
        foreach (['0', 'false', '', 'evet', '2'] as $v) {
            putenv('PARASUT_IRSALIYE_AKTIF=' . $v);
            $_ENV['PARASUT_IRSALIYE_AKTIF'] = $v;
            $this->assertFalse(ParasutYaz::aktif(), "'$v' kapalı sayılmalı");
        }
        $this->salter(true);
        $this->assertTrue(ParasutYaz::aktif());
    }

    // ══ 2) Onay imzası yok/yanlış → çağrı yok ═════════════════════
    public function testOnayImzasiYoksaVeyaYanlissaCagriYapilmaz(): void
    {
        $cid = $this->musteri('BOMİ');
        $this->repo->saveDayMeals($cid, '2026-07-20', ['ogle' => 75, 'aksam' => 0, 'kumanya' => 0], 245.0);
        $http = $this->http([$this->contactYanit()]);

        // (a) sunucuda token yok
        $r = (new ParasutYaz($this->repo, null, $http))
            ->createShipmentDocument($cid, '2026-07-20', ['ogle' => 75], ['onay' => 'x']);
        $this->assertSame('onaysiz', $r['durum']);

        // (b) istemci imza göndermedi
        $r = (new ParasutYaz($this->repo, 'dogru', $http))
            ->createShipmentDocument($cid, '2026-07-20', ['ogle' => 75], []);
        $this->assertSame('onaysiz', $r['durum']);

        // (c) imza yanlış
        $r = (new ParasutYaz($this->repo, 'dogru', $http))
            ->createShipmentDocument($cid, '2026-07-20', ['ogle' => 75], ['onay' => 'yanlis']);
        $this->assertSame('onaysiz', $r['durum']);

        $this->assertSame([], $this->cagrilar, 'onaysız hiçbir istek gitmez');
    }

    // ══ 3) Mükerrer kalkanı ══════════════════════════════════════
    public function testAyniGunIkinciKesimYerelKalkandaDurur(): void
    {
        $cid = $this->musteri('BOMİ');
        $this->repo->saveDayMeals($cid, '2026-07-20', ['ogle' => 75, 'aksam' => 0, 'kumanya' => 0], 245.0);
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            $this->contactYanit(), $this->bosListe(), $this->createYanit(),
        ]));
        $r1 = $yaz->createShipmentDocument($cid, '2026-07-20', ['ogle' => 75], ['onay' => 'imza']);
        $this->assertTrue($r1['ok']);
        $this->assertSame('kesildi', $r1['durum']);
        $this->assertSame('UU02026000000584', $r1['despatch_no']);
        $ilkCagriSayisi = count($this->cagrilar);

        // 2. kez (çift tık / ikinci cihaz): yerel log kalkanı → HİÇ yeni istek yok
        $r2 = $yaz->createShipmentDocument($cid, '2026-07-20', ['ogle' => 75], ['onay' => 'imza']);
        $this->assertFalse($r2['ok']);
        $this->assertSame('zaten_var', $r2['durum']);
        $this->assertCount($ilkCagriSayisi, $this->cagrilar, '2. kesimde yeni HTTP çağrısı olmamalı');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM parasut_irsaliye_log')->fetchColumn());
    }

    public function testDbUniqueAyniGunIkinciSatiriReddeder(): void
    {
        $cid = $this->musteri('BOMİ');
        $this->pdo->prepare('INSERT INTO parasut_irsaliye_log (customer_id, gun, durum) VALUES (?, ?, ?)')
            ->execute([$cid, '2026-07-20', 'kesildi']);
        $this->expectException(PDOException::class);
        $this->pdo->prepare('INSERT INTO parasut_irsaliye_log (customer_id, gun, durum) VALUES (?, ?, ?)')
            ->execute([$cid, '2026-07-20', 'kesildi']);
    }

    public function testKesildiKaydiUzerineYazilmaz(): void
    {
        $cid = $this->musteri('BOMİ');
        $this->repo->irsaliyeLogKaydet($cid, '2026-07-20', ['durum' => 'kesildi', 'despatch_no' => 'UU1', 'toplam_kisi' => 75]);
        $this->assertFalse(
            $this->repo->irsaliyeLogKaydet($cid, '2026-07-20', ['durum' => 'hata', 'hata_mesaj' => 'x']),
            'kesildi kaydı korunur (resmi belge izi)'
        );
        $log = $this->repo->irsaliyeLog($cid, '2026-07-20');
        $this->assertSame('kesildi', $log['durum']);
        $this->assertSame('UU1', $log['despatch_no']);

        // hata kaydı ise tekrar denemede güncellenebilir
        $c2 = $this->musteri('CEOTHERM');
        $this->repo->irsaliyeLogKaydet($c2, '2026-07-20', ['durum' => 'hata', 'hata_mesaj' => 'ilk']);
        $this->assertTrue($this->repo->irsaliyeLogKaydet($c2, '2026-07-20', ['durum' => 'kesildi', 'despatch_no' => 'UU2']));
        $this->assertSame('kesildi', $this->repo->irsaliyeLog($c2, '2026-07-20')['durum']);
    }

    public function testParasuttekiMevcutBelgeVarsaYeniBelgeOlusturulmaz(): void
    {
        $cid = $this->musteri('BOMİ');
        $mevcut = ['net' => 'ok', 'status' => 200, 'data' => ['data' => [[
            'id' => '5501',
            'attributes' => ['issue_date' => '2026-07-20', 'despatch_no' => 'UU02026000000500'],
            'relationships' => ['contact' => ['data' => ['id' => '1060083895']]],
        ]]]];
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([$this->contactYanit(), $mevcut, $this->createYanit()]));
        $r = $yaz->createShipmentDocument($cid, '2026-07-20', ['ogle' => 75], ['onay' => 'imza']);

        $this->assertSame('zaten_var', $r['durum']);
        $this->assertSame('UU02026000000500', $r['despatch_no']);
        $methods = array_column($this->cagrilar, 'method');
        $this->assertNotContains('POST', $methods, 'dış kaynaklı mükerrer: POST ATILMAZ');
        $this->assertSame('kesildi', $this->repo->irsaliyeLog($cid, '2026-07-20')['durum']);
    }

    // ══ 4) Timeout → RETRY YOK, "durum bilinmiyor" ════════════════
    public function testTimeoutSonrasiYenidenDenemeYok(): void
    {
        $cid = $this->musteri('BOMİ');
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            $this->contactYanit(), $this->bosListe(),
            ['net' => 'timeout', 'status' => 0, 'data' => [], 'error' => 'timeout'],
            $this->createYanit(), // bu ASLA tüketilmemeli
        ]));
        $r = $yaz->createShipmentDocument($cid, '2026-07-20', ['ogle' => 75], ['onay' => 'imza']);

        $this->assertFalse($r['ok']);
        $this->assertSame('bilinmiyor', $r['durum']);
        $this->assertStringContainsString('TEKRAR DENEMEYİN', $r['mesaj']);
        $postSayisi = count(array_filter($this->cagrilar, static fn($c) => $c['method'] === 'POST'));
        $this->assertSame(1, $postSayisi, 'timeout sonrası POST tekrarlanmaz');
        $this->assertSame('bilinmiyor', $this->repo->irsaliyeLog($cid, '2026-07-20')['durum']);

        // Sonraki denemede de kilitli: insan Paraşüt'ten doğrulamadan kesim yok
        $r2 = $yaz->createShipmentDocument($cid, '2026-07-20', ['ogle' => 75], ['onay' => 'imza']);
        $this->assertSame('bilinmiyor', $r2['durum']);
        $this->assertSame(1, count(array_filter($this->cagrilar, static fn($c) => $c['method'] === 'POST')));
    }

    public function testBaglantiKurulamadiysaBirKezYenidenDenenir(): void
    {
        $cid = $this->musteri('BOMİ');
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            $this->contactYanit(), $this->bosListe(),
            ['net' => 'connect', 'status' => 0, 'data' => [], 'error' => 'could not connect'],
            $this->createYanit(),
        ]));
        $r = $yaz->createShipmentDocument($cid, '2026-07-20', ['ogle' => 75], ['onay' => 'imza']);

        $this->assertTrue($r['ok'], 'istek hiç gitmediyse tek sefer yeniden denenir');
        $this->assertSame(2, count(array_filter($this->cagrilar, static fn($c) => $c['method'] === 'POST')));
    }

    // ══ 5) Kapsam dışı / eşleşmesiz müşteri ══════════════════════
    public function testKapsamDisiVeEslesmesizMusteriKesilmezVeSecilemez(): void
    {
        $kapsamDisi = $this->musteri('CANTAŞ', '1062204894', false);
        $eslesmesiz = $this->musteri('Marmara Teknik', null);
        $this->repo->saveDayMeals($kapsamDisi, '2026-07-20', ['ogle' => 70, 'aksam' => 0, 'kumanya' => 0], 328.0);
        $this->repo->saveDayMeals($eslesmesiz, '2026-07-20', ['ogle' => 19, 'aksam' => 0, 'kumanya' => 0], 300.0);

        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([$this->contactYanit()]));
        $a = $yaz->createShipmentDocument($kapsamDisi, '2026-07-20', ['ogle' => 70], ['onay' => 'imza']);
        $b = $yaz->createShipmentDocument($eslesmesiz, '2026-07-20', ['ogle' => 19], ['onay' => 'imza']);
        $this->assertSame('kapsam_disi', $a['durum']);
        $this->assertSame('eslesme_yok', $b['durum']);
        $this->assertSame([], $this->cagrilar, 'yerel engelde ağa çıkılmaz');

        $by = [];
        foreach ($this->repo->irsaliyeAdaylari('2026-07-20') as $r) {
            $by[$r['name']] = $r;
        }
        $this->assertFalse($by['CANTAŞ']['secilebilir']);
        $this->assertStringContainsString('kapsamı dışı', $by['CANTAŞ']['sebep']);
        $this->assertFalse($by['Marmara Teknik']['secilebilir']);
        $this->assertStringContainsString('eşleşmesi yok', $by['Marmara Teknik']['sebep'], 'sessiz atlama yok, sebep yazılı');
    }

    public function testSayiGirilmemisMusteriSecilemezVeKalemYok(): void
    {
        $cid = $this->musteri('OPAK');
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([$this->contactYanit()]));
        $r = $yaz->createShipmentDocument($cid, '2026-07-20', ['ogle' => 0, 'aksam' => 0, 'kumanya' => 0], ['onay' => 'imza']);
        $this->assertSame('kalem_yok', $r['durum']);
        $this->assertSame([], $this->cagrilar);

        $aday = $this->repo->irsaliyeAdaylari('2026-07-20')[0];
        $this->assertFalse($aday['secilebilir']);
        $this->assertStringContainsString('sayı girilmemiş', $aday['sebep']);
    }

    public function testKesildiktenSonraAdayListesindeKilitli(): void
    {
        $cid = $this->musteri('BOMİ');
        $this->repo->saveDayMeals($cid, '2026-07-20', ['ogle' => 75, 'aksam' => 0, 'kumanya' => 0], 245.0);
        $this->repo->irsaliyeLogKaydet($cid, '2026-07-20', ['durum' => 'kesildi', 'despatch_no' => 'UU9']);
        $aday = $this->repo->irsaliyeAdaylari('2026-07-20')[0];
        $this->assertFalse($aday['secilebilir']);
        $this->assertSame('Bugün zaten kesildi', $aday['sebep']);
        $this->assertSame('UU9', $aday['despatch_no']);
    }

    // ══ 6) Gövde kurulumu (kalemler / ürün id / miktar) ═══════════
    public function testUcOgunluMusteriUcKalemDogruUrunVeMiktar(): void
    {
        // PENDORYA 25 öğlen + 25 akşam + 8 kumanya (canlı emsal)
        $cid = $this->musteri('PENDORYA', '1060083802');
        $this->repo->saveDayMeals($cid, '2026-07-20', ['ogle' => 25, 'aksam' => 25, 'kumanya' => 8], 205.0);
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            $this->contactYanit('TSKB GAYRİMENKUL YATIRIM ORT. A.Ş.'), $this->bosListe(), $this->createYanit(),
        ]));
        $r = $yaz->createShipmentDocument($cid, '2026-07-20', ['ogle' => 25, 'aksam' => 25, 'kumanya' => 8], ['onay' => 'imza']);
        $this->assertTrue($r['ok']);
        $this->assertSame(58, $r['toplam'], '25+25+8');

        $post = null;
        foreach ($this->cagrilar as $c) {
            if ($c['method'] === 'POST') {
                $post = $c;
            }
        }
        $this->assertNotNull($post);
        $this->assertSame('/shipment_documents', $post['path']);
        $d = $post['body']['data'];
        $this->assertSame('shipment_documents', $d['type']);
        $this->assertSame('2026-07-20', $d['attributes']['issue_date']);
        $this->assertFalse($d['attributes']['inflow'], 'çıkış irsaliyesi');
        $this->assertSame('1060083802', $d['relationships']['contact']['data']['id']);
        $this->assertSame('İstanbul', $d['attributes']['city'], 'adres Paraşüt cari kartından');

        $hareket = $d['relationships']['stock_movements']['data'];
        $this->assertCount(3, $hareket, '3 öğün → 3 kalem');
        $map = [];
        foreach ($hareket as $h) {
            $map[$h['relationships']['product']['data']['id']] = $h['attributes']['quantity'];
        }
        $this->assertSame(25, $map['1063984872'], 'ÖĞLEN YEMEK BEDELİ × 25');
        $this->assertSame(25, $map['1063985050'], 'AKŞAM YEMEK BEDELİ × 25');
        $this->assertSame(8, $map['1063985150'], 'KUMANYA BEDELİ × 8');

        // Taşıyıcı bilgisi gövdede
        $this->assertSame('41BEM936', $d['attributes']['plate_number']);
        $this->assertSame('UFUK BALTACI', $d['attributes']['drivers_info'][0]['full_name']);
        $this->assertSame('23354463864', $d['attributes']['drivers_info'][0]['tckn']);
    }

    public function testIkiOgunluMusteriIkiKalem(): void
    {
        // ERMETAL 12 öğlen + 5 akşam (Ömer C1: irsaliyedeki kırılım doğru)
        $cid = $this->musteri('ERMETAL', '1060083336');
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            $this->contactYanit('ER METAL MADENCİLİK'), $this->bosListe(), $this->createYanit(),
        ]));
        $r = $yaz->createShipmentDocument($cid, '2026-07-20', ['ogle' => 12, 'aksam' => 5, 'kumanya' => 0], ['onay' => 'imza']);
        $this->assertTrue($r['ok']);
        $this->assertSame(17, $r['toplam']);

        $post = array_values(array_filter($this->cagrilar, static fn($c) => $c['method'] === 'POST'))[0];
        $hareket = $post['body']['data']['relationships']['stock_movements']['data'];
        $this->assertCount(2, $hareket, 'kumanya 0 → kalem üretilmez');
        $map = [];
        foreach ($hareket as $h) {
            $map[$h['relationships']['product']['data']['id']] = $h['attributes']['quantity'];
        }
        $this->assertSame(['1063984872' => 12, '1063985050' => 5], $map);
    }

    public function testOnizlemeHicAgCagrisiYapmaz(): void
    {
        $cid = $this->musteri('BOMİ');
        $yaz = new ParasutYaz($this->repo, null, $this->http([$this->contactYanit()]));
        $p = $yaz->onizleme($cid, '2026-07-20', ['ogle' => 75, 'aksam' => 0, 'kumanya' => 0]);
        $this->assertTrue($p['ok']);
        $this->assertSame(75, $p['toplam']);
        $this->assertSame([], $this->cagrilar, 'kuru deneme ağa çıkmaz');
        $this->assertSame('1060083895', $p['govde']['data']['relationships']['contact']['data']['id']);
        $this->assertCount(1, $p['govde']['data']['relationships']['stock_movements']['data']);
    }

    // ══ 7) Hata yolları ══════════════════════════════════════════
    public function testParasut4xxHatasiLoglanirVeAcikMesajDoner(): void
    {
        $cid = $this->musteri('BOMİ');
        $this->repo->saveDayMeals($cid, '2026-07-20', ['ogle' => 75, 'aksam' => 0, 'kumanya' => 0], 245.0);
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            $this->contactYanit(), $this->bosListe(),
            ['net' => 'ok', 'status' => 422, 'data' => ['errors' => [['detail' => 'Ürün bulunamadı']]], 'error' => ''],
        ]));
        $r = $yaz->createShipmentDocument($cid, '2026-07-20', ['ogle' => 75], ['onay' => 'imza']);
        $this->assertFalse($r['ok']);
        $this->assertSame('hata', $r['durum']);
        $this->assertStringContainsString('422', $r['mesaj']);
        $this->assertStringContainsString('Ürün bulunamadı', $r['mesaj']);
        $log = $this->repo->irsaliyeLog($cid, '2026-07-20');
        $this->assertSame('hata', $log['durum']);

        // Hata kaydı sonraki denemeyi ENGELLEMEZ (kalıcı kilit sadece kesildi/bilinmiyor)
        $aday = $this->repo->irsaliyeAdaylari('2026-07-20')[0];
        $this->assertSame('BOMİ', $aday['name']);
        $this->assertTrue($aday['secilebilir'], 'hata alan müşteri tekrar denenebilir');
    }

    public function testAdresYoksaMusteriAtlanirVeSebebiYazilir(): void
    {
        $cid = $this->musteri('BOMİ');
        $adressiz = ['net' => 'ok', 'status' => 200, 'data' => ['data' => ['id' => '1060083895',
            'attributes' => ['name' => 'LODİ', 'address' => '', 'city' => '', 'district' => '']]]];
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([$adressiz, $this->bosListe(), $this->createYanit()]));
        $r = $yaz->createShipmentDocument($cid, '2026-07-20', ['ogle' => 75], ['onay' => 'imza']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('sevk adresi yok', $r['mesaj']);
        $this->assertNotContains('POST', array_column($this->cagrilar, 'method'));
    }

    public function testTasiyiciIslenmediyseAcikcaBildirilir(): void
    {
        $cid = $this->musteri('BOMİ');
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            $this->contactYanit(), $this->bosListe(), $this->createYanit(false),
        ]));
        $r = $yaz->createShipmentDocument($cid, '2026-07-20', ['ogle' => 75], ['onay' => 'imza']);
        $this->assertTrue($r['ok'], 'belge kesildi');
        $this->assertFalse($r['tasiyici_ok']);
        $this->assertStringContainsString('taşıyıcı bilgisi', $r['mesaj'], 'gizlenmez, kullanıcıya söylenir');
        $this->assertSame(0, (int) $this->repo->irsaliyeLog($cid, '2026-07-20')['tasiyici_ok']);
    }

    public function testUrunEslemesiEksikseKesimYapilmaz(): void
    {
        $this->repo->ayarSet('irsaliye_urun_kumanya', '');
        $cid = $this->musteri('PENDORYA', '1060083802');
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([$this->contactYanit()]));
        $r = $yaz->createShipmentDocument($cid, '2026-07-20', ['ogle' => 25, 'aksam' => 25, 'kumanya' => 8], ['onay' => 'imza']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Kumanya', $r['mesaj']);
        $this->assertSame([], $this->cagrilar);
    }

    public function testRateLimit429BirKezYenidenDenenir(): void
    {
        $cid = $this->musteri('BOMİ');
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            ['net' => 'ok', 'status' => 429, 'data' => [], 'error' => ''],
            $this->contactYanit(), $this->bosListe(), $this->createYanit(),
        ]));
        $r = $yaz->createShipmentDocument($cid, '2026-07-20', ['ogle' => 75], ['onay' => 'imza']);
        $this->assertTrue($r['ok'], '429 sonrası bekle-tekrar dene ile devam eder');
    }

    // ══ 8) Kıran girdiler ════════════════════════════════════════
    public function testTurkceKarakterliMusteriAdiVeGecersizTarih(): void
    {
        $cid = $this->musteri('ŞIĞIRCI GIDA — ÖĞÜN A.Ş.');
        $this->repo->saveDayMeals($cid, '2026-07-20', ['ogle' => 3, 'aksam' => 0, 'kumanya' => 0], 245.0);
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            $this->contactYanit(), $this->bosListe(), $this->createYanit(),
        ]));
        $r = $yaz->createShipmentDocument($cid, '2026-07-20', ['ogle' => 3], ['onay' => 'imza']);
        $this->assertTrue($r['ok']);
        $this->assertSame('ŞIĞIRCI GIDA — ÖĞÜN A.Ş.', $this->repo->irsaliyeAdaylari('2026-07-20')[0]['name']);

        $bozuk = $yaz->createShipmentDocument($cid, '20.07.2026', ['ogle' => 3], ['onay' => 'imza']);
        $this->assertSame('hata', $bozuk['durum']);
        $this->assertSame('Geçersiz tarih.', $bozuk['mesaj']);
    }

    public function testNegatifVeAsiriMiktarNormalizeEdilir(): void
    {
        $cid = $this->musteri('BOMİ');
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            $this->contactYanit(), $this->bosListe(), $this->createYanit(),
        ]));
        $r = $yaz->createShipmentDocument(
            $cid,
            '2026-07-20',
            ['ogle' => -5, 'aksam' => 'abc', 'kumanya' => Repo::MEAL_MAX + 10],
            ['onay' => 'imza']
        );
        $this->assertTrue($r['ok']);
        $post = array_values(array_filter($this->cagrilar, static fn($c) => $c['method'] === 'POST'))[0];
        $hareket = $post['body']['data']['relationships']['stock_movements']['data'];
        $this->assertCount(1, $hareket, 'negatif ve metin öğün kalem üretmez');
        $this->assertSame(Repo::MEAL_MAX, $hareket[0]['attributes']['quantity'], 'üst sınır uygulanır');
    }

    public function testMukerrerSorgusuYapilamazsaAcikcaBildirilir(): void
    {
        $cid = $this->musteri('BOMİ');
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            $this->contactYanit(),
            ['net' => 'ok', 'status' => 400, 'data' => [], 'error' => ''], // filtre reddedildi
            $this->createYanit(),
        ]));
        $r = $yaz->createShipmentDocument($cid, '2026-07-20', ['ogle' => 75], ['onay' => 'imza']);
        $this->assertTrue($r['ok'], 'sorgu başarısızlığı kesimi engellemez (yerel kilit + onay var)');
        $this->assertStringContainsString('mükerrer sorgusu yapılamadı', $r['mesaj'], 'kalkan eksikliği gizlenmez');
    }

    // ══ 9) Yetki kapısı (canlı muhasebeye yazma) ══════════════════
    public function testYetkiKapisiRolBazli(): void
    {
        $this->assertTrue(\Uysa\Auth::isAdmin(['username' => 'ayse', 'role' => 'superadmin']));
        $this->assertTrue(\Uysa\Auth::isAdmin(['username' => 'ayse', 'role' => 'editor']));
        $this->assertFalse(\Uysa\Auth::isAdmin(['username' => 'ayse', 'role' => 'user']));
        $this->assertFalse(\Uysa\Auth::isAdmin(['username' => 'ayse', 'role' => 'viewer']));
        // Sistem sahibi rolü ne olursa olsun kilitlenmez (uysal daima sahip + .env ADMIN_USER)
        $this->assertTrue(\Uysa\Auth::isAdmin(['username' => 'uysal', 'role' => 'viewer']));
        $this->assertTrue(\Uysa\Auth::isAdmin([
            'username' => (string) \Uysa\Env::get('ADMIN_USER', 'uysal'), 'role' => 'viewer',
        ]));
        $this->assertFalse(\Uysa\Auth::isAdmin(['username' => '', 'role' => 'user']));
        $this->assertFalse(\Uysa\Auth::isAdmin(null));
    }

    // ══ 10) "durum bilinmiyor" kilidi insan kararıyla çözülür ═════
    public function testBilinmiyorKilidiInsanKarariylaCozulur(): void
    {
        $cid = $this->musteri('BOMİ');
        $this->repo->saveDayMeals($cid, '2026-07-20', ['ogle' => 75, 'aksam' => 0, 'kumanya' => 0], 245.0);
        $this->repo->irsaliyeLogKaydet($cid, '2026-07-20', ['durum' => 'bilinmiyor', 'toplam_kisi' => 75]);
        $this->assertFalse($this->repo->irsaliyeAdaylari('2026-07-20')[0]['secilebilir'], 'kilitli');

        // (a) "Paraşüt'te YOK" → kilit açılır, tekrar denenebilir
        $this->repo->irsaliyeLogKaydet($cid, '2026-07-20', ['durum' => 'hata', 'hata_mesaj' => 'elle açıldı']);
        $this->assertTrue($this->repo->irsaliyeAdaylari('2026-07-20')[0]['secilebilir']);

        // (b) "Paraşüt'te VAR" → kesildi olarak kilitlenir ve bir daha ezilmez
        $this->repo->irsaliyeLogKaydet($cid, '2026-07-20', ['durum' => 'kesildi', 'despatch_no' => 'UU7']);
        $aday = $this->repo->irsaliyeAdaylari('2026-07-20')[0];
        $this->assertFalse($aday['secilebilir']);
        $this->assertSame('UU7', $aday['despatch_no']);
    }

    public function testAdaylarListesiGununTumMusterileriniGosterir(): void
    {
        $a = $this->musteri('BOMİ');
        $b = $this->musteri('PENDORYA', '1060083802');
        $c = $this->musteri('CANTAŞ', '1062204894', false);
        $this->repo->saveDayMeals($a, '2026-07-20', ['ogle' => 75, 'aksam' => 0, 'kumanya' => 0], 245.0);
        $this->repo->saveDayMeals($b, '2026-07-20', ['ogle' => 25, 'aksam' => 25, 'kumanya' => 8], 205.0);
        $this->repo->saveDayMeals($c, '2026-07-20', ['ogle' => 70, 'aksam' => 0, 'kumanya' => 0], 328.0);

        $adaylar = $this->repo->irsaliyeAdaylari('2026-07-20');
        $this->assertCount(3, $adaylar, 'seçilemeyenler de listede (sessiz atlama yok)');
        $by = [];
        foreach ($adaylar as $r) {
            $by[$r['name']] = $r;
        }
        $this->assertTrue($by['BOMİ']['secilebilir']);
        $this->assertTrue($by['PENDORYA']['secilebilir']);
        $this->assertSame(58, $by['PENDORYA']['toplam']);
        $this->assertSame(25, $by['PENDORYA']['aksam']);
        $this->assertFalse($by['CANTAŞ']['secilebilir']);
    }
}
