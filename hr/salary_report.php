<?php
/**
 * HR Salary Report - Monthly payroll report matching the manual Excel template
 * CEO level only. Live-calculated via PayrollService, reading ค่าตำแหน่ง/ค่าครองชีพ/
 * ค่าเดินทาง/ค่าบริหาร/ชดเชยวันหยุด from employee_salary_setup — the same shared
 * tp_crm row tp-crm's payroll.php already maintains, so those columns are
 * read-only here. Only กยศ. has no source anywhere yet, so it stays a manual
 * entry per employee per month.
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

/**
 * Read ค่าตำแหน่ง/ค่าครองชีพ/ค่าเดินทาง/ค่าบริหาร/ชดเชยวันหยุด from whichever of
 * allowance_json / income_other_json tp-crm's payroll.php put them in — production
 * data shows both fields are used interchangeably for these labels. Items with a
 * label that doesn't match a known bucket still count toward total_income (the
 * engine sums them regardless) but are returned separately as "unmatched" so the
 * page can flag them — a new label upstream would otherwise inflate the total
 * without ever appearing in a named column.
 *
 * @return array{buckets: array<string,float>, unmatched: list<array{label: string, amount: float}>}
 */
function hr_salary_extract_income_buckets(?array $setup): array
{
    $out = array_fill_keys(array_keys(HR_SALARY_EXACT_LABEL_BUCKETS), 0.0);
    $out['holiday_compensation'] = 0.0;
    $unmatched = [];
    if (!$setup) {
        return ['buckets' => $out, 'unmatched' => $unmatched];
    }
    $items = [];
    foreach (['allowance_json', 'income_other_json'] as $field) {
        if (!empty($setup[$field])) {
            $decoded = json_decode((string)$setup[$field], true);
            if (is_array($decoded)) {
                $items = array_merge($items, $decoded);
            }
        }
    }
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $label = trim((string)($item['label'] ?? ''));
        $amount = (float)($item['amount'] ?? 0);
        $bucketKey = array_search($label, HR_SALARY_EXACT_LABEL_BUCKETS, true);
        if ($bucketKey !== false) {
            $out[$bucketKey] += $amount;
        } elseif (in_array($label, HR_SALARY_HOLIDAY_COMP_LABELS, true)) {
            $out['holiday_compensation'] += $amount;
        } else {
            $unmatched[] = ['label' => $label !== '' ? $label : '(ไม่มีชื่อรายการ)', 'amount' => $amount];
        }
    }
    return ['buckets' => $out, 'unmatched' => $unmatched];
}

/** Deduction items other than กยศ. that PayrollService still sums into total_deductions but this report has no named column for. */
function hr_salary_extract_unmatched_deductions(?array $setup): array
{
    $unmatched = [];
    if ($setup && !empty($setup['deduction_other_json'])) {
        $decoded = json_decode((string)$setup['deduction_other_json'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $label = trim((string)($item['label'] ?? ''));
                if ($label === 'กยศ.') {
                    continue;
                }
                $unmatched[] = ['label' => $label !== '' ? $label : '(ไม่มีชื่อรายการ)', 'amount' => (float)($item['amount'] ?? 0)];
            }
        }
    }
    return $unmatched;
}

