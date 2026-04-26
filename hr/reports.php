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

// Get departments for filter
$departments = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);

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
            WHERE u.is_active = 1 AND u.id NOT IN (" . SYSTEM_USER_IDS_SQL . ")
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
              AND u.is_active = 1 AND u.id NOT IN (" . SYSTEM_USER_IDS_SQL . ")
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
              AND u.is_active = 1 AND u.id NOT IN (" . SYSTEM_USER_IDS_SQL . ")
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
                (SELECT COUNT(*) FROM users
                  WHERE is_active = 1 AND id NOT IN (" . SYSTEM_USER_IDS_SQL . ")) AS total_employees
            FROM hr_attendances a
            LEFT JOIN hr_holidays h ON h.date = a.attendance_date AND h.is_active = 1
            WHERE a.attendance_date BETWEEN ? AND ?
              AND a.user_id NOT IN (" . SYSTEM_USER_IDS_SQL . ")
              AND EXISTS (SELECT 1 FROM users u WHERE u.id = a.user_id AND u.is_active = 1)
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
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . $report . '_' . date('Y-m-d') . '.csv"');
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

require_once __DIR__ . '/../templates/header.php';
?>

<!-- Page Header -->
<div class="mb-6 min-w-0">
    <nav class="text-sm text-white/60 mb-3" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <span class="text-white">รายงาน</span>
    </nav>
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
    <div class="min-w-0">
        <h1 class="text-2xl font-bold text-white tracking-tight mb-2">
            <i class="fas fa-chart-bar text-primary-400 mr-2"></i>
            รายงาน
        </h1>
        <p class="text-slate-300 text-sm leading-relaxed">ดูรายงานการลงเวลาและการลา</p>
    </div>
    
    <?php if ($reportData): ?>
    <form method="post" action="/hr/reports.php" class="inline-flex">
        <?php echo csrfField(); ?>
        <input type="hidden" name="export_csv" value="1">
        <input type="hidden" name="report" value="<?php echo htmlspecialchars($report); ?>">
        <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
        <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
        <input type="hidden" name="department" value="<?php echo htmlspecialchars($department); ?>">
        <button type="submit" class="btn-secondary">
            <i class="fas fa-file-csv mr-2"></i>Export CSV
        </button>
    </form>
    <?php endif; ?>
    </div>
</div>

<!-- Report Type Tabs -->
<div class="flex gap-2 mb-6 overflow-x-auto pb-2 min-w-0">
    <a href="?report=attendance" class="px-4 py-2 rounded-lg whitespace-nowrap <?php echo $report === 'attendance' ? 'bg-primary-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-white'; ?>">
        <i class="fas fa-user-clock mr-2"></i>รายงานการลงเวลา
    </a>
    <a href="?report=daily" class="px-4 py-2 rounded-lg whitespace-nowrap <?php echo $report === 'daily' ? 'bg-primary-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-white'; ?>">
        <i class="fas fa-calendar-day mr-2"></i>รายงานรายวัน
    </a>
    <a href="?report=leave" class="px-4 py-2 rounded-lg whitespace-nowrap <?php echo $report === 'leave' ? 'bg-primary-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-white'; ?>">
        <i class="fas fa-umbrella-beach mr-2"></i>รายงานการลารายบุคคล
    </a>
    <a href="?report=leave-summary" class="px-4 py-2 rounded-lg whitespace-nowrap <?php echo $report === 'leave-summary' ? 'bg-primary-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-white'; ?>">
        <i class="fas fa-chart-pie mr-2"></i>สรุปการลา
    </a>
</div>

