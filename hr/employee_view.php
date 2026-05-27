<?php
/**
 * HR Employee Profile View (read-only)
 * แสดงข้อมูลพนักงานแบบอ่านอย่างเดียว
 */
$page_title = 'ดูข้อมูลพนักงาน';
require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();
if (!hr_can_access_hr_dashboard()) { redirect('/', 302); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { redirect('/hr/employees.php', 302); }

$pdo = Database::getInstance()->getConnection();

$stmt = $pdo->prepare("
    SELECT u.*, r.name AS role_name,
           s.day_off AS scheduled_day_off
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    LEFT JOIN hr_employee_schedules s ON s.user_id = u.id
    WHERE u.id = ? LIMIT 1
");
$stmt->execute([$id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$emp) { flash('error', 'ไม่พบพนักงาน'); redirect('/hr/employees.php', 302); }

// Summary stats
$statsStmt = $pdo->prepare("
    SELECT
        (SELECT COUNT(*) FROM hr_attendances WHERE user_id = ? AND YEAR(attendance_date) = YEAR(CURDATE())) AS att_this_year,
        (SELECT COALESCE(SUM(total_days),0) FROM hr_leave_requests WHERE user_id = ? AND status='APPROVED' AND YEAR(start_date)=YEAR(CURDATE())) AS leaves_this_year,
        (SELECT COUNT(*) FROM hr_leave_requests WHERE user_id = ? AND status='PENDING') AS pending_leaves
");
$statsStmt->execute([$id, $id, $id]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Today's attendance
$todayStmt = $pdo->prepare("SELECT status, check_in_time, check_out_time FROM hr_attendances WHERE user_id = ? AND attendance_date = CURDATE() LIMIT 1");
$todayStmt->execute([$id]);
$today = $todayStmt->fetch(PDO::FETCH_ASSOC);

$dayNames = [0=>'อาทิตย์',1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์',6=>'เสาร์'];
$empTypeLabel = [
    'PROBATION' => 'ทดลองงาน', 'PERMANENT' => 'พนักงานประจำ',
    'CONTRACT' => 'สัญญาจ้าง', 'PARTTIME' => 'พาร์ทไทม์',
];
$genderLabel = ['M' => 'ชาย', 'F' => 'หญิง', 'MALE' => 'ชาย', 'FEMALE' => 'หญิง', 'OTHER' => 'อื่นๆ'];
$maritalLabel = ['SINGLE' => 'โสด', 'MARRIED' => 'สมรส', 'DIVORCED' => 'หย่า', 'WIDOWED' => 'หม้าย', 'SEPARATED' => 'แยกกันอยู่'];

$fullName = trim(($emp['title'] ?? '') . ' ' . ($emp['first_name_th'] ?? '') . ' ' . ($emp['last_name_th'] ?? ''));
$fullNameEn = trim(($emp['first_name_en'] ?? '') . ' ' . ($emp['last_name_en'] ?? ''));

$current_page = 'hr-employees';
include dirname(__DIR__) . '/templates/header.php';

$summaryMonth = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $summaryMonth)) {
    $summaryMonth = date('Y-m');
}
$summaryService = new EmployeeSummaryService($pdo);
$monthlySummary = $summaryService->getMonthlySummary($id, $summaryMonth);
$employeeId = $id;
$showMonthPicker = true;
$preserveQuery = ['id' => $id];
$summary = $monthlySummary;
$attendanceReturnUrl = '/hr/employee_view.php?' . http_build_query(['id' => $id, 'month' => $summaryMonth]);

// Helper: row output
if (!function_exists('tp_hr_emp_view_row')) {
    function tp_hr_emp_view_row($label, $value, $icon = null) {
        $iconHtml = $icon ? '<i class="fas fa-' . htmlspecialchars($icon) . ' text-white/50 mr-2"></i>' : '';
        $v = ($value === null || $value === '') ? '<span class="text-white/40">-</span>' : htmlspecialchars((string)$value);
        echo '<div class="flex justify-between items-start gap-4 py-2.5 border-b border-white/[0.06] min-w-0">'
            . '<span class="text-white/65 text-sm shrink-0">' . $iconHtml . htmlspecialchars($label) . '</span>'
            . '<span class="text-white text-sm text-right min-w-0 break-words">' . $v . '</span>'
            . '</div>';
    }
}
?>

<div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <a href="/hr/employees.php" class="hover:text-white touch-manipulation">จัดการพนักงาน</a>
        <span class="mx-2">/</span>
        <span class="text-white">ดูข้อมูล</span>
    </nav>
    <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between gap-y-4">
        <div class="min-w-0 flex-1">
            <h1 class="tp-ios-page-title"><?php echo htmlspecialchars($page_title); ?></h1>
            <p class="tp-ios-caption-muted mt-2"><?php echo htmlspecialchars($fullName); ?></p>
        </div>
        <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto shrink-0">
            <a href="/hr/employee_summaries.php?month=<?php echo urlencode($summaryMonth); ?>"
               class="inline-flex items-center justify-center min-h-[48px] px-4 py-2 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] transition-colors font-medium touch-manipulation">
                <i class="fas fa-chart-bar mr-2" aria-hidden="true"></i>สรุปทีม
            </a>
            <a href="/hr/employee_attendance.php?id=<?php echo (int)$emp['id']; ?>&month=<?php echo urlencode($summaryMonth); ?>"
               class="inline-flex items-center justify-center min-h-[48px] px-4 py-2 bg-violet-500/15 hover:bg-violet-500/25 border border-violet-500/25 text-violet-200 rounded-[var(--tp-ios-card-radius)] transition-colors font-medium touch-manipulation">
                <i class="fas fa-clock mr-2" aria-hidden="true"></i>ประวัติลงเวลา
            </a>
            <?php if (canManageUsers()): ?>
            <a href="/hr/employee_form.php?action=edit&id=<?php echo (int)$emp['id']; ?>"
               class="inline-flex items-center justify-center min-h-[56px] px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-[var(--tp-ios-card-radius)] transition-colors font-semibold touch-manipulation">
                <i class="fas fa-edit mr-2" aria-hidden="true"></i>แก้ไข
            </a>
            <?php endif; ?>
            <a href="/hr/employees.php" class="inline-flex items-center justify-center min-h-[48px] px-4 py-2 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] transition-colors font-medium touch-manipulation">
                <i class="fas fa-arrow-left mr-2" aria-hidden="true"></i>กลับ
            </a>
        </div>
    </div>
</header>

<!-- Profile Header -->
<div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 mb-6 min-w-0 max-w-full overflow-hidden">
    <div class="flex flex-col md:flex-row gap-6 items-start">
        <div class="flex-shrink-0 mx-auto md:mx-0">
            <?php if (!empty($emp['avatar'])): ?>
                <img src="<?php echo htmlspecialchars($emp['avatar']); ?>" alt="" class="w-32 h-32 rounded-full object-cover border-2 border-white/20">
            <?php else: ?>
                <div class="w-32 h-32 rounded-full bg-violet-600/30 flex items-center justify-center text-white text-5xl font-bold" aria-hidden="true">
                    <?php echo htmlspecialchars(mb_substr($emp['first_name_th'] ?? '?', 0, 1)); ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="flex-1 min-w-0 text-center md:text-left">
            <div class="flex flex-wrap items-center justify-center md:justify-start gap-2 mb-2">
                <h2 class="text-2xl font-bold text-white tracking-tight"><?php echo htmlspecialchars($fullName); ?></h2>
                <?php if (($emp['work_mode'] ?? 'OFFICE') === 'WFH'): ?>
                    <span class="px-3 py-1 rounded-[var(--tp-ios-card-radius)] text-xs font-medium bg-blue-500/20 text-blue-300 border border-blue-500/20"><i class="fas fa-home mr-1" aria-hidden="true"></i>WFH</span>
                <?php else: ?>
                    <span class="px-3 py-1 rounded-[var(--tp-ios-card-radius)] text-xs font-medium bg-slate-500/20 text-slate-300 border border-white/10"><i class="fas fa-building mr-1" aria-hidden="true"></i>Office</span>
                <?php endif; ?>
                <?php if ((int)$emp['is_active'] === 1): ?>
                    <span class="px-3 py-1 rounded-[var(--tp-ios-card-radius)] text-xs font-medium bg-emerald-500/20 text-emerald-300 border border-emerald-500/25">ทำงาน</span>
                <?php else: ?>
                    <span class="px-3 py-1 rounded-[var(--tp-ios-card-radius)] text-xs font-medium bg-red-500/20 text-red-300 border border-red-500/25">พ้นสภาพ</span>
                <?php endif; ?>
            </div>
            <?php if ($fullNameEn): ?>
            <p class="text-white/60 mb-1 text-sm"><?php echo htmlspecialchars($fullNameEn); ?><?php echo !empty($emp['nickname']) ? ' (' . htmlspecialchars($emp['nickname']) . ')' : ''; ?></p>
            <?php endif; ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2 mt-3 text-sm">
                <div class="text-white/80 min-w-0 break-words"><i class="fas fa-id-badge text-white/50 mr-2 shrink-0" aria-hidden="true"></i><?php echo htmlspecialchars($emp['employee_code'] ?? '-'); ?></div>
                <div class="text-white/80 min-w-0 break-words"><i class="fas fa-building text-white/50 mr-2 shrink-0" aria-hidden="true"></i><?php echo htmlspecialchars($emp['department'] ?? '-'); ?></div>
                <div class="text-white/80 min-w-0 break-words"><i class="fas fa-briefcase text-white/50 mr-2 shrink-0" aria-hidden="true"></i><?php echo htmlspecialchars($emp['position'] ?? '-'); ?></div>
                <div class="text-white/80 min-w-0 break-words"><i class="fas fa-envelope text-white/50 mr-2 shrink-0" aria-hidden="true"></i><?php echo htmlspecialchars($emp['email'] ?? '-'); ?></div>
                <div class="text-white/80 min-w-0 break-words"><i class="fas fa-phone text-white/50 mr-2 shrink-0" aria-hidden="true"></i><?php echo htmlspecialchars($emp['phone'] ?? '-'); ?></div>
                <div class="text-white/80 min-w-0 break-words"><i class="fas fa-user-shield text-white/50 mr-2 shrink-0" aria-hidden="true"></i><?php echo htmlspecialchars($emp['role_name'] ?? '-'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 sm:gap-6 mb-6 md:mb-8 min-w-0 max-w-full">
    <div class="stat-card tp-native-summary-card group min-w-0">
        <p class="text-slate-300 text-xs">สถานะวันนี้</p>
        <p class="text-white text-lg font-bold mt-1 min-h-[1.75rem]">
            <?php
            if ($today) {
                $st = (string)($today['status'] ?? '');
                $map = [
                    'PRESENT' => ['th' => 'มาตรงเวลา', 'cls' => 'text-emerald-400'],
                    'LATE' => ['th' => 'มาสาย', 'cls' => 'text-amber-400'],
                    'WFH' => ['th' => 'WFH', 'cls' => 'text-blue-400'],
                    'LEAVE' => ['th' => 'ลา', 'cls' => 'text-violet-400'],
                    'ABSENT' => ['th' => 'ขาด', 'cls' => 'text-red-400'],
                    'HOLIDAY' => ['th' => 'วันหยุด', 'cls' => 'text-slate-400'],
                    'HALF_DAY' => ['th' => 'ครึ่งวัน', 'cls' => 'text-orange-400'],
                ];
                $info = $map[$st] ?? ['th' => $st, 'cls' => 'text-white/80'];
                echo '<span class="' . htmlspecialchars($info['cls']) . '">' . htmlspecialchars($info['th']) . '</span>';
            } else {
                echo '<span class="text-white/45">ยังไม่มีข้อมูล</span>';
            }
            ?>
        </p>
    </div>
    <div class="stat-card tp-native-summary-card group min-w-0">
        <p class="text-slate-300 text-xs">ลงเวลาปีนี้ (วัน)</p>
        <p class="text-white text-lg font-bold mt-1 tabular-nums"><?php echo number_format((int)($stats['att_this_year'] ?? 0)); ?></p>
    </div>
    <div class="stat-card tp-native-summary-card group min-w-0">
        <p class="text-slate-300 text-xs">วันลาปีนี้</p>
        <p class="text-white text-lg font-bold mt-1 tabular-nums"><?php echo number_format((float)($stats['leaves_this_year'] ?? 0), 1); ?> วัน</p>
    </div>
    <div class="stat-card tp-native-summary-card group min-w-0">
        <p class="text-slate-300 text-xs">ใบลารออนุมัติ</p>
        <p class="text-white text-lg font-bold mt-1 tabular-nums"><?php echo (int)($stats['pending_leaves'] ?? 0); ?></p>
    </div>
</div>

<?php include dirname(__DIR__) . '/modules/hr/employee_monthly_summary.php'; ?>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-5 md:gap-8 min-w-0 max-w-full">
    <!-- Personal -->
    <div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 min-w-0 max-w-full overflow-hidden">
        <h3 class="section-title mb-4 text-white text-base sm:text-lg"><i class="fas fa-user text-violet-400 mr-2 text-xl" aria-hidden="true"></i>ข้อมูลส่วนตัว</h3>
        <?php
        tp_hr_emp_view_row('เพศ', $genderLabel[$emp['gender'] ?? ''] ?? null);
        tp_hr_emp_view_row('วันเกิด', !empty($emp['birth_date']) ? formatDateThai($emp['birth_date']) : null);
        tp_hr_emp_view_row('สถานภาพ', $maritalLabel[$emp['marital_status'] ?? ''] ?? null);
        tp_hr_emp_view_row('สัญชาติ', $emp['nationality'] ?? null);
        tp_hr_emp_view_row('ศาสนา', $emp['religion'] ?? null);
        tp_hr_emp_view_row('กรุ๊ปเลือด', $emp['blood_type'] ?? null);
        tp_hr_emp_view_row('สถานะทหาร', $emp['military_status'] ?? null);
        tp_hr_emp_view_row('เลขบัตรประชาชน', $emp['id_card'] ?? null);
        tp_hr_emp_view_row('วันหมดอายุบัตรประชาชน', !empty($emp['id_card_expiry']) ? formatDateThai($emp['id_card_expiry']) : null);
        tp_hr_emp_view_row('ที่อยู่ปัจจุบัน', $emp['address'] ?? null);
        tp_hr_emp_view_row('ที่อยู่ตามทะเบียนบ้าน', $emp['registered_address'] ?? null);
        ?>
    </div>

    <!-- Employment -->
    <div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 min-w-0 max-w-full overflow-hidden">
        <h3 class="section-title mb-4 text-white text-base sm:text-lg"><i class="fas fa-briefcase text-emerald-400 mr-2 text-xl" aria-hidden="true"></i>ข้อมูลการจ้างงาน</h3>
        <?php
        tp_hr_emp_view_row('วันที่เริ่มงาน', !empty($emp['hire_date']) ? formatDateThai($emp['hire_date']) : null, 'calendar');
        if (!empty($emp['termination_date'])) {
            tp_hr_emp_view_row('วันลาออก', formatDateThai($emp['termination_date']), 'door-open');
        }
        tp_hr_emp_view_row('ประเภทการจ้าง', $empTypeLabel[$emp['employment_type'] ?? ''] ?? null);
        tp_hr_emp_view_row('รูปแบบการทำงาน', (($emp['work_mode'] ?? 'OFFICE') === 'WFH') ? 'ทำงานที่บ้าน (WFH)' : 'ทำงานที่ออฟฟิศ (Office)', 'laptop-house');
        tp_hr_emp_view_row('วันหยุดประจำสัปดาห์', isset($emp['scheduled_day_off']) ? ('วัน' . ($dayNames[(int)$emp['scheduled_day_off']] ?? '-')) : null);
        if (($emp['employment_type'] ?? '') === 'PROBATION') {
            tp_hr_emp_view_row('วันทดลองงาน (วัน)', $emp['probation_days'] ?? null);
            tp_hr_emp_view_row('สิ้นสุดทดลองงาน', !empty($emp['probation_end_date']) ? formatDateThai($emp['probation_end_date']) : null);
        }
        if (!empty($emp['probation_passed_date'])) {
            tp_hr_emp_view_row('วันผ่านทดลองงาน', formatDateThai($emp['probation_passed_date']));
        }
        if (!empty($emp['hire_date'])) {
            $tenure = (new DateTime($emp['hire_date']))->diff(new DateTime('today'));
            tp_hr_emp_view_row('อายุงาน', $tenure->y . ' ปี ' . $tenure->m . ' เดือน ' . $tenure->d . ' วัน');
        }
        ?>
    </div>

    <!-- Contact / Emergency -->
    <div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 min-w-0 max-w-full overflow-hidden">
        <h3 class="section-title mb-4 text-white text-base sm:text-lg"><i class="fas fa-phone-alt text-amber-400 mr-2 text-xl" aria-hidden="true"></i>ติดต่อฉุกเฉิน</h3>
        <?php
        tp_hr_emp_view_row('ชื่อผู้ติดต่อ', $emp['emergency_contact_name'] ?? null);
        tp_hr_emp_view_row('เบอร์โทร', $emp['emergency_contact_phone'] ?? null);
        tp_hr_emp_view_row('ความสัมพันธ์', $emp['emergency_contact_relation'] ?? null);
        ?>
    </div>

    <!-- Payroll -->
    <div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 min-w-0 max-w-full overflow-hidden">
        <h3 class="section-title mb-4 text-white text-base sm:text-lg"><i class="fas fa-money-check text-emerald-400 mr-2 text-xl" aria-hidden="true"></i>การเงินและประกันสังคม</h3>
        <?php
        if (isCEOOrAbove()) {
            $effSalary = getEffectiveSalary($emp);
            $passed = !empty($emp['probation_passed_date']);
            tp_hr_emp_view_row('เงินเดือนหลังผ่านโปร (บาท)',
                !empty($emp['salary']) ? number_format((float)$emp['salary'], 2) . ($passed ? ' (ใช้งานอยู่)' : '') : null);
            tp_hr_emp_view_row('เงินเดือนช่วงทดลองงาน (บาท)',
                !empty($emp['probation_salary']) ? number_format((float)$emp['probation_salary'], 2) . (!$passed ? ' (ใช้งานอยู่)' : '') : null);
            tp_hr_emp_view_row('เงินเดือนที่มีผลปัจจุบัน', number_format($effSalary, 2) . ' บาท');
        } else {
            tp_hr_emp_view_row('เงินเดือน', 'CEO+ เท่านั้น');
        }
        tp_hr_emp_view_row('ธนาคาร', $emp['bank_name'] ?? null);
        tp_hr_emp_view_row('เลขบัญชี', $emp['bank_account'] ?? null);
        tp_hr_emp_view_row('เลขประกันสังคม', $emp['social_security_id'] ?? null);
        tp_hr_emp_view_row('วันที่เริ่มประกันสังคม', !empty($emp['social_security_start_date']) ? formatDateThai($emp['social_security_start_date']) : null);
        tp_hr_emp_view_row('วันเริ่มหักภาษี ณ ที่จ่าย', !empty($emp['tax_withholding_start_date']) ? formatDateThai($emp['tax_withholding_start_date']) : null);
        tp_hr_emp_view_row('วันเริ่มหักประกันกลุ่ม', !empty($emp['group_insurance_start_date']) ? formatDateThai($emp['group_insurance_start_date']) : null);
        tp_hr_emp_view_row('วันเริ่มหักประกันสุขภาพ', !empty($emp['health_insurance_start_date']) ? formatDateThai($emp['health_insurance_start_date']) : null);
        tp_hr_emp_view_row('โรงพยาบาลประกันสังคม', $emp['social_security_hospital'] ?? null);
        ?>
    </div>
</div>
</div>

<?php
$bulkDefaultCheckIn = substr((string)(getSetting('default_work_start', '08:45') ?? '08:45'), 0, 5);
$bulkDefaultCheckOut = substr((string)(getSetting('default_work_end', '17:30') ?? '17:30'), 0, 5);
$bulkReloadBase = '/hr/employee_view.php?' . http_build_query(['id' => $id, 'month' => $summaryMonth]);
include dirname(__DIR__) . '/modules/hr/employee_summary_bulk_attendance_modal.php';
?>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
