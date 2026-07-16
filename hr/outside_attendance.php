<?php
/**
 * HR Outside Attendance Approvals
 * อนุมัติคำขอลงเวลาเข้า-ออกงานนอกสถานที่
 */

$page_title = 'อนุมัตินอกสถานที่';
require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();

if (!hr_can_access_hr_dashboard() && !Auth::hasRole(MANAGER_ROLES)) {
    redirect('/', 302);
}

$pdo = Database::getInstance()->getConnection();
$current_page = 'hr-outside-attendance';

function hrOutsideStatusLabel(string $status): string
{
    return match ($status) {
        'PENDING' => 'รออนุมัติ',
        'APPROVED' => 'อนุมัติแล้ว',
        'REJECTED' => 'ไม่อนุมัติ',
        'CANCELLED' => 'ยกเลิก',
        default => $status,
    };
}

function hrOutsideStatusClass(string $status): string
{
    return match ($status) {
        'PENDING' => 'border border-amber-500/35 bg-amber-500/15 text-amber-200',
        'APPROVED' => 'border border-emerald-500/35 bg-emerald-500/15 text-emerald-200',
        'REJECTED' => 'border border-red-500/35 bg-red-500/15 text-red-200',
        'CANCELLED' => 'border border-slate-500/35 bg-slate-500/15 text-slate-200',
        default => 'border border-slate-500/35 bg-slate-500/15 text-slate-200',
    };
}

function hrOutsideTypeLabel(?string $type): string
{
    return match (strtoupper((string)$type)) {
        'CHECK_IN' => 'ขอเข้างานนอกสถานที่',
        'CHECK_OUT' => 'ขอออกงานนอกสถานที่',
        default => 'คำขอนอกสถานที่',
    };
}

function hrOutsideTypeTone(?string $type): string
{
    return strtoupper((string)$type) === 'CHECK_OUT'
        ? 'bg-sky-500/15 border-sky-400/25 text-sky-200'
        : 'bg-emerald-500/15 border-emerald-400/25 text-emerald-200';
}

function hrOutsideDate(?string $value): string
{
    if (!$value) return '-';
    return function_exists('formatDateThai') ? formatDateThai($value) : date('d/m/Y', strtotime($value));
}

function hrOutsideDateTime(?string $value): string
{
    if (!$value) return '-';
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : $value;
}

function hrOutsideTime(?string $value): string
{
    if (!$value) return '--:--';
    $ts = strtotime($value);
    return $ts ? date('H:i', $ts) : '--:--';
}

$service = new OutsideAttendanceService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_token'] ?? ($_POST['csrf_token'] ?? ''))) {
        flash('error', 'เซสชันหมดอายุหรือข้อมูลไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง');
        redirect($_SERVER['REQUEST_URI'] ?? '/hr/outside_attendance.php', 302);
    }

    $action = (string)($_POST['action'] ?? '');
    $requestId = (int)($_POST['request_id'] ?? 0);
    $reviewRemarks = trim((string)($_POST['review_remarks'] ?? ''));

    try {
        if ($action === 'approve' && $requestId > 0) {
            $result = $service->approve($requestId, (int)$user['id'], $reviewRemarks);
            Auth::log('outside_attendance_approve', 'hr_attendance_outside_requests', $requestId, null, [
                'attendance_id' => $result['attendance_id'] ?? null,
                'request_type' => $result['request_type'] ?? null,
                'review_remarks' => $reviewRemarks ?: null,
            ]);
            flash('success', 'อนุมัติคำขอนอกสถานที่และบันทึกเวลาเรียบร้อยแล้ว');
        } elseif ($action === 'reject' && $requestId > 0) {
            $result = $service->reject($requestId, (int)$user['id'], $reviewRemarks);
            Auth::log('outside_attendance_reject', 'hr_attendance_outside_requests', $requestId, null, [
                'attendance_id' => $result['attendance_id'] ?? null,
                'request_type' => $result['request_type'] ?? null,
                'review_remarks' => $reviewRemarks,
            ]);
            flash('success', 'ไม่อนุมัติคำขอนอกสถานที่เรียบร้อยแล้ว');
        } else {
            flash('error', 'ไม่พบคำสั่งที่ต้องการดำเนินการ');
        }
    } catch (OutsideAttendanceException $e) {
        flash('error', $e->getMessage());
    } catch (Throwable $e) {
        tpHrLogException($e, 'hr/outside_attendance');
        flash('error', 'เกิดข้อผิดพลาดภายในระบบ กรุณาลองใหม่');
    }

    redirect('/hr/outside_attendance.php?' . http_build_query($_GET), 302);
}

