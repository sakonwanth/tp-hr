<?php

require_once __DIR__ . '/bootstrap.php';
Auth::requireLogin();
$pdo = getDB();
$currentUser = Auth::user();
$userId = (int)($currentUser['id'] ?? 0);
$canManage = hr_can_access_hr_dashboard();
$canEditFinance = isCEOOrAbove();
$financeFlash = $_SESSION['employee_finance_flash'] ?? null;
unset($_SESSION['employee_finance_flash']);
$selectedType = (string)($_GET['type'] ?? $_POST['type'] ?? '');
$selectedId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (empty($_SESSION['employee_finance_repayment_key'])) {
    $_SESSION['employee_finance_repayment_key'] = bin2hex(random_bytes(24));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'receive_repayment') {
    $redirect = '/employee_finance.php?type=' . urlencode($selectedType) . '&id=' . $selectedId . '#repayment';
    try {
        if (!$canManage) {
            throw new RuntimeException('ไม่มีสิทธิ์บันทึกรับคืนเงิน');
        }
        if (!verifyCsrf()) {
            throw new RuntimeException('เซสชันหมดอายุ กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง');
        }
        require_once __DIR__ . '/core/Services/EmployeeFinanceRepaymentService.php';
        $service = new EmployeeFinanceRepaymentService($pdo);
        $result = $service->receive(
            $selectedType,
            $selectedId,
            (float)($_POST['amount'] ?? 0),
            (string)($_POST['payment_method'] ?? ''),
            (string)($_POST['received_at'] ?? ''),
            $userId,
            (string)($_POST['reference_number'] ?? ''),
            (string)($_POST['notes'] ?? ''),
            (string)($_POST['idempotency_key'] ?? '')
        );
        unset($_SESSION['employee_finance_repayment_key']);
        $_SESSION['employee_finance_flash'] = [
            'type' => 'success',
            'message' => 'บันทึกรับชำระแล้ว เลขที่ ' . (string)$result['receipt_number'],
        ];
    } catch (Throwable $e) {
        $_SESSION['employee_finance_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    }
    header('Location: ' . $redirect);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'upload_repayment_evidence') {
    $redirect = '/employee_finance.php?type=' . urlencode($selectedType) . '&id=' . $selectedId . '#repayment';
    try {
        if (!$canManage) throw new RuntimeException('ไม่มีสิทธิ์แนบหลักฐานรับเงิน');
        if (!verifyCsrf()) throw new RuntimeException('เซสชันหมดอายุ กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง');
        $receiptId = (int)($_POST['receipt_id'] ?? 0);
        $receiptStmt = $pdo->prepare(
            "SELECT id,user_id FROM hr_employee_finance_repayments_received
              WHERE id=? AND finance_type=? AND finance_id=? AND status='posted' LIMIT 1"
        );
        $receiptStmt->execute([$receiptId, $selectedType, $selectedId]);
        $receiptRow = $receiptStmt->fetch(PDO::FETCH_ASSOC);
        if (!$receiptRow) throw new RuntimeException('ไม่พบรายการรับชำระ');
        $file = $_FILES['evidence'] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('กรุณาเลือกไฟล์หลักฐานที่ลงนามแล้ว');
        }
        if ((int)$file['size'] <= 0 || (int)$file['size'] > 10 * 1024 * 1024) {
            throw new RuntimeException('ไฟล์หลักฐานต้องมีขนาดไม่เกิน 10 MB');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        $extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        if (!isset($extensions[$mime])) throw new RuntimeException('รองรับเฉพาะ PDF, JPG และ PNG');
        $relativeDir = 'storage/uploads/employee-finance/' . date('Y/m');
        $absoluteDir = __DIR__ . '/' . $relativeDir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0750, true) && !is_dir($absoluteDir)) {
            throw new RuntimeException('ไม่สามารถเตรียมพื้นที่เก็บหลักฐานได้');
        }
        $relativePath = $relativeDir . '/receipt-' . $receiptId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
        if (!move_uploaded_file((string)$file['tmp_name'], __DIR__ . '/' . $relativePath)) {
            throw new RuntimeException('บันทึกไฟล์หลักฐานไม่สำเร็จ');
        }
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE hr_employee_finance_repayments_received SET evidence_path=? WHERE id=?')
            ->execute([$relativePath, $receiptId]);
        $pdo->prepare(
            "INSERT INTO hr_employee_finance_audit_logs
             (user_id,finance_type,finance_id,event_type,actor_user_id,payload_json,created_at)
             VALUES (?,?,?,'repayment_evidence_uploaded',?,?,NOW())"
        )->execute([(int)$receiptRow['user_id'], $selectedType, $selectedId, $userId, json_encode(['receipt_id' => $receiptId, 'path' => $relativePath], JSON_UNESCAPED_UNICODE)]);
        $pdo->commit();
        $_SESSION['employee_finance_flash'] = ['type' => 'success', 'message' => 'แนบหลักฐานรับเงินที่ลงนามแล้วเรียบร้อย'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['employee_finance_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    }
    header('Location: ' . $redirect);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'change_first_due_month') {
    $redirect = '/employee_finance.php?type=' . urlencode($selectedType) . '&id=' . $selectedId . '#finance-detail';
    try {
        if (!$canEditFinance) {
            throw new RuntimeException('เฉพาะ CEO, Chairman หรือ Admin เท่านั้นที่แก้เดือนเริ่มหักได้');
        }
        if (!verifyCsrf()) {
            throw new RuntimeException('เซสชันหมดอายุ กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง');
        }
        require_once __DIR__ . '/core/Services/EmployeeFinanceManagementService.php';
        $service = new EmployeeFinanceManagementService($pdo);
        $service->changeFirstDueMonth(
            $selectedType,
            $selectedId,
            (string)($_POST['first_due_month'] ?? ''),
            $userId,
            (string)($_POST['reason'] ?? '')
        );
        $_SESSION['employee_finance_flash'] = ['type' => 'success', 'message' => 'เปลี่ยนเดือนเริ่มหักเรียบร้อยแล้ว'];
    } catch (Throwable $e) {
        $_SESSION['employee_finance_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    }
    header('Location: ' . $redirect);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'change_repayment_method') {
    $redirect = '/employee_finance.php?type=' . urlencode($selectedType) . '&id=' . $selectedId . '#finance-detail';
    try {
        if (!$canEditFinance) throw new RuntimeException('เฉพาะ CEO, Chairman หรือ Admin เท่านั้นที่แก้วิธีคืนเงินได้');
        if (!verifyCsrf()) throw new RuntimeException('เซสชันหมดอายุ กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง');
        require_once __DIR__ . '/core/Services/EmployeeFinanceManagementService.php';
        (new EmployeeFinanceManagementService($pdo))->changeRepaymentMethod(
            $selectedType, $selectedId, (string)($_POST['repayment_method'] ?? ''),
            $userId, (string)($_POST['reason'] ?? '')
        );
        $_SESSION['employee_finance_flash'] = ['type'=>'success','message'=>'เปลี่ยนวิธีคืนเงินเรียบร้อยแล้ว'];
    } catch (Throwable $e) {
        $_SESSION['employee_finance_flash'] = ['type'=>'error','message'=>$e->getMessage()];
    }
    header('Location: ' . $redirect);
    exit;
}
$where = $canManage ? '' : 'WHERE f.user_id = ?';
$params = $canManage ? [] : [$userId];
$sql = "SELECT f.*,u.first_name_th,u.last_name_th FROM (
          SELECT id,user_id,expense_request_id,'salary_advance' finance_type,amount principal_amount,amount total_payable,
                 amount monthly_installment,NULL interest_rate_pct,NULL term_months,deduction_month first_due_month,repayment_method,
                 disbursement_method,status,payroll_run_id,created_at
          FROM hr_salary_advances
          UNION ALL
          SELECT id,user_id,expense_request_id,'employee_loan',principal_amount,total_payable,monthly_installment,
                 interest_rate_pct,term_months,first_due_month,repayment_method,disbursement_method,status,NULL payroll_run_id,created_at
          FROM hr_employee_loans
        ) f JOIN users u ON u.id=f.user_id $where ORDER BY f.created_at DESC";
try {
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = [];
}
$detail = null;
foreach ($rows as $row) {
    if ((int)$row['id'] === $selectedId && (string)$row['finance_type'] === $selectedType) {
        $detail = $row;
        break;
    }
}
$repayments = [];
$receivedRepayments = [];
$financeAudit = [];
$expense = null;
$lineDelivery = ['sent' => 0, 'pending' => 0, 'failed' => 0, 'cancelled' => 0];
$lineTimeline = [];
$paymentSummary = ['paid_installments' => 0, 'total_installments' => 0, 'paid_amount' => 0.0, 'remaining_amount' => 0.0];
if ($detail) {
    try {
        if ($selectedType === 'employee_loan') {
            $repayStmt = $pdo->prepare(
                "SELECT r.*,pr.payroll_month,pr.status payroll_status,x.payroll_slip_id,x.link_status payroll_link_status
                 FROM hr_loan_repayments r
                 LEFT JOIN payroll_runs pr ON pr.id=r.payroll_run_id
                 LEFT JOIN hr_employee_finance_payroll_links x
                   ON x.source_type='employee_loan_repayment' AND x.source_id=r.id
                 WHERE r.loan_id=? ORDER BY r.installment_no,r.due_date,r.id"
            );
            $repayStmt->execute([$selectedId]);
            $repayments = $repayStmt->fetchAll(PDO::FETCH_ASSOC);
            $paymentSummary['total_installments'] = count($repayments);
        }
        $receivedStmt = $pdo->prepare(
            "SELECT p.*,u.first_name_th receiver_first_name,u.last_name_th receiver_last_name,o.status accounting_status,
                    o.erp_transaction_id,o.last_error accounting_error
               FROM hr_employee_finance_repayments_received p
               LEFT JOIN users u ON u.id=p.received_by
               LEFT JOIN hr_employee_finance_accounting_outbox o ON o.repayment_received_id=p.id
              WHERE p.finance_type=? AND p.finance_id=? ORDER BY p.received_at DESC,p.id DESC"
        );
        $receivedStmt->execute([$selectedType, $selectedId]);
        $receivedRepayments = $receivedStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($selectedType === 'employee_loan') {
            foreach ($repayments as $repayment) {
                $installmentPaid = (float)($repayment['paid_amount'] ?? 0);
                $paymentSummary['paid_amount'] += $installmentPaid;
                if ((string)$repayment['status'] === 'paid') $paymentSummary['paid_installments']++;
            }
        } elseif ((string)$detail['status'] === 'deducted') {
            $paymentSummary['paid_amount'] = (float)$detail['total_payable'];
        } else {
            foreach ($receivedRepayments as $received) {
                if ((string)$received['status'] === 'posted') {
                    $paymentSummary['paid_amount'] += (float)$received['amount'];
                }
            }
        }
        $paymentSummary['remaining_amount'] = max(0, (float)$detail['total_payable'] - $paymentSummary['paid_amount']);
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
            $lineTimelineStmt = $pdo->prepare(
                "SELECT id,message_type,recipient_type,recipient_line_id,status,attempt_count,last_error,scheduled_at,sent_at,created_at
                 FROM erp_expense_line_outbox WHERE expense_request_id=? ORDER BY id DESC LIMIT 20"
            );
            $lineTimelineStmt->execute([$expenseId]);
            $lineTimeline = $lineTimelineStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $auditStmt = $pdo->prepare(
            "SELECT event_type,payload_json,created_at FROM hr_employee_finance_audit_logs
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
    'repaid' => 'รับคืนครบแล้ว',
    'paid' => 'ชำระแล้ว', 'closed' => 'ชำระครบแล้ว', 'cancelled' => 'ยกเลิก',
    'rejected' => 'ไม่อนุมัติ', 'defaulted' => 'ค้างชำระ', 'scheduled' => 'รอถึงกำหนด',
    'missed' => 'เลยกำหนด', 'partial' => 'ชำระบางส่วน', 'waived' => 'ยกเว้น',
    'confirmed' => 'ผู้ขอยืนยันรับเงินแล้ว', 'completed' => 'ดำเนินการครบถ้วน',
];
$statusLabel = static fn(string $status): string => $statusLabels[$status] ?? $status;
$thaiMonths = [1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม'];
$formatThaiMonth = static function (?string $value) use ($thaiMonths): string {
    if (!$value || !preg_match('/^(\d{4})-(\d{2})/', $value, $m)) return $value ?: '-';
    return ($thaiMonths[(int)$m[2]] ?? $m[2]) . ' ' . ((int)$m[1] + 543);
};
$formatThaiDate = static function (?string $value) use ($thaiMonths): string {
    if (!$value || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) return $value ?: '-';
    return (int)$m[3] . ' ' . ($thaiMonths[(int)$m[2]] ?? $m[2]) . ' ' . ((int)$m[1] + 543);
};
$lineStatusLabels = ['sent'=>'ส่งสำเร็จ','pending'=>'รอส่ง','failed'=>'ส่งไม่สำเร็จ','cancelled'=>'ยกเลิก'];
$lineRecipientLabels = ['user'=>'ผู้ใช้งาน','group'=>'กลุ่ม LINE'];
$lineMessageLabels = [
    'approval_request'=>'แจ้งผู้บริหารให้อนุมัติ',
    'requester_update'=>'แจ้งผลให้ผู้ขอ',
    'group_announce'=>'แจ้งความคืบหน้าในกลุ่ม',
    'payment_update'=>'แจ้งสถานะการจ่ายเงิน',
    'receipt_update'=>'แจ้งสถานะหลักฐาน/ใบเสร็จ',
    'finance_schedule_changed'=>'แจ้งเปลี่ยนเดือนเริ่มหัก',
];
$disbursementLabels = [
    'transfer' => 'โอนเข้าบัญชีพนักงาน',
    'payroll' => 'จ่ายพร้อมรอบเงินเดือน',
    'cash' => 'จ่ายเงินสดให้พนักงาน',
];
$repaymentLabels = [
    'payroll' => 'หักคืนผ่านสลิปเงินเดือน',
    'transfer' => 'พนักงานโอนคืนบริษัท',
    'cash' => 'พนักงานคืนเป็นเงินสด',
];
$maskLineRecipient = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') return '-';
    return mb_substr($value, 0, 4) . '••••' . mb_substr($value, -4);
};
$linkedPayrollInstallments = 0;
foreach ($repayments as $repayment) {
    if (in_array((string)($repayment['payroll_link_status'] ?? ''), ['included', 'settled'], true)) {
        $linkedPayrollInstallments++;
    }
}
$crmBase = rtrim((string)($_ENV['CRM_BASE_URL'] ?? getenv('CRM_BASE_URL') ?: 'https://crm.tp-asset.com'), '/');
$erpBase = rtrim((string)($_ENV['ERP_BASE_URL'] ?? getenv('ERP_BASE_URL') ?: 'https://erp.tp-asset.com'), '/');
$page_title = 'สวัสดิการการเงินพนักงาน';
$current_page = 'employee-finance';
require_once __DIR__ . '/templates/header.php';
?>
<?php /* templates/header.php has already opened <main class="content-area">.
         Opening a second one here nested two .content-area elements, so the
         desktop rule `margin-left: 280px; width: calc(100% - 280px)` applied
         twice — 560px of left margin — and the page sat pushed to the right of
         every other screen. It was also a second <main> landmark on the page. */ ?>
  <div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(1200px,100%)] mx-auto min-w-0 space-y-6">
    <?php if (is_array($financeFlash)): ?>
    <div role="alert" class="rounded-xl border px-4 py-3 <?php echo ($financeFlash['type'] ?? '') === 'success' ? 'border-emerald-400/30 bg-emerald-500/10 text-emerald-200' : 'border-rose-400/30 bg-rose-500/10 text-rose-200'; ?>">
      <?php echo htmlspecialchars((string)($financeFlash['message'] ?? '')); ?>
    </div>
    <?php endif; ?>
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
        <a href="/employee_finance.php" class="min-h-[48px] inline-flex items-center rounded-xl border border-white/15 px-4 text-white/80 hover:bg-white/10">ปิดรายละเอียด</a>
      </div>
      <div class="p-5 grid gap-4 md:grid-cols-2 lg:grid-cols-4 text-sm">
        <div class="rounded-xl bg-black/15 p-4"><p class="text-white/50">สถานะคำขอ</p><p class="text-white font-semibold mt-1"><?php echo htmlspecialchars($statusLabel((string)($expense['status'] ?? $detail['status']))); ?></p></div>
        <div class="rounded-xl bg-black/15 p-4"><p class="text-white/50">สถานะเงินกู้</p><p class="text-white font-semibold mt-1"><?php echo htmlspecialchars($statusLabel((string)$detail['status'])); ?></p></div>
        <div class="rounded-xl bg-black/15 p-4"><p class="text-white/50">ค่างวด</p><p class="text-white font-semibold mt-1"><?php echo number_format((float)$detail['monthly_installment'], 2); ?> บาท × <?php echo (int)($detail['term_months'] ?: 1); ?> งวด</p></div>
        <div class="rounded-xl bg-black/15 p-4"><p class="text-white/50">เลขที่คำขอ</p><p class="text-white font-semibold mt-1"><?php echo htmlspecialchars((string)($expense['request_code'] ?? '-')); ?></p></div>
      </div>
      <div class="px-5 pb-5 grid gap-4 md:grid-cols-2 lg:grid-cols-4 text-sm">
        <div class="rounded-xl border border-white/10 p-4"><p class="text-white/50">อัตราดอกเบี้ย</p><p class="text-white font-semibold mt-1"><?php echo $detail['finance_type'] === 'employee_loan' ? number_format((float)$detail['interest_rate_pct'], 2) . '% ต่อปี (ลดต้นลดดอก)' : 'ไม่มีดอกเบี้ย'; ?></p></div>
        <div class="rounded-xl border border-white/10 p-4"><p class="text-white/50">บริษัทจ่าย / พนักงานคืน</p><p class="text-white font-semibold mt-1"><?php echo htmlspecialchars($disbursementLabels[(string)$detail['disbursement_method']] ?? (string)$detail['disbursement_method']); ?> · <?php echo htmlspecialchars($repaymentLabels[(string)$detail['repayment_method']] ?? (string)$detail['repayment_method']); ?></p></div>
        <div class="rounded-xl border border-white/10 p-4"><p class="text-white/50">เริ่มหักงวดแรก</p><p class="text-white font-semibold mt-1"><?php echo htmlspecialchars($formatThaiMonth((string)$detail['first_due_month'])); ?></p></div>
        <div class="rounded-xl border border-white/10 p-4"><p class="text-white/50">รายการจ่ายเงิน</p><p class="mt-1"><?php if (!empty($expense['id'])): ?><a class="text-violet-300 hover:text-violet-200 font-semibold" target="_blank" rel="noopener" href="<?php echo htmlspecialchars($erpBase . '/expenses/requests/' . (int)$expense['id']); ?>"><?php echo htmlspecialchars((string)$expense['request_code']); ?> <i class="fas fa-external-link-alt text-xs"></i></a><?php else: ?><span class="text-white/60">ยังไม่เชื่อมรายการ ERP</span><?php endif; ?></p></div>
      </div>
      <?php if ($canEditFinance && $detail['status'] === 'pending_disbursement' && in_array((string)($expense['status'] ?? ''), ['submitted', 'approved'], true)): ?>
      <div class="px-5 pb-5">
        <form method="post" class="rounded-xl border border-amber-300/25 bg-amber-400/5 p-4 grid gap-4 md:grid-cols-[1fr_1.5fr_auto] md:items-end">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="change_first_due_month">
          <input type="hidden" name="type" value="<?php echo htmlspecialchars($selectedType); ?>">
          <input type="hidden" name="id" value="<?php echo $selectedId; ?>">
          <label class="block text-sm text-white/80">เดือนเริ่มหักใหม่
            <select name="first_due_month" required class="mt-2 w-full min-h-[48px] rounded-xl border border-white/15 bg-slate-950 px-3 text-white">
              <option value="">กรุณาเลือกเดือน</option>
              <option value="<?php echo date('Y-m'); ?>"><?php echo htmlspecialchars($formatThaiMonth(date('Y-m'))); ?> (รอบปัจจุบัน)</option>
              <option value="<?php echo date('Y-m', strtotime('+1 month')); ?>"><?php echo htmlspecialchars($formatThaiMonth(date('Y-m', strtotime('+1 month')))); ?> (รอบถัดไป)</option>
            </select>
          </label>
          <label class="block text-sm text-white/80">เหตุผลการแก้ไข
            <input type="text" name="reason" required maxlength="500" placeholder="เช่น ผู้ขอเลือกเดือนผิด ต้องหักในรอบปัจจุบัน" class="mt-2 w-full min-h-[48px] rounded-xl border border-white/15 bg-slate-950 px-3 text-white">
          </label>
          <button type="submit" class="min-h-[48px] rounded-xl bg-amber-500 hover:bg-amber-400 px-5 font-semibold text-slate-950" onclick="return confirm('ยืนยันเปลี่ยนเดือนเริ่มหัก? ระบบจะตรวจรอบเงินเดือนและสร้างตารางงวดใหม่โดยอัตโนมัติ')">บันทึกการแก้ไข</button>
        </form>
        <p class="text-xs text-white/50 mt-2">แก้ได้เฉพาะรายการที่ยังไม่จ่ายและยังไม่เชื่อมสลิป ระบบบันทึกผู้แก้ เหตุผล และข้อมูลก่อน–หลังทุกครั้ง</p>
      </div>
      <?php endif; ?>
      <?php if ($canEditFinance && !in_array((string)$detail['status'], ['closed','deducted','cancelled','rejected'], true) && !$receivedRepayments): ?>
      <div class="px-5 pb-5">
        <form method="post" class="rounded-xl border border-sky-300/25 bg-sky-400/5 p-4 grid gap-4 md:grid-cols-[1fr_1.5fr_auto] md:items-end">
          <?php echo csrfField(); ?><input type="hidden" name="action" value="change_repayment_method"><input type="hidden" name="type" value="<?php echo htmlspecialchars($selectedType); ?>"><input type="hidden" name="id" value="<?php echo $selectedId; ?>">
          <label class="block text-sm text-white/80">แผนคืนเงิน
            <select name="repayment_method" required class="mt-2 w-full min-h-[48px] rounded-xl border border-white/15 bg-slate-950 px-3 text-white">
              <?php foreach ($repaymentLabels as $method => $label): ?><option value="<?php echo $method; ?>" <?php echo $detail['repayment_method'] === $method ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?>
            </select>
          </label>
          <label class="block text-sm text-white/80">เหตุผลการเปลี่ยน<input type="text" name="reason" required maxlength="500" class="mt-2 w-full min-h-[48px] rounded-xl border border-white/15 bg-slate-950 px-3 text-white" placeholder="ระบุเหตุผลเพื่อบันทึก Audit"></label>
          <button class="min-h-[48px] rounded-xl bg-sky-500 hover:bg-sky-400 px-5 font-semibold text-slate-950" onclick="return confirm('ยืนยันเปลี่ยนแผนคืนเงิน?')">บันทึกวิธีคืนเงิน</button>
        </form>
        <p class="text-xs text-white/50 mt-2">เปลี่ยนได้ก่อนมีรายการรับชำระหรือเชื่อมสลิป ระบบเก็บผู้แก้ เหตุผล และค่าก่อน–หลัง</p>
      </div>
      <?php endif; ?>
      <?php if ($lineTimeline): ?>
      <div class="px-5 pb-5">
        <div class="rounded-xl border border-white/10 overflow-hidden">
          <h3 class="px-4 py-3 text-white font-semibold border-b border-white/10">ประวัติการส่ง LINE ล่าสุด</h3>
          <ol class="divide-y divide-white/10 text-sm">
          <?php foreach ($lineTimeline as $lineEvent): ?>
            <li class="p-4 grid gap-2 md:grid-cols-[1.2fr_1fr_1fr]">
              <div><p class="text-white font-medium"><?php echo htmlspecialchars($lineMessageLabels[(string)$lineEvent['message_type']] ?? (string)$lineEvent['message_type']); ?></p><p class="text-white/50 mt-1"><?php echo htmlspecialchars($lineRecipientLabels[(string)$lineEvent['recipient_type']] ?? (string)$lineEvent['recipient_type']); ?> · <?php echo htmlspecialchars($maskLineRecipient((string)$lineEvent['recipient_line_id'])); ?></p></div>
              <div><p class="<?php echo $lineEvent['status'] === 'failed' ? 'text-rose-300' : ($lineEvent['status'] === 'sent' ? 'text-emerald-300' : 'text-amber-300'); ?> font-medium"><?php echo htmlspecialchars($lineStatusLabels[(string)$lineEvent['status']] ?? (string)$lineEvent['status']); ?></p><p class="text-white/50 mt-1">พยายาม <?php echo (int)$lineEvent['attempt_count']; ?> ครั้ง</p></div>
              <div><p class="text-white/70"><?php echo htmlspecialchars((string)($lineEvent['sent_at'] ?: $lineEvent['scheduled_at'] ?: $lineEvent['created_at'])); ?></p><?php if (!empty($lineEvent['last_error'])): ?><p class="text-rose-300 mt-1 break-words">สาเหตุ: <?php echo htmlspecialchars((string)$lineEvent['last_error']); ?></p><?php endif; ?></div>
            </li>
          <?php endforeach; ?>
          </ol>
        </div>
      </div>
      <?php endif; ?>
      <div id="repayment" class="px-5 pb-5">
        <div class="rounded-xl bg-emerald-500/10 border border-emerald-400/20 p-4">
          <div class="flex flex-wrap justify-between gap-2 text-sm"><span class="text-white font-semibold"><?php echo $detail['finance_type'] === 'employee_loan' ? 'ชำระครบ ' . (int)$paymentSummary['paid_installments'] . ' / ' . (int)$paymentSummary['total_installments'] . ' งวด' : 'สถานะการคืนเงิน'; ?></span><span class="text-white/75">รับคืนแล้ว <?php echo number_format((float)$paymentSummary['paid_amount'], 2); ?> · คงเหลือ <?php echo number_format((float)$paymentSummary['remaining_amount'], 2); ?> บาท</span></div>
          <div class="h-2 rounded-full bg-black/25 mt-3 overflow-hidden"><div class="h-full bg-emerald-400" style="width:<?php echo (float)$detail['total_payable'] > 0 ? min(100, ((float)$paymentSummary['paid_amount'] / (float)$detail['total_payable']) * 100) : 0; ?>%"></div></div>
        </div>
      </div>
      <?php if ($canManage && (float)$paymentSummary['remaining_amount'] > 0 && in_array((string)($expense['status'] ?? ''), ['paid','confirmed','completed'], true)): ?>
      <div class="px-5 pb-5">
        <form method="post" class="rounded-xl border border-sky-300/25 bg-sky-400/5 p-4 grid gap-4 md:grid-cols-2 lg:grid-cols-4 lg:items-end">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="receive_repayment">
          <input type="hidden" name="type" value="<?php echo htmlspecialchars($selectedType); ?>">
          <input type="hidden" name="id" value="<?php echo $selectedId; ?>">
          <input type="hidden" name="idempotency_key" value="<?php echo htmlspecialchars((string)$_SESSION['employee_finance_repayment_key']); ?>">
          <label class="block text-sm text-white/80">วิธีรับคืน
            <select name="payment_method" required class="mt-2 w-full min-h-[48px] rounded-xl border border-white/15 bg-slate-950 px-3 text-white"><option value="cash">รับคืนเป็นเงินสด</option><option value="transfer">พนักงานโอนคืนบริษัท</option></select>
          </label>
          <label class="block text-sm text-white/80">ยอดรับชำระ
            <input type="number" name="amount" min="0.01" max="<?php echo number_format((float)$paymentSummary['remaining_amount'], 2, '.', ''); ?>" step="0.01" required value="<?php echo number_format((float)$paymentSummary['remaining_amount'], 2, '.', ''); ?>" class="mt-2 w-full min-h-[48px] rounded-xl border border-white/15 bg-slate-950 px-3 text-white tabular-nums">
          </label>
          <label class="block text-sm text-white/80">วันเวลารับชำระ
            <input type="datetime-local" name="received_at" required value="<?php echo date('Y-m-d\TH:i'); ?>" class="mt-2 w-full min-h-[48px] rounded-xl border border-white/15 bg-slate-950 px-3 text-white">
          </label>
          <label class="block text-sm text-white/80">เลขอ้างอิง (ถ้ามี)
            <input type="text" name="reference_number" maxlength="100" class="mt-2 w-full min-h-[48px] rounded-xl border border-white/15 bg-slate-950 px-3 text-white">
          </label>
          <label class="block text-sm text-white/80 md:col-span-2 lg:col-span-3">หมายเหตุ
            <input type="text" name="notes" maxlength="1000" placeholder="ระบุผู้ส่งมอบเงินหรือรายละเอียดเพิ่มเติม" class="mt-2 w-full min-h-[48px] rounded-xl border border-white/15 bg-slate-950 px-3 text-white">
          </label>
          <button type="submit" class="min-h-[48px] rounded-xl bg-sky-500 hover:bg-sky-400 px-5 font-semibold text-slate-950" onclick="return confirm('ยืนยันยอดรับชำระ? ระบบจะตัดยอดลูกหนี้และออกเลขที่ใบรับเงินทันที')">บันทึกรับคืนเงิน</button>
        </form>
      </div>
      <?php endif; ?>
      <?php if ($receivedRepayments): ?>
      <div class="px-5 pb-5">
        <div class="rounded-xl border border-white/10 overflow-hidden">
          <h3 class="px-4 py-3 text-white font-semibold border-b border-white/10">ประวัติรับชำระ</h3>
          <div class="divide-y divide-white/10"><?php foreach ($receivedRepayments as $received): ?><div class="p-4 grid gap-3 md:grid-cols-[1fr_1fr_1fr_auto] md:items-center text-sm"><div><p class="text-white font-semibold"><?php echo htmlspecialchars((string)$received['receipt_number']); ?></p><p class="text-white/50 mt-1"><?php echo htmlspecialchars((string)$received['received_at']); ?></p></div><div><p class="text-white"><?php echo number_format((float)$received['amount'], 2); ?> บาท</p><p class="text-white/50 mt-1"><?php echo $received['payment_method'] === 'cash' ? 'เงินสด' : 'โอนคืนบริษัท'; ?></p></div><div><p class="text-white/75">ผู้รับ <?php echo htmlspecialchars(trim((string)$received['receiver_first_name'] . ' ' . (string)$received['receiver_last_name'])); ?></p><p class="text-white/50 mt-1">บัญชี: <?php echo htmlspecialchars((string)($received['accounting_status'] ?? 'pending')); ?> · หลักฐาน: <?php echo !empty($received['evidence_path']) ? 'แนบแล้ว' : 'รอลงนาม'; ?></p></div><div class="flex flex-wrap gap-2"><a class="min-h-[48px] inline-flex items-center justify-center rounded-xl border border-white/15 px-4 text-white hover:bg-white/10" target="_blank" rel="noopener" href="/employee_finance_receipt.php?id=<?php echo (int)$received['id']; ?>">เปิดใบรับเงิน</a><?php if ($canManage): ?><form method="post" enctype="multipart/form-data" class="flex items-center gap-2"><?php echo csrfField(); ?><input type="hidden" name="action" value="upload_repayment_evidence"><input type="hidden" name="type" value="<?php echo htmlspecialchars($selectedType); ?>"><input type="hidden" name="id" value="<?php echo $selectedId; ?>"><input type="hidden" name="receipt_id" value="<?php echo (int)$received['id']; ?>"><label class="min-h-[48px] inline-flex cursor-pointer items-center justify-center rounded-xl bg-white/10 px-4 text-white hover:bg-white/15"><span><?php echo !empty($received['evidence_path']) ? 'เปลี่ยนหลักฐาน' : 'แนบฉบับลงนาม'; ?></span><input class="sr-only" type="file" name="evidence" accept="application/pdf,image/jpeg,image/png" required onchange="this.form.submit()"></label></form><?php endif; ?></div></div><?php endforeach; ?></div>
        </div>
      </div>
      <?php endif; ?>
      <div class="px-5 pb-5 grid gap-4 md:grid-cols-2">
        <div class="rounded-xl border border-white/10 p-4">
          <h3 class="text-white font-semibold">การแจ้งเตือน LINE</h3>
          <p class="text-white/65 text-sm mt-2"><?php if (array_sum($lineDelivery) > 0): ?>ส่งสำเร็จ <?php echo $lineDelivery['sent']; ?> · รอส่ง <?php echo $lineDelivery['pending']; ?> · ส่งไม่สำเร็จ <?php echo $lineDelivery['failed']; ?> · ยกเลิก <?php echo $lineDelivery['cancelled']; ?><?php else: ?>ยังไม่มีประวัติในคิว LINE — ตรวจสอบว่าผู้ขอและผู้อนุมัติผูก LINE แล้ว<?php endif; ?></p>
        </div>
        <div class="rounded-xl border border-white/10 p-4">
          <h3 class="text-white font-semibold">การเชื่อมโยงเงินเดือน</h3>
          <p class="text-white/65 text-sm mt-2"><?php if ($detail['repayment_method'] !== 'payroll'): ?>รายการนี้ไม่หักผ่านสลิป พนักงานต้องคืนบริษัทตามวิธีที่เลือก และฝ่ายรับเงินต้องบันทึกหลักฐานการรับชำระ<?php elseif ($detail['status'] === 'pending_disbursement'): ?>จะเริ่มนำค่างวดเข้าสลิปหลังบริษัทบันทึกจ่ายเงิน<?php elseif ($linkedPayrollInstallments > 0): ?>เชื่อมกับสลิปเงินเดือนแล้ว <?php echo $linkedPayrollInstallments; ?> / <?php echo max(1, (int)$paymentSummary['total_installments']); ?> งวด<?php else: ?>ระบบจะนำค่างวดเดือน <?php echo htmlspecialchars($formatThaiMonth((string)$detail['first_due_month'])); ?> เข้ารายการหักอื่นในสลิปเงินเดือนโดยอัตโนมัติ<?php endif; ?></p>
        </div>
      </div>
      <?php if ($detail['finance_type'] === 'employee_loan'): ?>
      <div class="border-t border-white/10 p-5">
        <h3 class="text-white font-semibold mb-3">ตารางผ่อนชำระและการลงสลิป</h3>
        <?php
        // One renderer for the payroll-slip cell, shared by the phone cards and
        // the table below, so the two cannot drift apart.
        $payrollSlipCell = static function (array $repayment) use ($crmBase, $formatThaiMonth): string {
            $linkStatus = (string)($repayment['payroll_link_status'] ?? '');
            if (!empty($repayment['payroll_slip_id']) && in_array($linkStatus, ['included', 'settled'], true)) {
                return '<a class="text-violet-300 hover:text-violet-200" target="_blank" rel="noopener" href="'
                    . htmlspecialchars($crmBase . '/payroll_print.php?slip_id=' . (int)$repayment['payroll_slip_id']) . '">'
                    . htmlspecialchars($repayment['payroll_month'] ? $formatThaiMonth((string)$repayment['payroll_month']) : 'เปิดสลิป')
                    . '</a>';
            }
            if ($linkStatus === 'reversed') {
                return '<span class="text-amber-300">ยกเลิกการเชื่อมสลิปแล้ว</span>';
            }
            return htmlspecialchars($repayment['payroll_month'] ? $formatThaiMonth((string)$repayment['payroll_month']) : 'ยังไม่ลงสลิป');
        };
        ?>
        <?php /* Phone: seven columns do not fit 375px, and side-scrolling a
                 table hides the numbers that matter. One card per instalment
                 instead — same data, read top to bottom. */ ?>
        <div class="md:hidden space-y-3">
          <?php foreach ($repayments as $repayment): ?>
          <div class="tp-ios-attendance-panel p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="text-white font-semibold">งวดที่ <?php echo (int)$repayment['installment_no']; ?></p>
                <p class="text-white/50 text-xs mt-0.5">ครบกำหนด <?php echo htmlspecialchars($formatThaiDate((string)$repayment['due_date'])); ?></p>
              </div>
              <p class="text-white font-semibold tabular-nums text-right shrink-0"><?php echo number_format((float)$repayment['due_amount'], 2); ?></p>
            </div>
            <dl class="grid grid-cols-2 gap-3 mt-3 text-sm min-w-0">
              <div class="min-w-0">
                <dt class="text-white/50 text-xs">เงินต้น</dt>
                <dd class="text-white/85 tabular-nums"><?php echo number_format((float)$repayment['principal_portion'], 2); ?></dd>
              </div>
              <div class="min-w-0">
                <dt class="text-white/50 text-xs">ดอกเบี้ย</dt>
                <dd class="text-white/85 tabular-nums"><?php echo number_format((float)$repayment['interest_portion'], 2); ?></dd>
              </div>
              <div class="min-w-0">
                <dt class="text-white/50 text-xs">สถานะ</dt>
                <dd class="text-white/85"><?php echo htmlspecialchars($statusLabel((string)$repayment['status'])); ?></dd>
              </div>
              <div class="min-w-0">
                <dt class="text-white/50 text-xs">รอบเงินเดือน</dt>
                <dd class="text-white/85"><?php echo $payrollSlipCell($repayment); ?></dd>
              </div>
            </dl>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain"><table class="w-full min-w-[720px] text-sm">
          <thead class="text-white/55"><tr><th class="text-left p-3">งวด</th><th class="text-left p-3">ครบกำหนด</th><th class="text-right p-3">เงินต้น</th><th class="text-right p-3">ดอกเบี้ย</th><th class="text-right p-3">ยอดชำระ</th><th class="text-left p-3">สถานะ</th><th class="text-left p-3">รอบเงินเดือน</th></tr></thead>
          <tbody class="divide-y divide-white/10 text-white/80">
          <?php foreach ($repayments as $repayment): ?><tr>
            <td class="p-3"><?php echo (int)$repayment['installment_no']; ?></td><td class="p-3"><?php echo htmlspecialchars($formatThaiDate((string)$repayment['due_date'])); ?></td>
            <td class="p-3 text-right"><?php echo number_format((float)$repayment['principal_portion'], 2); ?></td><td class="p-3 text-right"><?php echo number_format((float)$repayment['interest_portion'], 2); ?></td><td class="p-3 text-right font-semibold"><?php echo number_format((float)$repayment['due_amount'], 2); ?></td>
            <td class="p-3"><?php echo htmlspecialchars($statusLabel((string)$repayment['status'])); ?></td><td class="p-3"><?php echo $payrollSlipCell($repayment); ?></td>
          </tr><?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
      <?php endif; ?>
      <?php if ($financeAudit): ?>
      <div class="border-t border-white/10 p-5">
        <h3 class="text-white font-semibold mb-3">ประวัติการเปลี่ยนแปลง</h3>
        <ol class="grid gap-2 md:grid-cols-2 text-sm"><?php foreach ($financeAudit as $audit): $auditPayload = json_decode((string)($audit['payload_json'] ?? ''), true) ?: []; ?><li class="rounded-lg bg-black/15 px-3 py-2 text-white/70"><span class="text-white/90"><?php echo htmlspecialchars((string)$audit['event_type']); ?></span> · <?php echo htmlspecialchars((string)$audit['created_at']); ?><?php if (($audit['event_type'] ?? '') === 'first_due_month_changed'): ?><p class="mt-1 text-white/60"><?php echo htmlspecialchars($formatThaiMonth((string)($auditPayload['old_month'] ?? ''))); ?> → <?php echo htmlspecialchars($formatThaiMonth((string)($auditPayload['new_month'] ?? ''))); ?> · <?php echo htmlspecialchars((string)($auditPayload['reason'] ?? '')); ?></p><?php endif; ?></li><?php endforeach; ?></ol>
      </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>
    <section class="rounded-2xl border border-white/10 bg-white/5 backdrop-blur overflow-hidden">
      <div class="p-5 border-b border-white/10"><h2 class="font-semibold text-white"><?php echo $canManage ? 'รายการของพนักงานทั้งหมด' : 'รายการของฉัน'; ?></h2></div>
      <?php if (!$rows): ?>
        <div class="p-8 text-center text-white/60">ยังไม่มีรายการเงินกู้หรือเบิกล่วงหน้า</div>
      <?php else: ?>
        <?php /* Phone: eight columns cannot fit, so each record becomes a card
                 — name and amount lead, the rest reads as label/value pairs. */ ?>
        <div class="md:hidden p-5 space-y-3">
          <?php foreach ($rows as $row): ?>
          <div class="tp-ios-attendance-panel p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="text-white font-semibold"><?php echo htmlspecialchars(trim(($row['first_name_th'] ?? '') . ' ' . ($row['last_name_th'] ?? ''))); ?></p>
                <p class="text-white/50 text-xs mt-0.5"><?php echo $row['finance_type'] === 'employee_loan' ? 'เงินกู้พนักงาน' : 'เบิกเงินเดือนล่วงหน้า'; ?></p>
              </div>
              <div class="text-right shrink-0">
                <p class="text-white font-semibold tabular-nums"><?php echo number_format((float)$row['total_payable'], 2); ?></p>
                <p class="text-white/50 text-xs mt-0.5">ชำระรวม</p>
              </div>
            </div>
            <dl class="grid grid-cols-2 gap-3 mt-3 text-sm min-w-0">
              <div class="min-w-0">
                <dt class="text-white/50 text-xs">เงินต้น</dt>
                <dd class="text-white/85 tabular-nums"><?php echo number_format((float)$row['principal_amount'], 2); ?></dd>
              </div>
              <div class="min-w-0">
                <dt class="text-white/50 text-xs">งวด</dt>
                <dd class="text-white/85 tabular-nums"><?php echo $row['term_months'] ?: '1'; ?></dd>
              </div>
              <div class="min-w-0">
                <dt class="text-white/50 text-xs">เริ่มชำระ</dt>
                <dd class="text-white/85"><?php echo htmlspecialchars($formatThaiMonth((string)$row['first_due_month'])); ?></dd>
              </div>
              <div class="min-w-0">
                <dt class="text-white/50 text-xs">วิธีชำระ</dt>
                <dd class="text-white/85"><?php echo htmlspecialchars($repaymentLabels[(string)$row['repayment_method']] ?? (string)$row['repayment_method']); ?></dd>
              </div>
            </dl>
            <div class="flex items-center justify-between gap-3 mt-3 pt-3 border-t border-white/10">
              <span class="text-white/85 text-sm min-w-0"><?php echo htmlspecialchars($statusLabel((string)$row['status'])); ?></span>
              <a class="tp-tap-48 shrink-0 text-violet-300 hover:text-violet-200 text-sm font-medium whitespace-nowrap" href="?type=<?php echo urlencode((string)$row['finance_type']); ?>&amp;id=<?php echo (int)$row['id']; ?>#finance-detail">ดูรายละเอียด</a>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain"><table class="w-full min-w-[760px] text-sm">
          <thead class="text-white/60"><tr><th class="text-left p-4">พนักงาน</th><th class="text-left p-4">ประเภท</th><th class="text-right p-4">เงินต้น</th><th class="text-right p-4">ชำระรวม</th><th class="text-center p-4">งวด</th><th class="text-left p-4">เริ่มชำระ</th><th class="text-left p-4">วิธีชำระ</th><th class="text-left p-4">สถานะ</th></tr></thead>
          <tbody class="divide-y divide-white/10 text-white/85">
          <?php foreach ($rows as $row): ?><tr class="hover:bg-white/[.03]">
            <td class="p-4"><?php echo htmlspecialchars(trim(($row['first_name_th'] ?? '') . ' ' . ($row['last_name_th'] ?? ''))); ?></td>
            <td class="p-4"><?php echo $row['finance_type'] === 'employee_loan' ? 'เงินกู้พนักงาน' : 'เบิกเงินเดือนล่วงหน้า'; ?></td>
            <td class="p-4 text-right tabular-nums"><?php echo number_format((float)$row['principal_amount'], 2); ?></td>
            <td class="p-4 text-right tabular-nums"><?php echo number_format((float)$row['total_payable'], 2); ?></td>
            <td class="p-4 text-center"><?php echo $row['term_months'] ?: '1'; ?></td>
            <td class="p-4"><?php echo htmlspecialchars($formatThaiMonth((string)$row['first_due_month'])); ?></td>
            <td class="p-4"><?php echo htmlspecialchars($repaymentLabels[(string)$row['repayment_method']] ?? (string)$row['repayment_method']); ?></td>
            <td class="p-4"><span><?php echo htmlspecialchars($statusLabel((string)$row['status'])); ?></span><a class="tp-tap-48 block text-violet-300 hover:text-violet-200 mt-2 font-medium" href="?type=<?php echo urlencode((string)$row['finance_type']); ?>&amp;id=<?php echo (int)$row['id']; ?>#finance-detail">ดูรายละเอียด</a></td>
          </tr><?php endforeach; ?>
          </tbody></table></div>
      <?php endif; ?>
    </section>
  </div>
<?php /* footer.php closes the <main> that header.php opened. */ ?>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
