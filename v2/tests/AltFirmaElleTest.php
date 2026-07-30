<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * fable-059 — FİRMA KIRILIMINA ELLE GİRİŞ (istisna günler).
 *
 * Ömer: "firma kırılımında sayı giremiyor muyum? 15 temmuzdaki sayısı ona göre gireyim
 * hangi firmaya istiyorsam." Desen normal günlerde doğru, İSTİSNA günlerde değil:
 * 15 Temmuz resmi tatilinde yemek verilmedi, o güne başka bir işin 36 kişilik maliyeti
 * yazıldı — o 36 kişinin hangi şirkete ait olduğunu desen bilemez. Ay sonu bu kırılım
 * 3 AYRI e-Faturaya bölünüyor → yanlış rakam = yanlış şirkete fatura (geri alınamaz).
 *
 * CANLI ÇIPA (Temmuz 2026, 15 Tem tatil + 36 kişi): 1.606 fatura kişisi
 *   desen → HC 690 · İç-Dış 690 · Bakır 226
 *   15 Tem'e elle "HC 36" → HC 726 · İç-Dış 660 · Bakır 220 = TOPLAM YİNE 1.606.
 */
final class AltFirmaElleTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    /** CANTAŞ: üretim 50 / hafta içi fatura 70; 3 alt firma (İç-Dış 30 · Bakır 10 · HC kalan). */
    private function seedCantas(): int
    {
        $cid = seed_customer($this->pdo, 'CANTAŞ', 328.0);
        $this->pdo->prepare('UPDATE customers SET fatura_kisi_haftaici = 70, irsaliye_aktif = 0 WHERE id = ?')
            ->execute([$cid]);
        $this->repo->upsertAltFirma($cid, 'fatura_cantas_icdis', 'CANTAŞ İç-Dış', '1062205016', false, 30, 1);
        $this->repo->upsertAltFirma($cid, 'fatura_cantas_bakir', 'CANTAŞ Bakır', '1062204894', false, 10, 2);
        $this->repo->upsertAltFirma($cid, 'fatura_cantas_hc', 'HC Isıtma', '1062205054', true, null, 3);
        return $cid;
    }

    /**
     * Temmuz 2026 CANLI hâli: 22 normal hafta içi günü × 50 (=70 fatura) + 15 Tem RESMİ TATİL
     * (36 kişi, kural uygulanmaz — fable-057) + 11/25 Tem cumartesi × 15.
     */
    private function seedTemmuzTatilli(int $cid): void
    {
        $this->pdo->prepare('INSERT INTO resmi_tatil (tarih, ad, tur, yarim_gun, aktif) VALUES (?, ?, ?, 0, 1)')
            ->execute(['2026-07-15', 'Demokrasi ve Milli Birlik Günü', 'resmi']);
        for ($d = 1; $d <= 31; $d++) {
            $gun = sprintf('2026-07-%02d', $d);
            $dow = (int) date('N', strtotime($gun));
            if ($gun === '2026-07-15') {
                $this->repo->saveDayMeals($cid, $gun, ['ogle' => 36, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', null);
            } elseif ($dow <= 5) {
                $this->repo->saveDayMeals($cid, $gun, ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);
            } elseif ($gun === '2026-07-11' || $gun === '2026-07-25') {
                $this->repo->saveDayMeals($cid, $gun, ['ogle' => 15, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);
            }
        }
    }

    /** @return array<string,int> kod => kişi */
    private function ozet(int $cid, string $ay = '2026-07'): array
    {
        $o = [];
        foreach ($this->repo->aylikAltFirmaOzet($cid, $ay) as $kod => $v) {
            $o[$kod] = (int) $v['kisi'];
        }
        return $o;
    }

    // ── ÇIPA: 15 Tem elle "HC 36" → dağılım değişir, TOPLAM değişmez ─
    public function testCipaTemmuzElleGirisToplamiBozmaz(): void
    {
        $cid = $this->seedCantas();
        $this->seedTemmuzTatilli($cid);

        // Önce mevcut (desen) hâli — canlı çıpa
        $once = $this->ozet($cid);
        self::assertSame(690, $once['fatura_cantas_hc'], 'desen HC (22×30 + 2×15 cmt)');
        self::assertSame(690, $once['fatura_cantas_icdis'], 'desen İç-Dış (22×30 + 15 Tem 30)');
        self::assertSame(226, $once['fatura_cantas_bakir'], 'desen Bakır (22×10 + 15 Tem 6)');
        self::assertSame(1606, array_sum($once), 'dönem fatura kişisi');

        // 15 Temmuz'un 36 kişisinin TAMAMI HC'ye ait (Ömer bilir, desen bilemez)
        self::assertSame(36, $this->repo->altFirmaGunHedef($cid, '2026-07-15'), 'tatilde hedef = girilen sayı');
        $this->repo->saveGunAltFirma($cid, '2026-07-15', [
            'fatura_cantas_hc' => 36, 'fatura_cantas_icdis' => 0, 'fatura_cantas_bakir' => 0,
        ]);

        $sonra = $this->ozet($cid);
        self::assertSame(726, $sonra['fatura_cantas_hc'], '690 − 0 + 36 (15 Tem tamamı HC)');
        self::assertSame(660, $sonra['fatura_cantas_icdis'], '690 − 30 (15 Tem deseni düştü)');
        self::assertSame(220, $sonra['fatura_cantas_bakir'], '226 − 6');
        self::assertSame(1606, array_sum($sonra), 'TOPLAM DEĞİŞMEZ — yalnız dağılım değişir');

        // Ciro da birebir korunur (fatura tutarı bölüşümden doğuyor)
        $ciro = (float) $this->pdo->query('SELECT SUM(amount) FROM production')->fetchColumn();
        $tutar = array_sum(array_column($this->repo->aylikAltFirmaOzet($cid, '2026-07'), 'tutar'));
        self::assertEqualsWithDelta($ciro, $tutar, 0.01, 'elle kırılım ciroyu birebir bölmeli');
    }

    // ── Elle kayıt DESENİ EZER (tek gün) ─────────────────────────
    public function testElleKayitDeseniEzer(): void
    {
        $cid = $this->seedCantas();
        $this->repo->saveDayMeals($cid, '2026-07-20', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);

        self::assertSame([], $this->repo->gunAltFirmaKirilim($cid, '2026-07-20'), 'başta kayıt yok = desen');
        self::assertSame(
            ['fatura_cantas_icdis' => 30, 'fatura_cantas_bakir' => 10, 'fatura_cantas_hc' => 30],
            $this->ozet($cid),
            'desen hâli'
        );

        $this->repo->saveGunAltFirma($cid, '2026-07-20', [
            'fatura_cantas_icdis' => 12, 'fatura_cantas_bakir' => 8, 'fatura_cantas_hc' => 50,
        ]);
        self::assertSame(
            ['fatura_cantas_icdis' => 12, 'fatura_cantas_bakir' => 8, 'fatura_cantas_hc' => 50],
            $this->ozet($cid),
            'elle kayıt deseni ezmeli'
        );
        self::assertSame(
            ['fatura_cantas_icdis' => 12, 'fatura_cantas_bakir' => 8, 'fatura_cantas_hc' => 50],
            $this->repo->gunAltFirmaKirilim($cid, '2026-07-20'),
            'okuma girilen değerleri döndürür'
        );
        self::assertSame(['2026-07-20'], $this->repo->altFirmaElleGunler($cid, '2026-07-01', '2026-07-31'));
    }

    // ── KARIŞIK AY: bazı gün elle, bazı gün desen → toplam doğru ──
    public function testKarisikAyDogruToplar(): void
    {
        $cid = $this->seedCantas();
        $this->seedTemmuzTatilli($cid);

        // İki ayrı istisna gün elle girilir (biri tatil 36, biri normal hafta içi 70)
        $this->repo->saveGunAltFirma($cid, '2026-07-15', ['fatura_cantas_hc' => 36]);
        $this->repo->saveGunAltFirma($cid, '2026-07-20', [
            'fatura_cantas_icdis' => 70, 'fatura_cantas_bakir' => 0, 'fatura_cantas_hc' => 0,
        ]);
        // Bir cumartesi de elle (desen tamamını HC'ye verirdi)
        $this->repo->saveGunAltFirma($cid, '2026-07-11', [
            'fatura_cantas_bakir' => 15, 'fatura_cantas_hc' => 0,
        ]);

        $o = $this->ozet($cid);
        // İç-Dış: 21 normal hafta içi günü × 30 (20 Tem elle) + 70 = 630 + 70 = 700
        self::assertSame(700, $o['fatura_cantas_icdis']);
        // Bakır: 21 × 10 + 15 (cumartesi elle) = 225
        self::assertSame(225, $o['fatura_cantas_bakir']);
        // HC: 21 × 30 + 36 (15 Tem) + 15 (25 Tem cmt deseni) = 681
        self::assertSame(681, $o['fatura_cantas_hc']);
        self::assertSame(1606, array_sum($o), 'karışık ay toplamı bozulmamalı');
        self::assertSame(
            ['2026-07-11', '2026-07-15', '2026-07-20'],
            $this->repo->altFirmaElleGunler($cid, '2026-07-01', '2026-07-31')
        );
    }

    // ── TOPLAM ≠ HEDEF → KAYDEDİLMEZ (sessiz yanlış kayıt yok) ───
    public function testToplamHedefeEsitDegilseKaydedilmez(): void
    {
        $cid = $this->seedCantas();
        $this->repo->saveDayMeals($cid, '2026-07-20', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);

        $bekle = function (array $kirilim, string $parca) use ($cid): void {
            try {
                $this->repo->saveGunAltFirma($cid, '2026-07-20', $kirilim);
                self::fail('hatalı kırılım kabul edildi: ' . json_encode($kirilim));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($parca, $e->getMessage());
            }
        };

        $bekle(['fatura_cantas_hc' => 67], 'eksik');   // 3 eksik
        $bekle(['fatura_cantas_hc' => 75], 'fazla');   // 5 fazla
        self::assertSame([], $this->repo->gunAltFirmaKirilim($cid, '2026-07-20'), 'reddedilen kayıt yazılmamalı');

        // Tanınmayan firma kodu + negatif sayı reddedilir
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->saveGunAltFirma($cid, '2026-07-20', ['baska_firma' => 70]);
    }

    public function testNegatifKisiReddedilir(): void
    {
        $cid = $this->seedCantas();
        $this->repo->saveDayMeals($cid, '2026-07-20', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->saveGunAltFirma($cid, '2026-07-20', ['fatura_cantas_hc' => 80, 'fatura_cantas_icdis' => -10]);
    }

    public function testSayiGirilmemisGuneKirilimYazilmaz(): void
    {
        $cid = $this->seedCantas(); // 2026-07-20'ye hiç sayı girilmedi
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->saveGunAltFirma($cid, '2026-07-20', ['fatura_cantas_hc' => 10]);
    }

    // ── TAMAMI 0 → kayıt SİLİNİR, gün desene döner ───────────────
    public function testTamamiSifirKaydiSilerVeDeseneDoner(): void
    {
        $cid = $this->seedCantas();
        $this->repo->saveDayMeals($cid, '2026-07-20', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);
        $this->repo->saveGunAltFirma($cid, '2026-07-20', ['fatura_cantas_hc' => 70]);
        self::assertSame(70, $this->ozet($cid)['fatura_cantas_hc']);

        $this->repo->saveGunAltFirma($cid, '2026-07-20', [
            'fatura_cantas_icdis' => 0, 'fatura_cantas_bakir' => 0, 'fatura_cantas_hc' => 0,
        ]);
        self::assertSame([], $this->repo->gunAltFirmaKirilim($cid, '2026-07-20'), 'satırlar silinmeli');
        self::assertSame(
            ['fatura_cantas_icdis' => 30, 'fatura_cantas_bakir' => 10, 'fatura_cantas_hc' => 30],
            $this->ozet($cid),
            'gün otomatiğe (desene) dönmeli'
        );
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM uretim_altfirma')->fetchColumn());

        // Boş dizi de aynı kapıya çıkar ("Otomatiğe dön" bağlantısı)
        $this->repo->saveGunAltFirma($cid, '2026-07-20', ['fatura_cantas_hc' => 70]);
        $this->repo->saveGunAltFirma($cid, '2026-07-20', []);
        self::assertSame([], $this->repo->gunAltFirmaKirilim($cid, '2026-07-20'));
    }

    // ── Aynı güne tekrar yazmak MÜKERRER satır açmaz (atomik) ────
    public function testTekrarYazmakMukerrerSatirAcmaz(): void
    {
        $cid = $this->seedCantas();
        $this->repo->saveDayMeals($cid, '2026-07-20', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);
        $this->repo->saveGunAltFirma($cid, '2026-07-20', ['fatura_cantas_hc' => 70]);
        $this->repo->saveGunAltFirma($cid, '2026-07-20', ['fatura_cantas_icdis' => 40, 'fatura_cantas_hc' => 30]);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM uretim_altfirma')->fetchColumn());
        self::assertSame(
            ['fatura_cantas_icdis' => 40, 'fatura_cantas_hc' => 30],
            $this->repo->gunAltFirmaKirilim($cid, '2026-07-20')
        );
    }

    // ── RESMİ TATİL: hedef = girilen sayı (fable-057 uyumu) ──────
    public function testResmiTatildeHedefGirilenSayidir(): void
    {
        $cid = $this->seedCantas();
        $this->pdo->prepare('INSERT INTO resmi_tatil (tarih, ad, tur, yarim_gun, aktif) VALUES (?, ?, ?, 0, 1)')
            ->execute(['2026-07-15', 'Demokrasi ve Milli Birlik Günü', 'resmi']);
        $this->repo->saveDayMeals($cid, '2026-07-15', ['ogle' => 36, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', null);
        $this->repo->saveDayMeals($cid, '2026-07-16', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);

        self::assertSame(36, $this->repo->altFirmaGunHedef($cid, '2026-07-15'), 'tatilde 70 kuralı geçmez');
        self::assertSame(70, $this->repo->altFirmaGunHedef($cid, '2026-07-16'), 'normal günde kural geçer');

        // Tatil gününe 70 yazmak REDDEDİLİR (hedef 36)
        try {
            $this->repo->saveGunAltFirma($cid, '2026-07-15', ['fatura_cantas_hc' => 70]);
            self::fail('tatil gününde 70 kişi kabul edildi');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('36', $e->getMessage());
        }
        $this->repo->saveGunAltFirma($cid, '2026-07-15', ['fatura_cantas_hc' => 36]);
        self::assertSame(36, $this->repo->gunAltFirmaKirilim($cid, '2026-07-15')['fatura_cantas_hc']);
    }

    // ── BAYAT KAYIT: sonradan gün sayısı değişti → toplam korunur + uyarılır ─
    public function testBayatKayitToplamiBozmazVeUyarilir(): void
    {
        $cid = $this->seedCantas();
        $this->repo->saveDayMeals($cid, '2026-07-15', ['ogle' => 36, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', null);
        $this->repo->saveGunAltFirma($cid, '2026-07-15', ['fatura_cantas_hc' => 36]);

        // Ömer günü sonradan 40 kişiye çeker (fatura kişisi 40 olur)
        $this->repo->saveDayMeals($cid, '2026-07-15', ['ogle' => 40, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', null);
        self::assertSame(40, $this->repo->altFirmaGunHedef($cid, '2026-07-15'));

        $o = $this->ozet($cid);
        self::assertSame(40, array_sum($o), 'kişi ne kaybolur ne uydurulur');
        self::assertSame(40, $o['fatura_cantas_hc'], 'fark varsayılan firmaya yazılır');

        $durum = $this->repo->altFirmaElleDurum($cid, '2026-07-01', '2026-07-31');
        self::assertSame(['2026-07-15'], $durum['gunler']);
        self::assertSame(['2026-07-15'], $durum['bayat'], 'bayat kayıt ekranda uyarılmalı');

        // Sayı azalırsa da toplam korunur (sondan kısılır, negatif yok)
        $this->repo->saveDayMeals($cid, '2026-07-15', ['ogle' => 20, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', null);
        $o2 = $this->ozet($cid);
        self::assertSame(20, array_sum($o2));
        foreach ($o2 as $kod => $kisi) {
            self::assertGreaterThanOrEqual(0, $kisi, "$kod negatife düştü");
        }
    }

    // ── PASİF firmaya yazılmış pay KAYBOLMAZ (varsayılana döner) ─
    public function testPasifFirmayaYazilmisPayVarsayilanaDoner(): void
    {
        $cid = $this->seedCantas();
        $this->repo->saveDayMeals($cid, '2026-07-20', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);
        $this->repo->saveGunAltFirma($cid, '2026-07-20', [
            'fatura_cantas_bakir' => 25, 'fatura_cantas_hc' => 45,
        ]);
        $bakirId = null;
        foreach ($this->repo->altFirmalar($cid) as $f) {
            if ($f['kod'] === 'fatura_cantas_bakir') {
                $bakirId = (int) $f['id'];
            }
        }
        $this->repo->setAltFirmaAktif((int) $bakirId, false);

        $o = $this->ozet($cid);
        self::assertArrayNotHasKey('fatura_cantas_bakir', $o, 'pasif firma dağıtımda yok');
        self::assertSame(70, array_sum($o), 'pasif firmanın payı kaybolmamalı');
        self::assertSame(70, $o['fatura_cantas_hc'], '45 + 25 (pasifin payı) varsayılana döndü');
    }

    // ── Türkçe firma adı + Türkçe müşteri adı bozulmaz ───────────
    public function testTurkceAdlarBozulmaz(): void
    {
        $cid = seed_customer($this->pdo, 'ŞİRKET ÖĞÜN A.Ş.', 100.0);
        $this->repo->upsertAltFirma($cid, 'birim_a', 'Öğün Bölümü İç-Dış', '1', false, 20, 1);
        $this->repo->upsertAltFirma($cid, 'birim_b', 'ÇAĞLAYAN Isıtma & Şube', '2', true, null, 2);
        $this->repo->saveDayMeals($cid, '2026-07-20', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 100.0);

        $this->repo->saveGunAltFirma($cid, '2026-07-20', ['birim_a' => 35, 'birim_b' => 15]);
        $o = $this->repo->aylikAltFirmaOzet($cid, '2026-07');
        self::assertSame(35, (int) $o['birim_a']['kisi']);
        self::assertSame(15, (int) $o['birim_b']['kisi']);
        self::assertSame('Öğün Bölümü İç-Dış', $o['birim_a']['ad'], 'Türkçe karakter bozuldu');
        self::assertSame('ÇAĞLAYAN Isıtma & Şube', $o['birim_b']['ad']);
        self::assertSame(['birim_a' => 35, 'birim_b' => 15], $this->repo->gunAltFirmaKirilim($cid, '2026-07-20'));
    }

    // ── REGRESYON: alt firması OLMAYAN müşteride hiçbir şey değişmez ─
    public function testAltFirmasizMusteriDegismez(): void
    {
        $o = seed_customer($this->pdo, 'OPAK', 250.0);
        $this->pdo->prepare("UPDATE customers SET irsaliye_aktif = 0, parasut_id = '111' WHERE id = ?")->execute([$o]);
        $this->repo->saveDayMeals($o, '2026-07-20', ['ogle' => 40, 'aksam' => 0, 'kumanya' => 0], 250.0);
        $this->repo->saveDayMeals($o, '2026-07-21', ['ogle' => 30, 'aksam' => 10, 'kumanya' => 0], 250.0);

        self::assertSame([], $this->repo->gunAltFirmaKirilim($o, '2026-07-20'));
        self::assertSame([], $this->repo->altFirmaElleGunler($o, '2026-07-01', '2026-07-31'));
        self::assertSame([], $this->repo->aylikAltFirmaOzet($o, '2026-07'), 'özet boş → eski davranış');

        $a = $this->repo->faturaAdaylari('2026-07-01', '2026-07-31')[0];
        self::assertSame(80, (int) $a['adet']);
        self::assertSame(80, (int) $a['fatura_adet']);
        self::assertNull($a['bolusum']);
        self::assertSame([], $a['altfirma']);
        self::assertSame([], $a['altfirma_elle']['gunler'], 'alt firmasız müşteride elle giriş yok');
        self::assertEqualsWithDelta(20000.0, $this->repo->customerCiroMap('2026-07')[$o], 0.01);

        // Alt firması olmayan müşteride elle kayıt hiç açılmaz
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->saveGunAltFirma($o, '2026-07-20', ['x' => 40]);
    }

    // ── FATURA TARAFI: elle kayıt otomatik yansır + not için gün listesi ─
    public function testFaturaAdaylariElleKayitlariYansitir(): void
    {
        $cid = $this->seedCantas();
        $this->seedTemmuzTatilli($cid);
        $this->repo->saveGunAltFirma($cid, '2026-07-15', ['fatura_cantas_hc' => 36]);

        $a = null;
        foreach ($this->repo->faturaAdaylari('2026-07-01', '2026-07-31') as $x) {
            if ((int) $x['customer_id'] === $cid) {
                $a = $x;
            }
        }
        self::assertNotNull($a);
        self::assertSame(1606, (int) $a['fatura_adet'], 'bölüşüm hedefi değişmedi');
        self::assertSame(726, (int) $a['altfirma']['fatura_cantas_hc']['kisi']);
        self::assertSame(660, (int) $a['altfirma']['fatura_cantas_icdis']['kisi']);
        self::assertSame(220, (int) $a['altfirma']['fatura_cantas_bakir']['kisi']);
        self::assertSame(
            1606,
            array_sum(array_column($a['altfirma'], 'kisi')),
            'faturaya giden 3 rakamın toplamı dönem fatura kişisine eşit olmalı'
        );
        self::assertSame(['2026-07-15'], $a['altfirma_elle']['gunler'], 'fatura penceresi notu');
        self::assertSame([], $a['altfirma_elle']['bayat']);
    }

    // ── Dağıtım fonksiyonu: kıran girdiler ───────────────────────
    public function testElleDagitKiranGirdiler(): void
    {
        $firmalar = [
            ['kod' => 'icdis', 'ad' => 'İç-Dış', 'varsayilan' => false, 'haftaici_sabit' => 30, 'sira' => 1],
            ['kod' => 'bakir', 'ad' => 'Bakır', 'varsayilan' => false, 'haftaici_sabit' => 10, 'sira' => 2],
            ['kod' => 'hc', 'ad' => 'HC', 'varsayilan' => true, 'haftaici_sabit' => null, 'sira' => 3],
        ];
        self::assertSame(
            ['icdis' => 0, 'bakir' => 0, 'hc' => 36],
            Repo::altFirmaElleDagit(36, ['hc' => 36], $firmalar)
        );
        // eksik yazılan gün → fark varsayılana
        self::assertSame(
            ['icdis' => 10, 'bakir' => 0, 'hc' => 26],
            Repo::altFirmaElleDagit(36, ['icdis' => 10], $firmalar)
        );
        // fazla yazılan gün → sondan kısılır, negatif YOK
        self::assertSame(
            ['icdis' => 30, 'bakir' => 0, 'hc' => 0],
            Repo::altFirmaElleDagit(30, ['icdis' => 30, 'bakir' => 10, 'hc' => 20], $firmalar)
        );
        // fatura kişisi 0/negatif → kırılım yok (0'a bölme / uydurma yok)
        self::assertSame(['icdis' => 0, 'bakir' => 0, 'hc' => 0], Repo::altFirmaElleDagit(0, ['hc' => 5], $firmalar));
        self::assertSame(['icdis' => 0, 'bakir' => 0, 'hc' => 0], Repo::altFirmaElleDagit(-5, ['hc' => 5], $firmalar));
        // tanınmayan kod → varsayılana
        self::assertSame(
            ['icdis' => 0, 'bakir' => 0, 'hc' => 20],
            Repo::altFirmaElleDagit(20, ['silinmis_firma' => 20], $firmalar)
        );
        // boş kayıt → tamamı varsayılana (fark kuralı)
        self::assertSame(['icdis' => 0, 'bakir' => 0, 'hc' => 70], Repo::altFirmaElleDagit(70, [], $firmalar));
    }
}
