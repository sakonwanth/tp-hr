<?php
/**
 * Holiday work + compensation day-off requests
 * ขอมาทำงานวันหยุดประจำปีและหยุดชดเชยวันอื่น
 */

$page_title = 'ทำงานวันหยุด / หยุดชดเชย';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/core/CrmLineNotifierBridge.php';

Auth::requireLogin();
$user = Auth::user();
$pdo = Database::getInstance()->getConnection();
$current_page = 'holiday-work';

$today = date('Y-m-d');
$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'CSRF token ไม่ถูกต้อง';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'request') {
            $holidayDate = trim($_POST['holiday_date'] ?? '');
            $compDate = trim($_POST['comp_date'] ?? '');
            $reason = trim($_POST['reason'] ?? '');

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate)) {
                $error = 'กรุณาเลือกวันหยุดที่ต้องมาทำงาน';
            } elseif ($reason === '') {
                $error = 'กรุณาระบุเหตุผล';
            } elseif ($compDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $compDate)) {
                $error = 'วันหยุดชดเชยไม่ถูกต้อง';
            } elseif ($compDate !== '' && $compDate === $holidayDate) {
                $error = 'วันหยุดชดเชยต้องไม่ตรงกับวันหยุดที่มาทำงาน';
            } else {
                $holStmt = $pdo->prepare('SELECT name FROM hr_holidays WHERE date = ? AND is_active = 1 LIMIT 1');
                $holStmt->execute([$holidayDate]);
                $holiday = $holStmt->fetch(PDO::FETCH_ASSOC);
                if (!$holiday) {
                    $error = 'วันที่เลือกไม่ใช่วันหยุดประจำปีของบริษัท';
                } elseif ($holidayDate < $today) {
                    $error = 'ไม่สามารถขอย้อนหลังสำหรับวันหยุดที่ผ่านมาแล้ว';
                } elseif ($compDate !== '') {
                    $maxComp = date('Y-m-d', strtotime($holidayDate . ' +90 days'));
                    $minComp = date('Y-m-d', strtotime($holidayDate . ' -90 days'));
                    if ($compDate < $minComp || $compDate > $maxComp) {
                        $error = 'วันหยุดชดเชยต้องอยู่ภายใน 90 วันก่อนหรือหลังวันหยุดที่มาทำงาน';
                    }
                }

                if ($error === '') {
                    try {
                        $compValue = $compDate !== '' ? $compDate : null;
                        $stmt = $pdo->prepare("
                            INSERT INTO hr_holiday_work_exceptions
                                (user_id, holiday_date, comp_date, holiday_name, reason, status)
                            VALUES (?, ?, ?, ?, ?, 'PENDING')
                            ON DUPLICATE KEY UPDATE
                                comp_date = VALUES(comp_date),
                                holiday_name = VALUES(holiday_name),
                                reason = VALUES(reason),
                                status = 'PENDING',
                                reviewed_by = NULL,
                                reviewed_at = NULL,
                                review_note = NULL
                        ");
                        $stmt->execute([
                            $user['id'],
                            $holidayDate,
                            $compValue,
                            $holiday['name'] ?? null,
                            $reason,
                        ]);
                        $requestId = (int) $pdo->lastInsertId();
                        if ($requestId <= 0) {
                            $idStmt = $pdo->prepare('SELECT id FROM hr_holiday_work_exceptions WHERE user_id = ? AND holiday_date = ? LIMIT 1');
                            $idStmt->execute([$user['id'], $holidayDate]);
                            $requestId = (int) $idStmt->fetchColumn();
                        }
                        if ($requestId > 0 && function_exists('crm_line_notify_holiday_work_requested')) {
                            crm_line_notify_holiday_work_requested($pdo, $requestId);
                        }
                        header('Location: holiday_work_request.php?year=' . urlencode((string) $year) . '&success=1');
                        exit;
                    } catch (Throwable $e) {
                        tpHrLogException($e, 'holiday_work_request submit');
                        $error = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง';
                    }
                }
            }
        } elseif ($action === 'cancel_request') {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $stmt = $pdo->prepare("
                DELETE FROM hr_holiday_work_exceptions
                WHERE id = ? AND user_id = ? AND status = 'PENDING'
            ");
            $stmt->execute([$requestId, $user['id']]);
            header('Location: holiday_work_request.php?year=' . urlencode((string) $year));
            exit;
        }
    }
}

