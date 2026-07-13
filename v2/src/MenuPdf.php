<?php
declare(strict_types=1);

namespace Uysa;

/**
 * Menü → PDF (opus-019, fable-001). Bundled FPDF (src/lib/fpdf.php, composer YOK) ile üretir.
 *
 * fable-001: Ömer'in Excel'iyle aynı TAKVİM düzeni — yatay A4, haftalık grid (Pzt–Cmt sütun,
 * hücrede o günün yemekleri). Önceki sürüm gün gün alt alta listeliyordu; "ben takvim gibi
 * yüklemiştim, sıralı çıkıyor" şikâyetinin çözümü.
 *
 * FPDF çekirdek fontları cp1252 kodlar; cp1252'de OLMAYAN Türkçe harfler (ş/Ş/ğ/Ğ/ı/İ) ASCII'ye
 * çevrilir (ç/ö/ü/Ç/Ö/Ü cp1252'de VAR, korunur). Kütüphanesiz, offline, geçerli PDF.
 */
final class MenuPdf
{
    private const MEALS = [
        'sabah' => 'Sabah', 'ogle' => 'Ogle', 'aksam' => 'Aksam', 'gece' => 'Gece', 'kumanya' => 'Kumanya',
    ];
    private const DAY_NAMES = ['PAZARTESI', 'SALI', 'CARSAMBA', 'PERSEMBE', 'CUMA', 'CUMARTESI', 'PAZAR'];
    private const MARGIN = 8.0;
    private const LINE_H = 4.6;

    /**
     * @param array<int,array{item_date:string,meal:string,dishes:string}> $items menuItems() çıktısı
     * @return string PDF ikili içeriği
     */
    public static function write(string $title, array $items, string $dateStart = '', string $dateEnd = ''): string
    {
        require_once __DIR__ . '/lib/fpdf.php';
        $pdf = new \FPDF('L', 'mm', 'A4'); // fable-001: yatay — takvim grid
        $pdf->SetAutoPageBreak(false);
        $pdf->SetTitle(self::conv($title));
        $pdf->AddPage();
        self::header($pdf, $title, $dateStart, $dateEnd);

        if (!$items) {
            $pdf->SetFont('Helvetica', '', 11);
            $pdf->Cell(0, 8, self::conv('Bu menude gun eklenmemis.'), 0, 1, 'L');
            return (string) $pdf->Output('S');
        }

        // Gün → hücre satırları. Birden fazla öğün varsa öğün etiketi başa gelir.
        $cells = []; // iso => string[]
        foreach ($items as $it) {
            $d = (string) $it['item_date'];
            $lines = XlsxMenu::splitDishes((string) $it['dishes']);
            if (!$lines) {
                continue;
            }
            $mealCnt = count(array_unique(array_column(
                array_filter($items, static fn ($x) => $x['item_date'] === $d && trim((string) $x['dishes']) !== ''),
                'meal'
            )));
            if ($mealCnt > 1) {
                $cells[$d][] = '[' . (self::MEALS[$it['meal']] ?? (string) $it['meal']) . ']';
            }
            foreach ($lines as $l) {
                $cells[$d][] = $l;
            }
        }
        ksort($cells);

        // Haftalara böl (ISO yıl-hafta) — sütun: Pzt..Cmt, Pazar'da veri varsa 7 sütun.
        $weeks = [];
        $hasSunday = false;
        foreach ($cells as $iso => $lines) {
            $weeks[date('o-W', strtotime($iso))][$iso] = $lines;
            if ((int) date('N', strtotime($iso)) === 7) {
                $hasSunday = true;
            }
        }
        $colCount = $hasSunday ? 7 : 6;
        $pageW = $pdf->GetPageWidth() - 2 * self::MARGIN;
        $colW = $pageW / $colCount;
        $pageH = $pdf->GetPageHeight();

        $pdf->SetDrawColor(180, 190, 186);
        $pdf->SetLineWidth(0.25);

        foreach ($weeks as $week) {
            // Sütunlara yerleştir + satırları sar (kolon genişliğine göre)
            $colLines = array_fill(0, $colCount, []);
            $colDates = array_fill(0, $colCount, '');
            foreach ($week as $iso => $lines) {
                $col = (int) date('N', strtotime($iso)) - 1;
                if ($col >= $colCount) {
                    continue;
                }
                $colDates[$col] = date('d.m.Y', strtotime($iso));
                $wrapped = [];
                foreach ($lines as $l) {
                    foreach (self::wrap($pdf, self::conv($l), $colW - 3.0) as $wl) {
                        $wrapped[] = $wl;
                    }
                }
                $colLines[$col] = $wrapped;
            }
            $maxLines = max(1, ...array_map('count', $colLines));
            $headH = 9.0;
            $bodyH = $maxLines * self::LINE_H + 3.0;

            if ($pdf->GetY() + $headH + $bodyH > $pageH - 10) {
                $pdf->AddPage();
                self::header($pdf, $title, $dateStart, $dateEnd);
            }
            $top = $pdf->GetY();
            $x0 = self::MARGIN;

            // Başlık hücreleri: gün adı + tarih
            for ($c = 0; $c < $colCount; $c++) {
                $x = $x0 + $c * $colW;
                $pdf->SetFillColor(14, 110, 116); // marka turkuazı
                $pdf->Rect($x, $top, $colW, $headH, 'DF');
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Helvetica', 'B', 8.5);
                $pdf->SetXY($x, $top + 1.0);
                $pdf->Cell($colW, 3.8, self::DAY_NAMES[$c], 0, 0, 'C');
                $pdf->SetFont('Helvetica', '', 8);
                $pdf->SetXY($x, $top + 4.8);
                $pdf->Cell($colW, 3.6, $colDates[$c], 0, 0, 'C');
            }
            $pdf->SetTextColor(0, 0, 0);

            // Gövde hücreleri
            $bodyTop = $top + $headH;
            for ($c = 0; $c < $colCount; $c++) {
                $x = $x0 + $c * $colW;
                $empty = !$colLines[$c];
                if ($empty) {
                    $pdf->SetFillColor(244, 246, 245);
                    $pdf->Rect($x, $bodyTop, $colW, $bodyH, 'DF');
                    continue;
                }
                $pdf->Rect($x, $bodyTop, $colW, $bodyH, 'D');
                $pdf->SetFont('Helvetica', '', 9);
                $y = $bodyTop + 1.6;
                foreach ($colLines[$c] as $l) {
                    $pdf->SetXY($x + 1.5, $y);
                    $pdf->Cell($colW - 3.0, self::LINE_H, $l, 0, 0, 'C');
                    $y += self::LINE_H;
                }
            }
            $pdf->SetY($bodyTop + $bodyH + 4);
        }

        return (string) $pdf->Output('S');
    }

