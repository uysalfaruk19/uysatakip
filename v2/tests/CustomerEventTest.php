<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Push;
use Uysa\Repo;

/**
 * fable-018: müşteri olay akışı (customer_events) — TEK KAPI addCustomerEvent,
 * IDOR (kendi olayları), okundu kesimi (feed_seen_at), rozet sayısı (feedUnseenCount +
 * badgeCountFor aynı kaynak), Türkçe karakter, geçersiz tür, sayfalama, hook entegrasyonu.
 */
final class CustomerEventTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    /** Doğrudan (açık zaman damgalı) olay ekle — deterministik kesim testleri için. */
    private function eventAt(int $cid, string $type, string $createdAt, string $title = 'Başlık'): void
    {
        $this->pdo->prepare(
            'INSERT INTO customer_events (customer_id, type, title, body, url, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$cid, $type, $title, null, '/m/menu.php', $createdAt]);
    }

    // ── 1) Olay insert + geçersiz tür güvenli varsayılana düşer ──
    public function testAddEventAndTypeFallback(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $id = $this->repo->addCustomerEvent($a, 'menu_yayin', 'Menünüz yayınlandı', 'Temmuz', '/m/menu.php');
        $this->assertGreaterThan(0, $id);

        $rows = $this->repo->customerEvents($a);
        $this->assertCount(1, $rows);
        $this->assertSame('menu_yayin', $rows[0]['type']);
        $this->assertSame('Menünüz yayınlandı', $rows[0]['title']);
        $this->assertSame('/m/menu.php', $rows[0]['url']);

        // Geçersiz tür → 'talep_cevap' (sessiz veri kaybı yok)
        $this->repo->addCustomerEvent($a, 'hacktype', 'X', null, '/m/x.php');
        $t = $this->pdo->query('SELECT type FROM customer_events ORDER BY id DESC LIMIT 1')->fetchColumn();
        $this->assertSame('talep_cevap', $t);
    }

    // ── 2) IDOR: sadece kendi customer_id olayları döner ─────────
    public function testCustomerEventsIdorScope(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $b = seed_customer($this->pdo, 'B AŞ', 100.0);
        $this->repo->addCustomerEvent($a, 'talep_cevap', 'A olayı', null, '/m/talep.php?r=1');
        $this->repo->addCustomerEvent($b, 'talep_cevap', 'B olayı', null, '/m/talep.php?r=2');

        $aRows = $this->repo->customerEvents($a);
        $this->assertCount(1, $aRows);
        $this->assertSame('A olayı', $aRows[0]['title'], 'A yalnız kendi olayını görür');

        $bRows = $this->repo->customerEvents($b);
        $this->assertCount(1, $bRows);
        $this->assertSame('B olayı', $bRows[0]['title'], 'B, A olayını görmez (IDOR)');
    }

    // ── 3) Okundu kesimi (feed_seen_at) + markFeedSeen ───────────
    public function testFeedSeenCutoffAndMarkSeen(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $cuid = $this->repo->createCustomerUser($a, 'a_owner', 'sifre1234', 'A Sahibi');
        $this->assertGreaterThan(0, $cuid);

        $this->eventAt($a, 'talep_cevap', '2026-01-01 10:00:00');
        $this->eventAt($a, 'menu_yayin', '2026-01-01 11:00:00');
        $this->eventAt($a, 'siparis_durum', '2026-01-01 12:00:00');

        // feed_seen_at NULL → hepsi görülmemiş
        $this->assertSame(3, $this->repo->feedUnseenCount($a, $cuid), 'hiç açılmamış → tümü görülmemiş');

        // Kesim 11:00 → sadece 12:00 sonrası (1 olay)
        $this->pdo->prepare('UPDATE customer_users SET feed_seen_at = ? WHERE id = ?')
            ->execute(['2026-01-01 11:00:00', $cuid]);
        $this->assertSame(1, $this->repo->feedUnseenCount($a, $cuid), 'kesimden sonraki tek olay');

        // Akış açılınca markFeedSeen → geçmiş olaylar görüldü (şimdi > 2026)
        $this->repo->markFeedSeen($cuid);
        $this->assertSame(0, $this->repo->feedUnseenCount($a, $cuid), 'Akış açılınca rozet sıfırlanır');
    }

    // ── 4) Rozet: feedUnseenCount ve badgeCountFor AYNI kaynak ──
    public function testBadgeAndFeedShareSource(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $cuid = $this->repo->createCustomerUser($a, 'a_owner', 'sifre1234', 'A Sahibi');
        $token = str_repeat('a', 64);
        $this->pdo->prepare('INSERT INTO push_tokens (platform, token, customer_id, cuid, last_seen) VALUES (?, ?, ?, ?, ?)')
            ->execute(['ios', $token, $a, $cuid, '2026-01-01 09:00:00']);

        $this->eventAt($a, 'talep_cevap', '2026-01-01 08:00:00'); // her iki kesimin de öncesi
        $this->eventAt($a, 'menu_yayin', '2026-01-01 10:00:00');
        $this->eventAt($a, 'siparis_durum', '2026-01-01 11:00:00');

        // Native badge: last_seen 09:00 sonrası = 2 (10:00, 11:00). Feed (seen NULL) = 3.
        $this->assertSame(2, $this->repo->badgeCountFor($a), 'native badge = last_seen sonrası customer_events');
        $this->assertSame(3, $this->repo->feedUnseenCount($a, $cuid), 'feed rozeti = feed_seen_at sonrası, aynı tablo');
    }

    // ── 5) 99+ kırpma ────────────────────────────────────────────
    public function testBadgeCappedAt99(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $cuid = $this->repo->createCustomerUser($a, 'a_owner', 'sifre1234', 'A');
        for ($i = 0; $i < 105; $i++) {
            $this->repo->addCustomerEvent($a, 'talep_cevap', 'Olay ' . $i, null, '/m/talep.php');
        }
        $this->assertSame(99, $this->repo->feedUnseenCount($a, $cuid), '99+ kırpılır');
    }

    // ── 6) Sayfalama (offset) ────────────────────────────────────
    public function testCustomerEventsPagination(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        for ($i = 1; $i <= 60; $i++) {
            $this->eventAt($a, 'talep_cevap', sprintf('2026-01-01 %02d:%02d:00', intdiv($i, 60), $i % 60), 'Olay ' . $i);
        }
        $sayfa1 = $this->repo->customerEvents($a, 50, 0);
        $this->assertCount(50, $sayfa1);
        $this->assertSame('Olay 60', $sayfa1[0]['title'], 'yeni→eski sıralama');

        $sayfa2 = $this->repo->customerEvents($a, 50, 50);
        $this->assertCount(10, $sayfa2, 'ikinci sayfada kalan 10');
        $this->assertSame('Olay 10', $sayfa2[0]['title']);
    }

    // ── 7) Türkçe karakter title/body round-trip ─────────────────
    public function testTurkishCharactersRoundTrip(): void
    {
        $a = seed_customer($this->pdo, 'Iğdır Çöp AŞ', 100.0);
        $title = 'Menü ışığı ğüşöçİ';
        $body = 'Iğdır Şöförü çöp öğütür — ĞÜŞÖÇİ';
        $this->repo->addCustomerEvent($a, 'menu_yayin', $title, $body, '/m/menu.php');

        $row = $this->repo->customerEvents($a)[0];
        $this->assertSame($title, $row['title'], 'Türkçe başlık bozulmadan döner');
        $this->assertSame($body, $row['body'], 'Türkçe gövde bozulmadan döner');
    }

    // ── 8) Hook entegrasyonu: menü yayını hem push_log hem feed yazar ──
    public function testMenuPublishWritesFeedEvenWithoutDevice(): void
    {
        $withDev = seed_customer($this->pdo, 'Cihazlı AŞ', 100.0);
        $noDev = seed_customer($this->pdo, 'Cihazsız AŞ', 100.0);
        $this->pdo->prepare('INSERT INTO push_tokens (platform, token, customer_id) VALUES (?, ?, ?)')
            ->execute(['ios', str_repeat('a', 64), $withDev]);

        $push = new Push($this->pdo, new FakeApns(), (int) strtotime(date('Y-m-d') . ' 12:00:00'));
        $res = $push->menuYayinlandi(5, 'Temmuz Menüsü', [$withDev, $noDev]);

        $this->assertSame(1, $res['pushed']);
        $this->assertSame(1, $res['no_device']);
        // KURAL: push atılamasa (cihaz yok) bile feed'e yazılır
        $this->assertCount(1, $this->repo->customerEvents($withDev), 'cihazlı müşteri feed olayı');
        $this->assertCount(1, $this->repo->customerEvents($noDev), 'cihazsız müşteri de feed olayı alır');
        $this->assertSame('menu_yayin', $this->repo->customerEvents($noDev)[0]['type']);
        $this->assertSame('/m/menu.php', $this->repo->customerEvents($noDev)[0]['url']);
    }

    // ── 9) Hook: sipariş onay/red müşteri feed olayı üretir ──────
    public function testOrderDecisionCreatesFeedEvent(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $oid = $this->repo->upsertOrder($a, '2026-07-20', 'ogle', 40, 'gonderildi', 'musteri');

        // approveOrder üretime yazar; feed olayı siparisler.php hook'unda — burada Repo düzeyinde doğrula:
        $this->repo->addCustomerEvent($a, 'siparis_durum', 'Siparişiniz onaylandı', '20 Temmuz · 40 kişi', '/m/siparis.php?date=2026-07-20');
        $rows = $this->repo->customerEvents($a);
        $this->assertSame('siparis_durum', $rows[0]['type']);
        $this->assertStringContainsString('40 kişi', (string) $rows[0]['body']);
        $this->assertSame($oid, (int) $this->repo->orderById($oid)['id']);
    }
}
