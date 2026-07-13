<?php
declare(strict_types=1);

namespace Uysa;

/**
 * Teklif → "Yemekhaneci formatinda" markali PDF (fable-005). Bundled FPDF (src/lib/fpdf.php,
 * composer YOK) ile üretir; dikey A4, tek sayfa hedef. MenuPdf ile AYNI cp1252 conv() deseni:
 * cp1252'de OLMAYAN Türkçe harfler (ş/Ş/ğ/Ğ/ı/İ) ASCII'ye çevrilir (ç/ö/ü/Ç/Ö/Ü korunur).
 *
 * Marka kimliği: koyu lacivert bant + turuncu "yemekhaneci" wordmark, alt ibare
 * "Bir UYSA Yemek Hizmetleri kurulusudur".
 *
 * Aylık hesap (yemekhaneci PricingService ile birebir):
 *   aylik_ogun   = kisi × gunluk_ogun × (22 + (cumartesi ? 4 : 0))
 *   aylik_toplam = aylik_ogun × birim_fiyat
 */
final class TeklifPdf
{
    private const NAVY = [11, 18, 32];      // #0B1220
    private const ORANGE = [242, 107, 33];  // #F26B21
    private const INK = [30, 30, 30];
    private const MARGIN = 15.0;

    private const SEGMENT_LABELS = ['ekonomik' => 'Ekonomik', 'genel' => 'Genel', 'premium' => 'Premium'];
    // fable-006 (Ömer): kefir kalktı; yogurt=Yoğurt, donusumlu=Yoğurt/Ayran. 'ikisi' eski kayıt uyumluluğu.
    private const ICECEK_LABELS = [
        'ayran'      => 'ayran',
        'yogurt'     => 'yogurt',
        'donusumlu'  => 'yogurt / ayran donusumlu',
        'ikisi'      => 'yogurt + ayran',
    ];
    // Site (yemekhaneci.com.tr) güven şeridi + teklif-sonuç "UYSA güvencesiyle" blokları
    private const TRUST_STRIP = 'ISO 22000 / HACCP   ·   Diyetisyen onayli menu   ·   Sahit numune saklama   ·   Sicak zincir lojistik   ·   Seffaf kisi basi fiyat';
    private const GUARANTEES = [
        'ISO 22000 / HACCP gida guvenligi',
        'Diyetisyen onayli menu planlamasi',
        'Yerinde uretim; taze, sicak ve hijyenik servis',
        'Gunluk sahit numune saklama (72 saat)',
        'Seffaf, kisi basi net fiyat',
        'Kesintisiz hizmet - 7/24 vardiya destegi',
    ];

