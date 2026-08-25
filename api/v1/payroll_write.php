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
        $result = $service->activateEmployeeFinanceForExpense($id, $actorId);
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
if ($resource === 'payroll-runs') {

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
            $result = $service->createRun($month, $createdBy, $payDay);
            ApiAuth::success(['data' => $result]);
        } catch (\InvalidArgumentException $e) {
            ApiAuth::fail(400, $e->getMessage());
        } catch (\RuntimeException $e) {
            tpHrLogException($e, 'payroll_write createRun');
            ApiAuth::fail(409, $e->getMessage());
        } catch (\Throwable $e) {
            tpHrLogException($e, 'payroll_write createRun');
            ApiAuth::fail(500, 'Internal server error');
        }
    }

    // POST /payroll-runs/{id}/approve
    if ($method === 'POST' && $id > 0 && $sub === 'approve') {
        ApiAuth::require(['payroll.approve']);
        apiKeyForbidServiceScoped();
        $input = ApiAuth::input();
        $approvedBy = apiKeyResolveActorForApi($pdo, ApiAuth::currentKey(), $input, 'approved_by', CEO_ROLES);
        $service->approveRun($id, $approvedBy);
        ApiAuth::success(['message' => 'อนุมัติรอบเงินเดือนแล้ว']);
    }

    // POST /payroll-runs/{id}/paid
    if ($method === 'POST' && $id > 0 && $sub === 'paid') {
        ApiAuth::require(['payroll.approve']);
        try {
            $service->markPaid($id);
            ApiAuth::success(['message' => 'บันทึกจ่ายเงินแล้ว']);
        } catch (\RuntimeException $e) {
            tpHrLogException($e, 'payroll_write markPaid');
            ApiAuth::fail(409, $e->getMessage());
        } catch (\Throwable $e) {
            tpHrLogException($e, 'payroll_write markPaid');
            ApiAuth::fail(500, 'Internal server error');
        }
    }

    // POST /payroll-runs/{id}/cancel-paid
    if ($method === 'POST' && $id > 0 && $sub === 'cancel-paid') {
        ApiAuth::require(['payroll.approve']);
        try {
            $service->cancelPaid($id);
            ApiAuth::success(['message' => 'ยกเลิกการบันทึกจ่ายแล้ว']);
        } catch (\RuntimeException $e) {
            tpHrLogException($e, 'payroll_write cancelPaid');
            ApiAuth::fail(409, $e->getMessage());
        } catch (\Throwable $e) {
            tpHrLogException($e, 'payroll_write cancelPaid');
            ApiAuth::fail(500, 'Internal server error');
        }
    }

    // POST /payroll-runs/{id}/cancel-approval
    if ($method === 'POST' && $id > 0 && $sub === 'cancel-approval') {
        ApiAuth::require(['payroll.approve']);
        try {
            $service->cancelApproval($id);
            ApiAuth::success(['message' => 'ยกเลิกการอนุมัติแล้ว']);
        } catch (\RuntimeException $e) {
            tpHrLogException($e, 'payroll_write cancelApproval');
            ApiAuth::fail(409, $e->getMessage());
        } catch (\Throwable $e) {
            tpHrLogException($e, 'payroll_write cancelApproval');
            ApiAuth::fail(500, 'Internal server error');
        }
    }

    // POST /payroll-runs/{id}/recalculate-slip
    if ($method === 'POST' && $id > 0 && $sub === 'recalculate-slip') {
        ApiAuth::require(['payroll.write']);
        apiKeyForbidServiceScoped();
        $input = ApiAuth::input();
        $userId = (int)($input['user_id'] ?? 0);
        $run = $service->getRun($id);
        if (!$run) ApiAuth::fail(404, 'Run not found');

        $updated = $service->recalculateSlip($id, $userId, $run['payroll_month']);
        if ($updated) $service->updateRunTotals($id);
        ApiAuth::success(['updated' => $updated]);
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
