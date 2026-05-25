<?php
/**
 * Partial: รายละเอียดรายวัน (สาย / ลา / ขาด / สลับวันหยุด)
 * @var array $summary จาก EmployeeSummaryService::getMonthlySummary()
 * @var bool $compact แสดงแบบย่อ (ใช้ในตารางรวม)
 */
if (empty($summary) || !is_array($summary)) {
    return;
}
$d = $summary['details'] ?? [];
$attStatus = defined('ATTENDANCE_STATUS') ? ATTENDANCE_STATUS : [];
$leaveStatus = defined('LEAVE_STATUS') ? LEAVE_STATUS : [];
$compact = !empty($compact);

$hasLate = !empty($d['late']);
$hasAbsent = !empty($d['absent']);
$hasWfh = !empty($d['wfh']);
$hasLeaveAtt = !empty($d['leave_attendance']);
$hasLeaveReq = !empty($summary['leave_requests']);
$hasHolidays = !empty($d['holidays']);
$hasScheduledOff = !empty($d['scheduled_off']);
$hasSwaps = !empty($summary['dayoff_swaps']);
$hasPresent = !empty($d['present']);

if (!$hasLate && !$hasAbsent && !$hasWfh && !$hasLeaveAtt && !$hasLeaveReq && !$hasHolidays && !$hasScheduledOff && !$hasSwaps) {
    echo '<p class="text-white/45 text-sm py-2">ไม่มีรายการพิเศษในเดือนนี้</p>';
    return;
}

$sectionClass = $compact
    ? 'rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/8 overflow-hidden'
    : 'rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 overflow-hidden';
$headClass = $compact
    ? 'px-3 py-2 text-xs font-semibold text-white/75 bg-white/5 border-b border-white/8'
    : 'px-4 py-3 text-sm font-semibold text-white/85 bg-white/5 border-b border-white/10';
$rowClass = $compact ? 'px-3 py-2 text-xs border-b border-white/5 last:border-0' : 'px-4 py-2.5 text-sm border-b border-white/5 last:border-0';
?>

