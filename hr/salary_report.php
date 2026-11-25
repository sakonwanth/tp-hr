<?php
/**
 * HR Salary Report - Monthly payroll report matching the manual Excel template
 * CEO level only. A month with a payroll run is reported from that run's slips (the
 * figures that were approved/paid); a month without one is a live PayrollService
 * preview, where กยศ. can be typed in per employee (hr_payroll_manual_items).
 * Income columns are bucketed by label from income_other_json / allowance_json — the
 * same items the payroll salary setup maintains.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/Services/PayrollService.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

Auth::requireLogin();
Auth::requireHR();

if (!isCEOOrAbove()) {
    flash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้ารายงานเงินเดือน');
    redirect('/hr/', 302);
}

$pdo = getDB();
$user = Auth::user();
$service = new PayrollService($pdo);

$page_title = 'รายงานเงินเดือน';
$current_page = 'hr-salary-report';

// Exact-label buckets. ค่าตำแหน่ง/ค่าครองชีพ/ค่าเดินทาง/ค่าบริหาร are entered
// consistently in tp-crm's payroll.php (checked against production data).
const HR_SALARY_EXACT_LABEL_BUCKETS = [
    'allowance_position' => 'ค่าตำแหน่ง',
    'allowance_col' => 'ค่าครองชีพ',
    'allowance_transport' => 'ค่าเดินทาง',
    'admin_fee' => 'ค่าบริหาร',
];

// "ชดเชยวันหยุด" has no single consistent label upstream — tp-crm users have
// typed it 3 different ways so far. Fuzzy-match all known variants and sum
// them; add new variants here as they turn up in production data.
const HR_SALARY_HOLIDAY_COMP_LABELS = [
    'ชดเชยวันหยุด',
    'เงินชดเชยวันทำงาน',
    'เงินชดเชยเวลาทำงาน',
    'ชดเชยวันทำงาน / Additional workday compensation',
];

/** Safe float cast — rejects arrays/objects that could slip in via malformed POST field names. */
function hr_salary_scalar_float($value): float
{
    return is_scalar($value) ? (float)$value : 0.0;
}

// กยศ. as typed in this report (live preview) or in the payroll salary setup's "รายการหักอื่น".
const HR_SALARY_STUDENT_LOAN_LABELS = ['กยศ.', 'กยศ'];

/** @return list<array<string,mixed>> every item of the given JSON arrays, in order */
function hr_salary_decode_items(?string ...$jsons): array
{
    $items = [];
    foreach ($jsons as $json) {
        if ($json === null || $json === '') {
            continue;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            continue;
        }
        foreach ($decoded as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }
    }
    return $items;
}

/** Loan/advance items written by the ERP activation path carry `name` rather than `label`. */
function hr_salary_item_label(array $item): string
{
    $label = trim((string)($item['label'] ?? $item['name'] ?? ''));
    return $label !== '' ? $label : '(ไม่มีชื่อรายการ)';
}

/**
 * One report row from a slip (payroll_slips row) or a live PayrollService::calculateSlip() result.
 *
 * The template has fixed columns, but total_income / total_deductions also carry things it
 * has no column for (lateness, provident fund, insurance, loan and advance installments,
 * labels this file does not recognise). Every such amount is listed in `hidden_items`, and
 * whatever is still unexplained after that is listed too — so a column total that does not
 * add up is always flagged rather than silently off.
 *
 * @param list<array<string,mixed>> $incomeItems itemised lines behind total_income
 * @param float $itemisedAllowances part of $figures['allowances'] already covered by $incomeItems
 */
