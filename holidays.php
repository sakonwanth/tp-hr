<?php
/**
 * Company annual holidays — read-only for all employees.
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
foreach ($holidays as $holiday) {
    $month = (int) date('n', strtotime($holiday['date']));
    $holidaysByMonth[$month][] = $holiday;
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

$holidayTypeBadgeClass = static function (string $type): string {
    return match ($type) {
        'PUBLIC' => 'border-sky-500/35 bg-sky-500/15 text-sky-100',
        'COMPANY' => 'border-violet-500/35 bg-violet-500/15 text-violet-100',
        'SPECIAL' => 'border-amber-500/35 bg-amber-500/15 text-amber-100',
        'SUBSTITUTE' => 'border-emerald-500/35 bg-emerald-500/15 text-emerald-100',
        default => 'border-white/20 bg-white/10 text-white/70',
    };
};

$holidayDateChipClass = static function (string $type, bool $isToday, bool $isPast): string {
    if ($isToday) {
        return 'bg-gradient-to-br from-orange-500/35 to-rose-500/25 ring-2 ring-orange-400/50 text-white';
    }
    if ($isPast) {
        return 'bg-white/5 text-white/45 ring-1 ring-white/10';
    }
    return match ($type) {
        'PUBLIC' => 'bg-orange-500/20 ring-1 ring-orange-400/35 text-orange-100',
        'COMPANY' => 'bg-violet-500/20 ring-1 ring-violet-400/35 text-violet-100',
        'SPECIAL' => 'bg-amber-500/20 ring-1 ring-amber-400/35 text-amber-100',
        'SUBSTITUTE' => 'bg-emerald-500/20 ring-1 ring-emerald-400/35 text-emerald-100',
        default => 'bg-white/10 ring-1 ring-white/15 text-white/80',
    };
};

$canManageHolidays = function_exists('isCEOOrAbove') && isCEOOrAbove();
$dayNames = THAI_DAY_NAMES;

require_once __DIR__ . '/templates/header.php';
?>

<div class="tp-holidays-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
    <header class="dashboard-hero tp-ios-large-title-block mb-6 md:mb-8">
        <div class="dashboard-hero-inner flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
            <div class="dashboard-hero-main min-w-0 w-full">
                <nav class="mb-3 text-sm text-white/55" aria-label="Breadcrumb">
                    <a href="index.php" class="hover:text-white touch-manipulation">หน้าแรก</a>
                    <span class="mx-2">/</span>
                    <span class="text-white/85">วันหยุดประจำปี</span>
                </nav>
                <h1 class="dashboard-hero-title tp-ios-page-title">วันหยุดประจำปี</h1>
                <p class="tp-ios-caption-muted mt-2 max-w-[40rem]">
                    นักขัตฤกษ์และวันหยุดบริษัท — แยกจาก
                    <a href="dayoff_schedule.php" class="text-violet-300 hover:text-violet-200 underline touch-manipulation">วันหยุดประจำสัปดาห์</a>
                </p>
            </div>
            <?php if ($canManageHolidays): ?>
            <div class="dashboard-hero-cta w-full sm:w-auto shrink-0">
                <a href="/hr/settings.php?tab=holidays&amp;year=<?php echo (int) $holidayYear; ?>"
                   class="btn-primary btn-primary-prominent w-full sm:w-auto inline-flex items-center justify-center touch-manipulation gap-2">
                    <i class="fas fa-cog text-xl shrink-0" aria-hidden="true"></i>
                    <span>จัดการวันหยุด</span>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </header>

    <!-- Year navigator -->
    <div class="native-card tp-native-card tp-native-data-card p-5 mb-6 min-w-0 overflow-hidden">
        <form method="GET" class="flex flex-col gap-4">
            <div class="flex items-center justify-between gap-3">
                <a href="?year=<?php echo (int) $prevYear; ?>"
                   class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] border border-white/12 bg-white/8 text-white hover:bg-white/14 touch-manipulation transition-colors"
                   aria-label="ปี <?php echo (int) ($prevYear + 543); ?>">
                    <i class="fas fa-chevron-left" aria-hidden="true"></i>
                </a>

                <div class="flex-1 text-center min-w-0 px-2">
                    <p class="text-[2rem] sm:text-[2.35rem] font-bold text-white leading-none tabular-nums tracking-tight">
                        <?php echo (int) $holidayYearTh; ?>
                    </p>
                    <label for="holiday-year-select" class="mt-2 block text-xs text-white/45 uppercase tracking-wider">ปี พ.ศ.</label>
                    <select id="holiday-year-select" name="year"
                            class="input-field tp-native-select mx-auto mt-2 min-h-[48px] max-w-[12rem] touch-manipulation text-center"
                            onchange="this.form.submit()">
                        <?php for ($y = (int) date('Y') + 1; $y >= 2000; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y === $holidayYear ? 'selected' : ''; ?>>
                            <?php echo $y + 543; ?> (ค.ศ. <?php echo $y; ?>)
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <a href="?year=<?php echo (int) $nextYear; ?>"
                   class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] border border-white/12 bg-white/8 text-white hover:bg-white/14 touch-manipulation transition-colors"
                   aria-label="ปี <?php echo (int) ($nextYear + 543); ?>">
                    <i class="fas fa-chevron-right" aria-hidden="true"></i>
                </a>
            </div>

            <?php if ($holidayCount > 0): ?>
            <div class="grid grid-cols-4 sm:grid-cols-6 lg:grid-cols-12 gap-2 pt-1 border-t border-white/8">
                <?php for ($m = 1; $m <= 12; $m++):
                    $monthCount = count($holidaysByMonth[$m] ?? []);
                    $hasHoliday = $monthCount > 0;
                    $isCurrentMonth = $isCurrentYear && $m === (int) date('n');
                ?>
                <div class="rounded-[var(--tp-ios-card-radius)] px-2 py-2.5 text-center min-h-[52px] flex flex-col items-center justify-center gap-0.5 transition-colors <?php echo $hasHoliday
                    ? 'bg-orange-500/15 border border-orange-400/30 text-orange-100'
                    : 'bg-white/[0.03] border border-white/8 text-white/30'; ?> <?php echo $isCurrentMonth ? 'ring-2 ring-violet-400/45' : ''; ?>"
                     title="<?php echo htmlspecialchars(thaiMonth($m)); ?><?php echo $hasHoliday ? ' — ' . $monthCount . ' วัน' : ''; ?>">
                    <span class="text-[11px] font-semibold leading-none"><?php echo thaiMonthShort($m); ?></span>
                    <?php if ($hasHoliday): ?>
                    <span class="text-sm font-bold tabular-nums leading-none"><?php echo (int) $monthCount; ?></span>
                    <?php else: ?>
                    <span class="text-[10px] leading-none">—</span>
                    <?php endif; ?>
                </div>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($holidayCount > 0): ?>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 md:gap-5 mb-6 md:mb-8">
        <div class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0">
            <div class="flex items-center gap-3">
                <div class="stat-icon bg-orange-500/15 border border-orange-400/25 shrink-0">
                    <i class="fas fa-calendar-check text-orange-300 text-xl" aria-hidden="true"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-white/55 text-xs">รวมทั้งปี</p>
                    <p class="text-2xl font-bold text-white tabular-nums"><?php echo (int) $holidayCount; ?></p>
                </div>
            </div>
        </div>
        <div class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0">
            <div class="flex items-center gap-3">
                <div class="stat-icon bg-white/8 border border-white/12 shrink-0">
                    <i class="fas fa-history text-white/45 text-xl" aria-hidden="true"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-white/55 text-xs">ผ่านมาแล้ว</p>
                    <p class="text-2xl font-bold text-white/70 tabular-nums"><?php echo (int) $pastCount; ?></p>
                </div>
            </div>
        </div>
        <div class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0">
            <div class="flex items-center gap-3">
                <div class="stat-icon bg-emerald-500/15 border border-emerald-400/25 shrink-0">
                    <i class="fas fa-hourglass-half text-emerald-300 text-xl" aria-hidden="true"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-white/55 text-xs">เหลืออีก</p>
                    <p class="text-2xl font-bold text-emerald-200 tabular-nums"><?php echo (int) $upcomingCount; ?></p>
                </div>
            </div>
        </div>
        <div class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 col-span-2 lg:col-span-1">
            <div class="flex items-center gap-3">
                <div class="stat-icon bg-violet-500/15 border border-violet-400/25 shrink-0">
                    <i class="fas fa-star text-violet-300 text-xl" aria-hidden="true"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-white/55 text-xs">วันหยุดถัดไป</p>
                    <?php if ($nextHoliday && $isCurrentYear): ?>
                    <p class="text-sm font-semibold text-white truncate"><?php echo htmlspecialchars($nextHoliday['name']); ?></p>
                    <p class="text-xs text-violet-200/85 mt-0.5">
                        <?php if ($daysUntilNext === 0): ?>
                        วันนี้
                        <?php else: ?>
                        อีก <?php echo (int) $daysUntilNext; ?> วัน
                        <?php endif; ?>
                    </p>
                    <?php elseif ($nextHoliday): ?>
                    <p class="text-sm font-semibold text-white truncate"><?php echo formatDateThai($nextHoliday['date']); ?></p>
                    <?php else: ?>
                    <p class="text-sm text-white/45">ครบแล้ว</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 md:gap-8 min-w-0">
        <div class="xl:col-span-2 space-y-6 min-w-0">
            <div class="native-card tp-native-card tp-native-data-card min-w-0">
                <h2 class="section-title mb-5">
                    <i class="fas fa-list-ul text-orange-400" aria-hidden="true"></i>
                    รายการตามเดือน
                </h2>

                <?php if ($holidays): ?>
                <div class="space-y-8">
                    <?php for ($m = 1; $m <= 12; $m++):
                        if (empty($holidaysByMonth[$m])) {
                            continue;
                        }
                        $isCurrentMonth = $isCurrentYear && $m === (int) date('n');
                    ?>
                    <section class="min-w-0" aria-labelledby="holiday-month-<?php echo $m; ?>">
                        <div class="flex items-center gap-3 mb-3">
                            <h3 id="holiday-month-<?php echo $m; ?>" class="text-sm font-semibold text-white flex items-center gap-2 min-w-0">
                                <span class="inline-flex h-8 min-w-[2rem] items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-orange-500/20 border border-orange-400/30 text-orange-200 text-xs font-bold px-2 tabular-nums">
                                    <?php echo count($holidaysByMonth[$m]); ?>
                                </span>
                                <span class="truncate"><?php echo thaiMonth($m); ?> <?php echo (int) $holidayYearTh; ?></span>
                            </h3>
                            <?php if ($isCurrentMonth): ?>
                            <span class="shrink-0 rounded-full bg-violet-500/20 border border-violet-400/30 px-2.5 py-0.5 text-[11px] font-semibold text-violet-200">เดือนนี้</span>
                            <?php endif; ?>
                        </div>

                        <div class="space-y-3">
                            <?php foreach ($holidaysByMonth[$m] as $holiday):
                                $isPast = $holiday['date'] < $today;
                                $isToday = $holiday['date'] === $today;
                                $dow = (int) date('w', strtotime($holiday['date']));
                                $badgeClass = $holidayTypeBadgeClass((string) $holiday['type']);
                                $chipClass = $holidayDateChipClass((string) $holiday['type'], $isToday, $isPast);
                            ?>
                            <article class="tp-ios-attendance-panel p-4 sm:p-5 min-w-0 transition-colors <?php echo $isPast ? 'opacity-75' : ''; ?> <?php echo $isToday ? 'ring-2 ring-orange-400/40' : ''; ?>">
                                <div class="flex items-start gap-4 min-w-0">
                                    <div class="w-14 h-14 shrink-0 rounded-[var(--tp-ios-card-radius)] flex flex-col items-center justify-center <?php echo $chipClass; ?>">
                                        <span class="text-[10px] font-semibold uppercase tracking-wide opacity-90"><?php echo thaiMonthShort($m); ?></span>
                                        <span class="text-xl font-bold leading-none tabular-nums"><?php echo (int) date('j', strtotime($holiday['date'])); ?></span>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <p class="text-white font-semibold text-base leading-snug break-words">
                                                    <?php echo htmlspecialchars($holiday['name']); ?>
                                                </p>
                                                <?php if (!empty($holiday['name_en'])): ?>
                                                <p class="text-white/45 text-xs mt-1 break-words"><?php echo htmlspecialchars($holiday['name_en']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                            <span class="inline-flex shrink-0 rounded-[var(--tp-ios-card-radius)] border px-2.5 py-1 text-[11px] font-medium <?php echo $badgeClass; ?>">
                                                <?php echo htmlspecialchars($holidayTypeLabel((string) $holiday['type'])); ?>
                                            </span>
                                        </div>
                                        <p class="text-white/55 text-sm mt-2 flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <span><?php echo formatDateThai($holiday['date']); ?></span>
                                            <span class="text-white/30" aria-hidden="true">·</span>
                                            <span>วัน<?php echo htmlspecialchars($dayNames[$dow] ?? ''); ?></span>
                                            <?php if ($isToday): ?>
                                            <span class="inline-flex rounded-full bg-orange-500/25 border border-orange-400/35 px-2 py-0.5 text-[11px] font-semibold text-orange-100">วันนี้</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endfor; ?>
                </div>
                <?php else: ?>
                <div class="tp-native-empty-state text-center py-12 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-0">
                    <div class="inline-flex h-16 w-16 items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 mb-4">
                        <i class="fas fa-calendar-times text-2xl text-white/30" aria-hidden="true"></i>
                    </div>
                    <p class="text-white/75 text-base font-medium">ยังไม่มีวันหยุดประจำปี <?php echo (int) $holidayYearTh; ?></p>
                    <p class="text-white/45 text-sm mt-2 max-w-sm mx-auto">HR ยังไม่ได้บันทึกตารางวันหยุดสำหรับปีนี้</p>
                    <?php if ($canManageHolidays): ?>
                    <a href="/hr/settings.php?tab=holidays&amp;year=<?php echo (int) $holidayYear; ?>"
                       class="mt-5 inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-600 hover:bg-violet-700 px-5 text-sm font-semibold text-white touch-manipulation gap-2">
                        <i class="fas fa-plus" aria-hidden="true"></i>
                        เพิ่มวันหยุด
                    </a>
                    <?php else: ?>
                    <p class="text-white/40 text-xs mt-3">ติดต่อ HR หรือผู้บริหาร</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <aside class="space-y-6 min-w-0 xl:sticky xl:top-6 xl:self-start">
            <?php if ($nextHoliday && $isCurrentYear): ?>
            <div class="native-card tp-native-card overflow-hidden rounded-[var(--tp-ios-card-radius)] border border-orange-400/25 bg-gradient-to-br from-orange-500/20 via-rose-500/10 to-violet-600/15 p-5 sm:p-6 min-w-0">
                <p class="text-orange-200/90 text-xs font-semibold uppercase tracking-wider mb-2">วันหยุดถัดไป</p>
                <div class="flex items-start gap-4">
                    <div class="w-16 h-16 shrink-0 rounded-[var(--tp-ios-card-radius)] bg-white/10 ring-1 ring-white/20 flex flex-col items-center justify-center text-white">
                        <span class="text-[11px] font-medium opacity-80"><?php echo thaiMonthShort((int) date('n', strtotime($nextHoliday['date']))); ?></span>
                        <span class="text-2xl font-bold tabular-nums leading-none"><?php echo (int) date('j', strtotime($nextHoliday['date'])); ?></span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-white font-bold text-lg leading-snug break-words"><?php echo htmlspecialchars($nextHoliday['name']); ?></p>
                        <p class="text-white/60 text-sm mt-1"><?php echo formatDateThai($nextHoliday['date']); ?></p>
                        <p class="mt-3 text-3xl font-bold text-white tabular-nums leading-none">
                            <?php if ($daysUntilNext === 0): ?>
                            <span class="text-xl">วันนี้</span>
                            <?php else: ?>
                            <?php echo (int) $daysUntilNext; ?>
                            <span class="text-base font-semibold text-white/70 ml-1">วัน</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="native-card tp-native-card tp-native-data-card min-w-0">
                <h2 class="section-title mb-4">
                    <i class="fas fa-link text-violet-400" aria-hidden="true"></i>
                    ที่เกี่ยวข้อง
                </h2>
                <div class="space-y-2">
                    <a href="dayoff_schedule.php" class="tp-ios-attendance-panel flex items-center gap-3 p-4 min-h-[48px] touch-manipulation hover:bg-white/[0.07] transition-colors">
                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-500/15 border border-violet-400/25 text-violet-300">
                            <i class="fas fa-calendar-week" aria-hidden="true"></i>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-white text-sm font-medium">วันหยุดประจำสัปดาห์</span>
                            <span class="block text-white/45 text-xs mt-0.5">ดู/ขอเปลี่ยนวันหยุดรายสัปดาห์</span>
                        </span>
                        <i class="fas fa-chevron-right text-white/30 ml-auto shrink-0 text-xs" aria-hidden="true"></i>
                    </a>
                    <a href="leave.php" class="tp-ios-attendance-panel flex items-center gap-3 p-4 min-h-[48px] touch-manipulation hover:bg-white/[0.07] transition-colors">
                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-blue-500/15 border border-blue-400/25 text-blue-300">
                            <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-white text-sm font-medium">การลา</span>
                            <span class="block text-white/45 text-xs mt-0.5">ยื่นขอลาและดูสิทธิ์คงเหลือ</span>
                        </span>
                        <i class="fas fa-chevron-right text-white/30 ml-auto shrink-0 text-xs" aria-hidden="true"></i>
                    </a>
                </div>
            </div>

            <?php if ($holidayCount > 0): ?>
            <div class="native-card tp-native-card tp-native-data-card p-5 min-w-0">
                <h2 class="section-title mb-3">
                    <i class="fas fa-palette text-orange-400" aria-hidden="true"></i>
                    ประเภทวันหยุด
                </h2>
                <div class="flex flex-col gap-2 text-xs text-white/65">
                    <span class="inline-flex items-center gap-2 min-h-[40px]">
                        <span class="inline-block h-3 w-3 shrink-0 rounded-[4px] bg-sky-500/50 ring-1 ring-sky-400/40" aria-hidden="true"></span>
                        วันหยุดราชการ / นักขัตฤกษ์
                    </span>
                    <span class="inline-flex items-center gap-2 min-h-[40px]">
                        <span class="inline-block h-3 w-3 shrink-0 rounded-[4px] bg-violet-500/50 ring-1 ring-violet-400/40" aria-hidden="true"></span>
                        วันหยุดบริษัท
                    </span>
                    <span class="inline-flex items-center gap-2 min-h-[40px]">
                        <span class="inline-block h-3 w-3 shrink-0 rounded-[4px] bg-amber-500/50 ring-1 ring-amber-400/40" aria-hidden="true"></span>
                        วันหยุดพิเศษ / ชดเชย
                    </span>
                </div>
            </div>
            <?php endif; ?>
        </aside>
    </div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
