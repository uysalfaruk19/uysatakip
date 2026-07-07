<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Parasut;
use Uysa\Repo;

/**
 * opus-012 — Paraşüt cari SALT-OKUMA testleri.
 * GERÇEK Paraşüt çağrısı YOK: JSON:API parse + eşleştirme + upsert mock/fixture ile kanıtlanır.
 * (Canlı okuma smoke'u Fable yerelde yapar; creds Ömer'in PC'sinde.)
 */
final class ParasutTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    // ── 1) JSON:API contact parse (fixture, ağ yok) ───────────────
    public function testParseContactsFlattensJsonApi(): void
    {
        $fixture = [
            'data' => [
                ['id' => '101', 'type' => 'contacts', 'attributes' => [
                    'name' => 'CANTAŞ GIDA', 'balance' => 12500.50, 'tax_number' => '1234567890',
                    'tax_office' => 'Gebze', 'email' => 'a@b.co', 'phone' => '05001112233',
                    'account_type' => 'customer',
                ]],
                ['id' => '102', 'type' => 'contacts', 'attributes' => [
                    'name' => '  OPAK  ', 'balance' => -300, 'account_type' => 'customer',
                ]],
                'bozuk-satir', // dizi değil → atlanmalı
            ],
        ];
        $rows = Parasut::parseContacts($fixture);
        $this->assertCount(2, $rows, 'geçersiz satır atlanır');
        $this->assertSame('101', $rows[0]['parasut_id']);
        $this->assertSame('CANTAŞ GIDA', $rows[0]['name']);
        $this->assertSame(12500.50, $rows[0]['balance']);
        $this->assertSame('1234567890', $rows[0]['tax_number']);
        $this->assertSame('OPAK', $rows[1]['name'], 'ad trim edilir');
        $this->assertSame(-300.0, $rows[1]['balance'], 'negatif bakiye korunur');
        $this->assertSame('', $rows[1]['tax_number'], 'eksik alan boş string');
    }

    public function testParseContactsEmptyOnBadShape(): void
    {
        $this->assertSame([], Parasut::parseContacts([]));
        $this->assertSame([], Parasut::parseContacts(['data' => 'x']));
    }

    // ── 2) configured(): env eksikse false, tamsa true ────────────
    public function testConfiguredReflectsEnv(): void
    {
        $keys = ['PARASUT_CLIENT_ID', 'PARASUT_CLIENT_SECRET', 'PARASUT_USERNAME', 'PARASUT_PASSWORD', 'PARASUT_COMPANY_ID'];
        foreach ($keys as $k) { putenv($k); unset($_ENV[$k]); }
        $this->assertFalse(Parasut::configured(), 'cred yokken false');

        foreach ($keys as $k) { putenv("$k=x"); $_ENV[$k] = 'x'; }
        $this->assertTrue(Parasut::configured(), 'tüm cred varken true');

        foreach ($keys as $k) { putenv($k); unset($_ENV[$k]); } // temizle
    }

    // ── 3) Eşleştirme önceliği: parasut_id > vergi no > ad-normalize ─
    public function testMatchPriorityParasutIdOverTaxOverName(): void
    {
        $candidates = [
            ['customer_id' => 1, 'name' => 'CANTAŞ',   'parasut_id' => '900', 'tax_number' => '111'],
            ['customer_id' => 2, 'name' => 'Cantas AŞ', 'parasut_id' => '',    'tax_number' => '222'],
            ['customer_id' => 3, 'name' => 'OPAK',      'parasut_id' => '',    'tax_number' => ''],
        ];

        // parasut_id eşleşmesi kazanır (ad başka müşteriye benzese bile)
        $r = $this->repo->matchCustomerByTaxOrName(
            ['parasut_id' => '900', 'tax_number' => '222', 'name' => 'OPAK'], $candidates);
        $this->assertSame(1, $r['customer_id']);
        $this->assertSame('parasut_id', $r['reason']);

        // parasut_id yok → vergi no
        $r = $this->repo->matchCustomerByTaxOrName(
            ['parasut_id' => '', 'tax_number' => '222', 'name' => 'OPAK'], $candidates);
        $this->assertSame(2, $r['customer_id']);
        $this->assertSame('tax_number', $r['reason']);

        // parasut_id + vergi yok → ad normalize (CANTAŞ ~ "cantas")
        $r = $this->repo->matchCustomerByTaxOrName(
            ['parasut_id' => '', 'tax_number' => '', 'name' => 'cantaş'], $candidates);
        $this->assertSame(1, $r['customer_id']);
        $this->assertSame('name', $r['reason']);

        // hiç eşleşmez
        $r = $this->repo->matchCustomerByTaxOrName(
            ['parasut_id' => '', 'tax_number' => '999', 'name' => 'YOKFIRMA'], $candidates);
        $this->assertNull($r['customer_id']);
        $this->assertSame('eslesmedi', $r['reason']);
    }

    // ── 4) setParasutInfo + customersWithParasut ──────────────────
    public function testSetParasutInfoWritesAndListsWithoutOverwritingId(): void
    {
        $cid = seed_customer($this->pdo, 'CANTAŞ', 328.0);

        $this->repo->setParasutInfo($cid, '555', 4200.75, '2026-07-06 10:00:00');
        $row = $this->repo->customer($cid);
        $this->assertSame('555', (string) $row['parasut_id']);
        $this->assertEqualsWithDelta(4200.75, (float) $row['parasut_bakiye'], 0.001);
        $this->assertSame('2026-07-06 10:00:00', $row['parasut_sync_at']);

        // parasut_id null verilince mevcut korunur (COALESCE), bakiye güncellenir
        $this->repo->setParasutInfo($cid, null, -100.0, '2026-07-06 12:00:00');
        $row = $this->repo->customer($cid);
        $this->assertSame('555', (string) $row['parasut_id'], 'null id mevcut değeri EZMEZ');
        $this->assertEqualsWithDelta(-100.0, (float) $row['parasut_bakiye'], 0.001);

        $list = $this->repo->customersWithParasut();
        $this->assertCount(1, $list);
        $this->assertSame($cid, (int) $list[0]['id']);
    }

    // ── 5) parasutCandidates: aktif müşteriler + parasut_id ────────
    public function testParasutCandidatesReturnsActiveWithParasutId(): void
    {
        $c1 = seed_customer($this->pdo, 'CANTAŞ', 328.0);
        seed_customer($this->pdo, 'OPAK', 250.0);
        $this->repo->setParasutInfo($c1, '777', 0.0, '2026-07-06 10:00:00');

        $cands = $this->repo->parasutCandidates(true);
        $this->assertCount(2, $cands);
        $byId = [];
        foreach ($cands as $c) { $byId[$c['customer_id']] = $c; }
        $this->assertSame('777', $byId[$c1]['parasut_id']);
        $this->assertArrayHasKey('tax_number', $byId[$c1], 'aday şeması vergi no alanı taşır');
    }

    // ── 6) lastParasutSync: audit'ten son kaydı okur ──────────────
    public function testLastParasutSyncReadsLatestAudit(): void
    {
        $this->assertNull($this->repo->lastParasutSync(), 'kayıt yokken null');
        uysa_audit('parasut_cari', 'bot/api', null, json_encode(['yazilan' => 3, 'eslesmeyen' => []]), '127.0.0.1');
        uysa_audit('parasut_cari', 'bot/api', null, json_encode(['yazilan' => 5, 'eslesmeyen' => [['girdi' => 'X']]]), '127.0.0.1');
        $last = $this->repo->lastParasutSync();
        $this->assertNotNull($last);
        $this->assertSame('parasut_cari', $last['action']);
        $detail = json_decode((string) $last['detail'], true);
        $this->assertSame(5, $detail['yazilan'], 'en son kayıt (id DESC)');
    }

    // ── 7) tools/parasut_sync.php esnek secrets parser ────────────
    public function testSyncSecretsParserJsonAndKeyValue(): void
    {
        require_once __DIR__ . '/../tools/parasut_sync.php';

        // JSON format
        $j = parasut_parse_secrets('{"client_id":"cid","Client Secret":"sec","company_id":"9"}');
        $this->assertSame('cid', $j['client_id']);
        $this->assertSame('sec', $j['client_secret'], 'boşluklu anahtar normalize edilir');
        $this->assertSame('9', $j['company_id']);

        // KEY=VALUE + tırnak + yorum + PARASUT_ ön eki
        $raw = "# yorum\nPARASUT_CLIENT_ID = abc \nusername=\"user1\"\npassword = 'p@ss=word'\n";
        $kv = parasut_parse_secrets($raw);
        $this->assertSame('abc', $kv['client_id'], 'PARASUT_ ön eki soyulur');
        $this->assertSame('user1', $kv['username'], 'tırnak temizlenir');
        $this->assertSame('p@ss=word', $kv['password'], 'değerdeki = korunur');

        // norm_key doğrudan
        $this->assertSame('client_id', parasut_norm_key('PARASUT_CLIENT_ID'));
        $this->assertSame('client_id', parasut_norm_key('Client-ID'));
        $this->assertSame('company_id', parasut_norm_key('company id'));
    }
}
