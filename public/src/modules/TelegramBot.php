<?php
/**
 * UYSA ERP — Telegram Bot Entegrasyonu
 * Webhook tabanlı Telegram bot handler
 */
declare(strict_types=1);

/**
 * Telegram Bot ana handler
 * Railway'de /uysa_api.php?action=telegram.webhook olarak çağrılır
 */
function handleTelegramWebhook(PDO $pdo, array $update): void
{
    $botToken = getenv('TELEGRAM_BOT_TOKEN') ?: '';
    if (!$botToken) {
        error_log('[UYSA] Telegram bot token tanımlı değil');
        return;
    }

    // Mesaj veya callback query
    $message = $update['message'] ?? $update['callback_query']['message'] ?? null;
    if (!$message) return;

    $chatId   = $message['chat']['id'] ?? 0;
    $text     = trim($message['text'] ?? '');
    $tgUserId = $message['from']['id'] ?? 0;
    $tgUser   = $message['from']['username'] ?? '';

    if (!$chatId || !$text) return;

    // Kullanıcıyı tanı
    $stmt = $pdo->prepare("SELECT tu.*, uu.username AS uysa_username, uu.role AS uysa_role
                           FROM uysa_telegram_users tu
                           LEFT JOIN uysa_users uu ON tu.uysa_user_id = uu.id
                           WHERE tu.telegram_id = ?");
    $stmt->execute([$tgUserId]);
    $tgUserData = $stmt->fetch();

    // Komut parse
    $parts   = explode(' ', $text, 2);
    $command = strtolower($parts[0]);
    $args    = $parts[1] ?? '';

    $response = match(true) {
        $command === '/start'      => handleTgStart($pdo, $tgUserId, $tgUser),
        $command === '/baglanti'   => handleTgLink($pdo, $tgUserId, $args),
        !$tgUserData || !$tgUserData['is_verified']
                                   => "Lütfen önce ERP hesabınızı bağlayın:\n/baglanti kullanici_adi sifre",
        $command === '/stok'       => handleTgStock($pdo, $args),
        $command === '/fatura'     => handleTgInvoice($pdo, $args),
        $command === '/personel'   => handleTgEmployee($pdo, $args),
        $command === '/ozet'       => handleTgSummary($pdo),
        $command === '/dusukstok'  => handleTgLowStock($pdo),
        $command === '/siparisler' => handleTgOrders($pdo, $args),
        $command === '/yardim'     => handleTgHelp(),
        default                    => handleTgAI($pdo, $tgUserData, $text),
    };

    sendTelegramMessage($botToken, $chatId, $response);
}

// ── Telegram API Mesaj Gönderme ──────────────────────────────
function sendTelegramMessage(string $token, int $chatId, string $text, string $parseMode = 'HTML'): void
{
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload = [
        'chat_id'    => $chatId,
        'text'       => mb_substr($text, 0, 4096),
        'parse_mode' => $parseMode,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── /start komutu ────────────────────────────────────────────
function handleTgStart(PDO $pdo, int $tgUserId, string $tgUser): string
{
    // Kayıt veya mevcut kullanıcı kontrolü
    $stmt = $pdo->prepare("SELECT id, is_verified FROM uysa_telegram_users WHERE telegram_id = ?");
    $stmt->execute([$tgUserId]);
    $existing = $stmt->fetch();

    if (!$existing) {
        $pdo->prepare("INSERT INTO uysa_telegram_users (telegram_id, telegram_username) VALUES (?, ?)")
            ->execute([$tgUserId, $tgUser]);
    }

    return "UYSA ERP Bot'a hoş geldiniz!\n\n"
         . "ERP hesabınızı bağlamak için:\n"
         . "<code>/baglanti kullanici_adi sifre</code>\n\n"
         . "Bağlandıktan sonra /yardim yazarak komutları görebilirsiniz.";
}

// ── /baglanti komutu ─────────────────────────────────────────
function handleTgLink(PDO $pdo, int $tgUserId, string $args): string
{
    $parts = explode(' ', $args, 2);
    if (count($parts) < 2) {
        return "Kullanım: /baglanti kullanici_adi sifre";
    }

    [$username, $password] = $parts;

    $stmt = $pdo->prepare("SELECT id, password, role FROM uysa_users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return "Kullanıcı adı veya şifre hatalı.";
    }

    $pdo->prepare("INSERT INTO uysa_telegram_users (telegram_id, uysa_user_id, is_verified)
                    VALUES (?, ?, 1)
                    ON DUPLICATE KEY UPDATE uysa_user_id = VALUES(uysa_user_id), is_verified = 1")
        ->execute([$tgUserId, $user['id']]);

    return "Hesabınız başarıyla bağlandı! ({$username})\n/yardim ile komutları görebilirsiniz.";
}

// ── /stok komutu ─────────────────────────────────────────────
function handleTgStock(PDO $pdo, string $search): string
{
    if (!$search) {
        $stmt = $pdo->query("SELECT name, current_stock, unit, min_stock FROM uysa_products WHERE is_active = 1 ORDER BY name LIMIT 20");
        $rows = $stmt->fetchAll();
        if (!$rows) return "Henüz ürün tanımlı değil.";

        $msg = "<b>Stok Durumu (ilk 20)</b>\n\n";
        foreach ($rows as $r) {
            $warn = ($r['current_stock'] <= $r['min_stock'] && $r['min_stock'] > 0) ? ' ⚠️' : '';
            $msg .= "• {$r['name']}: <b>{$r['current_stock']} {$r['unit']}</b>{$warn}\n";
        }
        return $msg;
    }

    $stmt = $pdo->prepare("SELECT name, current_stock, unit, min_stock, unit_cost, category
                           FROM uysa_products WHERE (name LIKE ? OR sku LIKE ? OR barcode = ?) AND is_active = 1 LIMIT 10");
    $like = "%{$search}%";
    $stmt->execute([$like, $like, $search]);
    $rows = $stmt->fetchAll();

    if (!$rows) return "'{$search}' ile eşleşen ürün bulunamadı.";

    $msg = "<b>Arama: {$search}</b>\n\n";
    foreach ($rows as $r) {
        $warn = ($r['current_stock'] <= $r['min_stock'] && $r['min_stock'] > 0) ? ' ⚠️ DÜŞÜK' : '';
        $msg .= "<b>{$r['name']}</b>\n"
              . "  Stok: {$r['current_stock']} {$r['unit']}{$warn}\n"
              . "  Maliyet: ₺{$r['unit_cost']}\n"
              . "  Kategori: {$r['category']}\n\n";
    }
    return $msg;
}

// ── /fatura komutu ───────────────────────────────────────────
function handleTgInvoice(PDO $pdo, string $args): string
{
    if ($args === 'ozet' || !$args) {
        $row = $pdo->query("SELECT
            COUNT(*) as total,
            SUM(CASE WHEN type='sales' THEN total ELSE 0 END) as sales_total,
            SUM(CASE WHEN type='purchase' THEN total ELSE 0 END) as purchase_total,
            SUM(CASE WHEN status IN ('sent','overdue') AND due_date < CURDATE() THEN total ELSE 0 END) as overdue_total
            FROM uysa_invoices WHERE status != 'cancelled'")->fetch();

        return "<b>Fatura Özeti</b>\n\n"
             . "Toplam Fatura: {$row['total']}\n"
             . "Satış Toplamı: ₺" . number_format((float)$row['sales_total'], 2, ',', '.') . "\n"
             . "Alış Toplamı: ₺" . number_format((float)$row['purchase_total'], 2, ',', '.') . "\n"
             . "Vadesi Geçen: ₺" . number_format((float)$row['overdue_total'], 2, ',', '.');
    }

    // Son faturalar
    $stmt = $pdo->query("SELECT invoice_no, type, total, status, date FROM uysa_invoices ORDER BY created_at DESC LIMIT 10");
    $rows = $stmt->fetchAll();
    if (!$rows) return "Henüz fatura bulunmuyor.";

    $msg = "<b>Son 10 Fatura</b>\n\n";
    foreach ($rows as $r) {
        $tip = $r['type'] === 'sales' ? 'Satış' : 'Alış';
        $msg .= "#{$r['invoice_no']} | {$tip} | ₺" . number_format((float)$r['total'], 2, ',', '.') . " | {$r['status']} | {$r['date']}\n";
    }
    return $msg;
}

// ── /personel komutu ─────────────────────────────────────────
function handleTgEmployee(PDO $pdo, string $search): string
{
    if (!$search) {
        $row = $pdo->query("SELECT COUNT(*) as total,
            SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_count
            FROM uysa_employees")->fetch();

        return "<b>Personel Özeti</b>\n\n"
             . "Toplam: {$row['total']}\n"
             . "Aktif: {$row['active_count']}";
    }

    $stmt = $pdo->prepare("SELECT employee_no, first_name, last_name, department, position, status
                           FROM uysa_employees WHERE first_name LIKE ? OR last_name LIKE ? LIMIT 10");
    $like = "%{$search}%";
    $stmt->execute([$like, $like]);
    $rows = $stmt->fetchAll();

    if (!$rows) return "'{$search}' ile eşleşen personel bulunamadı.";

    $msg = "<b>Personel Arama: {$search}</b>\n\n";
    foreach ($rows as $r) {
        $msg .= "#{$r['employee_no']} {$r['first_name']} {$r['last_name']}\n"
              . "  Departman: {$r['department']} | Pozisyon: {$r['position']}\n"
              . "  Durum: {$r['status']}\n\n";
    }
    return $msg;
}

// ── /ozet komutu ─────────────────────────────────────────────
function handleTgSummary(PDO $pdo): string
{
    $stats = [];

    try {
        $stats['products']   = (int)$pdo->query("SELECT COUNT(*) FROM uysa_products WHERE is_active=1")->fetchColumn();
        $stats['low_stock']  = (int)$pdo->query("SELECT COUNT(*) FROM uysa_products WHERE current_stock <= min_stock AND min_stock > 0 AND is_active=1")->fetchColumn();
        $stats['employees']  = (int)$pdo->query("SELECT COUNT(*) FROM uysa_employees WHERE status='active'")->fetchColumn();
        $stats['invoices']   = (int)$pdo->query("SELECT COUNT(*) FROM uysa_invoices WHERE MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())")->fetchColumn();
        $stats['orders']     = (int)$pdo->query("SELECT COUNT(*) FROM uysa_customer_orders WHERE status NOT IN ('delivered','cancelled')")->fetchColumn();
        $stats['overdue']    = (int)$pdo->query("SELECT COUNT(*) FROM uysa_invoices WHERE status IN ('sent','overdue') AND due_date < CURDATE()")->fetchColumn();
    } catch (\Throwable) {}

    return "<b>UYSA ERP — Günlük Özet</b>\n\n"
         . "📦 Aktif Ürün: {$stats['products']}\n"
         . "⚠️ Düşük Stok: {$stats['low_stock']}\n"
         . "👥 Aktif Personel: {$stats['employees']}\n"
         . "📄 Bu Ay Fatura: {$stats['invoices']}\n"
         . "📋 Açık Sipariş: {$stats['orders']}\n"
         . "🔴 Vadesi Geçen: {$stats['overdue']}";
}

// ── /dusukstok komutu ────────────────────────────────────────
function handleTgLowStock(PDO $pdo): string
{
    $stmt = $pdo->query("SELECT name, current_stock, min_stock, unit
                         FROM uysa_products WHERE current_stock <= min_stock AND min_stock > 0 AND is_active = 1
                         ORDER BY (current_stock / min_stock) ASC LIMIT 20");
    $rows = $stmt->fetchAll();

    if (!$rows) return "Düşük stoklu ürün bulunmuyor.";

    $msg = "<b>⚠️ Düşük Stok Uyarıları</b>\n\n";
    foreach ($rows as $r) {
        $pct = $r['min_stock'] > 0 ? round(($r['current_stock'] / $r['min_stock']) * 100) : 0;
        $msg .= "• <b>{$r['name']}</b>: {$r['current_stock']}/{$r['min_stock']} {$r['unit']} ({$pct}%)\n";
    }
    return $msg;
}

// ── /siparisler komutu ───────────────────────────────────────
function handleTgOrders(PDO $pdo, string $status): string
{
    $where = "1=1";
    $params = [];
    if ($status) {
        $where = "co.status = ?";
        $params[] = $status;
    }

    $sql = "SELECT co.order_no, c.name AS customer, co.total, co.status, co.date
            FROM uysa_customer_orders co
            LEFT JOIN uysa_customers c ON co.customer_id = c.id
            WHERE {$where} ORDER BY co.created_at DESC LIMIT 15";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (!$rows) return "Sipariş bulunmuyor.";

    $msg = "<b>Siparişler</b>\n\n";
    foreach ($rows as $r) {
        $msg .= "#{$r['order_no']} | {$r['customer']} | ₺" . number_format((float)$r['total'], 2, ',', '.') . " | {$r['status']} | {$r['date']}\n";
    }
    return $msg;
}

// ── /yardim komutu ───────────────────────────────────────────
function handleTgHelp(): string
{
    return "<b>UYSA ERP Bot Komutları</b>\n\n"
         . "/ozet — Genel durum özeti\n"
         . "/stok [arama] — Stok sorgula\n"
         . "/dusukstok — Düşük stok uyarıları\n"
         . "/fatura [ozet] — Fatura bilgileri\n"
         . "/personel [arama] — Personel sorgula\n"
         . "/siparisler [durum] — Sipariş listesi\n"
         . "/baglanti kullanici sifre — Hesap bağla\n\n"
         . "Doğal dilde de soru sorabilirsiniz:\n"
         . "<i>\"Bu ayın gelir toplamı ne?\"</i>";
}

// ── Doğal dil AI sorgusu (Claude API proxy) ──────────────────
function handleTgAI(PDO $pdo, ?array $tgUser, string $question): string
{
    // AI API key kontrol
    $aiKey = getenv('ANTHROPIC_API_KEY') ?: '';
    if (!$aiKey) {
        return "AI asistan şu an aktif değil. Komutlar için /yardim yazın.";
    }

    // ERP bağlamı oluştur
    $context = buildERPContext($pdo);

    // Sohbet geçmişini kaydet
    $user = $tgUser['uysa_username'] ?? 'telegram';
    $pdo->prepare("INSERT INTO uysa_ai_chats (user, source, role, message) VALUES (?, 'telegram', 'user', ?)")
        ->execute([$user, $question]);

    // Claude API çağrısı
    $response = callClaudeAPI($aiKey, $question, $context);

    // Yanıtı kaydet
    $pdo->prepare("INSERT INTO uysa_ai_chats (user, source, role, message) VALUES (?, 'telegram', 'assistant', ?)")
        ->execute([$user, $response]);

    return $response;
}

// ── ERP Bağlam Oluşturma ────────────────────────────────────
function buildERPContext(PDO $pdo): string
{
    $ctx = "UYSA ERP Sistem Verileri:\n";
    try {
        // Genel istatistikler
        $products  = (int)$pdo->query("SELECT COUNT(*) FROM uysa_products WHERE is_active=1")->fetchColumn();
        $lowStock  = (int)$pdo->query("SELECT COUNT(*) FROM uysa_products WHERE current_stock<=min_stock AND min_stock>0 AND is_active=1")->fetchColumn();
        $employees = (int)$pdo->query("SELECT COUNT(*) FROM uysa_employees WHERE status='active'")->fetchColumn();

        $finRow = $pdo->query("SELECT
            SUM(CASE WHEN type='sales' AND status!='cancelled' THEN total ELSE 0 END) as sales,
            SUM(CASE WHEN type='purchase' AND status!='cancelled' THEN total ELSE 0 END) as purchases
            FROM uysa_invoices WHERE YEAR(date)=YEAR(CURDATE())")->fetch();

        $ctx .= "- Aktif Ürün: {$products}, Düşük Stok: {$lowStock}\n";
        $ctx .= "- Aktif Personel: {$employees}\n";
        $ctx .= "- Bu Yıl Satış: ₺" . number_format((float)($finRow['sales'] ?? 0), 2) . "\n";
        $ctx .= "- Bu Yıl Alış: ₺" . number_format((float)($finRow['purchases'] ?? 0), 2) . "\n";

        // Düşük stok listesi
        $lowItems = $pdo->query("SELECT name, current_stock, min_stock, unit FROM uysa_products
                                 WHERE current_stock<=min_stock AND min_stock>0 AND is_active=1 LIMIT 5")->fetchAll();
        if ($lowItems) {
            $ctx .= "\nDüşük Stok Ürünleri:\n";
            foreach ($lowItems as $li) {
                $ctx .= "  - {$li['name']}: {$li['current_stock']}/{$li['min_stock']} {$li['unit']}\n";
            }
        }
    } catch (\Throwable) {}

    return $ctx;
}

// ── Claude API Çağrısı ──────────────────────────────────────
function callClaudeAPI(string $apiKey, string $question, string $context): string
{
    $url = 'https://api.anthropic.com/v1/messages';

    $payload = [
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 1024,
        'system'     => "Sen UYSA ERP sisteminin AI asistanısın. Yemek sektörü ERP'si hakkında soruları yanıtlıyorsun. "
                      . "Türkçe yanıt ver, kısa ve öz ol. Telegram formatında HTML kullan (<b>, <i>, <code>). "
                      . "Aşağıdaki güncel ERP verilerini kullanarak yanıt ver:\n\n{$context}",
        'messages'   => [
            ['role' => 'user', 'content' => $question]
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "x-api-key: {$apiKey}",
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$result) {
        return "AI yanıt veremedi. Lütfen komutları kullanın: /yardim";
    }

    $data = json_decode($result, true);
    return $data['content'][0]['text'] ?? "Yanıt alınamadı.";
}