    /** Sayfa başı: marka + menü adı + aralık (her yeni sayfada tekrar). */
    private static function header(\FPDF $pdf, string $title, string $dateStart, string $dateEnd): void
    {
        $pdf->SetXY(self::MARGIN, 8);
        $pdf->SetTextColor(14, 110, 116);
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->Cell(120, 7, self::conv('UYSA Yemek Hizmetleri'), 0, 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', 'B', 12);
        $range = ($dateStart !== '' && $dateEnd !== '') ? '  (' . self::d($dateStart) . ' - ' . self::d($dateEnd) . ')' : '';
        $pdf->Cell(0, 7, self::conv(($title !== '' ? $title : 'Menu') . $range), 0, 1, 'R');
        $pdf->SetDrawColor(14, 110, 116);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(self::MARGIN, 17, $pdf->GetPageWidth() - self::MARGIN, 17);
        $pdf->SetY(20);
    }

    /** Metni verilen genişliğe (mm) sığacak parçalara böl (kelime bazlı, taşan kelime kırpılır). */
    private static function wrap(\FPDF $pdf, string $text, float $w): array
    {
        $pdf->SetFont('Helvetica', '', 9);
        if ($pdf->GetStringWidth($text) <= $w) {
            return [$text];
        }
        $out = [];
        $line = '';
        foreach (explode(' ', $text) as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($pdf->GetStringWidth($try) <= $w) {
                $line = $try;
                continue;
            }
            if ($line !== '') {
                $out[] = $line;
            }
            // Tek başına sığmayan kelimeyi karakter bazında kır
            while ($pdf->GetStringWidth($word) > $w && $word !== '') {
                $cut = max(1, (int) floor(strlen($word) * $w / max(0.1, $pdf->GetStringWidth($word))));
                $out[] = substr($word, 0, $cut);
                $word = substr($word, $cut);
            }
            $line = $word;
        }
        if ($line !== '') {
            $out[] = $line;
        }
        return $out;
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

    private static function d(string $d): string
    {
        $ts = strtotime($d);
        return $ts === false ? $d : date('d.m.Y', $ts);
    }
}
