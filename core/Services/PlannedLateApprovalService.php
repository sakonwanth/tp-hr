<?php

/** One decision boundary for the HR inbox, LINE and legacy integration adapters. */
final class PlannedLateApprovalService
{
    public function __construct(private PDO $pdo) {}

    public static function effectiveStart(?array $attendance): ?string
    {
        if (!$attendance || ($attendance['planned_status'] ?? null) !== 'APPROVED') return null;
        $value = trim((string)($attendance['planned_start_time'] ?? ''));
        return $value !== '' ? $value : null;
    }

    public function decide(int $attendanceId, int $actorId, string $decision, string $note = ''): array
    {
        require_once __DIR__ . '/ClockHealth.php';
        ClockHealth::assertSafe();
        $decision = strtoupper(trim($decision));
        if (!in_array($decision, ['APPROVED', 'REJECTED'], true)) throw new InvalidArgumentException('Invalid planned-late decision');
        if ($attendanceId <= 0 || $actorId <= 0) throw new InvalidArgumentException('Invalid approval context');
        $note = mb_substr(trim($note), 0, 500);

        $this->pdo->beginTransaction();
        try {
            $actor = $this->pdo->prepare("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.is_active=1 AND r.name IN ('HR','Admin','Chairman','CEO') FOR UPDATE");
            $actor->execute([$actorId]);
            if (!$actor->fetchColumn()) throw new DomainException('ผู้อนุมัติไม่มีสิทธิ์หรือบัญชีถูกปิดใช้งาน');
            $stmt = $this->pdo->prepare('SELECT * FROM hr_attendances WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$attendanceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['planned_start_time'])) throw new RuntimeException('ไม่พบคำขอแจ้งเข้างานสาย');
            if ((string)$row['planned_status'] !== 'PENDING') throw new DomainException('คำขอนี้ได้รับการดำเนินการแล้ว');
            if ((int)$row['user_id'] === $actorId) throw new DomainException('ผู้ยื่นคำขอไม่สามารถอนุมัติคำขอของตนเอง');
            // A timely request remains reviewable after arrival; never excuse a retrospective request.
            if (!empty($row['check_in_time']) && (empty($row['planned_requested_at'])
                || strtotime($row['planned_requested_at']) === false
                || strtotime($row['planned_requested_at']) > strtotime($row['check_in_time']))) {
                throw new DomainException('คำขอไม่ได้ส่งก่อนลงเวลา กรุณาใช้การแก้ไขเวลาผ่าน HR');
            }
            $affectedRuns = $this->assertPayrollOpen($row);
            $summary = null;
            if (!empty($row['check_in_time']) && !in_array($row['check_in_outside_status'] ?? null, ['PENDING', 'REJECTED'], true)) {
                $attendance = new AttendanceService($this->pdo);
                $target = $attendance->getUserForAttendance((int)$row['user_id']);
                if (!$target) throw new DomainException('ไม่พบพนักงานที่สามารถคำนวณเวลางานได้ กรุณาติดต่อ HR');
                $summary = $attendance->determineCheckIn($target, $attendance->getShiftById((int)($row['shift_id'] ?? 0)), $row['check_in_time'], $decision === 'APPROVED' ? $row['planned_start_time'] : null);
            }
            ClockHealth::assertSafe();
            $update = $this->pdo->prepare("UPDATE hr_attendances SET planned_status = ?, planned_reviewed_by = ?, planned_reviewed_at = NOW(), planned_review_note = ?, updated_at = NOW() WHERE id = ? AND planned_status = 'PENDING'");
            $update->execute([$decision, $actorId, $note, $attendanceId]);
            if ($update->rowCount() !== 1) throw new DomainException('คำขอนี้ได้รับการดำเนินการแล้ว');
            if ($summary !== null) $this->pdo->prepare('UPDATE hr_attendances SET status=?, late_minutes=? WHERE id=?')->execute([$summary['status'], $summary['late_minutes'], $attendanceId]);
            foreach ($affectedRuns as $run) {
                if ($run['status'] !== 'calculated') continue;
                $invalidate = $this->pdo->prepare("UPDATE payroll_runs SET status='draft',updated_at=NOW() WHERE id=? AND status='calculated'");
                $invalidate->execute([(int)$run['id']]);
                if ($invalidate->rowCount() !== 1) throw new DomainException('สถานะรอบเงินเดือนเปลี่ยน กรุณาโหลดข้อมูลใหม่');
                $this->audit($actorId, 'PAYROLL_RECALC_REQUIRED', 'payroll_runs', (int)$run['id'], ['status'=>'calculated'], ['status'=>'draft','reason'=>'planned_late_decision','attendance_id'=>$attendanceId,'decision'=>$decision]);
            }
            $this->audit($actorId, 'PLANNED_LATE_'.$decision, 'hr_attendances', $attendanceId,
                ['planned_status'=>$row['planned_status'],'status'=>$row['status'] ?? null,'late_minutes'=>$row['late_minutes'] ?? null],
                ['planned_status'=>$decision,'status'=>$summary['status'] ?? ($row['status'] ?? null),'late_minutes'=>$summary['late_minutes'] ?? ($row['late_minutes'] ?? null),'review_note'=>$note]);
            $fresh = $this->pdo->prepare("SELECT a.*,CONCAT_WS(' ',u.first_name_th,u.last_name_th) employee_name FROM hr_attendances a JOIN users u ON u.id=a.user_id WHERE a.id=?");
            $fresh->execute([$attendanceId]);$result = $fresh->fetch(PDO::FETCH_ASSOC);
            if (!$result) throw new RuntimeException('ไม่พบผลอนุมัติหลังบันทึก');
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Lock the same canonical 26→25/hire/resignation periods before changing supporting attendance. */
    private function assertPayrollOpen(array $row): array
    {
        $payroll = new PayrollService($this->pdo);
        $date = (string)$row['attendance_date'];$month = date('Y-m-01', strtotime($date));$nextMonth = date('Y-m-01', strtotime($month.' +1 month'));
        $stmt = $this->pdo->prepare("SELECT r.id,r.payroll_month,r.pay_day,r.status FROM payroll_runs r JOIN payroll_slips s ON s.payroll_run_id=r.id WHERE s.user_id=? AND r.payroll_month BETWEEN ? AND ? ORDER BY r.id FOR UPDATE");
        $stmt->execute([(int)$row['user_id'], $month, $nextMonth]);
        $hire = $payroll->getUserHireDate((int)$row['user_id']);$termination = $payroll->getUserTerminationDate((int)$row['user_id']);$affected = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $run) {
            $period = $payroll->effectivePeriodBounds($run['payroll_month'], (int)$run['pay_day'], $hire, $termination);
            if ($date < $period['start'] || $date > $period['end']) continue;
            if (in_array($run['status'], ['approved','paid'], true)) throw new DomainException('รอบเงินเดือนนี้อนุมัติหรือจ่ายแล้ว กรุณาให้ HR ตรวจการแก้ไขย้อนหลัง');
            $affected[] = $run;
        }
        return $affected;
    }

    private function audit(int $actorId,string $action,string $table,int $id,array $before,array $after): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO hr_audit_logs (user_id,action,module,table_name,record_id,old_values,new_values) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$actorId,$action,'planned-late',$table,$id,json_encode($before,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),json_encode($after,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
    }
}
