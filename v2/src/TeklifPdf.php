<?php
declare(strict_types=1);

namespace Uysa;

// tFPDF (UTF-8) + TTFontFile — global sınıflar; TeklifPdfDoc alt sınıfı için dosya yüklenirken
// tanımlı olmalı (extends \tFPDF link zamanında çözülür).
require_once __DIR__ . '/lib/tfpdf/font/unifont/ttfonts.php';
require_once __DIR__ . '/lib/tfpdf/tfpdf.php';

/**
 * Teklif → Ömer'in matbu "Yemekhaneci Gıda" yemek hizmeti teklif şablonu (2 sayfa, dikey A4).
 *
 * fable-007: Referans "TEV - Yemekhaneci Yemek Hizmeti Teklifi.pdf" tasarımıyla BİREBİR.
 * Motor tFPDF 1.33 (UTF-8, fpdf.org script92 / Setasign) + gömülü DejaVu font ailesi:
 *   DejaVuSansCondensed (gövde/tablo), DejaVuSerif (bant + başlık). Türkçe ş/ğ/ı/İ TAM korunur;
 *   eski cp1252 conv()/transliterasyon KALDIRILDI — UTF-8 doğrudan yazılır.
 *
 * Tasarım öğeleri: ortalanmış logo (üst + alt, her sayfa), altın harf-aralıklı slogan + çizgi,
 * lacivert bant başlıklar (beyaz, letterspaced), lacivert başlıklı + açık mavi zebra tablolar,
 * turuncu madde imli kalın etiketli bullet'lar, italik gri dipnot, alt lacivert adres bandı.
 *
 * Değişkenler (forma bağlı): tarih/teklif no, firma, giriş metni, birim fiyat cetveli satırları,
 * günlük menü yapısı satırları (yalnız fiyatlandırılan öğünler). Geri kalan metinler MATBU (sabit).
 */
final class TeklifPdf
{
    private const NAVY   = [31, 56, 100];    // #1F3864 lacivert bant
    private const GOLD   = [178, 142, 60];   // #B28E3C altın/hardal
    private const ORANGE = [224, 120, 31];   // #E0781F turuncu madde imi
    private const INK    = [45, 45, 45];
    private const GRAY   = [112, 118, 128];
    private const ZEBRA  = [234, 239, 247];  // açık mavi zebra
    private const BORDER = [201, 210, 224];
    private const MARGIN = 16.0;
    private const CW     = 178.0;            // içerik genişliği = 210 - 2*MARGIN

    private const DEFAULT_GIRIS =
        'Kurumunuzun personeline, yerinde üretim ile günlük yemek hizmeti vermek isteriz. '
        . 'İhtiyaçlarınız doğrultusunda hazırladığımız birim fiyat teklifimizi bilgi ve onayınıza sunarız.';

    /** Birim fiyat cetveli / menü yapısı satır anahtarları (sıra önemli). */
    private const FIYAT_LABELS = [
        'kahvalti' => 'Kahvaltı',
        'ogle'     => 'Öğle Yemeği',
        'aksam'    => 'Akşam Yemeği',
        'ara'      => 'Ara Öğün (Beslenme)',
    ];

    /** Günlük menü yapısı — [Öğün etiketi, kapsam metni] (referanstan HARFİYEN). */
    private const MENU_YAPISI = [
        'kahvalti' => ['Kahvaltı',
            'Açık büfe düzeninde; peynir grubu, bal-tereyağı-reçel-tahin/pekmez, sıcak ara öğün, '
            . 'zeytin, yumurta, mevsim sebze (domates-salatalık-yeşillik), iki çeşit gevrek, '
            . 'içecek (çay-süt-meyve suyu) ve ekmek.'],
        'ogle' => ['Öğle Yemeği',
            'Dört çeşit: çorba + ana yemek + yardımcı yemek (pilav/makarna/börek/zeytinyağlı) + '
            . 'tatlı veya meyve; yanında en az dört çeşit salatabar (biri zeytinyağlı) ve yoğurt. '
            . 'Porsiyon gramajları şartnameye uygundur.'],
        'aksam' => ['Akşam Yemeği',
            'Öğle ile aynı standartta dört çeşit yemek + en az dört çeşit salatabar + yoğurt ve '
            . 'tatlı/meyve. Kırmızı et yalnızca dana/koyun karkas, ayrıca beyaz et kullanılır; '
            . 'işlenmiş/hazır et ürünü verilmez.'],
        'ara' => ['Ara Öğün',
            'Meyve, sandviç, mutfakta hazırlanan poğaça/kek, süt/ayran veya kuruyemiş türü '
            . 'atıştırmalıklar. Gazlı içecek, ambalajlı çikolata/gofret/cips gibi ultra işlenmiş '
            . 'ürünler verilmez.'],
    ];

