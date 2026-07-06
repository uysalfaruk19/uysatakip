<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * opus-010: Menü yayınlama (hedefli) + Malzeme talebi.
 * En kritik test: IDOR — müşteri A, sadece-B-hedefli menüyü GÖRMEZ; all-audience menüyü görür.
 * Müşteri A, müşteri B'nin malzeme talebini göremez.
 */
final class MenuSupplyTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    // ── Menü: hedefli görünürlük (IDOR) ───────────────────────
    public function testMenuTargetingIdorScope(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $b = seed_customer($this->pdo, 'B AŞ', 200.0);
        $today = '2026-07-04';

        // 1) all-audience yayınlanmış menü → herkes görür
        $mAll = $this->repo->upsertMenu('Herkese Açık', '2026-07-04', '2026-07-10', 'all');
        $this->repo->publishMenu($mAll, true);

        // 2) sadece B'ye hedefli yayınlanmış menü
        $mB = $this->repo->upsertMenu('Sadece B', '2026-07-04', '2026-07-10', 'selected');
        $this->repo->setMenuAudience($mB, 'selected', [$b]);
        $this->repo->publishMenu($mB, true);

        // 3) taslak (yayınlanmamış) all menü → kimse görmez
        $mDraft = $this->repo->upsertMenu('Taslak', '2026-07-04', '2026-07-10', 'all');

        // 4) süresi geçmiş yayınlanmış all menü → görünmez (date_end < today)
        $mPast = $this->repo->upsertMenu('Geçmiş', '2026-06-01', '2026-06-30', 'all');
        $this->repo->publishMenu($mPast, true);

        $aMenus = array_column($this->repo->menusForCustomer($a, $today), 'title');
        $bMenus = array_column($this->repo->menusForCustomer($b, $today), 'title');

        // A: yalnız "Herkese Açık" görür — "Sadece B" SIZMAZ
        $this->assertContains('Herkese Açık', $aMenus, 'A all-audience menüyü görür');
        $this->assertNotContains('Sadece B', $aMenus, 'IDOR: A, B-hedefli menüyü GÖRMEZ');
        $this->assertNotContains('Taslak', $aMenus, 'taslak menü görünmez');
        $this->assertNotContains('Geçmiş', $aMenus, 'süresi geçmiş menü görünmez');
        $this->assertCount(1, $aMenus, 'A yalnız 1 menü görür');

        // B: hem "Herkese Açık" hem "Sadece B" görür
        $this->assertContains('Herkese Açık', $bMenus);
        $this->assertContains('Sadece B', $bMenus, 'B kendi hedefli menüsünü görür');
        $this->assertCount(2, $bMenus, 'B tam 2 menü görür');
    }

    public function testMenuItemUpsertUnique(): void
    {
        $m = $this->repo->upsertMenu('Hafta 1', '2026-07-06', '2026-07-10', 'all');
        $this->repo->upsertMenuItem($m, '2026-07-06', 'ogle', 'Mercimek Çorbası, Etli Nohut, Pilav');
        $this->repo->upsertMenuItem($m, '2026-07-06', 'ogle', 'Ezogelin, Tavuk Sote, Bulgur'); // aynı gün/öğün → güncelle
        $this->repo->upsertMenuItem($m, '2026-07-06', 'aksam', 'Domates Çorbası');

        $items = $this->repo->menuItems($m);
        $this->assertCount(2, $items, 'UNIQUE(menu,date,meal): öğle güncellendi, akşam ayrı');
        $ogle = array_values(array_filter($items, static fn($i) => $i['meal'] === 'ogle'))[0];
        $this->assertSame('Ezogelin, Tavuk Sote, Bulgur', $ogle['dishes'], 'son değer geçerli');
    }

    public function testSetMenuAudienceAllClearsTargets(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $b = seed_customer($this->pdo, 'B AŞ', 200.0);
        $m = $this->repo->upsertMenu('Test', '2026-07-04', '2026-07-10', 'selected');
        $this->repo->setMenuAudience($m, 'selected', [$a, $b]);
        $this->assertCount(2, $this->repo->menuTargets($m));

        // 'all'a çevir → hedefler temizlenir (herkes görür)
        $this->repo->setMenuAudience($m, 'all');
        $this->assertCount(0, $this->repo->menuTargets($m), 'all → hedef listesi temizlenir');
    }

    public function testDeleteMenuItemScoped(): void
    {
        $m1 = $this->repo->upsertMenu('M1', '2026-07-04', '2026-07-10', 'all');
        $m2 = $this->repo->upsertMenu('M2', '2026-07-04', '2026-07-10', 'all');
        $this->repo->upsertMenuItem($m1, '2026-07-04', 'ogle', 'A');
        $this->repo->upsertMenuItem($m2, '2026-07-04', 'ogle', 'B');
        $item1 = $this->repo->menuItems($m1)[0];

        // Yanlış menü id ile silme → dokunmaz (scope guard)
        $this->repo->deleteMenuItem((int) $item1['id'], $m2);
        $this->assertCount(1, $this->repo->menuItems($m1), 'yanlış menü scope: silinmez');

        $this->repo->deleteMenuItem((int) $item1['id'], $m1);
        $this->assertCount(0, $this->repo->menuItems($m1), 'doğru scope: silinir');
    }

    // ── Malzeme talebi ────────────────────────────────────────
    public function testCreateSupplyRequestWithItems(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $items = $this->repo->listSupplyItems();
        $this->assertNotEmpty($items, 'seed kataloğu dolu gelir');

        // Ketçap 5 + Bulaşık Deterjanı 2 (isimden id bul)
        $byName = [];
        foreach ($items as $i) {
            $byName[$i['ad']] = (int) $i['id'];
        }
        $reqId = $this->repo->createSupplyRequest($a, [
            $byName['Ketçap'] => 5,
            $byName['Bulaşık Deterjanı'] => 2,
            $byName['Tuz'] => 0, // miktar 0 → atlanır
        ], null, 'Acil');
        $this->assertGreaterThan(0, $reqId);

        $reqItems = $this->repo->supplyRequestItems($reqId);
        $this->assertCount(2, $reqItems, 'miktar 0 kalem atlandı');
        $sumMiktar = array_sum(array_map(static fn($r) => (float) $r['miktar'], $reqItems));
        $this->assertEqualsWithDelta(7.0, $sumMiktar, 0.001, '5 + 2');

        // Boş talep (tüm miktar 0) → 0 döner, talep açılmaz
        $empty = $this->repo->createSupplyRequest($a, [$byName['Tuz'] => 0]);
        $this->assertSame(0, $empty, 'geçerli kalem yok → talep açılmaz');
        $cnt = (int) $this->pdo->query('SELECT COUNT(*) FROM supply_request')->fetchColumn();
        $this->assertSame(1, $cnt, 'yalnız 1 gerçek talep');
    }

    public function testSupplyRequestStatusTransition(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $items = $this->repo->listSupplyItems();
        $reqId = $this->repo->createSupplyRequest($a, [(int) $items[0]['id'] => 3]);

        $this->assertSame(1, $this->repo->openSupplyRequestsCount(), 'yeni talep açık kuyruğunda');
        $this->repo->setSupplyRequestStatus($reqId, 'hazirlandi');
        $this->assertSame(0, $this->repo->openSupplyRequestsCount(), 'hazırlanınca açık kuyruğu boşalır');

        $row = $this->repo->supplyRequestById($reqId);
        $this->assertSame('hazirlandi', $row['status']);
        $this->assertSame('A AŞ', $row['customer_name']);

        $this->repo->setSupplyRequestStatus($reqId, 'teslim');
        $this->assertSame('teslim', $this->repo->supplyRequestById($reqId)['status']);

        $this->expectException(\InvalidArgumentException::class);
        $this->repo->setSupplyRequestStatus($reqId, 'gecersiz');
    }

    public function testSupplyRequestsForCustomerIdorScope(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $b = seed_customer($this->pdo, 'B AŞ', 200.0);
        $items = $this->repo->listSupplyItems();
        $reqA = $this->repo->createSupplyRequest($a, [(int) $items[0]['id'] => 4]);

        // B kendi kapsamında A'nın talebini görmez
        $this->assertCount(1, $this->repo->supplyRequestsForCustomer($a), 'A kendi talebini görür');
        $this->assertCount(0, $this->repo->supplyRequestsForCustomer($b), 'IDOR: B, A talebini görmez');

        // B, A'nın talep id'siyle sorgu yapsa bile null (scope guard)
        $this->assertNull($this->repo->supplyRequestForCustomer($reqA, $b), 'IDOR: B, A talebine erişemez');
        $this->assertNotNull($this->repo->supplyRequestForCustomer($reqA, $a), 'A kendi talebine erişir');
    }

    // ── Müşteri malzeme hakedişi (entitlement) ────────────────
    public function testEntitlementUpsertAndUnique(): void
    {
        $a = seed_customer($this->pdo, 'CANTAŞ', 328.0);
        $items = $this->repo->listSupplyItems();
        $yag = (int) array_values(array_filter($items, static fn($i) => $i['ad'] === 'Ayçiçek Yağı'))[0]['id'];
        $ketcap = (int) array_values(array_filter($items, static fn($i) => $i['ad'] === 'Ketçap'))[0]['id'];

        $this->repo->upsertEntitlementsBulk($a, [$yag => 5, $ketcap => 3]);
        $ent = $this->repo->getEntitlements($a);
        $this->assertEqualsWithDelta(5.0, $ent[$yag], 0.001);
        $this->assertEqualsWithDelta(3.0, $ent[$ketcap], 0.001);

        // Güncelle (aynı customer×item) → çift satır YOK (UNIQUE)
        $this->repo->upsertEntitlementsBulk($a, [$yag => 8]);
        $this->assertEqualsWithDelta(8.0, $this->repo->getEntitlements($a)[$yag], 0.001, 'son değer');
        $cnt = (int) $this->pdo->query('SELECT COUNT(*) FROM supply_entitlement')->fetchColumn();
        $this->assertSame(2, $cnt, 'UNIQUE(customer,item): yağ + ketçap = 2 satır');

        // miktar 0 → hakediş silinir
        $this->repo->setEntitlement($a, $ketcap, 0);
        $this->assertArrayNotHasKey($ketcap, $this->repo->getEntitlements($a), '0 → hakediş kaydı silinir');
    }

    public function testEntitlementIdorScope(): void
    {
        $a = seed_customer($this->pdo, 'A AŞ', 100.0);
        $b = seed_customer($this->pdo, 'B AŞ', 200.0);
        $items = $this->repo->listSupplyItems();
        $yag = (int) $items[0]['id'];

        $this->repo->setEntitlement($a, $yag, 5);
        $this->assertEqualsWithDelta(5.0, $this->repo->getEntitlements($a)[$yag], 0.001, 'A kendi hakedişini görür');
        $this->assertArrayNotHasKey($yag, $this->repo->getEntitlements($b), 'IDOR: B, A hakedişini görmez');
        $this->assertCount(0, $this->repo->getEntitlements($b), 'B hakediş listesi boş');
    }
}
