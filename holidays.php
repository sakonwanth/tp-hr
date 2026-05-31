<?php
/**
 * Company annual holidays — read-only for all employees.
 */

require_once __DIR__ . '/bootstrap.php';
Auth::requireLogin();

$pdo = getDB();
$user = Auth::user();
$page_title = 'วันหยุดประจำปี';
$current_page = 'holidays';

$holidayYear = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
if ($holidayYear < 2000 || $holidayYear > 2100) {
    $holidayYear = (int) date('Y');
}
$holidayYearTh = $holidayYear + 543;
$prevYear = max(2000, $holidayYear - 1);
$nextYear = min(2100, $holidayYear + 1);

$stmt = $pdo->prepare("
    SELECT id, date, name, name_en, type, description
    FROM hr_holidays
    WHERE YEAR(date) = ? AND is_active = 1
    ORDER BY date
");
$stmt->execute([$holidayYear]);
$holidays = $stmt->fetchAll();
$holidayCount = count($holidays);

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
        'PUBLIC' => 'border-sky-500/30 bg-sky-500/15 text-sky-200',
        'COMPANY' => 'border-violet-500/30 bg-violet-500/15 text-violet-200',
        'SPECIAL' => 'border-amber-500/30 bg-amber-500/15 text-amber-200',
        'SUBSTITUTE' => 'border-emerald-500/30 bg-emerald-500/15 text-emerald-200',
        default => 'border-white/20 bg-white/10 text-white/70',
    };
};

$canManageHolidays = function_exists('isCEOOrAbove') && isCEOOrAbove();

require_once __DIR__ . '/templates/header.php';
?>