<div class="emp-summary-details space-y-3 <?php echo $compact ? 'text-xs' : ''; ?>">
    <?php if ($hasLate): ?>
    <div class="<?php echo $sectionClass; ?>">
        <h4 class="<?php echo $headClass; ?> text-amber-300">
            <i class="fas fa-clock mr-1.5" aria-hidden="true"></i>มาสาย <?php echo count($d['late']); ?> วัน
        </h4>
        <ul class="divide-y divide-white/5">
            <?php foreach ($d['late'] as $item): ?>
            <li class="<?php echo $rowClass; ?> flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="text-white font-medium tabular-nums shrink-0"><?php echo formatDateThai($item['date']); ?></span>
                <span class="text-white/45"><?php echo htmlspecialchars($item['day_label'] ?? ''); ?></span>
                <?php if (!empty($item['check_in'])): ?>
                <span class="text-amber-300">เข้า <?php echo htmlspecialchars($item['check_in']); ?></span>
                <?php endif; ?>
                <?php if ((int)($item['late_minutes'] ?? 0) > 0): ?>
                <span class="text-amber-400/80">สาย <?php echo (int)$item['late_minutes']; ?> น.</span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($hasAbsent): ?>
    <div class="<?php echo $sectionClass; ?>">
        <h4 class="<?php echo $headClass; ?> text-red-300">
            <i class="fas fa-user-times mr-1.5" aria-hidden="true"></i>ขาดงาน <?php echo count($d['absent']); ?> วัน
        </h4>
        <ul class="divide-y divide-white/5">
            <?php foreach ($d['absent'] as $item): ?>
            <li class="<?php echo $rowClass; ?> flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="text-white font-medium tabular-nums shrink-0"><?php echo formatDateThai($item['date']); ?></span>
                <span class="text-white/45"><?php echo htmlspecialchars($item['day_label'] ?? ''); ?></span>
                <span class="text-red-300/90"><?php echo htmlspecialchars($item['reason'] ?? 'ขาดงาน'); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($hasLeaveReq): ?>
    <div class="<?php echo $sectionClass; ?>">
        <h4 class="<?php echo $headClass; ?> text-blue-300">
            <i class="fas fa-calendar-minus mr-1.5" aria-hidden="true"></i>ใบลาในเดือนนี้
        </h4>
        <div class="divide-y divide-white/5">
            <?php foreach ($summary['leave_requests'] as $lr): ?>
            <div class="<?php echo $rowClass; ?>">
                <div class="flex flex-wrap items-center gap-2 mb-1.5">
                    <span class="inline-flex px-2 py-0.5 rounded text-xs font-semibold border border-white/10"
                          style="background-color: <?php echo htmlspecialchars($lr['color'] ?? '#3B82F6'); ?>20; color: <?php echo htmlspecialchars($lr['color'] ?? '#93C5FD'); ?>">
                        <?php echo htmlspecialchars($lr['leave_type_name'] ?? $lr['code'] ?? 'ลา'); ?>
                    </span>
                    <span class="px-2 py-0.5 rounded text-xs font-semibold <?php
                        echo match($lr['status'] ?? '') {
                            'APPROVED' => 'bg-emerald-500/20 text-emerald-300',
                            'PENDING' => 'bg-amber-500/20 text-amber-300',
                            default => 'bg-slate-500/20 text-slate-300',
                        };
                    ?>"><?php echo htmlspecialchars($leaveStatus[$lr['status']] ?? ($lr['status'] ?? '')); ?></span>
                    <span class="text-white/55 text-xs">
                        <?php echo formatDateThai($lr['start_date']); ?>
                        <?php if ($lr['start_date'] !== $lr['end_date']): ?>
                        – <?php echo formatDateThai($lr['end_date']); ?>
                        <?php endif; ?>
                        (<?php echo number_format((float)$lr['total_days'], 1); ?> วัน)
                    </span>
                </div>
                <?php if (!empty($lr['days_in_month'])): ?>
                <div class="flex flex-wrap gap-1.5 mt-1">
                    <?php foreach ($lr['days_in_month'] as $day): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-blue-500/10 text-blue-200 text-xs border border-blue-500/20">
                        <span class="tabular-nums"><?php echo formatDateThai($day['date']); ?></span>
                        <span class="text-blue-300/60"><?php echo htmlspecialchars($day['day_label']); ?></span>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!$compact && !empty($lr['reason'])): ?>
                <p class="text-white/45 text-xs mt-1.5"><?php echo htmlspecialchars($lr['reason']); ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php elseif ($hasLeaveAtt): ?>
    <div class="<?php echo $sectionClass; ?>">
        <h4 class="<?php echo $headClass; ?> text-blue-300">
            <i class="fas fa-calendar-minus mr-1.5" aria-hidden="true"></i>ลา (จากการลงเวลา) <?php echo count($d['leave_attendance']); ?> วัน
        </h4>
        <ul class="divide-y divide-white/5">
            <?php foreach ($d['leave_attendance'] as $item): ?>
            <li class="<?php echo $rowClass; ?> flex flex-wrap items-center gap-x-3">
                <span class="text-white font-medium tabular-nums"><?php echo formatDateThai($item['date']); ?></span>
                <span class="text-white/45"><?php echo htmlspecialchars($item['day_label'] ?? ''); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($hasWfh): ?>
    <div class="<?php echo $sectionClass; ?>">
        <h4 class="<?php echo $headClass; ?> text-purple-300">
            <i class="fas fa-house-laptop mr-1.5" aria-hidden="true"></i>WFH <?php echo count($d['wfh']); ?> วัน
        </h4>
        <ul class="divide-y divide-white/5">
            <?php foreach ($d['wfh'] as $item): ?>
            <li class="<?php echo $rowClass; ?> flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="text-white font-medium tabular-nums"><?php echo formatDateThai($item['date']); ?></span>
                <span class="text-white/45"><?php echo htmlspecialchars($item['day_label'] ?? ''); ?></span>
                <?php if (!empty($item['check_in'])): ?>
                <span class="text-purple-300/80">เข้า <?php echo htmlspecialchars($item['check_in']); ?> – ออก <?php echo htmlspecialchars($item['check_out'] ?? '-'); ?></span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($hasSwaps): ?>
    <div class="<?php echo $sectionClass; ?>">
        <h4 class="<?php echo $headClass; ?> text-violet-300">
            <i class="fas fa-calendar-day mr-1.5" aria-hidden="true"></i>การเปลี่ยนวันหยุด
        </h4>
        <div class="divide-y divide-white/5">
            <?php foreach ($summary['dayoff_swaps'] as $swap): ?>
            <div class="<?php echo $rowClass; ?>">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                    <span class="px-2 py-0.5 rounded text-xs font-semibold <?php
                        echo match($swap['status'] ?? '') {
                            'APPROVED' => 'bg-emerald-500/20 text-emerald-300',
                            'PENDING' => 'bg-amber-500/20 text-amber-300',
                            'REJECTED' => 'bg-red-500/20 text-red-300',
                            default => 'bg-slate-500/20 text-slate-300',
                        };
                    ?>"><?php echo htmlspecialchars($leaveStatus[$swap['status']] ?? ($swap['status'] ?? '-')); ?></span>
                    <span class="text-sky-300"><?php echo htmlspecialchars($swap['original_day_label'] ?? ''); ?></span>
                    <i class="fas fa-arrow-right text-white/35 text-xs" aria-hidden="true"></i>
                    <span class="text-violet-300 font-medium"><?php echo htmlspecialchars($swap['requested_day_label'] ?? ''); ?></span>
                    <span class="text-white/45 text-xs ml-auto">
                        สัปดาห์ <?php echo formatDateThai($swap['week_start'] ?? ''); ?> – <?php echo formatDateThai($swap['week_end'] ?? ''); ?>
                    </span>
                </div>
                <?php if (!empty($swap['affected_days'])): ?>
                <div class="flex flex-wrap gap-1.5 mt-1">
                    <?php foreach ($swap['affected_days'] as $ad): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-violet-500/10 text-violet-200 text-xs border border-violet-500/20" title="<?php echo htmlspecialchars(($ad['was'] ?? '') . ' → ' . ($ad['now'] ?? '')); ?>">
                        <span class="tabular-nums"><?php echo formatDateThai($ad['date']); ?></span>
                        <span class="text-violet-300/70"><?php echo htmlspecialchars($ad['change'] ?? ''); ?></span>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!$compact && !empty($swap['reason'])): ?>
                <p class="text-white/45 text-xs mt-1"><?php echo htmlspecialchars($swap['reason']); ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$compact && $hasHolidays): ?>
    <div class="<?php echo $sectionClass; ?>">
        <h4 class="<?php echo $headClass; ?> text-emerald-300">
            <i class="fas fa-star mr-1.5" aria-hidden="true"></i>วันหยุดนักขัตฤกษ์/บริษัท <?php echo count($d['holidays']); ?> วัน
        </h4>
        <ul class="divide-y divide-white/5">
            <?php foreach ($d['holidays'] as $item): ?>
            <li class="<?php echo $rowClass; ?> flex flex-wrap items-center gap-x-3">
                <span class="text-white font-medium tabular-nums"><?php echo formatDateThai($item['date']); ?></span>
                <span class="text-emerald-300"><?php echo htmlspecialchars($item['name'] ?? ''); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (!$compact && $hasPresent && empty($compactHidePresent)): ?>
    <details class="<?php echo $sectionClass; ?> group">
        <summary class="<?php echo $headClass; ?> text-emerald-300 cursor-pointer list-none flex items-center justify-between touch-manipulation">
            <span><i class="fas fa-check-circle mr-1.5" aria-hidden="true"></i>มาทำงานครบ <?php echo count($d['present']); ?> วัน</span>
            <i class="fas fa-chevron-down text-white/40 group-open:rotate-180 transition-transform text-xs" aria-hidden="true"></i>
        </summary>
        <ul class="divide-y divide-white/5 max-h-64 overflow-y-auto">
            <?php foreach ($d['present'] as $item): ?>
            <li class="<?php echo $rowClass; ?> flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="text-white/80 tabular-nums"><?php echo formatDateThai($item['date']); ?></span>
                <span class="text-white/40"><?php echo htmlspecialchars($item['day_label'] ?? ''); ?></span>
                <?php if (!empty($item['check_in'])): ?>
                <span class="text-white/55"><?php echo htmlspecialchars($item['check_in']); ?> – <?php echo htmlspecialchars($item['check_out'] ?? '-'); ?></span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </details>
    <?php endif; ?>
</div>
