<?php
if (!defined('BASE_PATH')) {http_response_code(403);exit;}
/**
 * Payroll Salary Setup Form View
 * Variables available: $setup_user, $salary_setup, $user_id_setup, $item_presets, $action
 */

$decoded_allowance = [];
$decoded_income = [];
$decoded_deduction = [];
if ($salary_setup) {
    if (!empty($salary_setup['allowance_json'])) { $decoded_allowance = json_decode($salary_setup['allowance_json'], true) ?: []; }
    if (!empty($salary_setup['income_other_json'])) { $decoded_income = json_decode($salary_setup['income_other_json'], true) ?: []; }
    if (!empty($salary_setup['deduction_other_json'])) { $decoded_deduction = json_decode($salary_setup['deduction_other_json'], true) ?: []; }
}
$bonus_val = $salary_setup ? (float)($salary_setup['bonus_fixed'] ?? 0) : 0;
$ss_cap_now = function_exists('payroll_ss_max_contribution') ? (int)payroll_ss_max_contribution(date('Y-m-01')) : 875;
$ss_preview = 0;
$ss_manual = !empty($salary_setup['social_security_manual']);
$ss_display = $ss_manual && $salary_setup
    ? (float)($salary_setup['social_security'] ?? 0)
    : 0;
if (!empty($salary_setup_form_post)) {
    $ss_manual = !empty($salary_setup_form_post['social_security_manual']);
    $ss_display = max(0, (float)($salary_setup_form_post['social_security'] ?? 0));
}
$ss_start_date = $setup_user['social_security_start_date'] ?? null;
$tax_start_date = $setup_user['tax_withholding_start_date'] ?? null;
$hi_start_date = $setup_user['health_insurance_start_date'] ?? null;
$gi_start_date = $setup_user['group_insurance_start_date'] ?? null;
$tax_enabled = function_exists('payroll_is_tax_enabled') ? payroll_is_tax_enabled() : true;
$hi_enabled = function_exists('payroll_is_health_insurance_enabled') ? payroll_is_health_insurance_enabled() : false;
$gi_enabled = function_exists('payroll_is_group_insurance_enabled') ? payroll_is_group_insurance_enabled() : true;
$profile_month_first = (!empty($for_month) && preg_match('/^\d{4}-\d{2}$/', $for_month))
    ? $for_month . '-01'
    : (($salary_setup && !empty($salary_setup['effective_from'])) ? (string)$salary_setup['effective_from'] : date('Y-m-01'));
$profile_base = (function_exists('payroll_resolve_profile_base_salary') && $user_id_setup > 0)
    ? payroll_resolve_profile_base_salary(getDB(), $user_id_setup, $profile_month_first)
    : null;
$profile_base_owned = $profile_base !== null && $profile_base > 0;
$base_field_value = $profile_base_owned ? $profile_base : ($salary_setup ? (float)($salary_setup['base_salary'] ?? 0) : 0);
if ($salary_setup && function_exists('payroll_ss_wage_base_from_setup')) {
    $ss_eff = $salary_setup['effective_from'] ?? date('Y-m-01');
    if (payroll_ss_applies_for_month($ss_start_date, $ss_eff)) {
        $ss_preview = payroll_calc_social_security(
            payroll_ss_wage_base_from_setup($salary_setup),
            !empty($salary_setup['ss_opt_out']),
            $ss_eff
        );
        if (!$ss_manual) {
            $ss_display = $ss_preview;
        }
    }
}

function render_preset_select($name, $presets, $selected_label = '', $category = 'income') {
    $html = '<select name="' . $name . '" class="preset-select w-full min-w-0 rounded-[10px] text-white transition-shadow" style="border:1px solid rgba(255,255,255,0.18);background:rgba(255,255,255,0.04);padding:14px 16px;font-size:16px;outline:none;" onfocus="this.style.borderColor=\'rgba(139,92,246,0.5)\';this.style.boxShadow=\'0 0 0 3px rgba(139,92,246,0.15)\'" onblur="this.style.borderColor=\'rgba(255,255,255,0.18)\';this.style.boxShadow=\'none\'" onchange="presetSelectChanged(this)">';
    $html .= '<option value="">-- เลือกรายการ --</option>';
    $found = false;
    foreach ($presets as $p) {
        $sel = ($selected_label !== '' && $p['label'] === $selected_label) ? ' selected' : '';
        if ($sel) $found = true;
        $amt = $p['default_amount'] ? ' (฿' . number_format((float)$p['default_amount'], 0) . ')' : '';
        $ssInclude = (int)($p['ss_include'] ?? payroll_default_ss_include_for_label($category, (string)$p['label']));
        $html .= '<option value="' . htmlspecialchars($p['label']) . '" data-default-amount="' . ((float)($p['default_amount'] ?? 0)) . '" data-ss-include="' . $ssInclude . '"' . $sel . '>' . htmlspecialchars($p['label']) . $amt . '</option>';
    }
    if ($selected_label !== '' && !$found) {
        $ssInclude = payroll_default_ss_include_for_label($category, $selected_label);
        $html .= '<option value="' . htmlspecialchars($selected_label) . '" data-ss-include="' . $ssInclude . '" selected>' . htmlspecialchars($selected_label) . '</option>';
    }
    $html .= '<option value="__custom__">+ พิมพ์เอง...</option>';
    $html .= '</select>';
    return $html;
}

