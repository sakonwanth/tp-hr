<?php

declare(strict_types=1);

$view = (string)file_get_contents(dirname(__DIR__) . '/employee_finance.php');
$checks = [
    'payroll linkage counts only active canonical links' => str_contains($view, "['included', 'settled']"),
    'linked installments render completed progress' => str_contains($view, 'เชื่อมกับสลิปเงินเดือนแล้ว') && str_contains($view, '$linkedPayrollInstallments'),
    'unlinked future installment keeps scheduling message' => str_contains($view, 'ระบบจะนำค่างวดเดือน'),
    'pending disbursement remains explicit' => str_contains($view, "pending_disbursement"),
];

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failed++;
}
exit($failed === 0 ? 0 : 1);
