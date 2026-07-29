<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$crm = dirname($root) . '/tp-crm';
$erp = dirname($root) . '/tp-erp';
$common = dirname($root) . '/tp-common';
$checks = [
    'shared reducing-balance policy' => str_contains((string)file_get_contents($common . '/src/Hr/EmployeeFinancePolicy.php'), 'buildReducingBalanceSchedule'),
    'maximum six months' => str_contains((string)file_get_contents($common . '/src/Hr/EmployeeFinancePolicy.php'), 'MAX_TERM_MONTHS = 6'),
    'minimum installment 1000' => str_contains((string)file_get_contents($common . '/src/Hr/EmployeeFinancePolicy.php'), 'MIN_MONTHLY_INSTALLMENT = 1000.00'),
    'LINE schedule preview' => str_contains((string)file_get_contents($crm . '/api/tp_expense_form.php'), 'preview_employee_loan'),
    'LINE consent payload' => str_contains((string)file_get_contents($crm . '/expense_request_form.php'), 'finance_consent'),
    'CRM payroll consumes finance rows' => str_contains((string)file_get_contents($crm . '/modules/payroll/queries.php'), 'payroll_employee_finance_deductions'),
    'HR payroll consumes finance rows' => str_contains((string)file_get_contents($root . '/core/Services/PayrollService.php'), 'employeeFinanceDeductions'),
    'ERP uses shared policy' => str_contains((string)file_get_contents($erp . '/core/HrLoanService.php'), 'EmployeeFinancePolicy'),
    'HR management surface' => is_file($root . '/employee_finance.php'),
    'additive lifecycle migration' => is_file($root . '/database/migrations/2026_07_29_employee_finance_lifecycle.sql'),
];
$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failed++;
}
exit($failed === 0 ? 0 : 1);
