<?php
/**
 * UYSA ERP — Portal & Entegrasyon Modülü v5.0
 * Müşteri portalı, 2FA, webhook, sipariş yönetimi
 */
declare(strict_types=1);

function handlePortalAction(string $action, PDO $pdo, array $body, ?array $authedUser, string $clientIp): void
{
    $actor = $authedUser['sub'] ?? 'anonymous';
    $isPortalUser = str_starts_with($actor, 'portal:');
    $customerId = $authedUser['customer_id'] ?? 0;

    switch ($action) {

    // ── Portal Giriş (Public) ────────────────────────────────
    case 'portal.login':
        $username = sanitizeInput($body['username'] ?? '', 50);
        $password = $body['password'] ?? '';
        if (!$username || !$password) jsonResponse(['ok' => false, 'error' => 'username ve password gerekli'], 400);

        $stmt = $pdo->prepare("SELECT id, name, portal_username, portal_password_hash, portal_active
            FROM uysa_customers WHERE portal_username = ?");
        $stmt->execute([$username]);
        $customer = $stmt->fetch();

        $dummy = '$2y$10$invalidhashfortimingattackprevention000000000000000000';
        $hash = $customer ? $customer['portal_password_hash'] : $dummy;

        if (!$customer || !password_verify($password, $hash) || !$customer['portal_active']) {
            jsonResponse(['ok' => false, 'error' => 'Geçersiz kullanıcı adı veya şifre'], 401);
        }

        // JWT oluştur (global JwtManager kullanarak)
        global $jwtManager;
        $tokenPayload = [
            'sub'         => 'portal:' . $customer['portal_username'],
            'role'        => 'portal',
            'customer_id' => (int)$customer['id'],
        ];
        $accessToken = $jwtManager->issue($tokenPayload);

        auditLog($pdo, 'portal.login', $customer['portal_username'], null, null, $clientIp);
        jsonResponse([
            'ok'           => true,
            'access_token' => $accessToken,
            'expires_in'   => 3600,
            'customer'     => ['id' => $customer['id'], 'name' => $customer['name'], 'username' => $customer['portal_username']],
        ]);

    // ── Müşteri Yönetimi (Admin) ─────────────────────────────
    case 'portal.customers':
        $search = sanitizeInput($_GET['search'] ?? '', 200);
        $active = $_GET['is_active'] ?? '';
        $portal = $_GET['portal_active'] ?? '';
        $limit  = min((int)($_GET['limit'] ?? 50), 500);
        $offset = max((int)($_GET['offset'] ?? 0), 0);

        $where = "1=1"; $params = [];
        if ($search) { $where .= " AND (name LIKE ? OR contact_person LIKE ? OR email LIKE ?)"; $like="%{$search}%"; $params=[$like,$like,$like]; }
        if ($active !== '') { $where .= " AND is_active = ?"; $params[] = (int)$active; }
        if ($portal !== '') { $where .= " AND portal_active = ?"; $params[] = (int)$portal; }

        $stmt = $pdo->prepare("SELECT id, name, contact_person, phone, email, address, tax_no, sector,
            credit_limit, balance, portal_username, portal_active, is_active, created_at
            FROM uysa_customers WHERE {$where} ORDER BY name LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'customers' => $stmt->fetchAll()]);

    case 'portal.customerSave':
        $id            = (int)($body['id'] ?? 0);
        $name          = sanitizeInput($body['name'] ?? '', 200);
        $contact       = sanitizeInput($body['contact_person'] ?? '', 100);
        $phone         = sanitizeInput($body['phone'] ?? '', 20);
        $email         = sanitizeInput($body['email'] ?? '', 255);
        $address       = sanitizeInput($body['address'] ?? '', 2000);
        $taxNo         = sanitizeInput($body['tax_no'] ?? '', 20);
        $sector        = sanitizeInput($body['sector'] ?? '', 100);
        $creditLimit   = round((float)($body['credit_limit'] ?? 0), 2);
        $portalUser    = sanitizeInput($body['portal_username'] ?? '', 50) ?: null;
        $portalPass    = $body['portal_password'] ?? '';
        $portalActive  = (int)($body['portal_active'] ?? 0);
        $isActive      = (int)($body['is_active'] ?? 1);

        if (!$name) jsonResponse(['ok' => false, 'error' => 'name gerekli'], 400);

        $portalHash = null;
        if ($portalPass) {
            if (strlen($portalPass) < 6) jsonResponse(['ok' => false, 'error' => 'Portal şifresi en az 6 karakter olmalı'], 400);
            $portalHash = password_hash($portalPass, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        if ($id) {
            $fields = "name=?, contact_person=?, phone=?, email=?, address=?, tax_no=?, sector=?, credit_limit=?, portal_username=?, portal_active=?, is_active=?";
            $params = [$name, $contact, $phone, $email, $address, $taxNo, $sector, $creditLimit, $portalUser, $portalActive, $isActive];
            if ($portalHash) {
                $fields .= ", portal_password_hash=?";
                $params[] = $portalHash;
            }
            $params[] = $id;
            $pdo->prepare("UPDATE uysa_customers SET {$fields} WHERE id=?")->execute($params);
        } else {
            $pdo->prepare("INSERT INTO uysa_customers (name, contact_person, phone, email, address, tax_no, sector,
                credit_limit, portal_username, portal_password_hash, portal_active, is_active)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$name, $contact, $phone, $email, $address, $taxNo, $sector, $creditLimit, $portalUser, $portalHash, $portalActive, $isActive]);
            $id = (int)$pdo->lastInsertId();
        }
        auditLog($pdo, 'portal.customerSave', $actor, $name, null, $clientIp);
        jsonResponse(['ok' => true, 'id' => $id]);

    case 'portal.customerDetail':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id gerekli'], 400);
        $cust = $pdo->prepare("SELECT id, name, contact_person, phone, email, address, tax_no, sector,
            credit_limit, balance, portal_username, portal_active, is_active, created_at
            FROM uysa_customers WHERE id=?");
        $cust->execute([$id]);
        $c = $cust->fetch();
        if (!$c) jsonResponse(['ok' => false, 'error' => 'Müşteri bulunamadı'], 404);

        $orders = $pdo->prepare("SELECT id, order_no, date, status, total FROM uysa_customer_orders WHERE customer_id=? ORDER BY date DESC LIMIT 20");
        $orders->execute([$id]);
        $c['recent_orders'] = $orders->fetchAll();

        $invoices = $pdo->prepare("SELECT id, invoice_no, date, total, status FROM uysa_invoices WHERE customer_id=? ORDER BY date DESC LIMIT 20");
        $invoices->execute([$id]);
        $c['recent_invoices'] = $invoices->fetchAll();

        jsonResponse(['ok' => true, 'customer' => $c]);

    // ── Portal Müşteri İşlemleri ─────────────────────────────
    case 'portal.myOrders':
        if (!$isPortalUser || !$customerId) jsonResponse(['ok' => false, 'error' => 'Portal girişi gerekli'], 403);
        $stmt = $pdo->prepare("SELECT co.*, (SELECT JSON_ARRAYAGG(JSON_OBJECT('description',col.description,'quantity',col.quantity,'unit_price',col.unit_price,'total',col.total))
            FROM uysa_customer_order_lines col WHERE col.order_id=co.id) AS lines_json
            FROM uysa_customer_orders co WHERE co.customer_id=? ORDER BY co.date DESC");
        $stmt->execute([$customerId]);
        $orders = $stmt->fetchAll();
        foreach ($orders as &$o) {
            $o['lines'] = json_decode($o['lines_json'] ?? '[]', true) ?: [];
            unset($o['lines_json']);
        }
        jsonResponse(['ok' => true, 'orders' => $orders]);

    case 'portal.myOrderSave':
        if (!$isPortalUser || !$customerId) jsonResponse(['ok' => false, 'error' => 'Portal girişi gerekli'], 403);
        $deliveryDate = sanitizeInput($body['delivery_date'] ?? '', 10) ?: null;
        $notes = sanitizeInput($body['notes'] ?? '', 2000);
        $lines = $body['lines'] ?? [];
        if (empty($lines)) jsonResponse(['ok' => false, 'error' => 'En az bir satır gerekli'], 400);

        $total = 0;
        foreach ($lines as $l) {
            $total += round((float)($l['quantity'] ?? 1) * (float)($l['unit_price'] ?? 0), 2);
        }

        $pdo->beginTransaction();
        try {
            $year = date('Y');
            $cnt = (int)$pdo->query("SELECT COUNT(*)+1 FROM uysa_customer_orders WHERE YEAR(date)={$year}")->fetchColumn();
            $orderNo = "ORD-{$year}-" . str_pad((string)$cnt, 5, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO uysa_customer_orders (order_no, customer_id, date, delivery_date, total, notes, created_by)
                VALUES (?,?,CURDATE(),?,?,?,?)")
                ->execute([$orderNo, $customerId, $deliveryDate, $total, $notes, $actor]);
            $orderId = (int)$pdo->lastInsertId();

            $lineStmt = $pdo->prepare("INSERT INTO uysa_customer_order_lines (order_id, product_id, description, quantity, unit_price, total) VALUES (?,?,?,?,?,?)");
            foreach ($lines as $l) {
                $qty = round((float)($l['quantity'] ?? 1), 3);
                $price = round((float)($l['unit_price'] ?? 0), 2);
                $lineStmt->execute([
                    $orderId,
                    $l['product_id'] ? (int)$l['product_id'] : null,
                    sanitizeInput($l['description'] ?? '', 500),
                    $qty, $price, round($qty * $price, 2),
                ]);
            }
            $pdo->commit();
            // Webhook dispatch
            try { dispatchWebhook($pdo, 'order.created', ['order_id' => $orderId, 'order_no' => $orderNo, 'total' => $total]); } catch (\Throwable) {}
            jsonResponse(['ok' => true, 'id' => $orderId, 'order_no' => $orderNo]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Sipariş oluşturulamadı: ' . $e->getMessage()], 500);
        }

    case 'portal.myInvoices':
        if (!$isPortalUser || !$customerId) jsonResponse(['ok' => false, 'error' => 'Portal girişi gerekli'], 403);
        $stmt = $pdo->prepare("SELECT id, invoice_no, date, due_date, subtotal, tax_amount, total, status
            FROM uysa_invoices WHERE customer_id=? AND type='sales' ORDER BY date DESC");
        $stmt->execute([$customerId]);
        jsonResponse(['ok' => true, 'invoices' => $stmt->fetchAll()]);

    case 'portal.myProfile':
        if (!$isPortalUser || !$customerId) jsonResponse(['ok' => false, 'error' => 'Portal girişi gerekli'], 403);
        $stmt = $pdo->prepare("SELECT id, name, contact_person, phone, email, address, balance, credit_limit FROM uysa_customers WHERE id=?");
        $stmt->execute([$customerId]);
        jsonResponse(['ok' => true, 'profile' => $stmt->fetch()]);

    case 'portal.myProfileUpdate':
        if (!$isPortalUser || !$customerId) jsonResponse(['ok' => false, 'error' => 'Portal girişi gerekli'], 403);
        $contact = sanitizeInput($body['contact_person'] ?? '', 100);
        $phone   = sanitizeInput($body['phone'] ?? '', 20);
        $email   = sanitizeInput($body['email'] ?? '', 255);
        $pdo->prepare("UPDATE uysa_customers SET contact_person=?, phone=?, email=? WHERE id=?")
            ->execute([$contact, $phone, $email, $customerId]);
        jsonResponse(['ok' => true]);

    // ── Sipariş Yönetimi (Admin) ─────────────────────────────
    case 'portal.orders':
        $custId = (int)($_GET['customer_id'] ?? 0);
        $status = sanitizeInput($_GET['status'] ?? '', 30);
        $limit  = min((int)($_GET['limit'] ?? 50), 500);

        $where = "1=1"; $params = [];
        if ($custId) { $where .= " AND co.customer_id = ?"; $params[] = $custId; }
        if ($status) { $where .= " AND co.status = ?"; $params[] = $status; }

        $stmt = $pdo->prepare("SELECT co.*, c.name AS customer_name
            FROM uysa_customer_orders co LEFT JOIN uysa_customers c ON co.customer_id=c.id
            WHERE {$where} ORDER BY co.date DESC LIMIT {$limit}");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'orders' => $stmt->fetchAll()]);

    case 'portal.orderSave':
        $id           = (int)($body['id'] ?? 0);
        $custId       = (int)($body['customer_id'] ?? 0);
        $date         = sanitizeInput($body['date'] ?? date('Y-m-d'), 10);
        $deliveryDate = sanitizeInput($body['delivery_date'] ?? '', 10) ?: null;
        $status       = sanitizeInput($body['status'] ?? 'pending', 30);
        $notes        = sanitizeInput($body['notes'] ?? '', 2000);
        $lines        = $body['lines'] ?? [];

        if (!$custId || empty($lines)) jsonResponse(['ok' => false, 'error' => 'customer_id ve lines gerekli'], 400);

        $total = 0;
        foreach ($lines as $l) {
            $total += round((float)($l['quantity'] ?? 1) * (float)($l['unit_price'] ?? 0), 2);
        }

        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare("UPDATE uysa_customer_orders SET customer_id=?, date=?, delivery_date=?, status=?, total=?, notes=? WHERE id=?")
                    ->execute([$custId, $date, $deliveryDate, $status, $total, $notes, $id]);
                $pdo->prepare("DELETE FROM uysa_customer_order_lines WHERE order_id=?")->execute([$id]);
            } else {
                $year = date('Y');
                $cnt = (int)$pdo->query("SELECT COUNT(*)+1 FROM uysa_customer_orders WHERE YEAR(date)={$year}")->fetchColumn();
                $orderNo = "ORD-{$year}-" . str_pad((string)$cnt, 5, '0', STR_PAD_LEFT);

                $pdo->prepare("INSERT INTO uysa_customer_orders (order_no, customer_id, date, delivery_date, status, total, notes, created_by) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$orderNo, $custId, $date, $deliveryDate, $status, $total, $notes, $actor]);
                $id = (int)$pdo->lastInsertId();
            }

            $lineStmt = $pdo->prepare("INSERT INTO uysa_customer_order_lines (order_id, product_id, description, quantity, unit_price, total) VALUES (?,?,?,?,?,?)");
            foreach ($lines as $l) {
                $qty = round((float)($l['quantity'] ?? 1), 3);
                $price = round((float)($l['unit_price'] ?? 0), 2);
                $lineStmt->execute([$id, $l['product_id'] ? (int)$l['product_id'] : null,
                    sanitizeInput($l['description'] ?? '', 500), $qty, $price, round($qty * $price, 2)]);
            }
            $pdo->commit();
            jsonResponse(['ok' => true, 'id' => $id, 'total' => $total]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Sipariş kaydı başarısız: ' . $e->getMessage()], 500);
        }

    case 'portal.orderStatusUpdate':
        $id = (int)($body['id'] ?? 0);
        $status = sanitizeInput($body['status'] ?? '', 30);
        if (!$id || !$status) jsonResponse(['ok' => false, 'error' => 'id ve status gerekli'], 400);
        $pdo->prepare("UPDATE uysa_customer_orders SET status=? WHERE id=?")->execute([$status, $id]);
        auditLog($pdo, 'portal.orderStatus', $actor, (string)$id, $status, $clientIp);
        jsonResponse(['ok' => true]);

    // ── Webhook Yönetimi (Admin) ─────────────────────────────
    case 'portal.webhooks':
        $stmt = $pdo->query("SELECT id, url, events, is_active, last_triggered, fail_count, created_by, created_at FROM uysa_webhooks ORDER BY created_at DESC");
        jsonResponse(['ok' => true, 'webhooks' => $stmt->fetchAll()]);

    case 'portal.webhookSave':
        $id     = (int)($body['id'] ?? 0);
        $url    = sanitizeInput($body['url'] ?? '', 500);
        $events = $body['events'] ?? [];
        $active = (int)($body['is_active'] ?? 1);

        if (!$url || empty($events)) jsonResponse(['ok' => false, 'error' => 'url ve events gerekli'], 400);

        $eventsJson = json_encode($events);

        if ($id) {
            $pdo->prepare("UPDATE uysa_webhooks SET url=?, events=?, is_active=? WHERE id=?")
                ->execute([$url, $eventsJson, $active, $id]);
        } else {
            $secret = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT INTO uysa_webhooks (url, events, secret, is_active, created_by) VALUES (?,?,?,?,?)")
                ->execute([$url, $eventsJson, $secret, $active, $actor]);
            $id = (int)$pdo->lastInsertId();
        }
        jsonResponse(['ok' => true, 'id' => $id]);

    case 'portal.webhookDelete':
        $id = (int)($body['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id gerekli'], 400);
        $pdo->prepare("DELETE FROM uysa_webhooks WHERE id=?")->execute([$id]);
        jsonResponse(['ok' => true]);

    case 'portal.webhookTest':
        $id = (int)($body['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id gerekli'], 400);
        $wh = $pdo->prepare("SELECT * FROM uysa_webhooks WHERE id=?");
        $wh->execute([$id]);
        $w = $wh->fetch();
        if (!$w) jsonResponse(['ok' => false, 'error' => 'Webhook bulunamadı'], 404);

        $testPayload = ['event' => 'test', 'message' => 'UYSA ERP webhook test', 'timestamp' => date('c')];
        $result = sendWebhookRequest($w['url'], $w['secret'], $testPayload);
        jsonResponse(['ok' => true, 'result' => $result]);

    // ── 2FA Yönetimi ─────────────────────────────────────────
    case 'portal.2faStatus':
        $userId = $authedUser['uid'] ?? 0;
        if (!$userId) jsonResponse(['ok' => false, 'error' => 'Giriş gerekli'], 401);
        $stmt = $pdo->prepare("SELECT is_enabled FROM uysa_2fa WHERE user_id=?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        jsonResponse(['ok' => true, 'enabled' => (bool)($row['is_enabled'] ?? false)]);

    case 'portal.2faSetup':
        $userId = $authedUser['uid'] ?? 0;
        if (!$userId) jsonResponse(['ok' => false, 'error' => 'Giriş gerekli'], 401);

        // 20 byte random secret (Base32 encoded)
        $secret = generateBase32Secret(20);
        $username = $authedUser['sub'] ?? 'user';
        $otpauthUri = "otpauth://totp/UYSA%20ERP:{$username}?secret={$secret}&issuer=UYSA%20ERP&digits=6&period=30";

        // Yedek kodlar
        $backupCodes = [];
        for ($i = 0; $i < 8; $i++) {
            $backupCodes[] = strtoupper(bin2hex(random_bytes(4)));
        }

        $pdo->prepare("INSERT INTO uysa_2fa (user_id, secret, backup_codes) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE secret=VALUES(secret), backup_codes=VALUES(backup_codes), is_enabled=0")
            ->execute([$userId, $secret, json_encode($backupCodes)]);

        jsonResponse(['ok' => true, 'secret' => $secret, 'otpauth_uri' => $otpauthUri, 'backup_codes' => $backupCodes]);

    case 'portal.2faVerify':
        $userId = $authedUser['uid'] ?? 0;
        $code = sanitizeInput($body['code'] ?? '', 6);
        if (!$userId || !$code) jsonResponse(['ok' => false, 'error' => 'code gerekli'], 400);

        $stmt = $pdo->prepare("SELECT secret FROM uysa_2fa WHERE user_id=?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) jsonResponse(['ok' => false, 'error' => '2FA henüz kurulmamış'], 400);

        if (!verifyTOTP($row['secret'], $code)) {
            jsonResponse(['ok' => false, 'error' => 'Geçersiz kod'], 400);
        }

        $pdo->prepare("UPDATE uysa_2fa SET is_enabled=1 WHERE user_id=?")->execute([$userId]);
        jsonResponse(['ok' => true, 'message' => '2FA başarıyla etkinleştirildi']);

    case 'portal.2faDisable':
        $userId = $authedUser['uid'] ?? 0;
        $code = sanitizeInput($body['code'] ?? '', 8);
        if (!$userId || !$code) jsonResponse(['ok' => false, 'error' => 'code gerekli'], 400);

        $stmt = $pdo->prepare("SELECT secret, backup_codes FROM uysa_2fa WHERE user_id=? AND is_enabled=1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) jsonResponse(['ok' => false, 'error' => '2FA aktif değil'], 400);

        // Normal kod veya yedek kod kontrolü
        $backupCodes = json_decode($row['backup_codes'] ?? '[]', true) ?: [];
        if (!verifyTOTP($row['secret'], $code) && !in_array(strtoupper($code), $backupCodes)) {
            jsonResponse(['ok' => false, 'error' => 'Geçersiz kod'], 400);
        }

        $pdo->prepare("UPDATE uysa_2fa SET is_enabled=0 WHERE user_id=?")->execute([$userId]);
        jsonResponse(['ok' => true, 'message' => '2FA devre dışı bırakıldı']);

    default:
        jsonResponse(['ok' => false, 'error' => "Bilinmeyen portal action: {$action}"], 404);
    }
}

// ── TOTP Yardımcıları ────────────────────────────────────────
function generateBase32Secret(int $bytes = 20): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $raw = random_bytes($bytes);
    // Base32 encode: 5 bits per char, 8 bits per byte -> ceil(bytes*8/5) chars
    $bits = '';
    foreach (str_split($raw) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $secret = '';
    for ($i = 0; $i + 5 <= strlen($bits); $i += 5) {
        $secret .= $chars[bindec(substr($bits, $i, 5))];
    }
    return $secret;
}

function base32Decode(string $b32): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(rtrim($b32, '='));
    $bits = '';
    for ($i = 0, $len = strlen($b32); $i < $len; $i++) {
        $val = strpos($chars, $b32[$i]);
        if ($val === false) continue;
        $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
    }
    $output = '';
    // Sadece tam byte'ları al
    $byteCount = (int)floor(strlen($bits) / 8);
    for ($i = 0; $i < $byteCount; $i++) {
        $output .= chr((int)bindec(substr($bits, $i * 8, 8)));
    }
    return $output;
}

function verifyTOTP(string $secret, string $code, int $window = 1): bool
{
    $key = base32Decode($secret);
    $now = (int)floor(time() / 30);

    for ($i = -$window; $i <= $window; $i++) {
        $time = pack('N*', 0, $now + $i);
        $hash = hash_hmac('sha1', $time, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $otp = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % 1000000;

        if (str_pad((string)$otp, 6, '0', STR_PAD_LEFT) === str_pad($code, 6, '0', STR_PAD_LEFT)) {
            return true;
        }
    }
    return false;
}

// ── Webhook Dispatch ─────────────────────────────────────────
function dispatchWebhook(PDO $pdo, string $event, array $payload): void
{
    $stmt = $pdo->prepare("SELECT * FROM uysa_webhooks WHERE is_active=1 AND JSON_CONTAINS(events, ?)");
    $stmt->execute([json_encode($event)]);
    $webhooks = $stmt->fetchAll();

    foreach ($webhooks as $wh) {
        $result = sendWebhookRequest($wh['url'], $wh['secret'], array_merge(['event' => $event], $payload));

        // Log
        $pdo->prepare("INSERT INTO uysa_webhook_logs (webhook_id, event, payload, response_code, response_body) VALUES (?,?,?,?,?)")
            ->execute([$wh['id'], $event, json_encode($payload), $result['code'] ?? null, $result['body'] ?? null]);

        // Güncelle
        $pdo->prepare("UPDATE uysa_webhooks SET last_triggered=NOW(), fail_count = CASE WHEN ? >= 200 AND ? < 300 THEN 0 ELSE fail_count+1 END WHERE id=?")
            ->execute([$result['code'], $result['code'], $wh['id']]);

        // 10 başarısız → devre dışı
        if (($result['code'] ?? 0) < 200 || ($result['code'] ?? 0) >= 300) {
            $pdo->prepare("UPDATE uysa_webhooks SET is_active=0 WHERE id=? AND fail_count >= 10")->execute([$wh['id']]);
        }
    }
}

function sendWebhookRequest(string $url, string $secret, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $signature = hash_hmac('sha256', $body, $secret);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "X-UYSA-Signature: sha256={$signature}",
            'X-UYSA-Event: ' . ($payload['event'] ?? 'unknown'),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $result = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $httpCode, 'body' => $result ?: null];
}
