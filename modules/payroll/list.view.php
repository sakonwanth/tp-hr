<?php
if (!defined('BASE_PATH')) {http_response_code(403);exit;}
/**
 * Payroll List View - Runs list, employees list, and home dashboard
 * Variables available: $payroll_tab, $runs, $employees, $departments, $user_has_salary,
 *                      $latest_run, $dept_filter, $page_subtitles
 */
?>

<?php if ($payroll_tab === 'runs'): ?>
    <?php
    $ss_enabled = function_exists('payroll_is_ss_enabled') ? payroll_is_ss_enabled() : false;
    $tax_enabled = function_exists('payroll_is_tax_enabled') ? payroll_is_tax_enabled() : true;
    $hi_enabled = function_exists('payroll_is_health_insurance_enabled') ? payroll_is_health_insurance_enabled() : false;
    $gi_enabled = function_exists('payroll_is_group_insurance_enabled') ? payroll_is_group_insurance_enabled() : true;
    $ss_enabled_from = function_exists('payroll_company_benefit_start_month_value') ? payroll_company_benefit_start_month_value('payroll_ss_enabled_from') : '';
    $tax_enabled_from = function_exists('payroll_company_benefit_start_month_value') ? payroll_company_benefit_start_month_value('payroll_tax_enabled_from') : '';
    $hi_enabled_from = function_exists('payroll_company_benefit_start_month_value') ? payroll_company_benefit_start_month_value('payroll_health_insurance_enabled_from') : '';
    $gi_enabled_from = function_exists('payroll_company_benefit_start_month_value') ? payroll_company_benefit_start_month_value('payroll_group_insurance_enabled_from') : '';
    $ss_cap_now = function_exists('payroll_ss_max_contribution') ? (int)payroll_ss_max_contribution(date('Y-m-01')) : 875;
    $default_pay_day = function_exists('payroll_get_default_pay_day') ? payroll_get_default_pay_day() : 25;
    $cutoff_day = function_exists('payroll_get_cutoff_day') ? payroll_get_cutoff_day() : 25;
    $payday_saved = !empty($_GET['payday_saved']);
    $benefit_cards = [
        [
            'key' => 'ss',
            'title' => 'ประกันสังคม',
            'hint' => 'หัก 5% สูงสุด ' . $ss_cap_now . ' บ./เดือน · ต้องตั้งวันเริ่มหักพนักงานใน HR',
            'icon' => 'fa-umbrella',
            'icon_color' => 'text-cyan-400',
            'enabled' => $ss_enabled,
            'enabled_from' => $ss_enabled_from,
            'saved' => !empty($_GET['ss_saved']),
        ],
        [
            'key' => 'tax',
            'title' => 'ภาษี ณ ที่จ่าย',
            'hint' => 'หักตามขั้นภาษี · เดือนมีผล = สูงสุดของ (บริษัท, พนักงาน)',
            'icon' => 'fa-file-invoice-dollar',
            'icon_color' => 'text-violet-400',
            'enabled' => $tax_enabled,
            'enabled_from' => $tax_enabled_from,
            'saved' => !empty($_GET['tax_saved']),
        ],
        [
            'key' => 'hi',
            'title' => 'ประกันสุขภาพ',
            'hint' => 'หักเบี้ยตามที่ตั้งรายบุคคล · ต้องตั้งวันเริ่มหักพนักงานใน HR',
            'icon' => 'fa-heartbeat',
            'icon_color' => 'text-rose-400',
            'enabled' => $hi_enabled,
            'enabled_from' => $hi_enabled_from,
            'saved' => !empty($_GET['hi_saved']),
        ],
        [
            'key' => 'gi',
            'title' => 'ประกันกลุ่ม',
            'hint' => 'หักเบี้ยตามที่ตั้งรายบุคคล',
            'icon' => 'fa-shield-alt',
            'icon_color' => 'text-teal-400',
            'enabled' => $gi_enabled,
            'enabled_from' => $gi_enabled_from,
            'saved' => !empty($_GET['gi_saved']),
        ],
    ];
    $settings_flash = null;
    foreach ($benefit_cards as $card) {
        if (!empty($card['saved'])) {
            $settings_flash = 'บันทึกการตั้งค่า「' . $card['title'] . '」แล้ว';
            break;
        }
    }
    if ($payday_saved) {
        $settings_flash = 'บันทึกวันที่จ่ายเงินเดือนเริ่มต้นแล้ว';
    }
    $suggest_month = function_exists('payroll_suggest_payroll_month') ? payroll_suggest_payroll_month() : date('Y-m');
    $suggest_month_first = $suggest_month . '-01';
    $period_bounds = function_exists('payroll_attendance_period_bounds')
        ? payroll_attendance_period_bounds($suggest_month_first)
        : ['start' => '', 'end' => ''];
    ?>
    <div class="glass-card rounded-2xl p-4 sm:p-6 mb-6 border border-white/10">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
            <div>
                <h3 class="text-base sm:text-lg font-semibold text-white">ตั้งค่าระบบเงินเดือน</h3>
                <p class="text-xs sm:text-sm text-white/55 mt-1 max-w-2xl">เปิด/ปิดการหักระดับบริษัท และกำหนดเดือนเริ่มหัก — วันเริ่มหักพนักงานแต่ละคนตั้งใน HR → กำหนดเงินเดือน</p>
            </div>
            <?php if ($settings_flash): ?>
            <div class="inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-emerald-500/15 border border-emerald-500/25 text-emerald-300 text-sm">
                <i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($settings_flash); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <div class="flex flex-wrap items-center gap-3 p-4 rounded-xl bg-white/[0.03] border border-white/10 mb-4">
            <div class="min-w-[200px] flex-1">
                <label class="block text-xs font-medium text-white/60 mb-1.5">นโยบายวันจ่ายเงินเดือน</label>
                <p class="text-[11px] text-white/45">ค่าเริ่มต้นคือวันสิ้นเดือน และหากตรงวันอาทิตย์จะเลื่อนมาจ่ายวันเสาร์ก่อนหน้า · เปลี่ยนเฉพาะกรณีพิเศษได้ตอนสร้างรอบ</p>
            </div>
            <div class="flex items-center gap-2">
                <label class="text-sm text-white/70 whitespace-nowrap">ค่าเริ่มต้น</label>
                <span class="inline-flex min-h-[52px] min-w-[5rem] items-center justify-center rounded-xl border border-white/20 bg-white/5 px-3 text-white/80">สิ้นเดือน</span>
            </div>
        </div>

        <div class="overflow-x-auto -mx-1">
            <table class="w-full min-w-[640px] text-sm">
                <thead>
                    <tr class="text-white/50 text-xs uppercase tracking-wide border-b border-white/10">
                        <th class="text-left py-3 px-3 font-medium">รายการหัก</th>
                        <th class="text-center py-3 px-2 font-medium w-24">สถานะ</th>
                        <th class="text-center py-3 px-2 font-medium w-28">เปิดใช้</th>
                        <th class="text-left py-3 px-2 font-medium w-44">เริ่มหักบริษัท</th>
                        <th class="text-right py-3 px-3 font-medium w-28"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach ($benefit_cards as $card): ?>
                    <tr class="align-middle hover:bg-white/[0.02]">
                        <td class="py-3 px-3">
                            <form method="post" id="benefit-form-<?php echo htmlspecialchars($card['key']); ?>" class="contents">
                                <?php echo function_exists('csrf_field') ? csrf_field() : ''; ?>
                                <input type="hidden" name="action" value="save_payroll_benefit_settings">
                                <input type="hidden" name="benefit" value="<?php echo htmlspecialchars($card['key']); ?>">
                            </form>
                            <div class="flex items-start gap-3">
                                <div class="w-9 h-9 rounded-lg bg-white/5 flex items-center justify-center flex-shrink-0 mt-0.5">
                                    <i class="fas <?php echo $card['icon']; ?> <?php echo $card['icon_color']; ?>"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="font-medium text-white"><?php echo htmlspecialchars($card['title']); ?></div>
                                    <div class="text-xs text-white/45 mt-0.5 leading-relaxed"><?php echo htmlspecialchars($card['hint']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="py-3 px-2 text-center">
                            <?php if ($card['enabled']): ?>
                            <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/15 text-emerald-300 border border-emerald-500/20">เปิด</span>
                            <?php else: ?>
                            <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium bg-white/5 text-white/45 border border-white/10">ปิด</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-2 text-center">
                            <label class="inline-flex items-center justify-center min-h-[52px] min-w-[52px] cursor-pointer">
                                <input type="checkbox" name="benefit_enabled" value="1" form="benefit-form-<?php echo htmlspecialchars($card['key']); ?>" class="w-5 h-5 rounded border-white/30 bg-white/5 text-emerald-500 focus:ring-emerald-500" <?php echo $card['enabled'] ? 'checked' : ''; ?>>
                            </label>
                        </td>
                        <td class="py-3 px-2">
                            <select name="benefit_enabled_from" form="benefit-form-<?php echo htmlspecialchars($card['key']); ?>" class="w-full rounded-xl border border-white/20 bg-slate-900/80 text-white px-3 py-3 text-sm min-h-[52px] focus:ring-2 focus:ring-violet-500">
                                <?php echo function_exists('payroll_benefit_start_month_options') ? payroll_benefit_start_month_options($card['enabled_from']) : ''; ?>
                            </select>
                        </td>
                        <td class="py-3 px-3 text-right">
                            <button type="submit" form="benefit-form-<?php echo htmlspecialchars($card['key']); ?>" class="btn-payroll inline-flex items-center justify-center gap-1.5 px-3 py-3 rounded-xl bg-violet-600 hover:bg-violet-500 text-white text-sm font-medium min-h-[52px] min-w-[52px]">
                                <i class="fas fa-save"></i><span class="hidden sm:inline">บันทึก</span>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <!-- รายการรอบ + สร้างรอบใหม่ -->
    <div class="glass-card rounded-2xl p-4 sm:p-6 mb-6 border border-white/10">
        <h3 class="text-base sm:text-lg font-semibold text-white">สร้างรอบเงินเดือนใหม่</h3>
        <p class="text-xs sm:text-sm text-white/55 mt-1 mb-4">ช่วงตัดรอบ <?php echo (int)$cutoff_day + 1; ?>→<?php echo (int)$cutoff_day; ?> · เดือนแรกเข้างาน=วันเริ่ม→สิ้นเดือน · วันจ่าย=วันที่โอน · เลือกย้อนหลังได้ 24 เดือน (หลังผ่านวันเริ่มรอบแล้ว)</p>

        <form method="post" id="payroll-create-run-form" class="grid grid-cols-1 sm:grid-cols-[12rem_minmax(0,1fr)_4.5rem_auto] gap-3 items-end max-w-4xl">
            <input type="hidden" name="action" value="create_run">
            <?php echo function_exists('csrf_field') ? csrf_field() : ''; ?>

            <div class="min-w-0">
                <label for="payroll-run-month" class="block text-xs font-medium text-white/60 mb-1.5">เดือนที่จ่าย</label>
                <select id="payroll-run-month" name="payroll_month" required class="block w-full h-[52px] rounded-xl border border-white/20 bg-slate-900/90 text-white px-3 text-sm focus:ring-2 focus:ring-violet-500 focus:border-violet-500/50">
                    <?php echo function_exists('payroll_run_month_options') ? payroll_run_month_options($suggest_month) : ''; ?>
                </select>
            </div>

            <div class="min-w-0 sm:pb-1">
                <span class="block text-xs font-medium text-white/60 mb-1.5">ช่วงตัดรอบ</span>
                <div class="flex h-[52px] items-center gap-2 rounded-xl border border-white/10 bg-white/[0.03] px-3 text-xs sm:text-sm text-white/70 min-w-0">
                    <i class="fas fa-calendar-week text-violet-400 flex-shrink-0"></i>
                    <span class="truncate">ช่วง <strong id="payroll-run-period-label" class="font-medium text-white/90"><?php echo htmlspecialchars($period_bounds['start']); ?> → <?php echo htmlspecialchars($period_bounds['end']); ?></strong></span>
                </div>
            </div>

            <div class="min-w-0">
                <label for="payroll-run-pay-day" class="block text-xs font-medium text-white/60 mb-1.5">วันจ่าย <span class="text-white/40">(แก้ได้)</span></label>
                <input type="number" id="payroll-run-pay-day" name="pay_day" value="<?php echo (int)substr(payroll_canonical_payment_date($suggest_month), 8, 2); ?>" min="1" max="31" required title="ค่าเริ่มต้นเป็นวันสิ้นเดือน แก้ไขได้สำหรับรอบนี้เท่านั้น" class="block w-full h-[52px] rounded-xl border border-white/20 bg-slate-900/90 text-white px-2 text-sm text-center focus:ring-2 focus:ring-violet-500 focus:border-violet-500/50">
            </div>

            <button type="submit" class="btn-payroll inline-flex h-[52px] w-full sm:w-auto items-center justify-center gap-2 px-5 rounded-xl bg-violet-600 hover:bg-violet-500 text-white text-sm font-medium whitespace-nowrap">
                <i class="fas fa-plus"></i><span>สร้างรอบ</span>
            </button>
        </form>
    </div>
    <script>
    (function () {
        var form = document.getElementById('payroll-create-run-form');
        if (!form) return;
        var monthEl = document.getElementById('payroll-run-month');
        var labelEl = document.getElementById('payroll-run-period-label');
        var payDayEl = document.getElementById('payroll-run-pay-day');
        var cutoffDay = <?php echo (int)$cutoff_day; ?>;
        if (!monthEl || !labelEl) return;

        function clampDay(year, monthIndex, day) {
            var last = new Date(year, monthIndex + 1, 0).getDate();
            return Math.max(1, Math.min(day, last));
        }

        function formatDate(d) {
            var y = d.getFullYear();
            var m = String(d.getMonth() + 1).padStart(2, '0');
            var day = String(d.getDate()).padStart(2, '0');
            return y + '-' + m + '-' + day;
        }

        function updatePeriodLabel() {
            var ym = monthEl.value || '';
            if (!/^\d{4}-\d{2}$/.test(ym)) return;
            var parts = ym.split('-');
            var year = parseInt(parts[0], 10);
            var monthIndex = parseInt(parts[1], 10) - 1;
            var prevMonthFirst = new Date(year, monthIndex - 1, 1);
            var prevLast = new Date(year, monthIndex, 0).getDate();
            var prevCutoff = Math.min(cutoffDay, prevLast);
            var startDay = prevCutoff + 1;
            var periodStart;
            if (startDay > prevLast) {
                periodStart = new Date(year, monthIndex, 1);
            } else {
                periodStart = new Date(prevMonthFirst.getFullYear(), prevMonthFirst.getMonth(), startDay);
            }
            var periodEnd = new Date(year, monthIndex, clampDay(year, monthIndex, cutoffDay));
            labelEl.textContent = formatDate(periodStart) + ' → ' + formatDate(periodEnd);
            if (payDayEl) {
                var monthEnd = new Date(year, monthIndex + 1, 0);
                var defaultPayDay = monthEnd.getDate();
                if (monthEnd.getDay() === 0) defaultPayDay -= 1;
                payDayEl.max = String(monthEnd.getDate());
                payDayEl.value = String(defaultPayDay);
            }
        }

        monthEl.addEventListener('change', updatePeriodLabel);
    })();
    </script>
    <div class="glass-card rounded-2xl overflow-hidden border border-white/10">
        <div class="overflow-x-auto">
            <table class="w-full text-left responsive-table">
                <thead>
                    <tr class="bg-white/5">
                        <th class="px-6 py-4 text-left text-sm font-medium text-white/60">เดือน</th>
                        <th class="px-6 py-4 text-center text-sm font-medium text-white/60">วันที่จ่าย</th>
                        <th class="px-6 py-4 text-left text-sm font-medium text-white/60">สถานะ</th>
                        <th class="px-6 py-4 text-right text-sm font-medium text-white/60">จำนวนคน</th>
                        <th class="px-6 py-4 text-right text-sm font-medium text-white/60">สุทธิรวม</th>
                        <th class="px-6 py-4 text-left text-sm font-medium text-white/60">อัปเดตเมื่อ</th>
                        <th class="px-6 py-4 text-center text-sm font-medium text-white/60">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach ($runs as $r): ?>
                    <?php
                    $updated_at = !empty($r['updated_at']) ? strtotime($r['updated_at']) : null;
                    $created_at = !empty($r['created_at']) ? strtotime($r['created_at']) : null;
                    $date_label = $updated_at ? 'อัปเดต ' . date('d/m/Y H:i', $updated_at) : ($created_at ? 'สร้าง ' . date('d/m/Y H:i', $created_at) : '-');
                    ?>
                    <?php
                    $pay_day_txt = function_exists('payroll_format_pay_date_thai') ? payroll_format_pay_date_thai($r['payroll_month'], (int)$r['pay_day']) : ((int)$r['pay_day']);
                    $period_txt = function_exists('payroll_format_period_range_thai') ? payroll_format_period_range_thai($r['payroll_month'], null) : '';
                    ?>
                    <tr class="table-row transition-colors hover:bg-white/5">
                        <td class="px-6 py-4 font-medium text-white"><?php echo date('F Y', strtotime($r['payroll_month'])); ?></td>
                        <td class="px-6 py-4 text-center text-emerald-300"><i class="far fa-calendar-check mr-1"></i><?php echo htmlspecialchars($pay_day_txt); ?><?php if ($period_txt !== ''): ?><div class="text-[11px] text-white/45 mt-0.5"><?php echo htmlspecialchars($period_txt); ?></div><?php endif; ?></td>
                        <td class="px-6 py-4"><span class="px-2.5 py-1 rounded-full text-xs font-medium <?php echo $r['status'] === 'approved' || $r['status'] === 'paid' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-amber-500/20 text-amber-300'; ?>"><?php echo $r['status']; ?></span></td>
                        <td class="px-6 py-4 text-right text-white/80"><?php echo (int)$r['employee_count']; ?></td>
                        <td class="px-6 py-4 text-right font-semibold text-emerald-400">฿<?php echo number_format($r['total_net'], 2); ?></td>
                        <td class="px-6 py-4 text-white/70 text-sm"><?php echo htmlspecialchars($date_label); ?></td>
                        <td class="px-6 py-4 text-center cell-actions"><a href="payroll.php?run_id=<?php echo (int)$r['id']; ?>" class="inline-flex items-center gap-1 px-4 py-2 rounded-lg bg-violet-600/80 hover:bg-violet-500 text-white text-sm min-h-[48px] min-w-[48px]"><i class="fas fa-list sm:mr-1"></i><span class="hidden sm:inline">ดูสลิป</span></a></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($runs)): ?>
                    <tr><td colspan="7" class="px-6 py-12 text-center text-white/50"><i class="fas fa-calendar-times text-4xl mb-3 block"></i>ยังไม่มีรอบเงินเดือน</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($payroll_tab === 'employees'): ?>
    <?php if (!empty($_GET['saved'])): ?>
    <div class="mb-4 p-4 rounded-xl bg-emerald-500/10 border border-emerald-400/30 text-emerald-200 text-sm">
        <i class="fas fa-check-circle mr-2"></i>บันทึกการตั้งค่าเงินเดือนเรียบร้อย
    </div>
    <?php endif; ?>
    <?php if (!empty($_GET['warn']) && $_GET['warn'] === 'hi_no_start_date'): ?>
    <div class="mb-4 p-4 rounded-xl bg-amber-500/10 border border-amber-400/30 text-amber-200 text-sm">
        <i class="fas fa-exclamation-triangle mr-2"></i>บันทึกแล้ว — แต่ยังไม่ได้ระบุวันเริ่มหักประกันสุขภาพ ระบบจะไม่หักประกันสุขภาพจนกว่าจะกำหนดวันเริ่มหัก
    </div>
    <?php endif; ?>
    <!-- รายการพนักงาน + ฟิลเตอร์แผนก + ปุ่มกำหนดเงินเดือน -->
    <?php
    $ff = $flag_filter ?? '';
    $count_multi = 0; $count_ssopt = 0;
    foreach ($employees as $e) {
        if (!empty($e['has_other_employer_income'])) $count_multi++;
        if (!empty($e['ss_opt_out'])) $count_ssopt++;
    }
    ?>
    <div class="glass-card rounded-2xl overflow-hidden border border-white/10">
        <div class="p-4 border-b border-white/10 flex flex-wrap items-center justify-between gap-3">
            <p class="text-white/70 text-sm">พนักงานที่ใช้งานอยู่ <?php echo count($employees); ?> คน — เลือกพนักงานเพื่อกำหนดฐานเงินเดือนและรายการหัก</p>
            <form method="get" class="flex items-center gap-2">
                <input type="hidden" name="action" value="employees">
                <?php if ($ff !== ''): ?><input type="hidden" name="flag" value="<?php echo htmlspecialchars($ff); ?>"><?php endif; ?>
                <label class="text-white/70 text-sm">แผนก:</label>
                <select name="department" onchange="this.form.submit()" class="rounded-xl border border-white/20 bg-white/5 text-white px-3 py-2 text-sm">
                    <option value="">ทั้งหมด</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?php echo htmlspecialchars($d); ?>" <?php echo ($dept_filter ?? '') === $d ? 'selected' : ''; ?>><?php echo htmlspecialchars($d); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <!-- Filter chips: พนักงานทำงานหลายบริษัท / ยกเว้น SS -->
        <div class="px-4 py-3 border-b border-white/10 flex flex-wrap items-center gap-2 bg-white/[0.02]">
            <span class="text-xs text-white/50 mr-1">กรองด่วน:</span>
            <?php $dep = $dept_filter ?? ''; $base = 'payroll.php?action=employees' . ($dep !== '' ? '&department=' . urlencode($dep) : ''); ?>
            <a href="<?php echo $base; ?>" class="px-3 py-1.5 rounded-full text-xs font-medium border transition <?php echo $ff === '' ? 'bg-violet-500/30 text-violet-200 border-violet-400/50' : 'bg-white/5 text-white/70 border-white/10 hover:bg-white/10'; ?>">
                ทั้งหมด
            </a>
            <a href="<?php echo $base . '&flag=multi'; ?>" class="px-3 py-1.5 rounded-full text-xs font-medium border transition <?php echo $ff === 'multi' ? 'bg-purple-500/30 text-purple-200 border-purple-400/50' : 'bg-white/5 text-white/70 border-white/10 hover:bg-white/10'; ?>">
                <i class="fas fa-briefcase mr-1"></i>มีรายได้หลายที่ <span class="ml-1 opacity-70">(<?php echo $count_multi; ?>)</span>
            </a>
            <a href="<?php echo $base . '&flag=ss_optout'; ?>" class="px-3 py-1.5 rounded-full text-xs font-medium border transition <?php echo $ff === 'ss_optout' ? 'bg-amber-500/30 text-amber-200 border-amber-400/50' : 'bg-white/5 text-white/70 border-white/10 hover:bg-white/10'; ?>">
                <i class="fas fa-ban mr-1"></i>ยกเว้นประกันสังคม <span class="ml-1 opacity-70">(<?php echo $count_ssopt; ?>)</span>
            </a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left responsive-table">
                <thead>
                    <tr class="bg-white/5">
                        <th class="px-6 py-4 text-left text-sm font-medium text-white/60">รหัสพนักงาน</th>
                        <th class="px-6 py-4 text-left text-sm font-medium text-white/60">ชื่อ-นามสกุล</th>
                        <th class="px-6 py-4 text-left text-sm font-medium text-white/60">แผนก / ตำแหน่ง</th>
                        <th class="px-6 py-4 text-left text-sm font-medium text-white/60">สถานะเงินเดือน</th>
                        <th class="px-6 py-4 text-center text-sm font-medium text-white/60">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach ($employees as $e): ?>
                    <tr class="table-row transition-colors hover:bg-white/5">
                        <td class="px-6 py-4 font-mono text-purple-300"><?php echo htmlspecialchars(payroll_format_employee_code($e['employee_code'] ?? '')); ?></td>
                        <td class="px-6 py-4 font-medium text-white">
                            <?php echo htmlspecialchars($e['first_name_th'] . ' ' . $e['last_name_th']); ?>
                            <?php if (!empty($e['has_other_employer_income'])): ?>
                                <span class="ml-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] bg-purple-500/20 text-purple-300 border border-purple-400/30" title="มีรายได้จากนายจ้างอื่น — ควรแจ้งยื่น ภ.ง.ด.91">
                                    <i class="fas fa-briefcase"></i>หลายบริษัท
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($e['ss_opt_out'])): ?>
                                <span class="ml-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] bg-amber-500/20 text-amber-300 border border-amber-400/30" title="ไม่หักประกันสังคม (ส่งที่บริษัทอื่น)">
                                    <i class="fas fa-ban"></i>ยกเว้น SS
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-white/80"><?php echo htmlspecialchars(($e['department'] ?? '-') . ' / ' . ($e['position'] ?? '-')); ?></td>
                        <td class="px-6 py-4">
                            <?php if (!empty($user_has_salary[$e['id']])): ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                <i class="fas fa-check-circle"></i> กำหนดแล้ว
                            </span>
                            <?php else: ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-amber-500/20 text-amber-300 border border-amber-500/30">
                                <i class="fas fa-exclamation-circle"></i> ยังไม่กำหนด
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <a href="payroll.php?action=salary_setup&user_id=<?php echo (int)$e['id']; ?>" class="btn-payroll inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium bg-violet-600 hover:bg-violet-500 text-white transition-colors min-h-[48px]">
                                <i class="fas fa-edit sm:mr-1"></i><span class="btn-text hidden sm:inline">กำหนดเงินเดือน</span>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($employees)): ?>
        <div class="px-6 py-12 text-center text-white/50">
            <i class="fas fa-users text-4xl mb-3 block"></i>
            ไม่มีพนักงานที่ใช้งานอยู่
        </div>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- หน้าหลัก: สรุปเงินเดือนรายเดือน + การ์ดลิงก์ -->
    <?php if ($latest_run): ?>
    <div class="glass-card rounded-2xl p-6 mb-6 border border-white/10">
        <h3 class="text-base font-semibold text-white mb-4">สรุปรอบเงินเดือนล่าสุด</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="p-4 rounded-xl bg-white/5">
                <p class="text-white/60 text-xs">เดือน</p>
                <p class="text-white font-semibold"><?php echo date('F Y', strtotime($latest_run['payroll_month'])); ?></p>
            </div>
            <div class="p-4 rounded-xl bg-white/5">
                <p class="text-white/60 text-xs">จำนวนคน</p>
                <p class="text-white font-semibold"><?php echo (int)$latest_run['employee_count']; ?> คน</p>
            </div>
            <div class="p-4 rounded-xl bg-white/5">
                <p class="text-white/60 text-xs">ภาษีรวม</p>
                <p class="text-amber-300 font-semibold">฿<?php echo number_format($latest_run['total_tax'], 2); ?></p>
            </div>
            <div class="p-4 rounded-xl bg-white/5">
                <p class="text-white/60 text-xs">สุทธิจ่ายรวม</p>
                <p class="text-emerald-400 font-semibold">฿<?php echo number_format($latest_run['total_net'], 2); ?></p>
            </div>
        </div>
        <div class="mt-3">
            <a href="payroll.php?run_id=<?php echo (int)$latest_run['id']; ?>" class="text-violet-400 hover:text-violet-300 text-sm">ดูสลิปทั้งหมด &rarr;</a>
        </div>
    </div>
    <?php endif; ?>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <?php $_home_default_pay_day = function_exists('payroll_get_default_pay_day') ? payroll_get_default_pay_day() : 25; ?>
        <a href="payroll.php?action=runs" class="glass-card block p-6 rounded-2xl border border-white/10 hover:border-violet-500/50 hover:bg-white/5 transition-all group">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-xl bg-violet-500/20 flex items-center justify-center group-hover:bg-violet-500/30 transition-colors">
                    <i class="fas fa-calendar-alt text-violet-400 text-2xl"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-white text-lg">รอบเงินเดือน</h3>
                    <p class="text-sm text-white/60 mt-0.5">สร้าง/ดูรอบจ่ายรายเดือน (จ่ายวันที่ <?php echo (int)$_home_default_pay_day; ?> โดยเริ่มต้น)</p>
                </div>
            </div>
        </a>
        <a href="payroll.php?action=employees" class="glass-card block p-6 rounded-2xl border border-white/10 hover:border-emerald-500/50 hover:bg-white/5 transition-all group">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-xl bg-emerald-500/20 flex items-center justify-center group-hover:bg-emerald-500/30 transition-colors">
                    <i class="fas fa-user-tie text-emerald-400 text-2xl"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-white text-lg">กำหนดเงินเดือน</h3>
                    <p class="text-sm text-white/60 mt-0.5">ฐานเงินเดือนและรายการหักต่อพนักงาน</p>
                </div>
            </div>
        </a>
        <a href="payroll_docs.php" class="glass-card block p-6 rounded-2xl border border-white/10 hover:border-amber-500/50 hover:bg-white/5 transition-all group">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-xl bg-amber-500/20 flex items-center justify-center group-hover:bg-amber-500/30 transition-colors">
                    <i class="fas fa-file-alt text-amber-400 text-2xl"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-white text-lg">เอกสารออกแบบ</h3>
                    <p class="text-sm text-white/60 mt-0.5">ฟังก์ชัน, DB, API, ภาษี</p>
                </div>
            </div>
        </a>
    </div>
<?php endif; ?>
