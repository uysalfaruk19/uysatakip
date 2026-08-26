<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * aksiyon-faz5 — Finans BELGE ZİNCİRİ: kesildi → mail bekliyor → açık alacak.
 * Üç adımın da rakamı sorgudan gelir; ekranda elle sayı yok.
 */
final class BelgeZinciriTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    private function fatura(int $cid, string $ay, string $durum): void
    {
        $this->pdo->prepare(
            'INSERT INTO fatura (customer_id, ay, ara_toplam, kdv_oran, genel_toplam, durum) VALUES (?,?,?,?,?,?)'
        )->execute([$cid, $ay, 1000.0, 0.0, 1000.0, $durum]);
    }

    private function cari(int $cid, string $yon, float $tutar): void
    {
        $this->pdo->prepare(
            "INSERT INTO cari_entries (party_type, party_id, entry_date, direction, amount, note)
             VALUES ('customer', ?, ?, ?, ?, ?)"
        )->execute([$cid, '2026-08-10', $yon, $tutar, 'test']);
    }

    public function testKesilenVeTaslakAyrilir(): void
    {
        $a = seed_customer($this->pdo, 'BOMİ', 265.0);
        $b = seed_customer($this->pdo, 'CANTAŞ', 260.0);
        $this->fatura($a, '2026-08', 'kesildi');
        $this->fatura($b, '2026-08', 'taslak');

        $z = $this->repo->belgeZinciri('2026-08');
        $this->assertSame(1, $z['kesildi']);
        $this->assertSame(1, $z['taslak']);
    }

    public function testBaskaAyinFaturasiSayilmaz(): void
    {
        $a = seed_customer($this->pdo, 'BOMİ', 265.0);
        $this->fatura($a, '2026-07', 'kesildi');

        $z = $this->repo->belgeZinciri('2026-08');
        $this->assertSame(0, $z['kesildi'], 'Temmuz faturası Ağustos zincirinde görünmez');
    }

    public function testAcikAlacakYalnizPozitifBakiyeleriToplar(): void
    {
        $a = seed_customer($this->pdo, 'BOMİ', 265.0);
        $b = seed_customer($this->pdo, 'CANTAŞ', 260.0);
        $c = seed_customer($this->pdo, 'ERMETAL', 240.0);

        $this->cari($a, 'borc', 10000.0);          // 10.000 borçlu
        $this->cari($a, 'alacak', 4000.0);         // 4.000 ödedi → 6.000 açık
        $this->cari($b, 'borc', 5000.0);
        $this->cari($b, 'alacak', 5000.0);         // kapandı → 0
        $this->cari($c, 'alacak', 2000.0);         // FAZLA ödeme → −2.000 (alacağa EKLENMEZ)

        $z = $this->repo->belgeZinciri('2026-08');
        $this->assertEqualsWithDelta(
            6000.0,
            $z['alacak'],
            0.01,
            'kapanan hesap 0 sayılır, fazla ödeme açık alacağı AZALTMAZ (ayrı bir borçtur)'
        );
    }

    public function testHicVeriYokkaSifirlarDoner(): void
    {
        $z = $this->repo->belgeZinciri('2026-08');
        $this->assertSame(0, $z['kesildi']);
        $this->assertSame(0, $z['mail_bekliyor']);
        $this->assertEqualsWithDelta(0.0, $z['alacak'], 0.001);
    }

    public function testMailKuyruguDurumlariSayilir(): void
    {
        $a = seed_customer($this->pdo, 'BOMİ', 265.0);
        foreach ([['bekliyor', 'F1'], ['bekliyor', 'F2'], ['gonderildi', 'F3'], ['hata', 'F4']] as [$durum, $kaynak]) {
            $this->pdo->prepare(
                "INSERT INTO mail_kuyruk (tur, customer_id, kaynak_id, alici, durum, created_at)
                 VALUES ('fatura', ?, ?, ?, ?, ?)"
            )->execute([$a, $kaynak, 'test@example.com', $durum, '2026-08-15 10:00:00']);
        }

        $z = $this->repo->belgeZinciri('2026-08');
        $this->assertSame(2, $z['mail_bekliyor']);
        $this->assertSame(1, $z['mail_hata']);
    }
}
