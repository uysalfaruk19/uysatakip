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
    /** fable-042: "bugün" (YYYY-MM-DD) — cari-ay MTD kesimi için. Set edilmezse APP_TODAY env / gerçek tarih. */
    private ?string $bugun = null;

    public function __construct(private PDO $pdo)
    {
    }

    /** fable-042: test/enjeksiyon — "bugün"ü sabitle (MTD sınır testleri). null → gerçek tarihe döner. */
    public function setBugun(?string $bugun): void
    {
        $this->bugun = $bugun;
    }

    /** fable-042: aktif "bugün" (YYYY-MM-DD). Enjekte edilmişse o; yoksa APP_TODAY env; yoksa gerçek tarih. */
    private function bugun(): string
    {
        if ($this->bugun !== null && $this->bugun !== '') {
            return $this->bugun;
        }
        $e = getenv('APP_TODAY');
        return ($e !== false && $e !== '') ? $e : date('Y-m-d');
    }

    /**
     * fable-042: Bir ayın ÜRETİM/GELİR hesap aralığı [bas, son] (YYYY-MM-DD, ikisi de dahil).
     *   CARİ ay  → ay başı .. BUGÜN (month-to-date): ileri günlere önceden girilen sayılar cari ay
     *              gelir/kişi toplamını ŞİŞİRMESİN (Ömer: "gelir yüksek gider düşük çıkmasın").
     *   Geçmiş/gelecek ay → TAM ay (değişmez; birebir regresyon).
     * SADECE üretim/gelir/kişi sorguları kullanır. FATURA-KES bunu KULLANMAZ (fatura tam dönem;
     * customerMealsRange/faturaAdaylari kendi bas/son'unu alır). Gider (tx_date) hiç dokunulmaz.
     * @return array{bas:string,son:string}
     */
    public function ayAralik(string $ay, ?string $bugun = null): array
    {
        $bas = $ay . '-01';
        $bugun ??= $this->bugun();
        if ($ay === substr($bugun, 0, 7)) {
            return ['bas' => $bas, 'son' => $bugun];          // cari ay → MTD (bugün dahil)
        }
        [$y, $m] = array_map('intval', explode('-', $ay));
        $son = sprintf('%s-%02d', $ay, (int) date('t', mktime(0, 0, 0, $m, 1, $y)));
        return ['bas' => $bas, 'son' => $son];                // geçmiş/gelecek → tam ay
    }

    // ── Müşteriler ────────────────────────────────────────────
    /** @return array<int,array> */
    public function activeCustomers(): array
    {
        return $this->pdo->query(
            'SELECT id, name, unit_price, category, contact, phone, contract_note, fatura_kisi_haftaici
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
        ?string $tasimaNot = null,
        ?string $email = null,
        int|null $faturaKisiHaftaici = -1 // sentinel: -1 = dokunma; null = kuralı kaldır; >0 = kural
    ): int {
        if (!in_array($category, ['uretim', 'tasima'], true)) {
            $category = 'uretim';
        }
        // fable-040: fatura kişisi 0/negatif = kural yok (null); INT UNSIGNED sütununa -1 girmez.
        $touchFatura = $faturaKisiHaftaici !== -1;
        $faturaVal = ($faturaKisiHaftaici !== null && $faturaKisiHaftaici > 0) ? $faturaKisiHaftaici : null;
        if ($id === null) {
            $st = $this->pdo->prepare('SELECT id FROM customers WHERE name = ?');
            $st->execute([$name]);
            $found = $st->fetchColumn();
            $id = $found !== false ? (int) $found : null;
        }
        if ($id !== null) {
            $sql = 'UPDATE customers SET name = ?, unit_price = ?, category = ?,
                 contact = COALESCE(?, contact), phone = COALESCE(?, phone), email = COALESCE(?, email),
                 contract_note = COALESCE(?, contract_note),
                 maliyet_birim = COALESCE(?, maliyet_birim),
                 tasima_sabit_gider = COALESCE(?, tasima_sabit_gider),
                 tasima_not = COALESCE(?, tasima_not)';
            $args = [$name, $unitPrice, $category, $contact, $phone, $email, $note,
                $maliyetBirim, $tasimaSabitGider, $tasimaNot];
            if ($touchFatura) {
                // Doğrudan atama (COALESCE değil) — boş bırakınca kural KALDIRILABİLSİN.
                $sql .= ', fatura_kisi_haftaici = ?';
                $args[] = $faturaVal;
            }
            $sql .= ' WHERE id = ?';
            $args[] = $id;
            $this->pdo->prepare($sql)->execute($args);
            return $id;
        }
        $this->pdo->prepare(
            'INSERT INTO customers (name, unit_price, category, contact, phone, email, contract_note,
                 maliyet_birim, tasima_sabit_gider, tasima_not, fatura_kisi_haftaici)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$name, $unitPrice, $category, $contact, $phone, $email, $note,
            $maliyetBirim ?? 0.0, $tasimaSabitGider ?? 0.0, $tasimaNot, $touchFatura ? $faturaVal : null]);
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

    /**
     * fable-046: periyodik bakiye senkronunun HEDEF listesi — Paraşüt cari'si bağlı AKTİF müşteriler.
     * customersWithParasut()'tan farkı: orası "daha önce senkronlanmış"ları (sync_at) listeler,
     * burası "senkronlanabilir"leri (parasut_id dolu) verir — hiç senkronlanmamış müşteri de gelir.
     * @return array<int,array{id:int,name:string,parasut_id:string}>
     */
    public function customersForParasutBakiye(): array
    {
        $rows = $this->pdo->query(
            "SELECT id, name, parasut_id FROM customers
             WHERE is_active = 1 AND parasut_id IS NOT NULL AND parasut_id <> '' ORDER BY name"
        )->fetchAll();
        return array_map(static fn(array $r) => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'parasut_id' => (string) $r['parasut_id'],
        ], $rows);
    }

    /**
     * fable-046: Paraşüt cari bakiyesi PERİYODİK senkronu (SALT-OKUMA — Paraşüt'e yazma YOK).
     * Ağ katmanı dışarıda: $fetch = fn(string $parasutId): ?array{balance:float} (canlıda
     * Parasut::contactBalance, testte mock) → bu metot saf DB tarafı, ağsız test edilir.
     * DÜRÜSTLÜK: fetch null/hata dönerse o müşterinin CACHE'İ KORUNUR — 0 uydurulmaz, eski
     * bakiye ezilmez (Alacaklarım ekranı "son senkron" zamanıyla birlikte gösterir).
     * @param callable(string):?array $fetch
     * @return array{hedef:int,guncel:int,atlanan:int,hata:int,detay:array<int,string>}
     */
    public function parasutBakiyeSenkron(callable $fetch, ?string $syncAt = null): array
    {
        $syncAt = $syncAt ?? date('Y-m-d H:i:s');
        $sonuc = ['hedef' => 0, 'guncel' => 0, 'atlanan' => 0, 'hata' => 0, 'detay' => []];
        foreach ($this->customersForParasutBakiye() as $c) {
            $sonuc['hedef']++;
            try {
                $r = $fetch($c['parasut_id']);
            } catch (\Throwable $e) {
                $sonuc['hata']++;
                $sonuc['detay'][] = $c['name'] . ': ' . $e->getMessage();
                continue;
            }
            if (!is_array($r) || !isset($r['balance'])) {
                $sonuc['atlanan']++;
                $sonuc['detay'][] = $c['name'] . ': bakiye alanı gelmedi (cache korundu)';
                continue;
            }
            $this->setParasutInfo($c['id'], null, (float) $r['balance'], $syncAt);
            $sonuc['guncel']++;
        }
        return $sonuc;
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

    // ── fable-023b: Paraşüt e-İrsaliye kesim kaydı ────────────────
    // NEDEN: kesilen belge GİB'e giden RESMİ e-İrsaliye. Mükerrer kesim geri dönülmez zarar →
    // kalkan üç katman: (1) DB UNIQUE(customer_id,gun) burada, (2) kesim öncesi Paraşüt sorgusu
    // (ParasutYaz), (3) UI'da "kesildi" kilidi. Kayıt SİLİNMEZ — audit izi.

    /** Bir müşterinin o güne ait irsaliye kaydı (yoksa null). */
    public function irsaliyeLog(int $customerId, string $gun): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM parasut_irsaliye_log WHERE customer_id = ? AND gun = ?'
        );
        $st->execute([$customerId, $gun]);
        $r = $st->fetch();
        return $r ?: null;
    }

    /** @return array<int,array> customer_id => o günkü irsaliye kaydı */
    public function irsaliyeLoglariGun(string $gun): array
    {
        $st = $this->pdo->prepare('SELECT * FROM parasut_irsaliye_log WHERE gun = ?');
        $st->execute([$gun]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['customer_id']] = $r;
        }
        return $out;
    }

    /**
     * Kesim sonucunu yaz. 'kesildi' kaydının ÜZERİNE YAZILMAZ (resmi belge izi korunur);
     * 'hata'/'bilinmiyor' kaydı tekrar denemede güncellenir.
     * @param array{parasut_doc_id?:?string,despatch_no?:?string,kalemler?:array,toplam_kisi?:int,
     *   durum:string,hata_mesaj?:?string,tasiyici_ok?:bool,entered_by?:string} $d
     * @return bool yazıldı mı (false = zaten 'kesildi', dokunulmadı)
     */
    public function irsaliyeLogKaydet(int $customerId, string $gun, array $d): bool
    {
        $mevcut = $this->irsaliyeLog($customerId, $gun);
        if ($mevcut !== null && (string) $mevcut['durum'] === 'kesildi') {
            return false;
        }
        $durum = in_array($d['durum'] ?? '', ['kesildi', 'hata', 'bilinmiyor'], true) ? $d['durum'] : 'hata';
        // fable-023d/e: gönderim (resmileştirme) ve mail paylaşımı kesimden ayrı adımlar — ayrı izlenir.
        $gonderim = in_array($d['gonderim'] ?? '', ['gonderildi', 'hata', 'yok'], true) ? $d['gonderim'] : 'yok';
        $mail = in_array($d['mail'] ?? '', ['gonderildi', 'hata', 'yok'], true) ? $d['mail'] : 'yok';
        $args = [
            $d['parasut_doc_id'] ?? null,
            $d['despatch_no'] ?? null,
            isset($d['kalemler']) ? json_encode($d['kalemler'], JSON_UNESCAPED_UNICODE) : null,
            (int) ($d['toplam_kisi'] ?? 0),
            $durum,
            $d['hata_mesaj'] ?? null,
            !empty($d['tasiyici_ok']) ? 1 : 0,
            $gonderim,
            $mail,
            (string) ($d['entered_by'] ?? ''),
        ];
        if ($mevcut !== null) {
            $sql = 'UPDATE parasut_irsaliye_log SET parasut_doc_id = ?, despatch_no = ?, kalemler = ?,
                        toplam_kisi = ?, durum = ?, hata_mesaj = ?, tasiyici_ok = ?, gonderim = ?, mail = ?, entered_by = ?,
                        updated_at = ' . $this->nowExpr() . '
                    WHERE customer_id = ? AND gun = ?';
            $args[] = $customerId;
            $args[] = $gun;
            $this->pdo->prepare($sql)->execute($args);
            return true;
        }
        $sql = 'INSERT INTO parasut_irsaliye_log
                    (parasut_doc_id, despatch_no, kalemler, toplam_kisi, durum, hata_mesaj,
                     tasiyici_ok, gonderim, mail, entered_by, customer_id, gun)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $args[] = $customerId;
        $args[] = $gun;
        $this->pdo->prepare($sql)->execute($args);
        return true;
    }

    private function nowExpr(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "datetime('now')" : 'NOW()';
    }

    /**
     * fable-023b: Seçim ekranının tek gerçek kaynağı — o günün müşterileri + neden
     * seçilebilir/seçilemez. Sessiz atlama YOK: seçilemeyen satır da listelenir, sebebi yazılır.
     * @return array<int,array{customer_id:int,name:string,parasut_id:string,ogle:int,aksam:int,
     *   kumanya:int,toplam:int,secilebilir:bool,sebep:string,despatch_no:?string,durum:?string}>
     */
    public function irsaliyeAdaylari(string $gun): array
    {
        $loglar = $this->irsaliyeLoglariGun($gun);
        $flags = [];
        foreach ($this->pdo->query('SELECT id, parasut_id, irsaliye_aktif FROM customers')->fetchAll() as $r) {
            $flags[(int) $r['id']] = [
                'parasut_id' => trim((string) ($r['parasut_id'] ?? '')),
                'aktif'      => (int) ($r['irsaliye_aktif'] ?? 1) === 1,
            ];
        }
        $out = [];
        foreach ($this->dayGridAllMeals($gun) as $r) {
            $cid = (int) $r['customer_id'];
            $f = $flags[$cid] ?? ['parasut_id' => '', 'aktif' => true];
            $log = $loglar[$cid] ?? null;
            $durum = $log !== null ? (string) $log['durum'] : null;

            $secilebilir = true;
            $sebep = '';
            if ((int) $r['toplam'] <= 0) {
                $secilebilir = false;
                $sebep = 'Bugün için sayı girilmemiş';
            } elseif (!$f['aktif']) {
                $secilebilir = false;
                $sebep = 'İrsaliye kapsamı dışı (aylık faturadan gidiyor)';
            } elseif ($f['parasut_id'] === '') {
                $secilebilir = false;
                $sebep = 'Paraşüt eşleşmesi yok';
            } elseif ($durum === 'kesildi') {
                $secilebilir = false;
                $sebep = 'Bugün zaten kesildi';
            } elseif ($durum === 'bilinmiyor') {
                $secilebilir = false;
                $sebep = 'Durum bilinmiyor — Paraşüt\'ten kontrol edin';
            }
            $out[] = [
                'customer_id' => $cid,
                'name'        => (string) $r['name'],
                'parasut_id'  => $f['parasut_id'],
                'ogle'        => (int) $r['ogle'],
                'aksam'       => (int) $r['aksam'],
                'kumanya'     => (int) $r['kumanya'],
                'toplam'      => (int) $r['toplam'],
                'secilebilir' => $secilebilir,
                'sebep'       => $sebep,
                'despatch_no' => $log !== null ? ($log['despatch_no'] ?? null) : null,
                'durum'       => $durum,
            ];
        }
        return $out;
    }

    // ── fable-024: Paraşüt satış faturası (irsaliyeden haftalık / üretimden aylık) ──────
    // Mükerrer kalkanı üç katman: (1) faturalanan irsaliye satırları fatura_log_id ile
    // işaretlenir → aday havuzundan düşer, (2) onay imzası (ParasutYaz), (3) 'bilinmiyor'
    // (timeout) + aylık 'kesildi' kilidi. Kayıt SİLİNMEZ (resmi belge izi).

    /** Bir müşterinin verilen dönemde FATURALANMAMIŞ (fatura_log_id NULL) kesilmiş irsaliyeleri. */
    public function faturaAdayIrsaliyeler(int $customerId, string $bas, string $son): array
    {
        $st = $this->pdo->prepare(
            "SELECT * FROM parasut_irsaliye_log
             WHERE customer_id = ? AND durum = 'kesildi' AND fatura_log_id IS NULL
               AND gun >= ? AND gun <= ?
             ORDER BY gun"
        );
        $st->execute([$customerId, $bas, $son]);
        return $st->fetchAll();
    }

    /**
     * fable-028: Müşterinin dönem içi GÜN GÜN öğün kırılımı (fatura onayında "netleştirme" tablosu).
     * @return array<int,array{gun:string,ogle:int,aksam:int,kumanya:int,toplam:int}>
     */
    public function customerMealsRange(int $customerId, string $bas, string $son): array
    {
        $st = $this->pdo->prepare(
            'SELECT prod_date, meal, persons FROM production
             WHERE customer_id = ? AND prod_date >= ? AND prod_date <= ?
             ORDER BY prod_date, meal'
        );
        $st->execute([$customerId, $bas, $son]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $g = (string) $r['prod_date'];
            if (!isset($out[$g])) {
                $out[$g] = ['gun' => $g, 'ogle' => 0, 'aksam' => 0, 'kumanya' => 0, 'toplam' => 0];
            }
            $meal = (string) $r['meal'];
            $p = (int) $r['persons'];
            if (isset($out[$g][$meal])) {
                $out[$g][$meal] += $p;
            }
            $out[$g]['toplam'] += $p;
        }
        return array_values($out);
    }

    /**
     * fable-029: Müşterinin SON kesilmiş faturası (önceki dönemle kıyas kontrolü için).
     * Aylıkta parçalar ayrı satır olduğundan dönem toplamı döner.
     * @return array{donem_bas:string,donem_son:string,kisi:int,tutar:float}|null
     */
    public function sonKesilenFatura(int $customerId, string $oncesinde): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT donem_bas, donem_son, SUM(toplam_kisi) AS kisi, SUM(toplam_tutar) AS tutar
             FROM parasut_fatura_log
             WHERE customer_id = ? AND durum = 'kesildi' AND donem_son < ?
             GROUP BY donem_bas, donem_son
             ORDER BY donem_son DESC LIMIT 1"
        );
        $st->execute([$customerId, $oncesinde]);
        $r = $st->fetch();
        if (!$r) {
            return null;
        }
        return ['donem_bas' => (string) $r['donem_bas'], 'donem_son' => (string) $r['donem_son'],
            'kisi' => (int) $r['kisi'], 'tutar' => (float) $r['tutar']];
    }

    /** Bir müşterinin verilen dönemde production.persons toplamı (aylık fatura adedi). */
    public function productionPersonsRange(int $customerId, string $bas, string $son): int
    {
        $st = $this->pdo->prepare(
            'SELECT COALESCE(SUM(persons),0) FROM production
             WHERE customer_id = ? AND prod_date >= ? AND prod_date <= ?'
        );
        $st->execute([$customerId, $bas, $son]);
        return (int) $st->fetchColumn();
    }

    /**
     * Dönemi kesişen fatura kaydından kilit sebebi (yoksa null).
     * 'bilinmiyor' her tip için kilitler; aylık 'kesildi' aynı dönemin ikinci kesimini engeller.
     */
    public function faturaKilidi(int $customerId, string $bas, string $son): ?string
    {
        $st = $this->pdo->prepare(
            "SELECT tip, durum FROM parasut_fatura_log
             WHERE customer_id = ? AND donem_bas <= ? AND donem_son >= ?
             ORDER BY id DESC"
        );
        $st->execute([$customerId, $son, $bas]);
        foreach ($st->fetchAll() as $r) {
            if ((string) $r['durum'] === 'bilinmiyor') {
                return 'Önceki fatura denemesi belirsiz — Paraşüt\'ten kontrol edin';
            }
            if ((string) $r['tip'] === 'aylik' && (string) $r['durum'] === 'kesildi') {
                return 'Bu dönem zaten faturalandı';
            }
        }
        return null;
    }

    public function faturaLog(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM parasut_fatura_log WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ?: null;
    }

    /**
     * Yeni fatura kaydı ekle (INSERT — UNIQUE yok). Başlangıç durumu genelde 'bilinmiyor'
     * (POST öncesi güvenli varsayılan: süreç yarıda kalırsa kilitli kalır, mükerrer olmaz).
     * @return int yeni kayıt id
     */
    public function faturaLogEkle(array $d): int
    {
        $enum = static fn(string $v, array $ok, string $def): string => in_array($v, $ok, true) ? $v : $def;
        $tip  = $enum((string) ($d['tip'] ?? 'irsaliye'), ['irsaliye', 'aylik'], 'irsaliye');
        $durum = $enum((string) ($d['durum'] ?? 'hata'), ['kesildi', 'hata', 'bilinmiyor', 'iptal'], 'hata');
        $resm = $enum((string) ($d['resmilestirme'] ?? 'yok'), ['gonderildi', 'hata', 'yok'], 'yok');
        $mail = $enum((string) ($d['mail'] ?? 'yok'), ['gonderildi', 'hata', 'yok'], 'yok');
        $sql = 'INSERT INTO parasut_fatura_log
                    (customer_id, donem_bas, donem_son, tip, parasut_contact_id, parasut_fatura_id,
                     fatura_no, alt_ad, kalemler, toplam_kisi, toplam_tutar, durum, resmilestirme, mail,
                     hata_mesaj, entered_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $this->pdo->prepare($sql)->execute([
            (int) $d['customer_id'],
            (string) $d['donem_bas'],
            (string) $d['donem_son'],
            $tip,
            $d['parasut_contact_id'] ?? null,
            $d['parasut_fatura_id'] ?? null,
            $d['fatura_no'] ?? null,
            $d['alt_ad'] ?? null,
            isset($d['kalemler']) ? json_encode($d['kalemler'], JSON_UNESCAPED_UNICODE) : null,
            (int) ($d['toplam_kisi'] ?? 0),
            (float) ($d['toplam_tutar'] ?? 0),
            $durum,
            $resm,
            $mail,
            isset($d['hata_mesaj']) ? mb_substr((string) $d['hata_mesaj'], 0, 490) : null,
            (string) ($d['entered_by'] ?? ''),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Var olan fatura kaydını güncelle (yalnız verilen alanlar). */
    public function faturaLogGuncelle(int $id, array $d): void
    {
        $set = [];
        $args = [];
        foreach ([
            'parasut_fatura_id', 'fatura_no', 'toplam_kisi', 'toplam_tutar', 'durum',
            'resmilestirme', 'mail', 'kalemler',
        ] as $k) {
            if (!array_key_exists($k, $d)) {
                continue;
            }
            if ($k === 'kalemler') {
                $set[] = 'kalemler = ?';
                $args[] = json_encode($d['kalemler'], JSON_UNESCAPED_UNICODE);
            } elseif ($k === 'durum') {
                $set[] = 'durum = ?';
                $args[] = in_array($d['durum'], ['kesildi', 'hata', 'bilinmiyor', 'iptal'], true) ? $d['durum'] : 'hata';
            } elseif ($k === 'resmilestirme') {
                $set[] = 'resmilestirme = ?';
                $args[] = in_array($d['resmilestirme'], ['gonderildi', 'hata', 'yok'], true) ? $d['resmilestirme'] : 'yok';
            } elseif ($k === 'mail') {
                $set[] = 'mail = ?';
                $args[] = in_array($d['mail'], ['gonderildi', 'hata', 'yok'], true) ? $d['mail'] : 'yok';
            } else {
                $set[] = "$k = ?";
                $args[] = $d[$k];
            }
        }
        if (array_key_exists('hata_mesaj', $d)) {
            $set[] = 'hata_mesaj = ?';
            $args[] = $d['hata_mesaj'] === null ? null : mb_substr((string) $d['hata_mesaj'], 0, 490);
        }
        if (!$set) {
            return;
        }
        $set[] = 'updated_at = ' . $this->nowExpr();
        $args[] = $id;
        $this->pdo->prepare('UPDATE parasut_fatura_log SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
    }

    /**
     * İrsaliye satırlarını bir faturaya BAĞLA (yalnız hâlâ boş olanları — atomik claim).
     * @param array<int,int> $irsaliyeIds
     * @return int gerçekten bağlanan satır sayısı (beklenenden azsa çakışma vardır)
     */
    public function irsaliyeleriFaturayaBagla(array $irsaliyeIds, int $faturaLogId): int
    {
        $ids = array_values(array_unique(array_map('intval', $irsaliyeIds)));
        if (!$ids) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare(
            "UPDATE parasut_irsaliye_log SET fatura_log_id = ?
             WHERE fatura_log_id IS NULL AND id IN ($ph)"
        );
        $st->execute(array_merge([$faturaLogId], $ids));
        return $st->rowCount();
    }

    /** Bağı GERİ AL (fatura başarısız → irsaliyeler tekrar aday olsun). Yalnız bu faturaya bağlı olanlar. */
    public function irsaliyeleriFaturadanCoz(array $irsaliyeIds, int $faturaLogId): int
    {
        $ids = array_values(array_unique(array_map('intval', $irsaliyeIds)));
        if (!$ids) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare(
            "UPDATE parasut_irsaliye_log SET fatura_log_id = NULL
             WHERE fatura_log_id = ? AND id IN ($ph)"
        );
        $st->execute(array_merge([$faturaLogId], $ids));
        return $st->rowCount();
    }

    /**
     * fable-024: Fatura Kes ekranının tek gerçek kaynağı — dönemdeki faturalanabilir müşteriler.
     * İki tip: 'irsaliye' (faturalanmamış kesilmiş irsaliyelerin öğün toplamı) ve
     * 'aylik' (irsaliye_aktif=0 → dönemdeki production.persons toplamı × birim; CANTAŞ bölüşümlü).
     * Seçilemeyen de listelenir (sessiz atlama yok, sebep yazılı).
     * @return array<int,array<string,mixed>>
     */
    public function faturaAdaylari(string $bas, string $son): array
    {
        $ay = substr($son, 0, 7);
        $out = [];
        $rows = $this->pdo->query('SELECT * FROM customers WHERE is_active = 1 ORDER BY name, id')->fetchAll();
        foreach ($rows as $c) {
            $cid = (int) $c['id'];
            $parasutId = trim((string) ($c['parasut_id'] ?? ''));
            $irsAktif = (int) ($c['irsaliye_aktif'] ?? 1) === 1;
            $kilit = $this->faturaKilidi($cid, $bas, $son);
            $birim = $this->priceFor($cid, $ay)['unit_price'];

            if ($irsAktif) {
                $irs = $this->faturaAdayIrsaliyeler($cid, $bas, $son);
                if (!$irs && $kilit === null) {
                    continue; // faturalanacak irsaliye yok → listeleme
                }
                $ogle = $aksam = $kumanya = 0;
                $irsaliyeIds = $docIds = $nolar = [];
                foreach ($irs as $r) {
                    $irsaliyeIds[] = (int) $r['id'];
                    if (($r['parasut_doc_id'] ?? '') !== '') {
                        $docIds[] = (string) $r['parasut_doc_id'];
                    }
                    if (($r['despatch_no'] ?? '') !== '') {
                        $nolar[] = (string) $r['despatch_no'];
                    }
                    foreach (json_decode((string) ($r['kalemler'] ?? '[]'), true) ?: [] as $k) {
                        $m = (int) ($k['miktar'] ?? 0);
                        $og = (string) ($k['ogun'] ?? '');
                        if ($og === 'ogle') {
                            $ogle += $m;
                        } elseif ($og === 'aksam') {
                            $aksam += $m;
                        } elseif ($og === 'kumanya') {
                            $kumanya += $m;
                        }
                    }
                }
                $toplam = $ogle + $aksam + $kumanya;
                $secilebilir = true;
                $sebep = '';
                if ($parasutId === '') {
                    $secilebilir = false;
                    $sebep = 'Paraşüt eşleşmesi yok';
                } elseif ($kilit !== null) {
                    // timeout sonrası irsaliye kilitli olduğundan $irs boş olabilir — kilit önce gelir.
                    $secilebilir = false;
                    $sebep = $kilit;
                } elseif (!$irs || $toplam <= 0) {
                    $secilebilir = false;
                    $sebep = 'Faturalanacak irsaliye yok';
                }
                $out[] = [
                    'customer_id'   => $cid,
                    'name'          => (string) $c['name'],
                    'tip'           => 'irsaliye',
                    'parasut_id'    => $parasutId,
                    'ogle'          => $ogle, 'aksam' => $aksam, 'kumanya' => $kumanya,
                    'toplam'        => $toplam,
                    'irsaliye_sayisi' => count($irs),
                    'irsaliye_ids'  => $irsaliyeIds,
                    'doc_ids'       => $docIds,
                    'despatch_nolar' => $nolar,
                    'birim'         => $birim,
                    'tevkifat_kodu' => trim((string) ($c['tevkifat_kodu'] ?? '')),
                    'tevkifat_oran' => $c['tevkifat_oran'] !== null ? (float) $c['tevkifat_oran'] : null,
                    'vade_gun'      => (int) ($c['fatura_vade_gun'] ?? 1),
                    'secilebilir'   => $secilebilir,
                    'sebep'         => $sebep,
                ];
                continue;
            }

            // ── aylık müşteri (irsaliye_aktif=0) ──
            $adet = $this->productionPersonsRange($cid, $bas, $son);
            if ($adet <= 0 && $kilit === null) {
                continue;
            }
            $bolusum = null;
            $bRaw = trim((string) ($c['fatura_bolusum'] ?? ''));
            if ($bRaw !== '') {
                $dec = json_decode($bRaw, true);
                if (is_array($dec)) {
                    $bolusum = [];
                    foreach ($dec as $p) {
                        $key = (string) ($p['key'] ?? '');
                        $bolusum[] = [
                            'key'        => $key,
                            'ad'         => (string) ($p['ad'] ?? $key),
                            'contact_id' => trim((string) $this->ayar($key, '')),
                        ];
                    }
                }
            }
            $secilebilir = true;
            $sebep = '';
            if ($bolusum !== null) {
                foreach ($bolusum as $p) {
                    if ($p['contact_id'] === '') {
                        $secilebilir = false;
                        $sebep = 'Bölüşüm cari eşleşmesi eksik (' . $p['ad'] . ')';
                        break;
                    }
                }
            } elseif ($parasutId === '') {
                $secilebilir = false;
                $sebep = 'Paraşüt eşleşmesi yok — cari açılınca aktif olur';
            }
            if ($secilebilir && $adet <= 0) {
                $secilebilir = false;
                $sebep = 'Bu dönemde üretim yok';
            }
            if ($secilebilir && $kilit !== null) {
                $secilebilir = false;
                $sebep = $kilit;
            }
            $out[] = [
                'customer_id' => $cid,
                'name'        => (string) $c['name'],
                'tip'         => 'aylik',
                'parasut_id'  => $parasutId,
                'adet'        => $adet,
                'birim'       => $birim,
                'vade_gun'    => (int) ($c['fatura_vade_gun'] ?? 1),
                'bolusum'     => $bolusum,
                'son_bolusum' => $bolusum !== null ? $this->faturaSonBolusum($cid) : null,
                'secilebilir' => $secilebilir,
                'sebep'       => $sebep,
            ];
        }
        return $out;
    }

    /**
     * Aylık bölüşümlü müşterinin (CANTAŞ) SON kesilen faturalarındaki kişi dağılımı
     * (contact_id => kişi) — UI'da varsayılan bölüşüm oranı olarak kullanılır.
     * @return array<string,int>|null
     */
    public function faturaSonBolusum(int $customerId): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT donem_son FROM parasut_fatura_log
             WHERE customer_id = ? AND tip = 'aylik' AND durum = 'kesildi'
             ORDER BY donem_son DESC, id DESC LIMIT 1"
        );
        $st->execute([$customerId]);
        $son = $st->fetchColumn();
        if ($son === false) {
            return null;
        }
        $st = $this->pdo->prepare(
            "SELECT parasut_contact_id, toplam_kisi FROM parasut_fatura_log
             WHERE customer_id = ? AND tip = 'aylik' AND durum = 'kesildi' AND donem_son = ?"
        );
        $st->execute([$customerId, $son]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $cid = trim((string) ($r['parasut_contact_id'] ?? ''));
            if ($cid !== '') {
                $out[$cid] = (int) $r['toplam_kisi'];
            }
        }
        return $out ?: null;
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
        $ar = $this->ayAralik($ay); // fable-042: cari ayda MTD
        $st = $this->pdo->prepare(
            'SELECT COALESCE(SUM(persons),0) FROM production
             WHERE customer_id = ? AND prod_date BETWEEN ? AND ?'
        );
        $st->execute([$customerId, $ar['bas'], $ar['son']]);
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
    /**
     * fable-031: O ay 'Taşıma alış' kategorili GERÇEK fatura toplamı (KIRMIZI 1).
     * >0 ise taşıma kârında matbu birim yerine bu kullanılır (Ömer: "olmazsa matbu kabul etme").
     */
    public function tasimaAlisFatura(string $ay): float
    {
        $st = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM transactions
             WHERE type = 'gider' AND category = 'Taşıma alış' AND substr(tx_date,1,7) = ?"
        );
        $st->execute([$ay]);
        return (float) $st->fetchColumn();
    }

    /**
     * fable-031b: O ay taşıma alış faturalarının GERÇEK BİRİM fiyatı (KDV hariç) —
     * fatura satırlarından: Σnet_amount / Σqty (KIRMIZI kanıtı: 345.275/1.973 = 175,00).
     * Ay ortasında toplam/ay-adedi bölmek YANILTIR (Ömer 23 Tem dersi); birim faturadan okunur.
     * Satır verisi (UBL) okunamamış kayıt varsa null döner → çağıran adet-oranlı yönteme düşer.
     */
    public function tasimaAlisBirim(string $ay): ?float
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) AS n, COALESCE(SUM(qty),0) AS q, COALESCE(SUM(net_amount),0) AS net,
                    SUM(CASE WHEN qty IS NULL OR qty <= 0 THEN 1 ELSE 0 END) AS satirsiz
             FROM transactions
             WHERE type = 'gider' AND category = 'Taşıma alış' AND substr(tx_date,1,7) = ?"
        );
        $st->execute([$ay]);
        $r = $st->fetch();
        if (!$r || (int) $r['n'] === 0 || (int) $r['satirsiz'] > 0 || (float) $r['q'] <= 0) {
            return null;
        }
        return round((float) $r['net'] / (float) $r['q'], 2);
    }

    /** O ay TÜM taşıma müşterilerinin toplam adedi (gerçek alışı adet-oranlı dağıtmak için). */
    public function tasimaToplamAdet(string $ay): int
    {
        $ar = $this->ayAralik($ay); // fable-042: cari ayda MTD
        $st = $this->pdo->prepare(
            "SELECT COALESCE(SUM(p.persons),0) FROM production p
             JOIN customers c ON c.id = p.customer_id
             WHERE c.category = 'tasima' AND p.prod_date BETWEEN ? AND ?"
        );
        $st->execute([$ar['bas'], $ar['son']]);
        return (int) $st->fetchColumn();
    }

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

        // fable-031/031b (Ömer): 'Taşıma alış' faturası (KIRMIZI 1) varsa GERÇEK maliyet esas.
        // ÖNCELİK: fatura satırından okunan BİRİM (KDV hariç; satışla elmayla elma — 175,00 kanıtı).
        // Satır verisi yoksa: ay toplamı adet-oranlı dağıtılır (yaklaşık; ay kapanınca oturur).
        // Fatura hiç yoksa: matbu birim (kart). Ay ortasında toplam/ay-adedi YANILTIR — birim esas.
        $alisKaynak = 'matbu';
        $toplamAlis = $adet * $alis;
        $birimGercek = $this->tasimaAlisBirim($ay);
        if ($birimGercek !== null && $adet > 0) {
            $alis = $birimGercek;
            $toplamAlis = round($adet * $alis, 2);
            $alisKaynak = 'fatura';
        } else {
            $fatura = $this->tasimaAlisFatura($ay);
            if ($fatura > 0) {
                $tumAdet = $this->tasimaToplamAdet($ay);
                if ($tumAdet > 0 && $adet > 0) {
                    $toplamAlis = round($fatura * $adet / $tumAdet, 2);
                    $alis = round($toplamAlis / $adet, 2);
                    $alisKaynak = 'fatura-oran';
                }
            }
        }

        $brut  = ($adet * $satis) - $toplamAlis;
        $net   = $brut - $sabit;
        return [
            'adet'         => $adet,
            'satis'        => $satis,
            'alis'         => $alis,
            'birim_satis'  => $satis,   // UI geriye dönük ad
            'birim_alis'   => $alis,
            'toplam_satis' => $adet * $satis,
            'toplam_alis'  => $toplamAlis,
            'brut'         => $brut,
            'sabit'        => $sabit,
            'sabit_gider'  => $sabit,   // UI geriye dönük ad
            'net'          => $net,
            'kar'          => $net,     // UI geriye dönük ad
            'alis_kaynak'  => $alisKaynak, // 'fatura' = KIRMIZI 1 gerçek maliyet · 'matbu' = kart birimi
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
    /**
     * Tek müşteri×gün×öğün üretim upsert. Tutar = kişi × birim fiyat snapshot.
     * fable-040: $amountOverride verilirse tutar bundan yazılır (fatura kişi kuralı — üretim ≠
     *   fatura; persons GERÇEK kalır, ciro fatura kişisinden). Kural saveDayMeals'ta uygulanır.
     */
    public function upsertProduction(
        int $customerId,
        string $prodDate,
        int $persons,
        float $unitPrice,
        string $meal = 'ogle',
        string $enteredBy = 'uysa',
        ?int $orderId = null,
        ?string $note = null,
        ?float $amountOverride = null
    ): array {
        $amount = round($amountOverride ?? ($persons * $unitPrice), 2);
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

    /**
     * Bir önceki üretim günü (dünü kopyala kaynağı).
     * fable-023a: $meal = null → öğün farkı gözetmez (sadece akşam kaydı olan gün de bulunur).
     */
    public function previousProductionDate(string $beforeDate, ?string $meal = 'ogle'): ?string
    {
        if ($meal === null) {
            $st = $this->pdo->prepare('SELECT MAX(prod_date) FROM production WHERE prod_date < ?');
            $st->execute([$beforeDate]);
            $d = $st->fetchColumn();
            return $d ?: null;
        }
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

    // ── fable-023a: öğün kırılımı (öğlen / akşam / kumanya) ────────
    // NEDEN: irsaliye kalemleri öğün bazlı kesiliyor (ÖĞLEN/AKŞAM/KUMANYA YEMEK BEDELİ).
    // Bugün ekranı yalnız 'ogle' okuyordu → akşam/kumanya kayıtları ciroya girmiyordu.

    /** Bugün ekranının öğün kırılımında kullandığı öğünler (sırayla gösterilir). */
    public const MEALS = ['ogle', 'aksam', 'kumanya'];

    /** Tek öğüne yazılabilecek üst sınır — hatalı/çok büyük giriş DB'ye taşmasın. */
    public const MEAL_MAX = 1000000;

    /**
     * fable-023a: Belirli gün için müşteri × ÖĞÜN KIRILIMI (girilmeyen öğün 0).
     * dayGrid imzası bozulmaz — bu ayrı bir metot; 3 öğünü toplayan tek gerçek kaynak.
     * @return array<int,array{customer_id:int,name:string,unit_price:float,
     *   ogle:int,aksam:int,kumanya:int,toplam:int,tutar:float}>
     */
    public function dayGridAllMeals(string $prodDate): array
    {
        $st = $this->pdo->prepare(
            'SELECT c.id AS customer_id, c.name, c.unit_price, c.fatura_kisi_haftaici,
                    p.meal, p.persons, p.amount
             FROM customers c
             LEFT JOIN production p
               ON p.customer_id = c.id AND p.prod_date = ?
             WHERE c.is_active = 1
             ORDER BY c.name, c.id'
        );
        $st->execute([$prodDate]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $cid = (int) $r['customer_id'];
            if (!isset($out[$cid])) {
                $out[$cid] = [
                    'customer_id' => $cid,
                    'name' => (string) $r['name'],
                    'unit_price' => (float) $r['unit_price'],
                    // fable-040: fatura kişi kuralı (hafta içi sabit; null = kural yok) — ciro bundan
                    'fatura_kisi' => $r['fatura_kisi_haftaici'] !== null ? (int) $r['fatura_kisi_haftaici'] : null,
                    'ogle' => 0, 'aksam' => 0, 'kumanya' => 0,
                    'toplam' => 0, 'tutar' => 0.0,
                ];
            }
            if ($r['meal'] === null) {
                continue; // müşteri var, o güne kayıt yok
            }
            $p = (int) $r['persons'];
            $meal = (string) $r['meal'];
            // Ekranda yeri olmayan öğünler (sabah/gece) toplama girer ama öğlene yazılmaz —
            // sayaçlar eksik göstermesin, kırılım kutuları da yanlış dolmasın.
            if (in_array($meal, self::MEALS, true)) {
                $out[$cid][$meal] += $p;
            }
            $out[$cid]['toplam'] += $p;
            $out[$cid]['tutar'] += (float) $r['amount'];
        }
        return array_values($out);
    }

    /** @return array{ogle:int,aksam:int,kumanya:int} tek müşterinin o günkü kırılımı */
    public function customerDayMeals(int $customerId, string $prodDate): array
    {
        $st = $this->pdo->prepare(
            'SELECT meal, persons FROM production WHERE customer_id = ? AND prod_date = ?'
        );
        $st->execute([$customerId, $prodDate]);
        $out = ['ogle' => 0, 'aksam' => 0, 'kumanya' => 0];
        foreach ($st->fetchAll() as $r) {
            if (isset($out[$r['meal']])) {
                $out[$r['meal']] = (int) $r['persons'];
            }
        }
        return $out;
    }

    /**
     * fable-040: Bir müşteri×gün için FATURA KİŞİSİ (ciro/fatura bu sayıdan). Kural
     * (customers.fatura_kisi_haftaici) VARSA + gün HAFTA İÇİ (Pzt–Cum) + gerçek üretim>0 ise
     * kural sayısı; aksi (kuralsız / cumartesi-pazar / üretim yok) gerçek toplam. persons GERÇEK
     * üretim kalır (maliyet/kişi-başı bundan); yalnız amount/ciro bu sayıdan hesaplanır.
     */
    public static function faturaKisiToplam(int $gercekToplam, ?int $kuralHaftaici, string $prodDate): int
    {
        if ($gercekToplam <= 0 || $kuralHaftaici === null || $kuralHaftaici <= 0) {
            return $gercekToplam;
        }
        $dow = (int) date('N', strtotime($prodDate)); // 1=Pzt … 7=Paz
        return $dow <= 5 ? $kuralHaftaici : $gercekToplam;
    }

    /**
     * fable-023a: 3 öğünü tek işlemde yaz — >0 upsert, 0/eksik ise sil.
     * Bağlı alanlar atomik gider: çağıran tek transaction içinde çağırır.
     * fable-040: $faturaKisiHaftaici verilirse (hafta içi) gün TOPLAM cirosu kuraldan; fark
     *   (fatura − üretim) birincil öğünün (öğle > akşam > kumanya) amount'una biner — persons
     *   dokunulmaz. Tek öğünlü (CANTAŞ) da çok öğünlü kurallı müşteri de tek noktadan doğru.
     * @param array<string,int> $meals ['ogle'=>..,'aksam'=>..,'kumanya'=>..]
     * @return array{ogle:int,aksam:int,kumanya:int,toplam:int} yazılan (normalize) değerler
     */
    public function saveDayMeals(
        int $customerId,
        string $prodDate,
        array $meals,
        float $unitPrice,
        string $enteredBy = 'uysa',
        ?int $faturaKisiHaftaici = null
    ): array {
        $norm = [];
        foreach (self::MEALS as $meal) {
            $norm[$meal] = self::normalizePersons($meals[$meal] ?? 0);
        }
        $realTotal = $norm['ogle'] + $norm['aksam'] + $norm['kumanya'];
        $billTotal = self::faturaKisiToplam($realTotal, $faturaKisiHaftaici, $prodDate);
        $fark = $billTotal - $realTotal;
        // Farkı taşıyacak birincil öğün (var olan ilki: öğle, sonra akşam, sonra kumanya).
        $absorb = null;
        if ($fark !== 0) {
            foreach (self::MEALS as $meal) {
                if ($norm[$meal] > 0) { $absorb = $meal; break; }
            }
        }
        $written = [];
        foreach (self::MEALS as $meal) {
            $p = $norm[$meal];
            $written[$meal] = $p;
            if ($p > 0) {
                $override = $meal === $absorb ? ($p + $fark) * $unitPrice : null;
                $this->upsertProduction($customerId, $prodDate, $p, $unitPrice, $meal, $enteredBy, null, null, $override);
            } else {
                $this->deleteProduction($customerId, $prodDate, $meal);
            }
        }
        $written['toplam'] = $written['ogle'] + $written['aksam'] + $written['kumanya'];
        return $written;
    }

    /** Negatif/boş/aşırı büyük girişi güvenli tam sayıya indirger. */
    public static function normalizePersons(int|string|null $v): int
    {
        $n = (int) $v;
        if ($n < 0) {
            return 0;
        }
        return $n > self::MEAL_MAX ? self::MEAL_MAX : $n;
    }

    /**
     * fable-023a: Toplam kutusu DOĞRUDAN düzenlendiğinde kırılımı türet —
     * fark öğlene yazılır, akşam/kumanya korunur (ekranda da yazan kural).
     * Toplam akşam+kumanya'nın altına düşerse (kural gereği imkânsız değil) öğlen 0 olur ve
     * yazılan toplam TUTSUN diye önce kumanya, sonra akşam kısılır — ekran yalan söylemesin.
     * @param array<string,int> $current mevcut kırılım
     * @return array{ogle:int,aksam:int,kumanya:int}
     */
    /**
     * fable-027c: Müşterinin hedef günden ÖNCEKİ son kayıtlı gününün öğün kırılımı (yoksa null).
     * NEDEN: yeni güne yalnız TOPLAM girildiğinde kırılım kaybolmasın (PENDORYA 58 = 25/25/8
     * dersi — 22 Tem belgesi tek öğün kesildi). Taban olarak kullanılır; fark-öğlene kuralı korunur.
     */
    public function lastKnownMeals(int $customerId, string $beforeDate): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT MAX(prod_date) FROM production WHERE customer_id = ? AND prod_date < ?'
        );
        $st->execute([$customerId, $beforeDate]);
        $d = $st->fetchColumn();
        if (!$d) {
            return null;
        }
        return $this->customerDayMeals($customerId, (string) $d);
    }

    /**
     * fable-027c: Son N günde (hedef gün hariç) akşam/kumanya kaydı var mıydı?
     * Kesim onayında "dün kırılımlıydı, bugün tek öğün" uyarısının kaynağı.
     */
    public function hadRecentSplit(int $customerId, string $beforeDate, int $days = 7): bool
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) FROM production
             WHERE customer_id = ? AND prod_date < ? AND prod_date >= ?
               AND meal IN ('aksam','kumanya') AND persons > 0"
        );
        $st->execute([$customerId, $beforeDate, date('Y-m-d', strtotime($beforeDate . ' -' . $days . ' day'))]);
        return (int) $st->fetchColumn() > 0;
    }

    public static function mealsFromTotal(int|string|null $newTotal, array $current): array
    {
        $total = self::normalizePersons($newTotal);
        $aksam = self::normalizePersons($current['aksam'] ?? 0);
        $kumanya = self::normalizePersons($current['kumanya'] ?? 0);
        $rest = $total - $aksam - $kumanya;
        if ($rest >= 0) {
            return ['ogle' => $rest, 'aksam' => $aksam, 'kumanya' => $kumanya];
        }
        $eksik = -$rest;
        $kes = min($kumanya, $eksik);
        $kumanya -= $kes;
        $eksik -= $kes;
        $aksam = max(0, $aksam - $eksik);
        return ['ogle' => 0, 'aksam' => $aksam, 'kumanya' => $kumanya];
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
    /**
     * fable-030: Paraşüt gider senkronu — bir ayın faturalarını transactions'a işler.
     * Mükerrer kalkanı: parasut_id UNIQUE (aynı fatura iki kez düşmez, elle girilenler ezilmez).
     * Gelen-kutusu kaydı daha önce 'ei-{id}' ile girdiyse, içeri alınınca oluşan purchase_bill
     * kimliğiyle TEKRAR girmesin diye pb_ref köprüsü de kontrol edilir (Hikari deseni).
     * @param array<int,array<string,mixed>> $bills
     * @return array{yeni:int,mevcut:int,tutar:float}
     */
    public function parasutGiderIsle(array $bills): array
    {
        $st = $this->pdo->prepare("SELECT parasut_id FROM transactions WHERE parasut_id IS NOT NULL");
        $st->execute();
        $mevcutIds = array_flip(array_column($st->fetchAll(), 'parasut_id'));
        $yeni = 0;
        $mevcut = 0;
        $tutarTop = 0.0;
        $ins = $this->pdo->prepare(
            "INSERT INTO transactions (type, category, tx_date, amount, description, source, parasut_id, qty, net_amount, alloc_type)
             VALUES ('gider', ?, ?, ?, ?, 'parasut', ?, ?, ?, 'genel')"
        );
        // fable-031 (Ömer): taşıma yemeğinin alındığı tedarikçi (KIRMIZI 1) faturaları
        // 'Taşıma alış' kategorisine işlenir — taşıma kârında matbu birim yerine GERÇEK maliyet
        // olur ve genel gider dağıtım havuzuna GİRMEZ (çift sayım olmaz). Anahtar ayarda.
        $tasimaAnahtar = [];
        foreach (explode(',', (string) $this->ayar('tasima_alis_tedarikci', 'KIRMIZI')) as $k) {
            // Türkçe İ tuzağı (Hikari dersi): mb_strtolower('İ') bozuk — önce elle küçült.
            $k = mb_strtolower(strtr(trim($k), ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
            if ($k !== '') {
                $tasimaAnahtar[] = $k;
            }
        }
        foreach ($bills as $b) {
            $pid = (string) ($b['parasut_id'] ?? '');
            if ($pid === '' || (float) ($b['tutar'] ?? 0) <= 0) {
                continue;
            }
            $tedNorm = mb_strtolower(strtr((string) ($b['tedarikci'] ?? ''), ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
            foreach ($tasimaAnahtar as $k) {
                if ($k !== '' && str_contains($tedNorm, $k)) {
                    $b['kategori_ad'] = 'Taşıma alış';
                    break;
                }
            }
            // Çift-gider köprüsü: pb kimliği geldiyse ama aynı fatura ei- anahtarıyla zaten
            // girdiyse atla; ei- kaydı geldiyse ve içeri-alınmış pb karşılığı zaten girdiyse atla.
            $pbRef = (string) ($b['pb_ref'] ?? '');
            if (isset($mevcutIds[$pid])
                || ($pbRef !== '' && isset($mevcutIds[$pbRef]))
                || (!str_starts_with($pid, 'ei-') && isset($mevcutIds['ei-' . $pid]))) {
                $mevcut++;
                continue;
            }
            $kategori = trim((string) ($b['kategori_ad'] ?? ''));
            if ($kategori === '') {
                $kategori = 'Tedarikçi faturası';
            }
            $aciklama = trim((string) ($b['tedarikci'] ?? ''));
            if (($b['fatura_no'] ?? '') !== '') {
                $aciklama .= ($aciklama !== '' ? ' · ' : '') . $b['fatura_no'];
            }
            // fable-031b: Taşıma alış faturasının SATIRLARI (miktar + KDV-hariç tutar) UBL'den —
            // gerçek BİRİM fiyat hesabı için. Okunamazsa null (birim yolu oran yöntemine düşer).
            $satir = null;
            if ($kategori === 'Taşıma alış' && str_starts_with($pid, 'ei-')) {
                try {
                    $satir = \Uysa\Parasut::eInvoiceLineTotals(substr($pid, 3));
                } catch (\Throwable) {
                    $satir = null;
                }
            }
            $ins->execute([$kategori, (string) $b['gun'], round((float) $b['tutar'], 2),
                $aciklama !== '' ? mb_substr($aciklama, 0, 490) : null, $pid,
                $satir['adet'] ?? null, $satir['net'] ?? null]);
            $mevcutIds[$pid] = true;
            $yeni++;
            $tutarTop += (float) $b['tutar'];
        }
        return ['yeni' => $yeni, 'mevcut' => $mevcut, 'tutar' => round($tutarTop, 2)];
    }

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

    /**
     * fable-031 (Ömer): "firma firma aylık bana ne fatura kesiyor" — gider FİRMA özeti.
     * Paraşüt kaydında firma = description'ın ' · ' öncesi (senkron 'TEDARİKÇİ · faturaNo' yazar);
     * elle girilende firma bilinmez → 'Elle girilen · {kategori}' grubu. Sürücü bağımsız (PHP grupla).
     * @return array<int,array{firma:string,adet:int,toplam:float}> toplam DESC
     */
    public function giderFirmaOzet(string $month): array
    {
        $st = $this->pdo->prepare(
            "SELECT t.source, t.category, t.description, t.amount, s.name AS supplier_name
             FROM transactions t
             LEFT JOIN suppliers s ON s.id = t.supplier_id
             WHERE t.type = 'gider' AND substr(t.tx_date,1,7) = ?"
        );
        $st->execute([$month]);
        $grup = [];
        foreach ($st->fetchAll() as $r) {
            $firma = $this->txFirma($r);
            if (!isset($grup[$firma])) {
                $grup[$firma] = ['firma' => $firma, 'adet' => 0, 'toplam' => 0.0];
            }
            $grup[$firma]['adet']++;
            $grup[$firma]['toplam'] += (float) $r['amount'];
        }
        usort($grup, static fn(array $a, array $b) => $b['toplam'] <=> $a['toplam']);
        return array_values($grup);
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

    /**
     * Native app rozeti (fable-018): customer_events TEK GERÇEK KAYNAK. App'in son token
     * kaydından (push_tokens.last_seen, her açılışta push-register ile yenilenir → "app açılınca
     * sıfırlama korunur") sonraki tüm müşteri olayları sayılır. Akış footer rozetiyle aynı kaynak.
     */
    public function badgeCountFor(int $customerId): int
    {
        $seen = $this->pdo->prepare('SELECT MAX(last_seen) FROM push_tokens WHERE customer_id = ?');
        $seen->execute([$customerId]);
        $lastSeen = $seen->fetchColumn();
        if ($lastSeen === false || $lastSeen === null || $lastSeen === '') {
            return 0;
        }
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM customer_events WHERE customer_id = ? AND created_at > ?'
        );
        $st->execute([$customerId, (string) $lastSeen]);
        return min(99, (int) $st->fetchColumn());
    }

    // ── Müşteri olay akışı (fable-018) ────────────────────────
    // TEK KAPI: push atılan/atılamayan HER müşteri-yüzlü olay buradan feed'e yazılır.
    // Feed = push'un kalıcı karşılığı; badge (native + Akış footer) bu tablodan sayar.
    public const CUSTOMER_EVENT_TYPES = ['menu_yayin', 'talep_cevap', 'siparis_durum', 'malzeme_durum'];

    /** Bir müşteriye olay yaz. Geçersiz type → 'talep_cevap'e düşmez, olduğu gibi kabul edilmez → filtrelenir. */
    public function addCustomerEvent(int $customerId, string $type, string $title, ?string $body, string $url): int
    {
        if (!in_array($type, self::CUSTOMER_EVENT_TYPES, true)) {
            $type = 'talep_cevap'; // bilinmeyen tür güvenli varsayılana düşer (sessiz veri kaybı yok)
        }
        $this->pdo->prepare(
            'INSERT INTO customer_events (customer_id, type, title, body, url)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$customerId, $type, mb_substr($title, 0, 200), $body === null ? null : mb_substr($body, 0, 300), mb_substr($url, 0, 200)]);
        return (int) $this->pdo->lastInsertId();
    }

    /** IDOR: sadece kendi customer_id olayları. Kronolojik (yeni→eski), sayfalı. @return array<int,array> */
    public function customerEvents(int $customerId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $st = $this->pdo->prepare(
            'SELECT id, type, title, body, url, created_at FROM customer_events
             WHERE customer_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $st->execute([$customerId]);
        return $st->fetchAll();
    }

    /** Akış footer rozeti: bu customer_user'ın feed_seen_at'inden sonraki olay sayısı (99+ kırpılır). */
    public function feedUnseenCount(int $customerId, int $cuid): int
    {
        $s = $this->pdo->prepare('SELECT feed_seen_at FROM customer_users WHERE id = ?');
        $s->execute([$cuid]);
        $seen = $s->fetchColumn();
        if ($seen === false || $seen === null || $seen === '') {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM customer_events WHERE customer_id = ?');
            $st->execute([$customerId]);
        } else {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM customer_events WHERE customer_id = ? AND created_at > ?');
            $st->execute([$customerId, (string) $seen]);
        }
        return min(99, (int) $st->fetchColumn());
    }

    /** Akış açılınca okundu kesimini şimdiye çek (o customer_user'a özel). */
    public function markFeedSeen(int $cuid): void
    {
        $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
        $this->pdo->prepare('UPDATE customer_users SET feed_seen_at = ' . $now . ' WHERE id = ?')->execute([$cuid]);
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

        // fable-047: statik liste → ANALİZ EDİLMİŞ maddeler. Her madde 'rows' taşır (tıklayınca
        // açılan detay: hangi müşteri/gün/tutar + derin bağlantı). 'info' = bilgi, uyarı SAYILMAZ.
        $eksikUretim = $this->ayEksikUretim($ay);
        $sapma = $this->ayAnormalSapma($ay);
        $belge = $this->ayBelgeDurumu($ay);
        $giderCache = $this->giderTamlikCache($ay);
        $eslesmeyen = $this->ayEslesmeyenTedarikci($ay);
        $personelDurum = $this->ayPersonelDurumu($ay);
        $tatilAnaliz = $this->ayTatilAnaliz($ay);

        $checks = [];
        $add = static function (string $key, string $label, string $status, string $detail, string $link = '', array $rows = [], string $rowsBaslik = '') use (&$checks): void {
            $checks[] = [
                'key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail,
                'link' => $link, 'rows' => $rows, 'rows_baslik' => $rowsBaslik,
            ];
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
            'uretim_eksik',
            'Eksik üretim günü',
            $eksikUretim['toplam'] === 0 ? 'ok' : 'warn',
            $eksikUretim['toplam'] === 0
                ? 'Geçmiş iş günlerinin tamamında sayı girilmiş.'
                : $eksikUretim['toplam'] . ' gün eksik · ' . count($eksikUretim['rows']) . ' müşteri (resmi tatiller hariç).',
            'rapor.php?ay=' . $ay,
            array_map(static fn(array $r) => [
                'ad'    => $r['name'],
                'meta'  => 'ilk eksik: ' . date('d.m.Y', strtotime((string) $r['ilk'])),
                'deger' => $r['eksik'] . ' gün',
                'link'  => 'bugun.php?date=' . $r['ilk'] . '&focus=' . $r['customer_id'],
                'link_ad' => 'Gir',
            ], $eksikUretim['rows']),
            'Sayı girilmemiş müşteri × gün'
        );
        $add(
            'tatil',
            'Tatil davranışı',
            $tatilAnaliz['durum'],
            $tatilAnaliz['ozet'],
            'tatiller.php',
            $tatilAnaliz['rows'],
            'Resmi tatilde çalışan / çalışmayan'
        );
        $add(
            'sapma',
            'Anormal sayı davranışı',
            $sapma['toplam'] === 0 ? 'ok' : 'warn',
            $sapma['toplam'] === 0
                ? 'Günlük ortalamadan %' . rtrim(rtrim(number_format($sapma['esik'], 1, ',', '.'), '0'), ',') . '+ sapan gün yok.'
                : $sapma['toplam'] . ' gün ortalamadan %' . rtrim(rtrim(number_format($sapma['esik'], 1, ',', '.'), '0'), ',') . '+ sapıyor · ' . count($sapma['rows']) . ' müşteri.',
            'rapor.php?ay=' . $ay,
            (static function (array $rows): array {
                $out = [];
                foreach ($rows as $r) {
                    foreach ($r['gunler'] as $g) {
                        $out[] = [
                            'ad'    => $r['name'],
                            'meta'  => date('d.m.Y', strtotime($g['gun'])) . ' · ort. ' . number_format($r['ort'], 1, ',', '.') . ' kişi',
                            'deger' => ($g['yon'] === 'dusuk' ? '↓ ' : '↑ ') . $g['kisi'] . ' kişi (%' . number_format($g['yuzde'], 1, ',', '.') . ')',
                            'link'  => 'bugun.php?date=' . $g['gun'] . '&focus=' . $r['customer_id'],
                            'link_ad' => 'Bak',
                        ];
                    }
                }
                return $out;
            })($sapma['rows']),
            'Ortalamadan sapan gün'
        );
        $add(
            'no_production',
            'Aktif müşteri sayımı',
            !$noProductionCustomers ? 'ok' : 'warn',
            !$noProductionCustomers ? 'Aktif müşterilerin tamamında bu ay kayıt var.' : count($noProductionCustomers) . ' aktif müşteride bu ay kayıt yok.',
            'bugun.php?date=' . $ay . '-01',
            array_map(static fn(array $r) => [
                'ad'    => $r['name'],
                'meta'  => $r['category'] === 'tasima' ? 'Taşıma' : 'Üretim',
                'deger' => 'kayıt yok',
                'link'  => 'bugun.php?date=' . $ay . '-01&focus=' . $r['customer_id'],
                'link_ad' => 'Gir',
            ], $noProductionCustomers),
            'Bu ay hiç kaydı olmayan müşteri'
        );
        $add(
            'zero_price',
            'Sıfır fiyat / tutar',
            (!$zeroPriceRows && !$priceIssues) ? 'ok' : 'fail',
            (!$zeroPriceRows && !$priceIssues) ? 'Fiyatı/tutarı sıfır görünen üretim yok.' : (count($zeroPriceRows) + count($priceIssues)) . ' kayıt/müşteri kontrol istiyor.',
            'musteriler.php',
            array_merge(
                array_map(static fn(array $r) => [
                    'ad'    => (string) $r['name'],
                    'meta'  => date('d.m.Y', strtotime((string) $r['prod_date'])) . ' · ' . (string) $r['meal'],
                    'deger' => (int) $r['persons'] . ' kişi · ₺ ' . Helpers::money((float) $r['amount']),
                    'link'  => 'bugun.php?date=' . (string) $r['prod_date'] . '&focus=' . (int) $r['customer_id'],
                    'link_ad' => 'Bak',
                ], $zeroPriceRows),
                array_map(static fn(array $r) => [
                    'ad'    => $r['name'],
                    'meta'  => $r['category'] === 'tasima' ? 'Taşıma — satış/alış birimi eksik' : 'Üretim — birim fiyat girilmemiş',
                    'deger' => 'fiyat yok',
                    'link'  => 'musteriler.php?musteri=' . $r['customer_id'],
                    'link_ad' => 'Düzelt',
                ], $priceIssues)
            ),
            'Fiyatı/tutarı sıfır görünen kayıt'
        );
        $add(
            'invoice',
            'Fatura / irsaliye',
            ((int) $fatura['adet'] > 0 && !$belge['fatura'] && $belge['irsaliye_eksik_gun'] === 0) ? 'ok' : 'warn',
            (int) $fatura['adet'] === 0 && !$belge['fatura'] && $belge['irsaliye_eksik_gun'] === 0
                ? 'Bu ay fatura kaydı yok.'
                : (int) $fatura['adet'] . ' fatura kaydı, ' . (int) $fatura['kesildi'] . ' kesildi · '
                    . count($belge['fatura']) . ' müşteride kesilmiş fatura yok · ' . $belge['irsaliye_eksik_gun'] . ' üretim günü irsaliyesiz.',
            'faturalar.php?ay=' . $ay,
            array_merge(
                array_map(static fn(array $r) => [
                    'ad'    => $r['name'],
                    'meta'  => 'faturası kesilmemiş · ay cirosu',
                    'deger' => '₺ ' . Helpers::money((float) $r['ciro']),
                    'link'  => 'fatura-kes.php?musteri=' . $r['customer_id'],
                    'link_ad' => 'Kes',
                ], $belge['fatura']),
                array_map(static fn(array $r) => [
                    'ad'    => $r['name'],
                    'meta'  => 'irsaliyesiz üretim günü · ilk: ' . date('d.m.Y', strtotime((string) $r['ilk'])),
                    'deger' => $r['eksik'] . ' gün',
                    'link'  => 'irsaliye.php?gun=' . $r['ilk'],
                    'link_ad' => 'Kes',
                ], $belge['irsaliye'])
            ),
            'Eksik belge'
        );
        $add(
            'gider_tamlik',
            'Gider tamlığı (Paraşüt)',
            $giderCache === null ? 'info' : ($giderCache['eksik'] > 0 ? 'warn' : 'ok'),
            $giderCache === null
                ? 'Henüz kontrol edilmedi — Paraşüt sayısı butonla çekilir (sayfa açılışında API çağrılmaz).'
                : ($giderCache['eksik'] > 0
                    ? $giderCache['eksik'] . ' fatura Paraşüt\'te var, Kokpit\'te yok (Paraşüt ' . $giderCache['parasut'] . ' · Kokpit ' . $giderCache['kokpit'] . ' · ' . $giderCache['at'] . ').'
                    : 'Paraşüt ' . $giderCache['parasut'] . ' fatura ↔ Kokpit ' . $giderCache['kokpit'] . ' gider — tam (' . $giderCache['at'] . ').'),
            'finans.php?ay=' . $ay
        );
        $add(
            'maliyet_esleme',
            'Maliyet eşleştirme boşluğu',
            !$eslesmeyen ? 'ok' : 'warn',
            !$eslesmeyen
                ? 'Bu ayın tüm tedarikçileri bir kırılıma/müşteriye eşlenmiş.'
                : count($eslesmeyen) . ' tedarikçi hiçbir kırılıma/müşteriye eşlenmemiş.',
            'tedarikci-eslestirme.php',
            array_map(static fn(array $r) => [
                'ad'    => $r['label'],
                'meta'  => $r['adet'] . ' fatura · eşleşme yok',
                'deger' => '₺ ' . Helpers::money((float) $r['toplam']),
                'link'  => 'tedarikci-eslestirme.php',
                'link_ad' => 'Eşle',
            ], $eslesmeyen),
            'Eşleşmemiş tedarikçi'
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
            'Personel',
            ((float) $nk['personel'] > 0 || $personelCount === 0) && !$personelDurum['odenmemis'] && !$personelDurum['atamasiz'] ? 'ok' : 'warn',
            ((float) $nk['personel'] > 0 ? 'Yüklü maliyet ₺ ' . Helpers::money((float) $nk['personel']) : ($personelCount === 0 ? 'Aktif personel yok' : 'Bu ay personel maliyeti sıfır görünüyor'))
                . ' · ' . count($personelDurum['odenmemis']) . ' maaş ödenmemiş · ' . count($personelDurum['atamasiz']) . ' atamasız.',
            'personel.php?ay=' . $ay,
            array_merge(
                array_map(static fn(array $r) => [
                    'ad'    => $r['ad'],
                    'meta'  => $r['islenmis'] ? 'maaş işlendi, ÖDENMEDİ' : 'maaş kaydı hiç girilmemiş',
                    'deger' => '₺ ' . Helpers::money((float) $r['ucret']),
                    'link'  => 'personel.php?ay=' . $ay,
                    'link_ad' => 'İşle',
                ], $personelDurum['odenmemis']),
                array_map(static fn(array $r) => [
                    'ad'    => $r['ad'],
                    'meta'  => 'müşteri ataması yok — maliyeti dağılmıyor',
                    'deger' => 'atamasız',
                    'link'  => 'tedarikci-eslestirme.php',
                    'link_ad' => 'Ata',
                ], $personelDurum['atamasiz'])
            ),
            'Kapanışı bekleyen personel'
        );
        $add(
            'negative_customers',
            'Negatif müşteri kârı',
            !$negativeCustomers ? 'ok' : 'warn',
            !$negativeCustomers ? 'Negatif net kâr veren müşteri yok.' : count($negativeCustomers) . ' müşteri negatifte.',
            'kar-analizi.php?ay=' . $ay,
            array_map(static fn(array $r) => [
                'ad'    => (string) $r['name'],
                'meta'  => 'net kâr negatif',
                'deger' => '₺ ' . Helpers::money((float) $r['net']),
                'link'  => 'rapor.php?musteri=' . (int) $r['customer_id'] . '&ay=' . $ay,
                'link_ad' => 'İncele',
            ], $negativeCustomers),
            'Zarar eden müşteri'
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
    // ══ fable-047: RESMİ TATİL takvimi ═══════════════════════════════════════════════
    // Tatil kaydı SİLİNMEZ (aktif=0 ile pasifleşir) — geçmiş tatil karşılaştırması bozulmasın.
    // 'arefe' (yarim_gun=1) TAM tatil sayılmaz: sayı girilir ama düşük olabilir.

    /**
     * Resmi tatil listesi (tarih ASC). $aktifOnly=false → pasifler de gelir (yönetim ekranı).
     * @return array<int,array{id:int,tarih:string,ad:string,tur:string,yarim_gun:int,aktif:int}>
     */
    public function resmiTatiller(bool $aktifOnly = true, ?string $bas = null, ?string $son = null): array
    {
        $sql = 'SELECT id, tarih, ad, tur, yarim_gun, aktif FROM resmi_tatil WHERE 1=1';
        $params = [];
        if ($aktifOnly) {
            $sql .= ' AND aktif = 1';
        }
        if ($bas !== null) {
            $sql .= ' AND tarih >= ?';
            $params[] = $bas;
        }
        if ($son !== null) {
            $sql .= ' AND tarih <= ?';
            $params[] = $son;
        }
        $sql .= ' ORDER BY tarih ASC';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function resmiTatil(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, tarih, ad, tur, yarim_gun, aktif FROM resmi_tatil WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ?: null;
    }

    /** Bir ayın AKTİF tatilleri: 'YYYY-MM-DD' → satır (analizde hızlı bakış için). */
    public function ayTatilleri(string $ay): array
    {
        $out = [];
        foreach ($this->resmiTatiller(true, $ay . '-01', $ay . '-31') as $t) {
            $out[(string) $t['tarih']] = $t;
        }
        return $out;
    }

    /**
     * Tatil ekle/güncelle. tarih UNIQUE — $id yoksa aynı tarihli kayıt varsa ÜZERİNE yazar
     * (mükerrer satır oluşmaz; pasifleştirilmiş kayıt yeniden girilince aktifleşir).
     * @return int etkilenen kaydın id'si
     */
    public function upsertResmiTatil(string $tarih, string $ad, string $tur = 'resmi', bool $yarimGun = false, ?int $id = null): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) {
            throw new \InvalidArgumentException('Geçersiz tarih: ' . $tarih);
        }
        $ad = trim($ad);
        if ($ad === '') {
            throw new \InvalidArgumentException('Tatil adı zorunlu.');
        }
        if (!in_array($tur, ['resmi', 'dini', 'arefe'], true)) {
            $tur = 'resmi';
        }
        $ad = mb_substr($ad, 0, 120);
        if ($id === null || $id <= 0) {
            $st = $this->pdo->prepare('SELECT id FROM resmi_tatil WHERE tarih = ?');
            $st->execute([$tarih]);
            $found = $st->fetchColumn();
            $id = $found === false ? null : (int) $found;
        }
        if ($id !== null && $id > 0) {
            $this->pdo->prepare('UPDATE resmi_tatil SET tarih = ?, ad = ?, tur = ?, yarim_gun = ?, aktif = 1 WHERE id = ?')
                ->execute([$tarih, $ad, $tur, $yarimGun ? 1 : 0, $id]);
            return $id;
        }
        $this->pdo->prepare('INSERT INTO resmi_tatil (tarih, ad, tur, yarim_gun, aktif) VALUES (?, ?, ?, ?, 1)')
            ->execute([$tarih, $ad, $tur, $yarimGun ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Tatili pasifleştir/aktifleştir (SİLME YOK — iz kalır). */
    public function setResmiTatilAktif(int $id, bool $aktif): void
    {
        $this->pdo->prepare('UPDATE resmi_tatil SET aktif = ? WHERE id = ?')->execute([$aktif ? 1 : 0, $id]);
    }

    /**
     * TAM $gunSonra gün sonraki aktif tatil (yoksa null). Sınır kesin: 2 ya da 4 gün sonrası DÖNMEZ.
     * @param string|null $bugun test için 'YYYY-MM-DD'; null → APP_TODAY/gerçek tarih.
     */
    public function yaklasanTatil(int $gunSonra = 3, ?string $bugun = null): ?array
    {
        $bugun ??= $this->bugun();
        $hedef = date('Y-m-d', strtotime($bugun . ' +' . max(0, $gunSonra) . ' day'));
        $st = $this->pdo->prepare('SELECT id, tarih, ad, tur, yarim_gun, aktif FROM resmi_tatil WHERE tarih = ? AND aktif = 1');
        $st->execute([$hedef]);
        $r = $st->fetch();
        return $r ?: null;
    }

    /** Verilen tarihten ÖNCEKİ en yakın aktif tatil (davranış kıyas tabanı). */
    public function oncekiTatil(string $tarih): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT id, tarih, ad, tur, yarim_gun, aktif FROM resmi_tatil
             WHERE aktif = 1 AND tarih < ? ORDER BY tarih DESC LIMIT 1'
        );
        $st->execute([$tarih]);
        $r = $st->fetch();
        return $r ?: null;
    }

    /** Bir günün müşteri bazlı TOPLAM kişi sayısı (tüm öğünler). customer_id → kişi. */
    private function gunKisiByCustomer(string $gun): array
    {
        $st = $this->pdo->prepare(
            'SELECT customer_id, COALESCE(SUM(persons),0) AS kisi FROM production
             WHERE prod_date = ? GROUP BY customer_id'
        );
        $st->execute([$gun]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['customer_id']] = (int) $r['kisi'];
        }
        return $out;
    }

    /**
     * TATİL DAVRANIŞI: o tatilde kim çalıştı / kimin sayısı girilmemiş + GEÇEN tatille kıyas.
     *   gecmis = 'calisti'  → geçen tatilde persons>0
     *           'calismadi' → geçen tatilde kaydı yoktu ama BAŞKA müşterilerin vardı (veri güvenilir)
     *           'veri_yok'  → geçen tatil yok ya da o gün HİÇ kayıt yok (teyit gerek)
     *   beklenmedik = geçen tatilde çalışmıştı ama bu tatile sayı GİRİLMEMİŞ (⚠ ay kapanışı + uyarı)
     * @return array{tarih:string,ad:string,tur:string,yarim_gun:bool,onceki:?array,onceki_veri:bool,
     *   rows:array<int,array>,calisan:int,kayitsiz:int,beklenmedik:int}
     */
    public function tatilDavranis(string $tarih): array
    {
        $st = $this->pdo->prepare('SELECT id, tarih, ad, tur, yarim_gun FROM resmi_tatil WHERE tarih = ?');
        $st->execute([$tarih]);
        $t = $st->fetch() ?: ['tarih' => $tarih, 'ad' => 'Tatil', 'tur' => 'resmi', 'yarim_gun' => 0];

        $onceki = $this->oncekiTatil($tarih);
        $bu = $this->gunKisiByCustomer($tarih);
        $onc = $onceki ? $this->gunKisiByCustomer((string) $onceki['tarih']) : [];
        $oncekiVeri = $onc !== [];

        $rows = [];
        $calisan = 0;
        $kayitsiz = 0;
        $beklenmedik = 0;
        foreach ($this->activeCustomers() as $c) {
            $cid = (int) $c['id'];
            $kayit = array_key_exists($cid, $bu);
            $kisi = (int) ($bu[$cid] ?? 0);
            if (!$oncekiVeri) {
                $gecmis = 'veri_yok';
            } else {
                $gecmis = (int) ($onc[$cid] ?? 0) > 0 ? 'calisti' : 'calismadi';
            }
            $bek = ($gecmis === 'calisti' && !$kayit);
            if ($kisi > 0) {
                $calisan++;
            }
            if (!$kayit) {
                $kayitsiz++;
            }
            if ($bek) {
                $beklenmedik++;
            }
            $rows[] = [
                'customer_id'    => $cid,
                'name'           => (string) $c['name'],
                'kayit'          => $kayit,
                'kisi'           => $kisi,
                'onceki_kisi'    => $oncekiVeri ? (int) ($onc[$cid] ?? 0) : null,
                'gecmis'         => $gecmis,
                'beklenmedik'    => $bek,
            ];
        }
        return [
            'tarih'       => (string) $t['tarih'],
            'ad'          => (string) $t['ad'],
            'tur'         => (string) $t['tur'],
            'yarim_gun'   => (int) ($t['yarim_gun'] ?? 0) === 1,
            'onceki'      => $onceki,
            'onceki_veri' => $oncekiVeri,
            'rows'        => $rows,
            'calisan'     => $calisan,
            'kayitsiz'    => $kayitsiz,
            'beklenmedik' => $beklenmedik,
        ];
    }

    /**
     * fable-047: AY KAPANIŞI "tatil davranışı" maddesi — o ayki resmi tatillerde kim çalıştı,
     * kim çalışmadı, hangisi BEKLENMEDİK (geçen tatilde çalışmıştı ama bu sefer sayı yok).
     * Yarım gün (arefe) tam tatil sayılmaz — sayı girilmesi normaldir, uyarı üretmez.
     * @return array{durum:string,ozet:string,rows:array<int,array<string,string>>}
     */
    public function ayTatilAnaliz(string $ay): array
    {
        $tatiller = $this->ayTatilleri($ay);
        if (!$tatiller) {
            return ['durum' => 'info', 'ozet' => 'Bu ay resmi tatil yok.', 'rows' => []];
        }

        $gunAdi = ['Mon' => 'Pzt', 'Tue' => 'Sal', 'Wed' => 'Çar', 'Thu' => 'Per', 'Fri' => 'Cum', 'Sat' => 'Cmt', 'Sun' => 'Paz'];
        $rows = [];
        $beklenmedikTop = 0;
        $veriYok = 0;
        $ozetPars = [];
        foreach ($tatiller as $tarih => $t) {
            $d = $this->tatilDavranis((string) $tarih);
            $gunTr = $gunAdi[date('D', strtotime((string) $tarih))] ?? '';
            $etiket = $d['ad'] . ' (' . date('d.m', strtotime((string) $tarih)) . ' ' . $gunTr . ')';
            $ozetPars[] = $etiket . ': ' . $d['calisan'] . ' çalıştı';

            if (!$d['onceki_veri']) {
                $veriYok++;
                $rows[] = [
                    'ad'      => $etiket,
                    'meta'    => 'geçmiş tatil verisi yok — elle teyit et',
                    'deger'   => $d['calisan'] . ' çalıştı',
                    'link'    => 'bugun.php?date=' . $tarih,
                    'link_ad' => 'Aç',
                ];
                continue;
            }
            // Yarım gün: çalışılması normal → beklenmedik üretme, bilgi satırı yeter.
            if ($d['yarim_gun']) {
                $rows[] = [
                    'ad'      => $etiket,
                    'meta'    => 'yarım gün (arefe) — sayı girilmesi normal',
                    'deger'   => $d['calisan'] . ' çalıştı',
                    'link'    => 'bugun.php?date=' . $tarih,
                    'link_ad' => 'Aç',
                ];
                continue;
            }
            $beklenmedikTop += (int) $d['beklenmedik'];
            foreach ($d['rows'] as $r) {
                if (empty($r['beklenmedik'])) {
                    continue;
                }
                $rows[] = [
                    'ad'      => (string) $r['name'],
                    'meta'    => $etiket . ' · geçen tatilde ' . (int) $r['onceki_kisi'] . ' kişi çalışmıştı',
                    'deger'   => 'sayı girilmedi',
                    'link'    => 'bugun.php?date=' . $tarih . '&focus=' . (int) $r['customer_id'],
                    'link_ad' => 'Gir',
                ];
            }
            if ((int) $d['beklenmedik'] === 0) {
                $rows[] = [
                    'ad'      => $etiket,
                    'meta'    => 'geçen tatille aynı davranış',
                    'deger'   => $d['calisan'] . ' çalıştı',
                    'link'    => 'bugun.php?date=' . $tarih,
                    'link_ad' => 'Aç',
                ];
            }
        }

        if ($beklenmedikTop > 0) {
            $durum = 'warn';
            $ozet = $beklenmedikTop . ' müşteri geçen tatilde çalışmıştı, bu tatilde sayı girilmemiş.';
        } elseif ($veriYok > 0 && $veriYok === count($tatiller)) {
            $durum = 'info';
            $ozet = count($tatiller) . ' tatil · geçmiş tatil verisi yok, davranış kıyaslanamadı.';
        } else {
            $durum = 'ok';
            $ozet = count($tatiller) . ' tatil · ' . implode(' · ', $ozetPars) . '.';
        }
        return ['durum' => $durum, 'ozet' => $ozet, 'rows' => $rows];
    }

    /**
     * Bir tarihe düşen sipariş/teslimat kayıtları — tatil uyarısındaki "ekmek vb. siparişi
     * iptal/güncelle" hatırlatmasının VERİ tarafı (yoksa genel hatırlatma metni kullanılır).
     * @return array{siparis:array<int,array>,teslimat:array<int,array>}
     */
    public function tatilSiparisleri(string $tarih): array
    {
        $st = $this->pdo->prepare(
            "SELECT o.customer_id, c.name, o.meal, o.persons, o.status
             FROM orders o JOIN customers c ON c.id = o.customer_id
             WHERE o.order_date = ? AND o.status <> 'reddedildi' AND o.persons > 0
             ORDER BY c.name ASC, o.meal ASC"
        );
        $st->execute([$tarih]);
        $siparis = $st->fetchAll();

        $st = $this->pdo->prepare(
            "SELECT t.customer_id, c.name, t.status
             FROM teslimat t JOIN customers c ON c.id = t.customer_id
             WHERE t.teslim_date = ? AND t.status <> 'teslim'
             ORDER BY c.name ASC"
        );
        $st->execute([$tarih]);
        return ['siparis' => $siparis, 'teslimat' => $st->fetchAll()];
    }

    // ══ fable-047: AY KAPANIŞI akıllı kontrol yardımcıları ════════════════════════════

    /**
     * Ayın iş günlerinde sayı GİRİLMEMİŞ müşteri×gün (rapor.php eksik-gün mantığı =
     * customerWeeklyGrid; tek gerçek kaynak). Resmi tatil günleri HARİÇ — onlar "tatil
     * davranışı" maddesinin işi (aynı gün iki maddede uyarı üretmesin).
     * @return array{toplam:int,rows:array<int,array{customer_id:int,name:string,eksik:int,ilk:?string,gunler:array<int,string>}>}
     */
    public function ayEksikUretim(string $ay, ?string $today = null): array
    {
        $today ??= $this->bugun();
        $tatiller = $this->ayTatilleri($ay);
        $rows = [];
        $toplam = 0;
        foreach ($this->activeCustomers() as $c) {
            $cid = (int) $c['id'];
            $gunler = [];
            foreach ($this->customerWeeklyGrid($cid, $ay, $today)['weeks'] as $w) {
                foreach ($w['days'] as $d) {
                    if ($d['missing'] && !isset($tatiller[$d['gun']])) {
                        $gunler[] = (string) $d['gun'];
                    }
                }
            }
            if ($gunler === []) {
                continue;
            }
            $toplam += count($gunler);
            $rows[] = [
                'customer_id' => $cid,
                'name'        => (string) $c['name'],
                'eksik'       => count($gunler),
                'ilk'         => $gunler[0],
                'gunler'      => $gunler,
            ];
        }
        usort($rows, static fn(array $a, array $b) => $b['eksik'] <=> $a['eksik']);
        return ['toplam' => $toplam, 'rows' => $rows];
    }

    /**
     * ANORMAL SAYI DAVRANIŞI: müşteri bazında ayın günlük ortalamasından %eşik+ sapan günler
     * (hem düşük hem yüksek). Eşik ayar tablosundan parametrik ('kapanis_sapma_esik', vars. 40).
     * Tatil günleri ve gelecek günler hesaba GİRMEZ; 3 günden az kaydı olan müşteri atlanır
     * (ortalama anlamsız → "veri yetersiz" sahte alarm üretmesin).
     * @return array{esik:float,toplam:int,rows:array<int,array>}
     */
    public function ayAnormalSapma(string $ay, ?float $esik = null, ?string $today = null): array
    {
        $esik = $esik ?? $this->ayarNum('kapanis_sapma_esik', 40.0);
        if ($esik <= 0) {
            $esik = 40.0;
        }
        $today ??= $this->bugun();
        $tatiller = $this->ayTatilleri($ay);

        $st = $this->pdo->prepare(
            "SELECT p.customer_id, c.name, p.prod_date, COALESCE(SUM(p.persons),0) AS kisi
             FROM production p JOIN customers c ON c.id = p.customer_id
             WHERE substr(p.prod_date,1,7) = ?
             GROUP BY p.customer_id, c.name, p.prod_date
             ORDER BY c.name ASC, p.prod_date ASC"
        );
        $st->execute([$ay]);

        $byCustomer = [];
        foreach ($st->fetchAll() as $r) {
            $gun = (string) $r['prod_date'];
            $kisi = (int) $r['kisi'];
            if ($kisi <= 0 || $gun > $today || isset($tatiller[$gun])) {
                continue;
            }
            $cid = (int) $r['customer_id'];
            $byCustomer[$cid] ??= ['customer_id' => $cid, 'name' => (string) $r['name'], 'gunler' => []];
            $byCustomer[$cid]['gunler'][$gun] = $kisi;
        }

        $rows = [];
        $toplam = 0;
        foreach ($byCustomer as $c) {
            if (count($c['gunler']) < 3) {
                continue;   // ortalama anlamsız — sahte alarm üretme
            }
            $ort = array_sum($c['gunler']) / count($c['gunler']);
            if ($ort <= 0) {
                continue;
            }
            $sapan = [];
            foreach ($c['gunler'] as $gun => $kisi) {
                $yuzde = abs($kisi - $ort) / $ort * 100;
                if ($yuzde + 1e-9 < $esik) {
                    continue;
                }
                $sapan[] = [
                    'gun'   => (string) $gun,
                    'kisi'  => $kisi,
                    'yuzde' => round($yuzde, 1),
                    'yon'   => $kisi < $ort ? 'dusuk' : 'yuksek',
                ];
            }
            if ($sapan === []) {
                continue;
            }
            $toplam += count($sapan);
            $rows[] = [
                'customer_id' => $c['customer_id'],
                'name'        => $c['name'],
                'ort'         => round($ort, 1),
                'gun_sayisi'  => count($c['gunler']),
                'gunler'      => $sapan,
            ];
        }
        usort($rows, static fn(array $a, array $b) => count($b['gunler']) <=> count($a['gunler']));
        return ['esik' => $esik, 'toplam' => $toplam, 'rows' => $rows];
    }

    /**
     * BELGE TAMLIĞI: irsaliyesi kesilmemiş üretim günü + faturası kesilmemiş müşteri.
     * İrsaliye kapsamı = customers.irsaliye_aktif=1 ve parasut_id dolu (irsaliyeAdaylari ile
     * aynı tanım). Fatura = fatura tablosunda 'kesildi' VEYA parasut_fatura_log'da 'kesildi'.
     * @return array{irsaliye_eksik_gun:int,irsaliye:array<int,array>,fatura:array<int,array>}
     */
    public function ayBelgeDurumu(string $ay): array
    {
        $st = $this->pdo->prepare(
            "SELECT p.customer_id, c.name, p.prod_date, COALESCE(SUM(p.persons),0) AS kisi
             FROM production p JOIN customers c ON c.id = p.customer_id
             WHERE substr(p.prod_date,1,7) = ? AND c.irsaliye_aktif = 1
               AND c.parasut_id IS NOT NULL AND c.parasut_id <> ''
             GROUP BY p.customer_id, c.name, p.prod_date"
        );
        $st->execute([$ay]);
        $uretimGunleri = $st->fetchAll();

        $st = $this->pdo->prepare(
            "SELECT customer_id, gun FROM parasut_irsaliye_log
             WHERE substr(gun,1,7) = ? AND durum IN ('kesildi','bilinmiyor')"
        );
        $st->execute([$ay]);
        $kesilen = [];
        foreach ($st->fetchAll() as $r) {
            $kesilen[(int) $r['customer_id'] . '|' . (string) $r['gun']] = true;
        }

        $irs = [];
        $irsGun = 0;
        foreach ($uretimGunleri as $r) {
            if ((int) $r['kisi'] <= 0) {
                continue;
            }
            $cid = (int) $r['customer_id'];
            if (isset($kesilen[$cid . '|' . (string) $r['prod_date']])) {
                continue;
            }
            $irs[$cid] ??= ['customer_id' => $cid, 'name' => (string) $r['name'], 'eksik' => 0, 'ilk' => null];
            $irs[$cid]['eksik']++;
            $irs[$cid]['ilk'] = min($irs[$cid]['ilk'] ?? '9999-99-99', (string) $r['prod_date']);
            $irsGun++;
        }
        $irs = array_values($irs);
        usort($irs, static fn(array $a, array $b) => $b['eksik'] <=> $a['eksik']);

        // Faturası kesilmemiş müşteri: o ay üretimi var ama 'kesildi' fatura kaydı yok.
        $st = $this->pdo->prepare("SELECT customer_id FROM fatura WHERE ay = ? AND durum = 'kesildi'");
        $st->execute([$ay]);
        $faturali = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        $st = $this->pdo->prepare(
            "SELECT DISTINCT customer_id FROM parasut_fatura_log
             WHERE substr(donem_son,1,7) = ? AND durum = 'kesildi'"
        );
        $st->execute([$ay]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $faturali[(int) $cid] = true;
        }

        $fat = [];
        foreach ($this->monthProductionByCustomer($ay) as $r) {
            $cid = (int) $r['customer_id'];
            if (isset($faturali[$cid])) {
                continue;
            }
            $fat[] = ['customer_id' => $cid, 'name' => (string) $r['name'], 'ciro' => (float) ($r['ciro'] ?? 0)];
        }
        return ['irsaliye_eksik_gun' => $irsGun, 'irsaliye' => $irs, 'fatura' => $fat];
    }

    /** Kokpit'e 'gider' olarak işlenmiş PARAŞÜT kaynaklı fatura adedi (o ay). */
    public function ayParasutGiderAdet(string $ay): int
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) FROM transactions
             WHERE type = 'gider' AND source = 'parasut' AND substr(tx_date,1,7) = ?"
        );
        $st->execute([$ay]);
        return (int) $st->fetchColumn();
    }

    /**
     * GİDER TAMLIĞI CACHE — sayfa açılışında Paraşüt'e AĞIR API çağrısı YAPILMAZ (rate limit).
     * Karşılaştırma butonla tetiklenir; sonuç ayar tablosunda saklanır, sayfa cache'i okur.
     * @return array{parasut:int,kokpit:int,eksik:int,at:string}|null hiç kontrol edilmediyse null
     */
    public function giderTamlikCache(string $ay): ?array
    {
        $raw = $this->ayar('kapanis_gider_' . $ay);
        if ($raw === null || $raw === '') {
            return null;
        }
        $d = json_decode($raw, true);
        if (!is_array($d) || !isset($d['parasut'], $d['kokpit'])) {
            return null;
        }
        return [
            'parasut' => (int) $d['parasut'],
            'kokpit'  => (int) $d['kokpit'],
            'eksik'   => max(0, (int) $d['parasut'] - (int) $d['kokpit']),
            'at'      => (string) ($d['at'] ?? ''),
        ];
    }

    /** Gider tamlığı karşılaştırmasını cache'e yaz (butonla tetiklenen SALT-OKUMA sonucu). */
    public function giderTamlikKaydet(string $ay, int $parasutAdet, int $kokpitAdet): array
    {
        $d = [
            'parasut' => max(0, $parasutAdet),
            'kokpit'  => max(0, $kokpitAdet),
            'at'      => date('Y-m-d H:i'),
        ];
        $this->ayarSet('kapanis_gider_' . $ay, (string) json_encode($d, JSON_UNESCAPED_UNICODE));
        return $this->giderTamlikCache($ay) ?? $d;
    }

    /**
     * MALİYET EŞLEŞTİRME BOŞLUĞU: o ay gideri olup hiçbir kırılıma/müşteriye eşlenmemiş tedarikçi.
     * Eşlenmiş sayılır → tedarikci_musteri_map VEYA tedarikci_gida_map'te anahtarı var,
     * ya da o aydaki TÜM faturaları fatura_musteri_map ile tek tek eşlenmiş.
     * 'Personel' + 'Taşıma alış' hariç (distinctGiderFirmalar ile aynı tedarikçi tanımı).
     * @return array<int,array{key:string,label:string,adet:int,toplam:float}> toplam DESC
     */
    public function ayEslesmeyenTedarikci(string $ay): array
    {
        $st = $this->pdo->prepare(
            "SELECT t.id, t.source, t.category, t.description, t.amount, s.name AS supplier_name
             FROM transactions t
             LEFT JOIN suppliers s ON s.id = t.supplier_id
             WHERE t.type = 'gider' AND substr(t.tx_date,1,7) = ?
               AND (t.category IS NULL OR t.category NOT IN ('Personel', 'Taşıma alış'))"
        );
        $st->execute([$ay]);
        $musteriMap = $this->tedarikciEslestirmeler();
        $gidaMap = $this->tedarikciGidaMap();
        $faturaMap = $this->faturaEslestirmeler($ay);

        $grup = [];
        foreach ($st->fetchAll() as $r) {
            $firma = $this->txFirma($r);
            $key = self::normTedarikci($firma);
            if ($key === '') {
                continue;
            }
            $grup[$key] ??= ['key' => $key, 'label' => $firma, 'adet' => 0, 'toplam' => 0.0, 'eslesmemis_fatura' => 0];
            $grup[$key]['adet']++;
            $grup[$key]['toplam'] += (float) $r['amount'];
            if (empty($faturaMap[(int) $r['id']])) {
                $grup[$key]['eslesmemis_fatura']++;
            }
        }

        $out = [];
        foreach ($grup as $key => $g) {
            if (!empty($musteriMap[$key]) || array_key_exists($key, $gidaMap)) {
                continue;                       // tedarikçi seviyesinde eşlenmiş
            }
            if ($g['eslesmemis_fatura'] === 0) {
                continue;                       // her faturası tek tek eşlenmiş
            }
            unset($g['eslesmemis_fatura']);
            $out[] = $g;
        }
        usort($out, static fn(array $a, array $b) => $b['toplam'] <=> $a['toplam']);
        return $out;
    }

    /**
     * PERSONEL kapanış durumu: o ay maaşı ödenmemiş/işlenmemiş + müşteri ataması olmayan personel.
     * (Atamasız personel maliyeti hiçbir müşteriye dağılmaz → kâr analizi eksik çıkar.)
     * @return array{odenmemis:array<int,array>,atamasiz:array<int,array>}
     */
    public function ayPersonelDurumu(string $ay): array
    {
        $st = $this->pdo->prepare(
            'SELECT p.id, p.ad, p.aylik_ucret, m.maas_odendi
             FROM personel p
             LEFT JOIN personel_maas_ay m ON m.personel_id = p.id AND m.ay = ?
             WHERE p.is_active = 1
             ORDER BY p.ad ASC'
        );
        $st->execute([$ay]);
        $liste = $st->fetchAll();

        $atanan = [];
        foreach ($this->pdo->query('SELECT DISTINCT personel_id FROM personel_musteri')->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $atanan[(int) $pid] = true;
        }

        $odenmemis = [];
        $atamasiz = [];
        foreach ($liste as $p) {
            $pid = (int) $p['id'];
            if ($p['maas_odendi'] === null || (int) $p['maas_odendi'] !== 1) {
                $odenmemis[] = [
                    'personel_id' => $pid,
                    'ad'          => (string) $p['ad'],
                    'ucret'       => (float) $p['aylik_ucret'],
                    'islenmis'    => $p['maas_odendi'] !== null,
                ];
            }
            if (!isset($atanan[$pid])) {
                $atamasiz[] = ['personel_id' => $pid, 'ad' => (string) $p['ad']];
            }
        }
        return ['odenmemis' => $odenmemis, 'atamasiz' => $atamasiz];
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
        // fable-042: cari ayda ay başı..bugün (MTD); geçmiş/gelecek ayda tam ay.
        $ar = $this->ayAralik($month);
        $sql = "SELECT c.id AS customer_id, c.name, c.category, c.fatura_kisi_haftaici,
                    COALESCE(SUM(p.persons),0) AS persons, COALESCE(SUM(p.amount),0) AS ciro,
                    COUNT(p.id) AS gun
             FROM customers c
             LEFT JOIN production p ON p.customer_id = c.id AND p.prod_date BETWEEN ? AND ?";
        $params = [$ar['bas'], $ar['son']];
        if ($category !== null) {
            $sql .= ' WHERE c.category = ?';
            $params[] = $category;
        }
        $sql .= " GROUP BY c.id, c.name, c.category, c.fatura_kisi_haftaici HAVING gun > 0 ORDER BY ciro DESC";
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

    /**
     * fable-020: Drill-down grid'i ISO haftalara böler ve ayın TÜM servis günlerini
     * (Pzt–Cmt; pazar atlanır) basar — kayıtsız GEÇMİŞ servis günü "eksik" işaretlenir.
     * NEDEN: customerDailyGrid sadece kayıtlı günü döndürüyordu, atlanan gün görünmüyordu.
     * Eksik = kayıt yok + gün geçmiş (bugün ve sonrası nötr, yanlış alarm üretmesin) +
     *   Cmt istisnası: müşterinin o ay hiç Cmt kaydı yoksa cumartesi eksik sayılmaz.
     * customerDailyGrid'i kaynak alır (tek gerçek kaynak; imzası bozulmaz).
     * @param string|null $today 'YYYY-MM-DD' — test edilebilirlik için; null → bugün.
     * @return array{weeks:array,kisi:int,tutar:float,missing:int,first_missing:?string,has_saturday_record:bool}
     */
    public function customerWeeklyGrid(int $customerId, string $ay, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $recorded = [];
        foreach ($this->customerDailyGrid($customerId, $ay) as $r) {
            $recorded[$r['gun']] = $r;
        }
        // Cmt kuralı: müşterinin o ay en az bir Cmt kaydı var mı?
        $hasSat = false;
        foreach ($recorded as $d => $_r) {
            if ((int) date('N', strtotime((string) $d)) === 6) { $hasSat = true; break; }
        }
        [$y, $m] = array_map('intval', explode('-', $ay));
        $daysInMonth = (int) date('t', mktime(0, 0, 0, $m, 1, $y));
        $weeks = [];
        $totKisi = 0; $totTutar = 0.0; $totMissing = 0; $firstMissing = null;
        for ($dd = 1; $dd <= $daysInMonth; $dd++) {
            $date = sprintf('%04d-%02d-%02d', $y, $m, $dd);
            $dow = (int) date('N', strtotime($date)); // 1=Pzt … 7=Paz
            if ($dow === 7) { continue; } // pazar servis günü değil
            $iso = date('o-W', strtotime($date));
            if (!isset($weeks[$iso])) {
                $weeks[$iso] = ['iso' => $iso, 'start' => $date, 'end' => $date, 'days' => [], 'kisi' => 0, 'tutar' => 0.0, 'missing' => 0];
            }
            $weeks[$iso]['end'] = $date;
            $rec = $recorded[$date] ?? null;
            $future = $date >= $today; // bugün ve sonrası nötr (servis henüz girilmemiş olabilir)
            $satNeutral = ($dow === 6 && !$hasSat);
            $missing = ($rec === null) && !$future && !$satNeutral;
            $row = [
                'gun'      => $date,
                'dow'      => $dow,
                'recorded' => $rec !== null,
                'future'   => $future,
                'missing'  => $missing,
                'ogle'     => (int) ($rec['ogle'] ?? 0),
                'aksam'    => (int) ($rec['aksam'] ?? 0),
                'kumanya'  => (int) ($rec['kumanya'] ?? 0),
                'kisi'     => (int) ($rec['kisi'] ?? 0),
                'tutar'    => (float) ($rec['tutar'] ?? 0.0),
            ];
            $weeks[$iso]['days'][] = $row;
            $weeks[$iso]['kisi']  += $row['kisi'];
            $weeks[$iso]['tutar'] += $row['tutar'];
            if ($missing) {
                $weeks[$iso]['missing']++;
                $totMissing++;
                $firstMissing ??= $date;
            }
            $totKisi  += $row['kisi'];
            $totTutar += $row['tutar'];
        }
        return [
            'weeks'                => array_values($weeks),
            'kisi'                 => $totKisi,
            'tutar'                => $totTutar,
            'missing'              => $totMissing,
            'first_missing'        => $firstMissing,
            'has_saturday_record'  => $hasSat,
        ];
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
        ?string $note = null,
        ?string $skt = null,
        ?int $supplierId = null
    ): int {
        if (!in_array($direction, ['giris', 'cikis'], true)) {
            throw new \InvalidArgumentException('direction giris|cikis olmalı');
        }
        if ($unit === null || $unit === '') {
            $ing = $this->ingredient($ingredientId);
            $unit = $ing['unit'] ?? 'kg';
        }
        // fable-003: SKT/tedarikçi sadece GİRİŞ hareketinde anlamlı
        if ($direction !== 'giris') {
            $skt = null;
            $supplierId = null;
        }
        $this->pdo->prepare(
            'INSERT INTO stock_moves (ingredient_id, move_date, direction, quantity, unit, skt, supplier_id, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$ingredientId, $moveDate, $direction, $quantity, $unit, $skt, $supplierId ?: null, $note]);
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

    // ── fable-003: Sipariş ihtiyacı · SKT · tedarikçi · HACCP · teklif · teslimat ──

    /**
     * Sipariş ihtiyaç listesi (cateringkolay esinli): eşik altındaki malzemeler +
     * eksik miktar + son alış (tarih & tedarikçi, son 'giris' hareketinden).
     * @return array<int,array{id:int,name:string,unit:string,min_stok:float,stok:float,eksik:float,son_alis:?string,son_tedarikci:?string}>
     */
    public function orderNeedList(): array
    {
        $out = [];
        foreach ($this->criticalStock() as $r) {
            $st = $this->pdo->prepare(
                "SELECT sm.move_date, s.name AS supplier_name
                 FROM stock_moves sm LEFT JOIN suppliers s ON s.id = sm.supplier_id
                 WHERE sm.ingredient_id = ? AND sm.direction = 'giris'
                 ORDER BY sm.move_date DESC, sm.id DESC LIMIT 1"
            );
            $st->execute([(int) $r['id']]);
            $last = $st->fetch() ?: null;
            $out[] = [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'unit' => (string) $r['unit'],
                'min_stok' => (float) $r['min_stok'],
                'stok' => (float) $r['stok'],
                'eksik' => (float) $r['min_stok'] - (float) $r['stok'],
                'son_alis' => $last['move_date'] ?? null,
                'son_tedarikci' => $last['supplier_name'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * SKT riski: önümüzdeki $days gün içinde (veya geçmişte) SKT'si dolan GİRİŞ kayıtları.
     * Not: parti bazlı tüketim takibi yok — liste bilgilendirme amaçlı (fiziksel kontrol şart).
     */
    public function sktRisk(int $days = 30): array
    {
        $limit = date('Y-m-d', strtotime("+$days day"));
        $st = $this->pdo->prepare(
            "SELECT sm.id, sm.move_date, sm.skt, sm.quantity, sm.unit, i.name AS ingredient_name,
                    s.name AS supplier_name
             FROM stock_moves sm
             JOIN ingredients i ON i.id = sm.ingredient_id
             LEFT JOIN suppliers s ON s.id = sm.supplier_id
             WHERE sm.direction = 'giris' AND sm.skt IS NOT NULL AND sm.skt <= ?
             ORDER BY sm.skt ASC, sm.id DESC"
        );
        $st->execute([$limit]);
        return $st->fetchAll();
    }

    /** Tedarikçi listesi (aktif varsayılan, ada göre). */
    public function listSuppliers(bool $activeOnly = true): array
    {
        $sql = 'SELECT id, name, contact, is_active FROM suppliers';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        return $this->pdo->query($sql . ' ORDER BY name ASC')->fetchAll();
    }

    public function supplierById(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, name, contact, is_active FROM suppliers WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Tedarikçi ekle/düzenle (ad UNIQUE — çakışmada mevcut günceller). @return id */
    public function upsertSupplier(string $name, ?string $contact = null, ?int $id = null): int
    {
        if ($id === null) {
            $st = $this->pdo->prepare('SELECT id FROM suppliers WHERE name = ?');
            $st->execute([$name]);
            $found = $st->fetchColumn();
            $id = $found !== false ? (int) $found : null;
        }
        if ($id !== null) {
            $this->pdo->prepare('UPDATE suppliers SET name = ?, contact = ? WHERE id = ?')
                ->execute([$name, $contact, $id]);
            return $id;
        }
        $this->pdo->prepare('INSERT INTO suppliers (name, contact) VALUES (?, ?)')->execute([$name, $contact]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * fable-043: Ad ile tedarikçi bul; yoksa oluştur. upsertSupplier'dan farkı: mevcut
     * kaydın contact'ına DOKUNMAZ (elle gider formu için "ekle-veya-bul"). TR-normalize eşleşme
     * ile 'KIRMIZI'=='kırmızı' dup önlenir. @return id (boş ad → 0).
     */
    public function ensureSupplier(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        $st = $this->pdo->prepare('SELECT id FROM suppliers WHERE name = ?');
        $st->execute([$name]);
        $found = $st->fetchColumn();
        if ($found !== false) {
            return (int) $found;
        }
        $norm = self::normTedarikci($name);
        foreach ($this->pdo->query('SELECT id, name FROM suppliers')->fetchAll() as $s) {
            if (self::normTedarikci((string) $s['name']) === $norm) {
                return (int) $s['id'];
            }
        }
        $this->pdo->prepare('INSERT INTO suppliers (name, contact) VALUES (?, NULL)')->execute([$name]);
        return (int) $this->pdo->lastInsertId();
    }

    public function setSupplierActive(int $id, bool $active): void
    {
        $this->pdo->prepare('UPDATE suppliers SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
    }

    /** Tedarikçinin son alışları: stok girişleri + finans giderleri (birleşik, yeni→eski). */
    public function supplierHistory(int $supplierId, int $limit = 10): array
    {
        $st = $this->pdo->prepare(
            "SELECT sm.move_date AS d, i.name AS what, sm.quantity, sm.unit, NULL AS amount
             FROM stock_moves sm JOIN ingredients i ON i.id = sm.ingredient_id
             WHERE sm.supplier_id = ? AND sm.direction = 'giris'
             UNION ALL
             SELECT t.tx_date AS d, COALESCE(t.description,'Gider') AS what, NULL, NULL, t.amount
             FROM transactions t WHERE t.supplier_id = ? AND t.type = 'gider'
             ORDER BY d DESC LIMIT " . (int) $limit
        );
        $st->execute([$supplierId, $supplierId]);
        return $st->fetchAll();
    }

    // ── HACCP (fable-003) ──────────────────────────────────────
    public const HACCP_KINDS = ['sicaklik', 'hijyen', 'numune', 'malkabul'];

    public function addHaccpLog(
        string $logDate,
        string $kind,
        string $nokta,
        ?string $deger = null,
        ?bool $uygun = null,
        ?string $note = null,
        ?string $createdBy = null
    ): int {
        if (!in_array($kind, self::HACCP_KINDS, true)) {
            throw new \InvalidArgumentException('Geçersiz HACCP kaydı türü: ' . $kind);
        }
        $this->pdo->prepare(
            'INSERT INTO haccp_log (log_date, kind, nokta, deger, uygun, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$logDate, $kind, $nokta, $deger, $uygun === null ? null : ($uygun ? 1 : 0), $note, $createdBy]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Bir günün HACCP kayıtları (tür filtresi opsiyonel), eski→yeni. */
    public function haccpLogsForDate(string $logDate, ?string $kind = null): array
    {
        $sql = 'SELECT * FROM haccp_log WHERE log_date = ?';
        $params = [$logDate];
        if ($kind !== null) {
            $sql .= ' AND kind = ?';
            $params[] = $kind;
        }
        $st = $this->pdo->prepare($sql . ' ORDER BY id ASC');
        $st->execute($params);
        return $st->fetchAll();
    }

    /** İmha edilmemiş şahit numuneler (eski→yeni; 72 saat kuralını çağıran değerlendirir). */
    public function haccpActiveSamples(): array
    {
        return $this->pdo->query(
            "SELECT * FROM haccp_log WHERE kind = 'numune' AND imha_at IS NULL ORDER BY log_date ASC, id ASC"
        )->fetchAll();
    }

    /** Şahit numuneyi imha olarak işaretle. @return bool bulundu mu */
    public function haccpDisposeSample(int $id): bool
    {
        $st = $this->pdo->prepare(
            "UPDATE haccp_log SET imha_at = CURRENT_TIMESTAMP WHERE id = ? AND kind = 'numune' AND imha_at IS NULL"
        );
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    // ── Teklifler (fable-003, fable-005 yemekhaneci teklif motoru) ──
    public const TEKLIF_DURUM = ['taslak', 'gonderildi', 'kabul', 'red'];

    /** fable-005: create/update ile yazılabilen bilinen kolonlar (durum hariç — kendi akışı var). */
    private const TEKLIF_FIELDS = [
        'firma', 'yetkili', 'telefon', 'email', 'kisi', 'ogun_sayisi', 'cumartesi',
        'sehir', 'ilce', 'segment', 'menu_json', 'personel_json', 'ekipman', 'birim_fiyat',
        'fiyat_json', 'giris_metni', 'note',
    ];

    public function listTeklif(): array
    {
        return $this->pdo->query(
            'SELECT * FROM teklif ORDER BY created_at DESC, id DESC'
        )->fetchAll();
    }

    /** Tek teklif (düzenleme + PDF için). Yoksa null. */
    public function teklifById(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM teklif WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Teklif oluştur. İmza geriye uyumlu (mevcut 4 parametre korunur); yemekhaneci
     * teklif motoru alanları $extra ile geçilir (whitelist TEKLIF_FIELDS ile prepared).
     * @param array<string,mixed> $extra
     */
    public function createTeklif(string $firma, ?int $kisi, ?float $birimFiyat, ?string $note, array $extra = []): int
    {
        $cols = ['firma', 'kisi', 'birim_fiyat', 'note'];
        $vals = [$firma, $kisi, $birimFiyat, $note];
        foreach ($extra as $k => $v) {
            if (in_array($k, self::TEKLIF_FIELDS, true) && !in_array($k, $cols, true)) {
                $cols[] = $k;
                $vals[] = $v;
            }
        }
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $this->pdo->prepare(
            'INSERT INTO teklif (' . implode(', ', $cols) . ') VALUES (' . $ph . ')'
        )->execute($vals);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Teklif alanlarını güncelle (SADECE bilinen kolonlar, prepared). Bilinmeyen anahtarlar
     * atlanır. Teklif yoksa false. (durum güncellemesi setTeklifDurum ile — kendi doğrulaması var.)
     * @param array<string,mixed> $fields
     */
    public function updateTeklif(int $id, array $fields): bool
    {
        $set = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            if (in_array($k, self::TEKLIF_FIELDS, true)) {
                $set[] = "$k = ?";
                $vals[] = $v;
            }
        }
        if (!$set) {
            return false;
        }
        // MariaDB rowCount() değişmeyen satırda 0 döner → açık SELECT ile var olma kontrolü.
        $ex = $this->pdo->prepare('SELECT 1 FROM teklif WHERE id = ?');
        $ex->execute([$id]);
        if ($ex->fetchColumn() === false) {
            return false;
        }
        $vals[] = $id;
        $this->pdo->prepare('UPDATE teklif SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
        return true;
    }

    public function setTeklifDurum(int $id, string $durum): bool
    {
        if (!in_array($durum, self::TEKLIF_DURUM, true)) {
            throw new \InvalidArgumentException('Geçersiz teklif durumu: ' . $durum);
        }
        // Var olma kontrolü rowCount yerine açık SELECT ile: MariaDB rowCount() değişmeyen
        // satırda (aynı duruma set) 0 döndürür; bu "bulunamadı" yanılgısı yaratır (SQLite'ta matched döner).
        $ex = $this->pdo->prepare('SELECT 1 FROM teklif WHERE id = ?');
        $ex->execute([$id]);
        if ($ex->fetchColumn() === false) {
            return false;
        }
        $this->pdo->prepare('UPDATE teklif SET durum = ? WHERE id = ?')->execute([$durum, $id]);
        return true;
    }

    // ── Teslimat / sevkiyat (fable-003) ────────────────────────
    /**
     * Günün sevkiyat listesi: o gün üretimi olan müşteriler (kişi toplamı) + teslimat durumu.
     * @return array<int,array{customer_id:int,name:string,persons:int,status:string}>
     */
    public function deliveriesForDate(string $date): array
    {
        $st = $this->pdo->prepare(
            "SELECT p.customer_id, c.name, SUM(p.persons) AS persons,
                    COALESCE(t.status, 'bekliyor') AS status
             FROM production p
             JOIN customers c ON c.id = p.customer_id
             LEFT JOIN teslimat t ON t.customer_id = p.customer_id AND t.teslim_date = p.prod_date
             WHERE p.prod_date = ?
             GROUP BY p.customer_id, c.name, t.status
             ORDER BY c.name ASC"
        );
        $st->execute([$date]);
        return array_map(static fn ($r) => [
            'customer_id' => (int) $r['customer_id'],
            'name' => (string) $r['name'],
            'persons' => (int) $r['persons'],
            'status' => (string) $r['status'],
        ], $st->fetchAll());
    }

    /**
     * Mutfak görünümü (fable-003): bir günün öğün bazlı üretim dökümü.
     * @return array<string,array{customers:array<int,array{name:string,persons:int}>,total:int}>
     */
    public function kitchenDay(string $date): array
    {
        $st = $this->pdo->prepare(
            'SELECT p.meal, c.name, p.persons FROM production p
             JOIN customers c ON c.id = p.customer_id
             WHERE p.prod_date = ? AND p.persons > 0
             ORDER BY p.meal ASC, c.name ASC'
        );
        $st->execute([$date]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $meal = (string) $r['meal'];
            $out[$meal]['customers'][] = ['name' => (string) $r['name'], 'persons' => (int) $r['persons']];
            $out[$meal]['total'] = ($out[$meal]['total'] ?? 0) + (int) $r['persons'];
        }
        return $out;
    }

    /** Teslimat durumunu güne+müşteriye yaz (upsert). */
    public function setDeliveryStatus(string $date, int $customerId, string $status): void
    {
        if (!in_array($status, ['bekliyor', 'yolda', 'teslim'], true)) {
            throw new \InvalidArgumentException('Geçersiz teslimat durumu: ' . $status);
        }
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $onConf = $driver === 'sqlite'
            ? 'ON CONFLICT(teslim_date, customer_id) DO UPDATE SET status = excluded.status'
            : 'ON DUPLICATE KEY UPDATE status = VALUES(status)';
        $this->pdo->prepare(
            'INSERT INTO teslimat (teslim_date, customer_id, status) VALUES (?, ?, ?) ' . $onConf
        )->execute([$date, $customerId, $status]);
    }

    // ── İşlem kaydı (audit UI — fable-003) ─────────────────────
    /** Son denetim kayıtları; $q actor/action içinde arar. */
    public function auditRecent(int $limit = 100, ?string $q = null): array
    {
        $sql = 'SELECT id, action, actor, target_key, detail, ip_addr, created_at FROM audit';
        $params = [];
        if ($q !== null && $q !== '') {
            $sql .= ' WHERE action LIKE ? OR actor LIKE ?';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        $st = $this->pdo->prepare($sql . ' ORDER BY id DESC LIMIT ' . (int) $limit);
        $st->execute($params);
        return $st->fetchAll();
    }

    // ── Personel Giderleri (opus-009) ─────────────────────────
    /** Geçerli personel gider türleri (ENUM/CHECK ile eş). */
    public const PERSONEL_GIDER_TUR = ['maas', 'prim', 'avans', 'sgk', 'diger'];

    /** @return array<int,array> personel listesi (aktif varsayılan). */
    public function listPersonel(bool $activeOnly = true): array
    {
        $sql = 'SELECT id, ad, gorev, aylik_ucret, ise_giris, ise_cikis, diger_maliyet, is_active FROM personel';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY ad';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function personel(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, ad, gorev, aylik_ucret, ise_giris, ise_cikis, diger_maliyet, is_active FROM personel WHERE id = ?');
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

    /**
     * fable-015: giriş/çıkış tarihine göre o ayın ÇALIŞILABİLİR gün sayısı (SGK 30-gün mantığı):
     *   ay içinde giriş  → 30 − (giriş günü − 1)   (12 Tem giriş → 19 gün)
     *   ay içinde çıkış  → çıkış günü               (15 Tem çıkış → 15 gün)
     *   ikisi de aynı ay → çıkış − giriş + 1
     *   çıkıştan sonraki / girişten önceki ay → 0
     * Elle girilen çalışma günü bunu EZER ama tavan olarak kullanılır (çıkana 30 gün yazılamaz).
     */
    public static function takvimCalismaGunu(?string $iseGiris, ?string $iseCikis, string $ay): float
    {
        $giris = ($iseGiris !== null && $iseGiris !== '') ? substr((string) $iseGiris, 0, 10) : null;
        $cikis = ($iseCikis !== null && $iseCikis !== '') ? substr((string) $iseCikis, 0, 10) : null;
        if ($giris !== null && substr($giris, 0, 7) > $ay) {
            return 0.0;
        }
        if ($cikis !== null && substr($cikis, 0, 7) < $ay) {
            return 0.0;
        }
        $gun = 30.0;
        $girisAyda = $giris !== null && substr($giris, 0, 7) === $ay;
        $cikisAyda = $cikis !== null && substr($cikis, 0, 7) === $ay;
        $girisGun = $girisAyda ? (int) substr($giris, 8, 2) : 1;
        $cikisGun = $cikisAyda ? min(30, (int) substr($cikis, 8, 2)) : 30;
        if ($girisAyda && $cikisAyda) {
            $gun = max(0, $cikisGun - $girisGun + 1);
        } elseif ($girisAyda) {
            $gun = 30 - ($girisGun - 1);
        } elseif ($cikisAyda) {
            $gun = $cikisGun;
        }
        return max(0.0, min(30.0, (float) $gun));
    }

    /**
     * İşten çıkış ver: tarih + pasife düşür (geri alma: $tarih = null).
     * Çıkış ayında maaş kıst hesaplanır, kıdem o tarihte donar.
     */
    public function setPersonelCikis(int $personelId, ?string $tarih): array
    {
        $p = $this->personel($personelId);
        if (!$p) {
            throw new \InvalidArgumentException('Personel bulunamadı: ' . $personelId);
        }
        $tarih = ($tarih !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) ? $tarih : null;
        $this->pdo->prepare('UPDATE personel SET ise_cikis = ?, is_active = ? WHERE id = ?')
            ->execute([$tarih, $tarih === null ? 1 : 0, $personelId]);
        $ay = $tarih !== null ? substr($tarih, 0, 7) : date('Y-m');
        $maas = $this->personelMaasAy($personelId, $ay);
        return [
            'ad'        => (string) $p['ad'],
            'cikis'     => $tarih,
            'kist_gun'  => (float) $maas['calisma_gunu'],
            'kist_maas' => (float) $maas['hesaplanan_maas'],
            'kidem'     => $this->kidemBirikim($personelId, $ay)['birikim'],
        ];
    }

    /** Seçili ay için çalışma günü ve ödeme durumunu döndür; kayıt yoksa giriş/çıkış takviminden hesaplanır. */
    public function personelMaasAy(int $personelId, string $ay): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
            throw new \InvalidArgumentException('Geçersiz ay: ' . $ay);
        }
        $p = $this->personel($personelId);
        $row = $this->personelMaasAyRaw($personelId, $ay);
        // fable-015: kayıt yoksa takvimden (giriş/çıkış ayında kıst); kayıt varsa takvim tavanını aşamaz.
        $takvim = $p ? self::takvimCalismaGunu($p['ise_giris'] ?? null, $p['ise_cikis'] ?? null, $ay) : 30.0;
        $gun = $row ? min((float) $row['calisma_gunu'], $takvim) : $takvim;
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
            // fable-015: çıkış verildiyse kıdem o tarihte DONAR (fesihte ödenecek tutar).
            $cikis = ($p['ise_cikis'] ?? null) !== null ? substr((string) $p['ise_cikis'], 0, 10) : null;
            if ($cikis !== null && $cikis < $bitis) {
                $bitis = $cikis;
            }
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

        $eslesme = $this->personelEslestirmeler(); // fable-035: personel→müşteri override
        $persons = null;                            // lazy: customerPersonsMap()

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
            // fable-035: eşleştirme VARSA → SADECE o müşterilere KİŞİ oranında (elle atamayı ezer).
            if (!empty($eslesme[$pid])) {
                $targets = $eslesme[$pid];
                $persons ??= $this->customerPersonsMap($ay);
                $sub = 0.0;
                foreach ($targets as $cid) {
                    $sub += $persons[$cid] ?? 0.0;
                }
                if ($sub > 0) {
                    foreach ($targets as $cid) {
                        $per[$cid] = ($per[$cid] ?? 0.0) + $yuklu * ($persons[$cid] ?? 0.0) / $sub;
                    }
                } else {
                    $n = count($targets);
                    foreach ($targets as $cid) {
                        $per[$cid] = ($per[$cid] ?? 0.0) + $yuklu / $n;
                    }
                }
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
    /**
     * fable-017: personel gider kaydını düzelt (yanlış tutar/tarih girildiyse). Silip yeniden
     * girmeye gerek yok; kayıt aynı kalır, audit "eski → yeni" tutar.
     * @return array{ad:?string,tur:string,eski:float,yeni:float}|null kayıt yoksa null
     */
    public function updatePersonelGider(int $id, float $tutar, string $tarih, ?string $aciklama = null): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT g.id, g.tutar, g.tur, p.ad FROM personel_gider g
               LEFT JOIN personel p ON p.id = g.personel_id WHERE g.id = ?'
        );
        $st->execute([$id]);
        $cur = $st->fetch();
        if (!$cur || $tutar <= 0) {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) {
            $tarih = date('Y-m-d');
        }
        $this->pdo->prepare('UPDATE personel_gider SET tutar = ?, tarih = ?, aciklama = ? WHERE id = ?')
            ->execute([$tutar, $tarih, $aciklama, $id]);
        return ['ad' => $cur['ad'] ?? null, 'tur' => (string) $cur['tur'], 'eski' => (float) $cur['tutar'], 'yeni' => $tutar];
    }

    /** fable-017: personel gider kaydını sil (yanlış kayıt; maaş gideri ise ay kaydının bağı kopar). */
    public function deletePersonelGider(int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT g.id, g.tutar, g.tur, p.ad FROM personel_gider g
               LEFT JOIN personel p ON p.id = g.personel_id WHERE g.id = ?'
        );
        $st->execute([$id]);
        $cur = $st->fetch();
        if (!$cur) {
            return null;
        }
        $this->pdo->prepare('UPDATE personel_maas_ay SET gider_id = NULL WHERE gider_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM personel_gider WHERE id = ?')->execute([$id]);
        return ['ad' => $cur['ad'] ?? null, 'tur' => (string) $cur['tur'], 'tutar' => (float) $cur['tutar']];
    }

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

    /** Kişinin o ay aldığı toplam avans. */
    public function personelAvansAy(int $personelId, string $ay): float
    {
        $st = $this->pdo->prepare(
            "SELECT COALESCE(SUM(tutar),0) FROM personel_gider
             WHERE personel_id = ? AND tur = 'avans' AND substr(tarih,1,7) = ?"
        );
        $st->execute([$personelId, $ay]);
        return (float) $st->fetchColumn();
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
        $ar = $this->ayAralik($ay); // fable-042: cari ayda MTD
        $st = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount),0) FROM production WHERE prod_date BETWEEN ? AND ?'
        );
        $st->execute([$ar['bas'], $ar['son']]);
        return (float) $st->fetchColumn();
    }

    /**
     * Aylık ÜRETİM cirosu — SADECE category='uretim' müşteriler (taşıma HARİÇ).
     * Net karlılıkta üretim cirosu taşıma satışını İÇERMEZ (opus-013 kategori ayrımı).
     */
    public function monthUretimCiro(string $ay): float
    {
        $ar = $this->ayAralik($ay); // fable-042: cari ayda MTD (netKarlilik gelir tarafı)
        $st = $this->pdo->prepare(
            "SELECT COALESCE(SUM(p.amount),0)
             FROM production p JOIN customers c ON c.id = p.customer_id
             WHERE c.category = 'uretim' AND p.prod_date BETWEEN ? AND ?"
        );
        $st->execute([$ar['bas'], $ar['son']]);
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

    // ── fable-035: Tedarikçi/Personel → müşteri MALİYET EŞLEŞTİRMESİ ──────
    /**
     * Bir gider tx satırından FİRMA (tedarikçi) adını türet — giderFirmaOzet ile TEK KAYNAK.
     * Paraşüt: description'ın ' · ' öncesi (senkron 'TEDARİKÇİ · faturaNo' yazar).
     * Elle girilen: 'Elle girilen · {kategori}'. $r: source/category/description alanları.
     */
    private function txFirma(array $r): string
    {
        if (($r['source'] ?? 'manuel') === 'parasut') {
            $d = (string) ($r['description'] ?? '');
            $firma = trim(explode(' · ', $d)[0] ?? '');
            return $firma !== '' ? $firma : 'Paraşüt (tedarikçi bilinmiyor)';
        }
        // fable-043: elle girilen + tedarikçi seçili → tedarikçi ADI (firma karnesi/eşleştirme/gıda
        // hepsi bu tek kaynaktan; supplier yoksa eski 'Elle girilen · kategori' fallback korunur).
        $sup = trim((string) ($r['supplier_name'] ?? ''));
        if ($sup !== '') {
            return $sup;
        }
        return 'Elle girilen · ' . (trim((string) ($r['category'] ?? '')) ?: 'Diğer');
    }

    /**
     * Firma/tedarikçi adı eşleştirme ANAHTARI — TR-uyumlu upper + trim.
     * mb_strtoupper 'i'↔'İ' / 'ı'↔'I' tuzağını çözmek için önce strtr ile TR harflerini
     * büyük karşılığına çevirir (KIRMIZI 1 == kırmızı 1 == Kırmızı 1). Yazarken de okurken de aynı.
     */
    public static function normTedarikci(string $s): string
    {
        $s = trim($s);
        $s = strtr($s, ['i' => 'İ', 'ı' => 'I']);
        return mb_substr(mb_strtoupper($s, 'UTF-8'), 0, 190);
    }

    /** Kayıtlı tedarikçi→müşteri eşleştirmeleri. @return array<string,array<int,int>> normAnahtar→[customer_id,...] */
    public function tedarikciEslestirmeler(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT tedarikci, customer_id FROM tedarikci_musteri_map ORDER BY id')->fetchAll() as $r) {
            $out[(string) $r['tedarikci']][] = (int) $r['customer_id'];
        }
        return $out;
    }

    /** Bir tedarikçinin eşleştirmesini ayarla (sil+yaz; boş liste = eşleşmeyi kaldır). Anahtar normalize. */
    public function tedarikciEslestirmeKaydet(string $tedarikci, array $customerIds): void
    {
        $key = self::normTedarikci($tedarikci);
        if ($key === '') {
            return;
        }
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare('DELETE FROM tedarikci_musteri_map WHERE tedarikci = ?')->execute([$key]);
            $ins = $this->pdo->prepare('INSERT INTO tedarikci_musteri_map (tedarikci, customer_id) VALUES (?, ?)');
            foreach (array_unique(array_map('intval', $customerIds)) as $cid) {
                if ($cid > 0) {
                    $ins->execute([$key, $cid]);
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

    /** Kayıtlı personel→müşteri eşleştirmeleri. @return array<int,array<int,int>> personel_id→[customer_id,...] */
    public function personelEslestirmeler(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT personel_id, customer_id FROM personel_musteri_map ORDER BY id')->fetchAll() as $r) {
            $out[(int) $r['personel_id']][] = (int) $r['customer_id'];
        }
        return $out;
    }

    /** Bir personelin eşleştirmesini ayarla (sil+yaz; boş liste = eşleşmeyi kaldır). */
    public function personelEslestirmeKaydet(int $personelId, array $customerIds): void
    {
        if ($personelId <= 0) {
            return;
        }
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare('DELETE FROM personel_musteri_map WHERE personel_id = ?')->execute([$personelId]);
            $ins = $this->pdo->prepare('INSERT INTO personel_musteri_map (personel_id, customer_id) VALUES (?, ?)');
            foreach (array_unique(array_map('intval', $customerIds)) as $cid) {
                if ($cid > 0) {
                    $ins->execute([$personelId, $cid]);
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

    // ── fable-037: FATURA bazlı → müşteri MALİYET EŞLEŞTİRMESİ ───────────
    /**
     * Kayıtlı fatura(tx)→müşteri eşleştirmeleri. En üst öncelik katmanı (tedarikçi/genel'i ezer).
     * $ay verilirse sadece o aya ait tx'ler döner (UI için); yoksa TÜMÜ (giderDagitim için).
     * @return array<int,array<int,int>> tx_id → [customer_id,...]
     */
    public function faturaEslestirmeler(?string $ay = null): array
    {
        if ($ay !== null) {
            $st = $this->pdo->prepare(
                'SELECT m.tx_id, m.customer_id FROM fatura_musteri_map m
                 JOIN transactions t ON t.id = m.tx_id
                 WHERE substr(t.tx_date,1,7) = ? ORDER BY m.id'
            );
            $st->execute([$ay]);
            $rows = $st->fetchAll();
        } else {
            $rows = $this->pdo->query('SELECT tx_id, customer_id FROM fatura_musteri_map ORDER BY id')->fetchAll();
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['tx_id']][] = (int) $r['customer_id'];
        }
        return $out;
    }

    /** Bir faturanın (tx) eşleştirmesini ayarla (sil+yaz; boş liste = özel dağıtımı kaldır). */
    public function faturaEslestirmeKaydet(int $txId, array $customerIds): void
    {
        if ($txId <= 0) {
            return;
        }
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare('DELETE FROM fatura_musteri_map WHERE tx_id = ?')->execute([$txId]);
            $ins = $this->pdo->prepare('INSERT INTO fatura_musteri_map (tx_id, customer_id) VALUES (?, ?)');
            foreach (array_unique(array_map('intval', $customerIds)) as $cid) {
                if ($cid > 0) {
                    $ins->execute([$txId, $cid]);
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
     * Son N aydaki gider faturalarını FİRMA anahtarına göre grupla (UI "fatura bazlı" modu için).
     * distinctGiderFirmalar ile AYNI kaynak/filtre ('Personel'/'Taşıma alış' hariç, txFirma tek kaynak).
     * @return array<string,array<int,array{id:int,tx_date:string,amount:float,description:string,no:string}>>
     *   firmaKey → faturalar (tarih DESC).
     */
    public function faturaListeleri(int $aySayisi = 6): array
    {
        $bas = date('Y-m-01', strtotime('-' . max(0, $aySayisi - 1) . ' months'));
        $st = $this->pdo->prepare(
            "SELECT t.id, t.tx_date, t.amount, t.source, t.category, t.description, s.name AS supplier_name
             FROM transactions t
             LEFT JOIN suppliers s ON s.id = t.supplier_id
             WHERE t.type = 'gider' AND t.tx_date >= ?
               AND (t.category IS NULL OR t.category NOT IN ('Personel', 'Taşıma alış'))
             ORDER BY t.tx_date DESC, t.id DESC"
        );
        $st->execute([$bas]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $key = self::normTedarikci($this->txFirma($r));
            if ($key === '') {
                continue;
            }
            $desc = (string) ($r['description'] ?? '');
            $parts = explode(' · ', $desc, 2);
            $no = isset($parts[1]) ? trim($parts[1]) : trim($desc);
            $out[$key][] = [
                'id'          => (int) $r['id'],
                'tx_date'     => (string) $r['tx_date'],
                'amount'      => (float) $r['amount'],
                'description' => $desc,
                'no'          => $no,
            ];
        }
        return $out;
    }

    /**
     * O ay müşteri başına TOPLAM kişi sayısı (üretim günlük persons toplamı). Ciro ile aynı
     * kaynak (monthProductionByCustomer) → maliyet dağıtımında kişi ağırlığı. @return array<int,int>
     */
    public function customerPersonsMap(string $ay): array
    {
        $out = [];
        foreach ($this->monthProductionByCustomer($ay) as $r) {
            $out[(int) $r['customer_id']] = (int) $r['persons'];
        }
        return $out;
    }

    /**
     * fable-040: Bir üretim müşterisinin o ay GERÇEK üretim GÜNLÜK ORTALAMASI (yalnız hafta
     * içi günler; kural hafta içine uygulanır). Kar-analizi "50 üretim · 70 fatura" rozetinin
     * "üretim" tarafı. Hafta içi kayıt yoksa null. Gün başına persons (öğün toplamı) ortalanır.
     */
    public function uretimGunlukOrtalama(int $customerId, string $ay): ?int
    {
        $st = $this->pdo->prepare(
            'SELECT prod_date, SUM(persons) AS p FROM production
             WHERE customer_id = ? AND substr(prod_date,1,7) = ?
             GROUP BY prod_date'
        );
        $st->execute([$customerId, $ay]);
        $tot = 0; $gun = 0;
        foreach ($st->fetchAll() as $r) {
            if ((int) date('N', strtotime((string) $r['prod_date'])) >= 6) {
                continue; // cumartesi/pazar kural dışı → ortalamaya girmez
            }
            $tot += (int) $r['p'];
            $gun++;
        }
        return $gun > 0 ? (int) round($tot / $gun) : null;
    }

    /**
     * Son N ayda gider tx'lerinde görülen distinct FİRMA listesi (eşleştirme ekranı için).
     * normAnahtar ile tekilleştirilmiş; 'Personel'/'Taşıma alış' kategorileri HARİÇ (dağıtımda
     * zaten dışlanır — matching etkisiz olurdu). @return array<int,array{key:string,label:string,adet:int,toplam:float}>
     */
    public function distinctGiderFirmalar(int $aySayisi = 6): array
    {
        $bas = date('Y-m-01', strtotime('-' . max(0, $aySayisi - 1) . ' months'));
        $st = $this->pdo->prepare(
            "SELECT t.source, t.category, t.description, t.amount, s.name AS supplier_name
             FROM transactions t
             LEFT JOIN suppliers s ON s.id = t.supplier_id
             WHERE t.type = 'gider' AND t.tx_date >= ?
               AND (t.category IS NULL OR t.category NOT IN ('Personel', 'Taşıma alış'))"
        );
        $st->execute([$bas]);
        $grup = [];
        foreach ($st->fetchAll() as $r) {
            $firma = $this->txFirma($r);
            $key = self::normTedarikci($firma);
            if ($key === '') {
                continue;
            }
            if (!isset($grup[$key])) {
                $grup[$key] = ['key' => $key, 'label' => $firma, 'adet' => 0, 'toplam' => 0.0];
            }
            $grup[$key]['adet']++;
            $grup[$key]['toplam'] += (float) $r['amount'];
        }
        usort($grup, static fn(array $a, array $b) => $b['toplam'] <=> $a['toplam']);
        return array_values($grup);
    }

    // ── fable-045: BORÇLARIM — tedarikçi bazlı borç (AYDAN BAĞIMSIZ, kümülatif) ──────────
    // Borç(tedarikçi) = devir(elle bir kere) + Σ(tüm zaman gider faturaları) − Σ(ödemeler).
    // AY FİLTRESİ BORCU ETKİLEMEZ: sorgularda tarih/ay kısıtı YOK (testle kanıtlı).
    // 'Personel' + 'Taşıma alış' HARİÇ — distinctGiderFirmalar/faturaListeleri ile birebir aynı
    // "tedarikçi faturası" tanımı (personel maaşının kendi ödeme akışı var; çift-sayım önlenir).

    /**
     * Tüm tedarikçilerin GÜNCEL borç durumu (aydan bağımsız). Kaynak birleşimi:
     *   gider faturaları (normTedarikci) ⊕ ödemeler (tedarikci_odeme) ⊕ devir (tedarikci_devir).
     * Faturası olmayıp yalnız devir/ödemesi olan tedarikçi de listede kalır (yetim borç gizlenmez).
     * @return array<int,array{key:string,label:string,fatura:float,adet:int,odenen:float,devir:float,kalan:float}>
     *   kalan DESC (en çok borçlu üstte).
     */
    public function borclarimListe(): array
    {
        $grup = [];
        $ensure = static function (string $key, string $label) use (&$grup): void {
            if (!isset($grup[$key])) {
                $grup[$key] = ['key' => $key, 'label' => $label !== '' ? $label : $key,
                    'fatura' => 0.0, 'adet' => 0, 'odenen' => 0.0, 'devir' => 0.0, 'kalan' => 0.0];
            }
        };

        // 1) Faturalar — TÜM ZAMAN (tarih kısıtı YOK), Personel/Taşıma alış hariç.
        $st = $this->pdo->query(
            "SELECT t.source, t.category, t.description, t.amount, s.name AS supplier_name
             FROM transactions t
             LEFT JOIN suppliers s ON s.id = t.supplier_id
             WHERE t.type = 'gider'
               AND (t.category IS NULL OR t.category NOT IN ('Personel', 'Taşıma alış'))"
        );
        foreach ($st->fetchAll() as $r) {
            $firma = $this->txFirma($r);
            $key = self::normTedarikci($firma);
            if ($key === '') {
                continue;
            }
            $ensure($key, $firma);
            $grup[$key]['fatura'] += (float) $r['amount'];
            $grup[$key]['adet']++;
        }

        // 2) Ödemeler (tedarikci_odeme; negatif düzeltmeler dahil toplam).
        foreach ($this->pdo->query('SELECT tedarikci, COALESCE(SUM(tutar),0) AS odenen FROM tedarikci_odeme GROUP BY tedarikci')->fetchAll() as $r) {
            $key = (string) $r['tedarikci'];
            if ($key === '') {
                continue;
            }
            $ensure($key, $key);
            $grup[$key]['odenen'] += (float) $r['odenen'];
        }

        // 3) Devir (açılış borcu).
        foreach ($this->pdo->query('SELECT tedarikci, label, tutar FROM tedarikci_devir')->fetchAll() as $r) {
            $key = (string) $r['tedarikci'];
            if ($key === '') {
                continue;
            }
            $ensure($key, trim((string) $r['label']));
            $grup[$key]['devir'] += (float) $r['tutar'];
        }

        foreach ($grup as &$g) {
            $g['kalan'] = round($g['devir'] + $g['fatura'] - $g['odenen'], 2);
        }
        unset($g);
        usort($grup, static fn(array $a, array $b) => $b['kalan'] <=> $a['kalan']);
        return array_values($grup);
    }

    /**
     * fable-046: TÜM tedarikçilerin borç DETAYI tek geçişte (Cari > Borçlarım artık satırları
     * inline açıyor — her satır için ayrı borclarimDetay() çağrısı N tam tarama demekti).
     * Tanım/rakam borclarimDetay ile BİREBİR aynı; tek fark tarama sayısı. Sıra: kalan DESC.
     * @return array<string,array{key:string,label:string,fatura:float,adet:int,odenen:float,devir:float,kalan:float,faturalar:array,odemeler:array}>
     */
    public function borclarimDetayTumu(): array
    {
        $det = [];
        $ensure = static function (string $key, string $label) use (&$det): void {
            if (!isset($det[$key])) {
                $det[$key] = ['key' => $key, 'label' => $label !== '' ? $label : $key,
                    'fatura' => 0.0, 'adet' => 0, 'odenen' => 0.0, 'devir' => 0.0, 'kalan' => 0.0,
                    'faturalar' => [], 'odemeler' => []];
            }
        };

        // 1) Faturalar — TÜM ZAMAN, Personel/Taşıma alış hariç (borclarimListe ile aynı tanım).
        $st = $this->pdo->query(
            "SELECT t.id, t.tx_date, t.amount, t.source, t.category, t.description, s.name AS supplier_name
             FROM transactions t
             LEFT JOIN suppliers s ON s.id = t.supplier_id
             WHERE t.type = 'gider'
               AND (t.category IS NULL OR t.category NOT IN ('Personel', 'Taşıma alış'))
             ORDER BY t.tx_date DESC, t.id DESC"
        );
        foreach ($st->fetchAll() as $r) {
            $firma = $this->txFirma($r);
            $key = self::normTedarikci($firma);
            if ($key === '') {
                continue;
            }
            $ensure($key, $firma);
            $desc = (string) ($r['description'] ?? '');
            $parts = explode(' · ', $desc, 2);
            $det[$key]['faturalar'][] = [
                'id'       => (int) $r['id'],
                'tx_date'  => (string) $r['tx_date'],
                'amount'   => (float) $r['amount'],
                'no'       => isset($parts[1]) ? trim($parts[1]) : trim($desc),
                'kategori' => (string) ($r['category'] ?? ''),
            ];
            $det[$key]['fatura'] += (float) $r['amount'];
            $det[$key]['adet']++;
        }

        // 2) Ödemeler (negatif düzeltmeler dahil).
        foreach ($this->pdo->query('SELECT id, tedarikci, odeme_tarihi, tutar, note FROM tedarikci_odeme ORDER BY odeme_tarihi DESC, id DESC')->fetchAll() as $r) {
            $key = (string) $r['tedarikci'];
            if ($key === '') {
                continue;
            }
            $ensure($key, $key);
            $det[$key]['odemeler'][] = $r;
            $det[$key]['odenen'] += (float) $r['tutar'];
        }

        // 3) Devir (açılış borcu).
        foreach ($this->pdo->query('SELECT tedarikci, label, tutar FROM tedarikci_devir')->fetchAll() as $r) {
            $key = (string) $r['tedarikci'];
            if ($key === '') {
                continue;
            }
            $ensure($key, trim((string) $r['label']));
            $det[$key]['devir'] += (float) $r['tutar'];
        }

        foreach ($det as &$d) {
            $d['fatura'] = round($d['fatura'], 2);
            $d['odenen'] = round($d['odenen'], 2);
            $d['devir'] = round($d['devir'], 2);
            $d['kalan'] = round($d['devir'] + $d['fatura'] - $d['odenen'], 2);
        }
        unset($d);
        uasort($det, static fn(array $a, array $b) => $b['kalan'] <=> $a['kalan']);
        return $det;
    }

    /**
     * Bir tedarikçinin borç DETAYI: faturalar (tarih DESC) + ödemeler (tarih DESC) + devir + toplamlar.
     * AYDAN BAĞIMSIZ (tarih kısıtı yok). @return array{key,label,fatura,adet,odenen,devir,kalan,faturalar,odemeler}
     */
    public function borclarimDetay(string $key): array
    {
        $key = self::normTedarikci($key);
        $faturalar = [];
        $label = '';
        $fatura = 0.0;
        $st = $this->pdo->query(
            "SELECT t.id, t.tx_date, t.amount, t.source, t.category, t.description, s.name AS supplier_name
             FROM transactions t
             LEFT JOIN suppliers s ON s.id = t.supplier_id
             WHERE t.type = 'gider'
               AND (t.category IS NULL OR t.category NOT IN ('Personel', 'Taşıma alış'))
             ORDER BY t.tx_date DESC, t.id DESC"
        );
        foreach ($st->fetchAll() as $r) {
            $firma = $this->txFirma($r);
            if (self::normTedarikci($firma) !== $key) {
                continue;
            }
            if ($label === '') {
                $label = $firma;
            }
            $desc = (string) ($r['description'] ?? '');
            $parts = explode(' · ', $desc, 2);
            $faturalar[] = [
                'id'       => (int) $r['id'],
                'tx_date'  => (string) $r['tx_date'],
                'amount'   => (float) $r['amount'],
                'no'       => isset($parts[1]) ? trim($parts[1]) : trim($desc),
                'kategori' => (string) ($r['category'] ?? ''),
            ];
            $fatura += (float) $r['amount'];
        }

        $od = $this->pdo->prepare('SELECT id, odeme_tarihi, tutar, note FROM tedarikci_odeme WHERE tedarikci = ? ORDER BY odeme_tarihi DESC, id DESC');
        $od->execute([$key]);
        $odemeler = $od->fetchAll();
        $odenen = 0.0;
        foreach ($odemeler as $o) {
            $odenen += (float) $o['tutar'];
        }

        $dv = $this->pdo->prepare('SELECT label, tutar FROM tedarikci_devir WHERE tedarikci = ?');
        $dv->execute([$key]);
        $drow = $dv->fetch();
        $devir = $drow ? (float) $drow['tutar'] : 0.0;
        if ($label === '' && $drow && trim((string) $drow['label']) !== '') {
            $label = trim((string) $drow['label']);
        }
        if ($label === '') {
            $label = $key;
        }

        return [
            'key' => $key, 'label' => $label, 'fatura' => round($fatura, 2), 'adet' => count($faturalar),
            'odenen' => round($odenen, 2), 'devir' => round($devir, 2),
            'kalan' => round($devir + $fatura - $odenen, 2),
            'faturalar' => $faturalar, 'odemeler' => $odemeler,
        ];
    }

    /**
     * Tedarikçiye ödeme işle (kısmi/tam) veya NEGATİF düzeltme kaydı. Silme YOK.
     * Sıfır tutar reddedilir (0 döner). Tarih geçersizse bugüne düşer. @return int yeni id / 0.
     */
    public function tedarikciOdemeEkle(string $key, string $odemeTarihi, float $tutar, ?string $note = null): int
    {
        $key = self::normTedarikci($key);
        if ($key === '' || abs($tutar) < 0.005) {
            return 0;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $odemeTarihi)) {
            $odemeTarihi = date('Y-m-d');
        }
        $note = $note !== null ? mb_substr(trim($note), 0, 300) : '';
        $this->pdo->prepare('INSERT INTO tedarikci_odeme (tedarikci, odeme_tarihi, tutar, note) VALUES (?, ?, ?, ?)')
            ->execute([$key, $odemeTarihi, round($tutar, 2), $note !== '' ? $note : null]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Tedarikçi devir (açılış) bakiyesi upsert (elle bir kere; güncellenebilir). label boşsa korunur. */
    public function tedarikciDevirKaydet(string $key, string $label, float $tutar): void
    {
        $key = self::normTedarikci($key);
        if ($key === '') {
            return;
        }
        $label = mb_substr(trim($label), 0, 190);
        $exists = $this->pdo->prepare('SELECT id FROM tedarikci_devir WHERE tedarikci = ?');
        $exists->execute([$key]);
        $id = $exists->fetchColumn();
        if ($id !== false) {
            $this->pdo->prepare("UPDATE tedarikci_devir SET tutar = ?, label = CASE WHEN ? <> '' THEN ? ELSE label END, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([round($tutar, 2), $label, $label, (int) $id]);
        } else {
            $this->pdo->prepare('INSERT INTO tedarikci_devir (tedarikci, label, tutar) VALUES (?, ?, ?)')
                ->execute([$key, $label, round($tutar, 2)]);
        }
    }

    // ── fable-039: Kişi başı GIDA MALİYETİ kırılımları ───────────────────
    /**
     * Parametrik gıda kırılımları (sıra + kod + ad). @return array<int,array{kod:string,ad:string,sira:int}>
     */
    public function gidaKirilimlar(): array
    {
        return $this->pdo->query('SELECT kod, ad, sira FROM gida_kirilim ORDER BY sira, id')->fetchAll();
    }

    /**
     * Tedarikçi → gıda kırılım eşlemesi. Sadece kaydı olanlar döner (satır yok = gıda costu DIŞI).
     * @return array<string,?string> normAnahtar → kirilim_kod (null olabilir = yine gıda costu dışı)
     */
    public function tedarikciGidaMap(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT tedarikci, kirilim_kod FROM tedarikci_gida_map ORDER BY id')->fetchAll() as $r) {
            $out[(string) $r['tedarikci']] = $r['kirilim_kod'] !== null ? (string) $r['kirilim_kod'] : null;
        }
        return $out;
    }

    /**
     * Bir tedarikçinin gıda kırılımını ayarla. $kirilimKod null/'' → eşleşmeyi kaldır (gıda costu dışı).
     * Geçersiz kod → kaldır (sessiz düşme yerine güvenli varsayılan). Anahtar normalize.
     */
    public function tedarikciGidaKaydet(string $tedarikci, ?string $kirilimKod): void
    {
        $key = self::normTedarikci($tedarikci);
        if ($key === '') {
            return;
        }
        if ($kirilimKod !== null && $kirilimKod !== '') {
            $valid = array_column($this->gidaKirilimlar(), 'kod');
            if (!in_array($kirilimKod, $valid, true)) {
                $kirilimKod = null;
            }
        } else {
            $kirilimKod = null;
        }
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare('DELETE FROM tedarikci_gida_map WHERE tedarikci = ?')->execute([$key]);
            if ($kirilimKod !== null) {
                $this->pdo->prepare('INSERT INTO tedarikci_gida_map (tedarikci, kirilim_kod) VALUES (?, ?)')->execute([$key, $kirilimKod]);
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
     * Kişi başı gıda maliyeti özeti (bir ay). Tutar kaynağı: o ayki gider tx'lerinden
     * (Personel/Taşıma alış HARİÇ, giderFirmaOzet ile tek kaynak) tedarikçisi bir gıda
     * kırılımına EŞLİ olanların TAMAMI (dağıtımdan bağımsız BRÜT gıda alımı). Kişi = o ay
     * ÜRETİM kategorisi müşterilerin toplam kişi sayısı (taşıma HARİÇ). Eşleşmeyen tedarikçi
     * gıda costuna girmez → karAnalizi/netKarlilik sayılarını ETKİLEMEZ (ayrı görünüm katmanı).
     * @return array{toplam:float,kisi_toplam:int,kisi_basi:float,kirilimlar:array<int,array{kod:string,ad:string,tutar:float,kisi_basi:float,oran:float}>}
     */
    public function gidaCostOzet(string $ay): array
    {
        $kirilimlar = $this->gidaKirilimlar();
        $adMap = [];
        $siraMap = [];
        foreach ($kirilimlar as $i => $k) {
            $adMap[(string) $k['kod']] = (string) $k['ad'];
            $siraMap[(string) $k['kod']] = $i;
        }
        $map = $this->tedarikciGidaMap();

        // Kişi = o ay ÜRETİM kategorisi müşterilerin toplam kişi sayısı (taşıma HARİÇ).
        $kisiToplam = 0;
        foreach ($this->monthProductionByCustomer($ay, 'uretim') as $r) {
            $kisiToplam += (int) $r['persons'];
        }

        // Gıda kırılımına eşli gider tx'lerinin BRÜT toplamı (kırılım bazında).
        $st = $this->pdo->prepare(
            "SELECT source, category, description, amount FROM transactions
             WHERE type = 'gider' AND substr(tx_date,1,7) = ?
               AND (category IS NULL OR category NOT IN ('Personel', 'Taşıma alış'))"
        );
        $st->execute([$ay]);
        $perKir = [];
        $toplam = 0.0;
        foreach ($st->fetchAll() as $r) {
            $key = self::normTedarikci($this->txFirma($r));
            $kod = $map[$key] ?? null;              // satır yok / null = gıda costu DIŞI
            if ($kod === null || !isset($adMap[$kod])) {
                continue;
            }
            $amt = (float) $r['amount'];
            $perKir[$kod] = ($perKir[$kod] ?? 0.0) + $amt;
            $toplam += $amt;
        }

        $rows = [];
        foreach ($perKir as $kod => $tutar) {
            $rows[] = [
                'kod'       => $kod,
                'ad'        => $adMap[$kod],
                'tutar'     => $tutar,
                'kisi_basi' => $kisiToplam > 0 ? $tutar / $kisiToplam : 0.0,
                'oran'      => $toplam > 0 ? $tutar / $toplam : 0.0,
            ];
        }
        usort($rows, static fn(array $a, array $b) => ($siraMap[$a['kod']] ?? 99) <=> ($siraMap[$b['kod']] ?? 99));

        return [
            'toplam'      => $toplam,
            'kisi_toplam' => $kisiToplam,
            'kisi_basi'   => $kisiToplam > 0 ? $toplam / $kisiToplam : 0.0,
            'kirilimlar'  => $rows,
        ];
    }

    /**
     * fable-044: Bir gıda kırılımının o ayki ÜRÜN KALEMLERİ — en çok para harcanan top-N.
     * gidaCostOzet ile AYNI tx kümesi (kırılıma eşli tedarikçiler, aynı filtre → aynı toplam).
     * Kalemler ürün adına göre gruplanır (TR-normalize anahtar; farklı yazımlar ayrı kalabilir,
     * birleştirme zorlaması yok). Her grup: Σtutar, Σmiktar, ORTALAMA birim fiyat (Σtutar/Σmiktar;
     * miktar eksikse boş), fatura adedi. 'kapsanmayan' = kırılım toplam − kalemli toplam (satırsız
     * fatura + KDV farkı — dürüstlük satırı; ÖRS gibi satırı olmayanlar burada görünür).
     * @return array{kirilim_kod:string,kirilim_ad:string,toplam:float,kalemli_toplam:float,
     *   kapsanmayan:float,urun_sayisi:int,urunler:array<int,array{urun:string,miktar:?float,
     *   birim:?string,ort_birim_fiyat:?float,tutar:float,fatura_adedi:int}>}
     */
    public function kirilimUrunOzet(string $ay, string $kirilimKod, int $limit = 10): array
    {
        $adMap = [];
        foreach ($this->gidaKirilimlar() as $k) {
            $adMap[(string) $k['kod']] = (string) $k['ad'];
        }
        $map = $this->tedarikciGidaMap();

        // Bu kırılıma eşli gider tx'leri (gidaCostOzet ile AYNI filtre → toplam birebir tutar).
        $st = $this->pdo->prepare(
            "SELECT id, source, category, description, amount FROM transactions
             WHERE type = 'gider' AND substr(tx_date,1,7) = ?
               AND (category IS NULL OR category NOT IN ('Personel', 'Taşıma alış'))"
        );
        $st->execute([$ay]);
        $txIds = [];
        $toplam = 0.0;
        foreach ($st->fetchAll() as $r) {
            $key = self::normTedarikci($this->txFirma($r));
            if (($map[$key] ?? null) !== $kirilimKod) {
                continue;
            }
            $txIds[] = (int) $r['id'];
            $toplam += (float) $r['amount'];
        }

        $bos = [
            'kirilim_kod' => $kirilimKod, 'kirilim_ad' => $adMap[$kirilimKod] ?? $kirilimKod,
            'toplam' => $toplam, 'kalemli_toplam' => 0.0, 'kapsanmayan' => max(0.0, $toplam),
            'urun_sayisi' => 0, 'urunler' => [],
        ];
        if ($txIds === []) {
            return $bos;
        }

        $ph = implode(',', array_fill(0, count($txIds), '?'));
        $ks = $this->pdo->prepare(
            "SELECT tx_id, urun, miktar, birim, birim_fiyat, tutar FROM gider_kalem WHERE tx_id IN ($ph)"
        );
        $ks->execute($txIds);

        $grup = [];             // normAd → agregat
        $kalemliToplam = 0.0;
        foreach ($ks->fetchAll() as $k) {
            $urun = trim((string) $k['urun']);
            $nk = self::normTedarikci($urun); // trim + TR-upper (grup anahtarı)
            $tutar = (float) $k['tutar'];
            $kalemliToplam += $tutar;
            if (!isset($grup[$nk])) {
                $grup[$nk] = ['urun' => $urun !== '' ? $urun : '(adsız)', 'birim' => null,
                    'tutar' => 0.0, 'miktar' => 0.0, 'miktar_tam' => true, 'tx' => []];
            }
            $grup[$nk]['tutar'] += $tutar;
            if ($k['miktar'] === null || (float) $k['miktar'] <= 0) {
                $grup[$nk]['miktar_tam'] = false;     // miktar eksik → ort. birim fiyat çıkmaz
            } else {
                $grup[$nk]['miktar'] += (float) $k['miktar'];
                if ($grup[$nk]['birim'] === null && $k['birim'] !== null && (string) $k['birim'] !== '') {
                    $grup[$nk]['birim'] = (string) $k['birim'];
                }
            }
            $grup[$nk]['tx'][(int) $k['tx_id']] = true;
        }

        $urunler = [];
        foreach ($grup as $g) {
            $miktar = ($g['miktar_tam'] && $g['miktar'] > 0) ? $g['miktar'] : null;
            $urunler[] = [
                'urun'            => $g['urun'],
                'miktar'          => $miktar,
                'birim'           => $g['birim'],
                'ort_birim_fiyat' => $miktar !== null ? $g['tutar'] / $miktar : null,
                'tutar'           => $g['tutar'],
                'fatura_adedi'    => count($g['tx']),
            ];
        }
        usort($urunler, static fn(array $a, array $b) => $b['tutar'] <=> $a['tutar']);
        $urunSayisi = count($urunler);
        if ($limit > 0) {
            $urunler = array_slice($urunler, 0, $limit);
        }

        return [
            'kirilim_kod'    => $kirilimKod,
            'kirilim_ad'     => $adMap[$kirilimKod] ?? $kirilimKod,
            'toplam'         => $toplam,
            'kalemli_toplam' => $kalemliToplam,
            'kapsanmayan'    => max(0.0, $toplam - $kalemliToplam),
            'urun_sayisi'    => $urunSayisi,
            'urunler'        => $urunler,
        ];
    }

    /**
     * fable-044: Paraşüt GELEN KUTUSU ('ei-' önekli) gider tx'lerinin UBL satırlarını gider_kalem'e
     * IDEMPOTENT yaz (tx'in kalemi zaten varsa atla). Satır ucu YALNIZ e-faturadan (signed_ubl)
     * çıkar; purchase_bill / elle girilen tx'ler bu senkronda YOK (onlar CSV/elle yüklenir).
     * $lineFetcher: test/mock için enjekte edilebilir (varsayılan Parasut::eInvoiceLines). UBL
     * okunamayan tx atlanır (kalem yazılmaz) → sonraki senkronda yeniden denenir.
     * @param null|callable(string):(?array) $lineFetcher eInvoiceId → satır listesi|null
     * @return array{aday:int,islenen:int,atlanan:int,okunamadi:int,kalem:int}
     */
    public function giderKalemSenkron(string $ay, ?callable $lineFetcher = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
            throw new \InvalidArgumentException('ay=YYYY-MM bekleniyor.');
        }
        $lineFetcher ??= static fn(string $eid): ?array => Parasut::eInvoiceLines($eid);

        $st = $this->pdo->prepare(
            "SELECT id, parasut_id FROM transactions
             WHERE type = 'gider' AND source = 'parasut' AND substr(tx_date,1,7) = ?
               AND parasut_id LIKE 'ei-%'
             ORDER BY id"
        );
        $st->execute([$ay]);
        $txlar = $st->fetchAll();

        $has = $this->pdo->prepare('SELECT 1 FROM gider_kalem WHERE tx_id = ? LIMIT 1');
        $ins = $this->pdo->prepare(
            'INSERT INTO gider_kalem (tx_id, urun, miktar, birim, birim_fiyat, tutar) VALUES (?, ?, ?, ?, ?, ?)'
        );

        $islenen = 0;
        $atlanan = 0;
        $okunamadi = 0;
        $kalem = 0;
        foreach ($txlar as $t) {
            $txId = (int) $t['id'];
            $has->execute([$txId]);
            if ($has->fetchColumn() !== false) {
                $atlanan++;                                  // idempotent: kalemi zaten var
                continue;
            }
            $eid = substr((string) $t['parasut_id'], 3);     // 'ei-' önekini at
            $lines = $lineFetcher($eid);
            if (!is_array($lines) || $lines === []) {
                $okunamadi++;                                // UBL okunamadı → yazma, sonra dene
                continue;
            }
            // Bir tx'in TÜM satırları atomik yazılır (kısmi yazım → sahte "kalemli" durumu olmasın).
            $own = !$this->pdo->inTransaction();
            if ($own) {
                $this->pdo->beginTransaction();
            }
            try {
                foreach ($lines as $L) {
                    $urun = mb_substr(trim((string) ($L['urun'] ?? '')), 0, 200);
                    if ($urun === '') {
                        continue;
                    }
                    $mik = (isset($L['miktar']) && $L['miktar'] !== null && (float) $L['miktar'] > 0) ? (float) $L['miktar'] : null;
                    $bir = (isset($L['birim']) && $L['birim'] !== null && (string) $L['birim'] !== '') ? mb_substr((string) $L['birim'], 0, 20) : null;
                    $bf  = (isset($L['birim_fiyat']) && $L['birim_fiyat'] !== null) ? round((float) $L['birim_fiyat'], 2) : null;
                    $ins->execute([$txId, $urun, $mik, $bir, $bf, round((float) ($L['tutar'] ?? 0), 2)]);
                    $kalem++;
                }
                if ($own) {
                    $this->pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($own && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
            $islenen++;
        }

        return ['aday' => count($txlar), 'islenen' => $islenen, 'atlanan' => $atlanan,
            'okunamadi' => $okunamadi, 'kalem' => $kalem];
    }

    /**
     * fable-042 ek (Ömer): kişi başı PERSONEL (işveren YÜKLÜ) maliyeti — ÜRETİM tarafı.
     * Tutar = personelDagitim'in ÜRETİM kategorisi müşterilere düşen payı toplamı (kar-analizi
     * P&L 'Personel payı' üretim toplamıyla BİREBİR; yeni hesap icat edilmez, aynı zincir).
     * Kişi = o ay üretim kategorisi toplam kişi (MTD payda — gıda kartıyla birebir). Taşıma
     * müşterisine (personel_musteri_map ile) düşen personel payı DAHİL DEĞİL (üretim rows dışı).
     * @return array{toplam:float,kisi_toplam:int,kisi_basi:float}
     */
    public function personelCostOzetUretim(string $ay): array
    {
        $persMap = $this->personelDagitim($ay)['per_customer'];
        $toplam = 0.0;
        $kisiToplam = 0;
        foreach ($this->monthProductionByCustomer($ay, 'uretim') as $r) {
            $cid = (int) $r['customer_id'];
            $toplam += (float) ($persMap[$cid] ?? 0.0);
            $kisiToplam += (int) $r['persons'];
        }
        return [
            'toplam'      => $toplam,
            'kisi_toplam' => $kisiToplam,
            'kisi_basi'   => $kisiToplam > 0 ? $toplam / $kisiToplam : 0.0,
        ];
    }

    /**
     * Tedarikçi→kırılım VARSAYILANLARINI idempotent yükle (Fable deploy'da bir kez CLI ile).
     * Canlı firma adları transactions.description'dan geldiği için LIKE değil, distinctGiderFirmalar
     * listesinde ANAHTAR KELİME (ÖRS, ATILGAN, ...) geçen firmayı bulup TAM normalize anahtarla kaydeder.
     * Zaten eşleşmesi olan tedarikçiyi EZMEZ (elle seçim korunur). @return array<string,string> anahtar→kod
     */
    public function gidaKirilimVarsayilanlariYukle(int $aySayisi = 12): array
    {
        // anahtar kelime (normalize) → kırılım kodu; TEKİNBEY/BOZDEMİR gibi TR harfli olanlar normTedarikci ile hizalı
        $defaults = [
            'ÖRS'        => 'kuru_gida',
            'ATILGAN'    => 'kirmizi_et',
            'TEKİNBEY'   => 'kirmizi_et',
            'BALCI'      => 'hal',
            'BOZDEMİR'   => 'hal',
            'OGÜN'       => 'beyaz_et',
            'KAR-UN-SAN' => 'ekmek',
            'HALK EKMEK' => 'ekmek',
            'POLATOĞLU'  => 'tatli',
        ];
        $normDefaults = [];
        foreach ($defaults as $kw => $kod) {
            $normDefaults[self::normTedarikci($kw)] = $kod;
        }
        $existing = $this->tedarikciGidaMap();
        $applied = [];
        foreach ($this->distinctGiderFirmalar($aySayisi) as $f) {
            $key = (string) $f['key']; // zaten normTedarikci
            if (array_key_exists($key, $existing)) {
                continue; // elle/önceki seçim korunur (idempotent)
            }
            foreach ($normDefaults as $kw => $kod) {
                if (mb_strpos($key, $kw) !== false) {
                    $this->tedarikciGidaKaydet((string) $f['label'], $kod);
                    $applied[$key] = $kod;
                    break;
                }
            }
        }
        return $applied;
    }

    /**
     * Gideri müşterilere dağıt (RAPOR ANINDA — ciro/kişi değişince güncellenir). Öncelik (üstten):
     *   fatura eşleşmesi VARSA (fable-037): o tx'in seçili müşterilerine KİŞİ SAYISI oranında (en üst).
     *   'musteri' gider: hedef müşteri(ler)e kendi ciroları oranında (elle seçim tedarikçi/genel'i ezer).
     *   tedarikçi eşleşmesi VARSA (fable-035): eşleşen müşterilere o ayki KİŞİ SAYISI oranında.
     *   aksi halde 'genel' gider: o ayki TÜM müşterilere ciroları oranında.
     *   Ağırlık toplamı 0 ise EŞİT böl; hiç hedef/müşteri yoksa 'dagitilmamis'.
     * 'Personel' + 'Taşıma alış' kategorili gider HARİÇ (çift sayımı önler).
     * @return array{per_customer:array<int,float>,dagitilmamis:float,toplam:float}
     */
    public function giderDagitim(string $ay): array
    {
        $ciro = $this->customerCiroMap($ay);
        $allIds = array_keys($ciro);
        $tedMap = null;     // lazy: tedarikciEslestirmeler()
        $faturaMap = null;  // lazy: faturaEslestirmeler() — fable-037 en üst katman
        $persons = null;    // lazy: customerPersonsMap()

        // fable-031: 'Taşıma alış' (KIRMIZI 1) genel havuza GİRMEZ — taşıma kârında
        // gerçek alış maliyeti olarak mahsup edilir (çift sayım olmasın).
        $st = $this->pdo->prepare(
            "SELECT t.id, t.amount, t.alloc_type, t.source, t.category, t.description, s.name AS supplier_name
             FROM transactions t
             LEFT JOIN suppliers s ON s.id = t.supplier_id
             WHERE t.type = 'gider' AND substr(t.tx_date,1,7) = ?
               AND (t.category IS NULL OR t.category NOT IN ('Personel', 'Taşıma alış'))"
        );
        $st->execute([$ay]);

        $per = [];
        $dagitilmamis = 0.0;
        $toplam = 0.0;
        foreach ($st->fetchAll() as $t) {
            $amt = (float) $t['amount'];
            $toplam += $amt;

            $weights = $ciro; // varsayılan ağırlık = ciro
            // fable-037: FATURA eşleşmesi EN ÜST katman — elle 'musteri'yi de tedarikçiyi de ezer.
            //   Eşleşen müşterilere o ayki KİŞİ oranında (kişi 0 → aşağıda eşit böl). Tablo boşken
            //   bu blok hiç girmez → dağıtım fable-035 ile birebir (regresyon garantisi).
            $faturaMap ??= $this->faturaEslestirmeler();
            $txId = (int) $t['id'];
            if (!empty($faturaMap[$txId])) {
                $targets = $faturaMap[$txId];
                $persons ??= $this->customerPersonsMap($ay);
                $weights = $persons;
            } elseif ($t['alloc_type'] === 'musteri') {
                $targets = $this->transactionTargets($txId);
            } else {
                // fable-035: tedarikçi eşleşmesi → o müşterilere KİŞİ oranında
                $tedMap ??= $this->tedarikciEslestirmeler();
                $key = self::normTedarikci($this->txFirma($t));
                if (!empty($tedMap[$key])) {
                    $targets = $tedMap[$key];
                    $persons ??= $this->customerPersonsMap($ay);
                    $weights = $persons;
                } else {
                    $targets = $allIds;
                }
            }
            if (!$targets) {
                $dagitilmamis += $amt; // hedef/müşteri yok → dağıtılamaz
                continue;
            }
            $sub = 0.0;
            foreach ($targets as $cid) {
                $sub += $weights[$cid] ?? 0.0;
            }
            if ($sub > 0) {
                foreach ($targets as $cid) {
                    $w = ($weights[$cid] ?? 0.0) / $sub;
                    $per[$cid] = ($per[$cid] ?? 0.0) + $amt * $w;
                }
            } else {
                // Ağırlık toplamı 0 → eşit böl (kayıp önle)
                $n = count($targets);
                foreach ($targets as $cid) {
                    $per[$cid] = ($per[$cid] ?? 0.0) + $amt / $n;
                }
            }
        }
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
            // fable-040: fatura kişi kuralı olan müşteride "üretim · fatura" rozeti (gelir 70-bazlı
            //   iken gider dağıtımı 50-bazlı kalır — Ömer: günlük ciro eksik gözükmesin).
            $faturaKisi = $r['fatura_kisi_haftaici'] !== null ? (int) $r['fatura_kisi_haftaici'] : null;
            $uretimRows[] = [
                'customer_id' => $cid, 'name' => $r['name'],
                'gelir' => $ciro, 'gider' => $pg, 'personel' => $pp,
                'net' => $net, 'marj' => $ciro > 0 ? $net / $ciro : 0.0,
                'fatura_kisi' => $faturaKisi,
                'uretim_gunluk' => $faturaKisi !== null ? $this->uretimGunlukOrtalama($cid, $ay) : null,
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
     * fable-019: panel "günün menüsü" kaynağı. Bugün ($fromDate) menü varsa onu, yoksa İLERİYE
     * bakıp (en fazla $lookahead gün) ilk menülü günü döner — servissiz günde (ör. pazar) "Menü
     * yakında" yerine yaklaşan menü görünür. Müşteri-scope (menusForCustomer ile aynı görünürlük).
     * SALT-OKUMA: sadece yayınlanmış menü kaleminden okur; orders/production'a DOKUNMAZ.
     * @return array{date:string,items:array<string,string>,ahead:bool}|null (hiç yoksa null)
     */
    public function menuForCustomerFrom(int $customerId, string $fromDate, int $lookahead = 7): ?array
    {
        // Görünür menü kalemlerini gün×öğün haritasına indir (boş yemek atlanır).
        $byDate = [];
        foreach ($this->menusForCustomer($customerId, $fromDate) as $m) {
            foreach ($this->menuItems((int) $m['id']) as $it) {
                $dishes = trim((string) $it['dishes']);
                if ($dishes === '') {
                    continue;
                }
                $byDate[(string) $it['item_date']][(string) $it['meal']] = $dishes;
            }
        }
        if (!$byDate) {
            return null;
        }
        for ($i = 0; $i <= $lookahead; $i++) {
            $d = date('Y-m-d', strtotime($fromDate . " +$i day"));
            if (!empty($byDate[$d])) {
                return ['date' => $d, 'items' => $byDate[$d], 'ahead' => $i > 0];
            }
        }
        return null;
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
    public function createSupplyRequest(int $customerId, array $items, ?int $customerUserId = null, ?string $note = null, ?string $requestDate = null, ?string $freeText = null): int
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
        // fable-001: serbest metin varsa kalemsiz talep de geçerli (müşteri app "yazı kutucuğu").
        $freeText = $freeText !== null ? trim($freeText) : null;
        if ($freeText === '') {
            $freeText = null;
        }
        if (!$valid && $freeText === null) {
            return 0;
        }
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare(
                'INSERT INTO supply_request (customer_id, customer_user_id, request_date, note, free_text) VALUES (?, ?, ?, ?, ?)'
            )->execute([$customerId, $customerUserId, $requestDate, $note, $freeText]);
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
            'SELECT sr.id, sr.request_date, sr.status, sr.note, sr.free_text, sr.created_at,
                    (SELECT COUNT(*) FROM supply_request_item i WHERE i.request_id = sr.id) AS item_count
             FROM supply_request sr WHERE sr.customer_id = ?
             ORDER BY sr.created_at DESC, sr.id DESC'
        );
        $st->execute([$customerId]);
        return $st->fetchAll();
    }

    /**
     * fable-001: müşterinin bir aydaki malzeme talepleri (yeni→eski, kalem sayısıyla).
     * "Bu ay gönderilenler" görünümü — $month 'YYYY-MM'. IDOR scope: customer_id zorunlu.
     */
    public function supplyRequestsForCustomerMonth(int $customerId, string $month): array
    {
        $st = $this->pdo->prepare(
            "SELECT sr.id, sr.request_date, sr.status, sr.note, sr.free_text, sr.created_at,
                    (SELECT COUNT(*) FROM supply_request_item i WHERE i.request_id = sr.id) AS item_count
             FROM supply_request sr
             WHERE sr.customer_id = ? AND substr(sr.request_date,1,7) = ?
             ORDER BY sr.request_date DESC, sr.id DESC"
        );
        $st->execute([$customerId, $month]);
        return $st->fetchAll();
    }

    /**
     * fable-001 (cateringkolay cetvel esinli): TÜM müşterilerin bir aralıktaki sipariş
     * toplamları — admin haftalık cetvel (müşteri × gün matrisi). Reddedilenler hariç.
     * @return array<int,array{customer_id:int,customer_name:string,order_date:string,persons:int}>
     */
    public function ordersMatrix(string $start, string $end): array
    {
        $st = $this->pdo->prepare(
            "SELECT o.customer_id, c.name AS customer_name, o.order_date,
                    SUM(o.persons) AS persons
             FROM orders o JOIN customers c ON c.id = o.customer_id
             WHERE o.order_date BETWEEN ? AND ? AND o.status <> 'reddedildi'
             GROUP BY o.customer_id, c.name, o.order_date
             ORDER BY c.name ASC, o.order_date ASC"
        );
        $st->execute([$start, $end]);
        return $st->fetchAll();
    }

    /**
     * fable-002: müşterinin gün bazlı KİŞİ sayıları [date => persons] — haftalık şerit.
     * Kaynak birleşik: production (kesinleşen, UYSA girişi dahil) ÖNCELİKLİ; production'da
     * olmayan güne reddedilmemiş sipariş toplamı. Müşteri app'ten girmese de UYSA'nın
     * girdiği sayı görünür ("Bomi boş görünüyor" fix'i). IDOR scope'lu.
     * @return array<string,int>
     */
    public function customerDailyCounts(int $customerId, string $start, string $end): array
    {
        $out = [];
        $st = $this->pdo->prepare(
            "SELECT order_date AS d, SUM(persons) AS p FROM orders
             WHERE customer_id = ? AND order_date BETWEEN ? AND ? AND status <> 'reddedildi'
             GROUP BY order_date"
        );
        $st->execute([$customerId, $start, $end]);
        foreach ($st->fetchAll() as $r) {
            $out[(string) $r['d']] = (int) $r['p'];
        }
        $st = $this->pdo->prepare(
            'SELECT prod_date AS d, SUM(persons) AS p FROM production
             WHERE customer_id = ? AND prod_date BETWEEN ? AND ?
             GROUP BY prod_date'
        );
        $st->execute([$customerId, $start, $end]);
        foreach ($st->fetchAll() as $r) {
            $p = (int) $r['p'];
            if ($p > 0 || !isset($out[(string) $r['d']])) {
                $out[(string) $r['d']] = $p; // kesinleşen sayı sipariş sayısını ezer
            }
        }
        return $out;
    }

    /**
     * fable-019: sayı DEVRİ gösterim varsayılanı — $beforeDate için hiç kayıt yokken kullanılır.
     * En yakın GEÇMİŞ servis gününün (Pzt–Cmt; pazar atlanır) birleşik sayısını döner
     * (customerDailyCounts kaynağı: production>0 siparişi ezer → haftalık şeritle birebir tutarlı).
     * SALT-GÖSTERİM: orders/production'a HİÇ yazmaz; bildirilmemiş sayı bildirilmiş gibi kaydedilmez.
     * @return array{date:string,persons:int}|null (geçmişte kayıt yoksa null)
     */
    public function lastKnownDailyCount(int $customerId, string $beforeDate, int $maxBack = 60): ?array
    {
        $start = date('Y-m-d', strtotime($beforeDate . ' -' . $maxBack . ' day'));
        $end = date('Y-m-d', strtotime($beforeDate . ' -1 day'));
        $counts = $this->customerDailyCounts($customerId, $start, $end);
        if (!$counts) {
            return null;
        }
        for ($i = 1; $i <= $maxBack; $i++) {
            $d = date('Y-m-d', strtotime($beforeDate . " -$i day"));
            if ((int) date('N', strtotime($d)) === 7) {
                continue; // pazar = servis günü değil
            }
            if (array_key_exists($d, $counts)) {
                return ['date' => $d, 'persons' => (int) $counts[$d]];
            }
        }
        return null;
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
        $sql = 'SELECT sr.id, sr.customer_id, sr.request_date, sr.status, sr.note, sr.free_text, sr.created_at,
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
