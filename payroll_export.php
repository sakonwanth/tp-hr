<?php
/**
 * Export รอบเงินเดือนเป็น CSV (เปิดใน Excel ได้)
 * GET: run_id= (บังคับ) — สิทธิ์ HR/CEO ขึ้นไป
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/./core/LegacyHrUi.php';
Auth::requireLogin();

$pdo = getDB();
$session_user = Auth::user();
$stmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
$stmt->execute([$session_user['id']]);
$current_user = $stmt->fetch();
if (!$current_user) { header('Location: login.php'); exit; }

if (!function_exists('crm_user_can_access_payroll_web') || !crm_user_can_access_payroll_web()) {
    header('Location: index.php?error=' . urlencode('คุณไม่มีสิทธิ์เข้าถึง'));
    exit;
}

// Export รอบใหญ่อาจใช้เวลานาน — ขยายเพดานเวลาให้พอสำหรับองค์กรขนาดกลาง (audit-13)
if (function_exists('set_time_limit')) {
    @set_time_limit(300);
}

$run_id = (int)($_GET['run_id'] ?? 0);
if ($run_id <= 0) {
    header('Location: payroll.php?action=runs');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM payroll_runs WHERE id = ?");
$stmt->execute([$run_id]);
$run = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$run) {
    header('Location: payroll.php?action=runs');
    exit;
}

$stmt = $pdo->prepare("SELECT s.*, u.employee_code, u.first_name_th, u.last_name_th, u.department FROM payroll_slips s JOIN users u ON s.user_id = u.id WHERE s.payroll_run_id = ? AND (u.employee_code IS NULL OR u.employee_code NOT LIKE 'CR%') AND " . tp_crm_person_user_condition_sql('u') . " ORDER BY u.employee_code");
$stmt->execute([$run_id]);
$slips = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/modules/payroll/queries.php';
$dateChanges = \TpCommon\Hr\PayrollPaymentDate::changes($pdo, $run_id);

$filename = 'payroll_' . date('Y-m', strtotime($run['payroll_month'])) . '.csv';
$filename_safe = crm_safe_content_disposition_filename($filename, 'payroll.csv');

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename_safe . '"');
header('X-Content-Type-Options: nosniff');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

$headers = ['รหัสพนักงาน', 'ชื่อ', 'นามสกุล', 'แผนก', 'ฐานเงินเดือน', 'โบนัส', 'เบี้ยเลี้ยง', 'รายได้อื่น', 'รวมได้', 'ภาษีหัก ณ ที่จ่าย', 'กองทุนสำรอง', 'ประกันสังคม', 'ประกันกลุ่ม (พนักงานจ่าย)', 'ประกันสุขภาพ (พนักงานจ่าย)', 'หักอื่น', 'รวมหัก', 'สุทธิรับ', 'วันที่จ่ายเงิน'];
fputcsv($out, $headers, ',', '"', '');

foreach ($slips as $s) {
    $ec = $s['employee_code'] ?? '';
    $income_other_sum = 0;
    $io_arr = !empty($s['income_other_json']) ? json_decode($s['income_other_json'], true) : [];
    if (is_array($io_arr)) foreach ($io_arr as $a) $income_other_sum += (float)($a['amount'] ?? 0);
    $ded_other_sum = 0;
    $do_arr = !empty($s['deduction_other_json']) ? json_decode($s['deduction_other_json'], true) : [];
    if (is_array($do_arr)) foreach ($do_arr as $a) $ded_other_sum += (float)($a['amount'] ?? 0);
    $row = [
        $ec,
        $s['first_name_th'] ?? '',
        $s['last_name_th'] ?? '',
        $s['department'] ?? '',
        $s['gross_salary'] ?? 0,
        $s['bonus'] ?? 0,
        $s['allowances'] ?? 0,
        $income_other_sum,
        $s['total_income'] ?? 0,
        $s['tax_withheld'] ?? 0,
        $s['provident_fund'] ?? 0,
        $s['social_security'] ?? 0,
        $s['group_insurance'] ?? 0,
        $s['health_insurance'] ?? 0,
        $ded_other_sum,
        $s['total_deductions'] ?? 0,
        $s['net_salary'] ?? 0,
        $dateChanges[(int)$s['user_id']]['payment_date'] ?? payroll_format_pay_date($run['payroll_month'], (int)$run['pay_day']),
    ];
    fputcsv($out, $row, ',', '"', '');
}

fclose($out);
exit;