$statusFilter = strtoupper(trim((string)($_GET['status'] ?? 'PENDING')));
if (!in_array($statusFilter, ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED', ''], true)) {
    $statusFilter = 'PENDING';
}
$month = trim((string)($_GET['month'] ?? ''));
$department = trim((string)($_GET['department'] ?? ''));

$stmtDepts = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' AND " . tp_hr_non_system_user_condition_sql('') . " ORDER BY department");
$departments = $stmtDepts->fetchAll(PDO::FETCH_COLUMN);

$where = [tp_hr_non_system_user_condition_sql('u')];
$params = [];
if ($statusFilter !== '') {
    $where[] = "o.status = ?";
    $params[] = $statusFilter;
}
if ($month !== '' && preg_match('/^\d{4}-\d$/', $month)) {
    $month = substr($month, 0, 5) . '0' . substr($month, 5);
}
if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
    $where[] = "DATE_FORMAT(o.request_date, '%Y-%m') = ?";
    $params[] = $month;
} else {
    $month = '';
}
if ($department !== '') {
    $where[] = "u.department = ?";
    $params[] = $department;
}

$sql = "
    SELECT o.*,
           u.first_name_th, u.last_name_th, u.employee_code, u.department,
           reviewer.first_name_th AS reviewer_first_name,
           reviewer.last_name_th AS reviewer_last_name,
           att.check_in_time, att.check_out_time, att.status AS attendance_status
    FROM hr_attendance_outside_requests o
    JOIN users u ON u.id = o.user_id
    LEFT JOIN users reviewer ON reviewer.id = o.reviewed_by
    LEFT JOIN hr_attendances att ON att.id = o.attendance_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY CASE o.status WHEN 'PENDING' THEN 0 WHEN 'APPROVED' THEN 1 WHEN 'REJECTED' THEN 2 ELSE 3 END,
             o.request_date DESC,
             o.id DESC
    LIMIT 1000
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statsWhere = [];
$statsParams = [];
if ($month !== '') {
    $statsWhere[] = "DATE_FORMAT(o.request_date, '%Y-%m') = ?";
    $statsParams[] = $month;
}
if ($department !== '') {
    $statsWhere[] = "u.department = ?";
    $statsParams[] = $department;
}
$statsSql = "
    SELECT
        SUM(CASE WHEN o.status = 'PENDING' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN o.status = 'APPROVED' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN o.status = 'REJECTED' THEN 1 ELSE 0 END) AS rejected,
        SUM(CASE WHEN o.status = 'CANCELLED' THEN 1 ELSE 0 END) AS cancelled,
        COUNT(*) AS total
    FROM hr_attendance_outside_requests o
    JOIN users u ON u.id = o.user_id
";
if ($statsWhere) {
    $statsSql .= " WHERE " . implode(' AND ', $statsWhere);
}
$stmtStats = $pdo->prepare($statsSql);
$stmtStats->execute($statsParams);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0, 'total' => 0];
$pendingCount = (int)($stats['pending'] ?? 0);

$filterBase = [];
if ($month !== '') $filterBase['month'] = $month;
if ($department !== '') $filterBase['department'] = $department;

include dirname(__DIR__) . '/templates/header.php';
?>

