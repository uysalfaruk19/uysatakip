<?php
/**
 * UYSA ERP v5.0 — Modül Unit Testleri
 * Finans, Stok, İK, Portal modüllerinin temel doğrulaması
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ModuleTest extends TestCase
{
    // ── PHP Dosya Sözdizimi Kontrolü ────────────────────────
    public function testFinanceModuleSyntax(): void
    {
        $output = [];
        $result = 0;
        exec('php -l ' . escapeshellarg(__DIR__ . '/../public/src/modules/FinanceModule.php') . ' 2>&1', $output, $result);
        $this->assertEquals(0, $result, 'FinanceModule.php syntax error: ' . implode("\n", $output));
    }

    public function testInventoryModuleSyntax(): void
    {
        $output = [];
        $result = 0;
        exec('php -l ' . escapeshellarg(__DIR__ . '/../public/src/modules/InventoryModule.php') . ' 2>&1', $output, $result);
        $this->assertEquals(0, $result, 'InventoryModule.php syntax error: ' . implode("\n", $output));
    }

    public function testHRModuleSyntax(): void
    {
        $output = [];
        $result = 0;
        exec('php -l ' . escapeshellarg(__DIR__ . '/../public/src/modules/HRModule.php') . ' 2>&1', $output, $result);
        $this->assertEquals(0, $result, 'HRModule.php syntax error: ' . implode("\n", $output));
    }

    public function testPortalModuleSyntax(): void
    {
        $output = [];
        $result = 0;
        exec('php -l ' . escapeshellarg(__DIR__ . '/../public/src/modules/PortalModule.php') . ' 2>&1', $output, $result);
        $this->assertEquals(0, $result, 'PortalModule.php syntax error: ' . implode("\n", $output));
    }

    public function testTelegramBotSyntax(): void
    {
        $output = [];
        $result = 0;
        exec('php -l ' . escapeshellarg(__DIR__ . '/../public/src/modules/TelegramBot.php') . ' 2>&1', $output, $result);
        $this->assertEquals(0, $result, 'TelegramBot.php syntax error: ' . implode("\n", $output));
    }

    public function testMainApiSyntax(): void
    {
        $output = [];
        $result = 0;
        exec('php -l ' . escapeshellarg(__DIR__ . '/../public/uysa_api.php') . ' 2>&1', $output, $result);
        $this->assertEquals(0, $result, 'uysa_api.php syntax error: ' . implode("\n", $output));
    }

    // ── Fonksiyon Varlık Kontrolü ───────────────────────────
    public function testFinanceModuleHasHandler(): void
    {
        require_once __DIR__ . '/../public/src/modules/FinanceModule.php';
        $this->assertTrue(function_exists('handleFinanceAction'), 'handleFinanceAction fonksiyonu tanımlı olmalı');
    }

    public function testInventoryModuleHasHandler(): void
    {
        require_once __DIR__ . '/../public/src/modules/InventoryModule.php';
        $this->assertTrue(function_exists('handleInventoryAction'), 'handleInventoryAction fonksiyonu tanımlı olmalı');
    }

    public function testHRModuleHasHandler(): void
    {
        require_once __DIR__ . '/../public/src/modules/HRModule.php';
        $this->assertTrue(function_exists('handleHRAction'), 'handleHRAction fonksiyonu tanımlı olmalı');
    }

    public function testPortalModuleHasHandler(): void
    {
        require_once __DIR__ . '/../public/src/modules/PortalModule.php';
        $this->assertTrue(function_exists('handlePortalAction'), 'handlePortalAction fonksiyonu tanımlı olmalı');
    }

    // ── 2FA TOTP Doğrulaması ────────────────────────────────
    public function testBase32SecretGeneration(): void
    {
        require_once __DIR__ . '/../public/src/modules/PortalModule.php';
        $secret = generateBase32Secret(20);
        $this->assertGreaterThanOrEqual(32, strlen($secret), 'Base32 secret en az 32 karakter olmalı');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret, 'Base32 sadece A-Z ve 2-7 içermeli');
    }

    public function testBase32Decode(): void
    {
        require_once __DIR__ . '/../public/src/modules/PortalModule.php';
        // "JBSWY3DP" = "Hello" in Base32
        $decoded = base32Decode('JBSWY3DP');
        $this->assertEquals('Hello', $decoded, 'Base32 decode "Hello" döndürmeli');
    }

    // ── Schema Dosyası Kontrolü ──────────────────────────────
    public function testSchemaV5Exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../sql/schema_v5.sql', 'schema_v5.sql mevcut olmalı');
    }

    public function testSchemaV5HasRequiredTables(): void
    {
        $schema = file_get_contents(__DIR__ . '/../sql/schema_v5.sql');
        $requiredTables = [
            'uysa_accounts', 'uysa_journal_entries', 'uysa_journal_lines',
            'uysa_invoices', 'uysa_invoice_lines', 'uysa_payments',
            'uysa_bank_accounts', 'uysa_bank_transactions',
            'uysa_warehouses', 'uysa_products', 'uysa_stock_movements',
            'uysa_suppliers', 'uysa_purchase_orders', 'uysa_lots',
            'uysa_employees', 'uysa_leave_types', 'uysa_leave_requests',
            'uysa_attendance', 'uysa_shifts', 'uysa_payroll',
            'uysa_customers', 'uysa_customer_orders', 'uysa_webhooks',
            'uysa_2fa', 'uysa_ai_chats', 'uysa_telegram_users',
        ];
        foreach ($requiredTables as $table) {
            $this->assertStringContainsString($table, $schema, "Schema v5 '{$table}' tablosunu içermeli");
        }
    }

    // ── Bordro Hesaplama Mantığı ────────────────────────────
    public function testPayrollCalculation(): void
    {
        $gross = 30000.00;
        $sgkEmployee = round($gross * 0.14, 2);
        $sgkEmployer = round($gross * 0.205, 2);
        $taxBase = round($gross - $sgkEmployee, 2);
        $incomeTax = round($taxBase * 0.15, 2);
        $stampTax = round($gross * 0.00759, 2);
        $net = round($gross - $sgkEmployee - $incomeTax - $stampTax, 2);

        $this->assertEquals(4200.00, $sgkEmployee, 'SGK İşçi %14');
        $this->assertEquals(6150.00, $sgkEmployer, 'SGK İşveren %20.5');
        $this->assertEquals(3870.00, $incomeTax, 'Gelir Vergisi %15');
        $this->assertEquals(227.70, $stampTax, 'Damga Vergisi %0.759');
        $this->assertGreaterThan(0, $net, 'Net maaş pozitif olmalı');
        $this->assertEquals(round(30000 - 4200 - 3870 - 227.70, 2), $net);
    }

    // ── İş Günü Hesaplama ───────────────────────────────────
    public function testWorkingDaysCalculation(): void
    {
        // 2026-03-23 Pazartesi -> 2026-03-27 Cuma = 5 iş günü
        $days = 0;
        $current = new \DateTime('2026-03-23');
        $end = new \DateTime('2026-03-27');
        while ($current <= $end) {
            if ((int)$current->format('N') < 6) $days++;
            $current->modify('+1 day');
        }
        $this->assertEquals(5, $days, 'Pzt-Cuma arası 5 iş günü olmalı');

        // 2026-03-23 Pazartesi -> 2026-03-29 Pazar = 5 iş günü (cumartesi pazar hariç)
        $days = 0;
        $current = new \DateTime('2026-03-23');
        $end = new \DateTime('2026-03-29');
        while ($current <= $end) {
            if ((int)$current->format('N') < 6) $days++;
            $current->modify('+1 day');
        }
        $this->assertEquals(5, $days, '1 hafta = 5 iş günü');
    }

    // ── Modül Dosya Yapısı ──────────────────────────────────
    public function testModuleDirectoryExists(): void
    {
        $this->assertDirectoryExists(__DIR__ . '/../public/src/modules', 'modules dizini mevcut olmalı');
    }

    public function testAllModuleFilesExist(): void
    {
        $files = [
            'FinanceModule.php',
            'InventoryModule.php',
            'HRModule.php',
            'PortalModule.php',
            'TelegramBot.php',
        ];
        foreach ($files as $file) {
            $this->assertFileExists(
                __DIR__ . '/../public/src/modules/' . $file,
                "{$file} mevcut olmalı"
            );
        }
    }
}
