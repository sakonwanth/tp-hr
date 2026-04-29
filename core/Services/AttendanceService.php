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
        $workMode = strtoupper((string)($user['work_mode'] ?? 'OFFICE'));
        if ($workMode === 'WFH') {
            return [
                'status' => 'WFH',
                'late_minutes' => 0,
                'effective_start_at' => null,
                'grace_minutes' => 0,
            ];
        }

        $status = 'PRESENT';
        $lateMinutes = 0;
        $effectiveStartAt = null;
        $graceMinutes = 0;

        if ($shift) {
            $date = date('Y-m-d', strtotime($checkInAt));
            $defaults = getShiftDefaults($shift);
            $shiftGrace = (int)$defaults['grace_period_minutes'];
            $plannedGrace = 30;
            $graceMinutes = $shiftGrace;

            if ($plannedStartTime !== null && $plannedStartTime !== '') {
                $effectiveStartAt = self::combineDateAndTime($date, $plannedStartTime);
                $graceMinutes = max($shiftGrace, $plannedGrace);
            } else {
                $effectiveStartAt = self::combineDateAndTime($date, (string)$shift['start_time']);
            }

            $checkInTs = strtotime($checkInAt);
            $startTs = strtotime($effectiveStartAt);
            if ($checkInTs !== false && $startTs !== false && $checkInTs > ($startTs + ($graceMinutes * 60))) {
                $lateMinutes = (int)floor(($checkInTs - $startTs) / 60);
                $status = 'LATE';
            }
        }

        return [
            'status' => $status,
            'late_minutes' => $lateMinutes,
            'effective_start_at' => $effectiveStartAt,
            'grace_minutes' => $graceMinutes,
        ];
    }

    public function summarizeWork(?string $checkInAt, ?string $checkOutAt, ?array $shift, ?string $date = null): array
    {
        $defaults = getShiftDefaults($shift);
        $breakMinutes = (int)$defaults['break_minutes'];
        $workMinutes = 0;
        $otMinutes = 0;
        $earlyLeaveMinutes = 0;

        if ($checkInAt && $checkOutAt) {
            $checkInTs = strtotime($checkInAt);
            $checkOutTs = strtotime($checkOutAt);
            if ($checkInTs !== false && $checkOutTs !== false) {
                $workMinutes = (int)floor(($checkOutTs - $checkInTs) / 60) - $breakMinutes;
                if ($workMinutes < 0) $workMinutes = 0;
            }
        }

        if ($shift) {
            $expectedWorkMinutes = (int)round(((float)$defaults['work_hours_per_day']) * 60);
            if ($workMinutes > $expectedWorkMinutes) {
                $otMinutes = $workMinutes - $expectedWorkMinutes;
            }

            if ($checkOutAt && !empty($shift['end_time'])) {
                $shiftDate = $date ?: date('Y-m-d', strtotime($checkOutAt));
                $shiftEndAt = self::combineDateAndTime($shiftDate, (string)$shift['end_time']);
                $checkOutTs = strtotime($checkOutAt);
                $shiftEndTs = strtotime($shiftEndAt);
                if ($checkOutTs !== false && $shiftEndTs !== false && $checkOutTs < $shiftEndTs) {
                    $earlyLeaveMinutes = (int)floor(($shiftEndTs - $checkOutTs) / 60);
                }
            }
        }

        return [
            'work_minutes' => $workMinutes,
            'break_minutes' => $breakMinutes,
            'ot_minutes' => $otMinutes,
            'early_leave_minutes' => $earlyLeaveMinutes,
        ];
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
            $stmt = $this->pdo->prepare("SELECT id, employee_code, work_mode FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            $stmt = $this->pdo->prepare("SELECT id, employee_code, 'OFFICE' AS work_mode FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
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
}
