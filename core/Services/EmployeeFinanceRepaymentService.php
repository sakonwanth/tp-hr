<?php

declare(strict_types=1);

final class EmployeeFinanceRepaymentService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function receive(
        string $financeType,
        int $financeId,
        float $amount,
        string $paymentMethod,
        string $receivedAt,
        int $actorUserId,
        string $referenceNumber,
        string $notes,
        string $idempotencyKey
    ): array {
        if (!in_array($financeType, ['salary_advance', 'employee_loan'], true) || $financeId <= 0) {
            throw new RuntimeException('ไม่พบรายการลูกหนี้พนักงาน');
        }
        if (!in_array($paymentMethod, ['cash', 'transfer'], true)) {
            throw new RuntimeException('วิธีรับชำระไม่ถูกต้อง');
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('ยอดรับชำระต้องมากกว่า 0 บาท');
        }
        $receivedTimestamp = strtotime($receivedAt);
        if ($receivedTimestamp === false || $receivedTimestamp > time() + 300) {
            throw new RuntimeException('วันเวลารับชำระไม่ถูกต้อง');
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw new RuntimeException('ไม่พบรหัสป้องกันการบันทึกซ้ำ กรุณาโหลดหน้าใหม่');
        }
        $idempotencyHash = hash('sha256', $idempotencyKey);

        $startedHere = !$this->pdo->inTransaction();
        if ($startedHere) {
            $this->pdo->beginTransaction();
        }
        try {
            $existing = $this->findByIdempotency($idempotencyHash);
            if ($existing) {
                if ($startedHere) $this->pdo->commit();
                return $existing;
            }
            $finance = $this->lockFinance($financeType, $financeId);
            if (!$finance) {
                throw new RuntimeException('ไม่พบรายการลูกหนี้พนักงาน');
            }
            if (!in_array((string)$finance['expense_status'], ['paid', 'confirmed', 'completed'], true)) {
                throw new RuntimeException('บันทึกรับคืนได้หลังบริษัทจ่ายเงินให้พนักงานแล้วเท่านั้น');
            }
            if (in_array((string)$finance['status'], ['cancelled', 'closed', 'deducted'], true)) {
                throw new RuntimeException('รายการนี้ปิดยอดหรือยกเลิกแล้ว');
            }
            $paid = $this->postedTotal($financeType, $financeId);
            $total = round((float)$finance['total_payable'], 2);
            $remaining = round($total - $paid, 2);
            if ($amount > $remaining) {
                throw new RuntimeException('ยอดรับชำระเกินยอดคงเหลือ ' . number_format($remaining, 2) . ' บาท');
            }

            $receiptNumber = $this->nextReceiptNumber((int)date('Y', $receivedTimestamp));
            $stmt = $this->pdo->prepare(
                "INSERT INTO hr_employee_finance_repayments_received
                 (receipt_number,finance_type,finance_id,user_id,amount,payment_method,received_at,
                  received_by,reference_number,notes,idempotency_key,status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,'posted')"
            );
            $stmt->execute([
                $receiptNumber, $financeType, $financeId, (int)$finance['user_id'], $amount,
                $paymentMethod, date('Y-m-d H:i:s', $receivedTimestamp), $actorUserId,
                mb_substr(trim($referenceNumber), 0, 100), mb_substr(trim($notes), 0, 1000), $idempotencyHash,
            ]);
            $receiptId = (int)$this->pdo->lastInsertId();
            if ($financeType === 'employee_loan') {
                $this->allocateLoanRepayment($financeId, $receiptId, $amount);
            } else {
                $this->pdo->prepare(
                    'INSERT INTO hr_employee_finance_repayment_allocations
                     (repayment_received_id,loan_repayment_id,allocated_amount) VALUES (?,NULL,?)'
                )->execute([$receiptId, $amount]);
            }

            $newPaid = round($paid + $amount, 2);
            $newRemaining = round(max(0, $total - $newPaid), 2);
            $this->updateLifecycle($financeType, $financeId, $newRemaining);
            $payload = [
                'receipt_id' => $receiptId,
                'receipt_number' => $receiptNumber,
                'finance_type' => $financeType,
                'finance_id' => $financeId,
                'expense_request_id' => (int)$finance['expense_request_id'],
                'user_id' => (int)$finance['user_id'],
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'received_at' => date('Y-m-d H:i:s', $receivedTimestamp),
                'total_payable' => $total,
                'paid_total' => $newPaid,
                'remaining_amount' => $newRemaining,
            ];
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->pdo->prepare(
                "INSERT INTO hr_employee_finance_audit_logs
                 (user_id,finance_type,finance_id,event_type,actor_user_id,payload_json,created_at)
                 VALUES (?,?,?,'repayment_received',?,?,NOW())"
            )->execute([(int)$finance['user_id'], $financeType, $financeId, $actorUserId, $payloadJson]);
            $this->pdo->prepare(
                "INSERT INTO hr_employee_finance_accounting_outbox
                 (repayment_received_id,payload_json,status,available_at)
                 VALUES (?,?,'pending',NOW())"
            )->execute([$receiptId, $payloadJson]);

            if ($startedHere) $this->pdo->commit();
            return $payload;
        } catch (Throwable $e) {
            if ($startedHere && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    private function findByIdempotency(string $hash): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id receipt_id,receipt_number,finance_type,finance_id,user_id,amount,payment_method,
                    received_at FROM hr_employee_finance_repayments_received WHERE idempotency_key=? LIMIT 1"
        );
        $stmt->execute([$hash]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string,mixed>|null */
    private function lockFinance(string $type, int $id): ?array
    {
        $forUpdate = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $sql = $type === 'salary_advance'
            ? "SELECT a.id,a.user_id,a.expense_request_id,a.amount total_payable,a.status,r.status expense_status
                 FROM hr_salary_advances a JOIN line_expense_requests r ON r.id=a.expense_request_id
                WHERE a.id=? LIMIT 1{$forUpdate}"
            : "SELECT l.id,l.user_id,l.expense_request_id,l.total_payable,l.status,r.status expense_status
                 FROM hr_employee_loans l JOIN line_expense_requests r ON r.id=l.expense_request_id
                WHERE l.id=? LIMIT 1{$forUpdate}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function postedTotal(string $type, int $id): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM hr_employee_finance_repayments_received
              WHERE finance_type=? AND finance_id=? AND status='posted'"
        );
        $stmt->execute([$type, $id]);
        return round((float)$stmt->fetchColumn(), 2);
    }

    private function nextReceiptNumber(int $year): string
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            $this->pdo->prepare(
                'INSERT OR IGNORE INTO hr_employee_finance_receipt_sequences (receipt_year,last_number) VALUES (?,0)'
            )->execute([$year]);
            $this->pdo->prepare(
                'UPDATE hr_employee_finance_receipt_sequences SET last_number=last_number+1 WHERE receipt_year=?'
            )->execute([$year]);
            $stmt = $this->pdo->prepare('SELECT last_number FROM hr_employee_finance_receipt_sequences WHERE receipt_year=?');
            $stmt->execute([$year]);
            return sprintf('HR-RC-%d-%06d', $year, (int)$stmt->fetchColumn());
        }
        $this->pdo->prepare(
            'INSERT INTO hr_employee_finance_receipt_sequences (receipt_year,last_number) VALUES (?,1)
             ON DUPLICATE KEY UPDATE last_number=LAST_INSERT_ID(last_number+1)'
        )->execute([$year]);
        $number = (int)$this->pdo->lastInsertId();
        if ($number === 0) {
            $stmt = $this->pdo->prepare('SELECT last_number FROM hr_employee_finance_receipt_sequences WHERE receipt_year=?');
            $stmt->execute([$year]);
            $number = (int)$stmt->fetchColumn();
        }
        return sprintf('HR-RC-%d-%06d', $year, $number);
    }

    private function allocateLoanRepayment(int $loanId, int $receiptId, float $amount): void
    {
        $forUpdate = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $this->pdo->prepare(
            "SELECT id,due_amount,COALESCE(paid_amount,0) paid_amount,status
               FROM hr_loan_repayments WHERE loan_id=? AND status IN ('scheduled','partial')
              ORDER BY installment_no,due_date,id{$forUpdate}"
        );
        $stmt->execute([$loanId]);
        $remaining = $amount;
        $insert = $this->pdo->prepare(
            'INSERT INTO hr_employee_finance_repayment_allocations
             (repayment_received_id,loan_repayment_id,allocated_amount) VALUES (?,?,?)'
        );
        $update = $this->pdo->prepare(
            'UPDATE hr_loan_repayments SET paid_amount=?,paid_at=?,status=? WHERE id=?'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $installment) {
            if ($remaining <= 0) break;
            $outstanding = round((float)$installment['due_amount'] - (float)$installment['paid_amount'], 2);
            if ($outstanding <= 0) continue;
            $allocated = round(min($remaining, $outstanding), 2);
            $newPaid = round((float)$installment['paid_amount'] + $allocated, 2);
            $status = $newPaid >= round((float)$installment['due_amount'], 2) ? 'paid' : 'partial';
            $insert->execute([$receiptId, (int)$installment['id'], $allocated]);
            $update->execute([$newPaid, date('Y-m-d H:i:s'), $status, (int)$installment['id']]);
            $remaining = round($remaining - $allocated, 2);
        }
        if ($remaining > 0.001) {
            throw new RuntimeException('ไม่สามารถกระจายยอดรับชำระเข้าตารางผ่อนได้ครบ');
        }
    }

    private function updateLifecycle(string $type, int $id, float $remaining): void
    {
        if ($type === 'employee_loan') {
            $status = $remaining <= 0 ? 'closed' : 'active';
            $this->pdo->prepare(
                'UPDATE hr_employee_loans SET status=?,closed_at=? WHERE id=?'
            )->execute([$status, $status === 'closed' ? date('Y-m-d') : null, $id]);
        } else {
            $this->pdo->prepare('UPDATE hr_salary_advances SET status=? WHERE id=?')
                ->execute([$remaining <= 0 ? 'repaid' : 'partial', $id]);
        }
    }
}
