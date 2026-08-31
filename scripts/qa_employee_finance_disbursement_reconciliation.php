<?php

$script = (string)file_get_contents(__DIR__ . '/reconcile_employee_finance_disbursement.php');
$service = (string)file_get_contents(__DIR__ . '/../core/Services/PayrollService.php');
$checks = [
    'preview is default' => str_contains($script, '$apply = array_key_exists(\'apply\', $options)')
        && str_contains($script, "'mode'=>\$apply ? 'apply' : 'preview'"),
    'apply requires explicit target' => str_contains($script, '--apply requires --expense-request-id'),
    'scans only paid staff finance requests' => str_contains($script, "r.request_kind='staff_finance'")
        && str_contains($script, "r.status IN ('paid','confirmed','completed')"),
    'detects payment projection mismatch' => str_contains($script, 'DISBURSEMENT_METHOD_MISMATCH'),
    'detects manual repayment status mismatch' => str_contains($script, 'MANUAL_REPAYMENT_STATUS_MISMATCH'),
    'repair reuses canonical activation service' => str_contains($script, 'activateEmployeeFinanceForExpense('),
    'automated repair is auditable without impersonation' => str_contains($script, '$actorId = max(0,')
        && str_contains($service, "'payroll_activation'"),
    'apply is single-record only' => str_contains($script, 'Apply must target exactly one matching expense request'),
    'non-payroll repayment ignores paid payroll run' => str_contains($service, '$repaymentMethod === \'payroll\' && $run'),
];
$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failed++;
}
exit($failed === 0 ? 0 : 1);
