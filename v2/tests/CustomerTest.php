<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\CustomerAuth;
use Uysa\Helpers;
use Uysa\Repo;

/**
 * M6 müşteri uygulaması: kimlik, sipariş scope, IDOR, talep, admin onay→production.
 * En kritik test: müşteri A, müşteri B'nin verisini ASLA göremez (IDOR).
 */
final class CustomerTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;
    private CustomerAuth $auth;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
        $this->auth = new CustomerAuth($this->pdo);
        $_SESSION = [];
    }

    // ── Kimlik ────────────────────────────────────────────────
    public function testCustomerLoginSuccessSetsCustomerScope(): void
    {
        $cid = seed_customer($this->pdo, 'CANTAŞ', 328.0);
        $this->auth->createCustomerUser($cid, 'cantas', 'sifre123', 'Cantaş A.Ş.');

        $ok = $this->auth->login('cantas', 'sifre123');
        $this->assertNotNull($ok, 'doğru şifre → giriş');
        $this->assertSame($cid, (int) $_SESSION['customer_id'], 'oturuma customer_id yazıldı');

        $this->assertNull($this->auth->login('cantas', 'yanlis'), 'yanlış şifre → null');
        $this->assertNull($this->auth->login('yok', 'sifre123'), 'olmayan kullanıcı → null');
    }

    public function testInactiveCustomerCannotLogin(): void
    {
        $cid = seed_customer($this->pdo, 'PASIF AŞ', 100.0);
        $this->auth->createCustomerUser($cid, 'pasif', 'sifre123');
        $this->pdo->prepare('UPDATE customers SET is_active = 0 WHERE id = ?')->execute([$cid]);
        $this->assertNull($this->auth->login('pasif', 'sifre123'), 'pasif firma → giriş yok');
    }

    // ── Sipariş: scope + unique ───────────────────────────────
    public function testOrderUpsertScopedAndUnique(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $b = seed_customer($this->pdo, 'B AŞ', 200.0);

        $this->repo->upsertOrder($a, '2026-07-10', 'ogle', 120, 'gonderildi', 'musteri');
        $this->repo->upsertOrder($a, '2026-07-10', 'ogle', 150, 'gonderildi', 'musteri'); // aynı → güncelle

        $rows = (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $this->assertSame(1, $rows, 'UNIQUE(customer,date,meal): tek satır');

        $oa = $this->repo->customerOrder($a, '2026-07-10', 'ogle');
        $this->assertSame(150, (int) $oa['persons'], 'son değer');

        // B, A'nın aynı tarih/öğün siparişini göremez
        $ob = $this->repo->customerOrder($b, '2026-07-10', 'ogle');
        $this->assertNull($ob, 'B kendi kapsamında A siparişini görmez');
    }

    // ── IDOR: A, B'nin HİÇBİR verisini göremez ────────────────
    public function testIdorCustomerCannotSeeOthersData(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $b = seed_customer($this->pdo, 'B AŞ', 200.0);

        // A'nın verileri: sipariş, üretim (cari), talep
        $this->repo->upsertOrder($a, '2026-07-10', 'ogle', 120, 'gonderildi', 'musteri');
        $this->repo->upsertProduction($a, '2026-07-05', 90, 100.0, 'ogle');
        $this->repo->addCari('customer', $a, '2026-07-06', 'alacak', 5000);
        $reqA = $this->repo->createRequest($a, null, 'talep', 'A gizli konu');
        $this->repo->addRequestMessage($reqA, 'musteri', 'A gizli mesaj');

        // B'nin kapsamında A'nın hiçbir şeyi görünmemeli
        $this->assertCount(0, $this->repo->customerOrders($b), 'B sipariş listesi boş');
        $this->assertCount(0, $this->repo->customerLedger($b, '2026-07'), 'B ekstresi boş (A üretimi sızmaz)');
        $this->assertEqualsWithDelta(0.0, $this->repo->customerBalance($b), 0.001, 'B bakiyesi 0');
        $this->assertCount(0, $this->repo->customerRequests($b), 'B talep listesi boş');

        // En kritik: B, A'nın talep id'siyle sorgu yapsa bile null (scope guard)
        $this->assertNull($this->repo->requestForCustomer($reqA, $b), 'B, A talebine erişemez (IDOR)');
        // A kendi talebini görür (kontrol)
        $this->assertNotNull($this->repo->requestForCustomer($reqA, $a), 'A kendi talebini görür');

        // A'nın ekstresi kendi verisini içerir (pozitif kontrol)
        $this->assertNotCount(0, $this->repo->customerLedger($a, '2026-07'), 'A kendi ekstresini görür');
    }

    // ── Talep + mesaj dizisi ──────────────────────────────────
    public function testRequestThreadOrdered(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $req = $this->repo->createRequest($a, null, 'sikayet', 'Menü değişikliği');
        $this->repo->addRequestMessage($req, 'musteri', 'İlk mesaj');
        $this->repo->addRequestMessage($req, 'uysa', 'Cevabımız');
        $this->repo->addRequestMessage($req, 'musteri', 'Teşekkürler');

        $msgs = $this->repo->requestMessages($req);
        $this->assertCount(3, $msgs);
        $this->assertSame('İlk mesaj', $msgs[0]['body'], 'eski→yeni sıralı');
        $this->assertSame('uysa', $msgs[1]['sender']);

        $this->repo->setRequestStatus($req, 'cozuldu');
        $this->assertCount(0, $this->repo->openRequests(), 'çözülen açık listede yok');
    }

    // ── Admin onay → production (fiyat snapshot, tek yön) ──────
    public function testApproveOrderWritesProductionSnapshotOnce(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 250.0);
        $orderId = $this->repo->upsertOrder($a, '2026-07-10', 'ogle', 100, 'gonderildi', 'musteri');

        $res = $this->repo->approveOrder($orderId);
        $this->assertNotNull($res);
        $this->assertEqualsWithDelta(25000.0, $res['amount'], 0.001, '100 × 250 snapshot');

        // Production yazıldı, fiyat sabitlendi
        $prod = $this->pdo->query('SELECT * FROM production')->fetch();
        $this->assertEqualsWithDelta(250.0, (float) $prod['unit_price_snap'], 0.001, 'fiyat snapshot');
        $this->assertSame($orderId, (int) $prod['order_id'], 'order bağı');

        // Sipariş durumu onaylandi
        $o = $this->repo->orderById($orderId);
        $this->assertSame('onaylandi', $o['status']);

        // Tekrar onay → duplicate üretim YOK (UNIQUE + tek yön)
        $this->repo->approveOrder($orderId);
        $cnt = (int) $this->pdo->query('SELECT COUNT(*) FROM production')->fetchColumn();
        $this->assertSame(1, $cnt, 'tekrar onay duplicate üretmez');

        // Onaylanınca artık onay kuyruğunda değil
        $this->assertCount(0, $this->repo->pendingOrders());
        $this->assertSame(0, $this->repo->pendingOrdersCount());
    }

    public function testRejectOrderNoProduction(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 250.0);
        $orderId = $this->repo->upsertOrder($a, '2026-07-11', 'ogle', 80, 'gonderildi', 'musteri');

        $this->assertTrue($this->repo->rejectOrder($orderId));
        $o = $this->repo->orderById($orderId);
        $this->assertSame('reddedildi', $o['status']);
        $cnt = (int) $this->pdo->query('SELECT COUNT(*) FROM production')->fetchColumn();
        $this->assertSame(0, $cnt, 'reddedilen sipariş üretime yazılmaz');
    }

    // ── Sipariş son değişiklik kuralı (bir gün önce 16:00) ─────
    public function testOrderEditableDeadline(): void
    {
        // 2026-07-10 siparişi → deadline 2026-07-09 16:00 (fable-001)
        $before = strtotime('2026-07-09 15:59:00');
        $after = strtotime('2026-07-09 16:01:00');
        $this->assertTrue(Helpers::orderEditable('2026-07-10', $before), '16:00 öncesi (15:59) değiştirilebilir');
        $this->assertFalse(Helpers::orderEditable('2026-07-10', $after), '16:00 sonrası (16:01) kilitli');
        $this->assertSame(strtotime('2026-07-09 16:00:00'), Helpers::orderDeadline('2026-07-10'), 'deadline bir gün önce 16:00');
    }

    // ── Haftalık şerit sayı kaynağı (fable-002) ────────────────
    public function testCustomerDailyCountsMergesProductionOverOrders(): void
    {
        $cid = seed_customer($this->pdo, 'BOMİ', 300.0);
        $other = seed_customer($this->pdo, 'BAŞKASI', 200.0);
        $insOrder = $this->pdo->prepare(
            "INSERT INTO orders (customer_id, order_date, meal, persons, status, entered_by)
             VALUES (?, ?, ?, ?, ?, 'musteri')"
        );
        $insProd = $this->pdo->prepare(
            "INSERT INTO production (customer_id, prod_date, meal, persons, entered_by)
             VALUES (?, ?, ?, ?, 'uysa')"
        );
        // Pzt: sipariş 50 + onay sonrası production 55 → production EZER
        $insOrder->execute([$cid, '2026-07-13', 'ogle', 50, 'onaylandi']);
        $insProd->execute([$cid, '2026-07-13', 'ogle', 55]);
        // Sal: SADECE production (UYSA girdi, müşteri app'ten hiç girmedi) → yine görünür
        $insProd->execute([$cid, '2026-07-14', 'ogle', 40]);
        // Çar: sadece sipariş (henüz onaysız) → sipariş sayısı
        $insOrder->execute([$cid, '2026-07-15', 'ogle', 30, 'gonderildi']);
        // Per: reddedilen sipariş → GÖRÜNMEZ
        $insOrder->execute([$cid, '2026-07-16', 'ogle', 99, 'reddedildi']);
        // Sal: iki öğün toplanır (production 40 + akşam 10 = 50)
        $insProd->execute([$cid, '2026-07-14', 'aksam', 10]);
        // Başka müşterinin verisi sızmaz (IDOR)
        $insProd->execute([$other, '2026-07-13', 'ogle', 500]);

        $counts = $this->repo->customerDailyCounts($cid, '2026-07-13', '2026-07-19');
        $this->assertSame(55, $counts['2026-07-13'], 'production sipariş sayısını ezer');
        $this->assertSame(50, $counts['2026-07-14'], 'sadece UYSA girişi de görünür, öğünler toplanır');
        $this->assertSame(30, $counts['2026-07-15'], 'onaysız sipariş görünür');
        $this->assertArrayNotHasKey('2026-07-16', $counts, 'reddedilen görünmez');
    }

    // ── fable-019: menü fallback (bugün boşsa ileriye bak) ─────
    /** Yayınlanmış (published, audience=all) menü oluşturur; menu id döner. */
    private function seedPublishedMenu(): int
    {
        $id = $this->repo->upsertMenu('Temmuz 2026', '2026-07-01', '2026-07-31', 'all');
        $this->repo->publishMenu($id, true);
        return $id;
    }

    public function testMenuFallbackLooksForwardWhenTodayEmpty(): void
    {
        $cid = seed_customer($this->pdo, 'MENÜ AŞ', 100.0);
        $mid = $this->seedPublishedMenu();
        // Bugün = 19 Tem PAZAR (servis yok, kalem yok); ertesi gün Pazartesi menülü
        $this->repo->upsertMenuItem($mid, '2026-07-20', 'ogle', 'Mercimek çorbası · Tavuk sote · Şehriye pilavı');

        $res = $this->repo->menuForCustomerFrom($cid, '2026-07-19', 7);
        $this->assertNotNull($res, 'ileriye bakıp menülü günü buldu');
        $this->assertSame('2026-07-20', $res['date'], 'ilk menülü gün = pazartesi');
        $this->assertTrue($res['ahead'], 'bugün değil, ileri gün (etiket açık olmalı)');
        $this->assertArrayHasKey('ogle', $res['items']);
        $this->assertStringContainsString('Tavuk sote', $res['items']['ogle'], 'Türkçe yemek adı korunur');
    }

    public function testMenuFallbackNoneWithin7DaysReturnsNull(): void
    {
        $cid = seed_customer($this->pdo, 'X AŞ', 100.0);
        $mid = $this->seedPublishedMenu();
        // Menü kalemi 7 günlük pencerenin (19–26 Tem) DIŞINDA → "Menü yakında" kalır
        $this->repo->upsertMenuItem($mid, '2026-07-30', 'ogle', 'Uzak menü');

        $this->assertNull($this->repo->menuForCustomerFrom($cid, '2026-07-19', 7), '7 gün içinde menü yoksa null');
    }

    public function testMenuTodayTakesPriorityNotAhead(): void
    {
        $cid = seed_customer($this->pdo, 'Y AŞ', 100.0);
        $mid = $this->seedPublishedMenu();
        $this->repo->upsertMenuItem($mid, '2026-07-20', 'ogle', 'Bugünkü menü');
        $this->repo->upsertMenuItem($mid, '2026-07-21', 'ogle', 'Yarınki menü');

        $res = $this->repo->menuForCustomerFrom($cid, '2026-07-20', 7);
        $this->assertSame('2026-07-20', $res['date'], 'bugün menü varsa bugün');
        $this->assertFalse($res['ahead'], 'bugün → ahead=false');
    }

    // ── fable-019: yarının sayısı birleşik + devir ─────────────
    public function testTomorrowCountFromProductionWhenNoOrder(): void
    {
        $cid = seed_customer($this->pdo, 'P AŞ', 100.0);
        // UYSA production girdi (8 müşteri/419 senaryosu), müşteri app'ten hiç order girmedi
        $this->pdo->prepare(
            "INSERT INTO production (customer_id, prod_date, meal, persons, entered_by) VALUES (?, ?, 'ogle', 419, 'uysa')"
        )->execute([$cid, '2026-07-20']);

        $this->assertNull($this->repo->customerOrder($cid, '2026-07-20', 'ogle'), 'kendi siparişi yok');
        $counts = $this->repo->customerDailyCounts($cid, '2026-07-20', '2026-07-20');
        $this->assertSame(419, $counts['2026-07-20'] ?? null, 'kart birleşik sayıyı gösterir (UYSA planı)');
    }

    public function testOwnOrderTakesPriorityOverProduction(): void
    {
        $cid = seed_customer($this->pdo, 'O AŞ', 100.0);
        $this->repo->upsertOrder($cid, '2026-07-20', 'ogle', 200, 'gonderildi', 'musteri');
        $this->pdo->prepare(
            "INSERT INTO production (customer_id, prod_date, meal, persons, entered_by) VALUES (?, ?, 'ogle', 419, 'uysa')"
        )->execute([$cid, '2026-07-20']);

        $order = $this->repo->customerOrder($cid, '2026-07-20', 'ogle');
        $this->assertNotNull($order, 'kendi siparişi öncelikli → durum korunur');
        $this->assertSame(200, (int) $order['persons'], 'order sayısı gösterilir, production değil');
    }

    public function testCarryForwardLastKnownDailyCount(): void
    {
        $cid = seed_customer($this->pdo, 'C AŞ', 100.0);
        // Geçmiş servis günü: 17 Tem Cuma = 120 kişi (üretim). Yarın (20 Tem Pzt) için hiç kayıt yok.
        $this->pdo->prepare(
            "INSERT INTO production (customer_id, prod_date, meal, persons, entered_by) VALUES (?, '2026-07-17', 'ogle', 120, 'uysa')"
        )->execute([$cid]);

        $carry = $this->repo->lastKnownDailyCount($cid, '2026-07-20');
        $this->assertNotNull($carry, 'devir: son servis gününden sayı');
        $this->assertSame('2026-07-17', $carry['date'], 'pazar (19) + cmt (18, kayıtsız) atlanır → cuma');
        $this->assertSame(120, $carry['persons'], 'son bilinen sayı devrolur');
    }

    public function testCarryForwardNullWhenNoHistory(): void
    {
        $cid = seed_customer($this->pdo, 'YENİ AŞ', 100.0);
        $this->assertNull($this->repo->lastKnownDailyCount($cid, '2026-07-20'), 'geçmiş kayıt yoksa devir yok');
    }

    public function testExplicitZeroTomorrowIsShownNotCarried(): void
    {
        $cid = seed_customer($this->pdo, 'Z AŞ', 100.0);
        // Geçmişte 100 var; müşteri yarın 0 bildirdi (kapalı) — 0 GERÇEK sayıdır, devir çalışmamalı
        $this->pdo->prepare(
            "INSERT INTO production (customer_id, prod_date, meal, persons, entered_by) VALUES (?, '2026-07-17', 'ogle', 100, 'uysa')"
        )->execute([$cid]);
        $this->pdo->prepare(
            "INSERT INTO orders (customer_id, order_date, meal, persons, status, entered_by) VALUES (?, '2026-07-20', 'ogle', 0, 'gonderildi', 'musteri')"
        )->execute([$cid]);

        $counts = $this->repo->customerDailyCounts($cid, '2026-07-20', '2026-07-20');
        $this->assertArrayHasKey('2026-07-20', $counts, '0 bir kayıttır → panel bunu (0) gösterir');
        $this->assertSame(0, $counts['2026-07-20'], '0 gösterilir, dünkü 100 devrolmaz');
    }

    // ── Admin: müşteri giriş hesabı oluştur (opus-018) ─────────
    public function testCreateCustomerUserUniqueAndBcrypt(): void
    {
        $cid = seed_customer($this->pdo, 'CANTAŞ', 328.0);
        $id = $this->repo->createCustomerUser($cid, 'cantas', 'sifre123', 'Cantaş A.Ş.');
        $this->assertGreaterThan(0, $id, 'giriş oluşturuldu');

        $row = $this->pdo->query('SELECT * FROM customer_users WHERE id = ' . $id)->fetch();
        $this->assertNotSame('sifre123', $row['password_bcrypt'], 'şifre düz metin değil');
        $this->assertTrue(password_verify('sifre123', $row['password_bcrypt']), 'bcrypt doğrulanır');
        $this->assertTrue($this->auth->login('cantas', 'sifre123') !== null, 'oluşturulan hesapla giriş yapılır');

        // Aynı kullanıcı adı → çakışma (0 döner, exception değil)
        $dup = $this->repo->createCustomerUser($cid, 'cantas', 'baska', null);
        $this->assertSame(0, $dup, 'çakışan kullanıcı adı 0 döner');

        // Liste + reset + pasif
        $users = $this->repo->listCustomerUsers();
        $this->assertCount(1, $users);
        $this->assertSame('CANTAŞ', $users[0]['customer_name'], 'firma adı listede');

        $this->repo->resetCustomerUserPassword($id, 'yenisifre');
        $this->assertNull($this->auth->login('cantas', 'sifre123'), 'eski şifre artık geçmez');
        $this->assertNotNull($this->auth->login('cantas', 'yenisifre'), 'yeni şifre geçer');

        $this->repo->setCustomerUserActive($id, false);
        $this->assertNull($this->auth->login('cantas', 'yenisifre'), 'pasif hesap giriş yapamaz');
    }
}
