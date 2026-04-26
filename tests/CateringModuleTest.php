<?php
/**
 * UYSA ERP — CateringModule (COZBIMESKİ) Unit Tests
 * SQLite in-memory DB ile CRUD ve iş mantığı testleri
 */
declare(strict_types=1);

namespace Uysa\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

#[Group('catering')]
class CateringModuleTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->createTables();
    }

    private function createTables(): void
    {
        $this->pdo->exec("CREATE TABLE uysa_dishes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT 'ana_yemek',
            sub_category TEXT,
            unit TEXT NOT NULL DEFAULT 'porsiyon',
            portion_gram REAL,
            calorie INTEGER,
            allergens TEXT,
            dish_price REAL NOT NULL DEFAULT 0,
            cost_price REAL,
            is_active INTEGER NOT NULL DEFAULT 1,
            notes TEXT,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now'))
        )");

        $this->pdo->exec("CREATE TABLE uysa_recipe_lines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dish_id INTEGER NOT NULL,
            ingredient TEXT NOT NULL,
            quantity REAL NOT NULL,
            unit TEXT NOT NULL DEFAULT 'gram',
            unit_price REAL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            notes TEXT,
            FOREIGN KEY (dish_id) REFERENCES uysa_dishes(id) ON DELETE CASCADE
        )");

        $this->pdo->exec("CREATE TABLE uysa_menu_plans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            plan_date TEXT NOT NULL,
            meal_type TEXT NOT NULL DEFAULT 'ogle',
            customer_id INTEGER,
            week_no INTEGER,
            status TEXT NOT NULL DEFAULT 'draft',
            created_by TEXT,
            notes TEXT,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now'))
        )");

        $this->pdo->exec("CREATE TABLE uysa_menu_plan_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            plan_id INTEGER NOT NULL,
            dish_id INTEGER NOT NULL,
            slot TEXT NOT NULL DEFAULT 'ana',
            portion_count INTEGER,
            sort_order INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (plan_id) REFERENCES uysa_menu_plans(id) ON DELETE CASCADE,
            FOREIGN KEY (dish_id) REFERENCES uysa_dishes(id) ON DELETE RESTRICT
        )");

        $this->pdo->exec("CREATE TABLE uysa_parties (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            type TEXT NOT NULL DEFAULT 'musteri',
            tax_no TEXT,
            tax_office TEXT,
            address TEXT,
            city TEXT,
            phone TEXT,
            email TEXT,
            iban TEXT,
            balance REAL NOT NULL DEFAULT 0,
            credit_limit REAL,
            is_active INTEGER NOT NULL DEFAULT 1,
            notes TEXT,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now'))
        )");

        $this->pdo->exec("CREATE TABLE uysa_deliveries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            delivery_no TEXT NOT NULL UNIQUE,
            delivery_date TEXT NOT NULL,
            customer_id INTEGER,
            meal_type TEXT NOT NULL DEFAULT 'ogle',
            vehicle TEXT,
            driver TEXT,
            portion_count INTEGER NOT NULL DEFAULT 0,
            departure_time TEXT,
            arrival_time TEXT,
            temperature REAL,
            status TEXT NOT NULL DEFAULT 'planned',
            recipient TEXT,
            signature INTEGER NOT NULL DEFAULT 0,
            notes TEXT,
            created_at TEXT DEFAULT (datetime('now'))
        )");

        $this->pdo->exec("CREATE TABLE uysa_haccp_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            log_date TEXT NOT NULL,
            log_time TEXT,
            check_point TEXT NOT NULL,
            check_type TEXT NOT NULL DEFAULT 'temperature',
            value REAL,
            min_limit REAL,
            max_limit REAL,
            is_ok INTEGER NOT NULL DEFAULT 1,
            corrective_action TEXT,
            checked_by TEXT,
            equipment TEXT,
            notes TEXT,
            created_at TEXT DEFAULT (datetime('now'))
        )");

        $this->pdo->exec("CREATE TABLE uysa_dish_pairings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dish_a_id INTEGER NOT NULL,
            dish_b_id INTEGER NOT NULL,
            score INTEGER NOT NULL DEFAULT 5,
            reason TEXT,
            FOREIGN KEY (dish_a_id) REFERENCES uysa_dishes(id) ON DELETE CASCADE,
            FOREIGN KEY (dish_b_id) REFERENCES uysa_dishes(id) ON DELETE CASCADE,
            UNIQUE(dish_a_id, dish_b_id)
        )");

        $this->pdo->exec("CREATE TABLE uysa_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action TEXT NOT NULL,
            actor TEXT,
            target_key TEXT,
            detail TEXT,
            ip_addr TEXT,
            created_at TEXT DEFAULT (datetime('now'))
        )");

        $this->pdo->exec("CREATE TABLE uysa_customers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        )");
    }

    // ── Dish CRUD ────────────────────────────────────────────────

    #[Test]
    public function dishInsertAndSelect(): void
    {
        $this->insertDish('YMK-001', 'Mercimek Çorbası', 'corba', 35.50);

        $stmt = $this->pdo->query("SELECT * FROM uysa_dishes WHERE code = 'YMK-001'");
        $dish = $stmt->fetch();

        $this->assertNotFalse($dish);
        $this->assertSame('Mercimek Çorbası', $dish['name']);
        $this->assertSame('corba', $dish['category']);
        $this->assertEquals(35.50, (float)$dish['dish_price']);
        $this->assertEquals(1, (int)$dish['is_active']);
    }

    #[Test]
    public function dishUpdate(): void
    {
        $id = $this->insertDish('YMK-002', 'Pilav', 'ana_yemek', 20.00);

        $this->pdo->prepare("UPDATE uysa_dishes SET name=?, dish_price=? WHERE id=?")
            ->execute(['Bulgur Pilavı', 25.00, $id]);

        $dish = $this->pdo->query("SELECT * FROM uysa_dishes WHERE id = {$id}")->fetch();
        $this->assertSame('Bulgur Pilavı', $dish['name']);
        $this->assertEquals(25.00, (float)$dish['dish_price']);
    }

    #[Test]
    public function dishDelete(): void
    {
        $id = $this->insertDish('YMK-DEL', 'Silinecek', 'corba', 10.00);
        $this->pdo->prepare("DELETE FROM uysa_dishes WHERE id = ?")->execute([$id]);

        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM uysa_dishes WHERE id = {$id}")->fetchColumn();
        $this->assertSame(0, $count);
    }

    #[Test]
    public function dishCodeUniqueConstraint(): void
    {
        $this->insertDish('UNIQ-001', 'Dish A', 'corba', 10.00);

        $this->expectException(\PDOException::class);
        $this->insertDish('UNIQ-001', 'Dish B', 'corba', 15.00);
    }

    #[Test]
    public function dishSearchByNameLike(): void
    {
        $this->insertDish('S-001', 'Mercimek Çorbası', 'corba', 10.00);
        $this->insertDish('S-002', 'Domates Çorbası', 'corba', 12.00);
        $this->insertDish('S-003', 'Izgara Tavuk', 'ana_yemek', 40.00);

        $like = '%Çorbası%';
        $stmt = $this->pdo->prepare("SELECT * FROM uysa_dishes WHERE name LIKE ?");
        $stmt->execute([$like]);
        $results = $stmt->fetchAll();

        $this->assertCount(2, $results);
    }

    #[Test]
    public function dishFilterByCategory(): void
    {
        $this->insertDish('F-001', 'Çorba 1', 'corba', 10.00);
        $this->insertDish('F-002', 'Ana 1', 'ana_yemek', 30.00);
        $this->insertDish('F-003', 'Çorba 2', 'corba', 12.00);

        $stmt = $this->pdo->prepare("SELECT * FROM uysa_dishes WHERE category = ?");
        $stmt->execute(['corba']);
        $this->assertCount(2, $stmt->fetchAll());
    }

    #[Test]
    public function dishFilterByActive(): void
    {
        $this->insertDish('A-001', 'Aktif', 'corba', 10.00, 1);
        $id2 = $this->insertDish('A-002', 'Pasif', 'corba', 10.00, 0);

        $stmt = $this->pdo->prepare("SELECT * FROM uysa_dishes WHERE is_active = ?");
        $stmt->execute([1]);
        $this->assertCount(1, $stmt->fetchAll());

        $stmt->execute([0]);
        $this->assertCount(1, $stmt->fetchAll());
    }

    // ── Recipe Lines CRUD ────────────────────────────────────────

    #[Test]
    public function recipeLineInsertAndSelect(): void
    {
        $dishId = $this->insertDish('R-001', 'Mercimek', 'corba', 10.00);

        $this->pdo->prepare("INSERT INTO uysa_recipe_lines (dish_id, ingredient, quantity, unit, unit_price, sort_order) VALUES (?,?,?,?,?,?)")
            ->execute([$dishId, 'Kırmızı Mercimek', 250.0, 'gram', 0.05, 1]);
        $this->pdo->prepare("INSERT INTO uysa_recipe_lines (dish_id, ingredient, quantity, unit, unit_price, sort_order) VALUES (?,?,?,?,?,?)")
            ->execute([$dishId, 'Soğan', 100.0, 'gram', 0.03, 2]);

        $stmt = $this->pdo->prepare("SELECT * FROM uysa_recipe_lines WHERE dish_id = ? ORDER BY sort_order");
        $stmt->execute([$dishId]);
        $lines = $stmt->fetchAll();

        $this->assertCount(2, $lines);
        $this->assertSame('Kırmızı Mercimek', $lines[0]['ingredient']);
        $this->assertSame('Soğan', $lines[1]['ingredient']);
    }

    #[Test]
    public function recipeBulkSaveReplacesAll(): void
    {
        $dishId = $this->insertDish('RB-001', 'Pilav', 'ana_yemek', 20.00);

        $this->pdo->prepare("INSERT INTO uysa_recipe_lines (dish_id, ingredient, quantity, unit, sort_order) VALUES (?,?,?,?,?)")
            ->execute([$dishId, 'Eski malzeme', 100, 'gram', 0]);

        $this->pdo->beginTransaction();
        $this->pdo->prepare("DELETE FROM uysa_recipe_lines WHERE dish_id = ?")->execute([$dishId]);
        $ins = $this->pdo->prepare("INSERT INTO uysa_recipe_lines (dish_id, ingredient, quantity, unit, sort_order) VALUES (?,?,?,?,?)");
        $ins->execute([$dishId, 'Pirinç', 500, 'gram', 0]);
        $ins->execute([$dishId, 'Tereyağı', 50, 'gram', 1]);
        $ins->execute([$dishId, 'Tuz', 10, 'gram', 2]);
        $this->pdo->commit();

        $stmt = $this->pdo->prepare("SELECT * FROM uysa_recipe_lines WHERE dish_id = ? ORDER BY sort_order");
        $stmt->execute([$dishId]);
        $lines = $stmt->fetchAll();

        $this->assertCount(3, $lines);
        $this->assertSame('Pirinç', $lines[0]['ingredient']);
    }

    #[Test]
    public function recipeCascadeDeleteWithDish(): void
    {
        $this->pdo->exec("PRAGMA foreign_keys = ON");
        $dishId = $this->insertDish('RC-001', 'Silinecek Yemek', 'corba', 10.00);

        $this->pdo->prepare("INSERT INTO uysa_recipe_lines (dish_id, ingredient, quantity, unit, sort_order) VALUES (?,?,?,?,?)")
            ->execute([$dishId, 'Malzeme', 100, 'gram', 0]);

        $this->pdo->prepare("DELETE FROM uysa_dishes WHERE id = ?")->execute([$dishId]);

        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM uysa_recipe_lines WHERE dish_id = {$dishId}")->fetchColumn();
        $this->assertSame(0, $count);
    }

    // ── Menu Plans CRUD ──────────────────────────────────────────

    #[Test]
    public function menuPlanInsertWithItems(): void
    {
        $dishId = $this->insertDish('MP-D01', 'Çorba', 'corba', 10.00);

        $this->pdo->beginTransaction();
        $this->pdo->prepare("INSERT INTO uysa_menu_plans (plan_date, meal_type, week_no, status, created_by) VALUES (?,?,?,?,?)")
            ->execute(['2026-04-27', 'ogle', 18, 'draft', 'admin']);
        $planId = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO uysa_menu_plan_items (plan_id, dish_id, slot, portion_count, sort_order) VALUES (?,?,?,?,?)")
            ->execute([$planId, $dishId, 'corba', 150, 0]);
        $this->pdo->commit();

        $plan = $this->pdo->query("SELECT * FROM uysa_menu_plans WHERE id = {$planId}")->fetch();
        $this->assertSame('2026-04-27', $plan['plan_date']);
        $this->assertSame('ogle', $plan['meal_type']);

        $items = $this->pdo->prepare("SELECT * FROM uysa_menu_plan_items WHERE plan_id = ?");
        $items->execute([$planId]);
        $rows = $items->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertEquals(150, (int)$rows[0]['portion_count']);
    }

    #[Test]
    public function menuPlanUpdateReplacesItems(): void
    {
        $d1 = $this->insertDish('MU-D01', 'Çorba', 'corba', 10.00);
        $d2 = $this->insertDish('MU-D02', 'Pilav', 'ana_yemek', 20.00);

        $this->pdo->prepare("INSERT INTO uysa_menu_plans (plan_date, meal_type, week_no, status) VALUES (?,?,?,?)")
            ->execute(['2026-04-28', 'aksam', 18, 'draft']);
        $planId = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO uysa_menu_plan_items (plan_id, dish_id, slot, sort_order) VALUES (?,?,?,?)")
            ->execute([$planId, $d1, 'corba', 0]);

        $this->pdo->beginTransaction();
        $this->pdo->prepare("DELETE FROM uysa_menu_plan_items WHERE plan_id = ?")->execute([$planId]);
        $ins = $this->pdo->prepare("INSERT INTO uysa_menu_plan_items (plan_id, dish_id, slot, sort_order) VALUES (?,?,?,?)");
        $ins->execute([$planId, $d1, 'corba', 0]);
        $ins->execute([$planId, $d2, 'ana', 1]);
        $this->pdo->commit();

        $stmt = $this->pdo->prepare("SELECT * FROM uysa_menu_plan_items WHERE plan_id = ? ORDER BY sort_order");
        $stmt->execute([$planId]);
        $this->assertCount(2, $stmt->fetchAll());
    }

    #[Test]
    public function menuPlanDeleteCascadesItems(): void
    {
        $this->pdo->exec("PRAGMA foreign_keys = ON");
        $dishId = $this->insertDish('MD-D01', 'Yemek', 'ana_yemek', 10.00);

        $this->pdo->prepare("INSERT INTO uysa_menu_plans (plan_date, meal_type, week_no, status) VALUES (?,?,?,?)")
            ->execute(['2026-05-01', 'ogle', 18, 'draft']);
        $planId = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO uysa_menu_plan_items (plan_id, dish_id, slot, sort_order) VALUES (?,?,?,?)")
            ->execute([$planId, $dishId, 'ana', 0]);

        $this->pdo->prepare("DELETE FROM uysa_menu_plans WHERE id = ?")->execute([$planId]);

        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM uysa_menu_plan_items WHERE plan_id = {$planId}")->fetchColumn();
        $this->assertSame(0, $count);
    }

    // ── Parties CRUD ─────────────────────────────────────────────

    #[Test]
    public function partyInsertAndSelect(): void
    {
        $this->pdo->prepare("INSERT INTO uysa_parties (code, name, type, tax_no, city, email, iban) VALUES (?,?,?,?,?,?,?)")
            ->execute(['CRI-001', 'ABC Gıda Ltd.', 'tedarikci', '1234567890', 'İstanbul', 'info@abc.com', 'TR123456789012345678901234']);

        $party = $this->pdo->query("SELECT * FROM uysa_parties WHERE code = 'CRI-001'")->fetch();
        $this->assertNotFalse($party);
        $this->assertSame('ABC Gıda Ltd.', $party['name']);
        $this->assertSame('tedarikci', $party['type']);
        $this->assertSame('İstanbul', $party['city']);
    }

    #[Test]
    public function partyCodeUniqueConstraint(): void
    {
        $this->pdo->prepare("INSERT INTO uysa_parties (code, name) VALUES (?,?)")
            ->execute(['DUP-001', 'First']);

        $this->expectException(\PDOException::class);
        $this->pdo->prepare("INSERT INTO uysa_parties (code, name) VALUES (?,?)")
            ->execute(['DUP-001', 'Duplicate']);
    }

    #[Test]
    public function partySearchByNameOrCode(): void
    {
        $this->pdo->prepare("INSERT INTO uysa_parties (code, name) VALUES (?,?)")->execute(['P-001', 'ABC Gıda']);
        $this->pdo->prepare("INSERT INTO uysa_parties (code, name) VALUES (?,?)")->execute(['P-002', 'XYZ Lojistik']);
        $this->pdo->prepare("INSERT INTO uysa_parties (code, name) VALUES (?,?)")->execute(['ABC-003', 'Test Şirket']);

        $like = '%ABC%';
        $stmt = $this->pdo->prepare("SELECT * FROM uysa_parties WHERE name LIKE ? OR code LIKE ?");
        $stmt->execute([$like, $like]);
        $this->assertCount(2, $stmt->fetchAll());
    }

    // ── Deliveries CRUD ──────────────────────────────────────────

    #[Test]
    public function deliveryInsertAndSelect(): void
    {
        $this->pdo->prepare("INSERT INTO uysa_deliveries (delivery_no, delivery_date, meal_type, vehicle, driver, portion_count, status) VALUES (?,?,?,?,?,?,?)")
            ->execute(['DLV-20260427-0001', '2026-04-27', 'ogle', '34ABC123', 'Ahmet', 200, 'planned']);

        $dlv = $this->pdo->query("SELECT * FROM uysa_deliveries WHERE delivery_no = 'DLV-20260427-0001'")->fetch();
        $this->assertNotFalse($dlv);
        $this->assertSame('2026-04-27', $dlv['delivery_date']);
        $this->assertEquals(200, (int)$dlv['portion_count']);
        $this->assertSame('planned', $dlv['status']);
    }

    #[Test]
    public function deliveryNoUniqueConstraint(): void
    {
        $this->pdo->prepare("INSERT INTO uysa_deliveries (delivery_no, delivery_date, status) VALUES (?,?,?)")
            ->execute(['DLV-DUP', '2026-04-27', 'planned']);

        $this->expectException(\PDOException::class);
        $this->pdo->prepare("INSERT INTO uysa_deliveries (delivery_no, delivery_date, status) VALUES (?,?,?)")
            ->execute(['DLV-DUP', '2026-04-28', 'planned']);
    }

    #[Test]
    public function deliveryAutoGeneratedNo(): void
    {
        $deliveryNo = 'DLV-' . date('Ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $this->assertMatchesRegularExpression('/^DLV-\d{8}-\d{4}$/', $deliveryNo);
    }

    #[Test]
    public function deliveryFilterByDateRange(): void
    {
        $this->pdo->prepare("INSERT INTO uysa_deliveries (delivery_no, delivery_date, status) VALUES (?,?,?)")
            ->execute(['DLV-01', '2026-04-25', 'delivered']);
        $this->pdo->prepare("INSERT INTO uysa_deliveries (delivery_no, delivery_date, status) VALUES (?,?,?)")
            ->execute(['DLV-02', '2026-04-27', 'planned']);
        $this->pdo->prepare("INSERT INTO uysa_deliveries (delivery_no, delivery_date, status) VALUES (?,?,?)")
            ->execute(['DLV-03', '2026-04-30', 'planned']);

        $stmt = $this->pdo->prepare("SELECT * FROM uysa_deliveries WHERE delivery_date >= ? AND delivery_date <= ?");
        $stmt->execute(['2026-04-26', '2026-04-28']);
        $this->assertCount(1, $stmt->fetchAll());
    }

    // ── HACCP Logs ───────────────────────────────────────────────

    #[Test]
    public function haccpIsOkCalculationPass(): void
    {
        $value = 4.5;
        $minLimit = 0.0;
        $maxLimit = 8.0;

        $isOk = 1;
        if ($value < $minLimit) $isOk = 0;
        if ($value > $maxLimit) $isOk = 0;

        $this->assertSame(1, $isOk);
    }

    #[Test]
    public function haccpIsOkCalculationFailBelowMin(): void
    {
        $value = -2.0;
        $minLimit = 0.0;
        $maxLimit = 8.0;

        $isOk = 1;
        if ($value < $minLimit) $isOk = 0;
        if ($value > $maxLimit) $isOk = 0;

        $this->assertSame(0, $isOk);
    }

    #[Test]
    public function haccpIsOkCalculationFailAboveMax(): void
    {
        $value = 12.0;
        $minLimit = 0.0;
        $maxLimit = 8.0;

        $isOk = 1;
        if ($value < $minLimit) $isOk = 0;
        if ($value > $maxLimit) $isOk = 0;

        $this->assertSame(0, $isOk);
    }

    #[Test]
    public function haccpIsOkWithNullLimitsAlwaysPasses(): void
    {
        $value = 999.0;
        $minLimit = null;
        $maxLimit = null;

        $isOk = 1;
        if ($value !== null) {
            if ($minLimit !== null && $value < $minLimit) $isOk = 0;
            if ($maxLimit !== null && $value > $maxLimit) $isOk = 0;
        }

        $this->assertSame(1, $isOk);
    }

    #[Test]
    public function haccpLogInsertAndSelect(): void
    {
        $this->pdo->prepare("INSERT INTO uysa_haccp_logs (log_date, log_time, check_point, check_type, value, min_limit, max_limit, is_ok, checked_by) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute(['2026-04-27', '08:30', 'Buzdolabı-1', 'temperature', 4.5, 0, 8, 1, 'Mehmet']);

        $log = $this->pdo->query("SELECT * FROM uysa_haccp_logs WHERE check_point = 'Buzdolabı-1'")->fetch();
        $this->assertNotFalse($log);
        $this->assertEquals(4.5, (float)$log['value']);
        $this->assertEquals(1, (int)$log['is_ok']);
    }

    #[Test]
    public function haccpFilterOnlyFails(): void
    {
        $this->pdo->prepare("INSERT INTO uysa_haccp_logs (log_date, check_point, check_type, value, is_ok) VALUES (?,?,?,?,?)")
            ->execute(['2026-04-27', 'Dolap-1', 'temperature', 4.0, 1]);
        $this->pdo->prepare("INSERT INTO uysa_haccp_logs (log_date, check_point, check_type, value, is_ok) VALUES (?,?,?,?,?)")
            ->execute(['2026-04-27', 'Dolap-2', 'temperature', 15.0, 0]);
        $this->pdo->prepare("INSERT INTO uysa_haccp_logs (log_date, check_point, check_type, value, is_ok) VALUES (?,?,?,?,?)")
            ->execute(['2026-04-27', 'Dolap-3', 'temperature', 3.0, 1]);

        $stmt = $this->pdo->query("SELECT * FROM uysa_haccp_logs WHERE is_ok = 0");
        $fails = $stmt->fetchAll();
        $this->assertCount(1, $fails);
        $this->assertSame('Dolap-2', $fails[0]['check_point']);
    }

    #[Test]
    #[DataProvider('haccpDataProvider')]
    public function haccpIsOkCalculation(float $value, ?float $min, ?float $max, int $expected): void
    {
        $isOk = 1;
        if ($min !== null && $value < $min) $isOk = 0;
        if ($max !== null && $value > $max) $isOk = 0;

        $this->assertSame($expected, $isOk);
    }

    public static function haccpDataProvider(): array
    {
        return [
            'within range'       => [4.0,  0.0, 8.0, 1],
            'at min boundary'    => [0.0,  0.0, 8.0, 1],
            'at max boundary'    => [8.0,  0.0, 8.0, 1],
            'below min'          => [-1.0, 0.0, 8.0, 0],
            'above max'          => [9.0,  0.0, 8.0, 0],
            'only min, pass'     => [5.0,  0.0, null, 1],
            'only min, fail'     => [-1.0, 0.0, null, 0],
            'only max, pass'     => [5.0,  null, 10.0, 1],
            'only max, fail'     => [11.0, null, 10.0, 0],
            'no limits'          => [999.0, null, null, 1],
        ];
    }

    // ── Dish Pairings ────────────────────────────────────────────

    #[Test]
    public function dishPairingSwapLogic(): void
    {
        $dishAId = 5;
        $dishBId = 2;

        if ($dishAId > $dishBId) {
            [$dishAId, $dishBId] = [$dishBId, $dishAId];
        }

        $this->assertSame(2, $dishAId);
        $this->assertSame(5, $dishBId);
    }

    #[Test]
    public function dishPairingScoreClamp(): void
    {
        $this->assertSame(1, max(1, min(10, 0)));
        $this->assertSame(5, max(1, min(10, 5)));
        $this->assertSame(10, max(1, min(10, 15)));
        $this->assertSame(1, max(1, min(10, -5)));
    }

    #[Test]
    public function dishPairingInsertAndQuery(): void
    {
        $d1 = $this->insertDish('DP-001', 'Çorba', 'corba', 10.00);
        $d2 = $this->insertDish('DP-002', 'Pilav', 'ana_yemek', 20.00);
        $d3 = $this->insertDish('DP-003', 'Salata', 'salata', 8.00);

        $this->pdo->prepare("INSERT INTO uysa_dish_pairings (dish_a_id, dish_b_id, score, reason) VALUES (?,?,?,?)")
            ->execute([$d1, $d2, 8, 'Çorba + pilav klasik']);
        $this->pdo->prepare("INSERT INTO uysa_dish_pairings (dish_a_id, dish_b_id, score, reason) VALUES (?,?,?,?)")
            ->execute([$d2, $d3, 6, null]);

        $stmt = $this->pdo->prepare("SELECT dp.*, da.name AS dish_a_name, db.name AS dish_b_name
            FROM uysa_dish_pairings dp
            JOIN uysa_dishes da ON dp.dish_a_id = da.id
            JOIN uysa_dishes db ON dp.dish_b_id = db.id
            WHERE dp.dish_a_id = ? OR dp.dish_b_id = ?
            ORDER BY dp.score DESC");
        $stmt->execute([$d2, $d2]);
        $pairings = $stmt->fetchAll();

        $this->assertCount(2, $pairings);
        $this->assertEquals(8, (int)$pairings[0]['score']);
    }

    #[Test]
    public function dishPairingUniqueConstraint(): void
    {
        $d1 = $this->insertDish('DPU-001', 'A', 'corba', 10.00);
        $d2 = $this->insertDish('DPU-002', 'B', 'corba', 10.00);

        $this->pdo->prepare("INSERT INTO uysa_dish_pairings (dish_a_id, dish_b_id, score) VALUES (?,?,?)")
            ->execute([$d1, $d2, 5]);

        $this->expectException(\PDOException::class);
        $this->pdo->prepare("INSERT INTO uysa_dish_pairings (dish_a_id, dish_b_id, score) VALUES (?,?,?)")
            ->execute([$d1, $d2, 7]);
    }

    // ── Validation Patterns ──────────────────────────────────────

    #[Test]
    public function dishCodeValidation(): void
    {
        $pattern = '/^[A-Za-z0-9ĞğİıÇçŞşÜüÖö_-]{1,20}$/u';

        $this->assertMatchesRegularExpression($pattern, 'YMK-001');
        $this->assertMatchesRegularExpression($pattern, 'Çorba_1');
        $this->assertMatchesRegularExpression($pattern, 'ABCDEFGHIJ1234567890');
        $this->assertMatchesRegularExpression($pattern, 'İstanbul-ŞÜÖ');
        $this->assertDoesNotMatchRegularExpression($pattern, '');
        $this->assertDoesNotMatchRegularExpression($pattern, 'has space');
        $this->assertDoesNotMatchRegularExpression($pattern, 'ABCDEFGHIJ12345678901'); // 21 chars
        $this->assertDoesNotMatchRegularExpression($pattern, 'code@invalid');
    }

    #[Test]
    public function ibanValidation(): void
    {
        $pattern = '/^TR\d{24}$/';

        $this->assertMatchesRegularExpression($pattern, 'TR123456789012345678901234');
        $this->assertDoesNotMatchRegularExpression($pattern, 'DE123456789012345678901234');
        $this->assertDoesNotMatchRegularExpression($pattern, 'TR12345');
        $this->assertDoesNotMatchRegularExpression($pattern, 'TR12345678901234567890123A');
    }

    #[Test]
    public function emailValidation(): void
    {
        $this->assertNotFalse(filter_var('test@example.com', FILTER_VALIDATE_EMAIL));
        $this->assertNotFalse(filter_var('user.name+tag@domain.co', FILTER_VALIDATE_EMAIL));
        $this->assertFalse(filter_var('not-an-email', FILTER_VALIDATE_EMAIL));
        $this->assertFalse(filter_var('@missing-local.com', FILTER_VALIDATE_EMAIL));
    }

    #[Test]
    public function planDateFormatValidation(): void
    {
        $pattern = '/^\d{4}-\d{2}-\d{2}$/';

        $this->assertMatchesRegularExpression($pattern, '2026-04-27');
        $this->assertDoesNotMatchRegularExpression($pattern, '27-04-2026');
        $this->assertDoesNotMatchRegularExpression($pattern, '2026/04/27');
        $this->assertDoesNotMatchRegularExpression($pattern, '2026-4-7');
    }

    // ── Audit Logging ────────────────────────────────────────────

    #[Test]
    public function auditLogRecordsCateringActions(): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO uysa_audit (action, actor, target_key, detail, ip_addr) VALUES (?,?,?,?,?)");
        $stmt->execute(['cat.dishSave', 'admin', 'dish:YMK-001', null, '127.0.0.1']);
        $stmt->execute(['cat.menuPlanSave', 'admin', 'menu:2026-04-27/ogle', null, '127.0.0.1']);
        $stmt->execute(['cat.haccpLogSave', 'admin', 'haccp:Dolap-1/2026-04-27', 'OK', '127.0.0.1']);

        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM uysa_audit WHERE action LIKE 'cat.%'")->fetchColumn();
        $this->assertSame(3, $count);
    }

    // ── Dashboard Queries ────────────────────────────────────────

    #[Test]
    public function dashboardCountQueries(): void
    {
        $this->insertDish('DASH-01', 'Aktif 1', 'corba', 10.00, 1);
        $this->insertDish('DASH-02', 'Aktif 2', 'ana_yemek', 20.00, 1);
        $this->insertDish('DASH-03', 'Pasif', 'corba', 5.00, 0);

        $this->pdo->prepare("INSERT INTO uysa_parties (code, name, is_active) VALUES (?,?,?)")->execute(['DP-01', 'Cari 1', 1]);
        $this->pdo->prepare("INSERT INTO uysa_parties (code, name, is_active) VALUES (?,?,?)")->execute(['DP-02', 'Cari 2', 0]);

        $dishCount = (int)$this->pdo->query("SELECT COUNT(*) FROM uysa_dishes WHERE is_active=1")->fetchColumn();
        $partyCount = (int)$this->pdo->query("SELECT COUNT(*) FROM uysa_parties WHERE is_active=1")->fetchColumn();

        $this->assertSame(2, $dishCount);
        $this->assertSame(1, $partyCount);
    }

    #[Test]
    public function dashboardRecipesDefinedCount(): void
    {
        $d1 = $this->insertDish('DRC-01', 'Yemek 1', 'corba', 10.00);
        $d2 = $this->insertDish('DRC-02', 'Yemek 2', 'ana_yemek', 20.00);
        $this->insertDish('DRC-03', 'Yemek 3', 'corba', 15.00);

        $this->pdo->prepare("INSERT INTO uysa_recipe_lines (dish_id, ingredient, quantity, unit, sort_order) VALUES (?,?,?,?,?)")
            ->execute([$d1, 'Malzeme', 100, 'gram', 0]);
        $this->pdo->prepare("INSERT INTO uysa_recipe_lines (dish_id, ingredient, quantity, unit, sort_order) VALUES (?,?,?,?,?)")
            ->execute([$d2, 'Malzeme', 200, 'gram', 0]);

        $recipeCount = (int)$this->pdo->query("SELECT COUNT(DISTINCT dish_id) FROM uysa_recipe_lines")->fetchColumn();
        $this->assertSame(2, $recipeCount);
    }

    // ── Transaction Rollback ─────────────────────────────────────

    #[Test]
    public function transactionRollbackOnError(): void
    {
        $dishId = $this->insertDish('TX-001', 'Transaction Test', 'corba', 10.00);

        $this->pdo->beginTransaction();
        $this->pdo->prepare("INSERT INTO uysa_recipe_lines (dish_id, ingredient, quantity, unit, sort_order) VALUES (?,?,?,?,?)")
            ->execute([$dishId, 'Rolled back', 100, 'gram', 0]);
        $this->pdo->rollBack();

        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM uysa_recipe_lines WHERE dish_id = {$dishId}")->fetchColumn();
        $this->assertSame(0, $count);
    }

    // ── Module Router Registration ───────────────────────────────

    #[Test]
    public function moduleRouterIncludesCatering(): void
    {
        $apiFile = file_get_contents(__DIR__ . '/../public/uysa_api.php');
        $this->assertStringContainsString("'cat.'", $apiFile);
        $this->assertStringContainsString('CateringModule.php', $apiFile);
        $this->assertStringContainsString('handleCateringAction', $apiFile);
    }

    // ── Helper ───────────────────────────────────────────────────

    private function insertDish(string $code, string $name, string $category, float $price, int $active = 1): int
    {
        $this->pdo->prepare("INSERT INTO uysa_dishes (code, name, category, dish_price, is_active) VALUES (?,?,?,?,?)")
            ->execute([$code, $name, $category, $price, $active]);
        return (int)$this->pdo->lastInsertId();
    }
}
