<?php
/**
 * Leave History Page
 * ประวัติการลา
 */

$page_title = 'ประวัติการลา';
require_once __DIR__ . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();

$pdo = Database::getInstance()->getConnection();

// Get filter parameters
$year = (int)($_GET['year'] ?? date('Y'));
$type = (int)($_GET['type'] ?? 0);
$status = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// Get leave types for filter
$stmtTypes = $pdo->query("SELECT id, name FROM hr_leave_types WHERE is_active = 1 ORDER BY sort_order");
$leaveTypes = $stmtTypes->fetchAll();

// Build query
$sql = "
    SELECT lr.*, lt.name as leave_type_name, lt.color as color_code,
           CONCAT(approver.first_name_th, ' ', approver.last_name_th) as approved_by_name
    FROM hr_leave_requests lr
    JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
    LEFT JOIN users approver ON lr.final_approved_by = approver.id
    WHERE lr.user_id = ? AND YEAR(lr.start_date) = ?
";
$params = [$user['id'], $year];

if ($type > 0) {
    $sql .= " AND lr.leave_type_id = ?";
    $params[] = $type;
}

if ($status) {
    $sql .= " AND lr.status = ?";
    $params[] = $status;
}

// Count total
$countSql = str_replace("lr.*, lt.name as leave_type_name, lt.color as color_code,\n           CONCAT(approver.first_name_th, ' ', approver.last_name_th) as approved_by_name", "COUNT(*)", $sql);
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// Get records
$sql .= " ORDER BY lr.created_at DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// Get summary for this year
$stmtSummary = $pdo->prepare("
    SELECT lt.name, lt.color as color_code,
           SUM(CASE WHEN lr.status = 'APPROVED' THEN lr.total_days ELSE 0 END) as approved_days,
           SUM(CASE WHEN lr.status = 'PENDING' THEN lr.total_days ELSE 0 END) as pending_days,
           COUNT(CASE WHEN lr.status = 'APPROVED' THEN 1 END) as approved_count,
           COUNT(CASE WHEN lr.status = 'PENDING' THEN 1 END) as pending_count
    FROM hr_leave_requests lr
    JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
    WHERE lr.user_id = ? AND YEAR(lr.start_date) = ?
    GROUP BY lt.id, lt.name, lt.color
    ORDER BY lt.sort_order
");
$stmtSummary->execute([$user['id'], $year]);
$summary = $stmtSummary->fetchAll();

// Years available
$stmtYears = $pdo->prepare("
    SELECT DISTINCT YEAR(start_date) as year 
    FROM hr_leave_requests 
    WHERE user_id = ? 
    ORDER BY year DESC
");
$stmtYears->execute([$user['id']]);
$availableYears = $stmtYears->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($year, $availableYears)) {
    $availableYears[] = $year;
    rsort($availableYears);
}

include 'templates/header.php';
?>

<div class="mb-6">
    <nav class="text-sm text-white/60 mb-1">
        <a href="leave.php" class="hover:text-white">การลา</a>
        <span class="mx-2">/</span>
        <span class="text-white">ประวัติการลา</span>
    </nav>
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <h1 class="text-2xl font-bold text-white">ประวัติการลา</h1>
        <a href="leave.php?action=new" class="inline-flex items-center px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg transition-colors">
            <i class="fas fa-plus mr-2"></i>ยื่นขอลาใหม่
        </a>
    </div>
</div>

<!-- Summary Cards -->
<?php if (!empty($summary)): ?>
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <?php foreach ($summary as $sum): ?>
    <div class="glass-card rounded-xl p-4">
        <div class="flex items-center gap-3">
            <div class="w-3 h-3 rounded-full" style="background-color: <?php echo $sum['color_code']; ?>"></div>
            <span class="text-white/70 text-sm"><?php echo htmlspecialchars($sum['name']); ?></span>
        </div>
        <p class="text-2xl font-bold text-white mt-2">
            <?php echo number_format($sum['approved_days'], 1); ?> <span class="text-sm font-normal text-white/60">วัน</span>
        </p>
        <p class="text-xs text-white/50 mt-1">
            <?php echo $sum['approved_count']; ?> ครั้ง
            <?php if ($sum['pending_days'] > 0): ?>
            <span class="text-yellow-400 ml-2">(รออนุมัติ <?php echo number_format($sum['pending_days'], 1); ?> วัน)</span>
            <?php endif; ?>
        </p>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="glass-card rounded-xl p-4 mb-6">
    <form method="GET" class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div>
            <label class="block text-white/60 text-xs mb-1">ปี</label>
            <select name="year" class="input-field" onchange="this.form.submit()">
                <?php foreach ($availableYears as $y): ?>
                <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y + 543; ?></option>
                <?php endforeach; ?>
            </select>
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
            <label class="block text-white/60 text-xs mb-1">สถานะ</label>
            <select name="status" class="input-field" onchange="this.form.submit()">
                <option value="">ทั้งหมด</option>
                <option value="PENDING" <?php echo $status === 'PENDING' ? 'selected' : ''; ?>>รออนุมัติ</option>
                <option value="APPROVED" <?php echo $status === 'APPROVED' ? 'selected' : ''; ?>>อนุมัติ</option>
                <option value="REJECTED" <?php echo $status === 'REJECTED' ? 'selected' : ''; ?>>ไม่อนุมัติ</option>
                <option value="CANCELLED" <?php echo $status === 'CANCELLED' ? 'selected' : ''; ?>>ยกเลิก</option>
            </select>
        </div>
        <div class="flex items-end">
            <a href="leave_history.php?year=<?php echo $year; ?>" class="w-full py-2.5 bg-white/10 hover:bg-white/20 text-white text-center rounded-lg transition-colors">
                <i class="fas fa-redo mr-2"></i>รีเซ็ต
            </a>
        </div>
    </form>
</div>

<!-- Results -->
<div class="glass-card rounded-xl overflow-hidden">
    <?php if (empty($requests)): ?>
    <div class="p-12 text-center">
        <i class="fas fa-calendar-times text-4xl text-white/20 mb-4"></i>
        <p class="text-white/60">ไม่พบประวัติการลา</p>
    </div>
    <?php else: ?>
    <!-- Mobile View -->
    <div class="md:hidden divide-y divide-white/10">
        <?php foreach ($requests as $req): ?>
        <div class="p-4">
            <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full" style="background-color: <?php echo $req['color_code']; ?>"></span>
                    <span class="text-white font-medium"><?php echo htmlspecialchars($req['leave_type_name']); ?></span>
                </div>
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
                <span class="px-2 py-0.5 rounded text-xs <?php echo $statusColors[$req['status']]; ?>">
                    <?php echo $statusText[$req['status']]; ?>
                </span>
            </div>
            <p class="text-white/60 text-sm">
                <?php echo formatDateThai($req['start_date']); ?> 
                <?php if ($req['start_date'] !== $req['end_date']): ?>
                - <?php echo formatDateThai($req['end_date']); ?>
                <?php endif; ?>
            </p>
            <p class="text-white/80 text-sm mt-1">
                <?php echo number_format($req['total_days'], 1); ?> วัน
            </p>
            <p class="text-white/50 text-xs mt-2 truncate"><?php echo htmlspecialchars($req['reason']); ?></p>
            
            <div class="flex gap-2 mt-3">
                <button onclick="viewDetail(<?php echo $req['id']; ?>)" class="flex-1 py-1.5 bg-white/10 hover:bg-white/20 text-white text-xs rounded transition-colors">
                    <i class="fas fa-eye mr-1"></i>ดูรายละเอียด
                </button>
                <?php if ($req['status'] === 'PENDING'): ?>
                <button onclick="cancelRequest(<?php echo $req['id']; ?>)" class="flex-1 py-1.5 bg-red-500/20 hover:bg-red-500/30 text-red-400 text-xs rounded transition-colors">
                    <i class="fas fa-times mr-1"></i>ยกเลิก
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Desktop View -->
    <table class="hidden md:table w-full">
        <thead class="bg-white/5">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-white/60 uppercase">เลขที่</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-white/60 uppercase">ประเภท</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-white/60 uppercase">วันที่ลา</th>
                <th class="px-6 py-3 text-center text-xs font-medium text-white/60 uppercase">จำนวนวัน</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-white/60 uppercase">เหตุผล</th>
                <th class="px-6 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                <th class="px-6 py-3 text-center text-xs font-medium text-white/60 uppercase">การดำเนินการ</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-white/10">
            <?php foreach ($requests as $req): ?>
            <tr class="hover:bg-white/5">
                <td class="px-6 py-4 text-white/60 text-sm"><?php echo htmlspecialchars($req['request_number']); ?></td>
                <td class="px-6 py-4">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full" style="background-color: <?php echo $req['color_code']; ?>"></span>
                        <span class="text-white"><?php echo htmlspecialchars($req['leave_type_name']); ?></span>
                    </div>
                </td>
                <td class="px-6 py-4 text-white/80 text-sm">
                    <?php echo formatDateThai($req['start_date']); ?> 
                    <?php if ($req['start_date'] !== $req['end_date']): ?>
                    <br><span class="text-white/50">ถึง</span> <?php echo formatDateThai($req['end_date']); ?>
                    <?php endif; ?>
                </td>
                <td class="px-6 py-4 text-center text-white font-medium">
                    <?php echo number_format($req['total_days'], 1); ?>
                </td>
                <td class="px-6 py-4 text-white/70 text-sm max-w-xs truncate">
                    <?php echo htmlspecialchars($req['reason']); ?>
                </td>
                <td class="px-6 py-4 text-center">
                    <span class="px-3 py-1 rounded-full text-xs <?php echo $statusColors[$req['status']]; ?>">
                        <?php echo $statusText[$req['status']]; ?>
                    </span>
                </td>
                <td class="px-6 py-4 text-center">
                    <button onclick="viewDetail(<?php echo $req['id']; ?>)" class="p-2 text-white/60 hover:text-white hover:bg-white/10 rounded-lg transition-colors" title="ดูรายละเอียด">
                        <i class="fas fa-eye"></i>
                    </button>
                    <?php if ($req['status'] === 'PENDING'): ?>
                    <button onclick="cancelRequest(<?php echo $req['id']; ?>)" class="p-2 text-red-400 hover:text-red-300 hover:bg-red-500/10 rounded-lg transition-colors" title="ยกเลิก">
                        <i class="fas fa-times"></i>
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="px-6 py-4 border-t border-white/10 flex items-center justify-between">
        <p class="text-white/60 text-sm">
            แสดง <?php echo $offset + 1; ?> - <?php echo min($offset + $limit, $totalRecords); ?> 
            จาก <?php echo $totalRecords; ?> รายการ
        </p>
        <div class="flex gap-2">
            <?php if ($page > 1): ?>
            <a href="?year=<?php echo $year; ?>&type=<?php echo $type; ?>&status=<?php echo $status; ?>&page=<?php echo $page - 1; ?>" 
               class="px-3 py-1 bg-white/10 hover:bg-white/20 text-white rounded transition-colors">
                <i class="fas fa-chevron-left"></i>
            </a>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="?year=<?php echo $year; ?>&type=<?php echo $type; ?>&status=<?php echo $status; ?>&page=<?php echo $i; ?>" 
               class="px-3 py-1 <?php echo $i === $page ? 'bg-violet-600 text-white' : 'bg-white/10 hover:bg-white/20 text-white'; ?> rounded transition-colors">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?year=<?php echo $year; ?>&type=<?php echo $type; ?>&status=<?php echo $status; ?>&page=<?php echo $page + 1; ?>" 
               class="px-3 py-1 bg-white/10 hover:bg-white/20 text-white rounded transition-colors">
                <i class="fas fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Detail Modal -->
<div id="detail-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-bold text-white">รายละเอียดคำขอลา</h3>
                <button onclick="closeModal()" class="p-2 text-white/60 hover:text-white hover:bg-white/10 rounded-lg">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="detail-content">
                <div class="text-center py-8">
                    <i class="fas fa-spinner fa-spin text-2xl text-white/30"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
async function viewDetail(id) {
    const modal = document.getElementById('detail-modal');
    const content = document.getElementById('detail-content');
    
    modal.classList.remove('hidden');
    content.innerHTML = '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-white/30"></i></div>';
    
    try {
        const response = await fetch(`/api/leave.php?action=detail&id=${id}`);
        const result = await response.json();
        
        if (result.success) {
            const r = result.request;
            const statusColors = {
                'PENDING': 'bg-yellow-500/20 text-yellow-400',
                'APPROVED': 'bg-green-500/20 text-green-400',
                'REJECTED': 'bg-red-500/20 text-red-400',
                'CANCELLED': 'bg-gray-500/20 text-gray-400'
            };
            const statusText = {
                'PENDING': 'รออนุมัติ',
                'APPROVED': 'อนุมัติ',
                'REJECTED': 'ไม่อนุมัติ',
                'CANCELLED': 'ยกเลิก'
            };
            
            content.innerHTML = `
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-white/60">เลขที่คำขอ</span>
                        <span class="text-white font-medium">${r.request_number}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-white/60">ประเภทการลา</span>
                        <span class="text-white">${r.leave_type_name}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-white/60">วันที่ลา</span>
                        <span class="text-white">${r.start_date} ${r.start_date !== r.end_date ? '- ' + r.end_date : ''}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-white/60">จำนวนวัน</span>
                        <span class="text-white font-medium">${parseFloat(r.total_days).toFixed(1)} วัน</span>
                    </div>
                    <div class="pt-2 border-t border-white/10">
                        <span class="text-white/60 block mb-2">เหตุผล</span>
                        <p class="text-white">${r.reason}</p>
                    </div>
                    ${r.contact_number ? `
                    <div class="flex items-center justify-between">
                        <span class="text-white/60">เบอร์ติดต่อ</span>
                        <span class="text-white">${r.contact_number}</span>
                    </div>
                    ` : ''}
                    <div class="flex items-center justify-between">
                        <span class="text-white/60">สถานะ</span>
                        <span class="px-3 py-1 rounded-full text-sm ${statusColors[r.status]}">${statusText[r.status]}</span>
                    </div>
                    ${r.approved_by_name ? `
                    <div class="pt-2 border-t border-white/10">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-white/60">ผู้พิจารณา</span>
                            <span class="text-white">${r.approved_by_name}</span>
                        </div>
                        ${r.approver_comment ? `
                        <span class="text-white/60 block mb-1">ความเห็น</span>
                        <p class="text-white/80 text-sm">${r.approver_comment}</p>
                        ` : ''}
                    </div>
                    ` : ''}
                    ${r.document_path ? `
                    <div class="pt-2 border-t border-white/10">
                        <a href="${r.document_path}" target="_blank" class="inline-flex items-center text-violet-400 hover:text-violet-300">
                            <i class="fas fa-paperclip mr-2"></i>ดูเอกสารแนบ
                        </a>
                    </div>
                    ` : ''}
                </div>
            `;
        } else {
            content.innerHTML = '<p class="text-red-400 text-center">' + (result.error || 'เกิดข้อผิดพลาด') + '</p>';
        }
    } catch (err) {
        console.error(err);
        content.innerHTML = '<p class="text-red-400 text-center">เกิดข้อผิดพลาดในการโหลดข้อมูล</p>';
    }
}

function closeModal() {
    document.getElementById('detail-modal').classList.add('hidden');
}

async function cancelRequest(id) {
    if (!confirm('ต้องการยกเลิกคำขอลานี้?')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'cancel');
        formData.append('request_id', id);
        formData.append('csrf_token', '<?php echo csrfToken(); ?>');
        
        const response = await fetch('/api/leave.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast('ยกเลิกคำขอลาสำเร็จ', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
    }
}

// Close modal on click outside
document.getElementById('detail-modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php include 'templates/footer.php'; ?>