/** Build one report row: live PayrollService calc + CRM-sourced items + manual กยศ. */
function hr_salary_build_row(PayrollService $service, array $employee, string $monthFirst, int $payDay, array $manual): array
{
    $userId = (int)$employee['id'];
    $setup = $service->getSalarySetup($userId, $monthFirst);
    $incomeResult = hr_salary_extract_income_buckets($setup);
    $buckets = $incomeResult['buckets'];
    $unmatchedItems = array_merge($incomeResult['unmatched'], hr_salary_extract_unmatched_deductions($setup));
    $studentLoan = (float)($manual['student_loan_deduction'] ?? 0);
    $override = [];
    if ($studentLoan != 0.0) {
        $items = [];
        if ($setup && !empty($setup['deduction_other_json'])) {
            $decoded = json_decode((string)$setup['deduction_other_json'], true);
            if (is_array($decoded)) {
                $items = array_values(array_filter($decoded, static function ($item) {
                    return !is_array($item) || trim((string)($item['label'] ?? '')) !== 'กยศ.';
                }));
            }
        }
        $items[] = ['label' => 'กยศ.', 'amount' => round($studentLoan, 2)];
        $override['deduction_other_json'] = json_encode($items, JSON_UNESCAPED_UNICODE);
    }
    $slip = $service->calculateSlip($userId, $monthFirst, $payDay, $override ?: null);

    return [
        'user_id' => $userId,
        'employee_code' => $employee['employee_code'] ?? '',
        'full_name' => $employee['full_name'],
        'position' => $employee['position'] ?? '',
        'bank_name' => $employee['bank_name'] ?? '',
        'base_salary' => (float)$slip['gross_salary'],
        'allowance_position' => $buckets['allowance_position'],
        'allowance_col' => $buckets['allowance_col'],
        'allowance_transport' => $buckets['allowance_transport'],
        'bonus' => (float)$slip['bonus'],
        'admin_fee' => $buckets['admin_fee'],
        'holiday_compensation' => $buckets['holiday_compensation'],
        'total_income' => (float)$slip['total_income'],
        'social_security' => (float)$slip['social_security'],
        'leave_deduction' => (float)$slip['absence_deduction'],
        'tax' => (float)$slip['tax_withheld'],
        'student_loan' => $studentLoan,
        'total_deductions' => (float)$slip['total_deductions'],
        'net_salary' => (float)$slip['net_salary'],
        'unmatched_items' => $unmatchedItems,
    ];
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

$payDay = (int)($_REQUEST['pay_day'] ?? $service->getDefaultPayDay());
if ($payDay < 1 || $payDay > 31) {
    $payDay = $service->getDefaultPayDay();
}

$payDateInput = trim((string)($_REQUEST['pay_date'] ?? ''));
$payDateDt = DateTimeImmutable::createFromFormat('Y-m-d', $payDateInput);
if (!$payDateDt || $payDateDt->format('Y-m-d') !== $payDateInput) {
    $defaultDay = min((int)$monthDt->format('t'), max(1, $payDay));
    $payDateDt = $monthDt->modify('+' . ($defaultDay - 1) . ' days');
}
$payDateStr = $payDateDt->format('Y-m-d');

// --- Employees eligible for payroll (same scope PayrollService::createRun() uses) ---
$employeesStmt = $pdo->prepare("
    SELECT u.id, u.employee_code, CONCAT(u.first_name_th, ' ', u.last_name_th) AS full_name,
           u.position, u.bank_name, u.bank_account
    FROM users u
    WHERE u.is_active = 1 AND " . tp_hr_payroll_employee_filter_sql('u') . "
    ORDER BY u.id
");
$employeesStmt->execute();
$employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);
$employeeIds = array_map(static fn($e) => (int)$e['id'], $employees);

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

    if ($action === 'export') {
        $manualByUser = [];
        foreach ($employeeIds as $empId) {
            $manualByUser[$empId] = [
                'student_loan_deduction' => round(hr_salary_scalar_float($postStudentLoan[$empId] ?? 0), 2),
            ];
        }
        $rows = [];
        foreach ($employees as $employee) {
            $rows[] = hr_salary_build_row($service, $employee, $monthFirst, $payDay, $manualByUser[(int)$employee['id']] ?? []);
        }
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

    flash('success', 'บันทึกข้อมูลเรียบร้อย');
    redirect($redirectQuery(), 302);
}

// --- GET: load manual entries + build rows for on-screen review ---
$manualStmt = $pdo->prepare("SELECT user_id, student_loan_deduction FROM hr_payroll_manual_items WHERE period_month = ?");
$manualStmt->execute([$monthFirst]);
$manualByUser = [];
foreach ($manualStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $manualByUser[(int)$m['user_id']] = $m;
}

$rows = [];
foreach ($employees as $employee) {
    $rows[] = hr_salary_build_row($service, $employee, $monthFirst, $payDay, $manualByUser[(int)$employee['id']] ?? []);
}

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
        <p class="tp-ios-caption-muted max-w-[42rem]">คำนวณสดจากฐานเงินเดือน/ค่าเผื่อ/ค่าบริหาร/ชดเชยวันหยุด (ข้อมูลเดียวกับที่กรอกไว้ใน CRM)/ประกันสังคม/ภาษี ปัจจุบัน — กรอกเฉพาะ กยศ. เพิ่มเติมรายเดือน แล้วดาวน์โหลดเป็นไฟล์ Excel</p>
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
            <input id="hr-sal-payday" type="number" min="1" max="31" name="pay_day" class="input-field tp-native-input w-full min-h-[48px]" value="<?php echo (int)$payDay; ?>">
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

        <?php $rowsWithUnmatched = array_filter($rows, static fn($r) => !empty($r['unmatched_items'])); ?>
        <?php if ($rowsWithUnmatched): ?>
        <div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-amber-500/35 bg-amber-500/15 px-4 py-3 text-amber-100 text-sm" role="status">
            <i class="fas fa-triangle-exclamation mr-2" aria-hidden="true"></i>
            พบ <?php echo count($rowsWithUnmatched); ?> คนที่มีรายการรายรับ/รายจ่ายจาก CRM ที่ยังไม่รู้จัก (ดูไอคอน <i class="fas fa-triangle-exclamation" aria-hidden="true"></i> ในตาราง) — ยอด "รวมรายรับ"/"รวมรายจ่าย" นับรวมให้ถูกต้องแล้ว แต่จำนวนนี้ยังไม่ถูกแยกเข้าคอลัมน์ไหน ต้องแจ้งให้เพิ่ม label เข้าไปในโค้ด
        </div>
        <?php endif; ?>

        <?php if ($rows): ?>
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
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase" title="ข้อมูลจาก employee_salary_setup — แก้ไขได้ที่ CRM payroll.php">ค่าบริหาร<span class="block normal-case text-[10px] text-white/40">จาก CRM</span></th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase" title="ข้อมูลจาก employee_salary_setup — แก้ไขได้ที่ CRM payroll.php">ชดเชยวันหยุด<span class="block normal-case text-[10px] text-white/40">จาก CRM</span></th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">รวมรายรับ</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">ประกันสังคม</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">ลางาน</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">ภาษี</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-amber-300/90 uppercase">กยศ.<span class="block normal-case text-[10px] text-amber-300/70">กรอกเอง</span></th>
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
                            <?php if (!empty($row['unmatched_items'])):
                                $tip = 'รายการที่ยังไม่รู้จัก: ' . implode(', ', array_map(
                                    static fn($u) => $u['label'] . ' ' . number_format($u['amount'], 2),
                                    $row['unmatched_items']
                                ));
                            ?>
                            <i class="fas fa-triangle-exclamation text-amber-400 ml-1" aria-hidden="true" title="<?php echo htmlspecialchars($tip); ?>"></i>
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
                        <td class="px-3 py-2 text-right">
                            <input type="number" step="0.01" name="student_loan[<?php echo (int)$row['user_id']; ?>]" value="<?php echo htmlspecialchars((string)$row['student_loan']); ?>" class="input-field tp-native-input w-28 text-right min-h-[48px]">
                        </td>
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
            <button type="submit" name="action" value="save" class="inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] border border-white/15 bg-white/10 px-5 text-sm font-semibold text-white hover:bg-white/20 touch-manipulation gap-2 whitespace-nowrap">
                <i class="fas fa-floppy-disk" aria-hidden="true"></i>บันทึกข้อมูล
            </button>
            <button type="submit" name="action" value="export" class="inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-600 hover:bg-violet-700 px-5 text-sm font-semibold text-white touch-manipulation gap-2 whitespace-nowrap">
                <i class="fas fa-file-excel" aria-hidden="true"></i>บันทึกและดาวน์โหลด Excel
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
