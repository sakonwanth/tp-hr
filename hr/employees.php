<?php
/**
 * HR Employee Management
 * จัดการพนักงาน - สำหรับ HR
 */

$page_title = 'จัดการพนักงาน';
require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();

if (!isHR()) {
    redirect('/', 302);
}

// Check for CEO-level actions (add, edit, delete)
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

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

// Filters
$search = $_GET['search'] ?? '';
$department = $_GET['department'] ?? '';
$status = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = DEFAULT_PER_PAGE;
$offset = ($page - 1) * $limit;

// Get departments
$stmtDepts = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department");
$departments = $stmtDepts->fetchAll(PDO::FETCH_COLUMN);

// Build query
$sql = "
    SELECT u.*, 
           (SELECT COUNT(*) FROM hr_attendances a WHERE a.user_id = u.id AND a.attendance_date = CURDATE() AND a.check_in_time IS NOT NULL) as checked_in_today,
           (SELECT SUM(total_days) FROM hr_leave_requests lr WHERE lr.user_id = u.id AND lr.status = 'APPROVED' AND YEAR(lr.start_date) = YEAR(CURDATE())) as total_leave_days
    FROM users u
    WHERE u.id > 0 AND u.id NOT IN (" . SYSTEM_USER_IDS_SQL . ")
";
$params = [];

if ($search) {
    $sql .= " AND (u.first_name_th LIKE ? OR u.last_name_th LIKE ? OR u.employee_code LIKE ? OR u.email LIKE ?)";
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if ($department) {
    $sql .= " AND u.department = ?";
    $params[] = $department;
}

if ($status === 'ACTIVE') {
    $sql .= " AND u.is_active = 1";
} elseif ($status === 'INACTIVE') {
    $sql .= " AND u.is_active = 0";
}

// Count
$countSql = "SELECT COUNT(*) FROM (" . str_replace("u.*, \n           (SELECT COUNT(*) FROM hr_attendances a WHERE a.user_id = u.id AND a.attendance_date = CURDATE() AND a.check_in_time IS NOT NULL) as checked_in_today,\n           (SELECT SUM(total_days) FROM hr_leave_requests lr WHERE lr.user_id = u.id AND lr.status = 'APPROVED' AND YEAR(lr.start_date) = YEAR(CURDATE())) as total_leave_days", "1", $sql) . ") t";
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
    WHERE id NOT IN (" . SYSTEM_USER_IDS_SQL . ")
");
$stats = $stmtStats->fetch();

include dirname(__DIR__) . '/templates/header.php';
$flashSuccess = flash('success');
$flashError = flash('error');
?>

<div class="mb-6">
    <nav class="text-sm text-white/60 mb-1">
        <a href="/hr/" class="hover:text-white">HR</a>
        <span class="mx-2">/</span>
        <span class="text-white">จัดการพนักงาน</span>
    </nav>
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-white">จัดการพนักงาน</h1>
        <?php if (canManageUsers()): ?>
        <a href="employees.php?action=add" class="px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg transition-colors">
            <i class="fas fa-plus mr-2"></i>เพิ่มพนักงาน
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flashSuccess): ?>
<div class="mb-4 rounded-xl border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-emerald-200">
    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($flashSuccess); ?>
</div>
<?php endif; ?>

<?php if ($flashError): ?>
<div class="mb-4 rounded-xl border border-red-500/30 bg-red-500/15 px-4 py-3 text-red-200">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($flashError); ?>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-3 gap-4 mb-6">
    <a href="?status=" class="glass-card rounded-xl p-4 <?php echo !$status ? 'ring-2 ring-violet-400' : ''; ?>">
        <p class="text-white/50 text-sm">พนักงานทั้งหมด</p>
        <p class="text-2xl font-bold text-violet-400"><?php echo $stats['total']; ?></p>
    </a>
    <a href="?status=ACTIVE" class="glass-card rounded-xl p-4 <?php echo $status === 'ACTIVE' ? 'ring-2 ring-green-400' : ''; ?>">
        <p class="text-white/50 text-sm">พนักงานปัจจุบัน</p>
        <p class="text-2xl font-bold text-green-400"><?php echo $stats['active']; ?></p>
    </a>
    <a href="?status=INACTIVE" class="glass-card rounded-xl p-4 <?php echo $status === 'INACTIVE' ? 'ring-2 ring-red-400' : ''; ?>">
        <p class="text-white/50 text-sm">พ้นสภาพ</p>
        <p class="text-2xl font-bold text-red-400"><?php echo $stats['inactive']; ?></p>
    </a>
</div>

