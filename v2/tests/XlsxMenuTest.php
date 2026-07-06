<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Uysa\Repo;
use Uysa\XlsxMenu;

/**
 * opus-016: Menü Excel içe/dışa aktarma (kütüphanesiz ZipArchive/SimpleXML).
 * - GERÇEK formatlı fixture (sharedStrings + t="s" tarih hücreleri, hafta sonu boş) parse.
 * - importMenuItems upsert (aynı tarih tekrar → güncelle, duplicate yok).
 * - export→import round-trip grid yapısı.
 * - parseDate: DD.MM.YYYY + Excel serial.
 */
final class XlsxMenuTest extends TestCase
{
    private const FIX = __DIR__ . '/fixtures/menu_grid.xlsx';

    // ── parseDate birimleri ───────────────────────────────────
    public function testParseDateFormats(): void
    {
        $this->assertSame('2026-06-01', XlsxMenu::parseDate('01.06.2026'));
        $this->assertSame('2026-06-01', XlsxMenu::parseDate('1/6/2026'));
        $this->assertSame('2026-07-06', XlsxMenu::parseDate('2026-07-06'));
        // Excel serial: 2026-06-01 = 46174 (1900 tabanı)
        $this->assertSame('2026-06-01', XlsxMenu::parseDate('46174'));
        // tarih olmayanlar
        $this->assertNull(XlsxMenu::parseDate('MERCİMEK ÇORBASI'));
        $this->assertNull(XlsxMenu::parseDate(''));
        $this->assertNull(XlsxMenu::parseDate('32.13.2026'));
        $this->assertNull(XlsxMenu::parseDate('5')); // küçük sayı serial değil
    }

    // ── Gerçek formatlı fixture okuma ─────────────────────────
    public function testReadRealFormatFixture(): void
    {
        $res = XlsxMenu::read(self::FIX);
        $this->assertSame('TEST MENU BASLIK', $res['title']);

        // 2 hafta × 5 iş günü = 10 gün; hafta sonu (F/G tarihli ama yemeksiz) ATLANIR
        $this->assertCount(10, $res['days'], 'yalnız yemeği olan (iş günü) tarihler alınır');

        $byDate = [];
        foreach ($res['days'] as $d) {
            $byDate[$d['date']] = $d['dishes'];
        }
        // Hafta sonu tarihleri gelmemeli
        $this->assertArrayNotHasKey('2026-07-11', $byDate, 'cumartesi (yemeksiz) atlandı');
        $this->assertArrayNotHasKey('2026-07-12', $byDate, 'pazar (yemeksiz) atlandı');

        // İlk gün: sütun A altındaki 4 yemek
        $this->assertSame(
            ['MERCİMEK ÇORBASI', 'ETLİ NOHUT', 'PİRİNÇ PİLAVI', 'SALATA'],
            $byDate['2026-07-06']
        );
        // İkinci hafta ilk gün (blok ayrımı doğru mu)
        $this->assertSame(
            ['İŞKEMBE ÇORBASI', 'IZGARA KÖFTE', 'PİRİNÇ PİLAVI'],
            $byDate['2026-07-13']
        );
        // Günler tarih sıralı
        $dates = array_column($res['days'], 'date');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates, 'günler tarih sıralı döner');
    }

    public function testReadInvalidFileThrows(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($tmp, 'bu bir xlsx değil');
        $this->expectException(\RuntimeException::class);
        try {
            XlsxMenu::read($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    // ── importMenuItems upsert (duplicate yok) ────────────────
    public function testImportMenuItemsUpsert(): void
    {
        $pdo = fresh_db();
        $repo = new Repo($pdo);
        $mid = $repo->upsertMenu('Excel Menü', '2026-07-06', '2026-07-10', 'all');

        $days = XlsxMenu::read(self::FIX)['days'];
        $n = $repo->importMenuItems($mid, $days);
        $this->assertSame(10, $n, '10 gün içe aktarıldı');
        $this->assertCount(10, $repo->menuItems($mid));

        // Aynı fixture'ı yeniden içe al → GÜNCELLE, çift satır YOK
        $n2 = $repo->importMenuItems($mid, $days);
        $this->assertSame(10, $n2);
        $this->assertCount(10, $repo->menuItems($mid), 'upsert: duplicate oluşmaz');

        // dishes satır-satır (newline) kaydedildi + ilk gün içeriği doğru
        $items = $repo->menuItems($mid);
        $first = array_values(array_filter($items, static fn($i) => $i['item_date'] === '2026-07-06'))[0];
        $this->assertStringContainsString('MERCİMEK ÇORBASI', $first['dishes']);
        $this->assertStringContainsString("\n", $first['dishes'], 'yemekler satır satır');
        $this->assertSame('ogle', $first['meal']);
    }

    public function testImportSkipsInvalid(): void
    {
        $pdo = fresh_db();
        $repo = new Repo($pdo);
        $mid = $repo->upsertMenu('M', '2026-07-06', '2026-07-10', 'all');
        $n = $repo->importMenuItems($mid, [
            ['date' => '2026-07-06', 'dishes' => ['Çorba', 'Ana']],
            ['date' => 'bozuk-tarih', 'dishes' => ['X']],   // geçersiz tarih → atla
            ['date' => '2026-07-07', 'dishes' => []],         // boş yemek → atla
            ['date' => '2026-07-08', 'dishes' => 'Tek Satır'],
        ]);
        $this->assertSame(2, $n, 'yalnız geçerli 2 gün');
        $this->assertCount(2, $repo->menuItems($mid));
    }

    // ── export (write) → import (read) round-trip ─────────────
    public function testExportImportRoundTrip(): void
    {
        $items = [
            ['item_date' => '2026-07-06', 'dishes' => "Mercimek Çorbası\nEtli Nohut\nPilav"],
            ['item_date' => '2026-07-07', 'dishes' => "Tarhana\nTavuk Sote"],
            ['item_date' => '2026-07-13', 'dishes' => "Domates Çorbası\nKöfte\nMakarna\nSalata"], // gelecek hafta
        ];
        $bin = XlsxMenu::write('Round Trip Menüsü', $items);
        $this->assertNotEmpty($bin);
        $this->assertSame("PK", substr($bin, 0, 2), 'geçerli zip/xlsx imzası');

        $tmp = tempnam(sys_get_temp_dir(), 'rt') . '.xlsx';
        file_put_contents($tmp, $bin);
        try {
            $res = XlsxMenu::read($tmp);
            $byDate = [];
            foreach ($res['days'] as $d) {
                $byDate[$d['date']] = $d['dishes'];
            }
            $this->assertCount(3, $res['days']);
            $this->assertSame(['Mercimek Çorbası', 'Etli Nohut', 'Pilav'], $byDate['2026-07-06']);
            $this->assertSame(['Domates Çorbası', 'Köfte', 'Makarna', 'Salata'], $byDate['2026-07-13']);
        } finally {
            @unlink($tmp);
        }
    }

    public function testWriteProducesOpenableXlsx(): void
    {
        $bin = XlsxMenu::write('Tek Gün', [['item_date' => '2026-07-06', 'dishes' => "A\nB"]]);
        $tmp = tempnam(sys_get_temp_dir(), 'ox') . '.xlsx';
        file_put_contents($tmp, $bin);
        $z = new ZipArchive();
        $this->assertTrue($z->open($tmp) === true, 'yazılan dosya geçerli zip');
        $this->assertNotFalse($z->getFromName('xl/worksheets/sheet1.xml'));
        $this->assertNotFalse($z->getFromName('[Content_Types].xml'));
        $z->close();
        @unlink($tmp);
    }
}
