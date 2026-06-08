<?php
/**
 * HR Reports - Attendance & Leave Reports
 * CEO level only
 */

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Auth::requireHR();

// CEO-level access only
if (!isCEOOrAbove()) {
    flash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้ารายงาน');
    redirect('/hr/', 302);
}

$pdo = getDB();
$user = Auth::user();

$page_title = 'รายงาน';
$current_page = 'hr-reports';

$allowedReports = ['attendance', 'leave', 'leave-summary', 'daily'];
$isPostExport = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['export_csv'] ?? '') === '1';

if ($isPostExport) {
    if (!verifyCsrfToken($_POST['_token'] ?? null)) {
        flash('error', 'โทเค็นความปลอดภัยไม่ถูกต้อง');
        redirect('/hr/reports.php', 302);
    }
    $report = (string)($_POST['report'] ?? 'attendance');
    if (!in_array($report, $allowedReports, true)) {
        $report = 'attendance';
    }
    $startDate = $_POST['start_date'] ?? date('Y-m-01');
    $endDate = $_POST['end_date'] ?? date('Y-m-d');
    $department = trim((string)($_POST['department'] ?? ''));
} else {
    if (($_GET['export'] ?? '') === 'csv') {
        $q = $_GET;
        unset($q['export']);
        flash('error', 'การส่งออกข้อมูล กรุณากดปุ่ม Export CSV เท่านั้น');
        redirect('/hr/reports.php' . (count($q) ? '?' . http_build_query($q) : ''), 302);
    }
    $report = $_GET['report'] ?? 'attendance';
    if (!in_array($report, $allowedReports, true)) {
        $report = 'attendance';
    }
    $startDate = $_GET['start_date'] ?? date('Y-m-01');
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $department = $_GET['department'] ?? '';
}

$reportMaxRangeDays = (int)($_ENV['HR_REPORT_MAX_RANGE_DAYS'] ?? (getenv('HR_REPORT_MAX_RANGE_DAYS') !== false ? getenv('HR_REPORT_MAX_RANGE_DAYS') : 366));
if ($reportMaxRangeDays < 31) {
    $reportMaxRangeDays = 366;
}
$reportRangeWasCapped = false;
$ds = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
$de = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
if (!$ds || $ds->format('Y-m-d') !== $startDate || !$de || $de->format('Y-m-d') !== $endDate) {
    flash('error', 'รูปแบบวันที่ไม่ถูกต้อง');
    $rq = ['report' => $report];
    if ($department !== '') {
        $rq['department'] = $department;
    }
    redirect('/hr/reports.php?' . http_build_query($rq), 302);
}
if ($ds > $de) {
    $startDate = $de->format('Y-m-d');
    $endDate = $ds->format('Y-m-d');
    $ds = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
    $de = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
}
$rangeDays = $ds->diff($de)->days + 1;
if ($rangeDays > $reportMaxRangeDays) {
    $endDate = $ds->modify('+' . ($reportMaxRangeDays - 1) . ' days')->format('Y-m-d');
    $de = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
    $reportRangeWasCapped = true;
}

// Get departments for filter
$departments = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' AND " . tp_hr_non_system_user_condition_sql('') . " ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);

// Build base query conditions
$conditions = [];
$params = [];

if ($department) {
    $conditions[] = "u.department = ?";
    $params[] = $department;
}

// Fetch report data
$reportData = [];

