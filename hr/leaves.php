<?php
/**
 * HR Leave Management
 * จัดการการลา - สำหรับ HR
 */

$page_title = 'จัดการการลา';
require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();

if (!hr_can_access_hr_dashboard()) {
    redirect('/', 302);
}

$pdo = Database::getInstance()->getConnection();

// Filters
$status = $_GET['status'] ?? 'PENDING';
$type = (int)($_GET['type'] ?? 0);
$department = $_GET['department'] ?? '';
$month = $_GET['month'] ?? date('Y-m');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = DEFAULT_PER_PAGE;
$offset = ($page - 1) * $limit;

// Get leave types
$stmtTypes = $pdo->query("SELECT id, name FROM hr_leave_types WHERE is_active = 1 ORDER BY sort_order");
$leaveTypes = $stmtTypes->fetchAll();

// Get departments
$stmtDepts = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department");
$departments = $stmtDepts->fetchAll(PDO::FETCH_COLUMN);

// Build query
$sql = "
    SELECT lr.*, lt.name as leave_type_name, lt.color as color_code,
           u.first_name_th, u.last_name_th, u.employee_code, u.department,
           approver.first_name_th as approver_first, approver.last_name_th as approver_last
    FROM hr_leave_requests lr
    JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
    JOIN users u ON lr.user_id = u.id
    LEFT JOIN users approver ON lr.final_approved_by = approver.id
    WHERE 1=1
";
$params = [];

if ($status && $status !== 'ALL') {
    $sql .= " AND lr.status = ?";
    $params[] = $status;
}

if ($type > 0) {
    $sql .= " AND lr.leave_type_id = ?";
    $params[] = $type;
}

if ($department) {
    $sql .= " AND u.department = ?";
    $params[] = $department;
}

if ($month) {
    $sql .= " AND DATE_FORMAT(lr.start_date, '%Y-%m') = ?";
    $params[] = $month;
}

// Count total
$countSql = "SELECT COUNT(*) FROM (" . str_replace("lr.*, lt.name as leave_type_name, lt.color as color_code,\n           u.first_name_th, u.last_name_th, u.employee_code, u.department,\n           approver.first_name_th as approver_first, approver.last_name_th as approver_last", "1", $sql) . ") t";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// Get records
$sql .= " ORDER BY " . ($status === 'PENDING' ? "lr.created_at ASC" : "lr.created_at DESC");
$sql .= " LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// Stats
$stmtStats = $pdo->query("
    SELECT 
        SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) as rejected,
        COUNT(*) as total
    FROM hr_leave_requests
    WHERE DATE_FORMAT(start_date, '%Y-%m') = '" . ($month ?: date('Y-m')) . "'
");
$stats = $stmtStats->fetch();

$filterBase = ['month' => $month];
if ($type > 0) {
    $filterBase['type'] = (string)$type;
}
if ($department !== '') {
    $filterBase['department'] = $department;
}

$current_page = 'hr-leaves';
include dirname(__DIR__) . '/templates/header.php';
?>

<div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(1200px,100%)] mx-auto min-w-0">
<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="tp-tap-48 hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <span class="text-white">จัดการการลา</span>
    </nav>
    <div class="min-w-0">
        <h1 class="tp-ios-page-title">จัดการการลา</h1>
        <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">อนุมัติคำขอลา กรองตามเดือน แผนก และประเภท</p>
    </div>
</header>

<!-- Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-2 sm:gap-4 mb-6 min-w-0 max-w-full">
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'PENDING']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $status === 'PENDING' ? 'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">รออนุมัติ</p>
        <p class="text-2xl font-bold text-amber-400 tabular-nums mt-1"><?php echo (int)($stats['pending'] ?? 0); ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'APPROVED']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $status === 'APPROVED' ? 'ring-2 ring-emerald-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">อนุมัติแล้ว</p>
        <p class="text-2xl font-bold text-emerald-400 tabular-nums mt-1"><?php echo (int)($stats['approved'] ?? 0); ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'REJECTED']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $status === 'REJECTED' ? 'ring-2 ring-red-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">ไม่อนุมัติ</p>
        <p class="text-2xl font-bold text-red-400 tabular-nums mt-1"><?php echo (int)($stats['rejected'] ?? 0); ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'ALL']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $status === 'ALL' ? 'ring-2 ring-violet-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">ทั้งหมด</p>
        <p class="text-2xl font-bold text-violet-400 tabular-nums mt-1"><?php echo (int)($stats['total'] ?? 0); ?></p>
    </a>
