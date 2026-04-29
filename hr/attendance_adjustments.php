<?php
/**
 * HR Attendance Adjustment Approvals
 * อนุมัติคำขอแก้ไขเวลาเข้า-ออกงาน - CEO ขึ้นไป
 */

$page_title = 'อนุมัติแก้เวลา';
require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();

if (!hr_can_access_hr_dashboard()) {
    redirect('/', 302);
}

if (!isCEOOrAbove()) {
    flash('error', 'ต้องเป็นระดับ CEO ขึ้นไปเท่านั้น');
    redirect('/hr/', 302);
}

$pdo = Database::getInstance()->getConnection();
$current_page = 'hr-attendance-adjustments';

function hrAdjStatusLabel(string $status): string
{
    return match ($status) {
        'PENDING' => 'รออนุมัติ',
        'APPROVED' => 'อนุมัติ',
        'REJECTED' => 'ไม่อนุมัติ',
        'CANCELLED' => 'ยกเลิก',
        default => $status,
    };
}

function hrAdjStatusClass(string $status): string
{
    return match ($status) {
        'PENDING' => 'border border-amber-500/35 bg-amber-500/15 text-amber-200',
        'APPROVED' => 'border border-emerald-500/35 bg-emerald-500/15 text-emerald-200',
        'REJECTED' => 'border border-red-500/35 bg-red-500/15 text-red-200',
        'CANCELLED' => 'border border-slate-500/35 bg-slate-500/15 text-slate-200',
        default => 'border border-slate-500/35 bg-slate-500/15 text-slate-200',
    };
}

function hrAdjTypeLabel(?string $type): string
{
    return match ((string)$type) {
        'check_in' => 'แก้เวลาเข้า',
        'check_out' => 'แก้เวลาออก',
        'both' => 'แก้เวลาเข้าและออก',
        default => 'แก้เวลา',
    };
}

function hrAdjTime(?string $value): string
{
    if (!$value) {
        return '--:--';
    }
    $ts = strtotime($value);
    return $ts ? date('H:i', $ts) : '--:--';
}

function hrAdjDate(?string $value): string
{
    if (!$value) {
        return '-';
    }
    return function_exists('formatDateThai') ? formatDateThai($value) : date('d/m/Y', strtotime($value));
}

$service = new AttendanceAdjustmentService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_token'] ?? ($_POST['csrf_token'] ?? ''))) {
        flash('error', 'เซสชันหมดอายุหรือข้อมูลไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง');
        redirect($_SERVER['REQUEST_URI'] ?? '/hr/attendance_adjustments.php', 302);
    }

    $action = (string)($_POST['action'] ?? '');
    $requestId = (int)($_POST['request_id'] ?? 0);
    $reviewRemarks = trim((string)($_POST['review_remarks'] ?? ''));

    try {
        if ($action === 'approve' && $requestId > 0) {
            $result = $service->approve($requestId, (int)$user['id'], $reviewRemarks);
            Auth::log('attendance_adjustment_approve', 'hr_attendance_adjustments', $requestId, null, [
                'attendance_id' => $result['attendance_id'] ?? null,
                'review_remarks' => $reviewRemarks ?: null,
            ]);
            flash('success', 'อนุมัติคำขอแก้เวลาเรียบร้อยแล้ว');
        } elseif ($action === 'reject' && $requestId > 0) {
            $result = $service->reject($requestId, (int)$user['id'], $reviewRemarks);
            Auth::log('attendance_adjustment_reject', 'hr_attendance_adjustments', $requestId, null, [
                'attendance_id' => $result['attendance_id'] ?? null,
                'review_remarks' => $reviewRemarks,
            ]);
            flash('success', 'ไม่อนุมัติคำขอแก้เวลาเรียบร้อยแล้ว');
        } elseif ($action === 'approve_all') {
            $requestIds = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['request_ids'] ?? '')))));
            $approvedCount = 0;
            foreach ($requestIds as $id) {
                $service->approve($id, (int)$user['id'], $reviewRemarks);
                $approvedCount++;
            }
            Auth::log('attendance_adjustment_approve_all', 'hr_attendance_adjustments', null, null, [
                'approved_count' => $approvedCount,
                'request_ids' => $requestIds,
                'review_remarks' => $reviewRemarks ?: null,
            ]);
            flash('success', 'อนุมัติคำขอแก้เวลาทั้งหมด ' . number_format($approvedCount) . ' รายการ');
        } else {
            flash('error', 'ไม่พบคำสั่งที่ต้องการดำเนินการ');
        }
    } catch (AttendanceAdjustmentException $e) {
        flash('error', $e->getMessage());
    } catch (Throwable $e) {
        tpHrLogException($e, 'hr/attendance_adjustments');
        flash('error', 'เกิดข้อผิดพลาดภายในระบบ กรุณาลองใหม่');
    }

    redirect('/hr/attendance_adjustments.php?' . http_build_query($_GET), 302);
}

