<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/** opus-008 — Reçete & Maliyet (porsiyon maliyeti) + Stok Durumu (giriş/çıkış, kritik eşik). */
final class RecipeStockTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    private function seedIngredient(string $name, float $price, string $unit = 'kg', float $minStok = 0.0): int
    {
        $this->pdo->prepare('INSERT INTO ingredients (name, unit, price_per_unit, min_stok) VALUES (?, ?, ?, ?)')
            ->execute([$name, $unit, $price, $minStok]);
        return (int) $this->pdo->lastInsertId();
    }

    // ── Reçete & Maliyet ──────────────────────────────────────
    public function testRecipeCostFromGramsAndPrice(): void
    {
        $et = $this->seedIngredient('Kıyma', 320.0);      // 320 TL/kg
        $pirinc = $this->seedIngredient('Pirinç', 40.0);  // 40 TL/kg
        $rid = $this->repo->upsertRecipe('Etli Pilav', 'ana');
        $this->repo->upsertRecipeItem($rid, $et, 150.0);    // 150g × 320/1000 = 48
        $this->repo->upsertRecipeItem($rid, $pirinc, 100.0); // 100g × 40/1000  = 4

        $this->assertEqualsWithDelta(52.0, $this->repo->recipeCost($rid), 0.001, '48 + 4');

        // listRecipes tek sorguda aynı maliyeti verir
        $list = $this->repo->listRecipes();
        $this->assertCount(1, $list);
        $this->assertSame('Etli Pilav', $list[0]['name']);
        $this->assertSame(2, (int) $list[0]['item_count']);
        $this->assertEqualsWithDelta(52.0, (float) $list[0]['cost'], 0.001);
    }

    public function testIngredientPriceUpdateChangesRecipeCost(): void
    {
        $et = $this->seedIngredient('Kıyma', 320.0);
        $rid = $this->repo->upsertRecipe('Köfte', 'ana');
        $this->repo->upsertRecipeItem($rid, $et, 200.0);       // 200g × 320/1000 = 64
        $this->assertEqualsWithDelta(64.0, $this->repo->recipeCost($rid), 0.001);

        $this->repo->upsertIngredientPrice($et, 400.0);        // fiyat güncelle
        $this->assertEqualsWithDelta(80.0, $this->repo->recipeCost($rid), 0.001, '200g × 400/1000');
    }

    public function testUpsertRecipeItemUpdatesGramsNoDuplicate(): void
    {
        $et = $this->seedIngredient('Kıyma', 320.0);
        $rid = $this->repo->upsertRecipe('Köfte');
        $this->repo->upsertRecipeItem($rid, $et, 150.0);
        $this->repo->upsertRecipeItem($rid, $et, 250.0); // aynı malzeme → gramaj güncelle

        $items = $this->repo->recipeItems($rid);
        $this->assertCount(1, $items, 'aynı malzeme çift satır olmaz');
        $this->assertEqualsWithDelta(250.0, (float) $items[0]['grams'], 0.001);
        $this->assertEqualsWithDelta(80.0, $this->repo->recipeCost($rid), 0.001, '250g × 320/1000');
    }

    public function testDeleteRecipeItemScoped(): void
    {
        $et = $this->seedIngredient('Kıyma', 320.0);
        $r1 = $this->repo->upsertRecipe('Köfte');
        $r2 = $this->repo->upsertRecipe('Sulu Yemek');
        $this->repo->upsertRecipeItem($r1, $et, 100.0);
        $this->repo->upsertRecipeItem($r2, $et, 100.0);
        $item = $this->repo->recipeItems($r1)[0];

        // Yanlış reçete id ile silme etkisiz (scope koruması)
        $this->repo->deleteRecipeItem((int) $item['item_id'], $r2);
        $this->assertCount(1, $this->repo->recipeItems($r1));
        // Doğru reçete id ile siler
        $this->repo->deleteRecipeItem((int) $item['item_id'], $r1);
        $this->assertCount(0, $this->repo->recipeItems($r1));
        $this->assertCount(1, $this->repo->recipeItems($r2), 'diğer reçete etkilenmez');
    }

    public function testMenuDayCostSumsRecipes(): void
    {
        $et = $this->seedIngredient('Kıyma', 320.0);
        $r1 = $this->repo->upsertRecipe('Köfte');
        $r2 = $this->repo->upsertRecipe('Çorba');
        $this->repo->upsertRecipeItem($r1, $et, 200.0); // 64
        $this->repo->upsertRecipeItem($r2, $et, 50.0);  // 16
        $this->pdo->prepare("INSERT INTO menu_days (menu_date, meal, recipe_id) VALUES (?, 'ogle', ?)")->execute(['2026-07-05', $r1]);
        $this->pdo->prepare("INSERT INTO menu_days (menu_date, meal, recipe_id) VALUES (?, 'ogle', ?)")->execute(['2026-07-05', $r2]);

        $rows = $this->repo->menuDayCost('2026-07-05', 'ogle');
        $this->assertCount(2, $rows);
        $total = array_sum(array_map(static fn($r) => (float) $r['cost'], $rows));
        $this->assertEqualsWithDelta(80.0, $total, 0.001, '64 + 16');
    }

    // ── Stok Durumu ───────────────────────────────────────────
    public function testStockLevelFromMoves(): void
    {
        $x = $this->seedIngredient('Domates', 25.0);
        $this->repo->addStockMove($x, '2026-07-01', 'giris', 100.0);
        $this->repo->addStockMove($x, '2026-07-02', 'cikis', 30.0);
        $this->repo->addStockMove($x, '2026-07-03', 'giris', 10.0);
        $this->assertEqualsWithDelta(80.0, $this->repo->stockLevel($x), 0.001, '100 - 30 + 10');

        $levels = $this->repo->stockLevels();
        $this->assertCount(1, $levels);
        $this->assertEqualsWithDelta(80.0, (float) $levels[0]['stok'], 0.001);
    }

    public function testAddStockMoveUsesIngredientUnitWhenNull(): void
    {
        $x = $this->seedIngredient('Süt', 30.0, 'lt');
        $id = $this->repo->addStockMove($x, '2026-07-01', 'giris', 20.0); // unit null
        $unit = $this->pdo->query('SELECT unit FROM stock_moves WHERE id = ' . $id)->fetchColumn();
        $this->assertSame('lt', $unit, 'birim malzemeden alınır');
    }

    public function testAddStockMoveRejectsBadDirection(): void
    {
        $x = $this->seedIngredient('Un', 20.0);
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->addStockMove($x, '2026-07-01', 'transfer', 5.0);
    }

    public function testCriticalStockDetection(): void
    {
        $az = $this->seedIngredient('Tuz', 15.0, 'kg', 5.0);   // eşik 5
        $bol = $this->seedIngredient('Şeker', 40.0, 'kg', 5.0); // eşik 5
        $sez = $this->seedIngredient('Baharat', 200.0, 'kg', 0.0); // eşik yok

        $this->repo->addStockMove($az, '2026-07-01', 'giris', 3.0);   // 3 < 5 → kritik
        $this->repo->addStockMove($bol, '2026-07-01', 'giris', 20.0); // 20 > 5 → değil
        $this->repo->addStockMove($sez, '2026-07-01', 'giris', 0.0);  // eşik 0 → asla kritik

        $crit = $this->repo->criticalStock();
        $this->assertCount(1, $crit, 'sadece Tuz kritik');
        $this->assertSame('Tuz', $crit[0]['name']);
        $this->assertEqualsWithDelta(3.0, (float) $crit[0]['stok'], 0.001);
    }

    public function testSetIngredientMinStock(): void
    {
        $x = $this->seedIngredient('Yağ', 60.0);
        $this->repo->addStockMove($x, '2026-07-01', 'giris', 2.0);
        $this->assertCount(0, $this->repo->criticalStock(), 'eşik 0 → kritik değil');

        $this->repo->setIngredientMinStock($x, 10.0); // eşik ayarla
        $crit = $this->repo->criticalStock();
        $this->assertCount(1, $crit, 'eşik 10, stok 2 → kritik');
        $this->assertSame('Yağ', $crit[0]['name']);
    }
}
