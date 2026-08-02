<?php
declare(strict_types=1);
$page = (string)file_get_contents(dirname(__DIR__) . '/employee_finance.php');
$checks = [
    'no raw confirmed status on HR detail' => str_contains($page, "'confirmed' => 'ผู้ขอยืนยันรับเงินแล้ว'"),
    'repayment status has Thai labels' => str_contains($page, "'scheduled' => 'รอถึงกำหนด'") && str_contains($page, "'paid' => 'ชำระแล้ว'"),
    'first due month is formatted' => str_contains($page, '$formatThaiMonth((string)$detail[\'first_due_month\'])'),
    'due date is formatted' => str_contains($page, '$formatThaiDate((string)$repayment[\'due_date\'])'),
    'payroll month link is formatted' => str_contains($page, '$formatThaiMonth((string)$repayment[\'payroll_month\'])'),
    'LINE timeline query is request-scoped' => str_contains($page, 'FROM erp_expense_line_outbox WHERE expense_request_id=? ORDER BY id DESC LIMIT 20'),
    'LINE error detail is escaped' => str_contains($page, "htmlspecialchars((string)\$lineEvent['last_error'])"),
    'LINE recipient is not exposed in full' => str_contains($page, '$maskLineRecipient((string)$lineEvent[\'recipient_line_id\'])'),
];
$failed = 0;
foreach ($checks as $name => $ok) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$ok) $failed++; }
exit($failed ? 1 : 0);