$statusFilter = strtoupper(trim((string)($_GET['status'] ?? 'PENDING')));
if (!in_array($statusFilter, ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED', ''], true)) {
    $statusFilter = 'PENDING';
}
$month = trim((string)($_GET['month'] ?? ''));
$department = trim((string)($_GET['department'] ?? ''));

$stmtDepts = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' AND id NOT IN (" . SYSTEM_USER_IDS_SQL . ") ORDER BY department");
$departments = $stmtDepts->fetchAll(PDO::FETCH_COLUMN);

$where = ["u.id NOT IN (" . SYSTEM_USER_IDS_SQL . ")"];
$params = [];
if ($statusFilter !== '') {
    $where[] = "adj.status = ?";
    $params[] = $statusFilter;
}
if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
    $where[] = "DATE_FORMAT(att.attendance_date, '%Y-%m') = ?";
    $params[] = $month;
} else {
    $month = '';
}
if ($department !== '') {
    $where[] = "u.department = ?";
    $params[] = $department;
}

$sql = "
    SELECT adj.*,
           att.attendance_date,
           att.check_in_time AS current_check_in,
           att.check_out_time AS current_check_out,
           att.work_minutes,
           u.first_name_th, u.last_name_th, u.employee_code, u.department,
           reviewer.first_name_th AS reviewer_first_name,
           reviewer.last_name_th AS reviewer_last_name
    FROM hr_attendance_adjustments adj
    JOIN hr_attendances att ON att.id = adj.attendance_id
    JOIN users u ON u.id = adj.user_id
    LEFT JOIN users reviewer ON reviewer.id = adj.reviewed_by
    WHERE " . implode(' AND ', $where) . "
    ORDER BY CASE adj.status WHEN 'PENDING' THEN 0 WHEN 'APPROVED' THEN 1 WHEN 'REJECTED' THEN 2 ELSE 3 END,
             adj.created_at DESC,
             adj.id DESC
    LIMIT 1000
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
$visiblePendingIds = array_values(array_map(
    static fn(array $r): int => (int)$r['id'],
    array_filter($requests, static fn(array $r): bool => (string)$r['status'] === 'PENDING')
));

$statsWhere = [];
$statsParams = [];
if ($month !== '') {
    $statsWhere[] = "DATE_FORMAT(att.attendance_date, '%Y-%m') = ?";
    $statsParams[] = $month;
}
if ($department !== '') {
    $statsWhere[] = "u.department = ?";
    $statsParams[] = $department;
}
$statsSql = "
    SELECT
        SUM(CASE WHEN adj.status = 'PENDING' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN adj.status = 'APPROVED' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN adj.status = 'REJECTED' THEN 1 ELSE 0 END) AS rejected,
        SUM(CASE WHEN adj.status = 'CANCELLED' THEN 1 ELSE 0 END) AS cancelled,
        COUNT(*) AS total
    FROM hr_attendance_adjustments adj
    JOIN hr_attendances att ON att.id = adj.attendance_id
    JOIN users u ON u.id = adj.user_id
";
if ($statsWhere) {
    $statsSql .= " WHERE " . implode(' AND ', $statsWhere);
}
$stmtStats = $pdo->prepare($statsSql);
$stmtStats->execute($statsParams);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0, 'total' => 0];
$pendingCount = (int)($stats['pending'] ?? 0);

