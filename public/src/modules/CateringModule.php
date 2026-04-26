<?php
/**
 * UYSA ERP — Catering (COZBIMESKİ) Modülü v5.0
 * Yemek kataloğu, reçete, menü planı, cari, teslimat, HACCP, eşleşme
 */
declare(strict_types=1);

function handleCateringAction(string $action, PDO $pdo, array $body, ?array $authedUser, string $clientIp): void
{
    $actor = $authedUser['sub'] ?? 'anonymous';

    switch ($action) {

    // ══════════════════════════════════════════════════════════
    // YEMEK KATALOĞU (uysa_dishes)
    // ══════════════════════════════════════════════════════════

    case 'cat.dishes':
        $search   = sanitizeInput($_GET['search'] ?? '', 200);
        $category = sanitizeInput($_GET['category'] ?? '', 30);
        $active   = $_GET['is_active'] ?? '';
        $limit    = min((int)($_GET['limit'] ?? 100), 500);
        $offset   = max((int)($_GET['offset'] ?? 0), 0);

        $where = "1=1"; $params = [];
        if ($search) {
            $where .= " AND (name LIKE ? OR code LIKE ?)";
            $like = "%{$search}%";
            $params[] = $like; $params[] = $like;
        }
        if ($category) { $where .= " AND category = ?"; $params[] = $category; }
        if ($active !== '') { $where .= " AND is_active = ?"; $params[] = (int)$active; }

        $stmt = $pdo->prepare("SELECT * FROM uysa_dishes WHERE {$where} ORDER BY name LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM uysa_dishes WHERE {$where}");
        $countStmt->execute($params);

        jsonResponse(['ok' => true, 'dishes' => $stmt->fetchAll(), 'total' => (int)$countStmt->fetchColumn()]);

    case 'cat.dishGet':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id gerekli'], 400);
        $stmt = $pdo->prepare("SELECT * FROM uysa_dishes WHERE id = ?");
        $stmt->execute([$id]);
        $dish = $stmt->fetch();
        if (!$dish) jsonResponse(['ok' => false, 'error' => 'Yemek bulunamadı'], 404);

        $rlStmt = $pdo->prepare("SELECT * FROM uysa_recipe_lines WHERE dish_id = ? ORDER BY sort_order, id");
        $rlStmt->execute([$id]);

        jsonResponse(['ok' => true, 'dish' => $dish, 'recipe_lines' => $rlStmt->fetchAll()]);

    case 'cat.dishSave':
        $id       = (int)($body['id'] ?? 0);
        $code     = sanitizeInput($body['code'] ?? '', 20);
        $name     = sanitizeInput($body['name'] ?? '', 150);
        $category = sanitizeInput($body['category'] ?? 'ana_yemek', 30);
        $subCat   = sanitizeInput($body['sub_category'] ?? '', 50);
        $unit     = sanitizeInput($body['unit'] ?? 'porsiyon', 20);
        $gram     = $body['portion_gram'] !== null ? (float)$body['portion_gram'] : null;
        $calorie  = $body['calorie'] !== null ? (int)$body['calorie'] : null;
        $allergens = isset($body['allergens']) ? sanitizeInput(json_encode($body['allergens']), 500) : null;
        $price    = (float)($body['dish_price'] ?? 0);
        $cost     = $body['cost_price'] !== null ? (float)$body['cost_price'] : null;
        $isActive = (int)($body['is_active'] ?? 1);
        $notes    = sanitizeInput($body['notes'] ?? '', 2000);

        if (!$code || !$name) jsonResponse(['ok' => false, 'error' => 'code ve name gerekli'], 400);
        if (!preg_match('/^[A-Za-z0-9ĞğİıÇçŞşÜüÖö_-]{1,20}$/u', $code)) {
            jsonResponse(['ok' => false, 'error' => 'code formatı geçersiz (1-20 alfanumerik karakter)'], 400);
        }

        if ($id) {
            $pdo->prepare("UPDATE uysa_dishes SET code=?, name=?, category=?, sub_category=?, unit=?, portion_gram=?, calorie=?, allergens=?, dish_price=?, cost_price=?, is_active=?, notes=? WHERE id=?")
                ->execute([$code, $name, $category, $subCat ?: null, $unit, $gram, $calorie, $allergens, $price, $cost, $isActive, $notes ?: null, $id]);
        } else {
            $pdo->prepare("INSERT INTO uysa_dishes (code, name, category, sub_category, unit, portion_gram, calorie, allergens, dish_price, cost_price, is_active, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$code, $name, $category, $subCat ?: null, $unit, $gram, $calorie, $allergens, $price, $cost, $isActive, $notes ?: null]);
            $id = (int)$pdo->lastInsertId();
        }
        auditLog($pdo, 'cat.dishSave', $actor, "dish:{$code}", null, $clientIp);
        jsonResponse(['ok' => true, 'id' => $id]);

    case 'cat.dishDelete':
        $id = (int)($body['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id gerekli'], 400);
        $pdo->prepare("DELETE FROM uysa_dishes WHERE id = ?")->execute([$id]);
        auditLog($pdo, 'cat.dishDelete', $actor, "dish:{$id}", null, $clientIp);
        jsonResponse(['ok' => true]);

    // ══════════════════════════════════════════════════════════
    // REÇETE SATIRLARI (uysa_recipe_lines)
    // ══════════════════════════════════════════════════════════

    case 'cat.recipeLines':
        $dishId = (int)($_GET['dish_id'] ?? 0);
        if (!$dishId) jsonResponse(['ok' => false, 'error' => 'dish_id gerekli'], 400);
        $stmt = $pdo->prepare("SELECT * FROM uysa_recipe_lines WHERE dish_id = ? ORDER BY sort_order, id");
        $stmt->execute([$dishId]);
        jsonResponse(['ok' => true, 'recipe_lines' => $stmt->fetchAll()]);

    case 'cat.recipeLineSave':
        $id         = (int)($body['id'] ?? 0);
        $dishId     = (int)($body['dish_id'] ?? 0);
        $ingredient = sanitizeInput($body['ingredient'] ?? '', 150);
        $quantity   = (float)($body['quantity'] ?? 0);
        $unit       = sanitizeInput($body['unit'] ?? 'gram', 20);
        $unitPrice  = $body['unit_price'] !== null ? (float)$body['unit_price'] : null;
        $sortOrder  = (int)($body['sort_order'] ?? 0);
        $notes      = sanitizeInput($body['notes'] ?? '', 255);

        if (!$dishId || !$ingredient) jsonResponse(['ok' => false, 'error' => 'dish_id ve ingredient gerekli'], 400);
        if ($quantity <= 0) jsonResponse(['ok' => false, 'error' => 'quantity > 0 olmalı'], 400);

        if ($id) {
            $pdo->prepare("UPDATE uysa_recipe_lines SET dish_id=?, ingredient=?, quantity=?, unit=?, unit_price=?, sort_order=?, notes=? WHERE id=?")
                ->execute([$dishId, $ingredient, $quantity, $unit, $unitPrice, $sortOrder, $notes ?: null, $id]);
        } else {
            $pdo->prepare("INSERT INTO uysa_recipe_lines (dish_id, ingredient, quantity, unit, unit_price, sort_order, notes) VALUES (?,?,?,?,?,?,?)")
                ->execute([$dishId, $ingredient, $quantity, $unit, $unitPrice, $sortOrder, $notes ?: null]);
            $id = (int)$pdo->lastInsertId();
        }
        jsonResponse(['ok' => true, 'id' => $id]);

    case 'cat.recipeLineDelete':
        $id = (int)($body['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id gerekli'], 400);
        $pdo->prepare("DELETE FROM uysa_recipe_lines WHERE id = ?")->execute([$id]);
        jsonResponse(['ok' => true]);

    case 'cat.recipeBulkSave':
        $dishId = (int)($body['dish_id'] ?? 0);
        $lines  = $body['lines'] ?? [];
        if (!$dishId) jsonResponse(['ok' => false, 'error' => 'dish_id gerekli'], 400);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM uysa_recipe_lines WHERE dish_id = ?")->execute([$dishId]);
            $ins = $pdo->prepare("INSERT INTO uysa_recipe_lines (dish_id, ingredient, quantity, unit, unit_price, sort_order, notes) VALUES (?,?,?,?,?,?,?)");
            foreach ($lines as $i => $l) {
                $ins->execute([
                    $dishId,
                    sanitizeInput($l['ingredient'] ?? '', 150),
                    (float)($l['quantity'] ?? 0),
                    sanitizeInput($l['unit'] ?? 'gram', 20),
                    isset($l['unit_price']) ? (float)$l['unit_price'] : null,
                    (int)($l['sort_order'] ?? $i),
                    sanitizeInput($l['notes'] ?? '', 255) ?: null,
                ]);
            }
            $pdo->commit();
            auditLog($pdo, 'cat.recipeBulkSave', $actor, "dish:{$dishId}", count($lines) . ' satır', $clientIp);
            jsonResponse(['ok' => true, 'count' => count($lines)]);
        } catch (\Exception $e) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Reçete kayıt hatası: ' . $e->getMessage()], 500);
        }

    // ══════════════════════════════════════════════════════════
    // MENÜ PLANLARI (uysa_menu_plans + uysa_menu_plan_items)
    // ══════════════════════════════════════════════════════════

    case 'cat.menuPlans':
        $startDate  = sanitizeInput($_GET['start_date'] ?? '', 10);
        $endDate    = sanitizeInput($_GET['end_date'] ?? '', 10);
        $mealType   = sanitizeInput($_GET['meal_type'] ?? '', 10);
        $customerId = (int)($_GET['customer_id'] ?? 0);
        $status     = sanitizeInput($_GET['status'] ?? '', 20);
        $limit      = min((int)($_GET['limit'] ?? 50), 200);
        $offset     = max((int)($_GET['offset'] ?? 0), 0);

        $where = "1=1"; $params = [];
        if ($startDate) { $where .= " AND mp.plan_date >= ?"; $params[] = $startDate; }
        if ($endDate)   { $where .= " AND mp.plan_date <= ?"; $params[] = $endDate; }
        if ($mealType)  { $where .= " AND mp.meal_type = ?"; $params[] = $mealType; }
        if ($customerId){ $where .= " AND mp.customer_id = ?"; $params[] = $customerId; }
        if ($status)    { $where .= " AND mp.status = ?"; $params[] = $status; }

        $stmt = $pdo->prepare("SELECT mp.*, c.name AS customer_name
            FROM uysa_menu_plans mp
            LEFT JOIN uysa_customers c ON mp.customer_id = c.id
            WHERE {$where} ORDER BY mp.plan_date DESC, mp.meal_type LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'menu_plans' => $stmt->fetchAll()]);

    case 'cat.menuPlanGet':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id gerekli'], 400);

        $stmt = $pdo->prepare("SELECT mp.*, c.name AS customer_name FROM uysa_menu_plans mp LEFT JOIN uysa_customers c ON mp.customer_id = c.id WHERE mp.id = ?");
        $stmt->execute([$id]);
        $plan = $stmt->fetch();
        if (!$plan) jsonResponse(['ok' => false, 'error' => 'Menü planı bulunamadı'], 404);

        $items = $pdo->prepare("SELECT mpi.*, d.code AS dish_code, d.name AS dish_name, d.category AS dish_category
            FROM uysa_menu_plan_items mpi
            JOIN uysa_dishes d ON mpi.dish_id = d.id
            WHERE mpi.plan_id = ? ORDER BY mpi.sort_order, mpi.slot");
        $items->execute([$id]);
        jsonResponse(['ok' => true, 'plan' => $plan, 'items' => $items->fetchAll()]);

    case 'cat.menuPlanSave':
        $id         = (int)($body['id'] ?? 0);
        $planDate   = sanitizeInput($body['plan_date'] ?? '', 10);
        $mealType   = sanitizeInput($body['meal_type'] ?? 'ogle', 10);
        $customerId = $body['customer_id'] ? (int)$body['customer_id'] : null;
        $status     = sanitizeInput($body['status'] ?? 'draft', 20);
        $notes      = sanitizeInput($body['notes'] ?? '', 2000);
        $items      = $body['items'] ?? [];

        if (!$planDate) jsonResponse(['ok' => false, 'error' => 'plan_date gerekli'], 400);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $planDate)) {
            jsonResponse(['ok' => false, 'error' => 'plan_date YYYY-MM-DD formatında olmalı'], 400);
        }

        $pdo->beginTransaction();
        try {
            $weekNo = (int)date('W', strtotime($planDate));

            if ($id) {
                $pdo->prepare("UPDATE uysa_menu_plans SET plan_date=?, meal_type=?, customer_id=?, week_no=?, status=?, notes=? WHERE id=?")
                    ->execute([$planDate, $mealType, $customerId, $weekNo, $status, $notes ?: null, $id]);
                $pdo->prepare("DELETE FROM uysa_menu_plan_items WHERE plan_id = ?")->execute([$id]);
            } else {
                $pdo->prepare("INSERT INTO uysa_menu_plans (plan_date, meal_type, customer_id, week_no, status, created_by, notes) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$planDate, $mealType, $customerId, $weekNo, $status, $actor, $notes ?: null]);
                $id = (int)$pdo->lastInsertId();
            }

            if (!empty($items)) {
                $ins = $pdo->prepare("INSERT INTO uysa_menu_plan_items (plan_id, dish_id, slot, portion_count, sort_order) VALUES (?,?,?,?,?)");
                foreach ($items as $i => $item) {
                    $ins->execute([
                        $id,
                        (int)($item['dish_id'] ?? 0),
                        sanitizeInput($item['slot'] ?? 'ana', 20),
                        isset($item['portion_count']) ? (int)$item['portion_count'] : null,
                        (int)($item['sort_order'] ?? $i),
                    ]);
                }
            }

            $pdo->commit();
            auditLog($pdo, 'cat.menuPlanSave', $actor, "menu:{$planDate}/{$mealType}", null, $clientIp);
            jsonResponse(['ok' => true, 'id' => $id]);
        } catch (\Exception $e) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Menü kayıt hatası: ' . $e->getMessage()], 500);
        }

    case 'cat.menuPlanDelete':
        $id = (int)($body['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id gerekli'], 400);
        $pdo->prepare("DELETE FROM uysa_menu_plans WHERE id = ?")->execute([$id]);
        auditLog($pdo, 'cat.menuPlanDelete', $actor, "menu:{$id}", null, $clientIp);
        jsonResponse(['ok' => true]);

    // ══════════════════════════════════════════════════════════
    // CARİ HESAPLAR (uysa_parties)
    // ══════════════════════════════════════════════════════════

    case 'cat.parties':
        $search = sanitizeInput($_GET['search'] ?? '', 200);
        $type   = sanitizeInput($_GET['type'] ?? '', 20);
        $active = $_GET['is_active'] ?? '';
        $limit  = min((int)($_GET['limit'] ?? 100), 500);
        $offset = max((int)($_GET['offset'] ?? 0), 0);

        $where = "1=1"; $params = [];
        if ($search) {
            $where .= " AND (name LIKE ? OR code LIKE ? OR tax_no LIKE ?)";
            $like = "%{$search}%";
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($type)          { $where .= " AND type = ?"; $params[] = $type; }
        if ($active !== '')  { $where .= " AND is_active = ?"; $params[] = (int)$active; }

        $stmt = $pdo->prepare("SELECT * FROM uysa_parties WHERE {$where} ORDER BY name LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM uysa_parties WHERE {$where}");
        $countStmt->execute($params);
        jsonResponse(['ok' => true, 'parties' => $stmt->fetchAll(), 'total' => (int)$countStmt->fetchColumn()]);

    case 'cat.partyGet':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id gerekli'], 400);
        $stmt = $pdo->prepare("SELECT * FROM uysa_parties WHERE id = ?");
        $stmt->execute([$id]);
        $party = $stmt->fetch();
        if (!$party) jsonResponse(['ok' => false, 'error' => 'Cari bulunamadı'], 404);
        jsonResponse(['ok' => true, 'party' => $party]);

    case 'cat.partySave':
        $id        = (int)($body['id'] ?? 0);
        $code      = sanitizeInput($body['code'] ?? '', 20);
        $name      = sanitizeInput($body['name'] ?? '', 200);
        $type      = sanitizeInput($body['type'] ?? 'musteri', 20);
        $taxNo     = sanitizeInput($body['tax_no'] ?? '', 11);
        $taxOffice = sanitizeInput($body['tax_office'] ?? '', 100);
        $address   = sanitizeInput($body['address'] ?? '', 2000);
        $city      = sanitizeInput($body['city'] ?? '', 50);
        $phone     = sanitizeInput($body['phone'] ?? '', 30);
        $email     = sanitizeInput($body['email'] ?? '', 255);
        $iban      = sanitizeInput($body['iban'] ?? '', 34);
        $creditLim = $body['credit_limit'] !== null ? (float)$body['credit_limit'] : null;
        $isActive  = (int)($body['is_active'] ?? 1);
        $notes     = sanitizeInput($body['notes'] ?? '', 2000);

        if (!$code || !$name) jsonResponse(['ok' => false, 'error' => 'code ve name gerekli'], 400);
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['ok' => false, 'error' => 'Geçersiz e-posta adresi'], 400);
        }
        if ($iban && !preg_match('/^TR\d{24}$/', $iban)) {
            jsonResponse(['ok' => false, 'error' => 'IBAN formatı geçersiz (TR + 24 rakam)'], 400);
        }

        if ($id) {
            $pdo->prepare("UPDATE uysa_parties SET code=?, name=?, type=?, tax_no=?, tax_office=?, address=?, city=?, phone=?, email=?, iban=?, credit_limit=?, is_active=?, notes=? WHERE id=?")
                ->execute([$code, $name, $type, $taxNo ?: null, $taxOffice ?: null, $address ?: null, $city ?: null, $phone ?: null, $email ?: null, $iban ?: null, $creditLim, $isActive, $notes ?: null, $id]);
        } else {
            $pdo->prepare("INSERT INTO uysa_parties (code, name, type, tax_no, tax_office, address, city, phone, email, iban, credit_limit, is_active, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$code, $name, $type, $taxNo ?: null, $taxOffice ?: null, $address ?: null, $city ?: null, $phone ?: null, $email ?: null, $iban ?: null, $creditLim, $isActive, $notes ?: null]);
            $id = (int)$pdo->lastInsertId();
        }
        auditLog($pdo, 'cat.partySave', $actor, "party:{$code}", null, $clientIp);
        jsonResponse(['ok' => true, 'id' => $id]);

    case 'cat.partyDelete':
        $id = (int)($body['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id gerekli'], 400);
        $pdo->prepare("DELETE FROM uysa_parties WHERE id = ?")->execute([$id]);
        auditLog($pdo, 'cat.partyDelete', $actor, "party:{$id}", null, $clientIp);
        jsonResponse(['ok' => true]);

    // ══════════════════════════════════════════════════════════
    // TESLİMAT / SEFER (uysa_deliveries)
    // ══════════════════════════════════════════════════════════

    case 'cat.deliveries':
        $startDate  = sanitizeInput($_GET['start_date'] ?? '', 10);
        $endDate    = sanitizeInput($_GET['end_date'] ?? '', 10);
        $customerId = (int)($_GET['customer_id'] ?? 0);
        $status     = sanitizeInput($_GET['status'] ?? '', 20);
        $limit      = min((int)($_GET['limit'] ?? 50), 200);
        $offset     = max((int)($_GET['offset'] ?? 0), 0);

        $where = "1=1"; $params = [];
        if ($startDate)  { $where .= " AND d.delivery_date >= ?"; $params[] = $startDate; }
        if ($endDate)    { $where .= " AND d.delivery_date <= ?"; $params[] = $endDate; }
        if ($customerId) { $where .= " AND d.customer_id = ?"; $params[] = $customerId; }
        if ($status)     { $where .= " AND d.status = ?"; $params[] = $status; }

        $stmt = $pdo->prepare("SELECT d.*, c.name AS customer_name
            FROM uysa_deliveries d
            LEFT JOIN uysa_customers c ON d.customer_id = c.id
            WHERE {$where} ORDER BY d.delivery_date DESC, d.id DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'deliveries' => $stmt->fetchAll()]);

    case 'cat.deliverySave':
        $id          = (int)($body['id'] ?? 0);
        $deliveryNo  = sanitizeInput($body['delivery_no'] ?? '', 30);
        $date        = sanitizeInput($body['delivery_date'] ?? date('Y-m-d'), 10);
        $customerId  = $body['customer_id'] ? (int)$body['customer_id'] : null;
        $mealType    = sanitizeInput($body['meal_type'] ?? 'ogle', 10);
        $vehicle     = sanitizeInput($body['vehicle'] ?? '', 50);
        $driver      = sanitizeInput($body['driver'] ?? '', 100);
        $portions    = (int)($body['portion_count'] ?? 0);
        $departure   = sanitizeInput($body['departure_time'] ?? '', 8);
        $arrival     = sanitizeInput($body['arrival_time'] ?? '', 8);
        $temp        = $body['temperature'] !== null ? (float)$body['temperature'] : null;
        $status      = sanitizeInput($body['status'] ?? 'planned', 20);
        $recipient   = sanitizeInput($body['recipient'] ?? '', 100);
        $signature   = (int)($body['signature'] ?? 0);
        $notes       = sanitizeInput($body['notes'] ?? '', 2000);

        if (!$deliveryNo) {
            $deliveryNo = 'DLV-' . date('Ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        }

        if ($id) {
            $pdo->prepare("UPDATE uysa_deliveries SET delivery_no=?, delivery_date=?, customer_id=?, meal_type=?, vehicle=?, driver=?, portion_count=?, departure_time=?, arrival_time=?, temperature=?, status=?, recipient=?, signature=?, notes=? WHERE id=?")
                ->execute([$deliveryNo, $date, $customerId, $mealType, $vehicle ?: null, $driver ?: null, $portions, $departure ?: null, $arrival ?: null, $temp, $status, $recipient ?: null, $signature, $notes ?: null, $id]);
        } else {
            $pdo->prepare("INSERT INTO uysa_deliveries (delivery_no, delivery_date, customer_id, meal_type, vehicle, driver, portion_count, departure_time, arrival_time, temperature, status, recipient, signature, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$deliveryNo, $date, $customerId, $mealType, $vehicle ?: null, $driver ?: null, $portions, $departure ?: null, $arrival ?: null, $temp, $status, $recipient ?: null, $signature, $notes ?: null]);
            $id = (int)$pdo->lastInsertId();
        }
        auditLog($pdo, 'cat.deliverySave', $actor, "delivery:{$deliveryNo}", null, $clientIp);
        jsonResponse(['ok' => true, 'id' => $id, 'delivery_no' => $deliveryNo]);

    case 'cat.deliveryDelete':
        $id = (int)($body['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id gerekli'], 400);
        $pdo->prepare("DELETE FROM uysa_deliveries WHERE id = ?")->execute([$id]);
        auditLog($pdo, 'cat.deliveryDelete', $actor, "delivery:{$id}", null, $clientIp);
        jsonResponse(['ok' => true]);

    // ══════════════════════════════════════════════════════════
    // HACCP KAYITLARI (uysa_haccp_logs)
    // ══════════════════════════════════════════════════════════

    case 'cat.haccpLogs':
        $startDate = sanitizeInput($_GET['start_date'] ?? '', 10);
        $endDate   = sanitizeInput($_GET['end_date'] ?? '', 10);
        $point     = sanitizeInput($_GET['check_point'] ?? '', 100);
        $type      = sanitizeInput($_GET['check_type'] ?? '', 20);
        $onlyFail  = ($_GET['only_fail'] ?? '') === '1';
        $limit     = min((int)($_GET['limit'] ?? 50), 500);
        $offset    = max((int)($_GET['offset'] ?? 0), 0);

        $where = "1=1"; $params = [];
        if ($startDate) { $where .= " AND log_date >= ?"; $params[] = $startDate; }
        if ($endDate)   { $where .= " AND log_date <= ?"; $params[] = $endDate; }
        if ($point)     { $where .= " AND check_point = ?"; $params[] = $point; }
        if ($type)      { $where .= " AND check_type = ?"; $params[] = $type; }
        if ($onlyFail)  { $where .= " AND is_ok = 0"; }

        $stmt = $pdo->prepare("SELECT * FROM uysa_haccp_logs WHERE {$where} ORDER BY log_date DESC, log_time DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'haccp_logs' => $stmt->fetchAll()]);

    case 'cat.haccpLogSave':
        $id        = (int)($body['id'] ?? 0);
        $logDate   = sanitizeInput($body['log_date'] ?? date('Y-m-d'), 10);
        $logTime   = sanitizeInput($body['log_time'] ?? '', 8);
        $point     = sanitizeInput($body['check_point'] ?? '', 100);
        $type      = sanitizeInput($body['check_type'] ?? 'temperature', 20);
        $value     = $body['value'] !== null ? (float)$body['value'] : null;
        $minLimit  = $body['min_limit'] !== null ? (float)$body['min_limit'] : null;
        $maxLimit  = $body['max_limit'] !== null ? (float)$body['max_limit'] : null;
        $checkedBy = sanitizeInput($body['checked_by'] ?? '', 100);
        $equipment = sanitizeInput($body['equipment'] ?? '', 100);
        $action_   = sanitizeInput($body['corrective_action'] ?? '', 2000);
        $notes     = sanitizeInput($body['notes'] ?? '', 2000);

        if (!$point) jsonResponse(['ok' => false, 'error' => 'check_point gerekli'], 400);

        $isOk = 1;
        if ($value !== null) {
            if ($minLimit !== null && $value < $minLimit) $isOk = 0;
            if ($maxLimit !== null && $value > $maxLimit) $isOk = 0;
        }

        if ($id) {
            $pdo->prepare("UPDATE uysa_haccp_logs SET log_date=?, log_time=?, check_point=?, check_type=?, value=?, min_limit=?, max_limit=?, is_ok=?, corrective_action=?, checked_by=?, equipment=?, notes=? WHERE id=?")
                ->execute([$logDate, $logTime ?: null, $point, $type, $value, $minLimit, $maxLimit, $isOk, $action_ ?: null, $checkedBy ?: null, $equipment ?: null, $notes ?: null, $id]);
        } else {
            $pdo->prepare("INSERT INTO uysa_haccp_logs (log_date, log_time, check_point, check_type, value, min_limit, max_limit, is_ok, corrective_action, checked_by, equipment, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$logDate, $logTime ?: null, $point, $type, $value, $minLimit, $maxLimit, $isOk, $action_ ?: null, $checkedBy ?: null, $equipment ?: null, $notes ?: null]);
            $id = (int)$pdo->lastInsertId();
        }
        auditLog($pdo, 'cat.haccpLogSave', $actor, "haccp:{$point}/{$logDate}", $isOk ? 'OK' : 'FAIL', $clientIp);
        jsonResponse(['ok' => true, 'id' => $id, 'is_ok' => $isOk]);

    // ══════════════════════════════════════════════════════════
    // YEMEK EŞLEŞMELERİ (uysa_dish_pairings)
    // ══════════════════════════════════════════════════════════

    case 'cat.dishPairings':
        $dishId = (int)($_GET['dish_id'] ?? 0);
        $minScore = (int)($_GET['min_score'] ?? 0);

        $where = "1=1"; $params = [];
        if ($dishId) {
            $where .= " AND (dp.dish_a_id = ? OR dp.dish_b_id = ?)";
            $params[] = $dishId; $params[] = $dishId;
        }
        if ($minScore > 0) { $where .= " AND dp.score >= ?"; $params[] = $minScore; }

        $stmt = $pdo->prepare("SELECT dp.*, da.name AS dish_a_name, db.name AS dish_b_name
            FROM uysa_dish_pairings dp
            JOIN uysa_dishes da ON dp.dish_a_id = da.id
            JOIN uysa_dishes db ON dp.dish_b_id = db.id
            WHERE {$where} ORDER BY dp.score DESC");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'pairings' => $stmt->fetchAll()]);

    case 'cat.dishPairingSave':
        $id      = (int)($body['id'] ?? 0);
        $dishAId = (int)($body['dish_a_id'] ?? 0);
        $dishBId = (int)($body['dish_b_id'] ?? 0);
        $score   = max(1, min(10, (int)($body['score'] ?? 5)));
        $reason  = sanitizeInput($body['reason'] ?? '', 255);

        if (!$dishAId || !$dishBId) jsonResponse(['ok' => false, 'error' => 'dish_a_id ve dish_b_id gerekli'], 400);
        if ($dishAId === $dishBId) jsonResponse(['ok' => false, 'error' => 'Aynı yemek eşleştirilemez'], 400);

        if ($dishAId > $dishBId) { [$dishAId, $dishBId] = [$dishBId, $dishAId]; }

        if ($id) {
            $pdo->prepare("UPDATE uysa_dish_pairings SET dish_a_id=?, dish_b_id=?, score=?, reason=? WHERE id=?")
                ->execute([$dishAId, $dishBId, $score, $reason ?: null, $id]);
        } else {
            $pdo->prepare("INSERT INTO uysa_dish_pairings (dish_a_id, dish_b_id, score, reason) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score), reason=VALUES(reason)")
                ->execute([$dishAId, $dishBId, $score, $reason ?: null]);
            $id = (int)$pdo->lastInsertId();
        }
        jsonResponse(['ok' => true, 'id' => $id]);

    // ══════════════════════════════════════════════════════════
    // DASHBOARD & RAPORLAR
    // ══════════════════════════════════════════════════════════

    case 'cat.dashboard':
        $dishCount    = (int)$pdo->query("SELECT COUNT(*) FROM uysa_dishes WHERE is_active=1")->fetchColumn();
        $recipeCount  = (int)$pdo->query("SELECT COUNT(DISTINCT dish_id) FROM uysa_recipe_lines")->fetchColumn();
        $partyCount   = (int)$pdo->query("SELECT COUNT(*) FROM uysa_parties WHERE is_active=1")->fetchColumn();
        $haccpFail    = (int)$pdo->query("SELECT COUNT(*) FROM uysa_haccp_logs WHERE is_ok=0 AND log_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();

        $todayDate = date('Y-m-d');
        $todayMenus = $pdo->prepare("SELECT mp.*, c.name AS customer_name FROM uysa_menu_plans mp LEFT JOIN uysa_customers c ON mp.customer_id = c.id WHERE mp.plan_date = ?");
        $todayMenus->execute([$todayDate]);

        $todayDeliveries = $pdo->prepare("SELECT d.*, c.name AS customer_name FROM uysa_deliveries d LEFT JOIN uysa_customers c ON d.customer_id = c.id WHERE d.delivery_date = ? ORDER BY d.departure_time");
        $todayDeliveries->execute([$todayDate]);

        jsonResponse([
            'ok' => true,
            'stats' => [
                'active_dishes'     => $dishCount,
                'recipes_defined'   => $recipeCount,
                'active_parties'    => $partyCount,
                'haccp_fails_7d'    => $haccpFail,
            ],
            'today_menus'      => $todayMenus->fetchAll(),
            'today_deliveries' => $todayDeliveries->fetchAll(),
        ]);

    default:
        jsonResponse(['ok' => false, 'error' => "Bilinmeyen catering action: {$action}"], 404);
    }
}