<style>
    .tp-outside-stack {
        --outside-amber: #f59e0b;
        --outside-green: #10b981;
        --outside-sky: #38bdf8;
    }
    .tp-outside-header {
        display: grid;
        gap: 1rem;
    }
    .tp-outside-header-panel {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.75rem;
        width: fit-content;
        min-height: 48px;
        padding: 0.35rem;
        border-radius: 999px;
        background: rgba(255,255,255,0.08);
        border: 1px solid rgba(255,255,255,0.13);
        box-shadow: var(--tp-surface-well-inset);
    }
    .tp-outside-header-pill {
        min-height: 38px;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0 0.9rem;
        border-radius: 999px;
        color: rgba(255,255,255,0.86);
        font-size: 0.86rem;
        white-space: nowrap;
    }
    .tp-outside-header-pill--pending {
        background: rgba(245,158,11,0.20);
        color: #fde68a;
    }
    .tp-outside-summary-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }
    .tp-outside-summary-grid .stat-card {
        min-height: 92px;
        border: 1px solid rgba(255,255,255,0.11);
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.08);
    }
    .tp-outside-filter-card {
        background: rgba(15,23,42,0.48);
        border: 1px solid rgba(255,255,255,0.11);
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.07);
    }
    .tp-outside-list-card {
        background: transparent;
        border: 0;
        box-shadow: none;
    }
    .tp-outside-request-card {
        position: relative;
        overflow: hidden;
        background: rgba(15,23,42,0.58);
        border: 1px solid rgba(255,255,255,0.11);
        box-shadow: 0 18px 48px rgba(0,0,0,0.22), inset 0 1px 0 rgba(255,255,255,0.08);
    }
    .tp-outside-request-card::before {
        content: "";
        position: absolute;
        left: 0;
        top: 1.1rem;
        bottom: 1.1rem;
        width: 4px;
        border-radius: 999px;
        background: linear-gradient(180deg, var(--outside-amber), var(--outside-sky));
    }
    .tp-outside-request-head {
        padding-left: 0.35rem;
    }
    .tp-outside-meta-tile {
        min-height: 96px;
        background: rgba(255,255,255,0.055);
        border: 1px solid rgba(255,255,255,0.10);
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.06);
    }
    .tp-outside-action-button {
        min-height: 58px;
        border-radius: var(--tp-radius-button, 18px);
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.14);
    }
    @media (max-width: 900px) {
        .tp-outside-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .tp-outside-summary-grid > :last-child {
            grid-column: span 2;
        }
    }
    @media (max-width: 640px) {
        .tp-outside-header-panel {
            width: 100%;
            border-radius: var(--tp-ios-card-radius);
            padding: 0.5rem;
        }
        .tp-outside-header-pill {
            flex: 1 1 auto;
            justify-content: center;
        }
        .tp-outside-summary-grid {
            gap: 0.65rem;
        }
        .tp-outside-request-card {
            border-radius: 28px;
        }
    }
</style>

<div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page tp-outside-stack w-full max-w-[min(960px,100%)] mx-auto min-w-0">
<header class="tp-ios-large-title-block tp-outside-header mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <span class="text-white">อนุมัตินอกสถานที่</span>
    </nav>
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div class="min-w-0 flex-1">
            <h1 class="tp-ios-page-title">อนุมัติลงเวลานอกสถานที่</h1>
            <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">คำขอจาก TP-Checkin ที่อยู่นอกพื้นที่สำนักงาน เมื่ออนุมัติแล้วระบบจะบันทึกเวลาเข้า/ออกในประวัติจริงทันที</p>
        </div>
        <a href="/hr/attendance.php" class="w-full sm:w-auto inline-flex min-h-[56px] items-center justify-center gap-2 rounded-[var(--tp-ios-card-radius)] bg-white/10 px-4 py-2 text-white hover:bg-white/15 touch-manipulation font-semibold border border-white/10">
            <i class="fas fa-user-clock" aria-hidden="true"></i>
            <span>ดูเวลาทำงาน</span>
        </a>
    </div>
    <div class="tp-outside-header-panel" aria-label="สรุปคำขอนอกสถานที่">
        <span class="tp-outside-header-pill tp-outside-header-pill--pending">
            <i class="fas fa-hourglass-half" aria-hidden="true"></i>
            รออนุมัติ <?php echo number_format($pendingCount); ?> รายการ
        </span>
        <span class="tp-outside-header-pill">
            <i class="fas fa-check-circle" aria-hidden="true"></i>
            อนุมัติแล้วบันทึกเวลาจริง
        </span>
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

<div class="tp-outside-summary-grid min-w-0 max-w-full">
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'PENDING']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $statusFilter === 'PENDING' ? 'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">รออนุมัติ</p>
        <p class="text-2xl font-bold text-amber-400 tabular-nums mt-1"><?php echo (int)($stats['pending'] ?? 0); ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'APPROVED']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $statusFilter === 'APPROVED' ? 'ring-2 ring-emerald-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">อนุมัติแล้ว</p>
        <p class="text-2xl font-bold text-emerald-400 tabular-nums mt-1"><?php echo (int)($stats['approved'] ?? 0); ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'REJECTED']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $statusFilter === 'REJECTED' ? 'ring-2 ring-red-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">ไม่อนุมัติ</p>
        <p class="text-2xl font-bold text-red-400 tabular-nums mt-1"><?php echo (int)($stats['rejected'] ?? 0); ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'CANCELLED']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $statusFilter === 'CANCELLED' ? 'ring-2 ring-slate-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">ยกเลิก</p>
        <p class="text-2xl font-bold text-slate-300 tabular-nums mt-1"><?php echo (int)($stats['cancelled'] ?? 0); ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => '']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow col-span-2 lg:col-span-1 <?php echo $statusFilter === '' ? 'ring-2 ring-violet-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">ทั้งหมด</p>
        <p class="text-2xl font-bold text-violet-400 tabular-nums mt-1"><?php echo (int)($stats['total'] ?? 0); ?></p>
    </a>
