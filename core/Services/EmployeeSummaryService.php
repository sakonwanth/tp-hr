<?php
/**
 * EmployeeSummaryService — สรุปรายเดือนรายพนักงาน (เข้างาน / ลา / หยุด / ขาด / สลับวันหยุด)
 */
class EmployeeSummaryService
{
    private ?AttendanceService $attendanceService = null;

    public function __construct(private PDO $pdo)
    {
    }

    private function attendance(): AttendanceService
    {
        return $this->attendanceService ??= new AttendanceService($this->pdo);
    }

    private function isWorkStatus(string $status): bool
    {
        return in_array($status, ['PRESENT', 'LATE', 'WFH', 'HALF_DAY'], true);
    }

    /**
     * ชม.ทำงานจาก record — ใช้ค่า DB หรือคำนวณจาก check_in/check_out ถ้ายังเป็น 0
     */
    private function resolveWorkMinutes(array $att): int
    {
        $stored = (int)($att['work_minutes'] ?? 0);
        if ($stored > 0) {
            return $stored;
        }
        $checkIn = $att['check_in_time'] ?? null;
        $checkOut = $att['check_out_time'] ?? null;
        if (!$checkIn || !$checkOut) {
            return 0;
        }
        $svc = $this->attendance();
        $shift = $svc->getShiftById((int)($att['shift_id'] ?? 0)) ?: $svc->getDefaultShift();
        $summary = $svc->summarizeWork(
            (string)$checkIn,
            (string)$checkOut,
            $shift,
            (string)($att['attendance_date'] ?? date('Y-m-d', strtotime($checkIn)))
        );
        return max(0, (int)$summary['work_minutes']);
    }

