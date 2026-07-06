<?php
declare(strict_types=1);

namespace Uysa;

use ZipArchive;

/**
 * Menü Excel (xlsx) oku/yaz — kütüphanesiz (ZipArchive + SimpleXML), PhpSpreadsheet YOK.
 *
 * Format (Ömer'in "UYSA HAZİRAN MENU 2026.xlsx"): tek sayfa haftalık grid —
 *   satır1 başlık · gün adı satırı (PAZARTESİ..) · TARİH satırı (01.06.2026 veya Excel serial) ·
 *   altında ~6 yemek satırı (çorba/ana/pilav/salata/tatlı/ayran). Haftalar boş satırla ayrılır.
 *   Her TARİH sütunu = o günün öğle menüsü = altındaki dolu yemek hücrelerinin listesi.
 *   (Hafta sonu sütunlarında tarih olsa da altı boş → o gün atlanır.)
 */
final class XlsxMenu
{
    /**
     * xlsx dosyasını oku → başlık + günler.
     * @return array{title:string,days:array<int,array{date:string,dishes:array<int,string>}>}
     * @throws \RuntimeException geçersiz/bozuk dosyada
     */
    public static function read(string $path): array
    {
        $z = new ZipArchive();
        if ($z->open($path) !== true) {
            throw new \RuntimeException('Excel dosyası açılamadı (bozuk veya xlsx değil).');
        }
        $shared = self::readSharedStrings($z);
        // İlk worksheet'in yolunu workbook ilişkilerinden bul (genelde sheet1.xml).
        $sheetXml = $z->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            $sheetXml = self::firstSheetXml($z);
        }
        $z->close();
        if ($sheetXml === false || $sheetXml === '') {
            throw new \RuntimeException('Excel içinde sayfa bulunamadı.');
        }