</div>

<div class="native-card tp-native-card tp-native-data-card tp-outside-filter-card p-5 sm:p-6 mb-6 min-w-0 overflow-hidden rounded-[var(--tp-ios-card-radius)]">
    <h2 class="section-title mb-4 text-white text-lg">
        <i class="fas fa-filter text-violet-400 text-xl mr-2" aria-hidden="true"></i>
        กรองคำขอ
    </h2>
    <form method="GET" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="tp-native-form-group mb-0">
            <label for="outside-status" class="text-white/70 text-sm font-medium">สถานะ</label>
            <select id="outside-status" name="status" class="input-field tp-native-select w-full" onchange="this.form.submit()">
                <option value="PENDING" <?php echo $statusFilter === 'PENDING' ? 'selected' : ''; ?>>รออนุมัติ<?php echo $pendingCount > 0 ? ' (' . $pendingCount . ')' : ''; ?></option>
                <option value="APPROVED" <?php echo $statusFilter === 'APPROVED' ? 'selected' : ''; ?>>อนุมัติแล้ว</option>
                <option value="REJECTED" <?php echo $statusFilter === 'REJECTED' ? 'selected' : ''; ?>>ไม่อนุมัติ</option>
                <option value="CANCELLED" <?php echo $statusFilter === 'CANCELLED' ? 'selected' : ''; ?>>ยกเลิก</option>
                <option value="" <?php echo $statusFilter === '' ? 'selected' : ''; ?>>ทั้งหมด</option>
            </select>
        </div>
        <div class="tp-native-form-group mb-0">
            <label for="outside-month" class="text-white/70 text-sm font-medium">เดือน</label>
            <input type="month" id="outside-month" name="month" class="input-field tp-native-input w-full" value="<?php echo htmlspecialchars($month); ?>" onchange="this.form.submit()">
        </div>
        <div class="tp-native-form-group mb-0">
            <label for="outside-dept" class="text-white/70 text-sm font-medium">แผนก</label>
            <select id="outside-dept" name="department" class="input-field tp-native-select w-full" onchange="this.form.submit()">
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

