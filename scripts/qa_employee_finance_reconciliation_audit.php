<?php

declare(strict_types=1);

$audit = (string)file_get_contents(__DIR__ . '/audit_employee_finance_reconciliation.php');
$checks = [
    'audit is CLI-only and read-only' => str_contains($audit, "PHP_SAPI !== 'cli'")
        && !preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)\b\s+/i', $audit),
    'supports targeted payroll run audit' => str_contains($audit, "'run-id::'"),
    'supports targeted loan audit' => str_contains($audit, "'loan-id::'"),
    'reports missing schema without crashing' => str_contains($audit, 'SCHEMA_MISSING')
        && str_contains($audit, 'information_schema.tables'),
    'detects duplicate payroll sources' => str_contains($audit, 'DUPLICATE_PAYROLL_SOURCE'),
    'detects paid repayment without source' => str_contains($audit, 'PAID_REPAYMENT_WITHOUT_SLIP_SOURCE'),
    'detects finalized slip omissions' => str_contains($audit, 'DUE_REPAYMENT_MISSING_FROM_FINALIZED_SLIP'),
    'detects amount mismatches' => str_contains($audit, 'REPAYMENT_AMOUNT_MISMATCH'),
    'detects wrong employee linkage' => str_contains($audit, 'SOURCE_ASSIGNED_TO_WRONG_USER'),
    'checks month-end Sunday calendar' => str_contains($audit, "format('w') === '0'"),
    'does not expose employee identity fields' => !str_contains($audit, 'first_name_th')
        && !str_contains($audit, 'last_name_th')
        && !str_contains($audit, 'account_number'),
];

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failed++;
}
exit($failed === 0 ? 0 : 1);