    /** Hizmet kapsamı bullet'ları — [kalın etiket, metin] (referanstan HARFİYEN). */
    private const KAPSAM = [
        ['Yerinde Üretim',
            'Hizmet, kurum mutfağında tarafımızca üretilip servis edilir; 3 ana + 3 ara öğün, '
            . '07:00–22:00 arası kesintisiz sürdürülür.'],
        ['Menü',
            'Diyetisyen onaylı aylık menü; her öğünde 4 çeşit yemek + en az 4 çeşit salatabar '
            . '(biri zeytinyağlı), yoğurt ve meyve/tatlı dahil; porsiyon gramajları şartnameye uygundur.'],
        ['Ürün Kalitesi',
            'A kalite markalı ürünler (Sütaş, Pınar, Ülker, Eti vb.); glikoz şurubu, yapay '
            . 'tatlandırıcı/renklendirici ve katkı kullanılmaz; kırmızı et yalnızca dana/koyun '
            . 'karkastır (işlenmiş et yok).'],
        ['Belge ve Güvence',
            'ISO 9001/22000/14001/45001 ve gerekli izin belgeleri, %6 teminat mektubu ve 100.000 € '
            . '3. şahıs mali mesuliyet sigortası sözleşme aşamasında sağlanır.'],
        ['Hijyen',
            'HACCP, 72 saat şahit numune, aylık periyodik pest control, soğuk zincir ve sıcaklık '
            . 'kayıtları, portör ve hijyen eğitimli personel.'],
        ['Personel ve Giderler',
            'Yeterli ve eğitimli kadro ile tüm özlük hakları (5510/4857 sayılı kanunlar), üniforma '
            . 've yemekhanede kullanılan elektrik-su-doğalgaz tarafımıza aittir.'],
    ];

    private const EK_HIZMETLER =
        'Özel gün, tören, veli toplantısı ve kutlamalara özel menü ve ikram organizasyonları '
        . 'sağlanır; misafir ikramları ara öğün veya önceden mutabık birim fiyat üzerinden karşılanır.';

    private const ODEME_1 =
        'Her ayın sonunda gerçekleşen öğün adetleri üzerinden mutabakatla faturalandırılır; ödeme '
        . '30 gün vade ile havale/EFT olarak tahsil edilir. Bu teklif düzenlenme tarihinden itibaren '
        . '30 gün geçerlidir.';

    private const ODEME_2 =
        'Kurumunuza sunacağımız sağlıklı, güvenli ve kaliteli hizmetle çözüm ortağınız olmaktan '
        . 'memnuniyet duyarız. Onayınıza sunar, iyi çalışmalar dileriz.';

    private const ADRES =
        'Emek Mah. Veysel Karani Cad. No:129/A Çayırova/Kocaeli · Vergi No: 9471093011 · '
        . 'www.yemekhaneci.com.tr · info@yemekhaneci.com.tr';