<!-- Filters -->
<div class="glass-card rounded-xl p-4 mb-6">
    <form method="GET" class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div>
            <label class="block text-white/60 text-xs mb-1">ค้นหา</label>
            <div class="relative">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="ชื่อ, รหัส, อีเมล..." class="input-field pl-10">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-white/40"></i>
            </div>
        </div>
        <div>
            <label class="block text-white/60 text-xs mb-1">แผนก</label>
            <select name="department" class="input-field" onchange="this.form.submit()">
                <option value="">ทั้งหมด</option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department === $dept ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="flex-1 py-2.5 bg-violet-600 hover:bg-violet-700 text-white rounded-lg transition-colors">
                <i class="fas fa-search mr-2"></i>ค้นหา
            </button>
        </div>
        <div class="flex items-end gap-2">
            <a href="employees.php" class="flex-1 py-2.5 bg-white/10 hover:bg-white/20 text-white text-center rounded-lg transition-colors">
                <i class="fas fa-redo mr-2"></i>รีเซ็ต
            </a>
            <a href="employees.php?action=export" class="flex-1 py-2.5 bg-green-600 hover:bg-green-700 text-white text-center rounded-lg transition-colors">
                <i class="fas fa-file-excel mr-2"></i>Export
            </a>
        </div>
    </form>
</div>

<!-- Employee List -->
<div class="glass-card rounded-xl overflow-hidden">
    <?php if (empty($employees)): ?>
    <div class="p-12 text-center">
        <i class="fas fa-users text-4xl text-white/20 mb-4"></i>
        <p class="text-white/60">ไม่พบพนักงาน</p>
    </div>
    <?php else: ?>
    <!-- Mobile-first: card list (desktop keeps table) -->
    <div class="lg:hidden p-3 space-y-3">
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
        <div class="rounded-2xl bg-white/5 border border-white/10 p-4">
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
                <div class="rounded-xl bg-black/20 border border-white/10 px-3 py-2">
                    <div class="text-[11px] text-white/50">เริ่มงาน</div>
                    <div class="text-white font-semibold text-sm"><?php echo htmlspecialchars($hire); ?></div>
                </div>
                <div class="rounded-xl bg-black/20 border border-white/10 px-3 py-2">
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
                   class="min-h-[44px] rounded-xl bg-violet-500/15 hover:bg-violet-500/25 border border-violet-500/20 text-violet-200 text-sm font-semibold flex items-center justify-center">
                    <i class="fas fa-clock mr-2"></i>ลงเวลา
                </a>
                <button type="button"
                        onclick="viewLeaveBalance(<?php echo (int)$emp['id']; ?>)"
                        class="min-h-[44px] rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold">
                    <i class="fas fa-calendar-alt mr-2"></i>สิทธิ์ลา
                </button>
                <?php if (canManageUsers() || isHR()): ?>
                <a href="employees.php?action=edit&id=<?php echo (int)$emp['id']; ?>"
                   class="min-h-[44px] rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold flex items-center justify-center">
                    <i class="fas fa-edit mr-2"></i>แก้ไข
                </a>
                <?php endif; ?>
                <a href="employee_view.php?id=<?php echo (int)$emp['id']; ?>"
                   class="min-h-[44px] rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold flex items-center justify-center">
                    <i class="fas fa-eye mr-2"></i>ดูข้อมูล
                </a>
                <?php if (canManageUsers()): ?>
                <button type="button"
                        onclick="confirmDelete(<?php echo (int)$emp['id']; ?>, '<?php echo htmlspecialchars($emp['first_name_th'] ?? '', ENT_QUOTES); ?>')"
                        class="col-span-2 min-h-[44px] rounded-xl bg-red-500/15 hover:bg-red-500/25 border border-red-500/30 text-red-200 text-sm font-semibold">
                    <i class="fas fa-trash mr-2"></i>ลบพนักงาน
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="hidden lg:block overflow-x-auto">
        <table class="w-full">
            <thead class="bg-white/5">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">พนักงาน</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">แผนก / ตำแหน่ง</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ติดต่อ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">วันที่เริ่มงาน</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">วันลาปีนี้</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะวันนี้</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($employees as $emp): ?>
                <tr class="hover:bg-white/5">
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
                        <span class="px-3 py-1 rounded-full text-xs bg-green-500/20 text-green-400">เข้างาน</span>
                        <?php elseif (($emp['work_mode'] ?? 'OFFICE') === 'WFH'): ?>
                        <span class="px-3 py-1 rounded-full text-xs bg-blue-500/20 text-blue-300">WFH</span>
                        <?php else: ?>
                        <span class="px-3 py-1 rounded-full text-xs bg-gray-500/20 text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($emp['is_active']): ?>
                        <span class="px-3 py-1 rounded-full text-xs bg-green-500/20 text-green-400">ทำงาน</span>
                        <?php else: ?>
                        <span class="px-3 py-1 rounded-full text-xs bg-red-500/20 text-red-400">พ้นสภาพ</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <a href="employee_view.php?id=<?php echo $emp['id']; ?>" 
                           class="px-2 py-1 bg-white/10 hover:bg-white/20 text-white text-xs rounded transition-colors mr-1" title="ดูข้อมูล">
                            <i class="fas fa-eye"></i>
                        </a>
                        <a href="/hr/employee_attendance.php?id=<?php echo $emp['id']; ?>" 
                           class="px-2 py-1 bg-violet-500/20 hover:bg-violet-500/30 text-violet-400 text-xs rounded transition-colors mr-1" title="ดูลงเวลา">
                            <i class="fas fa-clock"></i>
                        </a>
                        <?php if (canManageUsers()): ?>
                        <a href="employees.php?action=edit&id=<?php echo $emp['id']; ?>" 
                           class="px-2 py-1 bg-white/10 hover:bg-white/20 text-white text-xs rounded transition-colors mr-1" title="แก้ไข">
                            <i class="fas fa-edit"></i>
                        </a>
                        <button onclick="confirmDelete(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars($emp['first_name_th'] ?? ''); ?>')" 
                                class="px-2 py-1 bg-red-500/20 hover:bg-red-500/30 text-red-400 text-xs rounded transition-colors mr-1" title="ลบ">
                            <i class="fas fa-trash"></i>
                        </button>
                        <?php elseif (isHR()): ?>
                        <a href="employees.php?action=edit&id=<?php echo $emp['id']; ?>" 
                           class="px-2 py-1 bg-white/10 hover:bg-white/20 text-white text-xs rounded transition-colors mr-1" title="แก้ไข">
                            <i class="fas fa-edit"></i>
                        </a>
                        <?php endif; ?>
                        <button onclick="viewLeaveBalance(<?php echo $emp['id']; ?>)" 
                                class="px-2 py-1 bg-white/10 hover:bg-white/20 text-white text-xs rounded transition-colors" title="สิทธิ์การลา">
                            <i class="fas fa-calendar-alt"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="px-6 py-4 border-t border-white/10 flex items-center justify-between">
        <p class="text-white/60 text-sm">
            แสดง <?php echo $offset + 1; ?> - <?php echo min($offset + $limit, $totalRecords); ?> 
            จาก <?php echo $totalRecords; ?> รายการ
        </p>
        <div class="flex gap-2">
            <?php if ($page > 1): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
               class="px-3 py-1 bg-white/10 hover:bg-white/20 text-white rounded transition-colors">
                <i class="fas fa-chevron-left"></i>
            </a>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
               class="px-3 py-1 <?php echo $i === $page ? 'bg-violet-600 text-white' : 'bg-white/10 hover:bg-white/20 text-white'; ?> rounded transition-colors">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
               class="px-3 py-1 bg-white/10 hover:bg-white/20 text-white rounded transition-colors">
                <i class="fas fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Leave Balance Modal -->
