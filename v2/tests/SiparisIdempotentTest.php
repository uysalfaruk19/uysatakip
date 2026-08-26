<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * aksiyon-faz3 — MÜKERRER ONAY KALKANI.
 * Çift POST (çift tık / tarayıcıda geri + tekrar gönder) üretimi yeniden yazmamalı;
 * arada elle düzeltilen sayı EZİLMEMELİ.
 */
final class SiparisIdempotentTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    private function siparis(int $cid, string $gun, int $kisi): int
    {
        $this->pdo->prepare(
            "INSERT INTO orders (customer_id, order_date, meal, persons, status, entered_by)
             VALUES (?, ?, 'ogle', ?, 'gonderildi', 'musteri')"
        )->execute([$cid, $gun, $kisi]);
        return (int) $this->pdo->lastInsertId();
    }

    private function uretimKisi(int $cid, string $gun): int
    {
        $st = $this->pdo->prepare('SELECT COALESCE(SUM(persons),0) FROM production WHERE customer_id = ? AND prod_date = ?');
        $st->execute([$cid, $gun]);
        return (int) $st->fetchColumn();
    }

    public function testIkinciOnayUretimiYenidenYazmaz(): void
    {
        $cid = seed_customer($this->pdo, 'CANTAŞ', 260.0);
        $oid = $this->siparis($cid, '2026-08-27', 70);

        $ilk = $this->repo->approveOrder($oid);
        $this->assertNotNull($ilk);
        $this->assertSame(70, $this->uretimKisi($cid, '2026-08-27'));

        // Aradan sonra sayı ELLE düzeltiliyor (gerçek hayatta sık: müşteri telefonla değiştirdi)
        $this->repo->saveDayMeals($cid, '2026-08-27', ['ogle' => 55, 'aksam' => 0, 'kumanya' => 0], 260.0, 'uysa', null);
        $this->assertSame(55, $this->uretimKisi($cid, '2026-08-27'));

        // İkinci onay (çift tık / geri + tekrar gönder) → elle düzeltmeyi EZMEMELİ
        $ikinci = $this->repo->approveOrder($oid);
        $this->assertNull($ikinci, 'karara bağlanmış sipariş yeniden onaylanamaz');
        $this->assertSame(55, $this->uretimKisi($cid, '2026-08-27'), 'elle düzeltilen sayı korunmalı');
    }

    public function testOnaylanmisSiparisReddedilemez(): void
    {
        $cid = seed_customer($this->pdo, 'BOMİ', 265.0);
        $oid = $this->siparis($cid, '2026-08-27', 80);
        $this->repo->approveOrder($oid);

        $this->assertFalse(
            $this->repo->rejectOrder($oid),
            'onaylanmış sipariş "reddedildi" olamaz — üretim kaydı silinmediği için iki ekran çelişirdi'
        );
        $this->assertSame(80, $this->uretimKisi($cid, '2026-08-27'));
    }

    public function testReddedilenSiparisSonradanOnaylanamaz(): void
    {
        $cid = seed_customer($this->pdo, 'ERMETAL', 240.0);
        $oid = $this->siparis($cid, '2026-08-27', 17);
        $this->assertTrue($this->repo->rejectOrder($oid));

        $this->assertNull($this->repo->approveOrder($oid));
        $this->assertSame(0, $this->uretimKisi($cid, '2026-08-27'), 'reddedilen sipariş üretime yazılmaz');
    }

    public function testIlkOnayNormalCalisir(): void
    {
        $cid = seed_customer($this->pdo, 'PENDORYA', 255.0);
        $oid = $this->siparis($cid, '2026-08-27', 58);

        $res = $this->repo->approveOrder($oid);
        $this->assertNotNull($res);
        $this->assertSame(58, (int) $res['persons']);
        $this->assertEqualsWithDelta(58 * 255.0, (float) $res['amount'], 0.01);
    }
}