</div>

<!-- Filters -->
<div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 mb-6 min-w-0 overflow-hidden rounded-[var(--tp-ios-card-radius)]">
    <h2 class="section-title mb-4 text-white text-lg">
        <i class="fas fa-filter text-violet-400 text-xl mr-2" aria-hidden="true"></i>
        กรองคำขอลา
    </h2>
    <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 lg:items-end gap-4">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
        <div class="min-w-0 tp-native-form-group mb-0">
            <label for="hr-leaves-month" class="text-white/70 text-sm font-medium">เดือน</label>
            <input type="month" id="hr-leaves-month" name="month" value="<?php echo htmlspecialchars($month); ?>" class="input-field tp-native-input w-full" onchange="this.form.submit()">
        </div>
        <div class="min-w-0 tp-native-form-group mb-0">
            <label for="hr-leaves-type" class="text-white/70 text-sm font-medium">ประเภทการลา</label>
            <select id="hr-leaves-type" name="type" class="input-field tp-native-select w-full" onchange="this.form.submit()">
                <option value="">ทั้งหมด</option>
                <?php foreach ($leaveTypes as $lt): ?>
                <option value="<?php echo (int)$lt['id']; ?>" <?php echo $type === (int)$lt['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($lt['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="min-w-0 sm:col-span-2 lg:col-span-1 tp-native-form-group mb-0">
            <label for="hr-leaves-dept" class="text-white/70 text-sm font-medium">แผนก</label>
            <select id="hr-leaves-dept" name="department" class="input-field tp-native-select w-full" onchange="this.form.submit()">
                <option value="">ทั้งหมด</option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department === $dept ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="sm:col-span-2 lg:col-span-2 grid grid-cols-2 gap-2 min-w-0">
            <a href="leaves.php"
               class="min-h-[48px] px-3 sm:px-4 inline-flex items-center justify-center gap-2 bg-white/10 hover:bg-white/20 text-white text-sm font-medium text-center rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation">
                <i class="fas fa-redo shrink-0" aria-hidden="true"></i><span>รีเซ็ต</span>
            </a>
            <a href="leaves.php?action=calendar&amp;month=<?php echo urlencode($month); ?>"
               class="min-h-[56px] px-3 sm:px-4 inline-flex items-center justify-center gap-2 bg-violet-600 hover:bg-violet-700 text-white text-sm font-semibold text-center rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation">
                <i class="fas fa-calendar-alt shrink-0" aria-hidden="true"></i><span>ปฏิทิน</span>
            </a>
        </div>
    </form>
</div>

<!-- Results -->
<div class="native-card tp-native-card tp-native-data-card overflow-hidden min-w-0 rounded-[var(--tp-ios-card-radius)]">
    <?php if (empty($requests)): ?>
    <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-4 my-4">
        <i class="fas fa-calendar-check text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
        <p class="text-slate-400 text-sm">ไม่พบคำขอลา</p>
    </div>
    <?php else: ?>
    <!-- Card list: จอกว้างต่ำกว่า lg — ตารางเมื่อจอใหญ่พอ -->
    <div class="lg:hidden p-3 space-y-3">
        <?php
        $statusColors = [
            'PENDING' => 'bg-amber-500/15 border border-amber-500/35 text-amber-200',
            'APPROVED' => 'bg-emerald-500/15 border border-emerald-500/35 text-emerald-200',
            'REJECTED' => 'bg-red-500/15 border border-red-500/35 text-red-200',
            'CANCELLED' => 'bg-slate-500/15 border border-slate-500/35 text-slate-200'
        ];
        $statusText = [
            'PENDING' => 'รออนุมัติ',
            'APPROVED' => 'อนุมัติ',
            'REJECTED' => 'ไม่อนุมัติ',
            'CANCELLED' => 'ยกเลิก'
        ];
        ?>
        <?php foreach ($requests as $req): ?>
        <?php
        $fullName = trim((string)($req['first_name_th'] ?? '') . ' ' . (string)($req['last_name_th'] ?? ''));
        $empCode = (string)($req['employee_code'] ?? '');
        $dept = (string)($req['department'] ?? '-');
        $leaveName = (string)($req['leave_type_name'] ?? 'ลา');
        $color = (string)($req['color_code'] ?? '#d8c4ad');
        $days = number_format((float)($req['total_days'] ?? 0), 1);
        $dateLabel = formatDateThai($req['start_date']);
        if (($req['start_date'] ?? '') !== ($req['end_date'] ?? '')) {
            $dateLabel .= ' - ' . formatDateThai($req['end_date']);
        }
        $reason = trim((string)($req['reason'] ?? ''));
        $statusKey = (string)($req['status'] ?? 'PENDING');
        $chipCls = $statusColors[$statusKey] ?? 'bg-white/5 border border-white/10 text-white/70';
        $chipText = $statusText[$statusKey] ?? $statusKey;
        ?>
        <div class="tp-ios-attendance-panel p-5">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-white font-semibold truncate break-words"><?php echo htmlspecialchars($fullName); ?></div>
                    <div class="text-white/50 text-xs truncate"><?php echo htmlspecialchars($empCode); ?> · <?php echo htmlspecialchars($dept); ?></div>
                </div>
                <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-[var(--tp-ios-card-radius)] text-xs font-semibold <?php echo $chipCls; ?>">
                    <?php echo htmlspecialchars($chipText); ?>
                </span>
            </div>

            <div class="mt-3 flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: <?php echo htmlspecialchars($color); ?>"></span>
                <span class="text-white/90 font-semibold text-sm"><?php echo htmlspecialchars($leaveName); ?></span>
                <span class="text-white/50 text-xs">·</span>
                <span class="text-white/70 text-xs"><?php echo htmlspecialchars($days); ?> วัน</span>
            </div>

            <div class="mt-2 rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 px-3 py-2">
                <div class="text-[11px] text-white/50">ช่วงวันที่ลา</div>
                <div class="text-white font-semibold text-sm"><?php echo htmlspecialchars($dateLabel); ?></div>
            </div>

            <?php if ($reason !== ''): ?>
            <div class="mt-3 text-white/70 text-xs line-clamp-2">
                <i class="fas fa-note-sticky text-white/40 mr-1"></i><?php echo htmlspecialchars($reason); ?>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-2 gap-2 mt-4">
                <?php if ($statusKey === 'PENDING'): ?>
                <button type="button"
                        onclick="openApproveLeave(<?php echo (int)$req['id']; ?>)"
                        class="min-h-[56px] rounded-[var(--tp-ios-card-radius)] bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold touch-manipulation shadow-sm shadow-emerald-900/40 whitespace-nowrap">
                    <i class="fas fa-check mr-2" aria-hidden="true"></i>อนุมัติ
                </button>
                <button type="button"
                        onclick="rejectLeave(<?php echo (int)$req['id']; ?>)"
                        class="min-h-[56px] rounded-[var(--tp-ios-card-radius)] bg-red-500/15 hover:bg-red-500/25 border border-red-500/35 text-red-200 text-sm font-semibold touch-manipulation whitespace-nowrap">
                    <i class="fas fa-times mr-2" aria-hidden="true"></i>ไม่อนุมัติ
                </button>
                <?php else: ?>
                <button type="button"
                        onclick="viewDetail(<?php echo (int)$req['id']; ?>)"
                        class="col-span-2 min-h-[48px] rounded-[var(--tp-ios-card-radius)] bg-white/10 hover:bg-white/20 text-white text-sm font-semibold touch-manipulation whitespace-nowrap">
                    <i class="fas fa-eye mr-2" aria-hidden="true"></i>ดูรายละเอียด
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="hidden lg:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain -mx-1 px-1 pb-px">
        <table class="w-full" style="min-width:960px">
            <thead class="bg-white/5">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">พนักงาน</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ประเภท</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">วันที่ลา</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">จำนวนวัน</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">เหตุผล</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">การดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($requests as $req): ?>
                <?php
                $st = (string)($req['status'] ?? 'PENDING');
                $statusColors = [
                    'PENDING' => 'border border-amber-500/30 bg-amber-500/15 text-amber-300',
                    'APPROVED' => 'border border-emerald-500/30 bg-emerald-500/15 text-emerald-300',
                    'REJECTED' => 'border border-red-500/30 bg-red-500/15 text-red-300',
                    'CANCELLED' => 'border border-slate-500/30 bg-slate-500/15 text-slate-300'
                ];
                $statusText = [
                    'PENDING' => 'รออนุมัติ',
                    'APPROVED' => 'อนุมัติ',
                    'REJECTED' => 'ไม่อนุมัติ',
                    'CANCELLED' => 'ยกเลิก'
                ];
                ?>
                <tr class="hover:bg-white/[0.04]">
                    <td class="px-4 py-3">
                        <p class="text-white font-medium"><?php echo htmlspecialchars($req['first_name_th'] . ' ' . $req['last_name_th']); ?></p>
                        <p class="text-white/50 text-xs"><?php echo htmlspecialchars($req['employee_code'] ?? ''); ?> | <?php echo htmlspecialchars($req['department'] ?? '-'); ?></p>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="w-2 h-2 rounded-full shrink-0" style="background-color: <?php echo htmlspecialchars((string)($req['color_code'] ?? '#d8c4ad')); ?>"></span>
                            <span class="text-white truncate"><?php echo htmlspecialchars($req['leave_type_name']); ?></span>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-white/80 text-sm">
                        <?php echo formatDateThai($req['start_date']); ?>
                        <?php if ($req['start_date'] !== $req['end_date']): ?>
                        <br><span class="text-white/50">ถึง</span> <?php echo formatDateThai($req['end_date']); ?>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-white font-medium">
                        <?php echo number_format($req['total_days'], 1); ?>
                    </td>
                    <td class="px-4 py-3 text-white/70 text-sm max-w-xs">
                        <p class="truncate" title="<?php echo htmlspecialchars($req['reason']); ?>">
                            <?php echo htmlspecialchars($req['reason']); ?>
                        </p>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="inline-flex items-center px-3 py-1 rounded-[var(--tp-ios-card-radius)] text-xs <?php echo $statusColors[$st] ?? 'border border-white/10 bg-white/5 text-white/70'; ?>">
                            <?php echo htmlspecialchars($statusText[$st] ?? $st); ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex flex-wrap items-center justify-center gap-2">
                        <?php if ($req['status'] === 'PENDING'): ?>
                        <button type="button" onclick="openApproveLeave(<?php echo (int)$req['id']; ?>)" 
                                class="inline-flex items-center justify-center gap-1.5 min-h-[56px] px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation whitespace-nowrap">
                            <i class="fas fa-check" aria-hidden="true"></i><span>อนุมัติ</span>
                        </button>
                        <button type="button" onclick="rejectLeave(<?php echo (int)$req['id']; ?>)"
                                class="inline-flex items-center justify-center gap-1.5 min-h-[48px] px-3 py-2 bg-red-500/15 hover:bg-red-500/25 border border-red-500/35 text-red-200 text-sm font-medium rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation whitespace-nowrap">
                            <i class="fas fa-times" aria-hidden="true"></i><span>ปฏิเสธ</span>
                        </button>
                        <?php else: ?>
                        <button type="button" onclick="viewDetail(<?php echo (int)$req['id']; ?>)" class="inline-flex items-center justify-center gap-1.5 min-h-[48px] px-4 py-2 bg-white/10 hover:bg-white/20 text-white text-sm rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation whitespace-nowrap">
                            <i class="fas fa-eye" aria-hidden="true"></i><span>ดู</span>
                        </button>
                        <?php endif; ?>
                        </div>
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
        <div class="flex flex-wrap gap-2">
            <?php if ($page > 1): ?>
            <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1]))); ?>" 
               class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center px-3 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation" aria-label="หน้าก่อน">
                <i class="fas fa-chevron-left" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $i]))); ?>" 
               class="inline-flex min-h-[56px] min-w-[48px] items-center justify-center px-3 <?php echo $i === $page ? 'bg-violet-600 text-white' : 'bg-white/10 hover:bg-white/20 text-white'; ?> rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation">
                <?php echo (int)$i; ?>
            </a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page + 1]))); ?>" 
               class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center px-3 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation" aria-label="หน้าถัดไป">
                <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
