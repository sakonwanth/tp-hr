<?php
/**
 * HR Attendance Management
 * จัดการการเข้างาน - สำหรับ HR
 */

$page_title = 'จัดการเวลาทำงาน';
require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();

if (!isHR()) {
    redirect('/tp-hr/', 302);
}

$pdo = Database::getInstance()->getConnection();

// Filters
$date = $_GET['date'] ?? date('Y-m-d');
$department = $_GET['department'] ?? '';
$status = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 30;
$offset = ($page - 1) * $limit;

// Get departments
$stmtDepts = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department");
$departments = $stmtDepts->fetchAll(PDO::FETCH_COLUMN);

// Get all employees with attendance
$sql = "
    SELECT u.id, u.first_name_th, u.last_name_th, u.employee_code, u.department,
           a.check_in_time, a.check_out_time, a.status, a.late_minutes, 
           a.early_leave_minutes, a.overtime_minutes, a.work_hours, a.id as attendance_id,
           a.check_in_photo, a.location_in_lat, a.location_in_lng
    FROM users u
    LEFT JOIN hr_attendances a ON u.id = a.user_id AND a.attendance_date = ?
    WHERE u.is_active = 1
";
$params = [$date];

if ($department) {
    $sql .= " AND u.department = ?";
    $params[] = $department;
}

if ($status === 'PRESENT') {
    $sql .= " AND a.id IS NOT NULL";
} elseif ($status === 'ABSENT') {
    $sql .= " AND a.id IS NULL";
} elseif ($status === 'LATE') {
    $sql .= " AND a.late_minutes > 0";
}

// Count
$countSql = "SELECT COUNT(*) FROM (" . str_replace("u.id, u.first_name_th, u.last_name_th, u.employee_code, u.department,\n           a.check_in_time, a.check_out_time, a.status, a.late_minutes, \n           a.early_leave_minutes, a.overtime_minutes, a.work_hours, a.id as attendance_id,\n           a.check_in_photo, a.location_in_lat, a.location_in_lng", "1", $sql) . ") t";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$sql .= " ORDER BY a.check_in_time DESC, u.first_name_th ASC LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Daily stats
$stmtStats = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM users WHERE is_active = 1) as total_employees,
        COUNT(a.id) as checked_in,
        SUM(CASE WHEN a.late_minutes > 0 THEN 1 ELSE 0 END) as late_count,
        SUM(CASE WHEN a.check_out_time IS NOT NULL THEN 1 ELSE 0 END) as checked_out,
        SEC_TO_TIME(AVG(TIME_TO_SEC(a.check_in_time))) as avg_check_in
    FROM hr_attendances a
    WHERE a.attendance_date = ?
");
$stmtStats->execute([$date]);
$stats = $stmtStats->fetch();

$absentCount = $stats['total_employees'] - $stats['checked_in'];

include dirname(__DIR__) . '/templates/header.php';
?>

<div class="mb-6">
    <nav class="text-sm text-white/60 mb-1">
        <a href="/tp-hr/hr/" class="hover:text-white">HR</a>
        <span class="mx-2">/</span>
        <span class="text-white">จัดการเวลาทำงาน</span>
    </nav>
    <h1 class="text-2xl font-bold text-white">จัดการเวลาทำงาน</h1>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <a href="?date=<?php echo $date; ?>&status=" 
       class="glass-card rounded-xl p-4 <?php echo !$status ? 'ring-2 ring-violet-400' : ''; ?>">
        <p class="text-white/50 text-sm">พนักงานทั้งหมด</p>
        <p class="text-2xl font-bold text-violet-400"><?php echo $stats['total_employees']; ?></p>
    </a>
    <a href="?date=<?php echo $date; ?>&status=PRESENT"
       class="glass-card rounded-xl p-4 <?php echo $status === 'PRESENT' ? 'ring-2 ring-green-400' : ''; ?>">
        <p class="text-white/50 text-sm">เข้างาน</p>
        <p class="text-2xl font-bold text-green-400"><?php echo $stats['checked_in'] ?? 0; ?></p>
    </a>
    <a href="?date=<?php echo $date; ?>&status=ABSENT"
       class="glass-card rounded-xl p-4 <?php echo $status === 'ABSENT' ? 'ring-2 ring-red-400' : ''; ?>">
        <p class="text-white/50 text-sm">ขาดงาน</p>
        <p class="text-2xl font-bold text-red-400"><?php echo $absentCount; ?></p>
    </a>
    <a href="?date=<?php echo $date; ?>&status=LATE"
       class="glass-card rounded-xl p-4 <?php echo $status === 'LATE' ? 'ring-2 ring-yellow-400' : ''; ?>">
        <p class="text-white/50 text-sm">สาย</p>
        <p class="text-2xl font-bold text-yellow-400"><?php echo $stats['late_count'] ?? 0; ?></p>
    </a>
    <div class="glass-card rounded-xl p-4">
        <p class="text-white/50 text-sm">เวลาเข้างานเฉลี่ย</p>
        <p class="text-2xl font-bold text-white"><?php echo $stats['avg_check_in'] ? substr($stats['avg_check_in'], 0, 5) : '--:--'; ?></p>
    </div>