    /**
     * @param array<string,mixed> $t teklif satırı (Repo::teklifById / listTeklif çıktısı)
     * @return string PDF ikili içeriği
     */
    public static function render(array $t): string
    {
        require_once __DIR__ . '/lib/fpdf.php';
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $id = (int) ($t['id'] ?? 0);
        $pdf->SetTitle(self::conv('Teklif T-' . $id));
        $pdf->AddPage();

        self::band($pdf, $id, (string) ($t['created_at'] ?? ''));

        // ── Güven şeridi (sitedeki üst şerit) ──────────────────
        $pw = $pdf->GetPageWidth();
        $pdf->SetFillColor(244, 246, 249);
        $pdf->Rect(0, 34, $pw, 8, 'F');
        $pdf->SetXY(0, 35.4);
        $pdf->SetFont('Helvetica', 'B', 7.3);
        $pdf->SetTextColor(...self::NAVY);
        $pdf->Cell($pw, 5, self::conv(self::TRUST_STRIP), 0, 0, 'C');

        // ── Firma bloğu ────────────────────────────────────────
        $pdf->SetY(50);
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->SetTextColor(110, 116, 124);
        $pdf->Cell(0, 5, self::conv('Sayin,'), 0, 1, 'L');
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('Helvetica', 'B', 15);
        $pdf->SetTextColor(...self::NAVY);
        $pdf->MultiCell(0, 8, self::conv((string) ($t['firma'] ?? '-')), 0, 'L');
        $pdf->SetTextColor(...self::INK);
        self::kv($pdf, 'Yetkili', (string) ($t['yetkili'] ?? ''));
        self::kv($pdf, 'Telefon', (string) ($t['telefon'] ?? ''));
        self::kv($pdf, 'E-posta', (string) ($t['email'] ?? ''));
        $loc = trim(implode(' / ', array_filter([
            trim((string) ($t['sehir'] ?? '')),
            trim((string) ($t['ilce'] ?? '')),
        ], static fn ($x) => $x !== '')));
        self::kv($pdf, 'Lokasyon', $loc);

        // ── Hizmet tanımı (yemekhaneci gıda teklifi dili) ──────
        $pdf->Ln(1.5);
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->SetTextColor(...self::INK);
        $pdf->MultiCell(0, 5.4, self::conv(
            'Kurumunuzun toplu yemek ihtiyaci icin hazirladigimiz teklifimizi bilgilerinize sunariz. '
            . 'Yemekler diyetisyen onayli menulerle, ISO 22000 / HACCP gida guvenligi standartlarinda; '
            . 'taze, sicak ve hijyenik olarak hazirlanip servis edilir.'
        ), 0, 'L');

        // ── Hizmet kapsamı ─────────────────────────────────────
        self::heading($pdf, 'Hizmet kapsami');
        $kisi = (int) ($t['kisi'] ?? 0);
        $ogun = max(1, (int) ($t['ogun_sayisi'] ?? 1));
        $cmt = (int) ($t['cumartesi'] ?? 0) === 1;
        self::kv($pdf, 'Kisi sayisi', $kisi > 0 ? $kisi . ' kisi / gun' : '');
        self::kv($pdf, 'Gunluk ogun', $ogun . ' ogun');
        self::kv($pdf, 'Cumartesi', $cmt ? 'dahil (haftada 6 gun)' : 'haric (haftada 5 gun)');
        $seg = strtolower((string) ($t['segment'] ?? ''));
        if (isset(self::SEGMENT_LABELS[$seg])) {
            self::kv($pdf, 'Paket', self::SEGMENT_LABELS[$seg]);
        }

        // ── Menü yapısı ────────────────────────────────────────
        $menuLine = self::menuLine($t['menu_json'] ?? null);
        if ($menuLine !== '') {
            self::heading($pdf, 'Menu yapisi (gunluk)');
            $pdf->SetX(self::MARGIN);
            $pdf->SetFont('Helvetica', '', 9.5);
            $pdf->SetTextColor(...self::INK);
            $pdf->MultiCell(0, 5.6, self::conv($menuLine), 0, 'L');
        }

        // ── Personel / ekipman ─────────────────────────────────
        $persLine = self::persLine($t['personel_json'] ?? null);
        $ekipman = trim((string) ($t['ekipman'] ?? ''));
        if ($persLine !== '' || $ekipman !== '') {
            self::heading($pdf, 'Personel ve ekipman');
            if ($persLine !== '') {
                self::kv($pdf, 'Personel', $persLine);
            }
            if ($ekipman !== '') {
                self::kv($pdf, 'Ekipman', $ekipman);
            }
        }

        // ── Fiyat kutusu ───────────────────────────────────────
        $fiyat = ($t['birim_fiyat'] ?? '') !== '' && $t['birim_fiyat'] !== null ? (float) $t['birim_fiyat'] : null;
        if ($fiyat !== null) {
            self::priceBox($pdf, $fiyat, $kisi, $ogun, $cmt);
        }

        // ── Not ────────────────────────────────────────────────
        $note = trim((string) ($t['note'] ?? ''));
        if ($note !== '') {
            self::heading($pdf, 'Not');
            $pdf->SetX(self::MARGIN);
            $pdf->SetFont('Helvetica', '', 9.5);
            $pdf->SetTextColor(...self::INK);
            $pdf->MultiCell(0, 5.6, self::conv($note), 0, 'L');
        }

        // ── UYSA güvencesiyle (teklif-sonuç sayfasındaki blok, 2 sütun ✓) ──
        self::heading($pdf, 'UYSA guvencesiyle');
        $colW = ($pdf->GetPageWidth() - 2 * self::MARGIN) / 2;
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(...self::INK);
        foreach (array_chunk(self::GUARANTEES, 2) as $pair) {
            $pdf->SetX(self::MARGIN);
            $pdf->SetTextColor(...self::ORANGE);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell(5, 5.6, self::conv('+'), 0, 0, 'L');
            $pdf->SetTextColor(...self::INK);
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->Cell($colW - 5, 5.6, self::conv($pair[0]), 0, 0, 'L');
            if (isset($pair[1])) {
                $pdf->SetTextColor(...self::ORANGE);
                $pdf->SetFont('Helvetica', 'B', 9);
                $pdf->Cell(5, 5.6, self::conv('+'), 0, 0, 'L');
                $pdf->SetTextColor(...self::INK);
                $pdf->SetFont('Helvetica', '', 9);
                $pdf->Cell($colW - 5, 5.6, self::conv($pair[1]), 0, 0, 'L');
            }
            $pdf->Ln(5.6);
        }

        self::footer($pdf);

        return (string) $pdf->Output('S');
    }

