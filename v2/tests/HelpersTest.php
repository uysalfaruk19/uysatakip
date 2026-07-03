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

    /** H1: minimum skor kapısı — çok kısa/belirsiz girdi yanlış müşteriye yazmamalı. */
    public function testMatchCustomerMinScoreGate(): void
    {
        $cands = [1 => 'CANTAŞ', 2 => 'OPAK', 3 => 'ERMETAL', 4 => 'E-DEPO'];

        $m = Helpers::matchCustomer('e', $cands); // "e 300" → çok kısa/belirsiz
        $this->assertNull($m['id'], '"e" eşik altı → eşleşme yok');

        $m = Helpers::matchCustomer('op', $cands); // "op 280"
        $this->assertNull($m['id'], '"op" eşik altı → eşleşme yok');

        // Levenshtein eşik-altı yakın miss → gate 'dusuk_skor' ile null döner
        $m = Helpers::matchCustomer('cantx', $cands);
        $this->assertNull($m['id'], '"cantx" eşik altı → eşleşme yok');
        $this->assertSame('dusuk_skor', $m['reason']);
        $this->assertLessThan(Helpers::MATCH_MIN_SCORE, $m['score']);

        $m = Helpers::matchCustomer('cantas', $cands); // tam ad → eşleşir
        $this->assertSame(1, $m['id']);
        $this->assertGreaterThanOrEqual(Helpers::MATCH_MIN_SCORE, $m['score']);
    }

    public function testIsDate(): void
    {
        $this->assertTrue(Helpers::isDate('2026-07-03'));
        $this->assertFalse(Helpers::isDate('TALAY'));
        $this->assertFalse(Helpers::isDate('2026-13-40'));
    }
}
