<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;

/**
 * fable-051 — ALT FİRMA bölüşüm DESENİ (CANTAŞ faturası 3 ayrı şirkete kesilir).
 *
 * Desen (parametrik — musteri_altfirma.haftaici_sabit, koda gömülü DEĞİL):
 *   hafta içi → sabit kotalı firmalar `sira` düzeninde payını alır, KALAN varsayılana;
 *   cumartesi/pazar → TAMAMI varsayılana (Ömer: "cumartesiler default'ta HC'ye").
 * Hesap FATURA kişisi üzerinden (fable-040: CANTAŞ hafta içi üretim 50 / fatura 70).
 *
 * CANLI ÇIPA (Ömer'in Temmuz 2026 Excel'i): 1.640 fatura kişisi (₺537.920 / 328) →
 *   HC 720 · CANTAŞ İç-Dış 690 · CANTAŞ Bakır 230.
 */
final class AltFirmaTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
    }

    /** CANTAŞ: üretim 50 / hafta içi fatura 70; 3 alt firma (İç-Dış 30 · Bakır 10 · HC kalan). */
    private function seedCantas(int $icdis = 30, int $bakir = 10): int
    {
        $cid = seed_customer($this->pdo, 'CANTAŞ', 328.0);
        $this->pdo->prepare('UPDATE customers SET fatura_kisi_haftaici = 70, irsaliye_aktif = 0 WHERE id = ?')
            ->execute([$cid]);
        $this->repo->upsertAltFirma($cid, 'fatura_cantas_icdis', 'CANTAŞ İç-Dış', '1062205016', false, $icdis, 1);
        $this->repo->upsertAltFirma($cid, 'fatura_cantas_bakir', 'CANTAŞ Bakır', '1062204894', false, $bakir, 2);
        $this->repo->upsertAltFirma($cid, 'fatura_cantas_hc', 'HC Isıtma', '1062205054', true, null, 3);
        return $cid;
    }

    /** Temmuz 2026: 23 hafta içi günü × 50 üretim (=70 fatura) + 2 cumartesi × 15. */
    private function seedTemmuz(int $cid): void
    {
        for ($d = 1; $d <= 31; $d++) {
            $gun = sprintf('2026-07-%02d', $d);
            $dow = (int) date('N', strtotime($gun));
            if ($dow <= 5) {
                $this->repo->saveDayMeals($cid, $gun, ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);
            } elseif ($gun === '2026-07-11' || $gun === '2026-07-25') {
                // cumartesi çalışması — fable-040 kuralı hafta sonu UYGULANMAZ (gerçek 15)
                $this->repo->saveDayMeals($cid, $gun, ['ogle' => 15, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);
            }
        }
    }

    // ── ÇIPA: Temmuz 2026 → HC 720 · İç-Dış 690 · Bakır 230 ──────
    public function testTemmuzDeseniCanliCipa(): void
    {
        $cid = $this->seedCantas();
        $this->seedTemmuz($cid);

        $o = $this->repo->aylikAltFirmaOzet($cid, '2026-07');
        $this->assertSame(720, $o['fatura_cantas_hc']['kisi'], 'HC = 23×30 hafta içi + 2×15 cumartesi');
        $this->assertSame(690, $o['fatura_cantas_icdis']['kisi'], 'İç-Dış = 23×30');
        $this->assertSame(230, $o['fatura_cantas_bakir']['kisi'], 'Bakır = 23×10');

        $toplamKisi = $o['fatura_cantas_hc']['kisi'] + $o['fatura_cantas_icdis']['kisi'] + $o['fatura_cantas_bakir']['kisi'];
        $this->assertSame(1640, $toplamKisi, 'dönem fatura kişisi (₺537.920 / 328)');
        $this->assertEqualsWithDelta(537920.0, array_sum(array_column($o, 'tutar')), 0.01, 'bölüşüm tutarı = dönem cirosu');
    }

    // ── SENKRON: bölüşüm tutarı = production.amount toplamı (ciro) ─
    public function testBolusumToplamiCiroylaBirebir(): void
    {
        $cid = $this->seedCantas();
        $this->seedTemmuz($cid);
        $ciro = (float) $this->pdo->query('SELECT SUM(amount) FROM production')->fetchColumn();
        $o = $this->repo->aylikAltFirmaOzet($cid, '2026-07');
        $this->assertEqualsWithDelta($ciro, array_sum(array_column($o, 'tutar')), 0.01, 'kırılım ciroyu birebir bölüyor');
        $this->assertEqualsWithDelta($ciro, $this->repo->customerCiroMap('2026-07')[$cid], 0.01, 'ciro kaynağı tek');
    }

    // ── Hafta sonu: TAMAMI varsayılan firmaya (Ömer kuralı) ──────
    public function testHaftaSonuTamamiVarsayilana(): void
    {
        $cid = $this->seedCantas();
        // 2026-07-11 Cmt · 2026-07-12 Paz
        $this->repo->saveDayMeals($cid, '2026-07-11', ['ogle' => 15, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);
        $this->repo->saveDayMeals($cid, '2026-07-12', ['ogle' => 8, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);
        $o = $this->repo->aylikAltFirmaOzet($cid, '2026-07');
        $this->assertSame(23, $o['fatura_cantas_hc']['kisi'], 'cumartesi + pazar tamamı HC (15+8)');
        $this->assertSame(0, $o['fatura_cantas_icdis']['kisi']);
        $this->assertSame(0, $o['fatura_cantas_bakir']['kisi']);
    }

    // ── Az kişili hafta içi gün: negatife DÜŞMEZ (sondan kısılır) ─
    public function testAzKisiliGunNegatifeDusmez(): void
    {
        $firmalar = [
            ['kod' => 'icdis', 'ad' => 'İç-Dış', 'varsayilan' => false, 'haftaici_sabit' => 30, 'sira' => 1],
            ['kod' => 'bakir', 'ad' => 'Bakır', 'varsayilan' => false, 'haftaici_sabit' => 10, 'sira' => 2],
            ['kod' => 'hc', 'ad' => 'HC', 'varsayilan' => true, 'haftaici_sabit' => null, 'sira' => 3],
        ];
        $pzt = '2026-07-20'; // Pazartesi
        $this->assertSame(['icdis' => 30, 'bakir' => 10, 'hc' => 30], Repo::altFirmaGunDagit(70, $pzt, $firmalar));
        $this->assertSame(['icdis' => 30, 'bakir' => 10, 'hc' => 20], Repo::altFirmaGunDagit(60, $pzt, $firmalar));
        // 30 kişi → İç-Dış 30, önce Bakır sonra HC kısılır; hiçbiri negatif değil
        $this->assertSame(['icdis' => 30, 'bakir' => 0, 'hc' => 0], Repo::altFirmaGunDagit(30, $pzt, $firmalar));
        // 35 kişi → İç-Dış tam kotayı alır, Bakır kalanla yetinir (5), HC 0
        $this->assertSame(['icdis' => 30, 'bakir' => 5, 'hc' => 0], Repo::altFirmaGunDagit(35, $pzt, $firmalar));
        $this->assertSame(['icdis' => 0, 'bakir' => 0, 'hc' => 0], Repo::altFirmaGunDagit(0, $pzt, $firmalar), 'üretim yok → kırılım yok');
        $this->assertSame(['icdis' => 0, 'bakir' => 0, 'hc' => 0], Repo::altFirmaGunDagit(-5, $pzt, $firmalar), 'negatif girdi güvenli');
        // hafta sonu: tamamı varsayılana
        $this->assertSame(['icdis' => 0, 'bakir' => 0, 'hc' => 15], Repo::altFirmaGunDagit(15, '2026-07-25', $firmalar));
    }

    // ── PARAMETRİK: sabit kotalar değişince sonuç değişir (koda gömülü değil) ─
    public function testSabitKotaParametrik(): void
    {
        $cid = $this->seedCantas(25, 5); // İç-Dış 25 · Bakır 5 → HC 40
        $this->seedTemmuz($cid);
        $o = $this->repo->aylikAltFirmaOzet($cid, '2026-07');
        $this->assertSame(23 * 25, $o['fatura_cantas_icdis']['kisi']);
        $this->assertSame(23 * 5, $o['fatura_cantas_bakir']['kisi']);
        $this->assertSame(23 * 40 + 30, $o['fatura_cantas_hc']['kisi'], 'kalan + cumartesiler HC');
        $this->assertSame(1640, array_sum(array_column($o, 'kisi')), 'toplam değişmez, dağılım değişir');
    }

    // ── faturaAdaylari: bölüşüm hedefi FATURA kişisi (üretim değil) ─
    public function testFaturaAdayiDesenVeFaturaAdedi(): void
    {
        $cid = $this->seedCantas();
        $this->pdo->prepare("UPDATE customers SET parasut_id = '999', fatura_bolusum = ? WHERE id = ?")->execute([
            json_encode([
                ['key' => 'fatura_cantas_icdis', 'ad' => 'CANTAŞ İç-Dış'],
                ['key' => 'fatura_cantas_hc', 'ad' => 'HC Isıtma'],
                ['key' => 'fatura_cantas_bakir', 'ad' => 'CANTAŞ Bakır'],
            ], JSON_UNESCAPED_UNICODE),
            $cid,
        ]);
        // Bölüşüm cari id'leri ayar tablosundan çözülür (fatura_bolusum key = ayar anahtarı)
        $this->repo->ayarSet('fatura_cantas_icdis', '1062205016');
        $this->repo->ayarSet('fatura_cantas_bakir', '1062204894');
        $this->repo->ayarSet('fatura_cantas_hc', '1062205054');
        $this->seedTemmuz($cid);

        $a = null;
        foreach ($this->repo->faturaAdaylari('2026-07-01', '2026-07-31') as $x) {
            if ((int) $x['customer_id'] === $cid) {
                $a = $x;
            }
        }
        $this->assertNotNull($a);
        $this->assertSame('aylik', $a['tip']);
        $this->assertSame(23 * 50 + 30, (int) $a['adet'], "'adet' GERÇEK üretim kalır (semantik değişmedi)");
        $this->assertSame(1640, (int) $a['fatura_adet'], 'bölüşüm hedefi = dönemin FATURA kişisi');
        $this->assertSame(720, $a['altfirma']['fatura_cantas_hc']['kisi']);
        $this->assertSame(690, $a['altfirma']['fatura_cantas_icdis']['kisi']);
        $this->assertSame(230, $a['altfirma']['fatura_cantas_bakir']['kisi']);
        // fatura_bolusum key'leri ile alt firma kodları eşleşiyor (pencere dolu gelsin)
        foreach ($a['bolusum'] as $b) {
            $this->assertArrayHasKey($b['key'], $a['altfirma'], 'bölüşüm key ↔ alt firma kod eşleşmeli');
            $this->assertNotSame('', $a['altfirma'][$b['key']]['contact_id'], 'Paraşüt cari id çözülmeli');
        }
    }

    // ── REGRESYON: alt firması OLMAYAN müşteride hiçbir şey değişmez ─
    public function testAltFirmasizMusteriDegismez(): void
    {
        $o = seed_customer($this->pdo, 'OPAK', 250.0);
        $this->pdo->prepare("UPDATE customers SET irsaliye_aktif = 0, parasut_id = '111' WHERE id = ?")->execute([$o]);
        $this->repo->saveDayMeals($o, '2026-07-20', ['ogle' => 40, 'aksam' => 0, 'kumanya' => 0], 250.0);
        $this->repo->saveDayMeals($o, '2026-07-21', ['ogle' => 30, 'aksam' => 10, 'kumanya' => 0], 250.0);

        $this->assertSame([], $this->repo->altFirmalar($o), 'alt firma yok');
        $this->assertSame([], $this->repo->aylikAltFirmaOzet($o, '2026-07'), 'özet boş → çağıran eski davranışta');
        $this->assertNull($this->repo->altFirmaVarsayilan($o));

        $a = $this->repo->faturaAdaylari('2026-07-01', '2026-07-31')[0];
        $this->assertSame(80, (int) $a['adet'], 'üretim adedi eskisi gibi');
        $this->assertSame(80, (int) $a['fatura_adet'], 'kural yok → fatura kişisi = üretim');
        $this->assertNull($a['bolusum']);
        $this->assertSame([], $a['altfirma']);
        // ciro/kişi sayıları hiç etkilenmedi
        $this->assertEqualsWithDelta(20000.0, $this->repo->customerCiroMap('2026-07')[$o], 0.01);
        $this->assertSame(80, $this->repo->customerPersonsMap('2026-07')[$o]);
    }

    // ── Pasif alt firma bölüşüme GİRMEZ (silme yok, iz kalır) ─────
    public function testPasifAltFirmaBolusumeGirmez(): void
    {
        $cid = $this->seedCantas();
        $this->repo->saveDayMeals($cid, '2026-07-20', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);
        $bakir = null;
        foreach ($this->repo->altFirmalar($cid) as $f) {
            if ($f['kod'] === 'fatura_cantas_bakir') {
                $bakir = $f['id'];
            }
        }
        $this->repo->setAltFirmaAktif((int) $bakir, false);

        $o = $this->repo->aylikAltFirmaOzet($cid, '2026-07');
        $this->assertArrayNotHasKey('fatura_cantas_bakir', $o, 'pasif firma dağıtımda yok');
        $this->assertSame(30, $o['fatura_cantas_icdis']['kisi']);
        $this->assertSame(40, $o['fatura_cantas_hc']['kisi'], 'Bakır payı kalana (HC) döner — kişi kaybolmaz');
        $this->assertSame(70, array_sum(array_column($o, 'kisi')));
        // kayıt SİLİNMEDİ (iz kalır)
        $this->assertCount(3, $this->repo->altFirmalar($cid, false));
    }

    // ── Varsayılan TEK olur; işaret taşınınca eskisi düşer ────────
    public function testTekVarsayilanVeTurkceAlanlar(): void
    {
        $cid = seed_customer($this->pdo, 'ŞİRKET ÖĞÜN A.Ş.', 100.0);
        $this->repo->upsertAltFirma($cid, 'birim_a', 'Öğün Bölümü İç-Dış', '1', true, 5, 1);
        $this->repo->upsertAltFirma($cid, 'birim_b', 'ÇAĞLAYAN Isıtma', '2', false, null, 2);
        $this->assertSame('birim_a', $this->repo->altFirmaVarsayilan($cid)['kod']);

        // ikinciyi varsayılan yap → ilkinin işareti düşer (tek varsayılan)
        $this->repo->upsertAltFirma($cid, 'birim_b', 'ÇAĞLAYAN Isıtma', '2', true, null, 2);
        $this->assertSame('birim_b', $this->repo->altFirmaVarsayilan($cid)['kod']);
        $vars = 0;
        foreach ($this->repo->altFirmalar($cid) as $f) {
            $vars += $f['varsayilan'] ? 1 : 0;
        }
        $this->assertSame(1, $vars, 'tam olarak bir varsayılan');
        $this->assertSame('ÇAĞLAYAN Isıtma', $this->repo->altFirmaVarsayilan($cid)['ad'], 'Türkçe karakter bozulmadı');

        // aynı kod ikinci kez → yeni kayıt AÇMAZ (UNIQUE upsert)
        $this->repo->upsertAltFirma($cid, 'birim_a', 'Öğün Bölümü İç-Dış', '1', false, 7, 1);
        $this->assertCount(2, $this->repo->altFirmalar($cid, false));
        $this->assertSame(7, $this->repo->altFirmalar($cid)[0]['haftaici_sabit']);
    }

    // ── Varsayılan işaretlenmemişse: kotasız firma kalanı alır ───
    public function testVarsayilanIsaretsizKotasizFirmaKalaniAlir(): void
    {
        $cid = seed_customer($this->pdo, 'İŞARETSİZ AŞ', 100.0);
        $this->repo->upsertAltFirma($cid, 'a', 'A', null, false, 20, 1);
        $this->repo->upsertAltFirma($cid, 'b', 'B', null, false, null, 2);
        $this->repo->saveDayMeals($cid, '2026-07-20', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 100.0);
        $o = $this->repo->aylikAltFirmaOzet($cid, '2026-07');
        $this->assertSame(20, $o['a']['kisi']);
        $this->assertSame(30, $o['b']['kisi'], 'kotasız firma kalanı alır');
    }

    // ── Ay-bazlı fiyat: tutar her günün KENDİ ayının fiyatından ──
    public function testAyBazliFiyatIkiAyaTasanDonem(): void
    {
        $cid = $this->seedCantas();
        $this->repo->setCustomerPrice($cid, '2026-07', 328.0);
        $this->repo->setCustomerPrice($cid, '2026-08', 400.0);
        // 31 Tem Cuma (hafta içi) + 3 Ağu Pazartesi
        $this->repo->saveDayMeals($cid, '2026-07-31', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);
        $this->repo->saveDayMeals($cid, '2026-08-03', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 400.0, 'uysa', 70);

        $d = $this->repo->altFirmaDagilim($cid, '2026-07-31', '2026-08-03');
        $this->assertSame(140, array_sum(array_column($d, 'kisi')), '2 gün × 70 fatura kişisi');
        $this->assertEqualsWithDelta(70 * 328.0 + 70 * 400.0, array_sum(array_column($d, 'tutar')), 0.01);
        $this->assertSame(60, $d['fatura_cantas_icdis']['kisi'], '2 × 30');
        $this->assertSame(20, $d['fatura_cantas_bakir']['kisi'], '2 × 10');
    }

    /**
     * fable-054 REGRESYON: faturaAdaylari bölüşümü musteri_altfirma'dan üretirken cariyi
     * 'contact_id' anahtarından okumalı. Yanlış anahtar okunursa cari BOŞ görünür ve müşteri
     * "Bölüşüm cari eşleşmesi eksik" diye FATURA KESİLEMEZ hale gelir (canlıda yaşandı).
     */
    public function testFaturaAdaylariBolusumCarisiDolulur(): void
    {
        $cid = $this->seedCantas();
        $this->seedTemmuz($cid);

        $bulundu = null;
        foreach ($this->repo->faturaAdaylari('2026-07-01', '2026-07-31') as $a) {
            if ((int) $a['customer_id'] === $cid) {
                $bulundu = $a;
            }
        }
        self::assertNotNull($bulundu, 'aylık müşteri adaylarda yok');
        self::assertNotEmpty($bulundu['bolusum'] ?? [], 'bölüşüm üretilmedi');
        foreach ($bulundu['bolusum'] as $p) {
            self::assertNotSame('', $p['contact_id'], 'alt firma carisi BOŞ geldi: ' . $p['ad']);
        }
        self::assertTrue((bool) $bulundu['secilebilir'], 'cari dolu ama seçilemez: ' . ($bulundu['sebep'] ?? ''));
    }

    /**
     * fable-055 (Ömer): "CANTAŞ ve Marmara'da haftalık sayıları çekiyor, aylık çekmesi lazım."
     * Aylık müşteri ekrandaki dönem HAFTALIK olsa bile ayın TAMAMINDAN faturalanır.
     */
    public function testAylikMusteriHaftalikDonemdeDeAyinTamaminiCeker(): void
    {
        $cid = $this->seedCantas();
        $this->seedTemmuz($cid);

        $al = function (string $bas, string $son) use ($cid): ?array {
            foreach ($this->repo->faturaAdaylari($bas, $son) as $a) {
                if ((int) $a['customer_id'] === $cid) {
                    return $a;
                }
            }
            return null;
        };

        $hafta = $al('2026-07-22', '2026-07-28');   // haftalık tur
        $ay = $al('2026-07-01', '2026-07-31');      // ay sonu turu

        self::assertNotNull($hafta, 'haftalık dönemde aylık müşteri listelenmedi');
        self::assertSame(1640, (int) $hafta['fatura_adet'], 'haftalık dönemde ay toplamı gelmeli');
        self::assertSame((int) $ay['fatura_adet'], (int) $hafta['fatura_adet'], 'iki dönemde farklı sayı');
        self::assertSame('2026-07-01', $hafta['donem_bas']);
        self::assertSame('2026-07-31', $hafta['donem_son']);

        // bölüşüm de ay toplamından doğmalı (720/690/230)
        $kisiler = [];
        foreach ($hafta['altfirma'] as $v) {
            $kisiler[] = (int) $v['kisi'];
        }
        sort($kisiler);
        self::assertSame([230, 690, 720], $kisiler, 'haftalık dönemde bölüşüm ay toplamından gelmedi');
    }

    /**
     * fable-056 (Ömer): "CANTAŞ ve Marmara ayın son günü açık olsun, öncesinde seçilemesin."
     * Ay kapanmadan kesilen aylık fatura eksik kişiyle gider ve e-fatura geri alınamaz.
     */
    public function testAylikFaturaAyKapanmadanSecilemez(): void
    {
        $cid = $this->seedCantas();
        $this->seedTemmuz($cid);

        $durum = function (string $bugun) use ($cid): array {
            putenv('APP_TODAY=' . $bugun);
            try {
                foreach ($this->repo->faturaAdaylari('2026-07-01', '2026-07-31') as $a) {
                    if ((int) $a['customer_id'] === $cid) {
                        return $a;
                    }
                }
                return [];
            } finally {
                putenv('APP_TODAY');
            }
        };

        $ayOrtasi = $durum('2026-07-15');
        self::assertFalse((bool) $ayOrtasi['secilebilir'], 'ay ortasında kesilebiliyor');
        self::assertStringContainsString('31.07.2026', (string) $ayOrtasi['sebep']);

        $sonGunOncesi = $durum('2026-07-30');
        self::assertFalse((bool) $sonGunOncesi['secilebilir'], 'son günden bir gün önce açık');

        $sonGun = $durum('2026-07-31');
        self::assertTrue((bool) $sonGun['secilebilir'], 'ayın son günü kapalı: ' . ($sonGun['sebep'] ?? ''));

        $sonrakiAy = $durum('2026-08-04');
        self::assertTrue((bool) $sonrakiAy['secilebilir'], 'ay bittikten sonra kapalı kalmış');
    }

    /**
     * fable-057 (Ömer: "tatil günlerinde yemek yemiyor"): RESMİ TATİLDE hafta içi fatura
     * kişisi kuralı UYGULANMAZ. 15 Temmuz'da CANTAŞ 36 kişi girildiği halde 70 kişiden
     * faturalanıyordu → müşteriye ₺11.152 fazla yansıyacaktı (canlıda yakalandı).
     */
    public function testResmiTatildeFaturaKisisiKuraliUygulanmaz(): void
    {
        $cid = $this->seedCantas();
        $this->pdo->prepare('INSERT INTO resmi_tatil (tarih, ad, tur, yarim_gun, aktif) VALUES (?, ?, ?, 0, 1)')
            ->execute(['2026-07-15', 'Demokrasi ve Milli Birlik Günü', 'resmi']);

        self::assertTrue($this->repo->tatilMi('2026-07-15'), 'tatil tanınmadı');
        self::assertFalse($this->repo->tatilMi('2026-07-16'), 'normal gün tatil sayıldı');

        // Tatil günü: kural uygulanmaz → fatura kişisi = girilen kişi
        $this->repo->saveDayMeals($cid, '2026-07-15', ['ogle' => 36, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', null);
        // Normal hafta içi: kural uygulanır → 50 girildi, fatura 70
        $this->repo->saveDayMeals($cid, '2026-07-16', ['ogle' => 50, 'aksam' => 0, 'kumanya' => 0], 328.0, 'uysa', 70);

        $map = $this->repo->gunFaturaKisiMap($cid, '2026-07-15', '2026-07-16');
        self::assertSame(36, (int) ($map['2026-07-15'] ?? 0), 'tatilde 70 kuralı uygulanmış');
        self::assertSame(70, (int) ($map['2026-07-16'] ?? 0), 'normal günde kural bozulmuş');
    }
}
