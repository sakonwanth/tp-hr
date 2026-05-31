<?php
/** @var int $holidayYearTh */
/** @var bool $canManageHolidays */
/** @var int $holidayYear */
?>
<div class="tp-native-empty-state text-center py-12 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-0">
    <div class="inline-flex h-16 w-16 items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 mb-4">
        <i class="fas fa-calendar-times text-2xl text-white/30" aria-hidden="true"></i>
    </div>
    <p class="text-white/75 text-base font-medium">ยังไม่มีวันหยุดประจำปี <?php echo (int) $holidayYearTh; ?></p>
    <p class="text-white/45 text-sm mt-2 max-w-sm mx-auto px-4">HR ยังไม่ได้บันทึกตารางวันหยุดสำหรับปีนี้</p>
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
