<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Apns;
use Uysa\Push;
use Uysa\Repo;

/**
 * opus-021: Push orkestratörü — ölü token silme + push_log, sessiz saat suppressed,
 * menü yayını mükerrer koruması, reminder idempotent (sadece girmemişlere),
 * admin token hedefleme + kayıt upsert'i, migrate_023/şema eşliği.
 * GERÇEK APNs'e istek YOK — FakeApns stub'ı enjekte edilir.
 */
final class FakeApns extends Apns
{
    /** @var array<int,array{token:string,title:string,body:string,data:array,badge:?int}> */
    public array $sentCalls = [];
    /** @var array<string,string> token => APNs reason (boş = başarılı) */
    public array $responses = [];
    private bool $isConfigured;

    public function __construct(bool $configured = true)
    {
        // parent constructor ÇAĞRILMAZ (Env okumasın) — override'lar property kullanmaz
        $this->isConfigured = $configured;
    }

    public function configured(): bool
    {
        return $this->isConfigured;
    }

    public function send(string $deviceToken, string $title, string $body, array $data = [], ?int $badge = 1): array
    {
        $this->sentCalls[] = ['token' => $deviceToken, 'title' => $title, 'body' => $body, 'data' => $data, 'badge' => $badge];
        $reason = $this->responses[$deviceToken] ?? '';
        return $reason === ''
            ? ['ok' => true, 'status' => 200, 'reason' => '']
            : ['ok' => false, 'status' => 410, 'reason' => $reason];
    }
}

final class PayloadProbeApns extends Apns
{
    public function __construct()
    {
        // Sadece payload üretimi test edilir; Env/APNs anahtarı okunmaz.
    }

    public function payload(string $title, string $body, array $data, ?int $badge): array
    {
        return $this->buildPayload($title, $body, $data, $badge);
    }
}

