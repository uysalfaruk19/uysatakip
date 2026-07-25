<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * fable-040 — Müşteriye özel FATURA KİŞİ KURALI (üretim ≠ fatura; CANTAŞ 50 üretim / 70 fatura).
 *
 * İlke: production.persons = GERÇEK üretim (maliyet / kişi-başı / gıda-maliyeti bundan).
 * FATURA KİŞİSİ (customers.fatura_kisi_haftaici) VARSA hafta içi (Pzt–Cum) CİRO bundan
 * (production.amount = fatura_kisi × fiyat) → SUM(amount) okuyan HER yer otomatik doğru.
 * Cumartesi/pazar kural UYGULANMAZ.
 *
 * Tarih referansları: 2026-07-20 Pzt · 2026-07-24 Cum · 2026-07-25 Cmt · 2026-07-26 Paz.
 */
final class FaturaKisiKuraliTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    /** Kurallı müşteri kur (üretim; hafta içi sabit fatura kişisi). */
    private function seedKurali(string $name, float $price, int $faturaKisi): int
    {
        $id = seed_customer($this->pdo, $name, $price);
        $this->pdo->prepare('UPDATE customers SET fatura_kisi_haftaici = ? WHERE id = ?')
            ->execute([$faturaKisi, $id]);
        return $id;
    }

    // ── Statik kural: faturaKisiToplam ───────────────────────────
    public function testFaturaKisiToplamKurali(): void
    {
        $this->assertSame(70, Repo::faturaKisiToplam(50, 70, '2026-07-20'), 'Pzt hafta içi → kural 70');
        $this->assertSame(70, Repo::faturaKisiToplam(50, 70, '2026-07-24'), 'Cum hafta içi → kural 70');
        $this->assertSame(50, Repo::faturaKisiToplam(50, 70, '2026-07-25'), 'Cmt → gerçek 50');
        $this->assertSame(50, Repo::faturaKisiToplam(50, 70, '2026-07-26'), 'Paz → gerçek 50');
        $this->assertSame(0, Repo::faturaKisiToplam(0, 70, '2026-07-20'), 'üretim yok → fatura yok');
        $this->assertSame(50, Repo::faturaKisiToplam(50, null, '2026-07-20'), 'kural yok → gerçek');
        $this->assertSame(50, Repo::faturaKisiToplam(50, 0, '2026-07-20'), 'kural 0 → gerçek');
    }

    // ── CANTAŞ tek öğün: hafta içi ciro 70, persons 50 ───────────
    public function testTekOgunHaftaIciCiro70Persons50(): void
    {
        $c = $this->seedKurali('CANTAŞ', 328.0, 70);
        // Bugün ekranı akışı: kullanıcı 50 (gerçek üretim) girer
        $this->repo->saveDayMeals($c, '2026-07-20', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);

        // persons GERÇEK (50), amount FATURA (70 × 328 = 22.960)
        $row = $this->pdo->query('SELECT persons, amount FROM production')->fetch();
        $this->assertSame(50, (int) $row['persons'], 'üretim kişisi GERÇEK kalır');
        $this->assertEqualsWithDelta(22960.0, (float) $row['amount'], 0.001, '70 × 328');

        // Ciro okuyan her yer 70-bazlı, kişi sayısı 50
        $grid = $this->repo->dayGridAllMeals('2026-07-20')[0];
        $this->assertSame(50, $grid['toplam'], 'kişi sayacı GERÇEK üretim');
        $this->assertEqualsWithDelta(22960.0, $grid['tutar'], 0.001, 'günlük ciro FATURA kişisinden');

        $this->assertEqualsWithDelta(22960.0, $this->repo->customerCiroMap('2026-07')[$c], 0.001);
        $this->assertSame(50, $this->repo->customerPersonsMap('2026-07')[$c], 'aylık kişi GERÇEK');
        $mp = $this->repo->monthProductionByCustomer('2026-07')[0];
        $this->assertSame(50, (int) $mp['persons']);
        $this->assertEqualsWithDelta(22960.0, (float) $mp['ciro'], 0.001);
    }

    // ── Cumartesi: kural uygulanmaz (gerçek sayı = gerçek ciro) ──
    public function testCumartesiKuralUygulanmaz(): void
    {
        $c = $this->seedKurali('CANTAŞ', 328.0, 70);
        $this->repo->saveDayMeals($c, '2026-07-25', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);
        $row = $this->pdo->query('SELECT persons, amount FROM production')->fetch();
        $this->assertSame(50, (int) $row['persons']);
        $this->assertEqualsWithDelta(16400.0, (float) $row['amount'], 0.001, 'Cmt → 50 × 328');
        $this->assertEqualsWithDelta(16400.0, $this->repo->dayGridAllMeals('2026-07-25')[0]['tutar'], 0.001);
    }

    // ── Kuralsız müşteri: regresyon (hiçbir şey değişmez) ────────
    public function testKuralsizMusteriDegismez(): void
    {
        $o = seed_customer($this->pdo, 'OPAK', 250.0);
        // fatura_kisi_haftaici geçilmez → eski davranış birebir
        $this->repo->saveDayMeals($o, '2026-07-20', ['ogle' => 40, 'aksam' => 0, 'kumanya' => 0], 250.0);
        $row = $this->pdo->query('SELECT persons, amount FROM production')->fetch();
        $this->assertSame(40, (int) $row['persons']);
        $this->assertEqualsWithDelta(10000.0, (float) $row['amount'], 0.001, '40 × 250 (kural yok)');
        $this->assertEqualsWithDelta(10000.0, $this->repo->customerCiroMap('2026-07')[$o], 0.001);
        $this->assertSame(40, $this->repo->customerPersonsMap('2026-07')[$o]);
    }

    // ── Çok öğünlü kurallı gün: kural GÜN TOPLAMINA, fark ÖĞLENE ─
    public function testCokOgunluKuralFarkOglene(): void
    {
        $c = $this->seedKurali('ÇOK ÖĞÜN AŞ', 100.0, 70);
        // gerçek üretim 30 öğle + 15 akşam + 5 kumanya = 50; fatura 70 (fark +20 öğlene)
        $this->repo->saveDayMeals($c, '2026-07-20', ['ogle' => 30, 'aksam' => 15, 'kumanya' => 5], 100.0, 'uysa', 70);

        $by = [];
        foreach ($this->pdo->query('SELECT meal, persons, amount FROM production')->fetchAll() as $r) {
            $by[$r['meal']] = $r;
        }
        // persons GERÇEK kalır
        $this->assertSame(30, (int) $by['ogle']['persons']);
        $this->assertSame(15, (int) $by['aksam']['persons']);
        $this->assertSame(5, (int) $by['kumanya']['persons']);
        // fark yalnız öğle amount'una: (30+20) × 100 = 5000; diğerleri gerçek
        $this->assertEqualsWithDelta(5000.0, (float) $by['ogle']['amount'], 0.001, 'öğle 50 × 100 (fark biner)');
        $this->assertEqualsWithDelta(1500.0, (float) $by['aksam']['amount'], 0.001, 'akşam 15 × 100');
        $this->assertEqualsWithDelta(500.0, (float) $by['kumanya']['amount'], 0.001, 'kumanya 5 × 100');
        // gün toplamı: ciro 70 × 100 = 7000, kişi GERÇEK 50
        $grid = $this->repo->dayGridAllMeals('2026-07-20')[0];
        $this->assertSame(50, $grid['toplam']);
        $this->assertEqualsWithDelta(7000.0, $grid['tutar'], 0.001);
    }

    // ── Öğle YOK ama kural var: fark ilk mevcut öğüne biner (SUM daima doğru) ──
    public function testOgleYoksaFarkIlkMevcutOgune(): void
    {
        $c = $this->seedKurali('AKŞAMCI AŞ', 100.0, 70);
        $this->repo->saveDayMeals($c, '2026-07-20', ['ogle' => 0, 'aksam' => 40, 'kumanya' => 10], 100.0, 'uysa', 70);
        $by = [];
        foreach ($this->pdo->query('SELECT meal, persons, amount FROM production')->fetchAll() as $r) {
            $by[$r['meal']] = $r;
        }
        $this->assertArrayNotHasKey('ogle', $by, 'öğle satırı yok (persons 0 silinir)');
        $this->assertSame(40, (int) $by['aksam']['persons']);
        // fark (70−50=20) akşama biner: (40+20) × 100 = 6000
        $this->assertEqualsWithDelta(6000.0, (float) $by['aksam']['amount'], 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $by['kumanya']['amount'], 0.001);
        $this->assertEqualsWithDelta(7000.0, $this->repo->dayGridAllMeals('2026-07-20')[0]['tutar'], 0.001, 'toplam 70 × 100');
    }

    // ── Gıda kişi başı 50-bazlı (fatura kişisi gıda maliyetine karışmaz) ──
    public function testGidaKisiBasi50Bazli(): void
    {
        $c = $this->seedKurali('CANTAŞ', 328.0, 70);
        $this->repo->saveDayMeals($c, '2026-07-20', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);
        // 1500 TL gıda gideri, tedarikçi kırılıma eşli
        $this->pdo->prepare(
            "INSERT INTO transactions (type, category, tx_date, amount, description, source, alloc_type)
             VALUES ('gider', 'Gıda', '2026-07-05', 1500, 'ÖRS GIDA · INV', 'parasut', 'genel')"
        )->execute();
        $this->repo->tedarikciGidaKaydet('ÖRS GIDA', 'kuru_gida');

        $o = $this->repo->gidaCostOzet('2026-07');
        $this->assertSame(50, $o['kisi_toplam'], 'gıda kişi = GERÇEK üretim (fatura 70 DEĞİL)');
        $this->assertEqualsWithDelta(30.0, $o['kisi_basi'], 0.001, '1500 / 50 = 30,00 (70 olsaydı 21,43)');
    }

    // ── kar-analizi: gelir 70-bazlı, gider dağıtımı 50-bazlı; rozet değerleri ──
    public function testKarAnaliziGelir70GiderDagitim50(): void
    {
        $c = $this->seedKurali('CANTAŞ', 328.0, 70);
        // Hafta içi 2 gün üretim (Pzt+Cum), her gün 50 üretim → 100 kişi / ciro 2×22.960
        $this->repo->saveDayMeals($c, '2026-07-20', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);
        $this->repo->saveDayMeals($c, '2026-07-24', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);
        // Genel gider → tek üretim müşterisine tamamı gider (dağıtım kişi/ciro bazlı ama tek müşteri)
        $this->pdo->prepare(
            "INSERT INTO transactions (type, category, tx_date, amount, description, source, alloc_type)
             VALUES ('gider', 'Gıda', '2026-07-05', 1000, 'X · INV', 'parasut', 'genel')"
        )->execute();

        $ka = $this->repo->karAnalizi('2026-07');
        $row = $ka['uretim']['rows'][0];
        $this->assertEqualsWithDelta(45920.0, $row['gelir'], 0.001, 'gelir = 2 × 70 × 328 (FATURA)');
        $this->assertEqualsWithDelta(1000.0, $row['gider'], 0.001, 'gider payı (dağıtım) tam düşer');
        $this->assertSame(70, $row['fatura_kisi'], 'rozet: fatura kişisi');
        $this->assertSame(50, $row['uretim_gunluk'], 'rozet: üretim günlük ort (GERÇEK, hafta içi)');
        // gider dağıtımı kişi-bazlı katmanı GERÇEK persons kullanır (100), fatura değil
        $this->assertSame(100, $this->repo->customerPersonsMap('2026-07')[$c]);
    }

    // ── uretimGunlukOrtalama: yalnız hafta içi günler ────────────
    public function testUretimGunlukOrtalamaHaftaIci(): void
    {
        $c = $this->seedKurali('CANTAŞ', 328.0, 70);
        $this->repo->saveDayMeals($c, '2026-07-20', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70); // Pzt
        $this->repo->saveDayMeals($c, '2026-07-24', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70); // Cum
        $this->repo->saveDayMeals($c, '2026-07-25', ['ogle' => 40, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70); // Cmt (dışı)
        $this->assertSame(50, $this->repo->uretimGunlukOrtalama($c, '2026-07'), 'Cmt ortalamaya girmez');
        $this->assertNull($this->repo->uretimGunlukOrtalama($c, '2026-06'), 'kayıt yok → null');
    }

    // ── upsertCustomer: kural set / temizle / dokunma (sentinel) ─
    public function testUpsertCustomerKuralSetTemizleDokunma(): void
    {
        $id = $this->repo->upsertCustomer('CANTAŞ', 328.0, 'uretim', null, null, null, null, null, null, null, null, 70);
        $this->assertSame(70, (int) $this->repo->customer($id)['fatura_kisi_haftaici'], 'kural set edildi');

        // Sentinel (-1 varsayılan): başka alan güncellenirken kurala DOKUNULMAZ
        $this->repo->upsertCustomer('CANTAŞ', 330.0, 'uretim', $id);
        $this->assertSame(70, (int) $this->repo->customer($id)['fatura_kisi_haftaici'], 'dokunma korunur');

        // null → kural KALDIRILIR
        $this->repo->upsertCustomer('CANTAŞ', 330.0, 'uretim', $id, null, null, null, null, null, null, null, null);
        $this->assertNull($this->repo->customer($id)['fatura_kisi_haftaici'], 'boş = kural kaldırıldı');
    }

    // ── Türkçe karakter + çift kayıt (idempotent) sınır testi ────
    public function testTurkceVeIdempotentKurali(): void
    {
        $c = $this->seedKurali('ŞİRKET ÖĞÜN A.Ş.', 100.0, 70);
        $this->repo->saveDayMeals($c, '2026-07-20', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 100.0, 'uysa', 70);
        $this->repo->saveDayMeals($c, '2026-07-20', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 100.0, 'uysa', 70);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM production')->fetchColumn(), 'çift kayıt çoğalmaz');
        $g = $this->repo->dayGridAllMeals('2026-07-20')[0];
        $this->assertSame('ŞİRKET ÖĞÜN A.Ş.', $g['name']);
        $this->assertEqualsWithDelta(7000.0, $g['tutar'], 0.001, '70 × 100');
    }
}
