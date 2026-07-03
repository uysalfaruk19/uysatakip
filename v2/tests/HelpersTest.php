<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Helpers;

final class HelpersTest extends TestCase
{
    public function testUnmojibakeTurkishNames(): void
    {
        $this->assertSame('CANTAŞ', Helpers::unmojibake('CANTAÅž'));
        $this->assertSame('BOMİ', Helpers::unmojibake('BOMÄ°'));
        $this->assertSame('TALAY LOJİSTİK', Helpers::unmojibake('TALAY LOJÄ°STÄ°K'));
    }

    public function testUnmojibakeLeavesCleanTextUntouched(): void
    {
        $this->assertSame('OPAK', Helpers::unmojibake('OPAK'));
        $this->assertSame('E-DEPO', Helpers::unmojibake('E-DEPO'));
        $this->assertSame('ERMETAL', Helpers::unmojibake('ERMETAL'));
    }

    public function testMoneyFormatTurkish(): void
    {
        $this->assertSame('1.234,50', Helpers::money(1234.5));
        $this->assertSame('147.600,00', Helpers::money(147600));
        $this->assertSame('0,00', Helpers::money(0));
    }

    public function testNormalizeNameFoldsTurkish(): void
    {
        $this->assertSame('cantas', Helpers::normalizeName('CANTAŞ'));
        $this->assertSame('cantas', Helpers::normalizeName('cantas'));
        $this->assertSame('talaylojistik', Helpers::normalizeName('Talay Lojistik'));
    }

    /** Bot girişi: Türkçe karaktersiz/mojibake'li ad doğru müşteriye eşleşir. */
    public function testMatchCustomerFuzzy(): void
    {
        $cands = [1 => 'CANTAŞ', 2 => 'OPAK', 3 => 'ERMETAL', 4 => 'TALAY LOJİSTİK'];

        $m = Helpers::matchCustomer('cantas', $cands);
        $this->assertSame(1, $m['id'], 'cantas → CANTAŞ');

        $m = Helpers::matchCustomer('CANTAÅž', $cands); // mojibake girdi
        $this->assertSame(1, $m['id'], 'mojibake CANTAÅž → CANTAŞ');

        $m = Helpers::matchCustomer('opak', $cands);
        $this->assertSame(2, $m['id']);

        $m = Helpers::matchCustomer('talay', $cands); // kısmi
        $this->assertSame(4, $m['id'], 'talay → TALAY LOJİSTİK (önek)');
    }

    public function testMatchCustomerNoFalsePositive(): void
    {
        $cands = [1 => 'CANTAŞ', 2 => 'OPAK'];
        $m = Helpers::matchCustomer('mercedes', $cands);
        $this->assertNull($m['id'], 'alakasız ad eşleşmemeli');
    }

    public function testIsDate(): void
    {
        $this->assertTrue(Helpers::isDate('2026-07-03'));
        $this->assertFalse(Helpers::isDate('TALAY'));
        $this->assertFalse(Helpers::isDate('2026-13-40'));
    }
}
