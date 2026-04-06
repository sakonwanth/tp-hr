<?php
/**
 * HR Reports - Attendance & Leave Reports
 */

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Auth::requireHR();

$pdo = getDB();
$user = Auth::user();

$page_title = 'รายงาน';
$current_page = 'hr-reports';

// Get report type
$report = $_GET['report'] ?? 'attendance';
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$department = $_GET['department'] ?? '';
$exportFormat = $_GET['export'] ?? '';

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
        $sql = "
            SELECT 
                u.id,
                u.employee_code,
                CONCAT(u.first_name_th, ' ', u.last_name_th) as full_name,
                u.department,
                u.position,
                COUNT(DISTINCT a.attendance_date) as work_days,
                SUM(CASE WHEN a.status = 'LATE' THEN 1 ELSE 0 END) as late_days,
                SUM(CASE WHEN a.status = 'ABSENT' THEN 1 ELSE 0 END) as absent_days,
                SUM(CASE WHEN a.check_out_time IS NOT NULL THEN 1 ELSE 0 END) as checked_out_days,
                SEC_TO_TIME(AVG(TIME_TO_SEC(a.check_in_time))) as avg_check_in,
                SEC_TO_TIME(AVG(TIME_TO_SEC(a.check_out_time))) as avg_check_out,
                SEC_TO_TIME(SUM(TIME_TO_SEC(TIMEDIFF(a.check_out_time, a.check_in_time)))) as total_hours
            FROM users u
            LEFT JOIN hr_attendances a ON u.id = a.user_id 
                AND a.attendance_date BETWEEN ? AND ?
            WHERE u.is_active = 1
            " . ($conditions ? " AND " . implode(" AND ", $conditions) : "") . "
            GROUP BY u.id
            ORDER BY u.department, u.first_name_th
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$startDate, $endDate], $params));
        $reportData = $stmt->fetchAll();
        break;
        
    case 'leave':
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
                SUM(CASE WHEN lr.status = 'PENDING' THEN lr.total_days ELSE 0 END) as pending_days,
                SUM(CASE WHEN lr.status = 'REJECTED' THEN lr.total_days ELSE 0 END) as rejected_days
            FROM users u
            JOIN hr_leave_requests lr ON u.id = lr.user_id
            JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
            WHERE lr.start_date >= ? AND lr.end_date <= ?
            " . ($conditions ? " AND " . implode(" AND ", $conditions) : "") . "
            GROUP BY u.id, lt.id
            ORDER BY u.department, u.first_name_th, lt.sort_order
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$startDate, $endDate], $params));
        $reportData = $stmt->fetchAll();
        break;
        
    case 'leave-summary':
        $sql = "
            SELECT 
                lt.name as leave_type,
                lt.color,
                COUNT(DISTINCT lr.user_id) as employee_count,
                SUM(lr.total_days) as total_days,
                SUM(CASE WHEN lr.status = 'APPROVED' THEN lr.total_days ELSE 0 END) as approved_days,
                SUM(CASE WHEN lr.status = 'PENDING' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN lr.status = 'REJECTED' THEN 1 ELSE 0 END) as rejected_count
            FROM hr_leave_requests lr
            JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
            WHERE lr.start_date >= ? AND lr.end_date <= ?
            GROUP BY lt.id
            ORDER BY lt.sort_order
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$startDate, $endDate]);
        $reportData = $stmt->fetchAll();
        break;
        
    case 'daily':
        $sql = "
            SELECT 
                a.attendance_date,
                COUNT(DISTINCT a.user_id) as total_present,
                SUM(CASE WHEN a.status = 'PRESENT' THEN 1 ELSE 0 END) as on_time,
                SUM(CASE WHEN a.status = 'LATE' THEN 1 ELSE 0 END) as late,
                (SELECT COUNT(*) FROM users WHERE is_active = 1) - COUNT(DISTINCT a.user_id) as absent,
                (SELECT COUNT(*) FROM hr_leave_requests WHERE status = 'APPROVED' 
                    AND a.attendance_date BETWEEN start_date AND end_date) as on_leave
            FROM hr_attendances a
            WHERE a.attendance_date BETWEEN ? AND ?
            GROUP BY a.attendance_date
            ORDER BY a.attendance_date DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$startDate, $endDate]);
        $reportData = $stmt->fetchAll();
        break;
}

