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
          SELECT id,user_id,'salary_advance' finance_type,amount principal_amount,amount total_payable,
                 NULL term_months,deduction_month first_due_month,repayment_method,disbursement_method,status,created_at
          FROM hr_salary_advances
          UNION ALL
          SELECT id,user_id,'employee_loan',principal_amount,total_payable,term_months,first_due_month,
                 repayment_method,disbursement_method,status,created_at
          FROM hr_employee_loans
        ) f JOIN users u ON u.id=f.user_id $where ORDER BY f.created_at DESC";
try {
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = [];
}
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
    <section class="rounded-2xl border border-white/10 bg-white/5 backdrop-blur overflow-hidden">
      <div class="p-5 border-b border-white/10"><h2 class="font-semibold text-white"><?php echo $canManage ? 'รายการของพนักงานทั้งหมด' : 'รายการของฉัน'; ?></h2></div>
      <?php if (!$rows): ?>
        <div class="p-8 text-center text-white/60">ยังไม่มีรายการเงินกู้หรือเบิกล่วงหน้า</div>
      <?php else: ?>
        <div class="overflow-x-auto"><table class="w-full min-w-[760px] text-sm">
          <thead class="text-white/60"><tr><th class="text-left p-4">พนักงาน</th><th class="text-left p-4">ประเภท</th><th class="text-right p-4">เงินต้น</th><th class="text-right p-4">ชำระรวม</th><th class="text-center p-4">งวด</th><th class="text-left p-4">เริ่มชำระ</th><th class="text-left p-4">วิธีชำระ</th><th class="text-left p-4">สถานะ</th></tr></thead>
          <tbody class="divide-y divide-white/10 text-white/85">
          <?php foreach ($rows as $row): ?><tr>
            <td class="p-4"><?php echo htmlspecialchars(trim(($row['first_name_th'] ?? '') . ' ' . ($row['last_name_th'] ?? ''))); ?></td>
            <td class="p-4"><?php echo $row['finance_type'] === 'employee_loan' ? 'เงินกู้พนักงาน' : 'เบิกเงินเดือนล่วงหน้า'; ?></td>
            <td class="p-4 text-right tabular-nums"><?php echo number_format((float)$row['principal_amount'], 2); ?></td>
            <td class="p-4 text-right tabular-nums"><?php echo number_format((float)$row['total_payable'], 2); ?></td>
            <td class="p-4 text-center"><?php echo $row['term_months'] ?: '1'; ?></td>
            <td class="p-4"><?php echo htmlspecialchars((string)$row['first_due_month']); ?></td>
            <td class="p-4"><?php echo $row['repayment_method'] === 'payroll' ? 'หักผ่านสลิป' : 'โอนคืน'; ?></td>
            <td class="p-4"><?php echo htmlspecialchars((string)$row['status']); ?></td>
          </tr><?php endforeach; ?>
          </tbody></table></div>
      <?php endif; ?>
    </section>
  </div>
</main>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
