<?php

$page_title = 'อนุมัติเข้างานสาย';
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/core/Services/PlannedLateApprovalService.php';
require_once dirname(__DIR__) . '/core/CrmLineNotifierBridge.php';

Auth::requireLogin();
if (!hr_can_access_hr_dashboard() || !Auth::hasRole(['HR', 'Admin', 'Chairman', 'CEO'])) {
    flash('error', 'คุณไม่มีสิทธิ์อนุมัติคำขอเข้างานสาย');
    redirect('/', 302);
}

$pdo = Database::getInstance()->getConnection();
$user = Auth::user();
$current_page = 'hr-planned-late';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_token'] ?? '')) {
        flash('error', 'เซสชันหมดอายุหรือข้อมูลไม่ถูกต้อง กรุณาลองใหม่');
        redirect('/hr/planned_late_approvals.php', 302);
    }
    try {
        $decision = ($_POST['action'] ?? '') === 'approve' ? 'APPROVED' : 'REJECTED';
        $row = (new PlannedLateApprovalService($pdo))->decide(
            (int)($_POST['attendance_id'] ?? 0),
            (int)$user['id'],
            $decision,
            trim((string)($_POST['review_note'] ?? ''))
        );
        Auth::log('PLANNED_LATE_' . $decision, 'hr_attendances', (int)$row['id'], null, [
            'employee_id' => (int)$row['user_id'],
            'attendance_date' => $row['attendance_date'],
        ]);
        if (function_exists('crm_line_notify_planned_late_decision')) {
            crm_line_notify_planned_late_decision($pdo, (int)$row['id'], $decision, (int)$user['id']);
        }
        flash('success', $decision === 'APPROVED' ? 'อนุมัติคำขอเรียบร้อยแล้ว' : 'ปฏิเสธคำขอเรียบร้อยแล้ว');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/hr/planned_late_approvals.php?status=' . urlencode((string)($_GET['status'] ?? 'PENDING')), 302);
}

