<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/core/Services/EmployeeFinanceManagementService.php');
$page = (string)file_get_contents($root . '/employee_finance.php');

$checks = [
    'executive edit UI is restricted' => str_contains($page, '$canEditFinance = isCEOOrAbove()'),
    'write is protected by POST and CSRF' => str_contains($page, "REQUEST_METHOD'] === 'POST'") && str_contains($page, 'verifyCsrf()'),
    'reason is mandatory' => str_contains($service, 'กรุณาระบุเหตุผลที่เปลี่ยนเดือนเริ่มหัก'),
    'only current or next month is accepted' => str_contains($service, "date('Y-m', strtotime('+1 month'))"),
    'finance row is locked and must be unpaid' => str_contains($service, 'FOR UPDATE') && str_contains($service, "pending_disbursement"),
    'paid or approved payroll run is rejected' => str_contains($service, "['approved', 'paid']"),
    'payroll links prevent correction' => str_contains($service, "link_status IN ('included','settled')"),
    'salary advance keeps both month columns aligned' => str_contains($service, 'SET advance_for_month=?, deduction_month=?'),
    'loan schedule is rebuilt from canonical policy' => str_contains($service, 'EmployeeFinancePolicy::buildReducingBalanceSchedule'),
    'non-scheduled repayments block correction' => str_contains($service, "status<>'scheduled' OR payroll_run_id IS NOT NULL"),
	    'change is audited with before and after values' => str_contains($service, "'first_due_month_changed'") && str_contains($service, "'old_month'") && str_contains($service, "'new_month'"),
	    'requester receives queued LINE correction notice' => str_contains($service, "'finance_schedule_changed'") && str_contains($service, 'erp_expense_line_outbox'),
    'UI exposes audit reason and month transition' => str_contains($page, "auditPayload['old_month']") && str_contains($page, "auditPayload['reason']"),
];

$passed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $passed += $ok ? 1 : 0;
}
echo "Employee finance management QA {$passed}/" . count($checks) . PHP_EOL;
exit($passed === count($checks) ? 0 : 1);
