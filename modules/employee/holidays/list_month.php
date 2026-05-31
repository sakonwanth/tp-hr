<?php
/**
 * @var int $m
 * @var int $holidayYearTh
 * @var array $holidaysByMonth
 * @var bool $isCurrentYear
 * @var string $today
 * @var array $dayNames
 * @var callable $holidayTypeLabel
 * @var callable $yearQuery
 * @var int $holidayYear
 */
$isCurrentMonth = $isCurrentYear && $m === (int) date('n');
?>
<section class="tp-holidays-list-section scroll-mt-24"
         id="holiday-month-<?php echo $m; ?>"
         data-holiday-month="<?php echo $m; ?>"
         data-month-label="<?php echo htmlspecialchars(thaiMonth($m) . ' ' . $holidayYearTh); ?>"
         aria-labelledby="holiday-month-<?php echo $m; ?>-title">
    <div class="tp-holidays-list-section__head">
        <h3 id="holiday-month-<?php echo $m; ?>-title" class="tp-holidays-list-section__title">
            <?php echo thaiMonth($m); ?> <?php echo (int) $holidayYearTh; ?>
            <?php if ($isCurrentMonth): ?> · เดือนนี้<?php endif; ?>
        </h3>
    </div>

    <div class="tp-holidays-list-group">
        <?php foreach ($holidaysByMonth[$m] as $holiday):
            $isPast = $holiday['date'] < $today;
            $isToday = $holiday['date'] === $today;
            $dow = (int) date('w', strtotime($holiday['date']));
            $rowClass = 'tp-holidays-list-row';
            if ($isPast) {
                $rowClass .= ' is-past';
            }
            if ($isToday) {
                $rowClass .= ' is-today';
            }
        ?>
        <button type="button"
                class="<?php echo $rowClass; ?>"
                data-holiday-id="<?php echo (int) $holiday['id']; ?>"
                aria-label="ดูรายละเอียด <?php echo htmlspecialchars($holiday['name']); ?>">
            <div class="tp-holidays-list-date" aria-hidden="true">
                <div class="tp-holidays-list-date__day"><?php echo (int) date('j', strtotime($holiday['date'])); ?></div>
                <div class="tp-holidays-list-date__mon"><?php echo thaiMonthShort($m); ?></div>
            </div>
            <div class="tp-holidays-list-body">
                <p class="tp-holidays-list-body__title"><?php echo htmlspecialchars($holiday['name']); ?></p>
                <p class="tp-holidays-list-body__meta">
                    <?php echo formatDateThai($holiday['date']); ?>
                    · วัน<?php echo htmlspecialchars($dayNames[$dow] ?? ''); ?>
                    <?php if ($isToday): ?> · วันนี้<?php endif; ?>
                </p>
            </div>
            <span class="tp-holidays-list-type"><?php echo htmlspecialchars($holidayTypeLabel((string) $holiday['type'])); ?></span>
            <i class="fas fa-chevron-right text-white/25 shrink-0 text-xs ml-1" aria-hidden="true"></i>
        </button>
        <?php endforeach; ?>
    </div>
</section>
