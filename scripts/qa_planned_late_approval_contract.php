<?php

$root = dirname(__DIR__);
$files = [
    'migration' => $root . '/database/migrations/2026_09_02_planned_late_approval_workflow.sql',
    'submit' => $root . '/api/attendance.php',
    'approve_service' => $root . '/core/Services/PlannedLateApprovalService.php',
    'payroll' => $root . '/core/Services/PayrollService.php',
    'inbox' => $root . '/hr/planned_late_approvals.php',
    'checkin_submit' => $root . '/../tp-checkin/api/attendance.php',
    'line_flex' => $root . '/../tp-crm/modules/hr/notifications.php',
    'line_action' => $root . '/../tp-crm/core/Services/PlannedLateLineApprovalService.php',
    'line_webhook' => $root . '/../tp-crm/api/line_webhook.php',
    'crm_payroll' => $root . '/../tp-crm/modules/payroll/queries.php',
    'auto_absent' => $root . '/../tp-crm/scripts/cron_hr_auto_absent.php',
];
foreach ($files as $name => $path) {
    if (!is_file($path)) { fwrite(STDERR, "[FAIL] missing {$name}\n"); exit(1); }
    $files[$name] = file_get_contents($path);
}

$checks = [
    'additive approval schema and legacy backfill' => str_contains($files['migration'], "planned_status ENUM('PENDING','APPROVED','REJECTED','CANCELLED')") && str_contains($files['migration'], "SET planned_status = 'APPROVED'"),
    'HR and Checkin submissions are pending' => str_contains($files['submit'], "planned_status = 'PENDING'") && str_contains($files['checkin_submit'], "planned_status = 'PENDING'"),
    'approval has transaction row lock' => str_contains($files['approve_service'], 'FOR UPDATE') && str_contains($files['approve_service'], "planned_status = 'PENDING'"),
    'four-eyes and post-checkin guards' => str_contains($files['approve_service'], 'ผู้ยื่นคำขอไม่สามารถอนุมัติ') && str_contains($files['approve_service'], 'พนักงานลงเวลาแล้ว'),
    'HR payroll requires approved state' => str_contains($files['payroll'], "=== 'APPROVED'"),
    'CRM payroll requires approved state' => str_contains($files['crm_payroll'], "=== 'APPROVED'"),
    'auto absent skips approved only' => str_contains($files['auto_absent'], "=== 'APPROVED'"),
    'web approval inbox and CSRF' => str_contains($files['inbox'], 'verifyCsrfToken') && str_contains($files['inbox'], 'PlannedLateApprovalService'),
    'LINE offers approve and reject postbacks' => str_contains($files['line_flex'], 'hr_planned_late_approve') && str_contains($files['line_flex'], 'hr_planned_late_reject'),
    'LINE verifies linked active approver role' => str_contains($files['line_action'], 'u.line_user_id=?') && str_contains($files['line_action'], "['HR','Admin','Chairman','CEO']"),
    'LINE webhook routes both decisions' => str_contains($files['line_webhook'], "case 'hr_planned_late_approve':") && str_contains($files['line_webhook'], "case 'hr_planned_late_reject':"),
];

$passed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $passed += $ok ? 1 : 0;
}
echo "Result: {$passed}/" . count($checks) . PHP_EOL;
exit($passed === count($checks) ? 0 : 1);