// Export to CSV
if ($exportFormat === 'csv' && $reportData) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . $report . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for UTF-8
    
    // Add headers
    if ($reportData) {
        fputcsv($output, array_keys($reportData[0]));
        foreach ($reportData as $row) {
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit;
}

require_once __DIR__ . '/../templates/header.php';
?>

<!-- Page Header -->
<div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-white mb-2">
            <i class="fas fa-chart-bar text-primary-400 mr-2"></i>
            รายงาน
        </h1>
        <p class="text-slate-400">ดูรายงานการลงเวลาและการลา</p>
    </div>
    
    <?php if ($reportData): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn-secondary">
        <i class="fas fa-file-csv mr-2"></i>Export CSV
    </a>
    <?php endif; ?>
</div>

<!-- Report Type Tabs -->
<div class="flex gap-2 mb-6 overflow-x-auto pb-2">
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
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>รหัส</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>แผนก</th>
                    <th class="text-center">วันทำงาน</th>
                    <th class="text-center">มาสาย</th>
                    <th class="text-center">ขาดงาน</th>
                    <th>เวลาเฉลี่ยเข้า</th>
                    <th>เวลาเฉลี่ยออก</th>
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
                        <span class="text-emerald-400 font-medium"><?php echo $row['work_days']; ?></span>
                    </td>
                    <td class="text-center">
                        <?php if ($row['late_days'] > 0): ?>
                        <span class="text-amber-400 font-medium"><?php echo $row['late_days']; ?></span>
                        <?php else: ?>
                        <span class="text-slate-500">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($row['absent_days'] > 0): ?>
                        <span class="text-red-400 font-medium"><?php echo $row['absent_days']; ?></span>
                        <?php else: ?>
                        <span class="text-slate-500">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-slate-300">
                        <?php echo $row['avg_check_in'] ? substr($row['avg_check_in'], 0, 5) : '-'; ?>
                    </td>
                    <td class="text-slate-300">
                        <?php echo $row['avg_check_out'] ? substr($row['avg_check_out'], 0, 5) : '-'; ?>
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
    <h2 class="text-lg font-semibold text-white mb-4">
        รายงานรายวัน <?php echo formatDateThai($startDate); ?> - <?php echo formatDateThai($endDate); ?>
    </h2>
    
    <?php if ($reportData): ?>
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>วันที่</th>
                    <th class="text-center">มาทำงาน</th>
                    <th class="text-center">ตรงเวลา</th>
                    <th class="text-center">สาย</th>
                    <th class="text-center">ขาดงาน</th>
                    <th class="text-center">ลา</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData as $row): ?>
                <tr>
                    <td class="text-white"><?php echo formatDateThai($row['attendance_date']); ?></td>
                    <td class="text-center">
                        <span class="text-emerald-400 font-medium"><?php echo $row['total_present']; ?></span>
                    </td>
                    <td class="text-center">
                        <span class="text-blue-400"><?php echo $row['on_time']; ?></span>
                    </td>
                    <td class="text-center">
                        <?php if ($row['late'] > 0): ?>
                        <span class="text-amber-400"><?php echo $row['late']; ?></span>
                        <?php else: ?>
                        <span class="text-slate-500">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($row['absent'] > 0): ?>
                        <span class="text-red-400"><?php echo $row['absent']; ?></span>
                        <?php else: ?>
                        <span class="text-slate-500">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="text-purple-400"><?php echo $row['on_leave']; ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
    <div class="overflow-x-auto">
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
    <div class="text-center py-12">
        <i class="fas fa-umbrella-beach text-slate-600 text-5xl mb-4"></i>
        <p class="text-slate-400">ไม่มีข้อมูลการลาในช่วงเวลาที่เลือก</p>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($report === 'leave-summary'): ?>
<div class="glass-card rounded-2xl p-6">
    <h2 class="text-lg font-semibold text-white mb-4">
        สรุปการลา <?php echo formatDateThai($startDate); ?> - <?php echo formatDateThai($endDate); ?>
    </h2>
    
    <?php if ($reportData): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
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
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="text-center py-12">
        <i class="fas fa-chart-pie text-slate-600 text-5xl mb-4"></i>
        <p class="text-slate-400">ไม่มีข้อมูลการลาในช่วงเวลาที่เลือก</p>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