function render_ss_include_toggle($name, $item, $category) {
    $checked = payroll_item_counts_for_ss(is_array($item) ? $item : [], $category);
    $html = '<label class="flex-shrink-0 flex flex-col items-center gap-1 cursor-pointer select-none" title="รวมในฐานเงินสมทบประกันสังคม (รายได้ที่จ่ายเท่ากันทุกเดือน)">';
    $html .= '<span class="text-[10px] text-white/45 whitespace-nowrap">ฐาน สส.</span>';
    $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . ($checked ? '1' : '0') . '" class="ss-include-hidden">';
    $html .= '<input type="checkbox" class="ss-include-cb w-4 h-4 rounded border-white/30 bg-white/5 text-violet-500 focus:ring-violet-500/30"' . ($checked ? ' checked' : '') . ' onchange="ssIncludeChanged(this)">';
    $html .= '</label>';
    return $html;
}
?>
    <!-- ฟอร์มกำหนดเงินเดือน -->
    <div class="max-w-3xl mx-auto space-y-6">
        <!-- หัวการ์ด: ข้อมูลพนักงาน -->
        <div class="rounded-2xl border border-white/10 bg-gradient-to-br from-white/5 to-white/[0.02] overflow-hidden shadow-lg shadow-black/10">
            <div class="px-6 py-5 flex items-center gap-4">
                <a href="payroll.php?action=employees" class="btn-back flex-shrink-0 w-11 h-11 rounded-xl bg-white/10 hover:bg-white/15 text-white/80 hover:text-white flex items-center justify-center transition-colors" title="กลับรายการพนักงาน"><i class="fas fa-arrow-left"></i></a>
                <div class="flex-1 min-w-0">
                    <h1 class="text-xl font-semibold text-white tracking-tight"><?php echo htmlspecialchars($setup_user['first_name_th'] . ' ' . $setup_user['last_name_th']); ?></h1>
                    <p class="mt-2.5 text-sm text-white/50 leading-relaxed">รหัส <span class="font-mono text-violet-300"><?php echo htmlspecialchars(payroll_format_employee_code($setup_user['employee_code'] ?? '')); ?></span> · <?php echo htmlspecialchars($setup_user['department'] ?? '-'); ?> / <?php echo htmlspecialchars($setup_user['position'] ?? '-'); ?></p>
                </div>
            </div>
        </div>

        <?php if (!empty($for_month)): ?>
        <div class="rounded-xl bg-blue-500/20 border border-blue-500/30 p-4 text-blue-200 text-sm flex items-center gap-3">
            <i class="fas fa-info-circle text-blue-400"></i>
            <span>กำลังแก้ไข<strong>เฉพาะเดือน <?php echo date('F Y', strtotime($for_month . '-01')); ?></strong> — ค่าคอม/ค่าบริการ/รายได้อื่นจะมีผลแค่เดือนนี้ ไม่กระทบเดือนอื่น · ระบบจะอัปเดตสลิปของเดือนนี้โดยอัตโนมัติ</span>
        </div>
        <?php
        $target_month_first = $for_month . '-01';
        $setup_hire_date = !empty($setup_user['hire_date']) ? substr((string)$setup_user['hire_date'], 0, 10) : null;
        $salary_profile = (function_exists('payroll_get_user_salary_profile') && $user_id_setup > 0)
            ? payroll_get_user_salary_profile(getDB(), $user_id_setup)
            : null;
        $on_probation = function_exists('payroll_is_on_probation_for_month')
            ? payroll_is_on_probation_for_month($salary_profile, $target_month_first)
            : false;
        if ($profile_base !== null && $profile_base > 0):
        ?>
        <div class="rounded-xl bg-emerald-500/15 border border-emerald-500/30 p-4 text-emerald-100 text-sm flex items-start gap-3">
            <i class="fas fa-id-badge text-emerald-300 mt-0.5"></i>
            <span>ระบบดึง<strong>ฐานเงินเดือน</strong>จากโปรไฟล์ HR อัตโนมัติ:
                <?php if ($on_probation): ?>
                    ช่วงทดลองงาน <strong>฿<?php echo number_format($profile_base, 0); ?></strong> (probation_salary)
                <?php else: ?>
                    หลังผ่านโปร <strong>฿<?php echo number_format($profile_base, 0); ?></strong> (salary)
                <?php endif; ?>
                · รายได้อื่น/โบนัสยังมาจาก setup นี้</span>
        </div>
        <?php endif; ?>
        <?php
        if ($setup_hire_date && function_exists('payroll_is_first_hire_payroll_month') && payroll_is_first_hire_payroll_month($setup_hire_date, $target_month_first)):
            $first_period = payroll_effective_period_bounds($target_month_first, payroll_get_default_pay_day(), $setup_hire_date);
            $daily_divisor = payroll_first_hire_daily_divisor();
            $period_label = payroll_format_period_range_thai($target_month_first, payroll_get_default_pay_day(), $setup_hire_date);
            $base_preview = $profile_base ?? ($salary_setup ? (float)($salary_setup['base_salary'] ?? 0) : 0);
            $hire_on_first = function_exists('payroll_is_hire_on_month_first_day') && payroll_is_hire_on_month_first_day($setup_hire_date, $target_month_first);
        ?>
        <div class="rounded-xl bg-violet-500/15 border border-violet-500/30 p-4 text-violet-100 text-sm flex items-start gap-3">
            <i class="fas fa-user-clock text-violet-300 mt-0.5"></i>
            <?php if ($hire_on_first): ?>
            <span>พนักงานเริ่มงานวันที่ <strong><?php echo date('j/n/Y', strtotime($setup_hire_date)); ?></strong> (วันที่ 1 ของเดือน) — เดือนแรกจ่าย<strong>เต็มฐาน ฿<?php echo number_format($base_preview, 0); ?></strong> ช่วง <strong><?php echo htmlspecialchars($period_label); ?></strong> · โบนัส/รายได้พิเศษจ่ายเต็ม</span>
            <?php else:
            $payable_days = payroll_first_hire_payable_days(getDB(), $user_id_setup, $first_period, $setup_hire_date);
            $daily_rate = $daily_divisor > 0 ? $base_preview / $daily_divisor : 0;
            ?>
            <span>พนักงานเริ่มงานวันที่ <strong><?php echo date('j/n/Y', strtotime($setup_hire_date)); ?></strong> — เดือนแรกคิด <strong><?php echo (int)$payable_days; ?> วัน × ฿<?php echo number_format($daily_rate, 0); ?></strong> (ฐาน ÷ <?php echo (int)$daily_divisor; ?>, หักวันหยุดประจำทั้งเดือน) ช่วง <strong><?php echo htmlspecialchars($period_label); ?></strong> · โบนัส/รายได้พิเศษจ่ายเต็ม · รอบถัดไปใช้รอบจ่ายปกติ</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <form id="salary_setup_form" method="post" class="space-y-6">
            <input type="hidden" name="action" value="save_salary_setup">
            <input type="hidden" name="user_id" value="<?php echo $user_id_setup; ?>">
            <?php if (!empty($for_month) && preg_match('/^\d{4}-\d{2}$/', $for_month)): ?>
            <input type="hidden" name="scope_month" value="<?php echo htmlspecialchars($for_month); ?>">
            <input type="hidden" name="run_id" value="<?php echo (int)($run_id ?? 0); ?>">
            <?php endif; ?>
            <?php echo function_exists('csrf_field') ? csrf_field() : ''; ?>

            <style>
                .salary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
                @media (max-width: 639px) { .salary-grid { grid-template-columns: 1fr; } }
                .salary-field label { display: block; font-size: 13px; font-weight: 500; color: rgba(255,255,255,0.6); margin-bottom: 10px; letter-spacing: 0.01em; }
                .salary-field label span { color: rgba(255,255,255,0.35); font-weight: 400; }
                .salary-input { display: block; width: 100%; border-radius: 10px; border: 1px solid rgba(255,255,255,0.18); background: rgba(255,255,255,0.04); color: #fff; padding: 14px 16px; font-size: 16px; line-height: 1.4; transition: border-color 0.2s, box-shadow 0.2s; outline: none; }
                .salary-input:focus { border-color: rgba(139,92,246,0.5); box-shadow: 0 0 0 3px rgba(139,92,246,0.15); }
                .salary-input-num { text-align: right; font-variant-numeric: tabular-nums; }
                .salary-input-num::placeholder { text-align: right; color: rgba(255,255,255,0.25); }
                .salary-input-date { font-variant-numeric: tabular-nums; }
                .salary-input-date::-webkit-calendar-picker-indicator { opacity: 0.5; cursor: pointer; }
            </style>
            <!-- ข้อมูลเงินเดือนหลัก -->
            <div class="rounded-2xl border border-white/10 bg-white/[0.03] overflow-hidden">
                <div class="px-6 py-4 border-b border-white/10">
                    <h2 class="text-base font-semibold text-white flex items-center gap-2"><span class="w-8 h-8 rounded-lg bg-violet-500/20 flex items-center justify-center text-violet-400"><i class="fas fa-wallet text-sm"></i></span>ข้อมูลเงินเดือนหลัก</h2>
                </div>
                <div class="p-6 space-y-6">
                    <div class="salary-grid">
                        <div class="salary-field">
                            <label>ฐานเงินเดือน <span>(บาท · จากโปรไฟล์ HR)</span></label>
                            <input type="number" step="0.01" value="<?php echo (float)$base_field_value; ?>" class="salary-input salary-input-num <?php echo $profile_base_owned ? 'opacity-80 cursor-not-allowed' : ''; ?>" placeholder="0.00" <?php echo $profile_base_owned ? 'readonly aria-readonly="true"' : 'name="base_salary" required'; ?>>
                            <?php if ($profile_base_owned): ?>
                            <input type="hidden" name="base_salary" value="<?php echo (float)$profile_base; ?>">
                            <p class="mt-2 text-xs text-emerald-300/80"><i class="fas fa-shield-alt mr-1"></i>อ่านจาก HR อัตโนมัติและป้องกันการแก้ทับใน Payroll · หากต้องการเปลี่ยนให้แก้ที่โปรไฟล์พนักงานใน HR</p>
                            <?php else: ?>
                            <p class="mt-2 text-xs text-amber-300/80"><i class="fas fa-exclamation-triangle mr-1"></i>ยังไม่พบฐานเงินเดือนในโปรไฟล์ HR จึงใช้ค่า fallback ของ Payroll ชั่วคราว</p>
                            <?php endif; ?>
                        </div>
                        <div class="salary-field">
                            <label>โบนัสคงที่ <span>(บาท/เดือน)</span></label>
                            <input type="number" step="0.01" name="bonus_fixed" value="<?php echo $bonus_val; ?>" class="salary-input salary-input-num" placeholder="0">
                        </div>
                    </div>
                    <div class="salary-grid">
                        <div class="salary-field">
                            <label>กองทุนสำรองเลี้ยงชีพ <span>(บาท/เดือน)</span></label>
                            <input type="number" step="0.01" name="provident_fund" value="<?php echo $salary_setup ? (float)$salary_setup['provident_fund'] : '0'; ?>" class="salary-input salary-input-num">
                        </div>
                        <?php $ss_enabled = function_exists('payroll_is_ss_enabled') ? payroll_is_ss_enabled() : false; ?>
                        <div class="salary-field">
                            <label class="flex flex-wrap items-center gap-2">ประกันสังคม <span>(บาท/เดือน)</span>
                                <?php if (!$ss_enabled): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] bg-amber-500/20 text-amber-300 border border-amber-400/30">ปิดใช้งาน</span>
                                <?php elseif ($ss_manual): ?>
                                    <span id="ss_mode_badge" class="px-2 py-0.5 rounded-full text-[10px] bg-violet-500/20 text-violet-300 border border-violet-400/30">กำหนดเอง</span>
                                <?php else: ?>
                                    <span id="ss_mode_badge" class="px-2 py-0.5 rounded-full text-[10px] bg-sky-500/20 text-sky-300 border border-sky-400/30">คำนวณอัตโนมัติ</span>
                                <?php endif; ?>
                            </label>
                            <input type="hidden" name="social_security_manual" id="social_security_manual_hidden" value="<?php echo $ss_manual ? '1' : '0'; ?>">
                            <input type="number" step="0.01" min="0" name="social_security" id="social_security_input"
                                   value="<?php echo number_format($ss_display, 2, '.', ''); ?>"
                                   class="salary-input salary-input-num"
                                   <?php echo $ss_manual ? '' : 'readonly style="opacity:0.85;cursor:not-allowed"'; ?>
                                   title="ค่าเริ่มต้นคำนวณอัตโนมัติ 5% ของฐานค่าจ้าง — ติ๊ก 'กำหนดยอดเอง' เพื่อแก้ไข">
                            <label class="mt-2 flex items-center gap-2 text-xs text-white/70 cursor-pointer select-none">
                                <input type="checkbox" id="social_security_manual_cb" class="w-4 h-4 rounded border-white/30 bg-white/5 text-violet-500 focus:ring-violet-500/30" <?php echo $ss_manual ? ' checked' : ''; ?> onchange="toggleSocialSecurityManual(this.checked)">
                                กำหนดยอดเอง (ใช้เมื่อยอดคำนวณอัตโนมัติไม่ถูกต้องในเดือนนั้น)
                            </label>
                            <?php if (!$ss_enabled): ?>
                            <p class="mt-1 text-xs text-amber-300/70"><i class="fas fa-info-circle mr-1"></i>บริษัทยังไม่เข้าประกันสังคม — ระบบคำนวณเป็น 0 อัตโนมัติ</p>
                            <?php else: ?>
                            <p class="mt-1 text-xs text-sky-300/70"><i class="fas fa-info-circle mr-1"></i>คำนวณจากฐานค่าจ้างรายเดือน (ฐานเงินเดือน + รายได้ประจำ) 5% สูงสุด <?php echo $ss_cap_now; ?> บ./ด. — เริ่มหักเมื่อถึงเดือน<?php echo !empty($ss_start_date) ? ' ' . htmlspecialchars(substr($ss_start_date, 0, 7)) : 'ที่ระบุในโปรไฟล์ (วันเริ่มหักประกันสังคม)'; ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- ประกันกลุ่ม (Group Insurance) -->
                    <div class="pt-4 mt-4 border-t border-white/10">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="w-7 h-7 rounded-lg bg-cyan-500/20 flex items-center justify-center text-cyan-400"><i class="fas fa-shield-alt text-xs"></i></span>
                            <h3 class="text-sm font-semibold text-white">ประกันกลุ่ม</h3>
                            <span class="text-xs text-white/50">(บริษัทออกให้บางส่วน)</span>
                            <?php if (!$gi_enabled): ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] bg-amber-500/20 text-amber-300 border border-amber-400/30">ปิดใช้งานที่ระดับบริษัท</span>
                            <?php endif; ?>
                        </div>
                        <?php
                            $gi_total_val = ($salary_setup && isset($salary_setup['group_insurance_total_monthly']))
                                ? (float)$salary_setup['group_insurance_total_monthly'] : 0;
                            $gi_emp_pct_val = ($salary_setup && isset($salary_setup['group_insurance_employer_pct']))
                                ? (float)$salary_setup['group_insurance_employer_pct'] : 50;
                        ?>
                        <div class="salary-grid">
                            <div class="salary-field">
                                <label>เบี้ยประกันกลุ่มเต็มจำนวน <span>(บาท/เดือน)</span></label>
                                <input type="number" step="0.01" min="0" name="group_insurance_total_monthly"
                                       value="<?php echo $gi_total_val; ?>"
                                       class="salary-input salary-input-num" placeholder="0"
                                       oninput="updateGroupInsurancePreview()">
                            </div>
                            <div class="salary-field">
                                <label>บริษัทออกให้ <span>(%)</span></label>
                                <input type="number" step="0.01" min="0" max="100" name="group_insurance_employer_pct"
                                       value="<?php echo $gi_emp_pct_val; ?>"
                                       class="salary-input salary-input-num" placeholder="50"
                                       oninput="updateGroupInsurancePreview()">
                            </div>
                        </div>
                        <div class="mt-3 p-3 rounded-xl bg-white/5 border border-white/10 text-sm">
                            <div class="flex flex-wrap gap-x-6 gap-y-1 text-white/80">
                                <div>บริษัทจ่าย: <span id="giEmployerPreview" class="text-emerald-400 font-semibold">0.00</span> บาท</div>
                                <div>พนักงานจ่าย: <span id="giEmployeePreview" class="text-amber-300 font-semibold">0.00</span> บาท/เดือน</div>
                            </div>
                        </div>
                        <script>
                        function updateGroupInsurancePreview() {
                            var tot = parseFloat(document.querySelector('input[name="group_insurance_total_monthly"]').value) || 0;
                            var empPct = parseFloat(document.querySelector('input[name="group_insurance_employer_pct"]').value);
                            if (isNaN(empPct)) empPct = 50;
                            if (empPct < 0) empPct = 0; if (empPct > 100) empPct = 100;
                            var giOptOut = document.querySelector('input[name="gi_opt_out"]') && document.querySelector('input[name="gi_opt_out"]').checked;
                            var giEnabled = <?php echo $gi_enabled ? 'true' : 'false'; ?>;
                            if (!giEnabled || giOptOut) { tot = 0; }
                            var employer = tot * empPct / 100;
                            var employee = tot - employer;
                            document.getElementById('giEmployerPreview').textContent = employer.toFixed(2);
                            document.getElementById('giEmployeePreview').textContent = employee.toFixed(2);
                        }
                        document.addEventListener('DOMContentLoaded', updateGroupInsurancePreview);
                        </script>
                    </div>
                    <!-- ประกันสุขภาพ -->
                    <div class="pt-4 mt-4 border-t border-white/10">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="w-7 h-7 rounded-lg bg-rose-500/20 flex items-center justify-center text-rose-400"><i class="fas fa-heartbeat text-xs"></i></span>
                            <h3 class="text-sm font-semibold text-white">ประกันสุขภาพ</h3>
                            <?php if (!$hi_enabled): ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] bg-amber-500/20 text-amber-300 border border-amber-400/30">ปิดใช้งานที่ระดับบริษัท</span>
                            <?php endif; ?>
                        </div>
                        <?php
                            $hi_total_val = ($salary_setup && isset($salary_setup['health_insurance_total_monthly']))
                                ? (float)$salary_setup['health_insurance_total_monthly'] : 0;
                            $hi_emp_pct_val = ($salary_setup && isset($salary_setup['health_insurance_employer_pct']))
                                ? (float)$salary_setup['health_insurance_employer_pct'] : 50;
                            $hi_opt_out_val = !empty($salary_setup['hi_opt_out']);
                        ?>
                        <div class="salary-grid">
                            <div class="salary-field">
                                <label>เบี้ยประกันสุขภาพเต็มจำนวน <span>(บาท/เดือน)</span></label>
                                <input type="number" step="0.01" min="0" name="health_insurance_total_monthly"
                                       value="<?php echo $hi_total_val; ?>"
                                       class="salary-input salary-input-num" placeholder="0"
                                       oninput="updateHealthInsurancePreview()">
                            </div>
                            <div class="salary-field">
                                <label>บริษัทออกให้ <span>(%)</span></label>
                                <input type="number" step="0.01" min="0" max="100" name="health_insurance_employer_pct"
                                       value="<?php echo $hi_emp_pct_val; ?>"
                                       class="salary-input salary-input-num" placeholder="50"
                                       oninput="updateHealthInsurancePreview()">
                            </div>
                        </div>
                        <div class="mt-3 p-3 rounded-xl bg-white/5 border border-white/10 text-sm">
                            <div class="flex flex-wrap gap-x-6 gap-y-1 text-white/80">
                                <div>บริษัทจ่าย: <span id="hiEmployerPreview" class="text-emerald-400 font-semibold">0.00</span> บาท</div>
                                <div>พนักงานจ่าย: <span id="hiEmployeePreview" class="text-amber-300 font-semibold">0.00</span> บาท/เดือน</div>
                            </div>
                        </div>
                        <div id="hiStartDateWarning" class="hidden mt-3 p-3 rounded-xl bg-amber-500/10 border border-amber-400/30 text-xs text-amber-200">
                            <i class="fas fa-exclamation-triangle mr-1"></i>ตั้งค่าเบี้ยประกันสุขภาพแล้ว แต่ยังไม่ระบุวันเริ่มหัก — ระบบจะไม่หักจนกว่าจะกำหนดวันเริ่มหัก
                        </div>
                        <label class="mt-3 flex items-start gap-3 p-3 rounded-lg bg-white/5 border border-white/10 cursor-pointer hover:bg-white/10 transition">
                            <input type="checkbox" name="hi_opt_out" value="1" <?php echo $hi_opt_out_val ? 'checked' : ''; ?> class="mt-0.5 w-4 h-4 accent-rose-500">
                            <div class="flex-1">
                                <div class="text-sm text-white font-medium">ไม่หักประกันสุขภาพสำหรับพนักงานคนนี้</div>
                                <div class="text-xs text-white/50 mt-0.5">ใช้กรณีพนักงานมีประกันสุขภาพจากที่อื่นแล้ว</div>
                            </div>
                        </label>
                        <script>
                        function updateHealthInsurancePreview() {
                            var tot = parseFloat(document.querySelector('input[name="health_insurance_total_monthly"]').value) || 0;
                            var empPct = parseFloat(document.querySelector('input[name="health_insurance_employer_pct"]').value);
                            if (isNaN(empPct)) empPct = 50;
                            if (empPct < 0) empPct = 0; if (empPct > 100) empPct = 100;
                            var hiOptOut = document.querySelector('input[name="hi_opt_out"]') && document.querySelector('input[name="hi_opt_out"]').checked;
                            var hiEnabled = <?php echo $hi_enabled ? 'true' : 'false'; ?>;
                            if (!hiEnabled || hiOptOut) { tot = 0; }
                            var employer = tot * empPct / 100;
                            var employee = tot - employer;
                            document.getElementById('hiEmployerPreview').textContent = employer.toFixed(2);
                            document.getElementById('hiEmployeePreview').textContent = employee.toFixed(2);
                            var warn = document.getElementById('hiStartDateWarning');
                            var startDate = document.querySelector('input[name="health_insurance_start_date"]');
                            if (warn && startDate) {
                                var showWarn = hiEnabled && !hiOptOut && tot > 0 && !startDate.value;
                                warn.classList.toggle('hidden', !showWarn);
                            }
                        }
                        document.addEventListener('DOMContentLoaded', updateHealthInsurancePreview);
                        </script>
                    </div>
                    <!-- วันเริ่มหักสวัสดิการ / ภาษี -->
                    <div class="pt-4 mt-4 border-t border-white/10">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="w-7 h-7 rounded-lg bg-indigo-500/20 flex items-center justify-center text-indigo-400"><i class="fas fa-calendar-alt text-xs"></i></span>
                            <h3 class="text-sm font-semibold text-white">วันเริ่มหัก (รายบุคคล)</h3>
                        </div>
                        <p class="text-xs text-white/50 mb-4 leading-relaxed">
                            กำหนดเดือนที่เริ่มหักแต่ละรายการ — ว่างไว้หมายถึง: ภาษี/ประกันกลุ่ม = หักทันที, ประกันสังคม/ประกันสุขภาพ = ยังไม่หัก
                        </p>
                        <div class="salary-grid">
                            <div class="salary-field">
                                <label>วันเริ่มหักประกันสังคม</label>
                                <input type="date" name="social_security_start_date" value="<?php echo htmlspecialchars($ss_start_date ?? ''); ?>" class="salary-input salary-input-date">
                            </div>
                            <div class="salary-field">
                                <label>วันเริ่มหักภาษี ณ ที่จ่าย
                                    <?php if (!$tax_enabled): ?>
                                        <span class="ml-1 text-amber-300 text-[10px]">(ปิดที่บริษัท)</span>
                                    <?php endif; ?>
                                </label>
                                <input type="date" name="tax_withholding_start_date" value="<?php echo htmlspecialchars($tax_start_date ?? ''); ?>" class="salary-input salary-input-date">
                            </div>
                            <div class="salary-field">
                                <label>วันเริ่มหักประกันกลุ่ม
                                    <?php if (!$gi_enabled): ?>
                                        <span class="ml-1 text-amber-300 text-[10px]">(ปิดที่บริษัท)</span>
                                    <?php endif; ?>
                                </label>
                                <input type="date" name="group_insurance_start_date" value="<?php echo htmlspecialchars($gi_start_date ?? ''); ?>" class="salary-input salary-input-date">
                            </div>
                            <div class="salary-field">
                                <label>วันเริ่มหักประกันสุขภาพ
                                    <?php if (!$hi_enabled): ?>
                                        <span class="ml-1 text-amber-300 text-[10px]">(ปิดที่บริษัท)</span>
                                    <?php endif; ?>
                                </label>
                                <input type="date" name="health_insurance_start_date" value="<?php echo htmlspecialchars($hi_start_date ?? ''); ?>" class="salary-input salary-input-date">
                            </div>
                        </div>
                    </div>
                    <!-- การหักภาษี ณ ที่จ่าย -->
                    <div class="pt-4 mt-4 border-t border-white/10">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="w-7 h-7 rounded-lg bg-violet-500/20 flex items-center justify-center text-violet-400"><i class="fas fa-file-invoice-dollar text-xs"></i></span>
                            <h3 class="text-sm font-semibold text-white">ภาษี ณ ที่จ่าย</h3>
                            <?php if (!$tax_enabled): ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] bg-amber-500/20 text-amber-300 border border-amber-400/30">ปิดใช้งานที่ระดับบริษัท</span>
                            <?php endif; ?>
                        </div>
                        <?php
                        $tax_opt_out_val = !empty($salary_setup['tax_opt_out']);
                        $gi_opt_out_val = !empty($salary_setup['gi_opt_out']);
                        ?>
                        <div class="space-y-3">
                            <label class="flex items-start gap-3 p-3 rounded-lg bg-white/5 border border-white/10 cursor-pointer hover:bg-white/10 transition">
                                <input type="checkbox" name="tax_opt_out" value="1" <?php echo $tax_opt_out_val ? 'checked' : ''; ?> class="mt-0.5 w-4 h-4 accent-violet-500">
                                <div class="flex-1">
                                    <div class="text-sm text-white font-medium">ไม่หักภาษี ณ ที่จ่ายสำหรับพนักงานคนนี้</div>
                                    <div class="text-xs text-white/50 mt-0.5">ใช้กรณีพิเศษที่ได้รับอนุมัติไม่หักภาษีจากเงินเดือน</div>
                                </div>
                            </label>
                            <label class="flex items-start gap-3 p-3 rounded-lg bg-white/5 border border-white/10 cursor-pointer hover:bg-white/10 transition">
                                <input type="checkbox" name="gi_opt_out" value="1" <?php echo $gi_opt_out_val ? 'checked' : ''; ?> class="mt-0.5 w-4 h-4 accent-cyan-500">
                                <div class="flex-1">
                                    <div class="text-sm text-white font-medium">ไม่หักประกันกลุ่มสำหรับพนักงานคนนี้</div>
                                    <div class="text-xs text-white/50 mt-0.5">ใช้กรณีพนักงานไม่เข้าร่วมแผนประกันกลุ่ม</div>
                                </div>
                            </label>
                        </div>
                    </div>
                    <!-- พนักงานหลายนายจ้าง (Multi-Employer) -->
                    <div class="pt-4 mt-4 border-t border-white/10">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="w-7 h-7 rounded-lg bg-purple-500/20 flex items-center justify-center text-purple-400"><i class="fas fa-briefcase text-xs"></i></span>
                            <h3 class="text-sm font-semibold text-white">พนักงานทำงานหลายบริษัท</h3>
                            <span class="text-xs text-white/50">(Multi-Employer)</span>
                        </div>
                        <p class="text-xs text-white/50 mb-3 leading-relaxed">
                            <i class="fas fa-info-circle mr-1 text-amber-400"></i>
                            กรณีพนักงานมีงานประจำอยู่บริษัทอื่นด้วย — ตั้งค่าเพื่อไม่ให้หักประกันสังคมซ้ำ และคำนวณภาษีเผื่อรายได้รวมปลายปี
                        </p>
                        <?php
                        $has_other = !empty($setup_user['has_other_employer_income']);
                        $ss_opt_out_val = !empty($salary_setup['ss_opt_out']);
                        $extra_tax_val = ($salary_setup && isset($salary_setup['additional_tax_withholding'])) ? (float)$salary_setup['additional_tax_withholding'] : 0;
                        ?>
                        <div class="space-y-3">
                            <label class="flex items-start gap-3 p-3 rounded-lg bg-white/5 border border-white/10 cursor-pointer hover:bg-white/10 transition">
                                <input type="checkbox" name="has_other_employer_income" value="1" <?php echo $has_other ? 'checked' : ''; ?> class="mt-0.5 w-4 h-4 accent-purple-500">
                                <div class="flex-1">
                                    <div class="text-sm text-white font-medium">พนักงานมีรายได้จากนายจ้างอื่นด้วย</div>
                                    <div class="text-xs text-white/50 mt-0.5">ต้องแจ้งพนักงานให้ยื่น ภ.ง.ด.91 รวมรายได้ทั้งปี</div>
                                </div>
                            </label>
                            <label class="flex items-start gap-3 p-3 rounded-lg bg-white/5 border border-white/10 cursor-pointer hover:bg-white/10 transition">
                                <input type="checkbox" name="ss_opt_out" value="1" <?php echo $ss_opt_out_val ? 'checked' : ''; ?> class="mt-0.5 w-4 h-4 accent-amber-500">
                                <div class="flex-1">
                                    <div class="text-sm text-white font-medium">ไม่หักประกันสังคมสำหรับพนักงานคนนี้</div>
                                    <div class="text-xs text-white/50 mt-0.5">ใช้กรณีพนักงานส่งประกันสังคมผ่านบริษัทอื่นแล้ว (ประกันสังคมส่งได้ที่เดียว)</div>
                                </div>
                            </label>
                            <div class="salary-field" id="extraTaxField">
                                <label>หักภาษี ณ ที่จ่ายเพิ่มรายเดือน <span>(บาท/เดือน)</span></label>
                                <input type="number" step="0.01" min="0" name="additional_tax_withholding" value="<?php echo $extra_tax_val; ?>" class="salary-input salary-input-num" placeholder="0.00" <?php echo $tax_opt_out_val ? 'disabled' : ''; ?>>
                                <p class="mt-1 text-xs text-white/40"><i class="fas fa-info-circle mr-1"></i>ตามที่พนักงานร้องขอ (แบบ ล.ย.01) เผื่อส่วนต่างจากรายได้ที่อื่น เพื่อไม่ต้องจ่ายเพิ่มตอนยื่นภาษีปลายปี</p>
                            </div>
                        </div>
                    </div>
                    <div class="pt-4 border-t border-white/10">
                        <div class="salary-field" style="max-width: 280px;">
                            <?php if (!empty($for_month) && preg_match('/^\d{4}-\d{2}$/', $for_month)): ?>
                            <label>เดือนที่แก้ไข <span>(ล็อกเฉพาะเดือนนี้)</span></label>
                            <input type="month" value="<?php echo htmlspecialchars($for_month); ?>" class="salary-input salary-input-date opacity-70 cursor-not-allowed" readonly tabindex="-1">
                            <input type="hidden" name="effective_from" value="<?php echo htmlspecialchars($for_month); ?>">
                            <p class="mt-2 text-xs text-white/40"><i class="fas fa-lock mr-1"></i>บันทึกเป็น overlay เฉพาะเดือนนี้ (effective_to = สิ้นเดือน) — เดือนอื่นใช้ setup เดิม</p>
                            <?php else: ?>
                            <label>มีผลตั้งแต่เดือน <span>(เดือน/ปี)</span></label>
                            <input type="month" name="effective_from" value="<?php echo $salary_setup ? substr($salary_setup['effective_from'], 0, 7) : date('Y-m'); ?>" class="salary-input salary-input-date" required>
                            <p class="mt-2 text-xs text-white/40"><i class="fas fa-info-circle mr-1"></i>เงินเดือนจะมีผลกับรอบจ่ายตั้งแต่เดือนที่เลือกเป็นต้นไป</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- รายได้อื่นๆ / รายการหัก / เบี้ยเลี้ยง -->
            <div class="rounded-2xl border border-white/10 bg-white/[0.03] overflow-hidden">
                <div class="px-6 py-4 border-b border-white/10">
                    <h2 class="text-base font-semibold text-white flex items-center gap-2"><span class="w-8 h-8 rounded-lg bg-emerald-500/20 flex items-center justify-center text-emerald-400"><i class="fas fa-list-ul text-sm"></i></span>รายได้และรายการหัก <span class="text-xs font-normal text-white/40">(ตั้งค่าใน Payroll และ HR อ่านชุดเดียวกัน)</span></h2>
                </div>
                <div class="p-6 space-y-6">
                    <!-- รายได้อื่นๆ -->
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <label class="text-sm font-medium text-white/80 flex items-center gap-2"><i class="fas fa-plus-circle text-emerald-400/80 text-xs"></i>รายได้อื่นๆ</label>
                            <button type="button" onclick="openPresetManager('income')" class="text-xs font-medium text-violet-400 hover:text-violet-300 px-3 py-1.5 rounded-lg bg-violet-500/10 hover:bg-violet-500/20 transition-colors"><i class="fas fa-cog mr-1.5"></i>จัดการรายการ</button>
                        </div>
                        <p class="mb-3 text-xs text-white/45"><i class="fas fa-shield-alt mr-1"></i>ติ๊ก <strong class="text-white/60">ฐาน สส.</strong> เฉพาะรายได้ที่จ่ายเท่ากันทุกเดือน (เช่น ค่าตำแหน่ง ค่าเดินทาง) — ค่าคอม/เงินชดเชยไม่ติ๊ก</p>
                        <div id="income_other_rows" class="space-y-3">
                            <?php for ($i = 0; $i < max(1, count($decoded_income)); $i++): $item = $decoded_income[$i] ?? []; ?>
                            <div class="payroll-dyn-row flex gap-3 items-center p-4 rounded-xl bg-white/5 border border-white/5">
                                <?php echo render_preset_select('income_other_label[]', $item_presets['income'] ?? [], $item['label'] ?? '', 'income'); ?>
                                <input type="text" name="income_other_custom_label[]" placeholder="ชื่อรายการ" class="hidden flex-1 min-w-0 rounded-[10px] text-white custom-label-input" style="border:1px solid rgba(255,255,255,0.18);background:rgba(255,255,255,0.04);padding:14px 16px;font-size:16px;outline:none;">
                                <input type="number" step="0.01" name="income_other_amount[]" value="<?php echo isset($item['amount']) ? (float)$item['amount'] : ''; ?>" placeholder="0.00" class="salary-input salary-input-num" style="width:160px;flex-shrink:0;">
                                <?php echo render_ss_include_toggle('income_other_ss_include[]', $item, 'income'); ?>
                                <button type="button" class="payroll-remove-row flex-shrink-0 w-9 h-9 rounded-lg text-white/40 hover:text-rose-400 hover:bg-rose-500/10 flex items-center justify-center transition-colors" title="ลบ"><i class="fas fa-trash-alt text-xs"></i></button>
                            </div>
                            <?php endfor; ?>
                        </div>
                        <button type="button" class="payroll-add-row mt-3 text-sm font-medium text-emerald-400 hover:text-emerald-300 flex items-center gap-2" data-target="income_other_rows" data-category="income"><i class="fas fa-plus text-xs"></i> เพิ่มรายการ</button>
                    </div>

                    <!-- รายการหัก -->
                    <div class="pt-4 border-t border-white/10">
                        <div class="flex items-center justify-between mb-3">
                            <label class="text-sm font-medium text-white/80 flex items-center gap-2"><i class="fas fa-minus-circle text-rose-400/80 text-xs"></i>รายการหัก</label>
                            <button type="button" onclick="openPresetManager('deduction')" class="text-xs font-medium text-violet-400 hover:text-violet-300 px-3 py-1.5 rounded-lg bg-violet-500/10 hover:bg-violet-500/20 transition-colors"><i class="fas fa-cog mr-1.5"></i>จัดการรายการ</button>
                        </div>
                        <div id="deduction_other_rows" class="space-y-3">
                            <?php for ($i = 0; $i < max(1, count($decoded_deduction)); $i++): $item = $decoded_deduction[$i] ?? []; ?>
                            <div class="payroll-dyn-row flex gap-3 items-center p-4 rounded-xl bg-white/5 border border-white/5">
                                <?php echo render_preset_select('deduction_other_label[]', $item_presets['deduction'] ?? [], $item['label'] ?? ''); ?>
                                <input type="text" name="deduction_other_custom_label[]" placeholder="ชื่อรายการ" class="hidden flex-1 min-w-0 rounded-[10px] text-white custom-label-input" style="border:1px solid rgba(255,255,255,0.18);background:rgba(255,255,255,0.04);padding:14px 16px;font-size:16px;outline:none;">
                                <input type="number" step="0.01" name="deduction_other_amount[]" value="<?php echo isset($item['amount']) ? (float)$item['amount'] : ''; ?>" placeholder="0.00" class="salary-input salary-input-num" style="width:160px;flex-shrink:0;">
                                <button type="button" class="payroll-remove-row flex-shrink-0 w-9 h-9 rounded-lg text-white/40 hover:text-rose-400 hover:bg-rose-500/10 flex items-center justify-center transition-colors" title="ลบ"><i class="fas fa-trash-alt text-xs"></i></button>
                            </div>
                            <?php endfor; ?>
                        </div>
                        <button type="button" class="payroll-add-row mt-3 text-sm font-medium text-rose-400/90 hover:text-rose-300 flex items-center gap-2" data-target="deduction_other_rows" data-category="deduction"><i class="fas fa-plus text-xs"></i> เพิ่มรายการ</button>
                    </div>

                    <!-- เบี้ยเลี้ยง / สวัสดิการ -->
                    <div class="pt-4 border-t border-white/10">
                        <div class="flex items-center justify-between mb-3">
                            <label class="text-sm font-medium text-white/80 flex items-center gap-2"><i class="fas fa-hand-holding-usd text-amber-400/80 text-xs"></i>เบี้ยเลี้ยง / สวัสดิการ</label>
                            <button type="button" onclick="openPresetManager('allowance')" class="text-xs font-medium text-violet-400 hover:text-violet-300 px-3 py-1.5 rounded-lg bg-violet-500/10 hover:bg-violet-500/20 transition-colors"><i class="fas fa-cog mr-1.5"></i>จัดการรายการ</button>
                        </div>
                        <div id="allowance_rows" class="space-y-3">
                            <?php for ($i = 0; $i < max(1, count($decoded_allowance)); $i++): $item = $decoded_allowance[$i] ?? []; ?>
                            <div class="payroll-dyn-row flex gap-3 items-center p-4 rounded-xl bg-white/5 border border-white/5">
                                <?php echo render_preset_select('allowance_label[]', $item_presets['allowance'] ?? [], $item['label'] ?? '', 'allowance'); ?>
                                <input type="text" name="allowance_custom_label[]" placeholder="ชื่อรายการ" class="hidden flex-1 min-w-0 rounded-[10px] text-white custom-label-input" style="border:1px solid rgba(255,255,255,0.18);background:rgba(255,255,255,0.04);padding:14px 16px;font-size:16px;outline:none;">
                                <input type="number" step="0.01" name="allowance_amount[]" value="<?php echo isset($item['amount']) ? (float)$item['amount'] : ''; ?>" placeholder="0.00" class="salary-input salary-input-num" style="width:160px;flex-shrink:0;">
                                <?php echo render_ss_include_toggle('allowance_ss_include[]', $item, 'allowance'); ?>
                                <button type="button" class="payroll-remove-row flex-shrink-0 w-9 h-9 rounded-lg text-white/40 hover:text-rose-400 hover:bg-rose-500/10 flex items-center justify-center transition-colors" title="ลบ"><i class="fas fa-trash-alt text-xs"></i></button>
                            </div>
                            <?php endfor; ?>
                        </div>
                        <button type="button" class="payroll-add-row mt-3 text-sm font-medium text-amber-400/90 hover:text-amber-300 flex items-center gap-2" data-target="allowance_rows" data-category="allowance"><i class="fas fa-plus text-xs"></i> เพิ่มรายการ</button>
                    </div>
                </div>
            </div>

            <!-- บันทึก / หมายเหตุ -->
            <div class="rounded-2xl border border-white/10 bg-white/[0.03] overflow-hidden">
                <div class="px-6 py-4 border-b border-white/10">
                    <h2 class="text-base font-semibold text-white flex items-center gap-2"><span class="w-8 h-8 rounded-lg bg-slate-500/20 flex items-center justify-center text-slate-400"><i class="fas fa-sticky-note text-sm"></i></span>บันทึก / หมายเหตุ</h2>
                </div>
                <div class="p-6">
                    <textarea name="notes" rows="3" class="block w-full rounded-[10px] text-white resize-none" style="border:1px solid rgba(255,255,255,0.18);background:rgba(255,255,255,0.04);padding:14px 16px;font-size:16px;line-height:1.6;outline:none;color:rgba(255,255,255,0.9);" onfocus="this.style.borderColor='rgba(139,92,246,0.5)';this.style.boxShadow='0 0 0 3px rgba(139,92,246,0.15)'" onblur="this.style.borderColor='rgba(255,255,255,0.18)';this.style.boxShadow='none'" placeholder="บันทึกเพิ่มเติม เช่น เหตุผลการปรับเงินเดือน หรือเงื่อนไขพิเศษ"><?php echo htmlspecialchars($salary_setup['notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <!-- สรุปการคำนวณ (Preview) -->
            <div class="rounded-2xl border border-violet-500/30 bg-violet-500/5 overflow-hidden" id="payrollPreviewCard">
                <div class="px-6 py-4 border-b border-white/10 flex items-center justify-between gap-3">
                    <h2 class="text-base font-semibold text-white flex items-center gap-2">
                        <span class="w-8 h-8 rounded-lg bg-violet-500/20 flex items-center justify-center text-violet-400"><i class="fas fa-calculator text-sm"></i></span>
                        สรุปการคำนวณ (ตัวอย่าง)
                    </h2>
                    <span id="payrollPreviewStatus" class="text-xs text-white/40">กำลังโหลด...</span>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 text-sm" id="payrollPreviewGrid">
                        <div class="rounded-xl bg-white/5 border border-white/10 p-3">
                            <div class="text-white/50 text-xs mb-1">รายได้รวม</div>
                            <div class="text-emerald-400 font-semibold tabular-nums" data-preview="total_income">—</div>
                        </div>
                        <div class="rounded-xl bg-white/5 border border-white/10 p-3">
                            <div class="text-white/50 text-xs mb-1">ภาษี ณ ที่จ่าย</div>
                            <div class="text-rose-300 font-semibold tabular-nums" data-preview="tax_withheld">—</div>
                            <div class="text-[10px] text-white/40 mt-1" data-preview="tax_detail"></div>
                        </div>
                        <div class="rounded-xl bg-white/5 border border-white/10 p-3">
                            <div class="text-white/50 text-xs mb-1">ประกันสังคม</div>
                            <div class="text-white/80 font-semibold tabular-nums" data-preview="social_security">—</div>
                        </div>
                        <div class="rounded-xl bg-white/5 border border-white/10 p-3">
                            <div class="text-white/50 text-xs mb-1">กองทุนสำรองฯ</div>
                            <div class="text-white/80 font-semibold tabular-nums" data-preview="provident_fund">—</div>
                        </div>
                        <div class="rounded-xl bg-white/5 border border-white/10 p-3">
                            <div class="text-white/50 text-xs mb-1">ประกันกลุ่ม</div>
                            <div class="text-white/80 font-semibold tabular-nums" data-preview="group_insurance">—</div>
                        </div>
                        <div class="rounded-xl bg-white/5 border border-white/10 p-3">
                            <div class="text-white/50 text-xs mb-1">ประกันสุขภาพ</div>
                            <div class="text-white/80 font-semibold tabular-nums" data-preview="health_insurance">—</div>
                        </div>
                        <div class="rounded-xl bg-white/5 border border-white/10 p-3">
                            <div class="text-white/50 text-xs mb-1">หักอื่นๆ / ขาดสาย</div>
                            <div class="text-amber-300 font-semibold tabular-nums" data-preview="other_deductions">—</div>
                        </div>
                        <div class="rounded-xl bg-violet-500/10 border border-violet-400/30 p-3 col-span-2 sm:col-span-1">
                            <div class="text-white/50 text-xs mb-1">เงินได้สุทธิ</div>
                            <div class="text-violet-300 font-bold text-lg tabular-nums" data-preview="net_salary">—</div>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-white/40"><i class="fas fa-info-circle mr-1"></i>ตัวเลขจาก engine เดียวกับสลิปจริง — อัปเดตอัตโนมัติเมื่อแก้ฟอร์ม</p>
                </div>
            </div>

            <!-- ปุ่มดำเนินการ (ด้านล่างฟอร์ม) -->
            <div id="salary_setup_actions_bottom" class="flex flex-wrap items-center gap-3 pt-2 pb-4">
                <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-violet-600 hover:bg-violet-500 text-white font-semibold text-sm shadow-lg shadow-violet-500/20 transition-all hover:shadow-violet-500/30"><i class="fas fa-save"></i> บันทึกการตั้งค่า</button>
                <a href="payroll.php?action=employees" class="inline-flex items-center gap-2 px-5 py-3 rounded-xl border border-white/20 text-white/80 hover:text-white hover:bg-white/10 font-medium text-sm transition-colors">ยกเลิก</a>
            </div>
        </form>

        <!-- แถบปุ่มล่างคงที่ -->
        <div id="salary_setup_sticky_bar" class="fixed bottom-0 left-0 right-0 z-40 hidden px-4 py-3 bg-[#1a1b26]/95 border-t border-white/10 backdrop-blur-sm safe-area-pb">
            <div class="max-w-3xl mx-auto flex flex-wrap items-center justify-end gap-3">
                <a href="payroll.php?action=employees" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl border border-white/20 text-white/80 hover:text-white hover:bg-white/10 font-medium text-sm transition-colors">ยกเลิก</a>
                <button type="submit" form="salary_setup_form" class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-violet-600 hover:bg-violet-500 text-white font-semibold text-sm shadow-lg shadow-violet-500/20 transition-all"><i class="fas fa-save"></i> บันทึกการตั้งค่า</button>
            </div>
        </div>
    </div>

    <!-- Modal จัดการ Preset -->
    <div id="presetModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
        <div class="w-full max-w-md rounded-2xl border border-white/10 bg-[#1a1b26] shadow-2xl overflow-hidden flex flex-col max-h-[85vh]">
            <div class="px-6 py-4 border-b border-white/10 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-white" id="presetModalTitle">จัดการรายการ</h3>
                <button onclick="closePresetManager()" class="w-9 h-9 rounded-lg text-white/50 hover:text-white hover:bg-white/10 flex items-center justify-center transition-colors"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-4 border-b border-white/10 flex gap-2">
                <input type="text" id="presetNewLabel" placeholder="ชื่อรายการใหม่" class="flex-1 rounded-lg border border-white/20 bg-white/5 text-white px-3 py-2.5 text-sm placeholder-white/30 focus:ring-2 focus:ring-violet-500">
                <input type="number" step="0.01" id="presetNewAmount" placeholder="จำนวน" class="w-28 rounded-lg border border-white/20 bg-white/5 text-white px-3 py-2.5 text-sm text-right tabular-nums placeholder-white/30">
                <button type="button" onclick="addPresetItem()" class="px-4 py-2.5 rounded-lg bg-violet-600 hover:bg-violet-500 text-white text-sm font-medium transition-colors"><i class="fas fa-plus mr-1"></i>เพิ่ม</button>
            </div>
            <div id="presetList" class="p-4 overflow-y-auto flex-1 space-y-2 min-h-0"></div>
        </div>
    </div>

<script>
var presetData = <?php echo json_encode($item_presets, JSON_UNESCAPED_UNICODE); ?>;

// Sticky bar: show when scrolled past bottom actions
(function() {
    var bar = document.getElementById('salary_setup_sticky_bar');
    var actionsBottom = document.getElementById('salary_setup_actions_bottom');
    if (!bar || !actionsBottom) return;
    function check() {
        var bottomRect = actionsBottom.getBoundingClientRect();
        var show = bottomRect.bottom > window.innerHeight + 50;
        if (show) bar.classList.remove('hidden'); else bar.classList.add('hidden');
    }
    window.addEventListener('scroll', check, { passive: true });
    window.addEventListener('resize', check);
    check();
})();
var currentPresetCategory = '';

function ssIncludeChanged(cb) {
    var hidden = cb.closest('label')?.querySelector('.ss-include-hidden');
    if (hidden) hidden.value = cb.checked ? '1' : '0';
    if (typeof schedulePayrollPreview === 'function') schedulePayrollPreview();
}

function applySsIncludeFromPreset(row) {
    var sel = row.querySelector('.preset-select');
    var cb = row.querySelector('.ss-include-cb');
    var hidden = row.querySelector('.ss-include-hidden');
    if (!sel || !cb || !hidden) return;
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value || opt.value === '__custom__') return;
    var include = opt.getAttribute('data-ss-include') === '1';
    cb.checked = include;
    hidden.value = include ? '1' : '0';
}

function toggleSocialSecurityManual(checked) {
    var hidden = document.getElementById('social_security_manual_hidden');
    var input = document.getElementById('social_security_input');
    var badge = document.getElementById('ss_mode_badge');
    if (hidden) hidden.value = checked ? '1' : '0';
    if (badge) {
        badge.textContent = checked ? 'กำหนดเอง' : 'คำนวณอัตโนมัติ';
        badge.className = checked
            ? 'px-2 py-0.5 rounded-full text-[10px] bg-violet-500/20 text-violet-300 border border-violet-400/30'
            : 'px-2 py-0.5 rounded-full text-[10px] bg-sky-500/20 text-sky-300 border border-sky-400/30';
    }
    if (input) {
        input.readOnly = !checked;
        input.style.opacity = checked ? '1' : '0.85';
        input.style.cursor = checked ? 'text' : 'not-allowed';
        if (!checked && typeof schedulePayrollPreview === 'function') {
            schedulePayrollPreview();
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var ssManual = document.getElementById('social_security_manual_cb');
    if (ssManual) {
        toggleSocialSecurityManual(ssManual.checked);
    }
});

function presetSelectChanged(sel) {
    var row = sel.closest('.payroll-dyn-row');
    var customInput = row.querySelector('.custom-label-input');
    var amountInput = row.querySelector('input[type="number"]');
    if (sel.value === '__custom__') {
        customInput.classList.remove('hidden');
        customInput.focus();
        sel.classList.add('hidden');
    } else {
        customInput.classList.add('hidden');
        customInput.value = '';
        var opt = sel.options[sel.selectedIndex];
        var defAmt = parseFloat(opt.getAttribute('data-default-amount') || 0);
        if (defAmt > 0 && (!amountInput.value || parseFloat(amountInput.value) === 0)) {
            amountInput.value = defAmt;
        }
        applySsIncludeFromPreset(row);
    }
}

function buildPresetOptions(category, selectedLabel) {
    var items = presetData[category] || [];
    var html = '<option value="">-- เลือกรายการ --</option>';
    var found = false;
    items.forEach(function(p) {
        var sel = (selectedLabel && p.label === selectedLabel) ? ' selected' : '';
        if (sel) found = true;
        var amt = p.default_amount ? ' (฿' + Number(p.default_amount).toLocaleString() + ')' : '';
        html += '<option value="' + escHtml(p.label) + '" data-default-amount="' + (p.default_amount || 0) + '" data-ss-include="' + (p.ss_include != null ? p.ss_include : 1) + '"' + sel + '>' + escHtml(p.label) + amt + '</option>';
    });
    if (selectedLabel && !found) {
        html += '<option value="' + escHtml(selectedLabel) + '" selected>' + escHtml(selectedLabel) + '</option>';
    }
    html += '<option value="__custom__">+ พิมพ์เอง...</option>';
    return html;
}

function escHtml(s) {
    var d = document.createElement('div'); d.textContent = s; return d.innerHTML;
}

var nameMap = {
    'income_other_rows': {label: 'income_other_label[]', custom: 'income_other_custom_label[]', amount: 'income_other_amount[]', ss: 'income_other_ss_include[]', category: 'income', defaultSs: 0},
    'deduction_other_rows': {label: 'deduction_other_label[]', custom: 'deduction_other_custom_label[]', amount: 'deduction_other_amount[]', category: 'deduction'},
    'allowance_rows': {label: 'allowance_label[]', custom: 'allowance_custom_label[]', amount: 'allowance_amount[]', ss: 'allowance_ss_include[]', category: 'allowance', defaultSs: 1}
};

function ssIncludeRowHtml(name, checked) {
    var val = checked ? '1' : '0';
    var chk = checked ? ' checked' : '';
    return '<label class="flex-shrink-0 flex flex-col items-center gap-1 cursor-pointer select-none" title="รวมในฐานเงินสมทบประกันสังคม">'
        + '<span class="text-[10px] text-white/45 whitespace-nowrap">ฐาน สส.</span>'
        + '<input type="hidden" name="' + name + '" value="' + val + '" class="ss-include-hidden">'
        + '<input type="checkbox" class="ss-include-cb w-4 h-4 rounded border-white/30 bg-white/5 text-violet-500 focus:ring-violet-500/30"' + chk + ' onchange="ssIncludeChanged(this)">'
        + '</label>';
}

document.querySelectorAll('.payroll-add-row').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var targetId = this.getAttribute('data-target');
        var container = document.getElementById(targetId);
        if (!container) return;
        var map = nameMap[targetId];
        if (!map) return;
        var row = document.createElement('div');
        row.className = 'payroll-dyn-row flex gap-3 items-center p-4 rounded-xl bg-white/5 border border-white/5';
        row.innerHTML = '<select name="' + map.label + '" class="preset-select flex-1 min-w-0 rounded-[10px] text-white" style="border:1px solid rgba(255,255,255,0.18);background:rgba(255,255,255,0.04);padding:14px 16px;font-size:16px;outline:none;" onfocus="this.style.borderColor=\'rgba(139,92,246,0.5)\';this.style.boxShadow=\'0 0 0 3px rgba(139,92,246,0.15)\'" onblur="this.style.borderColor=\'rgba(255,255,255,0.18)\';this.style.boxShadow=\'none\'" onchange="presetSelectChanged(this)">' + buildPresetOptions(map.category, '') + '</select>'
            + '<input type="text" name="' + map.custom + '" placeholder="ชื่อรายการ" class="hidden flex-1 min-w-0 rounded-[10px] text-white custom-label-input" style="border:1px solid rgba(255,255,255,0.18);background:rgba(255,255,255,0.04);padding:14px 16px;font-size:16px;outline:none;">'
            + '<input type="number" step="0.01" name="' + map.amount + '" placeholder="0.00" class="salary-input salary-input-num" style="width:160px;flex-shrink:0;">'
            + (map.ss ? ssIncludeRowHtml(map.ss, !!map.defaultSs) : '')
            + '<button type="button" class="payroll-remove-row flex-shrink-0 w-9 h-9 rounded-lg text-white/40 hover:text-rose-400 hover:bg-rose-500/10 flex items-center justify-center transition-colors" title="ลบ"><i class="fas fa-trash-alt text-xs"></i></button>';
        container.appendChild(row);
    });
});

