<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/employee_finance.php');
$payroll = (string) file_get_contents($root . '/core/Services/PayrollService.php');
$checks = [
    'employee-owned detail route' => str_contains($page, '$detail = $row;'),
    'loan repayment schedule is visible' => str_contains($page, 'ตารางผ่อนชำระและการลงสลิป'),
    'expense workflow status is visible' => str_contains($page, 'สถานะคำขอ'),
    'LINE delivery status is visible' => str_contains($page, 'การแจ้งเตือน LINE'),
    'payroll activation is explained' => str_contains($page, 'จะเริ่มนำค่างวดเข้าสลิปหลังบริษัทบันทึกจ่ายเงิน'),
    'payroll calculation consumes loan repayments' => str_contains($payroll, 'employeeFinanceDeductions'),
    'payroll settlement links run id' => str_contains($payroll, 'settleEmployeeFinanceForRun'),
];
$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failed++;
}
exit($failed === 0 ? 0 : 1);
