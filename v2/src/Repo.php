<?php
declare(strict_types=1);

namespace Uysa;

use PDO;

/**
 * Veri erişim katmanı — müşteri-kapsamlı, tüm sorgular prepared statement.
 * (Multi-tenant geçişi kolay olsun diye sorgular müşteri odaklı kurulur.)
 */
final class Repo
{
    public function __construct(private PDO $pdo)
    {
    }

    // ── Müşteriler ────────────────────────────────────────────
    /** @return array<int,array> */
    public function activeCustomers(): array
    {
        return $this->pdo->query(
            'SELECT id, name, unit_price, category, contact, phone, contract_note
             FROM customers WHERE is_active = 1 ORDER BY name'
        )->fetchAll();
    }

    /** Kategoriye göre müşteri listesi (üretim/taşıma). Müşteri yönetimi ekranı. */
    public function listCustomersByCategory(string $category, bool $activeOnly = true): array
    {
        $sql = 'SELECT id, name, unit_price, category, contact, phone, is_active,
                       parasut_bakiye, parasut_sync_at
                FROM customers WHERE category = ?';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY name';
        $st = $this->pdo->prepare($sql);
        $st->execute([$category]);
        return $st->fetchAll();
    }

    /** Müşteri pasifleştir (silme YOK — FK bütünlüğü). */
    public function setCustomerActive(int $id, bool $active): void
    {
        $this->pdo->prepare('UPDATE customers SET is_active = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, $id]);
    }

    /** @return array<int,string> id=>name (fuzzy eşleşme için) */
    public function customerNameMap(bool $activeOnly = true): array
    {
        $sql = 'SELECT id, name FROM customers';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $out = [];
        foreach ($this->pdo->query($sql)->fetchAll() as $r) {
            $out[(int) $r['id']] = $r['name'];
        }
        return $out;
    }

    public function customer(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ?: null;
    }

    /**
     * Müşteri ekle/düzenle. $id verilirse o kayıt güncellenir (ad dahil);
     * verilmezse ada göre upsert (varsa güncelle, yoksa ekle). Kategori üretim/taşıma.
     */
    public function upsertCustomer(
        string $name,
        float $unitPrice,
        string $category = 'uretim',
        ?int $id = null,
        ?string $contact = null,
        ?string $phone = null,
        ?string $note = null,
        ?float $maliyetBirim = null,
        ?float $tasimaSabitGider = null,
        ?string $tasimaNot = null
    ): int {
        if (!in_array($category, ['uretim', 'tasima'], true)) {
            $category = 'uretim';
        }
        if ($id === null) {
            $st = $this->pdo->prepare('SELECT id FROM customers WHERE name = ?');
            $st->execute([$name]);
            $found = $st->fetchColumn();
            $id = $found !== false ? (int) $found : null;
        }
        if ($id !== null) {
            $this->pdo->prepare(
                'UPDATE customers SET name = ?, unit_price = ?, category = ?,
                 contact = COALESCE(?, contact), phone = COALESCE(?, phone),
                 contract_note = COALESCE(?, contract_note),
                 maliyet_birim = COALESCE(?, maliyet_birim),
                 tasima_sabit_gider = COALESCE(?, tasima_sabit_gider),
                 tasima_not = COALESCE(?, tasima_not) WHERE id = ?'
            )->execute([$name, $unitPrice, $category, $contact, $phone, $note,
                $maliyetBirim, $tasimaSabitGider, $tasimaNot, $id]);
            return $id;
        }
        $this->pdo->prepare(
            'INSERT INTO customers (name, unit_price, category, contact, phone, contract_note,
                 maliyet_birim, tasima_sabit_gider, tasima_not)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$name, $unitPrice, $category, $contact, $phone, $note,
            $maliyetBirim ?? 0.0, $tasimaSabitGider ?? 0.0, $tasimaNot]);
        return (int) $this->pdo->lastInsertId();
    }

    // ── Ay-bazlı fiyat (opus-017, reaktif) ────────────────────────
    // Tek çözüm noktası: her fiyat okuyan yer priceFor'dan geçer. Bir ayın fiyatını
    // değiştirince (setCustomerPrice) o ay production.amount da güncellenir → downstream
    // (ciro/analiz/cari/net) production.amount okuduğu için OTOMATİK ve DÜŞÜK-RİSK yansır.

    /**
     * Bir müşterinin o AY geçerli fiyatı: o ay > carry-forward (en yakın önceki ay) > current default.
     * @return array{unit_price:float,maliyet_birim:float,tasima_sabit_gider:float}
     */
    public function priceFor(int $customerId, string $ay): array
    {
        // 1) o ay tam eşleşme
        $st = $this->pdo->prepare(
            'SELECT unit_price, maliyet_birim, tasima_sabit_gider
             FROM customer_price WHERE customer_id = ? AND ay = ?'
        );
        $st->execute([$customerId, $ay]);
        if ($r = $st->fetch()) {
            return $this->priceRow($r);
        }
        // 2) carry-forward: en yakın ÖNCEKİ ay ('YYYY-MM' leksikografik sıralı)
        $st = $this->pdo->prepare(
            'SELECT unit_price, maliyet_birim, tasima_sabit_gider
             FROM customer_price WHERE customer_id = ? AND ay < ? ORDER BY ay DESC LIMIT 1'
        );
        $st->execute([$customerId, $ay]);
        if ($r = $st->fetch()) {
            return $this->priceRow($r);
        }
        // 3) current default (customers kartı)
        $c = $this->customer($customerId);
        return [
            'unit_price'         => $c ? (float) $c['unit_price'] : 0.0,
            'maliyet_birim'      => $c ? (float) ($c['maliyet_birim'] ?? 0) : 0.0,
            'tasima_sabit_gider' => $c ? (float) ($c['tasima_sabit_gider'] ?? 0) : 0.0,
        ];
    }

    /** @param array<string,mixed> $r */
    private function priceRow(array $r): array
    {
        return [
            'unit_price'         => (float) $r['unit_price'],
            'maliyet_birim'      => (float) $r['maliyet_birim'],
            'tasima_sabit_gider' => (float) $r['tasima_sabit_gider'],
        ];
    }

    /**
     * O ayın fiyatını düzenle (REAKTİF): customer_price upsert + o müşteri×o ay production
     * satırlarını GÜNCELLE (unit_price_snap = yeni satış/kişi fiyatı; amount = persons×yeni).
     * Böylece o ayın cirosu/analizi/carisi her yerde güncellenir; diğer aylar sabit kalır.
     * $maliyetBirim / $sabit null verilirse o ayın mevcut çözümünden (priceFor) korunur.
     */
    public function setCustomerPrice(
        int $customerId,
        string $ay,
        float $unitPrice,
        ?float $maliyetBirim = null,
        ?float $sabit = null
    ): void {
        $cur = $this->priceFor($customerId, $ay);
        $maliyet = $maliyetBirim ?? $cur['maliyet_birim'];
        $gider   = $sabit ?? $cur['tasima_sabit_gider'];

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $onConf = $driver === 'sqlite'
            ? 'ON CONFLICT(customer_id, ay) DO UPDATE SET
                 unit_price = excluded.unit_price, maliyet_birim = excluded.maliyet_birim,
                 tasima_sabit_gider = excluded.tasima_sabit_gider'
            : 'ON DUPLICATE KEY UPDATE
                 unit_price = VALUES(unit_price), maliyet_birim = VALUES(maliyet_birim),
                 tasima_sabit_gider = VALUES(tasima_sabit_gider)';

        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare(
                'INSERT INTO customer_price (customer_id, ay, unit_price, maliyet_birim, tasima_sabit_gider)
                 VALUES (?, ?, ?, ?, ?) ' . $onConf
            )->execute([$customerId, $ay, $unitPrice, $maliyet, $gider]);

            // O ay production satırlarını yeni fiyata çek (amount kaynak kalır → düşük risk).
            $this->pdo->prepare(
                'UPDATE production SET unit_price_snap = ?, amount = persons * ?
                 WHERE customer_id = ? AND substr(prod_date,1,7) = ?'
            )->execute([$unitPrice, $unitPrice, $customerId, $ay]);

            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * SEED (migrate_017 ile birebir, iki motorda da çalışır): production'ı olan her
     * müşteri×ay için customer_price'a MEVCUT baskın snapshot fiyatını yaz (idempotent).
     * Var olan (customer_id, ay) satırı ATLANIR → mevcut rakamlar DEĞİŞMEZ.
     * @return int eklenen satır sayısı
     */
    public function seedCustomerPricesFromProduction(): int
    {
        $sql =
            'INSERT INTO customer_price (customer_id, ay, unit_price, maliyet_birim, tasima_sabit_gider)
             SELECT g.customer_id, g.ay,
               (SELECT p2.unit_price_snap FROM production p2
                  WHERE p2.customer_id = g.customer_id AND substr(p2.prod_date,1,7) = g.ay
                  GROUP BY p2.unit_price_snap
                  ORDER BY SUM(p2.persons) DESC, p2.unit_price_snap DESC LIMIT 1),
               COALESCE(c.maliyet_birim, 0), COALESCE(c.tasima_sabit_gider, 0)
             FROM (SELECT DISTINCT customer_id, substr(prod_date,1,7) AS ay FROM production) g
             JOIN customers c ON c.id = g.customer_id
             WHERE NOT EXISTS (
               SELECT 1 FROM customer_price cp WHERE cp.customer_id = g.customer_id AND cp.ay = g.ay
             )';
        return (int) $this->pdo->exec($sql);
    }

    // ── Paraşüt cari (opus-012, SALT-OKUMA) ───────────────────────
    // Paraşüt (CANLI muhasebe) bakiyeleri YEREL senkron ile çekilir (tools/parasut_sync.php),
    // sonuç buraya (customers.parasut_*) yazılır. Kokpit'in kendi cari hesabını EZMEZ; yanında
    // "muhasebe referansı" olarak durur. VPS Paraşüt'e HİÇ çağrı yapmaz (cred yerelde).

    /**
     * Eşleştirme adayları: aktif (veya tüm) müşteriler + hâlihazır parasut_id.
     * customers'ta vergi no alanı YOK → tax_number boş gelir; eşleşme parasut_id > ad-normalize
     * ile yürür. (tax_number sütunu ileride eklenirse matchCustomerByTaxOrName otomatik kullanır.)
     * @return array<int,array{customer_id:int,name:string,parasut_id:string,tax_number:string}>
     */
    public function parasutCandidates(bool $activeOnly = true): array
    {
        $sql = 'SELECT id, name, parasut_id FROM customers';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $out = [];
        foreach ($this->pdo->query($sql)->fetchAll() as $r) {
            $out[] = [
                'customer_id' => (int) $r['id'],
                'name'        => (string) $r['name'],
                'parasut_id'  => (string) ($r['parasut_id'] ?? ''),
                'tax_number'  => '', // customers'ta yok; adaylara dışarıdan verilebilir (test/gelecek)
            ];
        }
        return $out;
    }

    /**
     * Paraşüt contact'ını Kokpit müşterisiyle eşleştir (PÜR — ağ yok, test edilebilir).
     * Öncelik: 1) parasut_id (zaten bağlı), 2) vergi no, 3) ad-normalize (Helpers::normalizeName).
     * Eşleşmezse customer_id null + reason 'eslesmedi' (oto müşteri OLUŞTURMA YOK — çağıran raporlar).
     * @param array{parasut_id?:string,tax_number?:string,name?:string} $needle
     * @param array<int,array{customer_id:int,name:string,parasut_id?:string,tax_number?:string}> $candidates
     * @return array{customer_id:?int,reason:string}
     */
    public function matchCustomerByTaxOrName(array $needle, array $candidates): array
    {
        $pid = trim((string) ($needle['parasut_id'] ?? ''));
        $tax = trim((string) ($needle['tax_number'] ?? ''));
        $nameNorm = Helpers::normalizeName((string) ($needle['name'] ?? ''));

        if ($pid !== '') {
            foreach ($candidates as $c) {
                if (trim((string) ($c['parasut_id'] ?? '')) === $pid) {
                    return ['customer_id' => (int) $c['customer_id'], 'reason' => 'parasut_id'];
                }
            }
        }
        if ($tax !== '') {
            foreach ($candidates as $c) {
                if (trim((string) ($c['tax_number'] ?? '')) === $tax) {
                    return ['customer_id' => (int) $c['customer_id'], 'reason' => 'tax_number'];
                }
            }
        }
        if ($nameNorm !== '') {
            foreach ($candidates as $c) {
                if (Helpers::normalizeName((string) ($c['name'] ?? '')) === $nameNorm) {
                    return ['customer_id' => (int) $c['customer_id'], 'reason' => 'name'];
                }
            }
        }
        return ['customer_id' => null, 'reason' => 'eslesmedi'];
    }

    /**
     * Paraşüt cari sonucunu müşteriye yaz (SALT senkron sonucu). parasut_id null verilirse
     * mevcut korunur (COALESCE). Bakiye + son senkron zamanı her zaman güncellenir.
     */
    public function setParasutInfo(int $customerId, ?string $parasutId, float $bakiye, string $syncAt): void
    {
        $this->pdo->prepare(
            'UPDATE customers SET parasut_id = COALESCE(?, parasut_id),
                 parasut_bakiye = ?, parasut_sync_at = ? WHERE id = ?'
        )->execute([$parasutId, $bakiye, $syncAt, $customerId]);
    }

    /** Paraşüt bakiyesi bağlı müşteriler (senkron durumu ekranı). */
    public function customersWithParasut(): array
    {
        return $this->pdo->query(
            'SELECT id, name, parasut_id, parasut_bakiye, parasut_sync_at
             FROM customers WHERE parasut_sync_at IS NOT NULL ORDER BY name'
        )->fetchAll();
    }

    /** Son Paraşüt senkron audit kaydı (parasut.php durum ekranı). */
    public function lastParasutSync(): ?array
    {
        $r = $this->pdo->query(
            "SELECT action, actor, detail, created_at FROM audit
             WHERE action = 'parasut_cari' ORDER BY id DESC LIMIT 1"
        )->fetch();
        return $r ?: null;
    }

    // ── Taşıma karlılık (opus-013: adet[production] × (satış − alış) − sabit gider) ─
    // Model: taşıma müşterisi KARTI (customers) = 4 alan →
    //   unit_price (satış birim fiyatı) · maliyet_birim (alış birim fiyatı) ·
    //   tasima_sabit_gider (aylık sabit gider, opsiyonel) · tasima_not (opsiyonel).
    // adet KARTTA YOK: adet = o müşterinin o ay production.persons TOPLAMI (Bugün sayımları).
    // tasima_aylik tablosu ARTIK KULLANILMIYOR (opus-011 modeli terk edildi, tablo atıl).

    /** Bir müşterinin o ay production.persons toplamı = taşıma adedi (Bugün sayımlarından). */
    public function monthProductionPersons(int $customerId, string $ay): float
    {
        $st = $this->pdo->prepare(
            'SELECT COALESCE(SUM(persons),0) FROM production
             WHERE customer_id = ? AND substr(prod_date,1,7) = ?'
        );
        $st->execute([$customerId, $ay]);
        return (float) $st->fetchColumn();
    }

    /**
     * Taşıma müşterisi aylık kâr (KESİN model, opus-013):
     *   adet  = o ay production.persons toplamı
     *   satis = customers.unit_price (satış birim fiyatı)
     *   alis  = customers.maliyet_birim (alış birim fiyatı)
     *   brut  = adet × (satis − alis)
     *   net   = brut − tasima_sabit_gider
     * @return array{adet,satis,alis,birim_satis,birim_alis,toplam_satis,toplam_alis,brut,sabit,sabit_gider,net,kar,note}
     */
    public function tasimaProfit(int $customerId, string $ay): array
    {
        $c = $this->customer($customerId);
        // opus-017: satış/alış/sabit o AY için priceFor'dan (ay-bazlı; current değil).
        $pr = $this->priceFor($customerId, $ay);
        $satis = $pr['unit_price'];
        $alis  = $pr['maliyet_birim'];
        $sabit = $pr['tasima_sabit_gider'];
        $note  = $c['tasima_not'] ?? null;
        $adet  = $this->monthProductionPersons($customerId, $ay);
        $brut  = $adet * ($satis - $alis);
        $net   = $brut - $sabit;
        return [
            'adet'         => $adet,
            'satis'        => $satis,
            'alis'         => $alis,
            'birim_satis'  => $satis,   // UI geriye dönük ad
            'birim_alis'   => $alis,
            'toplam_satis' => $adet * $satis,
            'toplam_alis'  => $adet * $alis,
            'brut'         => $brut,
            'sabit'        => $sabit,
            'sabit_gider'  => $sabit,   // UI geriye dönük ad
            'net'          => $net,
            'kar'          => $net,     // UI geriye dönük ad
            'note'         => $note,
        ];
    }

    /** Taşıma net kâr = adet×(satış−alış) − sabit gider. */
    public function tasimaKar(int $customerId, string $ay): float
    {
        return $this->tasimaProfit($customerId, $ay)['net'];
    }

    /**
     * Taşıma müşterisi aylar trendi (yeni→eski). adet = o ay production.persons;
     * satış/alış/sabit standing kart değerlerinden alınır.
     */
    public function customerMonthlyProfit(int $customerId): array
    {
        $c = $this->customer($customerId);
        if (!$c) {
            return [];
        }
        $st = $this->pdo->prepare(
            'SELECT substr(prod_date,1,7) AS ay, COALESCE(SUM(persons),0) AS adet
             FROM production WHERE customer_id = ?
             GROUP BY substr(prod_date,1,7) ORDER BY ay DESC'
        );
        $st->execute([$customerId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            // opus-017: her ay o ayın fiyatından (ay-bazlı).
            $pr = $this->priceFor($customerId, (string) $r['ay']);
            $satis = $pr['unit_price'];
            $alis  = $pr['maliyet_birim'];
            $sabit = $pr['tasima_sabit_gider'];
            $adet = (float) $r['adet'];
            $brut = $adet * ($satis - $alis);
            $out[] = [
                'ay'           => $r['ay'],
                'adet'         => $adet,
                'toplam_alis'  => $adet * $alis,
                'toplam_satis' => $adet * $satis,
                'brut'         => $brut,
                'net'          => $brut - $sabit,
            ];
        }
        return $out;
    }

    /**
     * Ayın tüm taşıma müşterileri toplamı (finans net + rapor için).
     * Sadece o ay adedi (production) olan müşteriler sayılır → sabit gider yalnızca
     * hizmet verilen ayda düşer (listelenen satırlarla tutarlı).
     * @return array{adet:float,alis:float,satis:float,gider:float,brut:float,net:float}
     */
    public function monthTasimaTotals(string $ay): array
    {
        $ids = $this->pdo->query("SELECT id FROM customers WHERE category = 'tasima'")
            ->fetchAll(PDO::FETCH_COLUMN);
        $tot = ['adet' => 0.0, 'alis' => 0.0, 'satis' => 0.0, 'gider' => 0.0, 'brut' => 0.0, 'net' => 0.0];
        foreach ($ids as $cid) {
            $p = $this->tasimaProfit((int) $cid, $ay);
            if ($p['adet'] <= 0) {
                continue; // bu ay hizmet/sayım yok → sabit gider de düşmez
            }
            $tot['adet']  += $p['adet'];
            $tot['alis']  += $p['toplam_alis'];
            $tot['satis'] += $p['toplam_satis'];
            $tot['gider'] += $p['sabit'];
            $tot['brut']  += $p['brut'];
            $tot['net']   += $p['net'];
        }
        return $tot;
    }

    // ── Üretim (Bugün) ────────────────────────────────────────
    /** Tek müşteri×gün×öğün üretim upsert. Tutar = kişi × birim fiyat snapshot. */
    public function upsertProduction(
        int $customerId,
        string $prodDate,
        int $persons,
        float $unitPrice,
        string $meal = 'ogle',
        string $enteredBy = 'uysa',
        ?int $orderId = null,
        ?string $note = null
    ): array {
        $amount = round($persons * $unitPrice, 2);
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $onConf = $driver === 'sqlite'
            ? 'ON CONFLICT(customer_id, prod_date, meal) DO UPDATE SET
                 persons = excluded.persons, unit_price_snap = excluded.unit_price_snap,
                 amount = excluded.amount, entered_by = excluded.entered_by,
                 order_id = excluded.order_id, note = excluded.note'
            : 'ON DUPLICATE KEY UPDATE
                 persons = VALUES(persons), unit_price_snap = VALUES(unit_price_snap),
                 amount = VALUES(amount), entered_by = VALUES(entered_by),
                 order_id = VALUES(order_id), note = VALUES(note)';
        $this->pdo->prepare(
            'INSERT INTO production
               (customer_id, prod_date, meal, persons, unit_price_snap, amount, order_id, note, entered_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ' . $onConf
        )->execute([$customerId, $prodDate, $meal, $persons, $unitPrice, $amount, $orderId, $note, $enteredBy]);

        return ['amount' => $amount, 'persons' => $persons, 'unit_price' => $unitPrice];
    }

    /** Belirli gün için müşteri × üretim (girilmeyenler NULL). */
    public function dayGrid(string $prodDate, string $meal = 'ogle'): array
    {
        $st = $this->pdo->prepare(
            'SELECT c.id AS customer_id, c.name, c.unit_price,
                    p.persons, p.amount, p.id AS prod_id
             FROM customers c
             LEFT JOIN production p
               ON p.customer_id = c.id AND p.prod_date = ? AND p.meal = ?
             WHERE c.is_active = 1
             ORDER BY c.name'
        );
        $st->execute([$prodDate, $meal]);
        return $st->fetchAll();
    }

    /** Bir önceki üretim günü (dünü kopyala kaynağı). */
    public function previousProductionDate(string $beforeDate, string $meal = 'ogle'): ?string
    {
        $st = $this->pdo->prepare(
            'SELECT MAX(prod_date) FROM production WHERE prod_date < ? AND meal = ?'
        );
        $st->execute([$beforeDate, $meal]);
        $d = $st->fetchColumn();
        return $d ?: null;
    }

    /** @return array<int,int> customer_id => persons (belirli gün) */
    public function productionPersonsByCustomer(string $prodDate, string $meal = 'ogle'): array
    {
        $st = $this->pdo->prepare(
            'SELECT customer_id, persons FROM production WHERE prod_date = ? AND meal = ?'
        );
        $st->execute([$prodDate, $meal]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['customer_id']] = (int) $r['persons'];
        }
        return $out;
    }

    public function deleteProduction(int $customerId, string $prodDate, string $meal = 'ogle'): void
    {
        $this->pdo->prepare('DELETE FROM production WHERE customer_id = ? AND prod_date = ? AND meal = ?')
            ->execute([$customerId, $prodDate, $meal]);
    }

    public function dayTotals(string $prodDate, string $meal = 'ogle'): array
    {
        $st = $this->pdo->prepare(
            'SELECT COALESCE(SUM(persons),0) AS persons, COALESCE(SUM(amount),0) AS amount, COUNT(*) AS cnt
             FROM production WHERE prod_date = ? AND meal = ?'
        );
        $st->execute([$prodDate, $meal]);
        return $st->fetch() ?: ['persons' => 0, 'amount' => 0, 'rows' => 0];
    }

    /** @return array<int,array> */
    public function activeSuppliers(): array
    {
        return $this->pdo->query('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name')->fetchAll();
    }

    public function addFile(string $filename, string $original, string $mime, int $size, ?string $by, string $category): int
    {
        $this->pdo->prepare(
            'INSERT INTO files (filename, original, mime, size_bytes, uploaded_by, category) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$filename, $original, $mime, $size, $by, $category]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Dosya kaydı (silinmemiş). Admin dosya servisi için (scope'suz). */
    public function fileById(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM files WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * IDOR SCOPE: bir dosya SADECE bu müşterinin kendi talep mesajına ekliyse döner.
     * Müşteri başka firmanın foto ekini (veya fatura fotosunu) ASLA indiremez.
     */
    public function customerFile(int $fileId, int $customerId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT f.* FROM files f
             JOIN request_messages rm ON rm.file_id = f.id
             JOIN requests r ON r.id = rm.request_id
             WHERE f.id = ? AND r.customer_id = ? AND f.deleted_at IS NULL LIMIT 1'
        );
        $st->execute([$fileId, $customerId]);
        return $st->fetch() ?: null;
    }

    // ── Finans ────────────────────────────────────────────────
    /**
     * Gelir/gider kaydı. opus-015: gider dağıtım hedefi.
     *   $allocType 'genel'  → rapor anında o ayki TÜM müşterilere ciro oranlı dağılır.
     *   $allocType 'musteri'→ $allocCustomerIds hedeflerine kendi ciroları oranlı dağılır.
     * Hedefler transaction_customer link tablosuna yazılır ('musteri' iken, boş değilse).
     */
    public function addTransaction(
        string $type,
        float $amount,
        string $txDate,
        ?string $category,
        ?string $desc,
        ?int $customerId = null,
        ?int $supplierId = null,
        ?int $fileId = null,
        string $allocType = 'genel',
        array $allocCustomerIds = []
    ): int {
        if (!in_array($allocType, ['genel', 'musteri'], true)) {
            $allocType = 'genel';
        }
        $ids = [];
        foreach (array_unique(array_map('intval', $allocCustomerIds)) as $cid) {
            if ($cid > 0) {
                $ids[] = $cid;
            }
        }
        if ($allocType === 'musteri' && !$ids) {
            $allocType = 'genel'; // hedef seçilmemiş → genele düş
        }
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare(
                'INSERT INTO transactions (type, category, tx_date, amount, customer_id, supplier_id, description, file_id, alloc_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$type, $category, $txDate, $amount, $customerId, $supplierId, $desc, $fileId, $allocType]);
            $txId = (int) $this->pdo->lastInsertId();
            if ($allocType === 'musteri' && $ids) {
                $ins = $this->pdo->prepare(
                    'INSERT INTO transaction_customer (transaction_id, customer_id) VALUES (?, ?)'
                );
                foreach ($ids as $cid) {
                    $ins->execute([$txId, $cid]);
                }
            }
            if ($own) {
                $this->pdo->commit();
            }
            return $txId;
        } catch (\Throwable $e) {
            if ($own) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<int,int> gider tx için hedef customer_id listesi (alloc_type='musteri'). */
    public function transactionTargets(int $transactionId): array
    {
        $st = $this->pdo->prepare('SELECT customer_id FROM transaction_customer WHERE transaction_id = ?');
        $st->execute([$transactionId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int,array> ay = 'YYYY-MM' */
    public function transactionsForMonth(string $month): array
    {
        $st = $this->pdo->prepare(
            "SELECT t.*, c.name AS customer_name, s.name AS supplier_name
             FROM transactions t
             LEFT JOIN customers c ON c.id = t.customer_id
             LEFT JOIN suppliers s ON s.id = t.supplier_id
             WHERE substr(t.tx_date,1,7) = ?
             ORDER BY t.tx_date DESC, t.id DESC"
        );
        $st->execute([$month]);
        return $st->fetchAll();
    }

    public function monthFinanceTotals(string $month): array
    {
        $st = $this->pdo->prepare(
            "SELECT type, COALESCE(SUM(amount),0) AS total
             FROM transactions WHERE substr(tx_date,1,7) = ? GROUP BY type"
        );
        $st->execute([$month]);
        $out = ['gelir' => 0.0, 'gider' => 0.0];
        foreach ($st->fetchAll() as $r) {
            $out[$r['type']] = (float) $r['total'];
        }
        $out['net'] = $out['gelir'] - $out['gider'];
        return $out;
    }

    // ── Cari ──────────────────────────────────────────────────
    public function addCari(string $partyType, int $partyId, string $entryDate, string $direction, float $amount, ?string $note = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO cari_entries (party_type, party_id, entry_date, direction, amount, note)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$partyType, $partyId, $entryDate, $direction, $amount, $note]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Müşteri bakiyesi: borç (bize borcu) − alacak (tahsilat). Pozitif = müşteri bize borçlu. */
    public function customerBalance(int $customerId): float
    {
        $st = $this->pdo->prepare(
            "SELECT
               COALESCE(SUM(CASE WHEN direction='borc' THEN amount ELSE 0 END),0)
             - COALESCE(SUM(CASE WHEN direction='alacak' THEN amount ELSE 0 END),0)
             FROM cari_entries WHERE party_type='customer' AND party_id = ?"
        );
        $st->execute([$customerId]);
        return (float) $st->fetchColumn();
    }

    public function customerStatement(int $customerId, string $month): array
    {
        $st = $this->pdo->prepare(
            "SELECT entry_date, direction, amount, note
             FROM cari_entries
             WHERE party_type='customer' AND party_id = ? AND substr(entry_date,1,7) = ?
             ORDER BY entry_date, id"
        );
        $st->execute([$customerId, $month]);
        return $st->fetchAll();
    }

    /** Bir müşterinin belirli aydaki üretimi (kişi + tutar). F2 fatura akışının girdisi. */
    public function customerMonthProduction(int $customerId, string $month): array
    {
        $st = $this->pdo->prepare(
            "SELECT COALESCE(SUM(persons),0) AS persons, COALESCE(SUM(amount),0) AS amount, COUNT(*) AS cnt
             FROM production WHERE customer_id = ? AND substr(prod_date,1,7) = ?"
        );
        $st->execute([$customerId, $month]);
        $r = $st->fetch();
        return ['persons' => (int) $r['persons'], 'amount' => (float) $r['amount'], 'cnt' => (int) $r['cnt']];
    }

    // ── Müşteri giriş hesapları (customer_users) — admin yönetimi (opus-018) ──
    /**
     * Müşteri giriş hesabı oluştur (bcrypt). Kullanıcı adı UNIQUE — çakışırsa exception yerine
     * 0 döner (çağıran "kullanıcı adı alınmış" gösterir). customerId geçerli olmalı.
     * @return int yeni id, veya kullanıcı adı çakıştıysa 0.
     */
    public function createCustomerUser(int $customerId, string $username, string $password, ?string $displayName = null): int
    {
        $username = trim($username);
        $exists = $this->pdo->prepare('SELECT 1 FROM customer_users WHERE username = ?');
        $exists->execute([$username]);
        if ($exists->fetchColumn() !== false) {
            return 0;
        }
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->pdo->prepare(
            'INSERT INTO customer_users (customer_id, username, password_bcrypt, display_name, role)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$customerId, $username, $hash, $displayName, 'owner']);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<int,array> müşteri giriş hesapları + bağlı firma adı/aktifliği (admin listesi). */
    public function listCustomerUsers(): array
    {
        return $this->pdo->query(
            'SELECT cu.id, cu.username, cu.display_name, cu.is_active, cu.last_login,
                    cu.customer_id, c.name AS customer_name, c.is_active AS customer_active
             FROM customer_users cu JOIN customers c ON c.id = cu.customer_id
             ORDER BY c.name, cu.username'
        )->fetchAll();
    }

    /** Şifre sıfırla (bcrypt). */
    public function resetCustomerUserPassword(int $id, string $password): void
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->pdo->prepare('UPDATE customer_users SET password_bcrypt = ? WHERE id = ?')
            ->execute([$hash, $id]);
    }

    /** Giriş hesabını aktif/pasif yap (silme YOK). */
    public function setCustomerUserActive(int $id, bool $active): void
    {
        $this->pdo->prepare('UPDATE customer_users SET is_active = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, $id]);
    }

    // ── Siparişler (M6 müşteri uygulaması) ────────────────────
    /**
     * Müşteri×gün×öğün sipariş upsert (UNIQUE customer_id,order_date,meal). Sipariş = onay öncesi.
     * customerId ZORUNLU parametre; çağıran daima oturumdaki customer_id'yi geçer (IDOR koruması).
     */
    public function upsertOrder(
        int $customerId,
        string $orderDate,
        string $meal,
        int $persons,
        string $status = 'gonderildi',
        string $enteredBy = 'musteri',
        ?string $menuType = null,
        ?string $note = null
    ): int {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $onConf = $driver === 'sqlite'
            ? 'ON CONFLICT(customer_id, order_date, meal) DO UPDATE SET
                 persons = excluded.persons, status = excluded.status,
                 entered_by = excluded.entered_by, menu_type = excluded.menu_type, note = excluded.note'
            : 'ON DUPLICATE KEY UPDATE
                 persons = VALUES(persons), status = VALUES(status),
                 entered_by = VALUES(entered_by), menu_type = VALUES(menu_type), note = VALUES(note)';
        $this->pdo->prepare(
            'INSERT INTO orders (customer_id, order_date, meal, persons, menu_type, status, entered_by, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?) ' . $onConf
        )->execute([$customerId, $orderDate, $meal, $persons, $menuType, $status, $enteredBy, $note]);

        $st = $this->pdo->prepare('SELECT id FROM orders WHERE customer_id = ? AND order_date = ? AND meal = ?');
        $st->execute([$customerId, $orderDate, $meal]);
        return (int) $st->fetchColumn();
    }

    /** @return array<int,array> müşteri kapsamlı sipariş geçmişi (yeni→eski). */
    public function customerOrders(int $customerId, int $limit = 30): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM orders WHERE customer_id = ?
             ORDER BY order_date DESC, meal ASC LIMIT ' . (int) $limit
        );
        $st->execute([$customerId]);
        return $st->fetchAll();
    }

    /** Tek sipariş — customer_id ile scope'lu (başka müşterininki NULL döner). */
    public function customerOrder(int $customerId, string $orderDate, string $meal): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM orders WHERE customer_id = ? AND order_date = ? AND meal = ?'
        );
        $st->execute([$customerId, $orderDate, $meal]);
        return $st->fetch() ?: null;
    }

    /** Admin: sipariş id ile (scope'suz — onay kuyruğu içindir). */
    public function orderById(int $orderId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $st->execute([$orderId]);
        return $st->fetch() ?: null;
    }

    // ── Admin: onay kuyruğu (müşteri girişleri) ───────────────
    /** status=gonderildi siparişler (müşteri/bot). $date verilirse o güne filtreli. */
    public function pendingOrders(?string $date = null): array
    {
        $sql = "SELECT o.*, c.name AS customer_name, c.unit_price
                FROM orders o JOIN customers c ON c.id = o.customer_id
                WHERE o.status = 'gonderildi'";
        $params = [];
        if ($date !== null) {
            $sql .= ' AND o.order_date = ?';
            $params[] = $date;
        }
        $sql .= ' ORDER BY o.order_date ASC, c.name ASC';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function pendingOrdersCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'gonderildi'")->fetchColumn();
    }

    /** Admin geçmişi: karar verilmiş siparişler (onaylandı/reddedildi, yeni→eski). */
    public function decidedOrders(int $limit = 20): array
    {
        return $this->pdo->query(
            "SELECT o.*, c.name AS customer_name, c.unit_price
             FROM orders o JOIN customers c ON c.id = o.customer_id
             WHERE o.status IN ('onaylandi','reddedildi')
             ORDER BY o.order_date DESC, o.id DESC LIMIT " . (int) $limit
        )->fetchAll();
    }

    /**
     * Sipariş onayı → production'a yaz (fiyat snapshot) + order.status=onaylandi.
     * Tek yön: production UNIQUE(customer,date,meal) sayesinde tekrar onay duplicate üretmez.
     * @return array|null ['amount','persons','customer_id'] veya order yoksa null.
     */
    public function approveOrder(int $orderId): ?array
    {
        $o = $this->orderById($orderId);
        if (!$o) {
            return null;
        }
        $cust = $this->customer((int) $o['customer_id']);
        if (!$cust) {
            return null;
        }
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            // opus-017: o ayın fiyatı (customer_price o ay > carry-forward > current default).
            $price = $this->priceFor((int) $o['customer_id'], substr((string) $o['order_date'], 0, 7))['unit_price'];
            $res = $this->upsertProduction(
                (int) $o['customer_id'],
                $o['order_date'],
                (int) $o['persons'],
                $price,
                $o['meal'],
                $o['entered_by'] ?: 'musteri',
                $orderId,
                $o['note'] ?? null
            );
            $this->pdo->prepare("UPDATE orders SET status = 'onaylandi' WHERE id = ?")->execute([$orderId]);
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return ['amount' => $res['amount'], 'persons' => (int) $o['persons'], 'customer_id' => (int) $o['customer_id']];
    }

    public function rejectOrder(int $orderId): bool
    {
        $o = $this->orderById($orderId);
        if (!$o) {
            return false;
        }
        $this->pdo->prepare("UPDATE orders SET status = 'reddedildi' WHERE id = ?")->execute([$orderId]);
        return true;
    }

    /** Geçerli talep türleri (opus-019: menu + oneri eklendi). */
    public const REQUEST_TYPES = ['talep', 'sikayet', 'mesaj', 'menu', 'oneri'];

    // ── Talepler / mesajlaşma (M6) ────────────────────────────
    public function createRequest(int $customerId, ?int $customerUserId, string $type, string $subject): int
    {
        if (!in_array($type, self::REQUEST_TYPES, true)) {
            $type = 'talep';
        }
        $this->pdo->prepare(
            'INSERT INTO requests (customer_id, customer_user_id, type, subject) VALUES (?, ?, ?, ?)'
        )->execute([$customerId, $customerUserId, $type, $subject]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Talebe mesaj ekle (opus-019: opsiyonel foto eki $fileId). */
    public function addRequestMessage(int $requestId, string $sender, string $body, ?int $fileId = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO request_messages (request_id, sender, body, file_id) VALUES (?, ?, ?, ?)'
        )->execute([$requestId, $sender, $body, $fileId]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * opus-019: sipariş varsayılanı — müşterinin en son (verilen tarihten ÖNCE) girdiği kişi sayısı.
     * Onaylı production ÖNCELİKLİ; yoksa gönderdiği sipariş. Meal verilirse o öğüne kısıtlar.
     * Müşteri sayı değiştirmezse "önceki günkü sayı" aynen devam eder. Bulunamazsa 0.
     */
    public function lastPersonsFor(int $customerId, ?string $meal = null, ?string $beforeDate = null): int
    {
        $params = [$customerId];
        $prodCond = '';
        $ordCond = '';
        if ($meal !== null) {
            $prodCond .= ' AND meal = ?';
            $params[] = $meal;
        }
        if ($beforeDate !== null) {
            $prodCond .= ' AND prod_date < ?';
            $params[] = $beforeDate;
        }
        $params2 = [$customerId];
        if ($meal !== null) {
            $ordCond .= ' AND meal = ?';
            $params2[] = $meal;
        }
        if ($beforeDate !== null) {
            $ordCond .= ' AND order_date < ?';
            $params2[] = $beforeDate;
        }
        $sql = 'SELECT persons FROM (
                  SELECT prod_date AS d, persons, 1 AS pr FROM production WHERE customer_id = ?' . $prodCond . '
                  UNION ALL
                  SELECT order_date AS d, persons, 0 AS pr FROM orders
                    WHERE customer_id = ?' . $ordCond . " AND status <> 'reddedildi'
                ) t
                WHERE persons > 0
                ORDER BY d DESC, pr DESC LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute(array_merge($params, $params2));
        $val = $st->fetchColumn();
        return $val === false ? 0 : (int) $val;
    }

    /** @return array<int,array> müşteri kapsamlı talep listesi. */
    public function customerRequests(int $customerId): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM requests WHERE customer_id = ? ORDER BY created_at DESC, id DESC'
        );
        $st->execute([$customerId]);
        return $st->fetchAll();
    }

    /** IDOR guard: talep SADECE sahibi müşteriye döner; başka müşterinin talebi → null. */
    public function requestForCustomer(int $requestId, int $customerId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM requests WHERE id = ? AND customer_id = ?');
        $st->execute([$requestId, $customerId]);
        return $st->fetch() ?: null;
    }

    /** Admin: talep id ile (scope'suz). */
    public function requestById(int $requestId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT r.*, c.name AS customer_name FROM requests r
             JOIN customers c ON c.id = r.customer_id WHERE r.id = ?'
        );
        $st->execute([$requestId]);
        return $st->fetch() ?: null;
    }

    /** @return array<int,array> talep mesajları (eski→yeni) + foto eki bilgisi (file_name/file_mime/file_orig). */
    public function requestMessages(int $requestId): array
    {
        $st = $this->pdo->prepare(
            'SELECT rm.*, f.filename AS file_name, f.mime AS file_mime, f.original AS file_orig
             FROM request_messages rm
             LEFT JOIN files f ON f.id = rm.file_id AND f.deleted_at IS NULL
             WHERE rm.request_id = ? ORDER BY rm.created_at ASC, rm.id ASC'
        );
        $st->execute([$requestId]);
        return $st->fetchAll();
    }

    public function setRequestStatus(int $requestId, string $status): void
    {
        $this->pdo->prepare('UPDATE requests SET status = ? WHERE id = ?')->execute([$status, $requestId]);
    }

    /** Admin: tüm açık talepler (müşteri adıyla). */
    public function openRequests(): array
    {
        return $this->pdo->query(
            "SELECT r.*, c.name AS customer_name FROM requests r
             JOIN customers c ON c.id = r.customer_id
             WHERE r.status = 'acik' ORDER BY r.created_at DESC, r.id DESC"
        )->fetchAll();
    }

    public function openRequestsCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'acik'")->fetchColumn();
    }

    /**
     * Admin: tüm talepler — tip/durum/müşteri filtreli (opus-019).
     * $filter: ['type'=>..., 'status'=>..., 'customer_id'=>int]. Boş → hepsi. Son mesaj sayısı + tarihi dahil.
     * @return array<int,array>
     */
    public function allRequests(array $filter = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filter['type']) && in_array($filter['type'], self::REQUEST_TYPES, true)) {
            $where[] = 'r.type = ?';
            $params[] = $filter['type'];
        }
        if (!empty($filter['status']) && in_array($filter['status'], ['acik', 'cozuldu'], true)) {
            $where[] = 'r.status = ?';
            $params[] = $filter['status'];
        }
        if (!empty($filter['customer_id'])) {
            $where[] = 'r.customer_id = ?';
            $params[] = (int) $filter['customer_id'];
        }
        $sql = "SELECT r.*, c.name AS customer_name,
                       (SELECT COUNT(*) FROM request_messages rm WHERE rm.request_id = r.id) AS msg_count,
                       (SELECT MAX(rm2.created_at) FROM request_messages rm2 WHERE rm2.request_id = r.id) AS last_msg_at
                FROM requests r JOIN customers c ON c.id = r.customer_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY r.status ASC, r.created_at DESC, r.id DESC';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Admin: talebe UYSA cevabı yaz (+opsiyonel foto) → talebi 'acik' yapar. @return mesaj id */
    public function replyRequest(int $requestId, string $body, ?int $fileId = null): int
    {
        $id = $this->addRequestMessage($requestId, 'uysa', $body, $fileId);
        $this->setRequestStatus($requestId, 'acik');
        return $id;
    }

    /**
     * Ay kapanışı kontrol özeti: veri yazmaz, mevcut modüllerden okur.
     * @return array{ay:string,status:string,summary:array,checks:array<int,array>,negative_customers:array<int,array>,no_production_customers:array<int,array>,zero_price_rows:array<int,array>,fatura:array}
     */
    public function ayKapanis(string $ay): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
            $ay = date('Y-m');
        }

        $ka = $this->karAnalizi($ay);
        $nk = $this->netKarlilik($ay);
        $fin = $this->monthFinanceTotals($ay);
        $kidem = $this->kidemToplamYukumluluk($ay);

        $st = $this->pdo->prepare(
            'SELECT COUNT(*) AS satir, COUNT(DISTINCT prod_date) AS gun_sayisi,
                    COALESCE(SUM(persons),0) AS kisi, COALESCE(SUM(amount),0) AS tutar
             FROM production WHERE substr(prod_date,1,7) = ?'
        );
        $st->execute([$ay]);
        $prodSummary = $st->fetch() ?: ['satir' => 0, 'gun_sayisi' => 0, 'kisi' => 0, 'tutar' => 0];

        $st = $this->pdo->prepare(
            'SELECT c.id AS customer_id, c.name, p.prod_date, p.meal, p.persons, p.unit_price_snap, p.amount
             FROM production p JOIN customers c ON c.id = p.customer_id
             WHERE substr(p.prod_date,1,7) = ? AND p.persons > 0
               AND (p.unit_price_snap <= 0 OR p.amount <= 0)
             ORDER BY p.prod_date ASC, c.name ASC LIMIT 20'
        );
        $st->execute([$ay]);
        $zeroPriceRows = $st->fetchAll();

        $st = $this->pdo->prepare(
            "SELECT COUNT(*) AS adet,
                    COALESCE(SUM(ara_toplam),0) AS ara_toplam,
                    COALESCE(SUM(genel_toplam),0) AS genel_toplam,
                    COALESCE(SUM(CASE WHEN durum = 'kesildi' THEN 1 ELSE 0 END),0) AS kesildi
             FROM fatura WHERE ay = ?"
        );
        $st->execute([$ay]);
        $fatura = $st->fetch() ?: ['adet' => 0, 'ara_toplam' => 0, 'genel_toplam' => 0, 'kesildi' => 0];

        $st = $this->pdo->prepare('SELECT COUNT(*) FROM transactions WHERE substr(tx_date,1,7) = ?');
        $st->execute([$ay]);
        $txCount = (int) $st->fetchColumn();

        $prodBy = [];
        foreach ($this->monthProductionByCustomer($ay) as $r) {
            $prodBy[(int) $r['customer_id']] = $r;
        }

        $noProductionCustomers = [];
        $priceIssues = [];
        foreach ($this->activeCustomers() as $c) {
            $cid = (int) $c['id'];
            if (!isset($prodBy[$cid])) {
                $noProductionCustomers[] = [
                    'customer_id' => $cid,
                    'name' => (string) $c['name'],
                    'category' => (string) ($c['category'] ?? 'uretim'),
                ];
                continue;
            }
            $pr = $this->priceFor($cid, $ay);
            if (($c['category'] ?? 'uretim') === 'tasima') {
                if ($pr['unit_price'] <= 0 || $pr['maliyet_birim'] <= 0) {
                    $priceIssues[] = ['customer_id' => $cid, 'name' => (string) $c['name'], 'category' => 'tasima'];
                }
            } elseif ($pr['unit_price'] <= 0) {
                $priceIssues[] = ['customer_id' => $cid, 'name' => (string) $c['name'], 'category' => 'uretim'];
            }
        }

        $negativeCustomers = [];
        foreach (array_merge($ka['uretim']['rows'], $ka['tasima']['rows']) as $r) {
            if ((float) $r['net'] < 0) {
                $negativeCustomers[] = $r;
            }
        }
        usort($negativeCustomers, static fn($a, $b) => (float) $a['net'] <=> (float) $b['net']);

        $personelCount = count($this->listPersonel());
        $netDiff = abs((float) $nk['net'] - (float) $ka['toplam_net']);

        $checks = [];
        $add = static function (string $key, string $label, string $status, string $detail, string $link = '') use (&$checks): void {
            $checks[] = compact('key', 'label', 'status', 'detail', 'link');
        };

        $add(
            'production',
            'Üretim sayımları',
            (int) $prodSummary['satir'] > 0 ? 'ok' : 'warn',
            (int) $prodSummary['satir'] > 0
                ? (int) $prodSummary['gun_sayisi'] . ' gün, ' . number_format((float) $prodSummary['kisi'], 0, ',', '.') . ' kişi girilmiş.'
                : 'Bu ay üretim kaydı yok.',
            'bugun.php?date=' . $ay . '-01'
        );
        $add(
            'zero_price',
            'Sıfır fiyat / tutar',
            (!$zeroPriceRows && !$priceIssues) ? 'ok' : 'fail',
            (!$zeroPriceRows && !$priceIssues) ? 'Fiyatı/tutarı sıfır görünen üretim yok.' : (count($zeroPriceRows) + count($priceIssues)) . ' kayıt/müşteri kontrol istiyor.',
            'musteriler.php'
        );
        $add(
            'no_production',
            'Aktif müşteri sayımı',
            !$noProductionCustomers ? 'ok' : 'warn',
            !$noProductionCustomers ? 'Aktif müşterilerin tamamında bu ay kayıt var.' : count($noProductionCustomers) . ' aktif müşteride bu ay kayıt yok.',
            'bugun.php?date=' . $ay . '-01'
        );
        $add(
            'allocation',
            'Gider/personel dağıtımı',
            (float) $ka['dagitilmamis'] <= 0.01 ? 'ok' : 'warn',
            (float) $ka['dagitilmamis'] <= 0.01 ? 'Dağıtılmamış gider/personel payı yok.' : 'Dağıtılmamış pay: ₺ ' . Helpers::money((float) $ka['dagitilmamis']),
            'finans.php?ay=' . $ay
        );
        $add(
            'personel',
            'Personel maliyeti',
            ((float) $nk['personel'] > 0 || $personelCount === 0) ? 'ok' : 'warn',
            (float) $nk['personel'] > 0 ? 'Bu ay yüklü personel maliyeti: ₺ ' . Helpers::money((float) $nk['personel']) : 'Aktif personel var ama bu ay personel maliyeti sıfır görünüyor.',
            'personel.php?ay=' . $ay
        );
        $add(
            'negative_customers',
            'Negatif müşteri kârı',
            !$negativeCustomers ? 'ok' : 'warn',
            !$negativeCustomers ? 'Negatif net kâr veren müşteri yok.' : count($negativeCustomers) . ' müşteri negatifte.',
            'kar-analizi.php?ay=' . $ay
        );
        $add(
            'invoice',
            'Fatura durumu',
            (int) $fatura['adet'] > 0 ? 'ok' : 'warn',
            (int) $fatura['adet'] > 0 ? (int) $fatura['adet'] . ' fatura kaydı, ' . (int) $fatura['kesildi'] . ' kesildi.' : 'Bu ay fatura kaydı yok.',
            'faturalar.php?ay=' . $ay
        );
        $add(
            'net_match',
            'Kâr hesabı tutarlılığı',
            $netDiff <= 0.05 ? 'ok' : 'fail',
            $netDiff <= 0.05 ? 'Finans net kârlılık ile Kâr Analizi birebir.' : 'Net fark: ₺ ' . Helpers::money($netDiff),
            'kar-analizi.php?ay=' . $ay
        );

        $status = 'ok';
        foreach ($checks as $c) {
            if ($c['status'] === 'fail') {
                $status = 'fail';
                break;
            }
            if ($c['status'] === 'warn') {
                $status = 'warn';
            }
        }

        return [
            'ay' => $ay,
            'status' => $status,
            'summary' => [
                'production_days' => (int) $prodSummary['gun_sayisi'],
                'production_rows' => (int) $prodSummary['satir'],
                'persons' => (float) $prodSummary['kisi'],
                'production_amount' => (float) $prodSummary['tutar'],
                'toplam_gelir' => (float) $ka['toplam_gelir'],
                'toplam_net' => (float) $ka['toplam_net'],
                'toplam_marj' => (float) $ka['toplam_marj'],
                'nakit_net' => (float) $fin['net'],
                'transaction_count' => $txCount,
                'personel' => (float) $nk['personel'],
                'kidem_birikim' => (float) ($kidem['birikim'] ?? 0),
                'warning_count' => count(array_filter($checks, static fn($c) => $c['status'] !== 'ok')),
            ],
            'checks' => $checks,
            'negative_customers' => $negativeCustomers,
            'no_production_customers' => $noProductionCustomers,
            'zero_price_rows' => $zeroPriceRows,
            'price_issues' => $priceIssues,
            'fatura' => [
                'adet' => (int) $fatura['adet'],
                'kesildi' => (int) $fatura['kesildi'],
                'ara_toplam' => (float) $fatura['ara_toplam'],
                'genel_toplam' => (float) $fatura['genel_toplam'],
            ],
        ];
    }
    // ── Yayınlanan menü (M6, salt-gösterim) ───────────────────
    /** @return array<int,array> yayınlanan menü günleri (recipe adıyla). Boşsa []. */
    public function publishedMenu(string $from, string $to, string $meal = 'ogle'): array
    {
        $st = $this->pdo->prepare(
            "SELECT md.menu_date, md.menu_type, r.name AS recipe_name
             FROM menu_days md LEFT JOIN recipes r ON r.id = md.recipe_id
             WHERE md.is_published = 1 AND md.meal = ? AND md.menu_date BETWEEN ? AND ?
             ORDER BY md.menu_date ASC, r.name ASC"
        );
        $st->execute([$meal, $from, $to]);
        return $st->fetchAll();
    }

    // ── Müşteri cari ekstresi (production + tahsilat birleşik, scope'lu) ─
    /**
     * Müşterinin aylık ekstresi: üretim günleri (borç) + cari tahsilat/düzeltme (alacak).
     * SADECE verilen customerId — IDOR koruması. Gösterim amaçlı (bakiye customerBalance'tan).
     * @return array<int,array> ['entry_date','label','amount','kind']
     */
    public function customerLedger(int $customerId, string $month): array
    {
        $st = $this->pdo->prepare(
            "SELECT prod_date AS entry_date, persons, amount FROM production
             WHERE customer_id = ? AND substr(prod_date,1,7) = ?"
        );
        $st->execute([$customerId, $month]);
        $rows = [];
        foreach ($st->fetchAll() as $p) {
            $rows[] = [
                'entry_date' => $p['entry_date'],
                'label'      => (int) $p['persons'] . ' kişi',
                'amount'     => (float) $p['amount'],
                'kind'       => 'borc',
            ];
        }
        $st = $this->pdo->prepare(
            "SELECT entry_date, direction, amount, note FROM cari_entries
             WHERE party_type = 'customer' AND party_id = ? AND substr(entry_date,1,7) = ?"
        );
        $st->execute([$customerId, $month]);
        foreach ($st->fetchAll() as $c) {
            $rows[] = [
                'entry_date' => $c['entry_date'],
                'label'      => $c['note'] ?: ($c['direction'] === 'alacak' ? 'Tahsilat' : 'Üretim'),
                'amount'     => (float) $c['amount'],
                'kind'       => $c['direction'],
            ];
        }
        usort($rows, static fn($a, $b) => strcmp($b['entry_date'], $a['entry_date']));
        return $rows;
    }

    // ── Rapor / Özet ──────────────────────────────────────────
    public function monthProductionByCustomer(string $month, ?string $category = null): array
    {
        $sql = "SELECT c.id AS customer_id, c.name, c.category,
                    COALESCE(SUM(p.persons),0) AS persons, COALESCE(SUM(p.amount),0) AS ciro,
                    COUNT(p.id) AS gun
             FROM customers c
             LEFT JOIN production p ON p.customer_id = c.id AND substr(p.prod_date,1,7) = ?";
        $params = [$month];
        if ($category !== null) {
            $sql .= ' WHERE c.category = ?';
            $params[] = $category;
        }
        $sql .= " GROUP BY c.id, c.name, c.category HAVING gun > 0 ORDER BY ciro DESC";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /**
     * Drill-down: bir üretim müşterisinin belirli ay GÜN GÜN öğün kırılımı.
     * SADECE verilen customerId (IDOR/scope koruması). satır=gün, öğle/akşam/kumanya + toplam.
     * @return array<int,array> ['gun','ogle','aksam','kumanya','diger','kisi','tutar']
     */
    public function customerDailyGrid(int $customerId, string $ay): array
    {
        $st = $this->pdo->prepare(
            "SELECT prod_date, meal, persons, amount FROM production
             WHERE customer_id = ? AND substr(prod_date,1,7) = ?
             ORDER BY prod_date ASC, meal ASC"
        );
        $st->execute([$customerId, $ay]);
        $days = [];
        foreach ($st->fetchAll() as $r) {
            $d = $r['prod_date'];
            if (!isset($days[$d])) {
                $days[$d] = ['gun' => $d, 'ogle' => 0, 'aksam' => 0, 'kumanya' => 0, 'diger' => 0, 'kisi' => 0, 'tutar' => 0.0];
            }
            $meal = $r['meal'];
            $p = (int) $r['persons'];
            if (isset($days[$d][$meal])) {
                $days[$d][$meal] += $p;
            } else {
                $days[$d]['diger'] += $p;
            }
            $days[$d]['kisi'] += $p;
            $days[$d]['tutar'] += (float) $r['amount'];
        }
        return array_values($days);
    }

    // ── Reçete & Maliyet (M4) ─────────────────────────────────
    /** Malzeme listesi (ad, birim, kg/birim fiyat, kritik eşik). $search verilirse ada göre LIKE filtre. */
    public function listIngredients(?string $search = null): array
    {
        $sql = 'SELECT id, name, unit, price_per_unit, min_stok FROM ingredients';
        $params = [];
        if ($search !== null && $search !== '') {
            $sql .= ' WHERE name LIKE ?';
            $params[] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY name';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function ingredient(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, name, unit, price_per_unit, min_stok FROM ingredients WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Malzeme birim fiyatını güncelle (reçete maliyeti anında değişir). */
    public function upsertIngredientPrice(int $id, float $price): void
    {
        $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
        $this->pdo->prepare("UPDATE ingredients SET price_per_unit = ?, updated_at = $now WHERE id = ?")
            ->execute([$price, $id]);
    }

    /** Malzeme kritik stok eşiğini güncelle (0 = uyarı yok). */
    public function setIngredientMinStock(int $id, float $min): void
    {
        $this->pdo->prepare('UPDATE ingredients SET min_stok = ? WHERE id = ?')->execute([$min, $id]);
    }

    /**
     * Reçete listesi + porsiyon maliyeti (tek grup sorgu — N+1 yok).
     * cost = Σ(gram/1000 × price_per_unit). $search ada göre LIKE filtre.
     * @return array<int,array> ['id','name','category','item_count','cost']
     */
    public function listRecipes(?string $search = null): array
    {
        $sql = 'SELECT r.id, r.name, r.category,
                       COUNT(ri.id) AS item_count,
                       COALESCE(SUM(ri.grams / 1000.0 * i.price_per_unit), 0) AS cost
                FROM recipes r
                LEFT JOIN recipe_items ri ON ri.recipe_id = r.id
                LEFT JOIN ingredients i ON i.id = ri.ingredient_id';
        $params = [];
        if ($search !== null && $search !== '') {
            $sql .= ' WHERE r.name LIKE ?';
            $params[] = '%' . $search . '%';
        }
        $sql .= ' GROUP BY r.id, r.name, r.category ORDER BY r.name';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function recipe(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, name, category, portion_note FROM recipes WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Bir reçetenin malzeme×gramaj kalemleri + satır maliyeti.
     * @return array<int,array> ['item_id','ingredient_id','name','unit','grams','price_per_unit','line_cost']
     */
    public function recipeItems(int $recipeId): array
    {
        $st = $this->pdo->prepare(
            'SELECT ri.id AS item_id, ri.ingredient_id, i.name, i.unit, ri.grams, i.price_per_unit,
                    (ri.grams / 1000.0 * i.price_per_unit) AS line_cost
             FROM recipe_items ri JOIN ingredients i ON i.id = ri.ingredient_id
             WHERE ri.recipe_id = ? ORDER BY i.name'
        );
        $st->execute([$recipeId]);
        return $st->fetchAll();
    }

    /** Porsiyon maliyeti = Σ(gram/1000 × birim fiyat). */
    public function recipeCost(int $recipeId): float
    {
        $st = $this->pdo->prepare(
            'SELECT COALESCE(SUM(ri.grams / 1000.0 * i.price_per_unit), 0)
             FROM recipe_items ri JOIN ingredients i ON i.id = ri.ingredient_id
             WHERE ri.recipe_id = ?'
        );
        $st->execute([$recipeId]);
        return (float) $st->fetchColumn();
    }

    /** Reçete ekle/düzenle (ada göre benzersiz). $id verilirse günceller. */
    public function upsertRecipe(string $name, ?string $category = null, ?int $id = null): int
    {
        if ($id !== null) {
            $this->pdo->prepare('UPDATE recipes SET name = ?, category = ? WHERE id = ?')
                ->execute([$name, $category, $id]);
            return $id;
        }
        $st = $this->pdo->prepare('SELECT id FROM recipes WHERE name = ?');
        $st->execute([$name]);
        $found = $st->fetchColumn();
        if ($found !== false) {
            return (int) $found;
        }
        $this->pdo->prepare('INSERT INTO recipes (name, category) VALUES (?, ?)')->execute([$name, $category]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Reçeteye malzeme ekle/gramaj güncelle (aynı malzeme varsa gramajı günceller). */
    public function upsertRecipeItem(int $recipeId, int $ingredientId, float $grams): void
    {
        $st = $this->pdo->prepare('SELECT id FROM recipe_items WHERE recipe_id = ? AND ingredient_id = ?');
        $st->execute([$recipeId, $ingredientId]);
        $found = $st->fetchColumn();
        if ($found !== false) {
            $this->pdo->prepare('UPDATE recipe_items SET grams = ? WHERE id = ?')->execute([$grams, (int) $found]);
            return;
        }
        $this->pdo->prepare('INSERT INTO recipe_items (recipe_id, ingredient_id, grams) VALUES (?, ?, ?)')
            ->execute([$recipeId, $ingredientId, $grams]);
    }

    /** Reçete kalemini sil (scope: recipe_id ile — yanlış reçete kalemi silinmez). */
    public function deleteRecipeItem(int $itemId, int $recipeId): void
    {
        $this->pdo->prepare('DELETE FROM recipe_items WHERE id = ? AND recipe_id = ?')->execute([$itemId, $recipeId]);
    }

    /**
     * Bir menü gününün reçeteleri + porsiyon maliyetleri (menu_days varsa).
     * @return array<int,array> ['recipe_id','name','cost']
     */
    public function menuDayCost(string $date, string $meal = 'ogle'): array
    {
        $st = $this->pdo->prepare(
            'SELECT r.id AS recipe_id, r.name,
                    COALESCE(SUM(ri.grams / 1000.0 * i.price_per_unit), 0) AS cost
             FROM menu_days md JOIN recipes r ON r.id = md.recipe_id
             LEFT JOIN recipe_items ri ON ri.recipe_id = r.id
             LEFT JOIN ingredients i ON i.id = ri.ingredient_id
             WHERE md.menu_date = ? AND md.meal = ?
             GROUP BY r.id, r.name ORDER BY r.name'
        );
        $st->execute([$date, $meal]);
        return $st->fetchAll();
    }

    // ── Stok Durumu (M4) ──────────────────────────────────────
    /**
     * Malzeme başına güncel stok = Σ(giriş) − Σ(çıkış). $search ada göre LIKE filtre.
     * is_critical PHP tarafında: min_stok > 0 && stok < min_stok.
     * @return array<int,array> ['id','name','unit','min_stok','stok']
     */
    public function stockLevels(?string $search = null): array
    {
        $sql = "SELECT i.id, i.name, i.unit, i.min_stok,
                       COALESCE(SUM(CASE WHEN sm.direction = 'giris' THEN sm.quantity ELSE 0 END), 0)
                     - COALESCE(SUM(CASE WHEN sm.direction = 'cikis' THEN sm.quantity ELSE 0 END), 0) AS stok
                FROM ingredients i
                LEFT JOIN stock_moves sm ON sm.ingredient_id = i.id";
        $params = [];
        if ($search !== null && $search !== '') {
            $sql .= ' WHERE i.name LIKE ?';
            $params[] = '%' . $search . '%';
        }
        $sql .= ' GROUP BY i.id, i.name, i.unit, i.min_stok ORDER BY i.name';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Tek malzemenin güncel stoğu (Σ giriş − Σ çıkış). */
    public function stockLevel(int $ingredientId): float
    {
        $st = $this->pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'giris' THEN quantity ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN direction = 'cikis' THEN quantity ELSE 0 END), 0)
             FROM stock_moves WHERE ingredient_id = ?"
        );
        $st->execute([$ingredientId]);
        return (float) $st->fetchColumn();
    }

    /** Stok hareketi ekle (giriş/çıkış). $unit null ise malzemenin birimi kullanılır. */
    public function addStockMove(
        int $ingredientId,
        string $moveDate,
        string $direction,
        float $quantity,
        ?string $unit = null,
        ?string $note = null
    ): int {
        if (!in_array($direction, ['giris', 'cikis'], true)) {
            throw new \InvalidArgumentException('direction giris|cikis olmalı');
        }
        if ($unit === null || $unit === '') {
            $ing = $this->ingredient($ingredientId);
            $unit = $ing['unit'] ?? 'kg';
        }
        $this->pdo->prepare(
            'INSERT INTO stock_moves (ingredient_id, move_date, direction, quantity, unit, note)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$ingredientId, $moveDate, $direction, $quantity, $unit, $note]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Kritik stok: eşiği (min_stok > 0) olan ve güncel stoğu eşiğin altındaki malzemeler.
     * @return array<int,array> ['id','name','unit','min_stok','stok']
     */
    public function criticalStock(): array
    {
        $out = [];
        foreach ($this->stockLevels() as $r) {
            $min = (float) $r['min_stok'];
            if ($min > 0 && (float) $r['stok'] < $min) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** Son stok hareketleri (yeni→eski, malzeme adıyla). */
    public function recentStockMoves(int $limit = 20): array
    {
        return $this->pdo->query(
            'SELECT sm.id, sm.move_date, sm.direction, sm.quantity, sm.unit, sm.note, i.name AS ingredient_name
             FROM stock_moves sm JOIN ingredients i ON i.id = sm.ingredient_id
             ORDER BY sm.move_date DESC, sm.id DESC LIMIT ' . (int) $limit
        )->fetchAll();
    }

    // ── Personel Giderleri (opus-009) ─────────────────────────
    /** Geçerli personel gider türleri (ENUM/CHECK ile eş). */
    public const PERSONEL_GIDER_TUR = ['maas', 'prim', 'avans', 'sgk', 'diger'];

    /** @return array<int,array> personel listesi (aktif varsayılan). */
    public function listPersonel(bool $activeOnly = true): array
    {
        $sql = 'SELECT id, ad, gorev, aylik_ucret, ise_giris, diger_maliyet, is_active FROM personel';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY ad';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function personel(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, ad, gorev, aylik_ucret, ise_giris, diger_maliyet, is_active FROM personel WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Personel ekle/düzenle. $id verilirse günceller (ad dahil), yoksa ekler.
     * $aylikUcret = BRÜT maaş. $iseGiris = kıdem başlangıcı (YYYY-MM-DD|null).
     * $digerMaliyet = override tutar (null → ayar diger_maliyet_oran'dan hesaplanır).
     */
    public function upsertPersonel(
        string $ad,
        ?string $gorev,
        float $aylikUcret,
        ?int $id = null,
        ?string $iseGiris = null,
        ?float $digerMaliyet = null
    ): int {
        if ($id !== null) {
            $this->pdo->prepare(
                'UPDATE personel SET ad = ?, gorev = ?, aylik_ucret = ?, ise_giris = ?, diger_maliyet = ? WHERE id = ?'
            )->execute([$ad, $gorev, $aylikUcret, $iseGiris, $digerMaliyet, $id]);
            return $id;
        }
        $this->pdo->prepare(
            'INSERT INTO personel (ad, gorev, aylik_ucret, ise_giris, diger_maliyet) VALUES (?, ?, ?, ?, ?)'
        )->execute([$ad, $gorev, $aylikUcret, $iseGiris, $digerMaliyet]);
        return (int) $this->pdo->lastInsertId();
    }

    // ── Ayar (mevzuat KV — SGK/kıdem/diğer oranları, opus-014) ─────
    /** Tek ayar değeri (string). Yoksa $default döner. */
    public function ayar(string $anahtar, ?string $default = null): ?string
    {
        $st = $this->pdo->prepare('SELECT deger FROM ayar WHERE anahtar = ?');
        $st->execute([$anahtar]);
        $v = $st->fetchColumn();
        return $v !== false ? (string) $v : $default;
    }

    /** Ayar değerini float olarak oku (mevzuat oranı/tavan). */
    public function ayarNum(string $anahtar, float $default = 0.0): float
    {
        $v = $this->ayar($anahtar, null);
        return $v === null || $v === '' ? $default : (float) $v;
    }

    /** Ayar yaz/güncelle (KV upsert). */
    public function ayarSet(string $anahtar, string $deger): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $onConf = $driver === 'sqlite'
            ? 'ON CONFLICT(anahtar) DO UPDATE SET deger = excluded.deger'
            : 'ON DUPLICATE KEY UPDATE deger = VALUES(deger)';
        $this->pdo->prepare("INSERT INTO ayar (anahtar, deger) VALUES (?, ?) $onConf")
            ->execute([$anahtar, $deger]);
    }

    /** @return array<string,string> tüm ayarlar (UI düzenleme için). */
    public function ayarlar(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT anahtar, deger FROM ayar')->fetchAll() as $r) {
            $out[$r['anahtar']] = $r['deger'];
        }
        return $out;
    }

    // ── Personel gerçek işveren maliyeti (opus-014) ────────────────
    /**
     * Bir personelin yüklü aylık işveren maliyeti (bileşen breakdown).
     * brüt + işveren SGK(oran) + kıdem aylık tahakkuk(min(brüt,tavan)/bölen) + diğer maliyet.
     * Oranlar ayar'dan (default mevzuat). diger: personel.diger_maliyet override, yoksa brüt×oran.
     * @return array{brut,sgk_isveren,kidem_aylik,diger,yuklu_toplam,sgk_orani,kidem_tavan,tavan_uygulandi}
     */
    public function personelYukluMaliyet(int $personelId, ?string $ay = null): array
    {
        $p = $this->personel($personelId);
        $tamBrut = $p ? (float) $p['aylik_ucret'] : 0.0;
        $calismaGunu = 30.0;
        if ($ay !== null && $p) {
            $calismaGunu = (float) $this->personelMaasAy($personelId, $ay)['calisma_gunu'];
        }
        $maasOrani = max(0.0, min(1.0, $calismaGunu / 30.0));
        $brut = $tamBrut * $maasOrani;
        $sgkOrani  = $this->ayarNum('sgk_isveren_orani', 0.225);
        $tavan     = $this->ayarNum('kidem_tavan', 64948.77);
        $bolen     = $this->ayarNum('kidem_aylik_bolen', 12);
        $digerOran = $this->ayarNum('diger_maliyet_oran', 0.0);

        $sgkIsveren = $brut * $sgkOrani;
        $kidemBaz   = min($brut, $tavan);
        $kidemAylik = $bolen > 0 ? $kidemBaz / $bolen : 0.0;
        $diger = ($p && $p['diger_maliyet'] !== null)
            ? (float) $p['diger_maliyet']
            : $brut * $digerOran;

        return [
            'brut'            => $brut,
            'tam_brut'        => $tamBrut,
            'calisma_gunu'    => $calismaGunu,
            'eksik_gun'       => max(0.0, 30.0 - $calismaGunu),
            'maas_orani'      => $maasOrani,
            'sgk_isveren'     => $sgkIsveren,
            'kidem_aylik'     => $kidemAylik,
            'diger'           => $diger,
            'yuklu_toplam'    => $brut + $sgkIsveren + $kidemAylik + $diger,
            'sgk_orani'       => $sgkOrani,
            'kidem_tavan'     => $tavan,
            'tavan_uygulandi' => $brut > $tavan,
        ];
    }

    private function personelMaasAyRaw(int $personelId, string $ay): ?array
    {
        $st = $this->pdo->prepare('SELECT id, personel_id, ay, calisma_gunu, maas_odendi, odeme_tarihi, gider_id FROM personel_maas_ay WHERE personel_id = ? AND ay = ?');
        $st->execute([$personelId, $ay]);
        return $st->fetch() ?: null;
    }

    /** Seçili ay için çalışma günü ve ödeme durumunu döndür; kayıt yoksa 30 gün / ödenmedi varsayılır. */
    public function personelMaasAy(int $personelId, string $ay): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
            throw new \InvalidArgumentException('Geçersiz ay: ' . $ay);
        }
        $p = $this->personel($personelId);
        $row = $this->personelMaasAyRaw($personelId, $ay);
        $gun = $row ? (float) $row['calisma_gunu'] : 30.0;
        $gun = max(0.0, min(30.0, $gun));
        $tamBrut = $p ? (float) $p['aylik_ucret'] : 0.0;
        $oran = $gun / 30.0;
        return [
            'id' => $row ? (int) $row['id'] : null,
            'personel_id' => $personelId,
            'ay' => $ay,
            'calisma_gunu' => $gun,
            'eksik_gun' => max(0.0, 30.0 - $gun),
            'maas_orani' => $oran,
            'hesaplanan_maas' => round($tamBrut * $oran, 2),
            'maas_odendi' => $row ? ((int) $row['maas_odendi'] === 1) : false,
            'odeme_tarihi' => $row['odeme_tarihi'] ?? null,
            'gider_id' => ($row && $row['gider_id'] !== null) ? (int) $row['gider_id'] : null,
        ];
    }

    /** Aylık çalışma günü/ödendi bilgisini kaydet; ödendi ise bağlı maaş giderini otomatik yazar. */
    public function setPersonelMaasAy(int $personelId, string $ay, float $calismaGunu, bool $maasOdendi, ?string $odemeTarihi = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
            throw new \InvalidArgumentException('Geçersiz ay: ' . $ay);
        }
        $p = $this->personel($personelId);
        if (!$p) {
            throw new \InvalidArgumentException('Personel bulunamadı: ' . $personelId);
        }
        $calismaGunu = max(0.0, min(30.0, $calismaGunu));
        if ($maasOdendi && (!$odemeTarihi || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $odemeTarihi))) {
            $odemeTarihi = date('Y-m-t', strtotime($ay . '-01'));
        }
        if (!$maasOdendi) {
            $odemeTarihi = null;
        }
        $hesaplananMaas = round(((float) $p['aylik_ucret']) * ($calismaGunu / 30.0), 2);
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $row = $this->personelMaasAyRaw($personelId, $ay);
            $giderId = ($row && $row['gider_id'] !== null) ? (int) $row['gider_id'] : null;
            $gunText = rtrim(rtrim(number_format($calismaGunu, 2, '.', ''), '0'), '.');
            $aciklama = $ay . ' maaşı (' . str_replace('.', ',', $gunText) . ' gün)';

            if ($maasOdendi) {
                if ($giderId) {
                    $this->pdo->prepare('UPDATE personel_gider SET tarih = ?, tur = ?, tutar = ?, aciklama = ? WHERE id = ?')
                        ->execute([$odemeTarihi, 'maas', $hesaplananMaas, $aciklama, $giderId]);
                } else {
                    $this->pdo->prepare('INSERT INTO personel_gider (personel_id, tarih, tur, tutar, aciklama) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$personelId, $odemeTarihi, 'maas', $hesaplananMaas, $aciklama]);
                    $giderId = (int) $this->pdo->lastInsertId();
                }
            } elseif ($giderId) {
                $this->pdo->prepare('DELETE FROM personel_gider WHERE id = ?')->execute([$giderId]);
                $giderId = null;
            }

            if ($row) {
                $this->pdo->prepare('UPDATE personel_maas_ay SET calisma_gunu = ?, maas_odendi = ?, odeme_tarihi = ?, gider_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                    ->execute([$calismaGunu, $maasOdendi ? 1 : 0, $odemeTarihi, $giderId, (int) $row['id']]);
            } else {
                $this->pdo->prepare('INSERT INTO personel_maas_ay (personel_id, ay, calisma_gunu, maas_odendi, odeme_tarihi, gider_id) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$personelId, $ay, $calismaGunu, $maasOdendi ? 1 : 0, $odemeTarihi, $giderId]);
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->personelMaasAy($personelId, $ay);
    }
    /**
     * Biriken kıdem yükümlülüğü (fesihte ödenecek borç) + o ayki tahakkuk.
     * ise_giris'ten referans aya (son gününe) kadar TAM ay sayısı × aylık kıdem (min(brüt,tavan)/bölen).
     * $ay null → bugüne kadar. ise_giris yoksa birikim 0 (ay_sayisi 0), aylık tahakkuk yine görünür.
     * @return array{ay_sayisi:int,aylik:float,birikim:float,bu_ay_tahakkuk:float}
     */
    public function kidemBirikim(int $personelId, ?string $ay = null): array
    {
        $y = $this->personelYukluMaliyet($personelId);
        $aylik = $y['kidem_aylik'];
        $p = $this->personel($personelId);
        $iseGiris = $p['ise_giris'] ?? null;

        $aySayisi = 0;
        if ($iseGiris !== null && $iseGiris !== '') {
            $bitis = $ay !== null && preg_match('/^\d{4}-\d{2}$/', $ay)
                ? date('Y-m-t', strtotime($ay . '-01'))
                : date('Y-m-d');
            // Tamamlanan ay sayısı (tam sayı ay aritmetiği — end-of-month yuvarlaması yok).
            [$sy, $sm, $sd] = array_map('intval', explode('-', substr($iseGiris, 0, 10)));
            [$ey, $em, $ed] = array_map('intval', explode('-', $bitis));
            $aySayisi = ($ey - $sy) * 12 + ($em - $sm);
            if ($ed < $sd) {
                $aySayisi--;
            }
            $aySayisi = max(0, $aySayisi);
        }
        return [
            'ay_sayisi'      => $aySayisi,
            'aylik'          => $aylik,
            'birikim'        => $aySayisi * $aylik,
            'bu_ay_tahakkuk' => $aylik,
        ];
    }

    // ── Personel → müşteri dağıtım ataması (opus-014) ──────────────
    /**
     * Bir personelin atamasını döndür.
     * @return array{genel:bool,customer_ids:array<int,int>}
     */
    public function personelAtama(int $personelId): array
    {
        $st = $this->pdo->prepare('SELECT customer_id, genel FROM personel_musteri WHERE personel_id = ?');
        $st->execute([$personelId]);
        $genel = false;
        $ids = [];
        foreach ($st->fetchAll() as $r) {
            if ((int) $r['genel'] === 1) {
                $genel = true;
            } elseif ($r['customer_id'] !== null) {
                $ids[] = (int) $r['customer_id'];
            }
        }
        return ['genel' => $genel, 'customer_ids' => $ids];
    }

    /**
     * Personel atamasını ayarla (önce temizle, sonra yaz). $genel=true → tek "genel" satır;
     * aksi halde $customerIds her biri bir müşteri (EŞİT böl). Boş → atama yok (dağıtılmamış).
     */
    public function setPersonelAtama(int $personelId, bool $genel, array $customerIds = []): void
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare('DELETE FROM personel_musteri WHERE personel_id = ?')->execute([$personelId]);
            if ($genel) {
                $this->pdo->prepare('INSERT INTO personel_musteri (personel_id, customer_id, genel) VALUES (?, NULL, 1)')
                    ->execute([$personelId]);
            } else {
                $ins = $this->pdo->prepare('INSERT INTO personel_musteri (personel_id, customer_id, genel) VALUES (?, ?, 0)');
                foreach (array_unique(array_map('intval', $customerIds)) as $cid) {
                    if ($cid > 0) {
                        $ins->execute([$personelId, $cid]);
                    }
                }
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Aylık personel yüklü maliyetini atanan müşterilere dağıt.
     *   - açık müşteri(ler): EŞİT böl (yuklu / N).
     *   - genel: o ay üretim HACMİNE (production.persons) oranlı tüm üretim müşterilerine.
     *     Hacim 0 ise aktif üretim müşterilerine eşit; üretim müşterisi yoksa dağıtılmamış.
     *   - atama yok: dağıtılmamış.
     * @return array{per_customer:array<int,float>,dagitilmamis:float,toplam:float}
     */
    public function personelDagitim(string $ay): array
    {
        // Üretim hacmi (o ay production.persons) ve toplam
        $uretimVol = [];
        $totalVol = 0.0;
        foreach ($this->monthProductionByCustomer($ay, 'uretim') as $r) {
            $vol = (float) $r['persons'];
            $uretimVol[(int) $r['customer_id']] = $vol;
            $totalVol += $vol;
        }
        $uretimCustomers = array_map(static fn($c) => (int) $c['id'], $this->listCustomersByCategory('uretim'));

        $per = [];
        $dagitilmamis = 0.0;
        $toplam = 0.0;
        foreach ($this->listPersonel() as $p) {
            $pid = (int) $p['id'];
            $yuklu = $this->personelYukluMaliyet($pid, $ay)['yuklu_toplam'];
            $toplam += $yuklu;
            if ($yuklu <= 0) {
                continue;
            }
            $atama = $this->personelAtama($pid);
            if ($atama['genel']) {
                if ($totalVol > 0) {
                    foreach ($uretimVol as $cid => $vol) {
                        $per[$cid] = ($per[$cid] ?? 0.0) + $yuklu * $vol / $totalVol;
                    }
                } elseif ($uretimCustomers) {
                    $n = count($uretimCustomers);
                    foreach ($uretimCustomers as $cid) {
                        $per[$cid] = ($per[$cid] ?? 0.0) + $yuklu / $n;
                    }
                } else {
                    $dagitilmamis += $yuklu;
                }
            } elseif ($atama['customer_ids']) {
                $n = count($atama['customer_ids']);
                foreach ($atama['customer_ids'] as $cid) {
                    $per[$cid] = ($per[$cid] ?? 0.0) + $yuklu / $n;
                }
            } else {
                $dagitilmamis += $yuklu;
            }
        }
        return ['per_customer' => $per, 'dagitilmamis' => $dagitilmamis, 'toplam' => $toplam];
    }

    /**
     * Şirket geneli toplam kıdem yükümlülüğü (aktif personel birikimleri toplamı) +
     * o ayki toplam tahakkuk. Finans "biriken borç" kartı.
     * @return array{birikim:float,bu_ay_tahakkuk:float}
     */
    public function kidemToplamYukumluluk(?string $ay = null): array
    {
        $birikim = 0.0;
        $tahakkuk = 0.0;
        foreach ($this->listPersonel() as $p) {
            $k = $this->kidemBirikim((int) $p['id'], $ay);
            $birikim += $k['birikim'];
            $tahakkuk += $k['bu_ay_tahakkuk'];
        }
        return ['birikim' => $birikim, 'bu_ay_tahakkuk' => $tahakkuk];
    }

    /** Personel pasifleştir (silme YOK — gider geçmişi bütünlüğü). */
    public function setPersonelActive(int $id, bool $active): void
    {
        $this->pdo->prepare('UPDATE personel SET is_active = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, $id]);
    }

    /** Personel gider kaydı ekle. $personelId NULL = kişiye bağlı olmayan toplu gider. */
    public function addPersonelGider(?int $personelId, string $tarih, string $tur, float $tutar, ?string $aciklama = null): int
    {
        if (!in_array($tur, self::PERSONEL_GIDER_TUR, true)) {
            throw new \InvalidArgumentException('Geçersiz gider türü: ' . $tur);
        }
        $this->pdo->prepare(
            'INSERT INTO personel_gider (personel_id, tarih, tur, tutar, aciklama) VALUES (?, ?, ?, ?, ?)'
        )->execute([$personelId, $tarih, $tur, $tutar, $aciklama]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Aylık toplam personel gideri (tüm türler). */
    public function monthPersonelTotal(string $ay): float
    {
        $st = $this->pdo->prepare(
            'SELECT COALESCE(SUM(tutar),0) FROM personel_gider WHERE substr(tarih,1,7) = ?'
        );
        $st->execute([$ay]);
        return (float) $st->fetchColumn();
    }

    /** Aylık personel gideri tür kırılımı [tur => toplam]. */
    public function monthPersonelByType(string $ay): array
    {
        $st = $this->pdo->prepare(
            'SELECT tur, COALESCE(SUM(tutar),0) AS toplam FROM personel_gider
             WHERE substr(tarih,1,7) = ? GROUP BY tur'
        );
        $st->execute([$ay]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[$r['tur']] = (float) $r['toplam'];
        }
        return $out;
    }

    /** Aydaki personel gider kayıtları (personel adıyla, yeni→eski). */
    public function monthPersonelGider(string $ay, int $limit = 60): array
    {
        $st = $this->pdo->prepare(
            'SELECT pg.id, pg.tarih, pg.tur, pg.tutar, pg.aciklama, p.ad AS personel_ad
             FROM personel_gider pg LEFT JOIN personel p ON p.id = pg.personel_id
             WHERE substr(pg.tarih,1,7) = ?
             ORDER BY pg.tarih DESC, pg.id DESC LIMIT ' . (int) $limit
        );
        $st->execute([$ay]);
        return $st->fetchAll();
    }

    // ── Faturalar (aylık müşteri faturası — opus-009) ─────────
    /** Aylık üretim cirosu (TÜM production tutar toplamı — kategori ayrımsız). */
    public function monthProductionTotal(string $ay): float
    {
        $st = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount),0) FROM production WHERE substr(prod_date,1,7) = ?'
        );
        $st->execute([$ay]);
        return (float) $st->fetchColumn();
    }

    /**
     * Aylık ÜRETİM cirosu — SADECE category='uretim' müşteriler (taşıma HARİÇ).
     * Net karlılıkta üretim cirosu taşıma satışını İÇERMEZ (opus-013 kategori ayrımı).
     */
    public function monthUretimCiro(string $ay): float
    {
        $st = $this->pdo->prepare(
            "SELECT COALESCE(SUM(p.amount),0)
             FROM production p JOIN customers c ON c.id = p.customer_id
             WHERE c.category = 'uretim' AND substr(p.prod_date,1,7) = ?"
        );
        $st->execute([$ay]);
        return (float) $st->fetchColumn();
    }

    /**
     * Bir müşterinin aylık faturası: üretim (gün gün öğün) satırları + toplamlar.
     * KDV opsiyonel: kdv_tutar = ara × kdv/100, genel = ara + kdv_tutar.
     * ara_toplam o ay production tutar toplamına eşittir (fatura = üretimden üretilir).
     * @return array{lines:array,ara_toplam:float,kdv_oran:float,kdv_tutar:float,genel_toplam:float,persons:int,gun:int}
     */
    public function customerInvoice(int $customerId, string $ay, float $kdvOran = 0.0): array
    {
        $lines = $this->customerDailyGrid($customerId, $ay);
        $araToplam = 0.0;
        $persons = 0;
        foreach ($lines as $l) {
            $araToplam += (float) $l['tutar'];
            $persons += (int) $l['kisi'];
        }
        $araToplam = round($araToplam, 2);
        $kdvOran = max(0.0, $kdvOran);
        $kdvTutar = round($araToplam * $kdvOran / 100, 2);
        $genelToplam = round($araToplam + $kdvTutar, 2);
        return [
            'lines'        => $lines,
            'ara_toplam'   => $araToplam,
            'kdv_oran'     => $kdvOran,
            'kdv_tutar'    => $kdvTutar,
            'genel_toplam' => $genelToplam,
            'persons'      => $persons,
            'gun'          => count($lines),
        ];
    }

    /** Fatura kaydı üret/güncelle (UNIQUE customer_id, ay → upsert). @return fatura id */
    public function saveFatura(int $customerId, string $ay, float $araToplam, float $kdvOran, float $genelToplam, string $durum = 'taslak'): int
    {
        if (!in_array($durum, ['taslak', 'kesildi'], true)) {
            $durum = 'taslak';
        }
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $onConf = $driver === 'sqlite'
            ? 'ON CONFLICT(customer_id, ay) DO UPDATE SET
                 ara_toplam = excluded.ara_toplam, kdv_oran = excluded.kdv_oran,
                 genel_toplam = excluded.genel_toplam, durum = excluded.durum'
            : 'ON DUPLICATE KEY UPDATE
                 ara_toplam = VALUES(ara_toplam), kdv_oran = VALUES(kdv_oran),
                 genel_toplam = VALUES(genel_toplam), durum = VALUES(durum)';
        $this->pdo->prepare(
            'INSERT INTO fatura (customer_id, ay, ara_toplam, kdv_oran, genel_toplam, durum)
             VALUES (?, ?, ?, ?, ?, ?) ' . $onConf
        )->execute([$customerId, $ay, $araToplam, $kdvOran, $genelToplam, $durum]);
        $st = $this->pdo->prepare('SELECT id FROM fatura WHERE customer_id = ? AND ay = ?');
        $st->execute([$customerId, $ay]);
        return (int) $st->fetchColumn();
    }

    /** Fatura listesi (müşteri adıyla, yeni→eski). $customerId verilirse filtreli. */
    public function listFaturalar(?int $customerId = null, int $limit = 50): array
    {
        $sql = 'SELECT f.id, f.customer_id, f.ay, f.ara_toplam, f.kdv_oran, f.genel_toplam, f.durum, f.created_at,
                       c.name AS customer_name
                FROM fatura f JOIN customers c ON c.id = f.customer_id';
        $params = [];
        if ($customerId !== null) {
            $sql .= ' WHERE f.customer_id = ?';
            $params[] = $customerId;
        }
        $sql .= ' ORDER BY f.ay DESC, c.name ASC LIMIT ' . (int) $limit;
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    // ── Gider → müşteri dağıtımı (CİRO ORANLI, opus-015) ──────────
    /**
     * O ay her müşterinin cirosu [customer_id => ciro].
     *   üretim: production.amount toplamı · taşıma: adet×birim_satis (= production.amount, snapshot satış).
     * Sadece o ay üretim/sayım OLAN müşteriler döner.
     * @return array<int,float>
     */
    public function customerCiroMap(string $ay): array
    {
        $out = [];
        foreach ($this->monthProductionByCustomer($ay) as $r) {
            $out[(int) $r['customer_id']] = (float) $r['ciro'];
        }
        return $out;
    }

    /**
     * Gideri ciro oranında müşterilere dağıt (RAPOR ANINDA — ciro değişince güncellenir).
     *   'genel' gider: o ayki TÜM müşterilere ciroları oranında.
     *   'musteri' gider: hedef müşteri(ler)e kendi ciroları oranında (tek → %100).
     *   Hedef cirosu 0 ise EŞİT böl (hedefler arası); hiç hedef/müşteri yoksa 'dagitilmamis'.
     * 'Personel' kategorili gider HARİÇ (personel yüklü maliyetiyle çift sayımı önler — netKarlilik ile eş).
     * @return array{per_customer:array<int,float>,dagitilmamis:float,toplam:float}
     */
    public function giderDagitim(string $ay): array
    {
        $ciro = $this->customerCiroMap($ay);
        $totalCiro = array_sum($ciro);
        $allIds = array_keys($ciro);

        $st = $this->pdo->prepare(
            "SELECT id, amount, alloc_type FROM transactions
             WHERE type = 'gider' AND substr(tx_date,1,7) = ?
               AND (category IS NULL OR category <> 'Personel')"
        );
        $st->execute([$ay]);

        $per = [];
        $dagitilmamis = 0.0;
        $toplam = 0.0;
        foreach ($st->fetchAll() as $t) {
            $amt = (float) $t['amount'];
            $toplam += $amt;

            if ($t['alloc_type'] === 'musteri') {
                $targets = $this->transactionTargets((int) $t['id']);
            } else {
                $targets = $allIds;
            }
            if (!$targets) {
                $dagitilmamis += $amt; // hedef/müşteri yok → dağıtılamaz
                continue;
            }
            $sub = 0.0;
            foreach ($targets as $cid) {
                $sub += $ciro[$cid] ?? 0.0;
            }
            if ($sub > 0) {
                foreach ($targets as $cid) {
                    $w = ($ciro[$cid] ?? 0.0) / $sub;
                    $per[$cid] = ($per[$cid] ?? 0.0) + $amt * $w;
                }
            } else {
                // Hedeflerin cirosu yok → eşit böl (kayıp önle)
                $n = count($targets);
                foreach ($targets as $cid) {
                    $per[$cid] = ($per[$cid] ?? 0.0) + $amt / $n;
                }
            }
        }
        unset($totalCiro);
        return ['per_customer' => $per, 'dagitilmamis' => $dagitilmamis, 'toplam' => $toplam];
    }

    /**
     * Müşteri bazlı NET kâr (ay). opus-015:
     *   üretim: net = ciro − payGider − payPersonel
     *   taşıma: net = satış − alış − sabit − payGider − payPersonel
     * $giderMap / $persMap verilirse yeniden hesaplanmaz (karAnalizi toplu çağrı için).
     * @return array{category,ciro,alis,sabit,pay_gider,pay_personel,net}
     */
    public function customerNetKarlilik(int $customerId, string $ay, ?array $giderMap = null, ?array $persMap = null): array
    {
        $payGider = ($giderMap ?? $this->giderDagitim($ay)['per_customer'])[$customerId] ?? 0.0;
        $payPersonel = ($persMap ?? $this->personelDagitim($ay)['per_customer'])[$customerId] ?? 0.0;
        $c = $this->customer($customerId);
        if ($c && ($c['category'] ?? 'uretim') === 'tasima') {
            $t = $this->tasimaProfit($customerId, $ay);
            $ciro = (float) $t['toplam_satis'];
            $net = $ciro - (float) $t['toplam_alis'] - (float) $t['sabit'] - $payGider - $payPersonel;
            return [
                'category'     => 'tasima',
                'ciro'         => $ciro,
                'alis'         => (float) $t['toplam_alis'],
                'sabit'        => (float) $t['sabit'],
                'pay_gider'    => $payGider,
                'pay_personel' => $payPersonel,
                'net'          => $net,
            ];
        }
        $ciro = (float) $this->customerMonthProduction($customerId, $ay)['amount'];
        return [
            'category'     => 'uretim',
            'ciro'         => $ciro,
            'alis'         => 0.0,
            'sabit'        => 0.0,
            'pay_gider'    => $payGider,
            'pay_personel' => $payPersonel,
            'net'          => $ciro - $payGider - $payPersonel,
        ];
    }

    /**
     * Kâr Analizi (opus-015, Ömer'in Excel'i): üretim/taşıma grup P&L + toplam.
     *   ÜRETİM: gelir − gider(pay) − personel(pay) = net, marj=net/gelir
     *   TAŞIMA: satış − alış − sabit − gider(pay) − personel(pay) = net, marj
     *   TOPLAM net = üretim + taşıma − dağıtılmamış = netKarlilik net (BİREBİR).
     * @return array{uretim:array,tasima:array,dagitilmamis:float,toplam_gelir:float,toplam_net:float,toplam_marj:float}
     */
    public function karAnalizi(string $ay): array
    {
        $giderD = $this->giderDagitim($ay);
        $persD = $this->personelDagitim($ay);
        $giderMap = $giderD['per_customer'];
        $persMap = $persD['per_customer'];

        $uretimRows = [];
        $uGelir = 0.0; $uGider = 0.0; $uPers = 0.0; $uNet = 0.0;
        foreach ($this->monthProductionByCustomer($ay, 'uretim') as $r) {
            $cid = (int) $r['customer_id'];
            $ciro = (float) $r['ciro'];
            $pg = $giderMap[$cid] ?? 0.0;
            $pp = $persMap[$cid] ?? 0.0;
            $net = $ciro - $pg - $pp;
            $uretimRows[] = [
                'customer_id' => $cid, 'name' => $r['name'],
                'gelir' => $ciro, 'gider' => $pg, 'personel' => $pp,
                'net' => $net, 'marj' => $ciro > 0 ? $net / $ciro : 0.0,
            ];
            $uGelir += $ciro; $uGider += $pg; $uPers += $pp; $uNet += $net;
        }

        $tasimaRows = [];
        $tSatis = 0.0; $tAlis = 0.0; $tSabit = 0.0; $tGider = 0.0; $tPers = 0.0; $tNet = 0.0;
        foreach ($this->listCustomersByCategory('tasima') as $c) {
            $cid = (int) $c['id'];
            $t = $this->tasimaProfit($cid, $ay);
            if ((float) $t['adet'] <= 0) {
                continue;
            }
            $satis = (float) $t['toplam_satis'];
            $alis = (float) $t['toplam_alis'];
            $sabit = (float) $t['sabit'];
            $pg = $giderMap[$cid] ?? 0.0;
            $pp = $persMap[$cid] ?? 0.0;
            $net = $satis - $alis - $sabit - $pg - $pp;
            $tasimaRows[] = [
                'customer_id' => $cid, 'name' => $c['name'],
                'satis' => $satis, 'alis' => $alis, 'sabit' => $sabit,
                'gider' => $pg, 'personel' => $pp,
                'net' => $net, 'marj' => $satis > 0 ? $net / $satis : 0.0,
            ];
            $tSatis += $satis; $tAlis += $alis; $tSabit += $sabit;
            $tGider += $pg; $tPers += $pp; $tNet += $net;
        }

        $dagitilmamis = $giderD['dagitilmamis'] + $persD['dagitilmamis'];
        $toplamNet = $uNet + $tNet - $dagitilmamis; // = netKarlilik net (birebir)
        $toplamGelir = $uGelir + $tSatis;

        return [
            'uretim' => [
                'rows' => $uretimRows,
                'gelir' => $uGelir, 'gider' => $uGider, 'personel' => $uPers,
                'net' => $uNet, 'marj' => $uGelir > 0 ? $uNet / $uGelir : 0.0,
            ],
            'tasima' => [
                'rows' => $tasimaRows,
                'satis' => $tSatis, 'alis' => $tAlis, 'sabit' => $tSabit,
                'gider' => $tGider, 'personel' => $tPers,
                'net' => $tNet, 'marj' => $tSatis > 0 ? $tNet / $tSatis : 0.0,
            ],
            'dagitilmamis' => $dagitilmamis,
            'toplam_gelir' => $toplamGelir,
            'toplam_net' => $toplamNet,
            'toplam_marj' => $toplamGelir > 0 ? $toplamNet / $toplamGelir : 0.0,
        ];
    }

    /**
     * Aylık net karlılık (tahakkuk, finans nakit akışından ayrı):
     *   üretim cirosu − hammadde/işletme gideri − personel gideri + taşıma net kârı = net.
     * Kalemler AYRI (karıştırma yok — taşıma satışı üretim cirosuna KARIŞMAZ).
     * Taşıma net kârı = Σ adet×(birim_satis−birim_alis) − sabit_gider (kâr merkezi katkısı, − olabilir).
     * 'Personel' kategorili finans gideri hammaddeden düşülür (çift sayım önlenir).
     * Personel gideri (opus-014): gerçek işveren YÜKLÜ maliyeti = Σ personelYukluMaliyet
     * (brüt + SGK + kıdem aylık tahakkuk + diğer); müşterilere dağıtılmış toplam.
     * @return array{ciro:float,hammadde:float,personel:float,tasima_kar:float,net:float}
     */
    public function netKarlilik(string $ay): array
    {
        $ciro = $this->monthUretimCiro($ay); // taşıma HARİÇ (kategori ayrımı)
        // opus-015: hammadde = gider dağıtım toplamı (giderDagitim ile birebir; 'Personel' kategori HARİÇ).
        $hammadde = $this->giderDagitim($ay)['toplam'];
        $personel = $this->personelDagitim($ay)['toplam']; // yüklü işveren maliyeti (dağıtılmış)
        $tasimaKar = $this->monthTasimaTotals($ay)['net'];
        return [
            'ciro'       => $ciro,
            'hammadde'   => $hammadde,
            'personel'   => $personel,
            'tasima_kar' => $tasimaKar,
            'net'        => $ciro - $hammadde - $personel + $tasimaKar,
        ];
    }

    // ── Yayınlanan menü (opus-010, müşteri-yüzü; menu_days'ten AYRI) ─
    /**
     * Admin menü listesi (taslak + yayında) + gün sayısı + hedef sayısı.
     * @return array<int,array> ['id','title','date_start','date_end','audience','status','item_count','target_count']
     */
    public function listMenus(int $limit = 50): array
    {
        return $this->pdo->query(
            'SELECT m.id, m.title, m.date_start, m.date_end, m.audience, m.status, m.created_at,
                    (SELECT COUNT(*) FROM menu_item mi WHERE mi.menu_id = m.id) AS item_count,
                    (SELECT COUNT(*) FROM menu_target mt WHERE mt.menu_id = m.id) AS target_count
             FROM menu m ORDER BY m.date_start DESC, m.id DESC LIMIT ' . (int) $limit
        )->fetchAll();
    }

    /** Tek menü (admin, scope'suz — yönetim ekranı). */
    public function menu(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM menu WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Menü oluştur/güncelle (başlık, tarih aralığı, audience). $id verilirse günceller. @return menu id */
    public function upsertMenu(string $title, string $dateStart, string $dateEnd, string $audience = 'all', ?int $id = null): int
    {
        if (!in_array($audience, ['all', 'selected'], true)) {
            $audience = 'all';
        }
        if ($id !== null) {
            $this->pdo->prepare(
                'UPDATE menu SET title = ?, date_start = ?, date_end = ?, audience = ? WHERE id = ?'
            )->execute([$title, $dateStart, $dateEnd, $audience, $id]);
            return $id;
        }
        $this->pdo->prepare(
            'INSERT INTO menu (title, date_start, date_end, audience) VALUES (?, ?, ?, ?)'
        )->execute([$title, $dateStart, $dateEnd, $audience]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Menü gün×öğün yemek listesi upsert (UNIQUE menu_id,item_date,meal). */
    public function upsertMenuItem(int $menuId, string $itemDate, string $meal, string $dishes): void
    {
        if (!in_array($meal, ['sabah', 'ogle', 'aksam', 'gece', 'kumanya'], true)) {
            $meal = 'ogle';
        }
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $onConf = $driver === 'sqlite'
            ? 'ON CONFLICT(menu_id, item_date, meal) DO UPDATE SET dishes = excluded.dishes'
            : 'ON DUPLICATE KEY UPDATE dishes = VALUES(dishes)';
        $this->pdo->prepare(
            'INSERT INTO menu_item (menu_id, item_date, meal, dishes) VALUES (?, ?, ?, ?) ' . $onConf
        )->execute([$menuId, $itemDate, $meal, $dishes]);
    }

    /** Bir menünün gün×öğün kalemleri (tarih, öğün sıralı). */
    public function menuItems(int $menuId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, item_date, meal, dishes FROM menu_item WHERE menu_id = ? ORDER BY item_date ASC, meal ASC'
        );
        $st->execute([$menuId]);
        return $st->fetchAll();
    }

    /**
     * Excel import: gün listesini menüye toplu upsert (opus-016). Aynı tarih+öğün → GÜNCELLE
     * (upsertMenuItem UNIQUE sayesinde duplicate yok). Tek transaction.
     * @param array<int,array{date:string,dishes:mixed}> $days dishes: dizi (satır satır) veya metin
     * @return int işlenen gün sayısı (geçersiz tarih / boş yemek atlanır)
     */
    public function importMenuItems(int $menuId, array $days, string $meal = 'ogle'): int
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $n = 0;
            foreach ($days as $d) {
                $date = trim((string) ($d['date'] ?? ''));
                $dishes = $d['dishes'] ?? '';
                if (is_array($dishes)) {
                    $dishes = implode("\n", array_map('strval', $dishes));
                }
                $dishes = trim((string) $dishes);
                if (!Helpers::isDate($date) || $dishes === '') {
                    continue;
                }
                $this->upsertMenuItem($menuId, $date, $meal, mb_substr($dishes, 0, 1000));
                $n++;
            }
            if ($own) {
                $this->pdo->commit();
            }
            return $n;
        } catch (\Throwable $e) {
            if ($own) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Menü kalemini sil (scope: menu_id ile — yanlış menü kalemi silinmez). */
    public function deleteMenuItem(int $itemId, int $menuId): void
    {
        $this->pdo->prepare('DELETE FROM menu_item WHERE id = ? AND menu_id = ?')->execute([$itemId, $menuId]);
    }

    /**
     * Menü hedefini ayarla. audience='all' → hedef listesi temizlenir (herkes görür).
     * audience='selected' → verilen customerIds hedef olur (önce temizle, sonra ekle).
     */
    public function setMenuAudience(int $menuId, string $audience, array $customerIds = []): void
    {
        if (!in_array($audience, ['all', 'selected'], true)) {
            $audience = 'all';
        }
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare('UPDATE menu SET audience = ? WHERE id = ?')->execute([$audience, $menuId]);
            $this->pdo->prepare('DELETE FROM menu_target WHERE menu_id = ?')->execute([$menuId]);
            if ($audience === 'selected') {
                $ins = $this->pdo->prepare('INSERT INTO menu_target (menu_id, customer_id) VALUES (?, ?)');
                foreach (array_unique(array_map('intval', $customerIds)) as $cId) {
                    if ($cId > 0) {
                        $ins->execute([$menuId, $cId]);
                    }
                }
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<int,int> menü hedef customer_id listesi (audience='selected' iken). */
    public function menuTargets(int $menuId): array
    {
        $st = $this->pdo->prepare('SELECT customer_id FROM menu_target WHERE menu_id = ?');
        $st->execute([$menuId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Menüyü yayınla / taslağa al. */
    public function publishMenu(int $menuId, bool $publish = true): void
    {
        $this->pdo->prepare('UPDATE menu SET status = ? WHERE id = ?')
            ->execute([$publish ? 'published' : 'draft', $menuId]);
    }

    /**
     * IDOR SCOPE: bir müşteriye görünür YAYINLANMIŞ menüler.
     * Görünürlük: status='published' VE date_end >= bugün VE
     * (audience='all' VEYA menu_target'ta customer_id VAR).
     * Müşteri A, sadece-B-hedefli menüyü GÖRMEZ; all-audience menüyü görür.
     * @return array<int,array>
     */
    public function menusForCustomer(int $customerId, ?string $minEndDate = null): array
    {
        // date_end >= $minEndDate → görünür. Panel için bugün (aktif+gelecek);
        // müşteri menü sayfası bugün−1 ay geçer (opus-019: en fazla 1 ay geri).
        $minEndDate ??= date('Y-m-d');
        $st = $this->pdo->prepare(
            "SELECT DISTINCT m.id, m.title, m.date_start, m.date_end, m.audience, m.status
             FROM menu m
             LEFT JOIN menu_target mt ON mt.menu_id = m.id AND mt.customer_id = ?
             WHERE m.status = 'published' AND m.date_end >= ?
               AND (m.audience = 'all' OR mt.customer_id IS NOT NULL)
             ORDER BY m.date_start DESC, m.id DESC"
        );
        $st->execute([$customerId, $minEndDate]);
        return $st->fetchAll();
    }

    /**
     * Bot/admin görünümü: bir tarih aralığında YAYINLANMIŞ menülerin gün×öğün yemekleri.
     * Müşteri-scope YOK — Ömer/bot tüm yayınlanmış menüyü görür (audience gözetilmez).
     * $meal verilirse o öğüne kısıtlar. Aynı gün+öğün birden çok menüde varsa hepsi döner.
     * @return array<int,array{item_date:string,meal:string,dishes:string,menu_title:string}>
     */
    public function publishedMenuItems(string $from, string $to, ?string $meal = null): array
    {
        $sql = "SELECT mi.item_date, mi.meal, mi.dishes, m.title AS menu_title
                FROM menu_item mi JOIN menu m ON m.id = mi.menu_id
                WHERE m.status = 'published' AND mi.item_date BETWEEN ? AND ?";
        $params = [$from, $to];
        if ($meal !== null) {
            $sql .= ' AND mi.meal = ?';
            $params[] = $meal;
        }
        $sql .= ' ORDER BY mi.item_date ASC, mi.meal ASC';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** IDOR SCOPE: tek menü — SADECE bu müşteriye görünürse döner (PDF indirme için). */
    public function menuForCustomer(int $customerId, int $menuId, ?string $minEndDate = null): ?array
    {
        $minEndDate ??= date('Y-m-d');
        $st = $this->pdo->prepare(
            "SELECT DISTINCT m.id, m.title, m.date_start, m.date_end, m.audience, m.status
             FROM menu m
             LEFT JOIN menu_target mt ON mt.menu_id = m.id AND mt.customer_id = ?
             WHERE m.id = ? AND m.status = 'published' AND m.date_end >= ?
               AND (m.audience = 'all' OR mt.customer_id IS NOT NULL)"
        );
        $st->execute([$customerId, $menuId, $minEndDate]);
        return $st->fetch() ?: null;
    }

    // ── Malzeme talebi (opus-010) ───────────────────────────────
    /** Sarf malzeme katalog listesi (aktif varsayılan, sort_order sonra ad). */
    public function listSupplyItems(bool $activeOnly = true): array
    {
        $sql = 'SELECT id, ad, birim, is_active, sort_order FROM supply_item';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, ad ASC';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function supplyItem(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, ad, birim, is_active, sort_order FROM supply_item WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Katalog ekle/düzenle. $id verilirse günceller; yoksa ada göre upsert. @return id */
    public function upsertSupplyItem(string $ad, string $birim = 'adet', ?int $id = null, int $sortOrder = 0): int
    {
        if ($id === null) {
            $st = $this->pdo->prepare('SELECT id FROM supply_item WHERE ad = ?');
            $st->execute([$ad]);
            $found = $st->fetchColumn();
            $id = $found !== false ? (int) $found : null;
        }
        if ($id !== null) {
            $this->pdo->prepare('UPDATE supply_item SET ad = ?, birim = ?, sort_order = ? WHERE id = ?')
                ->execute([$ad, $birim, $sortOrder, $id]);
            return $id;
        }
        $this->pdo->prepare('INSERT INTO supply_item (ad, birim, sort_order) VALUES (?, ?, ?)')
            ->execute([$ad, $birim, $sortOrder]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Katalog kalemini pasif/aktif (silme YOK — geçmiş talep bütünlüğü). */
    public function setSupplyItemActive(int $id, bool $active): void
    {
        $this->pdo->prepare('UPDATE supply_item SET is_active = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, $id]);
    }

    /**
     * Müşteri malzeme talebi oluştur: supply_request + kalemler.
     * $items: [supply_item_id => miktar]. miktar<=0 kalemler atlanır. customerId ZORUNLU (IDOR).
     * @return int request id (kalem yoksa 0 — talep açılmaz)
     */
    public function createSupplyRequest(int $customerId, array $items, ?int $customerUserId = null, ?string $note = null, ?string $requestDate = null): int
    {
        $requestDate ??= date('Y-m-d');
        // Geçerli kalemleri süz (miktar > 0)
        $valid = [];
        foreach ($items as $itemId => $qty) {
            $itemId = (int) $itemId;
            $qty = (float) $qty;
            if ($itemId > 0 && $qty > 0) {
                $valid[$itemId] = $qty;
            }
        }
        if (!$valid) {
            return 0;
        }
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare(
                'INSERT INTO supply_request (customer_id, customer_user_id, request_date, note) VALUES (?, ?, ?, ?)'
            )->execute([$customerId, $customerUserId, $requestDate, $note]);
            $reqId = (int) $this->pdo->lastInsertId();
            $ins = $this->pdo->prepare(
                'INSERT INTO supply_request_item (request_id, supply_item_id, miktar) VALUES (?, ?, ?)'
            );
            foreach ($valid as $itemId => $qty) {
                $ins->execute([$reqId, $itemId, $qty]);
            }
            if ($own) {
                $this->pdo->commit();
            }
            return $reqId;
        } catch (\Throwable $e) {
            if ($own) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** IDOR SCOPE: müşteri kapsamlı talep listesi (yeni→eski) + kalem sayısı. */
    public function supplyRequestsForCustomer(int $customerId): array
    {
        $st = $this->pdo->prepare(
            'SELECT sr.id, sr.request_date, sr.status, sr.note, sr.created_at,
                    (SELECT COUNT(*) FROM supply_request_item i WHERE i.request_id = sr.id) AS item_count
             FROM supply_request sr WHERE sr.customer_id = ?
             ORDER BY sr.created_at DESC, sr.id DESC'
        );
        $st->execute([$customerId]);
        return $st->fetchAll();
    }

    /** Bir talebin kalemleri (ad + birim + miktar). */
    public function supplyRequestItems(int $requestId): array
    {
        $st = $this->pdo->prepare(
            'SELECT sri.id, sri.supply_item_id, si.ad, si.birim, sri.miktar
             FROM supply_request_item sri JOIN supply_item si ON si.id = sri.supply_item_id
             WHERE sri.request_id = ? ORDER BY si.sort_order ASC, si.ad ASC'
        );
        $st->execute([$requestId]);
        return $st->fetchAll();
    }

    /** IDOR guard: talep SADECE sahibi müşteriye döner (müşteri-yüzü kalem erişimi). */
    public function supplyRequestForCustomer(int $requestId, int $customerId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM supply_request WHERE id = ? AND customer_id = ?');
        $st->execute([$requestId, $customerId]);
        return $st->fetch() ?: null;
    }

    /** Admin: talep kuyruğu (müşteri adıyla). $status null = hepsi, aksi belirli durum. */
    public function openSupplyRequests(?string $status = 'acik'): array
    {
        $sql = 'SELECT sr.id, sr.customer_id, sr.request_date, sr.status, sr.note, sr.created_at,
                       c.name AS customer_name,
                       (SELECT COUNT(*) FROM supply_request_item i WHERE i.request_id = sr.id) AS item_count
                FROM supply_request sr JOIN customers c ON c.id = sr.customer_id';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE sr.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY sr.created_at DESC, sr.id DESC';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function openSupplyRequestsCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM supply_request WHERE status = 'acik'")->fetchColumn();
    }

    /** Admin: talep id ile (scope'suz, müşteri adıyla). */
    public function supplyRequestById(int $requestId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT sr.*, c.name AS customer_name FROM supply_request sr
             JOIN customers c ON c.id = sr.customer_id WHERE sr.id = ?'
        );
        $st->execute([$requestId]);
        return $st->fetch() ?: null;
    }

    public function setSupplyRequestStatus(int $requestId, string $status): void
    {
        if (!in_array($status, ['acik', 'hazirlandi', 'teslim'], true)) {
            throw new \InvalidArgumentException('Geçersiz talep durumu: ' . $status);
        }
        $this->pdo->prepare('UPDATE supply_request SET status = ? WHERE id = ?')->execute([$status, $requestId]);
    }

    // ── Müşteri malzeme hakedişi (standing entitlement) ─────────
    /**
     * IDOR SCOPE: bir müşterinin malzeme hakedişleri [supply_item_id => miktar].
     * Sadece verilen customerId — başka müşterinin hakedişi sızmaz.
     * @return array<int,float>
     */
    public function getEntitlements(int $customerId): array
    {
        $st = $this->pdo->prepare('SELECT supply_item_id, miktar FROM supply_entitlement WHERE customer_id = ?');
        $st->execute([$customerId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['supply_item_id']] = (float) $r['miktar'];
        }
        return $out;
    }

    /** Tek müşteri×malzeme hakediş upsert. miktar<=0 → kaydı sil (hakediş yok). */
    public function setEntitlement(int $customerId, int $supplyItemId, float $miktar): void
    {
        if ($miktar <= 0) {
            $this->pdo->prepare('DELETE FROM supply_entitlement WHERE customer_id = ? AND supply_item_id = ?')
                ->execute([$customerId, $supplyItemId]);
            return;
        }
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $onConf = $driver === 'sqlite'
            ? 'ON CONFLICT(customer_id, supply_item_id) DO UPDATE SET miktar = excluded.miktar'
            : 'ON DUPLICATE KEY UPDATE miktar = VALUES(miktar)';
        $this->pdo->prepare(
            'INSERT INTO supply_entitlement (customer_id, supply_item_id, miktar) VALUES (?, ?, ?) ' . $onConf
        )->execute([$customerId, $supplyItemId, $miktar]);
    }

    /** Bir müşterinin hakedişlerini toplu ayarla [supply_item_id => miktar]. Tek transaction. */
    public function upsertEntitlementsBulk(int $customerId, array $items): void
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            foreach ($items as $itemId => $miktar) {
                $this->setEntitlement($customerId, (int) $itemId, (float) $miktar);
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
