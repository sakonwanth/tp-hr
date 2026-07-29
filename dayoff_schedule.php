<?php
/**
 * Weekly Day-Off Schedule
 * ตารางวันหยุดประจำสัปดาห์ - พนักงานดู/ขอเปลี่ยน, CEO อนุมัติ
 */

$page_title = 'วันหยุดประจำสัปดาห์';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/core/CrmLineNotifierBridge.php';

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
            
            $validWeeks = [];
            foreach ($weeks as $listedWeek) {
                $validWeeks[$listedWeek['start']] = $listedWeek['end'];
            }

            // Validate against the weeks rendered for the selected month. Do not trust hidden dates.
            if (!$weekStart || !$weekEnd || !isset($validWeeks[$weekStart]) || $validWeeks[$weekStart] !== $weekEnd) {
                $error = 'ข้อมูลสัปดาห์ไม่ถูกต้อง';
            } elseif ($requestedDay < 0 || $requestedDay > 6) {
                $error = 'วันที่เลือกไม่ถูกต้อง';
            } else {
                try {
                    $pdo->beginTransaction();
                    $existingStmt = $pdo->prepare("
                        SELECT * FROM hr_dayoff_requests
                        WHERE user_id = ? AND week_start = ?
                        LIMIT 1 FOR UPDATE
                    ");
                    $existingStmt->execute([$user['id'], $weekStart]);
                    $existingRequest = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                    if ($existingRequest && $existingRequest['status'] === 'PENDING') {
                        throw new RuntimeException('DAYOFF_REQUEST_ALREADY_PENDING');
                    }
                    if ($requestedDay === $defaultDayOff) {
                        if (!$existingRequest || $existingRequest['status'] !== 'APPROVED') {
                            throw new RuntimeException('DAYOFF_REQUEST_ALREADY_DEFAULT');
                        }

                        $restoreStmt = $pdo->prepare("
                            UPDATE hr_dayoff_requests
                            SET status = 'CANCELLED'
                            WHERE id = ? AND user_id = ? AND status = 'APPROVED'
                        ");
                        $restoreStmt->execute([(int)$existingRequest['id'], (int)$user['id']]);
                        if ($restoreStmt->rowCount() !== 1) {
                            throw new RuntimeException('DAYOFF_REQUEST_STATE_CHANGED');
                        }

                        Auth::log(
                            'dayoff_request_cancel',
                            'hr_dayoff_requests',
                            (int)$existingRequest['id'],
                            $existingRequest,
                            [
                                'status' => 'CANCELLED',
                                'restored_day_off' => $defaultDayOff,
                            ]
                        );
                        $pdo->commit();
                        if (function_exists('crm_line_notify_dayoff_cancelled')) {
                            crm_line_notify_dayoff_cancelled($pdo, (int)$existingRequest['id']);
                        }
                        header("Location: dayoff_schedule.php?month={$month}&restored=1");
                        exit;
                    }
                    if ($existingRequest
                        && $existingRequest['status'] === 'APPROVED'
                        && (int)$existingRequest['requested_day_off'] === $requestedDay) {
                        throw new RuntimeException('DAYOFF_REQUEST_UNCHANGED');
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO hr_dayoff_requests (user_id, week_start, week_end, original_day_off, requested_day_off, reason)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE requested_day_off = VALUES(requested_day_off), reason = VALUES(reason), 
                        status = 'PENDING', reviewed_by = NULL, reviewed_at = NULL, review_note = NULL
                    ");
                    $stmt->execute([$user['id'], $weekStart, $weekEnd, $defaultDayOff, $requestedDay, $reason ?: null]);
                    $requestId = (int)$pdo->lastInsertId();
                    if ($requestId <= 0) {
                        $idStmt = $pdo->prepare("SELECT id FROM hr_dayoff_requests WHERE user_id = ? AND week_start = ? LIMIT 1");
                        $idStmt->execute([$user['id'], $weekStart]);
                        $requestId = (int)$idStmt->fetchColumn();
                    }
                    Auth::log(
                        $existingRequest ? 'dayoff_request_resubmit' : 'dayoff_request_create',
                        'hr_dayoff_requests',
                        $requestId,
                        $existingRequest,
                        [
                            'user_id' => (int)$user['id'],
                            'week_start' => $weekStart,
                            'week_end' => $weekEnd,
                            'original_day_off' => $defaultDayOff,
                            'requested_day_off' => $requestedDay,
                            'reason' => $reason ?: null,
                            'status' => 'PENDING',
                        ]
                    );
                    $pdo->commit();
                    if ($requestId > 0 && function_exists('crm_line_notify_dayoff_requested')) {
                        crm_line_notify_dayoff_requested($pdo, $requestId);
                    }
                    $success = 'ส่งคำขอเปลี่ยนวันหยุดเรียบร้อยแล้ว รอการอนุมัติจากผู้บริหาร';
                    
                    // Reload requests
                    header("Location: dayoff_schedule.php?month={$month}&success=1");
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($e->getMessage() === 'DAYOFF_REQUEST_ALREADY_PENDING') {
                        $error = 'คำขอสัปดาห์นี้อยู่ระหว่างรออนุมัติแล้ว';
                    } elseif ($e->getMessage() === 'DAYOFF_REQUEST_ALREADY_DEFAULT') {
                        $error = 'วันที่เลือกเป็นวันหยุดประจำอยู่แล้ว';
                    } elseif ($e->getMessage() === 'DAYOFF_REQUEST_STATE_CHANGED') {
                        $error = 'สถานะคำขอมีการเปลี่ยนแปลง กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง';
                    } elseif ($e->getMessage() === 'DAYOFF_REQUEST_UNCHANGED') {
                        $error = 'กรุณาเลือกวันหยุดใหม่ที่ต่างจากวันที่อนุมัติอยู่';
                    } else {
                    tpHrLogException($e, 'dayoff_schedule request_change');
                    $error = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้งหรือติดต่อผู้ดูแลระบบ';
                    }
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
} elseif (isset($_GET['restored'])) {
    $success = 'ยกเลิกการสลับวันหยุดแล้ว สัปดาห์นี้กลับไปใช้วันหยุดประจำเดิมแล้ว';
}

// Month options
$monthOptions = [];
for ($i = -1; $i < 6; $i++) {
    $d = date('Y-m', strtotime("+$i months"));
    $monthOptions[] = ['value' => $d, 'label' => formatDateThai($d . '-01')];
}

include __DIR__ . '/templates/header.php';
?>

<div class="tp-dayoff-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
<header class="tp-ios-large-title-block mb-6 md:mb-8 max-w-3xl min-w-0">
    <nav class="mb-2 text-sm text-white/60" aria-label="Breadcrumb">
        <a href="index.php" class="inline-flex min-h-[48px] items-center hover:text-white touch-manipulation">หน้าแรก</a>
        <span class="mx-2">/</span>
        <a href="checkin.php" class="inline-flex min-h-[48px] items-center hover:text-white touch-manipulation">ลงเวลา</a>
        <span class="mx-2">/</span>
        <span class="text-white">วันหยุดประจำสัปดาห์</span>
    </nav>
    <h1 class="tp-ios-page-title">วันหยุดประจำสัปดาห์</h1>
    <div class="mt-2 space-y-2 text-sm">
        <p class="tp-ios-caption-muted">
            วันหยุดเริ่มต้น: <span class="font-semibold text-violet-200/95">วัน<?php echo htmlspecialchars($dayNames[$defaultDayOff]); ?></span>
        </p>
        <p class="tp-ios-caption-muted">ขอเปลี่ยนวันหยุดรายสัปดาห์ได้ รอผู้บริหารอนุมัติ</p>
    </div>
</header>

<?php if ($success): ?>
<div class="tp-native-success-state bg-emerald-500/15 border border-emerald-400/40 text-emerald-200 px-4 py-3 rounded-[var(--tp-ios-card-radius)] mb-6 flex items-start gap-3 max-w-none mx-0 w-full" role="status">
    <i class="fas fa-check-circle text-2xl shrink-0 mt-0.5" aria-hidden="true"></i>
    <span class="text-base leading-snug"><?php echo htmlspecialchars($success); ?></span>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="tp-native-error-state bg-red-500/15 border border-red-400/45 text-red-200 px-4 py-3 rounded-[var(--tp-ios-card-radius)] mb-6 flex items-start gap-3 max-w-none mx-0 w-full" role="alert">
    <i class="fas fa-exclamation-circle text-2xl shrink-0 mt-0.5" aria-hidden="true"></i>
    <span class="text-base leading-snug"><?php echo htmlspecialchars($error); ?></span>
</div>
<?php endif; ?>

<!-- Month Filter -->
<div class="native-card tp-native-card tp-native-data-card p-5 mb-6 min-w-0">
    <form method="GET" class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-6">
        <label for="dayoff-month-select" class="text-white/70 text-sm font-medium shrink-0">เดือน</label>
        <select id="dayoff-month-select" name="month" class="input-field tp-native-select w-full sm:w-auto sm:min-w-[12rem] touch-manipulation" onchange="this.form.submit()">
            <?php foreach ($monthOptions as $opt): ?>
            <option value="<?php echo $opt['value']; ?>" <?php echo $month === $opt['value'] ? 'selected' : ''; ?>>
                <?php echo $opt['label']; ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<!-- Weekly Schedule -->
<div class="space-y-6 min-w-0">
    <?php foreach ($weeks as $week): 
        $req = $requests[$week['start']] ?? null;
        $effectiveDayOff = $defaultDayOff;
        $statusBadge = '';
        
        if ($req && $req['status'] !== 'CANCELLED') {
            if ($req['status'] === 'APPROVED') {
                $effectiveDayOff = (int)$req['requested_day_off'];
                $statusBadge = '<span class="px-2.5 py-1 text-xs font-medium rounded-[var(--tp-ios-card-radius)] border border-white/10 bg-green-500/20 text-green-400">อนุมัติแล้ว</span>';
            } elseif ($req['status'] === 'PENDING') {
                $statusBadge = '<span class="px-2.5 py-1 text-xs font-medium rounded-[var(--tp-ios-card-radius)] border border-white/10 bg-amber-500/20 text-amber-300">รออนุมัติ</span>';
            } elseif ($req['status'] === 'REJECTED') {
                $statusBadge = '<span class="px-2.5 py-1 text-xs font-medium rounded-[var(--tp-ios-card-radius)] border border-white/10 bg-red-500/20 text-red-400">ไม่อนุมัติ</span>';
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
    <div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 min-w-0">
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
            
            <?php if (!$req || in_array($req['status'], ['REJECTED', 'CANCELLED'], true)): ?>
            <button type="button" onclick="openChangeModal('<?php echo $week['start']; ?>', '<?php echo $week['end']; ?>', <?php echo $week['num']; ?>)" 
                    class="btn-primary shrink-0 px-4 min-h-[48px] sm:min-h-[52px] rounded-[var(--tp-ios-card-radius)] text-xs sm:text-sm self-start sm:self-auto touch-manipulation border-0">
                <i class="fas fa-exchange-alt mr-1" aria-hidden="true"></i>ขอเปลี่ยนวันหยุด
            </button>
            <?php elseif ($req && $req['status'] === 'PENDING'): ?>
            <form method="POST" class="inline shrink-0 self-start sm:self-auto">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="cancel_request">
                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                <button type="submit" class="min-h-[48px] px-3 py-2 bg-red-500/15 hover:bg-red-500/25 border border-red-500/30 text-red-300 text-xs sm:text-sm rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation font-medium"
                        onclick="return confirm('ยกเลิกคำขอเปลี่ยนวันหยุดสัปดาห์นี้?')">
                    <i class="fas fa-times mr-1" aria-hidden="true"></i>ยกเลิกคำขอ
                </button>
            </form>
            <?php elseif ($req && $req['status'] === 'APPROVED'): ?>
            <button type="button"
                    onclick="openChangeModal('<?php echo $week['start']; ?>', '<?php echo $week['end']; ?>', <?php echo $week['num']; ?>, <?php echo (int)$req['requested_day_off']; ?>, true)"
                    class="min-h-[48px] px-4 py-2 bg-amber-500/15 hover:bg-amber-500/25 border border-amber-400/30 text-amber-200 text-xs sm:text-sm rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation font-medium shrink-0 self-start sm:self-auto">
                <i class="fas fa-pen mr-1" aria-hidden="true"></i>ขอแก้ไข
            </button>
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
                    $rowCls = 'border-amber-400/35 bg-amber-500/15 text-amber-100 animate-pulse';
                    $sub = 'รออนุมัติเปลี่ยนวันหยุด';
                } else {
                    $rowCls = 'border-white/10 bg-white/5 text-white/80';
                    $sub = 'วันทำงาน';
                }
            ?>
            <div class="flex items-start gap-3 rounded-[var(--tp-ios-card-radius)] border px-3 py-3 <?php echo $rowCls; ?>">
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
                $cellClass = 'rounded-[var(--tp-ios-card-radius)] px-1.5 py-2 text-center text-sm min-h-[4.5rem] flex flex-col items-center justify-start gap-1 touch-manipulation ';
                if (!$wd['in_month']) {
                    $cellClass .= 'opacity-35 bg-white/5 text-white/50';
                } elseif ($wd['is_holiday']) {
                    $cellClass .= 'bg-orange-500/20 text-orange-200 ring-1 ring-orange-500/35';
                } elseif ($wd['is_day_off']) {
                    $cellClass .= 'bg-violet-500/15 text-violet-100 ring-1 ring-violet-500/35';
                } elseif ($wd['is_pending_day_off']) {
                    $cellClass .= 'bg-amber-500/20 text-amber-200 ring-1 ring-amber-500/35 animate-pulse';
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
                <div class="text-[10px] font-medium text-amber-200/90">รออนุมัติ</div>
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
            <?php elseif ($req['status'] === 'CANCELLED'): ?>
            <i class="fas fa-rotate-left text-sky-400 mr-1"></i>
            ยกเลิกการสลับแล้ว — ใช้วันหยุดประจำ <?php echo $dayNames[$defaultDayOff]; ?>
            <?php endif; ?>
        </div>
        <?php elseif ($req && $req['status'] === 'PENDING'): ?>
        <div class="mt-2 text-xs text-amber-300/80">
            <i class="fas fa-clock mr-1" aria-hidden="true"></i>
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
<div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 mt-4 md:mt-6 min-w-0">
    <p class="text-sm font-semibold text-white mb-3 flex flex-wrap items-center gap-2">
        <i class="fas fa-palette text-violet-400 text-xl" aria-hidden="true"></i>
        คำอธิบายสี
    </p>
    <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:gap-x-6 sm:gap-y-2 text-xs text-white/65">
        <span class="inline-flex items-center gap-2 min-h-[48px] sm:min-h-0"><span class="inline-block w-3 h-3 shrink-0 rounded-[6px] bg-violet-500/35 ring-1 ring-violet-500/40" aria-hidden="true"></span> วันหยุดประจำสัปดาห์</span>
        <span class="inline-flex items-center gap-2 min-h-[48px] sm:min-h-0"><span class="inline-block w-3 h-3 shrink-0 rounded-[6px] bg-orange-500/30" aria-hidden="true"></span> วันหยุดราชการ/เทศกาล</span>
        <span class="inline-flex items-center gap-2 min-h-[48px] sm:min-h-0"><span class="inline-block w-3 h-3 shrink-0 rounded-[6px] bg-amber-500/30 animate-pulse" aria-hidden="true"></span> รออนุมัติเปลี่ยน</span>
        <span class="inline-flex items-center gap-2 min-h-[48px] sm:min-h-0"><span class="inline-block w-3 h-3 shrink-0 rounded-[6px] bg-white/10" aria-hidden="true"></span> วันทำงาน</span>
    </div>
</div>
</div>

<!-- Change Day-Off Modal -->
<div id="change-modal" class="tp-native-modal fixed inset-0 hidden flex items-center justify-center p-5 overflow-y-auto overscroll-contain bg-black/50 backdrop-blur-sm pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]">
    <div class="native-card tp-native-card w-full max-w-md my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden pb-[calc(env(safe-area-inset-bottom,0px)+1rem)]">
        <form method="POST" class="p-6">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="request_change">
            <input type="hidden" name="week_start" id="modal-week-start">
            <input type="hidden" name="week_end" id="modal-week-end">
            
            <h3 id="change-modal-title" class="text-xl font-bold text-white mb-1">ขอเปลี่ยนวันหยุด</h3>
            <p class="text-white/50 text-sm mb-4" id="modal-week-label"></p>
            <p id="change-modal-approved-note" class="hidden mb-4 rounded-[var(--tp-ios-card-radius)] border border-amber-400/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-100">
                เลือกวันหยุดเดิมเพื่อยกเลิกการสลับทันที หรือเลือกวันอื่นเพื่อส่งให้ผู้บริหารอนุมัติใหม่
            </p>
            
            <div class="tp-native-form-group">
                <label class="block text-white/70 text-sm mb-1">วันหยุดเดิม</label>
                <div class="input-field bg-white/5 cursor-not-allowed text-white/50 rounded-[var(--tp-ios-card-radius)]">
                    <?php echo $dayNames[$defaultDayOff]; ?>
                </div>
            </div>
            
            <div class="tp-native-form-group">
                <label class="block text-white/70 text-sm mb-1">เปลี่ยนเป็นวัน <span class="text-red-400">*</span></label>
                <select id="modal-requested-day-off" name="requested_day_off" class="input-field" required>
                    <?php foreach ($dayNames as $idx => $name): ?>
                    <option value="<?php echo $idx; ?>"
                            <?php echo $idx === $defaultDayOff ? 'data-default-day="1" disabled' : ''; ?>>
                        <?php echo $name; ?><?php echo $idx === $defaultDayOff ? ' (กลับไปวันหยุดเดิม)' : ''; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="tp-native-form-group">
                <label class="block text-white/70 text-sm mb-1">เหตุผล</label>
                <input type="text" name="reason" class="input-field" placeholder="เช่น ต้องไปธุระวันอาทิตย์">
            </div>
            
            <div class="flex flex-col-reverse gap-3 sm:flex-row pt-2">
                <button type="button" onclick="closeChangeModal()" class="flex-1 min-h-[52px] inline-flex items-center justify-center bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] font-semibold touch-manipulation border-0">
                    ยกเลิก
                </button>
                <button type="submit" class="flex-1 min-h-[56px] inline-flex items-center justify-center gap-2 bg-violet-600 hover:bg-violet-700 text-white rounded-[var(--tp-ios-card-radius)] font-semibold touch-manipulation border-0">
                    <i class="fas fa-paper-plane" aria-hidden="true"></i>ส่งคำขอ
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openChangeModal(weekStart, weekEnd, weekNum, requestedDayOff = null, isApprovedEdit = false) {
    document.getElementById('modal-week-start').value = weekStart;
    document.getElementById('modal-week-end').value = weekEnd;
    document.getElementById('modal-week-label').textContent = 'สัปดาห์ที่ ' + weekNum + ' (' + weekStart + ' - ' + weekEnd + ')';
    document.getElementById('change-modal-title').textContent = isApprovedEdit ? 'ขอแก้ไขวันหยุด' : 'ขอเปลี่ยนวันหยุด';
    document.getElementById('change-modal-approved-note').classList.toggle('hidden', !isApprovedEdit);
    const defaultDayOption = document.querySelector('#modal-requested-day-off option[data-default-day="1"]');
    if (defaultDayOption) {
        defaultDayOption.disabled = !isApprovedEdit;
    }
    if (requestedDayOff !== null) {
        document.getElementById('modal-requested-day-off').value = String(requestedDayOff);
    } else {
        const firstAvailableOption = document.querySelector('#modal-requested-day-off option:not(:disabled)');
        if (firstAvailableOption) {
            document.getElementById('modal-requested-day-off').value = firstAvailableOption.value;
        }
    }
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