        $grid = self::buildGrid($sheetXml, $shared); // [rowNum][colNum] => string
        return [
            'title' => self::detectTitle($grid),
            'days'  => self::extractDays($grid),
        ];
    }

    /**
     * Menü kalemlerini (menuItems çıktısı: item_date + dishes) haftalık grid xlsx'e yaz.
     * @param array<int,array{item_date:string,dishes:string}> $items
     * @return string xlsx ikili içeriği (indirilir/streamlenir)
     */
    public static function write(string $title, array $items): string
    {
        // Tarihe göre sırala + günleri {iso => dishes[]} olarak topla.
        $days = [];
        foreach ($items as $it) {
            $d = (string) ($it['item_date'] ?? '');
            if ($d === '') {
                continue;
            }
            $days[$d] = self::splitDishes((string) ($it['dishes'] ?? ''));
        }
        ksort($days);

        // Haftalara böl (ISO yıl-hafta). Her hafta 7 sütun (Pzt..Paz).
        $weeks = [];
        foreach ($days as $iso => $dishes) {
            $wk = date('o-W', strtotime($iso));
            $weeks[$wk][$iso] = $dishes;
        }

        $dayNames = ['PAZARTESİ', 'SALI', 'ÇARŞAMBA', 'PERŞEMBE', 'CUMA', 'CUMARTESİ', 'PAZAR'];
        $rows = [];
        $rows[] = [$title]; // satır 1: başlık
        $rows[] = [];       // boş
        foreach ($weeks as $week) {
            // gün adı satırı
            $rows[] = $dayNames;
            // tarih satırı — her günü ISO-8601 haftanın günü sütununa koy (1=Pzt..7=Paz)
            $dateRow = array_fill(0, 7, '');
            $colDishes = array_fill(0, 7, []);
            foreach ($week as $iso => $dishes) {
                $col = (int) date('N', strtotime($iso)) - 1; // 0..6
                $dateRow[$col] = date('d.m.Y', strtotime($iso));
                $colDishes[$col] = $dishes;
            }
            $rows[] = $dateRow;
            // yemek satırları (en çok yemeği olan güne göre)
            $maxDish = 0;
            foreach ($colDishes as $dd) {
                $maxDish = max($maxDish, count($dd));
            }
            for ($i = 0; $i < $maxDish; $i++) {
                $line = array_fill(0, 7, '');
                for ($c = 0; $c < 7; $c++) {
                    $line[$c] = $colDishes[$c][$i] ?? '';
                }
                $rows[] = $line;
            }
            $rows[] = []; // haftalar arası boş satır
        }

        return self::buildXlsx($rows);
    }

    /**
     * Ham hücre değerini ISO tarihe (YYYY-MM-DD) çevir; tarih değilse null.
     * Desteklenen: DD.MM.YYYY · DD/MM/YYYY · DD-MM-YYYY · Excel serial (1900 tabanı).
     */
    public static function parseDate(string $raw): ?string
    {
        $s = trim($raw);
        if ($s === '') {
            return null;
        }
        // DD.MM.YYYY / DD/MM/YYYY / DD-MM-YYYY
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $s, $m)) {
            $d = (int) $m[1];
            $mo = (int) $m[2];
            $y = (int) $m[3];
            if ($mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31 && checkdate($mo, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
            return null;
        }
        // Zaten ISO
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $s : null;
        }
        // Excel serial (tam sayı; makul tarih aralığı ~1970..2100 → 25569..73415)
        if (preg_match('/^\d+(\.0+)?$/', $s)) {
            $serial = (int) $s;
            if ($serial >= 25569 && $serial <= 80000) {
                // Excel 1900 epoch = 1899-12-30 (1900 artık yıl hatası bu tabanla telafi edilir).
                $ts = ($serial - 25569) * 86400; // 25569 = 1970-01-01
                return gmdate('Y-m-d', $ts);
            }
        }
        return null;
    }

    // ── iç yardımcılar ────────────────────────────────────────────

    /** @return array<int,string> sharedStrings indeksli metin */
    private static function readSharedStrings(ZipArchive $z): array
    {
        $xml = $z->getFromName('xl/sharedStrings.xml');
        if ($xml === false || $xml === '') {
            return [];
        }
        $sx = @simplexml_load_string($xml);
        if ($sx === false) {
            return [];
        }
        $out = [];
        foreach ($sx->si as $si) {
            $text = '';
            if (isset($si->t)) {
                $text .= (string) $si->t;
            }
            // zengin metin parçaları (r/t)
            foreach ($si->r as $r) {
                $text .= (string) $r->t;
            }
            $out[] = $text;
        }
        return $out;
    }

    private static function firstSheetXml(ZipArchive $z)
    {
        for ($i = 0; $i < $z->numFiles; $i++) {
            $name = $z->getNameIndex($i);
            if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $name)) {
                return $z->getFromName($name);
            }
        }
        return false;
    }

    /**
     * Worksheet XML → [rowNum][colNum] => değer (boş hücreler yok).
     * @param array<int,string> $shared
     * @return array<int,array<int,string>>
     */
    private static function buildGrid(string $sheetXml, array $shared): array
    {
        $sx = @simplexml_load_string($sheetXml);
        if ($sx === false || !isset($sx->sheetData)) {
            return [];
        }
        $grid = [];
        foreach ($sx->sheetData->row as $row) {
            $rNum = (int) $row['r'];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $type = (string) $c['t'];
                $val = '';
                if ($type === 's') {
                    $val = $shared[(int) $c->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = (string) $c->is->t;
                } elseif ($type === 'str') {
                    $val = (string) $c->v;
                } else {
                    $val = (string) $c->v;
                }
                $val = trim($val);
                if ($val === '') {
                    continue;
                }
                $col = self::colNum(preg_replace('/[0-9]/', '', $ref));
                if (!$rNum) {
                    $rNum = self::rowNum($ref);
                }
                $grid[$rNum][$col] = $val;
            }
        }
        return $grid;
    }

    /**
     * Grid'den günleri çıkar: her tarih hücresi altındaki dolu yemek hücrelerini toplar.
     * @param array<int,array<int,string>> $grid
     * @return array<int,array{date:string,dishes:array<int,string>}>
     */
    private static function extractDays(array $grid): array
    {
        $days = [];
        foreach ($grid as $rNum => $cols) {
            foreach ($cols as $cNum => $val) {
                $iso = self::parseDate($val);
                if ($iso === null) {
                    continue;
                }
                $dishes = [];
                for ($rr = $rNum + 1; ; $rr++) {
                    $cell = $grid[$rr][$cNum] ?? null;
                    if ($cell === null || trim($cell) === '') {
                        break; // sütunda boşluk → gün bloğu bitti
                    }
                    if (self::parseDate($cell) !== null) {
                        break; // bir sonraki tarih bloğu
                    }
                    $dishes[] = self::clean($cell);
                }
                if ($dishes) {
                    $days[$iso] = $dishes; // aynı tarih tekrarı → son okunan geçerli
                }
            }
        }
        ksort($days);
        $out = [];
        foreach ($days as $iso => $dishes) {
            $out[] = ['date' => $iso, 'dishes' => $dishes];
        }
        return $out;
    }

    /** @param array<int,array<int,string>> $grid */
    private static function detectTitle(array $grid): string
    {
        // İlk satırdaki ilk hücre başlık adayı (tarih değilse).
        if ($grid) {
            $firstRow = min(array_keys($grid));
            foreach ($grid[$firstRow] as $val) {
                if (self::parseDate($val) === null && mb_strlen($val) > 3) {
                    return mb_substr(self::clean($val), 0, 120);
                }
            }
        }
        return '';
    }

    private static function clean(string $s): string
    {
        // İç fazla boşlukları sadeleştir, kenar boşluklarını at.
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim((string) $s);
    }

    /** Kayıtlı dishes metnini satırlara ayır (önce satır sonu, yoksa virgül). */
    public static function splitDishes(string $dishes): array
    {
        $dishes = trim($dishes);
        if ($dishes === '') {
            return [];
        }
        $parts = preg_split("/\r\n|\r|\n/", $dishes);
        if (count($parts) <= 1) {
            $parts = explode(',', $dishes);
        }
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }

    /** Sütun harfi (A, B, .. AA) → 1 tabanlı sayı. */
    private static function colNum(string $letters): int
    {
        $letters = strtoupper($letters);
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n ?: 1;
    }

    /** 1 tabanlı sayı → sütun harfi. */
    private static function colLetter(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $r = ($n - 1) % 26;
            $s = chr(65 + $r) . $s;
            $n = intdiv($n - 1, 26);
        }
        return $s ?: 'A';
    }

    private static function rowNum(string $ref): int
    {
        preg_match('/(\d+)/', $ref, $m);
        return (int) ($m[1] ?? 0);
    }

    /**
     * Satır dizisinden minimal xlsx üret (inlineStr hücreler; sharedStrings gerektirmez).
     * @param array<int,array<int,string>> $rows 0 tabanlı sütunlu satırlar
     */
    private static function buildXlsx(array $rows): string
    {
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>';
        $r = 0;
        foreach ($rows as $cells) {
            $r++;
            $sheet .= '<row r="' . $r . '">';
            $c = 0;
            foreach ($cells as $val) {
                $c++;
                $val = (string) $val;
                if ($val === '') {
                    continue;
                }
                $ref = self::colLetter($c) . $r;
                $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                    . self::xmlEsc($val) . '</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Sayfa1" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';

        // Geçici dosyaya ZipArchive ile yaz, oku, sil (ZipArchive akış/bellek yazamaz).
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }

    private static function xmlEsc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