$filterBase = [];
if ($month !== '') {
    $filterBase['month'] = $month;
}
if ($department !== '') {
    $filterBase['department'] = $department;
}

include dirname(__DIR__) . '/templates/header.php';
?>

<div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <span class="text-white">อนุมัติแก้เวลา</span>
    </nav>
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div class="min-w-0 flex-1">
            <h1 class="tp-ios-page-title">อนุมัติคำขอแก้เวลาเข้า-ออกงาน</h1>
            <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">ตรวจสอบคำขอจาก TP-Checkin ก่อนนำเวลาที่แก้ไขไปอัปเดตในประวัติลงเวลา</p>
        </div>
        <?php if (count($visiblePendingIds) > 0 && $statusFilter === 'PENDING'): ?>
        <button type="button"
                onclick="openApproveAllModal()"
                class="w-full sm:w-auto min-h-[56px] px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation font-semibold shadow-sm shadow-emerald-900/30">
            <i class="fas fa-check-double mr-2" aria-hidden="true"></i>อนุมัติทั้งหมด (<?php echo count($visiblePendingIds); ?>)
        </button>
        <?php endif; ?>
    </div>
</header>

<?php if ($flashOk = flash('success')): ?>
<div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-emerald-200" role="status">
    <p><i class="fas fa-check-circle mr-2" aria-hidden="true"></i><?php echo htmlspecialchars($flashOk); ?></p>
</div>
<?php endif; ?>

<?php if ($flashErr = flash('error')): ?>
<div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-red-500/30 bg-red-500/15 px-4 py-3 text-red-200" role="alert">
    <p><i class="fas fa-exclamation-circle mr-2" aria-hidden="true"></i><?php echo htmlspecialchars($flashErr); ?></p>
</div>
<?php endif; ?>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-2 sm:gap-4 mb-6 min-w-0 max-w-full">
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'PENDING']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-4 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $statusFilter === 'PENDING' ? 'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">รออนุมัติ</p>
        <p class="text-2xl font-bold text-amber-400 tabular-nums mt-1"><?php echo (int)($stats['pending'] ?? 0); ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'APPROVED']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-4 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $statusFilter === 'APPROVED' ? 'ring-2 ring-emerald-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">อนุมัติแล้ว</p>
        <p class="text-2xl font-bold text-emerald-400 tabular-nums mt-1"><?php echo (int)($stats['approved'] ?? 0); ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'REJECTED']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-4 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $statusFilter === 'REJECTED' ? 'ring-2 ring-red-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">ไม่อนุมัติ</p>
        <p class="text-2xl font-bold text-red-400 tabular-nums mt-1"><?php echo (int)($stats['rejected'] ?? 0); ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'CANCELLED']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-4 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $statusFilter === 'CANCELLED' ? 'ring-2 ring-slate-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">ยกเลิก</p>
        <p class="text-2xl font-bold text-slate-300 tabular-nums mt-1"><?php echo (int)($stats['cancelled'] ?? 0); ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => '']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-4 min-w-0 overflow-hidden touch-manipulation transition-shadow col-span-2 lg:col-span-1 <?php echo $statusFilter === '' ? 'ring-2 ring-violet-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">ทั้งหมด</p>
        <p class="text-2xl font-bold text-violet-400 tabular-nums mt-1"><?php echo (int)($stats['total'] ?? 0); ?></p>
    </a>
</div>