</div>

<!-- Modals: อนุมัติ — ยืนยันใน shell แทน window.confirm -->
<div id="approve-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="approve-modal-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto rounded-[var(--tp-ios-card-radius)] p-6 pb-[calc(env(safe-area-inset-bottom,0px)+1.5rem)]">
        <div class="flex items-start gap-3 mb-4">
            <div class="w-12 h-12 rounded-full bg-emerald-500/20 flex items-center justify-center text-emerald-400 shrink-0">
                <i class="fas fa-check text-xl" aria-hidden="true"></i>
            </div>
            <div class="min-w-0">
                <h3 id="approve-modal-title" class="text-xl font-bold text-white leading-tight">อนุมัติคำขอลา</h3>
                <p class="text-white/65 text-sm mt-1">ยืนยันการอนุมัติคำขอลานี้?</p>
            </div>
        </div>
        <input type="hidden" id="approve-request-id" value="">
        <div class="flex flex-col sm:flex-row gap-3 pt-2">
            <button type="button" onclick="closeApproveModal()" class="flex-1 min-h-[48px] py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-medium whitespace-nowrap">ยกเลิก</button>
            <button type="button" onclick="confirmApproveLeave()" class="flex-1 min-h-[56px] py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold whitespace-nowrap">อนุมัติ</button>
        </div>
    </div>