$status = strtoupper((string)($_GET['status'] ?? 'PENDING'));
if (!in_array($status, ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED', 'ALL'], true)) $status = 'PENDING';
$where = $status === 'ALL' ? "a.planned_status IS NOT NULL" : "a.planned_status = ?";
$stmt = $pdo->prepare(
    "SELECT a.id, a.attendance_date, a.planned_start_time, a.planned_reason,
            a.planned_requested_at, a.planned_status, a.planned_review_note,
            u.first_name_th, u.last_name_th, u.employee_code, u.department
     FROM hr_attendances a JOIN users u ON u.id = a.user_id
     WHERE {$where} AND a.planned_start_time IS NOT NULL
     ORDER BY CASE a.planned_status WHEN 'PENDING' THEN 0 ELSE 1 END,
              a.attendance_date DESC, a.planned_requested_at DESC, a.id DESC"
);
$stmt->execute($status === 'ALL' ? [] : [$status]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM hr_attendances WHERE planned_status='PENDING' AND planned_start_time IS NOT NULL")->fetchColumn();

include dirname(__DIR__) . '/templates/header.php';
?>
<div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(1100px,100%)] mx-auto min-w-0">
    <header class="tp-ios-large-title-block mb-6">
        <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb"><a href="/hr/index.php" class="tp-tap-48 hover:text-white">แดชบอร์ด HR</a><span class="mx-2">/</span><span class="text-white">อนุมัติเข้างานสาย</span></nav>
        <h1 class="tp-ios-page-title">คำขอเข้างานสายล่วงหน้า</h1>
        <p class="tp-ios-caption-muted mt-2">เวลาที่พนักงานแจ้งจะยังไม่มีผลต่อการลงเวลาและเงินเดือนจนกว่าจะได้รับอนุมัติ</p>
    </header>

    <?php if ($ok = flash('success')): ?><div role="status" class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-emerald-200"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($ok); ?></div><?php endif; ?>
    <?php if ($err = flash('error')): ?><div role="alert" class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-red-500/30 bg-red-500/15 px-4 py-3 text-red-200"><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($err); ?></div><?php endif; ?>

    <div class="native-card tp-native-card p-4 mb-6 flex flex-wrap gap-2" aria-label="กรองสถานะ">
        <?php foreach (['PENDING'=>'รออนุมัติ (' . $pendingCount . ')','APPROVED'=>'อนุมัติแล้ว','REJECTED'=>'ไม่อนุมัติ','CANCELLED'=>'ยกเลิกแล้ว','ALL'=>'ทั้งหมด'] as $key=>$label): ?>
        <a href="?status=<?php echo $key; ?>" class="min-h-[48px] inline-flex items-center px-4 rounded-xl touch-manipulation whitespace-nowrap <?php echo $status === $key ? 'bg-violet-600 text-white' : 'bg-white/5 text-white/75 hover:bg-white/10'; ?>"><?php echo htmlspecialchars($label); ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (!$requests): ?>
    <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15">
        <i class="fas fa-inbox text-4xl text-white/30 mb-3" aria-hidden="true"></i><p class="text-white font-semibold">ไม่มีรายการในสถานะนี้</p><p class="text-white/50 mt-1">คำขอใหม่จะแสดงที่นี่โดยอัตโนมัติ</p>
    </div>
    <?php else: ?>
    <div class="space-y-4">
        <?php foreach ($requests as $r): $isPending = $r['planned_status'] === 'PENDING'; ?>
        <article class="native-card tp-native-card p-5 rounded-[var(--tp-ios-card-radius)]">
            <div class="flex flex-col sm:flex-row sm:justify-between gap-3">
                <div><h2 class="text-white text-lg font-semibold"><?php echo htmlspecialchars(trim($r['first_name_th'].' '.$r['last_name_th'])); ?></h2><p class="text-white/55 text-sm"><?php echo htmlspecialchars(($r['employee_code'] ?: '—').' · '.($r['department'] ?: 'ไม่ระบุแผนก')); ?></p></div>
                <span class="self-start rounded-full px-3 py-1 text-sm font-semibold <?php echo $isPending ? 'bg-amber-500/15 text-amber-200' : ($r['planned_status']==='APPROVED'?'bg-emerald-500/15 text-emerald-200':'bg-red-500/15 text-red-200'); ?>"><?php echo htmlspecialchars($r['planned_status']); ?></span>
            </div>
            <dl class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-4 text-sm"><div><dt class="text-white/50">วันที่</dt><dd class="text-white mt-1"><?php echo htmlspecialchars($r['attendance_date']); ?></dd></div><div><dt class="text-white/50">เวลาเข้าที่ขอ</dt><dd class="text-white mt-1 tabular-nums"><?php echo htmlspecialchars(substr($r['planned_start_time'],0,5)); ?> น.</dd></div><div><dt class="text-white/50">เหตุผล</dt><dd class="text-white mt-1"><?php echo htmlspecialchars($r['planned_reason']); ?></dd></div></dl>
            <?php if ($isPending): ?>
            <form method="post" class="mt-5 grid grid-cols-1 sm:grid-cols-[1fr_auto_auto] gap-2" onsubmit="this.querySelectorAll('button').forEach(b=>b.disabled=true)">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrfToken()); ?>"><input type="hidden" name="attendance_id" value="<?php echo (int)$r['id']; ?>">
                <label class="sr-only" for="note-<?php echo (int)$r['id']; ?>">หมายเหตุผู้อนุมัติ</label><input id="note-<?php echo (int)$r['id']; ?>" name="review_note" maxlength="500" class="input-field tp-native-input min-h-[52px]" placeholder="หมายเหตุ (ถ้ามี)">
                <button name="action" value="approve" class="min-h-[48px] px-5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold touch-manipulation whitespace-nowrap"><i class="fas fa-check mr-2"></i>อนุมัติ</button>
                <button name="action" value="reject" onclick="return confirm('ยืนยันไม่อนุมัติคำขอนี้?')" class="min-h-[48px] px-5 rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold touch-manipulation whitespace-nowrap"><i class="fas fa-times mr-2"></i>ไม่อนุมัติ</button>
            </form>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