    /**
     * @param array<string,mixed> $t teklif satırı (Repo::teklifById / listTeklif çıktısı)
     * @return string PDF ikili içeriği
     */
    public static function render(array $t): string
    {
        $pdf = new TeklifPdfDoc('P', 'mm', 'A4');
        $logo = __DIR__ . '/lib/assets/yemekhaneci-logo.png';
        $pdf->logoPath = is_file($logo) ? $logo : '';
        $pdf->SetMargins(self::MARGIN, 12.0, self::MARGIN);
        $pdf->SetAutoPageBreak(true, 22.0);
        $pdf->SetTitle('Yemek Hizmeti Fiyat Teklifi');
        self::fonts($pdf);

        // ── Değişkenler ────────────────────────────────────────
        $id = (int) ($t['id'] ?? 0);
        $created = (string) ($t['created_at'] ?? '');
        $ts = $created !== '' ? strtotime($created) : false;
        if ($ts === false) {
            $ts = time();
        }
        $tarih = date('d/m/Y', $ts);
        $yil = date('Y', $ts);
        $firma = trim((string) ($t['firma'] ?? '')) ?: '-';
        $rows = self::priceRows($t);
        $giris = trim((string) ($t['giris_metni'] ?? ''));
        if ($giris === '') {
            $giris = self::DEFAULT_GIRIS;
        }

        // ── SAYFA 1 ────────────────────────────────────────────
        $pdf->AddPage();
        self::slogan($pdf);
        self::mainBand($pdf, 'YEMEK HİZMETİ FİYAT TEKLİFİ');
        self::dateLine($pdf, $tarih, $yil, $id);
        self::companyBlock($pdf, $firma, $giris);

        self::sectionBand($pdf, 'BİRİM FİYAT TEKLİF CETVELİ');
        self::priceTable($pdf, $rows);
        self::footnote(
            $pdf,
            'Faturalandırma, kurumda fiilen bulunan kişi sayısı kadar yapılır; kişi/öğün garantisi '
            . 'aranmaz. Fiyatlara KDV dahil değildir.'
        );

        self::sectionBand($pdf, 'HİZMET KAPSAMI VE ŞARTNAME UYGUNLUĞU');
        self::bullets($pdf, self::KAPSAM);

        // ── SAYFA 2 ────────────────────────────────────────────
        $pdf->AddPage();
        self::sectionBand($pdf, 'GÜNLÜK MENÜ YAPISI');
        self::menuTable($pdf, $rows);

        self::sectionBand($pdf, 'EK HİZMETLER');
        self::para($pdf, self::EK_HIZMETLER);

        self::sectionBand($pdf, 'FİYAT REVİZYONU (ESKALASYON)');
        self::eskalasyon($pdf);

        self::sectionBand($pdf, 'ÖDEME VE GEÇERLİLİK');
        self::para($pdf, self::ODEME_1);
        self::para($pdf, self::ODEME_2);
        self::closing($pdf);

        self::addressBand($pdf);

        return (string) $pdf->Output('S');
    }

    /** DejaVu font ailesini (UTF-8) kaydet. */
    private static function fonts(TeklifPdfDoc $pdf): void
    {
        $pdf->AddFont('dvs', '', 'DejaVuSansCondensed.ttf', true);
        $pdf->AddFont('dvs', 'B', 'DejaVuSansCondensed-Bold.ttf', true);
        $pdf->AddFont('dvs', 'I', 'DejaVuSansCondensed-Oblique.ttf', true);
        $pdf->AddFont('dvf', '', 'DejaVuSerif.ttf', true);
        $pdf->AddFont('dvf', 'B', 'DejaVuSerif-Bold.ttf', true);
    }

    // ── Değişken hesap ─────────────────────────────────────────

    /**
     * Fiyat cetveli satırları: fiyat_json (kahvalti/ogle/aksam/ara) dolu olanlar;
     * yoksa birim_fiyat tek satır "Öğle Yemeği".
     * @return list<array{0:string,1:string,2:float}> [anahtar, etiket, tl]
     */
    private static function priceRows(array $t): array
    {
        $fj = self::decode($t['fiyat_json'] ?? null);
        $rows = [];
        foreach (self::FIYAT_LABELS as $k => $lbl) {
            if (!array_key_exists($k, $fj)) {
                continue;
            }
            $raw = $fj[$k];
            if ($raw === '' || $raw === null) {
                continue;
            }
            $v = (float) $raw;
            if ($v > 0) {
                $rows[] = [$k, $lbl, $v];
            }
        }
        if (!$rows) {
            $bf = $t['birim_fiyat'] ?? null;
            if ($bf !== '' && $bf !== null) {
                $rows[] = ['ogle', 'Öğle Yemeği', (float) $bf];
            }
        }
        return $rows;
    }

