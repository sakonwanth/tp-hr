<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$crm = dirname($root) . '/tp-crm';
$erp = dirname($root) . '/tp-erp';
$common = dirname($root) . '/tp-common';
$checks = [
    'shared reducing-balance policy' => str_contains((string)file_get_contents($common . '/src/Hr/EmployeeFinancePolicy.php'), 'buildReducingBalanceSchedule'),
    'month-end payroll calendar' => str_contains((string)file_get_contents($common . '/src/Hr/PayrollCalendar.php'), "format('w') === '0'"),
    'maximum six months' => str_contains((string)file_get_contents($common . '/src/Hr/EmployeeFinancePolicy.php'), 'MAX_TERM_MONTHS = 6'),
    'minimum installment 1000' => str_contains((string)file_get_contents($common . '/src/Hr/EmployeeFinancePolicy.php'), 'MIN_MONTHLY_INSTALLMENT = 1000.00'),
    'LINE schedule preview' => str_contains((string)file_get_contents($crm . '/api/tp_expense_form.php'), 'preview_employee_loan'),
    'LINE consent payload' => str_contains((string)file_get_contents($crm . '/expense_request_form.php'), 'finance_consent'),
    'CRM payroll consumes finance rows' => str_contains((string)file_get_contents($crm . '/modules/payroll/queries.php'), 'payroll_employee_finance_deductions'),
    'HR payroll defaults to canonical payment day and accepts validated override' => str_contains((string)file_get_contents($root . '/core/Services/PayrollService.php'), '$payDay = $payDay ?? $defaultPayDay'),
    'HR profile owns salary setup base at write boundary' => str_contains((string)file_get_contents($root . '/core/Services/PayrollService.php'), '? $profileBase'),
    'HR approval blocks stale salary snapshots' => str_contains((string)file_get_contents($root . '/core/Services/PayrollService.php'), 'assertRunSalarySnapshotCurrent($runId)'),
    'parity diagnostic exposes salary source without credentials' => str_contains((string)file_get_contents($root . '/scripts/payroll_calc_single.php'), "in_array('--explain'")
        && str_contains((string)file_get_contents($root . '/scripts/payroll_calc_single.php'), "'profile' => \$service->getUserSalaryProfile"),
    'HR payroll consumes finance rows' => str_contains((string)file_get_contents($root . '/core/Services/PayrollService.php'), 'employeeFinanceDeductions'),
    'ERP uses shared policy' => str_contains((string)file_get_contents($erp . '/core/HrLoanService.php'), 'EmployeeFinancePolicy'),
    'HR management surface' => is_file($root . '/employee_finance.php'),
    'additive lifecycle migration' => is_file($root . '/database/migrations/2026_07_29_employee_finance_lifecycle.sql'),
    'scheduled rows calendar migration' => is_file($root . '/database/migrations/2026_07_30_payroll_month_end_calendar.sql'),

    // Post-disbursement rescheduling: one policy, and one delivery chain that
    // actually reaches the employee (HR row → ERP outbox worker → CRM bridge).
    'shared schedule policy exists' => is_file($common . '/src/Hr/EmployeeFinanceSchedulePolicy.php')
        && str_contains((string)file_get_contents($common . '/src/Hr/EmployeeFinanceSchedulePolicy.php'), 'BLOCK_DUE_DATE_PASSED'),
    'HR writes reschedules through the shared policy' => str_contains((string)file_get_contents($root . '/core/Services/EmployeeFinanceManagementService.php'), 'EmployeeFinanceSchedulePolicy'),
    'ERP request page reads the same schedule policy' => str_contains((string)file_get_contents($erp . '/views/expenses/request-show.php'), 'EmployeeFinanceSchedulePolicy'),
    'ERP outbox worker can dispatch schedule-change cards' => str_contains((string)file_get_contents($erp . '/scripts/expense_line_outbox_worker.php'), "'finance_schedule_changed'")
        && str_contains((string)file_get_contents($erp . '/scripts/expense_line_outbox_worker.php'), "'finance_repayment_method_changed'"),
    'CRM bridge renders the schedule-change card' => str_contains((string)file_get_contents($crm . '/api/expense_flex_push.php'), 'expense_finance_schedule_flex'),
    'HR ships the card text and deep link in the payload' => str_contains((string)file_get_contents($root . '/core/Services/EmployeeFinanceManagementService.php'), "\$payload['headline']")
        && str_contains((string)file_get_contents($root . '/core/Services/EmployeeFinanceManagementService.php'), "\$payload['alt_text']")
        && str_contains((string)file_get_contents($root . '/core/Services/EmployeeFinanceManagementService.php'), "'finance_id' => \$financeId"),
];
$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failed++;
}
exit($failed === 0 ? 0 : 1);
