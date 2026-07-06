<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\MenuPdf;
use Uysa\Repo;

/**
 * opus-019: sipariş default (önceki gün sayısı), talep tipi menu/oneri + foto eki,
 * admin allRequests filtre + reply/status, müşteri IDOR (foto dahil), menü PDF üretimi.
 */
final class RequestsAppTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    // ── 1) Sipariş default = önceki günkü sayı ────────────────
    public function testLastPersonsForReturnsPreviousDayCount(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);

        // Hiç veri yokken 0
        $this->assertSame(0, $this->repo->lastPersonsFor($a, 'ogle', '2026-07-10'));

        // Onaylı üretim (önceki gün) → default o sayı
        $this->repo->upsertProduction($a, '2026-07-08', 90, 100.0, 'ogle');
        $this->assertSame(90, $this->repo->lastPersonsFor($a, 'ogle', '2026-07-10'), 'önceki günkü üretim sayısı');

        // Daha yeni bir sipariş (henüz onaysız) → en güncel geçerli
        $this->repo->upsertOrder($a, '2026-07-09', 'ogle', 120, 'gonderildi', 'musteri');
        $this->assertSame(120, $this->repo->lastPersonsFor($a, 'ogle', '2026-07-10'), 'daha yeni sipariş öncelikli');

        // beforeDate sınırı: 2026-07-09'dan ÖNCE → 90 (09 hariç)
        $this->assertSame(90, $this->repo->lastPersonsFor($a, 'ogle', '2026-07-09'), 'beforeDate strict <');

        // Öğün ayrımı: aksam için veri yok → 0
        $this->assertSame(0, $this->repo->lastPersonsFor($a, 'aksam', '2026-07-10'), 'öğün scope');
    }

    public function testLastPersonsForScopedPerCustomer(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $b = seed_customer($this->pdo, 'B AŞ', 100.0);
        $this->repo->upsertProduction($a, '2026-07-08', 90, 100.0, 'ogle');
        $this->assertSame(0, $this->repo->lastPersonsFor($b, 'ogle', '2026-07-10'), 'B, A verisini görmez');
    }

    public function testRejectedOrderNotUsedAsDefault(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $oid = $this->repo->upsertOrder($a, '2026-07-08', 'ogle', 200, 'gonderildi', 'musteri');
        $this->repo->rejectOrder($oid);
        $this->assertSame(0, $this->repo->lastPersonsFor($a, 'ogle', '2026-07-10'), 'reddedilen sipariş default olmaz');
    }

    // ── 2) Talep tipi menu/oneri + foto eki ───────────────────
    public function testRequestMenuAndOneriTypesWithPhoto(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $fid = $this->repo->addFile('20260706_abc.jpg', 'sikayet.jpg', 'image/jpeg', 1234, 'a', 'talep');

        $reqMenu = $this->repo->createRequest($a, null, 'menu', 'Menü önerisi');
        $reqOneri = $this->repo->createRequest($a, null, 'oneri', 'Bir önerim var');
        $this->repo->addRequestMessage($reqMenu, 'musteri', 'Fotoğraflı şikayet', $fid);

        $rowMenu = $this->pdo->query("SELECT type FROM requests WHERE id = $reqMenu")->fetchColumn();
        $rowOneri = $this->pdo->query("SELECT type FROM requests WHERE id = $reqOneri")->fetchColumn();
        $this->assertSame('menu', $rowMenu, "'menu' türü kabul edildi");
        $this->assertSame('oneri', $rowOneri, "'oneri' türü kabul edildi");

        $msgs = $this->repo->requestMessages($reqMenu);
        $this->assertSame($fid, (int) $msgs[0]['file_id'], 'foto eki bağlandı');
        $this->assertSame('sikayet.jpg', $msgs[0]['file_orig'], 'dosya join çalışıyor');
        $this->assertSame('image/jpeg', $msgs[0]['file_mime']);

        // Geçersiz tür → talep'e düşer
        $reqBad = $this->repo->createRequest($a, null, 'hacktype', 'x');
        $this->assertSame('talep', $this->pdo->query("SELECT type FROM requests WHERE id = $reqBad")->fetchColumn());
    }

    // ── 3) Admin allRequests filtre + reply + status ──────────
    public function testAllRequestsFilterAndReply(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $b = seed_customer($this->pdo, 'B AŞ', 100.0);
        $r1 = $this->repo->createRequest($a, null, 'sikayet', 'A şikayet');
        $r2 = $this->repo->createRequest($b, null, 'menu', 'B menü');
        $r3 = $this->repo->createRequest($a, null, 'oneri', 'A öneri');
        $this->repo->setRequestStatus($r3, 'cozuldu');

        $this->assertCount(3, $this->repo->allRequests(), 'hepsi');
        $this->assertCount(2, $this->repo->allRequests(['customer_id' => $a]), 'müşteri filtre');
        $this->assertCount(1, $this->repo->allRequests(['type' => 'menu']), 'tür filtre');
        $this->assertCount(1, $this->repo->allRequests(['status' => 'cozuldu']), 'durum filtre');
        $this->assertCount(1, $this->repo->allRequests(['customer_id' => $a, 'status' => 'acik']), 'kombine filtre');

        // reply → mesaj eklenir + talep 'acik' olur (çözülmüş talebe cevap yeniden açar)
        $this->repo->replyRequest($r3, 'UYSA cevabı');
        $this->assertSame('acik', $this->pdo->query("SELECT status FROM requests WHERE id = $r3")->fetchColumn(), 'cevap talebi açar');
        $msgs = $this->repo->requestMessages($r3);
        $this->assertSame('uysa', $msgs[0]['sender']);

        // msg_count doğru
        $rowsA = $this->repo->allRequests(['customer_id' => $a]);
        $found = false;
        foreach ($rowsA as $row) {
            if ((int) $row['id'] === $r3) {
                $this->assertSame(1, (int) $row['msg_count']);
                $found = true;
            }
        }
        $this->assertTrue($found, 'r3 listede');
    }

    // ── 4) IDOR: müşteri başka firmanın fotosunu indiremez ────
    public function testCustomerFileIdorScope(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $b = seed_customer($this->pdo, 'B AŞ', 100.0);
        $fid = $this->repo->addFile('20260706_secret.jpg', 'gizli.jpg', 'image/jpeg', 100, 'a', 'talep');
        $reqA = $this->repo->createRequest($a, null, 'sikayet', 'A gizli');
        $this->repo->addRequestMessage($reqA, 'musteri', 'foto', $fid);

        $this->assertNotNull($this->repo->customerFile($fid, $a), 'A kendi fotosunu indirir');
        $this->assertNull($this->repo->customerFile($fid, $b), 'B, A fotosunu indiremez (IDOR)');

        // Hiçbir talebe bağlı olmayan dosya (ör. fatura) müşteriye kapalı
        $orphan = $this->repo->addFile('20260706_fatura.jpg', 'fatura.jpg', 'image/jpeg', 100, 'admin', 'fatura');
        $this->assertNull($this->repo->customerFile($orphan, $a), 'talebe bağlı olmayan dosya müşteriye kapalı');
    }

    // ── 5) Menü PDF: gerçek PDF üretilir ──────────────────────
    public function testMenuPdfIsValidPdf(): void
    {
        $items = [
            ['item_date' => '2026-07-06', 'meal' => 'ogle', 'dishes' => "Mercimek Çorbası, Etli Nohut, Şehriye Pilavı"],
            ['item_date' => '2026-07-07', 'meal' => 'ogle', 'dishes' => "Ezogelin, Izgara Köfte, Bulgur"],
        ];
        $bin = MenuPdf::write('Test Menüsü ışığı', $items, '2026-07-06', '2026-07-12');
        $this->assertStringStartsWith('%PDF-', $bin, 'geçerli PDF imzası');
        $this->assertGreaterThan(600, strlen($bin), 'içerik dolu');
        // Boş menü de patlamaz
        $this->assertStringStartsWith('%PDF-', MenuPdf::write('Boş', []));
    }

    // ── 6) Menü müşteri scope: 1 ay geri + IDOR ───────────────
    public function testMenuForCustomerScopeAndOneMonthLimit(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $b = seed_customer($this->pdo, 'B AŞ', 100.0);

        // A-hedefli yayınlanmış menü (bu ay)
        $mid = $this->repo->upsertMenu('Bu ay', date('Y-m-d'), date('Y-m-d', strtotime('+5 day')), 'selected');
        $this->repo->setMenuAudience($mid, 'selected', [$a]);
        $this->repo->publishMenu($mid, true);

        $minEnd = date('Y-m-d', strtotime('-1 month'));
        $this->assertNotNull($this->repo->menuForCustomer($a, $mid, $minEnd), 'A kendi menüsünü görür');
        $this->assertNull($this->repo->menuForCustomer($b, $mid, $minEnd), 'B, A menüsünü görmez (IDOR)');

        // 2 ay önce biten menü → müşteriye görünmez (1 ay sınırı)
        $old = $this->repo->upsertMenu('Eski', date('Y-m-d', strtotime('-3 month')), date('Y-m-d', strtotime('-2 month')), 'all');
        $this->repo->publishMenu($old, true);
        $this->assertNull($this->repo->menuForCustomer($a, $old, $minEnd), '1 aydan eski menü gizli');
        $this->assertCount(1, $this->repo->menusForCustomer($a, $minEnd), 'sadece sınır içindeki menü');
    }
}