<div class="native-card tp-native-card tp-native-data-card p-4 sm:p-6 mb-6 min-w-0 overflow-hidden rounded-[var(--tp-ios-card-radius)]">
    <h2 class="section-title mb-4 text-white text-lg">
        <i class="fas fa-filter text-violet-400 text-xl mr-2" aria-hidden="true"></i>
        กรองคำขอ
    </h2>
    <form method="GET" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="tp-native-form-group mb-0">
            <label for="hr-adj-status" class="text-white/70 text-sm font-medium">สถานะ</label>
            <select id="hr-adj-status" name="status" class="input-field tp-native-select w-full" onchange="this.form.submit()">
                <option value="PENDING" <?php echo $statusFilter === 'PENDING' ? 'selected' : ''; ?>>รออนุมัติ<?php echo $pendingCount > 0 ? ' (' . $pendingCount . ')' : ''; ?></option>
                <option value="APPROVED" <?php echo $statusFilter === 'APPROVED' ? 'selected' : ''; ?>>อนุมัติแล้ว</option>
                <option value="REJECTED" <?php echo $statusFilter === 'REJECTED' ? 'selected' : ''; ?>>ไม่อนุมัติ</option>
                <option value="CANCELLED" <?php echo $statusFilter === 'CANCELLED' ? 'selected' : ''; ?>>ยกเลิก</option>
                <option value="" <?php echo $statusFilter === '' ? 'selected' : ''; ?>>ทั้งหมด</option>
            </select>
        </div>
        <div class="tp-native-form-group mb-0">
            <label for="hr-adj-month" class="text-white/70 text-sm font-medium">เดือน</label>
            <input type="month" id="hr-adj-month" name="month" class="input-field tp-native-input w-full" value="<?php echo htmlspecialchars($month); ?>" onchange="this.form.submit()">
        </div>
        <div class="tp-native-form-group mb-0">
            <label for="hr-adj-dept" class="text-white/70 text-sm font-medium">แผนก</label>
            <select id="hr-adj-dept" name="department" class="input-field tp-native-select w-full" onchange="this.form.submit()">
                <option value="">ทั้งหมด</option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department === $dept ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<div class="native-card tp-native-card tp-native-data-card overflow-hidden min-w-0 rounded-[var(--tp-ios-card-radius)]">
    <?php if (empty($requests)): ?>
    <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-4 my-4">
        <i class="fas fa-user-clock text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
        <p class="text-slate-400 text-sm">ไม่มีคำขอแก้เวลา</p>
    </div>
    <?php else: ?>
    <div class="md:hidden p-4 space-y-3">
        <?php foreach ($requests as $req): ?>
        <?php
        $st = (string)$req['status'];
        $empName = trim(($req['first_name_th'] ?? '') . ' ' . ($req['last_name_th'] ?? ''));
        ?>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-white font-medium break-words"><?php echo htmlspecialchars($empName); ?></p>
                    <p class="text-white/50 text-xs break-words"><?php echo htmlspecialchars($req['employee_code'] ?? ''); ?> | <?php echo htmlspecialchars($req['department'] ?? '-'); ?></p>
                </div>
                <span class="px-2.5 py-1 text-xs rounded-[var(--tp-ios-card-radius)] shrink-0 font-semibold <?php echo hrAdjStatusClass($st); ?>">
                    <?php echo htmlspecialchars(hrAdjStatusLabel($st)); ?>
                </span>
            </div>
            <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 px-3 py-2">
                    <p class="text-white/50 text-[11px]">วันที่</p>
                    <p class="text-white font-semibold"><?php echo hrAdjDate($req['attendance_date']); ?></p>
                    <p class="text-white/50 text-xs"><?php echo hrAdjTypeLabel($req['adjustment_type'] ?? null); ?></p>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 px-3 py-2">
                    <p class="text-white/50 text-[11px]">เวลาที่ขอแก้</p>
                    <p class="text-emerald-300">เข้า <?php echo hrAdjTime($req['requested_check_in'] ?? null); ?></p>
                    <p class="text-sky-300">ออก <?php echo hrAdjTime($req['requested_check_out'] ?? null); ?></p>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3 mt-3 text-sm">
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 px-3 py-2">
                    <p class="text-white/50 text-[11px]">เดิม</p>
                    <p class="text-white/70">เข้า <?php echo hrAdjTime($req['original_check_in'] ?? null); ?></p>
                    <p class="text-white/70">ออก <?php echo hrAdjTime($req['original_check_out'] ?? null); ?></p>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 px-3 py-2">
                    <p class="text-white/50 text-[11px]">ส่งคำขอ</p>
                    <p class="text-white/70"><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($req['created_at']))); ?></p>
                </div>
            </div>
            <p class="text-white/60 text-sm mt-3 break-words"><?php echo htmlspecialchars($req['reason'] ?? '-'); ?></p>
            <?php if ($st !== 'PENDING' && !empty($req['reviewer_first_name'])): ?>
            <p class="text-white/40 text-xs mt-2">โดย <?php echo htmlspecialchars(trim(($req['reviewer_first_name'] ?? '') . ' ' . ($req['reviewer_last_name'] ?? ''))); ?></p>
            <?php endif; ?>
            <?php if ($st === 'PENDING'): ?>
            <div class="grid grid-cols-2 gap-2 mt-4">
                <button type="button"
                        data-emp-label="<?php echo htmlspecialchars($empName, ENT_QUOTES); ?>"
                        onclick="openApproveOneModal(event, <?php echo (int)$req['id']; ?>)"
                        class="min-h-[56px] rounded-[var(--tp-ios-card-radius)] bg-emerald-600 hover:bg-emerald-700 text-white font-semibold transition-colors touch-manipulation shadow-sm shadow-emerald-900/30">
                    <i class="fas fa-check mr-2" aria-hidden="true"></i>อนุมัติ
                </button>
                <button type="button"
                        data-emp-label="<?php echo htmlspecialchars($empName, ENT_QUOTES); ?>"
                        onclick="openRejectModal(event, <?php echo (int)$req['id']; ?>)"
                        class="min-h-[56px] rounded-[var(--tp-ios-card-radius)] bg-red-500/15 hover:bg-red-500/25 border border-red-500/35 text-red-200 font-semibold transition-colors touch-manipulation">
                    <i class="fas fa-times mr-2" aria-hidden="true"></i>ไม่อนุมัติ
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain -mx-1 px-1 pb-px">
        <table class="w-full" style="min-width:1040px">
            <thead class="bg-white/5">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">พนักงาน</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">วันที่</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ประเภท</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">เวลาเดิม</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">เวลาที่ขอแก้</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">เหตุผล</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($requests as $req): ?>
                <?php
                $st = (string)$req['status'];
                $empName = trim(($req['first_name_th'] ?? '') . ' ' . ($req['last_name_th'] ?? ''));
                ?>
                <tr class="hover:bg-white/[0.04]">
                    <td class="px-4 py-3">
                        <p class="text-white font-medium"><?php echo htmlspecialchars($empName); ?></p>
                        <p class="text-white/50 text-xs"><?php echo htmlspecialchars($req['employee_code'] ?? ''); ?> | <?php echo htmlspecialchars($req['department'] ?? '-'); ?></p>
                    </td>
                    <td class="px-4 py-3 text-center text-white text-sm">
                        <?php echo hrAdjDate($req['attendance_date']); ?>
                        <div class="text-white/45 text-xs"><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($req['created_at']))); ?></div>
                    </td>
                    <td class="px-4 py-3 text-center text-white/70 text-sm"><?php echo htmlspecialchars(hrAdjTypeLabel($req['adjustment_type'] ?? null)); ?></td>
                    <td class="px-4 py-3 text-center text-sm">
                        <div class="text-white/70">เข้า <?php echo hrAdjTime($req['original_check_in'] ?? null); ?></div>
                        <div class="text-white/70">ออก <?php echo hrAdjTime($req['original_check_out'] ?? null); ?></div>
                    </td>
                    <td class="px-4 py-3 text-center text-sm">
                        <div class="text-emerald-300">เข้า <?php echo hrAdjTime($req['requested_check_in'] ?? null); ?></div>
                        <div class="text-sky-300">ออก <?php echo hrAdjTime($req['requested_check_out'] ?? null); ?></div>
                    </td>
                    <td class="px-4 py-3 text-white/60 text-sm max-w-[16rem]">
                        <span class="line-clamp-2"><?php echo htmlspecialchars($req['reason'] ?? '-'); ?></span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="inline-flex items-center px-3 py-1 text-xs rounded-[var(--tp-ios-card-radius)] <?php echo hrAdjStatusClass($st); ?>">
                            <?php echo htmlspecialchars(hrAdjStatusLabel($st)); ?>
                        </span>
                        <?php if ($st !== 'PENDING' && !empty($req['reviewer_first_name'])): ?>
                        <div class="text-white/40 text-xs mt-1">
                            โดย <?php echo htmlspecialchars(trim(($req['reviewer_first_name'] ?? '') . ' ' . ($req['reviewer_last_name'] ?? ''))); ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($st === 'PENDING'): ?>
                        <div class="flex flex-wrap items-center justify-center gap-2">
                            <button type="button"
                                    data-emp-label="<?php echo htmlspecialchars($empName, ENT_QUOTES); ?>"
                                    onclick="openApproveOneModal(event, <?php echo (int)$req['id']; ?>)"
                                    class="inline-flex items-center gap-1.5 min-h-[56px] px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation">
                                <i class="fas fa-check" aria-hidden="true"></i><span>อนุมัติ</span>
                            </button>
                            <button type="button"
                                    data-emp-label="<?php echo htmlspecialchars($empName, ENT_QUOTES); ?>"
                                    onclick="openRejectModal(event, <?php echo (int)$req['id']; ?>)"
                                    class="inline-flex items-center gap-1.5 min-h-[48px] px-3 py-2 bg-red-500/15 hover:bg-red-500/25 border border-red-500/35 text-red-200 text-sm font-medium rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation">
                                <i class="fas fa-times" aria-hidden="true"></i><span>ปฏิเสธ</span>
                            </button>
                        </div>
                        <?php else: ?>
                        <span class="text-white/30 text-xs">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
