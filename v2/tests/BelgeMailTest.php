<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Mail;
use Uysa\ParasutPdf;
use Uysa\ParasutYaz;
use Uysa\Repo;

/**
 * fable-052 — Fatura/irsaliye müşteriye TEK PDF olarak UYSA mailinden gider.
 *
 * 🔒 GERÇEK AĞ YOK, GERÇEK MAİL YOK: SMTP taşıyıcısı ve Paraşüt PDF katmanı enjekte edilir;
 *    her testte "ne gönderildi, kime, kaç kez" ÖLÇÜLÜR. Testler ağa ÇIKMAZ.
 */
final class BelgeMailTest extends TestCase
{
    private PDO $pdo;
    private Repo $repo;
    /** @var array<int,array<string,mixed>> gönderilen SMTP zarfları */
    private array $zarflar = [];
    /** @var array<int,array{method:string,path:string,body:?array}> */
    private array $cagrilar = [];

    protected function setUp(): void
    {
        $this->pdo = fresh_db();
        $this->repo = new Repo($this->pdo);
        $this->zarflar = [];
        $this->cagrilar = [];
        foreach (['PARASUT_IRSALIYE_AKTIF' => '1', 'PARASUT_FATURA_AKTIF' => '1',
            'SMTP_HOST' => 'smtp.test', 'SMTP_PORT' => '465',
            'SMTP_USER' => 'fatura@uysayemek.com.tr', 'SMTP_PASS' => 'gizli',
            'SMTP_FROM_AD' => 'UYSA Yemek Hizmetleri'] as $k => $v) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
        // SMTP konuşmasını yakala — hiçbir bayt ağa gitmez.
        Mail::tasiyiciAta(function (array $z): array {
            $this->zarflar[] = $z;
            return ['ok' => true, 'mesaj' => implode(', ', $z['rcpts'])];
        });
    }

    protected function tearDown(): void
    {
        foreach (['PARASUT_IRSALIYE_AKTIF', 'PARASUT_FATURA_AKTIF', 'SMTP_HOST', 'SMTP_PORT',
            'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM_AD'] as $k) {
            putenv($k);
            unset($_ENV[$k]);
        }
        // Ortak güvenlik ağını geri kur (tests/bootstrap.php ile aynı): asla gerçek gönderim.
        Mail::tasiyiciAta(static fn(array $z): array => ['ok' => false, 'mesaj' => 'TEST: SMTP kapalı']);
        ParasutPdf::agAta(static fn(string $p, array $q): ?array => null, static fn(string $u): ?string => null);
    }

    // ══ 1) MIME zarfı: multipart sınırları + base64 ek ═══════════════════

    public function testMimeMultipartSinirlariVeBase64Ek(): void
    {
        $pdf = "%PDF-1.4\nsahte pdf içeriği\n%%EOF";
        $m = Mail::mime('UYSA Yemek Hizmetleri', 'fatura@uysayemek.com.tr', ['a@b.com'],
            'Fatura UY1 — UYSA', "Merhabalar,\nFaturanız ektedir.\n",
            [['ad' => 'UYSA-fatura-UY1.pdf', 'tip' => 'application/pdf', 'veri' => $pdf]], 'SINIR1');

        $this->assertStringContainsString("Content-Type: multipart/mixed; boundary=\"SINIR1\"\r\n", $m);
        $this->assertSame(2, substr_count($m, "--SINIR1\r\n"), 'iki parça: gövde + ek');
        $this->assertStringEndsWith("--SINIR1--\r\n", $m, 'kapanış sınırı eksikse mail bozuk görünür');
        $this->assertStringContainsString('Content-Type: application/pdf; name="UYSA-fatura-UY1.pdf"', $m);
        $this->assertStringContainsString('Content-Disposition: attachment; filename="UYSA-fatura-UY1.pdf"', $m);
        $this->assertStringContainsString("Content-Transfer-Encoding: base64", $m);

        // Ek gerçekten aynı PDF mi? (base64 çöz → birebir)
        $parcalar = explode("--SINIR1", $m);
        $ekBlok = $parcalar[2];
        $govdeB64 = trim(substr($ekBlok, (int) strpos($ekBlok, "\r\n\r\n") + 4));
        $this->assertSame($pdf, base64_decode(str_replace("\r\n", '', $govdeB64), true));
    }