</div>

<div id="reject-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="reject-modal-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden rounded-[var(--tp-ios-card-radius)] pb-[calc(env(safe-area-inset-bottom,0px)+1rem)]">
        <form id="reject-form" class="p-6">
            <h3 id="reject-modal-title" class="text-xl font-bold text-white mb-4">ไม่อนุมัติคำขอลา</h3>
            <input type="hidden" name="request_id" id="reject-request-id">
            <div class="tp-native-form-group mb-4">
                <label for="reject-reason" class="text-white/80 text-sm">เหตุผล <span class="text-red-400" aria-hidden="true">*</span></label>
                <textarea name="reason" id="reject-reason" required rows="3" class="input-field tp-native-textarea"></textarea>
            </div>
            <div class="flex flex-col sm:flex-row gap-4">
                <button type="button" onclick="closeRejectModal()" class="flex-1 min-h-[48px] py-2 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-medium whitespace-nowrap">ยกเลิก</button>
                <button type="submit" class="flex-1 min-h-[56px] py-2 bg-red-600 hover:bg-red-700 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold whitespace-nowrap">ไม่อนุมัติ</button>
            </div>
        </form>
    </div>
</div>

<div id="detail-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="detail-modal-title">
    <div class="native-card tp-native-card w-full max-w-lg my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden rounded-[var(--tp-ios-card-radius)] pb-[calc(env(safe-area-inset-bottom,0px)+1rem)]">
        <div class="p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 id="detail-modal-title" class="text-xl font-bold text-white">รายละเอียดคำขอลา</h3>
                <button type="button" onclick="closeDetailModal()" class="min-h-[48px] min-w-[48px] inline-flex items-center justify-center text-white/60 hover:text-white hover:bg-white/10 rounded-[var(--tp-ios-card-radius)] touch-manipulation" aria-label="ปิด">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </div>
            <div id="detail-content">
                <div class="tp-native-loading-state py-8" role="status" aria-live="polite" aria-busy="true"><i class="fas fa-spinner fa-spin text-2xl text-white/30" aria-hidden="true"></i><span class="tp-visually-hidden">กำลังโหลด</span></div>
            </div>
        </div>
    </div>
