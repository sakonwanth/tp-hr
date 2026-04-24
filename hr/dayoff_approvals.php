<?php
/**
 * HR Day-Off Request Approvals
 * อนุมัติคำขอเปลี่ยนวันหยุดประจำสัปดาห์ - CEO ขึ้นไป
 */

$page_title = 'อนุมัติวันหยุด';
require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();

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
        flash('success', 'อนุมัติคำขอเรียบร้อยแล้ว');
    } elseif ($action === 'reject' && $requestId) {
        $stmt = $pdo->prepare("
            UPDATE hr_dayoff_requests 
            SET status = 'REJECTED', reviewed_by = ?, reviewed_at = NOW(), review_note = ?
            WHERE id = ? AND status = 'PENDING'
        ");
        $stmt->execute([$user['id'], $_POST['review_note'] ?? null, $requestId]);
        flash('success', 'ปฏิเสธคำขอเรียบร้อยแล้ว');
    } elseif ($action === 'approve_all') {
        $stmt = $pdo->prepare("
            UPDATE hr_dayoff_requests 
            SET status = 'APPROVED', reviewed_by = ?, reviewed_at = NOW()
            WHERE status = 'PENDING'
        ");
        $stmt->execute([$user['id']]);
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

// Count pending
$pendingCount = $pdo->query("SELECT COUNT(*) FROM hr_dayoff_requests WHERE status = 'PENDING'")->fetchColumn();

include dirname(__DIR__) . '/templates/header.php';
?>

<div class="mb-6">
    <nav class="text-sm text-white/60 mb-1">
        <a href="/hr/" class="hover:text-white">HR</a>
        <span class="mx-2">/</span>
        <span class="text-white">อนุมัติเปลี่ยนวันหยุด</span>
    </nav>
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white">อนุมัติเปลี่ยนวันหยุดประจำสัปดาห์</h1>
            <p class="text-white/60 text-sm mt-1">พนักงานขอเปลี่ยนวันหยุดในแต่ละสัปดาห์</p>
        </div>
        <?php if ($pendingCount > 0 && $statusFilter === 'PENDING'): ?>
        <form method="POST" class="inline" onsubmit="return confirm('อนุมัติคำขอทั้งหมด <?php echo $pendingCount; ?> รายการ?')">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="approve_all">
            <button class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors">
                <i class="fas fa-check-double mr-2"></i>อนุมัติทั้งหมด (<?php echo $pendingCount; ?>)
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash = flash('success')): ?>
<div class="glass-card rounded-xl p-4 mb-4 border-l-4 border-green-500">
    <p class="text-green-400"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($flash); ?></p>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="glass-card rounded-xl p-4 mb-6">
    <form method="GET" class="flex flex-wrap items-center gap-4">
        <div class="flex items-center gap-2">
            <label class="text-white/70 text-sm">สถานะ:</label>
            <select name="status" class="input-field w-auto" onchange="this.form.submit()">
                <option value="PENDING" <?php echo $statusFilter === 'PENDING' ? 'selected' : ''; ?>>
                    รออนุมัติ<?php echo $pendingCount > 0 ? " ($pendingCount)" : ''; ?>
                </option>
                <option value="APPROVED" <?php echo $statusFilter === 'APPROVED' ? 'selected' : ''; ?>>อนุมัติแล้ว</option>
                <option value="REJECTED" <?php echo $statusFilter === 'REJECTED' ? 'selected' : ''; ?>>ไม่อนุมัติ</option>
                <option value="" <?php echo $statusFilter === '' ? 'selected' : ''; ?>>ทั้งหมด</option>
            </select>
        </div>
        <div class="flex items-center gap-2">
            <label class="text-white/70 text-sm">เดือน:</label>
            <input type="month" name="month" class="input-field w-auto" value="<?php echo $month; ?>" onchange="this.form.submit()">
        </div>
    </form>
</div>

<!-- Requests Table -->
<div class="glass-card rounded-xl overflow-hidden">
    <?php if (empty($allRequests)): ?>
    <div class="p-12 text-center">
        <i class="fas fa-calendar-check text-4xl text-white/20 mb-4"></i>
        <p class="text-white/60">ไม่มีคำขอ</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-white/5">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">พนักงาน</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สัปดาห์</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">วันหยุดเดิม</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ขอเปลี่ยนเป็น</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">เหตุผล</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($allRequests as $req): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-4 py-3">
                        <p class="text-white font-medium"><?php echo htmlspecialchars($req['first_name_th'] . ' ' . $req['last_name_th']); ?></p>
                        <p class="text-white/50 text-xs"><?php echo htmlspecialchars($req['employee_code'] ?? ''); ?> | <?php echo htmlspecialchars($req['department'] ?? '-'); ?></p>
                    </td>
                    <td class="px-4 py-3 text-center text-white text-sm">
                        <div><?php echo formatDateThai($req['week_start']); ?></div>
                        <div class="text-white/50 text-xs">ถึง <?php echo formatDateThai($req['week_end']); ?></div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-blue-400"><?php echo $dayNames[(int)$req['original_day_off']]; ?></span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-violet-400 font-medium"><?php echo $dayNames[(int)$req['requested_day_off']]; ?></span>
                    </td>
                    <td class="px-4 py-3 text-white/60 text-sm">
                        <?php echo htmlspecialchars($req['reason'] ?? '-'); ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-1 text-xs rounded-full <?php 
                        echo match($req['status']) {
                            'PENDING' => 'bg-yellow-500/20 text-yellow-400',
                            'APPROVED' => 'bg-green-500/20 text-green-400',
                            'REJECTED' => 'bg-red-500/20 text-red-400',
                            default => 'bg-gray-500/20 text-gray-400'
                        }; ?>">
                        <?php echo match($req['status']) {
                            'PENDING' => 'รออนุมัติ',
                            'APPROVED' => 'อนุมัติ',
                            'REJECTED' => 'ไม่อนุมัติ',
                            default => $req['status']
                        }; ?>
                        </span>
                        <?php if ($req['status'] !== 'PENDING' && $req['reviewer_name_first']): ?>
                        <div class="text-white/40 text-xs mt-1">
                            โดย <?php echo htmlspecialchars($req['reviewer_name_first']); ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($req['status'] === 'PENDING'): ?>
                        <div class="flex items-center justify-center gap-1">
                            <form method="POST" class="inline">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                <button class="px-2 py-1 bg-green-500/20 hover:bg-green-500/30 text-green-400 text-xs rounded transition-colors" title="อนุมัติ">
                                    <i class="fas fa-check"></i>
                                </button>
                            </form>
                            <button onclick="openRejectModal(<?php echo $req['id']; ?>, '<?php echo htmlspecialchars($req['first_name_th']); ?>')"
                                    class="px-2 py-1 bg-red-500/20 hover:bg-red-500/30 text-red-400 text-xs rounded transition-colors" title="ไม่อนุมัติ">
                                <i class="fas fa-times"></i>
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

<!-- Reject Modal -->
<div id="reject-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl w-full max-w-md">
        <form method="POST" class="p-6">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="reject-request-id">
            
            <h3 class="text-xl font-bold text-white mb-1">ไม่อนุมัติคำขอ</h3>
            <p class="text-white/50 text-sm mb-4" id="reject-label"></p>
            
            <div class="mb-4">
                <label class="block text-white/70 text-sm mb-1">หมายเหตุ (ถ้ามี)</label>
                <input type="text" name="review_note" class="input-field" placeholder="เหตุผลที่ไม่อนุมัติ">
            </div>
            
            <div class="flex gap-3">
                <button type="button" onclick="closeRejectModal()" class="flex-1 py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-lg transition-colors">ยกเลิก</button>
                <button type="submit" class="flex-1 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                    <i class="fas fa-times mr-2"></i>ไม่อนุมัติ
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openRejectModal(id, name) {
    document.getElementById('reject-request-id').value = id;
    document.getElementById('reject-label').textContent = 'คำขอของ ' + name;
    document.getElementById('reject-modal').classList.remove('hidden');
}
function closeRejectModal() {
    document.getElementById('reject-modal').classList.add('hidden');
}
document.getElementById('reject-modal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
