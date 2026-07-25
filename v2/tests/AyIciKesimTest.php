<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * fable-042 — CARİ AY month-to-date (MTD) kesimi.
 * Görüntülenen ay = içinde bulunulan ay ise üretim/gelir/kişi hesapları ay başı..BUGÜN'den
 * gelir (ileri günlere önceden girilen sayılar cari ay rakamlarını şişirmesin). Geçmiş/gelecek
 * ay TAM ay (birebir regresyon). FATURA-KES tam dönem kalır (MTD'ye GEÇMEZ). Gider (tx_date) tam ay.
 *
 * "Bugün" Repo::setBugun ile enjekte edilir (bootstrap APP_TODAY=2099 → aksi belirtilmezse tam ay).
 */
final /**
 * fable-048f (Ömer, 26 Tem): "fatura/üretim ayırdık madem ÜRETİM tarafı TÜM AYI kapsasın"
 * → fable-042'nin MTD kırpması ÜRETİM/CİRO tarafından KALDIRILDI (varsayılan tam ay).
 * MTD yalnız BİRİM MALİYET kartlarında yaşıyor (gidaCostOzet / personelCostOzetUretim):
 * gider bugüne kadar olduğu için payda da bugüne kadar — kişi başı suni düşük çıkmasın.
 * Aşağıdaki testler bu YENİ kurala göre yeniden yazıldı.
 */
class AyIciKesimTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    // ── ayAralik yardımcı: cari ay MTD, geçmiş/gelecek tam ay ──────
    public function testAyAralikMtdVeTamAy(): void
    {
        // fable-048f: VARSAYILAN artık TAM AY (üretim/tahakkuk tüm ayı kapsar)
        $this->repo->setBugun('2026-07-15');
        $this->assertSame(['bas' => '2026-07-01', 'son' => '2026-07-31'], $this->repo->ayAralik('2026-07'));
        // MTD yalnız açıkça istenince (birim maliyet kartları)
        $this->assertSame(['bas' => '2026-07-01', 'son' => '2026-07-15'], $this->repo->ayAralik('2026-07', null, true));
        // Geçmiş ay → tam ay (Haziran 30 gün)
        $this->assertSame(['bas' => '2026-06-01', 'son' => '2026-06-30'], $this->repo->ayAralik('2026-06'));
        // Gelecek ay → tam ay (Ağustos 31 gün); ileri-ay önceden giriş cari-ay değil, tam kalır
        $this->assertSame(['bas' => '2026-08-01', 'son' => '2026-08-31'], $this->repo->ayAralik('2026-08'));
        // Şubat 2026 (28 gün) — geçmiş ay son gün doğru
        $this->assertSame(['bas' => '2026-02-01', 'son' => '2026-02-28'], $this->repo->ayAralik('2026-02'));
        // Opsiyonel $bugun paramı property'yi ezer
        $this->assertSame('2026-07-09', $this->repo->ayAralik('2026-07', '2026-07-09', true)['son']);
    }

    // ── fable-048f: kişi/ciro haritaları TÜM AYI kapsar (ileri gün DAHİL) ──
    public function testCariAyKisiCiroTamAy(): void
    {
        $u = seed_customer($this->pdo, 'DEMİR', 100.0);
        $this->repo->upsertProduction($u, '2026-07-05', 100, 100.0, 'ogle'); // bugüne kadar
        $this->repo->upsertProduction($u, '2026-07-31', 100, 100.0, 'ogle'); // İLERİ GÜN (önceden girili)

        // Cari ay, bugün 15 → ileri gün DE sayılır (Ömer: tahakkuk tüm ay)
        $this->repo->setBugun('2026-07-15');
        $this->assertSame(200, $this->repo->customerPersonsMap('2026-07')[$u], 'tahakkuk ileri günü DAHİL eder');
        $this->assertEqualsWithDelta(20000.0, $this->repo->customerCiroMap('2026-07')[$u], 0.001, 'ciro tüm ay');
        $this->assertEqualsWithDelta(20000.0, $this->repo->monthUretimCiro('2026-07'), 0.001, 'netKarlilik gelir tüm ay');

        // Ay sonunda da aynı sonuç
        $this->repo->setBugun('2026-07-31');
        $this->assertSame(200, $this->repo->customerPersonsMap('2026-07')[$u], 'ay sonu = tam ay');
        $this->assertEqualsWithDelta(20000.0, $this->repo->monthUretimCiro('2026-07'), 0.001);
    }

    // ── Geçmiş ay TAM (birebir regresyon): bugün sonraki ayda ──────
    public function testGecmisAyTamRegresyon(): void
    {
        $u = seed_customer($this->pdo, 'DEMİR', 100.0);
        $this->repo->upsertProduction($u, '2026-07-05', 100, 100.0, 'ogle');
        $this->repo->upsertProduction($u, '2026-07-31', 100, 100.0, 'ogle');

        // Bugün Eylül → Temmuz GEÇMİŞ ay → TAM (200 kişi / 20000 ciro), ileri gün dahil
        $this->repo->setBugun('2026-09-10');
        $this->assertSame(200, $this->repo->customerPersonsMap('2026-07')[$u], 'geçmiş ay tam');
        $this->assertEqualsWithDelta(20000.0, $this->repo->monthUretimCiro('2026-07'), 0.001);
        $this->assertEqualsWithDelta(20000.0, $this->repo->customerCiroMap('2026-07')[$u], 0.001);
    }

    // ── Ay sınırı: bugün ayın 1'i → sadece o gün (bas=son=01) ─────
    public function testAySiniriIlkGun(): void
    {
        $u = seed_customer($this->pdo, 'DEMİR', 100.0);
        $this->repo->upsertProduction($u, '2026-07-01', 40, 100.0, 'ogle');
        $this->repo->upsertProduction($u, '2026-07-02', 60, 100.0, 'ogle');

        $this->repo->setBugun('2026-07-01');
        // fable-048f: varsayılan tam ay → ayın 1'inde bile tüm ay sayılır (tahakkuk)
        $this->assertSame(['bas' => '2026-07-01', 'son' => '2026-07-31'], $this->repo->ayAralik('2026-07'));
        $this->assertSame(100, $this->repo->customerPersonsMap('2026-07')[$u], 'tahakkuk tüm ay');
        // MTD istenirse ayın 1'i sınırı hâlâ doğru (birim maliyet kartları için)
        $this->assertSame(['bas' => '2026-07-01', 'son' => '2026-07-01'], $this->repo->ayAralik('2026-07', null, true));
    }

    // ── Gıda kişi-başı paydası MTD (üretim kişi ileri günü hariç) ─
    public function testGidaKisiBasiPaydaMtd(): void
    {
        $u = seed_customer($this->pdo, 'DEMİR', 100.0);
        $this->repo->upsertProduction($u, '2026-07-05', 100, 100.0, 'ogle');
        $this->repo->upsertProduction($u, '2026-07-31', 100, 100.0, 'ogle'); // ileri gün

        $this->repo->setBugun('2026-07-15');
        $this->assertSame(100, $this->repo->gidaCostOzet('2026-07')['kisi_toplam'], 'gıda paydası MTD');
        $this->repo->setBugun('2026-07-31');
        $this->assertSame(200, $this->repo->gidaCostOzet('2026-07')['kisi_toplam'], 'ay sonu tam');
    }

    // ── Taşıma adet MTD (monthProductionPersons + tasimaToplamAdet) ─
    public function testTasimaAdetTamAy(): void
    {
        $t = $this->repo->upsertCustomer('KARGO', 120.0, 'tasima', null, null, null, null, 80.0, 0.0);
        $this->repo->upsertProduction($t, '2026-07-03', 300, 120.0, 'ogle');
        $this->repo->upsertProduction($t, '2026-07-28', 300, 120.0, 'ogle'); // ileri gün

        $this->repo->setBugun('2026-07-15');
        // fable-048f: tahakkuk tüm ay → ileri gün DAHİL
        $this->assertEqualsWithDelta(600.0, $this->repo->monthProductionPersons($t, '2026-07'), 0.001, 'taşıma adet tüm ay');
        $this->assertSame(600, $this->repo->tasimaToplamAdet('2026-07'), 'toplam taşıma adet tüm ay');
        $this->assertEqualsWithDelta(24000.0, (float) $this->repo->tasimaProfit($t, '2026-07')['net'], 0.001);
        // fable-048d: fatura modu için dönem kırpması hâlâ çalışır ($sonTarih)
        $this->assertEqualsWithDelta(300.0, $this->repo->monthProductionPersons($t, '2026-07', '2026-07-15'), 0.001, 'kırpma parametresi çalışır');
    }

    // ── FATURA-KES tam dönem: MTD'ye GEÇMEZ (ileri günü sayar) ────
    public function testFaturaKesTamDonemDegismez(): void
    {
        $u = seed_customer($this->pdo, 'DEMİR', 100.0);
        $this->pdo->prepare('UPDATE customers SET parasut_id = ?, irsaliye_aktif = 0 WHERE id = ?')
            ->execute(['999', $u]);
        $this->repo->upsertProduction($u, '2026-07-05', 100, 100.0, 'ogle');
        $this->repo->upsertProduction($u, '2026-07-31', 100, 100.0, 'ogle'); // ileri gün

        // fable-048f: üretim/tahakkuk da artık TAM AY; fatura-kes zaten tam dönemdi (ikisi 200)
        $this->repo->setBugun('2026-07-15');
        $this->assertSame(200, $this->repo->customerPersonsMap('2026-07')[$u], 'tahakkuk tüm ay');
        // Birim maliyet kartlarının paydası hâlâ MTD (gider dönemiyle uyumlu)
        $this->assertSame(100, (int) $this->repo->gidaCostOzet('2026-07')['kisi_toplam'], 'gıda paydası MTD kalır');

        // Fatura girdileri tam dönem (bas..ay sonu) → 200 (ileri gün dahil)
        $this->assertSame(200, $this->repo->customerMonthProduction($u, '2026-07')['persons'], 'fatura input tam ay');
        $this->assertSame(200, $this->repo->productionPersonsRange($u, '2026-07-01', '2026-07-31'), 'range tam dönem');

        // faturaAdaylari (aylık müşteri) adet = tam dönem 200 (MTD DEĞİL)
        $aday = null;
        foreach ($this->repo->faturaAdaylari('2026-07-01', '2026-07-31') as $a) {
            if ((int) $a['customer_id'] === $u) { $aday = $a; break; }
        }
        $this->assertNotNull($aday, 'aylık fatura adayı listelenir');
        $this->assertSame(200, (int) $aday['adet'], 'fatura-kes adedi tam dönem — MTD şişirmez/kırpmaz');
    }

    // ── Ek: kişi başı PERSONEL maliyeti (üretim payı / üretim kişi) ─
    public function testKisiBasiPersonelUretim(): void
    {
        $u = seed_customer($this->pdo, 'DEMİR', 100.0);
        $this->repo->upsertProduction($u, '2026-07-05', 100, 100.0, 'ogle');
        $this->repo->upsertProduction($u, '2026-07-31', 100, 100.0, 'ogle'); // ileri gün → paydaya girmez

        // Genel personel → tek üretim müşterisi U'ya düşer
        $pid = $this->repo->upsertPersonel('Ahmet', 'Aşçı', 10000.0);
        $this->repo->setPersonelAtama($pid, true);
        $yuklu = $this->repo->personelYukluMaliyet($pid, '2026-07')['yuklu_toplam'];

        $this->repo->setBugun('2026-07-15');
        $pc = $this->repo->personelCostOzetUretim('2026-07');
        $this->assertEqualsWithDelta($yuklu, $pc['toplam'], 0.01, 'üretim personel payı = yüklü toplam');
        $this->assertSame(100, $pc['kisi_toplam'], 'payda MTD (ileri gün hariç)');
        $this->assertEqualsWithDelta($yuklu / 100.0, $pc['kisi_basi'], 0.01, 'kişi başı = pay / MTD kişi');

        // kar-analizi üretim personel toplamıyla BİREBİR (aynı zincir)
        $ka = $this->repo->karAnalizi('2026-07');
        $this->assertEqualsWithDelta($ka['uretim']['personel'], $pc['toplam'], 0.01, 'P&L üretim personel ile birebir');
    }

    // ── Ek: taşıma müşterisine eşli personel üretim kişi-başına GİRMEZ ─
    public function testTasimaEsliPersonelUretimeGirmez(): void
    {
        $u = seed_customer($this->pdo, 'DEMİR', 100.0);
        $this->repo->upsertProduction($u, '2026-07-05', 100, 100.0, 'ogle');
        $t = $this->repo->upsertCustomer('KARGO', 120.0, 'tasima', null, null, null, null, 80.0, 0.0);
        $this->repo->upsertProduction($t, '2026-07-05', 50, 120.0, 'ogle');

        // Personel SADECE taşıma müşterisi T'ye eşli (fable-035 override)
        $pT = $this->repo->upsertPersonel('Kargocu', 'Şoför', 8000.0);
        $this->repo->personelEslestirmeKaydet($pT, [$t]);
        // Ayrıca genel bir üretim personeli (U'ya düşer) — üretim payı bundan gelir
        $pU = $this->repo->upsertPersonel('Aşçı', 'Aşçı', 9000.0);
        $this->repo->setPersonelAtama($pU, true);
        $yukluU = $this->repo->personelYukluMaliyet($pU, '2026-07')['yuklu_toplam'];

        $this->repo->setBugun('2026-07-15');
        $pc = $this->repo->personelCostOzetUretim('2026-07');
        // Taşıma-eşli personel (pT) üretim toplamına GİRMEZ → sadece pU'nun yüklüsü
        $this->assertEqualsWithDelta($yukluU, $pc['toplam'], 0.01, 'taşıma-eşli personel üretim payına girmez');
        $this->assertSame(100, $pc['kisi_toplam'], 'üretim kişi (taşıma kişi paydaya girmez)');
    }
}
