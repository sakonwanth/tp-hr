<?php

/** Canonical write boundary for HR approvals initiated by the CRM LINE webhook. */
final class HrLineApprovalService
{
    public function __construct(private PDO $pdo) {}

    public function decide(string $workflow, int $requestId, int $actorId, string $decision): array
    {
        $workflow = strtolower(trim($workflow));
        $decision = strtolower(trim($decision));
        if ($requestId <= 0 || $actorId <= 0 || !in_array($decision, ['approve','reject'], true)) {
            throw new InvalidArgumentException('ข้อมูลการอนุมัติไม่ถูกต้อง');
        }
        if ($decision === 'reject') $note = 'ไม่อนุมัติผ่าน LINE';
        else $note = 'อนุมัติผ่าน LINE';

        return match ($workflow) {
            'leave' => $this->decideLeave($requestId, $actorId, $decision, $note),
            'adjustment' => $this->decideAdjustment($requestId, $actorId, $decision, $note),
            'outside' => $this->decideOutside($requestId, $actorId, $decision, $note),
            'dayoff' => $this->decideSimple($workflow, 'hr_dayoff_requests', $requestId, $actorId, $decision, $note),
            'holiday_work' => $this->decideSimple($workflow, 'hr_holiday_work_exceptions', $requestId, $actorId, $decision, $note),
            default => throw new InvalidArgumentException('ไม่รองรับประเภทคำขอนี้'),
        };
    }

    private function decideAdjustment(int $id, int $actorId, string $decision, string $note): array
    {
        $this->assertContextNotSelf('adjustment',$id,$actorId);
        $svc = new AttendanceAdjustmentService($this->pdo);
        $result = $decision === 'approve' ? $svc->approve($id, $actorId, $note) : $svc->reject($id, $actorId, $note);
        return $this->context('adjustment', $id) + $result;
    }

    private function decideOutside(int $id, int $actorId, string $decision, string $note): array
    {
        $this->assertContextNotSelf('outside',$id,$actorId);
        $svc = new OutsideAttendanceService($this->pdo);
        $result = $decision === 'approve' ? $svc->approve($id, $actorId, $note) : $svc->reject($id, $actorId, $note);
        return $this->context('outside', $id) + $result;
    }

    private function decideLeave(int $id, int $actorId, string $decision, string $note): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM hr_leave_requests WHERE id=? LIMIT 1 FOR UPDATE');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('ไม่พบคำขอลา');
            $this->assertPendingAndNotSelf($row, $actorId);
            $status = $decision === 'approve' ? 'APPROVED' : 'REJECTED';
            $update = $this->pdo->prepare("UPDATE hr_leave_requests SET status=?,final_approved_by=?,final_approved_at=NOW(),approver_1_id=?,approver_1_status=?,approver_1_date=NOW(),approver_1_remarks=?,updated_at=NOW() WHERE id=? AND status='PENDING'");
            $update->execute([$status,$actorId,$actorId,$status,$note,$id]);
            if ($update->rowCount() !== 1) throw new DomainException('คำขอนี้ได้รับการดำเนินการแล้ว');
            if ($decision === 'approve') {
                $this->pdo->prepare('UPDATE hr_leave_entitlements SET pending_days=GREATEST(0,pending_days-?),used_days=used_days+? WHERE user_id=? AND leave_type_id=? AND year=?')->execute([$row['total_days'],$row['total_days'],$row['user_id'],$row['leave_type_id'],date('Y',strtotime($row['start_date']))]);
            } else {
                $this->pdo->prepare('UPDATE hr_leave_entitlements SET pending_days=GREATEST(0,pending_days-?) WHERE user_id=? AND leave_type_id=? AND year=?')->execute([$row['total_days'],$row['user_id'],$row['leave_type_id'],date('Y',strtotime($row['start_date']))]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        if ($decision === 'approve') {
            try {
                crm_line_sync_approved_leave_attendance($this->pdo, $id, $actorId, 'LINE approver');
            } catch (Throwable $e) {
                tpHrLogException($e, 'line-approval/leave-attendance-sync');
            }
        }
        $status = $decision === 'approve' ? 'APPROVED' : 'REJECTED';
        crm_line_notify_leave_decision($this->pdo, $id, $status, $note);
        tp_hr_push_leave_decision($this->pdo, $id, $status, $note);
        return $this->context('leave', $id);
    }

    private function decideSimple(string $workflow, string $table, int $id, int $actorId, string $decision, string $note): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE id=? LIMIT 1 FOR UPDATE");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('ไม่พบคำขอ');
            $this->assertPendingAndNotSelf($row, $actorId);
            $status = $decision === 'approve' ? 'APPROVED' : 'REJECTED';
            $update = $this->pdo->prepare("UPDATE {$table} SET status=?,reviewed_by=?,reviewed_at=NOW(),review_note=? WHERE id=? AND status='PENDING'");
            $update->execute([$status,$actorId,$note,$id]);
            if ($update->rowCount() !== 1) throw new DomainException('คำขอนี้ได้รับการดำเนินการแล้ว');
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        if ($workflow === 'dayoff') crm_line_notify_dayoff_decision($this->pdo,$id,$decision === 'approve' ? 'APPROVED' : 'REJECTED',$note);
        else crm_line_notify_holiday_work_decision($this->pdo,$id,$decision === 'approve' ? 'APPROVED' : 'REJECTED',$note);
        return $this->context($workflow, $id);
    }

    private function assertPendingAndNotSelf(array $row, int $actorId): void
    {
        if (($row['status'] ?? '') !== 'PENDING') throw new DomainException('คำขอนี้ได้รับการดำเนินการแล้ว');
        if ((int)($row['user_id'] ?? 0) === $actorId) throw new DomainException('ผู้ยื่นคำขอไม่สามารถอนุมัติคำขอของตนเอง');
    }

    private function assertContextNotSelf(string $workflow,int $id,int $actorId): void
    {
        $row=$this->context($workflow,$id);
        if (!$row) throw new RuntimeException('ไม่พบคำขอ');
        $this->assertPendingAndNotSelf($row,$actorId);
    }

    private function context(string $workflow, int $id): array
    {
        [$table,$dateExpr] = match ($workflow) {
            'leave' => ['hr_leave_requests', 'r.start_date'],
            'adjustment' => ['hr_attendance_adjustments', 'a.attendance_date'],
            'outside' => ['hr_attendance_outside_requests', 'r.request_date'],
            'dayoff' => ['hr_dayoff_requests', 'r.week_start'],
            'holiday_work' => ['hr_holiday_work_exceptions', 'r.holiday_date'],
        };
        if ($workflow === 'adjustment') {
            $sql = "SELECT r.*,{$dateExpr} event_date,CONCAT_WS(' ',u.first_name_th,u.last_name_th) employee_name FROM {$table} r JOIN hr_attendances a ON a.id=r.attendance_id JOIN users u ON u.id=r.user_id WHERE r.id=?";
        } else {
            $sql = "SELECT r.*,{$dateExpr} event_date,CONCAT_WS(' ',u.first_name_th,u.last_name_th) employee_name FROM {$table} r JOIN users u ON u.id=r.user_id WHERE r.id=?";
        }
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