<!-- Filters -->
<div class="glass-card rounded-2xl p-4 mb-6">
    <form method="GET" class="flex flex-wrap items-end gap-4">
        <input type="hidden" name="report" value="<?php echo htmlspecialchars($report); ?>">
        
        <div>
            <label class="block text-slate-400 text-sm mb-1">วันที่เริ่มต้น</label>
            <input type="date" name="start_date" class="input-field" value="<?php echo $startDate; ?>">
        </div>
        
        <div>
            <label class="block text-slate-400 text-sm mb-1">วันที่สิ้นสุด</label>
            <input type="date" name="end_date" class="input-field" value="<?php echo $endDate; ?>">
        </div>
        
        <?php if ($report === 'attendance' || $report === 'leave'): ?>
        <div>
            <label class="block text-slate-400 text-sm mb-1">แผนก</label>
            <select name="department" class="input-field">
                <option value="">ทั้งหมด</option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department === $dept ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        
        <button type="submit" class="btn-primary">
            <i class="fas fa-search mr-2"></i>ค้นหา
        </button>
    </form>
</div>

<!-- Report Content -->
<?php if ($report === 'attendance'): ?>
<div class="glass-card rounded-2xl p-6">
    <h2 class="text-lg font-semibold text-white mb-4">
        รายงานการลงเวลา <?php echo formatDateThai($startDate); ?> - <?php echo formatDateThai($endDate); ?>
    </h2>
    
    <?php if ($reportData): ?>
    <div class="md:hidden space-y-3">
        <?php foreach ($reportData as $row): ?>
        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-white font-semibold truncate"><?php echo htmlspecialchars($row['full_name']); ?></p>
                    <p class="text-slate-500 text-xs truncate"><?php echo htmlspecialchars($row['employee_code'] ?? '-'); ?> · <?php echo htmlspecialchars($row['position'] ?? '-'); ?></p>
                    <p class="text-slate-400 text-sm mt-1"><?php echo htmlspecialchars($row['department'] ?? '-'); ?></p>
                </div>
            </div>
            <div class="grid grid-cols-3 gap-2 mt-3 text-center text-sm">
                <div class="rounded-lg bg-slate-900/40 border border-slate-700/40 py-2">
                    <div class="text-[10px] text-slate-500 uppercase">วันทำงาน</div>
                    <div class="text-white font-bold"><?php echo (int)$row['work_days']; ?></div>
                </div>
                <div class="rounded-lg bg-slate-900/40 border border-slate-700/40 py-2">
                    <div class="text-[10px] text-emerald-500/80">ตรงเวลา</div>
                    <div class="text-emerald-400 font-semibold"><?php echo (int)$row['present_days']; ?></div>
                </div>
                <div class="rounded-lg bg-slate-900/40 border border-slate-700/40 py-2">
                    <div class="text-[10px] text-amber-500/80">สาย</div>
                    <div class="text-amber-400 font-semibold"><?php echo (int)$row['late_days']; ?></div>
                </div>
                <div class="rounded-lg bg-slate-900/40 border border-slate-700/40 py-2">
                    <div class="text-[10px] text-cyan-500/80">WFH</div>
                    <div class="text-cyan-400 font-semibold"><?php echo (int)$row['wfh_days']; ?></div>
                </div>
                <div class="rounded-lg bg-slate-900/40 border border-slate-700/40 py-2">
                    <div class="text-[10px] text-purple-500/80">ลา</div>
                    <div class="text-purple-400 font-semibold"><?php echo (int)$row['leave_days']; ?></div>
                </div>
                <div class="rounded-lg bg-slate-900/40 border border-slate-700/40 py-2">
                    <div class="text-[10px] text-red-500/80">ขาด</div>
                    <div class="text-red-400 font-semibold"><?php echo (int)$row['absent_days']; ?></div>
                </div>
            </div>
            <div class="flex justify-between text-sm mt-3 text-slate-400">
                <span>เฉลี่ยเข้า <strong class="text-slate-200"><?php echo $row['avg_check_in'] ? substr($row['avg_check_in'], 0, 5) : '-'; ?></strong></span>
                <span>เฉลี่ยออก <strong class="text-slate-200"><?php echo $row['avg_check_out'] ? substr($row['avg_check_out'], 0, 5) : '-'; ?></strong></span>
            </div>
            <a href="/hr/employee_attendance.php?id=<?php echo (int)$row['id']; ?>"
               class="mt-3 flex min-h-[44px] items-center justify-center rounded-lg bg-violet-500/20 text-violet-300 hover:bg-violet-500/30 text-sm font-semibold touch-manipulation">
                <i class="fas fa-clock mr-2"></i>ดูประวัติลงเวลา
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="hidden md:block overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>รหัส</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>แผนก</th>
                    <th class="text-center" title="PRESENT + LATE + WFH + HALF_DAY">วันทำงาน</th>
                    <th class="text-center text-emerald-400">ตรงเวลา</th>
                    <th class="text-center text-amber-400">มาสาย</th>
                    <th class="text-center text-cyan-400">WFH</th>
                    <th class="text-center text-purple-400">ลา</th>
                    <th class="text-center text-red-400">ขาดงาน</th>
                    <th>เฉลี่ยเข้า</th>
                    <th>เฉลี่ยออก</th>
                    <th class="text-center">รายละเอียด</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData as $row): ?>
                <tr>
                    <td class="text-slate-400"><?php echo htmlspecialchars($row['employee_code'] ?? '-'); ?></td>
                    <td>
                        <p class="text-white font-medium"><?php echo htmlspecialchars($row['full_name']); ?></p>
                        <p class="text-slate-500 text-xs"><?php echo htmlspecialchars($row['position'] ?? '-'); ?></p>
                    </td>
                    <td class="text-slate-400"><?php echo htmlspecialchars($row['department'] ?? '-'); ?></td>
                    <td class="text-center">
                        <span class="text-white font-semibold"><?php echo (int)$row['work_days']; ?></span>
                    </td>
                    <td class="text-center">
                        <?php if ((int)$row['present_days'] > 0): ?>
                            <span class="text-emerald-400"><?php echo (int)$row['present_days']; ?></span>
                        <?php else: ?><span class="text-slate-600">0</span><?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ((int)$row['late_days'] > 0): ?>
                            <span class="text-amber-400 font-medium"><?php echo (int)$row['late_days']; ?></span>
                        <?php else: ?><span class="text-slate-600">0</span><?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ((int)$row['wfh_days'] > 0): ?>
                            <span class="text-cyan-400"><?php echo (int)$row['wfh_days']; ?></span>
                        <?php else: ?><span class="text-slate-600">0</span><?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ((int)$row['leave_days'] > 0): ?>
                            <span class="text-purple-400"><?php echo (int)$row['leave_days']; ?></span>
                        <?php else: ?><span class="text-slate-600">0</span><?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ((int)$row['absent_days'] > 0): ?>
                            <span class="text-red-400 font-semibold"><?php echo (int)$row['absent_days']; ?></span>
                        <?php else: ?><span class="text-slate-600">0</span><?php endif; ?>
                    </td>
                    <td class="text-slate-300">
                        <?php echo $row['avg_check_in'] ? substr($row['avg_check_in'], 0, 5) : '-'; ?>
                    </td>
                    <td class="text-slate-300">
                        <?php echo $row['avg_check_out'] ? substr($row['avg_check_out'], 0, 5) : '-'; ?>
                    </td>
                    <td class="text-center">
                        <a href="/hr/employee_attendance.php?id=<?php echo $row['id']; ?>" 
                           class="px-3 py-1 bg-violet-500/20 text-violet-400 hover:bg-violet-500/30 text-xs rounded-lg transition-colors"
                           title="ดูประวัติลงเวลา">
                            <i class="fas fa-clock mr-1"></i>ดู
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="text-center py-12">
        <i class="fas fa-chart-bar text-slate-600 text-5xl mb-4"></i>
        <p class="text-slate-400">ไม่มีข้อมูลในช่วงเวลาที่เลือก</p>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($report === 'daily'): ?>
