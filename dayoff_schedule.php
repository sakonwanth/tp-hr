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
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="checkin.php" class="hover:text-white touch-manipulation inline-flex min-h-[44px] items-center">ลงเวลา</a>
        <span class="mx-2">/</span>
        <span class="text-white">วันหยุดประจำสัปดาห์</span>
    </nav>
    <h1 class="text-2xl sm:text-3xl font-bold text-white leading-tight">วันหยุดประจำสัปดาห์</h1>
    <div class="mt-3 max-w-3xl space-y-1.5 text-sm text-white/65">
        <p class="text-base text-white/90">
            วันหยุดเริ่มต้น: <span class="font-semibold text-violet-200">วัน<?php echo htmlspecialchars($dayNames[$defaultDayOff]); ?></span>
        </p>
        <p>ขอเปลี่ยนวันหยุดรายสัปดาห์ได้ รอผู้บริหารอนุมัติ</p>
    </div>
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
    <form method="GET" class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
        <label for="dayoff-month-select" class="text-white/70 text-sm font-medium shrink-0">เดือน</label>
        <select id="dayoff-month-select" name="month" class="input-field w-full sm:w-auto sm:min-w-[12rem] touch-manipulation" onchange="this.form.submit()">
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
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-3">
            <div class="flex flex-wrap items-center gap-3 min-w-0">
                <h3 class="text-white font-medium">
                    สัปดาห์ที่ <?php echo $week['num']; ?>
                    <span class="text-white/50 text-sm font-normal ml-2">
                        <?php echo formatDateThai($week['start']); ?> - <?php echo formatDateThai($week['end']); ?>
                    </span>
                </h3>
                <?php echo $statusBadge; ?>
            </div>
            
            <?php if (!$req || $req['status'] === 'REJECTED'): ?>
            <button type="button" onclick="openChangeModal('<?php echo $week['start']; ?>', '<?php echo $week['end']; ?>', <?php echo $week['num']; ?>)" 
                    class="btn-primary shrink-0 px-4 text-xs sm:text-sm self-start sm:self-auto touch-manipulation">
                <i class="fas fa-exchange-alt mr-1"></i>ขอเปลี่ยนวันหยุด
            </button>
            <?php elseif ($req && $req['status'] === 'PENDING'): ?>
            <form method="POST" class="inline shrink-0 self-start sm:self-auto">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="cancel_request">
                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                <button type="submit" class="min-h-[44px] px-3 py-2 bg-red-500/20 hover:bg-red-500/30 text-red-400 text-xs sm:text-sm rounded-lg transition-colors touch-manipulation"
                        onclick="return confirm('ยกเลิกคำขอเปลี่ยนวันหยุดสัปดาห์นี้?')">
                    <i class="fas fa-times mr-1"></i>ยกเลิกคำขอ
                </button>
            </form>
            <?php endif; ?>
        </div>
        
        <?php
        // Mobile: รายการ 7 วัน — ข้อความยาว (เช่น วันสงกรานต์) ไม่ล้นซ้อนช่อง
        ?>
        <div class="mt-3 space-y-2 sm:hidden">
            <?php foreach ($weekDays as $wd):
                $dowShort = $dayNamesShort[$wd['dow']] ?? '';
                $sub = '';
                if (!$wd['in_month']) {
                    $rowCls = 'border-white/10 bg-white/5 text-white/45';
                    $sub = 'นอกเดือนที่เลือก';
                } elseif ($wd['is_holiday']) {
                    $rowCls = 'border-orange-400/35 bg-orange-500/15 text-orange-100';
                    $sub = htmlspecialchars($wd['is_holiday']['name']);
                } elseif ($wd['is_day_off']) {
                    $rowCls = 'border-violet-400/35 bg-violet-500/10 text-violet-100';
                    $sub = 'วันหยุดประจำ';
                } elseif ($wd['is_pending_day_off']) {
                    $rowCls = 'border-yellow-400/35 bg-yellow-500/15 text-yellow-100 animate-pulse';
                    $sub = 'รออนุมัติเปลี่ยนวันหยุด';
                } else {
                    $rowCls = 'border-white/10 bg-white/5 text-white/80';
                    $sub = 'วันทำงาน';
                }
            ?>
            <div class="flex items-start gap-3 rounded-xl border px-3 py-3 <?php echo $rowCls; ?>">
                <div class="shrink-0 pt-0.5 text-sm font-semibold tabular-nums">
                    <span class="text-white/70"><?php echo htmlspecialchars($dowShort); ?></span>
                    <span class="ml-1 text-white"><?php echo (int)$wd['day']; ?></span>
                </div>
                <div class="min-w-0 flex-1 break-words text-sm leading-snug"><?php echo $sub; ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- sm+: ตาราง 7 ช่อง — เว้นระยะมากขึ้น + ห่อข้อความในช่อง -->
        <div class="hidden min-w-0 overflow-x-auto pb-1 sm:block [-webkit-overflow-scrolling:touch] overscroll-x-contain">
            <div class="grid grid-cols-7 gap-2 min-w-0">
            <?php foreach ($dayNamesGrid as $dn): ?>
            <div class="text-center text-white/45 text-xs font-medium py-1"><?php echo $dn; ?></div>
            <?php endforeach; ?>
            
            <?php foreach ($weekDays as $wd): 
                $cellClass = 'rounded-xl px-1.5 py-2 text-center text-sm min-h-[4.5rem] flex flex-col items-center justify-start gap-1 touch-manipulation ';
                if (!$wd['in_month']) {
                    $cellClass .= 'opacity-35 bg-white/5 text-white/50';
                } elseif ($wd['is_holiday']) {
                    $cellClass .= 'bg-orange-500/20 text-orange-200 ring-1 ring-orange-500/35';
                } elseif ($wd['is_day_off']) {
                    $cellClass .= 'bg-violet-500/15 text-violet-100 ring-1 ring-violet-500/35';
                } elseif ($wd['is_pending_day_off']) {
                    $cellClass .= 'bg-yellow-500/20 text-yellow-200 ring-1 ring-yellow-500/35 animate-pulse';
                } else {
                    $cellClass .= 'bg-white/5 text-white/75';
                }
            ?>
            <div class="<?php echo $cellClass; ?>">
                <div class="font-semibold tabular-nums"><?php echo $wd['day']; ?></div>
                <?php if ($wd['is_holiday']): ?>
                <div class="w-full px-0.5 text-center text-[10px] font-medium leading-tight text-orange-100/95 line-clamp-3 break-words"><?php echo htmlspecialchars($wd['is_holiday']['name']); ?></div>
                <?php elseif ($wd['is_day_off']): ?>
                <div class="text-[10px] font-medium text-violet-200/90">หยุด</div>
                <?php elseif ($wd['is_pending_day_off']): ?>
                <div class="text-[10px] font-medium text-yellow-200/90">รออนุมัติ</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>
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
    <p class="text-white/50 text-xs font-medium mb-3">คำอธิบายสี</p>
    <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:gap-x-6 sm:gap-y-2 text-xs text-white/65">
        <span class="inline-flex items-center gap-2 min-h-[44px] sm:min-h-0"><span class="inline-block w-3 h-3 shrink-0 rounded bg-violet-500/35 ring-1 ring-violet-500/40" aria-hidden="true"></span> วันหยุดประจำสัปดาห์</span>
        <span class="inline-flex items-center gap-2 min-h-[44px] sm:min-h-0"><span class="inline-block w-3 h-3 shrink-0 rounded bg-orange-500/30" aria-hidden="true"></span> วันหยุดราชการ/เทศกาล</span>
        <span class="inline-flex items-center gap-2 min-h-[44px] sm:min-h-0"><span class="inline-block w-3 h-3 shrink-0 rounded bg-yellow-500/30 animate-pulse" aria-hidden="true"></span> รออนุมัติเปลี่ยน</span>
        <span class="inline-flex items-center gap-2 min-h-[44px] sm:min-h-0"><span class="inline-block w-3 h-3 shrink-0 rounded bg-white/10" aria-hidden="true"></span> วันทำงาน</span>
    </div>
</div>

<!-- Change Day-Off Modal -->
<div id="change-modal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4 overflow-y-auto overscroll-contain bg-black/50 backdrop-blur-sm pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]">
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
            
            <div class="flex flex-col-reverse gap-3 sm:flex-row">
                <button type="button" onclick="closeChangeModal()" class="btn-secondary flex-1 justify-center touch-manipulation">
                    ยกเลิก
                </button>
                <button type="submit" class="btn-primary flex-1 justify-center touch-manipulation">
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