final class PushTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
    }

    private function addToken(string $token, ?int $customerId = null, ?int $userId = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO push_tokens (platform, token, customer_id, user_id) VALUES (?, ?, ?, ?)'
        )->execute(['ios', $token, $customerId, $userId]);
    }

    private function addLog(int $customerId, string $kind, string $createdAt, int $sent = 1, int $suppressed = 0): void
    {
        $this->pdo->prepare(
            'INSERT INTO push_log (kind, customer_id, title, body, sent, dead, suppressed, created_at)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?)'
        )->execute([$kind, $customerId, 'Başlık', 'Mesaj', $sent, $suppressed, $createdAt]);
    }

    /** Gündüz saati (sessiz saat DIŞI) sabit "now" — 12:00. */
    private function noon(): int
    {
        return (int) strtotime(date('Y-m-d') . ' 12:00:00');
    }

    // ── 1) Ölü token silinir + push_log'a yazılır ─────────────
    public function testToCustomerDeletesDeadTokenAndLogs(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $this->addToken(str_repeat('a', 64), $a);
        $this->addToken(str_repeat('b', 64), $a);
        $fake = new FakeApns();
        $fake->responses[str_repeat('b', 64)] = 'Unregistered';

        $push = new Push($this->pdo, $fake, $this->noon());
        $r = $push->toCustomer($a, 'Test', 'Mesaj', ['url' => '/m/menu.php'], 'manuel');

        $this->assertSame(1, $r['sent']);
        $this->assertSame(1, $r['dead'], 'Unregistered → ölü sayıldı');
        $this->assertCount(2, $fake->sentCalls, 'iki cihaza da denendi');
        $this->assertSame(['url' => '/m/menu.php'], $fake->sentCalls[0]['data'], "data['url'] payload'a taşındı");

        $left = $this->pdo->query('SELECT token FROM push_tokens')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([str_repeat('a', 64)], $left, 'ölü token DB\'den silindi');

        $log = $this->pdo->query('SELECT * FROM push_log')->fetchAll();
        $this->assertCount(1, $log, 'her gönderim tek log satırı');
        $this->assertSame('manuel', $log[0]['kind']);
        $this->assertSame($a, (int) $log[0]['customer_id']);
        $this->assertSame(1, (int) $log[0]['sent']);
        $this->assertSame(1, (int) $log[0]['dead']);
        $this->assertSame(0, (int) $log[0]['suppressed']);
    }

    // ── 2) Sessiz saat: olay bastırılır, manuel muaf ──────────
    public function testQuietHoursSuppressesEventButNotManual(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $this->addToken(str_repeat('a', 64), $a);
        $fake = new FakeApns();
        $night = (int) strtotime(date('Y-m-d') . ' 22:30:00'); // 21:00–07:00 içinde

        $push = new Push($this->pdo, $fake, $night);
        $this->assertTrue($push->inQuietHours(), '22:30 sessiz saat (default 21:00–07:00)');

        $r = $push->toCustomer($a, 'Talebinize cevap geldi', 'Konu', [], 'talep_cevap');
        $this->assertTrue($r['suppressed']);
        $this->assertSame(0, $r['sent']);
        $this->assertCount(0, $fake->sentCalls, 'sessiz saatte APNs hiç çağrılmadı');
        $sup = $this->pdo->query("SELECT suppressed FROM push_log WHERE kind='talep_cevap'")->fetchColumn();
        $this->assertSame(1, (int) $sup, 'suppressed=1 loglandı');

        // manuel (bildirim.php) MUAF — aynı saatte gönderilir
        $r2 = $push->toCustomer($a, 'Elle', 'Mesaj', [], 'manuel');
        $this->assertFalse($r2['suppressed']);
        $this->assertSame(1, $r2['sent'], 'manuel gönderim sessiz saatte de çıkar');

        // sabah 06:30 hâlâ sessiz (gece yarısını sarar), 08:00 değil
        $this->assertTrue((new Push($this->pdo, $fake, (int) strtotime(date('Y-m-d') . ' 06:30:00')))->inQuietHours());
        $this->assertFalse((new Push($this->pdo, $fake, (int) strtotime(date('Y-m-d') . ' 08:00:00')))->inQuietHours());
    }

    // ── 3) Menü yayını: mükerrer atlanır, cihazsız müşteri loglanmaz ─
    public function testMenuPublishSkipsDuplicates(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $b = seed_customer($this->pdo, 'B AŞ', 100.0);
        $c = seed_customer($this->pdo, 'C AŞ', 100.0); // cihazsız
        $this->addToken(str_repeat('a', 64), $a);
        $this->addToken(str_repeat('b', 64), $b);
        $fake = new FakeApns();
        $push = new Push($this->pdo, $fake, $this->noon());

        $r1 = $push->menuYayinlandi(7, 'Temmuz Menüsü', [$a, $b, $c]);
        $this->assertSame(2, $r1['pushed']);
        $this->assertSame(1, $r1['no_device'], 'cihazsız müşteri atlandı (loglanmadı)');
        $this->assertCount(2, $fake->sentCalls);
        $this->assertSame('Menünüz yayınlandı', $fake->sentCalls[0]['title']);
        $this->assertSame('/m/menu.php', $fake->sentCalls[0]['data']['url'] ?? null);

        // Aynı menü tekrar yayınlandı → kimseye ikinci push YOK
        $r2 = $push->menuYayinlandi(7, 'Temmuz Menüsü', [$a, $b, $c]);
        $this->assertSame(0, $r2['pushed'], 'mükerrer koruması');
        $this->assertSame(2, $r2['skipped']);
        $this->assertCount(2, $fake->sentCalls, 'APNs tekrar çağrılmadı');

        // FARKLI menü aynı müşteriye gider
        $r3 = $push->menuYayinlandi(8, 'Ağustos Menüsü', [$a]);
        $this->assertSame(1, $r3['pushed'], 'farklı menü engellenmez');

        $n = (int) $this->pdo->query("SELECT COUNT(*) FROM push_log WHERE kind='menu'")->fetchColumn();
        $this->assertSame(3, $n, 'log: menü 7 için 2 + menü 8 için 1');
    }

    // ── 4) Reminder: sadece girmemişlere + günde 1 (idempotent) ─
    public function testReminderOnlyToMissingAndOncePerDay(): void
    {
        $a = seed_customer($this->pdo, 'Girmiş AŞ', 100.0);
        $b = seed_customer($this->pdo, 'Girmemiş AŞ', 100.0);
        $c = seed_customer($this->pdo, 'Cihazsız AŞ', 100.0);
        $this->addToken(str_repeat('a', 64), $a);
        $this->addToken(str_repeat('b', 64), $b);

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $this->pdo->prepare(
            "INSERT INTO orders (customer_id, order_date, meal, persons, status, entered_by)
             VALUES (?, ?, 'ogle', 50, 'gonderildi', 'musteri')"
        )->execute([$a, $tomorrow]);

        $fake = new FakeApns();
        $push = new Push($this->pdo, $fake, $this->noon());

        $r1 = $push->gunlukHatirlatma();
        $this->assertSame(1, $r1['pushed'], 'sadece sayı girmemiş + cihazlı müşteri');
        $this->assertSame(1, $r1['skipped_entered'], 'yarına siparişi olan atlandı');
        $this->assertCount(1, $fake->sentCalls);
        $this->assertStringContainsString('Yarının kişi sayısını girmeyi unutmayın', $fake->sentCalls[0]['body']);
        $this->assertStringContainsString('15:30', $fake->sentCalls[0]['body']);

        // İkinci çağrı aynı gün → kimseye tekrar atmaz (cron çifte çalışsa da güvenli)
        $r2 = $push->gunlukHatirlatma();
        $this->assertSame(0, $r2['pushed'], 'günde 1 — idempotent');
        $this->assertSame(1, $r2['skipped_dup']);
        $this->assertCount(1, $fake->sentCalls, 'APNs tekrar çağrılmadı');

        $log = $this->pdo->query("SELECT customer_id FROM push_log WHERE kind='reminder'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([$b], array_map('intval', $log), 'reminder logu sadece girmemiş müşteriye');
    }

    // ── 5) Admin token hedefleme + kayıt upsert'i ─────────────
    public function testToAdminsTargetsOnlyAdminTokens(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $this->addToken(str_repeat('a', 64), $a);            // müşteri cihazı
        $this->addToken(str_repeat('e', 64), null, 1);        // admin cihazı
        $fake = new FakeApns();
        $push = new Push($this->pdo, $fake, $this->noon());

        $r = $push->toAdmins('Yeni talep: A AŞ', 'Konu', ['url' => '/talepler.php'], 'talep_yeni');
        $this->assertSame(1, $r['sent']);
        $this->assertCount(1, $fake->sentCalls);
        $this->assertSame(str_repeat('e', 64), $fake->sentCalls[0]['token'], 'sadece admin token');

        // toAll ise sadece müşteri token'ları
        $r2 = $push->toAll('Duyuru', 'Mesaj');
        $this->assertSame(str_repeat('a', 64), $fake->sentCalls[1]['token'], 'toAll admin cihazına gitmez');
        $this->assertSame(1, $r2['sent']);

        // admin kayıt upsert'i (public/push-register.php'nin SQLite dalı): sahiplik admin'e geçer
        $this->pdo->prepare(
            "INSERT INTO push_tokens (platform, token, user_id) VALUES (?, ?, ?)
             ON CONFLICT(token) DO UPDATE SET user_id = excluded.user_id, customer_id = NULL, cuid = NULL, last_seen = datetime('now')"
        )->execute(['ios', str_repeat('a', 64), 2]);
        $row = $this->pdo->query('SELECT customer_id, user_id FROM push_tokens WHERE token = ' . $this->pdo->quote(str_repeat('a', 64)))->fetch();
        $this->assertNull($row['customer_id'], 'müşteri sahipliği temizlendi');
        $this->assertSame(2, (int) $row['user_id'], 'token artık admin\'e kayıtlı');
    }

    // ── 6) APNs yapılandırılmamış: sessiz no-op + log, akış kırılmaz ─
    public function testUnconfiguredApnsIsSilentNoopButLogs(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $this->addToken(str_repeat('a', 64), $a);
        $fake = new FakeApns(false); // configured()=false

        $push = new Push($this->pdo, $fake, $this->noon());
        $r = $push->toCustomer($a, 'Test', 'Mesaj', [], 'talep_cevap');

        $this->assertSame(0, $r['sent']);
        $this->assertCount(0, $fake->sentCalls, 'APNs hiç çağrılmadı');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM push_log')->fetchColumn(), 'yine de loglandı');
    }

    // ── 7) Gerçek badge: son app açılışından sonraki cevaplar + yalnız bugünkü menü ──
    public function testBadgeCountUsesLastTokenSeenAndRelevantKinds(): void
    {
        $a = seed_customer($this->pdo, 'Badge AŞ', 100.0);
        $token = str_repeat('a', 64);
        $this->addToken($token, $a);

        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $this->pdo->prepare('UPDATE push_tokens SET last_seen = ? WHERE token = ?')
            ->execute([$today . ' 09:00:00', $token]);

        $this->addLog($a, 'talep_cevap', $today . ' 08:00:00'); // app açılmadan önce → görüldü
        $this->addLog($a, 'talep_cevap', $today . ' 10:00:00'); // sayılır
        $this->addLog($a, 'menu', $today . ' 11:00:00');        // bugünkü menü → sayılır
        $this->addLog($a, 'menu', $yesterday . ' 23:00:00');    // eski menü → sayılmaz
        $this->addLog($a, 'manuel', $today . ' 11:30:00');      // badge türü değil
        $this->addLog($a, 'talep_cevap', $today . ' 12:00:00', 0);    // gönderilmedi
        $this->addLog($a, 'talep_cevap', $today . ' 12:05:00', 1, 1); // bastırıldı

        $repo = new Repo($this->pdo);
        $this->assertSame(2, $repo->badgeCountFor($a));

        $this->pdo->prepare('UPDATE push_tokens SET last_seen = ? WHERE token = ?')
            ->execute([$today . ' 12:30:00', $token]);
        $this->assertSame(0, $repo->badgeCountFor($a), 'app tekrar açılınca sayaç sıfırlanır');
    }

    public function testCustomerPushAddsCurrentEventToBadge(): void
    {
        $a = seed_customer($this->pdo, 'Badge B AŞ', 100.0);
        $token = str_repeat('b', 64);
        $this->addToken($token, $a);
        $today = date('Y-m-d');
        $this->pdo->prepare('UPDATE push_tokens SET last_seen = ? WHERE token = ?')
            ->execute([$today . ' 09:00:00', $token]);
        $this->addLog($a, 'talep_cevap', $today . ' 10:00:00');

        $fake = new FakeApns();
        $push = new Push($this->pdo, $fake, (int) strtotime($today . ' 12:00:00'));
        $push->toCustomer($a, 'Talebinize cevap geldi', 'Konu', ['url' => '/m/talep.php?r=7'], 'talep_cevap');

        $this->assertSame(2, $fake->sentCalls[0]['badge'], 'önceki 1 + gönderilen güncel olay');
        $this->assertSame('/m/talep.php?r=7', $fake->sentCalls[0]['data']['url']);

        $push->toCustomer($a, 'Hatırlatma', 'Sayı girin', ['url' => '/m/siparis.php'], 'reminder');
        $this->assertSame(2, $fake->sentCalls[1]['badge'], 'reminder badge sayısını artırmaz');
    }

    public function testApnsPayloadAcceptsRealAndZeroBadge(): void
    {
        $probe = new PayloadProbeApns();
        $payload = $probe->payload('Başlık', 'Mesaj', ['url' => '/m/menu.php'], 7);
        $this->assertSame(7, $payload['aps']['badge']);
        $this->assertSame('/m/menu.php', $payload['url']);

        $zero = $probe->payload('Başlık', 'Mesaj', [], -4);
        $this->assertSame(0, $zero['aps']['badge'], 'negatif badge sıfıra sıkıştırılır');

        $none = $probe->payload('Başlık', 'Mesaj', [], null);
        $this->assertArrayNotHasKey('badge', $none['aps']);
    }

    // ── 8) migrate_023 + iki şema eş ──────────────────────────
    public function testMigrate023AndSchemaParity(): void
    {
        // SQLite test şeması push_tokens.user_id + push_log kolonlarını içeriyor (fresh_db uyguladı)
        $cols = array_column($this->pdo->query('PRAGMA table_info(push_tokens)')->fetchAll(), 'name');
        $this->assertContains('user_id', $cols, 'push_tokens.user_id (migrate_023)');

        $plCols = array_column($this->pdo->query('PRAGMA table_info(push_log)')->fetchAll(), 'name');
        foreach (['id', 'kind', 'customer_id', 'user_id', 'ref', 'title', 'body', 'sent', 'dead', 'suppressed', 'created_at'] as $c) {
            $this->assertContains($c, $plCols, "push_log.$c");
        }

        // Sessiz saat defaultları seed'li
        $st = $this->pdo->query("SELECT anahtar, deger FROM ayar WHERE anahtar LIKE 'push_quiet%' ORDER BY anahtar");
        $this->assertSame(
            [['anahtar' => 'push_quiet_end', 'deger' => '07:00'], ['anahtar' => 'push_quiet_start', 'deger' => '21:00']],
            $st->fetchAll()
        );

        // migrate_023.sql (MySQL): idempotent kalıplar + aynı kolonlar
        $sql = (string) file_get_contents(__DIR__ . '/../sql/migrate_023.sql');
        $this->assertStringContainsString('information_schema.COLUMNS', $sql, 'user_id yoksa-ekle guard');
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `push_log`', $sql);
        $this->assertStringContainsString('INSERT IGNORE INTO `ayar`', $sql);
        foreach (['`kind`', '`customer_id`', '`user_id`', '`ref`', '`title`', '`body`', '`sent`', '`dead`', '`suppressed`', '`created_at`'] as $c) {
            $this->assertStringContainsString($c, $sql, "migrate_023 push_log $c");
        }

        // MySQL kanonik şema da eş (schema_v2.sql)
        $canon = (string) file_get_contents(__DIR__ . '/../sql/schema_v2.sql');
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `push_tokens`', $canon);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `push_log`', $canon);
        $this->assertStringContainsString('`user_id`', $canon);
        $this->assertStringContainsString("('push_quiet_start', '21:00')", $canon);

        // Badge kesimi ve push_log aynı saat ekseninde olmalı (MariaDB NOW() UTC sapması yok).
        foreach ([__DIR__ . '/../public/m/push-register.php', __DIR__ . '/../public/push-register.php'] as $registerFile) {
            $register = (string) file_get_contents($registerFile);
            $this->assertStringContainsString("date('Y-m-d H:i:s')", $register);
            $this->assertStringContainsString('last_seen', $register);
        }
    }
}