<div class="native-card tp-native-card tp-native-data-card tp-outside-list-card overflow-visible min-w-0 rounded-[var(--tp-ios-card-radius)]">
    <?php if (empty($requests)): ?>
    <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-4 my-4">
        <i class="fas fa-location-dot text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
        <p class="text-slate-400 text-sm">ไม่มีคำขอลงเวลานอกสถานที่</p>
    </div>
    <?php else: ?>
    <div class="space-y-4">
        <?php foreach ($requests as $req): ?>
        <?php
        $st = (string)$req['status'];
        $type = strtoupper((string)$req['request_type']);
        $empName = trim(($req['first_name_th'] ?? '') . ' ' . ($req['last_name_th'] ?? ''));
        $mapUrl = ($req['latitude'] !== null && $req['longitude'] !== null)
            ? 'https://www.google.com/maps?q=' . rawurlencode((string)$req['latitude'] . ',' . (string)$req['longitude'])
            : '';
        $photoUrl = attendancePhotoPublicUrl($req['photo_path'] ?? null);
        ?>
        <article class="tp-outside-request-card rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6">
            <div class="tp-outside-request-head flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <span class="inline-flex items-center rounded-[var(--tp-ios-card-radius)] border px-3 py-1 text-xs font-semibold <?php echo hrOutsideTypeTone($type); ?>">
                            <?php echo htmlspecialchars(hrOutsideTypeLabel($type)); ?>
                        </span>
                        <span class="inline-flex items-center rounded-[var(--tp-ios-card-radius)] px-3 py-1 text-xs font-semibold <?php echo hrOutsideStatusClass($st); ?>">
                            <?php echo htmlspecialchars(hrOutsideStatusLabel($st)); ?>
                        </span>
                    </div>
                    <h2 class="text-white text-lg font-semibold break-words"><?php echo htmlspecialchars($empName !== '' ? $empName : '-'); ?></h2>
                    <p class="text-white/50 text-sm break-words"><?php echo htmlspecialchars($req['employee_code'] ?? ''); ?> | <?php echo htmlspecialchars($req['department'] ?? '-'); ?></p>
                </div>
                <div class="md:text-right shrink-0">
                    <p class="text-white text-2xl font-bold tabular-nums"><?php echo hrOutsideTime($req['request_time'] ?? null); ?></p>
                    <p class="text-white/55 text-sm"><?php echo hrOutsideDate($req['request_date'] ?? null); ?></p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4 text-sm">
                <div class="tp-outside-meta-tile rounded-[var(--tp-ios-card-radius)] px-3 py-3">
                    <p class="text-white/50 text-[11px]">เหตุผล</p>
                    <p class="text-white/80 break-words mt-1"><?php echo htmlspecialchars($req['reason'] ?? '-'); ?></p>
                </div>
                <div class="tp-outside-meta-tile rounded-[var(--tp-ios-card-radius)] px-3 py-3">
                    <p class="text-white/50 text-[11px]">ตำแหน่ง</p>
                    <?php if ($mapUrl !== ''): ?>
                    <a href="<?php echo htmlspecialchars($mapUrl); ?>" target="_blank" rel="noopener" class="text-sky-300 hover:underline break-all touch-manipulation">
                        <?php echo htmlspecialchars((string)$req['latitude']); ?>, <?php echo htmlspecialchars((string)$req['longitude']); ?>
                    </a>
                    <?php else: ?>
                    <p class="text-white/50 mt-1">ไม่มีพิกัด</p>
                    <?php endif; ?>
                </div>
                <div class="tp-outside-meta-tile rounded-[var(--tp-ios-card-radius)] px-3 py-3">
                    <p class="text-white/50 text-[11px]">ผลในประวัติลงเวลา</p>
                    <?php if ($st === 'APPROVED'): ?>
                    <p class="text-emerald-300 mt-1">เข้า <?php echo hrOutsideTime($req['check_in_time'] ?? null); ?></p>
                    <p class="text-sky-300">ออก <?php echo hrOutsideTime($req['check_out_time'] ?? null); ?></p>
                    <?php else: ?>
                    <p class="text-white/50 mt-1">ยังไม่บันทึกเวลา</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mt-4">
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <span class="text-white/45">ส่งคำขอ <?php echo htmlspecialchars(hrOutsideDateTime($req['created_at'] ?? null)); ?></span>
                    <?php if ($photoUrl !== ''): ?>
                    <a href="<?php echo htmlspecialchars($photoUrl); ?>" target="_blank" rel="noopener" class="inline-flex min-h-[48px] items-center gap-2 rounded-[var(--tp-ios-card-radius)] bg-white/10 px-3 text-white hover:bg-white/15 touch-manipulation">
                        <i class="fas fa-image" aria-hidden="true"></i>
                        <span>ดูรูปถ่าย</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($st !== 'PENDING' && !empty($req['reviewer_first_name'])): ?>
                    <span class="text-white/45">โดย <?php echo htmlspecialchars(trim(($req['reviewer_first_name'] ?? '') . ' ' . ($req['reviewer_last_name'] ?? ''))); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($st === 'PENDING'): ?>
                <div class="grid grid-cols-2 gap-2 md:min-w-[18rem]">
                    <button type="button"
                            data-emp-label="<?php echo htmlspecialchars($empName, ENT_QUOTES); ?>"
                            data-request-type="<?php echo htmlspecialchars(hrOutsideTypeLabel($type), ENT_QUOTES); ?>"
                            onclick="openApproveModal(event, <?php echo (int)$req['id']; ?>)"
                            class="tp-outside-action-button bg-emerald-600 hover:bg-emerald-700 text-white font-semibold transition-colors touch-manipulation shadow-sm shadow-emerald-900/30">
                        <i class="fas fa-check mr-2" aria-hidden="true"></i>อนุมัติ
                    </button>
                    <button type="button"
                            data-emp-label="<?php echo htmlspecialchars($empName, ENT_QUOTES); ?>"
                            data-request-type="<?php echo htmlspecialchars(hrOutsideTypeLabel($type), ENT_QUOTES); ?>"
                            onclick="openRejectModal(event, <?php echo (int)$req['id']; ?>)"
                            class="tp-outside-action-button bg-red-500/15 hover:bg-red-500/25 border border-red-500/35 text-red-200 font-semibold transition-colors touch-manipulation">
                        <i class="fas fa-times mr-2" aria-hidden="true"></i>ไม่อนุมัติ
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</div>

