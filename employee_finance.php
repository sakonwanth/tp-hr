<?php

require_once __DIR__ . '/bootstrap.php';
Auth::requireLogin();
$pdo = getDB();
$currentUser = Auth::user();
$userId = (int)($currentUser['id'] ?? 0);
$canManage = hr_can_access_hr_dashboard();
$where = $canManage ? '' : 'WHERE f.user_id = ?';
$params = $canManage ? [] : [$userId];
$sql = "SELECT f.*,u.first_name_th,u.last_name_th FROM (
          SELECT id,user_id,expense_request_id,'salary_advance' finance_type,amount principal_amount,amount total_payable,
                 amount monthly_installment,NULL term_months,deduction_month first_due_month,repayment_method,
                 disbursement_method,status,payroll_run_id,created_at
          FROM hr_salary_advances
          UNION ALL
          SELECT id,user_id,expense_request_id,'employee_loan',principal_amount,total_payable,monthly_installment,
                 term_months,first_due_month,repayment_method,disbursement_method,status,NULL payroll_run_id,created_at
          FROM hr_employee_loans
        ) f JOIN users u ON u.id=f.user_id $where ORDER BY f.created_at DESC";
try {
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = [];
}
$selectedType = (string)($_GET['type'] ?? '');
$selectedId = (int)($_GET['id'] ?? 0);
$detail = null;
foreach ($rows as $row) {
    if ((int)$row['id'] === $selectedId && (string)$row['finance_type'] === $selectedType) {
        $detail = $row;
        break;
    }
}
$repayments = [];
$financeAudit = [];
$expense = null;
$lineDelivery = ['sent' => 0, 'pending' => 0, 'failed' => 0];
if ($detail) {
    try {
        if ($selectedType === 'employee_loan') {
            $repayStmt = $pdo->prepare(
                "SELECT r.*,pr.payroll_month,pr.status payroll_status
                 FROM hr_loan_repayments r
                 LEFT JOIN payroll_runs pr ON pr.id=r.payroll_run_id
                 WHERE r.loan_id=? ORDER BY r.installment_no,r.due_date,r.id"
            );
            $repayStmt->execute([$selectedId]);
            $repayments = $repayStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $expenseId = (int)($detail['expense_request_id'] ?? 0);
        if ($expenseId > 0) {
            $expenseStmt = $pdo->prepare(
                "SELECT id,request_code,status,approval_current_step,approval_required_steps,approved_at,paid_at,created_at
                 FROM line_expense_requests WHERE id=? LIMIT 1"
            );
            $expenseStmt->execute([$expenseId]);
            $expense = $expenseStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $lineStmt = $pdo->prepare(
                "SELECT status,COUNT(*) total FROM erp_expense_line_outbox
                 WHERE expense_request_id=? GROUP BY status"
            );
            $lineStmt->execute([$expenseId]);
            foreach ($lineStmt->fetchAll(PDO::FETCH_ASSOC) as $lineRow) {
                $lineStatus = (string)($lineRow['status'] ?? '');
                if (array_key_exists($lineStatus, $lineDelivery)) {
                    $lineDelivery[$lineStatus] = (int)$lineRow['total'];
                }
            }
        }
        $auditStmt = $pdo->prepare(
            "SELECT event_type,created_at FROM hr_employee_finance_audit_logs
             WHERE finance_type=? AND finance_id=? ORDER BY id DESC LIMIT 20"
        );
        $auditStmt->execute([$selectedType, $selectedId]);
        $financeAudit = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Older deployments may not have every lifecycle/audit table yet.
    }
}
$statusLabels = [
    'pending_disbursement' => 'รออนุมัติ/รอจ่ายเงิน', 'submitted' => 'รออนุมัติ',
    'approved' => 'อนุมัติแล้ว รอจ่ายเงิน', 'active' => 'กำลังผ่อนชำระ',
    'pending_deduction' => 'รอหักเงินเดือน', 'deducted' => 'หักเงินเดือนแล้ว',
    'paid' => 'ชำระแล้ว', 'closed' => 'ชำระครบแล้ว', 'cancelled' => 'ยกเลิก',
    'rejected' => 'ไม่อนุมัติ', 'defaulted' => 'ค้างชำระ', 'scheduled' => 'รอถึงกำหนด',
    'missed' => 'เลยกำหนด', 'partial' => 'ชำระบางส่วน', 'waived' => 'ยกเว้น',
];
$statusLabel = static fn(string $status): string => $statusLabels[$status] ?? $status;
$crmBase = rtrim((string)($_ENV['CRM_BASE_URL'] ?? getenv('CRM_BASE_URL') ?: 'https://crm.tp-asset.com'), '/');
$page_title = 'สวัสดิการการเงินพนักงาน';
$current_page = 'employee-finance';
require_once __DIR__ . '/templates/header.php';
?>
<main class="content-area p-4 md:p-6">
  <div class="max-w-6xl mx-auto space-y-6">
    <section class="rounded-2xl border border-white/10 bg-white/5 backdrop-blur p-5">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div><h1 class="text-xl font-bold text-white">สวัสดิการการเงินพนักงาน</h1><p class="text-white/60 mt-1">เบิกเงินเดือนล่วงหน้า เงินกู้ และติดตามตารางชำระ</p></div>
        <a class="min-h-[48px] inline-flex items-center justify-center gap-2 rounded-xl bg-violet-600 hover:bg-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-300 px-5 text-white whitespace-nowrap" href="<?php echo htmlspecialchars($crmBase . '/employee_finance_request.php'); ?>">
          <i class="fas fa-plus" aria-hidden="true"></i>ยื่นคำขอใหม่
        </a>
      </div>
    </section>
    <?php if ($detail): ?>
    <section id="finance-detail" class="rounded-2xl border border-emerald-400/25 bg-white/5 backdrop-blur overflow-hidden">
      <div class="p-5 border-b border-white/10 flex flex-wrap items-center justify-between gap-3">
        <div>
          <p class="text-emerald-300 text-sm font-semibold">รายละเอียดคำขอ</p>
          <h2 class="text-lg font-bold text-white mt-1"><?php echo $detail['finance_type'] === 'employee_loan' ? 'เงินกู้พนักงาน' : 'เบิกเงินเดือนล่วงหน้า'; ?> · <?php echo number_format((float)$detail['principal_amount'], 2); ?> บาท</h2>
        </div>
        <a href="/employee_finance.php" class="min-h-[44px] inline-flex items-center rounded-xl border border-white/15 px-4 text-white/80 hover:bg-white/10">ปิดรายละเอียด</a>
      </div>
      <div class="p-5 grid gap-4 md:grid-cols-2 lg:grid-cols-4 text-sm">
        <div class="rounded-xl bg-black/15 p-4"><p class="text-white/50">สถานะคำขอ</p><p class="text-white font-semibold mt-1"><?php echo htmlspecialchars($statusLabel((string)($expense['status'] ?? $detail['status']))); ?></p></div>
        <div class="rounded-xl bg-black/15 p-4"><p class="text-white/50">สถานะเงินกู้</p><p class="text-white font-semibold mt-1"><?php echo htmlspecialchars($statusLabel((string)$detail['status'])); ?></p></div>
        <div class="rounded-xl bg-black/15 p-4"><p class="text-white/50">ค่างวด</p><p class="text-white font-semibold mt-1"><?php echo number_format((float)$detail['monthly_installment'], 2); ?> บาท × <?php echo (int)($detail['term_months'] ?: 1); ?> งวด</p></div>
        <div class="rounded-xl bg-black/15 p-4"><p class="text-white/50">เลขที่คำขอ</p><p class="text-white font-semibold mt-1"><?php echo htmlspecialchars((string)($expense['request_code'] ?? '-')); ?></p></div>
      </div>
      <div class="px-5 pb-5 grid gap-4 md:grid-cols-2">
        <div class="rounded-xl border border-white/10 p-4">
          <h3 class="text-white font-semibold">การแจ้งเตือน LINE</h3>
          <p class="text-white/65 text-sm mt-2"><?php if ($lineDelivery['sent'] > 0): ?>ส่งแล้ว <?php echo $lineDelivery['sent']; ?> รายการ<?php elseif ($lineDelivery['pending'] > 0): ?>อยู่ในคิวส่ง <?php echo $lineDelivery['pending']; ?> รายการ<?php elseif ($lineDelivery['failed'] > 0): ?>ส่งไม่สำเร็จ <?php echo $lineDelivery['failed']; ?> รายการ — ระบบจะลองใหม่<?php else: ?>กำลังสร้างสายอนุมัติและการแจ้งเตือน<?php endif; ?></p>
        </div>
        <div class="rounded-xl border border-white/10 p-4">
          <h3 class="text-white font-semibold">การเชื่อมโยงเงินเดือน</h3>
          <p class="text-white/65 text-sm mt-2"><?php if ($detail['repayment_method'] !== 'payroll'): ?>เลือกโอนชำระคืน จึงไม่หักผ่านสลิป<?php elseif ($detail['status'] === 'pending_disbursement'): ?>เชื่อมกับ payroll แล้ว แต่จะเริ่มนำค่างวดเข้าสลิปหลังบริษัทบันทึกจ่ายเงิน<?php else: ?>ระบบจะนำค่างวดเดือน <?php echo htmlspecialchars((string)$detail['first_due_month']); ?> เข้ารายการหักอื่นในสลิปเงินเดือนโดยอัตโนมัติ<?php endif; ?></p>
        </div>
      </div>
      <?php if ($detail['finance_type'] === 'employee_loan'): ?>
      <div class="border-t border-white/10 p-5">
        <h3 class="text-white font-semibold mb-3">ตารางผ่อนชำระและการลงสลิป</h3>
        <div class="overflow-x-auto"><table class="w-full min-w-[720px] text-sm">
          <thead class="text-white/55"><tr><th class="text-left p-3">งวด</th><th class="text-left p-3">ครบกำหนด</th><th class="text-right p-3">เงินต้น</th><th class="text-right p-3">ดอกเบี้ย</th><th class="text-right p-3">ยอดชำระ</th><th class="text-left p-3">สถานะ</th><th class="text-left p-3">รอบเงินเดือน</th></tr></thead>
          <tbody class="divide-y divide-white/10 text-white/80">
          <?php foreach ($repayments as $repayment): ?><tr>
            <td class="p-3"><?php echo (int)$repayment['installment_no']; ?></td><td class="p-3"><?php echo htmlspecialchars((string)$repayment['due_date']); ?></td>
            <td class="p-3 text-right"><?php echo number_format((float)$repayment['principal_portion'], 2); ?></td><td class="p-3 text-right"><?php echo number_format((float)$repayment['interest_portion'], 2); ?></td><td class="p-3 text-right font-semibold"><?php echo number_format((float)$repayment['due_amount'], 2); ?></td>
            <td class="p-3"><?php echo htmlspecialchars($statusLabel((string)$repayment['status'])); ?></td><td class="p-3"><?php echo htmlspecialchars((string)($repayment['payroll_month'] ?: 'ยังไม่ลงสลิป')); ?></td>
          </tr><?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>
    <section class="rounded-2xl border border-white/10 bg-white/5 backdrop-blur overflow-hidden">
      <div class="p-5 border-b border-white/10"><h2 class="font-semibold text-white"><?php echo $canManage ? 'รายการของพนักงานทั้งหมด' : 'รายการของฉัน'; ?></h2></div>
      <?php if (!$rows): ?>
        <div class="p-8 text-center text-white/60">ยังไม่มีรายการเงินกู้หรือเบิกล่วงหน้า</div>
      <?php else: ?>
        <div class="overflow-x-auto"><table class="w-full min-w-[760px] text-sm">
          <thead class="text-white/60"><tr><th class="text-left p-4">พนักงาน</th><th class="text-left p-4">ประเภท</th><th class="text-right p-4">เงินต้น</th><th class="text-right p-4">ชำระรวม</th><th class="text-center p-4">งวด</th><th class="text-left p-4">เริ่มชำระ</th><th class="text-left p-4">วิธีชำระ</th><th class="text-left p-4">สถานะ</th></tr></thead>
          <tbody class="divide-y divide-white/10 text-white/85">
          <?php foreach ($rows as $row): ?><tr class="hover:bg-white/[.03]">
            <td class="p-4"><?php echo htmlspecialchars(trim(($row['first_name_th'] ?? '') . ' ' . ($row['last_name_th'] ?? ''))); ?></td>
            <td class="p-4"><?php echo $row['finance_type'] === 'employee_loan' ? 'เงินกู้พนักงาน' : 'เบิกเงินเดือนล่วงหน้า'; ?></td>
            <td class="p-4 text-right tabular-nums"><?php echo number_format((float)$row['principal_amount'], 2); ?></td>
            <td class="p-4 text-right tabular-nums"><?php echo number_format((float)$row['total_payable'], 2); ?></td>
            <td class="p-4 text-center"><?php echo $row['term_months'] ?: '1'; ?></td>
            <td class="p-4"><?php echo htmlspecialchars((string)$row['first_due_month']); ?></td>
            <td class="p-4"><?php echo $row['repayment_method'] === 'payroll' ? 'หักผ่านสลิป' : 'โอนคืน'; ?></td>
            <td class="p-4"><span><?php echo htmlspecialchars($statusLabel((string)$row['status'])); ?></span><a class="block text-violet-300 hover:text-violet-200 mt-2 font-medium" href="?type=<?php echo urlencode((string)$row['finance_type']); ?>&amp;id=<?php echo (int)$row['id']; ?>#finance-detail">ดูรายละเอียด</a></td>
          </tr><?php endforeach; ?>
          </tbody></table></div>
      <?php endif; ?>
    </section>
  </div>
</main>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
