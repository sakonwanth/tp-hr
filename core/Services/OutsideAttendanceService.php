<?php
/**
 * OutsideAttendanceService
 *
 * Applies approved offsite check-in/out requests to the canonical attendance row.
 */
if (is_file(dirname(__DIR__) . '/CrmLineNotifierBridge.php')) {
    require_once dirname(__DIR__) . '/CrmLineNotifierBridge.php';
}

class OutsideAttendanceException extends RuntimeException
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

class OutsideAttendanceService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function approve(int $requestId, int $reviewerId, string $remarks = ''): array
    {
        if ($requestId <= 0) {
            throw new OutsideAttendanceException('กรุณาระบุคำขอนอกสถานที่ที่ต้องการอนุมัติ');
        }
        if ($reviewerId <= 0) {
            throw new OutsideAttendanceException('ไม่พบผู้อนุมัติ');
        }

        try {
            $this->pdo->beginTransaction();

            $request = $this->lockRequest($requestId);
            if ((string)$request['status'] !== 'PENDING') {
                throw new OutsideAttendanceException('คำขอนี้ถูกดำเนินการแล้ว', 409);
            }

            $attendanceService = new AttendanceService($this->pdo);
            $targetUser = $attendanceService->getUserForAttendance((int)$request['user_id']);
            if (!$targetUser) {
                throw new OutsideAttendanceException('ไม่พบพนักงานหรือพนักงานไม่ active', 404);
            }
            if (function_exists('tp_hr_is_attendance_exempt') && tp_hr_is_attendance_exempt($targetUser)) {
                throw new OutsideAttendanceException('ตำแหน่งนี้ได้รับการยกเว้น ไม่จำเป็นต้องลงเวลาเข้า-ออกงาน', 409);
            }

            $type = strtoupper((string)$request['request_type']);
            if ($type === 'CHECK_IN') {
                $result = $this->approveCheckIn($request, $targetUser, $reviewerId, $remarks, $attendanceService);
            } elseif ($type === 'CHECK_OUT') {
                $result = $this->approveCheckOut($request, $targetUser, $reviewerId, $remarks, $attendanceService);
            } else {
                throw new OutsideAttendanceException('ประเภทคำขอไม่ถูกต้อง');
            }

            $this->markRequestReviewed($requestId, 'APPROVED', $reviewerId, $remarks, (int)$result['attendance_id']);
            $this->pdo->commit();

            if (function_exists('crm_line_notify_outside_attendance_decision')) {
                crm_line_notify_outside_attendance_decision($this->pdo, $requestId, 'APPROVED', $remarks);
            }

            return $result + [
                'id' => $requestId,
                'status' => 'APPROVED',
                'user_id' => (int)$request['user_id'],
                'request_type' => $type,
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
            throw new OutsideAttendanceException('กรุณาระบุคำขอนอกสถานที่ที่ต้องการไม่อนุมัติ');
        }
        if ($reviewerId <= 0) {
            throw new OutsideAttendanceException('ไม่พบผู้อนุมัติ');
        }
        $remarks = trim($remarks);
        if ($remarks === '') {
            throw new OutsideAttendanceException('กรุณาระบุเหตุผลในการไม่อนุมัติ');
        }

        try {
            $this->pdo->beginTransaction();
            $request = $this->lockRequest($requestId);
            if ((string)$request['status'] !== 'PENDING') {
                throw new OutsideAttendanceException('คำขอนี้ถูกดำเนินการแล้ว', 409);
            }

            $this->rollbackPendingStamp($request);

            $this->markRequestReviewed($requestId, 'REJECTED', $reviewerId, $remarks, (int)($request['attendance_id'] ?? 0) ?: null);
            $this->pdo->commit();

            if (function_exists('crm_line_notify_outside_attendance_decision')) {
                crm_line_notify_outside_attendance_decision($this->pdo, $requestId, 'REJECTED', $remarks);
            }

            return [
                'id' => $requestId,
                'status' => 'REJECTED',
                'attendance_id' => isset($request['attendance_id']) ? (int)$request['attendance_id'] : null,
                'user_id' => (int)$request['user_id'],
                'request_type' => (string)$request['request_type'],
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function approveCheckIn(array $request, array $targetUser, int $reviewerId, string $remarks, AttendanceService $attendanceService): array
    {
        $date = (string)$request['request_date'];
        $checkInAt = AttendanceService::normalizeDateTime($date, (string)$request['request_time']);
        $attendance = $this->lockAttendanceByUserDate((int)$request['user_id'], $date);

        if ($attendance && !empty($attendance['check_in_time'])
            && !$this->sameCapturedTime($attendance['check_in_time'], $checkInAt)) {
            throw new OutsideAttendanceException('พนักงานมีเวลาเข้างานของวันนี้แล้ว', 409);
        }

        $shift = $attendance && !empty($attendance['shift_id'])
            ? $attendanceService->getShiftById((int)$attendance['shift_id'])
            : $attendanceService->getDefaultShift();
        $summary = $attendanceService->determineCheckIn(
            $targetUser,
            $shift,
            $checkInAt,
            $attendance['planned_start_time'] ?? null
        );

        $values = [
            'check_in_time' => $checkInAt,
            'check_in_latitude' => $request['latitude'] ?? null,
            'check_in_longitude' => $request['longitude'] ?? null,
            'check_in_photo' => $request['photo_path'] ?? null,
            'check_in_ip' => $request['request_ip'] ?? '',
            'late_minutes' => (int)$summary['late_minutes'],
            'status' => (string)$summary['status'],
            'offsite_reason' => $request['reason'] ?? null,
            'offsite_remarks' => $remarks !== '' ? $remarks : null,
        ];

        if ($attendance) {
            $attendanceId = (int)$attendance['id'];
            $this->pdo->prepare("
                UPDATE hr_attendances
                SET shift_id = COALESCE(shift_id, ?),
                    check_in_time = ?,
                    check_in_type = 'GPS',
                    check_in_latitude = ?,
                    check_in_longitude = ?,
                    check_in_location_id = NULL,
                    check_in_photo = ?,
                    check_in_ip = ?,
                    late_minutes = ?,
                    status = ?,
                    is_offsite = 1,
                    offsite_reason = ?,
                    offsite_status = 'APPROVED',
                    check_in_outside_status = 'APPROVED',
                    offsite_approved_by = ?,
                    offsite_approved_at = NOW(),
                    offsite_remarks = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $shift['id'] ?? null,
                $values['check_in_time'],
                $values['check_in_latitude'],
                $values['check_in_longitude'],
                $values['check_in_photo'],
                $values['check_in_ip'],
                $values['late_minutes'],
                $values['status'],
                $values['offsite_reason'],
                $reviewerId,
                $values['offsite_remarks'],
                $attendanceId,
            ]);
        } else {
            $this->pdo->prepare("
                INSERT INTO hr_attendances (
                    user_id, attendance_date, shift_id,
                    check_in_time, check_in_type, check_in_latitude, check_in_longitude,
                    check_in_location_id, check_in_photo, check_in_ip,
                    late_minutes, status,
                    is_offsite, offsite_reason, offsite_status,
                    check_in_outside_status,
                    offsite_approved_by, offsite_approved_at, offsite_remarks
                ) VALUES (?, ?, ?, ?, 'GPS', ?, ?, NULL, ?, ?, ?, ?, 1, ?, 'APPROVED', 'APPROVED', ?, NOW(), ?)
            ")->execute([
                (int)$request['user_id'],
                $date,
                $shift['id'] ?? null,
                $values['check_in_time'],
                $values['check_in_latitude'],
                $values['check_in_longitude'],
                $values['check_in_photo'],
                $values['check_in_ip'],
                $values['late_minutes'],
                $values['status'],
                $values['offsite_reason'],
                $reviewerId,
                $values['offsite_remarks'],
            ]);
            $attendanceId = (int)$this->pdo->lastInsertId();
        }

        return [
            'attendance_id' => $attendanceId,
            'check_in_time' => $checkInAt,
            'summary' => $summary,
        ];
    }

    private function approveCheckOut(array $request, array $targetUser, int $reviewerId, string $remarks, AttendanceService $attendanceService): array
    {
        $date = (string)$request['request_date'];
        $checkOutAt = AttendanceService::normalizeDateTime($date, (string)$request['request_time']);
        $attendance = $this->lockAttendanceForCheckout($request);

        if (!$attendance || empty($attendance['check_in_time'])) {
            throw new OutsideAttendanceException('ยังไม่มีเวลาเข้างาน จึงไม่สามารถอนุมัติเวลาออกงานได้', 409);
        }
        if (!empty($attendance['check_out_time'])
            && !$this->sameCapturedTime($attendance['check_out_time'], $checkOutAt)) {
            throw new OutsideAttendanceException('พนักงานมีเวลาออกงานของวันนี้แล้ว', 409);
        }

        $shift = $attendanceService->getShiftById((int)($attendance['shift_id'] ?? 0));
        $summary = $attendanceService->summarizeWork(
            $attendance['check_in_time'] ?? null,
            $checkOutAt,
            $shift,
            (string)$attendance['attendance_date']
        );

        $this->pdo->prepare("
            UPDATE hr_attendances
            SET check_out_time = ?,
                check_out_type = 'GPS',
                check_out_latitude = ?,
                check_out_longitude = ?,
                check_out_location_id = NULL,
                check_out_photo = ?,
                check_out_ip = ?,
                work_minutes = ?,
                break_minutes = ?,
                ot_minutes = ?,
                early_leave_minutes = ?,
                is_offsite = 1,
                offsite_reason = ?,
                offsite_status = 'APPROVED',
                check_outside_status = 'APPROVED',
                offsite_approved_by = ?,
                offsite_approved_at = NOW(),
                offsite_remarks = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([
            $checkOutAt,
            $request['latitude'] ?? null,
            $request['longitude'] ?? null,
            $request['photo_path'] ?? null,
            $request['request_ip'] ?? '',
            (int)$summary['work_minutes'],
            (int)$summary['break_minutes'],
            (int)$summary['ot_minutes'],
            (int)$summary['early_leave_minutes'],
            $request['reason'] ?? null,
            $reviewerId,
            $remarks !== '' ? $remarks : null,
            (int)$attendance['id'],
        ]);

        return [
            'attendance_id' => (int)$attendance['id'],
            'check_out_time' => $checkOutAt,
            'summary' => $summary,
        ];
    }

    private function lockRequest(int $requestId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM hr_attendance_outside_requests WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            throw new OutsideAttendanceException('ไม่พบคำขอลงเวลานอกสถานที่', 404);
        }
        return $request;
    }

    private function lockAttendanceByUserDate(int $userId, string $date): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM hr_attendances WHERE user_id = ? AND attendance_date = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$userId, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function lockAttendanceForCheckout(array $request): ?array
    {
        $attendanceId = (int)($request['attendance_id'] ?? 0);
        if ($attendanceId > 0) {
            $stmt = $this->pdo->prepare("SELECT * FROM hr_attendances WHERE id = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$attendanceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        return $this->lockAttendanceByUserDate((int)$request['user_id'], (string)$request['request_date']);
    }

    private function markRequestReviewed(int $requestId, string $status, int $reviewerId, string $remarks, ?int $attendanceId): void
    {
        $this->pdo->prepare("
            UPDATE hr_attendance_outside_requests
            SET status = ?,
                attendance_id = COALESCE(?, attendance_id),
                reviewed_by = ?,
                reviewed_at = NOW(),
                review_remarks = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([
            $status,
            $attendanceId,
            $reviewerId,
            $remarks !== '' ? $remarks : null,
            $requestId,
        ]);
    }

    private function sameCapturedTime(mixed $actual, string $expected): bool
    {
        return $actual !== null && strtotime((string)$actual) === strtotime($expected);
    }

    /** Remove only the timestamp still matching this request; never overwrite later HR edits. */
    private function rollbackPendingStamp(array $request): void
    {
        $attendance = $this->lockAttendanceForCheckout($request);
        if (!$attendance) return;
        $capturedAt = AttendanceService::normalizeDateTime((string)$request['request_date'], (string)$request['request_time']);
        $type = strtoupper((string)$request['request_type']);

        if ($type === 'CHECK_OUT' && $this->sameCapturedTime($attendance['check_out_time'] ?? null, $capturedAt)) {
            $this->pdo->prepare("UPDATE hr_attendances SET check_out_time=NULL, check_out_type=NULL, check_out_latitude=NULL, check_out_longitude=NULL, check_out_location_id=NULL, check_out_photo=NULL, check_out_ip=NULL, work_minutes=0, break_minutes=0, ot_minutes=0, early_leave_minutes=0, check_outside_status='REJECTED', offsite_status=CASE WHEN check_in_outside_status='APPROVED' THEN 'APPROVED' ELSE 'REJECTED' END, updated_at=NOW() WHERE id=?")
                ->execute([(int)$attendance['id']]);
            return;
        }

        if ($type === 'CHECK_IN' && $this->sameCapturedTime($attendance['check_in_time'] ?? null, $capturedAt)) {
            $this->pdo->prepare("UPDATE hr_attendances SET check_in_time=NULL, check_in_type=NULL, check_in_latitude=NULL, check_in_longitude=NULL, check_in_location_id=NULL, check_in_photo=NULL, check_in_ip=NULL, late_minutes=0, status=NULL, check_in_outside_status='REJECTED', offsite_status='REJECTED', updated_at=NOW() WHERE id=?")
                ->execute([(int)$attendance['id']]);
        }
    }
}