    /** Üst marka bandı: lacivert dolgu + turuncu wordmark + sağda TEKLİF/no/tarih. */
    private static function band(\FPDF $pdf, int $id, string $createdAt): void
    {
        $pw = $pdf->GetPageWidth();
        $pdf->SetFillColor(...self::NAVY);
        $pdf->Rect(0, 0, $pw, 34, 'F');

        $pdf->SetTextColor(...self::ORANGE);
        $pdf->SetFont('Helvetica', 'B', 26);
        $pdf->SetXY(self::MARGIN, 8);
        $pdf->Cell(120, 11, self::conv('yemekhaneci'), 0, 0, 'L');

        $pdf->SetTextColor(220, 224, 230);
        $pdf->SetFont('Helvetica', '', 7.5);
        $pdf->SetXY(self::MARGIN, 21);
        $pdf->Cell(120, 5, self::conv('Bir UYSA Yemek Hizmetleri kurulusudur'), 0, 0, 'L');

        $rw = 60.0;
        $rx = $pw - self::MARGIN - $rw;
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Helvetica', 'B', 19);
        $pdf->SetXY($rx, 7);
        $pdf->Cell($rw, 9, self::conv('TEKLIF'), 0, 0, 'R');
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetXY($rx, 17.5);
        $pdf->Cell($rw, 5, self::conv('#T-' . $id), 0, 0, 'R');
        $pdf->SetXY($rx, 22.5);
        $pdf->Cell($rw, 5, self::conv(self::d($createdAt)), 0, 0, 'R');
        $pdf->SetTextColor(0, 0, 0);
    }

    /** Etiket + değer satırı (değer boşsa hiç yazma). */
    private static function kv(\FPDF $pdf, string $label, string $value): void
    {
        if (trim($value) === '') {
            return;
        }
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('Helvetica', 'B', 9.5);
        $pdf->SetTextColor(110, 116, 124);
        $pdf->Cell(34, 6, self::conv($label), 0, 0, 'L');
        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->SetTextColor(...self::INK);
        $pdf->MultiCell(0, 6, self::conv($value), 0, 'L');
    }

