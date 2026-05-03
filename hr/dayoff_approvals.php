<?php
/**
 * HR Day-Off Request Approvals
 * อนุมัติคำขอเปลี่ยนวันหยุดประจำสัปดาห์ - CEO ขึ้นไป
 */

$page_title = 'อนุมัติวันหยุด';
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
$current_page = 'hr-dayoff';

$dayNames = THAI_DAY_NAMES;

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_token'] ?? ($_POST['csrf_token'] ?? ''))) {
        flash('error', 'เซสชันหมดอายุหรือข้อมูลไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง');
        redirect($_SERVER['REQUEST_URI'] ?? '/hr/dayoff_approvals.php', 302);
    }
    $action = $_POST['action'] ?? '';
    $requestId = (int)($_POST['request_id'] ?? 0);
    
    if ($action === 'approve' && $requestId) {
        $stmt = $pdo->prepare("
            UPDATE hr_dayoff_requests 
            SET status = 'APPROVED', reviewed_by = ?, reviewed_at = NOW(), review_note = ?
            WHERE id = ? AND status = 'PENDING'
        ");
        $stmt->execute([$user['id'], $_POST['review_note'] ?? null, $requestId]);
        if ($stmt->rowCount() > 0) {
            Auth::log('dayoff_request_approve', 'hr_dayoff_requests', $requestId, null, [
                'review_note' => $_POST['review_note'] ?? null,
            ]);
        }
        flash('success', 'อนุมัติคำขอเรียบร้อยแล้ว');
    } elseif ($action === 'reject' && $requestId) {
        $stmt = $pdo->prepare("
            UPDATE hr_dayoff_requests 
            SET status = 'REJECTED', reviewed_by = ?, reviewed_at = NOW(), review_note = ?
            WHERE id = ? AND status = 'PENDING'
        ");
        $stmt->execute([$user['id'], $_POST['review_note'] ?? null, $requestId]);
        if ($stmt->rowCount() > 0) {
            Auth::log('dayoff_request_reject', 'hr_dayoff_requests', $requestId, null, [
                'review_note' => $_POST['review_note'] ?? null,
            ]);
        }
        flash('success', 'ปฏิเสธคำขอเรียบร้อยแล้ว');
    } elseif ($action === 'approve_all') {
        $stmt = $pdo->prepare("
            UPDATE hr_dayoff_requests 
            SET status = 'APPROVED', reviewed_by = ?, reviewed_at = NOW()
            WHERE status = 'PENDING'
        ");
        $stmt->execute([$user['id']]);
        Auth::log('dayoff_request_approve_all', 'hr_dayoff_requests', null, null, [
            'approved_count' => $stmt->rowCount(),
        ]);
        flash('success', 'อนุมัติทั้งหมดเรียบร้อยแล้ว');
    }
    
    header("Location: dayoff_approvals.php?" . http_build_query($_GET));
    exit;
}

// Filter
$statusFilter = $_GET['status'] ?? 'PENDING';
$month = $_GET['month'] ?? '';

// Get requests
$sql = "
    SELECT r.*, 
           u.first_name_th, u.last_name_th, u.employee_code, u.department,
           rv.first_name_th as reviewer_name_first, rv.last_name_th as reviewer_name_last
    FROM hr_dayoff_requests r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN users rv ON r.reviewed_by = rv.id
    WHERE 1=1
";
$params = [];

if ($statusFilter) {
    $sql .= " AND r.status = ?";
    $params[] = $statusFilter;
}

if ($month) {
    $sql .= " AND DATE_FORMAT(r.week_start, '%Y-%m') = ?";
    $params[] = $month;
}

$sql .= " ORDER BY CASE r.status WHEN 'PENDING' THEN 0 WHEN 'APPROVED' THEN 1 ELSE 2 END, r.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allRequests = $stmt->fetchAll();

// Count pending (ทั้งระบบ — ใช้ปุ่มอนุมัติทั้งหมด)
$pendingCount = $pdo->query("SELECT COUNT(*) FROM hr_dayoff_requests WHERE status = 'PENDING'")->fetchColumn();

// สถิติตามตัวกรองเดือน (เดือนว่าง = นับทั้งหมด)
$statsWhere = '';
$statsParams = [];
if ($month !== '') {
    $statsWhere = " WHERE DATE_FORMAT(week_start, '%Y-%m') = ?";
    $statsParams[] = $month;
}
$stmtStats = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) AS rejected,
        COUNT(*) AS total
    FROM hr_dayoff_requests
    $statsWhere
");
$stmtStats->execute($statsParams);
$doStats = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'total' => 0];