switch ($report) {
    case 'attendance':
        // Per-employee aggregation. Only counts rows that indicate actual attendance activity.
        // work_days = PRESENT + LATE + WFH + HALF_DAY (excludes ABSENT/LEAVE/HOLIDAY/PENDING)
        $sql = "
            SELECT 
                u.id,
                u.employee_code,
                CONCAT(u.first_name_th, ' ', u.last_name_th) as full_name,
                u.department,
                u.position,
                SUM(CASE WHEN a.status IN ('PRESENT','LATE','WFH','HALF_DAY') THEN 1 ELSE 0 END) as work_days,
                SUM(CASE WHEN a.status = 'PRESENT'  THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN a.status = 'LATE'     THEN 1 ELSE 0 END) as late_days,
                SUM(CASE WHEN a.status = 'WFH'      THEN 1 ELSE 0 END) as wfh_days,
                SUM(CASE WHEN a.status = 'LEAVE'    THEN 1 ELSE 0 END) as leave_days,
                SUM(CASE WHEN a.status = 'ABSENT'   THEN 1 ELSE 0 END) as absent_days,
                SEC_TO_TIME(AVG(CASE WHEN a.status IN ('PRESENT','LATE','HALF_DAY') THEN TIME_TO_SEC(a.check_in_time) END))  as avg_check_in,
                SEC_TO_TIME(AVG(CASE WHEN a.status IN ('PRESENT','LATE','HALF_DAY') THEN TIME_TO_SEC(a.check_out_time) END)) as avg_check_out
            FROM users u
            LEFT JOIN hr_attendances a ON u.id = a.user_id 
                AND a.attendance_date BETWEEN ? AND ?
            WHERE u.is_active = 1 AND " . tp_hr_non_system_user_condition_sql('u') . "
            " . ($conditions ? " AND " . implode(" AND ", $conditions) : "") . "
            GROUP BY u.id
            ORDER BY u.department, u.first_name_th
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$startDate, $endDate], $params));
        $reportData = $stmt->fetchAll();
        break;
        
    case 'leave':
        // Overlap semantics: include any leave whose date range intersects [start,end].
        // Exclude DRAFT / CANCELLED. Only active non-system users.
        $sql = "
            SELECT 
                u.id,
                u.employee_code,
                CONCAT(u.first_name_th, ' ', u.last_name_th) as full_name,
                u.department,
                u.position,
                lt.name as leave_type,
                lt.color,
                SUM(lr.total_days) as total_days,
                SUM(CASE WHEN lr.status = 'APPROVED' THEN lr.total_days ELSE 0 END) as approved_days,
                SUM(CASE WHEN lr.status = 'PENDING'  THEN lr.total_days ELSE 0 END) as pending_days,
                SUM(CASE WHEN lr.status = 'REJECTED' THEN lr.total_days ELSE 0 END) as rejected_days
            FROM users u
            JOIN hr_leave_requests lr ON u.id = lr.user_id
            JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
            WHERE lr.start_date <= ? AND lr.end_date >= ?
              AND lr.status NOT IN ('DRAFT','CANCELLED')
              AND u.is_active = 1 AND " . tp_hr_non_system_user_condition_sql('u') . "
            " . ($conditions ? " AND " . implode(" AND ", $conditions) : "") . "
            GROUP BY u.id, lt.id
            ORDER BY u.department, u.first_name_th, lt.sort_order
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$endDate, $startDate], $params));
        $reportData = $stmt->fetchAll();
        break;
        
    case 'leave-summary':
        // Overlap semantics + exclude DRAFT/CANCELLED + active non-system users only.
        $sql = "
            SELECT 
                lt.name  as leave_type,
                lt.color,
                COUNT(DISTINCT lr.user_id) as employee_count,
                SUM(lr.total_days) as total_days,
                SUM(CASE WHEN lr.status = 'APPROVED' THEN lr.total_days ELSE 0 END) as approved_days,
                SUM(CASE WHEN lr.status = 'PENDING'  THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN lr.status = 'REJECTED' THEN 1 ELSE 0 END) as rejected_count
            FROM hr_leave_requests lr
            JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
            JOIN users u ON u.id = lr.user_id
            WHERE lr.start_date <= ? AND lr.end_date >= ?
              AND lr.status NOT IN ('DRAFT','CANCELLED')
              AND u.is_active = 1 AND " . tp_hr_non_system_user_condition_sql('u') . "
            GROUP BY lt.id
            ORDER BY lt.sort_order
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$endDate, $startDate]);
        $reportData = $stmt->fetchAll();
        break;
        
    case 'daily':
        // Pivot hr_attendances by status per date. Each (user, date) has at most 1 row (uk_user_date).
        // "มาทำงาน" = actually worked (PRESENT + LATE + WFH + HALF_DAY)
        // "ไม่มีข้อมูล" = active users with no row for that date (gap / future / weekend before cron)
        $sql = "
            SELECT
                a.attendance_date,
                h.name AS holiday_name,
                DAYOFWEEK(a.attendance_date) AS dow,
                SUM(CASE WHEN a.status IN ('PRESENT','LATE','WFH','HALF_DAY') THEN 1 ELSE 0 END) AS worked,
                SUM(CASE WHEN a.status = 'PRESENT' THEN 1 ELSE 0 END) AS on_time,
                SUM(CASE WHEN a.status = 'LATE'    THEN 1 ELSE 0 END) AS late,
                SUM(CASE WHEN a.status = 'WFH'     THEN 1 ELSE 0 END) AS wfh,
                SUM(CASE WHEN a.status = 'ABSENT'  THEN 1 ELSE 0 END) AS absent,
                SUM(CASE WHEN a.status = 'LEAVE'   THEN 1 ELSE 0 END) AS on_leave,
                SUM(CASE WHEN a.status = 'HOLIDAY' THEN 1 ELSE 0 END) AS holiday_off,
                (SELECT COUNT(*) FROM users u
                  WHERE " . tp_hr_attendance_scope_filter_sql('u') . ") AS total_employees
            FROM hr_attendances a
            LEFT JOIN hr_holidays h ON h.date = a.attendance_date AND h.is_active = 1
            WHERE a.attendance_date BETWEEN ? AND ?
              AND EXISTS (SELECT 1 FROM users u WHERE u.id = a.user_id AND u.is_active = 1 AND " . tp_hr_attendance_scope_filter_sql('u') . ")
            GROUP BY a.attendance_date, h.name
            ORDER BY a.attendance_date DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$startDate, $endDate]);
        $reportData = $stmt->fetchAll();
        break;
}