function hr_salary_assemble_row(array $employee, array $figures, array $incomeItems, float $itemisedAllowances): array
{
    $buckets = array_fill_keys(array_keys(HR_SALARY_EXACT_LABEL_BUCKETS), 0.0) + ['holiday_compensation' => 0.0];
    $hidden = [];
    $hiddenIncome = 0.0;
    $hiddenDeductions = 0.0;
    $hide = static function (string $label, float $amount, bool $isIncome) use (&$hidden, &$hiddenIncome, &$hiddenDeductions): void {
        if (abs($amount) < 0.005) {
            return;
        }
        $hidden[] = ['label' => ($isIncome ? 'รายรับ: ' : 'หัก: ') . $label, 'amount' => $amount];
        if ($isIncome) {
            $hiddenIncome += $amount;
        } else {
            $hiddenDeductions += $amount;
        }
    };

    foreach ($incomeItems as $item) {
        $label = hr_salary_item_label($item);
        $amount = (float)($item['amount'] ?? 0);
        $key = array_search($label, HR_SALARY_EXACT_LABEL_BUCKETS, true);
        if ($key !== false) {
            $buckets[$key] += $amount;
        } elseif (in_array($label, HR_SALARY_HOLIDAY_COMP_LABELS, true)) {
            $buckets['holiday_compensation'] += $amount;
        } else {
            $hide($label, $amount, true);
        }
    }
    $hide('ค่าเผื่อ (รวม)', (float)($figures['allowances'] ?? 0) - $itemisedAllowances, true);

    $studentLoan = 0.0;
    foreach (hr_salary_decode_items($figures['deduction_other_json'] ?? null) as $item) {
        $label = hr_salary_item_label($item);
        $amount = (float)($item['amount'] ?? 0);
        if (in_array($label, HR_SALARY_STUDENT_LOAN_LABELS, true)) {
            $studentLoan += $amount;
        } else {
            $hide($label, $amount, false);
        }
    }
    foreach (['lateness_deduction' => 'หักมาสาย', 'provident_fund' => 'กองทุนสำรองเลี้ยงชีพ',
              'group_insurance' => 'ประกันกลุ่ม', 'health_insurance' => 'ประกันสุขภาพ'] as $field => $label) {
        $hide($label, (float)($figures[$field] ?? 0), false);
    }

    $row = [
        'user_id' => (int)$employee['id'],
        'employee_code' => $employee['employee_code'] ?? '',
        'full_name' => $employee['full_name'],
        'position' => $employee['position'] ?? '',
        'bank_name' => $employee['bank_name'] ?? '',
        'base_salary' => (float)($figures['gross_salary'] ?? 0),
        'allowance_position' => $buckets['allowance_position'],
        'allowance_col' => $buckets['allowance_col'],
        'allowance_transport' => $buckets['allowance_transport'],
        'bonus' => (float)($figures['bonus'] ?? 0),
        'admin_fee' => $buckets['admin_fee'],
        'holiday_compensation' => $buckets['holiday_compensation'],
        'total_income' => (float)($figures['total_income'] ?? 0),
        'social_security' => (float)($figures['social_security'] ?? 0),
        'leave_deduction' => (float)($figures['absence_deduction'] ?? 0),
        'tax' => (float)($figures['tax_withheld'] ?? 0),
        'student_loan' => $studentLoan,
        'total_deductions' => (float)($figures['total_deductions'] ?? 0),
        'net_salary' => (float)($figures['net_salary'] ?? 0),
    ];

    $visibleIncome = $row['base_salary'] + $row['allowance_position'] + $row['allowance_col'] + $row['allowance_transport']
        + $row['bonus'] + $row['admin_fee'] + $row['holiday_compensation'];
    $hide('อื่น ๆ (ไม่ทราบที่มา)', $row['total_income'] - $visibleIncome - $hiddenIncome, true);
    $visibleDeductions = $row['social_security'] + $row['leave_deduction'] + $row['tax'] + $row['student_loan'];
    $hide('อื่น ๆ (ไม่ทราบที่มา)', $row['total_deductions'] - $visibleDeductions - $hiddenDeductions, false);

    $row['hidden_items'] = $hidden;
    return $row;
}

/** Live preview for a month with no payroll run yet: PayrollService calc + manual กยศ. */
function hr_salary_build_live_row(PayrollService $service, array $employee, string $monthFirst, int $payDay, array $manual): array
{
    $userId = (int)$employee['id'];
    $setup = $service->getSalarySetup($userId, $monthFirst);
    $studentLoan = (float)($manual['student_loan_deduction'] ?? 0);
    $override = [];
    if ($studentLoan != 0.0) {
        $items = array_values(array_filter(
            hr_salary_decode_items($setup['deduction_other_json'] ?? null),
            static fn(array $item): bool => !in_array(hr_salary_item_label($item), HR_SALARY_STUDENT_LOAN_LABELS, true)
        ));
        $items[] = ['label' => 'กยศ.', 'amount' => round($studentLoan, 2)];
        $override['deduction_other_json'] = json_encode($items, JSON_UNESCAPED_UNICODE);
    }
    $calc = $service->calculateSlip($userId, $monthFirst, $payDay, $override ?: null);

    // Income lines come from the calculation itself: for a mid-period hire it prorates
    // income_other_json, which the stored setup row does not reflect.
    $allowanceItems = hr_salary_decode_items($setup['allowance_json'] ?? null);
    $itemisedAllowances = array_sum(array_map(static fn(array $i): float => (float)($i['amount'] ?? 0), $allowanceItems));
    $incomeItems = array_merge($allowanceItems, hr_salary_decode_items($calc['income_other_json'] ?? null));
    return hr_salary_assemble_row($employee, $calc, $incomeItems, $itemisedAllowances);
}

/** A month that has a payroll run: the slip is what was (or will be) paid. */
function hr_salary_build_slip_row(array $slipWithEmployee): array
{
    return hr_salary_assemble_row(
        $slipWithEmployee,
        $slipWithEmployee,
        hr_salary_decode_items($slipWithEmployee['income_other_json'] ?? null),
        0.0
    );
}

