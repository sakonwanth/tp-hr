<?php
/**
 * HR Attendance Adjustment Approvals
 * หน้าอนุมัติคำขอแก้เวลาเข้า-ออกงาน
 */

require_once dirname(__DIR__) . '/bootstrap.php';
Auth::requireLogin();

if (!canApproveAttendanceAdjustments()) {
    flash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้าดังกล่าว');
    redirect('/hr/', 302);
}

$pdo = getDB();
$page_title = 'อนุมัติแก้เวลาเข้า-ออก';
$current_page = 'hr-attendance-adjustments';

$statusFilter = strtoupper(trim($_GET['status'] ?? 'PENDING'));
if (!in_array($statusFilter, ['PENDING', 'APPROVED', 'REJECTED', 'ALL'])) {
    $statusFilter = 'PENDING';
}

$logDate = trim($_GET['log_date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate)) {
    $logDate = date('Y-m-d');
}

$conditions = [];
$params = [];
if ($statusFilter !== 'ALL') {
    $conditions[] = 'aar.status = ?';
    $params[] = $statusFilter;
}
$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$stmt = $pdo->prepare("
    SELECT
        aar.*,
        a.attendance_date,
        CONCAT(req.first_name_th, ' ', req.last_name_th) AS requester_name,
        req.employee_code AS requester_code,
        req.department AS requester_department,
        CONCAT(rev.first_name_th, ' ', rev.last_name_th) AS reviewer_name
    FROM hr_attendance_adjustments aar
    JOIN hr_attendances a ON a.id = aar.attendance_id
    JOIN users req ON req.id = aar.user_id
    LEFT JOIN users rev ON rev.id = aar.reviewed_by
    $where
    ORDER BY
        CASE WHEN aar.status = 'PENDING' THEN 0 ELSE 1 END,
        aar.created_at DESC
    LIMIT 300
");
$stmt->execute($params);
$requests = $stmt->fetchAll();

$statsStmt = $pdo->query("
    SELECT
        SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) AS rejected_count
    FROM hr_attendance_adjustments
");
$stats = $statsStmt->fetch();

$outsideTableExists = false;
$outsideRequests = [];
$outsideStats = ['pending_count' => 0, 'approved_count' => 0, 'rejected_count' => 0];

$checkOutsideStmt = $pdo->query("SHOW TABLES LIKE 'hr_attendance_outside_requests'");
$outsideTableExists = (bool)$checkOutsideStmt->fetchColumn();

if ($outsideTableExists) {
    $outsideConditions = [];
    $outsideParams = [];
    if ($statusFilter !== 'ALL') {
        $outsideConditions[] = 'orr.status = ?';
        $outsideParams[] = $statusFilter;
    }
    $outsideWhere = $outsideConditions ? ('WHERE ' . implode(' AND ', $outsideConditions)) : '';

    $stmtOutside = $pdo->prepare("
        SELECT
            orr.*,
            CONCAT(req.first_name_th, ' ', req.last_name_th) AS requester_name,
            req.employee_code AS requester_code,
            req.department AS requester_department,
            CONCAT(rev.first_name_th, ' ', rev.last_name_th) AS reviewer_name
        FROM hr_attendance_outside_requests orr
        JOIN users req ON req.id = orr.user_id
        LEFT JOIN users rev ON rev.id = orr.reviewed_by
        $outsideWhere
        ORDER BY
            CASE WHEN orr.status = 'PENDING' THEN 0 ELSE 1 END,
            orr.created_at DESC
        LIMIT 300
    ");
    $stmtOutside->execute($outsideParams);
    $outsideRequests = $stmtOutside->fetchAll();

    $outsideStatsStmt = $pdo->query("
        SELECT
            SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) AS rejected_count
        FROM hr_attendance_outside_requests
    ");
    $outsideStats = $outsideStatsStmt->fetch() ?: $outsideStats;
}

$auditActions = [
    'CHECK_IN',
    'CHECK_OUT',
    'REQUEST_ADJUSTMENT',
    'ATTENDANCE_ADJUST',
    'ATTENDANCE_ADJUST_REVIEW',
    'OUTSIDE_LOCATION_REQUEST',
    'OUTSIDE_LOCATION_REVIEW',
];
$auditPlaceholders = implode(',', array_fill(0, count($auditActions), '?'));

$auditStmt = $pdo->prepare("\n    SELECT\n        al.id,\n        al.user_id,\n        al.action,\n        al.table_name,\n        al.record_id,\n        al.description,\n        al.ip_address,\n        al.created_at,\n        CONCAT(u.first_name_th, ' ', u.last_name_th) AS actor_name,\n        u.employee_code AS actor_code\n    FROM hr_audit_logs al\n    LEFT JOIN users u ON u.id = al.user_id\n    WHERE DATE(al.created_at) = ?\n      AND al.action IN ($auditPlaceholders)\n    ORDER BY al.created_at DESC\n    LIMIT 500\n");
$auditStmt->execute(array_merge([$logDate], $auditActions));
$dailyLogs = $auditStmt->fetchAll();

$auditCountStmt = $pdo->prepare("\n    SELECT action, COUNT(*) AS total\n    FROM hr_audit_logs\n    WHERE DATE(created_at) = ?\n      AND action IN ($auditPlaceholders)\n    GROUP BY action\n");
$auditCountStmt->execute(array_merge([$logDate], $auditActions));
$dailyLogCounts = [];
foreach ($auditCountStmt->fetchAll() as $row) {
    $dailyLogCounts[$row['action']] = (int)$row['total'];
}

require_once dirname(__DIR__) . '/templates/header.php';
?>

<main class="content-area p-6">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <nav class="text-sm text-white/60 mb-1">
                <a href="/hr/" class="hover:text-white">HR</a>
                <span class="mx-2">/</span>
                <span class="text-white">อนุมัติคำขอแก้เวลา</span>
            </nav>
            <h1 class="text-2xl font-bold text-white">อนุมัติคำขอแก้ไขเวลาเข้า-ออกงาน</h1>
            <p class="text-white/60 text-sm mt-1">อนุมัติหรือไม่อนุมัติคำขอแก้เวลา และบันทึกเหตุผลการพิจารณา</p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="glass-card rounded-xl p-4">
            <p class="text-white/50 text-sm">รออนุมัติ</p>
            <p class="text-3xl font-bold text-yellow-400"><?php echo (int)($stats['pending_count'] ?? 0); ?></p>
        </div>
        <div class="glass-card rounded-xl p-4">
            <p class="text-white/50 text-sm">อนุมัติแล้ว</p>
            <p class="text-3xl font-bold text-green-400"><?php echo (int)($stats['approved_count'] ?? 0); ?></p>
        </div>
        <div class="glass-card rounded-xl p-4">
            <p class="text-white/50 text-sm">ไม่อนุมัติ</p>
            <p class="text-3xl font-bold text-red-400"><?php echo (int)($stats['rejected_count'] ?? 0); ?></p>
        </div>
    </div>

    <?php if ($outsideTableExists): ?>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="glass-card rounded-xl p-4 border border-yellow-500/20">
            <p class="text-white/50 text-sm">นอกสถานที่รออนุมัติ</p>
            <p class="text-3xl font-bold text-yellow-400"><?php echo (int)($outsideStats['pending_count'] ?? 0); ?></p>
        </div>
        <div class="glass-card rounded-xl p-4 border border-green-500/20">
            <p class="text-white/50 text-sm">นอกสถานที่อนุมัติแล้ว</p>
            <p class="text-3xl font-bold text-green-400"><?php echo (int)($outsideStats['approved_count'] ?? 0); ?></p>
        </div>
        <div class="glass-card rounded-xl p-4 border border-red-500/20">
            <p class="text-white/50 text-sm">นอกสถานที่ไม่อนุมัติ</p>
            <p class="text-3xl font-bold text-red-400"><?php echo (int)($outsideStats['rejected_count'] ?? 0); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <div class="glass-card rounded-xl p-4 mb-6">
        <div class="flex flex-wrap gap-2">
            <?php
            $tabs = [
                'PENDING' => 'รออนุมัติ',
                'APPROVED' => 'อนุมัติแล้ว',
                'REJECTED' => 'ไม่อนุมัติ',
                'ALL' => 'ทั้งหมด',
            ];
            foreach ($tabs as $key => $label):
            ?>
            <a
                href="?status=<?php echo $key; ?>"
                class="px-4 py-2 rounded-lg text-sm transition-colors <?php echo $statusFilter === $key ? 'bg-violet-600 text-white' : 'bg-white/10 text-white/80 hover:bg-white/20'; ?>"
            >
                <?php echo $label; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="glass-card rounded-xl overflow-hidden mb-6">
        <div class="p-4 border-b border-white/10 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-white">Transaction Log รายวัน (Audit)</h2>
                <p class="text-white/60 text-sm">ติดตามเหตุการณ์ที่กระทบเวลาเข้า-ออก คำขอ และการอนุมัติแบบละเอียด</p>
            </div>
            <form method="GET" class="flex items-center gap-2">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                <input type="date" name="log_date" value="<?php echo htmlspecialchars($logDate); ?>" class="input-field py-2.5">
                <button type="submit" class="px-3 py-2.5 rounded-lg bg-white/10 hover:bg-white/20 text-white text-sm">ดูรายการ</button>
            </form>
        </div>

        <div class="px-4 py-3 border-b border-white/10 flex flex-wrap gap-2 text-xs">
            <?php foreach ($auditActions as $action): ?>
                <span class="px-2.5 py-1 rounded-full bg-white/10 text-white/80">
                    <?php echo htmlspecialchars($action); ?>: <?php echo (int)($dailyLogCounts[$action] ?? 0); ?>
                </span>
            <?php endforeach; ?>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-white/5">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">เวลา</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ผู้ทำรายการ</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">Action</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ตาราง/Record</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">รายละเอียด</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    <?php if ($dailyLogs): ?>
                    <?php foreach ($dailyLogs as $log): ?>
                    <tr class="hover:bg-white/5">
                        <td class="px-4 py-3 text-white/80 text-sm"><?php echo formatDateThai($log['created_at'], true); ?></td>
                        <td class="px-4 py-3 text-sm">
                            <div class="text-white"><?php echo htmlspecialchars(trim((string)$log['actor_name']) !== '' ? $log['actor_name'] : 'System'); ?></div>
                            <div class="text-white/50 text-xs"><?php echo htmlspecialchars($log['actor_code'] ?: '-'); ?></div>
                        </td>
                        <td class="px-4 py-3 text-sm text-blue-300"><?php echo htmlspecialchars($log['action']); ?></td>
                        <td class="px-4 py-3 text-sm text-white/70">
                            <div><?php echo htmlspecialchars($log['table_name'] ?: '-'); ?></div>
                            <div class="text-xs text-white/50">#<?php echo (int)($log['record_id'] ?? 0); ?></div>
                        </td>
                        <td class="px-4 py-3 text-sm text-white/80"><?php echo htmlspecialchars($log['description'] ?: '-'); ?></td>
                        <td class="px-4 py-3 text-sm text-white/70"><?php echo htmlspecialchars($log['ip_address'] ?: '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-white/50">ไม่พบ transaction log ของวันที่เลือก</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="glass-card rounded-xl overflow-hidden">
        <div class="p-4 border-b border-white/10">
            <h2 class="text-lg font-semibold text-white">คำขอแก้ไขเวลาเข้า-ออกงาน</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-white/5">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ผู้ขอ</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">วันที่ทำงาน</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">เวลาเดิม</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">เวลาที่ขอแก้</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">เหตุผล</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ดำเนินการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    <?php if ($requests): ?>
                    <?php foreach ($requests as $req): ?>
                    <?php
                    $origIn = $req['original_check_in'] ? date('H:i', strtotime($req['original_check_in'])) : '--:--';
                    $origOut = $req['original_check_out'] ? date('H:i', strtotime($req['original_check_out'])) : '--:--';
                    $newIn = $req['requested_check_in'] ? date('H:i', strtotime($req['requested_check_in'])) : '--:--';
                    $newOut = $req['requested_check_out'] ? date('H:i', strtotime($req['requested_check_out'])) : '--:--';
                    ?>
                    <tr class="hover:bg-white/5">
                        <td class="px-4 py-3">
                            <p class="text-white font-medium"><?php echo htmlspecialchars($req['requester_name']); ?></p>
                            <p class="text-white/50 text-xs"><?php echo htmlspecialchars(($req['requester_code'] ?? '-') . ' | ' . ($req['requester_department'] ?? '-')); ?></p>
                        </td>
                        <td class="px-4 py-3 text-white"><?php echo formatDateThai($req['attendance_date']); ?></td>
                        <td class="px-4 py-3 text-center text-white/70 text-sm"><?php echo $origIn . ' - ' . $origOut; ?></td>
                        <td class="px-4 py-3 text-center text-white text-sm"><?php echo $newIn . ' - ' . $newOut; ?></td>
                        <td class="px-4 py-3 text-white/80 text-sm">
                            <div><?php echo htmlspecialchars($req['reason']); ?></div>
                            <?php if (!empty($req['review_remarks'])): ?>
                            <div class="text-white/50 text-xs mt-1">ความเห็น: <?php echo htmlspecialchars($req['review_remarks']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-1 text-xs rounded <?php
                                echo match($req['status']) {
                                    'PENDING' => 'bg-yellow-500/20 text-yellow-400',
                                    'APPROVED' => 'bg-green-500/20 text-green-400',
                                    'REJECTED' => 'bg-red-500/20 text-red-400',
                                    default => 'bg-gray-500/20 text-gray-400'
                                };
                            ?>">
                                <?php
                                echo match($req['status']) {
                                    'PENDING' => 'รออนุมัติ',
                                    'APPROVED' => 'อนุมัติแล้ว',
                                    'REJECTED' => 'ไม่อนุมัติ',
                                    default => $req['status']
                                };
                                ?>
                            </span>
                            <?php if ($req['reviewer_name']): ?>
                            <div class="text-white/40 text-xs mt-1"><?php echo htmlspecialchars($req['reviewer_name']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($req['status'] === 'PENDING'): ?>
                            <div class="flex items-center justify-center gap-2">
                                <button
                                    type="button"
                                    class="px-2.5 py-1.5 rounded-lg bg-green-600 hover:bg-green-700 text-white text-xs"
                                    onclick="openReviewModal(<?php echo (int)$req['id']; ?>, 'APPROVED')"
                                >
                                    อนุมัติ
                                </button>
                                <button
                                    type="button"
                                    class="px-2.5 py-1.5 rounded-lg bg-red-500/20 hover:bg-red-500/30 text-red-400 text-xs"
                                    onclick="openReviewModal(<?php echo (int)$req['id']; ?>, 'REJECTED')"
                                >
                                    ไม่อนุมัติ
                                </button>
                            </div>
                            <?php else: ?>
                            <span class="text-white/40 text-xs">ดำเนินการแล้ว</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-white/50">ไม่พบคำขอตามเงื่อนไขที่เลือก</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($outsideTableExists): ?>
    <div class="glass-card rounded-xl overflow-hidden mt-6">
        <div class="p-4 border-b border-white/10">
            <h2 class="text-lg font-semibold text-white">คำขอลงเวลานอกสถานที่</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-white/5">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ผู้ขอ</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ประเภท</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">วันเวลา</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">เหตุผล</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ดำเนินการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    <?php if ($outsideRequests): ?>
                    <?php foreach ($outsideRequests as $req): ?>
                    <tr class="hover:bg-white/5">
                        <td class="px-4 py-3">
                            <p class="text-white font-medium"><?php echo htmlspecialchars($req['requester_name']); ?></p>
                            <p class="text-white/50 text-xs"><?php echo htmlspecialchars(($req['requester_code'] ?? '-') . ' | ' . ($req['requester_department'] ?? '-')); ?></p>
                        </td>
                        <td class="px-4 py-3 text-white/80 text-sm">
                            <?php echo $req['request_type'] === 'CHECK_IN' ? 'ลงเวลาเข้า' : 'ลงเวลาออก'; ?>
                        </td>
                        <td class="px-4 py-3 text-white text-sm">
                            <div><?php echo formatDateThai($req['request_date']); ?></div>
                            <div class="text-white/50 text-xs"><?php echo date('H:i', strtotime($req['request_time'])); ?></div>
                        </td>
                        <td class="px-4 py-3 text-white/80 text-sm">
                            <div><?php echo htmlspecialchars($req['reason']); ?></div>
                            <?php if (!empty($req['photo_path'])): ?>
                            <?php $photoUrl = '/' . ltrim((string)$req['photo_path'], '/'); ?>
                            <button
                                type="button"
                                class="mt-2 inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-blue-500/20 hover:bg-blue-500/30 text-blue-300 text-xs"
                                onclick='openEvidenceModal(<?php echo json_encode($photoUrl); ?>, <?php echo json_encode(($req['requester_name'] ?? '-') . ' | ' . formatDateThai($req['request_time'], true)); ?>)'
                            >
                                <i class="fas fa-image"></i>
                                ดูรูปหลักฐาน
                            </button>
                            <?php endif; ?>
                            <?php if (!empty($req['review_remarks'])): ?>
                            <div class="text-white/50 text-xs mt-1">ความเห็น: <?php echo htmlspecialchars($req['review_remarks']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-1 text-xs rounded <?php
                                echo match($req['status']) {
                                    'PENDING' => 'bg-yellow-500/20 text-yellow-400',
                                    'APPROVED' => 'bg-green-500/20 text-green-400',
                                    'REJECTED' => 'bg-red-500/20 text-red-400',
                                    default => 'bg-gray-500/20 text-gray-400'
                                };
                            ?>">
                                <?php
                                echo match($req['status']) {
                                    'PENDING' => 'รออนุมัติ',
                                    'APPROVED' => 'อนุมัติแล้ว',
                                    'REJECTED' => 'ไม่อนุมัติ',
                                    default => $req['status']
                                };
                                ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($req['status'] === 'PENDING'): ?>
                            <div class="flex items-center justify-center gap-2">
                                <button
                                    type="button"
                                    class="px-2.5 py-1.5 rounded-lg bg-green-600 hover:bg-green-700 text-white text-xs"
                                    onclick="openReviewModal(<?php echo (int)$req['id']; ?>, 'APPROVED', 'outside')"
                                >
                                    อนุมัติ
                                </button>
                                <button
                                    type="button"
                                    class="px-2.5 py-1.5 rounded-lg bg-red-500/20 hover:bg-red-500/30 text-red-400 text-xs"
                                    onclick="openReviewModal(<?php echo (int)$req['id']; ?>, 'REJECTED', 'outside')"
                                >
                                    ไม่อนุมัติ
                                </button>
                            </div>
                            <?php else: ?>
                            <span class="text-white/40 text-xs">ดำเนินการแล้ว</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-white/50">ไม่พบคำขอลงเวลานอกสถานที่</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</main>

<div id="review-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl w-full max-w-lg p-6">
        <h3 id="review-title" class="text-xl font-bold text-white mb-4">อนุมัติคำขอ</h3>

        <form id="review-form" class="space-y-4">
            <input type="hidden" name="request_id" id="review-request-id" value="">
            <input type="hidden" name="decision" id="review-decision" value="APPROVED">
            <input type="hidden" id="review-kind" value="adjustment">
            <input type="hidden" name="_token" value="<?php echo csrfToken(); ?>">

            <div>
                <label class="block text-white/70 text-sm mb-1">ความเห็นผู้พิจารณา</label>
                <textarea id="review-remarks" name="review_remarks" rows="3" class="input-field" placeholder="ระบุหมายเหตุเพิ่มเติม (สำหรับไม่อนุมัติควรระบุเหตุผล)"></textarea>
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="closeReviewModal()" class="flex-1 py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-lg transition-colors">ยกเลิก</button>
                <button type="submit" id="btn-submit-review" class="flex-1 py-2.5 bg-violet-600 hover:bg-violet-700 text-white rounded-lg transition-colors">ยืนยัน</button>
            </div>
        </form>
    </div>
</div>

<div id="evidence-modal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl w-full max-w-3xl p-4 md:p-5">
        <div class="flex items-center justify-between gap-3 mb-3">
            <div>
                <h3 class="text-lg md:text-xl font-bold text-white">รูปหลักฐานคำขอนอกสถานที่</h3>
                <p id="evidence-meta" class="text-white/60 text-xs md:text-sm"></p>
            </div>
            <button type="button" onclick="closeEvidenceModal()" class="w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="rounded-xl overflow-hidden border border-white/10 bg-black/30 min-h-[240px] flex items-center justify-center">
            <img id="evidence-image" src="" alt="Outside evidence" class="max-h-[70vh] w-auto object-contain" />
        </div>
        <div class="mt-3 text-right">
            <a id="evidence-open-link" href="#" target="_blank" rel="noopener" class="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm">
                <i class="fas fa-up-right-from-square"></i>
                เปิดภาพเต็ม
            </a>
        </div>
    </div>
</div>

<script>
function openReviewModal(requestId, decision, kind = 'adjustment') {
    document.getElementById('review-request-id').value = requestId;
    document.getElementById('review-decision').value = decision;
    document.getElementById('review-kind').value = kind;
    document.getElementById('review-remarks').value = '';

    const titlePrefix = kind === 'outside' ? 'คำขอลงเวลานอกสถานที่' : 'คำขอแก้ไขเวลา';

    if (decision === 'APPROVED') {
        document.getElementById('review-title').textContent = 'อนุมัติ' + titlePrefix;
        document.getElementById('btn-submit-review').textContent = 'อนุมัติ';
    } else {
        document.getElementById('review-title').textContent = 'ไม่อนุมัติ' + titlePrefix;
        document.getElementById('btn-submit-review').textContent = 'ไม่อนุมัติ';
    }

    document.getElementById('review-modal').classList.remove('hidden');
}

function closeReviewModal() {
    document.getElementById('review-modal').classList.add('hidden');
}

function openEvidenceModal(photoUrl, metaText = '') {
    const modal = document.getElementById('evidence-modal');
    const image = document.getElementById('evidence-image');
    const meta = document.getElementById('evidence-meta');
    const link = document.getElementById('evidence-open-link');

    image.src = photoUrl;
    meta.textContent = metaText;
    link.href = photoUrl;
    modal.classList.remove('hidden');
}

function closeEvidenceModal() {
    const modal = document.getElementById('evidence-modal');
    const image = document.getElementById('evidence-image');
    image.src = '';
    modal.classList.add('hidden');
}

document.getElementById('review-form').addEventListener('submit', async function (e) {
    e.preventDefault();

    const btn = document.getElementById('btn-submit-review');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังบันทึก...';

    try {
        const formData = new FormData(this);
        const kind = document.getElementById('review-kind').value;
        formData.append('action', kind === 'outside' ? 'review_outside_request' : 'review_adjustment');

        const response = await fetch('/api/attendance.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            showToast(result.message || 'บันทึกเรียบร้อย', 'success');
            setTimeout(() => location.reload(), 900);
        } else {
            showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
            btn.disabled = false;
            btn.textContent = 'ยืนยัน';
        }
    } catch (error) {
        showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
        btn.disabled = false;
        btn.textContent = 'ยืนยัน';
    }
});

document.getElementById('review-modal').addEventListener('click', function (e) {
    if (e.target === this) {
        closeReviewModal();
    }
});

document.getElementById('evidence-modal').addEventListener('click', function (e) {
    if (e.target === this) {
        closeEvidenceModal();
    }
});
</script>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