$filterBase = [];
if ($month !== '') {
    $filterBase['month'] = $month;
}

include dirname(__DIR__) . '/templates/header.php';
?>

<div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <span class="text-white">อนุมัติเปลี่ยนวันหยุด</span>
    </nav>
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 gap-y-4">
        <div class="min-w-0 flex-1">
            <h1 class="tp-ios-page-title">อนุมัติเปลี่ยนวันหยุดประจำสัปดาห์</h1>
            <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">พนักงานขอเปลี่ยนวันหยุดในแต่ละสัปดาห์</p>
        </div>
        <?php if ($pendingCount > 0 && $statusFilter === 'PENDING'): ?>
        <button type="button"
                onclick="openApproveAllModal()"
                class="w-full sm:w-auto min-h-[56px] px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation font-semibold shadow-sm shadow-emerald-900/30">
            <i class="fas fa-check-double mr-2" aria-hidden="true"></i>อนุมัติทั้งหมด (<?php echo (int)$pendingCount; ?>)
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

<!-- Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-2 sm:gap-4 mb-6 min-w-0 max-w-full">
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'PENDING']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $statusFilter === 'PENDING' ? 'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">รออนุมัติ</p>
        <p class="text-2xl font-bold text-amber-400 tabular-nums mt-1"><?php echo (int)($doStats['pending'] ?? 0); ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'APPROVED']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $statusFilter === 'APPROVED' ? 'ring-2 ring-emerald-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">อนุมัติแล้ว</p>
        <p class="text-2xl font-bold text-emerald-400 tabular-nums mt-1"><?php echo (int)($doStats['approved'] ?? 0); ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'REJECTED']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $statusFilter === 'REJECTED' ? 'ring-2 ring-red-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">ไม่อนุมัติ</p>
        <p class="text-2xl font-bold text-red-400 tabular-nums mt-1"><?php echo (int)($doStats['rejected'] ?? 0); ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => '']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $statusFilter === '' ? 'ring-2 ring-violet-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">ทั้งหมด</p>
        <p class="text-2xl font-bold text-violet-400 tabular-nums mt-1"><?php echo (int)($doStats['total'] ?? 0); ?></p>
    </a>
</div>

<!-- Filters -->
<div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 mb-6 min-w-0 overflow-hidden rounded-[var(--tp-ios-card-radius)]">
    <h2 class="section-title mb-4 text-white text-lg">
        <i class="fas fa-filter text-violet-400 text-xl mr-2" aria-hidden="true"></i>
        กรองคำขอ
    </h2>
    <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="tp-native-form-group mb-0">
            <label for="hr-dayoff-status" class="text-white/70 text-sm font-medium">สถานะ</label>
            <select id="hr-dayoff-status" name="status" class="input-field tp-native-select w-full" onchange="this.form.submit()">
                <option value="PENDING" <?php echo $statusFilter === 'PENDING' ? 'selected' : ''; ?>>
                    รออนุมัติ<?php echo (int)$pendingCount > 0 ? ' (' . (int)$pendingCount . ')' : ''; ?>
                </option>
                <option value="APPROVED" <?php echo $statusFilter === 'APPROVED' ? 'selected' : ''; ?>>อนุมัติแล้ว</option>
                <option value="REJECTED" <?php echo $statusFilter === 'REJECTED' ? 'selected' : ''; ?>>ไม่อนุมัติ</option>
                <option value="" <?php echo $statusFilter === '' ? 'selected' : ''; ?>>ทั้งหมด</option>
            </select>
        </div>
        <div class="tp-native-form-group mb-0">
            <label for="hr-dayoff-month" class="text-white/70 text-sm font-medium">เดือน</label>
            <input type="month" id="hr-dayoff-month" name="month" class="input-field tp-native-input w-full" value="<?php echo htmlspecialchars($month); ?>" onchange="this.form.submit()">
        </div>
    </form>
