<?php
/**
 * HR Holiday Work Exception Approvals — CEO+
 * อนุมัติคำขอมาทำงานวันหยุดประจำปี / หยุดชดเชย
 */

$page_title = 'อนุมัติทำงานวันหยุด';
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/core/CrmLineNotifierBridge.php';

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
$current_page = 'hr-holiday-work';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_token'] ?? ($_POST['csrf_token'] ?? ''))) {
        flash('error', 'เซสชันหมดอายุหรือข้อมูลไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง');
        redirect($_SERVER['REQUEST_URI'] ?? '/hr/holiday_work_approvals.php', 302);
    }
    $action = $_POST['action'] ?? '';
    $requestId = (int) ($_POST['request_id'] ?? 0);

    if ($action === 'approve' && $requestId) {
        $stmt = $pdo->prepare("
            UPDATE hr_holiday_work_exceptions
            SET status = 'APPROVED', reviewed_by = ?, reviewed_at = NOW(), review_note = ?
            WHERE id = ? AND status = 'PENDING'
        ");
        $stmt->execute([$user['id'], $_POST['review_note'] ?? null, $requestId]);
        if ($stmt->rowCount() > 0) {
            Auth::log('holiday_work_approve', 'hr_holiday_work_exceptions', $requestId, null, [
                'review_note' => $_POST['review_note'] ?? null,
            ]);
            crm_line_notify_holiday_work_decision($pdo, $requestId, 'APPROVED', trim((string) ($_POST['review_note'] ?? '')));
        }
        flash('success', 'อนุมัติคำขอเรียบร้อยแล้ว');
    } elseif ($action === 'reject' && $requestId) {
        $stmt = $pdo->prepare("
            UPDATE hr_holiday_work_exceptions
            SET status = 'REJECTED', reviewed_by = ?, reviewed_at = NOW(), review_note = ?
            WHERE id = ? AND status = 'PENDING'
        ");
        $stmt->execute([$user['id'], $_POST['review_note'] ?? null, $requestId]);
        if ($stmt->rowCount() > 0) {
            Auth::log('holiday_work_reject', 'hr_holiday_work_exceptions', $requestId, null, [
                'review_note' => $_POST['review_note'] ?? null,
            ]);
            crm_line_notify_holiday_work_decision($pdo, $requestId, 'REJECTED', trim((string) ($_POST['review_note'] ?? '')));
        }
        flash('success', 'ปฏิเสธคำขอเรียบร้อยแล้ว');
    } elseif ($action === 'approve_all') {
        $pendingIds = $pdo->query("SELECT id FROM hr_holiday_work_exceptions WHERE status = 'PENDING'")->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $pdo->prepare("
            UPDATE hr_holiday_work_exceptions
            SET status = 'APPROVED', reviewed_by = ?, reviewed_at = NOW()
            WHERE status = 'PENDING'
        ");
        $stmt->execute([$user['id']]);
        foreach ($pendingIds as $pid) {
            crm_line_notify_holiday_work_decision($pdo, (int) $pid, 'APPROVED', '');
        }
        Auth::log('holiday_work_approve_all', 'hr_holiday_work_exceptions', null, null, [
            'approved_count' => $stmt->rowCount(),
        ]);
        flash('success', 'อนุมัติทั้งหมดเรียบร้อยแล้ว');
    }

    header('Location: holiday_work_approvals.php?' . http_build_query($_GET));
    exit;
}

$statusFilter = $_GET['status'] ?? 'PENDING';
$year = $_GET['year'] ?? '';

$sql = "
    SELECT r.*,
           u.first_name_th, u.last_name_th, u.employee_code, u.department,
           rv.first_name_th AS reviewer_name_first, rv.last_name_th AS reviewer_name_last
    FROM hr_holiday_work_exceptions r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN users rv ON r.reviewed_by = rv.id
    WHERE 1=1
";
$params = [];

if ($statusFilter !== '') {
    $sql .= ' AND r.status = ?';
    $params[] = $statusFilter;
}

if ($year !== '') {
    $sql .= ' AND YEAR(r.holiday_date) = ?';
    $params[] = (int) $year;
}

$sql .= " ORDER BY CASE r.status WHEN 'PENDING' THEN 0 WHEN 'APPROVED' THEN 1 ELSE 2 END, r.holiday_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allRequests = $stmt->fetchAll();

try {
    $pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM hr_holiday_work_exceptions WHERE status = 'PENDING'")->fetchColumn();
} catch (Throwable) {
    $pendingCount = 0;
}

$statsWhere = '';
$statsParams = [];
if ($year !== '') {
    $statsWhere = ' WHERE YEAR(holiday_date) = ?';
    $statsParams[] = (int) $year;
}
$stmtStats = $pdo->prepare("
    SELECT
        SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) AS rejected,
        COUNT(*) AS total
    FROM hr_holiday_work_exceptions
    $statsWhere
");
$stmtStats->execute($statsParams);
$hwStats = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'total' => 0];

$filterBase = [];
if ($year !== '') {
    $filterBase['year'] = $year;
}

$yearOptions = [];
for ($y = (int) date('Y') - 1; $y <= (int) date('Y') + 1; $y++) {
    $yearOptions[] = $y;
}

include dirname(__DIR__) . '/templates/header.php';
?>

<div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <span class="text-white">อนุมัติทำงานวันหยุด</span>
    </nav>
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div class="min-w-0 flex-1">
            <h1 class="tp-ios-page-title">อนุมัติทำงานวันหยุด / หยุดชดเชย</h1>
            <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">พนักงานขอมาทำงานวันหยุดประจำปีและหยุดชดเชยวันอื่น</p>
        </div>
        <?php if ($pendingCount > 0 && $statusFilter === 'PENDING'): ?>
        <button type="button" onclick="openApproveAllModal()"
                class="w-full sm:w-auto min-h-[56px] px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold">
            <i class="fas fa-check-double mr-2" aria-hidden="true"></i>อนุมัติทั้งหมด (<?php echo $pendingCount; ?>)
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

<div class="grid grid-cols-2 lg:grid-cols-4 gap-2 sm:gap-4 mb-6 min-w-0">
    <?php
    $statCards = [
        ['status' => 'PENDING', 'label' => 'รออนุมัติ', 'key' => 'pending', 'color' => 'amber'],
        ['status' => 'APPROVED', 'label' => 'อนุมัติแล้ว', 'key' => 'approved', 'color' => 'emerald'],
        ['status' => 'REJECTED', 'label' => 'ไม่อนุมัติ', 'key' => 'rejected', 'color' => 'red'],
        ['status' => '', 'label' => 'ทั้งหมด', 'key' => 'total', 'color' => 'violet'],
    ];
    foreach ($statCards as $card):
        $active = $statusFilter === $card['status'];
    ?>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => $card['status']]))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 touch-manipulation <?php echo $active ? 'ring-2 ring-' . $card['color'] . '-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate"><?php echo htmlspecialchars($card['label']); ?></p>
        <p class="text-2xl font-bold text-<?php echo $card['color']; ?>-400 tabular-nums mt-1"><?php echo (int) ($hwStats[$card['key']] ?? 0); ?></p>
    </a>
    <?php endforeach; ?>
