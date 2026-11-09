<?php
if (!defined('BASE_PATH')) {http_response_code(403);exit;}
// Scope supplied by the authenticated page controller.
/** @var PDO $pdo */
/** @var array $run */
/** @var int $run_id */
/**
 * Payroll Detail View - Run detail with slips table
 * Variables available: $run, $run_id, $slips
 */
?>
    <?php
    $_finance_reconciliation = function_exists('payroll_reconcile_employee_finance_run')
        ? payroll_reconcile_employee_finance_run($pdo, (int)$run_id)
        : ['warnings' => [], 'affordability' => []];
    $_salary_advance_sync = function_exists('payroll_salary_advance_sync_summary')
        ? payroll_salary_advance_sync_summary($pdo, (int)$run_id)
        : ['total'=>0,'included'=>0,'waiting'=>0,'excluded'=>0,'rows'=>[]];
    $_employee_loan_sync = function_exists('payroll_employee_loan_sync_summary')
        ? payroll_employee_loan_sync_summary($pdo, (int)$run_id)
        : ['total'=>0,'included'=>0,'waiting'=>0,'excluded'=>0,'rows'=>[]];
    $_employee_codes = [];
    foreach ($slips as $_s) $_employee_codes[(int)$_s['id']] = payroll_format_employee_code($_s['employee_code'] ?? '');
    $_affordability_blocked = !empty(array_filter($_finance_reconciliation['affordability'] ?? [], static fn(array $a): bool => !empty($a['blocking'])));
    $_hr_base_synced = count(array_filter($slips, static fn(array $s): bool => ($s['hr_base_sync_status'] ?? '') === 'synced'));
    $_hr_base_changed = count(array_filter($slips, static fn(array $s): bool => ($s['hr_base_sync_status'] ?? '') === 'changed'));
    $_hr_base_missing = count(array_filter($slips, static fn(array $s): bool => ($s['hr_base_sync_status'] ?? '') === 'missing'));
    $_can_affordability_override = function_exists('payroll_employee_finance_override_role_allowed')
        && payroll_employee_finance_override_role_allowed((string)($current_user['role_name'] ?? ''));
    $_affordability_alert_heading = match ((string)$run['status']) {
        'paid' => 'พบยอดหักรวมสูงในรอบที่จ่ายแล้ว ใช้สำหรับตรวจสอบย้อนหลัง',
        'approved' => 'พบยอดหักรวมสูงเมื่อเทียบกับรายได้ กรุณาตรวจสอบก่อนบันทึกจ่าย',
        default => 'พบยอดหักรวมสูงเมื่อเทียบกับรายได้ กรุณาตรวจสอบก่อนอนุมัติ',
    };
    $_affordability_block_label = match ((string)$run['status']) {
        'paid' => ' — พบภายหลังการจ่าย โปรดบันทึกผลการตรวจสอบ',
        'approved' => ' — ไม่สามารถบันทึกจ่ายได้',
        default => ' — ไม่สามารถอนุมัติได้',
    };
    ?>
    <!-- รายละเอียดรอบ + สลิป -->
    <div class="glass-card rounded-2xl p-6 mb-6 border border-white/10">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <?php
                $_run_pay_date_th = function_exists('payroll_format_pay_date_thai') ? payroll_format_pay_date_thai($run['payroll_month'], (int)$run['pay_day']) : ('วันที่ ' . (int)$run['pay_day']);
                $_run_period_th = function_exists('payroll_format_period_range_thai') ? payroll_format_period_range_thai($run['payroll_month'], (int)$run['pay_day']) : '';
                ?>
                <h2 class="text-lg font-semibold text-white">รอบ <?php echo date('F Y', strtotime($run['payroll_month'])); ?> — จ่ายวันที่ <?php echo htmlspecialchars($_run_pay_date_th); ?></h2>
                <p class="text-white/60 text-sm mt-1">
                    <?php if ($_run_period_th !== ''): ?>ช่วงเวลาทำงาน <?php echo htmlspecialchars($_run_period_th); ?> · <?php endif; ?>
                    สถานะ: <span class="font-medium text-white/80"><?php echo $run['status']; ?></span> · จำนวน <?php echo (int)$run['employee_count']; ?> คน · สุทธิรวม <span class="text-emerald-400 font-semibold">฿<?php echo number_format($run['total_net'], 2); ?></span>
                </p>
            </div>
            <div class="action-row flex flex-wrap items-center gap-3">
            <?php if ($run['status'] === 'calculated'): ?>
            <form method="post" class="inline-flex flex-col gap-2 min-w-[280px]">
                <input type="hidden" name="action" value="approve_run">
                <input type="hidden" name="run_id" value="<?php echo (int)$run_id; ?>">
                <?php echo function_exists('csrf_field') ? csrf_field() : ''; ?>
                <?php if ($_affordability_blocked && $_can_affordability_override): ?>
                <label class="inline-flex items-center gap-2 text-xs text-rose-100"><input type="checkbox" name="affordability_override" value="1" required> ยืนยัน override เกณฑ์เงินคงเหลือ</label>
                <textarea name="affordability_override_reason" minlength="10" required rows="2" maxlength="1000" class="w-full rounded-lg bg-slate-950/60 border border-rose-400/40 px-3 py-2 text-sm text-white" placeholder="ระบุเหตุผลอย่างน้อย 10 ตัวอักษร"></textarea>
                <?php endif; ?>
                <button type="submit" <?php echo ($_affordability_blocked && !$_can_affordability_override) ? 'disabled' : ''; ?> class="btn-payroll inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-medium transition-colors min-h-[48px]"><i class="fas fa-check sm:mr-1"></i><span class="btn-text">อนุมัติรอบ<?php echo $_affordability_blocked ? 'พร้อม override' : ''; ?></span></button>
            </form>
            <?php endif; ?>
            <?php if ($run['status'] === 'approved'): ?>
            <form method="post" class="inline">
                <input type="hidden" name="action" value="mark_paid">
                <input type="hidden" name="run_id" value="<?php echo (int)$run_id; ?>">
                <?php echo function_exists('csrf_field') ? csrf_field() : ''; ?>
                <button type="submit" class="btn-payroll inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-sm font-medium transition-colors min-h-[48px]"><i class="fas fa-money-bill-wave sm:mr-1"></i><span class="btn-text hidden sm:inline">บันทึกว่าจ่ายแล้ว</span></button>
            </form>
            <form method="post" class="inline" onsubmit="return confirm('ยกเลิกการอนุมัติรอบเงินเดือนนี้?\n\n• รอบจะกลับสู่สถานะ calculated\n• สามารถคำนวณสลิปใหม่/แก้ไขได้\n• ลิงก์สลิปเก่าที่ส่งผ่าน LINE จะใช้งานไม่ได้อีก\n\nยืนยันหรือไม่?');">
                <input type="hidden" name="action" value="cancel_approval">
                <input type="hidden" name="run_id" value="<?php echo (int)$run_id; ?>">
                <?php echo function_exists('csrf_field') ? csrf_field() : ''; ?>
                <button type="submit" class="btn-payroll inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-amber-600/90 hover:bg-amber-500 text-white text-sm font-medium transition-colors min-h-[48px]" title="ยกเลิกการอนุมัติและกลับไปแก้ไข"><i class="fas fa-undo sm:mr-1"></i><span class="btn-text hidden sm:inline">ยกเลิกการอนุมัติ</span></button>
            </form>
            <?php endif; ?>
            <?php if ($run['status'] === 'paid'): ?>
            <form method="post" class="inline" onsubmit="return confirm('ยกเลิกการบันทึกว่าจ่ายแล้ว?\n\n• รอบจะกลับสู่สถานะ approved\n• จากนั้นกด ยกเลิกการอนุมัติ เพื่อคำนวณสลิปใหม่ได้\n• รายการค่าใช้จ่าย ERP ที่สร้างจากรอบนี้จะถูกลบ\n\nยืนยันหรือไม่?');">
                <input type="hidden" name="action" value="cancel_paid">
                <input type="hidden" name="run_id" value="<?php echo (int)$run_id; ?>">
                <?php echo function_exists('csrf_field') ? csrf_field() : ''; ?>
                <button type="submit" class="btn-payroll inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-amber-600/90 hover:bg-amber-500 text-white text-sm font-medium transition-colors min-h-[48px]" title="ย้อนรอบจาก paid กลับ approved เพื่อแก้ไข"><i class="fas fa-undo sm:mr-1"></i><span class="btn-text hidden sm:inline">ยกเลิกการบันทึกจ่าย</span></button>
            </form>
            <?php endif; ?>
                <a href="payroll_export.php?run_id=<?php echo (int)$run_id; ?>" class="btn-payroll inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-600/80 hover:bg-emerald-500 text-white text-sm font-medium transition-colors min-h-[48px]"><i class="fas fa-file-excel sm:mr-1"></i><span class="btn-text hidden sm:inline">Export Excel</span></a>
                <?php if (in_array($run['status'], ['approved', 'paid'], true)): ?>
                <form method="post" class="inline" onsubmit="return confirm('ส่งสลิปเงินเดือนให้พนักงานทุกคนใน run นี้ทาง LINE?\n(จะส่งเฉพาะพนักงานที่ผูก LINE แล้ว)');">
                    <input type="hidden" name="action" value="send_all_slips_to_line">
                    <input type="hidden" name="run_id" value="<?php echo (int)$run_id; ?>">
                    <?php echo function_exists('csrf_field') ? csrf_field() : ''; ?>
                    <button type="submit" class="btn-payroll inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-green-600/90 hover:bg-green-500 text-white text-sm font-medium transition-colors min-h-[48px]" title="ส่งสลิปทั้งหมดทาง LINE"><i class="fab fa-line sm:mr-1"></i><span class="btn-text hidden sm:inline">ส่งทั้งหมดทาง LINE</span></button>
                </form>
                <?php endif; ?>
                <a href="payroll.php?action=runs" class="btn-payroll inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-medium transition-colors min-h-[48px]"><i class="fas fa-arrow-left sm:mr-1"></i><span class="btn-text hidden sm:inline">กลับรายการรอบ</span></a>
            </div>
        </div>
        <?php if ($run['status'] === 'paid'): ?>
        <p class="mt-4 text-sm text-amber-200/90 bg-amber-500/10 border border-amber-500/25 rounded-xl px-4 py-3">
            <i class="fas fa-info-circle mr-2"></i>
            รอบนี้<strong class="text-amber-100">จ่ายแล้ว (paid)</strong> — ไม่มีปุ่ม "ยกเลิกการอนุมัติ" โดยตรง
            · หากต้องการคำนวณสลิปใหม่ ให้กด <strong class="text-amber-100">ยกเลิกการบันทึกจ่าย</strong> ก่อน แล้วกด <strong class="text-amber-100">ยกเลิกการอนุมัติ</strong>
        </p>
        <?php endif; ?>
        <?php if ((int)($_salary_advance_sync['total'] ?? 0) > 0): ?>
        <section class="mt-4 rounded-xl border border-cyan-400/25 bg-cyan-500/10 px-4 py-3" aria-label="สถานะซิงค์เงินเบิกล่วงหน้า">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <strong class="text-cyan-100"><i class="fas fa-sync-alt mr-2"></i>เงินเบิกล่วงหน้าที่กำหนดหักเดือนนี้</strong>
                <span class="rounded-full bg-emerald-500/20 px-2 py-1 text-emerald-200">เข้าสลิป <?php echo (int)$_salary_advance_sync['included']; ?></span>
                <span class="rounded-full bg-amber-500/20 px-2 py-1 text-amber-100">รอ ERP จ่าย <?php echo (int)$_salary_advance_sync['waiting']; ?></span>
                <span class="rounded-full bg-white/10 px-2 py-1 text-white/70">ไม่เข้าเงื่อนไข <?php echo (int)$_salary_advance_sync['excluded']; ?></span>
            </div>
            <div class="mt-3 grid gap-2 text-xs">
                <?php foreach ($_salary_advance_sync['rows'] as $_advance): ?>
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-950/30 px-3 py-2">
                    <span class="text-white/90">
                        <?php echo htmlspecialchars(trim((string)$_advance['employee_name']) ?: ('User #' . (int)$_advance['user_id'])); ?> · ฿<?php echo number_format((float)$_advance['amount'], 2); ?>
                        <?php if (!empty($_advance['expense_request_id'])): ?>
                            · <a class="text-cyan-200 underline hover:text-white" href="<?php echo htmlspecialchars(crm_tp_erp_url() . '/expenses/requests/' . (int)$_advance['expense_request_id']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars((string)($_advance['request_code'] ?: ('Request #' . (int)$_advance['expense_request_id']))); ?></a>
                        <?php endif; ?>
                    </span>
                    <span class="<?php echo $_advance['bucket'] === 'included' ? 'text-emerald-300' : ($_advance['bucket'] === 'waiting' ? 'text-amber-200' : 'text-white/60'); ?>"><?php echo htmlspecialchars((string)$_advance['reason']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="mt-3 text-xs text-cyan-100/70">ส่วนนี้แสดงเฉพาะเงินเบิกล่วงหน้าที่กำหนดหักในเดือนนี้ รวมประวัติที่ยกเลิกเพื่อการตรวจสอบ ส่วนเงินกู้บริษัทจะแสดงเป็นรายการหักรายงวดในสลิปของพนักงาน ระบบจะไม่หักรายการที่ ERP ยังไม่ยืนยันการจ่ายจริง</p>
        </section>
        <?php endif; ?>
        <?php if ((int)($_employee_loan_sync['total'] ?? 0) > 0): ?>
        <section class="mt-4 rounded-xl border border-sky-400/25 bg-sky-500/10 px-4 py-3" aria-label="สถานะหักเงินกู้พนักงาน">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <strong class="text-sky-100"><i class="fas fa-file-invoice-dollar mr-2"></i>เงินกู้บริษัทที่ครบกำหนดงวดเดือนนี้</strong>
                <span class="rounded-full bg-emerald-500/20 px-2 py-1 text-emerald-200">เข้าสลิป <?php echo (int)$_employee_loan_sync['included']; ?></span>
                <span class="rounded-full bg-amber-500/20 px-2 py-1 text-amber-100">รอ ERP จ่าย <?php echo (int)$_employee_loan_sync['waiting']; ?></span>
                <span class="rounded-full bg-white/10 px-2 py-1 text-white/70">ไม่เข้าเงื่อนไข <?php echo (int)$_employee_loan_sync['excluded']; ?></span>
            </div>
            <div class="mt-3 grid gap-2 text-xs">
                <?php foreach ($_employee_loan_sync['rows'] as $_loan): ?>
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-950/30 px-3 py-2">
                    <span class="text-white/90">
                        <?php echo htmlspecialchars(trim((string)$_loan['employee_name']) ?: ('User #' . (int)$_loan['user_id'])); ?>
                        · งวด <?php echo (int)$_loan['installment_no']; ?>/<?php echo (int)$_loan['term_months']; ?>
                        · ฿<?php echo number_format((float)$_loan['due_amount'], 2); ?>
                        <?php if (!empty($_loan['expense_request_id'])): ?>
                            · <a class="text-sky-200 underline hover:text-white" href="<?php echo htmlspecialchars(crm_tp_erp_url() . '/expenses/requests/' . (int)$_loan['expense_request_id']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars((string)($_loan['request_code'] ?: ('Request #' . (int)$_loan['expense_request_id']))); ?></a>
                        <?php endif; ?>
                    </span>
                    <span class="<?php echo $_loan['bucket'] === 'included' ? 'text-emerald-300' : ($_loan['bucket'] === 'waiting' ? 'text-amber-200' : 'text-white/60'); ?>"><?php echo htmlspecialchars((string)$_loan['reason']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="mt-3 text-xs text-sky-100/70">แสดงเฉพาะงวดเงินกู้ที่ครบกำหนดในรอบเงินเดือนนี้ ยอดคงเหลือทั้งหมดตรวจสอบได้จากคำขอต้นทางใน ERP</p>
        </section>
        <?php endif; ?>
        <?php if (!empty($_finance_reconciliation['affordability'])): ?>
        <div class="mt-4 text-sm text-rose-100 bg-rose-500/10 border border-rose-500/30 rounded-xl px-4 py-3" role="alert">
            <p class="font-semibold"><i class="fas fa-triangle-exclamation mr-2"></i><?php echo htmlspecialchars($_affordability_alert_heading); ?></p>
            <ul class="mt-2 space-y-1 list-disc list-inside">
                <?php foreach ($_finance_reconciliation['affordability'] as $_a): ?>
                <li>พนักงาน <?php echo htmlspecialchars($_employee_codes[(int)$_a['slip_id']] ?? ('สลิป #' . (int)$_a['slip_id'])); ?>: หัก <?php echo number_format((float)$_a['deduction_pct'], 2); ?>% · เงินสุทธิ <?php echo number_format((float)$_a['net_pct'], 2); ?>% ของรายได้<?php echo !empty($_a['blocking']) ? htmlspecialchars($_affordability_block_label) : ''; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
    <div class="mb-4 rounded-xl border <?php echo $_hr_base_changed > 0 || $_hr_base_missing > 0 ? 'border-amber-400/30 bg-amber-500/10 text-amber-100' : 'border-emerald-400/30 bg-emerald-500/10 text-emerald-100'; ?> px-4 py-3 text-sm">
        <strong><i class="fas fa-id-badge mr-2"></i>ฐานเงินเดือนจาก HR</strong>
        · ตรงกับ snapshot <?php echo (int)$_hr_base_synced; ?>/<?php echo count($slips); ?> คน
        <?php if ($_hr_base_changed > 0): ?> · <span class="text-amber-200">HR เปลี่ยนหลังคำนวณ <?php echo (int)$_hr_base_changed; ?> คน — ต้องคำนวณรอบใหม่ก่อนอนุมัติ</span><?php endif; ?>
        <?php if ($_hr_base_missing > 0): ?> · <span class="text-amber-200">ไม่พบฐานใน HR <?php echo (int)$_hr_base_missing; ?> คน</span><?php endif; ?>
        <div class="mt-1 text-xs opacity-70">ฐานเงินเดือนมาจากโปรไฟล์ HR ส่วนโบนัส เบี้ยเลี้ยง รายได้และรายการหักอื่นเป็น Payroll setup ที่ HR อ่านร่วมกัน</div>
    </div>
    <p class="mb-4 text-sm text-white/80">วันที่จ่ายรายบุคคลเลือกข้ามเดือนได้ สลิปยังเป็นของเดือนเดิม เมื่อบันทึกว่าจ่ายแล้วจะปิดการแก้ไขวันที่ หากเคยส่งสลิปทาง LINE แล้ว ให้ส่งซ้ำเพื่อแจ้งวันที่ใหม่</p>
    <div class="glass-card rounded-2xl overflow-hidden border border-white/10">
        <div class="overflow-x-auto">
            <table class="w-full text-left responsive-table payroll-slips-table">
                <thead>
                    <tr class="bg-white/5">
                        <th class="px-6 py-4 text-left text-sm font-medium text-white/60">รหัส</th>
                        <th class="px-6 py-4 text-left text-sm font-medium text-white/60">ชื่อ-นามสกุล</th>
                        <th class="px-6 py-4 text-right text-sm font-medium text-white/60">รวมได้</th>
                        <th class="px-6 py-4 text-right text-sm font-medium text-white/60">หักภาษี</th>
                        <th class="px-6 py-4 text-right text-sm font-medium text-white/60">ประกันกลุ่ม</th>
                        <th class="px-6 py-4 text-right text-sm font-medium text-white/60">ประกันสุขภาพ</th>
                        <th class="px-6 py-4 text-right text-sm font-medium text-white/60">สุทธิ</th>
                        <th class="px-6 py-4 text-center text-sm font-medium text-white/60">วันที่จ่าย</th>
                        <th class="px-6 py-4 text-center text-sm font-medium text-white/60">LINE</th>
                        <th class="px-6 py-4 text-center text-sm font-medium text-white/60">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach ($slips as $s): ?>
                    <tr class="table-row transition-colors hover:bg-white/5">
                        <td class="px-6 py-4 font-mono text-purple-300"><?php echo htmlspecialchars(payroll_format_employee_code($s['employee_code'] ?? '')); ?></td>
                        <td class="px-6 py-4 font-medium text-white">
                            <?php echo htmlspecialchars(($s['first_name_th'] ?? '') . ' ' . ($s['last_name_th'] ?? '')); ?>
                            <?php if (($s['hr_base_sync_status'] ?? '') === 'synced'): ?><div class="mt-1 text-[11px] text-emerald-300">ฐาน HR ตรงกับสลิป</div>
                            <?php elseif (($s['hr_base_sync_status'] ?? '') === 'changed'): ?><div class="mt-1 text-[11px] text-amber-300">ฐาน HR เปลี่ยนหลังคำนวณ</div>
                            <?php elseif (($s['hr_base_sync_status'] ?? '') === 'missing'): ?><div class="mt-1 text-[11px] text-rose-300">ไม่พบฐานเงินเดือนใน HR</div><?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-right text-white/90"><?php echo number_format($s['total_income'], 2); ?></td>
                        <td class="px-6 py-4 text-right text-white/80"><?php echo number_format($s['tax_withheld'], 2); ?></td>
                        <td class="px-6 py-4 text-right text-white/70"><?php echo number_format((float)($s['group_insurance'] ?? 0), 2); ?></td>
                        <td class="px-6 py-4 text-right text-white/70"><?php echo number_format((float)($s['health_insurance'] ?? 0), 2); ?></td>
                        <td class="px-6 py-4 text-right font-semibold text-emerald-400"><?php echo number_format($s['net_salary'], 2); ?></td>
                        <?php require __DIR__ . '/payment_date.view.php'; ?>
                        <td class="px-6 py-4 text-center text-xs">
                            <?php
                                $sent_cnt = (int)($s['line_sent_count'] ?? 0);
                                $dl_cnt   = (int)($s['total_downloads'] ?? 0);
                                $last     = $s['line_last_sent_at'] ?? null;
                            ?>
                            <?php if (!($s['has_line'] ?? 0)): ?>
                                <span class="px-2 py-1 bg-white/5 text-white/40 rounded" title="พนักงานยังไม่ได้ผูก LINE"><i class="fas fa-unlink"></i></span>
                            <?php elseif ($sent_cnt > 0): ?>
                                <div class="inline-flex flex-col items-center gap-0.5" title="<?php echo htmlspecialchars('ส่งล่าสุด: ' . ($last ?? '-') . ' · ดาวน์โหลด: ' . $dl_cnt . ' ครั้ง'); ?>">
                                    <span class="px-2 py-0.5 bg-emerald-500/20 text-emerald-300 rounded">ส่งแล้ว × <?php echo $sent_cnt; ?></span>
                                    <span class="text-white/40 text-[10px]"><i class="fas fa-download"></i> <?php echo $dl_cnt; ?></span>
                                </div>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-white/5 text-white/40 rounded">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="cell-actions flex items-center justify-center gap-2">
                                <a href="payroll.php?action=salary_setup&user_id=<?php echo (int)$s['user_id']; ?>&for_month=<?php echo date('Y-m', strtotime($run['payroll_month'])); ?>&run_id=<?php echo (int)$run_id; ?>" class="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-amber-600/80 hover:bg-amber-500 text-white text-sm min-h-[48px] min-w-[48px]" title="แก้ไขเงินเดือนเดือนนี้"><i class="fas fa-edit"></i></a>
                                <a href="payroll_print.php?slip_id=<?php echo (int)$s['id']; ?>" target="_blank" class="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-violet-600/80 hover:bg-violet-500 text-white text-sm min-h-[48px]"><i class="fas fa-print sm:mr-1"></i><span class="hidden sm:inline">พิมพ์</span></a>
                                <?php if (in_array($run['status'], ['approved','paid'], true)): ?>
                                <form method="post" class="inline" onsubmit="return confirm('<?php echo ($sent_cnt > 0) ? 'สลิปนี้เคยส่งไปแล้ว '.(int)$sent_cnt.' ครั้ง — ส่งซ้ำอีกครั้งหรือไม่?' : 'ส่งสลิปเงินเดือนให้พนักงานคนนี้ทาง LINE?'; ?>');">
                                    <input type="hidden" name="action" value="send_slip_to_line">
                                    <input type="hidden" name="slip_id" value="<?php echo (int)$s['id']; ?>">
                                    <input type="hidden" name="run_id" value="<?php echo (int)$run_id; ?>">
                                    <?php if ($sent_cnt > 0): ?><input type="hidden" name="force_resend" value="1"><?php endif; ?>
                                    <?php echo function_exists('csrf_field') ? csrf_field() : ''; ?>
                                    <button type="submit" class="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-green-600/80 hover:bg-green-500 text-white text-sm min-h-[48px]" title="ส่งสลิปทาง LINE"><i class="fab fa-line sm:mr-1"></i><span class="hidden sm:inline"><?php echo $sent_cnt > 0 ? 'ส่งซ้ำ' : 'ส่ง LINE'; ?></span></button>
                                </form>
                                <?php if ((int)($s['active_tokens'] ?? 0) > 0): ?>
                                <form method="post" class="inline" onsubmit="return confirm('ยกเลิกลิงก์ดาวน์โหลดทั้งหมดของสลิปนี้? ลิงก์ที่ส่งไปก่อนหน้านี้จะใช้งานไม่ได้อีก');">
                                    <input type="hidden" name="action" value="revoke_slip_tokens">
                                    <input type="hidden" name="slip_id" value="<?php echo (int)$s['id']; ?>">
                                    <input type="hidden" name="run_id" value="<?php echo (int)$run_id; ?>">
                                    <?php echo function_exists('csrf_field') ? csrf_field() : ''; ?>
                                    <button type="submit" class="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-rose-600/70 hover:bg-rose-500 text-white text-sm min-h-[48px]" title="ยกเลิกลิงก์ดาวน์โหลด"><i class="fas fa-ban"></i></button>
                                </form>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
