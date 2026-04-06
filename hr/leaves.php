<?php
/**
 * HR Leave Management
 * จัดการการลา - สำหรับ HR
 */

$page_title = 'จัดการการลา';
require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();

if (!isHR()) {
    redirect('/', 302);
}

$pdo = Database::getInstance()->getConnection();

// Filters
$status = $_GET['status'] ?? 'PENDING';
$type = (int)($_GET['type'] ?? 0);
$department = $_GET['department'] ?? '';
$month = $_GET['month'] ?? date('Y-m');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Get leave types
$stmtTypes = $pdo->query("SELECT id, name FROM hr_leave_types WHERE is_active = 1 ORDER BY sort_order");
$leaveTypes = $stmtTypes->fetchAll();

// Get departments
$stmtDepts = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department");
$departments = $stmtDepts->fetchAll(PDO::FETCH_COLUMN);

// Build query
$sql = "
    SELECT lr.*, lt.name as leave_type_name, lt.color_code,
           u.first_name_th, u.last_name_th, u.employee_code, u.department,
           approver.first_name_th as approver_first, approver.last_name_th as approver_last
    FROM hr_leave_requests lr
    JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
    JOIN users u ON lr.user_id = u.id
    LEFT JOIN users approver ON lr.approved_by = approver.id
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
$countSql = "SELECT COUNT(*) FROM (" . str_replace("lr.*, lt.name as leave_type_name, lt.color_code,\n           u.first_name_th, u.last_name_th, u.employee_code, u.department,\n           approver.first_name_th as approver_first, approver.last_name_th as approver_last", "1", $sql) . ") t";
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

include dirname(__DIR__) . '/templates/header.php';
?>

<div class="mb-6">
    <nav class="text-sm text-white/60 mb-1">
        <a href="/hr/" class="hover:text-white">HR</a>
        <span class="mx-2">/</span>
        <span class="text-white">จัดการการลา</span>
    </nav>
    <h1 class="text-2xl font-bold text-white">จัดการการลา</h1>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <a href="?status=PENDING&month=<?php echo $month; ?>" 
       class="glass-card rounded-xl p-4 <?php echo $status === 'PENDING' ? 'ring-2 ring-yellow-400' : ''; ?>">
        <p class="text-white/50 text-sm">รออนุมัติ</p>
        <p class="text-2xl font-bold text-yellow-400"><?php echo $stats['pending'] ?? 0; ?></p>
    </a>
    <a href="?status=APPROVED&month=<?php echo $month; ?>"
       class="glass-card rounded-xl p-4 <?php echo $status === 'APPROVED' ? 'ring-2 ring-green-400' : ''; ?>">
        <p class="text-white/50 text-sm">อนุมัติแล้ว</p>
        <p class="text-2xl font-bold text-green-400"><?php echo $stats['approved'] ?? 0; ?></p>
    </a>
    <a href="?status=REJECTED&month=<?php echo $month; ?>"
       class="glass-card rounded-xl p-4 <?php echo $status === 'REJECTED' ? 'ring-2 ring-red-400' : ''; ?>">
        <p class="text-white/50 text-sm">ไม่อนุมัติ</p>
        <p class="text-2xl font-bold text-red-400"><?php echo $stats['rejected'] ?? 0; ?></p>
    </a>
    <a href="?status=ALL&month=<?php echo $month; ?>"
       class="glass-card rounded-xl p-4 <?php echo $status === 'ALL' ? 'ring-2 ring-violet-400' : ''; ?>">
        <p class="text-white/50 text-sm">ทั้งหมด</p>
        <p class="text-2xl font-bold text-violet-400"><?php echo $stats['total'] ?? 0; ?></p>
    </a>
</div>