if (isset($_GET['success'])) {
    $success = 'ส่งคำขอเรียบร้อยแล้ว รอการอนุมัติจากผู้บริหาร';
}

$stmtHol = $pdo->prepare("
    SELECT date, name, type
    FROM hr_holidays
    WHERE YEAR(date) = ? AND is_active = 1
    ORDER BY date
");
$stmtHol->execute([$year]);
$holidays = $stmtHol->fetchAll(PDO::FETCH_ASSOC);

$stmtReq = $pdo->prepare("
    SELECT *
    FROM hr_holiday_work_exceptions
    WHERE user_id = ? AND YEAR(holiday_date) = ?
    ORDER BY holiday_date
");
$stmtReq->execute([$user['id'], $year]);
$requestsByDate = [];
foreach ($stmtReq->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $requestsByDate[$row['holiday_date']] = $row;
}

$holidayTypeLabel = static function (string $type): string {
    return match ($type) {
        'PUBLIC' => 'วันหยุดราชการ',
        'COMPANY' => 'วันหยุดบริษัท',
        'SPECIAL' => 'วันหยุดพิเศษ',
        'SUBSTITUTE' => 'วันหยุดชดเชย',
        default => 'วันหยุด',
    };
};

$statusChip = static function (string $status): string {
    return match ($status) {
        'PENDING' => '<span class="px-2.5 py-1 text-xs font-medium rounded-[var(--tp-ios-card-radius)] border border-white/10 bg-amber-500/20 text-amber-300">รออนุมัติ</span>',
        'APPROVED' => '<span class="px-2.5 py-1 text-xs font-medium rounded-[var(--tp-ios-card-radius)] border border-white/10 bg-green-500/20 text-green-400">อนุมัติแล้ว</span>',
        'REJECTED' => '<span class="px-2.5 py-1 text-xs font-medium rounded-[var(--tp-ios-card-radius)] border border-white/10 bg-red-500/20 text-red-400">ไม่อนุมัติ</span>',
        default => '',
    };
};

$yearOptions = [];
for ($y = (int) date('Y') - 1; $y <= (int) date('Y') + 1; $y++) {
    $yearOptions[] = $y;
}

include __DIR__ . '/templates/header.php';
?>

<div class="tp-holiday-work-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="mb-2 text-sm text-white/60" aria-label="Breadcrumb">
        <a href="index.php" class="tp-tap-48 inline-flex min-h-[48px] items-center hover:text-white touch-manipulation">หน้าแรก</a>
        <span class="mx-2">/</span>
        <a href="holidays.php" class="inline-flex min-h-[48px] items-center hover:text-white touch-manipulation">วันหยุดประจำปี</a>
        <span class="mx-2">/</span>
        <span class="text-white">ทำงานวันหยุด / หยุดชดเชย</span>
    </nav>
    <h1 class="tp-ios-page-title">ทำงานวันหยุด / หยุดชดเชย</h1>
    <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">
        กรณีบริษัทมีวันหยุดประจำปีแต่คุณต้องมาทำงาน — ขออนุมัติมาทำงานวันนั้นและระบุวันหยุดชดเชย (ถ้ามี)
    </p>
</header>

<?php if ($success): ?>
<div class="tp-native-success-state bg-emerald-500/15 border border-emerald-400/40 text-emerald-200 px-4 py-3 rounded-[var(--tp-ios-card-radius)] mb-6 flex items-start gap-3" role="status">
    <i class="fas fa-check-circle text-2xl shrink-0 mt-0.5" aria-hidden="true"></i>
    <span><?php echo htmlspecialchars($success); ?></span>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="tp-native-error-state bg-red-500/15 border border-red-400/45 text-red-200 px-4 py-3 rounded-[var(--tp-ios-card-radius)] mb-6 flex items-start gap-3" role="alert">
    <i class="fas fa-exclamation-circle text-2xl shrink-0 mt-0.5" aria-hidden="true"></i>
    <span><?php echo htmlspecialchars($error); ?></span>
</div>
<?php endif; ?>

<div class="native-card tp-native-card tp-native-data-card p-5 mb-6 min-w-0">
    <form method="GET" class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-6">
        <label for="hw-year" class="text-white/70 text-sm font-medium shrink-0">ปี</label>
        <select id="hw-year" name="year" class="input-field tp-native-select w-full sm:w-auto sm:min-w-[10rem] touch-manipulation" onchange="this.form.submit()">
            <?php foreach ($yearOptions as $y): ?>
            <option value="<?php echo $y; ?>" <?php echo $year === $y ? 'selected' : ''; ?>><?php echo $y + 543; ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="space-y-4 min-w-0">
    <?php if (!$holidays): ?>
    <div class="native-card tp-native-card p-6 text-center text-white/60">
        <i class="fas fa-calendar-xmark text-3xl text-white/30 mb-3" aria-hidden="true"></i>
        <p>ไม่มีวันหยุดประจำปีในปี <?php echo $year + 543; ?></p>
    </div>
    <?php else: ?>
    <?php foreach ($holidays as $hol): 
        $req = $requestsByDate[$hol['date']] ?? null;
        $isPast = $hol['date'] < $today;
        $canRequest = !$isPast && (!$req || $req['status'] === 'REJECTED');
    ?>
    <div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 min-w-0">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                    <h3 class="text-white font-semibold text-lg"><?php echo htmlspecialchars($hol['name']); ?></h3>
                    <?php if ($req) echo $statusChip((string)$req['status']); ?>
                </div>
                <p class="text-white/60 text-sm">
                    <?php echo formatDateThai($hol['date']); ?>
                    · <?php echo htmlspecialchars($holidayTypeLabel((string)$hol['type'])); ?>
                </p>
                <?php if ($req): ?>
                <div class="mt-3 text-sm text-white/70 space-y-1">
                    <?php if (!empty($req['comp_date'])): ?>
                    <p><i class="fas fa-exchange-alt text-violet-400 mr-2" aria-hidden="true"></i>
                        หยุดชดเชย: <span class="text-violet-200 font-medium"><?php echo formatDateThai($req['comp_date']); ?></span>
                    </p>
                    <?php endif; ?>
                    <?php if (!empty($req['reason'])): ?>
                    <p class="text-white/50 break-words"><?php echo htmlspecialchars($req['reason']); ?></p>
                    <?php endif; ?>
                    <?php if ($req['status'] === 'REJECTED' && !empty($req['review_note'])): ?>
                    <p class="text-red-300/80 text-xs">หมายเหตุ: <?php echo htmlspecialchars($req['review_note']); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="shrink-0 flex flex-col gap-2">
                <?php if ($canRequest): ?>
                <button type="button"
                        onclick="openHolidayWorkModal('<?php echo htmlspecialchars($hol['date'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($hol['name'], ENT_QUOTES); ?>')"
                        class="btn-primary min-h-[48px] px-4 rounded-[var(--tp-ios-card-radius)] text-sm touch-manipulation border-0 whitespace-nowrap">
                    <i class="fas fa-briefcase mr-1" aria-hidden="true"></i>ขอมาทำงานวันนี้
                </button>
                <?php elseif ($req && $req['status'] === 'PENDING'): ?>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="cancel_request">
                    <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                    <button type="submit" class="min-h-[48px] px-3 py-2 bg-red-500/15 hover:bg-red-500/25 border border-red-500/30 text-red-300 text-sm rounded-[var(--tp-ios-card-radius)] touch-manipulation font-medium whitespace-nowrap"
                            onclick="return confirm('ยกเลิกคำขอนี้?')">
                        <i class="fas fa-times mr-1" aria-hidden="true"></i>ยกเลิกคำขอ
                    </button>
                </form>
                <?php elseif ($isPast && !$req): ?>
                <span class="text-white/40 text-xs">วันหยุดผ่านมาแล้ว</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 mt-6 min-w-0">
    <p class="text-sm font-semibold text-white mb-3"><i class="fas fa-circle-info text-violet-400 mr-2" aria-hidden="true"></i>หมายเหตุ</p>
    <ul class="space-y-2 text-xs text-white/60 leading-relaxed list-disc pl-5">
        <li>หลังอนุมัติ ระบบจะนับวันหยุดนั้นเป็น<strong class="text-white/80">วันทำงาน</strong>ของคุณ (ต้องลงเวลา มาสาย/ขาดงานมีผลตามปกติ)</li>
        <li>วันหยุดชดเชย (ถ้าระบุ) จะกลายเป็น<strong class="text-white/80">วันหยุด</strong>ของคุณแม้เป็นวันทำงานปกติ</li>
        <li>การสลับวันหยุดประจำสัปดาห์แยกต่างหาก — ดูที่ <a href="dayoff_schedule.php" class="tp-tap-48 text-violet-300 hover:text-violet-200 underline">วันหยุดประจำสัปดาห์</a></li>
    </ul>
</div>
</div>

<div id="hw-modal" class="tp-native-modal fixed inset-0 hidden flex items-center justify-center p-5 overflow-y-auto overscroll-contain bg-black/50 backdrop-blur-sm">
    <div class="native-card tp-native-card w-full max-w-md my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto">
        <form method="POST" class="p-6">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="request">
            <input type="hidden" name="holiday_date" id="hw-modal-date">

            <h3 class="text-xl font-bold text-white mb-1">ขอมาทำงานวันหยุด</h3>
            <p class="text-white/50 text-sm mb-4" id="hw-modal-label"></p>

            <div class="tp-native-form-group">
                <label for="hw-comp-date" class="text-white/70 text-sm font-medium">วันหยุดชดเชย (ไม่บังคับ)</label>
                <input type="date" id="hw-comp-date" name="comp_date" class="input-field w-full touch-manipulation">
                <p class="text-white/45 text-xs mt-1">เลือกวันที่ต้องการหยุดแทน — ภายใน 90 วันก่อน/หลังวันหยุด</p>
            </div>

            <div class="tp-native-form-group">
                <label for="hw-reason" class="text-white/70 text-sm font-medium">เหตุผล <span class="text-red-400">*</span></label>
                <textarea id="hw-reason" name="reason" rows="3" required maxlength="500"
                          class="input-field w-full touch-manipulation" placeholder="เช่น แผนกต้องดูแลลูกค้าในวันหยุด"></textarea>
            </div>

            <div class="grid grid-cols-2 gap-3 mt-6">
                <button type="button" onclick="closeHolidayWorkModal()"
                        class="min-h-[52px] rounded-[var(--tp-ios-card-radius)] bg-white/10 hover:bg-white/15 text-white touch-manipulation whitespace-nowrap">
                    ยกเลิก
                </button>
                <button type="submit" class="btn-primary min-h-[52px] rounded-[var(--tp-ios-card-radius)] border-0 touch-manipulation whitespace-nowrap">
                    ส่งคำขอ
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openHolidayWorkModal(date, name) {
    document.getElementById('hw-modal-date').value = date;
    document.getElementById('hw-modal-label').textContent = name + ' · ' + date;
    document.getElementById('hw-comp-date').value = '';
    document.getElementById('hw-reason').value = '';
    document.getElementById('hw-modal').classList.remove('hidden');
}
function closeHolidayWorkModal() {
    document.getElementById('hw-modal').classList.add('hidden');
}
document.getElementById('hw-modal').addEventListener('click', function(e) {
    if (e.target === this) closeHolidayWorkModal();
});
</script>

<?php include __DIR__ . '/templates/footer.php'; ?>
