<?php

declare(strict_types=1);

use TpCommon\Hr\EmployeeFinancePolicy;
use TpCommon\Hr\EmployeeFinanceSchedulePolicy;
use TpCommon\Hr\PayrollCalendar;

/**
 * Canonical HR writer for controlled employee-finance corrections.
 *
 * The caller owns authorization; this service revalidates lifecycle and
 * payroll invariants inside one database transaction.
 */
final class EmployeeFinanceManagementService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{finance_type:string,finance_id:int,old_month:string,new_month:string} */
    public function changeFirstDueMonth(
        string $financeType,
        int $financeId,
        string $newMonth,
        int $actorUserId,
        string $reason
    ): array {
        if (!in_array($financeType, ['salary_advance', 'employee_loan'], true) || $financeId <= 0) {
            throw new RuntimeException('ไม่พบรายการการเงินพนักงานที่ต้องการแก้ไข');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('กรุณาระบุเหตุผลที่เปลี่ยนเดือนเริ่มหัก');
        }
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $newMonth)) {
            throw new RuntimeException('รูปแบบเดือนเริ่มหักไม่ถูกต้อง');
        }
        $allowedMonths = [date('Y-m'), date('Y-m', strtotime('+1 month'))];
        if (!in_array($newMonth, $allowedMonths, true)) {
            throw new RuntimeException('เลือกได้เฉพาะรอบเงินเดือนปัจจุบันหรือรอบถัดไป');
        }

        $startedHere = !$this->pdo->inTransaction();
        if ($startedHere) {
            $this->pdo->beginTransaction();
        }
        try {
            $row = $this->lockFinanceRow($financeType, $financeId);
            if (!$row) {
                throw new RuntimeException('ไม่พบรายการการเงินพนักงานที่ต้องการแก้ไข');
            }
            if ((string)$row['status'] !== 'pending_disbursement') {
                throw new RuntimeException('แก้เดือนเริ่มหักได้เฉพาะรายการที่ยังไม่จ่ายเงิน');
            }
            $expenseStatus = (string)($row['expense_status'] ?? '');
            if (!in_array($expenseStatus, ['submitted', 'approved'], true) || !empty($row['paid_at'])) {
                throw new RuntimeException('คำขอนี้จ่ายเงินหรือสิ้นสุดกระบวนการแล้ว จึงแก้เดือนเริ่มหักไม่ได้');
            }
            $this->assertNoPayrollLink($financeType, $financeId);
            $this->assertPayrollMonthOpen($newMonth);

            $oldMonth = (string)$row['first_due_month'];
            if ($oldMonth === $newMonth) {
                throw new RuntimeException('เดือนเริ่มหักใหม่ตรงกับข้อมูลเดิม');
            }

	            if ($financeType === 'salary_advance') {
                $stmt = $this->pdo->prepare(
                    'UPDATE hr_salary_advances
                        SET advance_for_month=?, deduction_month=?, updated_at=NOW()
                      WHERE id=? AND status=\'pending_disbursement\''
                );
                $stmt->execute([$newMonth, $newMonth, $financeId]);
	            } else {
	                $repaymentGuard = $this->pdo->prepare(
	                    "SELECT COUNT(*) FROM hr_loan_repayments
	                      WHERE loan_id=? AND (status<>'scheduled' OR payroll_run_id IS NOT NULL)"
	                );
	                $repaymentGuard->execute([$financeId]);
	                if ((int)$repaymentGuard->fetchColumn() > 0) {
	                    throw new RuntimeException('ตารางผ่อนเริ่มดำเนินการแล้ว จึงเปลี่ยนเดือนเริ่มหักไม่ได้');
	                }
	                $schedule = EmployeeFinancePolicy::buildReducingBalanceSchedule(
                    (float)$row['principal_amount'],
                    (int)$row['term_months'],
                    $newMonth
                );
                $summary = EmployeeFinancePolicy::summarize($schedule);
                $stmt = $this->pdo->prepare(
                    'UPDATE hr_employee_loans
                        SET first_due_month=?, schedule_snapshot_json=?, monthly_installment=?, total_payable=?, updated_at=NOW()
                      WHERE id=? AND status=\'pending_disbursement\''
                );
                $stmt->execute([
                    $newMonth,
                    json_encode($schedule, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    $summary['monthly_installment'],
                    $summary['total_payable'],
                    $financeId,
                ]);
                $this->pdo->prepare(
                    "DELETE FROM hr_loan_repayments
                      WHERE loan_id=? AND status='scheduled' AND payroll_run_id IS NULL"
                )->execute([$financeId]);
                $insert = $this->pdo->prepare(
                    "INSERT INTO hr_loan_repayments
                     (loan_id,installment_no,due_date,due_amount,principal_portion,interest_portion,status)
                     VALUES (?,?,?,?,?,?,'scheduled')"
                );
                foreach ($schedule as $installment) {
                    $insert->execute([
                        $financeId,
                        $installment['installment_no'],
                        $installment['due_date'],
                        $installment['due_amount'],
                        $installment['principal_portion'],
                        $installment['interest_portion'],
                    ]);
                }
            }
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('สถานะรายการถูกเปลี่ยนโดยผู้ใช้อื่น กรุณาโหลดหน้าใหม่');
            }

            $payload = [
                'old_month' => $oldMonth,
                'new_month' => $newMonth,
                'reason' => $reason,
                'expense_request_id' => (int)$row['expense_request_id'],
                'finance_type' => $financeType,
                'finance_id' => $financeId,
            ];
	            $this->pdo->prepare(
	                "INSERT INTO hr_employee_finance_audit_logs
                 (user_id,finance_type,finance_id,event_type,actor_user_id,payload_json,created_at)
                 VALUES (?,?,?,'first_due_month_changed',?,?,NOW())"
	            )->execute([(int)$row['user_id'], $financeType, $financeId, $actorUserId, $this->auditJson($payload)]);
	            $this->enqueueRequesterNotification($row, $financeType, $oldMonth, $newMonth, $reason, $payload);

            if ($startedHere) {
                $this->pdo->commit();
            }
            return [
                'finance_type' => $financeType,
                'finance_id' => $financeId,
                'old_month' => $oldMonth,
                'new_month' => $newMonth,
            ];
        } catch (Throwable $e) {
            if ($startedHere && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Move an already-disbursed collection to a different payroll month.
     *
     * This is the post-payment counterpart of changeFirstDueMonth(): once
     * the company has handed over the money the schedule is live, so the
     * owner rule is narrower — a collection may be pushed out or pulled in
     * only until its real repayment date arrives. Amounts never change;
     * deferring moves *when* the deduction is taken, not what is owed, so
     * the employee still repays exactly what they consented to.
     *
     * For a loan the whole remaining tail moves by the same number of
     * months. Moving one installment on its own would either collide with
     * the next one (two deductions in a single payroll run) or reverse
     * their order, and both corrupt the payroll pickup query, which matches
     * installments by DATE_FORMAT(due_date,'%Y-%m').
     *
     * @return array{finance_type:string,finance_id:int,old_month:string,new_month:string,moved:int}
     */
    public function rescheduleRepayment(
        string $financeType,
        int $financeId,
        int $repaymentId,
        string $newMonth,
        int $actorUserId,
        string $reason
    ): array {
        if (!in_array($financeType, ['salary_advance', 'employee_loan'], true) || $financeId <= 0) {
            throw new RuntimeException('ไม่พบรายการการเงินพนักงานที่ต้องการเลื่อนงวด');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('กรุณาระบุเหตุผลที่เลื่อนงวดชำระ');
        }
        EmployeeFinanceSchedulePolicy::assertMonthInWindow($newMonth);

        $startedHere = !$this->pdo->inTransaction();
        if ($startedHere) {
            $this->pdo->beginTransaction();
        }
        try {
            $row = $this->lockFinanceRow($financeType, $financeId);
            if (!$row) {
                throw new RuntimeException('ไม่พบรายการการเงินพนักงานที่ต้องการเลื่อนงวด');
            }
            $byPayroll = (string)$row['repayment_method'] === 'payroll';
            $result = $financeType === 'salary_advance'
                ? $this->rescheduleAdvance($row, $financeId, $newMonth, $byPayroll)
                : $this->rescheduleLoan($row, $financeId, $repaymentId, $newMonth, $byPayroll);

            $payload = [
                'old_month' => $result['old_month'],
                'new_month' => $result['new_month'],
                'moved_installments' => $result['moved'],
                'reason' => $reason,
                'expense_request_id' => (int)$row['expense_request_id'],
                'finance_type' => $financeType,
                'finance_id' => $financeId,
            ];
            $this->pdo->prepare(
                "INSERT INTO hr_employee_finance_audit_logs
                 (user_id,finance_type,finance_id,event_type,actor_user_id,payload_json,created_at)
                 VALUES (?,?,?,'repayment_rescheduled',?,?,NOW())"
            )->execute([(int)$row['user_id'], $financeType, $financeId, $actorUserId, $this->auditJson($payload)]);
            $this->enqueueRescheduleNotification($row, $financeType, $result, $reason, $payload);

            if ($startedHere) {
                $this->pdo->commit();
            }
            return [
                'finance_type' => $financeType,
                'finance_id' => $financeId,
                'old_month' => $result['old_month'],
                'new_month' => $result['new_month'],
                'moved' => $result['moved'],
            ];
        } catch (Throwable $e) {
            if ($startedHere && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array{old_month:string,new_month:string,moved:int}
     */
    private function rescheduleAdvance(array $row, int $financeId, string $newMonth, bool $byPayroll): array
    {
        $block = EmployeeFinanceSchedulePolicy::advanceBlockReason([
            'status' => (string)$row['status'],
            'deduction_month' => (string)$row['first_due_month'],
            'payroll_run_id' => (int)($row['payroll_run_id'] ?? 0),
            'payroll_link_status' => $this->payrollLinkStatus('salary_advance', $financeId),
        ]);
        if ($block !== null) {
            throw new RuntimeException(EmployeeFinanceSchedulePolicy::reasonText($block));
        }
        $oldMonth = substr((string)$row['first_due_month'], 0, 7);
        if ($oldMonth === $newMonth) {
            throw new RuntimeException('เดือนชำระใหม่ตรงกับข้อมูลเดิม');
        }
        if ($byPayroll) {
            $this->assertPayrollMonthOpen($newMonth);
        }
        $stmt = $this->pdo->prepare(
            'UPDATE hr_salary_advances
                SET advance_for_month=?, deduction_month=?, updated_at=NOW()
              WHERE id=? AND status=?'
        );
        $stmt->execute([$newMonth, $newMonth, $financeId, (string)$row['status']]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('สถานะรายการถูกเปลี่ยนโดยผู้ใช้อื่น กรุณาโหลดหน้าใหม่');
        }
        return ['old_month' => $oldMonth, 'new_month' => $newMonth, 'moved' => 1];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{old_month:string,new_month:string,moved:int}
     */
    private function rescheduleLoan(array $row, int $financeId, int $repaymentId, string $newMonth, bool $byPayroll): array
    {
        if (!EmployeeFinanceSchedulePolicy::loanDisbursed((string)$row['status'])) {
            throw new RuntimeException(EmployeeFinanceSchedulePolicy::reasonText(
                (string)$row['status'] === 'pending_disbursement'
                    ? EmployeeFinanceSchedulePolicy::BLOCK_NOT_DISBURSED
                    : EmployeeFinanceSchedulePolicy::BLOCK_SETTLED
            ));
        }
        $installments = $this->lockLoanInstallments($financeId);
        $targetIndex = null;
        foreach ($installments as $index => $installment) {
            if ((int)$installment['id'] === $repaymentId) {
                $targetIndex = $index;
                break;
            }
        }
        if ($targetIndex === null) {
            throw new RuntimeException('ไม่พบงวดชำระที่ต้องการเลื่อน');
        }
        $target = $installments[$targetIndex];
        $block = EmployeeFinanceSchedulePolicy::installmentBlockReason($target);
        if ($block !== null) {
            throw new RuntimeException(EmployeeFinanceSchedulePolicy::reasonText($block));
        }
        $oldMonth = substr((string)$target['due_date'], 0, 7);
        $delta = EmployeeFinanceSchedulePolicy::monthDelta($oldMonth, $newMonth);
        if ($delta === 0) {
            throw new RuntimeException('เดือนชำระใหม่ตรงกับข้อมูลเดิม');
        }
        // Pulling in must not jump over an earlier collection.
        if ($targetIndex > 0) {
            $previousMonth = substr((string)$installments[$targetIndex - 1]['due_date'], 0, 7);
            if (EmployeeFinanceSchedulePolicy::monthDelta($previousMonth, $newMonth) <= 0) {
                throw new RuntimeException(
                    'เดือนใหม่ต้องอยู่หลังงวดก่อนหน้า (งวด '
                    . (int)$installments[$targetIndex - 1]['installment_no'] . ' ครบกำหนด ' . $previousMonth . ')'
                );
            }
        }
        $updates = [];
        foreach (array_slice($installments, $targetIndex) as $installment) {
            $laterBlock = EmployeeFinanceSchedulePolicy::installmentBlockReason($installment);
            if ($laterBlock !== null) {
                throw new RuntimeException(
                    'งวด ' . (int)$installment['installment_no'] . ' เลื่อนไม่ได้: '
                    . EmployeeFinanceSchedulePolicy::reasonText($laterBlock)
                );
            }
            $month = EmployeeFinanceSchedulePolicy::shiftMonth(substr((string)$installment['due_date'], 0, 7), $delta);
            EmployeeFinanceSchedulePolicy::assertMonthInWindow($month);
            if ($byPayroll) {
                $this->assertPayrollMonthOpen($month);
            }
            $updates[] = [(int)$installment['id'], PayrollCalendar::paymentDate($month)->format('Y-m-d')];
        }
        $stmt = $this->pdo->prepare(
            "UPDATE hr_loan_repayments SET due_date=?
              WHERE id=? AND status='scheduled' AND payroll_run_id IS NULL"
        );
        foreach ($updates as [$id, $dueDate]) {
            $stmt->execute([$dueDate, $id]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('ตารางงวดถูกเปลี่ยนโดยผู้ใช้อื่น กรุณาโหลดหน้าใหม่');
            }
        }
        // first_due_month drives the HR list and the payroll disbursement
        // guard, so keep it pointing at the earliest remaining collection.
        $this->pdo->prepare(
            "UPDATE hr_employee_loans l
                SET l.first_due_month = (
                        SELECT DATE_FORMAT(MIN(r.due_date),'%Y-%m') FROM hr_loan_repayments r WHERE r.loan_id=l.id
                    ), l.updated_at=NOW()
              WHERE l.id=?"
        )->execute([$financeId]);
        return ['old_month' => $oldMonth, 'new_month' => $newMonth, 'moved' => count($updates)];
    }

    /**
     * Installments of one loan, ordered as they will be collected, with the
     * write lock held on hr_loan_repayments only. The payroll links are read
     * in a second, unlocked query on purpose: joining them under FOR UPDATE
     * would also lock rows the payroll run writes, which is the short path to
     * a deadlock between rescheduling and a payroll calculation.
     *
     * @return list<array<string,mixed>>
     */
    private function lockLoanInstallments(int $loanId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id,installment_no,due_date,due_amount,status,payroll_run_id
               FROM hr_loan_repayments
              WHERE loan_id=?
              ORDER BY due_date, installment_no, id
              FOR UPDATE"
        );
        $stmt->execute([$loanId]);
        $installments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($installments === []) {
            return [];
        }
        $linkStmt = $this->pdo->prepare(
            "SELECT x.source_id, x.link_status
               FROM hr_employee_finance_payroll_links x
               JOIN hr_loan_repayments r ON r.id = x.source_id
              WHERE x.source_type='employee_loan_repayment' AND r.loan_id=?"
        );
        $linkStmt->execute([$loanId]);
        $links = $linkStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($installments as $index => $installment) {
            $installments[$index]['payroll_link_status'] = (string)($links[(int)$installment['id']] ?? '');
        }
        return $installments;
    }

    private function payrollLinkStatus(string $sourceType, int $sourceId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT link_status FROM hr_employee_finance_payroll_links
              WHERE source_type=? AND source_id=? LIMIT 1'
        );
        $stmt->execute([$sourceType, $sourceId]);
        return (string)($stmt->fetchColumn() ?: '');
    }

    /** @return array{finance_type:string,finance_id:int,old_method:string,new_method:string} */
    public function changeRepaymentMethod(
        string $financeType,
        int $financeId,
        string $newMethod,
        int $actorUserId,
        string $reason
    ): array {
        if (!in_array($financeType, ['salary_advance', 'employee_loan'], true) || $financeId <= 0) {
            throw new RuntimeException('ไม่พบรายการการเงินพนักงานที่ต้องการแก้ไข');
        }
        if (!in_array($newMethod, ['payroll', 'transfer', 'cash'], true)) {
            throw new RuntimeException('วิธีคืนเงินไม่ถูกต้อง');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('กรุณาระบุเหตุผลที่เปลี่ยนวิธีคืนเงิน');
        }
        $startedHere = !$this->pdo->inTransaction();
        if ($startedHere) $this->pdo->beginTransaction();
        try {
            $row = $this->lockFinanceRow($financeType, $financeId);
            if (!$row) throw new RuntimeException('ไม่พบรายการการเงินพนักงานที่ต้องการแก้ไข');
            if (in_array((string)$row['status'], ['closed', 'deducted', 'cancelled', 'rejected'], true)) {
                throw new RuntimeException('รายการสิ้นสุดแล้ว จึงเปลี่ยนวิธีคืนเงินไม่ได้');
            }
            $received = $this->pdo->prepare(
                "SELECT COUNT(*) FROM hr_employee_finance_repayments_received WHERE finance_type=? AND finance_id=? AND status='posted'"
            );
            $received->execute([$financeType, $financeId]);
            if ((int)$received->fetchColumn() > 0) {
                throw new RuntimeException('เริ่มรับชำระแล้ว จึงเปลี่ยนแผนคืนเงินไม่ได้');
            }
            $this->assertNoPayrollLink($financeType, $financeId);
            if ($newMethod === 'payroll') {
                $this->assertPayrollMonthOpen(substr((string)$row['first_due_month'], 0, 7));
            }
            $oldMethod = (string)$row['repayment_method'];
            if ($oldMethod === $newMethod) throw new RuntimeException('วิธีคืนเงินใหม่ตรงกับข้อมูลเดิม');
            $table = $financeType === 'salary_advance' ? 'hr_salary_advances' : 'hr_employee_loans';
            $nextStatus = (string)$row['status'];
            if (!empty($row['paid_at']) && $financeType === 'salary_advance') {
                $nextStatus = $newMethod === 'payroll' ? 'pending_deduction' : 'partial';
            }
            $stmt = $this->pdo->prepare("UPDATE {$table} SET repayment_method=?,status=?,updated_at=NOW() WHERE id=?");
            $stmt->execute([$newMethod, $nextStatus, $financeId]);
            $payload = [
                'old_method' => $oldMethod, 'new_method' => $newMethod, 'reason' => $reason,
                'expense_request_id' => (int)$row['expense_request_id'],
                'finance_type' => $financeType,
                'finance_id' => $financeId,
            ];
            $this->pdo->prepare(
                "INSERT INTO hr_employee_finance_audit_logs
                 (user_id,finance_type,finance_id,event_type,actor_user_id,payload_json,created_at)
                 VALUES (?,?,?,'repayment_method_changed',?,?,NOW())"
            )->execute([(int)$row['user_id'], $financeType, $financeId, $actorUserId, $this->auditJson($payload)]);
            $this->enqueueRepaymentMethodNotification($row, $financeType, $oldMethod, $newMethod, $reason, $payload);
            if ($startedHere) $this->pdo->commit();
            return ['finance_type'=>$financeType,'finance_id'=>$financeId,'old_method'=>$oldMethod,'new_method'=>$newMethod];
        } catch (Throwable $e) {
            if ($startedHere && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $payload */
    private function auditJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function lockFinanceRow(string $financeType, int $financeId): ?array
    {
        if ($financeType === 'salary_advance') {
            $sql = "SELECT a.id,a.user_id,a.expense_request_id,a.amount principal_amount,1 term_months,
                           a.deduction_month first_due_month,a.repayment_method,a.status,a.payroll_run_id,
                           r.status expense_status,r.paid_at,r.request_code
                      FROM hr_salary_advances a
                      JOIN line_expense_requests r ON r.id=a.expense_request_id
                     WHERE a.id=? LIMIT 1 FOR UPDATE";
        } else {
            $sql = "SELECT l.id,l.user_id,l.expense_request_id,l.principal_amount,l.term_months,
                           l.first_due_month,l.repayment_method,l.status,r.status expense_status,r.paid_at,r.request_code
                      FROM hr_employee_loans l
                      JOIN line_expense_requests r ON r.id=l.expense_request_id
                     WHERE l.id=? LIMIT 1 FOR UPDATE";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$financeId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function assertNoPayrollLink(string $financeType, int $financeId): void
    {
        $sourceType = $financeType === 'salary_advance' ? 'salary_advance' : 'employee_loan_repayment';
        if ($financeType === 'salary_advance') {
            $sql = "SELECT COUNT(*) FROM hr_employee_finance_payroll_links
                     WHERE source_type=? AND source_id=? AND link_status IN ('included','settled')";
            $params = [$sourceType, $financeId];
        } else {
            $sql = "SELECT COUNT(*) FROM hr_employee_finance_payroll_links x
                      JOIN hr_loan_repayments r ON r.id=x.source_id
                     WHERE x.source_type=? AND r.loan_id=? AND x.link_status IN ('included','settled')";
            $params = [$sourceType, $financeId];
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException('รายการนี้เชื่อมกับสลิปเงินเดือนแล้ว จึงเปลี่ยนเดือนเริ่มหักไม่ได้');
        }
    }

	    private function assertPayrollMonthOpen(string $month): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT status FROM payroll_runs WHERE payroll_month=? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$month . '-01']);
        $status = $stmt->fetchColumn();
        if ($status !== false && in_array((string)$status, ['approved', 'paid'], true)) {
            throw new RuntimeException('รอบเงินเดือนที่เลือกอนุมัติหรือจ่ายแล้ว กรุณาเลือกรอบที่ยังเปิดอยู่');
	        }
	    }

	    /**
	     * @param array<string,mixed> $row
	     * @param array<string,mixed> $payload
	     */
	    private function enqueueRequesterNotification(
	        array $row,
	        string $financeType,
	        string $oldMonth,
	        string $newMonth,
	        string $reason,
	        array $payload
	    ): void {
	        $label = $financeType === 'employee_loan' ? 'เงินกู้บริษัท' : 'เบิกเงินเดือนล่วงหน้า';
	        $message = sprintf(
	            "%s %s\nผู้บริหารเปลี่ยนเดือนเริ่มหักจาก %s เป็น %s\nเหตุผล: %s",
	            $label,
	            (string)($row['request_code'] ?? ''),
	            $oldMonth,
	            $newMonth,
	            $reason
	        );
	        $this->enqueueRequesterMessage($row, 'finance_schedule_changed', 'เปลี่ยนเดือนเริ่มหักคืน', $message, $payload);
	    }

        /**
         * @param array<string,mixed> $row
         * @param array{old_month:string,new_month:string,moved:int} $result
         * @param array<string,mixed> $payload
         */
        private function enqueueRescheduleNotification(
            array $row,
            string $financeType,
            array $result,
            string $reason,
            array $payload
        ): void {
            $financeLabel = $financeType === 'employee_loan' ? 'เงินกู้บริษัท' : 'เบิกเงินเดือนล่วงหน้า';
            $scope = $financeType === 'employee_loan' && $result['moved'] > 1
                ? sprintf("\nงวดที่เหลืออีก %d งวดเลื่อนตามไปด้วย", $result['moved'] - 1)
                : '';
            $message = sprintf(
                "%s %s\nผู้บริหารเลื่อนกำหนดชำระจาก %s เป็น %s%s\nเหตุผล: %s",
                $financeLabel,
                (string)($row['request_code'] ?? ''),
                $result['old_month'],
                $result['new_month'],
                $scope,
                $reason
            );
            $this->enqueueRequesterMessage($row, 'finance_schedule_changed', 'เลื่อนกำหนดชำระคืน', $message, $payload);
        }

        /** @param array<string,mixed> $row */
        private function enqueueRepaymentMethodNotification(
            array $row,
            string $financeType,
            string $oldMethod,
            string $newMethod,
            string $reason,
            array $payload
        ): void {
            $financeLabel = $financeType === 'employee_loan' ? 'เงินกู้บริษัท' : 'เบิกเงินเดือนล่วงหน้า';
            $methodLabels = ['payroll'=>'หักเงินเดือน', 'transfer'=>'โอนคืน', 'cash'=>'คืนเงินสด'];
            $message = sprintf(
                "%s %s\nผู้บริหารเปลี่ยนวิธีคืนเงินจาก %s เป็น %s\nเหตุผล: %s",
                $financeLabel,
                (string)($row['request_code'] ?? ''),
                $methodLabels[$oldMethod] ?? $oldMethod,
                $methodLabels[$newMethod] ?? $newMethod,
                $reason
            );
            $this->enqueueRequesterMessage($row, 'finance_repayment_method_changed', 'เปลี่ยนวิธีคืนเงิน', $message, $payload);
        }

        /**
         * One place that puts a schedule-change card in the LINE outbox.
         * Silently does nothing when the outbox table is missing (older
         * deployments) or the employee has no LINE account linked.
         *
         * headline/message/alt_text ride along in payload_json because the
         * ERP outbox worker dispatches through the CRM flex bridge, which
         * renders from the payload — message_text alone would reach nobody
         * on a box with no direct LINE token.
         *
         * @param array<string,mixed> $row
         * @param array<string,mixed> $payload
         */
        private function enqueueRequesterMessage(
            array $row,
            string $messageType,
            string $headline,
            string $message,
            array $payload
        ): void {
            if (!$this->tableExists('erp_expense_line_outbox')) {
                return;
            }
            $line = $this->pdo->prepare('SELECT line_user_id FROM users WHERE id=? AND is_active=1 LIMIT 1');
            $line->execute([(int)$row['user_id']]);
            $lineUserId = trim((string)$line->fetchColumn());
            if ($lineUserId === '') {
                return;
            }
            $payload['stage'] = $messageType;
            $payload['headline'] = $headline;
            $payload['message'] = $message;
            $payload['alt_text'] = trim('TP-EXPENSE ' . (string)($row['request_code'] ?? '')) . ' · ' . $headline;
            $this->pdo->prepare(
                "INSERT INTO erp_expense_line_outbox
                 (expense_request_id,message_type,recipient_type,recipient_line_id,message_text,payload_json,status,scheduled_at)
                 VALUES (?,?,'user',?,?,?,'pending',NOW())"
            )->execute([
                (int)$row['expense_request_id'],
                $messageType,
                $lineUserId,
                mb_substr($message, 0, 2000),
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }

	    private function tableExists(string $table): bool
	    {
	        $stmt = $this->pdo->prepare(
	            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
	        );
	        $stmt->execute([$table]);
	        return (bool)$stmt->fetchColumn();
	    }
}