</div>

<form id="approve-one-form" method="POST" class="hidden" aria-hidden="true"><?php echo csrfField(); ?>
    <input type="hidden" name="action" value="approve">
    <input type="hidden" name="request_id" id="approve-one-request-id" value="">
    <input type="hidden" name="review_remarks" id="approve-one-review-remarks" value="">
</form>

<div id="approve-one-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="approve-one-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto rounded-[var(--tp-ios-card-radius)] p-6 pb-[calc(env(safe-area-inset-bottom,0px)+1.5rem)]">
        <h3 id="approve-one-title" class="text-xl font-bold text-white mb-1">อนุมัติคำขอแก้เวลา?</h3>
        <p class="text-white/65 text-sm mb-4" id="approve-one-label"></p>
        <div class="tp-native-form-group mb-5">
            <label for="approve-one-note" class="block text-white/70 text-sm">หมายเหตุ (ถ้ามี)</label>
            <input type="text" id="approve-one-note" class="input-field tp-native-input w-full" placeholder="บันทึกประกอบการอนุมัติ" autocomplete="off">
        </div>
        <div class="flex flex-col sm:flex-row gap-3">
            <button type="button" onclick="closeApproveOneModal()" class="flex-1 min-h-[48px] py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-medium">ยกเลิก</button>
            <button type="button" onclick="submitApproveOne()" class="flex-1 min-h-[56px] py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold">อนุมัติ</button>
        </div>
    </div>
