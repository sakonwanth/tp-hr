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
$dayNames = THAI_DAY_NAMES;

$holidaysForJs = [];
foreach ($holidays as $holiday) {
    $dow = (int) date('w', strtotime($holiday['date']));
    $holidaysForJs[(int) $holiday['id']] = [
        'id' => (int) $holiday['id'],
        'date' => $holiday['date'],
        'name' => $holiday['name'],
        'name_en' => $holiday['name_en'] ?? '',
        'type' => $holiday['type'],
        'type_label' => $holidayTypeLabel((string) $holiday['type']),
        'description' => $holiday['description'] ?? '',
        'date_th' => formatDateThai($holiday['date']),
        'dow' => 'วัน' . ($dayNames[$dow] ?? ''),
        'month_short' => thaiMonthShort((int) date('n', strtotime($holiday['date']))),
        'day' => (int) date('j', strtotime($holiday['date'])),
        'is_today' => $holiday['date'] === $today,
        'is_past' => $holiday['date'] < $today,
    ];
}

require_once __DIR__ . '/templates/header.php';
?>

<div class="tp-holidays-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(1200px,100%)] mx-auto min-w-0">
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
        <div class="tp-holidays-toolbar__row">
            <form method="GET" class="tp-holidays-year-row">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">

                <a href="<?php echo $yearQuery($prevYear, $view); ?>"
                   class="tp-holidays-year-btn touch-manipulation"
                   aria-label="ปี <?php echo (int) ($prevYear + 543); ?>">
                    <i class="fas fa-chevron-left" aria-hidden="true"></i>
                </a>

                <div class="tp-holidays-year-display">
                    <p class="tp-holidays-year-number">
                        <?php echo (int) $holidayYearTh; ?>
                        <i class="fas fa-chevron-down tp-holidays-year-caret" aria-hidden="true"></i>
                    </p>
                    <p class="tp-holidays-year-sub">พ.ศ. · ค.ศ. <?php echo (int) $holidayYear; ?></p>
                    <label for="holiday-year-select" class="tp-visually-hidden">เลือกปี</label>
                    <select id="holiday-year-select" name="year"
                            class="tp-holidays-year-select touch-manipulation"
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
            </form>

            <div class="tp-holidays-toolbar__controls min-w-0">
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

                <div class="tp-holidays-toolbar-actions">
                    <?php if ($holidayCount > 0): ?>
                    <a href="holidays_print.php?year=<?php echo (int) $holidayYear; ?>&amp;auto=pdf"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="tp-holidays-export-btn touch-manipulation">
                        <i class="fas fa-file-pdf" aria-hidden="true"></i>
                        <span>PDF</span>
                    </a>
                    <a href="holidays_print.php?year=<?php echo (int) $holidayYear; ?>&amp;auto=png"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="tp-holidays-export-btn touch-manipulation">
                        <i class="fas fa-file-image" aria-hidden="true"></i>
                        <span>PNG</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($canManageHolidays): ?>
                    <a href="/hr/settings.php?tab=holidays&amp;year=<?php echo (int) $holidayYear; ?>"
                       class="tp-holidays-manage-btn touch-manipulation">
                        <i class="fas fa-cog" aria-hidden="true"></i>
                        <span>จัดการวันหยุด</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
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
    </div>

    <div class="tp-holidays-layout min-w-0">
        <?php if ($nextHoliday && $isCurrentYear): ?>
        <div class="tp-holidays-next-slot min-w-0">
            <button type="button"
                    class="tp-holidays-next-card tp-holidays-next-card--clickable touch-manipulation w-full text-left"
                    data-holiday-id="<?php echo (int) $nextHoliday['id']; ?>"
                    aria-label="ดูรายละเอียดวันหยุดถัดไป <?php echo htmlspecialchars($nextHoliday['name']); ?>">
                <div class="tp-holidays-next-card__top">
                    <p class="tp-holidays-next-card__label">วันหยุดถัดไป</p>
                    <span class="tp-holidays-next-card__badge">
                        <?php if ($daysUntilNext === 0): ?>
                        วันนี้
                        <?php else: ?>
                        อีก <?php echo (int) $daysUntilNext; ?> วัน
                        <?php endif; ?>
                    </span>
                </div>
                <div class="tp-holidays-next-card__body">
                    <div class="tp-holidays-next-card__date" aria-hidden="true">
                        <span class="tp-holidays-next-card__date-mon"><?php echo thaiMonthShort((int) date('n', strtotime($nextHoliday['date']))); ?></span>
                        <span class="tp-holidays-next-card__date-day"><?php echo (int) date('j', strtotime($nextHoliday['date'])); ?></span>
                    </div>
                    <div class="tp-holidays-next-card__info min-w-0 flex-1">
                        <p class="tp-holidays-next-card__name"><?php echo htmlspecialchars($nextHoliday['name']); ?></p>
                        <p class="tp-holidays-next-card__meta"><?php echo formatDateThai($nextHoliday['date']); ?></p>
                    </div>
                    <i class="fas fa-chevron-right tp-holidays-next-card__chevron" aria-hidden="true"></i>
                </div>
            </button>
        </div>
        <?php endif; ?>

        <div class="tp-holidays-main min-w-0">
            <div class="tp-holidays-main-card min-w-0">
                <?php if ($holidayCount > 0): ?>
                <?php include __DIR__ . '/modules/employee/holidays/sticky_month_nav.php'; ?>
                <?php endif; ?>

                <?php if ($view === 'calendar'): ?>
                <div class="tp-holidays-main-head">
                    <h2 class="section-title">
                        <i class="fas fa-calendar-alt text-violet-400" aria-hidden="true"></i>
                        ปฏิทิน <?php echo (int) $holidayYearTh; ?>
                    </h2>
                    <?php if ($holidays): ?>
                    <div class="tp-holidays-pager-nav" id="tp-holidays-pager-nav" hidden>
                        <button type="button" class="tp-holidays-year-btn touch-manipulation"
                                data-pager-step="-1" aria-label="เดือนก่อนหน้า">
                            <i class="fas fa-chevron-left" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="tp-holidays-pager-all touch-manipulation"
                                id="tp-holidays-pager-toggle" aria-pressed="false">ดูทั้งปี</button>
                        <button type="button" class="tp-holidays-year-btn touch-manipulation"
                                data-pager-step="1" aria-label="เดือนถัดไป">
                            <i class="fas fa-chevron-right" aria-hidden="true"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($holidays): ?>
                <div class="tp-holidays-calendar-grid" id="tp-holidays-calendar-grid">
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
                <div class="tp-holidays-list-grid">
                    <?php for ($m = 1; $m <= 12; $m++):
                        if (empty($holidaysByMonth[$m])) {
                            continue;
                        }
                        include __DIR__ . '/modules/employee/holidays/list_month.php';
                    endfor; ?>
                </div>
                <?php else: ?>
                <?php include __DIR__ . '/modules/employee/holidays/empty_state.php'; ?>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <aside class="tp-holidays-aside min-w-0">
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
                    <a href="holiday_work_request.php" class="tp-holidays-link-row touch-manipulation">
                        <span class="tp-holidays-link-row__icon"><i class="fas fa-briefcase" aria-hidden="true"></i></span>
                        <span class="tp-holidays-link-row__text">
                            <span class="tp-holidays-link-row__title">ทำงานวันหยุด / หยุดชดเชย</span>
                            <span class="tp-holidays-link-row__sub">ขอมาทำงานวันหยุดประจำปีและหยุดวันอื่นแทน</span>
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
                    <li class="flex items-center gap-3 min-h-[48px]">
                        <span class="inline-flex h-4 w-4 shrink-0 rounded-full bg-violet-500/70 ring-1 ring-violet-300/40" aria-hidden="true"></span>
                        วันหยุดนักขัตฤกษ์ / บริษัท
                    </li>
                    <li class="flex items-center gap-3 min-h-[48px]">
                        <span class="inline-flex h-4 w-4 shrink-0 rounded-full bg-white/12 ring-2 ring-violet-400/55" aria-hidden="true"></span>
                        วันนี้
                    </li>
                </ul>
            </div>
            <?php endif; ?>
        </aside>
    </div>
