<?php
/**
 * Partial: รายละเอียดรายวัน (สาย / ลา / ขาด / สลับวันหยุด)
 * @var array $summary
 * @var bool $compact
 * @var bool $panelLayout ใช้ในแผงขยายตาราง (จัด grid 2 คอลัมน์)
 */
if (empty($summary) || !is_array($summary)) {
    return;
}
$d = $summary['details'] ?? [];
$leaveStatus = defined('LEAVE_STATUS') ? LEAVE_STATUS : [];
$compact = !empty($compact);
$panelLayout = !empty($panelLayout);
$employeeId = (int)($employeeId ?? $summary['user_id'] ?? 0);
$showActions = !empty($showActions) && $employeeId > 0;
$isCeo = function_exists('isCEOOrAbove') && isCEOOrAbove();

$attFixUrl = static function (int $uid, string $date) {
    return '/hr/attendance.php?' . http_build_query(['date' => $date, 'user_id' => $uid, 'fix' => 1]);
};

$hasLate = !empty($d['late']);
$hasAbsent = !empty($d['absent']);
$hasWfh = !empty($d['wfh']);
$hasLeaveAtt = !empty($d['leave_attendance']);
$hasLeaveReq = !empty($summary['leave_requests']);
$hasHolidays = !empty($d['holidays']);
$hasSwaps = !empty($summary['dayoff_swaps']);
$hasPresent = !empty($d['present']);

if (!$hasLate && !$hasAbsent && !$hasWfh && !$hasLeaveAtt && !$hasLeaveReq && !$hasHolidays && !$hasSwaps) {
    echo '<p class="text-white/50 text-sm py-4 px-1">ไม่มีรายการพิเศษในเดือนนี้</p>';
    return;
}

$wrapClass = $panelLayout
    ? 'grid grid-cols-1 xl:grid-cols-2 gap-5 lg:gap-6'
    : ($compact ? 'flex flex-col gap-4' : 'flex flex-col gap-5');
$cardClass = 'rounded-[var(--tp-ios-card-radius)] bg-white/[0.04] border border-white/10 overflow-hidden shadow-sm';
$headClass = $panelLayout
    ? 'px-5 py-4 text-sm font-semibold bg-white/[0.03] border-b border-white/10'
    : ($compact ? 'px-4 py-3 text-sm font-semibold bg-white/[0.03] border-b border-white/10' : 'px-5 py-4 text-sm font-semibold bg-white/[0.03] border-b border-white/10');
$listClass = 'divide-y divide-white/[0.06]';
$rowPad = $panelLayout ? 'px-5 py-4' : ($compact ? 'px-4 py-3.5' : 'px-5 py-4');
$actionBtn = 'inline-flex items-center justify-center gap-1.5 min-h-[40px] px-3 py-2 rounded-[var(--tp-ios-card-radius)] text-xs font-medium bg-violet-500/12 hover:bg-violet-500/22 text-violet-200 border border-violet-500/25 touch-manipulation shrink-0 whitespace-nowrap';

$renderDayRow = static function (array $item, string $toneClass, array $metaParts, ?string $actionHref = null) use ($rowPad, $actionBtn, $showActions): void {
    ?>
    <li class="<?php echo $rowPad; ?>">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="text-white font-semibold tabular-nums text-sm"><?php echo formatDateThai($item['date']); ?></span>
                    <?php if (!empty($item['day_label'])): ?>
                    <span class="text-white/45 text-xs"><?php echo htmlspecialchars($item['day_label']); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($metaParts): ?>
                <p class="mt-1.5 text-sm <?php echo $toneClass; ?> leading-relaxed"><?php echo implode(' · ', $metaParts); ?></p>
                <?php endif; ?>
            </div>
            <?php if ($showActions && $actionHref): ?>
            <a href="<?php echo htmlspecialchars($actionHref); ?>" class="<?php echo $actionBtn; ?>">
                <i class="fas fa-edit text-[11px]" aria-hidden="true"></i>แก้ไขเวลา
            </a>
            <?php endif; ?>
        </div>
    </li>
    <?php
};
?>