if ($isPostExport) {
    if (!$reportData) {
        flash('error', 'ไม่มีข้อมูลสำหรับส่งออก');
        $q = ['report' => $report, 'start_date' => $startDate, 'end_date' => $endDate];
        if ($department !== '') {
            $q['department'] = $department;
        }
        redirect('/hr/reports.php?' . http_build_query($q), 302);
    }
    $exportFilename = hr_safe_content_disposition_filename('report_' . $report . '_' . date('Y-m-d') . '.csv', 'report.csv');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $exportFilename . '"');
    header('X-Content-Type-Options: nosniff');

    $output = fopen('php://output', 'w');
    if ($output !== false) {
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($output, array_keys($reportData[0]));
        foreach ($reportData as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
    }
    Auth::log('hr_report_export_csv', null, null, null, [
        'report' => $report,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'department' => $department,
        'row_count' => count($reportData),
    ]);
    exit;
}

$reportFlashOk = flash('success');
$reportFlashErr = flash('error');

require_once __DIR__ . '/../templates/header.php';
?>

<div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
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
<?php if (!empty($reportRangeWasCapped)): ?>
<div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-amber-500/35 bg-amber-500/15 px-4 py-3 text-amber-100 text-sm" role="status">
    <i class="fas fa-info-circle mr-2" aria-hidden="true"></i>ช่วงวันที่รายงานจำกัดสูงสุด <?php echo (int)$reportMaxRangeDays; ?> วัน — แสดงข้อมูลถึงวันที่ <?php echo htmlspecialchars(formatDateThai($endDate)); ?>
</div>
<?php endif; ?>

<!-- Page Header -->
<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <span class="text-white">รายงาน</span>
    </nav>
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 md:gap-6">
    <div class="min-w-0">
        <h1 class="tp-ios-page-title flex flex-wrap items-center gap-2 mb-2">
            <i class="fas fa-chart-bar text-violet-400 shrink-0" aria-hidden="true"></i>
            <span>รายงาน</span>
        </h1>
        <p class="tp-ios-caption-muted max-w-[42rem]">ดูรายงานการลงเวลาและการลา</p>
    </div>
    
    <?php if ($reportData): ?>
    <form method="post" action="/hr/reports.php" class="inline-flex w-full md:w-auto shrink-0">
        <?php echo csrfField(); ?>
        <input type="hidden" name="export_csv" value="1">
        <input type="hidden" name="report" value="<?php echo htmlspecialchars($report); ?>">
        <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
        <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
        <input type="hidden" name="department" value="<?php echo htmlspecialchars($department); ?>">
        <button type="submit" class="inline-flex min-h-[48px] w-full md:w-auto items-center justify-center rounded-[var(--tp-ios-card-radius)] border border-white/15 bg-white/10 px-4 text-sm font-semibold text-white hover:bg-white/20 touch-manipulation gap-2" title="ส่งออกข้อมูลตารางด้านล่างเป็นไฟล์ CSV (UTF-8)">
            <i class="fas fa-file-csv" aria-hidden="true"></i>Export CSV
        </button>
    </form>
    <?php endif; ?>
    </div>
</header>

<!-- Report Type Tabs -->
<div class="flex gap-2 mb-6 overflow-x-auto pb-2 min-w-0 -mx-1 px-1" role="tablist" aria-label="ประเภทรายงาน">
    <a href="?report=attendance" role="tab" aria-selected="<?php echo $report === 'attendance' ? 'true' : 'false'; ?>" class="inline-flex min-h-[48px] shrink-0 items-center px-4 py-2 rounded-[var(--tp-ios-card-radius)] whitespace-nowrap touch-manipulation transition-colors <?php echo $report === 'attendance' ? 'bg-violet-600 text-white shadow-sm' : 'bg-white/10 text-white/70 hover:text-white hover:bg-white/15'; ?>">
        <i class="fas fa-user-clock mr-2" aria-hidden="true"></i>รายงานการลงเวลา
    </a>
    <a href="?report=daily" role="tab" aria-selected="<?php echo $report === 'daily' ? 'true' : 'false'; ?>" class="inline-flex min-h-[48px] shrink-0 items-center px-4 py-2 rounded-[var(--tp-ios-card-radius)] whitespace-nowrap touch-manipulation transition-colors <?php echo $report === 'daily' ? 'bg-violet-600 text-white shadow-sm' : 'bg-white/10 text-white/70 hover:text-white hover:bg-white/15'; ?>">
        <i class="fas fa-calendar-day mr-2" aria-hidden="true"></i>รายงานรายวัน
    </a>
    <a href="?report=leave" role="tab" aria-selected="<?php echo $report === 'leave' ? 'true' : 'false'; ?>" class="inline-flex min-h-[48px] shrink-0 items-center px-4 py-2 rounded-[var(--tp-ios-card-radius)] whitespace-nowrap touch-manipulation transition-colors <?php echo $report === 'leave' ? 'bg-violet-600 text-white shadow-sm' : 'bg-white/10 text-white/70 hover:text-white hover:bg-white/15'; ?>">
        <i class="fas fa-umbrella-beach mr-2" aria-hidden="true"></i>รายงานการลารายบุคคล
    </a>
    <a href="?report=leave-summary" role="tab" aria-selected="<?php echo $report === 'leave-summary' ? 'true' : 'false'; ?>" class="inline-flex min-h-[48px] shrink-0 items-center px-4 py-2 rounded-[var(--tp-ios-card-radius)] whitespace-nowrap touch-manipulation transition-colors <?php echo $report === 'leave-summary' ? 'bg-violet-600 text-white shadow-sm' : 'bg-white/10 text-white/70 hover:text-white hover:bg-white/15'; ?>">
        <i class="fas fa-chart-pie mr-2" aria-hidden="true"></i>สรุปการลา
    </a>
