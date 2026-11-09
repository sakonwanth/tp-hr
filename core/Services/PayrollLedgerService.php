<?php
/**
 * Payroll ↔ ERP ledger sync (erp_company_transactions)
 *
 * เงินเดือนบันทึกรายจ่ายเข้า Ledger ตอน mark paid เท่านั้น (CRM)
 * tp-hr / tp-checkin ไม่เขียน Ledger — อ่าน payroll_runs / payroll_slips ร่วมกัน
 */

// Generic ERP transport is shared infrastructure; payroll ownership is HR.
require_once dirname(__DIR__).'/LegacyHrUi.php';
foreach (['LedgerService','ErpService','ErpDualMode','ErpWriteRouter','ErpReadLocalQueries','ErpReadLineFinance','ErpReadHelpers','ErpReadRouter','LedgerGlBridgeService'] as $transport) {
    require_once hrTransportCrmRoot().'/core/Services/'.$transport.'.php';
}

class PayrollLedgerService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? getDB();
    }

    /**
     * สร้างรายจ่ายเงินเดือนใน erp_company_transactions เมื่อบันทึกจ่ายแล้ว
     * ไม่สร้างซ้ำถ้ามี reference payroll + run_id อยู่แล้ว
     */
    public function recordExpenseOnMarkPaid(int $runId, ?int $createdBy = null): bool
    {
        $runInfo = $this->pdo->prepare(
            'SELECT payroll_month, pay_day, total_gross, total_net, employee_count FROM payroll_runs WHERE id = ? LIMIT 1'
        );
        $runInfo->execute([$runId]);
        $runData = $runInfo->fetch(PDO::FETCH_ASSOC);
        if (!$runData) {
            return false;
        }

        if (erp_read_company_transaction_by_reference('payroll', $runId)) {
            return false;
        }

        require_once HR_OWNER_PATH . '/modules/payroll/queries.php';

        $salaryCatId = erp_read_category_id_by_name_like('เงินเดือน');

        $txCode = 'PAY-' . str_replace('-', '', $runData['payroll_month']) . '-' . substr(md5(uniqid((string)mt_rand(), true)), 0, 6);
        $payDate = payroll_format_pay_date(
            $runData['payroll_month'],
            (int)($runData['pay_day'] ?? payroll_get_default_pay_day())
        );
        if ($payDate === '') {
            $payDate = $runData['payroll_month'] . '-25';
        }

        $desc = 'เงินเดือนพนักงาน ' . $runData['payroll_month'] . ' (' . (int)$runData['employee_count'] . ' คน)';
        $netAmount = (float)$runData['total_net'];

        erp_writes()->insert('erp_company_transactions', [
            'transaction_code' => $txCode,
            'transaction_type' => 'expense',
            'category_id' => $salaryCatId,
            'amount' => $netAmount,
            'description' => $desc,
            'transaction_date' => $payDate,
            'payment_method' => 'transfer',
            'payment_status' => 'paid',
            'reference_type' => 'payroll',
            'reference_id' => $runId,
            'notes' => 'สร้างอัตโนมัติจากระบบเงินเดือน — Gross: ฿' . number_format((float)$runData['total_gross'], 2) . ' / Net: ฿' . number_format($netAmount, 2),
            'created_by' => $createdBy,
        ]);

        return true;
    }

    /**
     * ลบรายจ่าย payroll ใน Ledger (พร้อม audit_logs ผ่าน LedgerService)
     *
     * @return int จำนวนแถวที่ลบ
     */
    public function reverseExpenseOnCancelPaid(int $runId, string $reason = ''): int
    {
        $baseReason = $reason !== '' ? $reason : 'ยกเลิกการบันทึกจ่าย payroll run #' . $runId;
        return ledgerService()->deleteAllByReference('payroll', $runId, null, $baseReason);
    }
}

function payrollLedgerService(): PayrollLedgerService
{
    static $instance = null;
    if ($instance === null) {
        $instance = new PayrollLedgerService();
    }
    return $instance;
}