</div>

<div id="approve-all-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="approve-all-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto rounded-[var(--tp-ios-card-radius)] p-6 pb-[calc(env(safe-area-inset-bottom,0px)+1.5rem)]">
        <h3 id="approve-all-title" class="text-xl font-bold text-white mb-1">อนุมัติคำขอที่รอทั้งหมด?</h3>
        <p class="text-white/65 text-sm mb-4">มีคำขอแก้เวลารออนุมัติ <strong class="text-white"><?php echo count($visiblePendingIds); ?></strong> รายการตามตัวกรองปัจจุบัน</p>
        <form method="POST" id="approve-all-form-el">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="approve_all">
            <input type="hidden" name="request_ids" value="<?php echo htmlspecialchars(implode(',', $visiblePendingIds)); ?>">
            <div class="tp-native-form-group mb-5">
                <label for="approve-all-note" class="block text-white/70 text-sm">หมายเหตุ (ถ้ามี)</label>
                <input type="text" name="review_remarks" id="approve-all-note" class="input-field tp-native-input w-full" placeholder="บันทึกประกอบการอนุมัติทั้งหมด" autocomplete="off">
            </div>
            <div class="flex flex-col sm:flex-row gap-3">
                <button type="button" onclick="closeApproveAllModal()" class="flex-1 min-h-[48px] py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-medium">ยกเลิก</button>
                <button type="submit" class="flex-1 min-h-[56px] py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold">อนุมัติทั้งหมด</button>
            </div>
        </form>
    </div>