</div>

<!-- Filters -->
<div class="glass-card rounded-xl p-4 mb-6">
    <form method="GET" class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div>
            <label class="block text-white/60 text-xs mb-1">วันที่</label>
            <input type="date" name="date" value="<?php echo $date; ?>" class="input-field" onchange="this.form.submit()">
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
        <div class="flex items-end gap-2">
            <a href="?date=<?php echo date('Y-m-d'); ?>" class="flex-1 py-2.5 bg-white/10 hover:bg-white/20 text-white text-center rounded-lg transition-colors">วันนี้</a>
            <a href="?date=<?php echo date('Y-m-d', strtotime('-1 day', strtotime($date))); ?>" class="px-3 py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-lg">
                <i class="fas fa-chevron-left"></i>
            </a>
            <a href="?date=<?php echo date('Y-m-d', strtotime('+1 day', strtotime($date))); ?>" class="px-3 py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-lg">
                <i class="fas fa-chevron-right"></i>
            </a>
        </div>
        <div class="flex items-end gap-2">
            <a href="attendance.php?action=report&month=<?php echo date('Y-m', strtotime($date)); ?>" class="flex-1 py-2.5 bg-violet-600 hover:bg-violet-700 text-white text-center rounded-lg transition-colors">
                <i class="fas fa-file-export mr-2"></i>รายงาน
            </a>
        </div>
    </form>
</div>

<!-- Title -->
<div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-semibold text-white"><?php echo formatDateThai($date); ?></h2>
</div>

<!-- List -->
<div class="glass-card rounded-xl overflow-hidden">
    <?php if (empty($records)): ?>
    <div class="p-12 text-center">
        <i class="fas fa-users text-4xl text-white/20 mb-4"></i>
        <p class="text-white/60">ไม่พบข้อมูล</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-white/5">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">พนักงาน</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">เข้างาน</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ออกงาน</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ชั่วโมงทำงาน</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">หมายเหตุ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($records as $rec): ?>
                <?php
                $hasAttendance = !empty($rec['attendance_id']);
                $isLate = ($rec['late_minutes'] ?? 0) > 0;
                $isEarlyLeave = ($rec['early_leave_minutes'] ?? 0) > 0;
                ?>
                <tr class="hover:bg-white/5">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <?php if ($rec['check_in_photo']): ?>
                            <img src="<?php echo htmlspecialchars($rec['check_in_photo']); ?>" class="w-10 h-10 rounded-full object-cover">
                            <?php else: ?>
                            <div class="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center text-white/50">
                                <i class="fas fa-user"></i>
                            </div>
                            <?php endif; ?>
                            <div>
                                <p class="text-white font-medium"><?php echo htmlspecialchars($rec['first_name_th'] . ' ' . $rec['last_name_th']); ?></p>
                                <p class="text-white/50 text-xs"><?php echo htmlspecialchars($rec['employee_code'] ?? ''); ?> | <?php echo htmlspecialchars($rec['department'] ?? '-'); ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($rec['check_in_time']): ?>
                        <span class="text-white font-medium"><?php echo date('H:i', strtotime($rec['check_in_time'])); ?></span>
                        <?php else: ?>
                        <span class="text-white/40">--:--</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($rec['check_out_time']): ?>
                        <span class="text-white font-medium"><?php echo date('H:i', strtotime($rec['check_out_time'])); ?></span>
                        <?php else: ?>
                        <span class="text-white/40">--:--</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($rec['work_hours']): ?>
                        <span class="text-white"><?php echo number_format($rec['work_hours'], 1); ?> ชม.</span>
                        <?php if ($rec['overtime_minutes'] > 0): ?>
                        <span class="text-green-400 text-xs ml-1">(+<?php echo floor($rec['overtime_minutes']/60); ?>h)</span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-white/40">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if (!$hasAttendance): ?>
                        <span class="px-3 py-1 rounded-full text-xs bg-red-500/20 text-red-400">ขาดงาน</span>
                        <?php elseif ($isLate): ?>
                        <span class="px-3 py-1 rounded-full text-xs bg-yellow-500/20 text-yellow-400">มาสาย</span>
                        <?php else: ?>
                        <span class="px-3 py-1 rounded-full text-xs bg-green-500/20 text-green-400">ปกติ</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-white/60 text-sm">
                        <?php
                        $notes = [];
                        if ($isLate) $notes[] = 'สาย ' . $rec['late_minutes'] . ' นาที';
                        if ($isEarlyLeave) $notes[] = 'ออกก่อน ' . $rec['early_leave_minutes'] . ' นาที';
                        echo implode(', ', $notes) ?: '-';
                        ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($hasAttendance && $rec['location_in_lat']): ?>
                        <button onclick="viewLocation(<?php echo $rec['location_in_lat']; ?>, <?php echo $rec['location_in_lng']; ?>)" 
                                class="px-2 py-1 bg-white/10 hover:bg-white/20 text-white text-xs rounded transition-colors mr-1" title="ดูตำแหน่ง">
                            <i class="fas fa-map-marker-alt"></i>
                        </button>
                        <?php endif; ?>
                        <button onclick="editAttendance(<?php echo $rec['id']; ?>, '<?php echo $date; ?>', <?php echo $rec['attendance_id'] ?? 'null'; ?>)" 
                                class="px-2 py-1 bg-white/10 hover:bg-white/20 text-white text-xs rounded transition-colors" title="แก้ไข">
                            <i class="fas fa-edit"></i>
                        </button>
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
            หน้า <?php echo $page; ?> จาก <?php echo $totalPages; ?>
        </p>
        <div class="flex gap-2">
            <?php if ($page > 1): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="px-3 py-1 bg-white/10 hover:bg-white/20 text-white rounded">
                <i class="fas fa-chevron-left"></i>
            </a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="px-3 py-1 bg-white/10 hover:bg-white/20 text-white rounded">
                <i class="fas fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Edit Modal -->