// --- Resolve selected period ---
$periodMonthInput = (string)($_REQUEST['period_month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $periodMonthInput)) {
    $periodMonthInput = date('Y-m');
}
$monthFirst = $periodMonthInput . '-01';
$monthDt = DateTimeImmutable::createFromFormat('Y-m-d', $monthFirst);
if (!$monthDt) {
    $periodMonthInput = date('Y-m');
    $monthFirst = date('Y-m-01');
    $monthDt = new DateTimeImmutable($monthFirst);
}

// --- Source of figures ---
// A month with a payroll run is reported from its slips — the bank transfer must match what
// was approved/paid, even if salary setup or attendance changed afterwards. Only a month
// with no run yet is calculated live, as a preview.
$runStmt = $pdo->prepare('SELECT id, status, pay_day FROM payroll_runs WHERE payroll_month = ? LIMIT 1');
$runStmt->execute([$monthFirst]);
$reportRun = $runStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$reportRunStatusLabels = ['draft' => 'ร่าง', 'calculated' => 'คำนวณแล้ว รออนุมัติ', 'approved' => 'อนุมัติแล้ว', 'paid' => 'จ่ายแล้ว'];

if ($reportRun) {
    $payDay = (int)$reportRun['pay_day'];
} else {
    $payDay = (int)($_REQUEST['pay_day'] ?? $service->getDefaultPayDay());
    if ($payDay < 1 || $payDay > 31) {
        $payDay = $service->getDefaultPayDay();
    }
}

$payDateInput = trim((string)($_REQUEST['pay_date'] ?? ''));
$payDateDt = DateTimeImmutable::createFromFormat('Y-m-d', $payDateInput);
if (!$payDateDt || $payDateDt->format('Y-m-d') !== $payDateInput) {
    $defaultDay = min((int)$monthDt->format('t'), max(1, $payDay));
    $payDateDt = $monthDt->modify('+' . ($defaultDay - 1) . ' days');
}
$payDateStr = $payDateDt->format('Y-m-d');

// --- Employees ---
// With a run: exactly the people on it (someone who left after payment still belongs on
// that month's sheet; someone hired later does not). Without: the current payroll scope,
// as PayrollService::createRun() would pick it.
if ($reportRun) {
    $employeesStmt = $pdo->prepare("
        SELECT s.*, u.id AS id, u.employee_code, CONCAT(u.first_name_th, ' ', u.last_name_th) AS full_name,
               u.position, u.bank_name, u.bank_account
        FROM payroll_slips s
        JOIN users u ON u.id = s.user_id
        WHERE s.payroll_run_id = ? AND " . tp_hr_payroll_employee_filter_sql('u') . "
        ORDER BY u.id
    ");
    $employeesStmt->execute([(int)$reportRun['id']]);
} else {
    $employeesStmt = $pdo->prepare("
        SELECT u.id, u.employee_code, CONCAT(u.first_name_th, ' ', u.last_name_th) AS full_name,
               u.position, u.bank_name, u.bank_account
        FROM users u
        WHERE u.is_active = 1 AND " . tp_hr_payroll_employee_filter_sql('u') . "
        ORDER BY u.id
    ");
    $employeesStmt->execute();
}
$employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);
$employeeIds = array_map(static fn($e) => (int)$e['id'], $employees);

/** @param array<int,array<string,mixed>> $manualByUser */
$buildRows = static function (array $manualByUser) use ($reportRun, $employees, $service, $monthFirst, $payDay): array {
    $rows = [];
    foreach ($employees as $employee) {
        $rows[] = $reportRun
            ? hr_salary_build_slip_row($employee)
            : hr_salary_build_live_row($service, $employee, $monthFirst, $payDay, $manualByUser[(int)$employee['id']] ?? []);
    }
    return $rows;
};

$redirectQuery = static function () use ($periodMonthInput, $payDay, $payDateStr): string {
    return '/hr/salary_report.php?' . http_build_query([
        'period_month' => $periodMonthInput,
        'pay_day' => $payDay,
        'pay_date' => $payDateStr,
    ]);
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verifyCsrfToken($_POST['_token'] ?? null)) {
        flash('error', 'โทเค็นความปลอดภัยไม่ถูกต้อง');
        redirect($redirectQuery(), 302);
    }
    $action = (string)($_POST['action'] ?? 'save');
    $postStudentLoan = $_POST['student_loan'] ?? [];

    // กยศ. typed here only feeds the live preview. Once the month has a run, the run's own
    // deduction lines are what get paid, so nothing is saved over them.
    if (!$reportRun) {
        $upsert = $pdo->prepare("
            INSERT INTO hr_payroll_manual_items (user_id, period_month, student_loan_deduction, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE student_loan_deduction = VALUES(student_loan_deduction), updated_by = VALUES(updated_by)
        ");
        $pdo->beginTransaction();
        try {
            foreach ($employeeIds as $empId) {
                $studentLoan = round(hr_salary_scalar_float($postStudentLoan[$empId] ?? 0), 2);
                $upsert->execute([$empId, $monthFirst, $studentLoan, (int)$user['id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('error', 'บันทึกข้อมูลไม่สำเร็จ');
            redirect($redirectQuery(), 302);
        }
    }

    if ($action === 'export') {
        $manualByUser = [];
        foreach ($employeeIds as $empId) {
            $manualByUser[$empId] = [
                'student_loan_deduction' => round(hr_salary_scalar_float($postStudentLoan[$empId] ?? 0), 2),
            ];
        }
        $rows = $buildRows($manualByUser);
        $bankNote = trim((string)($_POST['bank_note'] ?? ''));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr(thaiMonth((int)$monthDt->format('n')) . ' ' . ((int)$monthDt->format('Y') + 543), 0, 31));

        $lastCol = 'Q'; // 17 columns: A..Q
        $titleText = 'รายงานเงินเดือนพนักงาน ประจำเดือน' . thaiMonth((int)$monthDt->format('n')) . ' ' . ((int)$monthDt->format('Y') + 543)
            . ' (วันที่นำจ่าย ' . (int)$payDateDt->format('j') . '/' . (int)$payDateDt->format('n') . '/' . substr((string)((int)$payDateDt->format('Y') + 543), -2) . ')';
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', $titleText);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A2:C2');
        $sheet->setCellValue('A2', 'ข้อมูลพนักงาน');
        $sheet->mergeCells('D2:J2');
        $sheet->setCellValue('D2', 'รายรับ');
        $sheet->mergeCells('K2:N2');
        $sheet->setCellValue('K2', 'รายจ่าย');
        $sheet->mergeCells('O2:Q2');
        $sheet->setCellValue('O2', 'จ่ายสุทธิ');
        $sheet->getStyle('A2:Q2')->getFont()->setBold(true);
        $sheet->getStyle('A2:Q2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $headers = ['ลำดับ', 'ชื่อ - นามสกุล', 'ตำแหน่ง', 'เงินเดือน', 'ค่าตำแหน่ง', 'ค่าครองชีพ', 'ค่าเดินทาง',
            'โบนัสประจำเดือน', 'ค่าบริหาร', 'ชดเชยวันหยุด', 'รวมรายรับ', 'ประกันสังคม', 'ลางาน', 'ภาษี', 'กยศ.',
            'รวมรายจ่าย', 'จ่ายสุทธิ'];
        $col = 'A';
        foreach ($headers as $h) {
            $sheet->setCellValue("{$col}3", $h);
            $col++;
        }
        $sheet->getStyle('A3:Q3')->getFont()->setBold(true);
        $sheet->getStyle('A3:Q3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
        $sheet->getStyle('A3:Q3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EFEFEF');

        $rowIdx = 4;
        $seq = 1;
        $bankCounts = [];
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$rowIdx}", $seq);
            $sheet->setCellValue("B{$rowIdx}", $row['full_name']);
            $sheet->setCellValue("C{$rowIdx}", $row['position']);
            $sheet->setCellValue("D{$rowIdx}", $row['base_salary']);
            $sheet->setCellValue("E{$rowIdx}", $row['allowance_position']);
            $sheet->setCellValue("F{$rowIdx}", $row['allowance_col']);
            $sheet->setCellValue("G{$rowIdx}", $row['allowance_transport']);
            $sheet->setCellValue("H{$rowIdx}", $row['bonus']);
            $sheet->setCellValue("I{$rowIdx}", $row['admin_fee']);
            $sheet->setCellValue("J{$rowIdx}", $row['holiday_compensation']);
            $sheet->setCellValue("K{$rowIdx}", $row['total_income']);
            $sheet->setCellValue("L{$rowIdx}", $row['social_security']);
            $sheet->setCellValue("M{$rowIdx}", $row['leave_deduction']);
            $sheet->setCellValue("N{$rowIdx}", $row['tax']);
            $sheet->setCellValue("O{$rowIdx}", $row['student_loan']);
            $sheet->setCellValue("P{$rowIdx}", $row['total_deductions']);
            $sheet->setCellValue("Q{$rowIdx}", $row['net_salary']);
            if ($row['net_salary'] > 0 && $row['bank_name'] !== '') {
                $bankCounts[$row['bank_name']] = ($bankCounts[$row['bank_name']] ?? 0) + 1;
            }
            $seq++;
            $rowIdx++;
        }
        $sheet->getStyle("D4:Q" . ($rowIdx - 1))->getNumberFormat()->setFormatCode('#,##0.00');

        $totalRow = $rowIdx + 1;
        $netTotal = array_sum(array_column($rows, 'net_salary'));
        $sheet->setCellValue("Q{$totalRow}", $netTotal);
        $sheet->getStyle("Q{$totalRow}")->getFont()->setBold(true);
        $sheet->getStyle("Q{$totalRow}")->getNumberFormat()->setFormatCode('#,##0.00');

        $noteLines = [];
        foreach ($bankCounts as $bankName => $count) {
            $noteLines[] = '#เข้าบัญชีธนาคาร' . $bankName . ' จำนวน ' . $count . ' คน';
        }
        if ($bankNote !== '') {
            $noteLines[] = $bankNote;
        }
        if ($noteLines) {
            $noteRow = $totalRow + 2;
            $sheet->mergeCells("A{$noteRow}:{$lastCol}{$noteRow}");
            $sheet->setCellValue("A{$noteRow}", implode('   ', $noteLines));
            $sheet->getStyle("A{$noteRow}")->getAlignment()->setWrapText(true);
        }

        foreach (range('A', 'Q') as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(32);

        Auth::log('hr_salary_report_export_xlsx', null, null, null, [
            'period_month' => $periodMonthInput,
            'pay_day' => $payDay,
            'row_count' => count($rows),
        ]);

        $filename = hr_safe_content_disposition_filename('salary_report_' . $periodMonthInput . '.xlsx', 'salary_report.xlsx');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: max-age=0');
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    flash('success', $reportRun
        ? 'เดือนนี้มีรอบเงินเดือนแล้ว ตัวเลขทั้งหมดมาจากรอบ — ไม่มีข้อมูลให้บันทึก'
        : 'บันทึกข้อมูลเรียบร้อย');
    redirect($redirectQuery(), 302);
}

// --- GET: load manual entries + build rows for on-screen review ---
$manualStmt = $pdo->prepare("SELECT user_id, student_loan_deduction FROM hr_payroll_manual_items WHERE period_month = ?");
$manualStmt->execute([$monthFirst]);
$manualByUser = [];
foreach ($manualStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $manualByUser[(int)$m['user_id']] = $m;
}

$rows = $buildRows($manualByUser);
$rowsWithHidden = array_filter($rows, static fn(array $r): bool => !empty($r['hidden_items']));
/** One line per amount the template has no column for, for the ⚠ tooltip. */
$hiddenTip = static fn(array $row): string => 'รวมอยู่ในยอดแต่ไม่มีคอลัมน์: ' . implode(', ', array_map(
    static fn(array $h): string => $h['label'] . ' ' . number_format((float)$h['amount'], 2),
    $row['hidden_items']
));

$reportFlashOk = flash('success');
$reportFlashErr = flash('error');

require_once __DIR__ . '/../templates/header.php';
?>

<div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(1200px,100%)] mx-auto min-w-0">
<?php if ($reportFlashOk): ?>
<div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-emerald-200 text-sm" role="status">
    <i class="fas fa-check-circle mr-2" aria-hidden="true"></i><?php echo htmlspecialchars($reportFlashOk); ?>
</div>
<?php endif; ?>
<?php if ($reportFlashErr): ?>
<div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-red-500/30 bg-red-500/15 px-4 py-3 text-red-200 text-sm" role="alert">
    <i class="fas fa-exclamation-circle mr-2" aria-hidden="true"></i><?php echo htmlspecialchars($reportFlashErr); ?>
</div>
<?php endif; ?>

<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="tp-tap-48 hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <a href="/hr/reports.php" class="tp-tap-48 hover:text-white touch-manipulation">รายงาน</a>
        <span class="mx-2">/</span>
        <span class="text-white">รายงานเงินเดือน</span>
    </nav>
    <div class="min-w-0">
        <h1 class="tp-ios-page-title flex flex-wrap items-center gap-2 mb-2">
            <i class="fas fa-money-check-dollar text-violet-400 shrink-0" aria-hidden="true"></i>
            <span>รายงานเงินเดือนพนักงาน</span>
        </h1>
        <p class="tp-ios-caption-muted max-w-[42rem]">เดือนที่มีรอบเงินเดือนแล้วใช้ตัวเลขจากสลิปของรอบ (ตรงกับที่อนุมัติ/จ่ายจริง) — เดือนที่ยังไม่มีรอบเป็นการคำนวณล่วงหน้า และกรอก กยศ. เพิ่มได้ แล้วดาวน์โหลดเป็นไฟล์ Excel</p>
    </div>
</header>

<div class="native-card tp-native-card overflow-hidden rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6 mb-6 min-w-0 border border-white/10">
    <form method="GET" class="flex flex-wrap items-end gap-4">
        <div class="tp-native-form-group mb-0 min-w-[10rem]">
            <label class="block text-white/70 text-sm mb-1" for="hr-sal-month">เดือน</label>
            <input id="hr-sal-month" type="month" name="period_month" class="input-field tp-native-input w-full min-h-[48px]" value="<?php echo htmlspecialchars($periodMonthInput); ?>">
        </div>
        <div class="tp-native-form-group mb-0 min-w-[8rem]">
            <label class="block text-white/70 text-sm mb-1" for="hr-sal-payday">วันตัดรอบ (payDay)</label>
            <input id="hr-sal-payday" type="number" min="1" max="31" name="pay_day" class="input-field tp-native-input w-full min-h-[48px]<?php echo $reportRun ? ' opacity-80 cursor-not-allowed' : ''; ?>" value="<?php echo (int)$payDay; ?>"<?php echo $reportRun ? ' readonly aria-readonly="true" title="ใช้วันตัดรอบของรอบเงินเดือนเดือนนี้"' : ''; ?>>
        </div>
        <div class="tp-native-form-group mb-0 min-w-[10rem]">
            <label class="block text-white/70 text-sm mb-1" for="hr-sal-paydate">วันที่นำจ่าย</label>
            <input id="hr-sal-paydate" type="date" name="pay_date" class="input-field tp-native-input w-full min-h-[48px]" value="<?php echo htmlspecialchars($payDateStr); ?>">
        </div>
        <button type="submit" class="inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-600 hover:bg-violet-700 px-5 text-sm font-semibold text-white touch-manipulation gap-2 whitespace-nowrap">
            <i class="fas fa-search" aria-hidden="true"></i>โหลดข้อมูล
        </button>
    </form>
</div>

<form method="POST" action="/hr/salary_report.php">
    <?php echo csrfField(); ?>
    <input type="hidden" name="period_month" value="<?php echo htmlspecialchars($periodMonthInput); ?>">
    <input type="hidden" name="pay_day" value="<?php echo (int)$payDay; ?>">
    <input type="hidden" name="pay_date" value="<?php echo htmlspecialchars($payDateStr); ?>">

    <div class="native-card tp-native-card tp-native-data-card overflow-hidden rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6 min-w-0 border border-white/10">
        <div class="mb-4 pb-4 border-b border-white/10 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-white">
                ประจำเดือน<?php echo htmlspecialchars(thaiMonth((int)$monthDt->format('n')) . ' ' . ((int)$monthDt->format('Y') + 543)); ?>
            </h2>
            <p class="text-white/55 text-sm" role="status">พนักงาน <?php echo count($rows); ?> คน</p>
        </div>

        <?php if ($reportRun): ?>
        <div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-emerald-100 text-sm" role="status">
            <i class="fas fa-circle-check mr-2" aria-hidden="true"></i>
            ตัวเลขจากสลิปของ<a class="underline" href="/payroll.php?run_id=<?php echo (int)$reportRun['id']; ?>">รอบเงินเดือนเดือนนี้</a>
            (สถานะ: <?php echo htmlspecialchars($reportRunStatusLabels[(string)$reportRun['status']] ?? (string)$reportRun['status']); ?>)
            <?php if (!in_array((string)$reportRun['status'], ['approved', 'paid'], true)): ?>
            — รอบยังไม่อนุมัติ ตัวเลขอาจเปลี่ยนเมื่อคำนวณรอบใหม่
            <?php endif; ?>
            · แก้ กยศ. ได้ที่ "รายการหักอื่น" ในหน้ากำหนดเงินเดือน แล้วคำนวณรอบใหม่
        </div>
        <?php else: ?>
        <div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-sky-500/30 bg-sky-500/10 px-4 py-3 text-sky-100 text-sm" role="status">
            <i class="fas fa-circle-info mr-2" aria-hidden="true"></i>
            เดือนนี้ยังไม่มีรอบเงินเดือน — ตัวเลขเป็นการคำนวณล่วงหน้าจากข้อมูลปัจจุบัน และจะเปลี่ยนได้จนกว่าจะสร้างและอนุมัติรอบ
        </div>
        <?php endif; ?>

        <?php if ($rowsWithHidden): ?>
        <div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-amber-500/35 bg-amber-500/15 px-4 py-3 text-amber-100 text-sm" role="status">
            <i class="fas fa-triangle-exclamation mr-2" aria-hidden="true"></i>
            พบ <?php echo count($rowsWithHidden); ?> คนที่ยอด "รวมรายรับ"/"รวมรายจ่าย" มีรายการที่รายงานนี้ไม่มีคอลัมน์ (เช่น หักมาสาย, กองทุนสำรองฯ, งวดเงินกู้/เงินเบิกล่วงหน้า) — ยอดรวมและจ่ายสุทธิถูกต้องแล้ว ดูรายการที่ไอคอน <i class="fas fa-triangle-exclamation" aria-hidden="true"></i> · ถ้าเป็นชื่อรายการที่ควรอยู่ในคอลัมน์ (เช่น ชดเชยวันหยุดสะกดแบบใหม่) แจ้งให้เพิ่มในโค้ด
        </div>
        <?php endif; ?>

        <?php if ($rows): ?>
        <?php /* Phone: seventeen columns will never fit, and the table was
                 simply `hidden` below md — so a phone showed the note field and
                 the save button with no payroll data at all. One card per
                 employee instead, grouped income then deductions.

                 Read-only on purpose. The กยศ. field is a real input, and the
                 desktop table is only display:none on a phone — its inputs are
                 still in the form and still submit. A second input with the
                 same name would submit too, and being later in the DOM the
                 untouched hidden copy would overwrite whatever was typed on
                 desktop. So the value is shown here, and edited on a wider
                 screen. */ ?>
        <div class="md:hidden space-y-3">
            <?php foreach ($rows as $i => $row): ?>
            <div class="tp-ios-attendance-panel p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-white font-semibold">
                            <?php echo ($i + 1) . '. ' . htmlspecialchars($row['full_name']); ?>
                            <?php if (!empty($row['hidden_items'])): ?>
                            <i class="fas fa-triangle-exclamation text-amber-400 ml-1" aria-hidden="true" title="<?php echo htmlspecialchars($hiddenTip($row)); ?>"></i>
                            <?php endif; ?>
                        </p>
                        <p class="text-white/50 text-xs mt-0.5"><?php echo htmlspecialchars($row['position']); ?></p>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="text-emerald-400 font-semibold tabular-nums"><?php echo number_format($row['net_salary'], 2); ?></p>
                        <p class="text-white/50 text-xs mt-0.5">จ่ายสุทธิ</p>
                    </div>
                </div>

                <p class="text-white/50 text-xs mt-4 mb-2">รายรับ</p>
                <dl class="grid grid-cols-2 gap-3 text-sm min-w-0">
                    <div class="min-w-0"><dt class="text-white/50 text-xs">เงินเดือน</dt><dd class="text-white/85 tabular-nums"><?php echo number_format($row['base_salary'], 2); ?></dd></div>
                    <div class="min-w-0"><dt class="text-white/50 text-xs">ค่าตำแหน่ง</dt><dd class="text-white/85 tabular-nums"><?php echo number_format($row['allowance_position'], 2); ?></dd></div>
                    <div class="min-w-0"><dt class="text-white/50 text-xs">ค่าครองชีพ</dt><dd class="text-white/85 tabular-nums"><?php echo number_format($row['allowance_col'], 2); ?></dd></div>
                    <div class="min-w-0"><dt class="text-white/50 text-xs">ค่าเดินทาง</dt><dd class="text-white/85 tabular-nums"><?php echo number_format($row['allowance_transport'], 2); ?></dd></div>
                    <div class="min-w-0"><dt class="text-white/50 text-xs">โบนัส</dt><dd class="text-white/85 tabular-nums"><?php echo number_format($row['bonus'], 2); ?></dd></div>
                    <div class="min-w-0"><dt class="text-white/50 text-xs">ค่าบริหาร</dt><dd class="text-white/85 tabular-nums"><?php echo number_format($row['admin_fee'], 2); ?></dd></div>
                    <div class="min-w-0"><dt class="text-white/50 text-xs">ชดเชยวันหยุด</dt><dd class="text-white/85 tabular-nums"><?php echo number_format($row['holiday_compensation'], 2); ?></dd></div>
                    <div class="min-w-0"><dt class="text-white/50 text-xs">รวมรายรับ</dt><dd class="text-white font-medium tabular-nums"><?php echo number_format($row['total_income'], 2); ?></dd></div>
                </dl>

                <p class="text-white/50 text-xs mt-4 mb-2">รายจ่าย</p>
                <dl class="grid grid-cols-2 gap-3 text-sm min-w-0">
                    <div class="min-w-0"><dt class="text-white/50 text-xs">ประกันสังคม</dt><dd class="text-white/85 tabular-nums"><?php echo number_format($row['social_security'], 2); ?></dd></div>
                    <div class="min-w-0"><dt class="text-white/50 text-xs">ลางาน</dt><dd class="text-white/85 tabular-nums"><?php echo number_format($row['leave_deduction'], 2); ?></dd></div>
                    <div class="min-w-0"><dt class="text-white/50 text-xs">ภาษี</dt><dd class="text-white/85 tabular-nums"><?php echo number_format($row['tax'], 2); ?></dd></div>
                    <div class="min-w-0"><dt class="text-amber-300/90 text-xs">กยศ.</dt><dd class="text-white/85 tabular-nums"><?php echo number_format((float)$row['student_loan'], 2); ?></dd></div>
                    <div class="min-w-0"><dt class="text-white/50 text-xs">รวมรายจ่าย</dt><dd class="text-white font-medium tabular-nums"><?php echo number_format($row['total_deductions'], 2); ?></dd></div>
                </dl>
            </div>
            <?php endforeach; ?>
            <?php if (!$reportRun): ?>
            <p class="text-white/45 text-xs px-1">แก้ไขยอด กยศ. ได้บนหน้าจอที่กว้างกว่านี้</p>
            <?php endif; ?>
        </div>

        <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain -mx-1 px-1 pb-px">
            <table class="w-full text-sm" style="min-width:1700px">
                <thead class="bg-white/5">
                    <tr>
                        <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-white/60 uppercase">ลำดับ</th>
                        <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-white/60 uppercase">ชื่อ-นามสกุล</th>
                        <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-white/60 uppercase">ตำแหน่ง</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">เงินเดือน</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">ค่าตำแหน่ง</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">ค่าครองชีพ</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">ค่าเดินทาง</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">โบนัส</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase" title="ข้อมูลจาก employee_salary_setup — แก้ไขได้ที่ HR payroll.php">ค่าบริหาร<span class="block normal-case text-[10px] text-white/40">จาก HR</span></th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase" title="ข้อมูลจาก employee_salary_setup — แก้ไขได้ที่ HR payroll.php">ชดเชยวันหยุด<span class="block normal-case text-[10px] text-white/40">จาก HR</span></th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">รวมรายรับ</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">ประกันสังคม</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">ลางาน</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">ภาษี</th>
                        <?php if ($reportRun): ?>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">กยศ.<span class="block normal-case text-[10px] text-white/40">จากรอบ</span></th>
                        <?php else: ?>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-amber-300/90 uppercase">กยศ.<span class="block normal-case text-[10px] text-amber-300/70">กรอกเอง</span></th>
                        <?php endif; ?>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">รวมรายจ่าย</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-emerald-400/90 uppercase">จ่ายสุทธิ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    <?php foreach ($rows as $i => $row): ?>
                    <tr class="hover:bg-white/[0.04]">
                        <td class="px-3 py-2 text-white/60"><?php echo $i + 1; ?></td>
                        <td class="px-3 py-2 text-white">
                            <?php echo htmlspecialchars($row['full_name']); ?>
                            <?php if (!empty($row['hidden_items'])): ?>
                            <i class="fas fa-triangle-exclamation text-amber-400 ml-1" aria-hidden="true" title="<?php echo htmlspecialchars($hiddenTip($row)); ?>"></i>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-white/60"><?php echo htmlspecialchars($row['position']); ?></td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['base_salary'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['allowance_position'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['allowance_col'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['allowance_transport'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['bonus'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['admin_fee'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['holiday_compensation'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-white font-medium"><?php echo number_format($row['total_income'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['social_security'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['leave_deduction'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['tax'], 2); ?></td>
                        <?php if ($reportRun): ?>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['student_loan'], 2); ?></td>
                        <?php else: ?>
                        <td class="px-3 py-2 text-right">
                            <input type="number" step="0.01" min="0" name="student_loan[<?php echo (int)$row['user_id']; ?>]" value="<?php echo htmlspecialchars((string)$row['student_loan']); ?>" class="input-field tp-native-input w-28 text-right min-h-[48px]">
                        </td>
                        <?php endif; ?>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['total_deductions'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-emerald-400 font-semibold"><?php echo number_format($row['net_salary'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-5">
            <label class="block text-white/70 text-sm mb-1" for="hr-sal-note">หมายเหตุเพิ่มเติม (ต่อท้ายบรรทัดจำนวนคนต่อธนาคาร ในไฟล์ Excel)</label>
            <textarea id="hr-sal-note" name="bank_note" rows="2" class="input-field tp-native-input w-full" placeholder="เช่น ค่าธรรมเนียมโอนธนาคารอื่น 20 บาท"></textarea>
        </div>

        <div class="flex flex-wrap gap-3 mt-5">
            <?php if (!$reportRun): ?>
            <button type="submit" name="action" value="save" class="inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] border border-white/15 bg-white/10 px-5 text-sm font-semibold text-white hover:bg-white/20 touch-manipulation gap-2 whitespace-nowrap">
                <i class="fas fa-floppy-disk" aria-hidden="true"></i>บันทึกข้อมูล
            </button>
            <?php endif; ?>
            <button type="submit" name="action" value="export" class="inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-600 hover:bg-violet-700 px-5 text-sm font-semibold text-white touch-manipulation gap-2 whitespace-nowrap">
                <i class="fas fa-file-excel" aria-hidden="true"></i><?php echo $reportRun ? 'ดาวน์โหลด Excel' : 'บันทึกและดาวน์โหลด Excel'; ?>
            </button>
        </div>
        <?php else: ?>
        <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15">
            <i class="fas fa-users-slash text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
            <p class="text-slate-400 text-sm">ไม่พบพนักงานที่อยู่ในระบบเงินเดือน</p>
        </div>
        <?php endif; ?>
    </div>
</form>

</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
