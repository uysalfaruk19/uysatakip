<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\ParasutYaz;
use Uysa\Repo;

/**
 * fable-065 — SABİT AYLIK FATURA KALEMİ (BOMİ personel hizmeti).
 *
 * Yemek faturasından AYRI, üretimden BAĞIMSIZ, her ay AYNI tutarda kesilen kalem.
 *
 * 🔒 GERÇEK Paraşüt çağrısı YOK: ağ katmanı enjekte edilir, "kaç kez neyle çağrıldı" ÖLÇÜLÜR.
 *    En kritik üç test: (1) şalter kapalıyken hiç HTTP, (2) aynı ay ikinci kesimde hiç HTTP
 *    (mükerrer e-Fatura geri alınamaz), (3) sabit kalemi olmayan müşteride REGRESYON YOK.
 */
final class SabitFaturaTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;
    /** @var array<int,array{method:string,path:string,body:?array}> */
    private array $cagrilar = [];

    private const BIRIM = 48208.83;   // canlı BOMİ tutarı (PERSONEL HİZMET)
    private const NET   = 57850.60;   // 1 × 48.208,83 + KDV %20

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
        putenv('APP_TODAY=2099-12-31');
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

    private function createYanit(string $id = '9101', string $no = 'UY02026000000199'): array
    {
        return ['net' => 'ok', 'status' => 201, 'data' => ['data' => [
            'id' => $id,
            'attributes' => ['invoice_no' => $no, 'item_type' => 'invoice'],
            'relationships' => ['details' => ['data' => [['id' => '701', 'type' => 'sales_invoice_details']]]],
        ]]];
    }

    private function musteri(string $ad, array $opt = []): int
    {
        $cid = seed_customer($this->pdo, $ad, 250.0);
        $this->pdo->prepare(
            'UPDATE customers SET parasut_id = ?, irsaliye_aktif = ?, fatura_vade_gun = ?,
                edespatch_alias = ?, fatura_mail = ? WHERE id = ?'
        )->execute([
            $opt['parasut_id'] ?? '1060083895',
            ($opt['irsaliye_aktif'] ?? true) ? 1 : 0,
            $opt['vade_gun'] ?? 1,
            $opt['alias'] ?? null,
            $opt['mail'] ?? null,
            $cid,
        ]);
        return $cid;
    }

    /** BOMİ deseni: PERSONEL HİZMET kalemi (1 × 48.208,83 · KDV %20). */
    private function kalem(int $cid, array $opt = []): int
    {
        return $this->repo->upsertSabitFaturaKalem(
            $cid,
            $opt['ad'] ?? 'Personel hizmeti',
            $opt['birim'] ?? self::BIRIM,
            $opt['kdv'] ?? 20.0,
            $opt['urun'] ?? '1066391424',
            $opt['contact'] ?? null,
            $opt['aciklama'] ?? null
        );
    }

    private function irsaliye(int $cid, string $gun, int $ogle, string $no): void
    {
        $this->repo->irsaliyeLogKaydet($cid, $gun, [
            'durum' => 'kesildi', 'despatch_no' => $no, 'parasut_doc_id' => 'DOC' . $no,
            'kalemler' => [['ogun' => 'ogle', 'urun_id' => '1063984872', 'miktar' => $ogle]],
            'toplam_kisi' => $ogle, 'gonderim' => 'gonderildi',
        ]);
    }

    /** Aday listesinden satir_key ile satır bul. */
    private function aday(string $key, string $bas = '2026-07-01', string $son = '2026-07-31'): ?array
    {
        foreach ($this->repo->faturaAdaylari($bas, $son) as $a) {
            if ((string) $a['satir_key'] === $key) {
                return $a;
            }
        }
        return null;
    }

    // ══ TUTAR: 48.208,83 → 57.850,60 kuruşu kuruşuna ═══════════════════════════
    public function testKdvYirmiHesabiKurusuKurusuna(): void
    {
        $h = Repo::sabitFaturaHesap(self::BIRIM, 20.0);
        self::assertSame(4820883, $h['brut_kurus'], 'brüt kuruş');
        self::assertSame(964177, $h['kdv_kurus'], 'KDV %20 kuruş (48.208,83 × 0,20 = 9.641,766 → 9.641,77)');
        self::assertSame(5785060, $h['net_kurus'], 'net kuruş');
        self::assertSame(57850.60, $h['net'], 'net TL');
        self::assertSame(48208.83, $h['brut']);
        self::assertSame(9641.77, $h['kdv']);

        // Yemek %10 ile KARIŞTIRILMADI: aynı tutarın %10'u farklı sonuç verir.
        self::assertSame(53029.71, Repo::sabitFaturaHesap(self::BIRIM, 10.0)['net']);

        // Kesim tarafı da AYNI hesabı okur (tek kaynak): ParasutYaz::faturaHesap ile birebir.
        $f = ParasutYaz::faturaHesap([['miktar' => 1, 'birim' => self::BIRIM]], 20.0, null);
        self::assertSame($h['net_kurus'], $f['net_kurus'], 'aday ekranı ve kesim farklı tutar hesaplıyor');
    }

    // ══ REGRESYON: sabit kalemi OLMAYAN müşteride hiçbir davranış değişmez ══════
    public function testSabitKalemiOlmayanMusterideRegresyonYok(): void
    {
        $bomi = $this->musteri('BOMİ');
        $opak = $this->musteri('OPAK', ['parasut_id' => '1060083802']);
        $this->irsaliye($bomi, '2026-07-13', 120, 'UU1');
        $this->irsaliye($opak, '2026-07-13', 40, 'UU2');

        $once = $this->repo->faturaAdaylari('2026-07-01', '2026-07-31');
        self::assertCount(2, $once, 'başlangıçta 2 yemek adayı olmalı');

        $this->kalem($bomi);   // yalnız BOMİ'ye sabit kalem eklendi

        $sonra = $this->repo->faturaAdaylari('2026-07-01', '2026-07-31');
        self::assertCount(3, $sonra, 'BOMİ sabit satırı eklenmeli (2 + 1)');

        // OPAK satırı BİREBİR aynı kalmalı (tek fark yok)
        $opakOnce = array_values(array_filter($once, static fn(array $a): bool => $a['name'] === 'OPAK'))[0];
        $opakSonra = array_values(array_filter($sonra, static fn(array $a): bool => $a['name'] === 'OPAK'))[0];
        self::assertSame($opakOnce, $opakSonra, 'sabit kalemi olmayan müşterinin adayı değişti');
    }

    // ══ BOMİ'de yemek + sabit BİRLİKTE çıkar (iki AYRI satır) ══════════════════
    public function testYemekVeSabitAdayiBirlikteCikar(): void
    {
        $cid = $this->musteri('BOMİ');
        $this->irsaliye($cid, '2026-07-13', 120, 'UU1');
        $this->irsaliye($cid, '2026-07-14', 118, 'UU2');
        $kid = $this->kalem($cid);

        $adaylar = $this->repo->faturaAdaylari('2026-07-01', '2026-07-31');
        self::assertCount(2, $adaylar, 'aynı müşteride iki ayrı aday satırı olmalı');

        $yemek = $this->aday((string) $cid);
        $sabit = $this->aday('s' . $kid);
        self::assertNotNull($yemek, 'yemek (irsaliye) adayı kayboldu');
        self::assertNotNull($sabit, 'sabit kalem adayı çıkmadı');

        self::assertSame('irsaliye', $yemek['tip']);
        self::assertSame(238, (int) $yemek['toplam'], 'yemek adayının sayısı bozuldu');
        self::assertTrue((bool) $yemek['secilebilir'], (string) $yemek['sebep']);

        self::assertSame('sabit', $sabit['tip']);
        self::assertSame('BOMİ · Personel hizmeti', $sabit['name']);
        self::assertSame(1, (int) $sabit['adet']);
        self::assertSame(self::NET, (float) $sabit['net']);
        self::assertSame(20.0, (float) $sabit['kdv_orani']);
        self::assertSame('2026-07-31', $sabit['donem_son']);
        self::assertTrue((bool) $sabit['secilebilir'], (string) $sabit['sebep']);

        // Anahtarlar benzersiz — ekran satırları birbirini EZMEZ (customer_id ikisinde de aynı).
        self::assertSame($cid, (int) $sabit['customer_id']);
        self::assertNotSame($yemek['satir_key'], $sabit['satir_key']);
    }

    // ══ SABİT KALEM ÜRETİMDEN BAĞIMSIZ: irsaliye/üretim yokken de çıkar ════════
    public function testUretimYokkenDeSabitAdayiCikar(): void
    {
        $cid = $this->musteri('BOMİ');
        $kid = $this->kalem($cid);

        $adaylar = $this->repo->faturaAdaylari('2026-07-01', '2026-07-31');
        self::assertCount(1, $adaylar, 'yemek adayı yokken sabit satırı tek başına çıkmalı');
        self::assertSame('s' . $kid, $adaylar[0]['satir_key']);
        self::assertTrue((bool) $adaylar[0]['secilebilir'], (string) $adaylar[0]['sebep']);
    }

    // ══ AY KAPANMADAN SEÇİLEMEZ (fable-056 kuralının AYNISI, mesaj biçimi de aynı) ══
    public function testAyKapanmadanSecilemez(): void
    {
        $cid = $this->musteri('BOMİ');
        $kid = $this->kalem($cid);

        $durum = function (string $bugun) use ($kid): array {
            putenv('APP_TODAY=' . $bugun);
            try {
                return $this->aday('s' . $kid) ?? [];
            } finally {
                putenv('APP_TODAY=2099-12-31');
            }
        };

        $ayOrtasi = $durum('2026-07-15');
        self::assertFalse((bool) $ayOrtasi['secilebilir'], 'ay ortasında kesilebiliyor');
        self::assertSame('Aylık fatura ay kapanınca kesilir — 31.07.2026 tarihinde açılır.',
            (string) $ayOrtasi['sebep'], 'mesaj biçimi fable-056 ile aynı olmalı');

        self::assertFalse((bool) $durum('2026-07-30')['secilebilir'], 'son günden bir gün önce açık');
        self::assertTrue((bool) $durum('2026-07-31')['secilebilir'], 'ayın son günü kapalı');
        self::assertTrue((bool) $durum('2026-08-04')['secilebilir'], 'ay bittikten sonra kapalı kalmış');
    }

    public function testAyKapanmadanKesimDeReddedilir(): void
    {
        $cid = $this->musteri('BOMİ');
        $kid = $this->kalem($cid);
        putenv('APP_TODAY=2026-07-15');
        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http([$this->createYanit()]));
        $r = $yaz->createFixedInvoice($kid, '2026-07', ['onay' => 'TOKEN', 'actor' => 'uysal']);
        putenv('APP_TODAY=2099-12-31');

        self::assertFalse($r['ok']);
        self::assertSame('ay_kapanmadi', $r['durum']);
        self::assertCount(0, $this->cagrilar, 'ay kapanmadan HTTP çağrısı yapıldı');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM parasut_fatura_log')->fetchColumn());
    }

    // ══ EN KRİTİK 1: şalter kapalı → HİÇ HTTP + HİÇ log ════════════════════════
    public function testSalterKapaliykenHicHttpVeLogYok(): void
    {
        $this->salter(false);
        $cid = $this->musteri('BOMİ');
        $kid = $this->kalem($cid);
        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http([$this->createYanit()]));

        $r = $yaz->createFixedInvoice($kid, '2026-07', ['onay' => 'TOKEN', 'actor' => 'uysal']);
        self::assertFalse($r['ok']);
        self::assertSame('kapali', $r['durum']);
        self::assertCount(0, $this->cagrilar, 'şalter kapalıyken HTTP çağrısı yapıldı');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM parasut_fatura_log')->fetchColumn());
    }

    public function testOnaysizKesimYok(): void
    {
        $cid = $this->musteri('BOMİ');
        $kid = $this->kalem($cid);
        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http([$this->createYanit()]));

        self::assertSame('onaysiz', $yaz->createFixedInvoice($kid, '2026-07', ['onay' => 'YANLIS'])['durum']);
        self::assertSame('onaysiz', $yaz->createFixedInvoice($kid, '2026-07', [])['durum']);
        self::assertCount(0, $this->cagrilar, 'onaysız HTTP çağrısı yapıldı');
    }

    // ══ KESİM: gövde + log + audit ════════════════════════════════════════════
    public function testKesimGovdesiVeLoguDogru(): void
    {
        $cid = $this->musteri('BOMİ', ['vade_gun' => 7]);
        $kid = $this->kalem($cid);
        $this->pdo->prepare("INSERT INTO ayar (anahtar, deger) VALUES ('fatura_notu', ?)")
            ->execute(['IBAN: TR00 0000 0000 0000 0000 0000 00 · UYSA YEMEK']);

        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http([$this->createYanit('9101', 'UY02026000000199')]));
        $r = $yaz->createFixedInvoice($kid, '2026-07', ['onay' => 'TOKEN', 'actor' => 'uysal']);

        self::assertTrue($r['ok'], $r['mesaj']);
        self::assertSame('kesildi', $r['durum']);
        self::assertSame('UY02026000000199', $r['fatura_no']);
        self::assertSame(self::NET, $r['net']);
        self::assertCount(1, $this->cagrilar, 'alias yokken tek POST bekleniyor');

        $g = $this->cagrilar[0]['body']['data'];
        self::assertSame('POST', $this->cagrilar[0]['method']);
        self::assertSame('2026-07-31', $g['attributes']['issue_date'], 'issue_date ayın SON günü olmalı');
        self::assertSame('2026-08-07', $g['attributes']['due_date'], 'vade = ay sonu + 7 gün');
        self::assertSame('07.2026 dönemi Personel hizmeti', $g['attributes']['description']);
        self::assertStringContainsString('IBAN', (string) $g['attributes']['print_note'], 'fable-061 IBAN notu yok');
        self::assertSame('1060083895', $g['relationships']['contact']['data']['id']);

        $d = $g['relationships']['details']['data'][0];
        self::assertSame(1, $d['attributes']['quantity']);
        self::assertSame(self::BIRIM, $d['attributes']['unit_price']);
        self::assertSame(20.0, $d['attributes']['vat_rate'], 'KDV %20 olmalı (yemek %10 DEĞİL)');
        self::assertSame('Personel hizmeti', $d['attributes']['description']);
        self::assertSame('1066391424', $d['relationships']['product']['data']['id']);
        self::assertArrayNotHasKey('vat_withholding_rate', $d['attributes'], 'sabit kalemde tevkifat yok');

        $log = $this->pdo->query('SELECT * FROM parasut_fatura_log ORDER BY id DESC LIMIT 1')->fetch();
        self::assertSame('sabit', $log['tip']);
        self::assertSame($kid, (int) $log['sabit_kalem_id']);
        self::assertSame('kesildi', $log['durum']);
        self::assertSame('Personel hizmeti', $log['alt_ad']);
        self::assertSame('2026-07-01', $log['donem_bas']);
        self::assertSame('2026-07-31', $log['donem_son']);
        self::assertSame(1, (int) $log['toplam_kisi']);
        self::assertEqualsWithDelta(self::NET, (float) $log['toplam_tutar'], 0.001);

        $audit = (int) $this->pdo->query("SELECT COUNT(*) FROM audit WHERE action = 'parasut_fatura'")->fetchColumn();
        self::assertSame(1, $audit, 'audit izi yazılmadı');
    }

    // ══ EN KRİTİK 2: aynı ay + aynı kalem İKİ KEZ kesilemez ═══════════════════
    public function testAyniAyIkinciKesimdeHicHttpYok(): void
    {
        $cid = $this->musteri('BOMİ');
        $kid = $this->kalem($cid);

        $yaz1 = new ParasutYaz($this->repo, 'TOKEN', $this->http([$this->createYanit()]));
        self::assertTrue($yaz1->createFixedInvoice($kid, '2026-07', ['onay' => 'TOKEN', 'actor' => 'uysal'])['ok']);
        self::assertCount(1, $this->cagrilar);

        // 2. kesim: kalkan ağa çıkmadan durdurur.
        $this->cagrilar = [];
        $yaz2 = new ParasutYaz($this->repo, 'TOKEN2', $this->http([$this->createYanit('9999', 'UY_MUKERRER')]));
        $r = $yaz2->createFixedInvoice($kid, '2026-07', ['onay' => 'TOKEN2', 'actor' => 'uysal']);

        self::assertFalse($r['ok'], 'mükerrer kesim geçti');
        self::assertSame('zaten_kesildi', $r['durum']);
        self::assertStringContainsString('UY02026000000199', $r['mesaj']);
        self::assertCount(0, $this->cagrilar, 'mükerrer denemede HTTP çağrısı yapıldı');
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM parasut_fatura_log WHERE tip='sabit'")->fetchColumn());

        // Aday listesi de kapanır — sebep fatura numarasını yazar.
        $a = $this->aday('s' . $kid);
        self::assertFalse((bool) $a['secilebilir']);
        self::assertSame('Bu ay kesildi (UY02026000000199)', (string) $a['sebep']);

        // SONRAKİ AY yeniden açılır (kalkan aya bağlı, kaleme kalıcı kilit değil).
        $agustos = $this->aday('s' . $kid, '2026-08-01', '2026-08-31');
        self::assertTrue((bool) $agustos['secilebilir'], (string) $agustos['sebep']);
        self::assertFalse($this->repo->sabitFaturaKesildiMi($kid, '2026-08'));
        self::assertTrue($this->repo->sabitFaturaKesildiMi($kid, '2026-07'));
    }

    /**
     * Fable'ın canlıda AÇACAĞI Temmuz kaydı: fatura Paraşüt'ten ELLE kesildi (UY02026000000145).
     * Kayıt sabit_kalem_id OLMADAN (yalnız alt_ad ile) açılsa bile kalkan tutmalı.
     */
    public function testElleAcilanLogKaydiDaKalkanOlurAltAdIle(): void
    {
        $cid = $this->musteri('BOMİ');
        $kid = $this->kalem($cid);
        $this->pdo->prepare(
            "INSERT INTO parasut_fatura_log
               (customer_id, donem_bas, donem_son, tip, sabit_kalem_id, parasut_contact_id,
                fatura_no, alt_ad, toplam_kisi, toplam_tutar, durum, resmilestirme, mail, entered_by)
             VALUES (?, '2026-07-01', '2026-07-31', 'sabit', NULL, '1060083895',
                'UY02026000000145', 'Personel hizmeti', 1, 57850.60, 'kesildi', 'gonderildi', 'yok', 'elle-parasut')"
        )->execute([$cid]);

        self::assertTrue($this->repo->sabitFaturaKesildiMi($kid, '2026-07'), 'elle açılan kayıt kalkan olmadı');
        $a = $this->aday('s' . $kid);
        self::assertFalse((bool) $a['secilebilir']);
        self::assertSame('Bu ay kesildi (UY02026000000145)', (string) $a['sebep']);

        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http([$this->createYanit()]));
        $r = $yaz->createFixedInvoice($kid, '2026-07', ['onay' => 'TOKEN', 'actor' => 'uysal']);
        self::assertFalse($r['ok']);
        self::assertCount(0, $this->cagrilar, 'elle kesilmiş ay için HTTP çağrısı yapıldı');
    }

    // ══ TIMEOUT: durum 'bilinmiyor' KALIR, asla yeniden denenmez ══════════════
    public function testTimeoutBilinmiyorKalirVeTekrarDenenmez(): void
    {
        $cid = $this->musteri('BOMİ');
        $kid = $this->kalem($cid);

        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http([['net' => 'timeout', 'status' => 0, 'data' => []]]));
        $r = $yaz->createFixedInvoice($kid, '2026-07', ['onay' => 'TOKEN', 'actor' => 'uysal']);

        self::assertFalse($r['ok']);
        self::assertSame('bilinmiyor', $r['durum']);
        self::assertCount(1, $this->cagrilar, 'timeout sonrası YENİDEN DENENDİ (yasak)');
        $log = $this->pdo->query('SELECT * FROM parasut_fatura_log ORDER BY id DESC LIMIT 1')->fetch();
        self::assertSame('bilinmiyor', $log['durum']);

        // Aday listesi kilitlenir + ikinci deneme ağa çıkmaz.
        $a = $this->aday('s' . $kid);
        self::assertFalse((bool) $a['secilebilir']);
        self::assertStringContainsString('belirsiz', (string) $a['sebep']);

        $this->cagrilar = [];
        $r2 = (new ParasutYaz($this->repo, 'T2', $this->http([$this->createYanit()])))
            ->createFixedInvoice($kid, '2026-07', ['onay' => 'T2']);
        self::assertSame('bilinmiyor', $r2['durum']);
        self::assertCount(0, $this->cagrilar, 'belirsiz kesimden sonra tekrar denendi');
    }

    // ══ SABİT KALEMİN TAKILMASI YEMEK FATURASINI KİLİTLEMEZ ═══════════════════
    public function testSabitKalemKilidiYemekFaturasiniEtkilemez(): void
    {
        $cid = $this->musteri('BOMİ');
        $kid = $this->kalem($cid);
        $this->irsaliye($cid, '2026-07-13', 120, 'UU1');

        // Sabit kalemde zaman aşımı → o satır kilitli.
        (new ParasutYaz($this->repo, 'TOKEN', $this->http([['net' => 'timeout', 'status' => 0, 'data' => []]])))
            ->createFixedInvoice($kid, '2026-07', ['onay' => 'TOKEN']);

        self::assertFalse((bool) $this->aday('s' . $kid)['secilebilir'], 'sabit satır kilitlenmeliydi');
        $yemek = $this->aday((string) $cid);
        self::assertTrue((bool) $yemek['secilebilir'],
            'sabit kalemdeki takılma yemek faturasını da kilitledi: ' . (string) $yemek['sebep']);
        self::assertNull($this->repo->faturaKilidi($cid, '2026-07-01', '2026-07-31'));
    }

    // ══ EKSİK EŞLEŞME / PASİF / KIRAN GİRDİ ═══════════════════════════════════
    public function testEksikEslesmeSecilemezVeSebebiYazili(): void
    {
        $cariSiz = $this->musteri('CARİSİZ', ['parasut_id' => '']);
        $k1 = $this->kalem($cariSiz, ['ad' => 'Kira bedeli']);
        $a1 = $this->aday('s' . $k1);
        self::assertFalse((bool) $a1['secilebilir']);
        self::assertStringContainsString('cari açılınca aktif olur', (string) $a1['sebep']);

        $urunsuz = $this->musteri('ÜRÜNSÜZ', ['parasut_id' => '1060099999']);
        $k2 = $this->kalem($urunsuz, ['ad' => 'Kira bedeli', 'urun' => '']);
        $a2 = $this->aday('s' . $k2);
        self::assertFalse((bool) $a2['secilebilir']);
        self::assertStringContainsString('ürün eşleşmesi yok', (string) $a2['sebep']);

        // Kesim tarafı da aynı kapıları uygular (ekranı atlayan istek geçemez).
        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http([$this->createYanit()]));
        self::assertSame('eslesme_yok', $yaz->createFixedInvoice($k1, '2026-07', ['onay' => 'TOKEN'])['durum']);
        self::assertSame('hata', $yaz->createFixedInvoice($k2, '2026-07', ['onay' => 'TOKEN'])['durum']);
        self::assertCount(0, $this->cagrilar);
    }

    public function testPasifKalemAdaydaCikmazVeKesilemez(): void
    {
        $cid = $this->musteri('BOMİ');
        $kid = $this->kalem($cid);
        self::assertNotNull($this->aday('s' . $kid));

        $this->repo->setSabitFaturaKalemAktif($kid, false);
        self::assertNull($this->aday('s' . $kid), 'pasif kalem aday listesinde kaldı');
        self::assertCount(1, $this->repo->sabitFaturaKalemleri($cid, false), 'kayıt SİLİNMEMELİ (iz kalır)');
        self::assertCount(0, $this->repo->sabitFaturaKalemleri($cid), 'pasif kalem aktif listesinde');

        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http([$this->createYanit()]));
        $r = $yaz->createFixedInvoice($kid, '2026-07', ['onay' => 'TOKEN']);
        self::assertSame('kapsam_disi', $r['durum']);
        self::assertCount(0, $this->cagrilar);
    }

    public function testKiranGirdilerReddedilir(): void
    {
        $cid = $this->musteri('BOMİ');

        $this->expectException(InvalidArgumentException::class);
        $this->repo->upsertSabitFaturaKalem($cid, '   ', 100.0);
    }

    public function testSifirVeNegatifBirimFiyatReddedilir(): void
    {
        $cid = $this->musteri('BOMİ');
        foreach ([0.0, -1.0] as $kotu) {
            try {
                $this->repo->upsertSabitFaturaKalem($cid, 'Kira ' . $kotu, $kotu);
                self::fail('birim fiyat ' . $kotu . ' kabul edildi');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('Birim fiyat', $e->getMessage());
            }
        }
        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http([$this->createYanit()]));
        self::assertSame('hata', $yaz->createFixedInvoice(999999, '2026-07', ['onay' => 'TOKEN'])['durum'],
            'olmayan kalem kesilebiliyor');
        self::assertSame('hata', $yaz->createFixedInvoice(1, 'temmuz', ['onay' => 'TOKEN'])['durum'],
            'geçersiz ay biçimi kabul edildi');
        self::assertCount(0, $this->cagrilar);
    }

    // ══ TÜRKÇE KARAKTER: kayıt → aday → gövde zinciri bozulmamalı ═════════════
    public function testTurkceAdZincirBoyuncaKorunur(): void
    {
        $cid = $this->musteri('BOMİ ŞİRKETİ');
        $ad = 'Personel hizmeti · İŞÇİ ÜÇRETİ (ÖĞÜN)';
        $kid = $this->kalem($cid, ['ad' => $ad, 'aciklama' => 'Ayrı fatura — üretimden bağımsız']);

        $kalem = $this->repo->sabitFaturaKalem($kid);
        self::assertSame($ad, $kalem['ad'], 'kayıt/okuma turunda Türkçe ad bozuldu');

        $a = $this->aday('s' . $kid);
        self::assertSame('BOMİ ŞİRKETİ · ' . $ad, $a['name']);
        self::assertSame('Ayrı fatura — üretimden bağımsız', $a['aciklama']);

        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http([$this->createYanit()]));
        $r = $yaz->createFixedInvoice($kid, '2026-07', ['onay' => 'TOKEN', 'actor' => 'uysal']);
        self::assertTrue($r['ok'], $r['mesaj']);
        $g = $this->cagrilar[0]['body']['data'];
        self::assertSame('07.2026 dönemi ' . $ad, $g['attributes']['description']);
        self::assertSame($ad, $g['relationships']['details']['data'][0]['attributes']['description']);

        $log = $this->pdo->query('SELECT alt_ad FROM parasut_fatura_log ORDER BY id DESC LIMIT 1')->fetch();
        self::assertSame($ad, $log['alt_ad']);
    }

    // ══ CARİ OVERRIDE: kalem BAŞKA bir cariye kesilebilir ═════════════════════
    public function testCariOverrideKullanilir(): void
    {
        $cid = $this->musteri('BOMİ', ['alias' => 'urn:mail:musteri@x.com']);
        $kid = $this->kalem($cid, ['contact' => '1099999999']);

        $a = $this->aday('s' . $kid);
        self::assertSame('1099999999', $a['parasut_id'], 'kalem carisi müşteri carisini ezmeli');

        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http([$this->createYanit()]));
        $r = $yaz->createFixedInvoice($kid, '2026-07', ['onay' => 'TOKEN', 'actor' => 'uysal']);
        self::assertTrue($r['ok'], $r['mesaj']);
        self::assertSame('1099999999',
            $this->cagrilar[0]['body']['data']['relationships']['contact']['data']['id']);
        // Alias çözülemedi (test ortamında Paraşüt yok) → müşteri alias'ına DÜŞÜLMEZ:
        // yanlış e-Fatura kutusuna belge gitmez, elle resmileştirme uyarısı verilir.
        self::assertSame('yok', $r['resmilestirme']);
        self::assertCount(1, $this->cagrilar, 'alias çözülemezken e-Fatura POST edildi');
    }

    // ══ e-FATURA RESMİLEŞTİRME (müşterinin kendi carisi + alias) ══════════════
    public function testKendiCarisindeAliasIleResmilestirilir(): void
    {
        $cid = $this->musteri('BOMİ', ['alias' => 'urn:mail:defaultpk@lodi.com.tr']);
        $kid = $this->kalem($cid);

        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http([
            $this->createYanit(),
            ['net' => 'ok', 'status' => 201, 'data' => ['data' => ['id' => 'JOB1']]],
            ['net' => 'ok', 'status' => 200, 'data' => ['data' => ['id' => 'JOB1', 'attributes' => ['status' => 'done']]]],
            ['net' => 'ok', 'status' => 200, 'data' => ['data' => ['id' => '9101',
                'relationships' => ['active_e_document' => ['data' => ['id' => 'EDOC1', 'type' => 'e_invoices']]]]]],
        ]));
        $r = $yaz->createFixedInvoice($kid, '2026-07', ['onay' => 'TOKEN', 'actor' => 'uysal']);

        self::assertTrue($r['ok'], $r['mesaj']);
        self::assertSame('gonderildi', $r['resmilestirme'], 'e-Fatura otomatik resmileşmedi');
        self::assertSame('/e_invoices', $this->cagrilar[1]['path']);
        self::assertSame('urn:mail:defaultpk@lodi.com.tr', $this->cagrilar[1]['body']['data']['attributes']['to']);
        self::assertArrayNotHasKey('vat_withholding_params', $this->cagrilar[1]['body']['data']['attributes']);

        $log = $this->pdo->query('SELECT resmilestirme FROM parasut_fatura_log ORDER BY id DESC LIMIT 1')->fetch();
        self::assertSame('gonderildi', $log['resmilestirme']);
    }

    // ══ PARAŞÜT REDDİ: log 'hata', kalkan AÇIK kalır (tekrar denenebilir) ═════
    public function testParasutReddindeKalkanAcikKalir(): void
    {
        $cid = $this->musteri('BOMİ');
        $kid = $this->kalem($cid);

        $yaz = new ParasutYaz($this->repo, 'TOKEN', $this->http([
            ['net' => 'ok', 'status' => 422, 'data' => ['errors' => [['detail' => 'ürün bulunamadı']]]],
        ]));
        $r = $yaz->createFixedInvoice($kid, '2026-07', ['onay' => 'TOKEN', 'actor' => 'uysal']);

        self::assertFalse($r['ok']);
        self::assertSame('hata', $r['durum']);
        $log = $this->pdo->query('SELECT durum FROM parasut_fatura_log ORDER BY id DESC LIMIT 1')->fetch();
        self::assertSame('hata', $log['durum']);
        // 'hata' kaydı KALKAN DEĞİL — düzeltip yeniden kesilebilmeli.
        self::assertFalse($this->repo->sabitFaturaKesildiMi($kid, '2026-07'));
        self::assertTrue((bool) $this->aday('s' . $kid)['secilebilir'], 'reddedilen kesimden sonra satır kapandı');
    }

    // ══ HAFTALIK DÖNEMDE DE AYIN TAMAMI (fable-055 ile aynı davranış) ═════════
    public function testHaftalikDonemdeDeAyinTamamiGorunur(): void
    {
        $cid = $this->musteri('BOMİ');
        $kid = $this->kalem($cid);

        $hafta = $this->aday('s' . $kid, '2026-07-22', '2026-07-28');
        self::assertNotNull($hafta, 'haftalık turda sabit kalem listelenmedi');
        self::assertSame('2026-07-01', $hafta['donem_bas']);
        self::assertSame('2026-07-31', $hafta['donem_son']);
        self::assertSame('2026-07', $hafta['ay']);
        self::assertSame(self::NET, (float) $hafta['net']);
    }

    // ══ KALEM GÜNCELLEME: aynı ad → yeni kayıt AÇILMAZ (mükerrer kalem yok) ═══
    public function testAyniAdlaKaydetGuncellerYeniAcmaz(): void
    {
        $cid = $this->musteri('BOMİ');
        $kid = $this->kalem($cid);
        $kid2 = $this->repo->upsertSabitFaturaKalem($cid, 'Personel hizmeti', 50000.00, 20.0, '1066391424');

        self::assertSame($kid, $kid2, 'aynı ad ikinci kez eklendi (mükerrer kalem)');
        self::assertCount(1, $this->repo->sabitFaturaKalemleri($cid));
        self::assertSame(50000.00, $this->repo->sabitFaturaKalem($kid)['birim_fiyat']);
        self::assertSame(60000.00, (float) $this->aday('s' . $kid)['net'], 'yeni fiyat aday satırına yansımadı');
    }

    // ══ TABLO YOKSA (migrate_052 uygulanmadan deploy) EKRAN ÇÖKMEZ ════════════
    public function testTabloYokkenAdayListesiCalismayaDevamEder(): void
    {
        $cid = $this->musteri('BOMİ');
        $this->irsaliye($cid, '2026-07-13', 120, 'UU1');
        $this->pdo->exec('DROP TABLE musteri_sabit_fatura');

        $adaylar = $this->repo->faturaAdaylari('2026-07-01', '2026-07-31');
        self::assertCount(1, $adaylar, 'tablo yokken yemek adayı da kayboldu');
        self::assertSame('irsaliye', $adaylar[0]['tip']);
        self::assertSame([], $this->repo->sabitFaturaKalemleri($cid));
    }
}
