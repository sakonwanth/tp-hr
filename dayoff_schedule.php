<?php
/**
 * Weekly Day-Off Schedule
 * ตารางวันหยุดประจำสัปดาห์ - พนักงานดู/ขอเปลี่ยน, CEO อนุมัติ
 */

$page_title = 'วันหยุดประจำสัปดาห์';
require_once __DIR__ . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();
$pdo = Database::getInstance()->getConnection();
$current_page = 'dayoff';

$dayNames = THAI_DAY_NAMES;
$dayNamesShort = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
$dayNamesGrid = ['จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.']; // Mon-first for grid

// Get employee's default day off
$stmtSched = $pdo->prepare("SELECT day_off FROM hr_employee_schedules WHERE user_id = ?");
$stmtSched->execute([$user['id']]);
$schedRow = $stmtSched->fetch();
$defaultDayOff = $schedRow ? (int)$schedRow['day_off'] : 0; // 0=Sunday

// Month filter
$month = $_GET['month'] ?? date('Y-m');
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

// Calculate weeks in this month
$weeks = [];
$d = new DateTime($monthStart);
$endDt = new DateTime($monthEnd);

// Find the Monday of the first week that contains any day of this month
$firstDow = (int)$d->format('w'); // 0=Sun
$mondayOfFirstWeek = clone $d;
if ($firstDow === 0) {
    $mondayOfFirstWeek->modify('-6 days');
} elseif ($firstDow !== 1) {
    $mondayOfFirstWeek->modify('-' . ($firstDow - 1) . ' days');
}

$cursor = clone $mondayOfFirstWeek;
$weekNum = 1;
while (true) {
    $wStart = clone $cursor;
    $wEnd = clone $cursor;
    $wEnd->modify('+6 days');
    
    // Only include weeks that overlap with this month
    if ($wStart->format('Y-m') > $month && $wEnd->format('Y-m') > $month) break;
    
    if ($wEnd >= $d) { // Week overlaps with month
        $weeks[] = [
            'num' => $weekNum,
            'start' => $wStart->format('Y-m-d'),
            'end' => $wEnd->format('Y-m-d'),
            'start_label' => (int)$wStart->format('d') . ' ' . $dayNamesShort[(int)$wStart->format('w')],
            'end_label' => (int)$wEnd->format('d') . ' ' . $dayNamesShort[(int)$wEnd->format('w')],
        ];
        $weekNum++;
    }
    
    $cursor->modify('+7 days');
    if ($cursor > $endDt && $cursor->format('N') == 1) break;
}

