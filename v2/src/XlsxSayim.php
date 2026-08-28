<?php

declare(strict_types=1);

namespace Uysa;

use ZipArchive;

/**
 * fable-093 (Ömer, 28 Ağu): "bir de Excel olarak indirebileyim."
 *
 * Aylık sayım tablosunu xlsx'e yazar — kütüphanesiz (ZipArchive + XML), PhpSpreadsheet YOK.
 *
 * XlsxMenu::buildXlsx'ten farkı: orası HER hücreyi `inlineStr` yazıyor, yani sayılar Excel'de
 * METİN olur ve Ömer dosyada toplam alamaz ("sayı metin olarak saklanmış" uyarısı). Burada
 * sayısal hücreler gerçek sayı (`<v>`) olarak yazılır; kalın satırlar için tek bir stil tanımı
 * vardır (başlık/toplam satırları okunur kalsın).
 */
final class XlsxSayim
{
    /**
     * @param list<array<int,string|int|float|null>> $rows Hücreler: string → metin, int/float → sayı
     * @param list<int> $kalinSatirlar 1'den başlayan satır numaraları (başlık/toplam)
     */
    public static function yaz(array $rows, string $sayfaAdi = 'Sayım', array $kalinSatirlar = []): string
    {
        $kalin = array_flip($kalinSatirlar);
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<cols><col min="1" max="1" width="14" customWidth="1"/>'
            . '<col min="2" max="20" width="12" customWidth="1"/></cols>'
            . '<sheetData>';
        $r = 0;
        foreach ($rows as $cells) {
            $r++;
            $sheet .= '<row r="' . $r . '">';
            $c = 0;
            foreach ($cells as $val) {
                $c++;
                if ($val === null || $val === '') {
                    continue;
                }
                $ref = self::sutun($c) . $r;
                $s = isset($kalin[$r]) ? ' s="1"' : '';
                if (is_int($val) || is_float($val)) {
                    $sheet .= '<c r="' . $ref . '"' . $s . '><v>' . $val . '</v></c>';
                } else {
                    $sheet .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
                        . self::esc((string) $val) . '</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="2"><xf xfId="0"/><xf xfId="0" fontId="1" applyFont="1"/></cellXfs>'
            . '</styleSheet>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::esc(mb_substr($sayfaAdi, 0, 30)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) {
            throw new \RuntimeException('Geçici dosya açılamadı.');
        }
        $z = new ZipArchive();
        if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Excel paketi oluşturulamadı.');
        }
        $z->addFromString('[Content_Types].xml', $contentTypes);
        $z->addFromString('_rels/.rels', $rels);
        $z->addFromString('xl/workbook.xml', $workbook);
        $z->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
        $z->addFromString('xl/styles.xml', $styles);
        $z->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $z->close();
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }

    private static function sutun(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + ($n % 26)) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
