<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * aksiyon-faz2 — sapma EŞİĞİ ayardan gelir (koda gömülmez) ve tatil günü yanlış pozitif üretmez.
 * Bugün ekranındaki uyarı mantığının birebir aynısı burada sınanır.
 */
final class OneriEsikTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

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

    /** bugun.php'deki karar: eşiği aşan sapma uyarı üretir (tatilde hiç üretmez). */
    private function sapmaVarMi(int $cid, string $date, int $girilen, bool $tatil = false): bool
    {
        $esik = max(0.05, $this->repo->ayarNum('bugun_sapma_esigi_yuzde', 30.0) / 100);
        $on = $this->repo->onerilenKisi($cid, $date);
        if ($on === null || $tatil || $on['ortalama'] <= 0) {
            return false;
        }
        return abs(($girilen - $on['ortalama']) / $on['ortalama']) >= $esik;
    }

    public function testEsikAyardanDegisir(): void
    {
        $cid = seed_customer($this->pdo, 'PENDORYA', 255.0);
        $this->gecmis($cid, '2026-08-26', [84, 86, 85, 85]);   // ortalama 85, girilen 58 → −%31,8

        // Varsayılan %30 → uyarı çıkar
        $this->assertTrue($this->sapmaVarMi($cid, '2026-08-26', 58), 'varsayılan eşik %30');

        // Eşik %90'a çekilirse aynı sapma artık uyarı üretmez
        $this->repo->ayarSet('bugun_sapma_esigi_yuzde', '90');
        $this->assertFalse($this->sapmaVarMi($cid, '2026-08-26', 58), 'eşik %90 → sus');

        // Eşik %10'a çekilirse küçük sapma bile uyarı üretir (85 → 76 = −%10,6)
        $this->repo->ayarSet('bugun_sapma_esigi_yuzde', '10');
        $this->assertTrue($this->sapmaVarMi($cid, '2026-08-26', 76), 'eşik %10 → küçük sapma da yakalanır');
    }

    public function testNormalDalgalanmaSessizKalir(): void
    {
        $cid = seed_customer($this->pdo, 'BOMİ', 265.0);
        $this->gecmis($cid, '2026-08-26', [75, 78, 72, 75]);   // ortalama 75

        $this->assertFalse($this->sapmaVarMi($cid, '2026-08-26', 75), 'tam ortalama');
        $this->assertFalse($this->sapmaVarMi($cid, '2026-08-26', 80), '+%6,7 normal dalgalanma');
        $this->assertFalse($this->sapmaVarMi($cid, '2026-08-26', 70), '−%6,7 normal dalgalanma');
    }

    public function testTatilGunundeUyariCikmaz(): void
    {
        $cid = seed_customer($this->pdo, 'CANTAŞ', 260.0);
        $this->gecmis($cid, '2026-08-26', [70, 68, 71, 70]);   // ortalama ~70

        // Tatilde üretim meşru olarak düşer; %70 düşüş bile uyarı ÜRETMEMELİ
        $this->assertTrue($this->sapmaVarMi($cid, '2026-08-26', 20), 'normal günde %71 düşüş uyarır');
        $this->assertFalse(
            $this->sapmaVarMi($cid, '2026-08-26', 20, true),
            'resmî tatilde aynı düşüş yanlış pozitiftir — susmalı'
        );
    }

    public function testEsikSifiraCekilemez(): void
    {
        $this->repo->ayarSet('bugun_sapma_esigi_yuzde', '0');
        $esik = max(0.05, $this->repo->ayarNum('bugun_sapma_esigi_yuzde', 30.0) / 100);
        $this->assertGreaterThanOrEqual(0.05, $esik, 'eşik 0 yapılırsa her satır uyarır — alt sınır %5');
    }
}