    /**
     * @return array<string,mixed>
     */
    public function getMonthlySummary(int $userId, string $month): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new InvalidArgumentException('Invalid month format');
        }

        $monthStart = $month . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $today = date('Y-m-d');
        $lastDay = ($monthEnd <= $today) ? $monthEnd : $today;

        $defaultDayOff = $this->getDefaultDayOff($userId);
        $dayoffSwaps = $this->getApprovedDayoffSwaps($userId, $monthStart, $lastDay);
        $holidays = $this->getHolidaysInRange($monthStart, $lastDay);
        $attendanceByDate = $this->getAttendanceByDate($userId, $month);

        $counts = [
            'calendar_days' => 0,
            'expected_work_days' => 0,
            'present_days' => 0,
            'late_days' => 0,
            'wfh_days' => 0,
            'leave_days' => 0,
            'absent_days' => 0,
            'holiday_days' => 0,
            'scheduled_off_days' => 0,
            'missing_record_days' => 0,
            'total_work_minutes' => 0,
            'total_late_minutes' => 0,
            'incomplete_checkout_days' => 0,
            'days_with_work_hours' => 0,
        ];

        $dayNames = defined('THAI_DAY_NAMES') ? THAI_DAY_NAMES : ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
        $details = [
            'late' => [],
            'absent' => [],
            'wfh' => [],
            'present' => [],
            'leave_attendance' => [],
            'holidays' => [],
            'scheduled_off' => [],
        ];

        $currentDay = $monthStart;
        while ($currentDay <= $lastDay) {
            $counts['calendar_days']++;
            $dow = (int)date('w', strtotime($currentDay));
            $dayLabel = $dayNames[$dow] ?? '';
            $effectiveDayOff = $this->resolveEffectiveDayOff($currentDay, $defaultDayOff, $dayoffSwaps);
            $isScheduledOff = ($dow === $effectiveDayOff);
            $holiday = $holidays[$currentDay] ?? null;
            $att = $attendanceByDate[$currentDay] ?? null;

            if ($holiday) {
                $counts['holiday_days']++;
                $details['holidays'][] = [
                    'date' => $currentDay,
                    'day_label' => $dayLabel,
                    'name' => $holiday['name'] ?? '',
                    'type' => $holiday['type'] ?? '',
                ];
            } elseif ($isScheduledOff) {
                $counts['scheduled_off_days']++;
                $details['scheduled_off'][] = [
                    'date' => $currentDay,
                    'day_label' => $dayLabel,
                ];
            } else {
                $counts['expected_work_days']++;
                if ($att) {
                    $status = (string)($att['status'] ?? '');
                    $checkIn = !empty($att['check_in_time']) ? date('H:i', strtotime($att['check_in_time'])) : null;
                    $checkOut = !empty($att['check_out_time']) ? date('H:i', strtotime($att['check_out_time'])) : null;
                    $lateMins = (int)($att['late_minutes'] ?? 0);
                    $workMins = $this->isWorkStatus($status) ? $this->resolveWorkMinutes($att) : 0;

                    match ($status) {
                        'PRESENT', 'HALF_DAY' => $counts['present_days']++,
                        'LATE' => $counts['late_days']++,
                        'WFH' => $counts['wfh_days']++,
                        'LEAVE' => $counts['leave_days']++,
                        'ABSENT' => $counts['absent_days']++,
                        default => $counts['missing_record_days']++,
                    };
                    if ($this->isWorkStatus($status)) {
                        if (!empty($att['check_in_time']) && empty($att['check_out_time'])) {
                            $counts['incomplete_checkout_days']++;
                        }
                        if ($workMins > 0) {
                            $counts['days_with_work_hours']++;
                        }
                        $counts['total_work_minutes'] += $workMins;
                        $counts['total_late_minutes'] += $lateMins;
                    }

                    if ($status === 'LATE' || $lateMins > 0) {
                        $details['late'][] = [
                            'date' => $currentDay,
                            'day_label' => $dayLabel,
                            'check_in' => $checkIn,
                            'check_out' => $checkOut,
                            'late_minutes' => $lateMins,
                            'status' => $status,
                        ];
                    } elseif (in_array($status, ['PRESENT', 'HALF_DAY'], true)) {
                        $details['present'][] = [
                            'date' => $currentDay,
                            'day_label' => $dayLabel,
                            'check_in' => $checkIn,
                            'check_out' => $checkOut,
                            'work_minutes' => $workMins,
                            'status' => $status,
                        ];
                    } elseif ($status === 'WFH') {
                        $details['wfh'][] = [
                            'date' => $currentDay,
                            'day_label' => $dayLabel,
                            'check_in' => $checkIn,
                            'check_out' => $checkOut,
                            'work_minutes' => $workMins,
                        ];
                    } elseif ($status === 'LEAVE') {
                        $details['leave_attendance'][] = [
                            'date' => $currentDay,
                            'day_label' => $dayLabel,
                            'note' => $att['notes'] ?? '',
                        ];
                    } elseif ($status === 'ABSENT') {
                        $details['absent'][] = [
                            'date' => $currentDay,
                            'day_label' => $dayLabel,
                            'reason' => 'บันทึกขาดงาน',
                        ];
                    } else {
                        $details['absent'][] = [
                            'date' => $currentDay,
                            'day_label' => $dayLabel,
                            'reason' => 'สถานะไม่ชัดเจน (' . $status . ')',
                        ];
                    }
                } else {
                    $counts['missing_record_days']++;
                    $counts['absent_days']++;
                    $details['absent'][] = [
                        'date' => $currentDay,
                        'day_label' => $dayLabel,
                        'reason' => 'ไม่มีการลงเวลา',
                    ];
                }
            }

            $currentDay = date('Y-m-d', strtotime('+1 day', strtotime($currentDay)));
        }

        $approvedLeaveDays = $this->getApprovedLeaveDaysInMonth($userId, $month);
        $leaveByType = $this->getLeaveByTypeInMonth($userId, $month);
        $leaveRequests = $this->getLeaveRequestsInMonth($userId, $month);
        $pendingLeaves = $this->getPendingLeaveCount($userId);
        $entitlements = $this->getLeaveEntitlements((int)date('Y', strtotime($monthStart)), $userId);
        $swapsInMonth = $this->getDayoffSwapsInMonth($userId, $month);

        return [
            'user_id' => $userId,
            'month' => $month,
            'month_label' => formatDateThai($monthStart),
            'period_start' => $monthStart,
            'period_end' => $lastDay,
            'counts' => $counts,
            'details' => $details,
            'approved_leave_days' => $approvedLeaveDays,
            'leave_by_type' => $leaveByType,
            'leave_requests' => $leaveRequests,
            'pending_leave_requests' => $pendingLeaves,
            'leave_entitlements' => $entitlements,
            'dayoff_swaps' => $swapsInMonth,
            'default_day_off' => $defaultDayOff,
            'work_hours' => round($counts['total_work_minutes'] / 60, 1),
            'incomplete_checkout_days' => (int)$counts['incomplete_checkout_days'],
            'days_with_work_hours' => (int)$counts['days_with_work_hours'],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getOrgMonthlySummaries(string $month, ?string $department = null): array
    {
        $sql = "
            SELECT u.id, u.employee_code, u.first_name_th, u.last_name_th, u.department, u.position
            FROM users u
            WHERE u.is_active = 1 AND u.id NOT IN (" . SYSTEM_USER_IDS_SQL . ")
        ";
        $params = [];
        if ($department !== null && $department !== '') {
            $sql .= " AND u.department = ?";
            $params[] = $department;
        }
        $sql .= " ORDER BY u.employee_code ASC, u.first_name_th ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rows = [];
        foreach ($employees as $emp) {
            $summary = $this->getMonthlySummary((int)$emp['id'], $month);
            $c = $summary['counts'];
            $rows[] = [
                'id' => (int)$emp['id'],
                'employee_code' => $emp['employee_code'] ?? '',
                'name' => trim(($emp['first_name_th'] ?? '') . ' ' . ($emp['last_name_th'] ?? '')),
                'department' => $emp['department'] ?? '',
                'position' => $emp['position'] ?? '',
                'expected_work_days' => $c['expected_work_days'],
                'present_days' => $c['present_days'],
                'late_days' => $c['late_days'],
                'wfh_days' => $c['wfh_days'],
                'leave_days' => $c['leave_days'],
                'absent_days' => $c['absent_days'],
                'holiday_days' => $c['holiday_days'],
                'scheduled_off_days' => $c['scheduled_off_days'],
                'approved_leave_days' => $summary['approved_leave_days'],
                'dayoff_swap_count' => count($summary['dayoff_swaps']),
                'work_hours' => $summary['work_hours'],
                'incomplete_checkout_days' => (int)($summary['incomplete_checkout_days'] ?? 0),
                'days_with_work_hours' => (int)($summary['days_with_work_hours'] ?? 0),
                'pending_leaves' => $summary['pending_leave_requests'],
                'summary' => $summary,
            ];
        }

        return $rows;
    }

    /**
     * Org-wide KPI for HR dashboard (current month).
     *
     * @return array<string,int|float>
     */
    public function getOrgMonthlyKpi(string $month): array
    {
        $rows = $this->getOrgMonthlySummaries($month);
        $totals = [
            'employee_count' => count($rows),
            'expected_work_days' => 0,
            'present_days' => 0,
            'late_days' => 0,
            'absent_days' => 0,
            'leave_days' => 0,
            'wfh_days' => 0,
            'approved_leave_days' => 0.0,
            'dayoff_swaps' => 0,
        ];
        foreach ($rows as $row) {
            $totals['expected_work_days'] += (int)$row['expected_work_days'];
            $totals['present_days'] += (int)$row['present_days'];
            $totals['late_days'] += (int)$row['late_days'];
            $totals['absent_days'] += (int)$row['absent_days'];
            $totals['leave_days'] += (int)$row['leave_days'];
            $totals['wfh_days'] += (int)$row['wfh_days'];
            $totals['approved_leave_days'] += (float)$row['approved_leave_days'];
            $totals['dayoff_swaps'] += (int)$row['dayoff_swap_count'];
        }
        $denom = max(1, (int)$totals['expected_work_days']);
        $totals['attendance_rate'] = round(
            (($totals['present_days'] + $totals['late_days'] + $totals['wfh_days']) / $denom) * 100,
            1
        );
        return $totals;
    }

    private function getDefaultDayOff(int $userId): int
    {
        $stmt = $this->pdo->prepare("SELECT day_off FROM hr_employee_schedules WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['day_off'] : 0;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function getApprovedDayoffSwaps(int $userId, string $from, string $to): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, week_start, week_end, original_day_off, requested_day_off, reason, status
            FROM hr_dayoff_requests
            WHERE user_id = ? AND status = 'APPROVED'
            AND week_start <= ? AND week_end >= ?
            ORDER BY week_start ASC
        ");
        $stmt->execute([$userId, $to, $from]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function getHolidaysInRange(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare("
            SELECT date, name, type FROM hr_holidays
            WHERE date BETWEEN ? AND ? AND is_active = 1
        ");
        $stmt->execute([$from, $to]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $map[$h['date']] = $h;
        }
        return $map;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function getAttendanceByDate(int $userId, string $month): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM hr_attendances
            WHERE user_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
        ");
        $stmt->execute([$userId, $month]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[$row['attendance_date']] = $row;
        }
        return $map;
    }

    private function resolveEffectiveDayOff(string $date, int $defaultDayOff, array $swaps): int
    {
        foreach ($swaps as $swap) {
            if ($date >= $swap['week_start'] && $date <= $swap['week_end']) {
                return (int)$swap['requested_day_off'];
            }
        }
        return $defaultDayOff;
    }

    private function getApprovedLeaveDaysInMonth(int $userId, string $month): float
    {
        $monthStart = $month . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(total_days), 0)
            FROM hr_leave_requests
            WHERE user_id = ? AND status = 'APPROVED'
            AND start_date <= ? AND end_date >= ?
        ");
        $stmt->execute([$userId, $monthEnd, $monthStart]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function getLeaveRequestsInMonth(int $userId, string $month): array
    {
        $monthStart = $month . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $today = date('Y-m-d');
        $lastDay = ($monthEnd <= $today) ? $monthEnd : $today;
        $dayNames = defined('THAI_DAY_NAMES') ? THAI_DAY_NAMES : ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];

        $stmt = $this->pdo->prepare("
            SELECT lr.id, lr.start_date, lr.end_date, lr.total_days, lr.status, lr.reason,
                   lt.code, lt.name AS leave_type_name, lt.color
            FROM hr_leave_requests lr
            JOIN hr_leave_types lt ON lt.id = lr.leave_type_id
            WHERE lr.user_id = ?
            AND lr.status IN ('APPROVED', 'PENDING')
            AND lr.start_date <= ? AND lr.end_date >= ?
            ORDER BY lr.start_date ASC, lr.id ASC
        ");
        $stmt->execute([$userId, $lastDay, $monthStart]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$r) {
            $daysInMonth = [];
            $cursor = max($r['start_date'], $monthStart);
            $end = min($r['end_date'], $lastDay);
            while ($cursor <= $end) {
                $dow = (int)date('w', strtotime($cursor));
                $daysInMonth[] = [
                    'date' => $cursor,
                    'day_label' => $dayNames[$dow] ?? '',
                ];
                $cursor = date('Y-m-d', strtotime('+1 day', strtotime($cursor)));
            }
            $r['days_in_month'] = $daysInMonth;
            $r['days_in_month_count'] = count($daysInMonth);
        }
        unset($r);

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function getLeaveByTypeInMonth(int $userId, string $month): array
    {
        $monthStart = $month . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $stmt = $this->pdo->prepare("
            SELECT lt.code, lt.name, lt.color,
                   COALESCE(SUM(lr.total_days), 0) AS days,
                   COUNT(*) AS request_count
            FROM hr_leave_requests lr
            JOIN hr_leave_types lt ON lt.id = lr.leave_type_id
            WHERE lr.user_id = ? AND lr.status = 'APPROVED'
            AND lr.start_date <= ? AND lr.end_date >= ?
            GROUP BY lt.id
            ORDER BY lt.sort_order, lt.name
        ");
        $stmt->execute([$userId, $monthEnd, $monthStart]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function getPendingLeaveCount(int $userId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM hr_leave_requests WHERE user_id = ? AND status = 'PENDING'");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function getLeaveEntitlements(int $year, int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT lt.code, lt.name, lt.color,
                   COALESCE(le.entitled_days, lt.default_days_per_year) AS entitled_days,
                   COALESCE(le.carried_over_days, 0) AS carried_over_days,
                   COALESCE(le.used_days, 0) AS used_days,
                   COALESCE(le.pending_days, 0) AS pending_days
            FROM hr_leave_types lt
            LEFT JOIN hr_leave_entitlements le ON le.leave_type_id = lt.id AND le.user_id = ? AND le.year = ?
            WHERE lt.is_active = 1
            ORDER BY lt.sort_order
        ");
        $stmt->execute([$userId, $year]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['remaining_days'] = (float)$r['entitled_days'] + (float)$r['carried_over_days']
                - (float)$r['used_days'] - (float)$r['pending_days'];
        }
        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function getDayoffSwapsInMonth(int $userId, string $month): array
    {
        $monthStart = $month . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $today = date('Y-m-d');
        $lastDay = ($monthEnd <= $today) ? $monthEnd : $today;
        $dayNames = defined('THAI_DAY_NAMES') ? THAI_DAY_NAMES : ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];

        $stmt = $this->pdo->prepare("
            SELECT id, week_start, week_end, original_day_off, requested_day_off, reason, status, created_at
            FROM hr_dayoff_requests
            WHERE user_id = ?
            AND ((DATE_FORMAT(week_start, '%Y-%m') = ?) OR (DATE_FORMAT(week_end, '%Y-%m') = ?))
            ORDER BY week_start DESC
        ");
        $stmt->execute([$userId, $month, $month]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['original_day_label'] = $dayNames[(int)$r['original_day_off']] ?? '-';
            $r['requested_day_label'] = $dayNames[(int)$r['requested_day_off']] ?? '-';
            $r['original_off_date'] = $this->findWeekDateByDayOfWeek(
                (string)$r['week_start'],
                (string)$r['week_end'],
                (int)$r['original_day_off']
            );
            $r['requested_off_date'] = $this->findWeekDateByDayOfWeek(
                (string)$r['week_start'],
                (string)$r['week_end'],
                (int)$r['requested_day_off']
            );
        }
        return $rows;
    }

    private function findWeekDateByDayOfWeek(string $weekStart, string $weekEnd, int $dayOfWeek): ?string
    {
        $cursor = $weekStart;
        while ($cursor <= $weekEnd) {
            if ((int)date('w', strtotime($cursor)) === $dayOfWeek) {
                return $cursor;
            }
            $cursor = date('Y-m-d', strtotime('+1 day', strtotime($cursor)));
        }
        return null;
    }
}