</div>

<!-- Filters -->
<div class="native-card tp-native-card overflow-hidden rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6 mb-6 min-w-0 border border-white/10">
    <form method="GET" class="flex flex-wrap items-end gap-4">
        <input type="hidden" name="report" value="<?php echo htmlspecialchars($report); ?>">
        
        <div class="tp-native-form-group mb-0 min-w-[10rem]">
            <label class="block text-white/70 text-sm mb-1" for="hr-rep-start">วันที่เริ่มต้น</label>
            <input id="hr-rep-start" type="date" name="start_date" class="input-field tp-native-input w-full min-h-[48px]" value="<?php echo htmlspecialchars($startDate); ?>">
        </div>
        
        <div class="tp-native-form-group mb-0 min-w-[10rem]">
            <label class="block text-white/70 text-sm mb-1" for="hr-rep-end">วันที่สิ้นสุด</label>
            <input id="hr-rep-end" type="date" name="end_date" class="input-field tp-native-input w-full min-h-[48px]" value="<?php echo htmlspecialchars($endDate); ?>">
        </div>
        <p class="w-full text-white/45 text-xs mb-0 basis-full">ช่วงวันที่สูงสุด <?php echo (int)$reportMaxRangeDays; ?> วันต่อครั้ง (ตั้งค่า <code class="text-white/60">HR_REPORT_MAX_RANGE_DAYS</code> ได้)</p>
        
        <?php if ($report === 'attendance' || $report === 'leave'): ?>
        <div class="tp-native-form-group mb-0 min-w-[12rem]">
            <label class="block text-white/70 text-sm mb-1" for="hr-rep-dept">แผนก</label>
            <select id="hr-rep-dept" name="department" class="input-field tp-native-select w-full min-h-[48px]">
                <option value="">ทั้งหมด</option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department === $dept ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        
        <button type="submit" class="inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-600 hover:bg-violet-700 px-5 text-sm font-semibold text-white touch-manipulation gap-2">
            <i class="fas fa-search" aria-hidden="true"></i>ค้นหา
        </button>
    </form>
</div>

