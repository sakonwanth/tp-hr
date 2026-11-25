<?php
/**
 * Payroll write endpoints (Phase 6.1.2)
 *
 *   POST /api/v1/payroll-runs                scope: payroll.write
 *        body: { "month": "YYYY-MM", "created_by"? } — integration key (ไม่ผูกพนักงาน): created_by = ผู้ใช้ CRM ที่ล็อกอิน; คีย์ผูกพนักงาน: ต้องตรงผู้ออกคีย์
 *
 *   POST /api/v1/payroll-runs/{id}/approve   scope: payroll.approve
 *        body: { "approved_by"? } — ผูกกับผู้ออก API key (CEO+)
 *
 *   POST /api/v1/payroll-runs/{id}/paid      scope: payroll.approve
 *
 *   POST /api/v1/payroll-runs/{id}/cancel-paid      scope: payroll.approve
 *
 *   POST /api/v1/payroll-runs/{id}/cancel-approval  scope: payroll.approve
 *
 *   POST /api/v1/payroll-runs/{id}/recalculate-slip   scope: payroll.write
 *        ?user_id=5&month=YYYY-MM
 *
 *   POST /api/v1/salary-setup                scope: payroll.write
 *        body: { "user_id": 5, "effective_from": "2026-05-01", ... }
 *
 *   GET  /api/v1/salary-setup/{user_id}      scope: payroll.read (+ payroll.read_all if key has no service_user_id)
 *        ?month=YYYY-MM
 */

require_once BASE_PATH . '/core/Services/PayrollService.php';

$pdo = getDB();
$service = new PayrollService($pdo);

$resource = $segments[0] ?? '';
$id = isset($segments[1]) ? (int)$segments[1] : 0;
$sub = $segments[2] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// POST /employee-finance/{expense_request_id}/activate-after-disbursement
if ($resource === 'employee-finance' && $method === 'POST' && $id > 0
    && $sub === 'activate-after-disbursement') {
    ApiAuth::require(['payroll.write']);
    apiKeyForbidServiceScoped();
    $input = ApiAuth::input();
    try {
        $actorId = apiKeyResolveActorForApi($pdo, ApiAuth::currentKey(), $input, 'actor_id', HR_ROLES);
        $result = $service->activateEmployeeFinanceForExpense(
            $id,
            $actorId,
            (string)($input['payment_method'] ?? '')
        );
        ApiAuth::success(['data' => $result]);
    } catch (\InvalidArgumentException $e) {
        ApiAuth::fail(400, $e->getMessage());
    } catch (\RuntimeException $e) {
        tpHrLogException($e, 'payroll_write activateEmployeeFinanceForExpense');
        ApiAuth::fail(409, $e->getMessage());
    } catch (\Throwable $e) {
        tpHrLogException($e, 'payroll_write activateEmployeeFinanceForExpense');
        ApiAuth::fail(500, 'Internal server error');
    }
}