<div class="emp-summary-details <?php echo $wrapClass; ?>">
    <?php if ($hasLate): ?>
    <section class="<?php echo $cardClass; ?>">
        <h4 class="<?php echo $headClass; ?> text-amber-300">
            <i class="fas fa-clock mr-2 opacity-80" aria-hidden="true"></i>มาสาย <?php echo count($d['late']); ?> วัน
        </h4>
        <ul class="<?php echo $listClass; ?>">
            <?php foreach ($d['late'] as $item):
                $meta = [];
                if (!empty($item['check_in'])) {
                    $meta[] = 'เข้า ' . htmlspecialchars($item['check_in']);
                }
                if ((int)($item['late_minutes'] ?? 0) > 0) {
                    $meta[] = 'สาย ' . (int)$item['late_minutes'] . ' นาที';
                }
                $renderDayRow($item, 'text-amber-300/90', $meta, $showActions ? $attFixUrl($employeeId, $item['date']) : null);
            endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($hasAbsent): ?>
    <section class="<?php echo $cardClass; ?>">
        <h4 class="<?php echo $headClass; ?> text-red-300">
            <i class="fas fa-user-times mr-2 opacity-80" aria-hidden="true"></i>ขาดงาน <?php echo count($d['absent']); ?> วัน
        </h4>
        <ul class="<?php echo $listClass; ?> max-h-[min(420px,50vh)] overflow-y-auto overscroll-contain">
            <?php foreach ($d['absent'] as $item):
                $meta = [htmlspecialchars($item['reason'] ?? 'ขาดงาน')];
                $renderDayRow($item, 'text-red-300/90', $meta, $showActions ? $attFixUrl($employeeId, $item['date']) : null);
            endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($hasLeaveReq): ?>
    <section class="<?php echo $cardClass; ?><?php echo $panelLayout && ($hasLate || $hasAbsent) ? ' xl:col-span-2' : ''; ?>">
        <h4 class="<?php echo $headClass; ?> text-blue-300">
            <i class="fas fa-calendar-minus mr-2 opacity-80" aria-hidden="true"></i>ใบลาในเดือนนี้
        </h4>
        <div class="<?php echo $listClass; ?>">
            <?php foreach ($summary['leave_requests'] as $lr): ?>
            <div class="<?php echo $rowPad; ?>">
                <div class="flex flex-wrap items-center gap-2 mb-3">
                    <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold border border-white/10"
                          style="background-color: <?php echo htmlspecialchars($lr['color'] ?? '#3B82F6'); ?>18; color: <?php echo htmlspecialchars($lr['color'] ?? '#93C5FD'); ?>">
                        <?php echo htmlspecialchars($lr['leave_type_name'] ?? $lr['code'] ?? 'ลา'); ?>
                    </span>
                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold <?php
                        echo match($lr['status'] ?? '') {
                            'APPROVED' => 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/25',
                            'PENDING' => 'bg-amber-500/15 text-amber-300 border border-amber-500/25',
                            default => 'bg-slate-500/15 text-slate-300 border border-slate-500/25',
                        };
                    ?>"><?php echo htmlspecialchars($leaveStatus[$lr['status']] ?? ($lr['status'] ?? '')); ?></span>
                    <span class="text-white/55 text-sm">
                        <?php echo formatDateThai($lr['start_date']); ?>
                        <?php if ($lr['start_date'] !== $lr['end_date']): ?>
                        – <?php echo formatDateThai($lr['end_date']); ?>
                        <?php endif; ?>
                        · <?php echo number_format((float)$lr['total_days'], 1); ?> วัน
                    </span>
                </div>
                <?php if (!empty($lr['days_in_month'])): ?>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($lr['days_in_month'] as $day): ?>
                    <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-[var(--tp-ios-card-radius)] bg-blue-500/10 text-blue-100 text-xs border border-blue-500/20">
                        <span class="tabular-nums font-medium"><?php echo formatDateThai($day['date']); ?></span>
                        <span class="text-blue-300/55"><?php echo htmlspecialchars($day['day_label']); ?></span>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!$compact && !empty($lr['reason'])): ?>
                <p class="text-white/45 text-sm mt-3 leading-relaxed"><?php echo htmlspecialchars($lr['reason']); ?></p>
                <?php endif; ?>
                <?php if ($showActions && ($lr['status'] ?? '') === 'PENDING'): ?>
                <a href="/hr/leaves.php?status=pending" class="inline-flex items-center gap-1.5 mt-4 min-h-[40px] px-3 py-2 rounded-[var(--tp-ios-card-radius)] text-xs font-medium bg-amber-500/12 hover:bg-amber-500/22 text-amber-200 border border-amber-500/25 touch-manipulation">
                    <i class="fas fa-check text-[11px]" aria-hidden="true"></i>ไปอนุมัติใบลา
                </a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php elseif ($hasLeaveAtt): ?>
    <section class="<?php echo $cardClass; ?>">
        <h4 class="<?php echo $headClass; ?> text-blue-300">
            <i class="fas fa-calendar-minus mr-2 opacity-80" aria-hidden="true"></i>ลา (จากการลงเวลา) <?php echo count($d['leave_attendance']); ?> วัน
        </h4>
        <ul class="<?php echo $listClass; ?>">
            <?php foreach ($d['leave_attendance'] as $item):
                $renderDayRow($item, 'text-blue-300/90', [], null);
            endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($hasWfh): ?>
    <section class="<?php echo $cardClass; ?>">
        <h4 class="<?php echo $headClass; ?> text-purple-300">
            <i class="fas fa-house-laptop mr-2 opacity-80" aria-hidden="true"></i>WFH <?php echo count($d['wfh']); ?> วัน
        </h4>
        <ul class="<?php echo $listClass; ?>">
            <?php foreach ($d['wfh'] as $item):
                $meta = [];
                if (!empty($item['check_in'])) {
                    $meta[] = 'เข้า ' . htmlspecialchars($item['check_in']) . ' – ออก ' . htmlspecialchars($item['check_out'] ?? '-');
                }
                $renderDayRow($item, 'text-purple-300/90', $meta, $showActions ? $attFixUrl($employeeId, $item['date']) : null);
            endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($hasSwaps): ?>
    <section class="<?php echo $cardClass; ?>">
        <h4 class="<?php echo $headClass; ?> text-violet-300">
            <i class="fas fa-calendar-day mr-2 opacity-80" aria-hidden="true"></i>การเปลี่ยนวันหยุด
        </h4>
        <div class="<?php echo $listClass; ?>">
            <?php foreach ($summary['dayoff_swaps'] as $swap): ?>
            <div class="<?php echo $rowPad; ?>">
                <div class="flex flex-wrap items-center gap-2 mb-3">
                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold <?php
                        echo match($swap['status'] ?? '') {
                            'APPROVED' => 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/25',
                            'PENDING' => 'bg-amber-500/15 text-amber-300 border border-amber-500/25',
                            'REJECTED' => 'bg-red-500/15 text-red-300 border border-red-500/25',
                            default => 'bg-slate-500/15 text-slate-300 border border-slate-500/25',
                        };
                    ?>"><?php echo htmlspecialchars($leaveStatus[$swap['status']] ?? ($swap['status'] ?? '-')); ?></span>
                    <span class="text-sky-300 text-sm"><?php echo htmlspecialchars($swap['original_day_label'] ?? ''); ?></span>
                    <i class="fas fa-arrow-right text-white/30 text-xs" aria-hidden="true"></i>
                    <span class="text-violet-300 font-medium text-sm"><?php echo htmlspecialchars($swap['requested_day_label'] ?? ''); ?></span>
                </div>
                <p class="text-white/45 text-xs mb-3">
                    สัปดาห์ <?php echo formatDateThai($swap['week_start'] ?? ''); ?> – <?php echo formatDateThai($swap['week_end'] ?? ''); ?>
                </p>
                <?php if (!empty($swap['affected_days'])): ?>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($swap['affected_days'] as $ad): ?>
                    <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-[var(--tp-ios-card-radius)] bg-violet-500/10 text-violet-100 text-xs border border-violet-500/20">
                        <span class="tabular-nums font-medium"><?php echo formatDateThai($ad['date']); ?></span>
                        <span class="text-violet-300/60"><?php echo htmlspecialchars($ad['change'] ?? ''); ?></span>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if ($showActions && ($swap['status'] ?? '') === 'PENDING' && $isCeo): ?>
                <a href="/hr/dayoff_approvals.php" class="inline-flex items-center gap-1.5 mt-4 min-h-[40px] px-3 py-2 rounded-[var(--tp-ios-card-radius)] text-xs font-medium bg-violet-500/12 hover:bg-violet-500/22 text-violet-200 border border-violet-500/25 touch-manipulation">
                    <i class="fas fa-check text-[11px]" aria-hidden="true"></i>ไปอนุมัติสลับวันหยุด
                </a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!$compact && $hasHolidays): ?>
    <section class="<?php echo $cardClass; ?>">
        <h4 class="<?php echo $headClass; ?> text-emerald-300">
            <i class="fas fa-star mr-2 opacity-80" aria-hidden="true"></i>วันหยุดนักขัตฤกษ์/บริษัท <?php echo count($d['holidays']); ?> วัน
        </h4>
        <ul class="<?php echo $listClass; ?>">
            <?php foreach ($d['holidays'] as $item):
                $renderDayRow($item, 'text-emerald-300/90', [htmlspecialchars($item['name'] ?? '')], null);
            endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if (!$compact && $hasPresent && empty($compactHidePresent)): ?>
    <details class="<?php echo $cardClass; ?> group xl:col-span-2">
        <summary class="<?php echo $headClass; ?> text-emerald-300 cursor-pointer list-none flex items-center justify-between touch-manipulation">
            <span><i class="fas fa-check-circle mr-2 opacity-80" aria-hidden="true"></i>มาทำงานครบ <?php echo count($d['present']); ?> วัน</span>
            <i class="fas fa-chevron-down text-white/40 group-open:rotate-180 transition-transform text-xs" aria-hidden="true"></i>
        </summary>
        <ul class="<?php echo $listClass; ?> max-h-72 overflow-y-auto">
            <?php foreach ($d['present'] as $item):
                $meta = [];
                if (!empty($item['check_in'])) {
                    $meta[] = htmlspecialchars($item['check_in']) . ' – ' . htmlspecialchars($item['check_out'] ?? '-');
                }
                $renderDayRow($item, 'text-white/55', $meta, null);
            endforeach; ?>
        </ul>
    </details>
    <?php endif; ?>
</div>