// Get existing requests for this month's weeks
$weekStarts = array_map(fn($w) => $w['start'], $weeks);
if ($weekStarts) {
    $placeholders = implode(',', array_fill(0, count($weekStarts), '?'));
    $stmtReqs = $pdo->prepare("
        SELECT * FROM hr_dayoff_requests 
        WHERE user_id = ? AND week_start IN ($placeholders)
        ORDER BY week_start
    ");
    $stmtReqs->execute(array_merge([$user['id']], $weekStarts));
    $requests = [];
    foreach ($stmtReqs->fetchAll() as $r) {
        $requests[$r['week_start']] = $r;
    }
} else {
    $requests = [];
}

// Get holidays in this month
$stmtHol = $pdo->prepare("SELECT date, name, type FROM hr_holidays WHERE DATE_FORMAT(date, '%Y-%m') = ? AND is_active = 1");
$stmtHol->execute([$month]);
$holidays = [];
foreach ($stmtHol->fetchAll() as $h) {
    $holidays[$h['date']] = $h;
}

// Handle POST (submit request)
$success = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'CSRF token ไม่ถูกต้อง';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'request_change') {
            $weekStart = $_POST['week_start'] ?? '';
            $weekEnd = $_POST['week_end'] ?? '';
            $requestedDay = (int)($_POST['requested_day_off'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            
            // Validate
            if (!$weekStart || !$weekEnd) {
                $error = 'ข้อมูลสัปดาห์ไม่ถูกต้อง';
            } elseif ($requestedDay === $defaultDayOff) {
                $error = 'วันที่เลือกเป็นวันหยุดเริ่มต้นอยู่แล้ว';
            } elseif ($requestedDay < 0 || $requestedDay > 6) {
                $error = 'วันที่เลือกไม่ถูกต้อง';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO hr_dayoff_requests (user_id, week_start, week_end, original_day_off, requested_day_off, reason)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE requested_day_off = VALUES(requested_day_off), reason = VALUES(reason), 
                        status = 'PENDING', reviewed_by = NULL, reviewed_at = NULL, review_note = NULL
                    ");
                    $stmt->execute([$user['id'], $weekStart, $weekEnd, $defaultDayOff, $requestedDay, $reason ?: null]);
                    $success = 'ส่งคำขอเปลี่ยนวันหยุดเรียบร้อยแล้ว รอการอนุมัติจากผู้บริหาร';
                    
                    // Reload requests
                    header("Location: dayoff_schedule.php?month={$month}&success=1");
                    exit;
                } catch (Exception $e) {
                    $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'cancel_request') {
            $requestId = (int)($_POST['request_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM hr_dayoff_requests WHERE id = ? AND user_id = ? AND status = 'PENDING'");
            $stmt->execute([$requestId, $user['id']]);
            header("Location: dayoff_schedule.php?month={$month}");
            exit;
        }
    }
}

if (isset($_GET['success'])) {
    $success = 'ส่งคำขอเปลี่ยนวันหยุดเรียบร้อยแล้ว รอการอนุมัติจากผู้บริหาร';
}

// Month options
$monthOptions = [];
for ($i = -1; $i < 6; $i++) {
    $d = date('Y-m', strtotime("+$i months"));
    $monthOptions[] = ['value' => $d, 'label' => formatDateThai($d . '-01')];
}

include __DIR__ . '/templates/header.php';
?>

<div class="mb-6">
    <nav class="text-sm text-white/60 mb-1">
        <a href="checkin.php" class="hover:text-white">ลงเวลา</a>
        <span class="mx-2">/</span>
        <span class="text-white">วันหยุดประจำสัปดาห์</span>
    </nav>
    <h1 class="text-2xl font-bold text-white">วันหยุดประจำสัปดาห์</h1>
    <p class="text-white/60 text-sm mt-1">
        วันหยุดเริ่มต้น: <span class="text-blue-400 font-medium"><?php echo $dayNames[$defaultDayOff]; ?></span>
        — สามารถขอเปลี่ยนวันหยุดแต่ละสัปดาห์ได้ โดยรอผู้บริหารอนุมัติ
    </p>
</div>

<?php if ($success): ?>
<div class="glass-card rounded-xl p-4 mb-4 border-l-4 border-green-500">
    <p class="text-green-400"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?></p>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="glass-card rounded-xl p-4 mb-4 border-l-4 border-red-500">
    <p class="text-red-400"><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<!-- Month Filter -->
<div class="glass-card rounded-xl p-4 mb-6">
    <form method="GET" class="flex items-center gap-4">
        <label class="text-white/70 text-sm">เดือน:</label>
        <select name="month" class="input-field w-auto" onchange="this.form.submit()">
            <?php foreach ($monthOptions as $opt): ?>
            <option value="<?php echo $opt['value']; ?>" <?php echo $month === $opt['value'] ? 'selected' : ''; ?>>
                <?php echo $opt['label']; ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<!-- Weekly Schedule -->
<div class="space-y-4">
    <?php foreach ($weeks as $week): 
        $req = $requests[$week['start']] ?? null;
        $effectiveDayOff = $defaultDayOff;
        $statusBadge = '';
        
        if ($req) {
            if ($req['status'] === 'APPROVED') {
                $effectiveDayOff = (int)$req['requested_day_off'];
                $statusBadge = '<span class="px-2 py-0.5 text-xs rounded-full bg-green-500/20 text-green-400">อนุมัติแล้ว</span>';
            } elseif ($req['status'] === 'PENDING') {
                $statusBadge = '<span class="px-2 py-0.5 text-xs rounded-full bg-yellow-500/20 text-yellow-400">รออนุมัติ</span>';
            } elseif ($req['status'] === 'REJECTED') {
                $statusBadge = '<span class="px-2 py-0.5 text-xs rounded-full bg-red-500/20 text-red-400">ไม่อนุมัติ</span>';
            }
        }
        
        // Build days of this week
        $weekDays = [];
        $wCursor = new DateTime($week['start']);
        for ($i = 0; $i < 7; $i++) {
            $dateStr = $wCursor->format('Y-m-d');
            $dow = (int)$wCursor->format('w');
            $inMonth = ($wCursor->format('Y-m') === $month);
            $isHoliday = $holidays[$dateStr] ?? null;
            $isEffectiveDayOff = ($dow === $effectiveDayOff);
            $isPendingDayOff = ($req && $req['status'] === 'PENDING' && $dow === (int)$req['requested_day_off']);
            
            $weekDays[] = [
                'date' => $dateStr,
                'dow' => $dow,
                'day' => (int)$wCursor->format('d'),
                'in_month' => $inMonth,
                'is_holiday' => $isHoliday,
                'is_day_off' => $isEffectiveDayOff,
                'is_pending_day_off' => $isPendingDayOff,
            ];
            $wCursor->modify('+1 day');
        }
    ?>
    <div class="glass-card rounded-xl p-4">
        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-3">
                <h3 class="text-white font-medium">
                    สัปดาห์ที่ <?php echo $week['num']; ?>
                    <span class="text-white/50 text-sm font-normal ml-2">
                        <?php echo formatDateThai($week['start']); ?> - <?php echo formatDateThai($week['end']); ?>
                    </span>
                </h3>
                <?php echo $statusBadge; ?>
            </div>
            
            <?php if (!$req || $req['status'] === 'REJECTED'): ?>
            <button onclick="openChangeModal('<?php echo $week['start']; ?>', '<?php echo $week['end']; ?>', <?php echo $week['num']; ?>)" 
                    class="px-3 py-1.5 bg-violet-600 hover:bg-violet-700 text-white text-xs rounded-lg transition-colors">
                <i class="fas fa-exchange-alt mr-1"></i>ขอเปลี่ยนวันหยุด
            </button>
            <?php elseif ($req && $req['status'] === 'PENDING'): ?>
            <form method="POST" class="inline">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="cancel_request">
                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                <button type="submit" class="px-3 py-1.5 bg-red-500/20 hover:bg-red-500/30 text-red-400 text-xs rounded-lg transition-colors"
                        onclick="return confirm('ยกเลิกคำขอเปลี่ยนวันหยุดสัปดาห์นี้?')">
                    <i class="fas fa-times mr-1"></i>ยกเลิกคำขอ
                </button>
            </form>
            <?php endif; ?>
        </div>
        
        <!-- Week Calendar Grid -->
        <div class="grid grid-cols-7 gap-1">
            <?php foreach ($dayNamesGrid as $dn): ?>
            <div class="text-center text-white/40 text-xs py-1"><?php echo $dn; ?></div>
            <?php endforeach; ?>
            
            <?php foreach ($weekDays as $wd): 
                $cellClass = 'rounded-lg p-2 text-center text-sm ';
                if (!$wd['in_month']) {
                    $cellClass .= 'opacity-30 bg-white/5 text-white/50';
                } elseif ($wd['is_holiday']) {
                    $cellClass .= 'bg-orange-500/20 text-orange-400 ring-1 ring-orange-500/30';
                } elseif ($wd['is_day_off']) {
                    $cellClass .= 'bg-blue-500/20 text-blue-400 ring-1 ring-blue-500/30';
                } elseif ($wd['is_pending_day_off']) {
                    $cellClass .= 'bg-yellow-500/20 text-yellow-400 ring-1 ring-yellow-500/30 animate-pulse';
                } else {
                    $cellClass .= 'bg-white/5 text-white/70';
                }
            ?>
            <div class="<?php echo $cellClass; ?>">
                <div class="font-medium"><?php echo $wd['day']; ?></div>
                <?php if ($wd['is_holiday']): ?>
                <div class="text-[10px] truncate mt-0.5"><?php echo htmlspecialchars($wd['is_holiday']['name']); ?></div>
                <?php elseif ($wd['is_day_off']): ?>
                <div class="text-[10px] mt-0.5">หยุด</div>
                <?php elseif ($wd['is_pending_day_off']): ?>
                <div class="text-[10px] mt-0.5">รออนุมัติ</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php if ($req && $req['status'] !== 'PENDING'): ?>
        <div class="mt-2 text-xs text-white/50">
            <?php if ($req['status'] === 'APPROVED'): ?>
            <i class="fas fa-check text-green-400 mr-1"></i>
            เปลี่ยนจาก <?php echo $dayNames[(int)$req['original_day_off']]; ?> → <?php echo $dayNames[(int)$req['requested_day_off']]; ?>
            <?php elseif ($req['status'] === 'REJECTED'): ?>
            <i class="fas fa-times text-red-400 mr-1"></i>
            ขอเปลี่ยนเป็น <?php echo $dayNames[(int)$req['requested_day_off']]; ?> — ไม่อนุมัติ
            <?php if ($req['review_note']): ?>
            <span class="text-red-400/70">(<?php echo htmlspecialchars($req['review_note']); ?>)</span>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php elseif ($req && $req['status'] === 'PENDING'): ?>
        <div class="mt-2 text-xs text-yellow-400/70">
            <i class="fas fa-clock mr-1"></i>
            ขอเปลี่ยนเป็น <?php echo $dayNames[(int)$req['requested_day_off']]; ?>
            <?php if ($req['reason']): ?>
            — <?php echo htmlspecialchars($req['reason']); ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Legend -->
<div class="glass-card rounded-xl p-4 mt-6">
    <div class="flex flex-wrap gap-4 text-xs text-white/60">
        <span><span class="inline-block w-3 h-3 rounded bg-blue-500/30 mr-1"></span> วันหยุดประจำสัปดาห์</span>
        <span><span class="inline-block w-3 h-3 rounded bg-orange-500/30 mr-1"></span> วันหยุดราชการ/เทศกาล</span>
        <span><span class="inline-block w-3 h-3 rounded bg-yellow-500/30 mr-1 animate-pulse"></span> รออนุมัติเปลี่ยน</span>
        <span><span class="inline-block w-3 h-3 rounded bg-white/10 mr-1"></span> วันทำงาน</span>
    </div>
</div>

<!-- Change Day-Off Modal -->
<div id="change-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 overflow-y-auto overscroll-contain">
    <div class="glass-card rounded-2xl w-full max-w-md my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden pb-[calc(env(safe-area-inset-bottom,0px)+1rem)]">
        <form method="POST" class="p-6">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="request_change">
            <input type="hidden" name="week_start" id="modal-week-start">
            <input type="hidden" name="week_end" id="modal-week-end">
            
            <h3 class="text-xl font-bold text-white mb-1">ขอเปลี่ยนวันหยุด</h3>
            <p class="text-white/50 text-sm mb-4" id="modal-week-label"></p>
            
            <div class="mb-4">
                <label class="block text-white/70 text-sm mb-1">วันหยุดเดิม</label>
                <div class="input-field bg-white/5 cursor-not-allowed text-white/50">
                    <?php echo $dayNames[$defaultDayOff]; ?>
                </div>
            </div>
            
            <div class="mb-4">
                <label class="block text-white/70 text-sm mb-1">เปลี่ยนเป็นวัน <span class="text-red-400">*</span></label>
                <select name="requested_day_off" class="input-field" required>
                    <?php foreach ($dayNames as $idx => $name): ?>
                    <?php if ($idx !== $defaultDayOff): ?>
                    <option value="<?php echo $idx; ?>"><?php echo $name; ?></option>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="mb-6">
                <label class="block text-white/70 text-sm mb-1">เหตุผล</label>
                <input type="text" name="reason" class="input-field" placeholder="เช่น ต้องไปธุระวันอาทิตย์">
            </div>
            
            <div class="flex gap-3">
                <button type="button" onclick="closeChangeModal()" class="flex-1 py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-lg transition-colors">
                    ยกเลิก
                </button>
                <button type="submit" class="flex-1 py-2.5 bg-violet-600 hover:bg-violet-700 text-white rounded-lg transition-colors">
                    <i class="fas fa-paper-plane mr-2"></i>ส่งคำขอ
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openChangeModal(weekStart, weekEnd, weekNum) {
    document.getElementById('modal-week-start').value = weekStart;
    document.getElementById('modal-week-end').value = weekEnd;
    document.getElementById('modal-week-label').textContent = 'สัปดาห์ที่ ' + weekNum + ' (' + weekStart + ' - ' + weekEnd + ')';
    if (typeof uiOpenModal === 'function') uiOpenModal('change-modal');
    else document.getElementById('change-modal').classList.remove('hidden');
}

function closeChangeModal() {
    if (typeof uiCloseModal === 'function') uiCloseModal('change-modal');
    else document.getElementById('change-modal').classList.add('hidden');
}

document.getElementById('change-modal').addEventListener('click', function(e) {
    if (e.target === this) closeChangeModal();
});
</script>

<?php include __DIR__ . '/templates/footer.php'; ?>