    // ── Sayfa 1 blokları ───────────────────────────────────────

    private static function slogan(TeklifPdfDoc $pdf): void
    {
        $y = $pdf->GetY();
        self::lettered($pdf, 'MÜKEMMEL LEZZET · PROFESYONEL HİZMET', self::MARGIN, $y, self::CW, 5.0, 1.1, self::GOLD, 8.5, 'dvf', 'B', 'C');
        $ly = $y + 6.4;
        $pdf->SetDrawColor(...self::GOLD);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(self::MARGIN, $ly, self::MARGIN + self::CW, $ly);
        $pdf->SetLineWidth(0.2);
        $pdf->SetY($ly + 2.5);
    }

    private static function mainBand(TeklifPdfDoc $pdf, string $title): void
    {
        $y = $pdf->GetY();
        $h = 9.0;
        $pdf->SetFillColor(...self::NAVY);
        $pdf->Rect(self::MARGIN, $y, self::CW, $h, 'F');
        self::lettered($pdf, $title, self::MARGIN, $y, self::CW, $h, 1.1, [255, 255, 255], 13.0, 'dvf', 'B', 'C');
        $pdf->SetY($y + $h + 1.5);
    }

    private static function sectionBand(TeklifPdfDoc $pdf, string $title): void
    {
        $pdf->Ln(1.5);
        $y = $pdf->GetY();
        $h = 7.6;
        $pdf->SetFillColor(...self::NAVY);
        $pdf->Rect(self::MARGIN, $y, self::CW, $h, 'F');
        self::lettered($pdf, $title, self::MARGIN + 4.0, $y, self::CW - 8.0, $h, 0.8, [255, 255, 255], 11.0, 'dvf', 'B', 'L');
        $pdf->SetY($y + $h + 2.5);
    }

    private static function dateLine(TeklifPdfDoc $pdf, string $tarih, string $yil, int $id): void
    {
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('dvs', '', 9.0);
        $pdf->SetTextColor(...self::GRAY);
        $pdf->Cell(self::CW, 6.0, 'Tarih: ' . $tarih . '          Teklif No: ' . $yil . ' / ' . $id, 0, 1, 'C');
        $pdf->Ln(1.5);
    }

    private static function companyBlock(TeklifPdfDoc $pdf, string $firma, string $giris): void
    {
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('dvf', 'B', 12.5);
        $pdf->SetTextColor(...self::NAVY);
        $pdf->MultiCell(self::CW, 6.4, $firma, 0, 'L');
        $pdf->Ln(0.3);
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('dvf', 'B', 10.0);
        $pdf->SetTextColor(...self::INK);
        $pdf->Cell(0, 5.6, 'Sayın Yetkili,', 0, 1, 'L');
        $pdf->Ln(0.6);
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('dvs', '', 9.5);
        $pdf->SetTextColor(...self::INK);
        $pdf->MultiCell(self::CW, 5.4, $giris, 0, 'J');
    }

