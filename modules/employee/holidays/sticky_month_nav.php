<?php
/**
 * Sticky month navigator — calendar + list views.
 *
 * @var int $holidayYearTh
 * @var array $holidaysByMonth
 * @var bool $isCurrentYear
 * @var string $view
 */
?>
<div class="tp-holidays-sticky-nav" id="tp-holidays-sticky-nav">
    <?php /* Phone: a disclosure that opens a 12-month grid. >=640px: an inert label
             above the chip row, which fits there without horizontal scrolling. */ ?>
    <button type="button"
            class="tp-holidays-sticky-nav__label touch-manipulation"
            id="tp-holidays-month-picker-toggle"
            aria-expanded="false"
            aria-controls="tp-holidays-sticky-chips">
        <span id="tp-holidays-sticky-label" aria-live="polite"><?php echo thaiMonth((int) date('n')); ?> <?php echo (int) $holidayYearTh; ?></span>
        <i class="fas fa-chevron-down tp-holidays-sticky-nav__caret" aria-hidden="true"></i>
        <span class="tp-visually-hidden">เลือกเดือน</span>
    </button>
    <div class="tp-holidays-month-scroll tp-holidays-sticky-nav__chips" id="tp-holidays-sticky-chips" role="navigation" aria-label="ข้ามเดือน">
        <?php for ($m = 1; $m <= 12; $m++):
            $monthCount = count($holidaysByMonth[$m] ?? []);
            $hasHoliday = $monthCount > 0;
            $isCurrentMonth = $isCurrentYear && $m === (int) date('n');
            $anchorId = $view === 'calendar' ? 'cal-month-' . $m : 'holiday-month-' . $m;
            if ($view === 'list' && !$hasHoliday) {
                continue;
            }
            $chipClass = 'tp-holidays-month-chip touch-manipulation';
            if ($hasHoliday) {
                $chipClass .= ' has-holidays';
            } else {
                $chipClass .= ' is-empty-month';
            }
            if ($isCurrentMonth) {
                $chipClass .= ' is-current';
            }
        ?>
        <button type="button"
                class="<?php echo $chipClass; ?>"
                data-month="<?php echo $m; ?>"
                data-anchor="<?php echo htmlspecialchars($anchorId); ?>"
                data-label="<?php echo htmlspecialchars(thaiMonth($m) . ' ' . $holidayYearTh); ?>"
                aria-label="<?php echo htmlspecialchars(thaiMonth($m)); ?><?php echo $hasHoliday ? ' — ' . $monthCount . ' วัน' : ''; ?>">
            <span><?php echo thaiMonthShort($m); ?></span>
            <?php if ($hasHoliday): ?>
            <span class="tp-holidays-month-chip__count"><?php echo (int) $monthCount; ?></span>
            <?php endif; ?>
        </button>
        <?php endfor; ?>
    </div>
</div>
