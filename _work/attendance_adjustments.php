<?php
/**
 * TP-HR Attendance Adjustment Request
 * คำขอแก้ไขเวลาเข้า-ออกงาน (พนักงาน)
 */

require_once __DIR__ . '/bootstrap.php';
Auth::requireLogin();

$pdo = getDB();
$user = Auth::user();
$page_title = 'ขอแก้ไขเวลาเข้า-ออกงาน';
$current_page = 'attendance-adjustments';

// Recent attendance records for request form
$stmt = $pdo->prepare("
    SELECT id, attendance_date, check_in_time, check_out_time, status
    FROM hr_attendances
    WHERE user_id = ?
      AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
    ORDER BY attendance_date DESC
");
$stmt->execute([$user['id']]);
$attendances = $stmt->fetchAll();

// My adjustment requests
$stmt = $pdo->prepare("
    SELECT
        aar.*,
        a.attendance_date,
        CONCAT(rev.first_name_th, ' ', rev.last_name_th) AS reviewer_name
    FROM hr_attendance_adjustments aar
    JOIN hr_attendances a ON a.id = aar.attendance_id
    LEFT JOIN users rev ON rev.id = aar.reviewed_by
    WHERE aar.user_id = ?
    ORDER BY aar.created_at DESC
    LIMIT 100
");
$stmt->execute([$user['id']]);
$requests = $stmt->fetchAll();

require_once __DIR__ . '/templates/header.php';
?>

<main class="content-area p-6">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <nav class="text-sm text-white/60 mb-1">
                <a href="checkin.php" class="hover:text-white">ลงเวลา</a>
                <span class="mx-2">/</span>
                <span class="text-white">คำขอแก้ไขเวลา</span>
            </nav>
            <h1 class="text-2xl font-bold text-white">คำขอแก้ไขเวลาเข้า-ออกงาน</h1>
            <p class="text-white/60 text-sm mt-1">ส่งคำขอเพื่อให้ผู้มีสิทธิ์อนุมัติแก้ไขเวลาเข้างานหรือออกงาน</p>
        </div>
        <button type="button" onclick="openRequestModal()" class="btn-primary">
            <i class="fas fa-pen-to-square mr-2"></i>ส่งคำขอใหม่
        </button>
    </div>

    <div class="glass-card rounded-xl p-4 mb-6">
        <h2 class="text-white font-semibold mb-3">วิธีการทำงาน</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
            <div class="p-3 rounded-lg bg-white/5 border border-white/10 text-white/80">
                <i class="fas fa-1 text-violet-400 mr-2"></i>เลือกวันที่ต้องการแก้ไข
            </div>
            <div class="p-3 rounded-lg bg-white/5 border border-white/10 text-white/80">
                <i class="fas fa-2 text-violet-400 mr-2"></i>ระบุเวลาใหม่และเหตุผล
            </div>
            <div class="p-3 rounded-lg bg-white/5 border border-white/10 text-white/80">
                <i class="fas fa-3 text-violet-400 mr-2"></i>รอผู้อนุมัติพิจารณา
            </div>
        </div>
    </div>

    <div class="glass-card rounded-xl overflow-hidden mb-6">
        <div class="p-4 border-b border-white/10 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-white">ข้อมูลลงเวลาย้อนหลัง 60 วัน</h2>
            <span class="text-white/50 text-sm"><?php echo count($attendances); ?> รายการ</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-white/5">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">วันที่</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">เข้างาน</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ออกงาน</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ดำเนินการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    <?php if ($attendances): ?>
                    <?php foreach ($attendances as $att): ?>
                    <tr class="hover:bg-white/5">
                        <td class="px-4 py-3 text-white"><?php echo formatDateThai($att['attendance_date']); ?></td>
                        <td class="px-4 py-3 text-center text-green-400"><?php echo $att['check_in_time'] ? date('H:i', strtotime($att['check_in_time'])) : '--:--'; ?></td>
                        <td class="px-4 py-3 text-center text-blue-400"><?php echo $att['check_out_time'] ? date('H:i', strtotime($att['check_out_time'])) : '--:--'; ?></td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-1 text-xs rounded <?php
                                echo match($att['status']) {
                                    'PRESENT' => 'bg-green-500/20 text-green-400',
                                    'LATE' => 'bg-yellow-500/20 text-yellow-400',
                                    'ABSENT' => 'bg-red-500/20 text-red-400',
                                    'LEAVE' => 'bg-blue-500/20 text-blue-400',
                                    default => 'bg-gray-500/20 text-gray-400'
                                };
                            ?>">
                                <?php echo ATTENDANCE_STATUS[$att['status']] ?? $att['status']; ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button
                                type="button"
                                class="px-3 py-1.5 bg-violet-600 hover:bg-violet-700 text-white text-xs rounded-lg transition-colors"
                                onclick="openRequestModal(<?php echo (int)$att['id']; ?>, '<?php echo $att['attendance_date']; ?>', '<?php echo $att['check_in_time'] ? date('H:i', strtotime($att['check_in_time'])) : ''; ?>', '<?php echo $att['check_out_time'] ? date('H:i', strtotime($att['check_out_time'])) : ''; ?>')"
                            >
                                <i class="fas fa-pen mr-1"></i>ขอแก้ไข
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-white/50">ยังไม่มีข้อมูลลงเวลา</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="glass-card rounded-xl overflow-hidden">
        <div class="p-4 border-b border-white/10 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-white">ประวัติคำขอแก้ไขเวลา</h2>
            <span class="text-white/50 text-sm"><?php echo count($requests); ?> รายการ</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-white/5">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">วันที่ทำงาน</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">เวลาเดิม</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">เวลาที่ขอแก้ไข</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">เหตุผล</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ผู้พิจารณา</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    <?php if ($requests): ?>
                    <?php foreach ($requests as $req): ?>
                    <tr class="hover:bg-white/5">
                        <td class="px-4 py-3 text-white"><?php echo formatDateThai($req['attendance_date']); ?></td>
                        <td class="px-4 py-3 text-center text-white/80 text-sm">
                            <?php
                            $origIn = $req['original_check_in'] ? date('H:i', strtotime($req['original_check_in'])) : '--:--';
                            $origOut = $req['original_check_out'] ? date('H:i', strtotime($req['original_check_out'])) : '--:--';
                            echo $origIn . ' - ' . $origOut;
                            ?>
                        </td>
                        <td class="px-4 py-3 text-center text-white text-sm">
                            <?php
                            $newIn = $req['requested_check_in'] ? date('H:i', strtotime($req['requested_check_in'])) : '--:--';
                            $newOut = $req['requested_check_out'] ? date('H:i', strtotime($req['requested_check_out'])) : '--:--';
                            echo $newIn . ' - ' . $newOut;
                            ?>
                        </td>
                        <td class="px-4 py-3 text-white/80 text-sm"><?php echo htmlspecialchars($req['reason']); ?></td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-1 text-xs rounded <?php
                                echo match($req['status']) {
                                    'PENDING' => 'bg-yellow-500/20 text-yellow-400',
                                    'APPROVED' => 'bg-green-500/20 text-green-400',
                                    'REJECTED' => 'bg-red-500/20 text-red-400',
                                    'CANCELLED' => 'bg-gray-500/20 text-gray-400',
                                    default => 'bg-gray-500/20 text-gray-400'
                                };
                            ?>">
                                <?php
                                echo match($req['status']) {
                                    'PENDING' => 'รออนุมัติ',
                                    'APPROVED' => 'อนุมัติแล้ว',
                                    'REJECTED' => 'ไม่อนุมัติ',
                                    'CANCELLED' => 'ยกเลิก',
                                    default => $req['status']
                                };
                                ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-white/70 text-sm">
                            <?php if ($req['reviewer_name']): ?>
                                <?php echo htmlspecialchars($req['reviewer_name']); ?>
                                <div class="text-white/40 text-xs"><?php echo $req['reviewed_at'] ? formatDateThai($req['reviewed_at'], true) : ''; ?></div>
                                <?php if (!empty($req['review_remarks'])): ?>
                                <div class="text-white/50 text-xs mt-1">หมายเหตุ: <?php echo htmlspecialchars($req['review_remarks']); ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-white/40">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-white/50">ยังไม่มีคำขอแก้ไขเวลา</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<div id="request-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl w-full max-w-xl p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-xl font-bold text-white">ส่งคำขอแก้ไขเวลา</h3>
            <button type="button" onclick="closeRequestModal()" class="text-white/60 hover:text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="request-form" class="space-y-4">
            <input type="hidden" id="attendance-id" name="attendance_id" value="">
            <input type="hidden" name="_token" value="<?php echo csrfToken(); ?>">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-white/70 text-sm mb-1">วันที่</label>
                    <input type="text" id="attendance-date" class="input-field" readonly>
                </div>
                <div>
                    <label class="block text-white/70 text-sm mb-1">เวลาเดิม</label>
                    <input type="text" id="current-time-range" class="input-field" readonly>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-white/70 text-sm mb-1">เวลาเข้างาน (ใหม่)</label>
                    <input type="time" id="requested-check-in" name="requested_check_in" class="input-field">
                </div>
                <div>
                    <label class="block text-white/70 text-sm mb-1">เวลาออกงาน (ใหม่)</label>
                    <input type="time" id="requested-check-out" name="requested_check_out" class="input-field">
                </div>
            </div>

            <div>
                <label class="block text-white/70 text-sm mb-1">เหตุผล</label>
                <textarea id="request-reason" name="reason" rows="3" class="input-field" placeholder="ระบุเหตุผลในการขอแก้ไขเวลา" required></textarea>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeRequestModal()" class="flex-1 py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-lg transition-colors">ยกเลิก</button>
                <button type="submit" id="btn-submit-request" class="flex-1 py-2.5 bg-violet-600 hover:bg-violet-700 text-white rounded-lg transition-colors">ส่งคำขอ</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRequestModal(attendanceId = null, attendanceDate = '', checkIn = '', checkOut = '') {
    if (!attendanceId) {
        showToast('กรุณาเลือกวันที่จากตารางก่อนส่งคำขอ', 'error');
        return;
    }

    document.getElementById('attendance-id').value = attendanceId;
    document.getElementById('attendance-date').value = attendanceDate;
    document.getElementById('current-time-range').value = (checkIn || '--:--') + ' - ' + (checkOut || '--:--');
    document.getElementById('requested-check-in').value = checkIn || '';
    document.getElementById('requested-check-out').value = checkOut || '';
    document.getElementById('request-reason').value = '';

    document.getElementById('request-modal').classList.remove('hidden');
}

function closeRequestModal() {
    document.getElementById('request-modal').classList.add('hidden');
}

document.getElementById('request-form').addEventListener('submit', async function (e) {
    e.preventDefault();

    const btn = document.getElementById('btn-submit-request');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังส่งคำขอ...';

    try {
        const formData = new FormData(this);
        formData.append('action', 'request_adjustment');

        const response = await fetch('/api/attendance.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            showToast(result.message || 'ส่งคำขอเรียบร้อย', 'success');
            setTimeout(() => location.reload(), 900);
        } else {
            showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
            btn.disabled = false;
            btn.innerHTML = 'ส่งคำขอ';
        }
    } catch (error) {
        showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
        btn.disabled = false;
        btn.innerHTML = 'ส่งคำขอ';
    }
});

document.getElementById('request-modal').addEventListener('click', function (e) {
    if (e.target === this) {
        closeRequestModal();
    }
});
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