<!-- Report Content -->
<?php if ($report === 'attendance'): ?>
<div class="native-card tp-native-card tp-native-data-card overflow-hidden rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6 min-w-0 border border-white/10">
    <div class="mb-4 pb-4 border-b border-white/10">
        <h2 class="text-lg font-semibold text-white">
            รายงานการลงเวลา <?php echo formatDateThai($startDate); ?> - <?php echo formatDateThai($endDate); ?>
        </h2>
        <?php if ($reportData): ?>
        <p class="text-white/55 text-sm mt-2" role="status">พบ <?php echo count($reportData); ?> รายการในตาราง</p>
        <?php endif; ?>
    </div>
    <?php if ($reportData): ?>
    <div class="md:hidden space-y-3">
        <?php foreach ($reportData as $row): ?>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-5">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-white font-semibold truncate"><?php echo htmlspecialchars($row['full_name']); ?></p>
                    <p class="text-slate-500 text-xs truncate"><?php echo htmlspecialchars($row['employee_code'] ?? '-'); ?> · <?php echo htmlspecialchars($row['position'] ?? '-'); ?></p>
                    <p class="text-slate-400 text-sm mt-1"><?php echo htmlspecialchars($row['department'] ?? '-'); ?></p>
                </div>
            </div>
            <div class="grid grid-cols-3 gap-2 mt-3 text-center text-sm">
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2">
                    <div class="text-[10px] text-slate-500 uppercase">วันทำงาน</div>
                    <div class="text-white font-bold"><?php echo (int)$row['work_days']; ?></div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2">
                    <div class="text-[10px] text-emerald-500/80">ตรงเวลา</div>
                    <div class="text-emerald-400 font-semibold"><?php echo (int)$row['present_days']; ?></div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2">
                    <div class="text-[10px] text-amber-500/80">สาย</div>
                    <div class="text-amber-400 font-semibold"><?php echo (int)$row['late_days']; ?></div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2">
                    <div class="text-[10px] text-cyan-500/80">WFH</div>
                    <div class="text-cyan-400 font-semibold"><?php echo (int)$row['wfh_days']; ?></div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2">
                    <div class="text-[10px] text-purple-500/80">ลา</div>
                    <div class="text-purple-400 font-semibold"><?php echo (int)$row['leave_days']; ?></div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2">
                    <div class="text-[10px] text-red-500/80">ขาด</div>
                    <div class="text-red-400 font-semibold"><?php echo (int)$row['absent_days']; ?></div>
                </div>
            </div>
            <div class="flex justify-between text-sm mt-3 text-slate-400">
                <span>เฉลี่ยเข้า <strong class="text-slate-200"><?php echo $row['avg_check_in'] ? substr($row['avg_check_in'], 0, 5) : '-'; ?></strong></span>
                <span>เฉลี่ยออก <strong class="text-slate-200"><?php echo $row['avg_check_out'] ? substr($row['avg_check_out'], 0, 5) : '-'; ?></strong></span>
            </div>
            <a href="/hr/employee_attendance.php?id=<?php echo (int)$row['id']; ?>"
               class="mt-3 flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-500/20 text-violet-300 hover:bg-violet-500/30 text-sm font-semibold touch-manipulation">
                <i class="fas fa-clock mr-2" aria-hidden="true"></i>ดูประวัติลงเวลา
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain -mx-1 px-1 pb-px">
        <table class="w-full text-sm" style="min-width:1200px">
            <thead class="bg-white/5">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">รหัส</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ชื่อ-นามสกุล</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">แผนก</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase" title="PRESENT + LATE + WFH + HALF_DAY">วันทำงาน</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-emerald-400/90 uppercase">ตรงเวลา</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-amber-400/90 uppercase">มาสาย</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-cyan-400/90 uppercase">WFH</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-purple-400/90 uppercase">ลา</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-red-400/90 uppercase">ขาดงาน</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">เฉลี่ยเข้า</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">เฉลี่ยออก</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">รายละเอียด</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($reportData as $row): ?>
                <tr class="hover:bg-white/[0.04]">
                    <td class="px-4 py-3 text-white/60"><?php echo htmlspecialchars($row['employee_code'] ?? '-'); ?></td>
                    <td class="px-4 py-3">
                        <p class="text-white font-medium"><?php echo htmlspecialchars($row['full_name']); ?></p>
                        <p class="text-white/50 text-xs"><?php echo htmlspecialchars($row['position'] ?? '-'); ?></p>
                    </td>
                    <td class="px-4 py-3 text-white/60"><?php echo htmlspecialchars($row['department'] ?? '-'); ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-white font-semibold"><?php echo (int)$row['work_days']; ?></span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ((int)$row['present_days'] > 0): ?>
                            <span class="text-emerald-400"><?php echo (int)$row['present_days']; ?></span>
                        <?php else: ?><span class="text-white/30">0</span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ((int)$row['late_days'] > 0): ?>
                            <span class="text-amber-400 font-medium"><?php echo (int)$row['late_days']; ?></span>
                        <?php else: ?><span class="text-white/30">0</span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ((int)$row['wfh_days'] > 0): ?>
                            <span class="text-cyan-400"><?php echo (int)$row['wfh_days']; ?></span>
                        <?php else: ?><span class="text-white/30">0</span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ((int)$row['leave_days'] > 0): ?>
                            <span class="text-purple-400"><?php echo (int)$row['leave_days']; ?></span>
                        <?php else: ?><span class="text-white/30">0</span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ((int)$row['absent_days'] > 0): ?>
                            <span class="text-red-400 font-semibold"><?php echo (int)$row['absent_days']; ?></span>
                        <?php else: ?><span class="text-white/30">0</span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-white/80">
                        <?php echo $row['avg_check_in'] ? substr($row['avg_check_in'], 0, 5) : '-'; ?>
                    </td>
                    <td class="px-4 py-3 text-white/80">
                        <?php echo $row['avg_check_out'] ? substr($row['avg_check_out'], 0, 5) : '-'; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <a href="/hr/employee_attendance.php?id=<?php echo $row['id']; ?>" 
                           class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-500/20 text-violet-300 hover:bg-violet-500/30 text-xs px-3 transition-colors touch-manipulation"
                           title="ดูประวัติลงเวลา">
                            <i class="fas fa-clock mr-1" aria-hidden="true"></i>ดู
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15">
        <i class="fas fa-chart-bar text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
        <p class="text-slate-400 text-sm">ไม่มีข้อมูลในช่วงเวลาที่เลือก</p>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($report === 'daily'): ?>
