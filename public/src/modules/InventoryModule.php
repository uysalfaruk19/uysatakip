<?php
/**
 * UYSA ERP — Stok & Depo Modülü v5.0
 * Çoklu depo, ürün, stok hareketleri, satın alma, lot takibi
 */
declare(strict_types=1);

function handleInventoryAction(string $action, PDO $pdo, array $body, ?array $authedUser, string $clientIp): void
{
    $actor = $authedUser['sub'] ?? 'anonymous';

    switch ($action) {

    // ── Depolar ──────────────────────────────────────────────
    case 'inv.warehouses':
        $active = $_GET['is_active'] ?? '';
        $where = "1=1"; $params = [];
        if ($active !== '') { $where .= " AND is_active = ?"; $params[] = (int)$active; }
        $stmt = $pdo->prepare("SELECT * FROM uysa_warehouses WHERE {$where} ORDER BY name");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'warehouses' => $stmt->fetchAll()]);

    case 'inv.warehouseSave':
        $id       = (int)($body['id'] ?? 0);
        $name     = sanitizeInput($body['name'] ?? '', 100);
        $location = sanitizeInput($body['location'] ?? '', 300);
        $manager  = sanitizeInput($body['manager'] ?? '', 100);
        $isActive = (int)($body['is_active'] ?? 1);
        if (!$name) jsonResponse(['ok' => false, 'error' => 'name gerekli'], 400);

        if ($id) {
            $pdo->prepare("UPDATE uysa_warehouses SET name=?, location=?, manager=?, is_active=? WHERE id=?")
                ->execute([$name, $location, $manager, $isActive, $id]);
        } else {
            $pdo->prepare("INSERT INTO uysa_warehouses (name, location, manager, is_active) VALUES (?,?,?,?)")
                ->execute([$name, $location, $manager, $isActive]);
            $id = (int)$pdo->lastInsertId();
        }
        jsonResponse(['ok' => true, 'id' => $id]);

    // ── Ürünler ──────────────────────────────────────────────
    case 'inv.products':
        $search   = sanitizeInput($_GET['search'] ?? '', 200);
        $category = sanitizeInput($_GET['category'] ?? '', 100);
        $suppId   = (int)($_GET['supplier_id'] ?? 0);
        $lowStock = ($_GET['low_stock'] ?? '') === '1';
        $active   = $_GET['is_active'] ?? '';
        $limit    = min((int)($_GET['limit'] ?? 50), 500);
        $offset   = max((int)($_GET['offset'] ?? 0), 0);

        $where = "1=1"; $params = [];
        if ($search) {
            $where .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode = ?)";
            $like = "%{$search}%";
            $params = array_merge($params, [$like, $like, $search]);
        }
        if ($category) { $where .= " AND p.category = ?"; $params[] = $category; }
        if ($suppId)   { $where .= " AND p.supplier_id = ?"; $params[] = $suppId; }
        if ($lowStock) { $where .= " AND p.current_stock <= p.min_stock AND p.min_stock > 0"; }
        if ($active !== '') { $where .= " AND p.is_active = ?"; $params[] = (int)$active; }

        $stmt = $pdo->prepare("SELECT p.*, s.name AS supplier_name
            FROM uysa_products p LEFT JOIN uysa_suppliers s ON p.supplier_id=s.id
            WHERE {$where} ORDER BY p.name LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        $total = (int)$pdo->prepare("SELECT COUNT(*) FROM uysa_products p WHERE {$where}");
        jsonResponse(['ok' => true, 'products' => $stmt->fetchAll()]);

    case 'inv.productSave':
        $id          = (int)($body['id'] ?? 0);
        $sku         = sanitizeInput($body['sku'] ?? '', 50) ?: null;
        $barcode     = sanitizeInput($body['barcode'] ?? '', 50) ?: null;
        $name        = sanitizeInput($body['name'] ?? '', 200);
        $category    = sanitizeInput($body['category'] ?? '', 100);
        $unit        = sanitizeInput($body['unit'] ?? 'kg', 20);
        $minStock    = round((float)($body['min_stock'] ?? 0), 3);
        $maxStock    = round((float)($body['max_stock'] ?? 0), 3);
        $unitCost    = round((float)($body['unit_cost'] ?? 0), 2);
        $unitPrice   = round((float)($body['unit_price'] ?? 0), 2);
        $supplierId  = $body['supplier_id'] ? (int)$body['supplier_id'] : null;
        $isActive    = (int)($body['is_active'] ?? 1);

        if (!$name) jsonResponse(['ok' => false, 'error' => 'name gerekli'], 400);

        if ($id) {
            $pdo->prepare("UPDATE uysa_products SET sku=?, barcode=?, name=?, category=?, unit=?,
                min_stock=?, max_stock=?, unit_cost=?, unit_price=?, supplier_id=?, is_active=? WHERE id=?")
                ->execute([$sku, $barcode, $name, $category, $unit, $minStock, $maxStock, $unitCost, $unitPrice, $supplierId, $isActive, $id]);
        } else {
            $pdo->prepare("INSERT INTO uysa_products (sku, barcode, name, category, unit, min_stock, max_stock, unit_cost, unit_price, supplier_id, is_active)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$sku, $barcode, $name, $category, $unit, $minStock, $maxStock, $unitCost, $unitPrice, $supplierId, $isActive]);
            $id = (int)$pdo->lastInsertId();
        }
        auditLog($pdo, 'inv.productSave', $actor, $name, null, $clientIp);
        jsonResponse(['ok' => true, 'id' => $id]);

    case 'inv.barcodeSearch':
        $code = sanitizeInput($_GET['code'] ?? '', 50);
        if (!$code) jsonResponse(['ok' => false, 'error' => 'code gerekli'], 400);
        $stmt = $pdo->prepare("SELECT p.*, s.name AS supplier_name FROM uysa_products p
            LEFT JOIN uysa_suppliers s ON p.supplier_id=s.id WHERE p.barcode=? OR p.sku=? LIMIT 1");
        $stmt->execute([$code, $code]);
        $product = $stmt->fetch();
        if (!$product) jsonResponse(['ok' => false, 'error' => 'Ürün bulunamadı'], 404);
        jsonResponse(['ok' => true, 'product' => $product]);

    // ── Tedarikçiler ─────────────────────────────────────────
    case 'inv.suppliers':
        $search = sanitizeInput($_GET['search'] ?? '', 200);
        $active = $_GET['is_active'] ?? '';
        $where = "1=1"; $params = [];
        if ($search) { $where .= " AND (name LIKE ? OR contact_person LIKE ?)"; $like="%{$search}%"; $params=[$like,$like]; }
        if ($active !== '') { $where .= " AND is_active = ?"; $params[] = (int)$active; }
        $stmt = $pdo->prepare("SELECT * FROM uysa_suppliers WHERE {$where} ORDER BY name");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'suppliers' => $stmt->fetchAll()]);

    case 'inv.supplierSave':
        $id      = (int)($body['id'] ?? 0);
        $name    = sanitizeInput($body['name'] ?? '', 200);
        $contact = sanitizeInput($body['contact_person'] ?? '', 100);
        $phone   = sanitizeInput($body['phone'] ?? '', 20);
        $email   = sanitizeInput($body['email'] ?? '', 255);
        $address = sanitizeInput($body['address'] ?? '', 2000);
        $taxNo   = sanitizeInput($body['tax_no'] ?? '', 20);
        $terms   = (int)($body['payment_terms'] ?? 30);
        $rating  = $body['rating'] !== null ? (int)$body['rating'] : null;
        $active  = (int)($body['is_active'] ?? 1);

        if (!$name) jsonResponse(['ok' => false, 'error' => 'name gerekli'], 400);

        if ($id) {
            $pdo->prepare("UPDATE uysa_suppliers SET name=?, contact_person=?, phone=?, email=?, address=?, tax_no=?, payment_terms=?, rating=?, is_active=? WHERE id=?")
                ->execute([$name, $contact, $phone, $email, $address, $taxNo, $terms, $rating, $active, $id]);
        } else {
            $pdo->prepare("INSERT INTO uysa_suppliers (name, contact_person, phone, email, address, tax_no, payment_terms, rating, is_active) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$name, $contact, $phone, $email, $address, $taxNo, $terms, $rating, $active]);
            $id = (int)$pdo->lastInsertId();
        }
        jsonResponse(['ok' => true, 'id' => $id]);

    // ── Stok Hareketleri ─────────────────────────────────────
    case 'inv.stockMovements':
        $productId   = (int)($_GET['product_id'] ?? 0);
        $warehouseId = (int)($_GET['warehouse_id'] ?? 0);
        $type        = sanitizeInput($_GET['type'] ?? '', 20);
        $startDate   = sanitizeInput($_GET['start_date'] ?? '', 10);
        $endDate     = sanitizeInput($_GET['end_date'] ?? '', 10);
        $limit = min((int)($_GET['limit'] ?? 50), 500);

        $where = "1=1"; $params = [];
        if ($productId)  { $where .= " AND sm.product_id = ?"; $params[] = $productId; }
        if ($warehouseId){ $where .= " AND sm.warehouse_id = ?"; $params[] = $warehouseId; }
        if ($type)       { $where .= " AND sm.type = ?"; $params[] = $type; }
        if ($startDate)  { $where .= " AND sm.created_at >= ?"; $params[] = $startDate; }
        if ($endDate)    { $where .= " AND sm.created_at <= ?"; $params[] = $endDate . ' 23:59:59'; }

        $stmt = $pdo->prepare("SELECT sm.*, p.name AS product_name, p.sku, w.name AS warehouse_name
            FROM uysa_stock_movements sm
            LEFT JOIN uysa_products p ON sm.product_id=p.id
            LEFT JOIN uysa_warehouses w ON sm.warehouse_id=w.id
            WHERE {$where} ORDER BY sm.created_at DESC LIMIT {$limit}");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'movements' => $stmt->fetchAll()]);

    case 'inv.stockMovementSave':
        $productId   = (int)($body['product_id'] ?? 0);
        $warehouseId = (int)($body['warehouse_id'] ?? 0);
        $type        = sanitizeInput($body['type'] ?? '', 20);
        $quantity    = round((float)($body['quantity'] ?? 0), 3);
        $unitCost    = $body['unit_cost'] !== null ? round((float)$body['unit_cost'], 2) : null;
        $refType     = sanitizeInput($body['reference_type'] ?? '', 50);
        $refId       = $body['reference_id'] ? (int)$body['reference_id'] : null;
        $toWarehouse = $body['to_warehouse_id'] ? (int)$body['to_warehouse_id'] : null;
        $notes       = sanitizeInput($body['notes'] ?? '', 500);

        if (!$productId || !$warehouseId || !$type || $quantity <= 0) {
            jsonResponse(['ok' => false, 'error' => 'product_id, warehouse_id, type ve quantity gerekli'], 400);
        }
        if (!in_array($type, ['in', 'out', 'transfer', 'adjustment'])) {
            jsonResponse(['ok' => false, 'error' => "Geçersiz hareket tipi: {$type}"], 400);
        }

        $pdo->beginTransaction();
        try {
            // Mevcut stok kontrol
            $currentStock = (float)$pdo->prepare("SELECT current_stock FROM uysa_products WHERE id=?")
                ->execute([$productId]) ? $pdo->query("SELECT current_stock FROM uysa_products WHERE id={$productId}")->fetchColumn() : 0;

            if ($type === 'out' && $currentStock < $quantity) {
                $pdo->rollBack();
                jsonResponse(['ok' => false, 'error' => "Yetersiz stok. Mevcut: {$currentStock}, İstenen: {$quantity}"], 400);
            }

            // Hareketi kaydet
            $pdo->prepare("INSERT INTO uysa_stock_movements (product_id, warehouse_id, type, quantity, unit_cost, reference_type, reference_id, to_warehouse_id, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$productId, $warehouseId, $type, $quantity, $unitCost, $refType, $refId, $toWarehouse, $notes, $actor]);
            $mvId = (int)$pdo->lastInsertId();

            // Stok güncelle
            $stockChange = match($type) {
                'in'         => $quantity,
                'out'        => -$quantity,
                'transfer'   => -$quantity,
                'adjustment' => $quantity - $currentStock,
            };
            $pdo->prepare("UPDATE uysa_products SET current_stock = current_stock + ? WHERE id=?")
                ->execute([$stockChange, $productId]);

            // Transfer: hedef depoya da kayıt
            if ($type === 'transfer' && $toWarehouse) {
                $pdo->prepare("INSERT INTO uysa_stock_movements (product_id, warehouse_id, type, quantity, unit_cost, reference_type, reference_id, notes, created_by)
                    VALUES (?,?,'in',?,?,'transfer',?,?,?)")
                    ->execute([$productId, $toWarehouse, $quantity, $unitCost, $mvId, "Transfer: {$notes}", $actor]);
            }

            $pdo->commit();
            auditLog($pdo, 'inv.stockMovement', $actor, (string)$productId, json_encode(['type'=>$type,'qty'=>$quantity]), $clientIp);
            jsonResponse(['ok' => true, 'id' => $mvId]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Stok hareketi başarısız: ' . $e->getMessage()], 500);
        }

    // ── Satın Alma Siparişleri ────────────────────────────────
    case 'inv.purchaseOrders':
        $suppId = (int)($_GET['supplier_id'] ?? 0);
        $status = sanitizeInput($_GET['status'] ?? '', 20);
        $limit  = min((int)($_GET['limit'] ?? 50), 500);

        $where = "1=1"; $params = [];
        if ($suppId) { $where .= " AND po.supplier_id = ?"; $params[] = $suppId; }
        if ($status) { $where .= " AND po.status = ?"; $params[] = $status; }

        $stmt = $pdo->prepare("SELECT po.*, s.name AS supplier_name, w.name AS warehouse_name
            FROM uysa_purchase_orders po
            LEFT JOIN uysa_suppliers s ON po.supplier_id=s.id
            LEFT JOIN uysa_warehouses w ON po.warehouse_id=w.id
            WHERE {$where} ORDER BY po.date DESC LIMIT {$limit}");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'orders' => $stmt->fetchAll()]);

    case 'inv.purchaseOrderSave':
        $id          = (int)($body['id'] ?? 0);
        $supplierId  = (int)($body['supplier_id'] ?? 0);
        $warehouseId = (int)($body['warehouse_id'] ?? 0);
        $date        = sanitizeInput($body['date'] ?? date('Y-m-d'), 10);
        $expectedDate = sanitizeInput($body['expected_date'] ?? '', 10) ?: null;
        $notes       = sanitizeInput($body['notes'] ?? '', 2000);
        $lines       = $body['lines'] ?? [];

        if (!$supplierId || !$warehouseId || empty($lines)) {
            jsonResponse(['ok' => false, 'error' => 'supplier_id, warehouse_id ve lines gerekli'], 400);
        }

        $total = 0;
        foreach ($lines as $l) {
            $total += round((float)($l['quantity'] ?? 0) * (float)($l['unit_price'] ?? 0), 2);
        }

        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare("UPDATE uysa_purchase_orders SET supplier_id=?, warehouse_id=?, date=?, expected_date=?, total=?, notes=? WHERE id=?")
                    ->execute([$supplierId, $warehouseId, $date, $expectedDate, $total, $notes, $id]);
                $pdo->prepare("DELETE FROM uysa_purchase_order_lines WHERE order_id=?")->execute([$id]);
            } else {
                $year = date('Y');
                $cnt = (int)$pdo->query("SELECT COUNT(*)+1 FROM uysa_purchase_orders WHERE YEAR(date)={$year}")->fetchColumn();
                $orderNo = "PO-{$year}-" . str_pad((string)$cnt, 5, '0', STR_PAD_LEFT);

                $pdo->prepare("INSERT INTO uysa_purchase_orders (order_no, supplier_id, warehouse_id, date, expected_date, total, notes, created_by) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$orderNo, $supplierId, $warehouseId, $date, $expectedDate, $total, $notes, $actor]);
                $id = (int)$pdo->lastInsertId();
            }

            $lineStmt = $pdo->prepare("INSERT INTO uysa_purchase_order_lines (order_id, product_id, quantity, unit_price, total) VALUES (?,?,?,?,?)");
            foreach ($lines as $l) {
                $qty = round((float)($l['quantity'] ?? 0), 3);
                $price = round((float)($l['unit_price'] ?? 0), 2);
                $lineStmt->execute([$id, (int)$l['product_id'], $qty, $price, round($qty * $price, 2)]);
            }
            $pdo->commit();
            auditLog($pdo, 'inv.purchaseOrderSave', $actor, (string)$id, null, $clientIp);
            jsonResponse(['ok' => true, 'id' => $id, 'total' => $total]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Sipariş kaydı başarısız: ' . $e->getMessage()], 500);
        }

    case 'inv.purchaseOrderReceive':
        $orderId = (int)($body['order_id'] ?? 0);
        $lines   = $body['lines'] ?? [];
        if (!$orderId || empty($lines)) jsonResponse(['ok' => false, 'error' => 'order_id ve lines gerekli'], 400);

        $order = $pdo->prepare("SELECT * FROM uysa_purchase_orders WHERE id=?");
        $order->execute([$orderId]);
        $po = $order->fetch();
        if (!$po) jsonResponse(['ok' => false, 'error' => 'Sipariş bulunamadı'], 404);

        $pdo->beginTransaction();
        try {
            foreach ($lines as $l) {
                $lineId = (int)($l['line_id'] ?? 0);
                $recQty = round((float)($l['received_qty'] ?? 0), 3);
                if (!$lineId || $recQty <= 0) continue;

                // Satır bilgisi al
                $lineData = $pdo->prepare("SELECT * FROM uysa_purchase_order_lines WHERE id=? AND order_id=?");
                $lineData->execute([$lineId, $orderId]);
                $ld = $lineData->fetch();
                if (!$ld) continue;

                // received_qty güncelle
                $pdo->prepare("UPDATE uysa_purchase_order_lines SET received_qty = received_qty + ? WHERE id=?")
                    ->execute([$recQty, $lineId]);

                // Stok hareketi oluştur
                $pdo->prepare("INSERT INTO uysa_stock_movements (product_id, warehouse_id, type, quantity, unit_cost, reference_type, reference_id, notes, created_by)
                    VALUES (?,?,'in',?,?,'purchase_order',?,?,?)")
                    ->execute([$ld['product_id'], $po['warehouse_id'], $recQty, $ld['unit_price'], $orderId, "PO Mal Kabul", $actor]);

                // Ürün stoku güncelle
                $pdo->prepare("UPDATE uysa_products SET current_stock = current_stock + ?, unit_cost = ? WHERE id=?")
                    ->execute([$recQty, $ld['unit_price'], $ld['product_id']]);
            }

            // Sipariş durumunu kontrol et
            $allReceived = $pdo->prepare("SELECT COUNT(*) FROM uysa_purchase_order_lines WHERE order_id=? AND received_qty < quantity");
            $allReceived->execute([$orderId]);
            $pending = (int)$allReceived->fetchColumn();
            $newStatus = $pending === 0 ? 'received' : 'partial';
            $pdo->prepare("UPDATE uysa_purchase_orders SET status=? WHERE id=?")->execute([$newStatus, $orderId]);

            $pdo->commit();
            auditLog($pdo, 'inv.receive', $actor, (string)$orderId, json_encode(['status' => $newStatus]), $clientIp);
            jsonResponse(['ok' => true, 'status' => $newStatus]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Mal kabul başarısız: ' . $e->getMessage()], 500);
        }

    // ── Lot / Parti ──────────────────────────────────────────
    case 'inv.lots':
        $productId = (int)($_GET['product_id'] ?? 0);
        $expiring  = ($_GET['expiring_soon'] ?? '') === '1';
        $limit = min((int)($_GET['limit'] ?? 50), 500);

        $where = "1=1"; $params = [];
        if ($productId) { $where .= " AND l.product_id = ?"; $params[] = $productId; }
        if ($expiring)  { $where .= " AND l.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND l.expiry_date >= CURDATE()"; }

        $stmt = $pdo->prepare("SELECT l.*, p.name AS product_name FROM uysa_lots l
            LEFT JOIN uysa_products p ON l.product_id=p.id
            WHERE {$where} ORDER BY l.expiry_date ASC LIMIT {$limit}");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'lots' => $stmt->fetchAll()]);

    case 'inv.lotSave':
        $productId = (int)($body['product_id'] ?? 0);
        $lotNumber = sanitizeInput($body['lot_number'] ?? '', 50);
        $prodDate  = sanitizeInput($body['production_date'] ?? '', 10) ?: null;
        $expDate   = sanitizeInput($body['expiry_date'] ?? '', 10) ?: null;
        $quantity  = round((float)($body['quantity'] ?? 0), 3);
        $whId      = $body['warehouse_id'] ? (int)$body['warehouse_id'] : null;

        if (!$productId || !$lotNumber) jsonResponse(['ok' => false, 'error' => 'product_id ve lot_number gerekli'], 400);

        $pdo->prepare("INSERT INTO uysa_lots (product_id, lot_number, production_date, expiry_date, quantity, warehouse_id) VALUES (?,?,?,?,?,?)")
            ->execute([$productId, $lotNumber, $prodDate, $expDate, $quantity, $whId]);
        jsonResponse(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);

    // ── Raporlar ─────────────────────────────────────────────
    case 'inv.lowStockAlert':
        $stmt = $pdo->query("SELECT p.*, s.name AS supplier_name, s.phone AS supplier_phone
            FROM uysa_products p LEFT JOIN uysa_suppliers s ON p.supplier_id=s.id
            WHERE p.current_stock <= p.min_stock AND p.min_stock > 0 AND p.is_active=1
            ORDER BY (p.current_stock / p.min_stock) ASC");
        jsonResponse(['ok' => true, 'products' => $stmt->fetchAll()]);

    case 'inv.stockReport':
        $groupBy = sanitizeInput($_GET['group_by'] ?? 'category', 20);
        if ($groupBy === 'warehouse') {
            $stmt = $pdo->query("SELECT w.name AS group_name, COUNT(DISTINCT sm.product_id) AS product_count,
                SUM(CASE WHEN sm.type='in' THEN sm.quantity ELSE -sm.quantity END) AS total_qty
                FROM uysa_stock_movements sm LEFT JOIN uysa_warehouses w ON sm.warehouse_id=w.id
                GROUP BY sm.warehouse_id ORDER BY w.name");
        } else {
            $stmt = $pdo->query("SELECT COALESCE(p.category,'Kategorisiz') AS group_name,
                COUNT(*) AS product_count, SUM(p.current_stock) AS total_qty,
                SUM(p.current_stock * p.unit_cost) AS total_value
                FROM uysa_products p WHERE p.is_active=1 GROUP BY p.category ORDER BY total_value DESC");
        }
        jsonResponse(['ok' => true, 'report' => $stmt->fetchAll(), 'group_by' => $groupBy]);

    case 'inv.supplierComparison':
        $productId = (int)($_GET['product_id'] ?? 0);
        if (!$productId) jsonResponse(['ok' => false, 'error' => 'product_id gerekli'], 400);
        $stmt = $pdo->prepare("SELECT s.id, s.name, s.rating, s.payment_terms,
            AVG(pol.unit_price) AS avg_price, SUM(pol.quantity) AS total_qty,
            MAX(po.date) AS last_order_date, COUNT(DISTINCT po.id) AS order_count
            FROM uysa_purchase_order_lines pol
            JOIN uysa_purchase_orders po ON pol.order_id=po.id
            JOIN uysa_suppliers s ON po.supplier_id=s.id
            WHERE pol.product_id=?
            GROUP BY s.id ORDER BY avg_price ASC");
        $stmt->execute([$productId]);
        jsonResponse(['ok' => true, 'suppliers' => $stmt->fetchAll()]);

    case 'inv.dashboard':
        $d = [];
        try {
            $d['total_products']    = (int)$pdo->query("SELECT COUNT(*) FROM uysa_products WHERE is_active=1")->fetchColumn();
            $d['low_stock_count']   = (int)$pdo->query("SELECT COUNT(*) FROM uysa_products WHERE current_stock<=min_stock AND min_stock>0 AND is_active=1")->fetchColumn();
            $d['total_stock_value'] = (float)$pdo->query("SELECT COALESCE(SUM(current_stock*unit_cost),0) FROM uysa_products WHERE is_active=1")->fetchColumn();
            $d['pending_orders']    = (int)$pdo->query("SELECT COUNT(*) FROM uysa_purchase_orders WHERE status IN ('draft','sent','partial')")->fetchColumn();
            $d['expiring_lots']     = (int)$pdo->query("SELECT COUNT(*) FROM uysa_lots WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date >= CURDATE()")->fetchColumn();
            $d['warehouses']        = (int)$pdo->query("SELECT COUNT(*) FROM uysa_warehouses WHERE is_active=1")->fetchColumn();
            $d['total_suppliers']   = (int)$pdo->query("SELECT COUNT(*) FROM uysa_suppliers WHERE is_active=1")->fetchColumn();
        } catch (\Throwable) {}
        jsonResponse(['ok' => true, 'dashboard' => $d]);

    default:
        jsonResponse(['ok' => false, 'error' => "Bilinmeyen stok action: {$action}"], 404);
    }
}