document.addEventListener('click', function(e) {
    if (!e.target.closest('.payroll-remove-row')) return;
    var row = e.target.closest('.payroll-dyn-row');
    if (!row) return;
    var container = row.parentElement;
    if (container.querySelectorAll('.payroll-dyn-row').length <= 1) {
        row.querySelectorAll('input').forEach(function(inp) { inp.value = ''; });
        var sel = row.querySelector('select');
        if (sel) { sel.value = ''; sel.classList.remove('hidden'); }
        var ci = row.querySelector('.custom-label-input');
        if (ci) ci.classList.add('hidden');
        return;
    }
    row.remove();
});

// Before submit: if select is hidden (custom selected), set select value = __custom__
document.querySelector('form')?.addEventListener('submit', function() {
    this.querySelectorAll('.payroll-dyn-row').forEach(function(row) {
        var sel = row.querySelector('.preset-select');
        var custom = row.querySelector('.custom-label-input');
        if (sel && custom && sel.classList.contains('hidden') && custom.value.trim() !== '') {
            sel.classList.remove('hidden');
            sel.style.display = 'none';
            sel.value = '__custom__';
        }
    });
});

// Preset Manager
function openPresetManager(category) {
    currentPresetCategory = category;
    var titles = {income: 'จัดการรายการรายได้อื่นๆ', deduction: 'จัดการรายการหัก', allowance: 'จัดการเบี้ยเลี้ยง/สวัสดิการ'};
    document.getElementById('presetModalTitle').textContent = titles[category] || 'จัดการรายการ';
    document.getElementById('presetNewLabel').value = '';
    document.getElementById('presetNewAmount').value = '';
    loadPresetList(category);
    document.getElementById('presetModal').classList.remove('hidden');
    document.getElementById('presetModal').classList.add('flex');
}

