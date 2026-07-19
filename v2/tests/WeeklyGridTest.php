<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * fable-020 — customerWeeklyGrid: haftalık kırılım + eksik gün görünürlüğü.
 * Temmuz 2026 servis günleri (Pzt–Cmt, pazar atlanır):
 *   W27: 1,2,3,4 · W28: 6-11 · W29: 13-18 · W30: 20-25 · W31: 27-31 → 27 gün.
 */
final class WeeklyGridTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    /** Tüm haftalardaki günleri düz listeye indir (tarih → satır). */
    private function flatDays(array $grid): array
    {
        $out = [];
        foreach ($grid['weeks'] as $wk) {
            foreach ($wk['days'] as $d) {
                $out[$d['gun']] = $d;
            }
        }
        return $out;
    }

    // ── (a) Kırılım tutarlılığı: hafta = Σgün, ay = Σhafta ─────
    public function testWeekAndMonthSubtotalsConsistent(): void
    {
        $a = seed_customer($this->pdo, 'CANTAŞ', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 10, 1.0, 'ogle'); // W27
        $this->repo->upsertProduction($a, '2026-07-02', 20, 1.0, 'ogle'); // W27
        $this->repo->upsertProduction($a, '2026-07-06', 30, 1.0, 'ogle'); // W28
        $this->repo->upsertProduction($a, '2026-07-13', 40, 1.0, 'ogle'); // W29

        $grid = $this->repo->customerWeeklyGrid($a, '2026-07', '2026-08-01');

        $this->assertCount(5, $grid['weeks'], 'Temmuz 5 ISO hafta (W27-W31)');
        $flat = $this->flatDays($grid);
        $this->assertCount(27, $flat, 'Pzt-Cmt servis günleri = 27');

        $weekKisiSum = 0; $weekTutarSum = 0.0;
        foreach ($grid['weeks'] as $wk) {
            $dayKisi = array_sum(array_column($wk['days'], 'kisi'));
            $dayTutar = array_sum(array_column($wk['days'], 'tutar'));
            $this->assertSame($dayKisi, $wk['kisi'], 'hafta kişi = günlerin toplamı');
            $this->assertEqualsWithDelta($dayTutar, $wk['tutar'], 0.001, 'hafta tutar = günlerin toplamı');
            $weekKisiSum += $wk['kisi'];
            $weekTutarSum += $wk['tutar'];
        }
        $this->assertSame(100, $grid['kisi'], 'ay toplam 10+20+30+40');
        $this->assertSame($weekKisiSum, $grid['kisi'], 'ay kişi = haftaların toplamı');
        $this->assertEqualsWithDelta($weekTutarSum, $grid['tutar'], 0.001, 'ay tutar = haftaların toplamı');

        // Aynı verinin customerDailyGrid toplamıyla senkronu
        $daily = $this->repo->customerDailyGrid($a, '2026-07');
        $this->assertSame((int) array_sum(array_column($daily, 'kisi')), $grid['kisi'], 'weekly toplam = daily toplam');
    }

    // ── (b) Kayıtsız geçmiş servis günü "eksik" işaretli ──────
    public function testMissingPastServiceDayFlagged(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 10, 1.0, 'ogle'); // Çar kayıtlı
        // 2026-07-02 (Per) kayıtsız, geçmiş → eksik

        $grid = $this->repo->customerWeeklyGrid($a, '2026-07', '2026-08-01');
        $flat = $this->flatDays($grid);

        $this->assertFalse($flat['2026-07-01']['missing'], 'kayıtlı gün eksik değil');
        $this->assertTrue($flat['2026-07-01']['recorded']);
        $this->assertTrue($flat['2026-07-02']['missing'], 'kayıtsız geçmiş gün eksik');
        $this->assertFalse($flat['2026-07-02']['recorded']);
        $this->assertSame('2026-07-02', $grid['first_missing'], 'ilk eksik = anchor');
        $this->assertGreaterThan(0, $grid['missing']);
    }

    // ── (c) Pazar satırı yok ──────────────────────────────────
    public function testNoSundayRows(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        $this->repo->upsertProduction($a, '2026-07-06', 10, 1.0, 'ogle');

        $grid = $this->repo->customerWeeklyGrid($a, '2026-07', '2026-08-01');
        $flat = $this->flatDays($grid);

        foreach ($flat as $date => $row) {
            $this->assertNotSame(7, $row['dow'], "pazar satırı olmamalı: $date");
        }
        // Temmuz 2026 pazarları
        foreach (['2026-07-05', '2026-07-12', '2026-07-19', '2026-07-26'] as $sun) {
            $this->assertArrayNotHasKey($sun, $flat, "pazar $sun tabloda yok");
        }
    }

    // ── (d) Gelecek gün eksik sayılmaz (bugün ve sonrası nötr) ─
    public function testFutureDayNotMissing(): void
    {
        $a = seed_customer($this->pdo, 'A', 1.0);
        // Hiç kayıt yok ama grid boş değil; bugün = 14 Tem
        $this->repo->upsertProduction($a, '2026-07-06', 5, 1.0, 'ogle');

        $grid = $this->repo->customerWeeklyGrid($a, '2026-07', '2026-07-14');
        $flat = $this->flatDays($grid);

        $this->assertTrue($flat['2026-07-13']['missing'], 'geçmiş kayıtsız gün eksik');
        $this->assertFalse($flat['2026-07-14']['missing'], 'bugün nötr, eksik değil');
        $this->assertTrue($flat['2026-07-14']['future'], 'bugün ve sonrası future bayrağı');
        $this->assertFalse($flat['2026-07-20']['missing'], 'gelecek gün eksik değil');
        $this->assertTrue($flat['2026-07-20']['future']);
    }

    // ── (e) Cmt kuralı: Cmt kaydı yoksa cumartesi nötr ────────
    public function testSaturdayNeutralWhenNoSaturdayRecord(): void
    {
        // Sadece hafta içi çalışan müşteri: hiç Cmt kaydı yok
        $a = seed_customer($this->pdo, 'HAFTAICI', 1.0);
        $this->repo->upsertProduction($a, '2026-07-01', 10, 1.0, 'ogle'); // Çar (hafta içi)

        $grid = $this->repo->customerWeeklyGrid($a, '2026-07', '2026-08-01');
        $flat = $this->flatDays($grid);

        $this->assertFalse($grid['has_saturday_record'], 'hiç Cmt kaydı yok');
        // 04 Tem Cmt geçmiş ve kayıtsız ama nötr (eksik değil)
        $this->assertSame(6, $flat['2026-07-04']['dow']);
        $this->assertFalse($flat['2026-07-04']['missing'], 'Cmt kaydı olmayan müşteride cumartesi nötr');
        // hafta içi kayıtsız gün yine eksik (kural sadece Cmt'ye)
        $this->assertTrue($flat['2026-07-02']['missing'], 'hafta içi kayıtsız gün eksik kalır');
    }

    // ── (e2) Cmt kaydı varsa diğer cumartesiler eksik işaretlenir ─
    public function testSaturdayMissingWhenHasSaturdayRecord(): void
    {
        $a = seed_customer($this->pdo, 'CMTVAR', 1.0);
        $this->repo->upsertProduction($a, '2026-07-11', 50, 1.0, 'ogle'); // 11 Tem Cmt kayıtlı

        $grid = $this->repo->customerWeeklyGrid($a, '2026-07', '2026-08-01');
        $flat = $this->flatDays($grid);

        $this->assertTrue($grid['has_saturday_record'], 'en az bir Cmt kaydı var');
        $this->assertFalse($flat['2026-07-11']['missing'], 'kayıtlı Cmt eksik değil');
        $this->assertTrue($flat['2026-07-04']['missing'], 'Cmt kaydı olan müşteride kayıtsız Cmt eksik');
        $this->assertTrue($flat['2026-07-18']['missing'], 'diğer kayıtsız Cmt de eksik');
    }
}
