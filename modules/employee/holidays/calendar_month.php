<?php
/**
 * @var int $m
 * @var int $holidayYear
 * @var array $holidaysByMonth
 * @var bool $isCurrentYear
 * @var callable $buildMiniMonth
 * @var callable $holidaysCalDayClass
 */
$monthCells = $buildMiniMonth($holidayYear, $m);
$monthHolidayCount = count($holidaysByMonth[$m] ?? []);
$isCurrentMonth = $isCurrentYear && $m === (int) date('n');
$dayNamesGrid = ['จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.'];
?>
<section id="cal-month-<?php echo $m; ?>"
         class="tp-holidays-month <?php echo $isCurrentMonth ? 'is-current' : ''; ?>"
         data-holiday-month="<?php echo $m; ?>"
         data-month-label="<?php echo htmlspecialchars(thaiMonth($m) . ' ' . ($holidayYear + 543)); ?>"
         aria-labelledby="cal-month-title-<?php echo $m; ?>">
    <header class="tp-holidays-month__head">
        <h3 id="cal-month-title-<?php echo $m; ?>" class="tp-holidays-month__title"><?php echo thaiMonth($m); ?></h3>
        <?php if ($monthHolidayCount > 0): ?>
        <span class="tp-holidays-month__badge"><?php echo (int) $monthHolidayCount; ?></span>
        <?php endif; ?>
    </header>

    <div class="tp-holidays-cal-weekdays" aria-hidden="true">
        <?php foreach ($dayNamesGrid as $dn): ?>
        <div class="tp-holidays-cal-weekday"><?php echo $dn; ?></div>
        <?php endforeach; ?>
    </div>

    <div class="tp-holidays-cal-days">
        <?php foreach ($monthCells as $cell): ?>
        <?php if (!empty($cell['empty'])): ?>
        <div aria-hidden="true"></div>
        <?php else:
            $holiday = $cell['holiday'];
            $cellClass = $holidaysCalDayClass($holiday, $cell['isToday'], $cell['isPast']);
            $title = $holiday
                ? ($holiday['name'] . ' — ' . formatDateThai($cell['date']))
                : formatDateThai($cell['date']);
        ?>
        <?php if ($holiday): ?>
        <button type="button"
                class="<?php echo $cellClass; ?>"
                title="<?php echo htmlspecialchars($title); ?>"
                data-holiday-id="<?php echo (int) $holiday['id']; ?>"
                aria-label="<?php echo htmlspecialchars($holiday['name'] . ' ' . formatDateThai($cell['date'])); ?>">
            <?php echo (int) $cell['day']; ?>
        </button>
        <?php else: ?>
        <div class="<?php echo $cellClass; ?>"
             title="<?php echo htmlspecialchars($title); ?>"
             aria-hidden="true">
            <?php echo (int) $cell['day']; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?php if ($monthHolidayCount > 0): ?>
    <ul class="tp-holidays-month__names">
        <?php foreach ($holidaysByMonth[$m] as $holiday): ?>
        <li>
            <button type="button"
                    class="tp-holidays-month__name-row touch-manipulation"
                    data-holiday-id="<?php echo (int) $holiday['id']; ?>"
                    aria-label="ดูรายละเอียด <?php echo htmlspecialchars($holiday['name']); ?>">
                <span class="tp-holidays-month__name-day"><?php echo (int) date('j', strtotime($holiday['date'])); ?></span>
                <span class="tp-holidays-month__name-text"><?php echo htmlspecialchars($holiday['name']); ?></span>
            </button>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
