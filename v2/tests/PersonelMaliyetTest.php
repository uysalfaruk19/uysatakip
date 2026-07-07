<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * opus-014 — Personel gerçek işveren maliyeti (brüt+SGK+kıdem tahakkuk+diğer),
 * müşteriye dağıtım (açık=eşit / genel=hacme oranlı), biriken kıdem yükümlülüğü, ayar (KV).
 */
final class PersonelMaliyetTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    // ── Ayar (mevzuat KV) ─────────────────────────────────────
    public function testAyarDefaultsSeeded(): void
    {
        $this->assertEqualsWithDelta(0.225, $this->repo->ayarNum('sgk_isveren_orani'), 0.0001);
        $this->assertEqualsWithDelta(64948.77, $this->repo->ayarNum('kidem_tavan'), 0.001);
        $this->assertEqualsWithDelta(12.0, $this->repo->ayarNum('kidem_aylik_bolen'), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->repo->ayarNum('diger_maliyet_oran'), 0.001);
        $this->assertSame('7', $this->repo->ayar('yok_boyle', '7'), 'yoksa default döner');
    }

    public function testAyarSetUpsert(): void
    {
        $this->repo->ayarSet('sgk_isveren_orani', '0.175');
        $this->assertEqualsWithDelta(0.175, $this->repo->ayarNum('sgk_isveren_orani'), 0.0001);
        $this->repo->ayarSet('sgk_isveren_orani', '0.225'); // tekrar → tek satır güncellenir
        $this->assertEqualsWithDelta(0.225, $this->repo->ayarNum('sgk_isveren_orani'), 0.0001);
        $cnt = (int) $this->pdo->query("SELECT COUNT(*) FROM ayar WHERE anahtar='sgk_isveren_orani'")->fetchColumn();
        $this->assertSame(1, $cnt);
    }

    // ── Yüklü işveren maliyeti (bileşen breakdown) ────────────
    public function testYukluMaliyetBreakdown(): void
    {
        $pid = $this->repo->upsertPersonel('Ahmet', 'Aşçı', 40000.0);
        $y = $this->repo->personelYukluMaliyet($pid);
        $this->assertEqualsWithDelta(40000.0, $y['brut'], 0.001);
        $this->assertEqualsWithDelta(9000.0, $y['sgk_isveren'], 0.001, '40000 × 0.225');
        $this->assertEqualsWithDelta(3333.333, $y['kidem_aylik'], 0.01, '40000 / 12');
        $this->assertEqualsWithDelta(0.0, $y['diger'], 0.001, 'default oran 0');
        $this->assertEqualsWithDelta(52333.333, $y['yuklu_toplam'], 0.01);
        $this->assertFalse($y['tavan_uygulandi']);
    }

    public function testAylikCalismaGunuMaasiOranlar(): void
    {
        $pid = $this->repo->upsertPersonel('Izinli', 'Asci', 30000.0);

        $varsayilan = $this->repo->personelMaasAy($pid, '2026-06');
        $this->assertEqualsWithDelta(30.0, $varsayilan['calisma_gunu'], 0.001);
        $this->assertEqualsWithDelta(30000.0, $varsayilan['hesaplanan_maas'], 0.001);
        $this->assertFalse($varsayilan['maas_odendi']);

        $this->repo->setPersonelMaasAy($pid, '2026-06', 27.0, false);
        $maas = $this->repo->personelMaasAy($pid, '2026-06');
        $this->assertEqualsWithDelta(27.0, $maas['calisma_gunu'], 0.001);
        $this->assertEqualsWithDelta(3.0, $maas['eksik_gun'], 0.001);
        $this->assertEqualsWithDelta(27000.0, $maas['hesaplanan_maas'], 0.001, '30000 x 27/30');

        $y = $this->repo->personelYukluMaliyet($pid, '2026-06');
        $this->assertEqualsWithDelta(27000.0, $y['brut'], 0.001);
        $this->assertEqualsWithDelta(6075.0, $y['sgk_isveren'], 0.001, '27000 x 0.225');
        $this->assertEqualsWithDelta(2250.0, $y['kidem_aylik'], 0.001, '27000 / 12');
    }

    public function testMaasOdendiOtomatikGiderYazarVeGunceller(): void
    {
        $pid = $this->repo->upsertPersonel('Odenecek', 'Asci', 30000.0);

        $this->repo->setPersonelMaasAy($pid, '2026-06', 27.0, true, '2026-07-05');
        $this->assertEqualsWithDelta(27000.0, $this->repo->monthPersonelByType('2026-07')['maas'] ?? 0.0, 0.001);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM personel_gider')->fetchColumn());
        $this->assertStringContainsString('2026-06', (string) $this->pdo->query('SELECT aciklama FROM personel_gider')->fetchColumn());

        $this->repo->setPersonelMaasAy($pid, '2026-06', 24.0, true, '2026-07-06');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM personel_gider')->fetchColumn(), 'ayni otomatik gider guncellenir');
        $this->assertEqualsWithDelta(24000.0, (float) $this->pdo->query('SELECT tutar FROM personel_gider')->fetchColumn(), 0.001);

        $this->repo->setPersonelMaasAy($pid, '2026-06', 24.0, false);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM personel_gider')->fetchColumn(), 'odendi kalkarsa bagli otomatik gider silinir');
        $maas = $this->repo->personelMaasAy($pid, '2026-06');
        $this->assertFalse($maas['maas_odendi']);
        $this->assertNull($maas['gider_id']);
    }
    public function testKidemTavanKapi(): void
    {
        // brüt 80000 > tavan 64948.77 → kıdem = tavan/12
        $pid = $this->repo->upsertPersonel('Yüksek Maaş', 'Müdür', 80000.0);
        $y = $this->repo->personelYukluMaliyet($pid);
        $this->assertTrue($y['tavan_uygulandi']);
        $this->assertEqualsWithDelta(64948.77 / 12, $y['kidem_aylik'], 0.01, 'kıdem tavan kaplı');
    }

    public function testDigerMaliyetOverrideVeOran(): void
    {
        // override tutar
        $a = $this->repo->upsertPersonel('A', null, 30000.0, null, null, 2500.0);
        $this->assertEqualsWithDelta(2500.0, $this->repo->personelYukluMaliyet($a)['diger'], 0.001);
        // override yok → ayar oranından
        $this->repo->ayarSet('diger_maliyet_oran', '0.10');
        $b = $this->repo->upsertPersonel('B', null, 30000.0);
        $this->assertEqualsWithDelta(3000.0, $this->repo->personelYukluMaliyet($b)['diger'], 0.001, '30000 × 0.10');
    }

    public function testAyarDegisinceMaliyetGuncellenir(): void
    {
        $pid = $this->repo->upsertPersonel('Ahmet', 'Aşçı', 40000.0);
        $this->assertEqualsWithDelta(9000.0, $this->repo->personelYukluMaliyet($pid)['sgk_isveren'], 0.001);
        $this->repo->ayarSet('sgk_isveren_orani', '0.175'); // teşvikli
        $this->assertEqualsWithDelta(7000.0, $this->repo->personelYukluMaliyet($pid)['sgk_isveren'], 0.001, '40000 × 0.175');
    }

    // ── Biriken kıdem yükümlülüğü ─────────────────────────────
    public function testKidemBirikimAySayisi(): void
    {
        // ise_giris 2024-07-01, referans ay 2026-07 (son gün 2026-07-31) → 24 tam ay
        $pid = $this->repo->upsertPersonel('Kıdemli', 'Aşçı', 40000.0, null, '2024-07-01');
        $k = $this->repo->kidemBirikim($pid, '2026-07');
        $this->assertSame(24, $k['ay_sayisi']);
        $this->assertEqualsWithDelta(3333.333, $k['aylik'], 0.01);
        $this->assertEqualsWithDelta(24 * (40000.0 / 12), $k['birikim'], 0.1, 'ay_sayisi × aylık');
        $this->assertEqualsWithDelta(3333.333, $k['bu_ay_tahakkuk'], 0.01);
    }

    public function testKidemBirikimIseGirisYok(): void
    {
        $pid = $this->repo->upsertPersonel('Yeni', 'Aşçı', 40000.0); // ise_giris NULL
        $k = $this->repo->kidemBirikim($pid, '2026-07');
        $this->assertSame(0, $k['ay_sayisi']);
        $this->assertEqualsWithDelta(0.0, $k['birikim'], 0.001, 'ise_giris yok → birikim 0');
        $this->assertEqualsWithDelta(3333.333, $k['bu_ay_tahakkuk'], 0.01, 'bu ay tahakkuk yine görünür');
    }

    // ── Dağıtım ───────────────────────────────────────────────
    public function testDagitimAcikMusteriEsit(): void
    {
        $a = seed_customer($this->pdo, 'A', 300.0);
        $b = seed_customer($this->pdo, 'B', 300.0);
        $pid = $this->repo->upsertPersonel('Ortak', 'Aşçı', 40000.0); // yüklü 52333.33
        $this->repo->setPersonelAtama($pid, false, [$a, $b]);

        $d = $this->repo->personelDagitim('2026-07');
        $yuklu = $this->repo->personelYukluMaliyet($pid)['yuklu_toplam'];
        $this->assertEqualsWithDelta($yuklu / 2, $d['per_customer'][$a], 0.01, 'A %50');
        $this->assertEqualsWithDelta($yuklu / 2, $d['per_customer'][$b], 0.01, 'B %50');
        $this->assertEqualsWithDelta(0.0, $d['dagitilmamis'], 0.001);
        $this->assertEqualsWithDelta($yuklu, $d['toplam'], 0.01);
    }

    public function testDagitimGenelHacmeOranli(): void
    {
        // A 100 adet / B 300 adet → %25 / %75
        $a = seed_customer($this->pdo, 'A', 300.0);
        $b = seed_customer($this->pdo, 'B', 300.0);
        $this->repo->upsertProduction($a, '2026-07-01', 100, 300.0, 'ogle');
        $this->repo->upsertProduction($b, '2026-07-01', 300, 300.0, 'ogle');
        $pid = $this->repo->upsertPersonel('Genel', 'Aşçı', 40000.0);
        $this->repo->setPersonelAtama($pid, true);

        $d = $this->repo->personelDagitim('2026-07');
        $yuklu = $this->repo->personelYukluMaliyet($pid)['yuklu_toplam'];
        $this->assertEqualsWithDelta($yuklu * 0.25, $d['per_customer'][$a], 0.01, 'A hacim %25');
        $this->assertEqualsWithDelta($yuklu * 0.75, $d['per_customer'][$b], 0.01, 'B hacim %75');
        $this->assertEqualsWithDelta(0.0, $d['dagitilmamis'], 0.001);
    }

    public function testDagitimGenelHacimYokEsitDusum(): void
    {
        // genel ama o ay üretim yok → aktif üretim müşterilerine eşit
        $a = seed_customer($this->pdo, 'A', 300.0);
        $b = seed_customer($this->pdo, 'B', 300.0);
        $pid = $this->repo->upsertPersonel('Genel', 'Aşçı', 40000.0);
        $this->repo->setPersonelAtama($pid, true);
        $d = $this->repo->personelDagitim('2026-07');
        $yuklu = $this->repo->personelYukluMaliyet($pid)['yuklu_toplam'];
        $this->assertEqualsWithDelta($yuklu / 2, $d['per_customer'][$a], 0.01);
        $this->assertEqualsWithDelta($yuklu / 2, $d['per_customer'][$b], 0.01);
    }

    public function testDagitimAtamaYokDagitilmamis(): void
    {
        seed_customer($this->pdo, 'A', 300.0);
        $pid = $this->repo->upsertPersonel('Atamasız', 'Aşçı', 40000.0);
        $d = $this->repo->personelDagitim('2026-07');
        $yuklu = $this->repo->personelYukluMaliyet($pid)['yuklu_toplam'];
        $this->assertEqualsWithDelta($yuklu, $d['dagitilmamis'], 0.01, 'atama yok → dağıtılmamış');
        $this->assertSame([], $d['per_customer']);
    }

    public function testSetPersonelAtamaReplaces(): void
    {
        $a = seed_customer($this->pdo, 'A', 300.0);
        $b = seed_customer($this->pdo, 'B', 300.0);
        $pid = $this->repo->upsertPersonel('X', null, 40000.0);
        $this->repo->setPersonelAtama($pid, false, [$a, $b]);
        $this->assertSame([$a, $b], $this->repo->personelAtama($pid)['customer_ids']);
        // genel'e geç → önceki temizlenir
        $this->repo->setPersonelAtama($pid, true);
        $at = $this->repo->personelAtama($pid);
        $this->assertTrue($at['genel']);
        $this->assertSame([], $at['customer_ids']);
    }

    public function testKidemToplamYukumluluk(): void
    {
        $a = $this->repo->upsertPersonel('A', null, 40000.0, null, '2025-07-01'); // 12 ay @2026-07
        $b = $this->repo->upsertPersonel('B', null, 24000.0, null, '2026-01-01'); // 6 ay
        $t = $this->repo->kidemToplamYukumluluk('2026-07');
        $exp = 12 * (40000.0 / 12) + 6 * (24000.0 / 12); // 40000 + 12000 = 52000
        $this->assertEqualsWithDelta($exp, $t['birikim'], 0.5);
        $this->assertEqualsWithDelta((40000.0 / 12) + (24000.0 / 12), $t['bu_ay_tahakkuk'], 0.01);
    }

    public function testPersonelAvansAySadeceKisiTurVeAyToplar(): void
    {
        $pid = $this->repo->upsertPersonel('Avansli', 'Asci', 30000.0);
        $diger = $this->repo->upsertPersonel('Baska', 'Garson', 25000.0);

        $this->repo->addPersonelGider($pid, '2026-07-05', 'avans', 2000.0);
        $this->repo->addPersonelGider($pid, '2026-07-20', 'avans', 1500.0);
        $this->repo->addPersonelGider($pid, '2026-06-15', 'avans', 999.0);   // başka ay
        $this->repo->addPersonelGider($pid, '2026-07-10', 'prim', 500.0);    // başka tür
        $this->repo->addPersonelGider($diger, '2026-07-11', 'avans', 700.0); // başka kişi
        $this->repo->addPersonelGider(null, '2026-07-12', 'avans', 300.0);   // toplu (kişisiz)

        $this->assertEqualsWithDelta(3500.0, $this->repo->personelAvansAy($pid, '2026-07'), 0.001);
        $this->assertEqualsWithDelta(700.0, $this->repo->personelAvansAy($diger, '2026-07'), 0.001);
        $this->assertEqualsWithDelta(999.0, $this->repo->personelAvansAy($pid, '2026-06'), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->repo->personelAvansAy($pid, '2026-05'), 0.001);
    }
}
