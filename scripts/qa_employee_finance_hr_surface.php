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
    'LINE sent pending failed and cancelled totals are all visible' => str_contains($page, "ส่งสำเร็จ") && str_contains($page, "ส่งไม่สำเร็จ") && str_contains($page, "lineDelivery['cancelled']"),
    'LINE delivery timeline is scoped and visible' => str_contains($page, 'FROM erp_expense_line_outbox WHERE expense_request_id=?') && str_contains($page, 'ประวัติการส่ง LINE ล่าสุด'),
    'LINE failure reason and attempts are visible' => str_contains($page, "\$lineEvent['last_error']") && str_contains($page, "\$lineEvent['attempt_count']"),
    'LINE recipient identifiers are masked' => str_contains($page, '$maskLineRecipient') && str_contains($page, "'••••'"),
    'interest and repayment method are visible' => str_contains($page, 'อัตราดอกเบี้ย') && str_contains($page, 'วิธีรับเงิน / ชำระคืน'),
    'paid and remaining summary is visible' => str_contains($page, 'ชำระแล้ว') && str_contains($page, 'คงเหลือ'),
    'ERP expense source is linked' => str_contains($page, "'/expenses/requests/'"),
    'canonical payroll slip source is linked' => str_contains($page, "hr_employee_finance_payroll_links") && str_contains($page, "payroll_print.php?slip_id="),
    'finance audit history is visible' => str_contains($page, 'ประวัติการเปลี่ยนแปลง'),
    'payroll activation is explained' => str_contains($page, 'จะเริ่มนำค่างวดเข้าสลิปหลังบริษัทบันทึกจ่ายเงิน'),
    'payroll calculation consumes loan repayments' => str_contains($payroll, 'employeeFinanceDeductions'),
    'payroll settlement links run id' => str_contains($payroll, 'settleEmployeeFinanceForRun'),
    'payroll settlement uses canonical source links' => str_contains($payroll, "x.source_type='employee_loan_repayment' AND x.source_id=r.id"),
    'confirmed request status is localized' => str_contains($page, "'confirmed' => 'ผู้ขอยืนยันรับเงินแล้ว'"),
    'finance months and due dates use Thai presentation' => str_contains($page, '$formatThaiMonth') && str_contains($page, '$formatThaiDate') && str_contains($page, '+ 543'),
    'payroll approval requires reconciled links' => str_contains($payroll, '$this->assertEmployeeFinanceReconciled($runId);'),
    'company loan label is consistent' => str_contains($payroll, "'เงินกู้บริษัท งวดที่ '"),
];
$checks['reversed payroll links are not rendered as active slips'] = str_contains($page, 'in_array($linkStatus, [\'included\', \'settled\'], true)')
    && str_contains($page, 'ยกเลิกการเชื่อมสลิปแล้ว');
$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failed++;
}
exit($failed === 0 ? 0 : 1);