<div class="glass-card rounded-2xl p-6">
    <div class="flex items-start justify-between gap-4 mb-4">
        <div>
            <h2 class="text-lg font-semibold text-white">
                รายงานรายวัน <?php echo formatDateThai($startDate); ?> - <?php echo formatDateThai($endDate); ?>
            </h2>
            <p class="text-slate-400 text-sm mt-1">
                สรุปจำนวนพนักงานในแต่ละวันตามสถานะการลงเวลา
                (จำนวนพนักงานที่ใช้งานอยู่ทั้งหมด: <span class="text-white font-medium"><?php echo $reportData[0]['total_employees'] ?? 0; ?></span> คน)
            </p>
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
        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-white font-semibold"><?php echo formatDateThai($row['attendance_date']); ?></span>
                <?php if ($row['holiday_name']): ?>
                    <span class="px-2 py-0.5 text-xs rounded bg-rose-900/40 text-rose-300" title="<?php echo htmlspecialchars($row['holiday_name']); ?>">วันหยุด</span>
                <?php elseif ($isWeekend): ?>
                    <span class="px-2 py-0.5 text-xs rounded bg-slate-800 text-slate-400">สุดสัปดาห์</span>
                <?php endif; ?>
            </div>
            <div class="grid grid-cols-4 gap-2 mt-3 text-center text-xs">
                <div class="rounded-lg bg-slate-900/40 border border-slate-700/40 py-2">
                    <div class="text-slate-500">มาทำงาน</div>
                    <div class="text-white font-bold"><?php echo (int)$row['worked']; ?></div>
                </div>
                <div class="rounded-lg bg-slate-900/40 border border-slate-700/40 py-2">
                    <div class="text-emerald-500/80">ตรงเวลา</div>
                    <div class="text-emerald-400 font-semibold"><?php echo (int)$row['on_time']; ?></div>
                </div>
                <div class="rounded-lg bg-slate-900/40 border border-slate-700/40 py-2">
                    <div class="text-amber-500/80">สาย</div>
                    <div class="text-amber-400 font-semibold"><?php echo (int)$row['late']; ?></div>
                </div>
                <div class="rounded-lg bg-slate-900/40 border border-slate-700/40 py-2">
                    <div class="text-cyan-500/80">WFH</div>
                    <div class="text-cyan-400 font-semibold"><?php echo (int)$row['wfh']; ?></div>
                </div>
                <div class="rounded-lg bg-slate-900/40 border border-slate-700/40 py-2">
                    <div class="text-purple-500/80">ลา</div>
                    <div class="text-purple-400 font-semibold"><?php echo (int)$row['on_leave']; ?></div>
                </div>
                <div class="rounded-lg bg-slate-900/40 border border-slate-700/40 py-2">
                    <div class="text-red-500/80">ขาด</div>
                    <div class="text-red-400 font-semibold"><?php echo (int)$row['absent']; ?></div>
                </div>
                <div class="rounded-lg bg-slate-900/40 border border-slate-700/40 py-2 col-span-2">
                    <div class="text-slate-500">ไม่มีข้อมูล</div>
                    <div class="text-slate-300 font-semibold"><?php echo $no_data; ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="hidden md:block overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>วันที่</th>
                    <th class="text-center" title="มาทำงาน = ตรงเวลา + สาย + WFH + ครึ่งวัน">
                        มาทำงาน
                        <i class="fas fa-info-circle text-slate-500 ml-1 text-xs"></i>
                    </th>
                    <th class="text-center text-emerald-400">ตรงเวลา</th>
                    <th class="text-center text-amber-400">สาย</th>
                    <th class="text-center text-cyan-400">WFH</th>
                    <th class="text-center text-purple-400">ลา</th>
                    <th class="text-center text-red-400">ขาดงาน</th>
                    <th class="text-center text-slate-400" title="พนักงานที่ยังไม่มีข้อมูลการลงเวลาในวันนี้ (อาจยังไม่ถึงเวลา cron หรือข้อมูลหาย)">
                        ไม่มีข้อมูล
                        <i class="fas fa-info-circle text-slate-500 ml-1 text-xs"></i>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData as $row):
                    $total = (int)$row['total_employees'];
                    $recorded = (int)$row['worked'] + (int)$row['absent'] + (int)$row['on_leave'] + (int)$row['holiday_off'];
                    $no_data = max(0, $total - $recorded);
                    $dow = (int)$row['dow']; // 1=Sun..7=Sat
                    $isWeekend = ($dow === 1 || $dow === 7);
                ?>
                <tr>
                    <td class="text-white">
                        <div class="flex items-center gap-2">
                            <span><?php echo formatDateThai($row['attendance_date']); ?></span>
                            <?php if ($row['holiday_name']): ?>
                                <span class="px-2 py-0.5 text-xs rounded bg-rose-900/40 text-rose-300" title="<?php echo htmlspecialchars($row['holiday_name']); ?>">
                                    วันหยุด
                                </span>
                            <?php elseif ($isWeekend): ?>
                                <span class="px-2 py-0.5 text-xs rounded bg-slate-800 text-slate-400">สุดสัปดาห์</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="text-center">
                        <span class="text-white font-semibold"><?php echo (int)$row['worked']; ?></span>
                    </td>
                    <td class="text-center"><span class="text-emerald-400"><?php echo (int)$row['on_time']; ?></span></td>
                    <td class="text-center">
                        <?php if ((int)$row['late'] > 0): ?>
                            <span class="text-amber-400 font-medium"><?php echo (int)$row['late']; ?></span>
                        <?php else: ?><span class="text-slate-600">0</span><?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ((int)$row['wfh'] > 0): ?>
                            <span class="text-cyan-400"><?php echo (int)$row['wfh']; ?></span>
                        <?php else: ?><span class="text-slate-600">0</span><?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ((int)$row['on_leave'] > 0): ?>
                            <span class="text-purple-400"><?php echo (int)$row['on_leave']; ?></span>
                        <?php else: ?><span class="text-slate-600">0</span><?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ((int)$row['absent'] > 0): ?>
                            <span class="text-red-400 font-semibold"><?php echo (int)$row['absent']; ?></span>
                        <?php else: ?><span class="text-slate-600">0</span><?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($no_data > 0): ?>
                            <span class="text-slate-400"><?php echo $no_data; ?></span>
                        <?php else: ?><span class="text-slate-600">0</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-4 p-3 rounded-lg bg-slate-800/50 text-xs text-slate-400 space-y-1">
        <p><i class="fas fa-info-circle mr-1"></i> <strong class="text-slate-300">คำอธิบายคอลัมน์:</strong></p>
        <p>• <strong class="text-white">มาทำงาน</strong> = ตรงเวลา + สาย + WFH + ครึ่งวัน (ไม่รวมลา/ขาดงาน)</p>
        <p>• <strong class="text-red-400">ขาดงาน</strong> = ไม่ได้เช็คอินและไม่ได้ลา (ระบบ cron มาร์คอัตโนมัติเวลา 20:01 ทุกวัน)</p>
        <p>• <strong class="text-slate-400">ไม่มีข้อมูล</strong> = พนักงานที่ยังไม่มีแถวข้อมูลในวันนั้นเลย (ปกติจะเหลือ 0 หลัง cron ทำงาน)</p>
    </div>
    <?php else: ?>
    <div class="text-center py-12">
        <i class="fas fa-calendar-day text-slate-600 text-5xl mb-4"></i>
        <p class="text-slate-400">ไม่มีข้อมูลในช่วงเวลาที่เลือก</p>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($report === 'leave'): ?>
