<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * aksiyon-faz2 — ÖNERİ SAYISI (Bugün ekranı).
 * Son 4 haftanın aynı günü ortalaması; en az 3 veri noktası yoksa öneri YOK (uydurma yasak).
 */
final class OneriTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    /** Aynı gün (Çarşamba) için 4 hafta geriye kayıt yazar. */
    private function gecmis(int $cid, string $hedef, array $kisiler): void
    {
        foreach ($kisiler as $h => $kisi) {
            $g = date('Y-m-d', strtotime($hedef . ' -' . ($h + 1) . ' week'));
            $this->pdo->prepare(
                'INSERT INTO production (customer_id, prod_date, meal, persons, unit_price_snap, amount, entered_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$cid, $g, 'ogle', $kisi, 100.0, $kisi * 100.0, 'uysa']);
        }
    }

    public function testDortHaftaOrtalamasiOnerilir(): void
    {
        $cid = seed_customer($this->pdo, 'BOMİ', 265.0);
        $this->gecmis($cid, '2026-08-26', [75, 78, 72, 75]);

        $o = $this->repo->onerilenKisi($cid, '2026-08-26');
        $this->assertNotNull($o);
        $this->assertSame(75, $o['oneri'], '(75+78+72+75)/4 = 75');
        $this->assertSame(4, $o['nokta']);
    }

    public function testUcNoktadanAzVarsaOneriYok(): void
    {
        $cid = seed_customer($this->pdo, 'YENİ FİRMA', 200.0);
        $this->gecmis($cid, '2026-08-26', [40, 42]);   // yalnız 2 hafta

        $this->assertNull(
            $this->repo->onerilenKisi($cid, '2026-08-26'),
            'yeni/düzensiz müşteride yanlış sayı önermektense hiç önerme'
        );
    }

    public function testHicVeriYoksaOneriYok(): void
    {
        $cid = seed_customer($this->pdo, 'BOŞ', 200.0);
        $this->assertNull($this->repo->onerilenKisi($cid, '2026-08-26'));
    }

    public function testSifirKisiliGunVeriNoktasiSayilmaz(): void
    {
        $cid = seed_customer($this->pdo, 'TATİLCİ', 200.0);
        $this->gecmis($cid, '2026-08-26', [50, 0, 0, 54]);   // iki gün üretim yok

        $this->assertNull(
            $this->repo->onerilenKisi($cid, '2026-08-26'),
            '0 kişi girilen gün veri noktası değildir (2 gerçek nokta kaldı)'
        );
    }

    public function testOgunlerToplanarakOrtalamaAlinir(): void
    {
        $cid = seed_customer($this->pdo, 'PENDORYA', 255.0);
        // Aynı günde öğle + akşam + kumanya → gün toplamı 58
        foreach ([1, 2, 3] as $h) {
            $g = date('Y-m-d', strtotime('2026-08-26 -' . $h . ' week'));
            foreach (['ogle' => 25, 'aksam' => 25, 'kumanya' => 8] as $meal => $kisi) {
                $this->pdo->prepare(
                    'INSERT INTO production (customer_id, prod_date, meal, persons, unit_price_snap, amount, entered_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$cid, $g, $meal, $kisi, 255.0, $kisi * 255.0, 'uysa']);
            }
        }

        $o = $this->repo->onerilenKisi($cid, '2026-08-26');
        $this->assertNotNull($o);
        $this->assertSame(58, $o['oneri'], 'gün toplamı = 25+25+8');
        $this->assertSame(3, $o['nokta']);
    }

    public function testBaskaMusterininVerisiKarismaz(): void
    {
        $a = seed_customer($this->pdo, 'A FİRMA', 200.0);
        $b = seed_customer($this->pdo, 'B FİRMA', 200.0);
        $this->gecmis($a, '2026-08-26', [100, 100, 100, 100]);

        $this->assertNull($this->repo->onerilenKisi($b, '2026-08-26'), 'B firmasının kaydı yok');
        $this->assertSame(100, $this->repo->onerilenKisi($a, '2026-08-26')['oneri']);
    }
}