</div>

<div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 mb-6 min-w-0">
    <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
        <div class="tp-native-form-group mb-0">
            <label for="hw-approval-year" class="text-white/70 text-sm font-medium">ปี (วันหยุด)</label>
            <select id="hw-approval-year" name="year" class="input-field tp-native-select w-full" onchange="this.form.submit()">
                <option value="">ทุกปี</option>
                <?php foreach ($yearOptions as $y): ?>
                <option value="<?php echo $y; ?>" <?php echo (string) $year === (string) $y ? 'selected' : ''; ?>><?php echo $y + 543; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<div class="native-card tp-native-card tp-native-data-card p-4 sm:p-6 min-w-0">
    <?php if (!$allRequests): ?>
    <p class="text-white/50 text-center py-8">ไม่มีคำขอตามตัวกรอง</p>
    <?php else: ?>
    <div class="space-y-4 md:hidden">
        <?php foreach ($allRequests as $req):
            $st = (string) $req['status'];
            $stChip = match ($st) {
                'PENDING' => 'bg-amber-500/20 text-amber-300',
                'APPROVED' => 'bg-green-500/20 text-green-400',
                'REJECTED' => 'bg-red-500/20 text-red-400',
                default => 'bg-white/10 text-white/60',
            };
        ?>
        <div class="rounded-[var(--tp-ios-card-radius)] border border-white/10 bg-black/20 p-4">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <p class="text-white font-semibold"><?php echo htmlspecialchars(trim($req['first_name_th'] . ' ' . $req['last_name_th'])); ?></p>
                    <p class="text-white/50 text-xs"><?php echo htmlspecialchars($req['employee_code'] ?? ''); ?></p>
                </div>
                <span class="px-2.5 py-1 text-xs rounded-[var(--tp-ios-card-radius)] font-semibold <?php echo $stChip; ?>"><?php echo htmlspecialchars($st === 'PENDING' ? 'รออนุมัติ' : ($st === 'APPROVED' ? 'อนุมัติ' : 'ไม่อนุมัติ')); ?></span>
            </div>
            <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 px-3 py-2">
                    <p class="text-white/50 text-[11px]">มาทำงานวันหยุด</p>
                    <p class="text-orange-200 font-semibold"><?php echo formatDateThai($req['holiday_date']); ?></p>
                    <p class="text-white/45 text-xs truncate"><?php echo htmlspecialchars($req['holiday_name'] ?? ''); ?></p>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 px-3 py-2">
                    <p class="text-white/50 text-[11px]">หยุดชดเชย</p>
                    <p class="text-violet-200 font-semibold"><?php echo !empty($req['comp_date']) ? formatDateThai($req['comp_date']) : '—'; ?></p>
                </div>
            </div>
            <p class="text-white/60 text-sm mt-3 break-words"><?php echo htmlspecialchars($req['reason'] ?? '-'); ?></p>
            <?php if ($st === 'PENDING'): ?>
            <div class="grid grid-cols-2 gap-2 mt-4">
                <button type="button" onclick="openApproveOneModal(<?php echo (int) $req['id']; ?>)" class="min-h-[56px] rounded-[var(--tp-ios-card-radius)] bg-emerald-600 text-white font-semibold touch-manipulation">อนุมัติ</button>
                <button type="button" data-emp-label="<?php echo htmlspecialchars(trim($req['first_name_th'] . ' ' . $req['last_name_th']), ENT_QUOTES); ?>" onclick="openRejectModal(event, <?php echo (int) $req['id']; ?>)" class="min-h-[56px] rounded-[var(--tp-ios-card-radius)] bg-red-500/15 border border-red-500/35 text-red-200 font-semibold touch-manipulation">ไม่อนุมัติ</button>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0">
        <table class="w-full" style="min-width:880px">
            <thead class="bg-white/5">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">พนักงาน</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">วันหยุด (มาทำงาน)</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">หยุดชดเชย</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">เหตุผล</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($allRequests as $req): ?>
                <tr class="hover:bg-white/[0.04]">
                    <td class="px-4 py-3">
                        <p class="text-white font-medium"><?php echo htmlspecialchars($req['first_name_th'] . ' ' . $req['last_name_th']); ?></p>
                        <p class="text-white/50 text-xs"><?php echo htmlspecialchars($req['employee_code'] ?? ''); ?></p>
                    </td>
                    <td class="px-4 py-3 text-center text-sm">
                        <div class="text-orange-200"><?php echo formatDateThai($req['holiday_date']); ?></div>
                        <div class="text-white/45 text-xs"><?php echo htmlspecialchars($req['holiday_name'] ?? ''); ?></div>
                    </td>
                    <td class="px-4 py-3 text-center text-violet-200 text-sm"><?php echo !empty($req['comp_date']) ? formatDateThai($req['comp_date']) : '—'; ?></td>
                    <td class="px-4 py-3 text-white/70 text-sm max-w-[200px] break-words"><?php echo htmlspecialchars($req['reason'] ?? '-'); ?></td>
                    <td class="px-4 py-3 text-center text-sm"><?php echo htmlspecialchars($req['status']); ?></td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($req['status'] === 'PENDING'): ?>
                        <div class="flex justify-center gap-2">
                            <button type="button" onclick="openApproveOneModal(<?php echo (int) $req['id']; ?>)" class="min-h-[48px] px-3 bg-emerald-600 text-white rounded-[var(--tp-ios-card-radius)] text-sm touch-manipulation">อนุมัติ</button>
                            <button type="button" data-emp-label="<?php echo htmlspecialchars(trim($req['first_name_th'] . ' ' . $req['last_name_th']), ENT_QUOTES); ?>" onclick="openRejectModal(event, <?php echo (int) $req['id']; ?>)" class="min-h-[48px] px-3 bg-red-500/15 text-red-200 rounded-[var(--tp-ios-card-radius)] text-sm touch-manipulation">ไม่อนุมัติ</button>
                        </div>
                        <?php else: ?>
                        <span class="text-white/40 text-xs">—</span>
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