</div>

<!-- Requests Table -->
<div class="native-card tp-native-card tp-native-data-card overflow-hidden min-w-0 rounded-[var(--tp-ios-card-radius)]">
    <?php if (empty($allRequests)): ?>
    <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-4 my-4">
        <i class="fas fa-calendar-check text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
        <p class="text-slate-400 text-sm">ไม่มีคำขอ</p>
    </div>
    <?php else: ?>
    <div class="md:hidden p-5 space-y-4">
        <?php foreach ($allRequests as $req): ?>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-5">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-white font-medium break-words"><?php echo htmlspecialchars($req['first_name_th'] . ' ' . $req['last_name_th']); ?></p>
                    <p class="text-white/50 text-xs break-words"><?php echo htmlspecialchars($req['employee_code'] ?? ''); ?> | <?php echo htmlspecialchars($req['department'] ?? '-'); ?></p>
                </div>
                <?php
                $st = (string)$req['status'];
                $stChip = match ($st) {
                    'PENDING' => 'border border-amber-500/35 bg-amber-500/15 text-amber-200',
                    'APPROVED' => 'border border-emerald-500/35 bg-emerald-500/15 text-emerald-200',
                    'REJECTED' => 'border border-red-500/35 bg-red-500/15 text-red-200',
                    default => 'border border-slate-500/35 bg-slate-500/15 text-slate-200'
                };
                $stLabel = match ($st) {
                    'PENDING' => 'รออนุมัติ',
                    'APPROVED' => 'อนุมัติ',
                    'REJECTED' => 'ไม่อนุมัติ',
                    default => $st
                };
                ?>
                <span class="px-2.5 py-1 text-xs rounded-[var(--tp-ios-card-radius)] shrink-0 font-semibold <?php echo $stChip; ?>">
                    <?php echo htmlspecialchars($stLabel); ?>
                </span>
            </div>

            <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 px-3 py-2">
                    <p class="text-white/50 text-[11px]">สัปดาห์</p>
                    <p class="text-white font-semibold"><?php echo formatDateThai($req['week_start']); ?></p>
                    <p class="text-white/50 text-xs">ถึง <?php echo formatDateThai($req['week_end']); ?></p>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 px-3 py-2">
                    <p class="text-white/50 text-[11px]">เปลี่ยนวันหยุด</p>
                    <p><span class="text-sky-400"><?php echo $dayNames[(int)$req['original_day_off']]; ?></span>
                        <i class="fas fa-arrow-right text-white/30 mx-1" aria-hidden="true"></i>
                        <span class="text-violet-400 font-medium"><?php echo $dayNames[(int)$req['requested_day_off']]; ?></span>
                    </p>
                </div>
            </div>

            <p class="text-white/60 text-sm mt-3 break-words"><?php echo htmlspecialchars($req['reason'] ?? '-'); ?></p>
            <?php if ($req['status'] !== 'PENDING' && $req['reviewer_name_first']): ?>
            <p class="text-white/40 text-xs mt-2">โดย <?php echo htmlspecialchars($req['reviewer_name_first']); ?></p>
            <?php endif; ?>

            <?php if ($req['status'] === 'PENDING'): ?>
            <div class="grid grid-cols-2 gap-2 mt-4">
                <button type="button"
                        onclick="openApproveOneModal(<?php echo (int)$req['id']; ?>)"
                        class="min-h-[56px] rounded-[var(--tp-ios-card-radius)] bg-emerald-600 hover:bg-emerald-700 text-white font-semibold transition-colors touch-manipulation shadow-sm shadow-emerald-900/30">
                    <i class="fas fa-check mr-2" aria-hidden="true"></i>อนุมัติ
                </button>
                <button type="button"
                        data-emp-label="<?php echo htmlspecialchars(trim(($req['first_name_th'] ?? '') . ' ' . ($req['last_name_th'] ?? '')), ENT_QUOTES); ?>"
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
        <table class="w-full" style="min-width:920px">
            <thead class="bg-white/5">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">พนักงาน</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สัปดาห์</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">วันหยุดเดิม</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ขอเปลี่ยนเป็น</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">เหตุผล</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($allRequests as $req): ?>
                <?php $stRow = (string)$req['status']; ?>
                <tr class="hover:bg-white/[0.04]">
                    <td class="px-4 py-3">
                        <p class="text-white font-medium"><?php echo htmlspecialchars($req['first_name_th'] . ' ' . $req['last_name_th']); ?></p>
                        <p class="text-white/50 text-xs"><?php echo htmlspecialchars($req['employee_code'] ?? ''); ?> | <?php echo htmlspecialchars($req['department'] ?? '-'); ?></p>
                    </td>
                    <td class="px-4 py-3 text-center text-white text-sm">
                        <div><?php echo formatDateThai($req['week_start']); ?></div>
                        <div class="text-white/50 text-xs">ถึง <?php echo formatDateThai($req['week_end']); ?></div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-sky-400"><?php echo $dayNames[(int)$req['original_day_off']]; ?></span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-violet-400 font-medium"><?php echo $dayNames[(int)$req['requested_day_off']]; ?></span>
                    </td>
                    <td class="px-4 py-3 text-white/60 text-sm">
                        <?php echo htmlspecialchars($req['reason'] ?? '-'); ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php
                        $stChip = match ($stRow) {
                            'PENDING' => 'border border-amber-500/30 bg-amber-500/15 text-amber-300',
                            'APPROVED' => 'border border-emerald-500/30 bg-emerald-500/15 text-emerald-300',
                            'REJECTED' => 'border border-red-500/30 bg-red-500/15 text-red-300',
                            default => 'border border-slate-500/30 bg-slate-500/15 text-slate-300'
                        };
                        $stLabel = match ($stRow) {
                            'PENDING' => 'รออนุมัติ',
                            'APPROVED' => 'อนุมัติ',
                            'REJECTED' => 'ไม่อนุมัติ',
                            default => $stRow
                        };
                        ?>
                        <span class="inline-flex items-center px-3 py-1 text-xs rounded-[var(--tp-ios-card-radius)] <?php echo $stChip; ?>">
                            <?php echo htmlspecialchars($stLabel); ?>
                        </span>
                        <?php if ($req['status'] !== 'PENDING' && $req['reviewer_name_first']): ?>
                        <div class="text-white/40 text-xs mt-1">
                            โดย <?php echo htmlspecialchars($req['reviewer_name_first']); ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($req['status'] === 'PENDING'): ?>
                        <div class="flex flex-wrap items-center justify-center gap-2">
                            <button type="button"
                                    onclick="openApproveOneModal(<?php echo (int)$req['id']; ?>)"
                                    class="inline-flex items-center gap-1.5 min-h-[56px] px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation">
                                <i class="fas fa-check" aria-hidden="true"></i><span>อนุมัติ</span>
                            </button>
                            <button type="button"
                                    data-emp-label="<?php echo htmlspecialchars(trim(($req['first_name_th'] ?? '') . ' ' . ($req['last_name_th'] ?? '')), ENT_QUOTES); ?>"
                                    onclick="openRejectModal(event, <?php echo (int)$req['id']; ?>)"
                                    class="inline-flex items-center gap-1.5 min-h-[48px] px-3 py-2 bg-red-500/15 hover:bg-red-500/25 border border-red-500/35 text-red-200 text-sm font-medium rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation">
                                <i class="fas fa-times" aria-hidden="true"></i><span>ปฏิเสธ</span>
                            </button>
                        </div>
                        <?php else: ?>
                        <span class="text-white/30 text-xs">—</span>
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