    /** @param list<array{0:string,1:string,2:float}> $rows */
    private static function priceTable(TeklifPdfDoc $pdf, array $rows): void
    {
        $x = self::MARGIN;
        $c2 = 58.0;
        $c1 = self::CW - $c2;
        $y = $pdf->GetY();

        // başlık satırı
        $pdf->SetFillColor(...self::NAVY);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dvs', 'B', 9.5);
        $pdf->SetXY($x, $y);
        $pdf->Cell($c1, 8.0, 'Hizmet Kalemi', 0, 0, 'C', true);
        $pdf->Cell($c2, 8.0, 'Birim Fiyat (KDV Hariç)', 0, 1, 'C', true);
        $y += 8.0;

        if (!$rows) {
            $rows = [['ogle', 'Öğle Yemeği', 0.0]];
        }
        $i = 0;
        foreach ($rows as $r) {
            $fill = ($i % 2 === 1);
            $pdf->SetFillColor(...($fill ? self::ZEBRA : [255, 255, 255]));
            $pdf->SetXY($x, $y);
            $pdf->SetFont('dvs', '', 9.5);
            $pdf->SetTextColor(...self::INK);
            $pdf->Cell($c1, 7.6, '  ' . $r[1], 0, 0, 'L', true);
            $pdf->SetFont('dvs', 'B', 9.5);
            $pdf->SetTextColor(...self::NAVY);
            $pdf->Cell($c2, 7.6, Helpers::money($r[2]) . ' TL', 0, 1, 'C', true);
            $y += 7.6;
            $i++;
        }

        // dış çerçeve + sütun ayıracı
        $pdf->SetDrawColor(...self::BORDER);
        $pdf->SetLineWidth(0.25);
        $top = $pdf->GetY() - 8.0 - count($rows) * 7.6;
        $pdf->Rect($x, $top, self::CW, 8.0 + count($rows) * 7.6, 'D');
        $pdf->Line($x + $c1, $top, $x + $c1, $y);
        $pdf->SetLineWidth(0.2);
        $pdf->SetY($y + 1.0);
    }

    private static function footnote(TeklifPdfDoc $pdf, string $text): void
    {
        $pdf->Ln(1.5);
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('dvs', 'I', 8.0);
        $pdf->SetTextColor(...self::GRAY);
        $pdf->MultiCell(self::CW, 4.6, $text, 0, 'L');
    }

    /** @param list<array{0:string,1:string}> $items */
    private static function bullets(TeklifPdfDoc $pdf, array $items): void
    {
        foreach ($items as $it) {
            [$label, $text] = $it;
            $pdf->Ln(1.4);
            $y = $pdf->GetY();
            $pdf->SetXY(self::MARGIN, $y);
            $pdf->SetFont('dvs', 'B', 9.0);
            $pdf->SetTextColor(...self::ORANGE);
            $pdf->Cell(4.5, 5.0, '•', 0, 0, 'L');

            $pdf->SetLeftMargin(self::MARGIN + 5.0);
            $pdf->SetX(self::MARGIN + 5.0);
            $pdf->SetFont('dvs', 'B', 9.0);
            $pdf->SetTextColor(...self::NAVY);
            $pdf->Write(5.0, $label . ': ');
            $pdf->SetFont('dvs', '', 9.0);
            $pdf->SetTextColor(...self::INK);
            $pdf->Write(5.0, $text);
            $pdf->Ln(5.0);
            $pdf->SetLeftMargin(self::MARGIN);
        }
    }

    // ── Sayfa 2 blokları ───────────────────────────────────────