</div>

<div id="reject-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="reject-modal-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden rounded-[var(--tp-ios-card-radius)] pb-[calc(env(safe-area-inset-bottom,0px)+1rem)]">
        <form method="POST" class="p-6">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="reject-request-id">
            <h3 id="reject-modal-title" class="text-xl font-bold text-white mb-1">ไม่อนุมัติคำขอแก้เวลา</h3>
            <p class="text-white/50 text-sm mb-4" id="reject-label"></p>
            <div class="tp-native-form-group mb-4">
                <label for="reject-review-remarks" class="block text-white/70 text-sm">เหตุผลที่ไม่อนุมัติ <span class="text-red-400" aria-hidden="true">*</span></label>
                <textarea name="review_remarks" id="reject-review-remarks" rows="3" class="input-field tp-native-textarea w-full" placeholder="ระบุเหตุผลให้พนักงานเห็นในประวัติ" required></textarea>
            </div>
            <div class="flex flex-col-reverse sm:flex-row gap-3">
                <button type="button" onclick="closeRejectModal()" class="flex-1 min-h-[48px] bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation font-medium">ยกเลิก</button>
                <button type="submit" class="flex-1 min-h-[56px] bg-red-600 hover:bg-red-700 text-white rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation font-semibold">
                    <i class="fas fa-times mr-2" aria-hidden="true"></i>ไม่อนุมัติ
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function modalOpen(id) {
    if (typeof uiOpenModal === 'function') uiOpenModal(id);
    else {
        const m = document.getElementById(id);
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}
function modalClose(id) {
    if (typeof uiCloseModal === 'function') uiCloseModal(id);
    else {
        const m = document.getElementById(id);
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}
function eventLabel(e) {
    if (e && e.currentTarget && e.currentTarget.getAttribute) {
        return e.currentTarget.getAttribute('data-emp-label') || '';
    }
    return '';
}
function openApproveOneModal(e, id) {
    const label = eventLabel(e);
    document.getElementById('approve-one-request-id').value = String(id);
    document.getElementById('approve-one-note').value = '';
    document.getElementById('approve-one-review-remarks').value = '';
    document.getElementById('approve-one-label').textContent = label ? ('คำขอของ ' + label) : ('คำขอ #' + id);
    modalOpen('approve-one-modal');
}
function closeApproveOneModal() {
    modalClose('approve-one-modal');
}
function submitApproveOne() {
    document.getElementById('approve-one-review-remarks').value = document.getElementById('approve-one-note').value || '';
    document.getElementById('approve-one-form').submit();
}
function openApproveAllModal() {
    modalOpen('approve-all-modal');
}
function closeApproveAllModal() {
    modalClose('approve-all-modal');
}
function openRejectModal(e, id) {
    const label = eventLabel(e);
    document.getElementById('reject-request-id').value = String(id);
    document.getElementById('reject-review-remarks').value = '';
    document.getElementById('reject-label').textContent = label ? ('คำขอของ ' + label) : ('คำขอ #' + id);
    modalOpen('reject-modal');
}
function closeRejectModal() {
    modalClose('reject-modal');
}
['approve-one-modal', 'approve-all-modal', 'reject-modal'].forEach(function(id) {
    document.getElementById(id).addEventListener('click', function(ev) {
        if (ev.target !== this) return;
        if (id === 'approve-one-modal') closeApproveOneModal();
        if (id === 'approve-all-modal') closeApproveAllModal();
        if (id === 'reject-modal') closeRejectModal();
    });
});
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
