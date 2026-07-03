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
        $sql = 'SELECT id, name, unit_price, category, contact, phone, is_active
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
        ?string $note = null
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
                 contract_note = COALESCE(?, contract_note) WHERE id = ?'
            )->execute([$name, $unitPrice, $category, $contact, $phone, $note, $id]);
            return $id;
        }
        $this->pdo->prepare(
            'INSERT INTO customers (name, unit_price, category, contact, phone, contract_note)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$name, $unitPrice, $category, $contact, $phone, $note]);
        return (int) $this->pdo->lastInsertId();
    }

    // ── Taşıma karlılık (aylık satış − sabit gider) ───────────
    public function upsertTasimaAylik(int $customerId, string $ay, float $satisFiyati, float $sabitGider, ?string $note = null): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $onConf = $driver === 'sqlite'
            ? 'ON CONFLICT(customer_id, ay) DO UPDATE SET
                 satis_fiyati = excluded.satis_fiyati, sabit_gider = excluded.sabit_gider, note = excluded.note'
            : 'ON DUPLICATE KEY UPDATE
                 satis_fiyati = VALUES(satis_fiyati), sabit_gider = VALUES(sabit_gider), note = VALUES(note)';
        $this->pdo->prepare(
            'INSERT INTO tasima_aylik (customer_id, ay, satis_fiyati, sabit_gider, note)
             VALUES (?, ?, ?, ?, ?) ' . $onConf
        )->execute([$customerId, $ay, $satisFiyati, $sabitGider, $note]);
    }

    /** Bir taşıma müşterisinin belirli ay satış/gider/kâr kaydı (yoksa null). */
    public function tasimaAylik(int $customerId, string $ay): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT satis_fiyati, sabit_gider, note FROM tasima_aylik WHERE customer_id = ? AND ay = ?'
        );
        $st->execute([$customerId, $ay]);
        $r = $st->fetch();
        if (!$r) {
            return null;
        }
        $r['kar'] = (float) $r['satis_fiyati'] - (float) $r['sabit_gider'];
        return $r;
    }

    /** Kâr = satış − sabit gider (kayıt yoksa 0). */
    public function tasimaKar(int $customerId, string $ay): float
    {
        $st = $this->pdo->prepare(
            'SELECT COALESCE(satis_fiyati,0) - COALESCE(sabit_gider,0)
             FROM tasima_aylik WHERE customer_id = ? AND ay = ?'
        );
        $st->execute([$customerId, $ay]);
        $v = $st->fetchColumn();
        return $v === false ? 0.0 : (float) $v;
    }

    /** Taşıma müşterisi aylar trendi (yeni→eski). */
    public function customerMonthlyProfit(int $customerId): array
    {
        $st = $this->pdo->prepare(
            'SELECT ay, satis_fiyati, sabit_gider, (satis_fiyati - sabit_gider) AS kar
             FROM tasima_aylik WHERE customer_id = ? ORDER BY ay DESC'
        );
        $st->execute([$customerId]);
        return $st->fetchAll();
    }

    /** Ayın tüm taşıma müşterilerinin sabit gider + kâr toplamı (finans net için). */
    public function monthTasimaTotals(string $ay): array
    {
        $st = $this->pdo->prepare(
            'SELECT COALESCE(SUM(satis_fiyati),0) AS satis, COALESCE(SUM(sabit_gider),0) AS gider,
                    COALESCE(SUM(satis_fiyati - sabit_gider),0) AS kar
             FROM tasima_aylik WHERE ay = ?'
        );
        $st->execute([$ay]);
        $r = $st->fetch() ?: ['satis' => 0, 'gider' => 0, 'kar' => 0];
        return ['satis' => (float) $r['satis'], 'gider' => (float) $r['gider'], 'kar' => (float) $r['kar']];
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

    // ── Finans ────────────────────────────────────────────────
    public function addTransaction(string $type, float $amount, string $txDate, ?string $category, ?string $desc, ?int $customerId = null, ?int $supplierId = null, ?int $fileId = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO transactions (type, category, tx_date, amount, customer_id, supplier_id, description, file_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$type, $category, $txDate, $amount, $customerId, $supplierId, $desc, $fileId]);
        return (int) $this->pdo->lastInsertId();
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
            $price = (float) $cust['unit_price'];
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

    // ── Talepler / mesajlaşma (M6) ────────────────────────────
    public function createRequest(int $customerId, ?int $customerUserId, string $type, string $subject): int
    {
        $this->pdo->prepare(
            'INSERT INTO requests (customer_id, customer_user_id, type, subject) VALUES (?, ?, ?, ?)'
        )->execute([$customerId, $customerUserId, $type, $subject]);
        return (int) $this->pdo->lastInsertId();
    }

    public function addRequestMessage(int $requestId, string $sender, string $body): int
    {
        $this->pdo->prepare(
            'INSERT INTO request_messages (request_id, sender, body) VALUES (?, ?, ?)'
        )->execute([$requestId, $sender, $body]);
        return (int) $this->pdo->lastInsertId();
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

    /** @return array<int,array> talep mesajları (eski→yeni). */
    public function requestMessages(int $requestId): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM request_messages WHERE request_id = ? ORDER BY created_at ASC, id ASC'
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
    public function monthProductionByCustomer(string $month): array
    {
        $st = $this->pdo->prepare(
            "SELECT c.id AS customer_id, c.name, c.category,
                    COALESCE(SUM(p.persons),0) AS persons, COALESCE(SUM(p.amount),0) AS ciro,
                    COUNT(p.id) AS gun
             FROM customers c
             LEFT JOIN production p ON p.customer_id = c.id AND substr(p.prod_date,1,7) = ?
             GROUP BY c.id, c.name, c.category
             HAVING gun > 0
             ORDER BY ciro DESC"
        );
        $st->execute([$month]);
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
}