<form id="approve-one-form" method="POST" class="hidden"><?php echo csrfField(); ?>
    <input type="hidden" name="action" value="approve">
    <input type="hidden" name="request_id" id="approve-one-request-id">
</form>

<div id="approve-one-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5">
    <div class="native-card tp-native-card w-full max-w-md p-6 rounded-[var(--tp-ios-card-radius)]">
        <h3 class="text-xl font-bold text-white mb-4">อนุมัติคำขอนี้?</h3>
        <div class="flex gap-3">
            <button type="button" onclick="closeApproveOneModal()" class="flex-1 min-h-[48px] bg-white/10 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation">ยกเลิก</button>
            <button type="button" onclick="document.getElementById('approve-one-form').submit()" class="flex-1 min-h-[56px] bg-emerald-600 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold">อนุมัติ</button>
        </div>
    </div>
</div>

<div id="approve-all-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5">
    <div class="native-card tp-native-card w-full max-w-md p-6 rounded-[var(--tp-ios-card-radius)]">
        <h3 class="text-xl font-bold text-white mb-2">อนุมัติทั้งหมด?</h3>
        <p class="text-white/65 text-sm mb-6">มี <?php echo (int) $pendingCount; ?> คำขอรออนุมัติ</p>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="approve_all">
            <div class="flex gap-3">
                <button type="button" onclick="closeApproveAllModal()" class="flex-1 min-h-[48px] bg-white/10 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation">ยกเลิก</button>
                <button type="submit" class="flex-1 min-h-[56px] bg-emerald-600 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold">อนุมัติทั้งหมด</button>
            </div>
        </form>
    </div>