<div class="native-card tp-native-card tp-native-data-card overflow-hidden rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6 min-w-0 border border-white/10">
    <div class="flex items-start justify-between gap-4 mb-4">
        <div>
            <h2 class="text-lg font-semibold text-white">
                รายงานรายวัน <?php echo formatDateThai($startDate); ?> - <?php echo formatDateThai($endDate); ?>
            </h2>
            <p class="text-white/55 text-sm mt-2">
                สรุปจำนวนพนักงานในแต่ละวันตามสถานะการลงเวลา
                <?php if (!empty($reportData)): ?>
                (จำนวนพนักงานที่ใช้งานอยู่ทั้งหมด: <span class="text-white font-medium"><?php echo (int)$reportData[0]['total_employees']; ?></span> คน)
                <?php endif; ?>
            </p>
            <?php if ($reportData): ?>
            <p class="text-white/50 text-xs mt-1" role="status">พบ <?php echo count($reportData); ?> วันในช่วงที่เลือก</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($reportData): ?>
    <div class="md:hidden space-y-3">
        <?php foreach ($reportData as $row):
            $total = (int)$row['total_employees'];
            $recorded = (int)$row['worked'] + (int)$row['absent'] + (int)$row['on_leave'] + (int)$row['holiday_off'];
            $no_data = max(0, $total - $recorded);
            $dow = (int)$row['dow'];
            $isWeekend = ($dow === 1 || $dow === 7);
        ?>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-5">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-white font-semibold"><?php echo formatDateThai($row['attendance_date']); ?></span>
                <?php if ($row['holiday_name']): ?>
                    <span class="px-2 py-0.5 text-xs rounded bg-rose-900/40 text-rose-300" title="<?php echo htmlspecialchars($row['holiday_name']); ?>">วันหยุด</span>
                <?php elseif ($isWeekend): ?>
                    <span class="rounded-[var(--tp-ios-card-radius)] px-2 py-0.5 text-xs bg-white/10 text-white/50">สุดสัปดาห์</span>
                <?php endif; ?>
            </div>
            <div class="grid grid-cols-4 gap-2 mt-3 text-center text-xs">
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2">
                    <div class="text-slate-500">มาทำงาน</div>
                    <div class="text-white font-bold"><?php echo (int)$row['worked']; ?></div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2">
                    <div class="text-emerald-500/80">ตรงเวลา</div>
                    <div class="text-emerald-400 font-semibold"><?php echo (int)$row['on_time']; ?></div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2">
                    <div class="text-amber-500/80">สาย</div>
                    <div class="text-amber-400 font-semibold"><?php echo (int)$row['late']; ?></div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2">
                    <div class="text-cyan-500/80">WFH</div>
                    <div class="text-cyan-400 font-semibold"><?php echo (int)$row['wfh']; ?></div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2">
                    <div class="text-purple-500/80">ลา</div>
                    <div class="text-purple-400 font-semibold"><?php echo (int)$row['on_leave']; ?></div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2">
                    <div class="text-red-500/80">ขาด</div>
                    <div class="text-red-400 font-semibold"><?php echo (int)$row['absent']; ?></div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2 col-span-2">
                    <div class="text-slate-500">ไม่มีข้อมูล</div>
                    <div class="text-slate-300 font-semibold"><?php echo $no_data; ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain -mx-1 px-1 pb-px">
        <table class="w-full text-sm" style="min-width:900px">
            <thead class="bg-white/5">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">วันที่</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase" title="มาทำงาน = ตรงเวลา + สาย + WFH + ครึ่งวัน">มาทำงาน <i class="fas fa-info-circle text-white/40 ml-1 text-[10px]" aria-hidden="true"></i></th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-emerald-400/90 uppercase">ตรงเวลา</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-amber-400/90 uppercase">สาย</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-cyan-400/90 uppercase">WFH</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-purple-400/90 uppercase">ลา</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-red-400/90 uppercase">ขาดงาน</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase" title="พนักงานที่ยังไม่มีข้อมูลการลงเวลาในวันนี้">ไม่มีข้อมูล <i class="fas fa-info-circle text-white/40 ml-1 text-[10px]" aria-hidden="true"></i></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($reportData as $row):
                    $total = (int)$row['total_employees'];
                    $recorded = (int)$row['worked'] + (int)$row['absent'] + (int)$row['on_leave'] + (int)$row['holiday_off'];
                    $no_data = max(0, $total - $recorded);
                    $dow = (int)$row['dow']; // 1=Sun..7=Sat
                    $isWeekend = ($dow === 1 || $dow === 7);
                ?>
                <tr class="hover:bg-white/[0.04]">
                    <td class="px-4 py-3 text-white">
                        <div class="flex flex-wrap items-center gap-2">
                            <span><?php echo formatDateThai($row['attendance_date']); ?></span>
                            <?php if ($row['holiday_name']): ?>
                                <span class="rounded-[var(--tp-ios-card-radius)] px-2 py-0.5 text-xs bg-rose-900/40 text-rose-300" title="<?php echo htmlspecialchars($row['holiday_name']); ?>">
                                    วันหยุด
                                </span>
                            <?php elseif ($isWeekend): ?>
                                <span class="rounded-[var(--tp-ios-card-radius)] px-2 py-0.5 text-xs bg-white/10 text-white/50">สุดสัปดาห์</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-white font-semibold"><?php echo (int)$row['worked']; ?></span>
                    </td>
                    <td class="px-4 py-3 text-center"><span class="text-emerald-400"><?php echo (int)$row['on_time']; ?></span></td>
                    <td class="px-4 py-3 text-center">
                        <?php if ((int)$row['late'] > 0): ?>
                            <span class="text-amber-400 font-medium"><?php echo (int)$row['late']; ?></span>
                        <?php else: ?><span class="text-white/30">0</span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ((int)$row['wfh'] > 0): ?>
                            <span class="text-cyan-400"><?php echo (int)$row['wfh']; ?></span>
                        <?php else: ?><span class="text-white/30">0</span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ((int)$row['on_leave'] > 0): ?>
                            <span class="text-purple-400"><?php echo (int)$row['on_leave']; ?></span>
                        <?php else: ?><span class="text-white/30">0</span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ((int)$row['absent'] > 0): ?>
                            <span class="text-red-400 font-semibold"><?php echo (int)$row['absent']; ?></span>
                        <?php else: ?><span class="text-white/30">0</span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($no_data > 0): ?>
                            <span class="text-white/60"><?php echo $no_data; ?></span>
                        <?php else: ?><span class="text-white/30">0</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-4 rounded-[var(--tp-ios-card-radius)] border border-white/10 bg-white/5 p-5 text-xs text-white/60 space-y-1">
        <p><i class="fas fa-info-circle mr-1 text-violet-400" aria-hidden="true"></i> <strong class="text-white/90">คำอธิบายคอลัมน์:</strong></p>
        <p>• <strong class="text-white">มาทำงาน</strong> = ตรงเวลา + สาย + WFH + ครึ่งวัน (ไม่รวมลา/ขาดงาน)</p>
        <p>• <strong class="text-red-400">ขาดงาน</strong> = ไม่ได้เช็คอินและไม่ได้ลา (ระบบ cron มาร์คอัตโนมัติเวลา 20:01 ทุกวัน)</p>
        <p>• <strong class="text-white/80">ไม่มีข้อมูล</strong> = พนักงานที่ยังไม่มีแถวข้อมูลในวันนั้นเลย (ปกติจะเหลือ 0 หลัง cron ทำงาน)</p>
    </div>
    <?php else: ?>
    <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15">
        <i class="fas fa-calendar-day text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
        <p class="text-slate-400 text-sm">ไม่มีข้อมูลในช่วงเวลาที่เลือก</p>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($report === 'leave'): ?>
