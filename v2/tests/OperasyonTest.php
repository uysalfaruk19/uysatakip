<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * fable-003 operasyon modülleri: sipariş ihtiyacı, SKT riski, tedarikçi,
 * HACCP kaydı/numune, teklif akışı, teslimat durumu.
 */
final class OperasyonTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    private function seedIngredient(string $name, float $min = 0): int
    {
        $this->pdo->prepare('INSERT INTO ingredients (name, unit, min_stok) VALUES (?, ?, ?)')
            ->execute([$name, 'kg', $min]);
        return (int) $this->pdo->lastInsertId();
    }

    // ── Sipariş ihtiyacı + son alış/tedarikçi ──────────────────
    public function testOrderNeedListWithLastPurchase(): void
    {
        $sid = $this->repo->upsertSupplier('X Gıda', 'Mehmet 0532');
        $iid = $this->seedIngredient('Ayçiçek Yağı', 20);
        $ok = $this->seedIngredient('Tuz', 5);
        // Yağ: 8 giriş → stok 8 < eşik 20 → ihtiyaçta, eksik 12; son alış tedarikçili
        $this->repo->addStockMove($iid, '2026-07-10', 'giris', 8, null, null, null, $sid);
        // Tuz: 50 giriş → eşik üstü → listede YOK
        $this->repo->addStockMove($ok, '2026-07-10', 'giris', 50);

        $need = $this->repo->orderNeedList();
        $this->assertCount(1, $need, 'sadece eşik altı listelenir');
        $this->assertSame('Ayçiçek Yağı', $need[0]['name']);
        $this->assertEqualsWithDelta(12.0, $need[0]['eksik'], 0.001, 'eksik = eşik − stok');
        $this->assertSame('2026-07-10', $need[0]['son_alis']);
        $this->assertSame('X Gıda', $need[0]['son_tedarikci']);
    }

    // ── SKT riski ──────────────────────────────────────────────
    public function testSktRiskWindowAndCikisIgnored(): void
    {
        $iid = $this->seedIngredient('Süt');
        $yakin = date('Y-m-d', strtotime('+10 day'));
        $uzak = date('Y-m-d', strtotime('+90 day'));
        $this->repo->addStockMove($iid, date('Y-m-d'), 'giris', 10, null, null, $yakin);
        $this->repo->addStockMove($iid, date('Y-m-d'), 'giris', 10, null, null, $uzak);
        // Çıkışa SKT verilse bile sunucu tarafı temizler
        $this->repo->addStockMove($iid, date('Y-m-d'), 'cikis', 5, null, null, $yakin);

        $risk = $this->repo->sktRisk(30);
        $this->assertCount(1, $risk, '30 gün penceresinde tek kayıt');
        $this->assertSame($yakin, $risk[0]['skt']);
        $this->assertSame('Süt', $risk[0]['ingredient_name']);
    }

    // ── HACCP ──────────────────────────────────────────────────
    public function testHaccpLogAndSampleDisposal(): void
    {
        $d = date('Y-m-d');
        $this->repo->addHaccpLog($d, 'sicaklik', 'Soğuk Oda 1', '3.5', null, null, 'uysal');
        $this->repo->addHaccpLog($d, 'hijyen', 'WC / lavabo', null, false, 'sabun bitmiş', 'uysal');
        $nid = $this->repo->addHaccpLog($d, 'numune', 'Mercimek Çorbası', null, null, null, 'uysal');

        $logs = $this->repo->haccpLogsForDate($d);
        $this->assertCount(3, $logs);
        $this->assertSame('3.5', $this->repo->haccpLogsForDate($d, 'sicaklik')[0]['deger']);
        $this->assertSame(0, (int) $this->repo->haccpLogsForDate($d, 'hijyen')[0]['uygun'], 'uygunsuz kaydedildi');

        $samples = $this->repo->haccpActiveSamples();
        $this->assertCount(1, $samples);
        $this->assertTrue($this->repo->haccpDisposeSample($nid), 'imha başarılı');
        $this->assertFalse($this->repo->haccpDisposeSample($nid), 'ikinci imha reddedilir');
        $this->assertCount(0, $this->repo->haccpActiveSamples(), 'imha sonrası aktif numune yok');

        $this->expectException(InvalidArgumentException::class);
        $this->repo->addHaccpLog($d, 'gecersiz', 'X');
    }

    // ── Teklif ─────────────────────────────────────────────────
    public function testTeklifFlow(): void
    {
        $id = $this->repo->createTeklif('ABC Tekstil', 80, 320.0, 'Ali Bey');
        $this->assertGreaterThan(0, $id);
        $this->assertTrue($this->repo->setTeklifDurum($id, 'gonderildi'));
        $this->assertTrue($this->repo->setTeklifDurum($id, 'kabul'));
        $this->assertFalse($this->repo->setTeklifDurum(9999, 'red'), 'olmayan teklif false');
        $list = $this->repo->listTeklif();
        $this->assertSame('kabul', $list[0]['durum']);

        $this->expectException(InvalidArgumentException::class);
        $this->repo->setTeklifDurum($id, 'yanlis');
    }

    // ── fable-005: teklif motoru alanları — update round-trip + teklifById ──
    public function testTeklifUpdateRoundTrip(): void
    {
        $id = $this->repo->createTeklif('Gebze Metal', 120, 340.0, null, [
            'yetkili'     => 'Ayşe Hanım',
            'telefon'     => '0532 000 00 00',
            'cumartesi'   => 1,
            'segment'     => 'genel',
            'menu_json'   => json_encode(['corba' => 1, 'ana' => 1, 'ekmek' => 1]),
            'fiyat_json'  => json_encode(['kahvalti' => 135, 'ogle' => 235]),
            'giris_metni' => 'Kuruma özel giriş metni.',
        ]);
        $this->assertGreaterThan(0, $id);

        $t = $this->repo->teklifById($id);
        $this->assertNotNull($t);
        $this->assertSame('Gebze Metal', $t['firma']);
        $this->assertSame('Ayşe Hanım', $t['yetkili']);
        $this->assertSame(1, (int) $t['cumartesi']);
        $this->assertSame('genel', $t['segment']);
        // fable-007: fiyat_json / giris_metni round-trip
        $this->assertSame('Kuruma özel giriş metni.', $t['giris_metni']);
        $this->assertSame(['kahvalti' => 135, 'ogle' => 235], json_decode((string) $t['fiyat_json'], true));

        $ok = $this->repo->updateTeklif($id, [
            'firma'       => 'Gebze Metal A.Ş.',
            'kisi'        => 150,
            'birim_fiyat' => 360.0,
            'ilce'        => 'Gebze',
            'ekipman'     => 'benmari, çelik masa',
            'fiyat_json'  => json_encode(['ogle' => 250, 'aksam' => 250]),
            'giris_metni' => 'Güncel giriş metni.',
            'durum'       => 'kabul', // whitelist dışı — sessizce atlanmalı
        ]);
        $this->assertTrue($ok);

        $t = $this->repo->teklifById($id);
        $this->assertSame('Gebze Metal A.Ş.', $t['firma']);
        $this->assertSame(150, (int) $t['kisi']);
        $this->assertEqualsWithDelta(360.0, (float) $t['birim_fiyat'], 0.001);
        $this->assertSame('Gebze', $t['ilce']);
        $this->assertSame('benmari, çelik masa', $t['ekipman']);
        $this->assertSame(['ogle' => 250, 'aksam' => 250], json_decode((string) $t['fiyat_json'], true));
        $this->assertSame('Güncel giriş metni.', $t['giris_metni']);
        $this->assertSame('taslak', $t['durum'], 'durum updateTeklif ile değişmez (whitelist dışı)');

        $this->assertNull($this->repo->teklifById(99999));
        $this->assertFalse($this->repo->updateTeklif(99999, ['firma' => 'Yok']));
        $this->assertFalse($this->repo->updateTeklif($id, ['bilinmeyen' => 'x']), 'bilinen kolon yoksa false');
    }

    // ── fable-005: TeklifPdf geçerli PDF üretir (dolu + boş alanlarla) ──
    public function testTeklifPdfRenders(): void
    {
        $id = $this->repo->createTeklif('Şahinler Tekstil Sanayi A.Ş.', 100, null, 'Servis dahil', [
            'yetkili'       => 'Ömer Bey',
            'telefon'       => '0532',
            'email'         => 'a@b.com',
            'sehir'         => 'Kocaeli',
            'ilce'          => 'Gebze',
            'cumartesi'     => 1,
            'ogun_sayisi'   => 2,
            'segment'       => 'premium',
            'menu_json'     => json_encode(['corba' => 1, 'ana' => 1, 'yan' => 1, 'salata' => 4, 'tatli' => 1, 'icecek' => 'donusumlu', 'ekmek' => 1]),
            'personel_json' => json_encode(['asci' => 2, 'servis' => 3, 'temizlik' => 1]),
            'ekipman'       => 'benmari, çelik masa',
            // fable-007: öğün fiyat cetveli + kuruma özel giriş metni
            'fiyat_json'    => json_encode(['kahvalti' => 135, 'ogle' => 235, 'aksam' => 235, 'ara' => 55]),
            'giris_metni'   => 'Kurumunuzun personeline yerinde üretim ile üç öğün hizmet sunmak isteriz.',
        ]);
        $full = \Uysa\TeklifPdf::render($this->repo->teklifById($id));
        $this->assertStringStartsWith('%PDF-', $full);

        // Minimum (sadece firma + tek fiyat, fiyat_json yok) — birim_fiyat tek satıra düşer.
        $id2 = $this->repo->createTeklif('Boş Firma', null, 320.0, null);
        $bin = \Uysa\TeklifPdf::render($this->repo->teklifById($id2));
        $this->assertStringStartsWith('%PDF-', $bin);

        // Hiç fiyat yok (sadece firma) da patlamamalı.
        $id3 = $this->repo->createTeklif('Fiyatsız Firma', null, null, null);
        $this->assertStringStartsWith('%PDF-', \Uysa\TeklifPdf::render($this->repo->teklifById($id3)));

        // Tamamen boş dizi (defansif) da patlamamalı.
        $this->assertStringStartsWith('%PDF-', \Uysa\TeklifPdf::render([]));
    }

    // ── Teslimat ───────────────────────────────────────────────
    public function testDeliveriesFollowProductionAndStatusUpsert(): void
    {
        $a = seed_customer($this->pdo, 'BOMİ', 300.0);
        $b = seed_customer($this->pdo, 'CANTAŞ', 328.0);
        $d = date('Y-m-d');
        $ins = $this->pdo->prepare(
            "INSERT INTO production (customer_id, prod_date, meal, persons, entered_by) VALUES (?, ?, ?, ?, 'uysa')"
        );
        $ins->execute([$a, $d, 'ogle', 75]);
        $ins->execute([$a, $d, 'aksam', 25]);
        $ins->execute([$b, $d, 'ogle', 70]);

        $rows = $this->repo->deliveriesForDate($d);
        $this->assertCount(2, $rows, 'üretimi olan 2 müşteri');
        $bomi = $rows[array_search('BOMİ', array_column($rows, 'name'), true)];
        $this->assertSame(100, $bomi['persons'], 'öğünler toplanır');
        $this->assertSame('bekliyor', $bomi['status'], 'varsayılan bekliyor');

        $this->repo->setDeliveryStatus($d, $a, 'yolda');
        $this->repo->setDeliveryStatus($d, $a, 'teslim'); // upsert — aynı gün ikinci yazım
        $rows = $this->repo->deliveriesForDate($d);
        $bomi = $rows[array_search('BOMİ', array_column($rows, 'name'), true)];
        $this->assertSame('teslim', $bomi['status']);

        $this->expectException(InvalidArgumentException::class);
        $this->repo->setDeliveryStatus($d, $a, 'ucup-gitti');
    }

    // ── Tedarikçi geçmişi ──────────────────────────────────────
    public function testSupplierHistoryMergesStockAndExpenses(): void
    {
        $sid = $this->repo->upsertSupplier('X Gıda');
        $iid = $this->seedIngredient('Pirinç');
        $this->repo->addStockMove($iid, '2026-07-10', 'giris', 25, null, null, null, $sid);
        $this->pdo->prepare(
            "INSERT INTO transactions (type, tx_date, amount, supplier_id, description) VALUES ('gider','2026-07-11',1500,?, 'Kuru gıda')"
        )->execute([$sid]);

        $hist = $this->repo->supplierHistory($sid);
        $this->assertCount(2, $hist, 'stok girişi + gider birleşik');
        $this->assertSame('Kuru gıda', $hist[0]['what'], 'yeni tarih önce');
        $this->assertSame('Pirinç', $hist[1]['what']);
    }

    // ── Denetim izi ────────────────────────────────────────────
    public function testAuditRecentSearch(): void
    {
        uysa_audit('teklif_yeni', 'uysal', '1', 'ABC', '1.2.3.4');
        uysa_audit('stok_hareket', 'azim', '2', null, '1.2.3.4');
        $this->assertCount(2, $this->repo->auditRecent(10));
        $found = $this->repo->auditRecent(10, 'teklif');
        $this->assertCount(1, $found);
        $this->assertSame('teklif_yeni', $found[0]['action']);
    }
}