</div>

<?php include __DIR__ . '/modules/employee/holidays/detail_modal.php'; ?>

<script>
window.tpHolidaysById = <?php echo json_encode($holidaysForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
window.tpHolidaysToday = <?php echo json_encode($today); ?>;

function tpHolidaysOpenDetail(id) {
    var data = window.tpHolidaysById && window.tpHolidaysById[id];
    if (!data) return;

    document.getElementById('tp-holiday-detail-type').textContent = data.type_label || '';
    document.getElementById('tp-holiday-detail-title').textContent = data.name || '';
    var nameEn = document.getElementById('tp-holiday-detail-name-en');
    if (data.name_en) {
        nameEn.textContent = data.name_en;
        nameEn.classList.remove('hidden');
    } else {
        nameEn.classList.add('hidden');
    }
    document.getElementById('tp-holiday-detail-mon').textContent = data.month_short || '';
    document.getElementById('tp-holiday-detail-day').textContent = data.day || '';
    document.getElementById('tp-holiday-detail-date-th').textContent = data.date_th || '';
    document.getElementById('tp-holiday-detail-dow').textContent = data.dow || '';

    var countdown = document.getElementById('tp-holiday-detail-countdown');
    if (!data.is_past && data.date) {
        var diff = Math.round((new Date(data.date + 'T00:00:00') - new Date(window.tpHolidaysToday + 'T00:00:00')) / 86400000);
        if (diff === 0) {
            countdown.textContent = 'วันนี้';
            countdown.classList.remove('hidden');
        } else if (diff > 0) {
            countdown.textContent = 'อีก ' + diff + ' วัน';
            countdown.classList.remove('hidden');
        } else {
            countdown.classList.add('hidden');
        }
    } else {
        countdown.classList.add('hidden');
    }

    var descWrap = document.getElementById('tp-holiday-detail-desc-wrap');
    var desc = document.getElementById('tp-holiday-detail-desc');
    if (data.description) {
        desc.textContent = data.description;
        descWrap.classList.remove('hidden');
    } else {
        descWrap.classList.add('hidden');
    }

    if (typeof uiOpenModal === 'function') uiOpenModal('tp-holiday-detail-modal');
    else document.getElementById('tp-holiday-detail-modal').classList.remove('hidden');
}

function tpHolidaysCloseDetail() {
    if (typeof uiCloseModal === 'function') uiCloseModal('tp-holiday-detail-modal');
    else document.getElementById('tp-holiday-detail-modal').classList.add('hidden');
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-holiday-id]').forEach(function(el) {
        el.addEventListener('click', function() {
            var id = parseInt(el.getAttribute('data-holiday-id'), 10);
            if (id) tpHolidaysOpenDetail(id);
        });
        if (el.getAttribute('role') === 'button' && !el.hasAttribute('data-holiday-id-listener')) {
            el.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    var id = parseInt(el.getAttribute('data-holiday-id'), 10);
                    if (id) tpHolidaysOpenDetail(id);
                }
            });
        }
    });

    var modal = document.getElementById('tp-holiday-detail-modal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) tpHolidaysCloseDetail();
        });
    }

    var sections = document.querySelectorAll('[data-holiday-month]');
    var labelEl = document.getElementById('tp-holidays-sticky-label');
    var chips = document.querySelectorAll('#tp-holidays-sticky-chips [data-month]');
    if (!sections.length || !labelEl) return;

    function setActiveMonth(month, monthLabel) {
        if (monthLabel) labelEl.textContent = monthLabel;
        chips.forEach(function(chip) {
            var isActive = chip.getAttribute('data-month') === String(month);
            chip.classList.toggle('is-active', isActive);
            if (isActive) {
                chip.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            }
        });
    }

    /* ---- Phone month pager --------------------------------------------------
       Twelve mini-grids stacked is ~7000px of scroll on a phone. Below 640px the
       calendar shows one month at a time (chips + arrows switch it); "ดูทั้งปี"
       restores the full stack. No-JS and >=640px keep the full grid. */
    var calGrid = document.getElementById('tp-holidays-calendar-grid');
    var pagerNav = document.getElementById('tp-holidays-pager-nav');
    var pagerToggle = document.getElementById('tp-holidays-pager-toggle');
    var monthSections = calGrid ? calGrid.querySelectorAll('[data-holiday-month]') : [];
    var phoneMq = window.matchMedia('(max-width: 639px)');
    var pagerOn = false;
    var pagerExpanded = false;
    var pagerMonth = <?php echo (int) ($isCurrentYear ? (int) date('n') : (($holidays[0] ?? null) ? (int) date('n', strtotime($holidays[0]['date'])) : 1)); ?>;

    function monthSectionFor(month) {
        for (var i = 0; i < monthSections.length; i++) {
            if (monthSections[i].getAttribute('data-holiday-month') === String(month)) return monthSections[i];
        }
        return null;
    }

    function renderPager() {
        if (!calGrid) return;
        var single = pagerOn && !pagerExpanded;
        calGrid.classList.toggle('is-pager', single);
        for (var i = 0; i < monthSections.length; i++) {
            var isSel = monthSections[i].getAttribute('data-holiday-month') === String(pagerMonth);
            monthSections[i].classList.toggle('is-pager-visible', isSel);
        }
        if (pagerNav) pagerNav.hidden = !pagerOn;
        if (pagerToggle) {
            pagerToggle.textContent = pagerExpanded ? 'ดูทีละเดือน' : 'ดูทั้งปี';
            pagerToggle.setAttribute('aria-pressed', pagerExpanded ? 'true' : 'false');
        }
        if (single) {
            var sec = monthSectionFor(pagerMonth);
            setActiveMonth(pagerMonth, sec ? sec.getAttribute('data-month-label') : '');
        }
    }

    function setPagerMonth(month, scrollIntoView) {
        month = Math.min(12, Math.max(1, parseInt(month, 10) || 1));
        pagerMonth = month;
        renderPager();
        if (scrollIntoView && calGrid) {
            calGrid.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function syncPagerMode() {
        pagerOn = !!calGrid && phoneMq.matches;
        if (!pagerOn) pagerExpanded = false;
        renderPager();
    }

    if (pagerNav) {
        pagerNav.querySelectorAll('[data-pager-step]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var step = parseInt(btn.getAttribute('data-pager-step'), 10) || 0;
                setPagerMonth(pagerMonth + step, false);
            });
        });
    }
    if (pagerToggle) {
        pagerToggle.addEventListener('click', function() {
            pagerExpanded = !pagerExpanded;
            renderPager();
        });
    }
    if (phoneMq.addEventListener) {
        phoneMq.addEventListener('change', syncPagerMode);
    } else if (phoneMq.addListener) {
        phoneMq.addListener(syncPagerMode);
    }
    syncPagerMode();

    chips.forEach(function(chip) {
        chip.addEventListener('click', function() {
            var month = chip.getAttribute('data-month');
            if (pagerOn && !pagerExpanded) {
                setPagerMonth(month, true);
                return;
            }
            var anchorId = chip.getAttribute('data-anchor');
            var target = anchorId ? document.getElementById(anchorId) : null;
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            setActiveMonth(month, chip.getAttribute('data-label'));
        });
    });

    if ('IntersectionObserver' in window) {
        var visible = new Map();
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                var month = entry.target.getAttribute('data-holiday-month');
                if (entry.isIntersecting) {
                    visible.set(month, entry.intersectionRatio);
                } else {
                    visible.delete(month);
                }
            });
            if (!visible.size) return;
            var bestMonth = null;
            var bestRatio = -1;
            visible.forEach(function(ratio, month) {
                if (ratio > bestRatio) {
                    bestRatio = ratio;
                    bestMonth = month;
                }
            });
            if (bestMonth) {
                var section = document.querySelector('[data-holiday-month="' + bestMonth + '"]');
                var monthLabel = section ? section.getAttribute('data-month-label') : '';
                setActiveMonth(bestMonth, monthLabel);
            }
        }, { root: null, rootMargin: '-30% 0px -55% 0px', threshold: [0, 0.15, 0.35, 0.55] });

        sections.forEach(function(section) { observer.observe(section); });
    } else if (sections[0]) {
        setActiveMonth(sections[0].getAttribute('data-holiday-month'), sections[0].getAttribute('data-month-label'));
    }
});
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
