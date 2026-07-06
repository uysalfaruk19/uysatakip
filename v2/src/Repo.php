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
        $sql = 'SELECT id, ad, gorev, aylik_ucret, is_active FROM personel';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY ad';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function personel(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, ad, gorev, aylik_ucret, is_active FROM personel WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Personel ekle/düzenle. $id verilirse günceller (ad dahil), yoksa ekler. */
    public function upsertPersonel(string $ad, ?string $gorev, float $aylikUcret, ?int $id = null): int
    {
        if ($id !== null) {
            $this->pdo->prepare('UPDATE personel SET ad = ?, gorev = ?, aylik_ucret = ? WHERE id = ?')
                ->execute([$ad, $gorev, $aylikUcret, $id]);
            return $id;
        }
        $this->pdo->prepare('INSERT INTO personel (ad, gorev, aylik_ucret) VALUES (?, ?, ?)')
            ->execute([$ad, $gorev, $aylikUcret]);
        return (int) $this->pdo->lastInsertId();
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
    /** Aylık üretim cirosu (production tutar toplamı). */
    public function monthProductionTotal(string $ay): float
    {
        $st = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount),0) FROM production WHERE substr(prod_date,1,7) = ?'
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

    /**
     * Aylık net karlılık (tahakkuk, finans nakit akışından ayrı):
     *   üretim cirosu − hammadde/işletme gideri − personel gideri − taşıma sabit gideri = net.
     * Kalemler AYRI (karıştırma yok). 'Personel' kategorili finans gideri hammaddeden düşülür
     * (çift sayım önlenir); personel gideri TEK kaynak = personel_gider.
     * @return array{ciro:float,hammadde:float,personel:float,tasima_gider:float,net:float}
     */
    public function netKarlilik(string $ay): array
    {
        $ciro = $this->monthProductionTotal($ay);
        $st = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM transactions
             WHERE type = 'gider' AND substr(tx_date,1,7) = ?
               AND (category IS NULL OR category <> 'Personel')"
        );
        $st->execute([$ay]);
        $hammadde = (float) $st->fetchColumn();
        $personel = $this->monthPersonelTotal($ay);
        $tasimaGider = $this->monthTasimaTotals($ay)['gider'];
        return [
            'ciro'         => $ciro,
            'hammadde'     => $hammadde,
            'personel'     => $personel,
            'tasima_gider' => $tasimaGider,
            'net'          => $ciro - $hammadde - $personel - $tasimaGider,
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
    public function menusForCustomer(int $customerId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $st = $this->pdo->prepare(
            "SELECT DISTINCT m.id, m.title, m.date_start, m.date_end, m.audience, m.status
             FROM menu m
             LEFT JOIN menu_target mt ON mt.menu_id = m.id AND mt.customer_id = ?
             WHERE m.status = 'published' AND m.date_end >= ?
               AND (m.audience = 'all' OR mt.customer_id IS NOT NULL)
             ORDER BY m.date_start ASC, m.id ASC"
        );
        $st->execute([$customerId, $today]);
        return $st->fetchAll();
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