<div class="glass-card rounded-2xl p-6">
    <h2 class="text-lg font-semibold text-white mb-4">
        รายงานการลารายบุคคล <?php echo formatDateThai($startDate); ?> - <?php echo formatDateThai($endDate); ?>
    </h2>
    
    <?php if ($reportData): ?>
    <div class="md:hidden space-y-3">
        <?php foreach ($reportData as $row): ?>
        <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-4">
            <p class="text-white font-semibold"><?php echo htmlspecialchars($row['full_name']); ?></p>
            <p class="text-slate-500 text-xs"><?php echo htmlspecialchars($row['position'] ?? '-'); ?></p>
            <p class="text-slate-400 text-sm mt-1"><?php echo htmlspecialchars($row['department'] ?? '-'); ?></p>
            <div class="flex items-center gap-2 mt-2">
                <div class="w-3 h-3 rounded-full shrink-0" style="background: <?php echo htmlspecialchars($row['color']); ?>"></div>
                <span class="text-white text-sm"><?php echo htmlspecialchars($row['leave_type']); ?></span>
            </div>
            <div class="grid grid-cols-2 gap-2 mt-3 text-center text-sm">
                <div class="rounded-lg bg-slate-900/40 border border-slate-700/40 py-2">
                    <div class="text-slate-500 text-xs">รวม</div>
                    <div class="text-white font-bold"><?php echo htmlspecialchars((string)$row['total_days']); ?></div>
                </div>
                <div class="rounded-lg bg-slate-900/40 border border-slate-700/40 py-2">
                    <div class="text-emerald-500/80 text-xs">อนุมัติ</div>
                    <div class="text-emerald-400 font-semibold"><?php echo htmlspecialchars((string)$row['approved_days']); ?></div>
                </div>
                <div class="rounded-lg bg-slate-900/40 border border-slate-700/40 py-2">
                    <div class="text-amber-500/80 text-xs">รออนุมัติ</div>
                    <div class="text-amber-400 font-semibold"><?php echo htmlspecialchars((string)$row['pending_days']); ?></div>
                </div>
                <div class="rounded-lg bg-slate-900/40 border border-slate-700/40 py-2">
                    <div class="text-red-500/80 text-xs">ปฏิเสธ</div>
                    <div class="text-red-400 font-semibold"><?php echo htmlspecialchars((string)$row['rejected_days']); ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="hidden md:block overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ชื่อ-นามสกุล</th>
                    <th>แผนก</th>
                    <th>ประเภทการลา</th>
                    <th class="text-center">รวม</th>
                    <th class="text-center">อนุมัติ</th>
                    <th class="text-center">รออนุมัติ</th>
                    <th class="text-center">ปฏิเสธ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData as $row): ?>
                <tr>
                    <td>
                        <p class="text-white font-medium"><?php echo htmlspecialchars($row['full_name']); ?></p>
                        <p class="text-slate-500 text-xs"><?php echo htmlspecialchars($row['position'] ?? '-'); ?></p>
                    </td>
                    <td class="text-slate-400"><?php echo htmlspecialchars($row['department'] ?? '-'); ?></td>
                    <td>
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded-full" style="background: <?php echo $row['color']; ?>"></div>
                            <span class="text-white"><?php echo htmlspecialchars($row['leave_type']); ?></span>
                        </div>
                    </td>
                    <td class="text-center text-white font-medium"><?php echo $row['total_days']; ?></td>
                    <td class="text-center text-emerald-400"><?php echo $row['approved_days']; ?></td>
                    <td class="text-center text-amber-400"><?php echo $row['pending_days']; ?></td>
                    <td class="text-center text-red-400"><?php echo $row['rejected_days']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <?php
        $totalLeaveRows = (int)$pdo->query("SELECT COUNT(*) FROM hr_leave_requests")->fetchColumn();
    ?>
    <div class="text-center py-12">
        <i class="fas fa-umbrella-beach text-slate-600 text-5xl mb-4"></i>
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
<div class="glass-card rounded-2xl p-6">
    <h2 class="text-lg font-semibold text-white mb-4">
        สรุปการลา <?php echo formatDateThai($startDate); ?> - <?php echo formatDateThai($endDate); ?>
    </h2>
    
    <?php if ($reportData): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 mb-6">
        <?php foreach ($reportData as $row): ?>
        <div class="bg-slate-800/50 rounded-xl p-4">
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
    <div class="text-center py-12">
        <i class="fas fa-chart-pie text-slate-600 text-5xl mb-4"></i>
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

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