<!-- Hidden POST: อนุมัติรายการเดียว (ยืนยันผ่านโมดัล) -->
<form id="approve-one-form" method="POST" class="hidden" aria-hidden="true"><?php echo csrfField(); ?>
    <input type="hidden" name="action" value="approve">
    <input type="hidden" name="request_id" id="approve-one-request-id" value="">
</form>

<!-- ยืนยันอนุมัติ 1 รายการ -->
<div id="approve-one-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="approve-one-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto rounded-[var(--tp-ios-card-radius)] p-6 pb-[calc(env(safe-area-inset-bottom,0px)+1.5rem)]">
        <h3 id="approve-one-title" class="text-xl font-bold text-white mb-1">อนุมัติคำขอนี้?</h3>
        <p class="text-white/65 text-sm mb-6">ระบบจะบันทึกสถานะเป็นอนุมัติ และแจ้งในประวัติตามเดิม</p>
        <div class="flex flex-col sm:flex-row gap-3">
            <button type="button" onclick="closeApproveOneModal()" class="flex-1 min-h-[48px] py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-medium">ยกเลิก</button>
            <button type="button" onclick="submitApproveOne()" class="flex-1 min-h-[56px] py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold">อนุมัติ</button>
        </div>
    </div>
</div>

<!-- ยืนยันอนุมัติทั้งหมด (CEO) -->
<div id="approve-all-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="approve-all-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto rounded-[var(--tp-ios-card-radius)] p-6 pb-[calc(env(safe-area-inset-bottom,0px)+1.5rem)]">
        <h3 id="approve-all-title" class="text-xl font-bold text-white mb-1">อนุมัติคำขอที่รอทั้งหมด?</h3>
        <p class="text-white/65 text-sm mb-6">มีคำขอสถานะ &quot;รออนุมัติ&quot; ทั้งหมด <strong class="text-white"><?php echo (int)$pendingCount; ?></strong> รายการ (ทุกช่วงเวลาในระบบ) — ยืนยันดำเนินการ?</p>
        <form method="POST" id="approve-all-form-el">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="approve_all">
            <div class="flex flex-col sm:flex-row gap-3">
                <button type="button" onclick="closeApproveAllModal()" class="flex-1 min-h-[48px] py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-medium">ยกเลิก</button>
                <button type="submit" class="flex-1 min-h-[56px] py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold">อนุมัติทั้งหมด</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="reject-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="reject-modal-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden rounded-[var(--tp-ios-card-radius)] pb-[calc(env(safe-area-inset-bottom,0px)+1rem)]">
        <form method="POST" class="p-6">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="reject-request-id">
            
            <h3 id="reject-modal-title" class="text-xl font-bold text-white mb-1">ไม่อนุมัติคำขอ</h3>
            <p class="text-white/50 text-sm mb-4" id="reject-label"></p>
            
            <div class="tp-native-form-group mb-4">
                <label for="reject-review-note" class="block text-white/70 text-sm">หมายเหตุ (ถ้ามี)</label>
                <input type="text" name="review_note" id="reject-review-note" class="input-field tp-native-input w-full" placeholder="เหตุผลที่ไม่อนุมัติ" autocomplete="off">
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
function openApproveOneModal(id) {
    document.getElementById('approve-one-request-id').value = String(id);
    if (typeof uiOpenModal === 'function') uiOpenModal('approve-one-modal');
    else {
        const m = document.getElementById('approve-one-modal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}
function closeApproveOneModal() {
    if (typeof uiCloseModal === 'function') uiCloseModal('approve-one-modal');
    else {
        const m = document.getElementById('approve-one-modal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}
function submitApproveOne() {
    document.getElementById('approve-one-form').submit();
}

function openApproveAllModal() {
    if (typeof uiOpenModal === 'function') uiOpenModal('approve-all-modal');
    else {
        const m = document.getElementById('approve-all-modal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}
function closeApproveAllModal() {
    if (typeof uiCloseModal === 'function') uiCloseModal('approve-all-modal');
    else {
        const m = document.getElementById('approve-all-modal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

function openRejectModal(e, id) {
    var label = '';
    if (e && e.currentTarget && e.currentTarget.getAttribute) {
        label = e.currentTarget.getAttribute('data-emp-label') || '';
    }
    document.getElementById('reject-request-id').value = String(id);
    document.getElementById('reject-review-note').value = '';
    document.getElementById('reject-label').textContent = label ? ('คำขอของ ' + label) : ('คำขอ #' + id);
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
document.getElementById('approve-one-modal').addEventListener('click', function(ev) {
    if (ev.target === this) closeApproveOneModal();
});
document.getElementById('approve-all-modal').addEventListener('click', function(ev) {
    if (ev.target === this) closeApproveAllModal();
});
document.getElementById('reject-modal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