function closePresetManager() {
    document.getElementById('presetModal').classList.add('hidden');
    document.getElementById('presetModal').classList.remove('flex');
}

function loadPresetList(category) {
    fetch('api/payroll/item_presets.php?category=' + category)
        .then(r => r.json())
        .then(res => {
            if (!res.success) return;
            presetData[category] = res.data;
            var html = '';
            res.data.forEach(function(item) {
                html += '<div class="flex items-center gap-2 p-2 rounded-xl bg-white/5" data-id="' + item.id + '">'
                    + '<span class="flex-1 text-white text-sm">' + escHtml(item.label) + '</span>'
                    + '<span class="text-white/50 text-xs w-24 text-right">' + (item.default_amount ? '฿' + Number(item.default_amount).toLocaleString() : '-') + '</span>'
                    + '<button type="button" onclick="editPresetItem(' + item.id + ',\'' + escHtml(item.label).replace(/'/g, "\\'") + '\',' + (item.default_amount || 'null') + ')" class="p-1.5 rounded-lg text-white/50 hover:text-amber-400 hover:bg-white/10" title="แก้ไข"><i class="fas fa-edit text-xs"></i></button>'
                    + '<button type="button" onclick="deletePresetItem(' + item.id + ')" class="p-1.5 rounded-lg text-white/50 hover:text-rose-400 hover:bg-white/10" title="ลบ"><i class="fas fa-trash text-xs"></i></button>'
                    + '</div>';
            });
            if (!res.data.length) html = '<p class="text-white/40 text-sm text-center py-4">ยังไม่มีรายการ</p>';
            document.getElementById('presetList').innerHTML = html;
        });
}

function addPresetItem() {
    var label = document.getElementById('presetNewLabel').value.trim();
    if (!label) { document.getElementById('presetNewLabel').focus(); return; }
    var amount = document.getElementById('presetNewAmount').value;
    fetch('api/payroll/item_presets.php', {
        method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content},
        body: JSON.stringify({action: 'add', category: currentPresetCategory, label: label, default_amount: amount || null})
    }).then(r => r.json()).then(res => {
        if (res.success) {
            document.getElementById('presetNewLabel').value = '';
            document.getElementById('presetNewAmount').value = '';
            loadPresetList(currentPresetCategory);
            refreshDropdowns(currentPresetCategory);
        } else { alert(res.message); }
    });
}