<div class="native-card tp-native-card tp-native-data-card overflow-hidden rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6 min-w-0 border border-white/10">
    <div class="mb-4 pb-4 border-b border-white/10">
        <h2 class="text-lg font-semibold text-white">
            รายงานการลารายบุคคล <?php echo formatDateThai($startDate); ?> - <?php echo formatDateThai($endDate); ?>
        </h2>
        <?php if ($reportData): ?>
        <p class="text-white/55 text-sm mt-2" role="status">พบ <?php echo count($reportData); ?> แถว (รายการลาต่อประเภท)</p>
        <?php endif; ?>
    </div>
    
    <?php if ($reportData): ?>
    <div class="md:hidden space-y-3">
        <?php foreach ($reportData as $row): ?>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-5">
            <p class="text-white font-semibold"><?php echo htmlspecialchars($row['full_name']); ?></p>
            <p class="text-slate-500 text-xs"><?php echo htmlspecialchars($row['position'] ?? '-'); ?></p>
            <p class="text-slate-400 text-sm mt-1"><?php echo htmlspecialchars($row['department'] ?? '-'); ?></p>
            <div class="flex items-center gap-2 mt-2">
                <div class="w-3 h-3 rounded-full shrink-0" style="background: <?php echo htmlspecialchars($row['color']); ?>"></div>
                <span class="text-white text-sm"><?php echo htmlspecialchars($row['leave_type']); ?></span>
            </div>
            <div class="grid grid-cols-2 gap-2 mt-3 text-center text-sm">
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2">
                    <div class="text-slate-500 text-xs">รวม</div>
                    <div class="text-white font-bold"><?php echo htmlspecialchars((string)$row['total_days']); ?></div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2">
                    <div class="text-emerald-500/80 text-xs">อนุมัติ</div>
                    <div class="text-emerald-400 font-semibold"><?php echo htmlspecialchars((string)$row['approved_days']); ?></div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2">
                    <div class="text-amber-500/80 text-xs">รออนุมัติ</div>
                    <div class="text-amber-400 font-semibold"><?php echo htmlspecialchars((string)$row['pending_days']); ?></div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2">
                    <div class="text-red-500/80 text-xs">ปฏิเสธ</div>
                    <div class="text-red-400 font-semibold"><?php echo htmlspecialchars((string)$row['rejected_days']); ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain -mx-1 px-1 pb-px">
        <table class="w-full text-sm" style="min-width:800px">
            <thead class="bg-white/5">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ชื่อ-นามสกุล</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">แผนก</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ประเภทการลา</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">รวม</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-emerald-400/90 uppercase">อนุมัติ</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-amber-400/90 uppercase">รออนุมัติ</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-red-400/90 uppercase">ปฏิเสธ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($reportData as $row): ?>
                <tr class="hover:bg-white/[0.04]">
                    <td class="px-4 py-3">
                        <p class="text-white font-medium"><?php echo htmlspecialchars($row['full_name']); ?></p>
                        <p class="text-white/50 text-xs"><?php echo htmlspecialchars($row['position'] ?? '-'); ?></p>
                    </td>
                    <td class="px-4 py-3 text-white/60"><?php echo htmlspecialchars($row['department'] ?? '-'); ?></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded-full shrink-0 ring-2 ring-white/15" style="background: <?php echo htmlspecialchars((string)$row['color']); ?>"></div>
                            <span class="text-white"><?php echo htmlspecialchars($row['leave_type']); ?></span>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center font-medium text-white"><?php echo htmlspecialchars((string)$row['total_days']); ?></td>
                    <td class="px-4 py-3 text-center text-emerald-400"><?php echo htmlspecialchars((string)$row['approved_days']); ?></td>
                    <td class="px-4 py-3 text-center text-amber-400"><?php echo htmlspecialchars((string)$row['pending_days']); ?></td>
                    <td class="px-4 py-3 text-center text-red-400"><?php echo htmlspecialchars((string)$row['rejected_days']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <?php
        $totalLeaveRows = (int)$pdo->query("SELECT COUNT(*) FROM hr_leave_requests")->fetchColumn();
    ?>
    <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15">
        <i class="fas fa-umbrella-beach text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
        <?php if ($totalLeaveRows === 0): ?>
            <p class="text-slate-300 font-medium mb-1">ยังไม่มีการยื่นใบลาในระบบ</p>
            <p class="text-slate-500 text-sm">เมื่อมีพนักงานยื่นใบลา รายการจะแสดงที่นี่</p>
        <?php else: ?>
            <p class="text-slate-300 font-medium mb-1">ไม่มีการลาในช่วงวันที่เลือก</p>
            <p class="text-slate-500 text-sm">ลองปรับช่วงวันที่ให้กว้างขึ้น หรือเลือก "ทั้งหมด" ในฟิลเตอร์แผนก</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($report === 'leave-summary'): ?>
<div class="native-card tp-native-card tp-native-data-card overflow-hidden rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6 min-w-0 border border-white/10">
    <div class="mb-4 pb-4 border-b border-white/10">
        <h2 class="text-lg font-semibold text-white">
            สรุปการลา <?php echo formatDateThai($startDate); ?> - <?php echo formatDateThai($endDate); ?>
        </h2>
        <?php if ($reportData): ?>
        <p class="text-white/55 text-sm mt-2" role="status"><?php echo count($reportData); ?> ประเภทการลาที่มีในช่วงนี้</p>
        <?php endif; ?>
    </div>
    
    <?php if ($reportData): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 mb-6">
        <?php foreach ($reportData as $row): ?>
        <div class="native-card tp-native-card rounded-[var(--tp-ios-card-radius)] border border-white/10 bg-white/5 p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-4 h-4 rounded-full" style="background: <?php echo $row['color']; ?>"></div>
                <h3 class="text-white font-medium"><?php echo htmlspecialchars($row['leave_type']); ?></h3>
            </div>
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div>
                    <p class="text-slate-500">พนักงาน</p>
                    <p class="text-white font-medium"><?php echo $row['employee_count']; ?> คน</p>
                </div>
                <div>
                    <p class="text-slate-500">รวมวัน</p>
                    <p class="text-white font-medium"><?php echo $row['total_days']; ?> วัน</p>
                </div>
                <div>
                    <p class="text-slate-500">อนุมัติ</p>
                    <p class="text-emerald-400 font-medium"><?php echo $row['approved_days']; ?> วัน</p>
                </div>
                <div>
                    <p class="text-slate-500">รออนุมัติ</p>
                    <p class="text-amber-400 font-medium"><?php echo $row['pending_count']; ?> รายการ</p>
                </div>
                <div>
                    <p class="text-slate-500">ปฏิเสธ</p>
                    <p class="text-red-400 font-medium"><?php echo $row['rejected_count']; ?> รายการ</p>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <?php $totalLeaveRows = (int)$pdo->query("SELECT COUNT(*) FROM hr_leave_requests")->fetchColumn(); ?>
    <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15">
        <i class="fas fa-chart-pie text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
        <?php if ($totalLeaveRows === 0): ?>
            <p class="text-slate-300 font-medium mb-1">ยังไม่มีการยื่นใบลาในระบบ</p>
            <p class="text-slate-500 text-sm">เมื่อมีพนักงานยื่นใบลา สรุปจะแสดงที่นี่</p>
        <?php else: ?>
            <p class="text-slate-300 font-medium mb-1">ไม่มีการลาในช่วงวันที่เลือก</p>
            <p class="text-slate-500 text-sm">ลองปรับช่วงวันที่ให้กว้างขึ้น</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
