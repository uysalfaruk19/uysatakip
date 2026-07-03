<?php
declare(strict_types=1);

/**
 * UYSA ERP v1 → v2 migrasyonu (kayıpsız).
 *
 * Kaynak: v1 `uysa_storage` (key-value JSON blob).  Hedef: v2 ilişkisel tablolar.
 * Eşleme (mimari.md): gunluk_uretim→production+customers · gelir/gider→transactions ·
 *   alacak/borç→cari_entries · crm→customers.contact · prices→ingredients · recipes_v2→recipes.
 * lafetta_* / hikari_* TAŞINMAZ (DTakip'in işi). v1 tablolarına DOKUNULMAZ.
 *
 * Mojibake: müşteri adları CP1252 çift-kodlama ile bozuk → onarılır (Helpers::unmojibake),
 * onarılan ad KNOWN listesine karşı doğrulanır; eşleşmeyen/anomali satır RAPORLANIR, tahmin edilmez.
 *
 * Kullanım:
 *   php tools/migrate_v1.php --dry-run [--source=uysa_v1_src]
 *   php tools/migrate_v1.php           [--source=uysa_v1_src]   (gerçek yazım)
 */

require __DIR__ . '/../src/bootstrap.php';

use Uysa\Db;
use Uysa\Env;
use Uysa\Helpers;

$dryRun = in_array('--dry-run', $argv, true);
$sourceDb = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--source=')) {
        $sourceDb = substr($a, 9);
    }
}
$sourceDb ??= Env::get('V1_DB_NAME', Env::get('DB_NAME', 'uysa_db'));

// ── Kaynak bağlantı (v1) ─────────────────────────────────────
$srcDriver = Env::get('DB_DRIVER', 'mysql');
if ($srcDriver === 'sqlite') {
    // Test modunda kaynak da hedef PDO üzerinden bir uysa_storage tablosundan okunur.
    $src = Db::pdo();
} else {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        Env::get('DB_HOST', '127.0.0.1'), Env::get('DB_PORT', '3306'), $sourceDb);
    $src = new PDO($dsn, Env::get('DB_USER', 'root'), Env::get('DB_PASS', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
    ]);
}

$dst = Db::pdo();

