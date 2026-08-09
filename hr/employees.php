<?php
/**
 * HR Employee Management
 * จัดการพนักงาน - สำหรับ HR
 */

$page_title = 'จัดการพนักงาน';
require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();

if (!hr_can_access_hr_dashboard()) {
    redirect('/', 302);
}

// Check for CEO-level actions (add, edit, delete)
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

if (($_GET['action'] ?? '') === 'export') {
    $q = $_GET;
    unset($q['action']);
    flash('error', 'การส่งออกข้อมูล กรุณากดปุ่ม Export ในหน้านี้เท่านั้น');
    redirect('/hr/employees.php' . (count($q) ? '?' . http_build_query($q) : ''), 302);
}

// Redirect add/edit to employee_form.php
if ($action === 'add') {
    if (!canManageUsers()) {
        flash('error', 'คุณไม่มีสิทธิ์ในการเพิ่มพนักงาน ต้องเป็นระดับ CEO ขึ้นไป');
        redirect('/hr/employees.php', 302);
    }
    redirect('/hr/employee_form.php?action=add', 302);
}

if ($action === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        redirect("/hr/employee_form.php?action=edit&id={$id}", 302);
    }
    redirect('/hr/employees.php', 302);
}

if ($action === 'delete' && !canManageUsers()) {
    flash('error', 'คุณไม่มีสิทธิ์ในการลบพนักงาน ต้องเป็นระดับ CEO ขึ้นไป');
    redirect('/hr/employees.php', 302);
}

$pdo = Database::getInstance()->getConnection();

if ($action === 'delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        flash('error', 'รูปแบบคำขอไม่ถูกต้อง');
        redirect('/hr/employees.php', 302);
    }
    $id = (int)($_POST['id'] ?? 0);
    if (!verifyCsrfToken($_POST['_token'] ?? null)) {
        flash('error', 'โทเค็นความปลอดภัยไม่ถูกต้อง');
        redirect('/hr/employees.php', 302);
    }
    if ($id <= 0 || in_array($id, SYSTEM_USER_IDS, true) || $id === (int)$user['id']) {
        flash('error', 'ไม่สามารถปิดใช้งานพนักงานรายการนี้ได้');
        redirect('/hr/employees.php', 302);
    }

    $stmt = $pdo->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = ? AND id NOT IN (" . SYSTEM_USER_IDS_SQL . ")");
    $stmt->execute([$id]);
    if ($stmt->rowCount() > 0) {
        Auth::log('employee_deactivate', 'users', $id);
        flash('success', 'ปิดใช้งานพนักงานเรียบร้อยแล้ว');
    } else {
        flash('error', 'ไม่พบพนักงาน หรือพนักงานถูกปิดใช้งานอยู่แล้ว');
    }
    redirect('/hr/employees.php', 302);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['export_csv'] ?? '') === '1') {
    if (!verifyCsrfToken($_POST['_token'] ?? null)) {
        flash('error', 'โทเค็นความปลอดภัยไม่ถูกต้อง');
        redirect('/hr/employees.php', 302);
    }
    $search = trim((string)($_POST['search'] ?? ''));
    $department = trim((string)($_POST['department'] ?? ''));
    $status = (string)($_POST['status'] ?? '');

    $employeeWhere = "u.id > 0 AND " . tp_hr_non_system_user_condition_sql('u');
    $params = [];

    if ($search !== '') {
        $employeeWhere .= " AND (u.first_name_th LIKE ? OR u.last_name_th LIKE ? OR u.employee_code LIKE ? OR u.email LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    if ($department !== '') {
        $employeeWhere .= " AND u.department = ?";
        $params[] = $department;
    }

    if ($status === 'ACTIVE') {
        $employeeWhere .= " AND u.is_active = 1";
    } elseif ($status === 'INACTIVE') {
        $employeeWhere .= " AND u.is_active = 0";
    }

    $exportCols = canManageUsers()
        ? "u.id, u.employee_code, u.title, u.first_name_th, u.last_name_th, u.department, u.position, u.email, u.phone, u.hire_date, u.is_active, u.work_mode, u.salary, u.probation_salary"
        : "u.id, u.employee_code, u.title, u.first_name_th, u.last_name_th, u.department, u.position, u.email, u.phone, u.hire_date, u.is_active, u.work_mode";
    $exportSql = "SELECT {$exportCols} FROM users u WHERE {$employeeWhere} ORDER BY u.is_active DESC, u.employee_code ASC LIMIT 10000";
    $stmt = $pdo->prepare($exportSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = hr_safe_content_disposition_filename('employees_export_' . date('Y-m-d_His') . '.csv', 'employees.csv');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    if ($out !== false) {
        if (!empty($rows)) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
        } else {
            $hdr = canManageUsers()
                ? ['id', 'employee_code', 'title', 'first_name_th', 'last_name_th', 'department', 'position', 'email', 'phone', 'hire_date', 'is_active', 'work_mode', 'salary', 'probation_salary']
                : ['id', 'employee_code', 'title', 'first_name_th', 'last_name_th', 'department', 'position', 'email', 'phone', 'hire_date', 'is_active', 'work_mode'];
            fputcsv($out, $hdr);
        }
        fclose($out);
    }
    Auth::log('employees_export_csv', 'users', null, null, [
        'filters' => ['search' => $search, 'department' => $department, 'status' => $status],
        'row_count' => count($rows),
    ]);
    exit;
}