<div id="leave-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 overflow-y-auto overscroll-contain">
    <div class="glass-card rounded-2xl w-full max-w-lg my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden pb-[calc(env(safe-area-inset-bottom,0px)+1rem)]">
        <div class="p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-bold text-white">สิทธิ์การลา</h3>
                <button onclick="closeLeaveModal()" class="p-2 text-white/60 hover:text-white hover:bg-white/10 rounded-lg">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="leave-content">
                <div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-white/30"></i></div>
            </div>
        </div>
    </div>
</div>

<script>
async function viewLeaveBalance(userId) {
    if (typeof uiOpenModal === 'function') uiOpenModal('leave-modal');
    else document.getElementById('leave-modal').classList.remove('hidden');
    document.getElementById('leave-content').innerHTML = '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-white/30"></i></div>';
    
    try {
        const response = await fetch(`/api/leave.php?action=entitlements&user_id=${userId}&year=<?php echo date('Y'); ?>`);
        const result = await response.json();
        
        if (result.success && result.entitlements) {
            let html = '<div class="space-y-4">';
            for (const e of result.entitlements) {
                const usedPercent = ((e.used_days / e.entitled_days) * 100).toFixed(0);
                html += `
                    <div class="glass-card rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-white font-medium">${e.leave_type_name}</span>
                            <div class="flex items-center">
                                <span style="background-color:${e.color_code}" class="w-2 h-2 rounded-full mr-2"></span>
                                <span class="text-white/60 text-sm">${e.used_days}/${e.entitled_days} วัน</span>
                            </div>
                        </div>
                        <div class="w-full bg-white/10 rounded-full h-2">
                            <div class="h-2 rounded-full" style="width: ${usedPercent}%; background-color:${e.color_code}"></div>
                        </div>
                        <p class="text-right text-white/50 text-xs mt-1">คงเหลือ ${e.remaining_days} วัน</p>
                    </div>
                `;
            }
            html += '</div>';
            document.getElementById('leave-content').innerHTML = html;
        } else {
            document.getElementById('leave-content').innerHTML = '<p class="text-center text-white/60">ไม่พบข้อมูล</p>';
        }
    } catch (err) {
        document.getElementById('leave-content').innerHTML = '<p class="text-center text-red-400">เกิดข้อผิดพลาด</p>';
    }
}

function closeLeaveModal() {
    if (typeof uiCloseModal === 'function') uiCloseModal('leave-modal');
    else document.getElementById('leave-modal').classList.add('hidden');
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
