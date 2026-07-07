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

    // ── Sipariş son değişiklik kuralı (bir gün önce 15:30) ─────
    public function testOrderEditableDeadline(): void
    {
        // 2026-07-10 siparişi → deadline 2026-07-09 15:30 (opus-018)
        $before = strtotime('2026-07-09 15:29:00');
        $after = strtotime('2026-07-09 15:31:00');
        $this->assertTrue(Helpers::orderEditable('2026-07-10', $before), '15:30 öncesi (15:29) değiştirilebilir');
        $this->assertFalse(Helpers::orderEditable('2026-07-10', $after), '15:30 sonrası (15:31) kilitli');
        $this->assertSame(strtotime('2026-07-09 15:30:00'), Helpers::orderDeadline('2026-07-10'), 'deadline bir gün önce 15:30');
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
