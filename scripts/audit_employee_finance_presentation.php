<?php
declare(strict_types=1);
$page = (string)file_get_contents(dirname(__DIR__) . '/employee_finance.php');
$checks = [
    'no raw confirmed status on HR detail' => str_contains($page, "'confirmed' => 'ผู้ขอยืนยันรับเงินแล้ว'"),
    'repayment status has Thai labels' => str_contains($page, "'scheduled' => 'รอถึงกำหนด'") && str_contains($page, "'paid' => 'ชำระแล้ว'"),
    'first due month is formatted' => str_contains($page, '$formatThaiMonth((string)$detail[\'first_due_month\'])'),
    'due date is formatted' => str_contains($page, '$formatThaiDate((string)$repayment[\'due_date\'])'),
    'payroll month link is formatted' => str_contains($page, '$formatThaiMonth((string)$repayment[\'payroll_month\'])'),
];
$failed = 0;
foreach ($checks as $name => $ok) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$ok) $failed++; }
exit($failed ? 1 : 0);