</div>

<div id="reject-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5">
    <div class="native-card tp-native-card w-full max-w-md rounded-[var(--tp-ios-card-radius)]">
        <form method="POST" class="p-6">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="reject-request-id">
            <h3 class="text-xl font-bold text-white mb-1">ไม่อนุมัติคำขอ</h3>
            <p class="text-white/50 text-sm mb-4" id="reject-label"></p>
            <div class="tp-native-form-group mb-4">
                <label for="reject-review-note" class="text-white/70 text-sm">หมายเหตุ</label>
                <input type="text" name="review_note" id="reject-review-note" class="input-field w-full">
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closeRejectModal()" class="flex-1 min-h-[48px] bg-white/10 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation">ยกเลิก</button>
                <button type="submit" class="flex-1 min-h-[56px] bg-red-600 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold">ไม่อนุมัติ</button>
            </div>
        </form>
    </div>
</div>

<script>
function openApproveOneModal(id) {
    document.getElementById('approve-one-request-id').value = String(id);
    document.getElementById('approve-one-modal').classList.remove('hidden');
}
function closeApproveOneModal() {
    document.getElementById('approve-one-modal').classList.add('hidden');
}
function openApproveAllModal() {
    document.getElementById('approve-all-modal').classList.remove('hidden');
}
function closeApproveAllModal() {
    document.getElementById('approve-all-modal').classList.add('hidden');
}
function openRejectModal(e, id) {
    var label = e && e.currentTarget ? (e.currentTarget.getAttribute('data-emp-label') || '') : '';
    document.getElementById('reject-request-id').value = String(id);
    document.getElementById('reject-label').textContent = label ? ('คำขอของ ' + label) : ('#' + id);
    document.getElementById('reject-modal').classList.remove('hidden');
}
function closeRejectModal() {
    document.getElementById('reject-modal').classList.add('hidden');
}
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