// ── payroll-runs ──
// Run lifecycle goes through PayrollUiService — the same transactional path as the web
// payroll page — so the API cannot skip status guards, the ERP ledger entry, or the
// LINE-link / affordability-override revocation that the web flow performs.
if ($resource === 'payroll-runs') {
    require_once BASE_PATH . '/core/LegacyHrUi.php';
    $runApi = payroll_service();

    /** Map a lifecycle failure onto the API envelope (never returns). */
    $failRunAction = static function (\Throwable $e, string $context): void {
        tpHrLogException($e, 'payroll_write ' . $context);
        if ($e instanceof \InvalidArgumentException) {
            ApiAuth::fail(400, $e->getMessage());
        }
        if ($e instanceof \RuntimeException) {
            ApiAuth::fail(409, $e->getMessage());
        }
        ApiAuth::fail(500, 'Internal server error');
    };

    // POST /payroll-runs → create/recalculate run
    if ($method === 'POST' && $id === 0) {
        ApiAuth::require(['payroll.write']);
        apiKeyForbidServiceScoped('Employee-scoped keys cannot create or modify payroll runs');
        $input = ApiAuth::input();
        $month = $input['month'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            ApiAuth::fail(400, 'Invalid month format (YYYY-MM)');
        }
        try {
            $createdBy = apiKeyResolveActorForApi($pdo, ApiAuth::currentKey(), $input, 'created_by', HR_ROLES);
            $payDay = isset($input['pay_day']) ? (int)$input['pay_day'] : null;
            $result = $runApi->createRun($month, $createdBy, $payDay);
            ApiAuth::success(['data' => $result]);
        } catch (\Throwable $e) {
            $failRunAction($e, 'createRun');
        }
    }

    // POST /payroll-runs/{id}/approve
    if ($method === 'POST' && $id > 0 && $sub === 'approve') {
        ApiAuth::require(['payroll.approve']);
        apiKeyForbidServiceScoped();
        $input = ApiAuth::input();
        try {
            $approvedBy = apiKeyResolveActorForApi($pdo, ApiAuth::currentKey(), $input, 'approved_by', CEO_ROLES);
            $runApi->approveRun($id, $approvedBy);
            ApiAuth::success(['message' => 'อนุมัติรอบเงินเดือนแล้ว']);
        } catch (\Throwable $e) {
            $failRunAction($e, 'approveRun');
        }
    }

    // POST /payroll-runs/{id}/paid
    if ($method === 'POST' && $id > 0 && $sub === 'paid') {
        ApiAuth::require(['payroll.approve']);
        apiKeyForbidServiceScoped();
        try {
            $runApi->markPaid($id);
            ApiAuth::success(['message' => 'บันทึกจ่ายเงินแล้ว', 'ledger_pending' => false]);
        } catch (\PayrollLedgerPendingException $e) {
            // The run IS paid; only the ERP ledger row is outstanding (retry from the run page).
            tpHrLogException($e, 'payroll_write markPaid ledger');
            ApiAuth::success(['message' => 'บันทึกจ่ายเงินแล้ว', 'ledger_pending' => true, 'warning' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $failRunAction($e, 'markPaid');
        }
    }

    // POST /payroll-runs/{id}/cancel-paid
    if ($method === 'POST' && $id > 0 && $sub === 'cancel-paid') {
        ApiAuth::require(['payroll.approve']);
        apiKeyForbidServiceScoped();
        try {
            $runApi->cancelPaid($id);
            ApiAuth::success(['message' => 'ยกเลิกการบันทึกจ่ายแล้ว', 'ledger_pending' => false]);
        } catch (\PayrollLedgerPendingException $e) {
            tpHrLogException($e, 'payroll_write cancelPaid ledger');
            ApiAuth::success(['message' => 'ยกเลิกการบันทึกจ่ายแล้ว', 'ledger_pending' => true, 'warning' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $failRunAction($e, 'cancelPaid');
        }
    }

    // POST /payroll-runs/{id}/cancel-approval
    if ($method === 'POST' && $id > 0 && $sub === 'cancel-approval') {
        ApiAuth::require(['payroll.approve']);
        apiKeyForbidServiceScoped();
        try {
            $runApi->cancelApproval($id);
            ApiAuth::success(['message' => 'ยกเลิกการอนุมัติแล้ว']);
        } catch (\Throwable $e) {
            $failRunAction($e, 'cancelApproval');
        }
    }

    // POST /payroll-runs/{id}/recalculate-slip
    if ($method === 'POST' && $id > 0 && $sub === 'recalculate-slip') {
        ApiAuth::require(['payroll.write']);
        apiKeyForbidServiceScoped();
        $input = ApiAuth::input();
        $userId = (int)($input['user_id'] ?? 0);
        $run = $runApi->getRun($id);
        if (!$run) ApiAuth::fail(404, 'Run not found');
        // Approved/paid slips are what employees were (or will be) paid — they only change
        // after an explicit cancel-approval, exactly like the web page.
        if (!in_array((string)$run['status'], ['draft', 'calculated'], true)) {
            ApiAuth::fail(409, 'คำนวณสลิปใหม่ได้เฉพาะรอบที่ยังไม่อนุมัติ (สถานะปัจจุบัน: ' . $run['status'] . ')');
        }
        $eligible = $pdo->prepare('SELECT 1 FROM users u WHERE u.id = ? AND u.is_active = 1 AND ' . tp_hr_payroll_employee_filter_sql('u'));
        $eligible->execute([$userId]);
        if ($userId <= 0 || !$eligible->fetchColumn()) {
            ApiAuth::fail(400, 'user_id is not an active payroll employee');
        }
        try {
            $updated = $runApi->recalculateSlip($id, $userId, (string)$run['payroll_month']);
            ApiAuth::success(['updated' => $updated]);
        } catch (\Throwable $e) {
            $failRunAction($e, 'recalculateSlip');
        }
    }

    // GET /payroll-runs/{id}/calculate-preview
    if ($method === 'GET' && $id > 0 && $sub === 'calculate-preview') {
        ApiAuth::require(['payroll.read']);
        $key = ApiAuth::currentKey();
        apiKeyRequireServiceUserOrReadAllScope(
            $key,
            'payroll.read_all',
            'calculate-preview requires payroll.read_all (or *) or a service user bound to the API key'
        );
        $userId = apiKeyResolveScopedUserId($key, (int)($_GET['user_id'] ?? 0));
        $month = $_GET['month'] ?? '';
        if (!$userId || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            ApiAuth::fail(400, 'user_id and month (YYYY-MM) required');
        }
        $slip = $service->calculateSlip($userId, $month . '-01');
        ApiAuth::success(['data' => $slip]);
    }
}

// ── salary-setup ──
if ($resource === 'salary-setup') {

    // POST /salary-setup
    if ($method === 'POST') {
        ApiAuth::require(['payroll.write']);
        apiKeyForbidServiceScoped();
        $input = ApiAuth::input();
        $userId = (int)($input['user_id'] ?? 0);
        if ($userId <= 0) ApiAuth::fail(400, 'user_id required');
        try {
            $result = $service->saveSalarySetup($userId, $input);
            ApiAuth::success(['data' => $result]);
        } catch (\InvalidArgumentException $e) {
            ApiAuth::fail(400, $e->getMessage());
        } catch (\RuntimeException $e) {
            ApiAuth::fail(400, $e->getMessage());
        } catch (\Throwable $e) {
            tpHrLogException($e, 'api/v1/salary-setup');
            ApiAuth::fail(500, 'Internal server error');
        }
    }

    // GET /salary-setup/{user_id}
    if ($method === 'GET' && $id > 0) {
        ApiAuth::require(['payroll.read']);
        $key = ApiAuth::currentKey();
        if (apiKeyServiceUserId($key) !== null) {
            apiKeyAssertResourceOwnerUserId($key, $id);
        } elseif (!apiKeyHasReadAllScope($key, 'payroll.read_all')) {
            ApiAuth::fail(403, 'Salary setup requires payroll.read_all (or *) or a service user bound to the API key');
        }
        $month = ($_GET['month'] ?? date('Y-m')) . '-01';
        $setup = $service->getSalarySetup($id, $month);
        ApiAuth::success(['data' => $setup]);
    }
}

// ── payroll-preview (draft calc — no run id required) ──
if ($resource === 'payroll-preview' && $method === 'POST') {
    ApiAuth::require(['payroll.read']);
    apiKeyForbidServiceScoped('Employee-scoped keys cannot preview payroll for other users');
    $input = ApiAuth::input();
    $userId = (int)($input['user_id'] ?? 0);
    $month = trim((string)($input['month'] ?? ''));
    if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) {
        ApiAuth::fail(400, 'user_id and month (YYYY-MM) required');
    }
    $payDay = isset($input['pay_day']) ? (int)$input['pay_day'] : null;
    $override = is_array($input['setup_override'] ?? null) ? $input['setup_override'] : null;
    $slip = $service->calculateSlip($userId, $month . '-01', $payDay, $override);
    ApiAuth::success(['data' => $slip]);
}

ApiAuth::fail(404, 'Endpoint not found');
