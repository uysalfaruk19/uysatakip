<?php
/**
 * UYSA ERP — Finans & Muhasebe Modülü v5.0
 * Çift taraflı kayıt, fatura, ödeme, banka, raporlama
 */
declare(strict_types=1);

function handleFinanceAction(string $action, PDO $pdo, array $body, ?array $authedUser, string $clientIp): void
{
    $actor = $authedUser['sub'] ?? 'anonymous';

    switch ($action) {

    // ── Hesap Planı ──────────────────────────────────────────
    case 'fin.accounts':
        $type = sanitizeInput($_GET['type'] ?? '', 20);
        $where = "1=1";
        $params = [];
        if ($type) { $where .= " AND type = ?"; $params[] = $type; }
        $stmt = $pdo->prepare("SELECT * FROM uysa_accounts WHERE {$where} ORDER BY code");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'accounts' => $stmt->fetchAll()]);

    case 'fin.accountSave':
        $id   = (int)($body['id'] ?? 0);
        $code = sanitizeInput($body['code'] ?? '', 20);
        $name = sanitizeInput($body['name'] ?? '', 200);
        $type = sanitizeInput($body['type'] ?? 'asset', 20);
        $parentId = $body['parent_id'] ? (int)$body['parent_id'] : null;
        $isActive = (int)($body['is_active'] ?? 1);

        if (!$code || !$name) jsonResponse(['ok' => false, 'error' => 'code ve name gerekli'], 400);

        if ($id) {
            $pdo->prepare("UPDATE uysa_accounts SET code=?, name=?, type=?, parent_id=?, is_active=? WHERE id=?")
                ->execute([$code, $name, $type, $parentId, $isActive, $id]);
        } else {
            $pdo->prepare("INSERT INTO uysa_accounts (code, name, type, parent_id, is_active) VALUES (?,?,?,?,?)")
                ->execute([$code, $name, $type, $parentId, $isActive]);
            $id = (int)$pdo->lastInsertId();
        }
        auditLog($pdo, 'fin.accountSave', $actor, $code, null, $clientIp);
        jsonResponse(['ok' => true, 'id' => $id]);

    // ── Yevmiye Kayıtları ────────────────────────────────────
    case 'fin.journalList':
        $startDate = sanitizeInput($_GET['start_date'] ?? '', 10);
        $endDate   = sanitizeInput($_GET['end_date'] ?? '', 10);
        $status    = sanitizeInput($_GET['status'] ?? '', 20);
        $limit     = min((int)($_GET['limit'] ?? 50), 500);
        $offset    = max((int)($_GET['offset'] ?? 0), 0);

        $where = "1=1"; $params = [];
        if ($startDate) { $where .= " AND je.date >= ?"; $params[] = $startDate; }
        if ($endDate)   { $where .= " AND je.date <= ?"; $params[] = $endDate; }
        if ($status)    { $where .= " AND je.status = ?"; $params[] = $status; }

        $stmt = $pdo->prepare("SELECT je.*,
            (SELECT SUM(debit) FROM uysa_journal_lines WHERE entry_id=je.id) as total_debit,
            (SELECT SUM(credit) FROM uysa_journal_lines WHERE entry_id=je.id) as total_credit
            FROM uysa_journal_entries je WHERE {$where} ORDER BY je.date DESC, je.id DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'entries' => $stmt->fetchAll()]);

    case 'fin.journalSave':
        $date  = sanitizeInput($body['date'] ?? date('Y-m-d'), 10);
        $desc  = sanitizeInput($body['description'] ?? '', 500);
        $lines = $body['lines'] ?? [];

        if (empty($lines)) jsonResponse(['ok' => false, 'error' => 'En az bir satır gerekli'], 400);

        // Borç/Alacak eşitlik kontrolü
        $totalDebit = $totalCredit = 0;
        foreach ($lines as $l) {
            $totalDebit  += (float)($l['debit'] ?? 0);
            $totalCredit += (float)($l['credit'] ?? 0);
        }
        if (abs($totalDebit - $totalCredit) > 0.01) {
            jsonResponse(['ok' => false, 'error' => "Borç ({$totalDebit}) ve Alacak ({$totalCredit}) eşit olmalı"], 400);
        }

        $pdo->beginTransaction();
        try {
            // Fiş numarası oluştur
            $year = date('Y');
            $cnt = (int)$pdo->query("SELECT COUNT(*)+1 FROM uysa_journal_entries WHERE YEAR(date)={$year}")->fetchColumn();
            $entryNo = "YMM-{$year}-" . str_pad((string)$cnt, 5, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO uysa_journal_entries (entry_no, date, description, status, created_by) VALUES (?,?,?,?,?)")
                ->execute([$entryNo, $date, $desc, 'draft', $actor]);
            $entryId = (int)$pdo->lastInsertId();

            $lineStmt = $pdo->prepare("INSERT INTO uysa_journal_lines (entry_id, account_id, debit, credit, description) VALUES (?,?,?,?,?)");
            foreach ($lines as $l) {
                $lineStmt->execute([
                    $entryId,
                    (int)$l['account_id'],
                    round((float)($l['debit'] ?? 0), 2),
                    round((float)($l['credit'] ?? 0), 2),
                    sanitizeInput($l['description'] ?? '', 300),
                ]);
            }
            $pdo->commit();
            auditLog($pdo, 'fin.journalSave', $actor, $entryNo, null, $clientIp);
            jsonResponse(['ok' => true, 'id' => $entryId, 'entry_no' => $entryNo]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Yevmiye kaydı başarısız: ' . $e->getMessage()], 500);
        }

    case 'fin.journalDetail':
        $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id gerekli'], 400);
        $entry = $pdo->prepare("SELECT * FROM uysa_journal_entries WHERE id=?");
        $entry->execute([$id]);
        $e = $entry->fetch();
        if (!$e) jsonResponse(['ok' => false, 'error' => 'Fiş bulunamadı'], 404);
        $lines = $pdo->prepare("SELECT jl.*, a.code AS account_code, a.name AS account_name
                                FROM uysa_journal_lines jl LEFT JOIN uysa_accounts a ON jl.account_id=a.id WHERE jl.entry_id=?");
        $lines->execute([$id]);
        $e['lines'] = $lines->fetchAll();
        jsonResponse(['ok' => true, 'entry' => $e]);

    case 'fin.journalPost':
        $id = (int)($body['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id gerekli'], 400);
        $pdo->prepare("UPDATE uysa_journal_entries SET status='posted' WHERE id=? AND status='draft'")->execute([$id]);
        auditLog($pdo, 'fin.journalPost', $actor, (string)$id, null, $clientIp);
        jsonResponse(['ok' => true]);

    case 'fin.journalVoid':
        $id = (int)($body['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id gerekli'], 400);
        $pdo->prepare("UPDATE uysa_journal_entries SET status='void' WHERE id=?")->execute([$id]);
        auditLog($pdo, 'fin.journalVoid', $actor, (string)$id, null, $clientIp);
        jsonResponse(['ok' => true]);

    // ── Faturalar ────────────────────────────────────────────
    case 'fin.invoiceList':
        $type     = sanitizeInput($_GET['type'] ?? '', 20);
        $status   = sanitizeInput($_GET['status'] ?? '', 20);
        $startDate = sanitizeInput($_GET['start_date'] ?? '', 10);
        $endDate   = sanitizeInput($_GET['end_date'] ?? '', 10);
        $customerId = (int)($_GET['customer_id'] ?? 0);
        $supplierId = (int)($_GET['supplier_id'] ?? 0);
        $limit = min((int)($_GET['limit'] ?? 50), 500);
        $offset = max((int)($_GET['offset'] ?? 0), 0);

        $where = "1=1"; $params = [];
        if ($type)     { $where .= " AND i.type = ?"; $params[] = $type; }
        if ($status)   { $where .= " AND i.status = ?"; $params[] = $status; }
        if ($startDate){ $where .= " AND i.date >= ?"; $params[] = $startDate; }
        if ($endDate)  { $where .= " AND i.date <= ?"; $params[] = $endDate; }
        if ($customerId){ $where .= " AND i.customer_id = ?"; $params[] = $customerId; }
        if ($supplierId){ $where .= " AND i.supplier_id = ?"; $params[] = $supplierId; }

        $stmt = $pdo->prepare("SELECT i.*, c.name AS customer_name, s.name AS supplier_name
            FROM uysa_invoices i
            LEFT JOIN uysa_customers c ON i.customer_id = c.id
            LEFT JOIN uysa_suppliers s ON i.supplier_id = s.id
            WHERE {$where} ORDER BY i.date DESC, i.id DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'invoices' => $stmt->fetchAll()]);

    case 'fin.invoiceSave':
        $id        = (int)($body['id'] ?? 0);
        $type      = sanitizeInput($body['type'] ?? 'sales', 20);
        $custId    = $body['customer_id'] ? (int)$body['customer_id'] : null;
        $suppId    = $body['supplier_id'] ? (int)$body['supplier_id'] : null;
        $date      = sanitizeInput($body['date'] ?? date('Y-m-d'), 10);
        $dueDate   = sanitizeInput($body['due_date'] ?? '', 10) ?: null;
        $taxRate   = round((float)($body['tax_rate'] ?? 20), 2);
        $notes     = sanitizeInput($body['notes'] ?? '', 2000);
        $lines     = $body['lines'] ?? [];

        if (empty($lines)) jsonResponse(['ok' => false, 'error' => 'En az bir satır gerekli'], 400);

        // Hesapla
        $subtotal = 0;
        foreach ($lines as &$l) {
            $qty   = round((float)($l['quantity'] ?? 1), 3);
            $price = round((float)($l['unit_price'] ?? 0), 2);
            $lTax  = round((float)($l['tax_rate'] ?? $taxRate), 2);
            $lineTotal = round($qty * $price * (1 + $lTax / 100), 2);
            $l['_total'] = $lineTotal;
            $subtotal += round($qty * $price, 2);
        }
        unset($l);
        $taxAmount = round($subtotal * $taxRate / 100, 2);
        $total = round($subtotal + $taxAmount, 2);

        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare("UPDATE uysa_invoices SET type=?, customer_id=?, supplier_id=?, date=?, due_date=?,
                    subtotal=?, tax_rate=?, tax_amount=?, total=?, notes=? WHERE id=?")
                    ->execute([$type, $custId, $suppId, $date, $dueDate, $subtotal, $taxRate, $taxAmount, $total, $notes, $id]);
                $pdo->prepare("DELETE FROM uysa_invoice_lines WHERE invoice_id=?")->execute([$id]);
            } else {
                $prefix = $type === 'sales' ? 'SLS' : 'PRC';
                $year = date('Y');
                $cnt = (int)$pdo->query("SELECT COUNT(*)+1 FROM uysa_invoices WHERE type='{$type}' AND YEAR(date)={$year}")->fetchColumn();
                $invoiceNo = "{$prefix}-{$year}-" . str_pad((string)$cnt, 5, '0', STR_PAD_LEFT);

                $pdo->prepare("INSERT INTO uysa_invoices (invoice_no, type, customer_id, supplier_id, date, due_date,
                    subtotal, tax_rate, tax_amount, total, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$invoiceNo, $type, $custId, $suppId, $date, $dueDate, $subtotal, $taxRate, $taxAmount, $total, $notes, $actor]);
                $id = (int)$pdo->lastInsertId();
            }

            $lineStmt = $pdo->prepare("INSERT INTO uysa_invoice_lines (invoice_id, product_id, description, quantity, unit, unit_price, tax_rate, total) VALUES (?,?,?,?,?,?,?,?)");
            foreach ($lines as $l) {
                $qty = round((float)($l['quantity'] ?? 1), 3);
                $price = round((float)($l['unit_price'] ?? 0), 2);
                $lTax = round((float)($l['tax_rate'] ?? $taxRate), 2);
                $lt = round($qty * $price * (1 + $lTax/100), 2);
                $lineStmt->execute([
                    $id,
                    $l['product_id'] ? (int)$l['product_id'] : null,
                    sanitizeInput($l['description'] ?? '', 500),
                    $qty,
                    sanitizeInput($l['unit'] ?? 'adet', 20),
                    $price,
                    $lTax,
                    $lt,
                ]);
            }
            $pdo->commit();
            auditLog($pdo, 'fin.invoiceSave', $actor, (string)$id, json_encode(['total' => $total]), $clientIp);
            jsonResponse(['ok' => true, 'id' => $id, 'total' => $total]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Fatura kaydı başarısız: ' . $e->getMessage()], 500);
        }

    case 'fin.invoiceDetail':
        $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id gerekli'], 400);
        $inv = $pdo->prepare("SELECT i.*, c.name AS customer_name, s.name AS supplier_name
            FROM uysa_invoices i LEFT JOIN uysa_customers c ON i.customer_id=c.id
            LEFT JOIN uysa_suppliers s ON i.supplier_id=s.id WHERE i.id=?");
        $inv->execute([$id]);
        $i = $inv->fetch();
        if (!$i) jsonResponse(['ok' => false, 'error' => 'Fatura bulunamadı'], 404);
        $lines = $pdo->prepare("SELECT * FROM uysa_invoice_lines WHERE invoice_id=?");
        $lines->execute([$id]);
        $i['lines'] = $lines->fetchAll();
        // Ödemeler
        $pays = $pdo->prepare("SELECT * FROM uysa_payments WHERE invoice_id=?");
        $pays->execute([$id]);
        $i['payments'] = $pays->fetchAll();
        $i['paid_total'] = array_sum(array_column($i['payments'], 'amount'));
        $i['remaining'] = round((float)$i['total'] - $i['paid_total'], 2);
        jsonResponse(['ok' => true, 'invoice' => $i]);

    case 'fin.invoiceStatusUpdate':
        $id = (int)($body['id'] ?? 0);
        $status = sanitizeInput($body['status'] ?? '', 20);
        if (!$id || !$status) jsonResponse(['ok' => false, 'error' => 'id ve status gerekli'], 400);
        $pdo->prepare("UPDATE uysa_invoices SET status=? WHERE id=?")->execute([$status, $id]);
        auditLog($pdo, 'fin.invoiceStatus', $actor, (string)$id, $status, $clientIp);
        jsonResponse(['ok' => true]);

    // ── Ödemeler ─────────────────────────────────────────────
    case 'fin.paymentList':
        $invoiceId = (int)($_GET['invoice_id'] ?? 0);
        $method    = sanitizeInput($_GET['method'] ?? '', 20);
        $startDate = sanitizeInput($_GET['start_date'] ?? '', 10);
        $endDate   = sanitizeInput($_GET['end_date'] ?? '', 10);
        $limit = min((int)($_GET['limit'] ?? 50), 500);

        $where = "1=1"; $params = [];
        if ($invoiceId) { $where .= " AND p.invoice_id = ?"; $params[] = $invoiceId; }
        if ($method)    { $where .= " AND p.method = ?"; $params[] = $method; }
        if ($startDate) { $where .= " AND p.date >= ?"; $params[] = $startDate; }
        if ($endDate)   { $where .= " AND p.date <= ?"; $params[] = $endDate; }

        $stmt = $pdo->prepare("SELECT p.*, i.invoice_no, i.total AS invoice_total
            FROM uysa_payments p LEFT JOIN uysa_invoices i ON p.invoice_id=i.id
            WHERE {$where} ORDER BY p.date DESC LIMIT {$limit}");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'payments' => $stmt->fetchAll()]);

    case 'fin.paymentSave':
        $invoiceId = $body['invoice_id'] ? (int)$body['invoice_id'] : null;
        $amount    = round((float)($body['amount'] ?? 0), 2);
        $method    = sanitizeInput($body['method'] ?? 'cash', 20);
        $date      = sanitizeInput($body['date'] ?? date('Y-m-d'), 10);
        $reference = sanitizeInput($body['reference'] ?? '', 100);
        $notes     = sanitizeInput($body['notes'] ?? '', 500);
        $bankAccId = $body['bank_account_id'] ? (int)$body['bank_account_id'] : null;

        if ($amount <= 0) jsonResponse(['ok' => false, 'error' => 'Tutar 0\'dan büyük olmalı'], 400);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO uysa_payments (invoice_id, amount, method, date, reference, notes, bank_account_id, created_by)
                VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$invoiceId, $amount, $method, $date, $reference, $notes, $bankAccId, $actor]);
            $payId = (int)$pdo->lastInsertId();

            // Fatura durumunu güncelle
            if ($invoiceId) {
                $paid = (float)$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM uysa_payments WHERE invoice_id=?")
                    ->execute([$invoiceId]) ? $pdo->query("SELECT COALESCE(SUM(amount),0) FROM uysa_payments WHERE invoice_id={$invoiceId}")->fetchColumn() : 0;
                $invTotal = (float)$pdo->query("SELECT total FROM uysa_invoices WHERE id={$invoiceId}")->fetchColumn();
                if ($paid >= $invTotal) {
                    $pdo->prepare("UPDATE uysa_invoices SET status='paid' WHERE id=?")->execute([$invoiceId]);
                }
            }

            // Banka bakiyesini güncelle
            if ($bankAccId) {
                $pdo->prepare("UPDATE uysa_bank_accounts SET balance = balance + ? WHERE id=?")
                    ->execute([$method === 'cash' ? 0 : -$amount, $bankAccId]);
            }

            $pdo->commit();
            auditLog($pdo, 'fin.paymentSave', $actor, (string)$payId, json_encode(['amount' => $amount]), $clientIp);
            jsonResponse(['ok' => true, 'id' => $payId]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Ödeme kaydı başarısız: ' . $e->getMessage()], 500);
        }

    // ── Banka Hesapları ──────────────────────────────────────
    case 'fin.bankAccounts':
        $stmt = $pdo->query("SELECT * FROM uysa_bank_accounts ORDER BY name");
        jsonResponse(['ok' => true, 'accounts' => $stmt->fetchAll()]);

    case 'fin.bankAccountSave':
        $id       = (int)($body['id'] ?? 0);
        $name     = sanitizeInput($body['name'] ?? '', 100);
        $bankName = sanitizeInput($body['bank_name'] ?? '', 100);
        $iban     = sanitizeInput($body['iban'] ?? '', 34);
        $currency = sanitizeInput($body['currency'] ?? 'TRY', 3);
        $balance  = round((float)($body['balance'] ?? 0), 2);
        $isActive = (int)($body['is_active'] ?? 1);

        if (!$name || !$bankName) jsonResponse(['ok' => false, 'error' => 'name ve bank_name gerekli'], 400);

        if ($id) {
            $pdo->prepare("UPDATE uysa_bank_accounts SET name=?, bank_name=?, iban=?, currency=?, balance=?, is_active=? WHERE id=?")
                ->execute([$name, $bankName, $iban, $currency, $balance, $isActive, $id]);
        } else {
            $pdo->prepare("INSERT INTO uysa_bank_accounts (name, bank_name, iban, currency, balance, is_active) VALUES (?,?,?,?,?,?)")
                ->execute([$name, $bankName, $iban, $currency, $balance, $isActive]);
            $id = (int)$pdo->lastInsertId();
        }
        jsonResponse(['ok' => true, 'id' => $id]);

    case 'fin.bankTransactions':
        $bankId    = (int)($_GET['bank_account_id'] ?? 0);
        $startDate = sanitizeInput($_GET['start_date'] ?? '', 10);
        $endDate   = sanitizeInput($_GET['end_date'] ?? '', 10);
        $limit = min((int)($_GET['limit'] ?? 50), 500);

        $where = "1=1"; $params = [];
        if ($bankId)   { $where .= " AND bt.bank_account_id = ?"; $params[] = $bankId; }
        if ($startDate){ $where .= " AND bt.date >= ?"; $params[] = $startDate; }
        if ($endDate)  { $where .= " AND bt.date <= ?"; $params[] = $endDate; }

        $stmt = $pdo->prepare("SELECT bt.*, ba.name AS bank_name FROM uysa_bank_transactions bt
            LEFT JOIN uysa_bank_accounts ba ON bt.bank_account_id=ba.id
            WHERE {$where} ORDER BY bt.date DESC LIMIT {$limit}");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'transactions' => $stmt->fetchAll()]);

    case 'fin.bankTransactionSave':
        $bankAccId = (int)($body['bank_account_id'] ?? 0);
        $type      = sanitizeInput($body['type'] ?? '', 20);
        $amount    = round((float)($body['amount'] ?? 0), 2);
        $desc      = sanitizeInput($body['description'] ?? '', 500);
        $date      = sanitizeInput($body['date'] ?? date('Y-m-d'), 10);
        $reference = sanitizeInput($body['reference'] ?? '', 100);

        if (!$bankAccId || !$type || $amount <= 0) jsonResponse(['ok' => false, 'error' => 'bank_account_id, type ve amount gerekli'], 400);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO uysa_bank_transactions (bank_account_id, type, amount, description, date, reference) VALUES (?,?,?,?,?,?)")
                ->execute([$bankAccId, $type, $amount, $desc, $date, $reference]);
            $txId = (int)$pdo->lastInsertId();

            $balanceChange = $type === 'deposit' ? $amount : -$amount;
            $pdo->prepare("UPDATE uysa_bank_accounts SET balance = balance + ? WHERE id=?")->execute([$balanceChange, $bankAccId]);

            $pdo->commit();
            jsonResponse(['ok' => true, 'id' => $txId]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'İşlem kaydı başarısız: ' . $e->getMessage()], 500);
        }

    // ── Raporlar ─────────────────────────────────────────────
    case 'fin.trialBalance':
        $startDate = sanitizeInput($_GET['start_date'] ?? '', 10);
        $endDate   = sanitizeInput($_GET['end_date'] ?? date('Y-m-d'), 10);

        $where = "je.status = 'posted'";
        $params = [];
        if ($startDate) { $where .= " AND je.date >= ?"; $params[] = $startDate; }
        if ($endDate)   { $where .= " AND je.date <= ?"; $params[] = $endDate; }

        $stmt = $pdo->prepare("SELECT a.id, a.code, a.name, a.type,
            COALESCE(SUM(jl.debit),0) AS total_debit,
            COALESCE(SUM(jl.credit),0) AS total_credit,
            COALESCE(SUM(jl.debit),0) - COALESCE(SUM(jl.credit),0) AS balance
            FROM uysa_accounts a
            LEFT JOIN uysa_journal_lines jl ON a.id = jl.account_id
            LEFT JOIN uysa_journal_entries je ON jl.entry_id = je.id AND {$where}
            WHERE a.is_active = 1
            GROUP BY a.id ORDER BY a.code");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'accounts' => $stmt->fetchAll()]);

    case 'fin.balanceSheet':
        $date = sanitizeInput($_GET['date'] ?? date('Y-m-d'), 10);
        $stmt = $pdo->prepare("SELECT a.type,
            COALESCE(SUM(jl.debit),0) - COALESCE(SUM(jl.credit),0) AS balance
            FROM uysa_accounts a
            LEFT JOIN uysa_journal_lines jl ON a.id = jl.account_id
            LEFT JOIN uysa_journal_entries je ON jl.entry_id = je.id AND je.status='posted' AND je.date <= ?
            WHERE a.type IN ('asset','liability','equity') AND a.is_active=1
            GROUP BY a.type");
        $stmt->execute([$date]);
        $rows = $stmt->fetchAll();
        $result = ['assets' => 0, 'liabilities' => 0, 'equity' => 0];
        foreach ($rows as $r) {
            $key = $r['type'] === 'asset' ? 'assets' : ($r['type'] === 'liability' ? 'liabilities' : 'equity');
            $result[$key] = round((float)$r['balance'], 2);
        }
        $result['balanced'] = abs($result['assets'] - ($result['liabilities'] + $result['equity'])) < 0.01;
        jsonResponse(['ok' => true, 'balance_sheet' => $result, 'date' => $date]);

    case 'fin.incomeStatement':
        $startDate = sanitizeInput($_GET['start_date'] ?? date('Y-01-01'), 10);
        $endDate   = sanitizeInput($_GET['end_date'] ?? date('Y-m-d'), 10);

        $stmt = $pdo->prepare("SELECT a.type,
            COALESCE(SUM(jl.credit),0) - COALESCE(SUM(jl.debit),0) AS balance
            FROM uysa_accounts a
            LEFT JOIN uysa_journal_lines jl ON a.id = jl.account_id
            LEFT JOIN uysa_journal_entries je ON jl.entry_id = je.id AND je.status='posted' AND je.date >= ? AND je.date <= ?
            WHERE a.type IN ('income','expense') AND a.is_active=1
            GROUP BY a.type");
        $stmt->execute([$startDate, $endDate]);
        $rows = $stmt->fetchAll();
        $income = $expense = 0;
        foreach ($rows as $r) {
            if ($r['type'] === 'income') $income = round((float)$r['balance'], 2);
            if ($r['type'] === 'expense') $expense = round(abs((float)$r['balance']), 2);
        }
        jsonResponse(['ok' => true, 'income_statement' => [
            'income' => $income, 'expense' => $expense, 'net_profit' => round($income - $expense, 2),
            'start_date' => $startDate, 'end_date' => $endDate,
        ]]);

    case 'fin.dashboard':
        $year = date('Y');
        $month = date('m');
        $d = [];
        try {
            $d['total_income'] = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM uysa_invoices WHERE type='sales' AND status!='cancelled' AND YEAR(date)={$year}")->fetchColumn();
            $d['total_expense'] = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM uysa_invoices WHERE type='purchase' AND status!='cancelled' AND YEAR(date)={$year}")->fetchColumn();
            $d['net_profit'] = round($d['total_income'] - $d['total_expense'], 2);
            $d['monthly_income'] = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM uysa_invoices WHERE type='sales' AND status!='cancelled' AND YEAR(date)={$year} AND MONTH(date)={$month}")->fetchColumn();
            $d['monthly_expense'] = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM uysa_invoices WHERE type='purchase' AND status!='cancelled' AND YEAR(date)={$year} AND MONTH(date)={$month}")->fetchColumn();
            $d['total_receivables'] = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM uysa_invoices WHERE type='sales' AND status IN ('sent','overdue')")->fetchColumn();
            $d['total_payables'] = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM uysa_invoices WHERE type='purchase' AND status IN ('sent','overdue')")->fetchColumn();
            $d['overdue_count'] = (int)$pdo->query("SELECT COUNT(*) FROM uysa_invoices WHERE status IN ('sent','overdue') AND due_date < CURDATE()")->fetchColumn();
            $d['bank_total'] = (float)$pdo->query("SELECT COALESCE(SUM(balance),0) FROM uysa_bank_accounts WHERE is_active=1")->fetchColumn();
            $d['pending_payments'] = (int)$pdo->query("SELECT COUNT(*) FROM uysa_invoices WHERE status='sent'")->fetchColumn();
        } catch (\Throwable) {}
        jsonResponse(['ok' => true, 'dashboard' => $d]);

    default:
        jsonResponse(['ok' => false, 'error' => "Bilinmeyen finans action: {$action}"], 404);
    }
}