<div class="tp-holidays-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
    <header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
        <nav class="mb-2 text-sm text-white/60" aria-label="Breadcrumb">
            <a href="index.php" class="inline-flex min-h-[48px] items-center hover:text-white touch-manipulation">หน้าแรก</a>
            <span class="mx-2">/</span>
            <span class="text-white">วันหยุดประจำปี</span>
        </nav>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="tp-ios-page-title">วันหยุดประจำปี</h1>
                <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">
                    ตารางวันหยุดนักขัตฤกษ์และวันหยุดบริษัทประจำปี <?php echo (int) $holidayYearTh; ?>
                    — แยกจาก<a href="dayoff_schedule.php" class="text-violet-300 hover:text-violet-200 underline touch-manipulation">วันหยุดประจำสัปดาห์</a>
                </p>
            </div>
            <?php if ($canManageHolidays): ?>
            <a href="/hr/settings.php?tab=holidays&amp;year=<?php echo (int) $holidayYear; ?>"
               class="inline-flex min-h-[48px] shrink-0 items-center justify-center rounded-[var(--tp-ios-card-radius)] border border-white/15 bg-white/10 px-4 text-sm font-semibold text-white hover:bg-white/15 touch-manipulation gap-2">
                <i class="fas fa-cog" aria-hidden="true"></i>
                <span>จัดการวันหยุด</span>
            </a>
            <?php endif; ?>
        </div>
    </header>

    <div class="native-card tp-native-card tp-native-data-card p-5 mb-6 min-w-0">
        <form method="GET" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap items-center gap-2">
                <a href="?year=<?php echo (int) $prevYear; ?>"
                   class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] border border-white/15 bg-white/10 text-white hover:bg-white/15 touch-manipulation"
                   aria-label="ปีก่อนหน้า">
                    <i class="fas fa-chevron-left" aria-hidden="true"></i>
                </a>
                <label for="holiday-year-select" class="sr-only">ปี ค.ศ.</label>
                <select id="holiday-year-select" name="year"
                        class="input-field tp-native-select min-h-[48px] w-full sm:w-auto sm:min-w-[10rem] touch-manipulation"
                        onchange="this.form.submit()">
                    <?php for ($y = (int) date('Y') + 1; $y >= 2000; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y === $holidayYear ? 'selected' : ''; ?>>
                        <?php echo $y + 543; ?> (<?php echo $y; ?>)
                    </option>
                    <?php endfor; ?>
                </select>
                <a href="?year=<?php echo (int) $nextYear; ?>"
                   class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] border border-white/15 bg-white/10 text-white hover:bg-white/15 touch-manipulation"
                   aria-label="ปีถัดไป">
                    <i class="fas fa-chevron-right" aria-hidden="true"></i>
                </a>
            </div>
            <p class="text-sm text-white/60">
                รวม <span class="font-semibold text-white tabular-nums"><?php echo (int) $holidayCount; ?></span> วัน
            </p>
        </form>
    </div>

    <div class="native-card tp-native-card tp-native-data-card overflow-hidden rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6 min-w-0 border border-white/10">
        <h2 class="text-lg font-semibold text-white mb-4 flex items-center gap-2">
            <i class="fas fa-calendar-day text-orange-400" aria-hidden="true"></i>
            รายการวันหยุด <?php echo (int) $holidayYearTh; ?>
        </h2>

        <?php if ($holidays): ?>
        <div class="md:hidden space-y-3">
            <?php foreach ($holidays as $holiday):
                $isPast = $holiday['date'] < date('Y-m-d');
                $badgeClass = $holidayTypeBadgeClass((string) $holiday['type']);
            ?>
            <div class="rounded-[var(--tp-ios-card-radius)] border border-white/10 bg-white/5 p-5 <?php echo $isPast ? 'opacity-70' : ''; ?>">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-white font-medium break-words"><?php echo htmlspecialchars($holiday['name']); ?></p>
                        <?php if (!empty($holiday['name_en'])): ?>
                        <p class="text-white/45 text-xs mt-1 break-words"><?php echo htmlspecialchars($holiday['name_en']); ?></p>
                        <?php endif; ?>
                        <p class="text-white/55 text-sm mt-2"><?php echo formatDateThai($holiday['date']); ?></p>
                    </div>
                    <span class="inline-flex shrink-0 rounded-[var(--tp-ios-card-radius)] border px-2 py-0.5 text-xs <?php echo $badgeClass; ?>">
                        <?php echo htmlspecialchars($holidayTypeLabel((string) $holiday['type'])); ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="tp-native-table-shell hidden md:block overflow-x-auto [-webkit-overflow-scrolling:touch]">
            <table class="min-w-full divide-y divide-white/10">
                <thead>
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">วันที่</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ชื่อวันหยุด</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ประเภท</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    <?php foreach ($holidays as $holiday):
                        $isPast = $holiday['date'] < date('Y-m-d');
                        $badgeClass = $holidayTypeBadgeClass((string) $holiday['type']);
                    ?>
                    <tr class="hover:bg-white/[0.04] <?php echo $isPast ? 'opacity-70' : ''; ?>">
                        <td class="px-4 py-3 text-white/80 whitespace-nowrap"><?php echo formatDateThai($holiday['date']); ?></td>
                        <td class="px-4 py-3 text-white font-medium">
                            <?php echo htmlspecialchars($holiday['name']); ?>
                            <?php if (!empty($holiday['name_en'])): ?>
                            <span class="block text-xs text-white/45 font-normal mt-0.5"><?php echo htmlspecialchars($holiday['name_en']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-[var(--tp-ios-card-radius)] border px-2 py-0.5 text-xs <?php echo $badgeClass; ?>">
                                <?php echo htmlspecialchars($holidayTypeLabel((string) $holiday['type'])); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="tp-native-empty-state text-center py-10 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-0">
            <i class="fas fa-calendar-times text-3xl text-white/30 mb-3" aria-hidden="true"></i>
            <p class="text-white/70 text-sm">ยังไม่มีวันหยุดประจำปี <?php echo (int) $holidayYearTh; ?> ในระบบ</p>
            <?php if ($canManageHolidays): ?>
            <a href="/hr/settings.php?tab=holidays&amp;year=<?php echo (int) $holidayYear; ?>"
               class="mt-4 inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-600 hover:bg-violet-700 px-4 text-sm font-semibold text-white touch-manipulation gap-2">
                <i class="fas fa-plus" aria-hidden="true"></i>
                เพิ่มวันหยุดใน Settings
            </a>
            <?php else: ?>
            <p class="text-white/45 text-xs mt-2">ติดต่อ HR หรือผู้บริหารเพื่ออัปเดตตารางวันหยุด</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