    /** Günlük menü yapısı tablosu — yalnız fiyatlandırılan öğünler. */
    private static function menuTable(TeklifPdfDoc $pdf, array $rows): void
    {
        $keys = [];
        foreach ($rows as $r) {
            if (isset(self::MENU_YAPISI[$r[0]]) && !in_array($r[0], $keys, true)) {
                $keys[] = $r[0];
            }
        }
        if (!$keys) {
            $keys = ['ogle'];
        }

        $x = self::MARGIN;
        $c1 = 32.0;
        $c2 = self::CW - $c1;
        $y = $pdf->GetY();
        $top = $y;

        // başlık
        $pdf->SetFillColor(...self::NAVY);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dvs', 'B', 9.5);
        $pdf->SetXY($x, $y);
        $pdf->Cell($c1, 8.0, 'Öğün', 0, 0, 'C', true);
        $pdf->Cell($c2, 8.0, 'Kapsam (Şartname Menü Standardı)', 0, 1, 'C', true);
        $y += 8.0;

        $i = 0;
        foreach ($keys as $k) {
            [$lbl, $desc] = self::MENU_YAPISI[$k];
            $pdf->SetFont('dvs', '', 9.0);
            $nl = self::nbLines($pdf, $c2 - 4.0, $desc);
            $rowH = max(13.0, $nl * 4.7 + 4.0);

            $fill = ($i % 2 === 1);
            $pdf->SetFillColor(...($fill ? self::ZEBRA : [255, 255, 255]));
            $pdf->Rect($x, $y, self::CW, $rowH, 'F');

            $pdf->SetFont('dvs', 'B', 9.5);
            $pdf->SetTextColor(...self::NAVY);
            $pdf->SetXY($x + 2.5, $y + ($rowH - 5.0) / 2.0);
            $pdf->Cell($c1 - 3.0, 5.0, $lbl, 0, 0, 'L');

            $pdf->SetFont('dvs', '', 9.0);
            $pdf->SetTextColor(...self::INK);
            $pdf->SetXY($x + $c1 + 2.0, $y + 2.0);
            $pdf->MultiCell($c2 - 4.0, 4.7, $desc, 0, 'L');

            // sütun ayıracı + üst çizgi
            $pdf->SetDrawColor(...self::BORDER);
            $pdf->SetLineWidth(0.2);
            $pdf->Line($x, $y, $x + self::CW, $y);
            $pdf->Line($x + $c1, $y, $x + $c1, $y + $rowH);
            $y += $rowH;
            $i++;
        }

        // dış çerçeve
        $pdf->SetDrawColor(...self::BORDER);
        $pdf->SetLineWidth(0.25);
        $pdf->Rect($x, $top, self::CW, $y - $top, 'D');
        $pdf->SetLineWidth(0.2);
        $pdf->SetY($y + 1.0);
    }

    private static function para(TeklifPdfDoc $pdf, string $text): void
    {
        $pdf->Ln(0.4);
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('dvs', '', 9.5);
        $pdf->SetTextColor(...self::INK);
        $pdf->MultiCell(self::CW, 5.2, $text, 0, 'J');
    }

    private static function eskalasyon(TeklifPdfDoc $pdf): void
    {
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('dvs', '', 9.5);
        $pdf->SetTextColor(...self::INK);
        $pdf->MultiCell(self::CW, 5.2, 'Birim fiyatlar 3 ayda bir, önceki 3 aylık döneme ait TÜİK verileriyle revize edilir:', 0, 'L');
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('dvs', 'B', 9.5);
        $pdf->SetTextColor(...self::NAVY);
        $pdf->MultiCell(self::CW, 5.4, 'Artış = (0,50 × Gıda) + (0,20 × Genel) + (0,30 × Asgari Ücret Artışı).', 0, 'L');
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('dvs', '', 9.5);
        $pdf->SetTextColor(...self::INK);
        $pdf->MultiCell(self::CW, 5.2, 'Gıda = TÜFE "Gıda" ile Tarım-ÜFE/Yİ-ÜFE ortalaması;', 0, 'L');
        $pdf->SetX(self::MARGIN);
        $pdf->MultiCell(self::CW, 5.2, 'Genel = TÜFE ile Yİ-ÜFE ortalaması.', 0, 'L');
        $pdf->SetX(self::MARGIN);
        $pdf->MultiCell(self::CW, 5.2, 'Olağanüstü maliyet değişimlerinde (ek asgari ücret zammı, %15\'i aşan kur/gıda şoku, KDV değişikliği) ara revizyon görüşülür.', 0, 'L');
    }

    private static function closing(TeklifPdfDoc $pdf): void
    {
        $pdf->Ln(2.0);
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('dvs', '', 9.5);
        $pdf->SetTextColor(...self::INK);
        $pdf->Cell(0, 5.6, 'Saygılarımızla,', 0, 1, 'L');
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('dvs', 'B', 9.5);
        $pdf->SetTextColor(...self::NAVY);
        $pdf->Write(5.6, 'Ömer Faruk UYSAL');
        $pdf->SetFont('dvs', '', 9.5);
        $pdf->SetTextColor(...self::INK);
        $pdf->Write(5.6, '  ·  Yemekhaneci Gıda Organizasyon Danışmanlık ve İnşaat Ticaret Ltd. Şti.');
        $pdf->Ln(6.0);
    }

