<?php
$service = file_get_contents(__DIR__ . '/../core/Services/PayrollService.php');
$router = file_get_contents(__DIR__ . '/../api/v1/index.php');
$api = file_get_contents(__DIR__ . '/../api/v1/payroll_write.php');
$checks = [
    'canonical activation method exists' => str_contains($service, 'activateEmployeeFinanceForExpense'),
    'paid payroll run is blocked' => str_contains($service, '(string)$run[\'status\'] === \'paid\''),
    'approved run is reopened before recalculation' => str_contains($service, "status='calculated',approved_by=NULL,approved_at=NULL"),
    'loan and advance activation are supported' => str_contains($service, "hr_employee_loans SET status='active'") && str_contains($service, "hr_salary_advances SET status='pending_deduction'"),
    'open payroll slip is recalculated' => str_contains($service, 'recalculateSlip($runId'),
    'activation owns transaction rollback' => str_contains($service, '$this->pdo->rollBack()'),
    'API route is registered' => str_contains($router, "case 'employee-finance':"),
    'API requires payroll write scope' => str_contains($api, "activate-after-disbursement") && str_contains($api, "ApiAuth::require(['payroll.write'])"),
];
$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $failed += $ok ? 0 : 1;
}
echo 'QA: ' . (count($checks) - $failed) . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
