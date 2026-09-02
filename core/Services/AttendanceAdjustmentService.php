<?php
/**
 * AttendanceAdjustmentService
 *
 * Applies employee-requested check-in/out corrections after executive review.
 */
if (is_file(dirname(__DIR__) . '/CrmLineNotifierBridge.php')) {
    require_once dirname(__DIR__) . '/CrmLineNotifierBridge.php';
}

class AttendanceAdjustmentException extends RuntimeException
{
    public function __construct(string $message, private int $httpStatus = 400)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

class AttendanceAdjustmentService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function approve(int $requestId, int $reviewerId, string $remarks = ''): array
    {
        if ($requestId <= 0) {
            throw new AttendanceAdjustmentException('กรุณาระบุคำขอที่ต้องการอนุมัติ');
        }
        if ($reviewerId <= 0) {
            throw new AttendanceAdjustmentException('ไม่พบผู้อนุมัติ');
        }

        try {
            $this->pdo->beginTransaction();

            $cur = $this->lockRequest($requestId);
            if ((string)$cur['status'] !== 'PENDING') {
                throw new AttendanceAdjustmentException('คำขอนี้ถูกดำเนินการแล้ว', 409);
            }

            $attStmt = $this->pdo->prepare("SELECT * FROM hr_attendances WHERE id = ? LIMIT 1 FOR UPDATE");
            $attStmt->execute([(int)$cur['attendance_id']]);
            $attendance = $attStmt->fetch(PDO::FETCH_ASSOC);
            if (!$attendance) {
                throw new AttendanceAdjustmentException('ไม่พบรายการลงเวลาต้นทาง', 404);
            }

            $attendanceService = new AttendanceService($this->pdo);
            $targetUser = $attendanceService->getUserForAttendance((int)$cur['user_id']);
            if (!$targetUser) {
                throw new AttendanceAdjustmentException('ไม่พบพนักงานหรือพนักงานไม่ active', 404);
            }

            $date = (string)$attendance['attendance_date'];
            $checkIn = !empty($cur['requested_check_in'])
                ? AttendanceService::normalizeDateTime($date, (string)$cur['requested_check_in'])
                : ($attendance['check_in_time'] ?? null);
            $checkOut = !empty($cur['requested_check_out'])
                ? AttendanceService::normalizeDateTime($date, (string)$cur['requested_check_out'])
                : ($attendance['check_out_time'] ?? null);
            $shift = $attendanceService->getShiftById((int)($attendance['shift_id'] ?? 0));
            $summary = $attendanceService->summarizeAttendance(
                $targetUser,
                $shift,
                $date,
                $checkIn,
                $checkOut,
                (($attendance['planned_status'] ?? null) === 'APPROVED') ? ($attendance['planned_start_time'] ?? null) : null,
                $attendance['status'] ?? null
            );

            $this->pdo->prepare("
                UPDATE hr_attendances
                SET check_in_time = ?,
                    check_out_time = ?,
                    check_in_type = CASE WHEN ? = 1 THEN 'MANUAL' ELSE check_in_type END,
                    check_out_type = CASE WHEN ? = 1 THEN 'MANUAL' ELSE check_out_type END,
                    work_minutes = ?,
                    break_minutes = ?,
                    late_minutes = ?,
                    early_leave_minutes = ?,
                    ot_minutes = ?,
                    status = ?,
                    adjusted_by = ?,
                    adjusted_at = NOW(),
                    adjustment_reason = ?,
                    approved_by = ?,
                    approved_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $checkIn,
                $checkOut,
                !empty($cur['requested_check_in']) ? 1 : 0,
                !empty($cur['requested_check_out']) ? 1 : 0,
                (int)$summary['work_minutes'],
                (int)$summary['break_minutes'],
                (int)$summary['late_minutes'],
                (int)$summary['early_leave_minutes'],
                (int)$summary['ot_minutes'],
                (string)$summary['status'],
                $reviewerId,
                $cur['reason'],
                $reviewerId,
                (int)$cur['attendance_id'],
            ]);

            $this->pdo->prepare("
                UPDATE hr_attendance_adjustments
                SET status = 'APPROVED',
                    reviewed_by = ?,
                    reviewed_at = NOW(),
                    review_remarks = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$reviewerId, $remarks !== '' ? $remarks : null, $requestId]);

            $this->pdo->commit();

            if (function_exists('crm_line_notify_attendance_adjustment_decision')) {
                crm_line_notify_attendance_adjustment_decision($this->pdo, $requestId, 'APPROVED', $remarks);
            }

            return [
                'id' => $requestId,
                'status' => 'APPROVED',
                'attendance_id' => (int)$cur['attendance_id'],
                'user_id' => (int)$cur['user_id'],
                'check_in_time' => $checkIn,
                'check_out_time' => $checkOut,
                'summary' => $summary,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function reject(int $requestId, int $reviewerId, string $remarks): array
    {
        if ($requestId <= 0) {
            throw new AttendanceAdjustmentException('กรุณาระบุคำขอที่ต้องการปฏิเสธ');
        }
        if ($reviewerId <= 0) {
            throw new AttendanceAdjustmentException('ไม่พบผู้อนุมัติ');
        }
        $remarks = trim($remarks);
        if ($remarks === '') {
            throw new AttendanceAdjustmentException('กรุณาระบุเหตุผลในการไม่อนุมัติ');
        }

        try {
            $this->pdo->beginTransaction();
            $cur = $this->lockRequest($requestId);
            if ((string)$cur['status'] !== 'PENDING') {
                throw new AttendanceAdjustmentException('คำขอนี้ถูกดำเนินการแล้ว', 409);
            }

            $this->pdo->prepare("
                UPDATE hr_attendance_adjustments
                SET status = 'REJECTED',
                    reviewed_by = ?,
                    reviewed_at = NOW(),
                    review_remarks = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$reviewerId, $remarks, $requestId]);

            $this->pdo->commit();

            if (function_exists('crm_line_notify_attendance_adjustment_decision')) {
                crm_line_notify_attendance_adjustment_decision($this->pdo, $requestId, 'REJECTED', $remarks);
            }

            return [
                'id' => $requestId,
                'status' => 'REJECTED',
                'attendance_id' => (int)$cur['attendance_id'],
                'user_id' => (int)$cur['user_id'],
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function approveAllPending(int $reviewerId, string $remarks = ''): int
    {
        $stmt = $this->pdo->query("SELECT id FROM hr_attendance_adjustments WHERE status = 'PENDING' ORDER BY created_at ASC, id ASC");
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $approved = 0;
        foreach ($ids as $id) {
            $this->approve($id, $reviewerId, $remarks);
            $approved++;
        }
        return $approved;
    }

    private function lockRequest(int $requestId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM hr_attendance_adjustments WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$requestId]);
        $cur = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            throw new AttendanceAdjustmentException('ไม่พบคำขอแก้เวลา', 404);
        }
        return $cur;
    }
}