    /** Turuncu tab + lacivert başlık. */
    private static function heading(\FPDF $pdf, string $text): void
    {
        $pdf->Ln(2.5);
        $y = $pdf->GetY();
        $pdf->SetFillColor(...self::ORANGE);
        $pdf->Rect(self::MARGIN, $y + 0.8, 2.6, 5.0, 'F');
        $pdf->SetX(self::MARGIN + 5);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(...self::NAVY);
        $pdf->Cell(0, 6.5, self::conv($text), 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(1);
    }

    /** Turuncu çerçeveli fiyat kutusu. */
    private static function priceBox(\FPDF $pdf, float $fiyat, int $kisi, int $ogun, bool $cmt): void
    {
        $pdf->Ln(3);
        $gunAy = 22 + ($cmt ? 4 : 0);
        $hasAylik = $kisi > 0;
        $boxH = $hasAylik ? 26.0 : 16.0;
        $y = $pdf->GetY();
        $pw = $pdf->GetPageWidth();
        $w = $pw - 2 * self::MARGIN;

        $pdf->SetFillColor(255, 247, 242);
        $pdf->SetDrawColor(...self::ORANGE);
        $pdf->SetLineWidth(0.6);
        $pdf->Rect(self::MARGIN, $y, $w, $boxH, 'DF');

        $pdf->SetXY(self::MARGIN + 5, $y + 3.5);
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetTextColor(...self::NAVY);
        $pdf->Cell(0, 6, self::conv('Kisi basi ogun: ' . Helpers::money($fiyat) . ' TL  (KDV haric)'), 0, 2, 'L');

        if ($hasAylik) {
            $aylikOgun = $kisi * $ogun * $gunAy;
            $aylikToplam = $aylikOgun * $fiyat;
            $pdf->SetX(self::MARGIN + 5);
            $pdf->SetFont('Helvetica', 'B', 12);
            $pdf->SetTextColor(...self::ORANGE);
            $pdf->Cell(0, 7, self::conv('Aylik tahmini toplam: ' . Helpers::money($aylikToplam) . ' TL'), 0, 2, 'L');
            $pdf->SetX(self::MARGIN + 5);
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetTextColor(120, 120, 120);
            $pdf->Cell(0, 5, self::conv(
                '(' . $kisi . ' kisi x ' . $ogun . ' ogun x ' . $gunAy . ' gun/ay = ' . $aylikOgun . ' ogun)'
            ), 0, 2, 'L');
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetLineWidth(0.2);
        $pdf->SetY($y + $boxH);
    }

    /** Sayfa altı: geçerlilik + iletişim (tek sayfa garantisi için auto-break kapalı). */
    private static function footer(\FPDF $pdf): void
    {
        $pdf->SetAutoPageBreak(false);
        $pdf->SetY(-22);
        $pdf->SetX(self::MARGIN);
        $pdf->SetDrawColor(...self::ORANGE);
        $pdf->SetLineWidth(0.4);
        $pdf->Line(self::MARGIN, $pdf->GetY(), $pdf->GetPageWidth() - self::MARGIN, $pdf->GetY());
        $pdf->Ln(2);
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(...self::NAVY);
        $pdf->Cell(0, 5, self::conv('Bu teklif 15 gun gecerlidir.'), 0, 1, 'L');
        $pdf->SetX(self::MARGIN);
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->SetTextColor(110, 116, 124);
        $pdf->Cell(0, 5, self::conv('yemekhaneci.com.tr  ·  UYSA Yemek Hizmetleri'), 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }

    /** menu_json → tek okunur satır. Boşsa ''. */
    private static function menuLine(mixed $json): string
    {
        $m = self::decode($json);
        if (!$m) {
            return '';
        }
        $parts = [];
        $corba = (int) ($m['corba'] ?? 0);
        $ana = (int) ($m['ana'] ?? 0);
        $yan = (int) ($m['yan'] ?? 0);
        $salata = (int) ($m['salata'] ?? 0);
        if ($corba > 0) {
            $parts[] = 'Corba: ' . $corba . ' cesit';
        }
        if ($ana > 0) {
            $parts[] = 'Ana yemek: ' . $ana;
        }
        if ($yan > 0) {
            $parts[] = 'Yan: ' . $yan;
        }
        if ($salata > 0) {
            $parts[] = 'Salata bar: ' . $salata . ' cesit';
        }
        if (!empty($m['tatli'])) {
            // fable-006 (Ömer): tatlı üçlü dönüşüm — tatlı / meyve / meşrubat
            $parts[] = 'Tatli / meyve / mesrubat (donusumlu)';
        }
        $icecek = strtolower(trim((string) ($m['icecek'] ?? '')));
        if (isset(self::ICECEK_LABELS[$icecek])) {
            $parts[] = 'Icecek: ' . self::ICECEK_LABELS[$icecek];
        }
        if (!empty($m['ekmek'])) {
            $parts[] = 'Ekmek dahil';
        }
        return implode('  ·  ', $parts);
    }

    /** personel_json → "2 asci · 3 servis · 1 temizlik". Boşsa ''. */
    private static function persLine(mixed $json): string
    {
        $p = self::decode($json);
        if (!$p) {
            return '';
        }
        $parts = [];
        foreach (['asci' => 'asci', 'servis' => 'servis', 'temizlik' => 'temizlik'] as $k => $lbl) {
            $n = (int) ($p[$k] ?? 0);
            if ($n > 0) {
                $parts[] = $n . ' ' . $lbl;
            }
        }
        return implode('  ·  ', $parts);
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

    /** UTF-8 → cp1252 (Türkçe ş/ğ/ı/İ/Ş/Ğ ASCII'ye; ç/ö/ü korunur) — MenuPdf ile birebir. */
    private static function conv(string $s): string
    {
        $map = ['ş' => 's', 'Ş' => 'S', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I'];
        $s = strtr($s, $map);
        $out = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        if ($out === false) {
            $out = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        }
        return $out === false ? $s : $out;
    }

    private static function d(string $d): string
    {
        $ts = strtotime($d);
        return $ts === false || $d === '' ? date('d.m.Y') : date('d.m.Y', $ts);
    }
}
