<?php
/**
 * Payroll write endpoints (Phase 6.1.2)
 *
 *   POST /api/v1/payroll-runs                scope: payroll.write
 *        body: { "month": "YYYY-MM", "created_by"? } — actor = ผู้ออกคีย์ (HR/Admin/Chairman/CEO) หรือส่ง created_by (คีย์เก่า)
 *
 *   POST /api/v1/payroll-runs/{id}/approve   scope: payroll.approve
 *        body: { "approved_by"? } — ผูกกับผู้ออก API key (CEO+)
 *
 *   POST /api/v1/payroll-runs/{id}/paid      scope: payroll.approve
 *
 *   POST /api/v1/payroll-runs/{id}/recalculate-slip   scope: payroll.write
 *        body: { "user_id": 5 }
 *
 *   GET  /api/v1/payroll-runs/{id}/calculate-preview  scope: payroll.read (+ payroll.read_all if key has no service_user_id)
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
            $result = $service->createRun($month, $createdBy);
            ApiAuth::success(['data' => $result]);
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
        $result = $service->saveSalarySetup($userId, $input);

        // Auto-recalculate open runs
        $effMonth = substr($input['effective_from'] ?? '', 0, 7) . '-01';
        if ($effMonth !== '-01') {
            $rr = $pdo->prepare("SELECT id, payroll_month FROM payroll_runs WHERE status IN ('draft','calculated') AND payroll_month >= ?");
            $rr->execute([$effMonth]);
            while ($ar = $rr->fetch(PDO::FETCH_ASSOC)) {
                $service->recalculateSlip((int)$ar['id'], $userId, $ar['payroll_month']);
                $service->updateRunTotals((int)$ar['id']);
            }
        }
        ApiAuth::success(['data' => $result]);
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

ApiAuth::fail(404, 'Endpoint not found');
