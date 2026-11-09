<?php
if (!defined('BASE_PATH')) {http_response_code(403);exit;}
/** Employee payment date cell; shares the run form CSRF and access guard. */
if (!isset($s, $run, $run_id)) return;
?>
                        <td class="px-6 py-4 text-white/90 cell-full payroll-payment-date-cell" data-label="วันที่จ่าย">
                            <div class="whitespace-nowrap"><?php echo htmlspecialchars(payroll_payment_date_label($s['payment_date'])); ?></div>
                            <div class="text-xs text-white/70 mt-1"><?php echo $s['payment_date_override'] ? 'กำหนดรายบุคคล' : 'ตามรอบจ่าย'; ?></div>
                            <?php if (!empty($s['payment_date_reason'])): ?><p class="mt-1 text-xs text-white/70"><?php echo htmlspecialchars($s['payment_date_changed_at'] . ' · ' . $s['payment_date_reason']); ?></p><?php endif; ?>
                            <?php if (\TpCommon\Hr\PayrollPaymentDate::editable((string)$run['status'])): ?>
                            <details class="mt-2">
                                <summary class="min-h-[48px] flex items-center cursor-pointer text-sky-200 focus-visible:outline focus-visible:outline-2">แก้ไขวันที่จ่าย</summary>
                                <form method="post" class="space-y-2 w-full min-w-0" onsubmit="this.querySelector('button[type=submit]').disabled=true;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="save_employee_payment_date">
                                    <input type="hidden" name="run_id" value="<?php echo (int)$run_id; ?>">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$s['user_id']; ?>">
                                    <input type="hidden" name="payment_date_revision" value="<?php echo (int)$s['payment_date_revision']; ?>">
                                    <label class="block text-sm" for="payment-date-<?php echo (int)$s['id']; ?>">วันที่จ่ายเงินเดือน</label>
                                    <input type="date" id="payment-date-<?php echo (int)$s['id']; ?>" name="payment_date" value="<?php echo htmlspecialchars($s['payment_date']); ?>" required class="w-full h-[52px] rounded-xl bg-slate-900 border border-white/20 px-3 text-white focus:ring-2 focus:ring-violet-500">
                                    <label class="payroll-run-date-toggle flex items-center gap-2 min-h-[48px] text-sm"><input type="checkbox" name="use_run_date" value="1"> กลับไปใช้วันที่ของรอบจ่าย</label>
                                    <label class="block text-sm" for="payment-date-reason-<?php echo (int)$s['id']; ?>">เหตุผลการแก้ไข</label>
                                    <textarea id="payment-date-reason-<?php echo (int)$s['id']; ?>" name="payment_date_reason" required maxlength="1000" rows="2" class="w-full min-h-[52px] rounded-xl bg-slate-900 border border-white/20 px-3 py-2 text-white focus:ring-2 focus:ring-violet-500"></textarea>
                                    <button type="submit" class="block min-h-[48px] px-4 rounded-xl bg-violet-600 text-white whitespace-nowrap focus:ring-2 focus:ring-violet-400">บันทึกวันที่จ่าย</button>
                                </form>
                            </details>
                            <?php endif; ?>
                        </td>