// Filters
$search = $_GET['search'] ?? '';
$department = $_GET['department'] ?? '';
$status = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = DEFAULT_PER_PAGE;
$offset = ($page - 1) * $limit;

$employeeWhere = "u.id > 0 AND " . tp_hr_non_system_user_condition_sql('u');
$params = [];

if ($search) {
    $employeeWhere .= " AND (u.first_name_th LIKE ? OR u.last_name_th LIKE ? OR u.employee_code LIKE ? OR u.email LIKE ?)";
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if ($department) {
    $employeeWhere .= " AND u.department = ?";
    $params[] = $department;
}

if ($status === 'ACTIVE') {
    $employeeWhere .= " AND u.is_active = 1";
} elseif ($status === 'INACTIVE') {
    $employeeWhere .= " AND u.is_active = 0";
}

// Get departments
$stmtDepts = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' AND " . tp_hr_non_system_user_condition_sql('') . " ORDER BY department");
$departments = $stmtDepts->fetchAll(PDO::FETCH_COLUMN);

$sql = "
    SELECT u.*, 
           (SELECT COUNT(*) FROM hr_attendances a WHERE a.user_id = u.id AND a.attendance_date = CURDATE() AND a.check_in_time IS NOT NULL) as checked_in_today,
           (SELECT SUM(total_days) FROM hr_leave_requests lr WHERE lr.user_id = u.id AND lr.status = 'APPROVED' AND YEAR(lr.start_date) = YEAR(CURDATE())) as total_leave_days
    FROM users u
    WHERE " . $employeeWhere;

$countSql = "SELECT COUNT(*) FROM users u WHERE " . $employeeWhere;
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$sql .= " ORDER BY u.is_active DESC, u.employee_code ASC LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

// Stats
$stmtStats = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive
    FROM users
    WHERE " . tp_hr_non_system_user_condition_sql('') . "
");
$stats = $stmtStats->fetch();

$current_page = 'hr-employees';

include dirname(__DIR__) . '/templates/header.php';
$flashSuccess = flash('success');
$flashError = flash('error');
?>

<div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <span class="text-white">จัดการพนักงาน</span>
    </nav>
    <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between gap-y-4">
        <div class="min-w-0 flex-1">
            <h1 class="tp-ios-page-title">จัดการพนักงาน</h1>
            <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">ค้นหา กรองแผนกและสถานะ ดูสรุปและดำเนินการรายคน</p>
        </div>
        <?php if (canManageUsers()): ?>
        <a href="employees.php?action=add" class="w-full sm:w-auto shrink-0 inline-flex items-center justify-center min-h-[56px] px-4 py-2.5 bg-violet-600 hover:bg-violet-700 text-white rounded-[var(--tp-ios-card-radius)] transition-colors font-semibold touch-manipulation">
            <i class="fas fa-plus mr-2" aria-hidden="true"></i>เพิ่มพนักงาน
        </a>
        <?php endif; ?>
    </div>
</header>

<?php if ($flashSuccess): ?>
<div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-emerald-200" role="status">
    <i class="fas fa-check-circle mr-2" aria-hidden="true"></i><?php echo htmlspecialchars($flashSuccess); ?>
</div>
<?php endif; ?>

<?php if ($flashError): ?>
<div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-red-500/30 bg-red-500/15 px-4 py-3 text-red-200" role="alert">
    <i class="fas fa-exclamation-circle mr-2" aria-hidden="true"></i><?php echo htmlspecialchars($flashError); ?>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 mb-6 min-w-0 max-w-full">
    <a href="?status=" class="stat-card tp-native-summary-card group block min-w-0 touch-manipulation transition-shadow <?php echo !$status ? 'ring-2 ring-violet-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">พนักงานทั้งหมด</p>
        <p class="text-2xl font-bold text-violet-400 tabular-nums mt-1"><?php echo (int)$stats['total']; ?></p>
    </a>
    <a href="?status=ACTIVE" class="stat-card tp-native-summary-card group block min-w-0 touch-manipulation transition-shadow <?php echo $status === 'ACTIVE' ? 'ring-2 ring-emerald-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">พนักงานปัจจุบัน</p>
        <p class="text-2xl font-bold text-emerald-400 tabular-nums mt-1"><?php echo (int)$stats['active']; ?></p>
    </a>
    <a href="?status=INACTIVE" class="stat-card tp-native-summary-card group block min-w-0 touch-manipulation transition-shadow <?php echo $status === 'INACTIVE' ? 'ring-2 ring-red-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">พ้นสภาพ</p>
        <p class="text-2xl font-bold text-red-400 tabular-nums mt-1"><?php echo (int)$stats['inactive']; ?></p>
    </a>
</div>

<!-- Filters -->
<div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 mb-6 min-w-0 max-w-full overflow-hidden">
    <h2 class="section-title mb-4 text-white text-lg">
        <i class="fas fa-filter text-violet-400 text-xl mr-2" aria-hidden="true"></i>
        ค้นหาและกรอง
    </h2>
    <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 min-w-0" id="employees-filter-form">
        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>"><?php endif; ?>
        <div class="min-w-0 sm:col-span-2 xl:col-span-1 tp-native-form-group mb-0">
            <label for="emp-search" class="text-white/70 text-sm font-medium">ค้นหา</label>
            <div class="relative">
                <input type="text" id="emp-search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="ชื่อ, รหัส, อีเมล..." autocomplete="off" class="input-field tp-native-input pl-10 w-full">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-white/40 pointer-events-none" aria-hidden="true"></i>
            </div>
        </div>
        <div class="min-w-0 tp-native-form-group mb-0">
            <label for="emp-department" class="text-white/70 text-sm font-medium">แผนก</label>
            <select id="emp-department" name="department" class="input-field tp-native-select w-full" onchange="this.form.submit()">
                <option value="">ทั้งหมด</option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department === $dept ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex items-end gap-2 min-w-0 sm:col-span-2 xl:col-span-1">
            <button type="submit" class="flex-1 min-h-[56px] py-2.5 bg-violet-600 hover:bg-violet-700 text-white rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation font-semibold">
                <i class="fas fa-search mr-2" aria-hidden="true"></i>ค้นหา
            </button>
        </div>
    </form>
    <div class="flex flex-col sm:flex-row items-stretch sm:items-end gap-3 min-w-0 mt-4 pt-4 border-t border-white/10">
        <a href="employees.php" class="flex-1 min-h-[48px] py-2.5 bg-white/10 hover:bg-white/20 text-white text-center rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation inline-flex items-center justify-center font-medium">
            <i class="fas fa-redo mr-2" aria-hidden="true"></i>รีเซ็ต
        </a>
        <form method="post" class="flex-1 min-w-0">
            <?php echo csrfField(); ?>
            <input type="hidden" name="export_csv" value="1">
            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
            <input type="hidden" name="department" value="<?php echo htmlspecialchars($department); ?>">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
            <button type="submit" class="w-full min-h-[56px] py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-center rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation inline-flex items-center justify-center font-semibold gap-2">
                <i class="fas fa-file-excel" aria-hidden="true"></i>Export CSV
            </button>
        </form>
    </div>
</div>

<!-- Employee List -->
<div class="native-card tp-native-card tp-native-data-card min-w-0 max-w-full overflow-hidden">
    <?php if (empty($employees)): ?>
    <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-4 my-4">
        <i class="fas fa-users text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
        <p class="text-slate-400 text-sm">ไม่พบพนักงาน</p>
    </div>
    <?php else: ?>
    <!-- Mobile-first: card list below md (tablet/desktop use table) -->
    <div class="md:hidden p-5 space-y-4">
        <?php foreach ($employees as $emp): ?>
        <?php
        $fullName = trim((string)($emp['first_name_th'] ?? '') . ' ' . (string)($emp['last_name_th'] ?? ''));
        $empCode = (string)($emp['employee_code'] ?? 'ไม่มีรหัส');
        $dept = (string)($emp['department'] ?? '-');
        $pos = (string)($emp['position'] ?? '-');
        $email = (string)($emp['email'] ?? '-');
        $phone = (string)($emp['phone'] ?? '-');
        $hire = $emp['hire_date'] ? formatDateThai($emp['hire_date']) : '-';
        $leaveDays = number_format((float)($emp['total_leave_days'] ?? 0), 1);
        $isWfh = (($emp['work_mode'] ?? 'OFFICE') === 'WFH');
        $checkedIn = !empty($emp['checked_in_today']);
        $isActive = !empty($emp['is_active']);

        $todayLabel = $checkedIn ? 'เข้างาน' : ($isWfh ? 'WFH' : '-');
        $todayCls = $checkedIn ? 'bg-green-500/15 border border-green-500/30 text-green-200'
            : ($isWfh ? 'bg-blue-500/15 border border-blue-500/30 text-blue-200' : 'bg-white/5 border border-white/10 text-white/60');

        $activeLabel = $isActive ? 'ทำงาน' : 'พ้นสภาพ';
        $activeCls = $isActive ? 'bg-green-500/15 border border-green-500/30 text-green-200'
            : 'bg-red-500/15 border border-red-500/30 text-red-200';
        ?>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-5 min-w-0">
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <?php if (!empty($emp['avatar'])): ?>
                    <img src="<?php echo htmlspecialchars($emp['avatar']); ?>" class="w-12 h-12 rounded-2xl object-cover">
                    <?php else: ?>
                    <div class="w-12 h-12 rounded-2xl bg-violet-600/30 flex items-center justify-center text-white font-bold">
                        <?php echo mb_substr($emp['first_name_th'] ?? '', 0, 1); ?>
                    </div>
                    <?php endif; ?>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <a href="employee_view.php?id=<?php echo (int)$emp['id']; ?>"
                               class="text-white font-semibold leading-tight hover:text-violet-300 transition-colors truncate">
                                <?php echo htmlspecialchars($fullName); ?>
                            </a>
                            <?php if ($isWfh): ?>
                            <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] bg-blue-500/20 text-blue-300" title="Work From Home">
                                <i class="fas fa-home mr-1"></i>WFH
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="text-white/50 text-xs truncate"><?php echo htmlspecialchars($empCode); ?></div>
                        <div class="text-white/60 text-xs truncate"><?php echo htmlspecialchars($dept); ?> · <?php echo htmlspecialchars($pos); ?></div>
                    </div>
                </div>
                <div class="flex flex-col items-end gap-2 shrink-0">
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $todayCls; ?>">
                        <?php echo htmlspecialchars($todayLabel); ?>
                    </span>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $activeCls; ?>">
                        <?php echo htmlspecialchars($activeLabel); ?>
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 mt-4">
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 px-3 py-2">
                    <div class="text-[11px] text-white/50">เริ่มงาน</div>
                    <div class="text-white font-semibold text-sm"><?php echo htmlspecialchars($hire); ?></div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 px-3 py-2">
                    <div class="text-[11px] text-white/50">ลาปีนี้</div>
                    <div class="text-white font-semibold text-sm"><?php echo htmlspecialchars($leaveDays); ?> วัน</div>
                </div>
            </div>

            <div class="mt-3 text-white/70 text-xs space-y-1">
                <div class="truncate"><i class="fas fa-envelope text-white/40 mr-2"></i><?php echo htmlspecialchars($email); ?></div>
                <div class="truncate"><i class="fas fa-phone text-white/40 mr-2"></i><?php echo htmlspecialchars($phone); ?></div>
            </div>

            <div class="grid grid-cols-2 gap-2 mt-4">
                <a href="/hr/employee_attendance.php?id=<?php echo (int)$emp['id']; ?>"
                   class="min-h-[48px] rounded-[var(--tp-ios-card-radius)] bg-violet-500/15 hover:bg-violet-500/25 border border-violet-500/20 text-violet-200 text-sm font-semibold flex items-center justify-center touch-manipulation">
                    <i class="fas fa-clock mr-2" aria-hidden="true"></i>ลงเวลา
                </a>
                <button type="button"
                        title="สิทธิ์การลาและประวัติ"
                        onclick="viewLeaveBalance(<?php echo (int)$emp['id']; ?>)"
                        class="min-h-[48px] rounded-[var(--tp-ios-card-radius)] bg-white/10 hover:bg-white/20 text-white text-sm font-semibold touch-manipulation">
                    <i class="fas fa-calendar-alt mr-2" aria-hidden="true"></i>สิทธิ์ลา
                </button>
                <?php if (canManageUsers() || hr_can_access_hr_dashboard()): ?>
                <a href="employees.php?action=edit&id=<?php echo (int)$emp['id']; ?>"
                   class="min-h-[48px] rounded-[var(--tp-ios-card-radius)] bg-white/10 hover:bg-white/20 text-white text-sm font-semibold flex items-center justify-center touch-manipulation">
                    <i class="fas fa-edit mr-2" aria-hidden="true"></i>แก้ไข
                </a>
                <?php endif; ?>
                <a href="employee_view.php?id=<?php echo (int)$emp['id']; ?>"
                   class="min-h-[48px] rounded-[var(--tp-ios-card-radius)] bg-white/10 hover:bg-white/20 text-white text-sm font-semibold flex items-center justify-center touch-manipulation">
                    <i class="fas fa-eye mr-2" aria-hidden="true"></i>ดูข้อมูล
                </a>
                <?php if (canManageUsers()): ?>
                <button type="button"
                        onclick="confirmDelete(<?php echo (int)$emp['id']; ?>, '<?php echo htmlspecialchars($emp['first_name_th'] ?? '', ENT_QUOTES); ?>')"
                        class="col-span-2 min-h-[48px] rounded-[var(--tp-ios-card-radius)] bg-red-500/15 hover:bg-red-500/25 border border-red-500/30 text-red-200 text-sm font-semibold touch-manipulation">
                    <i class="fas fa-trash mr-2"></i>ลบพนักงาน
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain -mx-1 px-1">
        <table class="w-full min-w-[720px]">
            <thead class="bg-white/5">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">พนักงาน</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">แผนก / ตำแหน่ง</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ติดต่อ</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">วันที่เริ่มงาน</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">วันลาปีนี้</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะวันนี้</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($employees as $emp): ?>
                <tr class="hover:bg-white/[0.04]">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <?php if (!empty($emp['avatar'])): ?>
                            <img src="<?php echo htmlspecialchars($emp['avatar']); ?>" class="w-10 h-10 rounded-full object-cover">
                            <?php else: ?>
                            <div class="w-10 h-10 rounded-full bg-violet-600/30 flex items-center justify-center text-white font-bold">
                                <?php echo mb_substr($emp['first_name_th'] ?? '', 0, 1); ?>
                            </div>
                            <?php endif; ?>
                            <div>
                                <p class="text-white font-medium">
                                    <?php echo htmlspecialchars(($emp['first_name_th'] ?? '') . ' ' . ($emp['last_name_th'] ?? '')); ?>
                                    <?php if (($emp['work_mode'] ?? 'OFFICE') === 'WFH'): ?>
                                    <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] bg-blue-500/20 text-blue-300" title="Work From Home"><i class="fas fa-home mr-1"></i>WFH</span>
                                    <?php endif; ?>
                                </p>
                                <p class="text-white/50 text-xs"><?php echo htmlspecialchars($emp['employee_code'] ?? 'ไม่มีรหัส'); ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-white"><?php echo htmlspecialchars($emp['department'] ?? '-'); ?></p>
                        <p class="text-white/50 text-xs"><?php echo htmlspecialchars($emp['position'] ?? '-'); ?></p>
                    </td>
                    <td class="px-4 py-3 text-white/80 text-sm">
                        <p><?php echo htmlspecialchars($emp['email'] ?? '-'); ?></p>
                        <p class="text-white/50"><?php echo htmlspecialchars($emp['phone'] ?? '-'); ?></p>
                    </td>
                    <td class="px-4 py-3 text-center text-white/80 text-sm">
                        <?php echo $emp['hire_date'] ? formatDateThai($emp['hire_date']) : '-'; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-white">
                        <?php echo number_format($emp['total_leave_days'] ?? 0, 1); ?> วัน
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($emp['checked_in_today']): ?>
                        <span class="px-3 py-1 rounded-[var(--tp-ios-card-radius)] text-xs bg-emerald-500/20 text-emerald-300">เข้างาน</span>
                        <?php elseif (($emp['work_mode'] ?? 'OFFICE') === 'WFH'): ?>
                        <span class="px-3 py-1 rounded-[var(--tp-ios-card-radius)] text-xs bg-blue-500/20 text-blue-300">WFH</span>
                        <?php else: ?>
                        <span class="px-3 py-1 rounded-[var(--tp-ios-card-radius)] text-xs bg-gray-500/20 text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($emp['is_active']): ?>
                        <span class="px-3 py-1 rounded-[var(--tp-ios-card-radius)] text-xs bg-emerald-500/20 text-emerald-300">ทำงาน</span>
                        <?php else: ?>
                        <span class="px-3 py-1 rounded-[var(--tp-ios-card-radius)] text-xs bg-red-500/20 text-red-400">พ้นสภาพ</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <div class="inline-flex flex-wrap items-center justify-center gap-2 max-w-[220px] mx-auto">
                        <a href="employee_view.php?id=<?php echo (int)$emp['id']; ?>"
                           class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center bg-white/10 hover:bg-white/20 text-white text-sm rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation" title="ดูข้อมูล" aria-label="ดูข้อมูล">
                            <i class="fas fa-eye" aria-hidden="true"></i>
                        </a>
                        <a href="/hr/employee_attendance.php?id=<?php echo (int)$emp['id']; ?>"
                           class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center bg-violet-500/20 hover:bg-violet-500/30 text-violet-300 text-sm rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation" title="ดูลงเวลา" aria-label="ดูลงเวลา">
                            <i class="fas fa-clock" aria-hidden="true"></i>
                        </a>
                        <?php if (canManageUsers()): ?>
                        <a href="employees.php?action=edit&id=<?php echo (int)$emp['id']; ?>"
                           class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center bg-white/10 hover:bg-white/20 text-white text-sm rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation" title="แก้ไข" aria-label="แก้ไข">
                            <i class="fas fa-edit" aria-hidden="true"></i>
                        </a>
                        <button type="button" onclick="confirmDelete(<?php echo (int)$emp['id']; ?>, '<?php echo htmlspecialchars($emp['first_name_th'] ?? '', ENT_QUOTES, 'UTF-8'); ?>')"
                                class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center bg-red-500/20 hover:bg-red-500/30 text-red-300 text-sm rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation" title="ปิดใช้งาน" aria-label="ปิดใช้งาน">
                            <i class="fas fa-trash" aria-hidden="true"></i>
                        </button>
                        <?php elseif (hr_can_access_hr_dashboard()): ?>
                        <a href="employees.php?action=edit&id=<?php echo (int)$emp['id']; ?>"
                           class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center bg-white/10 hover:bg-white/20 text-white text-sm rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation" title="แก้ไข" aria-label="แก้ไข">
                            <i class="fas fa-edit" aria-hidden="true"></i>
                        </a>
                        <?php endif; ?>
                        <button type="button" onclick="viewLeaveBalance(<?php echo (int)$emp['id']; ?>)"
                                class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center bg-white/10 hover:bg-white/20 text-white text-sm rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation" title="สิทธิ์การลาและประวัติ" aria-label="สิทธิ์การลา">
                            <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                        </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="px-4 sm:px-6 py-4 border-t border-white/10 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between min-w-0">
        <p class="text-white/60 text-sm min-w-0">
            แสดง <?php echo $offset + 1; ?> - <?php echo min($offset + $limit, $totalRecords); ?> 
            จาก <?php echo $totalRecords; ?> รายการ
        </p>
        <div class="flex flex-wrap gap-2 shrink-0">
            <?php if ($page > 1): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>"
               class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center px-3 py-1 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation"
               aria-label="หน้าก่อน">
                <i class="fas fa-chevron-left" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
               class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center px-3 py-1 <?php echo $i === $page ? 'bg-violet-600 text-white' : 'bg-white/10 hover:bg-white/20 text-white'; ?> rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation"
               <?php echo $i === $page ? ' aria-current="page"' : ''; ?>>
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>"
               class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center px-3 py-1 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation"
               aria-label="หน้าถัดไป">
                <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