<!-- Filters -->
<div class="glass-card rounded-xl p-4 mb-6">
    <form method="GET" class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
        <div>
            <label class="block text-white/60 text-xs mb-1">เดือน</label>
            <input type="month" name="month" value="<?php echo $month; ?>" class="input-field" onchange="this.form.submit()">
        </div>
        <div>
            <label class="block text-white/60 text-xs mb-1">ประเภทการลา</label>
            <select name="type" class="input-field" onchange="this.form.submit()">
                <option value="">ทั้งหมด</option>
                <?php foreach ($leaveTypes as $lt): ?>
                <option value="<?php echo $lt['id']; ?>" <?php echo $type == $lt['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($lt['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
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
        <div class="md:col-span-2 flex items-end gap-2">
            <a href="leaves.php" class="flex-1 py-2.5 bg-white/10 hover:bg-white/20 text-white text-center rounded-lg transition-colors">
                <i class="fas fa-redo mr-2"></i>รีเซ็ต
            </a>
            <a href="leaves.php?action=calendar&month=<?php echo $month; ?>" class="flex-1 py-2.5 bg-violet-600 hover:bg-violet-700 text-white text-center rounded-lg transition-colors">
                <i class="fas fa-calendar-alt mr-2"></i>ปฏิทิน
            </a>
        </div>
    </form>
</div>

<!-- Results -->
<div class="glass-card rounded-xl overflow-hidden">
    <?php if (empty($requests)): ?>
    <div class="p-12 text-center">
        <i class="fas fa-calendar-check text-4xl text-white/20 mb-4"></i>
        <p class="text-white/60">ไม่พบคำขอลา</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-white/5">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">พนักงาน</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ประเภท</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">วันที่ลา</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">จำนวนวัน</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">เหตุผล</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">การดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($requests as $req): ?>
                <?php
                $statusColors = [
                    'PENDING' => 'bg-yellow-500/20 text-yellow-400',
                    'APPROVED' => 'bg-green-500/20 text-green-400',
                    'REJECTED' => 'bg-red-500/20 text-red-400',
                    'CANCELLED' => 'bg-gray-500/20 text-gray-400'
                ];
                $statusText = [
                    'PENDING' => 'รออนุมัติ',
                    'APPROVED' => 'อนุมัติ',
                    'REJECTED' => 'ไม่อนุมัติ',
                    'CANCELLED' => 'ยกเลิก'
                ];
                ?>
                <tr class="hover:bg-white/5">
                    <td class="px-4 py-3">
                        <p class="text-white font-medium"><?php echo htmlspecialchars($req['first_name_th'] . ' ' . $req['last_name_th']); ?></p>
                        <p class="text-white/50 text-xs"><?php echo htmlspecialchars($req['employee_code'] ?? ''); ?> | <?php echo htmlspecialchars($req['department'] ?? '-'); ?></p>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full" style="background-color: <?php echo $req['color_code']; ?>"></span>
                            <span class="text-white"><?php echo htmlspecialchars($req['leave_type_name']); ?></span>
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
                        <span class="px-3 py-1 rounded-full text-xs <?php echo $statusColors[$req['status']]; ?>">
                            <?php echo $statusText[$req['status']]; ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($req['status'] === 'PENDING'): ?>
                        <button onclick="approveLeave(<?php echo $req['id']; ?>)" 
                                class="px-2 py-1 bg-green-600 hover:bg-green-700 text-white text-xs rounded transition-colors mr-1">
                            <i class="fas fa-check"></i>
                        </button>
                        <button onclick="rejectLeave(<?php echo $req['id']; ?>)"
                                class="px-2 py-1 bg-red-500/20 hover:bg-red-500/30 text-red-400 text-xs rounded transition-colors">
                            <i class="fas fa-times"></i>
                        </button>
                        <?php else: ?>
                        <button onclick="viewDetail(<?php echo $req['id']; ?>)" class="px-2 py-1 bg-white/10 hover:bg-white/20 text-white text-xs rounded transition-colors">
                            <i class="fas fa-eye"></i>
                        </button>
                        <?php endif; ?>
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

<!-- Modals -->
<div id="reject-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl w-full max-w-md">
        <form id="reject-form" class="p-6">
            <h3 class="text-xl font-bold text-white mb-4">ไม่อนุมัติคำขอลา</h3>
            <input type="hidden" name="request_id" id="reject-request-id">
            <div class="mb-4">
                <label class="block text-white/80 text-sm mb-2">เหตุผล <span class="text-red-400">*</span></label>
                <textarea name="reason" id="reject-reason" required rows="3" class="input-field"></textarea>
            </div>
            <div class="flex gap-4">
                <button type="button" onclick="closeRejectModal()" class="flex-1 py-2 bg-white/10 hover:bg-white/20 text-white rounded-lg">ยกเลิก</button>
                <button type="submit" class="flex-1 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg">ไม่อนุมัติ</button>
            </div>
        </form>
    </div>
</div>

<div id="detail-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-bold text-white">รายละเอียดคำขอลา</h3>
                <button onclick="closeDetailModal()" class="p-2 text-white/60 hover:text-white hover:bg-white/10 rounded-lg">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="detail-content">
                <div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-white/30"></i></div>
            </div>
        </div>
    </div>
</div>

<script>
async function approveLeave(id) {
    if (!confirm('อนุมัติคำขอลานี้?')) return;
    
    const formData = new FormData();
    formData.append('action', 'approve');
    formData.append('request_id', id);
    formData.append('_token', '<?php echo csrfToken(); ?>');
    
    const response = await fetch('/api/leave.php', { method: 'POST', body: formData });
    const result = await response.json();
    
    if (result.success) {
        showToast('อนุมัติสำเร็จ', 'success');
        setTimeout(() => location.reload(), 1000);
    } else {
        showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
    }
}

function rejectLeave(id) {
    document.getElementById('reject-request-id').value = id;
    document.getElementById('reject-reason').value = '';
    document.getElementById('reject-modal').classList.remove('hidden');
}

function closeRejectModal() {
    document.getElementById('reject-modal').classList.add('hidden');
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
    document.getElementById('detail-modal').classList.remove('hidden');
    document.getElementById('detail-content').innerHTML = '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-white/30"></i></div>';
    
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
    document.getElementById('detail-modal').classList.add('hidden');
}

document.getElementById('reject-modal').addEventListener('click', e => { if (e.target === document.getElementById('reject-modal')) closeRejectModal(); });
document.getElementById('detail-modal').addEventListener('click', e => { if (e.target === document.getElementById('detail-modal')) closeDetailModal(); });
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