    private static function addressBand(TeklifPdfDoc $pdf): void
    {
        // Sayfanın en altına sabitlenir; Cell y+h eşiği aşacağı için auto-break'i kapat (son eleman).
        $pdf->SetAutoPageBreak(false);
        $y = $pdf->GetPageHeight() - 26.0;
        $h = 5.6;
        $pdf->SetFillColor(...self::NAVY);
        $pdf->Rect(self::MARGIN, $y, self::CW, $h, 'F');
        $pdf->SetXY(self::MARGIN, $y);
        $pdf->SetFont('dvs', '', 7.2);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(self::CW, $h, self::ADRES, 0, 0, 'C');
    }

    // ── Ortak yardımcılar ──────────────────────────────────────

    /**
     * Harf-aralıklı (letterspaced) tek satır metni verilen [x0, x0+cellW] bölgesinde çizer.
     * $align: 'C' ortalar, 'L' sola (x0'dan) yaslar. Her karakter kendi hücresinde → hassas tracking.
     */
    private static function lettered(
        TeklifPdfDoc $pdf,
        string $text,
        float $x0,
        float $y,
        float $cellW,
        float $cellH,
        float $tracking,
        array $rgb,
        float $size,
        string $font,
        string $style,
        string $align
    ): void {
        $pdf->SetFont($font, $style, $size);
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $widths = [];
        $total = 0.0;
        foreach ($chars as $c) {
            $w = $pdf->GetStringWidth($c);
            $widths[] = $w;
            $total += $w + $tracking;
        }
        $total -= $tracking;
        $x = $align === 'C' ? $x0 + ($cellW - $total) / 2.0 : $x0;
        $pdf->SetTextColor(...$rgb);
        foreach ($chars as $idx => $c) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($widths[$idx], $cellH, $c, 0, 0, 'L');
            $x += $widths[$idx] + $tracking;
        }
    }

    /** MultiCell'in satır sayısını (mb-güvenli, greedy word-wrap) kestir. Font ÖNCEDEN ayarlı olmalı. */
    private static function nbLines(TeklifPdfDoc $pdf, float $w, string $txt): int
    {
        $lines = 0;
        foreach (explode("\n", $txt) as $para) {
            $words = preg_split('/ +/', trim($para)) ?: [''];
            $cur = '';
            $count = 1;
            foreach ($words as $wd) {
                $try = $cur === '' ? $wd : $cur . ' ' . $wd;
                if ($pdf->GetStringWidth($try) > $w && $cur !== '') {
                    $count++;
                    $cur = $wd;
                } else {
                    $cur = $try;
                }
            }
            $lines += $count;
        }
        return max(1, $lines);
    }

    /** @return array<string,mixed> */
    private static function decode(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $s = trim((string) $json);
        if ($s === '') {
            return [];
        }
        $d = json_decode($s, true);
        return is_array($d) ? $d : [];
    }
}

/**
 * tFPDF alt sınıfı: her sayfaya ortalanmış üst + alt logo (Header/Footer otomatik çağrılır).
 */
final class TeklifPdfDoc extends \tFPDF
{
    public string $logoPath = '';

    public function Header(): void
    {
        if ($this->logoPath === '') {
            $this->SetY(22.0);
            return;
        }
        $w = 52.0; // logo 720x134 → yükseklik ≈ 9.7mm
        $x = ($this->GetPageWidth() - $w) / 2.0;
        $this->Image($this->logoPath, $x, 9.0, $w);
        $this->SetY(22.5);
    }

    public function Footer(): void
    {
        if ($this->logoPath === '') {
            return;
        }
        $w = 34.0;
        $x = ($this->GetPageWidth() - $w) / 2.0;
        $this->Image($this->logoPath, $x, $this->GetPageHeight() - 15.0, $w);
    }
}
