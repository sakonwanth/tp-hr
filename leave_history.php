<?php
/**
 * Leave History Page
 * ประวัติการลา
 */

$page_title = 'ประวัติการลา';
require_once __DIR__ . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();
$current_page = 'leave';

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
           lr.approver_1_remarks AS approver_comment,
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

<div class="tp-leave-history-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="index.php" class="hover:text-white touch-manipulation">หน้าแรก</a>
        <span class="mx-2">/</span>
        <a href="leave.php" class="hover:text-white touch-manipulation">การลา</a>
        <span class="mx-2">/</span>
        <span class="text-white">ประวัติการลา</span>
    </nav>
    <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between gap-y-4">
        <div class="min-w-0 flex-1">
            <h1 class="tp-ios-page-title">ประวัติการลา</h1>
            <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">กรองตามปี ประเภท และสถานะ ดูสรุปวันลาที่ใช้ไปในแต่ละประเภท</p>
        </div>
        <a href="leave.php?action=request" class="btn-primary btn-primary-prominent w-full sm:w-auto shrink-0 inline-flex items-center justify-center touch-manipulation">
            <i class="fas fa-plus mr-2"></i>ยื่นขอลาใหม่
        </a>
    </div>
</header>
<?php if (!empty($summary)): ?>
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6 mb-6 min-w-0 max-w-full">
    <?php foreach ($summary as $sum): ?>
    <div class="native-card tp-native-card tp-native-data-card min-w-0">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-3 h-3 rounded-full shrink-0" style="background-color: <?php echo htmlspecialchars($sum['color_code'] ?? '#6B7280'); ?>"></div>
            <span class="text-white/70 text-sm truncate min-w-0"><?php echo htmlspecialchars($sum['name']); ?></span>
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
<div class="native-card tp-native-card tp-native-data-card mb-6 min-w-0">
    <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6 min-w-0">
        <div class="min-w-0">
            <label class="block text-white/60 text-xs mb-1">ปี</label>
            <select name="year" class="input-field tp-native-select" onchange="this.form.submit()">
                <?php foreach ($availableYears as $y): ?>
                <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y + 543; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="min-w-0">
            <label class="block text-white/60 text-xs mb-1">ประเภทการลา</label>
            <select name="type" class="input-field tp-native-select" onchange="this.form.submit()">
                <option value="">ทั้งหมด</option>
                <?php foreach ($leaveTypes as $lt): ?>
                <option value="<?php echo $lt['id']; ?>" <?php echo $type == $lt['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($lt['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="min-w-0">
            <label class="block text-white/60 text-xs mb-1">สถานะ</label>
            <select name="status" class="input-field tp-native-select" onchange="this.form.submit()">
                <option value="">ทั้งหมด</option>
                <option value="PENDING" <?php echo $status === 'PENDING' ? 'selected' : ''; ?>>รออนุมัติ</option>
                <option value="APPROVED" <?php echo $status === 'APPROVED' ? 'selected' : ''; ?>>อนุมัติ</option>
                <option value="REJECTED" <?php echo $status === 'REJECTED' ? 'selected' : ''; ?>>ไม่อนุมัติ</option>
                <option value="CANCELLED" <?php echo $status === 'CANCELLED' ? 'selected' : ''; ?>>ยกเลิก</option>
            </select>
        </div>
        <div class="flex items-end min-w-0">
            <a href="leave_history.php?year=<?php echo (int)$year; ?>" class="touch-manipulation w-full min-h-[54px] inline-flex items-center justify-center py-2.5 bg-white/10 hover:bg-white/20 text-white text-center rounded-[var(--tp-radius-button)] transition-colors font-medium">
                <i class="fas fa-redo mr-2" aria-hidden="true"></i>รีเซ็ต
            </a>
        </div>
    </form>
</div>

<!-- Results -->
<div class="native-card tp-native-card tp-native-data-card min-w-0 max-w-full overflow-hidden">
    <?php if (empty($requests)): ?>
    <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-4 my-4">
        <i class="fas fa-calendar-times text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
        <p class="text-slate-400 text-sm">ไม่พบประวัติการลา</p>
    </div>
    <?php else: ?>
    <?php
    $statusColors = [
        'PENDING' => 'bg-yellow-500/20 text-yellow-400',
        'APPROVED' => 'bg-green-500/20 text-green-400',
        'REJECTED' => 'bg-red-500/20 text-red-400',
        'CANCELLED' => 'bg-gray-500/20 text-gray-400',
    ];
    $statusText = [
        'PENDING' => 'รออนุมัติ',
        'APPROVED' => 'อนุมัติ',
        'REJECTED' => 'ไม่อนุมัติ',
        'CANCELLED' => 'ยกเลิก',
    ];
    ?>
    <!-- Mobile: card stack (< md) -->
    <div class="md:hidden space-y-6 min-w-0 p-1">
        <?php foreach ($requests as $req): ?>
        <div class="tp-ios-attendance-panel p-5 min-w-0">
            <div class="flex items-center justify-between gap-2 mb-2 min-w-0">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="w-2 h-2 rounded-full shrink-0" style="background-color: <?php echo htmlspecialchars($req['color_code'] ?? '#6B7280'); ?>"></span>
                    <span class="text-white font-medium truncate min-w-0"><?php echo htmlspecialchars($req['leave_type_name']); ?></span>
                </div>
                <span class="px-2.5 py-1 rounded-[var(--tp-ios-card-radius)] text-xs font-medium shrink-0 border border-white/10 <?php echo $statusColors[$req['status']] ?? 'bg-gray-500/20 text-gray-400'; ?>">
                    <?php echo $statusText[$req['status']] ?? $req['status']; ?>
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
            <p class="text-white/50 text-xs mt-2 line-clamp-2"><?php echo htmlspecialchars($req['reason']); ?></p>
            
            <div class="flex gap-2 mt-3">
                <button type="button" onclick="viewDetail(<?php echo $req['id']; ?>)" class="flex-1 min-h-[48px] py-2 bg-white/10 hover:bg-white/20 text-white text-sm rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation font-medium">
                    <i class="fas fa-eye mr-1" aria-hidden="true"></i>ดูรายละเอียด
                </button>
                <?php if ($req['status'] === 'PENDING'): ?>
                <button type="button" onclick="cancelRequest(<?php echo $req['id']; ?>)" class="flex-1 min-h-[48px] py-2 bg-red-500/15 hover:bg-red-500/25 border border-red-500/30 text-red-300 text-sm rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation font-medium">
                    <i class="fas fa-times mr-1" aria-hidden="true"></i>ยกเลิก
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Desktop View -->
    <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain -mx-1 px-1">
    <table class="w-full" style="min-width:640px">
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
                    <span class="px-3 py-1 rounded-full text-xs <?php echo $statusColors[$req['status']] ?? 'bg-gray-500/20 text-gray-400'; ?>">
                        <?php echo $statusText[$req['status']] ?? htmlspecialchars($req['status']); ?>
                    </span>
                </td>
                <td class="px-6 py-4 text-center">
                    <button type="button" onclick="viewDetail(<?php echo $req['id']; ?>)" class="min-h-[48px] min-w-[48px] inline-flex items-center justify-center p-2 text-white/60 hover:text-white hover:bg-white/10 rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation" title="ดูรายละเอียด">
                        <i class="fas fa-eye" aria-hidden="true"></i>
                    </button>
                    <?php if ($req['status'] === 'PENDING'): ?>
                    <button type="button" onclick="cancelRequest(<?php echo $req['id']; ?>)" class="min-h-[48px] min-w-[48px] inline-flex items-center justify-center p-2 text-red-400 hover:text-red-300 hover:bg-red-500/10 rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation" title="ยกเลิก">
                        <i class="fas fa-times" aria-hidden="true"></i>
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
    <div class="px-4 sm:px-6 py-4 border-t border-white/10 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between min-w-0">
        <p class="text-white/60 text-sm min-w-0">
            แสดง <?php echo $offset + 1; ?> - <?php echo min($offset + $limit, $totalRecords); ?> 
            จาก <?php echo $totalRecords; ?> รายการ
        </p>
        <div class="flex flex-wrap gap-2 justify-center sm:justify-end">
            <?php if ($page > 1): ?>
            <a href="?year=<?php echo $year; ?>&type=<?php echo $type; ?>&status=<?php echo urlencode($status); ?>&page=<?php echo $page - 1; ?>" 
               aria-label="หน้าก่อนหน้า"
               class="touch-manipulation min-h-[48px] min-w-[48px] inline-flex items-center justify-center px-3 py-2 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] transition-colors">
                <i class="fas fa-chevron-left" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="?year=<?php echo $year; ?>&type=<?php echo $type; ?>&status=<?php echo urlencode($status); ?>&page=<?php echo $i; ?>" 
               class="touch-manipulation min-h-[56px] min-w-[48px] inline-flex items-center justify-center px-3 py-2 <?php echo $i === $page ? 'bg-violet-600 text-white' : 'bg-white/10 hover:bg-white/20 text-white'; ?> rounded-[var(--tp-ios-card-radius)] transition-colors font-medium">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?year=<?php echo $year; ?>&type=<?php echo $type; ?>&status=<?php echo urlencode($status); ?>&page=<?php echo $page + 1; ?>" 
               aria-label="หน้าถัดไป"
               class="touch-manipulation min-h-[48px] min-w-[48px] inline-flex items-center justify-center px-3 py-2 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] transition-colors">
                <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
</div>

<!-- Detail Modal -->
<div id="detail-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden flex items-center justify-center p-5 overflow-y-auto overscroll-contain">
    <div class="native-card tp-native-card w-full max-w-lg my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden p-6 pb-[calc(env(safe-area-inset-bottom,0px)+1.5rem)]">
        <div class="flex items-center justify-between mb-6 gap-2">
            <h3 class="text-xl font-bold text-white">รายละเอียดคำขอลา</h3>
            <button type="button" onclick="closeModal()" class="min-h-[48px] min-w-[48px] inline-flex items-center justify-center text-white/60 hover:text-white hover:bg-white/10 rounded-[var(--tp-ios-card-radius)] touch-manipulation" aria-label="ปิด">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div id="detail-content">
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-2xl text-white/30" aria-hidden="true"></i>
            </div>
        </div>
    </div>
</div>

<script>
async function viewDetail(id) {
    const modal = document.getElementById('detail-modal');
    const content = document.getElementById('detail-content');
    
    if (typeof uiOpenModal === 'function') uiOpenModal('detail-modal');
    else modal.classList.remove('hidden');
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
    if (typeof uiCloseModal === 'function') uiCloseModal('detail-modal');
    else document.getElementById('detail-modal').classList.add('hidden');
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
            showToast('success', 'สำเร็จ', 'ยกเลิกคำขอลาเรียบร้อยแล้ว');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('error', 'ผิดพลาด', result.error || 'เกิดข้อผิดพลาด');
        }
    } catch (err) {
        console.error(err);
        showToast('error', 'ผิดพลาด', 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
    }
}

// Close modal on click outside
document.getElementById('detail-modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php include 'templates/footer.php'; ?>
