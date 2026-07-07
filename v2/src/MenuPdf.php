<?php
declare(strict_types=1);

namespace Uysa;

/**
 * Menü → PDF (opus-019). Bundled FPDF (src/lib/fpdf.php, composer YOK) ile gerçek .pdf üretir.
 *
 * FPDF çekirdek fontları cp1252 kodlar; cp1252'de OLMAYAN Türkçe harfler (ş/Ş/ğ/Ğ/ı/İ) ASCII'ye
 * çevrilir (ç/ö/ü/Ç/Ö/Ü cp1252'de VAR, korunur). Böylece kütüphanesiz, offline, geçerli PDF çıkar.
 * (Tam Unicode font gömme = büyük TTF bağımlılığı; bu tur için transliterasyon yeterli.)
 */
final class MenuPdf
{
    private const MEALS = [
        'sabah' => 'Sabah', 'ogle' => 'Ogle', 'aksam' => 'Aksam', 'gece' => 'Gece', 'kumanya' => 'Kumanya',
    ];

    /**
     * @param array<int,array{item_date:string,meal:string,dishes:string}> $items menuItems() çıktısı
     * @return string PDF ikili içeriği
     */
    public static function write(string $title, array $items, string $dateStart = '', string $dateEnd = ''): string
    {
        require_once __DIR__ . '/lib/fpdf.php';
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->SetTitle(self::conv($title));
        $pdf->AddPage();
        $pw = $pdf->GetPageWidth() - 20; // 10mm kenar boşluğu iki yan

        // ── Başlık bloğu ──
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Cell(0, 9, self::conv('UYSA Yemek Hizmetleri'), 0, 1, 'L');
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->Cell(0, 8, self::conv($title !== '' ? $title : 'Menu'), 0, 1, 'L');
        if ($dateStart !== '' && $dateEnd !== '') {
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->SetTextColor(90, 90, 90);
            $pdf->Cell(0, 6, self::conv(self::d($dateStart) . ' - ' . self::d($dateEnd)), 0, 1, 'L');
            $pdf->SetTextColor(0, 0, 0);
        }
        $pdf->Ln(2);
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->SetLineWidth(0.3);
        $y = $pdf->GetY();
        $pdf->Line(10, $y, 10 + $pw, $y);
        $pdf->Ln(3);

        if (!$items) {
            $pdf->SetFont('Helvetica', '', 11);
            $pdf->Cell(0, 8, self::conv('Bu menude gun eklenmemis.'), 0, 1, 'L');
            return (string) $pdf->Output('S');
        }

        // ── Gün × öğün kartları ──
        foreach ($items as $it) {
            $head = self::gunLabel((string) $it['item_date']) . '  ·  '
                . (self::MEALS[$it['meal']] ?? (string) $it['meal']);
            $dishes = trim((string) $it['dishes']);
            // Estimate whether the block fits; if not, force page break before header.
            $pdf->SetFont('Helvetica', 'B', 11);
            if ($pdf->GetY() > $pdf->GetPageHeight() - 30) {
                $pdf->AddPage();
            }
            $pdf->SetFillColor(238, 243, 240);
            $pdf->Cell(0, 7, self::conv($head), 0, 1, 'L', true);
            $pdf->SetFont('Helvetica', '', 11);
            $pdf->MultiCell(0, 6, self::conv($dishes !== '' ? $dishes : '-'), 0, 'L');
            $pdf->Ln(2);
        }

        return (string) $pdf->Output('S');
    }

    /** UTF-8 → cp1252 (Türkçe ş/ğ/ı/İ/Ş/Ğ ASCII'ye; ç/ö/ü korunur). */
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

    /** 'YYYY-MM-DD' → '03.07.2026 Cuma' (bootstrap gun_label_tr'ye bağımlı olmadan). */
    private static function gunLabel(string $d): string
    {
        $ts = strtotime($d);
        if ($ts === false) {
            return $d;
        }
        $gunler = ['Sunday' => 'Pazar', 'Monday' => 'Pazartesi', 'Tuesday' => 'Sali',
            'Wednesday' => 'Carsamba', 'Thursday' => 'Persembe', 'Friday' => 'Cuma', 'Saturday' => 'Cumartesi'];
        return date('d.m.Y', $ts) . ' ' . ($gunler[date('l', $ts)] ?? '');
    }

    private static function d(string $d): string
    {
        $ts = strtotime($d);
        return $ts === false ? $d : date('d.m.Y', $ts);
    }
}