function editPresetItem(id, oldLabel, oldAmount) {
    var newLabel = prompt('ชื่อรายการ:', oldLabel);
    if (newLabel === null) return;
    newLabel = newLabel.trim();
    if (!newLabel) return;
    var newAmount = prompt('จำนวนเงินเริ่มต้น (ว่างถ้าไม่ระบุ):', oldAmount || '');
    fetch('api/payroll/item_presets.php', {
        method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content},
        body: JSON.stringify({action: 'edit', id: id, label: newLabel, default_amount: newAmount || null})
    }).then(r => r.json()).then(res => {
        if (res.success) { loadPresetList(currentPresetCategory); refreshDropdowns(currentPresetCategory); }
        else { alert(res.message); }
    });
}

function deletePresetItem(id) {
    if (!confirm('ต้องการลบรายการนี้?')) return;
    fetch('api/payroll/item_presets.php', {
        method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content},
        body: JSON.stringify({action: 'delete', id: id})
    }).then(r => r.json()).then(res => {
        if (res.success) { loadPresetList(currentPresetCategory); refreshDropdowns(currentPresetCategory); }
        else { alert(res.message); }
    });
}

function refreshDropdowns(category) {
    fetch('api/payroll/item_presets.php?category=' + category)
        .then(r => r.json())
        .then(res => {
            if (!res.success) return;
            presetData[category] = res.data;
            var targetIds = {income: 'income_other_rows', deduction: 'deduction_other_rows', allowance: 'allowance_rows'};
            var container = document.getElementById(targetIds[category]);
            if (!container) return;
            container.querySelectorAll('.preset-select').forEach(function(sel) {
                if (sel.classList.contains('hidden')) return;
                var currentVal = sel.value;
                sel.innerHTML = buildPresetOptions(category, currentVal);
            });
        });
}

