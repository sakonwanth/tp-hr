<?php
/**
 * AttendanceService
 *
 * Canonical attendance calculation helpers shared by UI APIs, external APIs,
 * HR manual adjustments, and payroll-related jobs.
 */
class AttendanceService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function getDefaultShift(): ?array
    {
        $stmt = $this->pdo->query("SELECT * FROM hr_work_shifts WHERE is_default = 1 AND is_active = 1 LIMIT 1");
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);
        return $shift ?: null;
    }

    public function getShiftById(?int $shiftId): ?array
    {
        if (!$shiftId) return null;
        $stmt = $this->pdo->prepare("SELECT * FROM hr_work_shifts WHERE id = ? LIMIT 1");
        $stmt->execute([$shiftId]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);
        return $shift ?: null;
    }

    public function determineCheckIn(array $user, ?array $shift, string $checkInAt, ?string $plannedStartTime = null): array
    {
        // Calc lives in TpCommon\Hr\WorkdayCalculator (single source shared with tp-checkin).
        // This method still owns HR-side inputs: work_mode, workday lookup, shift defaults.
        $workMode = strtoupper((string)($user['work_mode'] ?? 'OFFICE'));
        if ($workMode === 'WFH') {
            return \TpCommon\Hr\WorkdayCalculator::computeCheckIn('WFH', true, $shift, $checkInAt, $plannedStartTime, 0);
        }

        $userId = (int)($user['id'] ?? 0);
        $date = date('Y-m-d', strtotime($checkInAt));
        // Original semantics: non-workday short-circuits only when we have a real user id.
        $isExpectedWorkday = ($userId <= 0) ? true : $this->isExpectedWorkday($userId, $date);
        $defaults = getShiftDefaults($shift);

        return \TpCommon\Hr\WorkdayCalculator::computeCheckIn(
            $workMode,
            $isExpectedWorkday,
            $shift,
            $checkInAt,
            $plannedStartTime,
            (int)$defaults['grace_period_minutes']
        );
    }

    public function summarizeWork(?string $checkInAt, ?string $checkOutAt, ?array $shift, ?string $date = null): array
    {
        $defaults = getShiftDefaults($shift);
        return \TpCommon\Hr\WorkdayCalculator::computeWork(
            $checkInAt,
            $checkOutAt,
            $shift,
            $date,
            (int)$defaults['break_minutes'],
            (float)$defaults['work_hours_per_day']
        );
    }

    public function summarizeAttendance(
        array $user,
        ?array $shift,
        string $date,
        ?string $checkInAt,
        ?string $checkOutAt,
        ?string $plannedStartTime = null,
        ?string $existingStatus = null
    ): array {
        $checkIn = $checkInAt ? self::normalizeDateTime($date, $checkInAt) : null;
        $checkOut = $checkOutAt ? self::normalizeDateTime($date, $checkOutAt) : null;
        $checkInSummary = $checkIn
            ? $this->determineCheckIn($user, $shift, $checkIn, $plannedStartTime)
            : ['status' => $existingStatus ?: 'PENDING', 'late_minutes' => 0, 'effective_start_at' => null, 'grace_minutes' => 0];
        $workSummary = $this->summarizeWork($checkIn, $checkOut, $shift, $date);

        return $checkInSummary + $workSummary + [
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
        ];
    }

    public function getUserForAttendance(int $userId): ?array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT u.id, u.employee_code, u.work_mode, u.attendance_exempt, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id=u.role_id WHERE u.id = ? AND u.is_active = 1 LIMIT 1");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            $stmt = $this->pdo->prepare("SELECT u.id, u.employee_code, 'OFFICE' AS work_mode, u.attendance_exempt, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id=u.role_id WHERE u.id = ? AND u.is_active = 1 LIMIT 1");
            $stmt->execute([$userId]);
        }
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    public static function normalizeDateTime(string $date, string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            return $date . ' ' . $value . ':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
            return $date . ' ' . $value;
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i:s', $ts) : $value;
    }

    private static function combineDateAndTime(string $date, string $time): string
    {
        return $date . ' ' . substr($time, 0, 8);
    }

    /** วันที่ควรนับเข้างาน/มาสาย (ไม่ใช่หยุดประจำ นักขัตฤกษ์ หรือลา) */
    public function isExpectedWorkday(int $userId, string $date): bool
    {
        $payroll = new PayrollService($this->pdo);
        $ctx = $payroll->buildWorkdayContext($userId, $date, $date);
        return $payroll->isPayrollWorkday($ctx, $date);
    }
}
