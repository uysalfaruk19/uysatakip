<?php
/**
 * UYSA ERP — İK & Bordro Modülü v5.0
 * Personel, izin, puantaj, vardiya, bordro, performans, eğitim
 */
declare(strict_types=1);

function handleHRAction(string $action, PDO $pdo, array $body, ?array $authedUser, string $clientIp): void
{
    $actor = $authedUser['sub'] ?? 'anonymous';

    switch ($action) {

    // ── Personel ─────────────────────────────────────────────
    case 'hr.employees':
        $dept   = sanitizeInput($_GET['department'] ?? '', 100);
        $status = sanitizeInput($_GET['status'] ?? '', 20);
        $search = sanitizeInput($_GET['search'] ?? '', 100);
        $limit  = min((int)($_GET['limit'] ?? 50), 500);
        $offset = max((int)($_GET['offset'] ?? 0), 0);

        $where = "1=1"; $params = [];
        if ($dept)   { $where .= " AND e.department = ?"; $params[] = $dept; }
        if ($status) { $where .= " AND e.status = ?"; $params[] = $status; }
        if ($search) {
            $where .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_no LIKE ?)";
            $like = "%{$search}%"; $params = array_merge($params, [$like, $like, $like]);
        }

        $stmt = $pdo->prepare("SELECT e.*, CONCAT(m.first_name,' ',m.last_name) AS manager_name
            FROM uysa_employees e LEFT JOIN uysa_employees m ON e.manager_id=m.id
            WHERE {$where} ORDER BY e.first_name, e.last_name LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'employees' => $stmt->fetchAll()]);

    case 'hr.employeeSave':
        $id         = (int)($body['id'] ?? 0);
        $firstName  = sanitizeInput($body['first_name'] ?? '', 50);
        $lastName   = sanitizeInput($body['last_name'] ?? '', 50);
        $tcNo       = sanitizeInput($body['tc_no'] ?? '', 11);
        $phone      = sanitizeInput($body['phone'] ?? '', 20);
        $email      = sanitizeInput($body['email'] ?? '', 255);
        $department = sanitizeInput($body['department'] ?? '', 100);
        $position   = sanitizeInput($body['position'] ?? '', 100);
        $hireDate   = sanitizeInput($body['hire_date'] ?? date('Y-m-d'), 10);
        $salary     = round((float)($body['salary'] ?? 0), 2);
        $salaryType = sanitizeInput($body['salary_type'] ?? 'monthly', 20);
        $shiftGroup = sanitizeInput($body['shift_group'] ?? '', 50) ?: null;
        $managerId  = $body['manager_id'] ? (int)$body['manager_id'] : null;
        $status     = sanitizeInput($body['status'] ?? 'active', 20);

        if (!$firstName || !$lastName) jsonResponse(['ok' => false, 'error' => 'first_name ve last_name gerekli'], 400);

        if ($id) {
            $pdo->prepare("UPDATE uysa_employees SET first_name=?, last_name=?, tc_no=?, phone=?, email=?,
                department=?, position=?, hire_date=?, salary=?, salary_type=?, shift_group=?, manager_id=?, status=? WHERE id=?")
                ->execute([$firstName, $lastName, $tcNo, $phone, $email, $department, $position, $hireDate, $salary, $salaryType, $shiftGroup, $managerId, $status, $id]);
        } else {
            // Sicil numarası oluştur
            $cnt = (int)$pdo->query("SELECT COUNT(*)+1 FROM uysa_employees")->fetchColumn();
            $empNo = 'EMP-' . str_pad((string)$cnt, 4, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO uysa_employees (employee_no, first_name, last_name, tc_no, phone, email,
                department, position, hire_date, salary, salary_type, shift_group, manager_id, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$empNo, $firstName, $lastName, $tcNo, $phone, $email, $department, $position, $hireDate, $salary, $salaryType, $shiftGroup, $managerId, $status]);
            $id = (int)$pdo->lastInsertId();
        }
        auditLog($pdo, 'hr.employeeSave', $actor, "{$firstName} {$lastName}", null, $clientIp);
        jsonResponse(['ok' => true, 'id' => $id]);

    case 'hr.employeeDetail':
        $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id gerekli'], 400);

        $stmt = $pdo->prepare("SELECT e.*, CONCAT(m.first_name,' ',m.last_name) AS manager_name
            FROM uysa_employees e LEFT JOIN uysa_employees m ON e.manager_id=m.id WHERE e.id=?");
        $stmt->execute([$id]);
        $emp = $stmt->fetch();
        if (!$emp) jsonResponse(['ok' => false, 'error' => 'Personel bulunamadı'], 404);

        // İzin kullanımı
        $year = date('Y');
        $leaves = $pdo->prepare("SELECT lt.name, COALESCE(SUM(lr.days),0) AS used_days, lt.default_days
            FROM uysa_leave_types lt LEFT JOIN uysa_leave_requests lr ON lt.id=lr.leave_type_id
            AND lr.employee_id=? AND lr.status='approved' AND YEAR(lr.start_date)=?
            WHERE lt.is_active=1 GROUP BY lt.id");
        $leaves->execute([$id, $year]);
        $emp['leave_summary'] = $leaves->fetchAll();

        // Son puantaj
        $att = $pdo->prepare("SELECT * FROM uysa_attendance WHERE employee_id=? ORDER BY date DESC LIMIT 30");
        $att->execute([$id]);
        $emp['recent_attendance'] = $att->fetchAll();

        jsonResponse(['ok' => true, 'employee' => $emp]);

    // ── İzin Yönetimi ────────────────────────────────────────
    case 'hr.leaveTypes':
        $stmt = $pdo->query("SELECT * FROM uysa_leave_types ORDER BY name");
        jsonResponse(['ok' => true, 'types' => $stmt->fetchAll()]);

    case 'hr.leaveTypeSave':
        $id   = (int)($body['id'] ?? 0);
        $name = sanitizeInput($body['name'] ?? '', 100);
        $days = (int)($body['default_days'] ?? 14);
        $paid = (int)($body['is_paid'] ?? 1);
        $active = (int)($body['is_active'] ?? 1);
        if (!$name) jsonResponse(['ok' => false, 'error' => 'name gerekli'], 400);

        if ($id) {
            $pdo->prepare("UPDATE uysa_leave_types SET name=?, default_days=?, is_paid=?, is_active=? WHERE id=?")
                ->execute([$name, $days, $paid, $active, $id]);
        } else {
            $pdo->prepare("INSERT INTO uysa_leave_types (name, default_days, is_paid, is_active) VALUES (?,?,?,?)")
                ->execute([$name, $days, $paid, $active]);
            $id = (int)$pdo->lastInsertId();
        }
        jsonResponse(['ok' => true, 'id' => $id]);

    case 'hr.leaveRequests':
        $empId  = (int)($_GET['employee_id'] ?? 0);
        $status = sanitizeInput($_GET['status'] ?? '', 20);
        $limit  = min((int)($_GET['limit'] ?? 50), 500);

        $where = "1=1"; $params = [];
        if ($empId)  { $where .= " AND lr.employee_id = ?"; $params[] = $empId; }
        if ($status) { $where .= " AND lr.status = ?"; $params[] = $status; }

        $stmt = $pdo->prepare("SELECT lr.*, lt.name AS leave_type_name,
            CONCAT(e.first_name,' ',e.last_name) AS employee_name, e.employee_no
            FROM uysa_leave_requests lr
            LEFT JOIN uysa_leave_types lt ON lr.leave_type_id=lt.id
            LEFT JOIN uysa_employees e ON lr.employee_id=e.id
            WHERE {$where} ORDER BY lr.created_at DESC LIMIT {$limit}");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'requests' => $stmt->fetchAll()]);

    case 'hr.leaveRequestSave':
        $empId     = (int)($body['employee_id'] ?? 0);
        $typeId    = (int)($body['leave_type_id'] ?? 0);
        $startDate = sanitizeInput($body['start_date'] ?? '', 10);
        $endDate   = sanitizeInput($body['end_date'] ?? '', 10);
        $notes     = sanitizeInput($body['notes'] ?? '', 500);

        if (!$empId || !$typeId || !$startDate || !$endDate) {
            jsonResponse(['ok' => false, 'error' => 'employee_id, leave_type_id, start_date, end_date gerekli'], 400);
        }

        // İş günü hesapla (hafta sonu hariç)
        $days = 0;
        $current = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        while ($current <= $end) {
            $dow = (int)$current->format('N');
            if ($dow < 6) $days++; // Pazartesi-Cuma
            $current->modify('+1 day');
        }

        $pdo->prepare("INSERT INTO uysa_leave_requests (employee_id, leave_type_id, start_date, end_date, days, notes) VALUES (?,?,?,?,?,?)")
            ->execute([$empId, $typeId, $startDate, $endDate, $days, $notes]);
        auditLog($pdo, 'hr.leaveRequest', $actor, (string)$empId, json_encode(['days' => $days]), $clientIp);
        jsonResponse(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'days' => $days]);

    case 'hr.leaveApprove':
        $id     = (int)($body['id'] ?? 0);
        $status = sanitizeInput($body['status'] ?? '', 20);
        if (!$id || !in_array($status, ['approved', 'rejected'])) {
            jsonResponse(['ok' => false, 'error' => 'id ve status (approved/rejected) gerekli'], 400);
        }
        $pdo->prepare("UPDATE uysa_leave_requests SET status=?, approved_by=? WHERE id=?")
            ->execute([$status, $actor, $id]);
        auditLog($pdo, 'hr.leaveApprove', $actor, (string)$id, $status, $clientIp);
        jsonResponse(['ok' => true]);

    // ── Puantaj ──────────────────────────────────────────────
    case 'hr.attendance':
        $empId     = (int)($_GET['employee_id'] ?? 0);
        $startDate = sanitizeInput($_GET['start_date'] ?? '', 10);
        $endDate   = sanitizeInput($_GET['end_date'] ?? '', 10);
        $status    = sanitizeInput($_GET['status'] ?? '', 20);

        $where = "1=1"; $params = [];
        if ($empId)    { $where .= " AND a.employee_id = ?"; $params[] = $empId; }
        if ($startDate){ $where .= " AND a.date >= ?"; $params[] = $startDate; }
        if ($endDate)  { $where .= " AND a.date <= ?"; $params[] = $endDate; }
        if ($status)   { $where .= " AND a.status = ?"; $params[] = $status; }

        $stmt = $pdo->prepare("SELECT a.*, CONCAT(e.first_name,' ',e.last_name) AS employee_name, e.employee_no
            FROM uysa_attendance a LEFT JOIN uysa_employees e ON a.employee_id=e.id
            WHERE {$where} ORDER BY a.date DESC, e.first_name LIMIT 500");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'attendance' => $stmt->fetchAll()]);

    case 'hr.attendanceSave':
        $empId    = (int)($body['employee_id'] ?? 0);
        $date     = sanitizeInput($body['date'] ?? date('Y-m-d'), 10);
        $checkIn  = sanitizeInput($body['check_in'] ?? '', 8) ?: null;
        $checkOut = sanitizeInput($body['check_out'] ?? '', 8) ?: null;
        $status   = sanitizeInput($body['status'] ?? 'present', 20);
        $overtime = round((float)($body['overtime_hours'] ?? 0), 1);
        $notes    = sanitizeInput($body['notes'] ?? '', 300);

        if (!$empId) jsonResponse(['ok' => false, 'error' => 'employee_id gerekli'], 400);

        $pdo->prepare("INSERT INTO uysa_attendance (employee_id, date, check_in, check_out, status, overtime_hours, notes)
            VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE check_in=VALUES(check_in), check_out=VALUES(check_out),
            status=VALUES(status), overtime_hours=VALUES(overtime_hours), notes=VALUES(notes)")
            ->execute([$empId, $date, $checkIn, $checkOut, $status, $overtime, $notes]);
        jsonResponse(['ok' => true]);

    case 'hr.attendanceBulk':
        $date    = sanitizeInput($body['date'] ?? date('Y-m-d'), 10);
        $records = $body['records'] ?? [];
        if (empty($records)) jsonResponse(['ok' => false, 'error' => 'records gerekli'], 400);

        $stmt = $pdo->prepare("INSERT INTO uysa_attendance (employee_id, date, check_in, check_out, status, overtime_hours)
            VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE check_in=VALUES(check_in), check_out=VALUES(check_out),
            status=VALUES(status), overtime_hours=VALUES(overtime_hours)");

        $count = 0;
        foreach ($records as $r) {
            $stmt->execute([
                (int)$r['employee_id'], $date,
                sanitizeInput($r['check_in'] ?? '', 8) ?: null,
                sanitizeInput($r['check_out'] ?? '', 8) ?: null,
                sanitizeInput($r['status'] ?? 'present', 20),
                round((float)($r['overtime_hours'] ?? 0), 1),
            ]);
            $count++;
        }
        jsonResponse(['ok' => true, 'count' => $count]);

    // ── Vardiya ──────────────────────────────────────────────
    case 'hr.shifts':
        $stmt = $pdo->query("SELECT * FROM uysa_shifts ORDER BY start_time");
        jsonResponse(['ok' => true, 'shifts' => $stmt->fetchAll()]);

    case 'hr.shiftSave':
        $id    = (int)($body['id'] ?? 0);
        $name  = sanitizeInput($body['name'] ?? '', 50);
        $start = sanitizeInput($body['start_time'] ?? '', 8);
        $end   = sanitizeInput($body['end_time'] ?? '', 8);
        $brk   = (int)($body['break_minutes'] ?? 60);
        $active= (int)($body['is_active'] ?? 1);
        if (!$name || !$start || !$end) jsonResponse(['ok' => false, 'error' => 'name, start_time, end_time gerekli'], 400);

        if ($id) {
            $pdo->prepare("UPDATE uysa_shifts SET name=?, start_time=?, end_time=?, break_minutes=?, is_active=? WHERE id=?")
                ->execute([$name, $start, $end, $brk, $active, $id]);
        } else {
            $pdo->prepare("INSERT INTO uysa_shifts (name, start_time, end_time, break_minutes, is_active) VALUES (?,?,?,?,?)")
                ->execute([$name, $start, $end, $brk, $active]);
            $id = (int)$pdo->lastInsertId();
        }
        jsonResponse(['ok' => true, 'id' => $id]);

    case 'hr.shiftAssign':
        $empId     = (int)($body['employee_id'] ?? 0);
        $shiftId   = (int)($body['shift_id'] ?? 0);
        $startDate = sanitizeInput($body['start_date'] ?? '', 10);
        $endDate   = sanitizeInput($body['end_date'] ?? '', 10);
        if (!$empId || !$shiftId || !$startDate || !$endDate) {
            jsonResponse(['ok' => false, 'error' => 'employee_id, shift_id, start_date, end_date gerekli'], 400);
        }

        $stmt = $pdo->prepare("INSERT INTO uysa_shift_assignments (employee_id, shift_id, date, created_by)
            VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE shift_id=VALUES(shift_id), created_by=VALUES(created_by)");
        $current = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $count = 0;
        while ($current <= $end) {
            $stmt->execute([$empId, $shiftId, $current->format('Y-m-d'), $actor]);
            $current->modify('+1 day');
            $count++;
        }
        jsonResponse(['ok' => true, 'assigned_days' => $count]);

    case 'hr.shiftPlan':
        $startDate = sanitizeInput($_GET['start_date'] ?? date('Y-m-d'), 10);
        $endDate   = sanitizeInput($_GET['end_date'] ?? date('Y-m-d', strtotime('+7 days')), 10);
        $dept      = sanitizeInput($_GET['department'] ?? '', 100);

        $where = "sa.date BETWEEN ? AND ?"; $params = [$startDate, $endDate];
        if ($dept) { $where .= " AND e.department = ?"; $params[] = $dept; }

        $stmt = $pdo->prepare("SELECT sa.date, sa.employee_id, sa.shift_id,
            CONCAT(e.first_name,' ',e.last_name) AS employee_name, e.department,
            s.name AS shift_name, s.start_time, s.end_time
            FROM uysa_shift_assignments sa
            LEFT JOIN uysa_employees e ON sa.employee_id=e.id
            LEFT JOIN uysa_shifts s ON sa.shift_id=s.id
            WHERE {$where} ORDER BY sa.date, e.first_name");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'plan' => $stmt->fetchAll()]);

    // ── Bordro ───────────────────────────────────────────────
    case 'hr.payrollCalculate':
        $empId  = (int)($body['employee_id'] ?? 0);
        $year   = (int)($body['period_year'] ?? date('Y'));
        $month  = (int)($body['period_month'] ?? date('m'));
        $overtimeHours = round((float)($body['overtime_hours'] ?? 0), 1);
        $deductions    = round((float)($body['deductions'] ?? 0), 2);
        $bonuses       = round((float)($body['bonuses'] ?? 0), 2);

        if (!$empId) jsonResponse(['ok' => false, 'error' => 'employee_id gerekli'], 400);

        $emp = $pdo->prepare("SELECT * FROM uysa_employees WHERE id=?");
        $emp->execute([$empId]);
        $e = $emp->fetch();
        if (!$e) jsonResponse(['ok' => false, 'error' => 'Personel bulunamadı'], 404);

        $baseSalary = (float)$e['salary'];

        // Mesai ücreti hesapla
        $overtimePay = 0;
        if ($overtimeHours > 0 && $e['salary_type'] === 'monthly') {
            $overtimePay = round(($baseSalary / 225) * 1.5 * $overtimeHours, 2);
        }

        $gross = round($baseSalary + $overtimePay + $bonuses, 2);

        // SGK İşçi Payı: %14
        $sgkEmployee = round($gross * 0.14, 2);
        // SGK İşveren Payı: %20.5
        $sgkEmployer = round($gross * 0.205, 2);
        // Gelir Vergisi Matrahı
        $taxBase = round($gross - $sgkEmployee, 2);
        // Gelir Vergisi: %15 (basitleştirilmiş ilk dilim)
        $incomeTax = round($taxBase * 0.15, 2);
        // Damga Vergisi: %0.759
        $stampTax = round($gross * 0.00759, 2);
        // Net
        $net = round($gross - $sgkEmployee - $incomeTax - $stampTax - $deductions, 2);

        $pdo->prepare("INSERT INTO uysa_payroll (employee_id, period_year, period_month, gross_salary,
            sgk_employee, sgk_employer, income_tax, stamp_tax, net_salary, overtime_pay, deductions, bonuses)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE gross_salary=VALUES(gross_salary), sgk_employee=VALUES(sgk_employee),
            sgk_employer=VALUES(sgk_employer), income_tax=VALUES(income_tax), stamp_tax=VALUES(stamp_tax),
            net_salary=VALUES(net_salary), overtime_pay=VALUES(overtime_pay), deductions=VALUES(deductions), bonuses=VALUES(bonuses)")
            ->execute([$empId, $year, $month, $gross, $sgkEmployee, $sgkEmployer, $incomeTax, $stampTax, $net, $overtimePay, $deductions, $bonuses]);

        auditLog($pdo, 'hr.payrollCalc', $actor, "{$e['first_name']} {$e['last_name']}", json_encode(['net' => $net]), $clientIp);
        jsonResponse(['ok' => true, 'payroll' => [
            'employee_id' => $empId, 'period' => "{$year}-{$month}",
            'gross_salary' => $gross, 'sgk_employee' => $sgkEmployee, 'sgk_employer' => $sgkEmployer,
            'income_tax' => $incomeTax, 'stamp_tax' => $stampTax, 'overtime_pay' => $overtimePay,
            'deductions' => $deductions, 'bonuses' => $bonuses, 'net_salary' => $net,
        ]]);

    case 'hr.payrollList':
        $year   = (int)($_GET['period_year'] ?? 0);
        $month  = (int)($_GET['period_month'] ?? 0);
        $status = sanitizeInput($_GET['status'] ?? '', 20);
        $empId  = (int)($_GET['employee_id'] ?? 0);

        $where = "1=1"; $params = [];
        if ($year)  { $where .= " AND pr.period_year = ?"; $params[] = $year; }
        if ($month) { $where .= " AND pr.period_month = ?"; $params[] = $month; }
        if ($status){ $where .= " AND pr.status = ?"; $params[] = $status; }
        if ($empId) { $where .= " AND pr.employee_id = ?"; $params[] = $empId; }

        $stmt = $pdo->prepare("SELECT pr.*, CONCAT(e.first_name,' ',e.last_name) AS employee_name, e.employee_no, e.department
            FROM uysa_payroll pr LEFT JOIN uysa_employees e ON pr.employee_id=e.id
            WHERE {$where} ORDER BY pr.period_year DESC, pr.period_month DESC, e.first_name");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'payroll' => $stmt->fetchAll()]);

    case 'hr.payrollApprove':
        $id     = (int)($body['id'] ?? 0);
        $status = sanitizeInput($body['status'] ?? '', 20);
        if (!$id || !in_array($status, ['approved', 'paid'])) {
            jsonResponse(['ok' => false, 'error' => 'id ve status (approved/paid) gerekli'], 400);
        }
        $pdo->prepare("UPDATE uysa_payroll SET status=? WHERE id=?")->execute([$status, $id]);
        jsonResponse(['ok' => true]);

    case 'hr.payrollBulk':
        $year  = (int)($body['period_year'] ?? date('Y'));
        $month = (int)($body['period_month'] ?? date('m'));

        $employees = $pdo->query("SELECT * FROM uysa_employees WHERE status='active'")->fetchAll();
        $count = 0;

        foreach ($employees as $e) {
            $gross = (float)$e['salary'];
            $sgkEmp = round($gross * 0.14, 2);
            $sgkEmpr = round($gross * 0.205, 2);
            $taxBase = round($gross - $sgkEmp, 2);
            $incomeTax = round($taxBase * 0.15, 2);
            $stampTax = round($gross * 0.00759, 2);
            $net = round($gross - $sgkEmp - $incomeTax - $stampTax, 2);

            $pdo->prepare("INSERT INTO uysa_payroll (employee_id, period_year, period_month, gross_salary,
                sgk_employee, sgk_employer, income_tax, stamp_tax, net_salary) VALUES (?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE gross_salary=VALUES(gross_salary), sgk_employee=VALUES(sgk_employee),
                sgk_employer=VALUES(sgk_employer), income_tax=VALUES(income_tax), stamp_tax=VALUES(stamp_tax), net_salary=VALUES(net_salary)")
                ->execute([$e['id'], $year, $month, $gross, $sgkEmp, $sgkEmpr, $incomeTax, $stampTax, $net]);
            $count++;
        }
        jsonResponse(['ok' => true, 'calculated' => $count, 'period' => "{$year}-{$month}"]);

    // ── Performans ───────────────────────────────────────────
    case 'hr.performanceReviews':
        $empId  = (int)($_GET['employee_id'] ?? 0);
        $period = sanitizeInput($_GET['period'] ?? '', 20);

        $where = "1=1"; $params = [];
        if ($empId)  { $where .= " AND pr.employee_id = ?"; $params[] = $empId; }
        if ($period) { $where .= " AND pr.period = ?"; $params[] = $period; }

        $stmt = $pdo->prepare("SELECT pr.*, CONCAT(e.first_name,' ',e.last_name) AS employee_name,
            CONCAT(r.first_name,' ',r.last_name) AS reviewer_name
            FROM uysa_performance_reviews pr
            LEFT JOIN uysa_employees e ON pr.employee_id=e.id
            LEFT JOIN uysa_employees r ON pr.reviewer_id=r.id
            WHERE {$where} ORDER BY pr.created_at DESC");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'reviews' => $stmt->fetchAll()]);

    case 'hr.performanceReviewSave':
        $id       = (int)($body['id'] ?? 0);
        $empId    = (int)($body['employee_id'] ?? 0);
        $revId    = $body['reviewer_id'] ? (int)$body['reviewer_id'] : null;
        $period   = sanitizeInput($body['period'] ?? '', 20);
        $score    = max(0, min(100, (int)($body['score'] ?? 0)));
        $strengths    = sanitizeInput($body['strengths'] ?? '', 5000);
        $improvements = sanitizeInput($body['improvements'] ?? '', 5000);
        $goals        = sanitizeInput($body['goals'] ?? '', 5000);

        if (!$empId || !$period) jsonResponse(['ok' => false, 'error' => 'employee_id ve period gerekli'], 400);

        if ($id) {
            $pdo->prepare("UPDATE uysa_performance_reviews SET employee_id=?, reviewer_id=?, period=?, score=?, strengths=?, improvements=?, goals=? WHERE id=?")
                ->execute([$empId, $revId, $period, $score, $strengths, $improvements, $goals, $id]);
        } else {
            $pdo->prepare("INSERT INTO uysa_performance_reviews (employee_id, reviewer_id, period, score, strengths, improvements, goals) VALUES (?,?,?,?,?,?,?)")
                ->execute([$empId, $revId, $period, $score, $strengths, $improvements, $goals]);
            $id = (int)$pdo->lastInsertId();
        }
        jsonResponse(['ok' => true, 'id' => $id]);

    // ── Eğitim ───────────────────────────────────────────────
    case 'hr.trainings':
        $category = sanitizeInput($_GET['category'] ?? '', 100);
        $where = "1=1"; $params = [];
        if ($category) { $where .= " AND category = ?"; $params[] = $category; }
        $stmt = $pdo->prepare("SELECT t.*,
            (SELECT COUNT(*) FROM uysa_training_participants WHERE training_id=t.id) AS participant_count
            FROM uysa_trainings t WHERE {$where} ORDER BY t.date DESC");
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'trainings' => $stmt->fetchAll()]);

    case 'hr.trainingSave':
        $id       = (int)($body['id'] ?? 0);
        $title    = sanitizeInput($body['title'] ?? '', 200);
        $desc     = sanitizeInput($body['description'] ?? '', 5000);
        $trainer  = sanitizeInput($body['trainer'] ?? '', 100);
        $date     = sanitizeInput($body['date'] ?? date('Y-m-d'), 10);
        $duration = round((float)($body['duration_hours'] ?? 1), 1);
        $category = sanitizeInput($body['category'] ?? '', 100);

        if (!$title) jsonResponse(['ok' => false, 'error' => 'title gerekli'], 400);

        if ($id) {
            $pdo->prepare("UPDATE uysa_trainings SET title=?, description=?, trainer=?, date=?, duration_hours=?, category=? WHERE id=?")
                ->execute([$title, $desc, $trainer, $date, $duration, $category, $id]);
        } else {
            $pdo->prepare("INSERT INTO uysa_trainings (title, description, trainer, date, duration_hours, category) VALUES (?,?,?,?,?,?)")
                ->execute([$title, $desc, $trainer, $date, $duration, $category]);
            $id = (int)$pdo->lastInsertId();
        }
        jsonResponse(['ok' => true, 'id' => $id]);

    case 'hr.trainingParticipants':
        $trainingId = (int)($_GET['training_id'] ?? 0);
        if (!$trainingId) jsonResponse(['ok' => false, 'error' => 'training_id gerekli'], 400);
        $stmt = $pdo->prepare("SELECT tp.*, CONCAT(e.first_name,' ',e.last_name) AS employee_name, e.department
            FROM uysa_training_participants tp LEFT JOIN uysa_employees e ON tp.employee_id=e.id WHERE tp.training_id=?");
        $stmt->execute([$trainingId]);
        jsonResponse(['ok' => true, 'participants' => $stmt->fetchAll()]);

    case 'hr.trainingParticipantSave':
        $trainingId = (int)($body['training_id'] ?? 0);
        $empId      = (int)($body['employee_id'] ?? 0);
        $status     = sanitizeInput($body['status'] ?? 'registered', 20);
        $score      = $body['score'] !== null ? (int)$body['score'] : null;

        if (!$trainingId || !$empId) jsonResponse(['ok' => false, 'error' => 'training_id ve employee_id gerekli'], 400);

        $pdo->prepare("INSERT INTO uysa_training_participants (training_id, employee_id, status, score)
            VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status), score=VALUES(score)")
            ->execute([$trainingId, $empId, $status, $score]);
        jsonResponse(['ok' => true]);

    // ── Organizasyon Şeması ──────────────────────────────────
    case 'hr.orgChart':
        $stmt = $pdo->query("SELECT id, employee_no, first_name, last_name, department, position, manager_id, status
            FROM uysa_employees WHERE status='active' ORDER BY first_name");
        $all = $stmt->fetchAll();

        // Ağaç yapısı oluştur
        $tree = [];
        $map = [];
        foreach ($all as &$e) { $e['children'] = []; $map[$e['id']] = &$e; }
        unset($e);
        foreach ($map as &$e) {
            if ($e['manager_id'] && isset($map[$e['manager_id']])) {
                $map[$e['manager_id']]['children'][] = &$e;
            } else {
                $tree[] = &$e;
            }
        }
        jsonResponse(['ok' => true, 'org_chart' => $tree]);

    // ── Dashboard ────────────────────────────────────────────
    case 'hr.dashboard':
        $d = [];
        try {
            $d['total_employees']  = (int)$pdo->query("SELECT COUNT(*) FROM uysa_employees")->fetchColumn();
            $d['active_count']     = (int)$pdo->query("SELECT COUNT(*) FROM uysa_employees WHERE status='active'")->fetchColumn();
            $d['avg_salary']       = (float)$pdo->query("SELECT COALESCE(AVG(salary),0) FROM uysa_employees WHERE status='active'")->fetchColumn();
            $d['pending_leaves']   = (int)$pdo->query("SELECT COUNT(*) FROM uysa_leave_requests WHERE status='pending'")->fetchColumn();
            $d['absent_today']     = (int)$pdo->query("SELECT COUNT(*) FROM uysa_attendance WHERE date=CURDATE() AND status='absent'")->fetchColumn();
            $d['departments']      = $pdo->query("SELECT department, COUNT(*) as count FROM uysa_employees WHERE status='active' AND department IS NOT NULL GROUP BY department ORDER BY count DESC")->fetchAll();
            $d['avg_performance']  = (float)$pdo->query("SELECT COALESCE(AVG(score),0) FROM uysa_performance_reviews")->fetchColumn();
        } catch (\Throwable) {}
        jsonResponse(['ok' => true, 'dashboard' => $d]);

    default:
        jsonResponse(['ok' => false, 'error' => "Bilinmeyen İK action: {$action}"], 404);
    }
}
