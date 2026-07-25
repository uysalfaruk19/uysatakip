<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * fable-046 — Arayüz cilası: inline detay verisi + Paraşüt bakiye cache senkronu.
 *
 * İki yük taşıyan iddia burada kanıtlanır:
 *  1) borclarimDetayTumu() (tek geçiş) ile borclarimDetay()/borclarimListe() (tedarikçi başına
 *     tam tarama) BİREBİR aynı rakamı verir — inline açılır satırlar yanlış tutar göstermez.
 *  2) parasutBakiyeSenkron() SALT-OKUMA cache'i doğru doldurur ve bakiye gelmeyen müşterinin
 *     mevcut cache'ini EZMEZ (0 uydurmaz).
 */
final class ArayuzCilasiTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    private function seedSupplier(string $name): int
    {
        $this->pdo->prepare('INSERT INTO suppliers (name) VALUES (?)')->execute([$name]);
        return (int) $this->pdo->lastInsertId();
    }

    private function giderElle(int $supplierId, string $date, float $amount, string $category = 'Gıda'): void
    {
        $this->pdo->prepare(
            "INSERT INTO transactions (type, category, tx_date, amount, supplier_id, source, description)
             VALUES ('gider', ?, ?, ?, ?, 'manuel', ?)"
        )->execute([$category, $date, $amount, $supplierId, 'Elle fatura']);
    }

    private function musteri(string $ad, ?string $parasutId = null, ?float $bakiye = null): int
    {
        $this->pdo->prepare('INSERT INTO customers (name, unit_price) VALUES (?, ?)')->execute([$ad, 100.0]);
        $id = (int) $this->pdo->lastInsertId();
        if ($parasutId !== null) {
            $this->repo->setParasutInfo($id, $parasutId, $bakiye ?? 0.0, '2026-07-01 08:00:00');
        }
        return $id;
    }

    // ── 1) Tek geçiş detayı = tek tek detay (tutar/adet/ödeme/devir birebir) ──
    public function testDetayTumuTekTekDetayIleBirebir(): void
    {
        $m = $this->seedSupplier('METRO');
        $this->giderElle($m, '2026-05-12', 18400.0);
        $this->giderElle($m, '2026-07-03', 15750.0);
        $b = $this->seedSupplier('BOMİ');
        $this->giderElle($b, '2026-06-20', 9800.0);
        $mKey = Repo::normTedarikci('METRO');
        $bKey = Repo::normTedarikci('BOMİ');
        $this->repo->tedarikciOdemeEkle($mKey, '2026-07-15', 20000.0, 'havale');
        $this->repo->tedarikciOdemeEkle($mKey, '2026-07-20', -5000.0, 'düzeltme');
        $this->repo->tedarikciDevirKaydet($bKey, 'BOMİ', 2500.0);

        $tumu = $this->repo->borclarimDetayTumu();
        $this->assertArrayHasKey($mKey, $tumu);
        $this->assertArrayHasKey($bKey, $tumu);

        foreach ([$mKey, $bKey] as $key) {
            $tek = $this->repo->borclarimDetay($key);
            foreach (['fatura', 'adet', 'odenen', 'devir', 'kalan'] as $alan) {
                $this->assertEqualsWithDelta((float) $tek[$alan], (float) $tumu[$key][$alan], 0.001, "$key.$alan");
            }
            $this->assertCount(count($tek['faturalar']), $tumu[$key]['faturalar'], "$key fatura adedi");
            $this->assertCount(count($tek['odemeler']), $tumu[$key]['odemeler'], "$key ödeme adedi");
        }
    }

    // ── 2) Liste toplamları = tek geçiş toplamları (ekrandaki özet kartlar) ──
    public function testDetayTumuToplamlariListeyleAyni(): void
    {
        $m = $this->seedSupplier('METRO');
        $this->giderElle($m, '2026-07-03', 15750.0);
        $o = $this->seedSupplier('ÖRS ET');
        $this->giderElle($o, '2026-07-08', 31200.0);
        $this->repo->tedarikciOdemeEkle(Repo::normTedarikci('METRO'), '2026-07-15', 5000.0);
        $this->repo->tedarikciDevirKaydet(Repo::normTedarikci('ÖRS ET'), 'ÖRS ET', 1000.0);

        $liste = $this->repo->borclarimListe();
        $tumu = $this->repo->borclarimDetayTumu();
        $this->assertCount(count($liste), $tumu, 'tedarikçi sayısı aynı');

        $topL = ['kalan' => 0.0, 'odenen' => 0.0, 'toplam' => 0.0];
        foreach ($liste as $r) {
            $topL['kalan'] += $r['kalan'];
            $topL['odenen'] += $r['odenen'];
            $topL['toplam'] += $r['fatura'] + $r['devir'];
        }
        $topT = ['kalan' => 0.0, 'odenen' => 0.0, 'toplam' => 0.0];
        foreach ($tumu as $r) {
            $topT['kalan'] += $r['kalan'];
            $topT['odenen'] += $r['odenen'];
            $topT['toplam'] += $r['fatura'] + $r['devir'];
        }
        foreach (['kalan', 'odenen', 'toplam'] as $k) {
            $this->assertEqualsWithDelta($topL[$k], $topT[$k], 0.001, "toplam $k");
        }
    }

    // ── 3) Sıra: en çok borçlu üstte (liste ile aynı sıralama kuralı) ──
    public function testDetayTumuKalanaGoreSirali(): void
    {
        $a = $this->seedSupplier('AZ BORÇ');
        $this->giderElle($a, '2026-07-01', 100.0);
        $c = $this->seedSupplier('ÇOK BORÇ');
        $this->giderElle($c, '2026-07-01', 9000.0);

        $keys = array_keys($this->repo->borclarimDetayTumu());
        $this->assertSame(Repo::normTedarikci('ÇOK BORÇ'), $keys[0]);
        $this->assertSame(Repo::normTedarikci('AZ BORÇ'), $keys[1]);
    }

    // ── 4) Personel/Taşıma alış hariç kuralı tek geçişte de geçerli ──
    /** fable-049 (Ömer: "her fatura borçtur"): Taşıma alış artık borçta; yalnız Personel dışlanır. */
    public function testDetayTumuYalnizPersoneliDislar(): void
    {
        $p = $this->seedSupplier('MAAŞ');
        $this->giderElle($p, '2026-07-01', 5000.0, 'Personel');
        $t = $this->seedSupplier('TAŞIYICI');
        $this->giderElle($t, '2026-07-01', 3000.0, 'Taşıma alış');
        $g = $this->seedSupplier('METRO');
        $this->giderElle($g, '2026-07-01', 1000.0, 'Gıda');

        $tumu = $this->repo->borclarimDetayTumu();
        $this->assertArrayNotHasKey(Repo::normTedarikci('MAAŞ'), $tumu, 'Personel borç değil (bordro)');
        $this->assertArrayHasKey(Repo::normTedarikci('TAŞIYICI'), $tumu, 'fable-049: Taşıma alış BORÇTUR');
        $this->assertArrayHasKey(Repo::normTedarikci('METRO'), $tumu);
    }

    // ── 5) Senkron hedefi: parasut_id dolu AKTİF müşteriler ──
    public function testBakiyeSenkronHedefListesi(): void
    {
        $bagli = $this->musteri('CANTAŞ', '555', 1000.0);
        $this->musteri('BAĞSIZ');
        $pasif = $this->musteri('KAPANAN', '777', 500.0);
        $this->repo->setCustomerActive($pasif, false);

        $hedef = $this->repo->customersForParasutBakiye();
        $this->assertCount(1, $hedef);
        $this->assertSame($bagli, $hedef[0]['id']);
        $this->assertSame('555', $hedef[0]['parasut_id']);
    }

    // ── 6) Senkron cache'i yazar (SALT-OKUMA; Paraşüt'e yazma yok) ──
    public function testBakiyeSenkronCacheYazar(): void
    {
        $cid = $this->musteri('CANTAŞ', '555', 1000.0);
        $sonuc = $this->repo->parasutBakiyeSenkron(
            static fn(string $pid): array => ['parasut_id' => $pid, 'name' => 'CANTAŞ', 'balance' => 42500.75],
            '2026-07-25 14:05:00'
        );

        $this->assertSame(1, $sonuc['hedef']);
        $this->assertSame(1, $sonuc['guncel']);
        $this->assertSame(0, $sonuc['atlanan']);
        $c = $this->repo->customer($cid);
        $this->assertEqualsWithDelta(42500.75, (float) $c['parasut_bakiye'], 0.001);
        $this->assertSame('2026-07-25 14:05:00', (string) $c['parasut_sync_at']);
    }

    // ── 7) DÜRÜSTLÜK: bakiye gelmezse eski cache KORUNUR (0 uydurulmaz) ──
    public function testBakiyeGelmezseCacheKorunur(): void
    {
        $cid = $this->musteri('CANTAŞ', '555', 1000.0);
        $sonuc = $this->repo->parasutBakiyeSenkron(
            static fn(string $pid): ?array => null,
            '2026-07-25 14:05:00'
        );

        $this->assertSame(1, $sonuc['atlanan']);
        $this->assertSame(0, $sonuc['guncel']);
        $c = $this->repo->customer($cid);
        $this->assertEqualsWithDelta(1000.0, (float) $c['parasut_bakiye'], 0.001, 'eski bakiye ezilmedi');
        $this->assertSame('2026-07-01 08:00:00', (string) $c['parasut_sync_at'], 'senkron zamanı da ilerlemedi');
    }

    // ── 8) Bir müşterinin hatası diğerlerini durdurmaz (kısmi senkron) ──
    public function testBakiyeSenkronHataIzole(): void
    {
        $iyi = $this->musteri('AAA', '111', 0.0);
        $this->musteri('BBB', '222', 0.0);

        $sonuc = $this->repo->parasutBakiyeSenkron(static function (string $pid): array {
            if ($pid === '222') {
                throw new RuntimeException('429 rate limit');
            }
            return ['balance' => 777.0];
        }, '2026-07-25 15:00:00');

        $this->assertSame(2, $sonuc['hedef']);
        $this->assertSame(1, $sonuc['guncel']);
        $this->assertSame(1, $sonuc['hata']);
        $this->assertEqualsWithDelta(777.0, (float) $this->repo->customer($iyi)['parasut_bakiye'], 0.001);
    }

    // ── 9) Negatif bakiye (fazla ödeme) işaretiyle yazılır ──
    public function testNegatifBakiyeKorunur(): void
    {
        $cid = $this->musteri('CANTAŞ', '555', 0.0);
        $this->repo->parasutBakiyeSenkron(static fn(string $pid): array => ['balance' => -1250.40], '2026-07-25 16:00:00');
        $this->assertEqualsWithDelta(-1250.40, (float) $this->repo->customer($cid)['parasut_bakiye'], 0.001);
    }
}