// ── Yardımcılar ──────────────────────────────────────────────
$store = static function (string $key) use ($src): ?string {
    $st = $src->prepare('SELECT store_value FROM uysa_storage WHERE store_key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
};
$json = static function (?string $s): array {
    if ($s === null) {
        return [];
    }
    $d = json_decode($s, true);
    return is_array($d) ? $d : [];
};
$fix = static fn(string $s): string => Helpers::unmojibake($s);

// Bilinen müşteriler (onarım doğrulaması). Onarılan ad buraya normalize ile uymazsa RAPORLANIR.
$KNOWN = ['BOMİ','OPAK','CANTAŞ','TALAY LOJİSTİK','PENDORYA','ERMETAL','CEOTHERM','E-DEPO','MACİTLER MOBİLYA','GENEL'];
$knownNorm = [];
foreach ($KNOWN as $k) {
    $knownNorm[Helpers::normalizeName($k)] = $k;
}

$report = [
    'mojibake_map'   => [],   // raw => fixed (yalnız gerçekten değişenler)
    'unmatched'      => [],   // onarılamayan / KNOWN dışı adlar
    'skipped_rows'   => [],   // anomali satırlar
    'collisions'     => [],   // UNIQUE çakışması
    'sections'       => [],   // bölüm bazlı sayı/toplam
];

/** Ham adı onar + doğrula. Dönüş [canonical|null, note]. */
$resolveCustomer = static function (string $raw) use ($fix, $knownNorm, &$report): array {
    $raw = trim($raw);
    if ($raw === '') {
        return [null, 'bos_ad'];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return [null, 'ad_yerine_tarih'];
    }
    $fixed = $fix($raw);
    if (str_contains($fixed, "\u{FFFD}")) {
        return [null, 'onarilamaz_karakter'];
    }
    $norm = Helpers::normalizeName($fixed);
    if (isset($knownNorm[$norm])) {
        $canonical = $knownNorm[$norm];
        if ($raw !== $canonical) {
            $report['mojibake_map'][$raw] = $canonical;
        }
        return [$canonical, 'ok'];
    }
    // KNOWN dışı — raporla, tahminle yazma (ama onarılmış hali kaydedilebilir olsun diye döndürme)
    return [null, 'known_disi'];
};

// ════════════════════════════════════════════════════════════
// 1) MÜŞTERİLER  (customers_v1 + gunluk_uretim distinct + crm)
// ════════════════════════════════════════════════════════════
$gu = $json($store('uysa_gunluk_uretim'));

// v1 ham üretim toplamları (reconciliation referansı)
$rawRows = 0; $rawTotal = 0.0;
foreach ($gu as $r) {
    $rawRows++;
    $rawTotal += (float) ($r['tutar'] ?? 0);
}

// Geçerli üretim satırları + müşteri fiyatları.
// ÖĞÜN KIRILIMI (rev): v1 satırlarının çoğunda ogle/aksam/kumanya alt objeleri var ve
// gerçek veri onlar (üst kisi/tutar türetilmiş alan). Kırılım varsa her öğün AYRI production
// satırı olur; kırılım toplamı v1 tutar ile TUTMUYORSA satır anomalidir (tahmin yok).
$MEALS = ['sabah', 'ogle', 'aksam', 'gece', 'kumanya'];
$validProd = [];      // [customer => [ [date,meal,persons,price,amount,note], ... ]]
$latestPrice = [];    // canonical => ['date'=>,'price'=>]
$custContact = [];    // canonical => ['contact'=>,'phone'=>]
$skippedTotal = 0.0;  // ad/tarih anomalisi tutar toplamı
$mismatchTotal = 0.0; // kırılım≠tutar uyuşmazlığı tutar toplamı
$mismatchRows = 0;
$breakdownSrcRows = 0;
$flatSrcRows = 0;

foreach ($gu as $r) {
    $rawName = (string) ($r['musteri'] ?? '');
    $date = (string) ($r['tarih'] ?? '');
    [$canon, $note] = $resolveCustomer($rawName);
    $tutar = (float) ($r['tutar'] ?? 0);
    if ($canon === null || !Helpers::isDate($date)) {
        $report['skipped_rows'][] = [
            'kaynak' => 'gunluk_uretim',
            'sebep'  => $canon === null ? $note : 'gecersiz_tarih',
            'ham'    => $r,
        ];
        $skippedTotal += $tutar;
        if ($canon === null && !in_array($note, ['bos_ad','ad_yerine_tarih'], true)) {
            $report['unmatched'][$rawName] = $note;
        }
        continue;
    }
    $rowNote = (string) ($r['not'] ?? '');
    $topPrice = (float) ($r['fiyat'] ?? 0);

    $hasBreakdown = false;
    foreach ($MEALS as $m) {
        if (isset($r[$m]) && is_array($r[$m])) {
            $hasBreakdown = true;
            break;
        }
    }

    if ($hasBreakdown) {
        $breakdownSrcRows++;
        $mealRows = [];
        $sum = 0.0;
        foreach ($MEALS as $m) {
            if (!isset($r[$m]) || !is_array($r[$m])) {
                continue;
            }
            $k = (int) ($r[$m]['kisi'] ?? 0);
            $f = (float) ($r[$m]['fiyat'] ?? $topPrice);
            if ($k <= 0) {
                continue;
            }
            $amt = round($k * $f, 2);
            $mealRows[] = ['date' => $date, 'meal' => $m, 'persons' => $k, 'price' => $f, 'amount' => $amt, 'note' => $rowNote];
            $sum += $amt;
        }
        if (abs($sum - $tutar) > 0.005) {
            // Kırılım toplamı türetilmiş tutar ile uyuşmuyor → ANOMALİ, tahminle yazma
            $report['skipped_rows'][] = [
                'kaynak' => 'gunluk_uretim',
                'sebep'  => sprintf('kirilim_tutar_uyusmazligi (ogun_toplam=%s / tutar=%s)',
                    number_format($sum, 2, ',', '.'), number_format($tutar, 2, ',', '.')),
                'ham'    => $r,
            ];
            $mismatchTotal += $tutar;
            $mismatchRows++;
        } else {
            foreach ($mealRows as $mr) {
                $validProd[$canon][] = $mr;
            }
        }
    } else {
        // Düz satır (kırılım alanı hiç yok) → tek satır meal='ogle', amount = v1 tutar
        $flatSrcRows++;
        $validProd[$canon][] = [
            'date' => $date, 'meal' => 'ogle', 'persons' => (int) ($r['kisi'] ?? 0),
            'price' => $topPrice, 'amount' => $tutar, 'note' => $rowNote,
        ];
    }

    if (!isset($latestPrice[$canon]) || $date > $latestPrice[$canon]['date']) {
        $latestPrice[$canon] = ['date' => $date, 'price' => $topPrice];
    }
}

// customers_v1 listesi (ekstra ad garantisi)
foreach (($json($store('uysa_customers_v1'))['customers'] ?? []) as $rawName) {
    [$canon] = $resolveCustomer((string) $rawName);
    if ($canon !== null && !isset($latestPrice[$canon])) {
        $latestPrice[$canon] = ['date' => '', 'price' => 0.0];
    }
}

// crm kartlarından iletişim + fiyat
$allKeys = $src->query('SELECT store_key FROM uysa_storage')->fetchAll(PDO::FETCH_COLUMN);
foreach ($allKeys as $key) {
    if (!str_starts_with($key, 'uysa_crm_')) {
        continue;
    }
    $rawName = substr($key, strlen('uysa_crm_'));
    [$canon] = $resolveCustomer($rawName);
    if ($canon === null) {
        continue;
    }
    $crm = $json($store($key));
    $custContact[$canon] = [
        'contact' => $fix((string) ($crm['ilgili'] ?? '')),
        'phone'   => (string) ($crm['telefon'] ?? ''),
    ];
    if (!isset($latestPrice[$canon]) || $latestPrice[$canon]['price'] == 0.0) {
        $latestPrice[$canon] = ['date' => '', 'price' => (float) ($crm['fiyat'] ?? 0)];
    }
}

$customersToWrite = [];
foreach ($latestPrice as $canon => $info) {
    $customersToWrite[$canon] = [
        'unit_price' => $info['price'],
        'contact'    => $custContact[$canon]['contact'] ?? null,
        'phone'      => $custContact[$canon]['phone'] ?? null,
    ];
}
$report['sections']['customers'] = ['count' => count($customersToWrite)];

// ════════════════════════════════════════════════════════════
// 2) SUPPLIERS (tedarikci + tedarikciler)
// ════════════════════════════════════════════════════════════
$suppliers = [];
foreach ($json($store('uysa_tedarikci')) as $t) {
    $n = $fix((string) ($t['firma'] ?? $t['ad'] ?? ''));
    if ($n !== '') { $suppliers[$n] = true; }
}
foreach ($json($store('uysa_tedarikciler')) as $t) {
    $n = $fix((string) ($t['ad'] ?? $t['firma'] ?? $t['unvan'] ?? ''));
    if ($n !== '') { $suppliers[$n] = true; }
}
$report['sections']['suppliers'] = ['count' => count($suppliers)];

// ════════════════════════════════════════════════════════════
// 3) TRANSACTIONS (gelirler → gelir, giderler → gider)
// ════════════════════════════════════════════════════════════
$gelirler = $json($store('uysa_gelirler'));
$giderler = $json($store('uysa_giderler'));
$txGelirTotal = 0.0; $txGiderTotal = 0.0;
$txRows = [];
foreach ($gelirler as $g) {
    $d = (string) ($g['tarih'] ?? '');
    if (!Helpers::isDate($d)) {
        $report['skipped_rows'][] = ['kaynak' => 'gelirler', 'sebep' => 'gecersiz_tarih', 'ham' => $g];
        continue;
    }
    $amt = (float) ($g['tutar'] ?? 0);
    [$canon] = $resolveCustomer((string) ($g['musteri'] ?? ''));
    $txRows[] = ['type' => 'gelir', 'amount' => $amt, 'date' => $d,
        'cat' => 'uretim', 'desc' => $fix((string) ($g['aciklama'] ?? '')), 'customer' => $canon];
    $txGelirTotal += $amt;
}
foreach ($giderler as $g) {
    $d = (string) ($g['tarih'] ?? '');
    if (!Helpers::isDate($d)) {
        $report['skipped_rows'][] = ['kaynak' => 'giderler', 'sebep' => 'gecersiz_tarih', 'ham' => $g];
        continue;
    }
    $amt = (float) ($g['tutar'] ?? 0);
    $txRows[] = ['type' => 'gider', 'amount' => $amt, 'date' => $d,
        'cat' => $fix((string) ($g['kat'] ?? '')), 'desc' => $fix((string) ($g['aciklama'] ?? '')), 'customer' => null];
    $txGiderTotal += $amt;
}
$report['sections']['transactions'] = [
    'gelir_count' => count($gelirler), 'gelir_total' => $txGelirTotal,
    'gider_count' => count($giderler), 'gider_total' => $txGiderTotal,
];

// ════════════════════════════════════════════════════════════
// 4) CARI (alacaklar → customer borç · borclar → supplier borç)
// ════════════════════════════════════════════════════════════
$alacaklar = $json($store('uysa_alacaklar'));
$borclar = $json($store('uysa_borclar'));
$cariRows = [];
$alacakTotal = 0.0; $borcTotal = 0.0;
foreach ($alacaklar as $a) {
    $d = (string) ($a['tarih'] ?? '');
    if (!Helpers::isDate($d)) {
        $report['skipped_rows'][] = ['kaynak' => 'alacaklar', 'sebep' => 'gecersiz_tarih', 'ham' => $a];
        continue;
    }
    [$canon] = $resolveCustomer((string) ($a['musteri'] ?? ''));
    $amt = (float) ($a['tutar'] ?? 0);
    // Dry-run'da da taraf kontrolü: müşteri KNOWN/customersToWrite'ta değilse raporla
    if ($canon === null || !isset($customersToWrite[$canon])) {
        $report['skipped_rows'][] = ['kaynak' => 'alacaklar', 'sebep' => 'taraf_bulunamadi', 'ham' => $a];
        continue;
    }
    $cariRows[] = ['party' => 'customer', 'name' => $canon, 'date' => $d,
        'dir' => 'borc', 'amount' => $amt, 'note' => $fix((string) ($a['aciklama'] ?? ''))];
    $alacakTotal += $amt;
}
foreach ($borclar as $b) {
    $d = (string) ($b['tarih'] ?? '');
    if (!Helpers::isDate($d)) {
        $report['skipped_rows'][] = ['kaynak' => 'borclar', 'sebep' => 'gecersiz_tarih', 'ham' => $b];
        continue;
    }
    $amt = (float) ($b['tutar'] ?? 0);
    $sup = $fix((string) ($b['tedarikci'] ?? ''));
    if ($sup === '') {
        $report['skipped_rows'][] = ['kaynak' => 'borclar', 'sebep' => 'taraf_bulunamadi', 'ham' => $b];
        continue;
    }
    $suppliers[$sup] = true;
    $cariRows[] = ['party' => 'supplier', 'name' => $sup, 'date' => $d,
        'dir' => 'borc', 'amount' => $amt, 'note' => $fix((string) ($b['aciklama'] ?? ''))];
    $borcTotal += $amt;
}
$report['sections']['cari'] = [
    'alacak_count' => count($cariRows ? array_filter($cariRows, fn($c) => $c['party'] === 'customer') : []),
    'alacak_total' => $alacakTotal,
    'borc_count' => count($cariRows ? array_filter($cariRows, fn($c) => $c['party'] === 'supplier') : []),
    'borc_total' => $borcTotal,
];

// ════════════════════════════════════════════════════════════
// 5) INGREDIENTS (prices_tl_per_kg_v1) + RECIPES (recipes_v2)
// ════════════════════════════════════════════════════════════
$prices = $json($store('uysa_prices_tl_per_kg_v1'));
// utf8mb4_unicode_ci büyük/küçük harfi eşitler → malzeme adları küçük-harf anahtarla tekilleştirilir
$ingredients = []; // lowerKey => ['name'=>display, 'price'=>float]
// MySQL utf8mb4_unicode_ci ile aynı davranış (aksan/combining/İ-ı katlama) için sıkı normalize
$ingKey = static fn(string $n): string => Helpers::normalizeName($n);
foreach ($prices as $name => $price) {
    $n = $fix((string) $name);
    if ($n === '') { continue; }
    $k = $ingKey($n);
    if (!isset($ingredients[$k]) || $ingredients[$k]['price'] == 0.0) {
        $ingredients[$k] = ['name' => $n, 'price' => (float) $price];
    }
}
$recipes = $json($store('uysa_recipes_v2'));
$recipeRows = []; // name => [ [ingredientKey, grams], ... ]
$recipeSeen = []; // normalize => true (case-insensitive tekilleştirme)
foreach ($recipes as $rname => $items) {
    $rn = $fix((string) $rname);
    if ($rn === '' || !is_array($items)) { continue; }
    $rk = Helpers::normalizeName($rn);
    if (isset($recipeSeen[$rk])) { continue; }
    $recipeSeen[$rk] = true;
    $parts = [];
    foreach ($items as $it) {
        $inm = $fix((string) ($it['name'] ?? ''));
        $g = (float) ($it['gram'] ?? 0);
        if ($inm === '') { continue; }
        $k = $ingKey($inm);
        if (!isset($ingredients[$k])) { $ingredients[$k] = ['name' => $inm, 'price' => 0.0]; }
        $parts[] = [$k, $g];
    }
    $recipeRows[$rn] = $parts;
}
$report['sections']['ingredients'] = ['count' => count($ingredients)];
$report['sections']['recipes'] = ['count' => count($recipeRows)];

// menu_grid: yapı uyuşmuyor (haftalık grid) → F2'ye ertelendi, RAPORLANIR
$menuGrid = $json($store('uysa_menu_grid_v2_dates'));
$report['sections']['menu_grid'] = ['count' => count($menuGrid), 'durum' => 'F2_ertelendi_yapi_uyusmuyor'];

// ── Üretim reconciliation hesabı (TUTAR bazlı denklik) ───────
// v1 satır ≠ v2 satır (öğün kırılımı) → denklik: valid + ad/tarih-anomali + kırılım-anomali == ham TUTAR
$validRows = 0; $validTotal = 0.0;
foreach ($validProd as $rows) {
    foreach ($rows as $row) {
        $validRows++;
        $validTotal += $row['amount'];
    }
}
$report['sections']['production'] = [
    'raw_rows' => $rawRows, 'raw_total' => $rawTotal,
    'breakdown_src' => $breakdownSrcRows, 'flat_src' => $flatSrcRows,
    'valid_rows' => $validRows, 'valid_total' => $validTotal,
    'skipped_total' => $skippedTotal,
    'mismatch_rows' => $mismatchRows, 'mismatch_total' => $mismatchTotal,
];

// ════════════════════════════════════════════════════════════
// YAZIM (dry-run değilse)
// ════════════════════════════════════════════════════════════
$inserted = ['customers' => 0, 'suppliers' => 0, 'production' => 0, 'transactions' => 0,
    'cari' => 0, 'ingredients' => 0, 'recipes' => 0, 'recipe_items' => 0];
$prodInsertedTotal = 0.0;

if (!$dryRun) {
    $dst->beginTransaction();
    try {
        // customers
        $custId = [];
        foreach ($customersToWrite as $name => $c) {
            $dst->prepare('INSERT INTO customers (name, unit_price, contact, phone) VALUES (?, ?, ?, ?)')
                ->execute([$name, $c['unit_price'], $c['contact'] ?: null, $c['phone'] ?: null]);
            $custId[$name] = (int) $dst->lastInsertId();
            $inserted['customers']++;
        }
        // suppliers
        $supId = [];
        foreach (array_keys($suppliers) as $name) {
            $dst->prepare('INSERT INTO suppliers (name) VALUES (?)')->execute([$name]);
            $supId[$name] = (int) $dst->lastInsertId();
            $inserted['suppliers']++;
        }
        // production (UNIQUE customer,date,meal — çakışmaları raporla)
        $seen = [];
        $prodStmt = $dst->prepare(
            'INSERT INTO production (customer_id, prod_date, meal, persons, unit_price_snap, amount, note, entered_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($validProd as $name => $rows) {
            foreach ($rows as $row) {
                $meal = $row['meal'];
                $ck = $name . '|' . $row['date'] . '|' . $meal;
                if (isset($seen[$ck])) {
                    $report['collisions'][] = ['kaynak' => 'gunluk_uretim', 'anahtar' => $ck, 'tutar' => $row['amount']];
                    continue;
                }
                $seen[$ck] = true;
                $prodStmt->execute([$custId[$name], $row['date'], $meal, $row['persons'],
                    $row['price'], $row['amount'], $row['note'] ?: null, 'uysa']);
                $inserted['production']++;
                $prodInsertedTotal += $row['amount'];
            }
        }
        // transactions
        foreach ($txRows as $t) {
            $cid = $t['customer'] !== null ? ($custId[$t['customer']] ?? null) : null;
            $dst->prepare('INSERT INTO transactions (type, category, tx_date, amount, customer_id, description) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$t['type'], $t['cat'] ?: null, $t['date'], $t['amount'], $cid, $t['desc'] ?: null]);
            $inserted['transactions']++;
        }
        // cari (build fazı taraf kontrolünü zaten yaptı → burada taraf garanti var)
        foreach ($cariRows as $c) {
            $pid = $c['party'] === 'customer' ? ($custId[$c['name']] ?? null) : ($supId[$c['name']] ?? null);
            if ($pid === null) {
                continue; // beklenmez
            }
            $dst->prepare('INSERT INTO cari_entries (party_type, party_id, entry_date, direction, amount, note) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$c['party'], $pid, $c['date'], $c['dir'], $c['amount'], $c['note'] ?: null]);
            $inserted['cari']++;
        }
        // ingredients (lowerKey => id)
        $ingId = [];
        foreach ($ingredients as $k => $info) {
            $dst->prepare('INSERT INTO ingredients (name, unit, price_per_unit) VALUES (?, ?, ?)')
                ->execute([$info['name'], 'kg', $info['price']]);
            $ingId[$k] = (int) $dst->lastInsertId();
            $inserted['ingredients']++;
        }
        // recipes + items
        foreach ($recipeRows as $rname => $parts) {
            $dst->prepare('INSERT INTO recipes (name) VALUES (?)')->execute([$rname]);
            $rid = (int) $dst->lastInsertId();
            $inserted['recipes']++;
            foreach ($parts as [$k, $g]) {
                $dst->prepare('INSERT INTO recipe_items (recipe_id, ingredient_id, grams) VALUES (?, ?, ?)')
                    ->execute([$rid, $ingId[$k], $g]);
                $inserted['recipe_items']++;
            }
        }
        $dst->commit();
    } catch (\Throwable $e) {
        $dst->rollBack();
        fwrite(STDERR, "MIGRASYON HATASI: " . $e->getMessage() . "\n");
        exit(1);
    }
}

// ════════════════════════════════════════════════════════════
// RAPOR
// ════════════════════════════════════════════════════════════
$m = fn(float $n) => Helpers::money($n);
echo "\n";
echo "════════════════════════════════════════════════════════════\n";
echo "  UYSA v1 → v2 MİGRASYON " . ($dryRun ? "[DRY-RUN — yazım yok]" : "[GERÇEK YAZIM]") . "\n";
echo "  Kaynak: {$sourceDb}\n";
echo "════════════════════════════════════════════════════════════\n\n";

echo "── ÜRETİM RECONCILIATION (kabul kriteri — TUTAR bazlı) ─────\n";
$p = $report['sections']['production'];
echo "  Öğün kırılımı: v1 satır ≠ v2 satır (kırılımlı satır → öğün başına ayrı production).\n";
printf("  Kaynak satır: %d ham (%d kırılımlı + %d düz)\n", $p['raw_rows'], $p['breakdown_src'], $p['flat_src']);
printf("  %-38s %10s  %16s\n", "", "SATIR", "TUTAR (TL)");
printf("  %-38s %10s  %16s\n", "v1 ham (gunluk_uretim tutar toplamı)", '', $m($p['raw_total']));
printf("  %-38s %10d  %16s\n", "  - ad/tarih anomalisi (atlandı)", 0, $m($p['skipped_total']));
printf("  %-38s %10d  %16s\n", "  - kırılım≠tutar anomalisi (atlandı)", $p['mismatch_rows'], $m($p['mismatch_total']));
printf("  %-38s %10d  %16s\n", "= production'a taşınan (öğün satırı)", $p['valid_rows'], $m($p['valid_total']));
if (!$dryRun) {
    printf("  %-38s %10d  %16s\n", "→ production DB'de OKUNAN", $inserted['production'], $m($prodInsertedTotal));
    $dbSum = (float) $dst->query('SELECT COALESCE(SUM(amount),0) FROM production')->fetchColumn();
    $dbCnt = (int) $dst->query('SELECT COUNT(*) FROM production')->fetchColumn();
    printf("  %-38s %10d  %16s\n", "→ production DB SUM (kanıt)", $dbCnt, $m($dbSum));
}
$sumAll = $p['valid_total'] + $p['skipped_total'] + $p['mismatch_total'];
$balanced = abs($sumAll - $p['raw_total']) < 0.005;
echo "  DENKLİK (TUTAR): taşınan + anomaliler == ham → " . ($balanced ? "✅ TUTUYOR" : "❌ TUTMUYOR (" . $m($sumAll) . " / " . $m($p['raw_total']) . ")") . "\n";
if (!empty($report['collisions'])) {
    echo "  UYARI: " . count($report['collisions']) . " UNIQUE çakışması (aynı müşteri×gün×öğün) — aşağıda.\n";
}
echo "\n";

echo "── BÖLÜM ÖZETLERİ ──────────────────────────────────────────\n";
printf("  Müşteri (customers)   : %d\n", $report['sections']['customers']['count']);
printf("  Tedarikçi (suppliers) : %d\n", $report['sections']['suppliers']['count']);
$t = $report['sections']['transactions'];
printf("  Gelir tx : %d  (%s TL)   Gider tx : %d  (%s TL)\n", $t['gelir_count'], $m($t['gelir_total']), $t['gider_count'], $m($t['gider_total']));
$cr = $report['sections']['cari'];
printf("  Cari alacak : %d (%s TL)   Cari borç : %d (%s TL)\n", $cr['alacak_count'], $m($cr['alacak_total']), $cr['borc_count'], $m($cr['borc_total']));
printf("  Malzeme : %d   Reçete : %d\n", $report['sections']['ingredients']['count'], $report['sections']['recipes']['count']);
printf("  Menü grid : %d kayıt → %s\n", $report['sections']['menu_grid']['count'], $report['sections']['menu_grid']['durum']);
echo "\n";

echo "── MOJIBAKE ONARIM TABLOSU (uygulanan) ─────────────────────\n";
if ($report['mojibake_map']) {
    foreach ($report['mojibake_map'] as $raw => $fixed) {
        printf("  %-28s → %s\n", $raw, $fixed);
    }
} else {
    echo "  (onarım gerektiren müşteri adı bulunmadı)\n";
}
echo "\n";

echo "── ONAYLANMASI GEREKEN (eşleşmeyen / anomali) ──────────────\n";
if ($report['unmatched']) {
    echo "  KNOWN listesine uymayan adlar (Ömer onayı bekler):\n";
    foreach ($report['unmatched'] as $name => $why) {
        printf("    [%s]  (%s)\n", $name, $why);
    }
} else {
    echo "  Eşleşmeyen müşteri adı YOK.\n";
}
if ($report['skipped_rows']) {
    echo "  Atlanan anomali satırlar (" . count($report['skipped_rows']) . "):\n";
    foreach ($report['skipped_rows'] as $s) {
        echo "    [{$s['kaynak']}] {$s['sebep']}: " . json_encode($s['ham'], JSON_UNESCAPED_UNICODE) . "\n";
    }
}
if ($report['collisions']) {
    echo "  UNIQUE çakışmaları (ilk kayıt tutuldu, tekrar atlandı):\n";
    foreach ($report['collisions'] as $c) {
        echo "    {$c['anahtar']}  tutar={$m($c['tutar'])}\n";
    }
}
echo "\n";

if (!$dryRun) {
    echo "── YAZILAN KAYIT SAYILARI ──────────────────────────────────\n";
    foreach ($inserted as $k => $v) {
        printf("  %-14s : %d\n", $k, $v);
    }
    echo "\n";
}

echo ($dryRun ? "DRY-RUN tamam. Gerçek yazım için --dry-run'ı kaldır.\n" : "MİGRASYON TAMAM.\n");