</div>

<!-- Leave Balance Modal -->
<div id="leave-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="leave-modal-title">
    <div class="native-card tp-native-card w-full max-w-lg my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden p-6 pb-[calc(env(safe-area-inset-bottom,0px)+1.5rem)]">
        <div class="flex items-center justify-between mb-6 gap-3">
            <h3 id="leave-modal-title" class="text-xl font-bold text-white">สิทธิ์การลาและประวัติ</h3>
            <button type="button" onclick="closeLeaveModal()" class="tp-native-icon-btn min-h-[48px] min-w-[48px] text-white/70 hover:text-white hover:bg-white/10 rounded-[var(--tp-ios-card-radius)]" aria-label="ปิด">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div id="leave-content">
            <div class="tp-native-loading-state py-8" role="status" aria-live="polite" aria-busy="true"><i class="fas fa-spinner fa-spin text-2xl text-white/30" aria-hidden="true"></i><span class="tp-visually-hidden">กำลังโหลด</span></div>
        </div>
    </div>
</div>

<script>
async function viewLeaveBalance(userId) {
    if (typeof uiOpenModal === 'function') uiOpenModal('leave-modal');
    else {
        const lm = document.getElementById('leave-modal');
        if (lm) { lm.classList.remove('hidden'); lm.classList.add('flex'); }
    }
    document.getElementById('leave-content').innerHTML = typeof tpHrNativeLoadingHtml === 'function'
        ? tpHrNativeLoadingHtml()
        : '<div class="tp-native-loading-state py-8" role="status" aria-live="polite" aria-busy="true"><i class="fas fa-spinner fa-spin text-2xl text-white/30" aria-hidden="true"></i><span class="tp-visually-hidden">กำลังโหลด</span></div>';

    const year = <?php echo (int)date('Y'); ?>;
    const stLabel = { PENDING: 'รออนุมัติ', APPROVED: 'อนุมัติ', REJECTED: 'ไม่อนุมัติ', CANCELLED: 'ยกเลิก', DRAFT: 'ร่าง' };

    try {
        const [entRes, histRes] = await Promise.all([
            fetch(`/api/leave.php?action=entitlements&user_id=${userId}&year=${year}`),
            fetch(`/api/leave.php?action=history&user_id=${userId}&year=${year}&limit=10`),
        ]);
        const result = await entRes.json();
        const hist = await histRes.json();

        if (!result.success || !result.entitlements) {
            document.getElementById('leave-content').innerHTML = '<p class="text-center text-white/60">ไม่พบข้อมูลสิทธิ์ลา</p>';
            return;
        }

        let html = '<div class="space-y-4">';
        for (const e of result.entitlements) {
            const denom = Number(e.entitled_days) || 0;
            const used = Number(e.used_days) || 0;
            const usedPercent = denom > 0 ? Math.min(100, Math.round((used / denom) * 100)) : 0;
            html += `
                <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-5">
                    <div class="flex items-center justify-between mb-2 gap-2">
                        <span class="text-white font-medium">${e.leave_type_name}</span>
                        <div class="flex items-center shrink-0">
                            <span style="background-color:${e.color_code || '#6B7280'}" class="w-2 h-2 rounded-full mr-2"></span>
                            <span class="text-white/60 text-sm">${used}/${denom} วัน</span>
                        </div>
                    </div>
                    <div class="w-full bg-white/10 rounded-full h-2">
                        <div class="h-2 rounded-full" style="width: ${usedPercent}%; background-color:${e.color_code || '#d8c4ad'}"></div>
                    </div>
                    <p class="text-right text-white/50 text-xs mt-1">คงเหลือ ${e.remaining_days} วัน</p>
                </div>
            `;
        }
        html += '</div>';

        if (hist.success && Array.isArray(hist.requests) && hist.requests.length > 0) {
            html += '<div class="mt-6 pt-4 border-t border-white/10">';
            html += '<h4 class="text-white font-semibold text-sm mb-3">ประวัติลาปี ' + year + ' (ล่าสุด)</h4>';
            html += '<ul class="space-y-2 text-sm">';
            for (const r of hist.requests) {
                const st = stLabel[r.status] || r.status;
                const d0 = r.start_date || '';
                const d1 = r.end_date || '';
                const range = d0 === d1 ? d0 : (d0 + ' – ' + d1);
                html += `<li class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 px-3 py-2 text-white/85">
                    <span class="font-medium text-white">${r.leave_type_name || 'ลา'}</span>
                    <span class="text-white/50 mx-1">·</span>${range}
                    <span class="text-white/50 mx-1">·</span>${Number(r.total_days).toFixed(1)} วัน
                    <span class="block text-xs text-white/45 mt-0.5">${st}</span>
                </li>`;
            }
            html += '</ul></div>';
        }

        document.getElementById('leave-content').innerHTML = html;
    } catch (err) {
        document.getElementById('leave-content').innerHTML = '<p class="text-center text-red-400">เกิดข้อผิดพลาด</p>';
    }
}

function closeLeaveModal() {
    if (typeof uiCloseModal === 'function') uiCloseModal('leave-modal');
    else {
        const lm = document.getElementById('leave-modal');
        if (lm) { lm.classList.add('hidden'); lm.classList.remove('flex'); }
    }
}

<?php if (canManageUsers()): ?>
function confirmDelete(userId, name) {
    if (confirm(`ต้องการปิดใช้งานพนักงาน "${name}" ใช่หรือไม่?\n\nข้อมูลประวัติจะยังถูกเก็บไว้เพื่อรายงานและ audit log`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'employees.php';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="${userId}">
            <input type="hidden" name="_token" value="<?php echo csrfToken(); ?>">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
<?php endif; ?>

document.getElementById('leave-modal').addEventListener('click', e => { if (e.target === document.getElementById('leave-modal')) closeLeaveModal(); });
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
