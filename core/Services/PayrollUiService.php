<?php
/**
 * PayrollUiService — CRM adapter (Phase 6.1 / Phase C)
 *
 * All CRM admin payroll operations use the local engine (shared tp_crm DB + schema fallbacks).
 * USE_TPHR_PAYROLL applies to tp-hr employee-facing paths; this adapter does not call HR API.
 * ERP ledger writes stay in CRM (PayrollLedgerService).
 */

/**
 * The payroll status change committed, but the ERP ledger write that follows it did not.
 * Extends RuntimeException so existing callers that expect mark-paid/cancel-paid to throw on
 * ledger failure keep working; the web UI catches it specifically to show both outcomes.
 */
class PayrollLedgerPendingException extends RuntimeException
{
}

class PayrollUiService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ──────────────── Run Management ────────────────

    public function createRun(string $month, int $createdBy, ?int $payDay = null): array
    {
        require_once HR_OWNER_PATH . '/modules/payroll/queries.php';
        return payroll_create_run_local($this->pdo, $month, $createdBy, $payDay);
    }

    /** @param array{actor_id?:int,actor_role?:string,reason?:string}|null $affordabilityOverride */
    public function approveRun(int $runId, int $approvedBy, ?array $affordabilityOverride = null): void
    {
        require_once HR_OWNER_PATH . '/modules/payroll/queries.php';
        $this->pdo->beginTransaction();
        try {
            payroll_assert_run_salary_snapshot_current($this->pdo, $runId);
            payroll_sync_employee_finance_links_for_run($this->pdo, $runId);
            payroll_assert_employee_finance_run_reconciled($this->pdo, $runId, $affordabilityOverride);
            $stmt = $this->pdo->prepare(
                "UPDATE payroll_runs SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'calculated'"
            );
            $stmt->execute([$approvedBy, $runId]);
            if (!$stmt->rowCount()) {
                throw new \RuntimeException('อนุมัติได้เฉพาะรอบที่คำนวณแล้วเท่านั้น');
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function cancelApproval(int $runId, ?int $actorId = null): void
    {
        $run = $this->getRun($runId);
        if (!$run) {
            throw new \RuntimeException('ไม่พบรอบเงินเดือน');
        }
        $status = (string)($run['status'] ?? '');
        if ($status === 'paid') {
            throw new \RuntimeException('ไม่สามารถยกเลิกการอนุมัติได้ — รอบนี้ถูกบันทึกว่าจ่ายแล้ว กรุณากด "ยกเลิกการบันทึกจ่าย" ก่อน');
        }
        if ($status !== 'approved') {
            throw new \RuntimeException('ยกเลิกการอนุมัติได้เฉพาะรอบที่อนุมัติแล้วเท่านั้น (สถานะปัจจุบัน: ' . $status . ')');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE payroll_runs SET status = 'calculated', approved_by = NULL, approved_at = NULL WHERE id = ? AND status = 'approved'"
            );
            $stmt->execute([$runId]);
            if (!$stmt->rowCount()) {
                throw new \RuntimeException('ยกเลิกการอนุมัติไม่สำเร็จ กรุณาลองใหม่');
            }
            require_once HR_OWNER_PATH . '/modules/payroll/queries.php';
            payroll_revoke_employee_finance_affordability_overrides($this->pdo, $runId, $actorId);
            // Slip links already sent over LINE stop working together with the approval.
            $this->pdo->prepare("UPDATE payroll_slip_tokens t
                JOIN payroll_slips s ON t.slip_id = s.id
                SET t.revoked_at = NOW()
                WHERE s.payroll_run_id = ? AND t.revoked_at IS NULL")
                ->execute([$runId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * The ledger write goes to ERP (possibly over HTTP), so it cannot share the payroll
     * transaction. If it fails, the run is still paid — PayrollLedgerPendingException says
     * so explicitly, and syncLedger() retries (recordExpenseOnMarkPaid is idempotent).
     */
    public function markPaid(int $runId, ?int $createdBy = null): void
    {
        require_once HR_OWNER_PATH . '/modules/payroll/queries.php';
        if ($this->ledgerState($runId) === 'orphan') {
            // A ledger row from an earlier payment of this run survived a failed reversal.
            // Recording again would be skipped as "already exists" and keep the old amount.
            throw new \RuntimeException('รอบนี้ยังมีรายจ่ายเงินเดือนจากการบันทึกจ่ายครั้งก่อนค้างในบัญชีกลาง — กด "ซิงก์บัญชีกลาง" ก่อนบันทึกจ่าย');
        }
        $this->pdo->beginTransaction();
        try {
            // cancelPaid() leaves this run's loan/advance links "reversed"; paying again must
            // re-include them from the (unchanged, approved) slips — as approveRun() does —
            // or reconciliation fails with LINK_MISSING.
            payroll_sync_employee_finance_links_for_run($this->pdo, $runId);
            payroll_assert_employee_finance_run_reconciled($this->pdo, $runId);
            $stmt = $this->pdo->prepare("UPDATE payroll_runs SET status = 'paid' WHERE id = ? AND status = 'approved'");
            $stmt->execute([$runId]);
            if (!$stmt->rowCount()) {
                throw new \RuntimeException('บันทึกจ่ายได้เฉพาะรอบที่อนุมัติแล้ว — กรุณาโหลดหน้าใหม่');
            }
            $this->settleEmployeeFinanceForRun($runId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        try {
            $this->ledgerService()->recordExpenseOnMarkPaid($runId, $createdBy);
            if (!$this->ledgerService()->hasExpenseForRun($runId)) {
                throw new \RuntimeException('ledger row not found after recording');
            }
        } catch (\Throwable $e) {
            tpCrmLogException($e, 'PayrollUiService::markPaid ledger');
            throw new PayrollLedgerPendingException(
                'บันทึกจ่ายเรียบร้อยแล้ว แต่ลงรายจ่ายเข้าบัญชีกลาง (ERP) ไม่สำเร็จ — กด "ซิงก์บัญชีกลาง" ในหน้ารอบนี้เพื่อลองใหม่',
                0,
                $e
            );
        }
    }

    public function cancelPaid(int $runId, string $reason = ''): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("UPDATE payroll_runs SET status = 'approved' WHERE id = ? AND status = 'paid'");
            $stmt->execute([$runId]);
            if (!$stmt->rowCount()) {
                throw new \RuntimeException('ยกเลิกการบันทึกจ่ายได้เฉพาะรอบที่บันทึกจ่ายแล้ว — กรุณาโหลดหน้าใหม่');
            }
            $this->reverseEmployeeFinanceForRun($runId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        try {
            $this->ledgerService()->reverseExpenseOnCancelPaid(
                $runId,
                $reason !== '' ? $reason : 'ยกเลิกการบันทึกจ่าย payroll run #' . $runId
            );
            // LedgerService::deleteAllByReference() swallows its own errors and returns 0,
            // so check the outcome rather than trusting the absence of an exception.
            if ($this->ledgerService()->hasExpenseForRun($runId)) {
                throw new \RuntimeException('ledger row still present after reversal');
            }
        } catch (\Throwable $e) {
            tpCrmLogException($e, 'PayrollUiService::cancelPaid ledger');
            throw new PayrollLedgerPendingException(
                'ยกเลิกการบันทึกจ่ายแล้ว แต่ลบรายจ่ายออกจากบัญชีกลาง (ERP) ไม่สำเร็จ — กด "ซิงก์บัญชีกลาง" ในหน้ารอบนี้เพื่อลองใหม่',
                0,
                $e
            );
        }
    }

    /**
     * Whether ERP's ledger agrees with the run status.
     * 'in_sync' | 'missing' (paid, no expense row) | 'orphan' (not paid, row remains) | 'unknown' (ERP unreachable)
     */
    public function ledgerState(int $runId): string
    {
        $run = $this->getRun($runId);
        if (!$run) {
            return 'unknown';
        }
        try {
            $hasRow = $this->ledgerService()->hasExpenseForRun($runId);
        } catch (\Throwable $e) {
            return 'unknown';
        }
        $isPaid = (string)$run['status'] === 'paid';
        if ($isPaid && !$hasRow) return 'missing';
        if (!$isPaid && $hasRow) return 'orphan';
        return 'in_sync';
    }

    /** Repair a ledger left behind by a failed mark-paid / cancel-paid. Returns the action taken. */
    public function syncLedger(int $runId, ?int $actorId = null): string
    {
        $state = $this->ledgerState($runId);
        // The ERP transport can fail without throwing, so each repair checks its own outcome.
        if ($state === 'missing') {
            $this->ledgerService()->recordExpenseOnMarkPaid($runId, $actorId);
            if (!$this->ledgerService()->hasExpenseForRun($runId)) {
                throw new \RuntimeException('ลงรายจ่ายเข้าบัญชีกลาง (ERP) ไม่สำเร็จ กรุณาลองใหม่ภายหลัง');
            }
            return 'recorded';
        }
        if ($state === 'orphan') {
            $this->ledgerService()->reverseExpenseOnCancelPaid($runId, 'ซิงก์บัญชีกลาง: รอบ #' . $runId . ' ไม่ได้อยู่ในสถานะจ่ายแล้ว');
            if ($this->ledgerService()->hasExpenseForRun($runId)) {
                throw new \RuntimeException('ลบรายจ่ายออกจากบัญชีกลาง (ERP) ไม่สำเร็จ กรุณาลองใหม่ภายหลัง');
            }
            return 'reversed';
        }
        if ($state === 'unknown') {
            throw new \RuntimeException('ติดต่อระบบบัญชีกลาง (ERP) ไม่ได้ กรุณาลองใหม่ภายหลัง');
        }
        return 'in_sync';
    }

    private function ledgerService(): PayrollLedgerService
    {
        require_once HR_OWNER_PATH . '/core/Services/PayrollLedgerService.php';
        return payrollLedgerService();
    }

    private function settleEmployeeFinanceForRun(int $runId): void
    {
        require_once HR_OWNER_PATH . '/modules/payroll/queries.php';
        payroll_settle_employee_finance_for_run($this->pdo, $runId);
    }

    private function reverseEmployeeFinanceForRun(int $runId): void
    {
        require_once HR_OWNER_PATH . '/modules/payroll/queries.php';
        payroll_reverse_employee_finance_for_run($this->pdo, $runId);
    }

    // ──────────────── Salary Setup ────────────────

    public function getSalarySetup(int $userId, string $month = ''): ?array
    {
        require_once HR_OWNER_PATH . '/modules/payroll/queries.php';
        payroll_ensure_social_security_manual_column($this->pdo);
        $monthFirst = ($month ?: date('Y-m')) . '-01';
        return get_salary_setup_for_month($this->pdo, $userId, $monthFirst);
    }

    public function saveSalarySetup(int $userId, array $data): array
    {
        require_once HR_OWNER_PATH . '/modules/payroll/queries.php';
        payroll_ensure_social_security_manual_column($this->pdo);
        return payroll_save_salary_setup_bundle($this->pdo, $userId, $data);
    }

    // ──────────────── Slip Preview ────────────────

    public function recalculateSlip(int $runId, int $userId, string $monthFirst): bool
    {
        require_once HR_OWNER_PATH . '/modules/payroll/queries.php';
        return payroll_recalculate_employee_slip($this->pdo, $runId, $userId, $monthFirst);
    }

    public function calculatePreview(int $userId, string $month, ?array $setupOverride = null, ?int $payDay = null): array
    {
        require_once HR_OWNER_PATH . '/modules/payroll/queries.php';
        $monthFirst = preg_match('/^\d{4}-\d{2}-\d{2}$/', $month) ? $month : ($month . '-01');
        if ($payDay === null) {
            $payDay = payroll_get_default_pay_day();
        }
        return payroll_calculate_slip_for_user($this->pdo, $userId, $monthFirst, $payDay, $setupOverride);
    }

    // ──────────────── Query helpers (always shared DB) ────────────────

    public function listRuns(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return $this->pdo->query("
            SELECT r.*, u.first_name_th as approver_first, u.last_name_th as approver_last
            FROM payroll_runs r LEFT JOIN users u ON r.approved_by = u.id
            ORDER BY r.payroll_month DESC LIMIT {$limit}
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRun(int $runId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM payroll_runs WHERE id = ?");
        $stmt->execute([$runId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getSlips(int $runId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*, u.employee_code, u.first_name_th, u.last_name_th
            FROM payroll_slips s JOIN users u ON s.user_id = u.id
            WHERE s.payroll_run_id = ?
              AND (u.employee_code IS NULL OR u.employee_code NOT LIKE 'CR%')
              AND " . tp_crm_person_user_condition_sql('u') . "
            ORDER BY u.employee_code
        ");
        $stmt->execute([$runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** CRM payroll adapter always uses shared DB — not HR API. */
    public function isApiMode(): bool
    {
        return false;
    }

    /** @deprecated CRM payroll no longer calls HR API */
    public function isHrClientReady(): bool
    {
        return true;
    }
}

function payroll_service(): PayrollUiService
{
    static $instance = null;
    if ($instance === null) {
        $instance = new PayrollUiService(getDB());
    }
    return $instance;
}