<form id="approve-form" method="POST" class="hidden" aria-hidden="true">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="approve">
    <input type="hidden" name="request_id" id="approve-request-id" value="">
    <input type="hidden" name="review_remarks" id="approve-review-remarks" value="">
</form>

<div id="approve-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="approve-modal-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto rounded-[var(--tp-ios-card-radius)] p-6 pb-[calc(env(safe-area-inset-bottom,0px)+1.5rem)]">
        <h3 id="approve-modal-title" class="text-xl font-bold text-white mb-1">อนุมัติและบันทึกเวลาจริง?</h3>
        <p class="text-white/65 text-sm mb-4" id="approve-label"></p>
        <div class="tp-native-form-group mb-5">
            <label for="approve-note" class="block text-white/70 text-sm">หมายเหตุ (ถ้ามี)</label>
            <input type="text" id="approve-note" class="input-field tp-native-input w-full" placeholder="บันทึกประกอบการอนุมัติ" autocomplete="off">
        </div>
        <div class="flex flex-col sm:flex-row gap-3">
            <button type="button" onclick="closeApproveModal()" class="flex-1 min-h-[48px] py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-medium">ยกเลิก</button>
            <button type="button" onclick="submitApprove()" class="flex-1 min-h-[56px] py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold">อนุมัติ</button>
        </div>
    </div>
</div>

<div id="reject-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="reject-modal-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden rounded-[var(--tp-ios-card-radius)] pb-[calc(env(safe-area-inset-bottom,0px)+1rem)]">
        <form method="POST" class="p-6">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="reject-request-id">
            <h3 id="reject-modal-title" class="text-xl font-bold text-white mb-1">ไม่อนุมัติคำขอนอกสถานที่</h3>
            <p class="text-white/50 text-sm mb-4" id="reject-label"></p>
            <div class="tp-native-form-group mb-4">
                <label for="reject-review-remarks" class="block text-white/70 text-sm">เหตุผลที่ไม่อนุมัติ <span class="text-red-400" aria-hidden="true">*</span></label>
                <textarea name="review_remarks" id="reject-review-remarks" rows="3" class="input-field tp-native-textarea w-full" placeholder="ระบุเหตุผลให้พนักงานเห็นในสถานะคำขอ" required></textarea>
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
function outsideModalOpen(id) {
    if (typeof uiOpenModal === 'function') uiOpenModal(id);
    else {
        const m = document.getElementById(id);
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}
function outsideModalClose(id) {
    if (typeof uiCloseModal === 'function') uiCloseModal(id);
    else {
        const m = document.getElementById(id);
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}
function outsideLabel(e) {
    const target = e && e.currentTarget && e.currentTarget.getAttribute ? e.currentTarget : null;
    if (!target) return '';
    const type = target.getAttribute('data-request-type') || 'คำขอนอกสถานที่';
    const emp = target.getAttribute('data-emp-label') || '';
    return emp ? (type + 'ของ ' + emp) : type;
}
function openApproveModal(e, id) {
    document.getElementById('approve-request-id').value = String(id);
    document.getElementById('approve-note').value = '';
    document.getElementById('approve-review-remarks').value = '';
    document.getElementById('approve-label').textContent = outsideLabel(e);
    outsideModalOpen('approve-modal');
}
function closeApproveModal() {
    outsideModalClose('approve-modal');
}
function submitApprove() {
    document.getElementById('approve-review-remarks').value = document.getElementById('approve-note').value || '';
    document.getElementById('approve-form').submit();
}
function openRejectModal(e, id) {
    document.getElementById('reject-request-id').value = String(id);
    document.getElementById('reject-review-remarks').value = '';
    document.getElementById('reject-label').textContent = outsideLabel(e);
    outsideModalOpen('reject-modal');
}
function closeRejectModal() {
    outsideModalClose('reject-modal');
}
['approve-modal', 'reject-modal'].forEach(function(id) {
    document.getElementById(id).addEventListener('click', function(ev) {
        if (ev.target !== this) return;
        if (id === 'approve-modal') closeApproveModal();
        if (id === 'reject-modal') closeRejectModal();
    });
});
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