function syncTaxOptOutUi() {
    var taxOptOut = document.querySelector('input[name="tax_opt_out"]');
    var extraTax = document.querySelector('input[name="additional_tax_withholding"]');
    if (!taxOptOut || !extraTax) return;
    var disabled = taxOptOut.checked;
    extraTax.disabled = disabled;
    if (disabled) extraTax.value = '0';
}

document.addEventListener('DOMContentLoaded', function() {
    syncTaxOptOutUi();
    var taxOptOut = document.querySelector('input[name="tax_opt_out"]');
    if (taxOptOut) taxOptOut.addEventListener('change', syncTaxOptOutUi);
    var giOptOut = document.querySelector('input[name="gi_opt_out"]');
    if (giOptOut) giOptOut.addEventListener('change', updateGroupInsurancePreview);
    var hiOptOut = document.querySelector('input[name="hi_opt_out"]');
    if (hiOptOut) hiOptOut.addEventListener('change', updateHealthInsurancePreview);
    var hiStart = document.querySelector('input[name="health_insurance_start_date"]');
    if (hiStart) hiStart.addEventListener('change', updateHealthInsurancePreview);
    initPayrollPreview();
});

var payrollPreviewTimer = null;
function fmtBaht(n) {
    return Number(n || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function initPayrollPreview() {
    var form = document.getElementById('salary_setup_form');
    if (!form) return;
    var run = function() { schedulePayrollPreview(); };
    form.addEventListener('input', run);
    form.addEventListener('change', run);
    schedulePayrollPreview();
}
function schedulePayrollPreview() {
    if (payrollPreviewTimer) clearTimeout(payrollPreviewTimer);
    payrollPreviewTimer = setTimeout(refreshPayrollPreview, 450);
}
function refreshPayrollPreview() {
    var form = document.getElementById('salary_setup_form');
    var status = document.getElementById('payrollPreviewStatus');
    if (!form) return;
    if (status) status.textContent = 'กำลังคำนวณ...';
    var fd = new FormData(form);
    var month = fd.get('effective_from') || '';
    if (!month) {
        if (status) status.textContent = 'เลือกเดือนที่มีผล';
        return;
    }
    fd.set('month', month);
    fetch('api/payroll/preview.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success || !res.data) {
                if (status) status.textContent = res.message || 'คำนวณไม่สำเร็จ';
                return;
            }
            var d = res.data;
            setPreviewVal('total_income', fmtBaht(d.total_income));
            setPreviewVal('tax_withheld', fmtBaht(d.tax_withheld));
            var taxDetail = document.querySelector('[data-preview="tax_detail"]');
            if (taxDetail) {
                var base = Number(d.tax_base || 0);
                var extra = Number(d.extra_tax || 0);
                taxDetail.textContent = extra > 0
                    ? ('ขั้นภาษี ' + fmtBaht(base) + ' + หักเพิ่ม ' + fmtBaht(extra))
                    : (base > 0 ? ('ขั้นภาษี ' + fmtBaht(base)) : '');
            }
            setPreviewVal('social_security', fmtBaht(d.social_security));
            setPreviewVal('provident_fund', fmtBaht(d.provident_fund));
            setPreviewVal('group_insurance', fmtBaht(d.group_insurance));
            setPreviewVal('health_insurance', fmtBaht(d.health_insurance));
            var otherDed = Number(d.deduction_other || 0) + Number(d.absence_deduction || 0) + Number(d.lateness_deduction || 0);
            setPreviewVal('other_deductions', fmtBaht(otherDed));
            setPreviewVal('net_salary', fmtBaht(d.net_salary));
            if (status) status.textContent = 'อัปเดตแล้ว';
            var ssManual = document.getElementById('social_security_manual_cb');
            var ssField = document.getElementById('social_security_input');
            if (ssField && (!ssManual || !ssManual.checked)) {
                ssField.value = Number(d.social_security || 0).toFixed(2);
            }
        })
        .catch(function() {
            if (status) status.textContent = 'เชื่อมต่อไม่สำเร็จ';
        });
}
function setPreviewVal(key, text) {
    var el = document.querySelector('[data-preview="' + key + '"]');
    if (el) el.textContent = text;
}
</script>