    // ══ 2) TÜRKÇE KARAKTER — iki projede canlıda ısırdı, testle kilitli ══

    public function testTurkceKonuUtf8Base64KodlanirVeGeriCozulur(): void
    {
        $konu = 'İrsaliye · Öğlen · ŞŞ ĞĞ ÜÜ';
        $kodlu = Mail::konuKodla($konu);

        $this->assertStringContainsString('=?UTF-8?B?', $kodlu, 'Türkçe konu ham geçemez');
        $this->assertMatchesRegularExpression('/^[\x00-\x7F]*$/', $kodlu, 'başlık saf ASCII olmalı');
        // Her satır (katlama dahil) 75 karakteri aşmamalı — RFC 2047.
        foreach (explode("\r\n", $kodlu) as $satir) {
            $this->assertLessThanOrEqual(76, strlen($satir), 'kodlanmış başlık satırı çok uzun');
        }
        $this->assertSame($konu, mb_decode_mimeheader($kodlu), 'çözülünce birebir aynı metin çıkmalı');
    }

    public function testTurkceGovdeUtf8OlarakGider(): void
    {
        $govde = Mail::belgeGovdesi('irsaliye');
        $this->assertStringContainsString('Bugünün irsaliyesi ektedir.', $govde);
        $this->assertStringContainsString('İyi çalışmalar dilerim.', $govde);

        $m = Mail::mime('UYSA', 'f@u.com', ['a@b.com'], 'x', $govde, [], 'S');
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $m);
        $blok = explode('--S', $m)[1];
        $b64 = trim(substr($blok, (int) strpos($blok, "\r\n\r\n") + 4));
        $cozulen = base64_decode(str_replace("\r\n", '', $b64), true);
        $this->assertStringContainsString('Bugünün irsaliyesi ektedir.', (string) $cozulen);
        $this->assertStringContainsString('İyi çalışmalar dilerim.', (string) $cozulen);
        $this->assertSame('UTF-8', mb_detect_encoding((string) $cozulen, ['UTF-8'], true));
    }

    public function testSafAsciiKonuKodlanmaz(): void
    {
        $this->assertSame('Fatura UY1 - UYSA', Mail::konuKodla('Fatura UY1 - UYSA'));
    }

    public function testEkDosyaAdiAsciiyeIndirgenir(): void
    {
        // Türkçe/boşluklu ad ek başlığını bozmasın (istemciler farklı yorumlar).
        $this->assertSame('UYSA-irsaliye-OGLEN.pdf', Mail::dosyaAdiTemizle('UYSA irsaliye ÖĞLEN.pdf'));
        $this->assertStringNotContainsString('"', Mail::dosyaAdiTemizle('kot"u"ad.pdf'));
    }

    // ══ 3) Çoklu alıcı ayrıştırma ════════════════════════════════════════

    public function testCokluAliciAyristirilir(): void
    {
        $r = Mail::adresAyristir(' a@b.com , Ad Soyad <c@d.com>; e@f.com,, A@B.COM ; bozukadres ');
        $this->assertSame(['a@b.com', 'c@d.com', 'e@f.com'], $r, 'geçersiz atılır, mükerrer tekilleşir');
        $this->assertSame([], Mail::adresAyristir(''));
        $this->assertSame([], Mail::adresAyristir('   ,  ; '));
    }

    public function testHerAliciyaRcptGider(): void
    {
        $r = Mail::gonder('a@b.com, c@d.com', 'Konu', 'Gövde');
        $this->assertTrue($r['ok']);
        $this->assertCount(1, $this->zarflar, 'tek SMTP oturumu');
        $this->assertSame(['a@b.com', 'c@d.com'], $this->zarflar[0]['rcpts']);
        $this->assertStringContainsString('To: a@b.com, c@d.com', $this->zarflar[0]['mesaj']);
    }

    // ══ 4) Mail ASLA çağıranı çökertmez ══════════════════════════════════

    public function testGecersizAliciCokertmezHataDoner(): void
    {
        $r = Mail::gonder('bozuk', 'Konu', 'Gövde');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('alıcı', $r['mesaj']);
        $this->assertSame([], $this->zarflar, 'geçersiz adreste SMTP bağlantısı bile açılmaz');
    }

    public function testSmtpTanimsizsaSessizceHataDonerCokmez(): void
    {
        foreach (['SMTP_HOST', 'SMTP_USER', 'SMTP_PASS'] as $k) {
            putenv($k);
            unset($_ENV[$k]);
        }
        $this->assertFalse(Mail::yapilandirilmis());
        $r = Mail::gonder('a@b.com', 'Konu', 'Gövde');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('SMTP yapılandırılmamış', $r['mesaj']);
    }

    public function testTasiyiciIstisnaFirlatirsaYineDeArrayDoner(): void
    {
        Mail::tasiyiciAta(static function (array $z): array {
            throw new \RuntimeException('ağ koptu');
        });
        $r = Mail::gonder('a@b.com', 'Konu', 'Gövde');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('ağ koptu', $r['mesaj']);
    }

    // ══ 5) Konu / dosya adı üretimi (Ömer'in birebir istediği biçim) ══════

    public function testKonuVeDosyaAdiBicimi(): void
    {
        $this->assertSame('İrsaliye 29.07.2026 — UYSA Yemek Hizmetleri',
            Mail::belgeKonusu('irsaliye', 'UU1', '2026-07-29'));
        $this->assertSame('Fatura UY02026000000132 — UYSA Yemek Hizmetleri',
            Mail::belgeKonusu('fatura', 'UY02026000000132', null));
        $this->assertSame('Fatura — UYSA Yemek Hizmetleri', Mail::belgeKonusu('fatura', null, null),
            'belge no yoksa boşluk artığı kalmamalı');
        $this->assertSame('UYSA-irsaliye-2026-07-29.pdf', Mail::belgeDosyaAdi('irsaliye', 'UU1', '2026-07-29'));
        $this->assertSame('UYSA-fatura-UY02026000000132.pdf', Mail::belgeDosyaAdi('fatura', 'UY02026000000132', null));
        $this->assertStringContainsString('Faturanız ektedir.', Mail::belgeGovdesi('fatura'));
    }

    // ══ 6) KUYRUK — mükerrer kalkanı + boş adres ═════════════════════════

    public function testAyniBelgeIkiKezKuyrugaGirmez(): void
    {
        $cid = seed_customer($this->pdo, 'BOMİ', 245.0);
        $a = $this->repo->mailKuyrugaEkle('irsaliye', $cid, 'DOC1', 'a@b.com', 'UU1', '2026-07-29');
        $b = $this->repo->mailKuyrugaEkle('irsaliye', $cid, 'DOC1', 'a@b.com', 'UU1', '2026-07-29');
        $this->assertTrue($a['ok']);
        $this->assertTrue($b['ok']);
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM mail_kuyruk')->fetchColumn());

        // Aynı kaynak_id farklı TÜR ise ayrı satır (fatura ve irsaliye id uzayları ayrı).
        $this->repo->mailKuyrugaEkle('fatura', $cid, 'DOC1', 'a@b.com', 'UY1', null);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM mail_kuyruk')->fetchColumn());
    }

    public function testMailAdresiYoksaKuyrugaKayitAcilmaz(): void
    {
        $cid = seed_customer($this->pdo, 'BOMİ', 245.0);
        $r = $this->repo->mailKuyrugaEkle('irsaliye', $cid, 'DOC9', '   ', 'UU9', '2026-07-29');
        $this->assertFalse($r['ok']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mail_kuyruk')->fetchColumn());
    }

    // ══ 7) KUYRUK İŞLEME — PDF yoksa deneme artar, tavanda 'hata' ════════

    public function testPdfHazirDegilseDenemeArtarVeMaxDenemedeHataOlur(): void
    {
        $cid = seed_customer($this->pdo, 'BOMİ', 245.0);
        $this->repo->irsaliyeLogKaydet($cid, '2026-07-29', ['durum' => 'kesildi', 'parasut_doc_id' => 'DOC1']);
        $this->repo->mailKuyrugaEkle('irsaliye', $cid, 'DOC1', 'a@b.com', 'UU1', '2026-07-29');

        $pdfYok = static fn(string $t, string $k): ?string => null;
        $mailCagrildi = 0;
        $mail = function () use (&$mailCagrildi): array {
            $mailCagrildi++;
            return ['ok' => true, 'mesaj' => 'x'];
        };

        for ($i = 1; $i < Repo::MAIL_MAX_DENEME; $i++) {
            $r = $this->repo->mailKuyrukIsle(20, $pdfYok, $mail);
            $this->assertSame(1, $r['islenen']);
            // Kira süresini geçmiş say (cron 5 dk'da bir çalışır; testte beklemiyoruz)
            $this->pdo->exec("UPDATE mail_kuyruk SET updated_at = datetime('now','-10 minutes')");
            $s = $this->repo->mailKuyrukSatiri('irsaliye', 'DOC1');
            $this->assertSame('bekliyor', $s['durum'], "deneme $i sonrası hâlâ bekliyor olmalı");
            $this->assertSame($i, (int) $s['deneme']);
        }
        // Tavan denemesi → kalıcı 'hata' (sonsuz döngü yok)
        $r = $this->repo->mailKuyrukIsle(20, $pdfYok, $mail);
        $s = $this->repo->mailKuyrukSatiri('irsaliye', 'DOC1');
        $this->assertSame('hata', $s['durum']);
        $this->assertSame(Repo::MAIL_MAX_DENEME, (int) $s['deneme']);
        $this->assertSame(1, $r['hata']);
        $this->assertStringContainsString('PDF', (string) $s['son_hata']);
        $this->assertSame(0, $mailCagrildi, 'PDF yokken mail HİÇ denenmez (boş ek gitmesin)');

        // 'hata' satırı bir daha işlenmez
        $this->assertSame(0, $this->repo->mailKuyrukIsle(20, $pdfYok, $mail)['islenen']);
        // Kesim kaydındaki gösterge de sessiz kalmaz
        $this->assertSame('hata', $this->repo->irsaliyeLog($cid, '2026-07-29')['mail']);
    }

    public function testPdfHazirsaTekPdfEkiyleGonderilirVeIzKalir(): void
    {
        $cid = seed_customer($this->pdo, 'BOMİ', 245.0);
        $this->repo->irsaliyeLogKaydet($cid, '2026-07-29', ['durum' => 'kesildi', 'parasut_doc_id' => 'DOC1']);
        $this->repo->mailKuyrugaEkle('irsaliye', $cid, 'DOC1', 'a@b.com; c@d.com', 'UU1', '2026-07-29');

        $pdf = "%PDF-1.4\nirsaliye\n%%EOF";
        $r = $this->repo->mailKuyrukIsle(20, static fn(string $t, string $k): ?string => $pdf);

        $this->assertSame(1, $r['gonderildi']);
        $this->assertCount(1, $this->zarflar);
        $zarf = $this->zarflar[0];
        $this->assertSame(['a@b.com', 'c@d.com'], $zarf['rcpts']);
        $this->assertStringContainsString('=?UTF-8?B?', $zarf['mesaj'], 'Türkçe konu kodlanmış olmalı');
        $this->assertStringContainsString('filename="UYSA-irsaliye-2026-07-29.pdf"', $zarf['mesaj']);
        $this->assertStringContainsString('Content-Type: application/pdf', $zarf['mesaj']);
        $this->assertStringNotContainsString('zip', strtolower($zarf['mesaj']), 'müşteriye ZIP gitmez');

        $s = $this->repo->mailKuyrukSatiri('irsaliye', 'DOC1');
        $this->assertSame('gonderildi', $s['durum']);
        $this->assertNotNull($s['gonderim_at']);
        $this->assertNull($s['son_hata']);
        $this->assertSame('gonderildi', $this->repo->irsaliyeLog($cid, '2026-07-29')['mail']);

        // İkinci tur: gönderilmiş satır TEKRAR gönderilmez (mükerrer mail kalkanı)
        $r2 = $this->repo->mailKuyrukIsle(20, static fn(string $t, string $k): ?string => $pdf);
        $this->assertSame(0, $r2['islenen']);
        $this->assertCount(1, $this->zarflar);
    }

    public function testSmtpYapilandirilmamissaKuyrukHicDenenmezDenemeArtmaz(): void
    {
        $cid = seed_customer($this->pdo, 'BOMİ', 245.0);
        $this->repo->mailKuyrugaEkle('irsaliye', $cid, 'DOC1', 'a@b.com', 'UU1', '2026-07-29');
        foreach (['SMTP_HOST', 'SMTP_USER', 'SMTP_PASS'] as $k) {
            putenv($k);
            unset($_ENV[$k]);
        }
        $r = $this->repo->mailKuyrukIsle(20); // enjeksiyon YOK → gerçek katman kapıları
        $this->assertSame(0, $r['islenen']);
        $this->assertNotSame('', $r['atlandi'], 'sebep açıkça bildirilir');
        $s = $this->repo->mailKuyrukSatiri('irsaliye', 'DOC1');
        $this->assertSame('bekliyor', $s['durum']);
        $this->assertSame(0, (int) $s['deneme'], 'yapılandırma eksikliği belgeyi yakmaz');
    }

    public function testKuyrukOzetiVeListesi(): void
    {
        $cid = seed_customer($this->pdo, 'BOMİ', 245.0);
        $this->repo->mailKuyrugaEkle('irsaliye', $cid, 'D1', 'a@b.com', 'UU1', '2026-07-29');
        $this->repo->mailKuyrugaEkle('fatura', $cid, 'F1', 'a@b.com', 'UY1', null);
        $this->repo->mailKuyrukIsle(20, static fn(string $t, string $k): ?string
            => $t === 'fatura' ? "%PDF-1.4\nx" : null);

        $o = $this->repo->mailKuyrukOzet();
        $this->assertSame(1, $o['gonderildi']);
        $this->assertSame(1, $o['bekliyor']);
        $liste = $this->repo->mailKuyrukSon(10);
        $this->assertCount(2, $liste);
        $this->assertSame('BOMİ', $liste[0]['musteri'], 'müşteri adı Türkçe karakteriyle görünmeli');
    }

    // ══ 8) ParasutPdf — doğru uç + %PDF doğrulaması ══════════════════════

    public function testFaturaPdfEArsivVeEFaturaUcunuDogruSecer(): void
    {
        foreach (['e_invoices', 'e_archives'] as $tip) {
            $sorulan = [];
            ParasutPdf::agAta(
                static function (string $path, array $q) use (&$sorulan, $tip): ?array {
                    $sorulan[] = $path;
                    if (str_starts_with($path, 'sales_invoices/')) {
                        return ['data' => ['relationships' => ['active_e_document' => ['data' => ['id' => 'E9', 'type' => $tip]]]]];
                    }
                    return ['data' => ['attributes' => ['url' => 'https://s3.test/imzali']]];
                },
                static fn(string $u): ?string => "%PDF-1.4\nham"
            );
            $this->assertSame("%PDF-1.4\nham", ParasutPdf::faturaPdf('9001'));
            $this->assertSame("$tip/E9/pdf", $sorulan[1], "$tip için doğru uç çağrılmalı");
        }
    }

    public function testResmilesmemisFaturaIcinNullDoner(): void
    {
        ParasutPdf::agAta(
            static fn(string $p, array $q): ?array => ['data' => ['relationships' => []]],
            static fn(string $u): ?string => "%PDF-1.4"
        );
        $this->assertNull(ParasutPdf::faturaPdf('9001'), 'e-belge yoksa PDF uydurulmaz');
    }

    public function testPdfOlmayanIcerikAsalDondurulmez(): void
    {
        ParasutPdf::agAta(
            static fn(string $p, array $q): ?array => ['data' => ['attributes' => ['url' => 'https://s3.test/x']]],
            static fn(string $u): ?string => '<html>Hata sayfası</html>'
        );
        $this->assertNull(ParasutPdf::irsaliyePdf('DOC1'), '%PDF değilse ek olarak gönderilmez');
    }

    public function testIrsaliyePdfDogruUcuCagirir(): void
    {
        $sorulan = '';
        ParasutPdf::agAta(
            static function (string $p, array $q) use (&$sorulan): ?array {
                $sorulan = $p;
                return ['data' => ['attributes' => ['url' => 'https://s3.test/x']]];
            },
            static fn(string $u): ?string => "%PDF-1.4\nirs"
        );
        $this->assertSame("%PDF-1.4\nirs", ParasutPdf::irsaliyePdf('DOC1'));
        $this->assertSame('shipment_documents/DOC1/pdf', $sorulan);
        $this->assertNull(ParasutPdf::irsaliyePdf('  '), 'boş id ile ağa çıkılmaz');
    }

    // ══ 9) ŞALTER — uysa_mail (varsayılan) vs parasut (rollback) ═════════

    public function testVarsayilanYontemUysaMail(): void
    {
        $this->assertSame('uysa_mail', $this->repo->paylasimYontemi());
        $this->repo->ayarSet('paylasim_yontemi', 'parasut');
        $this->assertSame('parasut', $this->repo->paylasimYontemi());
        $this->repo->ayarSet('paylasim_yontemi', 'saçmalık');
        $this->assertSame('uysa_mail', $this->repo->paylasimYontemi(), 'bilinmeyen değer güvenli varsayılana düşer');
    }

    /** Kod deploy edildi ama migrate_049 uygulanmadı → ESKİ akış sürer, mail sessizce kesilmez. */
    public function testMigrasyonUygulanmadiysaEskiAkisaDusulur(): void
    {
        $this->pdo->exec("DELETE FROM ayar WHERE anahtar = 'paylasim_yontemi'");
        $this->pdo->exec('DROP TABLE mail_kuyruk');
        $this->assertSame('parasut', $this->repo->paylasimYontemi());

        $cid = $this->irsaliyeMusterisi('BOMİ', 'musteri@firma.com');
        $r = $this->irsaliyeKes($cid);
        $this->assertTrue($r['ok'], $r['mesaj']);
        $this->assertSame('gonderildi', $r['mail']);
        $this->assertCount(1, $this->sharingCagrilari(), 'ayar satırı yokken Paraşüt paylaşımı devrede kalır');
    }

    /** İki cron çakışırsa aynı belge İKİ KEZ maillenmemeli (atomik claim). */
    public function testEszamanliIsleyiciAyniSatiriIkiKezGondermez(): void
    {
        $cid = seed_customer($this->pdo, 'BOMİ', 245.0);
        $this->repo->mailKuyrugaEkle('irsaliye', $cid, 'DOC1', 'a@b.com', 'UU1', '2026-07-29');

        // İlk işleyici PDF'i indirirken ikinci işleyici devreye girer (gerçek cron çakışması).
        $ikinci = null;
        $pdf = function (string $t, string $k) use (&$ikinci): ?string {
            if ($ikinci === null) {
                $ikinci = $this->repo->mailKuyrukIsle(20, static fn(string $a, string $b): ?string => "%PDF-1.4\nx");
            }
            return "%PDF-1.4\nx";
        };
        $ilk = $this->repo->mailKuyrukIsle(20, $pdf);

        $this->assertSame(0, $ikinci['islenen'], 'satır kapılmış olmalı — ikinci işleyici dokunamaz');
        $this->assertSame(1, $ilk['gonderildi']);
        $this->assertCount(1, $this->zarflar, 'müşteriye TEK mail gider');
    }

    public function testUysaMailSalterindeSharingsCagrilmazKuyrugaYazilir(): void
    {
        $cid = $this->irsaliyeMusterisi('BOMİ', 'musteri@firma.com');
        $r = $this->irsaliyeKes($cid);

        $this->assertTrue($r['ok'], $r['mesaj']);
        $this->assertSame('sirada', $r['mail']);
        $this->assertSame([], $this->sharingCagrilari(), 'uysa_mail açıkken Paraşüt paylaşımı ÇAĞRILMAZ (ZIP gitmez)');
        $s = $this->repo->mailKuyrukSatiri('irsaliye', '9911');
        $this->assertNotNull($s);
        $this->assertSame('musteri@firma.com', $s['alici']);
        $this->assertSame('2026-07-29', $s['gun']);
        $this->assertSame('bekliyor', $s['durum']);
        $this->assertStringContainsString('SIRADA', $r['mesaj']);
    }

    public function testParasutSalterindeEskiAkisAynenKorunur(): void
    {
        $this->repo->ayarSet('paylasim_yontemi', 'parasut');
        $cid = $this->irsaliyeMusterisi('BOMİ', 'musteri@firma.com');
        $r = $this->irsaliyeKes($cid);

        $this->assertTrue($r['ok'], $r['mesaj']);
        $this->assertSame('gonderildi', $r['mail']);
        $this->assertCount(1, $this->sharingCagrilari(), 'rollback yolu: eski sharings akışı çalışır');
        $this->assertNull($this->repo->mailKuyrukSatiri('irsaliye', '9911'), 'eski akışta kuyruğa yazılmaz');
    }

    public function testMailAdresiYokkenIkiYontemdeDeCagriYapilmaz(): void
    {
        $cid = $this->irsaliyeMusterisi('BOMİ', null);
        $r = $this->irsaliyeKes($cid);
        $this->assertSame('yok', $r['mail']);
        $this->assertSame([], $this->sharingCagrilari());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mail_kuyruk')->fetchColumn());
    }

    public function testFaturaKesimiUysaMailKuyruguna(): void
    {
        $cid = seed_customer($this->pdo, 'BOMİ', 245.0);
        $this->pdo->prepare('UPDATE customers SET parasut_id = ?, edespatch_alias = ?, fatura_mail = ? WHERE id = ?')
            ->execute(['1060083895', 'urn:mail:pk@bomi.com', 'muhasebe@firma.com', $cid]);
        $this->repo->irsaliyeLogKaydet($cid, '2026-07-14', [
            'durum' => 'kesildi', 'despatch_no' => 'UU1', 'parasut_doc_id' => 'D1',
            'kalemler' => [['ogun' => 'ogle', 'urun_id' => '1063984872', 'miktar' => 75]],
            'toplam_kisi' => 75, 'gonderim' => 'gonderildi',
        ]);

        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            ['net' => 'ok', 'status' => 201, 'data' => ['data' => ['id' => '7001',
                'attributes' => ['invoice_no' => 'UY02026000000132'],
                'relationships' => ['details' => ['data' => [['id' => '501', 'type' => 'sales_invoice_details']]]]]]],
            ['net' => 'ok', 'status' => 201, 'data' => ['data' => ['id' => 'JOB1']]],
            ['net' => 'ok', 'status' => 200, 'data' => ['data' => ['id' => 'JOB1', 'attributes' => ['status' => 'done']]]],
            ['net' => 'ok', 'status' => 200, 'data' => ['data' => ['id' => '7001',
                'relationships' => ['active_e_document' => ['data' => ['id' => 'EDOC1', 'type' => 'e_invoices']]]]]],
        ]));
        $r = $yaz->createSalesInvoice($cid, '2026-07-08', '2026-07-14', ['onay' => 'imza', 'actor' => 'uysal']);

        $this->assertTrue($r['ok'], $r['mesaj']);
        $this->assertSame('sirada', $r['mail']);
        $this->assertSame([], $this->sharingCagrilari());
        $s = $this->repo->mailKuyrukSatiri('fatura', '7001');
        $this->assertNotNull($s);
        $this->assertSame('UY02026000000132', $s['belge_no']);
        $this->assertSame('muhasebe@firma.com', $s['alici']);
    }

    public function testKesimAnindaPdfHazirsaAnindaderhalGonderilir(): void
    {
        $cid = $this->irsaliyeMusterisi('BOMİ', 'musteri@firma.com');
        // Kesim anındaki tek deneme: PDF hazır → 'gonderildi', kuyrukta beklemez.
        $r = $this->irsaliyeKes($cid, fn(int $id): array => $this->repo->mailKuyrukIsle(
            1,
            static fn(string $t, string $k): ?string => "%PDF-1.4\nhazir",
            null,
            $id
        ));
        $this->assertSame('gonderildi', $r['mail']);
        $this->assertCount(1, $this->zarflar);
        $this->assertSame('gonderildi', $this->repo->mailKuyrukSatiri('irsaliye', '9911')['durum']);
    }

    // ── yardımcılar ─────────────────────────────────────────────────────

    private function http(array $yanitlar): callable
    {
        $i = 0;
        return function (string $method, string $path, ?array $body) use (&$i, $yanitlar): array {
            $this->cagrilar[] = ['method' => $method, 'path' => $path, 'body' => $body];
            $y = $yanitlar[$i] ?? ['net' => 'ok', 'status' => 200, 'data' => []];
            $i++;
            return $y;
        };
    }

    /** @return array<int,array<string,mixed>> */
    private function sharingCagrilari(): array
    {
        return array_values(array_filter($this->cagrilar, static fn(array $c) => str_contains($c['path'], '/sharings')));
    }

    private function irsaliyeMusterisi(string $ad, ?string $mail): int
    {
        $cid = seed_customer($this->pdo, $ad, 245.0);
        $this->pdo->prepare('UPDATE customers SET parasut_id = ?, irsaliye_aktif = 1, irsaliye_mail = ?, edespatch_alias = ? WHERE id = ?')
            ->execute(['1060083895', $mail, 'urn:mail:pk@bomi.com', $cid]);
        $this->repo->saveDayMeals($cid, '2026-07-29', ['ogle' => 75, 'aksam' => 0, 'kumanya' => 0], 245.0);
        return $cid;
    }

    /** Resmileşmeye kadar giden tam irsaliye kesim akışı (mail adımına ULAŞIR). */
    private function irsaliyeKes(int $cid, ?callable $mailIsle = null): array
    {
        $yaz = new ParasutYaz($this->repo, 'imza', $this->http([
            // 1) contact
            ['net' => 'ok', 'status' => 200, 'data' => ['data' => ['id' => '1060083895',
                'attributes' => ['name' => 'BOMİ', 'address' => 'Şerifali Mah.', 'city' => 'İstanbul', 'district' => 'Ümraniye']]]],
            // 2) mükerrer sorgusu (boş)
            ['net' => 'ok', 'status' => 200, 'data' => ['data' => []]],
            // 3) POST /shipment_documents
            ['net' => 'ok', 'status' => 201, 'data' => ['data' => ['id' => '9911', 'attributes' => [
                'issue_date' => '2026-07-29', 'despatch_no' => 'UU02026000000584',
                'plate_number' => '41BEM936',
                'drivers_info' => [['tckn' => '23354463864', 'full_name' => 'UFUK BALTACI']]]]]],
            // 4) PUT issue_datetime
            ['net' => 'ok', 'status' => 200, 'data' => []],
            // 5) POST legalize → job
            ['net' => 'ok', 'status' => 202, 'data' => ['data' => ['id' => 'JOB1']]],
            // 6) GET job → done
            ['net' => 'ok', 'status' => 200, 'data' => ['data' => ['id' => 'JOB1', 'attributes' => ['status' => 'done']]]],
            // 7) GET belge → legalized
            ['net' => 'ok', 'status' => 200, 'data' => ['data' => ['id' => '9911', 'attributes' => [
                'legalized_at' => '2026-07-29T10:00:00Z', 'despatch_no' => 'UU02026000000584']]]],
            // 8) POST /sharings (yalnız 'parasut' şalterinde çağrılır)
            ['net' => 'ok', 'status' => 201, 'data' => ['data' => ['id' => 'SH1']]],
        ]), $mailIsle);

        return $yaz->createShipmentDocument($cid, '2026-07-29', ['ogle' => 75], ['onay' => 'imza', 'actor' => 'uysal']);
    }
}
