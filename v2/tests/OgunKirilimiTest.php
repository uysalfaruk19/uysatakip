<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * fable-023a — Bugün ekranı öğün kırılımı (öğlen / akşam / kumanya).
 * dayGridAllMeals 3 öğünü toplar (akşam/kumanya artık ciroya girer), saveDayMeals atomik
 * yazar/siler, mealsFromTotal toplam kutusu kuralını uygular (fark öğlene).
 */
final class OgunKirilimiTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    // ── dayGridAllMeals ──────────────────────────────────────────
    public function testDayGridAllMealsSumsThreeMeals(): void
    {
        // Canlı senaryo: ERMETAL öğle 17 + akşam 5 (eski ekran sadece 17 görüyordu)
        $e = seed_customer($this->pdo, 'ERMETAL', 200.0);
        $this->repo->upsertProduction($e, '2026-07-20', 17, 200.0, 'ogle');
        $this->repo->upsertProduction($e, '2026-07-20', 5, 200.0, 'aksam');
        // Kırılımsız müşteri (eski davranış korunmalı)
        $o = seed_customer($this->pdo, 'OPAK', 250.0);
        $this->repo->upsertProduction($o, '2026-07-20', 40, 250.0, 'ogle');
        // Girilmemiş müşteri
        seed_customer($this->pdo, 'ÇAĞDAŞ ÖZTÜRK LTD.', 100.0);

        $grid = $this->repo->dayGridAllMeals('2026-07-20');
        $this->assertCount(3, $grid, 'aktif müşteri başına TEK satır');
        $by = [];
        foreach ($grid as $r) {
            $by[$r['name']] = $r;
        }
        $this->assertSame(17, $by['ERMETAL']['ogle']);
        $this->assertSame(5, $by['ERMETAL']['aksam']);
        $this->assertSame(0, $by['ERMETAL']['kumanya']);
        $this->assertSame(22, $by['ERMETAL']['toplam'], 'toplam = 17+5 (eski ekran 17 gösteriyordu)');
        $this->assertEqualsWithDelta(4400.0, $by['ERMETAL']['tutar'], 0.001, '22 × 200');

        $this->assertSame(40, $by['OPAK']['toplam'], 'kırılımsız müşteri eski gibi');
        $this->assertSame(40, $by['OPAK']['ogle']);
        $this->assertSame(0, $by['ÇAĞDAŞ ÖZTÜRK LTD.']['toplam'], 'girilmemiş müşteri 0');
        $this->assertEqualsWithDelta(0.0, $by['ÇAĞDAŞ ÖZTÜRK LTD.']['tutar'], 0.001);
    }

    public function testDayGridAllMealsIgnoresPassiveAndOtherDays(): void
    {
        $a = seed_customer($this->pdo, 'CANTAŞ', 328.0);
        $p = seed_customer($this->pdo, 'PASİF AŞ', 100.0);
        $this->repo->setCustomerActive($p, false);
        $this->repo->upsertProduction($a, '2026-07-20', 10, 328.0, 'ogle');
        $this->repo->upsertProduction($a, '2026-07-19', 99, 328.0, 'ogle'); // başka gün
        $this->repo->upsertProduction($p, '2026-07-20', 55, 100.0, 'ogle'); // pasif

        $grid = $this->repo->dayGridAllMeals('2026-07-20');
        $this->assertCount(1, $grid, 'pasif müşteri listede yok');
        $this->assertSame(10, $grid[0]['toplam'], 'yalnız o günün kaydı');
    }

    public function testDayGridAllMealsCountsUnlistedMealsInTotalOnly(): void
    {
        // 'sabah' ekranda kutusu olmayan öğün: toplama girer ama öğlen kutusunu şişirmez
        $a = seed_customer($this->pdo, 'CANTAŞ', 100.0);
        $this->repo->upsertProduction($a, '2026-07-20', 10, 100.0, 'ogle');
        $this->repo->upsertProduction($a, '2026-07-20', 3, 100.0, 'sabah');
        $g = $this->repo->dayGridAllMeals('2026-07-20')[0];
        $this->assertSame(10, $g['ogle']);
        $this->assertSame(13, $g['toplam']);
        $this->assertEqualsWithDelta(1300.0, $g['tutar'], 0.001);
    }

    // ── saveDayMeals: yaz / oku / sıfırı sil ─────────────────────
    public function testSaveDayMealsWritesAndDeletesZeroMeals(): void
    {
        $e = seed_customer($this->pdo, 'ERMETAL', 200.0);
        $this->repo->saveDayMeals($e, '2026-07-20', ['ogle' => 12, 'aksam' => 5, 'kumanya' => 3], 200.0);
        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM production')->fetchColumn());
        $this->assertSame(
            ['ogle' => 12, 'aksam' => 5, 'kumanya' => 3],
            $this->repo->customerDayMeals($e, '2026-07-20')
        );

        // Kumanya 0'lanınca satırı SİLİNİR (hayalet kayıt kalmaz)
        $w = $this->repo->saveDayMeals($e, '2026-07-20', ['ogle' => 12, 'aksam' => 5, 'kumanya' => 0], 200.0);
        $this->assertSame(17, $w['toplam']);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM production')->fetchColumn());
        $this->assertSame(0, $this->repo->customerDayMeals($e, '2026-07-20')['kumanya']);
        $this->assertSame(17, $this->repo->dayGridAllMeals('2026-07-20')[0]['toplam']);

        // Hepsi 0 → gün tamamen temizlenir
        $this->repo->saveDayMeals($e, '2026-07-20', ['ogle' => 0, 'aksam' => 0, 'kumanya' => 0], 0.0);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM production')->fetchColumn());
        $this->assertSame(0, $this->repo->dayGridAllMeals('2026-07-20')[0]['toplam']);
    }

    public function testSaveDayMealsIsIdempotent(): void
    {
        // Çift tık / çift POST: aynı değerler tekrar yazılınca satır çoğalmaz
        $e = seed_customer($this->pdo, 'ERMETAL', 200.0);
        $this->repo->saveDayMeals($e, '2026-07-20', ['ogle' => 12, 'aksam' => 5, 'kumanya' => 0], 200.0);
        $this->repo->saveDayMeals($e, '2026-07-20', ['ogle' => 12, 'aksam' => 5, 'kumanya' => 0], 200.0);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM production')->fetchColumn());
        $this->assertSame(17, $this->repo->dayGridAllMeals('2026-07-20')[0]['toplam']);
    }

    public function testSaveDayMealsAmountPerMealUsesSameUnitPrice(): void
    {
        $e = seed_customer($this->pdo, 'ERMETAL', 200.0);
        $this->repo->saveDayMeals($e, '2026-07-20', ['ogle' => 12, 'aksam' => 5, 'kumanya' => 0], 200.0);
        $rows = $this->pdo->query('SELECT meal, amount FROM production ORDER BY meal')->fetchAll();
        $amt = [];
        foreach ($rows as $r) {
            $amt[$r['meal']] = (float) $r['amount'];
        }
        $this->assertEqualsWithDelta(2400.0, $amt['ogle'], 0.001, '12 × 200');
        $this->assertEqualsWithDelta(1000.0, $amt['aksam'], 0.001, '5 × 200 (aynı birim fiyat)');
        $this->assertEqualsWithDelta(3400.0, $this->repo->dayGridAllMeals('2026-07-20')[0]['tutar'], 0.001);
    }

    public function testSaveDayMealsNormalizesBadInput(): void
    {
        $e = seed_customer($this->pdo, 'ERMETAL', 200.0);
        // Negatif / boş / metin / devasa girişte patlamaz
        $w = $this->repo->saveDayMeals(
            $e,
            '2026-07-20',
            ['ogle' => -5, 'aksam' => 0, 'kumanya' => Repo::MEAL_MAX + 999],
            200.0
        );
        $this->assertSame(0, $w['ogle'], 'negatif → 0');
        $this->assertSame(Repo::MEAL_MAX, $w['kumanya'], 'üst sınır uygulanır');
        $this->assertSame(Repo::MEAL_MAX, $w['toplam']);
    }

    public function testNormalizePersonsEdgeCases(): void
    {
        $this->assertSame(0, Repo::normalizePersons(null));
        $this->assertSame(0, Repo::normalizePersons(''));
        $this->assertSame(0, Repo::normalizePersons('abc'));
        $this->assertSame(0, Repo::normalizePersons(-1));
        $this->assertSame(17, Repo::normalizePersons('17'));
        $this->assertSame(Repo::MEAL_MAX, Repo::normalizePersons(99999999));
    }

    // ── Toplam kutusu kuralı: fark öğlene ────────────────────────
    public function testMealsFromTotalWritesDiffToOgle(): void
    {
        $cur = ['ogle' => 12, 'aksam' => 5, 'kumanya' => 0];
        $this->assertSame(
            ['ogle' => 15, 'aksam' => 5, 'kumanya' => 0],
            Repo::mealsFromTotal(20, $cur),
            'toplam 20 → öğlen 15, akşam korunur'
        );
        $this->assertSame(
            ['ogle' => 0, 'aksam' => 0, 'kumanya' => 0],
            Repo::mealsFromTotal(0, $cur),
            'toplam 0 → gün silinir'
        );
    }

    public function testMealsFromTotalWithoutBreakdownBehavesLikeBefore(): void
    {
        // Kırılım girilmemiş müşteri: eski davranış — tamamı öğlen
        $this->assertSame(
            ['ogle' => 40, 'aksam' => 0, 'kumanya' => 0],
            Repo::mealsFromTotal(40, ['ogle' => 0, 'aksam' => 0, 'kumanya' => 0])
        );
        $this->assertSame(
            ['ogle' => 0, 'aksam' => 0, 'kumanya' => 0],
            Repo::mealsFromTotal('', ['ogle' => 40, 'aksam' => 0, 'kumanya' => 0]),
            'boş kutu → sıfır'
        );
    }

    public function testMealsFromTotalBelowBreakdownTrimsKumanyaThenAksam(): void
    {
        // Toplam akşam+kumanya altına düşerse ekran yalan söylemesin: önce kumanya, sonra akşam kısılır
        $cur = ['ogle' => 12, 'aksam' => 5, 'kumanya' => 3];
        $this->assertSame(['ogle' => 0, 'aksam' => 5, 'kumanya' => 1], Repo::mealsFromTotal(6, $cur));
        $this->assertSame(['ogle' => 0, 'aksam' => 2, 'kumanya' => 0], Repo::mealsFromTotal(2, $cur));
        $this->assertSame(['ogle' => 0, 'aksam' => 0, 'kumanya' => 0], Repo::mealsFromTotal(-9, $cur));
    }

    public function testTotalEditKeepsOtherMealsEndToEnd(): void
    {
        $e = seed_customer($this->pdo, 'ERMETAL', 200.0);
        $this->repo->saveDayMeals($e, '2026-07-20', ['ogle' => 12, 'aksam' => 5, 'kumanya' => 0], 200.0);
        // Ekranda toplam kutusuna 25 yazıldı → öğlen 20, akşam 5 korunur
        $cur = $this->repo->customerDayMeals($e, '2026-07-20');
        $this->repo->saveDayMeals($e, '2026-07-20', Repo::mealsFromTotal(25, $cur), 200.0);
        $this->assertSame(
            ['ogle' => 20, 'aksam' => 5, 'kumanya' => 0],
            $this->repo->customerDayMeals($e, '2026-07-20')
        );
        $g = $this->repo->dayGridAllMeals('2026-07-20')[0];
        $this->assertSame(25, $g['toplam']);
        $this->assertEqualsWithDelta(5000.0, $g['tutar'], 0.001, '25 × 200');
    }

    // ── Sayaçlar / dünü kopyala ──────────────────────────────────
    public function testDayTotalsAcrossMealsMatchesGridSum(): void
    {
        // Üst sayaçlar (kişi/ciro) 3 öğünü toplar — dayGridAllMeals tek gerçek kaynak
        $e = seed_customer($this->pdo, 'ERMETAL', 200.0);
        $o = seed_customer($this->pdo, 'OPAK', 250.0);
        $this->repo->saveDayMeals($e, '2026-07-20', ['ogle' => 12, 'aksam' => 5, 'kumanya' => 0], 200.0);
        $this->repo->saveDayMeals($o, '2026-07-20', ['ogle' => 25, 'aksam' => 25, 'kumanya' => 8], 250.0);

        $kisi = 0;
        $tutar = 0.0;
        $filled = 0;
        foreach ($this->repo->dayGridAllMeals('2026-07-20') as $r) {
            $kisi += $r['toplam'];
            $tutar += $r['tutar'];
            if ($r['toplam'] > 0) {
                $filled++;
            }
        }
        $this->assertSame(75, $kisi, 'ERMETAL 17 + PENDORYA tarzı 58');
        $this->assertEqualsWithDelta(17 * 200.0 + 58 * 250.0, $tutar, 0.001);
        $this->assertSame(2, $filled);
        // Eski tek-öğün görünümü daha azdı → bu bir DÜZELTME
        $this->assertSame(37, (int) $this->repo->dayTotals('2026-07-20', 'ogle')['persons']);
    }

    public function testPreviousProductionDateAnyMealFindsEveningOnlyDay(): void
    {
        $e = seed_customer($this->pdo, 'ERMETAL', 200.0);
        $this->repo->upsertProduction($e, '2026-07-19', 5, 200.0, 'aksam'); // sadece akşam
        $this->assertNull(
            $this->repo->previousProductionDate('2026-07-20', 'ogle'),
            'öğün filtreli eski davranış korunur'
        );
        $this->assertSame(
            '2026-07-19',
            $this->repo->previousProductionDate('2026-07-20', null),
            'öğün gözetmeyen arama akşam-only günü bulur'
        );
    }

    public function testTurkishCustomerNameSurvivesRoundTrip(): void
    {
        $id = seed_customer($this->pdo, 'ŞIĞIRCI GIDA — ÖĞÜN A.Ş.', 123.45);
        $this->repo->saveDayMeals($id, '2026-07-20', ['ogle' => 1, 'aksam' => 2, 'kumanya' => 3], 123.45);
        $g = $this->repo->dayGridAllMeals('2026-07-20')[0];
        $this->assertSame('ŞIĞIRCI GIDA — ÖĞÜN A.Ş.', $g['name']);
        $this->assertSame(6, $g['toplam']);
    }
}
