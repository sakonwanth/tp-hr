<?php
/**
 * Payroll endpoints (read-only)
 *
 *   GET /api/v1/payroll-runs                      scope: payroll.read
 *   GET /api/v1/payroll-runs/{id}                 scope: payroll.read
 *   GET /api/v1/payroll-runs/{id}/slips           scope: payroll.read
 *   GET /api/v1/payslips?month=YYYY-MM[&user_id=] scope: payroll.read
 *   GET /api/v1/payslips/{id}                     scope: payroll.read
 *
 * Only returns runs/slips with status IN ('approved', 'paid').
 *
 * Keys without service_user_id need payroll.read_all (or *) for any bulk or by-id access.
 */
ApiAuth::require(['payroll.read']);
ApiAuth::requireMethod(['GET']);

$pdo = getDB();
$key = ApiAuth::currentKey();
$svc = apiKeyServiceUserId($key);
$payrollUnscopedForbidden = ($svc === null && !apiKeyMayAccessFullPayroll($key));
$resource = $segments[0] ?? '';
$id = isset($segments[1]) ? (int)$segments[1] : 0;
$sub = $segments[2] ?? '';

$slipSelect = "
    SELECT s.id, s.payroll_run_id, s.user_id, u.employee_code, u.first_name_th, u.last_name_th,
           s.gross_salary, s.bonus, s.allowances, s.total_income,
           s.tax_withheld, s.provident_fund, s.social_security, s.group_insurance, s.health_insurance,
           s.total_deductions, s.net_salary,
           s.absent_days, s.late_count_30, s.late_count_60,
           s.absence_deduction, s.lateness_deduction,
           r.payroll_month, r.status AS run_status, r.approved_at
    FROM payroll_slips s
    JOIN payroll_runs r ON r.id = s.payroll_run_id
    JOIN users u ON u.id = s.user_id
    WHERE r.status IN ('approved','paid')
      AND " . tp_hr_non_system_user_condition_sql('u') . "
";

if ($resource === 'payroll-runs') {
    if ($id > 0 && $sub === 'slips') {
        if ($payrollUnscopedForbidden) {
            ApiAuth::fail(403, 'Payroll run slips require payroll.read_all (or *) or a service user bound to the API key');
        }
        $sql = $slipSelect . " AND r.id = ?";
        $params = [$id];
        if ($svc !== null) {
            $sql .= " AND s.user_id = ?";
            $params[] = $svc;
        }
        $stmt = $pdo->prepare($sql . " ORDER BY u.employee_code ASC");
        $stmt->execute($params);
        ApiAuth::success(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($id > 0) {
        if ($payrollUnscopedForbidden) {
            ApiAuth::fail(403, 'Payroll run detail requires payroll.read_all (or *) or a service user bound to the API key');
        }
        if ($svc !== null) {
            $stmt = $pdo->prepare("
                SELECT id, payroll_month, pay_day, status, total_gross, total_tax, total_net,
                       employee_count, approved_at, created_at
                FROM payroll_runs
                WHERE id = ? AND status IN ('approved','paid')
                  AND EXISTS (SELECT 1 FROM payroll_slips s WHERE s.payroll_run_id = payroll_runs.id AND s.user_id = ?)
                LIMIT 1
            ");
            $stmt->execute([$id, $svc]);
        } else {
            $stmt = $pdo->prepare("
                SELECT id, payroll_month, pay_day, status, total_gross, total_tax, total_net,
                       employee_count, approved_at, created_at
                FROM payroll_runs
                WHERE id = ? AND status IN ('approved','paid')
                LIMIT 1
            ");
            $stmt->execute([$id]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) ApiAuth::fail(404, 'Payroll run not found');
        ApiAuth::success(['data' => $row]);
    }
    if ($svc !== null) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT r.id, r.payroll_month, r.pay_day, r.status, r.total_gross, r.total_tax, r.total_net,
                   r.employee_count, r.approved_at, r.created_at
            FROM payroll_runs r
            INNER JOIN payroll_slips s ON s.payroll_run_id = r.id AND s.user_id = ?
            WHERE r.status IN ('approved','paid')
            ORDER BY r.payroll_month DESC
            LIMIT 120
        ");
        $stmt->execute([$svc]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        if ($payrollUnscopedForbidden) {
            ApiAuth::fail(403, 'Listing payroll runs requires payroll.read_all (or *) or a service user bound to the API key');
        }
        $rows = $pdo->query("
            SELECT id, payroll_month, pay_day, status, total_gross, total_tax, total_net,
                   employee_count, approved_at, created_at
            FROM payroll_runs
            WHERE status IN ('approved','paid')
            ORDER BY payroll_month DESC
            LIMIT 120
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
    ApiAuth::success(['data' => $rows]);
}

if ($resource === 'payslips') {
    if ($id > 0) {
        $stmt = $pdo->prepare($slipSelect . " AND s.id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) ApiAuth::fail(404, 'Payslip not found');
        if ($svc !== null) {
            apiKeyAssertResourceOwnerUserId($key, (int) $row['user_id']);
        } elseif ($payrollUnscopedForbidden) {
            ApiAuth::fail(403, 'Reading payslips by id requires payroll.read_all (or *) or a service user bound to the API key');
        }
        ApiAuth::success(['data' => $row]);
    }
    if ($payrollUnscopedForbidden) {
        ApiAuth::fail(403, 'Listing payslips requires payroll.read_all (or *) or a service user bound to the API key');
    }
    $month  = trim($_GET['month'] ?? '');
    $userId = apiKeyResolveScopedUserId($key, isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0);

    $extra = '';
    $params = [];
    if ($month !== '') {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) ApiAuth::fail(400, 'Invalid month (YYYY-MM)');
        $extra .= " AND DATE_FORMAT(r.payroll_month, '%Y-%m') = ?";
        $params[] = $month;
    }
    if ($userId > 0) {
        $extra .= " AND s.user_id = ?";
        $params[] = $userId;
    }
    $stmt = $pdo->prepare($slipSelect . $extra . " ORDER BY r.payroll_month DESC, u.employee_code ASC LIMIT 2000");
    $stmt->execute($params);
    ApiAuth::success(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

ApiAuth::fail(404, 'Endpoint not found');
