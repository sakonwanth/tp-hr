<?php

$root = dirname(__DIR__);
$files = [
    'migration' => $root . '/database/migrations/2026_09_02_planned_late_approval_workflow.sql',
    'submit' => $root . '/api/attendance.php',
    'approve_service' => $root . '/core/Services/PlannedLateApprovalService.php',
    'payroll' => $root . '/core/Services/PayrollService.php',
    'inbox' => $root . '/hr/planned_late_approvals.php',
    'checkin_submit' => $root . '/../tp-checkin/api/attendance.php',
    'line_flex' => $root . '/modules/hr/notifications.php',
    'line_action' => $root . '/../tp-crm/core/Services/PlannedLateLineApprovalService.php',
    'line_webhook' => $root . '/../tp-crm/api/line_webhook.php',
    'line_all_service' => $root . '/../tp-crm/core/Services/HrLineApprovalService.php',
    'line_hr_boundary' => $root . '/core/Services/HrLineApprovalService.php',
    'crm_payroll' => $root . '/modules/payroll/queries.php',
    'auto_absent' => $root . '/scripts/cron_hr_auto_absent.php',
];
foreach ($files as $name => $path) {
    $files[$name] = is_file($path) ? file_get_contents($path) : null;
}

$checks = [
    'additive approval schema and legacy backfill' => str_contains($files['migration'], "planned_status ENUM('PENDING','APPROVED','REJECTED','CANCELLED')") && str_contains($files['migration'], "SET planned_status = 'APPROVED'"),
    'HR submissions are pending' => str_contains($files['submit'], "planned_status = 'PENDING'"),
    'approval has transaction row lock' => str_contains($files['approve_service'], 'FOR UPDATE') && str_contains($files['approve_service'], "planned_status = 'PENDING'"),
    'four-eyes and retrospective request guards' => str_contains($files['approve_service'], 'ผู้ยื่นคำขอไม่สามารถอนุมัติ') && str_contains($files['approve_service'], 'คำขอไม่ได้ส่งก่อนลงเวลา'),
    'delayed approval recalculates captured arrival' => str_contains($files['approve_service'], 'determineCheckIn(') && str_contains($files['approve_service'], 'assertPayrollOpen'),
    'approval form preserves submitter and rejects missing action' => str_contains($files['inbox'], "namedItem('action').value=action") && str_contains($files['inbox'], "in_array(\$action, ['approve', 'reject'], true)"),
    'HR payroll requires approved state' => str_contains($files['payroll'], "=== 'APPROVED'"),
    'web approval inbox and CSRF' => str_contains($files['inbox'], 'verifyCsrfToken') && str_contains($files['inbox'], 'PlannedLateApprovalService'),
    'canonical HR LINE approval boundary uses locks' => str_contains($files['line_hr_boundary'], 'FOR UPDATE') && str_contains($files['line_hr_boundary'], "status='PENDING'"),
    'calculated payroll is invalidated with actor audit' => str_contains($files['approve_service'], "SET status='draft'") && str_contains($files['approve_service'], 'PAYROLL_RECALC_REQUIRED'),
    'canonical approval rechecks active human role' => str_contains($files['approve_service'], "r.name IN ('HR','Admin','Chairman','CEO')") && str_contains($files['approve_service'], 'u.is_active=1'),
    'approval inbox sorts newest first' => str_contains($files['inbox'], 'a.attendance_date DESC') && str_contains($files['inbox'], 'a.planned_requested_at DESC'),
];

$crossChecks = [
    'Checkin submissions are pending' => ['checkin_submit', "planned_status = 'PENDING'"],
    'HR-owned migrated payroll requires approved state' => ['crm_payroll', "=== 'APPROVED'"],
    'auto absent skips approved only' => ['auto_absent', "=== 'APPROVED'"],
    'LINE offers approve and reject postbacks' => ['line_flex', ['hr_planned_late_approve','hr_planned_late_reject']],
    'LINE verifies linked active approver role' => ['line_action', ['u.line_user_id=?', "['HR','Admin','Chairman','CEO']"]],
    'LINE webhook routes both decisions' => ['line_webhook', ["case 'hr_planned_late_approve':", "case 'hr_planned_late_reject':"]],
    'LINE replies to approver with detailed flex' => ['line_webhook', ['replyLineFlexMessage($replyToken', "'flex'" ]],
    'LINE supports all employee HR approval workflows' => ['line_all_service', ["'leave'","'adjustment'","'outside'","'dayoff'","'holiday_work'",'HrClient::fromEnv()']],
];
foreach ($crossChecks as $label => [$fileKey, $needles]) {
    if ($files[$fileKey] === null) { echo "[SKIP] {$label} (sibling repo unavailable)\n"; continue; }
    $needles = (array)$needles;
    $checks[$label] = array_reduce($needles, static fn(bool $ok, string $needle): bool => $ok && str_contains($files[$fileKey], $needle), true);
}

$passed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $passed += $ok ? 1 : 0;
}
echo "Result: {$passed}/" . count($checks) . PHP_EOL;
exit($passed === count($checks) ? 0 : 1);
