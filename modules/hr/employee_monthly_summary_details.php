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

$attFixUrl = static function (int $uid, string $date) use ($attendanceReturnUrl) {
    $q = ['date' => $date, 'user_id' => $uid, 'fix' => 1];
    if (!empty($attendanceReturnUrl)) {
        $return = $attendanceReturnUrl;
        $return .= (str_contains($return, '?') ? '&' : '?') . 'expand=' . $uid;
        $q['return'] = $return;
    }
    return '/hr/attendance.php?' . http_build_query($q);
};

$hasLate = !empty($d['late']);
$hasAbsent = !empty($d['absent']);
$hasWfh = !empty($d['wfh']);
$hasLeaveAtt = !empty($d['leave_attendance']);
$hasLeaveReq = !empty($summary['leave_requests']);
$hasHolidays = !empty($d['holidays']);
$hasSwaps = !empty($summary['dayoff_swaps']);
$hasHolidayWork = !empty($summary['holiday_work_exceptions']);
$hasPresent = !empty($d['present']);
$payrollBreakdown = $summary['payroll_attendance_breakdown'] ?? [];
$hasPayrollAbsence = !empty($payrollBreakdown) || (float)($summary['payroll_absence_deduction'] ?? 0) > 0;

if (!$hasLate && !$hasAbsent && !$hasWfh && !$hasLeaveAtt && !$hasLeaveReq && !$hasHolidays && !$hasSwaps && !$hasHolidayWork && !$hasPayrollAbsence) {
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
$actionBtn = 'inline-flex items-center justify-center gap-1.5 min-h-[48px] px-3 py-2 rounded-[var(--tp-ios-card-radius)] text-xs font-medium bg-violet-500/10 hover:bg-violet-500/20 text-violet-200 border border-violet-500/25 touch-manipulation shrink-0 whitespace-nowrap';
$bulkBtn = 'inline-flex items-center justify-center gap-1 min-h-[48px] px-2.5 py-1.5 rounded-[var(--tp-ios-card-radius)] text-[11px] sm:text-xs font-medium touch-manipulation whitespace-nowrap';
$bulkGroupId = static fn(string $kind): string => 'bulk-' . $kind . '-' . $employeeId;

$renderBulkToolbar = static function (string $kind, string $label, int $count) use ($bulkBtn, $bulkGroupId, $employeeId, $showActions): void {
    if (!$showActions || $count <= 0) {
        return;
    }
    $group = $bulkGroupId($kind);
    ?>
    <div class="flex flex-wrap items-center gap-1.5 sm:gap-2 shrink-0">
        <button type="button" class="<?php echo $bulkBtn; ?> bg-white/10 hover:bg-white/15 text-white/75 border border-white/10"
                data-bulk-action="select-all" data-group="<?php echo htmlspecialchars($group); ?>">เลือกทั้งหมด</button>
        <button type="button" class="<?php echo $bulkBtn; ?> bg-white/10 hover:bg-white/15 text-white/75 border border-white/10"
                data-bulk-action="clear-all" data-group="<?php echo htmlspecialchars($group); ?>">ล้าง</button>
        <button type="button" class="<?php echo $bulkBtn; ?> bg-violet-500/20 hover:bg-violet-500/30 text-violet-100 border border-violet-500/30"
                data-bulk-action="edit-selected" data-group="<?php echo htmlspecialchars($group); ?>"
                data-user-id="<?php echo (int)$employeeId; ?>" data-label="<?php echo htmlspecialchars($label); ?>">
            แก้ที่เลือก (<span class="emp-bulk-count tabular-nums" data-group="<?php echo htmlspecialchars($group); ?>">0</span>)
        </button>
        <button type="button" class="<?php echo $bulkBtn; ?> bg-amber-500/15 hover:bg-amber-500/25 text-amber-100 border border-amber-500/25"
                data-bulk-action="edit-group" data-group="<?php echo htmlspecialchars($group); ?>"
                data-user-id="<?php echo (int)$employeeId; ?>" data-label="<?php echo htmlspecialchars($label); ?>">
            แก้ทั้งกลุ่ม (<?php echo (int)$count; ?>)
        </button>
    </div>
    <?php
};

$renderDayRow = static function (
    array $item,
    string $toneClass,
    array $metaParts,
    ?string $actionHref = null,
    ?string $bulkGroup = null
) use ($rowPad, $actionBtn, $showActions, $employeeId): void {
    $date = $item['date'] ?? '';
    ?>
    <li class="<?php echo $rowPad; ?>">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3 min-w-0 flex-1">
                <?php if ($showActions && $bulkGroup && $date): ?>
                <label class="flex min-h-[48px] min-w-[48px] items-center justify-center shrink-0 cursor-pointer touch-manipulation">
                    <input type="checkbox" class="emp-bulk-day-cb w-4 h-4 rounded border-white/30 bg-white/10 text-violet-500 focus:ring-violet-500/50"
                           data-group="<?php echo htmlspecialchars($bulkGroup); ?>"
                           data-date="<?php echo htmlspecialchars($date); ?>"
                           data-user-id="<?php echo (int)$employeeId; ?>"
                           aria-label="เลือกวันที่ <?php echo htmlspecialchars($date); ?>">
                </label>
                <?php endif; ?>
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
            </div>
            <?php if ($showActions && $actionHref): ?>
            <a href="<?php echo htmlspecialchars($actionHref); ?>" class="<?php echo $actionBtn; ?>">
                <i class="fas fa-edit text-[11px]" aria-hidden="true"></i>รายวัน
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
        <div class="<?php echo $headClass; ?> text-amber-300 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h4 class="font-semibold">
                <i class="fas fa-clock mr-2 opacity-80" aria-hidden="true"></i>มาสาย <?php echo count($d['late']); ?> วัน
            </h4>
            <?php $renderBulkToolbar('late', 'มาสาย', count($d['late'])); ?>
        </div>
        <ul class="<?php echo $listClass; ?>">
            <?php foreach ($d['late'] as $item):
                $meta = [];
                if (!empty($item['check_in'])) {
                    $meta[] = 'เข้า ' . htmlspecialchars($item['check_in']);
                }
                if ((int)($item['late_minutes'] ?? 0) > 0) {
                    $meta[] = 'สาย ' . (int)$item['late_minutes'] . ' นาที';
                }
                $renderDayRow(
                    $item,
                    'text-amber-300/90',
                    $meta,
                    $showActions ? $attFixUrl($employeeId, $item['date']) : null,
                    $showActions ? $bulkGroupId('late') : null
                );
            endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($hasAbsent): ?>
    <section class="<?php echo $cardClass; ?>">
        <div class="<?php echo $headClass; ?> text-red-300 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h4 class="font-semibold">
                <i class="fas fa-user-times mr-2 opacity-80" aria-hidden="true"></i>ขาดงาน <?php echo count($d['absent']); ?> วัน
            </h4>
            <?php $renderBulkToolbar('absent', 'ขาดงาน', count($d['absent'])); ?>
        </div>
        <?php
        $absentHasSwapNote = false;
        foreach ($d['absent'] as $_ab) {
            if (!empty($_ab['swap_note'])) {
                $absentHasSwapNote = true;
                break;
            }
        }
        if ($absentHasSwapNote): ?>
        <p class="px-5 py-3 text-xs text-violet-200/90 bg-violet-500/[0.08] border-b border-white/10 leading-relaxed">
            <i class="fas fa-shuffle mr-1.5 text-violet-300/80" aria-hidden="true"></i>
            บางวันที่ขาดเป็นวันทำงานตาม<strong class="text-violet-100">คำขอสลับวันหยุด</strong> (วันหยุดประจำถูกเลื่อนไปวันอื่นในสัปดาห์นั้น)
        </p>
        <?php endif; ?>
        <ul class="<?php echo $listClass; ?> max-h-[min(420px,50vh)] overflow-y-auto overscroll-contain">
            <?php foreach ($d['absent'] as $item):
                $meta = [htmlspecialchars($item['reason'] ?? 'ขาดงาน')];
                if (!empty($item['swap_note'])) {
                    $meta[] = '<span class="text-violet-300/95">' . htmlspecialchars($item['swap_note']) . '</span>';
                }
                $renderDayRow(
                    $item,
                    'text-red-300/90',
                    $meta,
                    $showActions ? $attFixUrl($employeeId, $item['date']) : null,
                    $showActions ? $bulkGroupId('absent') : null
                );
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
                    <?php if (($lr['code'] ?? '') === 'SICK' && ($lr['status'] ?? '') === 'APPROVED'): ?>
                    <span class="px-2.5 py-1 rounded-full text-xs font-medium <?php echo (int)($lr['has_medical_cert'] ?? 0) === 1 ? 'bg-emerald-500/10 text-emerald-300 border border-emerald-500/20' : 'bg-amber-500/10 text-amber-200 border border-amber-500/25'; ?>">
                        <?php echo (int)($lr['has_medical_cert'] ?? 0) === 1 ? 'มีใบรับรองแพทย์' : 'ไม่มีใบรับรอง — ไม่หักเงินเดือน'; ?>
                    </span>
                    <?php endif; ?>
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
                <a href="/hr/leaves.php?status=pending" class="inline-flex items-center gap-1.5 mt-4 min-h-[48px] px-3 py-2 rounded-[var(--tp-ios-card-radius)] text-xs font-medium bg-amber-500/10 hover:bg-amber-500/20 text-amber-200 border border-amber-500/25 touch-manipulation">
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

    <?php if ($hasPayrollAbsence): ?>
    <section class="<?php echo $cardClass; ?><?php echo $panelLayout ? ' xl:col-span-2' : ''; ?>">
        <h4 class="<?php echo $headClass; ?> text-orange-300">
            <i class="fas fa-file-invoice-dollar mr-2 opacity-80" aria-hidden="true"></i>
            หักเงินเดือน (ขาดงาน)
            · <?php echo number_format((float)($summary['payroll_absence_deduction'] ?? 0), 0); ?> บาท
        </h4>
        <ul class="<?php echo $listClass; ?>">
            <?php foreach ($payrollBreakdown as $pb):
                if (!in_array($pb['kind'] ?? '', ['absent', 'sick_no_cert', 'missing_attendance_absent', 'late_over60_absent'], true)) {
                    continue;
                }
            ?>
            <li class="<?php echo $rowPad; ?>">
                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <span class="text-white font-semibold tabular-nums text-sm"><?php echo formatDateThai($pb['date'] ?? ''); ?></span>
                        <p class="mt-1 text-sm text-orange-200/90"><?php echo htmlspecialchars($pb['note'] ?? ''); ?></p>
                    </div>
                    <span class="text-orange-300 font-bold tabular-nums shrink-0"><?php echo number_format((float)($pb['amount'] ?? 0), 0); ?> บาท</span>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <p class="px-5 py-3 text-xs text-white/45 border-t border-white/10 leading-relaxed">
            คำนวณตามกฎ payroll — ลาป่วยอนุมัติไม่หักเงินเดือน (ตาม พ.ร.บ. คุ้มครองแรงงาน) แม้ไม่มีใบรับรองแพทย์
        </p>
    </section>
    <?php endif; ?>

    <?php if ($hasWfh): ?>
    <section class="<?php echo $cardClass; ?>">
        <div class="<?php echo $headClass; ?> text-purple-300 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h4 class="font-semibold">
                <i class="fas fa-house-laptop mr-2 opacity-80" aria-hidden="true"></i>WFH <?php echo count($d['wfh']); ?> วัน
            </h4>
            <?php $renderBulkToolbar('wfh', 'WFH', count($d['wfh'])); ?>
        </div>
        <ul class="<?php echo $listClass; ?>">
            <?php foreach ($d['wfh'] as $item):
                $meta = [];
                if (!empty($item['check_in'])) {
                    $meta[] = 'เข้า ' . htmlspecialchars($item['check_in']) . ' – ออก ' . htmlspecialchars($item['check_out'] ?? '-');
                }
                $renderDayRow(
                    $item,
                    'text-purple-300/90',
                    $meta,
                    $showActions ? $attFixUrl($employeeId, $item['date']) : null,
                    $showActions ? $bulkGroupId('wfh') : null
                );
            endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($hasSwaps): ?>
    <section class="<?php echo $cardClass; ?>">
        <h4 class="<?php echo $headClass; ?> text-violet-300">
            <i class="fas fa-calendar-day mr-2 opacity-80" aria-hidden="true"></i>เปลี่ยนวันหยุด <?php echo count($summary['dayoff_swaps']); ?> ครั้ง
        </h4>
        <p class="px-5 py-3 text-xs text-white/55 bg-white/[0.03] border-b border-white/10 leading-relaxed">
            สัปดาห์ที่อนุมัติแล้ว: วันหยุดประจำเดิมจะ<strong class="text-white/70">ไม่หยุด</strong> และย้ายไปหยุดวันใหม่แทน
            · วันที่เคยเป็นวันหยุดอาจกลายเป็นวันทำงานและนับขาดถ้าไม่ลงเวลา
        </p>
        <ul class="<?php echo $listClass; ?>">
            <?php foreach ($summary['dayoff_swaps'] as $swap): ?>
            <li class="<?php echo $rowPad; ?>">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 text-xs mb-1">
                            <span class="px-2 py-0.5 rounded font-semibold <?php
                                echo match($swap['status'] ?? '') {
                                    'APPROVED' => 'bg-emerald-500/15 text-emerald-300',
                                    'PENDING' => 'bg-amber-500/15 text-amber-300',
                                    'REJECTED' => 'bg-red-500/15 text-red-300',
                                    default => 'bg-slate-500/15 text-slate-300',
                                };
                            ?>"><?php echo htmlspecialchars($leaveStatus[$swap['status']] ?? ($swap['status'] ?? '-')); ?></span>
                            <span class="text-white/45"><?php echo formatDateThai($swap['week_start'] ?? ''); ?>–<?php echo formatDateThai($swap['week_end'] ?? ''); ?></span>
                        </div>
                        <p class="text-sm text-white/90 leading-snug">
                            <?php if (!empty($swap['original_off_date'])): ?>
                            <span class="text-sky-300/80"><?php echo formatDateThai($swap['original_off_date']); ?></span>
                            <span class="text-white/40 text-xs">(<?php echo htmlspecialchars($swap['original_day_label'] ?? ''); ?>)</span>
                            <?php else: ?>
                            <span class="text-sky-300/80"><?php echo htmlspecialchars($swap['original_day_label'] ?? '-'); ?></span>
                            <?php endif; ?>
                            <span class="text-white/35 mx-1.5" aria-hidden="true">→</span>
                            <?php if (!empty($swap['requested_off_date'])): ?>
                            <span class="text-violet-300 font-medium"><?php echo formatDateThai($swap['requested_off_date']); ?></span>
                            <span class="text-violet-300/70 text-xs">(<?php echo htmlspecialchars($swap['requested_day_label'] ?? ''); ?> หยุด)</span>
                            <?php else: ?>
                            <span class="text-violet-300 font-medium"><?php echo htmlspecialchars($swap['requested_day_label'] ?? '-'); ?> หยุด</span>
                            <?php endif; ?>
                        </p>
                        <?php if (!$compact && !empty($swap['reason'])): ?>
                        <p class="text-white/40 text-xs mt-1 truncate" title="<?php echo htmlspecialchars($swap['reason']); ?>"><?php echo htmlspecialchars($swap['reason']); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ($showActions && ($swap['status'] ?? '') === 'PENDING' && $isCeo): ?>
                    <a href="/hr/dayoff_approvals.php" class="<?php echo $actionBtn; ?>">อนุมัติ</a>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($hasHolidayWork): ?>
    <section class="<?php echo $cardClass; ?>">
        <h4 class="<?php echo $headClass; ?> text-orange-300">
            <i class="fas fa-briefcase mr-2 opacity-80" aria-hidden="true"></i>มาทำงานวันหยุด / หยุดชดเชย <?php echo count($summary['holiday_work_exceptions']); ?> รายการ
        </h4>
        <p class="px-5 py-3 text-xs text-white/55 bg-white/[0.03] border-b border-white/10 leading-relaxed">
            วันหยุดที่อนุมัติให้มาทำงานจะนับเป็น<strong class="text-white/70">วันทำงาน</strong>
            · วันหยุดชดเชยจะนับเป็น<strong class="text-white/70">วันหยุด</strong>
        </p>
        <ul class="<?php echo $listClass; ?>">
            <?php foreach ($summary['holiday_work_exceptions'] as $hw):
                $st = (string)($hw['status'] ?? '');
            ?>
            <li class="<?php echo $rowPad; ?>">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 text-xs mb-1">
                            <span class="px-2 py-0.5 rounded font-semibold <?php
                                echo match ($st) {
                                    'APPROVED' => 'bg-emerald-500/15 text-emerald-300',
                                    'PENDING' => 'bg-amber-500/15 text-amber-300',
                                    'REJECTED' => 'bg-red-500/15 text-red-300',
                                    default => 'bg-slate-500/15 text-slate-300',
                                };
                            ?>"><?php echo htmlspecialchars($leaveStatus[$st] ?? $st); ?></span>
                        </div>
                        <p class="text-sm text-white/90 leading-snug">
                            มาทำงาน: <span class="text-orange-200 font-medium"><?php echo formatDateThai($hw['holiday_date'] ?? ''); ?></span>
                            <?php if (!empty($hw['holiday_name'])): ?>
                            <span class="text-white/45 text-xs">(<?php echo htmlspecialchars($hw['holiday_name']); ?>)</span>
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($hw['comp_date'])): ?>
                        <p class="text-sm text-violet-200/90 mt-1">
                            หยุดชดเชย: <?php echo formatDateThai($hw['comp_date']); ?>
                        </p>
                        <?php endif; ?>
                        <?php if (!$compact && !empty($hw['reason'])): ?>
                        <p class="text-white/40 text-xs mt-1 truncate"><?php echo htmlspecialchars($hw['reason']); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ($showActions && $st === 'PENDING' && $isCeo): ?>
                    <a href="/hr/holiday_work_approvals.php" class="<?php echo $actionBtn; ?>">อนุมัติ</a>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
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