<div id="edit-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl w-full max-w-md">
        <form id="edit-form" class="p-6">
            <h3 class="text-xl font-bold text-white mb-4">แก้ไขเวลาทำงาน</h3>
            <input type="hidden" name="user_id" id="edit-user-id">
            <input type="hidden" name="attendance_date" id="edit-date">
            <input type="hidden" name="attendance_id" id="edit-attendance-id">
            
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-white/80 text-sm mb-2">เวลาเข้างาน</label>
                    <input type="time" name="check_in_time" id="edit-check-in" class="input-field">
                </div>
                <div>
                    <label class="block text-white/80 text-sm mb-2">เวลาออกงาน</label>
                    <input type="time" name="check_out_time" id="edit-check-out" class="input-field">
                </div>
            </div>
            
            <div class="mb-4">
                <label class="block text-white/80 text-sm mb-2">หมายเหตุ</label>
                <textarea name="note" id="edit-note" rows="2" class="input-field" placeholder="เหตุผลที่แก้ไข"></textarea>
            </div>
            
            <div class="flex gap-4">
                <button type="button" onclick="closeEditModal()" class="flex-1 py-2 bg-white/10 hover:bg-white/20 text-white rounded-lg">ยกเลิก</button>
                <button type="submit" class="flex-1 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<!-- Location Modal -->
<div id="location-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl w-full max-w-lg">
        <div class="p-4">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-white">ตำแหน่ง Check-in</h3>
                <button onclick="closeLocationModal()" class="p-2 text-white/60 hover:text-white hover:bg-white/10 rounded-lg">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="map" class="w-full h-80 rounded-lg bg-white/10"></div>
        </div>
    </div>
</div>

<script>
function editAttendance(userId, date, attendanceId) {
    document.getElementById('edit-user-id').value = userId;
    document.getElementById('edit-date').value = date;
    document.getElementById('edit-attendance-id').value = attendanceId || '';
    document.getElementById('edit-check-in').value = '';
    document.getElementById('edit-check-out').value = '';
    document.getElementById('edit-note').value = '';
    document.getElementById('edit-modal').classList.remove('hidden');
}

function closeEditModal() {
    document.getElementById('edit-modal').classList.add('hidden');
}

document.getElementById('edit-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData();
    formData.append('action', 'adjust');
    formData.append('user_id', document.getElementById('edit-user-id').value);
    formData.append('attendance_date', document.getElementById('edit-date').value);
    formData.append('attendance_id', document.getElementById('edit-attendance-id').value);
    formData.append('check_in_time', document.getElementById('edit-check-in').value);
    formData.append('check_out_time', document.getElementById('edit-check-out').value);
    formData.append('note', document.getElementById('edit-note').value);
    formData.append('_token', '<?php echo csrfToken(); ?>');
    
    const response = await fetch('/tp-hr/api/attendance.php', { method: 'POST', body: formData });
    const result = await response.json();
    
    if (result.success) {
        showToast('บันทึกสำเร็จ', 'success');
        setTimeout(() => location.reload(), 1000);
    } else {
        showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
    }
});

let map;
function viewLocation(lat, lng) {
    document.getElementById('location-modal').classList.remove('hidden');
    
    if (typeof google !== 'undefined') {
        setTimeout(() => {
            if (!map) {
                map = new google.maps.Map(document.getElementById('map'), {
                    zoom: 17,
                    center: { lat, lng },
                    styles: [
                        { featureType: 'all', elementType: 'all', stylers: [{ saturation: -100 }, { gamma: 1.5 }] }
                    ]
                });
            } else {
                map.setCenter({ lat, lng });
            }
            new google.maps.Marker({ position: { lat, lng }, map: map });
        }, 100);
    }
}

function closeLocationModal() {
    document.getElementById('location-modal').classList.add('hidden');
}

document.getElementById('edit-modal').addEventListener('click', e => { if (e.target === document.getElementById('edit-modal')) closeEditModal(); });
document.getElementById('location-modal').addEventListener('click', e => { if (e.target === document.getElementById('location-modal')) closeLocationModal(); });
</script>

<script async defer src="https://maps.googleapis.com/maps/api/js?key="></script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
