<?php
/**
 * Company annual holidays — read-only for all employees (IOS26 layout).
 */

require_once __DIR__ . '/bootstrap.php';
Auth::requireLogin();

$pdo = getDB();
$page_title = 'วันหยุดประจำปี';
$current_page = 'holidays';

$holidayYear = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
if ($holidayYear < 2000 || $holidayYear > 2100) {
    $holidayYear = (int) date('Y');
}
$view = $_GET['view'] ?? 'calendar';
if (!in_array($view, ['calendar', 'list'], true)) {
    $view = 'calendar';
}

$holidayYearTh = $holidayYear + 543;
$prevYear = max(2000, $holidayYear - 1);
$nextYear = min(2100, $holidayYear + 1);
$today = date('Y-m-d');
$isCurrentYear = $holidayYear === (int) date('Y');

$stmt = $pdo->prepare("
    SELECT id, date, name, name_en, type, description
    FROM hr_holidays
    WHERE YEAR(date) = ? AND is_active = 1
    ORDER BY date
");
$stmt->execute([$holidayYear]);
$holidays = $stmt->fetchAll();
$holidayCount = count($holidays);

$holidaysByMonth = [];
$holidaysByDate = [];
foreach ($holidays as $holiday) {
    $month = (int) date('n', strtotime($holiday['date']));
    $holidaysByMonth[$month][] = $holiday;
    $holidaysByDate[$holiday['date']] = $holiday;
}

$pastCount = 0;
$upcomingCount = 0;
$nextHoliday = null;
$daysUntilNext = null;

foreach ($holidays as $holiday) {
    if ($holiday['date'] < $today) {
        $pastCount++;
        continue;
    }
    $upcomingCount++;
    if ($nextHoliday === null) {
        $nextHoliday = $holiday;
        if ($isCurrentYear) {
            $daysUntilNext = (int) (new DateTime($today))->diff(new DateTime($holiday['date']))->format('%a');
        }
    }
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

$holidaysCalDayClass = static function (?array $holiday, bool $isToday, bool $isPast): string {
    $classes = ['tp-holidays-cal-day'];
    if ($holiday) {
        $classes[] = 'tp-holidays-cal-day--holiday';
    } elseif ($isToday) {
        $classes[] = 'tp-holidays-cal-day--today';
    }
    if ($isPast) {
        $classes[] = 'is-past';
    }
    return implode(' ', $classes);
};

$buildMiniMonth = static function (int $year, int $month) use ($holidaysByDate, $today): array {
    $cells = [];
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $daysInMonth = (int) date('t', strtotime($monthStart));
    $startPad = (int) date('N', strtotime($monthStart)) - 1;

    for ($i = 0; $i < $startPad; $i++) {
        $cells[] = ['empty' => true];
    }
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $cells[] = [
            'empty' => false,
            'day' => $day,
            'date' => $date,
            'holiday' => $holidaysByDate[$date] ?? null,
            'isToday' => $date === $today,
            'isPast' => $date < $today,
        ];
    }
    while (count($cells) % 7 !== 0) {
        $cells[] = ['empty' => true];
    }
    return $cells;
};

$yearQuery = static function (int $year, string $viewMode): string {
    return '?year=' . $year . '&view=' . urlencode($viewMode);
};

$canManageHolidays = function_exists('isCEOOrAbove') && isCEOOrAbove();

require_once __DIR__ . '/templates/header.php';
?>

<div class="tp-holidays-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
    <header class="tp-ios-large-title-block mb-5 md:mb-6">
        <nav class="mb-2 text-sm text-white/55" aria-label="Breadcrumb">
            <a href="index.php" class="hover:text-white touch-manipulation">หน้าแรก</a>
            <span class="mx-2">/</span>
            <span class="text-white/85">วันหยุดประจำปี</span>
        </nav>
        <h1 class="tp-ios-page-title">วันหยุดประจำปี</h1>
        <p class="tp-ios-caption-muted mt-2 max-w-[40rem]">
            นักขัตฤกษ์และวันหยุดบริษัท — แยกจาก
            <a href="dayoff_schedule.php" class="text-violet-300 hover:text-violet-200 underline touch-manipulation">วันหยุดประจำสัปดาห์</a>
        </p>
    </header>

    <div class="tp-holidays-toolbar native-card tp-native-card min-w-0">
        <form method="GET">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">

            <div class="tp-holidays-year-row">
                <a href="<?php echo $yearQuery($prevYear, $view); ?>"
                   class="tp-holidays-year-btn touch-manipulation"
                   aria-label="ปี <?php echo (int) ($prevYear + 543); ?>">
                    <i class="fas fa-chevron-left" aria-hidden="true"></i>
                </a>

                <div class="tp-holidays-year-display">
                    <p class="tp-holidays-year-number"><?php echo (int) $holidayYearTh; ?></p>
                    <p class="tp-holidays-year-sub">พ.ศ. · ค.ศ. <?php echo (int) $holidayYear; ?></p>
                    <label for="holiday-year-select" class="tp-visually-hidden">เลือกปี</label>
                    <select id="holiday-year-select" name="year"
                            class="input-field tp-native-select tp-holidays-year-select touch-manipulation"
                            onchange="this.form.submit()">
                        <?php for ($y = (int) date('Y') + 1; $y >= 2000; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y === $holidayYear ? 'selected' : ''; ?>>
                            <?php echo $y + 543; ?> (<?php echo $y; ?>)
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <a href="<?php echo $yearQuery($nextYear, $view); ?>"
                   class="tp-holidays-year-btn touch-manipulation"
                   aria-label="ปี <?php echo (int) ($nextYear + 543); ?>">
                    <i class="fas fa-chevron-right" aria-hidden="true"></i>
                </a>
            </div>
        </form>

        <div class="tp-holidays-segment" role="tablist" aria-label="มุมมองวันหยุด">
            <a href="<?php echo $yearQuery($holidayYear, 'calendar'); ?>"
               role="tab"
               aria-selected="<?php echo $view === 'calendar' ? 'true' : 'false'; ?>"
               class="tp-holidays-segment__item touch-manipulation">
                <i class="fas fa-th-large" aria-hidden="true"></i><span>ปฏิทิน</span>
            </a>
            <a href="<?php echo $yearQuery($holidayYear, 'list'); ?>"
               role="tab"
               aria-selected="<?php echo $view === 'list' ? 'true' : 'false'; ?>"
               class="tp-holidays-segment__item touch-manipulation">
                <i class="fas fa-list-ul" aria-hidden="true"></i><span>รายการ</span>
            </a>
        </div>

        <?php if ($holidayCount > 0): ?>
        <div class="tp-holidays-metrics" aria-label="สรุปวันหยุด">
            <div class="tp-holidays-metric">
                <p class="tp-holidays-metric__label">รวมทั้งปี</p>
                <p class="tp-holidays-metric__value"><?php echo (int) $holidayCount; ?></p>
            </div>
            <div class="tp-holidays-metric">
                <p class="tp-holidays-metric__label">ผ่านมาแล้ว</p>
                <p class="tp-holidays-metric__value is-muted"><?php echo (int) $pastCount; ?></p>
            </div>
            <div class="tp-holidays-metric">
                <p class="tp-holidays-metric__label">เหลืออีก</p>
                <p class="tp-holidays-metric__value is-accent"><?php echo (int) $upcomingCount; ?></p>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($holidayCount > 0 && $view === 'list'): ?>
        <div class="tp-holidays-month-scroll" aria-label="ข้ามไปเดือน">
            <?php for ($m = 1; $m <= 12; $m++):
                if (empty($holidaysByMonth[$m])) {
                    continue;
                }
                $monthCount = count($holidaysByMonth[$m]);
                $isCurrentMonth = $isCurrentYear && $m === (int) date('n');
                $chipClass = 'tp-holidays-month-chip has-holidays touch-manipulation';
                if ($isCurrentMonth) {
                    $chipClass .= ' is-current';
                }
            ?>
            <a href="#holiday-month-<?php echo $m; ?>" class="<?php echo $chipClass; ?>">
                <span><?php echo thaiMonthShort($m); ?></span>
                <span class="tp-holidays-month-chip__count"><?php echo (int) $monthCount; ?></span>
            </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        <?php if ($canManageHolidays): ?>
        <a href="/hr/settings.php?tab=holidays&amp;year=<?php echo (int) $holidayYear; ?>"
           class="tp-holidays-manage-btn w-full sm:w-auto touch-manipulation">
            <i class="fas fa-cog" aria-hidden="true"></i>
            <span>จัดการวันหยุด</span>
        </a>
        <?php endif; ?>
    </div>

    <div class="tp-holidays-layout min-w-0">
        <div class="tp-holidays-main min-w-0">
            <div class="tp-holidays-main-card min-w-0">
                <?php if ($view === 'calendar'): ?>
                <h2 class="section-title mb-4">
                    <i class="fas fa-calendar-alt text-violet-400" aria-hidden="true"></i>
                    ปฏิทิน <?php echo (int) $holidayYearTh; ?>
                </h2>

                <?php if ($holidays): ?>
                <div class="tp-holidays-calendar-grid">
                    <?php for ($m = 1; $m <= 12; $m++):
                        include __DIR__ . '/modules/employee/holidays/calendar_month.php';
                    endfor; ?>
                </div>
                <?php else: ?>
                <?php include __DIR__ . '/modules/employee/holidays/empty_state.php'; ?>
                <?php endif; ?>

                <?php else: ?>
                <h2 class="section-title mb-4">
                    <i class="fas fa-list-ul text-violet-400" aria-hidden="true"></i>
                    รายการวันหยุด
                </h2>

                <?php if ($holidays): ?>
                <?php for ($m = 1; $m <= 12; $m++):
                    if (empty($holidaysByMonth[$m])) {
                        continue;
                    }
                    include __DIR__ . '/modules/employee/holidays/list_month.php';
                endfor; ?>
                <?php else: ?>
                <?php include __DIR__ . '/modules/employee/holidays/empty_state.php'; ?>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <aside class="tp-holidays-aside min-w-0">
            <?php if ($nextHoliday && $isCurrentYear): ?>
            <div class="tp-holidays-aside-block tp-holidays-next-card">
                <p class="tp-holidays-next-card__label">วันหยุดถัดไป</p>
                <div class="tp-holidays-next-card__body">
                    <div class="w-14 h-14 shrink-0 rounded-[var(--tp-ios-card-radius)] bg-white/10 ring-1 ring-white/15 flex flex-col items-center justify-center text-white">
                        <span class="text-[11px] font-medium opacity-80"><?php echo thaiMonthShort((int) date('n', strtotime($nextHoliday['date']))); ?></span>
                        <span class="text-xl font-bold tabular-nums leading-none"><?php echo (int) date('j', strtotime($nextHoliday['date'])); ?></span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-white font-semibold text-base leading-snug break-words"><?php echo htmlspecialchars($nextHoliday['name']); ?></p>
                        <p class="text-white/55 text-sm mt-1"><?php echo formatDateThai($nextHoliday['date']); ?></p>
                    </div>
                </div>
                <p class="tp-holidays-next-card__countdown">
                    <?php if ($daysUntilNext === 0): ?>
                    วันนี้
                    <?php else: ?>
                    <?php echo (int) $daysUntilNext; ?><span class="text-base font-semibold text-white/65 ml-1">วัน</span>
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

            <div class="tp-holidays-aside-block">
                <h2 class="section-title mb-3 px-1">
                    <i class="fas fa-link text-violet-400" aria-hidden="true"></i>
                    ที่เกี่ยวข้อง
                </h2>
                <div class="tp-holidays-link-group">
                    <a href="dayoff_schedule.php" class="tp-holidays-link-row touch-manipulation">
                        <span class="tp-holidays-link-row__icon"><i class="fas fa-calendar-week" aria-hidden="true"></i></span>
                        <span class="tp-holidays-link-row__text">
                            <span class="tp-holidays-link-row__title">วันหยุดประจำสัปดาห์</span>
                            <span class="tp-holidays-link-row__sub">ดู/ขอเปลี่ยนวันหยุดรายสัปดาห์</span>
                        </span>
                        <i class="fas fa-chevron-right text-white/28 shrink-0 text-xs" aria-hidden="true"></i>
                    </a>
                    <a href="leave.php" class="tp-holidays-link-row touch-manipulation">
                        <span class="tp-holidays-link-row__icon"><i class="fas fa-calendar-alt" aria-hidden="true"></i></span>
                        <span class="tp-holidays-link-row__text">
                            <span class="tp-holidays-link-row__title">การลา</span>
                            <span class="tp-holidays-link-row__sub">ยื่นขอลาและดูสิทธิ์คงเหลือ</span>
                        </span>
                        <i class="fas fa-chevron-right text-white/28 shrink-0 text-xs" aria-hidden="true"></i>
                    </a>
                </div>
            </div>

            <?php if ($holidayCount > 0): ?>
            <div class="tp-holidays-aside-block native-card tp-native-card p-4 sm:p-5">
                <h2 class="section-title mb-2">
                    <i class="fas fa-circle-info text-violet-400" aria-hidden="true"></i>
                    คำอธิบาย
                </h2>
                <ul class="space-y-2 text-xs text-white/60 leading-relaxed">
                    <li class="flex items-center gap-3 min-h-[40px]">
                        <span class="inline-flex h-4 w-4 shrink-0 rounded-full bg-violet-500/70 ring-1 ring-violet-300/40" aria-hidden="true"></span>
                        วันหยุดนักขัตฤกษ์ / บริษัท
                    </li>
                    <li class="flex items-center gap-3 min-h-[40px]">
                        <span class="inline-flex h-4 w-4 shrink-0 rounded-full bg-white/12 ring-2 ring-violet-400/55" aria-hidden="true"></span>
                        วันนี้
                    </li>
                </ul>
            </div>
            <?php endif; ?>
        </aside>
    </div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
