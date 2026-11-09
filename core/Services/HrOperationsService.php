<?php
/**
 * HR Service — Abstraction layer for tp-crm's access to HR data.
 *
 * Dual-mode: uses tp-hr API (via TpCommon\Http\HrClient) when available,
 * falls back to direct DB queries (shared database) for transition period.
 * Goal: Phase 6.1 will remove all direct DB access; this service is the
 * migration boundary.
 */

class HrOperationsService
{
    private PDO $pdo;
    private ?object $hrClient = null;
    private bool $useApi;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->useApi = false;

        // HR-owned operations use the shared canonical datastore locally.
    }

    private function isPayrollApiFlagOn(): bool
    {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'USE_TPHR_PAYROLL'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false) {
                return $val === '1';
            }
        } catch (\Throwable $e) {
        }

        $env = $_ENV['USE_TPHR_PAYROLL'] ?? getenv('USE_TPHR_PAYROLL') ?: '0';
        return $env === '1';
    }

    /**
     * Force a specific mode (useful for testing or configuration).
     */
    public function setMode(bool $useApi): void
    {
        $this->useApi = $useApi && $this->hrClient !== null;
    }

    /**
     * SQL fragment: TPE* staff who must check in (shared AttendanceScope).
     */
    private function summaryEmployeeFilterSql(string $alias = 'u'): string
    {
        if (function_exists('tp_crm_attendance_scope_filter_sql')) {
            return tp_crm_attendance_scope_filter_sql($alias);
        }

        $prefix = $alias !== '' ? $alias . '.' : '';

        return $prefix . 'is_active = 1'
            . ' AND ' . $prefix . "employee_code LIKE 'TPE%'"
            . ' AND ' . (function_exists('tp_crm_person_user_condition_sql')
                ? tp_crm_person_user_condition_sql($alias)
                : $prefix . "account_type = 'PERSON' AND {$prefix}is_employee = 1 AND {$prefix}is_test_account = 0");
    }

    /**
     * @return list<int>
     */
    private function getSummaryEligibleUserIds(): array
    {
        $where = $this->summaryEmployeeFilterSql('u');
        $stmt = $this->pdo->query("SELECT u.id FROM users u WHERE {$where} ORDER BY u.id");
        $ids = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

        return array_map('intval', $ids ?: []);
    }

    /**
     * Get today's attendance summary (present, on_leave, absent counts).
     *
     * Counts only TPE* check-in staff (AttendanceScope) — not executives/CRM users with is_employee=1.
     */
    public function getTodayAttendanceSummary(): array
    {
        $eligibleIds = $this->getSummaryEligibleUserIds();
        $total = count($eligibleIds);

        if ($eligibleIds === []) {
            return [
                'absent' => 0,
                'onLeave' => 0,
                'present' => 0,
                'late' => 0,
                'total' => 0,
            ];
        }

        if ($this->useApi) {
            $today = date('Y-m-d');
            $result = $this->hrClient->getAttendance(['date' => $today]);
            if (!empty($result['success']) && isset($result['data'])) {
                $eligibleLookup = array_fill_keys($eligibleIds, true);
                $absent = $onLeave = $present = $late = 0;
                foreach ($result['data'] as $row) {
                    $userId = (int) ($row['user_id'] ?? 0);
                    if ($userId <= 0 || !isset($eligibleLookup[$userId])) {
                        continue;
                    }
                    $status = strtoupper($row['status'] ?? '');
                    if ($status === 'ABSENT') {
                        $absent++;
                    } elseif ($status === 'LEAVE') {
                        $onLeave++;
                    } elseif (in_array($status, ['PRESENT', 'LATE', 'WFH', 'HALF_DAY'], true)) {
                        $present++;
                        if ($status === 'LATE') {
                            $late++;
                        }
                    }
                }

                return compact('absent', 'onLeave', 'present', 'late', 'total');
            }
            $this->logApiFallback(__FUNCTION__);
        }

        $placeholders = implode(',', array_fill(0, count($eligibleIds), '?'));
        $today = date('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT
            SUM(CASE WHEN a.status='ABSENT' THEN 1 ELSE 0 END) AS absent,
            SUM(CASE WHEN a.status='LEAVE' THEN 1 ELSE 0 END) AS on_leave,
            SUM(CASE WHEN a.status IN ('PRESENT','LATE','WFH','HALF_DAY') THEN 1 ELSE 0 END) AS present,
            SUM(CASE WHEN a.status='LATE' THEN 1 ELSE 0 END) AS late
            FROM hr_attendances a
            INNER JOIN users u ON u.id = a.user_id
            WHERE a.attendance_date = ?
              AND a.user_id IN ({$placeholders})
              AND {$this->summaryEmployeeFilterSql('u')}");
        $stmt->execute(array_merge([$today], $eligibleIds));
        $stat = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'absent' => (int) ($stat['absent'] ?? 0),
            'onLeave' => (int) ($stat['on_leave'] ?? 0),
            'present' => (int) ($stat['present'] ?? 0),
            'late' => (int) ($stat['late'] ?? 0),
            'total' => $total,
        ];
    }

    /**
     * Get attendance records for a date range.
     */
    public function getAttendanceRange(string $from, string $to, ?int $userId = null): array
    {
        if ($this->useApi) {
            $params = ['from' => $from, 'to' => $to];
            if ($userId) $params['user_id'] = $userId;
            $result = $this->hrClient->getAttendance($params);
            if (!empty($result['success'])) return $result['data'] ?? [];
            $this->logApiFallback(__FUNCTION__);
        }

        $sql = "SELECT * FROM hr_attendances WHERE attendance_date BETWEEN ? AND ?";
        $params = [$from, $to];
        if ($userId) {
            $sql .= " AND user_id = ?";
            $params[] = $userId;
        }
        $sql .= " ORDER BY attendance_date, user_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get leave requests with optional filters.
     */
    public function getLeaveRequests(array $filters = []): array
    {
        if ($this->useApi) {
            $result = $this->hrClient->listLeave($filters);
            if (!empty($result['success'])) return $result['data'] ?? [];
            $this->logApiFallback(__FUNCTION__);
        }

        $sql = "SELECT lr.*, lt.name AS leave_type_name
                FROM hr_leave_requests lr
                LEFT JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
                WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND lr.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['user_id'])) {
            $sql .= " AND lr.user_id = ?";
            $params[] = $filters['user_id'];
        }
        if (!empty($filters['from'])) {
            $sql .= " AND lr.start_date >= ?";
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql .= " AND lr.end_date <= ?";
            $params[] = $filters['to'];
        }

        $sql .= " ORDER BY lr.created_at DESC LIMIT 200";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if a date is a holiday.
     */
    public function isHoliday(string $date): bool
    {
        if ($this->useApi) {
            $year = (int)date('Y', strtotime($date));
            $result = $this->hrClient->getHolidays($year);
            if (!empty($result['success']) && isset($result['data'])) {
                foreach ($result['data'] as $h) {
                    if (($h['date'] ?? '') === $date) {
                        return true;
                    }
                }
                return false;
            }
            $this->logApiFallback(__FUNCTION__);
        }

        if (class_exists(\TpCommon\Hr\WorkdayCalculator::class)) {
            return \TpCommon\Hr\WorkdayCalculator::isCompanyHoliday($this->pdo, $date);
        }

        $stmt = $this->pdo->prepare('SELECT 1 FROM hr_holidays WHERE date = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$date]);
        return (bool)$stmt->fetch();
    }

    /**
     * List employees (paged).
     */
    public function listEmployees(int $page = 1, int $perPage = 50, bool $includeInactive = false): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        if ($this->useApi) {
            $result = $this->hrClient->listEmployees([
                'page' => $page,
                'per_page' => $perPage,
                'include_inactive' => $includeInactive ? 1 : 0,
            ]);
            if (!empty($result['success'])) return $result['data'] ?? [];
            $this->logApiFallback(__FUNCTION__);
        }

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT id, username, first_name, last_name, email, role_name, department, position
                FROM users WHERE 1=1";
        if (!$includeInactive) {
            $sql .= " AND (status IS NULL OR status != 'inactive')";
        }
        $sql .= " ORDER BY first_name, last_name LIMIT {$perPage} OFFSET {$offset}";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get leave types.
     */
    public function getLeaveTypes(): array
    {
        if ($this->useApi) {
            $result = $this->hrClient->getLeaveTypes();
            if (!empty($result['success'])) return $result['data'] ?? [];
            $this->logApiFallback(__FUNCTION__);
        }

        return $this->pdo->query("SELECT * FROM hr_leave_types WHERE is_active = 1 ORDER BY sort_order, name")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get departments.
     */
    public function getDepartments(): array
    {
        if ($this->useApi) {
            $result = $this->hrClient->getDepartments();
            if (!empty($result['success'])) return $result['data'] ?? [];
            $this->logApiFallback(__FUNCTION__);
        }

        return $this->pdo->query("SELECT * FROM departments ORDER BY name")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Admin: toggle late-excused on an attendance row (Phase 2.1). Writes via tp-hr API when
     * in API mode, else direct SQL — the same statement tp-hr runs. Caller supplies the
     * pre-built audit tag so both paths append an identical adjustment_reason.
     */
    public function excuseLate(int $attendanceId, bool $excused, string $reason, string $audit): bool
    {
        if ($attendanceId <= 0) {
            return false;
        }
        $ex = $excused ? 1 : 0;
        if ($this->useApi) {
            $result = $this->hrClient->excuseLate([
                'attendance_id' => $attendanceId,
                'excused' => $ex,
                'reason' => $reason,
                'audit' => $audit,
            ]);
            if (!empty($result['success'])) {
                return true;
            }
            $this->logApiFallback(__FUNCTION__);
        }
        $stmt = $this->pdo->prepare("UPDATE hr_attendances
            SET late_excused = ?,
                late_excused_reason = CASE WHEN ? <> '' THEN ? ELSE late_excused_reason END,
                late_notified_at = COALESCE(late_notified_at, NOW()),
                adjustment_reason = CONCAT_WS(\"\\n\", NULLIF(adjustment_reason,''), ?)
            WHERE id = ?");
        $stmt->execute([$ex, $reason, $reason, $audit, $attendanceId]);
        return true;
    }

    /**
     * Admin: reclassify an attendance row's status (Phase 2.1). API when enabled, else
     * identical direct SQL. Caller computes the final status + audit tag + actor id.
     */
    public function reclassifyAttendance(int $attendanceId, string $status, string $audit, int $actorId): bool
    {
        if ($attendanceId <= 0) {
            return false;
        }
        if ($this->useApi) {
            $result = $this->hrClient->reclassifyAttendance([
                'attendance_id' => $attendanceId,
                'status' => $status,
                'audit' => $audit,
                'actor_id' => $actorId,
            ]);
            if (!empty($result['success'])) {
                return true;
            }
            $this->logApiFallback(__FUNCTION__);
        }
        $stmt = $this->pdo->prepare("UPDATE hr_attendances
            SET status=?,
                adjustment_reason=CONCAT_WS(\"\\n\", NULLIF(adjustment_reason,''), ?),
                adjusted_by=?, adjusted_at=NOW(),
                approved_by=?, approved_at=NOW()
            WHERE id=?");
        $stmt->execute([$status, $audit, $actorId ?: null, $actorId ?: null, $attendanceId]);
        return true;
    }

    /**
     * Notify-late: upsert late-excused for a user+date (Phase 2.1). API when enabled, else
     * an equivalent direct upsert. Returns true on success.
     */
    public function notifyLate(int $userId, string $date, string $reason): bool
    {
        if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        if ($this->useApi) {
            $result = $this->hrClient->notifyLate([
                'user_id' => $userId,
                'date' => $date,
                'reason' => $reason,
            ]);
            if (!empty($result['success'])) {
                return true;
            }
            $this->logApiFallback(__FUNCTION__);
        }
        $stmt = $this->pdo->prepare("INSERT INTO hr_attendances
                (user_id, attendance_date, status, late_excused, late_excused_reason, late_notified_at)
            VALUES (?, ?, 'PENDING', 1, ?, NOW())
            ON DUPLICATE KEY UPDATE
                late_excused = 1,
                late_excused_reason = VALUES(late_excused_reason),
                late_notified_at = NOW()");
        $stmt->execute([$userId, $date, $reason]);
        return true;
    }

    /**
     * Manual-log: admin upsert of an attendance status for a user+date (Phase 2.1). API when
     * enabled, else identical direct upsert. Caller computes status + audit + actor id.
     */
    public function createManualLog(int $userId, string $date, string $status, string $audit, int $actorId): bool
    {
        if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        if ($this->useApi) {
            $result = $this->hrClient->createManualLog([
                'user_id' => $userId,
                'date' => $date,
                'status' => $status,
                'audit' => $audit,
                'actor_id' => $actorId,
            ]);
            if (!empty($result['success'])) {
                return true;
            }
            $this->logApiFallback(__FUNCTION__);
        }
        $stmt = $this->pdo->prepare("INSERT INTO hr_attendances
                (user_id, attendance_date, status, adjustment_reason, adjusted_by, adjusted_at, approved_by, approved_at)
            VALUES (?, ?, ?, ?, ?, NOW(), ?, NOW())
            ON DUPLICATE KEY UPDATE
                status=VALUES(status),
                adjustment_reason=CONCAT_WS(\"\\n\", NULLIF(adjustment_reason,''), VALUES(adjustment_reason)),
                adjusted_by=VALUES(adjusted_by), adjusted_at=NOW(),
                approved_by=VALUES(approved_by), approved_at=NOW()");
        $stmt->execute([$userId, $date, $status, $audit, $actorId ?: null, $actorId ?: null]);
        return true;
    }

    /**
     * Plan-late: admin sets a planned late start for a user+date (Phase 2.1). API when
     * enabled, else identical direct upsert. Caller validates rules + supplies shift_id/audit.
     */
    public function planLate(int $userId, string $date, string $plannedStart, string $reason, int $plannedBy, ?int $shiftId, string $audit): bool
    {
        if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $plannedStart)) {
            return false;
        }
        if ($this->useApi) {
            $result = $this->hrClient->planLate([
                'user_id' => $userId,
                'date' => $date,
                'planned_start' => $plannedStart,
                'reason' => $reason,
                'planned_by' => $plannedBy,
                'shift_id' => $shiftId,
                'audit' => $audit,
            ]);
            if (!empty($result['success'])) {
                return true;
            }
            $this->logApiFallback(__FUNCTION__);
        }
        $stmt = $this->pdo->prepare("INSERT INTO hr_attendances
                (user_id, attendance_date, shift_id, planned_start_time, planned_reason,
                 planned_requested_at, planned_requested_by, planned_status, planned_reviewed_by, planned_reviewed_at,
                 adjustment_reason, adjusted_by, adjusted_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), ?, 'APPROVED', ?, NOW(), ?, ?, NOW(), NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                planned_start_time=VALUES(planned_start_time),
                planned_reason=VALUES(planned_reason),
                planned_requested_at=NOW(),
                planned_requested_by=VALUES(planned_requested_by),
                planned_status='APPROVED',
                planned_reviewed_by=VALUES(planned_reviewed_by),
                planned_reviewed_at=NOW(),
                adjustment_reason=CONCAT_WS(\"\\n\", NULLIF(adjustment_reason,''), VALUES(adjustment_reason)),
                updated_at=NOW()");
        $stmt->execute([$userId, $date, $shiftId, $plannedStart, $reason, $plannedBy ?: null, $plannedBy ?: null, $audit, $plannedBy ?: null]);
        return true;
    }

    /**
     * Cancel-plan-late: clear a planned late start on an attendance row (Phase 2.1). API when
     * enabled, else identical direct SQL. Caller validates the row + supplies the audit tag.
     */
    public function cancelPlanLate(int $attendanceId, string $audit): bool
    {
        if ($attendanceId <= 0) {
            return false;
        }
        if ($this->useApi) {
            $result = $this->hrClient->cancelPlanLate([
                'attendance_id' => $attendanceId,
                'audit' => $audit,
            ]);
            if (!empty($result['success'])) {
                return true;
            }
            $this->logApiFallback(__FUNCTION__);
        }
        $stmt = $this->pdo->prepare("UPDATE hr_attendances
            SET planned_start_time=NULL, planned_reason=NULL,
                planned_requested_at=NULL, planned_requested_by=NULL,
                planned_status='CANCELLED',
                adjustment_reason=CONCAT_WS(\"\\n\", NULLIF(adjustment_reason,''), ?),
                updated_at=NOW()
            WHERE id=?");
        $stmt->execute([$audit, $attendanceId]);
        return true;
    }

    /** HR executes policy locally; external consumers cross the authenticated HR API. */
    public function decidePlannedLate(int $attendanceId, int $actorId, string $decision, string $note = ''): array
    {
        $decision = strtoupper(trim($decision));
        if ($attendanceId <= 0 || $actorId <= 0 || !in_array($decision, ['APPROVED','REJECTED'], true)) {
            throw new InvalidArgumentException('ข้อมูลคำขอไม่ถูกต้อง');
        }
        if (defined('BASE_PATH') && realpath(BASE_PATH) !== realpath(dirname(__DIR__,2))) {
            // Do not load HR's PayrollService into a consumer that already has a
            // class with that compatibility name, or fall back to local writes.
            $client = \TpCommon\Http\HrClient::fromEnv();
            $response = $client->decideLineApproval('planned_late',$attendanceId,$decision === 'APPROVED' ? 'approve' : 'reject',$actorId);
            if (empty($response['success'])) throw new RuntimeException((string)($response['error'] ?? $response['message'] ?? 'HR API ไม่สามารถดำเนินการได้'));
            $row = (array)($response['data'] ?? []);
            return isset($row['data']) && is_array($row['data']) ? $row['data'] : $row;
        }
        require_once __DIR__.'/AttendanceService.php';
        require_once __DIR__.'/PayrollService.php';
        require_once __DIR__.'/PlannedLateApprovalService.php';
        return (new PlannedLateApprovalService($this->pdo))->decide($attendanceId,$actorId,$decision,$note);
    }

    /**
     * Toggle the medical-cert document marker on a leave request (Phase 2.1). API when enabled,
     * else identical direct SQL.
     */
    public function setLeaveDocument(int $leaveId, bool $has): bool
    {
        if ($leaveId <= 0) {
            return false;
        }
        if ($this->useApi) {
            $result = $this->hrClient->setLeaveDocument($leaveId, $has);
            if (!empty($result['success'])) {
                return true;
            }
            $this->logApiFallback(__FUNCTION__);
        }
        if ($has) {
            $stmt = $this->pdo->prepare("UPDATE hr_leave_requests SET document_path=COALESCE(document_path,'crm-admin-confirmed') WHERE id=?");
        } else {
            $stmt = $this->pdo->prepare("UPDATE hr_leave_requests SET document_path=NULL WHERE id=?");
        }
        $stmt->execute([$leaveId]);
        return true;
    }

    /**
     * Verbatim CRM leave submit (Phase 2.1). API when enabled, else identical direct INSERT.
     * Preserves the caller's request_number / total_days / document_path (no recompute).
     */
    public function submitLeave(string $requestNumber, int $userId, int $leaveTypeId, string $start, string $end, float $totalDays, string $reason, ?string $documentPath): bool
    {
        if ($userId <= 0 || $leaveTypeId <= 0 || $requestNumber === '') {
            return false;
        }
        if ($this->useApi) {
            $result = $this->hrClient->crmSubmitLeave([
                'request_number' => $requestNumber,
                'user_id' => $userId,
                'leave_type_id' => $leaveTypeId,
                'start_date' => $start,
                'end_date' => $end,
                'total_days' => $totalDays,
                'reason' => $reason,
                'document_path' => $documentPath,
            ]);
            if (!empty($result['success'])) {
                return true;
            }
            $this->logApiFallback(__FUNCTION__);
        }
        $stmt = $this->pdo->prepare("INSERT INTO hr_leave_requests
                (request_number, user_id, leave_type_id, start_date, end_date, total_days, reason, document_path, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING')");
        $stmt->execute([$requestNumber, $userId, $leaveTypeId, $start, $end, $totalDays, $reason ?: null, $documentPath]);
        return true;
    }

    /**
     * CRM single-step approve/reject a leave request (Phase 2.1). API when enabled, else
     * identical direct SQL (txn: UPDATE leave + UPSERT LEAVE attendance per day on approve).
     * Caller supplies the leave's day-range context via the leave row; LINE stays in CRM.
     *
     * @param array{user_id:int,start_date:string,end_date:string} $leave
     */
    public function decideLeave(int $leaveId, string $decision, string $note, int $actorId, string $audit, array $leave): bool
    {
        if ($leaveId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
            return false;
        }
        if ($this->useApi) {
            $result = $this->hrClient->decideLeave($leaveId, [
                'decision' => $decision,
                'note' => $note,
                'actor_id' => $actorId,
                'audit' => $audit,
            ]);
            if (!empty($result['success'])) {
                return true;
            }
            $this->logApiFallback(__FUNCTION__);
        }
        if ($decision === 'approve') {
            $this->pdo->beginTransaction();
            try {
                $this->pdo->prepare("UPDATE hr_leave_requests
                    SET status='APPROVED', final_approved_by=?, final_approved_at=NOW(),
                        approver_1_id=?, approver_1_status='APPROVED', approver_1_date=NOW(), approver_1_remarks=?
                    WHERE id=?")->execute([$actorId ?: null, $actorId ?: null, $note, $leaveId]);
                $sync = $this->pdo->prepare("INSERT INTO hr_attendances (user_id, attendance_date, status, adjustment_reason, adjusted_by, adjusted_at, approved_by, approved_at)
                    VALUES (?, ?, 'LEAVE', ?, ?, NOW(), ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        status='LEAVE',
                        adjustment_reason=CONCAT_WS(\"\\n\", NULLIF(adjustment_reason,''), VALUES(adjustment_reason)),
                        adjusted_by=VALUES(adjusted_by), adjusted_at=NOW(),
                        approved_by=VALUES(approved_by), approved_at=NOW()");
                $ts = strtotime((string)($leave['start_date'] ?? ''));
                $te = strtotime((string)($leave['end_date'] ?? ''));
                for ($t = $ts; $t <= $te; $t += 86400) {
                    $sync->execute([(int)($leave['user_id'] ?? 0), date('Y-m-d', $t), $audit, $actorId ?: null, $actorId ?: null]);
                }
                $this->pdo->commit();
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        } else {
            $this->pdo->prepare("UPDATE hr_leave_requests
                SET status='REJECTED', cancel_reason=?, approver_1_id=?, approver_1_status='REJECTED', approver_1_date=NOW(), approver_1_remarks=?
                WHERE id=?")->execute([$note, $actorId ?: null, $note, $leaveId]);
        }
        return true;
    }

    /**
     * CRM self-service web check-in (Phase 2.1). CRM computes late/status/remarks; this persists.
     * API when enabled, else identical upsert on (user_id, attendance_date).
     */
    public function webCheckIn(int $userId, string $date, string $datetime, $lat, $lng, $ip, string $remarks, int $lateMin, string $status): bool
    {
        // Retired: an API failure must never fall through to unchecked shared-DB writes.
        throw new DomainException('กรุณาลงเวลาเข้า–ออกผ่าน https://checkin.tp-asset.com/ เพื่อยืนยันรูปและพิกัด');
    }

    /**
     * CRM self-service web check-out (Phase 2.1). CRM computes work_minutes + remark; this persists by row id.
     */
    public function webCheckOut(int $attendanceId, string $datetime, $ip, ?string $checkoutRemark, int $workMin): bool
    {
        // Retired: an API failure must never fall through to unchecked shared-DB writes.
        throw new DomainException('กรุณาลงเวลาเข้า–ออกผ่าน https://checkin.tp-asset.com/ เพื่อยืนยันรูปและพิกัด');
    }

    /**
     * Auto-absent cron mark (Phase 2.1 op #12). API when enabled, else identical CASE upsert.
     * Preserves already-set statuses / late_excused / planned-late rows; only blanks → ABSENT.
     */
    public function autoMarkAbsent(int $userId, string $date, string $audit): bool
    {
        if ($this->useApi) {
            $result = $this->hrClient->autoAbsent(['user_id' => $userId, 'date' => $date, 'audit' => $audit]);
            if (!empty($result['success'])) {
                return true;
            }
            $this->logApiFallback(__FUNCTION__);
        }
        $stmt = $this->pdo->prepare("INSERT INTO hr_attendances (user_id, attendance_date, status, adjustment_reason, adjusted_by, adjusted_at)
            VALUES (?, ?, 'ABSENT', ?, NULL, NOW())
            ON DUPLICATE KEY UPDATE
                status = CASE
                    WHEN status IN ('PRESENT','LATE','WFH','HALF_DAY','LEAVE','HOLIDAY') THEN status
                    WHEN late_excused = 1 THEN status
                    WHEN planned_status = 'APPROVED' AND planned_start_time IS NOT NULL AND planned_start_time <> '' THEN status
                    ELSE 'ABSENT' END,
                adjustment_reason = CONCAT_WS(\"\\n\", NULLIF(adjustment_reason,''), VALUES(adjustment_reason)),
                adjusted_at = NOW()");
        $stmt->execute([$userId, $date, $audit]);
        return true;
    }

    /**
     * Recompute-late backfill (Phase 2.1 op #13). Caller computes late_minutes; this persists by row id.
     * API when enabled, else identical UPDATE (sets late_minutes + flips PRESENT/WFH/HALF_DAY↔LATE).
     */
    public function recomputeLate(int $attendanceId, int $lateMinutes): bool
    {
        if ($this->useApi) {
            $result = $this->hrClient->recomputeLate(['attendance_id' => $attendanceId, 'late_minutes' => $lateMinutes]);
            if (!empty($result['success'])) {
                return true;
            }
            $this->logApiFallback(__FUNCTION__);
        }
        $stmt = $this->pdo->prepare("UPDATE hr_attendances SET late_minutes = ?, status = CASE
            WHEN ? > 0 AND status IN ('PRESENT','WFH','HALF_DAY') THEN 'LATE'
            WHEN ? = 0 AND status = 'LATE' THEN 'PRESENT'
            ELSE status END
          WHERE id = ?");
        $stmt->execute([$lateMinutes, $lateMinutes, $lateMinutes, $attendanceId]);
        return true;
    }

    public function isApiMode(): bool
    {
        return $this->useApi;
    }

    /**
     * Observability for Phase 6.x: log when API mode is ON but a read fell back to
     * direct hr_* SQL. Lets us confirm HR API coverage in production logs BEFORE
     * retiring the PDO fallback paths. Logging only — does not change data flow.
     */
    private function logApiFallback(string $method): void
    {
        if (!$this->useApi) {
            return;
        }
        error_log('[HrService] API mode ON but ' . $method . '() fell back to direct hr_* SQL — Phase 6.x coverage gap');
    }
}