</div>

<script>
function openApproveLeave(id) {
    document.getElementById('approve-request-id').value = String(id);
    if (typeof uiOpenModal === 'function') uiOpenModal('approve-modal');
    else {
        const m = document.getElementById('approve-modal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}

function closeApproveModal() {
    if (typeof uiCloseModal === 'function') uiCloseModal('approve-modal');
    else {
        const m = document.getElementById('approve-modal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

async function confirmApproveLeave() {
    const id = document.getElementById('approve-request-id').value;
    if (!id) return;

    const formData = new FormData();
    formData.append('action', 'approve');
    formData.append('request_id', id);
    formData.append('_token', '<?php echo csrfToken(); ?>');

    try {
        const response = await fetch('/api/leave.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.success) {
            showToast('อนุมัติสำเร็จ', 'success');
            closeApproveModal();
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (e) {
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}


function rejectLeave(id) {
    document.getElementById('reject-request-id').value = id;
    document.getElementById('reject-reason').value = '';
    if (typeof uiOpenModal === 'function') uiOpenModal('reject-modal');
    else {
        const m = document.getElementById('reject-modal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}

function closeRejectModal() {
    if (typeof uiCloseModal === 'function') uiCloseModal('reject-modal');
    else {
        const m = document.getElementById('reject-modal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

document.getElementById('reject-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData();
    formData.append('action', 'reject');
    formData.append('request_id', document.getElementById('reject-request-id').value);
    formData.append('reason', document.getElementById('reject-reason').value);
    formData.append('_token', '<?php echo csrfToken(); ?>');
    
    const response = await fetch('/api/leave.php', { method: 'POST', body: formData });
    const result = await response.json();
    
    if (result.success) {
        showToast('บันทึกผลสำเร็จ', 'success');
        setTimeout(() => location.reload(), 1000);
    } else {
        showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
    }
});

async function viewDetail(id) {
    if (typeof uiOpenModal === 'function') uiOpenModal('detail-modal');
    else {
        const m = document.getElementById('detail-modal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
    document.getElementById('detail-content').innerHTML = typeof tpHrNativeLoadingHtml === 'function'
        ? tpHrNativeLoadingHtml()
        : '<div class="tp-native-loading-state py-8" role="status" aria-live="polite" aria-busy="true"><i class="fas fa-spinner fa-spin text-2xl text-white/30" aria-hidden="true"></i><span class="tp-visually-hidden">กำลังโหลด</span></div>';
    
    const response = await fetch(`/api/leave.php?action=detail&id=${id}`);
    const result = await response.json();
    
    if (result.success) {
        const r = result.request;
        document.getElementById('detail-content').innerHTML = `
            <div class="space-y-4">
                <div class="flex items-center justify-between"><span class="text-white/60">พนักงาน</span><span class="text-white">${r.user_name}</span></div>
                <div class="flex items-center justify-between"><span class="text-white/60">ประเภทการลา</span><span class="text-white">${r.leave_type_name}</span></div>
                <div class="flex items-center justify-between"><span class="text-white/60">วันที่ลา</span><span class="text-white">${r.start_date} ${r.start_date !== r.end_date ? '- ' + r.end_date : ''}</span></div>
                <div class="flex items-center justify-between"><span class="text-white/60">จำนวนวัน</span><span class="text-white">${parseFloat(r.total_days).toFixed(1)} วัน</span></div>
                <div class="pt-2 border-t border-white/10"><span class="text-white/60 block mb-2">เหตุผล</span><p class="text-white">${r.reason}</p></div>
                ${r.approved_by_name ? `<div class="pt-2 border-t border-white/10"><span class="text-white/60">ผู้พิจารณา</span><span class="text-white ml-2">${r.approved_by_name}</span></div>` : ''}
            </div>
        `;
    }
}

function closeDetailModal() {
    if (typeof uiCloseModal === 'function') uiCloseModal('detail-modal');
    else {
        const m = document.getElementById('detail-modal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

document.getElementById('approve-modal').addEventListener('click', e => { if (e.target === document.getElementById('approve-modal')) closeApproveModal(); });
document.getElementById('reject-modal').addEventListener('click', e => { if (e.target === document.getElementById('reject-modal')) closeRejectModal(); });
document.getElementById('detail-modal').addEventListener('click', e => { if (e.target === document.getElementById('detail-modal')) closeDetailModal(); });
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
