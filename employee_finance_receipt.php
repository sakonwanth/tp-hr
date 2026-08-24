<?php
require_once __DIR__ . '/bootstrap.php';
Auth::requireLogin();
$pdo = getDB();
$currentUser = Auth::user();
$canManage = hr_can_access_hr_dashboard();
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT p.*,u.first_name_th,u.last_name_th,r.request_code,
            receiver.first_name_th receiver_first_name,receiver.last_name_th receiver_last_name
       FROM hr_employee_finance_repayments_received p
       JOIN users u ON u.id=p.user_id
       JOIN users receiver ON receiver.id=p.received_by
       LEFT JOIN hr_salary_advances a ON p.finance_type='salary_advance' AND a.id=p.finance_id
       LEFT JOIN hr_employee_loans l ON p.finance_type='employee_loan' AND l.id=p.finance_id
       LEFT JOIN line_expense_requests r ON r.id=COALESCE(a.expense_request_id,l.expense_request_id)
      WHERE p.id=? LIMIT 1"
);
$stmt->execute([$id]);
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$receipt || (!$canManage && (int)$receipt['user_id'] !== (int)($currentUser['id'] ?? 0))) {
    http_response_code(404);
    exit('ไม่พบใบรับเงิน');
}
$page_title = 'ใบรับเงิน ' . $receipt['receipt_number'];
require_once __DIR__ . '/templates/header.php';
?>
<div class="w-full max-w-3xl mx-auto p-4 sm:p-6">
  <section class="bg-white text-slate-900 rounded-xl p-6 sm:p-10 shadow-xl print:shadow-none print:rounded-none">
    <div class="flex flex-wrap justify-between gap-5 border-b border-slate-300 pb-5"><div><p class="text-sm text-slate-500">TP-ASSET</p><h1 class="text-2xl font-bold mt-1">ใบรับเงินคืนจากพนักงาน</h1></div><div class="text-right"><p class="font-bold"><?php echo htmlspecialchars((string)$receipt['receipt_number']); ?></p><p class="text-sm text-slate-500 mt-1"><?php echo htmlspecialchars((string)$receipt['received_at']); ?></p></div></div>
    <dl class="grid sm:grid-cols-2 gap-x-8 gap-y-5 mt-7"><div><dt class="text-sm text-slate-500">รับจาก</dt><dd class="font-semibold mt-1"><?php echo htmlspecialchars(trim((string)$receipt['first_name_th'] . ' ' . (string)$receipt['last_name_th'])); ?></dd></div><div><dt class="text-sm text-slate-500">อ้างอิงคำขอ</dt><dd class="font-semibold mt-1"><?php echo htmlspecialchars((string)($receipt['request_code'] ?: '-')); ?></dd></div><div><dt class="text-sm text-slate-500">วิธีรับชำระ</dt><dd class="font-semibold mt-1"><?php echo $receipt['payment_method'] === 'cash' ? 'เงินสด' : 'โอนคืนบริษัท'; ?></dd></div><div><dt class="text-sm text-slate-500">เลขอ้างอิง</dt><dd class="font-semibold mt-1"><?php echo htmlspecialchars((string)($receipt['reference_number'] ?: '-')); ?></dd></div></dl>
    <div class="rounded-lg bg-slate-100 p-6 mt-7 flex justify-between items-baseline gap-5"><span class="font-semibold">จำนวนเงินรับคืน</span><strong class="text-2xl tabular-nums"><?php echo number_format((float)$receipt['amount'], 2); ?> บาท</strong></div>
    <?php if (!empty($receipt['notes'])): ?><div class="mt-6"><p class="text-sm text-slate-500">หมายเหตุ</p><p class="mt-1"><?php echo nl2br(htmlspecialchars((string)$receipt['notes'])); ?></p></div><?php endif; ?>
    <div class="grid grid-cols-2 gap-10 mt-16 text-center"><div><div class="border-t border-slate-400 pt-3">ผู้ส่งมอบเงิน</div></div><div><div class="border-t border-slate-400 pt-3">ผู้รับเงิน<br><span class="text-sm text-slate-500"><?php echo htmlspecialchars(trim((string)$receipt['receiver_first_name'] . ' ' . (string)$receipt['receiver_last_name'])); ?></span></div></div></div>
    <div class="mt-8 print:hidden flex justify-end"><button type="button" onclick="window.print()" class="min-h-[48px] rounded-lg bg-slate-900 px-5 text-white">พิมพ์ใบรับเงิน</button></div>
  </section>
</div>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
