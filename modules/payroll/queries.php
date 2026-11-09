<?php
if (!defined('BASE_PATH')) {http_response_code(403);exit;}
/**
 * Payroll DB query functions & helpers
 * Variables available: $pdo, $current_user
 */

/**
 * คำนวณประกันสังคมอัตโนมัติ (ส่วนลูกจ้าง)
 * อัตรา 5% ของฐานค่าจ้างรายเดือน (ฐานเงินเดือน + รายได้ประจำทุกเดือน), ฐานขั้นต่ำ 1,650
 * ก่อน ม.ค. 2569: ฐานสูงสุด 15,000 → สมทบสูงสุด 750 บาท/เดือน
 * ตั้งแต่ ม.ค. 2569: ฐานสูงสุด 17,500 → สมทบสูงสุด 875 บาท/เดือน
 *
 * ถ้าบริษัทยังไม่ได้เข้าระบบประกันสังคม (setting payroll_ss_enabled = 0)
 * จะคืนค่า 0 เพื่อไม่หักประกันสังคม
 */
function payroll_is_ss_enabled() {
    static $enabled = null;
    if ($enabled === null) {
        $val = function_exists('getSetting') ? getSetting('payroll_ss_enabled', '0') : '0';
        $enabled = ($val === '1' || $val === 1 || $val === true);
    }
    return $enabled;
}

function payroll_is_tax_enabled() {
    static $enabled = null;
    if ($enabled === null) {
        $val = function_exists('getSetting') ? getSetting('payroll_tax_enabled', '1') : '1';
        $enabled = ($val === '1' || $val === 1 || $val === true);
    }
    return $enabled;
}

function payroll_is_health_insurance_enabled() {
    static $enabled = null;
    if ($enabled === null) {
        $val = function_exists('getSetting') ? getSetting('payroll_health_insurance_enabled', '0') : '0';
        $enabled = ($val === '1' || $val === 1 || $val === true);
    }
    return $enabled;
}

function payroll_is_group_insurance_enabled() {
    static $enabled = null;
    if ($enabled === null) {
        $val = function_exists('getSetting') ? getSetting('payroll_group_insurance_enabled', '1') : '1';
        $enabled = ($val === '1' || $val === 1 || $val === true);
    }
    return $enabled;
}

/** @return string|null YYYY-MM-01 */
function payroll_get_company_benefit_start(string $setting_key): ?string
{
    $val = trim((string)(function_exists('getSetting') ? getSetting($setting_key, '') : ''));
    if ($val === '') {
        return null;
    }
    if (preg_match('/^(\d{4}-\d{2})(-\d{2})?$/', $val, $m)) {
        return $m[1] . '-01';
    }
    return null;
}

function payroll_company_benefit_start_month_value(string $setting_key): string
{
    $d = payroll_get_company_benefit_start($setting_key);
    return $d ? substr($d, 0, 7) : '';
}

/**
 * HTML <option> list for company benefit start month (empty = immediate).
 */
function payroll_benefit_start_month_options(string $selected_ym = ''): string
{
    $selected_ym = substr(trim($selected_ym), 0, 7);
    $thai_months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $html = '<option value="">ทันทีเมื่อเปิด</option>';
    $cursor = new DateTime('2025-01-01');
    $end = new DateTime('+36 months');
    while ($cursor <= $end) {
        $ym = $cursor->format('Y-m');
        $m = (int)$cursor->format('n');
        $be = (int)$cursor->format('Y') + 543;
        $label = ($thai_months[$m] ?? $ym) . ' ' . $be;
        $sel = ($ym === $selected_ym) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($ym) . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
        $cursor->modify('+1 month');
    }
    return $html;
}

/**
 * HTML <option> list for payroll run month picker.
 */
function payroll_run_month_options(string $selected_ym = ''): string
{
    $selected_ym = substr(trim($selected_ym), 0, 7);
    if ($selected_ym === '') {
        $selected_ym = function_exists('payroll_suggest_payroll_month')
            ? payroll_suggest_payroll_month()
            : date('Y-m');
    }
    $thai_months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $html = '';
    $cursor = new DateTime('-24 months');
    $end = new DateTime('+3 months');
    while ($cursor <= $end) {
        $ym = $cursor->format('Y-m');
        $m = (int)$cursor->format('n');
        $be = (int)$cursor->format('Y') + 543;
        $label = ($thai_months[$m] ?? $ym) . ' ' . $be;
        $sel = ($ym === $selected_ym) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($ym) . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
        $cursor->modify('+1 month');
    }
    return $html;
}

function payroll_save_company_benefit_start(PDO $pdo, string $setting_key, string $raw_month, string $description): void
{
    $stored = preg_match('/^\d{4}-\d{2}$/', trim($raw_month)) ? trim($raw_month) : '';
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, category, description)
        VALUES (?, ?, 'HR', ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)")
        ->execute([$setting_key, $stored, $description]);
}

function payroll_normalize_setting_month(?string $date): ?string
{
    if ($date === null || $date === '') {
        return null;
    }
    if (preg_match('/^(\d{4}-\d{2})/', trim((string)$date), $m)) {
        return $m[1];
    }
    return null;
}

/**
 * วันเริ่มหักที่มีผล = สูงสุดของเดือนเริ่มบริษัท vs พนักงาน (ตามกฎ require employee date)
 */
function payroll_effective_withholding_applies(
    ?string $company_start_date,
    ?string $employee_start_date,
    string $month_first,
    bool $require_employee_start_date
): bool {
    $month_ym = payroll_normalize_setting_month($month_first);
    if ($month_ym === null) {
        return false;
    }

    $company_ym = payroll_normalize_setting_month($company_start_date);
    $employee_ym = payroll_normalize_setting_month($employee_start_date);

    if ($require_employee_start_date && $employee_ym === null) {
        return false;
    }

    $effective_ym = null;
    if ($require_employee_start_date) {
        $effective_ym = $employee_ym;
        if ($company_ym !== null && ($effective_ym === null || $company_ym > $effective_ym)) {
            $effective_ym = $company_ym;
        }
    } elseif ($employee_ym !== null && $company_ym !== null) {
        $effective_ym = max($employee_ym, $company_ym);
    } elseif ($employee_ym !== null) {
        $effective_ym = $employee_ym;
    } elseif ($company_ym !== null) {
        $effective_ym = $company_ym;
    } else {
        return true;
    }

    if ($effective_ym === null) {
        return !$require_employee_start_date;
    }

    return $month_ym >= $effective_ym;
}

function payroll_is_ss_enabled_for_month($month_first = null): bool
{
    if (!payroll_is_ss_enabled()) {
        return false;
    }
    $month_first = $month_first ?: date('Y-m-01');
    return payroll_effective_withholding_applies(
        payroll_get_company_benefit_start('payroll_ss_enabled_from'),
        null,
        $month_first,
        false
    );
}

function payroll_is_tax_enabled_for_month($month_first = null): bool
{
    if (!payroll_is_tax_enabled()) {
        return false;
    }
    $month_first = $month_first ?: date('Y-m-01');
    return payroll_effective_withholding_applies(
        payroll_get_company_benefit_start('payroll_tax_enabled_from'),
        null,
        $month_first,
        false
    );
}

function payroll_is_health_insurance_enabled_for_month($month_first = null): bool
{
    if (!payroll_is_health_insurance_enabled()) {
        return false;
    }
    $month_first = $month_first ?: date('Y-m-01');
    return payroll_effective_withholding_applies(
        payroll_get_company_benefit_start('payroll_health_insurance_enabled_from'),
        null,
        $month_first,
        false
    );
}

function payroll_is_group_insurance_enabled_for_month($month_first = null): bool
{
    if (!payroll_is_group_insurance_enabled()) {
        return false;
    }
    $month_first = $month_first ?: date('Y-m-01');
    return payroll_effective_withholding_applies(
        payroll_get_company_benefit_start('payroll_group_insurance_enabled_from'),
        null,
        $month_first,
        false
    );
}

/**
 * Extra monthly WHT (ล.ย.01) — zero when tax opt-out or company tax disabled.
 *
 * @param array<string,mixed>|null $setup
 */
function payroll_resolve_extra_tax_request(?array $setup, bool $tax_opt_out = false, $month_first = null): float
{
    if ($tax_opt_out || !payroll_is_tax_enabled_for_month($month_first)) {
        return 0.0;
    }
    if (!$setup || !isset($setup['additional_tax_withholding'])) {
        return 0.0;
    }
    return max(0, (float)$setup['additional_tax_withholding']);
}

function payroll_ss_wage_ceiling($month_first = null) {
    $override = function_exists('getSetting') ? getSetting('payroll_ss_max_base', '') : '';
    if ($override !== '' && (float)$override > 0) {
        return (float)$override;
    }
    $ref = $month_first ?: date('Y-m-01');
    return ($ref >= '2026-01-01') ? 17500.0 : 15000.0;
}

function payroll_ss_max_contribution($month_first = null) {
    return round(payroll_ss_wage_ceiling($month_first) * 0.05, 2);
}

function payroll_ss_annual_tax_deduction_cap($month_first = null) {
    return payroll_ss_max_contribution($month_first) * 12;
}

/**
 * รายการรายได้ที่ถือว่า "ไม่ประจำ" — ไม่รวมฐานประกันสังคม (เมื่อไม่ได้ตั้ง ss_exclude ใน JSON)
 *
 * @return list<string>
 */
function payroll_ss_variable_income_labels(): array {
    return [
        'เบี้ยขยัน',
        'ค่าคอมมิชชั่น',
        'ค่าล่วงเวลา (OT)',
        'เงินชดเชยวันทำงาน',
        'ค่าบริการ',
        'OT',
    ];
}

/**
 * รายการรายได้ที่ถือว่า "ประจำทุกเดือน" — รวมฐานประกันสังคม
 *
 * @return list<string>
 */
function payroll_ss_fixed_income_labels(): array {
    return [
        'ค่าตำแหน่ง',
        'ค่าเดินทาง',
        'ค่าครองชีพ',
    ];
}

/**
 * ค่าเริ่มต้น ss_include จากชื่อรายการ (1=รวมฐาน สส., 0=ไม่รวม)
 */
function payroll_default_ss_include_for_label(string $category, string $label): int {
    $label = trim($label);
    if ($label === '') {
        return $category === 'allowance' ? 1 : 0;
    }
    if ($category === 'allowance') {
        return 1;
    }
    if ($category === 'income') {
        if (in_array($label, payroll_ss_fixed_income_labels(), true)) {
            return 1;
        }
        if (in_array($label, payroll_ss_variable_income_labels(), true)) {
            return 0;
        }
        return 0;
    }
    return 0;
}

/**
 * รายการนี้ควรถูกตัดออกจากฐาน สส. หรือไม่
 *
 * @param array<string,mixed> $item
 */
function payroll_item_ss_excluded(array $item, string $category): bool {
    if (array_key_exists('ss_exclude', $item)) {
        return !empty($item['ss_exclude']);
    }
    return payroll_default_ss_include_for_label($category, (string)($item['label'] ?? '')) === 0;
}

/**
 * สำหรับ UI — ติ๊ก "รวมฐานประกันสังคม" หรือไม่
 *
 * @param array<string,mixed> $item
 */
function payroll_item_counts_for_ss(array $item, string $category): bool {
    return !payroll_item_ss_excluded($item, $category);
}

/**
 * ฐานค่าจ้างสำหรับเงินสมทบประกันสังคม ม.33 (ส่วนลูกจ้าง 5%)
 *
 * รวมเฉพาะเงินเดือน + รายได้/เบี้ยเลี้ยงที่จ่ายเท่ากันทุกเดือน
 * ไม่รวมรายการ ss_exclude=1 (เช่น ค่าคอมมิชชั่น เงินชดเชยวันทำงาน)
 *
 * @param array<string,mixed>|null $setup แถว employee_salary_setup
 */
function payroll_ss_wage_base_from_setup($setup): float {
    if (!is_array($setup)) {
        return 0.0;
    }
    $base = (float)($setup['base_salary'] ?? 0);

    $jsonCategories = [
        'allowance_json' => 'allowance',
        'income_other_json' => 'income',
    ];
    foreach ($jsonCategories as $jsonField => $category) {
        if (empty($setup[$jsonField])) {
            continue;
        }
        $items = json_decode((string)$setup[$jsonField], true);
        if (!is_array($items)) {
            continue;
        }
        foreach ($items as $item) {
            if (!is_array($item) || payroll_item_ss_excluded($item, $category)) {
                continue;
            }
            $base += (float)($item['amount'] ?? 0);
        }
    }

    return round(max(0, $base), 2);
}

/**
 * วันเริ่มหักประกันสังคมจากโปรไฟล์พนักงาน (users.social_security_start_date)
 */
function payroll_get_user_ss_start_date(PDO $pdo, int $user_id): ?string {
    return payroll_get_user_date_column($pdo, $user_id, 'social_security_start_date');
}

function payroll_get_user_tax_start_date(PDO $pdo, int $user_id): ?string {
    return payroll_get_user_date_column($pdo, $user_id, 'tax_withholding_start_date');
}

function payroll_get_user_health_insurance_start_date(PDO $pdo, int $user_id): ?string {
    return payroll_get_user_date_column($pdo, $user_id, 'health_insurance_start_date');
}

function payroll_get_user_group_insurance_start_date(PDO $pdo, int $user_id): ?string {
    return payroll_get_user_date_column($pdo, $user_id, 'group_insurance_start_date');
}

function payroll_get_user_date_column(PDO $pdo, int $user_id, string $column): ?string {
    static $cache = [];
    $key = $user_id . ':' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $allowed = [
        'probation_passed_date',
        'social_security_start_date',
        'tax_withholding_start_date',
        'health_insurance_start_date',
        'group_insurance_start_date',
    ];
    if (!in_array($column, $allowed, true)) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT {$column} FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $val = ($row && !empty($row[$column])) ? substr((string)$row[$column], 0, 10) : null;
        $cache[$key] = $val;
        return $val;
    } catch (Throwable $e) {
        $cache[$key] = null;
        return null;
    }
}

/**
 * ตรวจว่ารอบเงินเดือนนี้ควรหักสวัสดิการหรือไม่
 *
 * @param bool $require_start_date true = ยังไม่ระบุวันเริ่มหัก → ไม่หัก
 */
function payroll_benefit_applies_for_month($start_date, $month_first, bool $require_start_date = true): bool {
    if ($start_date === null || $start_date === '') {
        return !$require_start_date;
    }
    $startYm = substr((string)$start_date, 0, 7);
    $monthYm = substr((string)($month_first ?: date('Y-m-01')), 0, 7);
    if ($startYm === '' || $monthYm === '') {
        return false;
    }
    return $monthYm >= $startYm;
}

/**
 * ตรวจว่ารอบเงินเดือนนี้ควรหักประกันสังคมหรือไม่
 * บริษัทเริ่มหักเมื่อถึงเดือนของ social_security_start_date (ไม่หักก่อนผ่านโปร)
 * ถ้ายังไม่ระบุวันเริ่มหัก → ไม่หัก
 */
function payroll_ss_applies_for_month($ss_start_date, $month_first): bool
{
    return payroll_effective_withholding_applies(
        payroll_get_company_benefit_start('payroll_ss_enabled_from'),
        $ss_start_date,
        $month_first,
        true
    );
}

/**
 * ตรวจว่ารอบเงินเดือนนี้ควรหักภาษี ณ ที่จ่ายหรือไม่
 * ว่างพนักงาน = หักได้ทันทีเมื่อเปิด flag · มีวันที่ = หักเมื่อ payroll_month >= max(บริษัท, พนักงาน)
 */
function payroll_tax_applies_for_month($tax_start_date, $month_first): bool
{
    return payroll_effective_withholding_applies(
        payroll_get_company_benefit_start('payroll_tax_enabled_from'),
        $tax_start_date,
        $month_first,
        false
    );
}

/**
 * @return array<string,mixed>
 */
function payroll_audit_benefit_context(PDO $pdo, int $user_id, string $month_first, ?array $setup = null): array
{
    if ($setup === null) {
        $setup = get_salary_setup_for_month($pdo, $user_id, $month_first) ?: [];
    }

    $ssStart = payroll_get_user_ss_start_date($pdo, $user_id);
    $taxStart = payroll_get_user_tax_start_date($pdo, $user_id);
    $hiStart = payroll_get_user_health_insurance_start_date($pdo, $user_id);
    $giStart = payroll_get_user_group_insurance_start_date($pdo, $user_id);
    $ssCompanyFrom = payroll_get_company_benefit_start('payroll_ss_enabled_from');
    $taxCompanyFrom = payroll_get_company_benefit_start('payroll_tax_enabled_from');
    $hiCompanyFrom = payroll_get_company_benefit_start('payroll_health_insurance_enabled_from');
    $giCompanyFrom = payroll_get_company_benefit_start('payroll_group_insurance_enabled_from');

    $ssOptOut = !empty($setup['ss_opt_out']);
    $taxOptOut = !empty($setup['tax_opt_out']);
    $hiOptOut = !empty($setup['hi_opt_out']);
    $giOptOut = !empty($setup['gi_opt_out']);

    $ssEnabled = payroll_is_ss_enabled_for_month($month_first);
    $taxEnabled = payroll_is_tax_enabled_for_month($month_first);
    $hiEnabled = payroll_is_health_insurance_enabled_for_month($month_first);
    $giEnabled = payroll_is_group_insurance_enabled_for_month($month_first);

    $ssDateOk = payroll_ss_applies_for_month($ssStart, $month_first);
    $taxDateOk = payroll_tax_applies_for_month($taxStart, $month_first);
    $hiDateOk = payroll_effective_withholding_applies($hiCompanyFrom, $hiStart, $month_first, true);
    $giDateOk = payroll_effective_withholding_applies($giCompanyFrom, $giStart, $month_first, false);

    return [
        'ss_start' => $ssStart,
        'tax_start' => $taxStart,
        'hi_start' => $hiStart,
        'gi_start' => $giStart,
        'ss_company_from' => $ssCompanyFrom,
        'tax_company_from' => $taxCompanyFrom,
        'hi_company_from' => $hiCompanyFrom,
        'gi_company_from' => $giCompanyFrom,
        'ss_opt_out' => $ssOptOut,
        'tax_opt_out' => $taxOptOut,
        'hi_opt_out' => $hiOptOut,
        'gi_opt_out' => $giOptOut,
        'ss_enabled' => $ssEnabled,
        'tax_enabled' => $taxEnabled,
        'hi_enabled' => $hiEnabled,
        'gi_enabled' => $giEnabled,
        'ss_should_withhold' => $ssEnabled && !$ssOptOut && $ssDateOk,
        'tax_should_withhold' => $taxEnabled && !$taxOptOut && $taxDateOk,
        'hi_should_withhold' => $hiEnabled && !$hiOptOut && $hiDateOk
            && (float)($setup['health_insurance_total_monthly'] ?? 0) > 0,
        'gi_should_withhold' => $giEnabled && !$giOptOut && $giDateOk
            && (float)($setup['group_insurance_total_monthly'] ?? 0) > 0,
        'ss_would_withhold_if_enabled' => !$ssOptOut && $ssDateOk,
        'tax_would_withhold_if_enabled' => !$taxOptOut && $taxDateOk,
    ];
}

/**
 * คำนวณประกันสังคมสำหรับพนักงาน — รวมเงื่อนไขวันเริ่มหักจากโปรไฟล์
 */
function payroll_calc_social_security_for_user(PDO $pdo, int $user_id, $wage_base, $employee_opt_out = false, $month_first = null) {
    $start = payroll_get_user_ss_start_date($pdo, $user_id);
    if (!payroll_ss_applies_for_month($start, $month_first)) {
        return 0.0;
    }
    return payroll_calc_social_security($wage_base, $employee_opt_out, $month_first);
}

/**
 * วันเริ่มงานจาก HR (users.hire_date)
 */
function payroll_get_user_hire_date(PDO $pdo, int $user_id): ?string {
    static $cache = [];
    if (array_key_exists($user_id, $cache)) {
        return $cache[$user_id];
    }
    try {
        $stmt = $pdo->prepare('SELECT hire_date FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $val = ($row && !empty($row['hire_date']))
            ? substr((string)$row['hire_date'], 0, 10)
            : null;
        $cache[$user_id] = $val;
        return $val;
    } catch (Throwable $e) {
        $cache[$user_id] = null;
        return null;
    }
}

/** วันลาออกต้องเป็นสิ้นเดือนปฏิทินเท่านั้น */
function payroll_is_month_end_date(?string $date): bool
{
    if ($date === null || $date === '') {
        return false;
    }
    $monthEnd = date('Y-m-t', strtotime(substr($date, 0, 7) . '-01'));
    return $date === $monthEnd;
}

/** วันลาออกจาก users.termination_date */
function payroll_get_user_termination_date(PDO $pdo, int $user_id): ?string {
    static $cache = [];
    if (array_key_exists($user_id, $cache)) {
        return $cache[$user_id];
    }
    try {
        $stmt = $pdo->prepare('SELECT termination_date FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $val = ($row && !empty($row['termination_date']))
            ? substr((string)$row['termination_date'], 0, 10)
            : null;
        $cache[$user_id] = $val;
        return $val;
    } catch (Throwable $e) {
        $cache[$user_id] = null;
        return null;
    }
}

/**
 * โปรไฟล์เงินเดือนจาก users (probation_salary / salary / probation_passed_date)
 *
 * @return array{probation_salary: ?float, salary: ?float, probation_passed_date: ?string}|null
 */
function payroll_get_user_salary_profile(PDO $pdo, int $user_id): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT probation_salary, salary, probation_passed_date FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $prob = ($row['probation_salary'] !== null && $row['probation_salary'] !== '')
            ? (float)$row['probation_salary'] : null;
        $salary = ($row['salary'] !== null && $row['salary'] !== '')
            ? (float)$row['salary'] : null;
        $passed = !empty($row['probation_passed_date'])
            ? substr((string)$row['probation_passed_date'], 0, 10) : null;
        return [
            'probation_salary' => $prob,
            'salary' => $salary,
            'probation_passed_date' => $passed,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/** ยังอยู่ในช่วงทดลองงานสำหรับรอบเงินเดือนนี้ (ยังไม่มี passed_date หรือ passed หลังสิ้นรอบ cutoff) */
function payroll_is_on_probation_for_month(?array $profile, string $month_first): bool
{
    if (!$profile) {
        return false;
    }
    $passedDate = $profile['probation_passed_date'] ?? null;
    if ($passedDate === null || $passedDate === '') {
        return true;
    }
    $periodEnd = payroll_attendance_period_bounds($month_first)['end'];
    return $passedDate > $periodEnd;
}

/**
 * ฐานเงินเดือนจากโปรไฟล์ HR — ทดลองงานใช้ probation_salary, ผ่านแล้วใช้ salary
 */
function payroll_resolve_profile_base_salary(PDO $pdo, int $user_id, string $month_first): ?float
{
    $profile = payroll_get_user_salary_profile($pdo, $user_id);
    if (!$profile) {
        return null;
    }
    $prob = $profile['probation_salary'];
    $salary = $profile['salary'];
    if (payroll_is_on_probation_for_month($profile, $month_first)) {
        if ($prob !== null && $prob > 0) {
            return $prob;
        }
    } elseif ($salary !== null && $salary > 0) {
        return $salary;
    }
    if ($salary !== null && $salary > 0) {
        return $salary;
    }
    if ($prob !== null && $prob > 0) {
        return $prob;
    }
    return null;
}

/**
 * ฐานเงินเดือนสำหรับคำนวณ — โปรไฟล์ HR ชนะ setup (ยกเว้น preview ที่ระบุ base เอง)
 */
function payroll_resolve_base_salary_for_month(
    PDO $pdo,
    int $user_id,
    string $month_first,
    ?array $setup,
    bool $explicit_base_override = false
): float {
    if ($explicit_base_override && is_array($setup) && array_key_exists('base_salary', $setup)) {
        return (float)$setup['base_salary'];
    }
    $profileBase = payroll_resolve_profile_base_salary($pdo, $user_id, $month_first);
    if ($profileBase !== null && $profileBase > 0) {
        return $profileBase;
    }
    return is_array($setup) ? (float)($setup['base_salary'] ?? 0) : 0.0;
}

/** HR profile owns base salary; setup is only a compatibility projection/fallback. */
function payroll_resolve_setup_base_salary(?float $profileBase, $requestedBase): float
{
    if ($profileBase !== null && $profileBase > 0) {
        return $profileBase;
    }
    return max(0, (float)$requestedBase);
}

function payroll_salary_snapshot_status(?float $profileBase, float $storedGross, float $freshGross): string
{
    if ($profileBase === null || $profileBase <= 0) {
        return 'missing';
    }
    return abs($storedGross - $freshGross) <= 0.01 ? 'synced' : 'changed';
}

/** Block approval when the stored payslip no longer reflects HR's salary source of truth. */
function payroll_assert_run_salary_snapshot_current(PDO $pdo, int $runId): void
{
    $stmt = $pdo->prepare('SELECT payroll_month, pay_day FROM payroll_runs WHERE id = ? LIMIT 1');
    $stmt->execute([$runId]);
    $run = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$run) {
        throw new RuntimeException('ไม่พบรอบเงินเดือน');
    }
    $monthFirst = date('Y-m-01', strtotime((string)$run['payroll_month']));
    $slips = $pdo->prepare('SELECT user_id, gross_salary FROM payroll_slips WHERE payroll_run_id = ?');
    $slips->execute([$runId]);
    $changed = 0;
    $missing = 0;
    foreach ($slips->fetchAll(PDO::FETCH_ASSOC) as $slip) {
        $userId = (int)$slip['user_id'];
        $profileBase = payroll_resolve_profile_base_salary($pdo, $userId, $monthFirst);
        $fresh = payroll_calculate_slip_for_user($pdo, $userId, $monthFirst, (int)$run['pay_day']);
        $status = payroll_salary_snapshot_status($profileBase, (float)$slip['gross_salary'], (float)$fresh['gross_salary']);
        if ($status === 'changed') $changed++;
        if ($status === 'missing') $missing++;
    }
    if ($changed > 0 || $missing > 0) {
        throw new RuntimeException(
            'ยังอนุมัติไม่ได้ — ฐานเงินเดือน HR เปลี่ยนหลังคำนวณ ' . $changed
            . ' คน และไม่พบฐานเงินเดือนใน HR ' . $missing . ' คน กรุณาแก้ข้อมูล HR ให้ครบแล้วคำนวณรอบใหม่'
        );
    }
}

function payroll_inclusive_day_count(string $start, string $end): int {
    $a = strtotime($start);
    $b = strtotime($end);
    if (!$a || !$b || $b < $a) {
        return 0;
    }
    return (int)(($b - $a) / 86400) + 1;
}

/**
 * สัดส่วนจ่ายเงินเดือนตามวันเริ่มงานในรอบเดือนปฏิทิน
 * 0 = ยังไม่เริ่มงานในรอบนี้, 1 = ทำงานครบรอบ, 0<x<1 = เริ่มกลางรอบ
 */
function payroll_hire_prorate_factor($hire_date, string $period_start, string $period_end): float {
    if ($hire_date === null || $hire_date === '') {
        return 0.0;
    }
    if ($hire_date > $period_end) {
        return 0.0;
    }
    if ($hire_date <= $period_start) {
        return 1.0;
    }
    $total = payroll_inclusive_day_count($period_start, $period_end);
    $employed = payroll_inclusive_day_count($hire_date, $period_end);
    return $total > 0 ? round($employed / $total, 6) : 0.0;
}

/**
 * เดือนแรกของพนักงานใหม่ = เดือนปฏิทินเดียวกับ hire_date
 */
function payroll_is_first_hire_payroll_month(?string $hire_date, string $payroll_month): bool
{
    if ($hire_date === null || $hire_date === '') {
        return false;
    }

    return substr($hire_date, 0, 7) === substr($payroll_month, 0, 7);
}

/** เริ่มงานวันที่ 1 ของเดือน → จ่ายเต็มเดือน (ไม่ใช้ ÷30) */
function payroll_is_hire_on_month_first_day(?string $hire_date, string $payroll_month): bool
{
    if ($hire_date === null || $hire_date === '') {
        return false;
    }

    return $hire_date === substr($payroll_month, 0, 7) . '-01';
}

/**
 * ช่วงคำนวณตาม hire/termination — เดือนแรก: วันเริ่ม→สิ้นเดือน · รอบปกติ 26→25
 * · ลาออกสิ้นเดือน: รอบเต็ม 26→25 + ขยายถึงสิ้นเดือน (26–สิ้นเดือน จ่ายเพิ่มแยก)
 *
 * @return array{start: string, end: string, cutoff_day: int, pay_day: int, is_first_hire_month?: bool, is_hire_transition?: bool, is_resignation_month_end?: bool, is_terminated_before_period?: bool, standard_start?: string, standard_end?: string}
 */
function payroll_effective_period_bounds(string $payroll_month, ?int $pay_day, ?string $hire_date, ?string $termination_date = null): array
{
    $bounds = payroll_attendance_period_bounds($payroll_month);
    if ($hire_date !== null && $hire_date !== '') {
        $hireYm = substr($hire_date, 0, 7);
        $payrollYm = substr($payroll_month, 0, 7);
        $hireMonthEnd = date('Y-m-t', strtotime($hireYm . '-01'));

        if ($hireYm === $payrollYm) {
            $bounds['start'] = $hire_date;
            $bounds['end'] = $hireMonthEnd;
            $bounds['is_first_hire_month'] = true;
        } elseif ($payrollYm > $hireYm && $bounds['start'] <= $hireMonthEnd) {
            $dayAfterHireMonth = date('Y-m-d', strtotime($hireMonthEnd . ' +1 day'));
            if ($bounds['start'] < $dayAfterHireMonth) {
                $bounds['standard_start'] = $bounds['start'];
                $bounds['standard_end'] = $bounds['end'];
                $bounds['start'] = $dayAfterHireMonth;
                $bounds['is_hire_transition'] = true;
            }
        }
    }

    if ($termination_date !== null && $termination_date !== '' && payroll_is_month_end_date($termination_date)) {
        $termYm = substr($termination_date, 0, 7);
        $payrollYm = substr($payroll_month, 0, 7);

        if ($termYm < $payrollYm) {
            $bounds['is_terminated_before_period'] = true;
        } elseif ($termYm === $payrollYm) {
            if (!isset($bounds['standard_end'])) {
                $bounds['standard_end'] = $bounds['end'];
            }
            $bounds['end'] = $termination_date;
            $bounds['is_resignation_month_end'] = true;
        }
    }

    return $bounds;
}

function payroll_effective_period_bounds_for_user(PDO $pdo, int $user_id, string $payroll_month, ?int $pay_day = null): array
{
    return payroll_effective_period_bounds(
        $payroll_month,
        $pay_day,
        payroll_get_user_hire_date($pdo, $user_id),
        payroll_get_user_termination_date($pdo, $user_id)
    );
}

/**
 * สัดส่วนรายได้ — เฉพาะเดือนแรก (hire prorate factor สำหรับ SS)
 *
 * @param array{start: string, end: string, is_first_hire_month?: bool} $period
 */
function payroll_hire_income_prorate_factor(?string $hire_date, array $period): float
{
    if ($hire_date === null || $hire_date === '') {
        return 0.0;
    }
    if ($hire_date > $period['end']) {
        return 0.0;
    }

    if (!empty($period['is_first_hire_month'])) {
        $monthStart = substr($hire_date, 0, 7) . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $total = payroll_inclusive_day_count($monthStart, $monthEnd);
        $employed = payroll_inclusive_day_count($period['start'], $period['end']);
        return $total > 0 ? round($employed / $total, 6) : 0.0;
    }

    return payroll_hire_prorate_factor($hire_date, $period['start'], $period['end']);
}

/**
 * สัดส่วนรายได้ในรอบ 26→25 — รองรับเริ่มกลางรอบ (hire) · ลาออกสิ้นเดือนจ่ายเต็มรอบ (tail แยกใน apply_hire_date_income)
 *
 * @param array{start: string, end: string, standard_end?: string, is_first_hire_month?: bool, is_resignation_month_end?: bool, is_terminated_before_period?: bool} $period
 */
function payroll_cycle_employment_factor(?string $hire_date, array $period): float
{
    if (!empty($period['is_terminated_before_period'])) {
        return 0.0;
    }
    if (!empty($period['is_first_hire_month'])) {
        return payroll_hire_income_prorate_factor($hire_date, $period);
    }
    if (!empty($period['is_resignation_month_end'])) {
        return 1.0;
    }

    $standardStart = $period['start'];
    $standardEnd = $period['standard_end'] ?? $period['end'];
    $effectiveStart = $standardStart;
    if ($hire_date !== null && $hire_date !== '' && $hire_date > $effectiveStart) {
        $effectiveStart = $hire_date;
    }
    $effectiveEnd = $standardEnd;

    if ($hire_date !== null && $hire_date !== '' && $hire_date > $effectiveEnd) {
        return 0.0;
    }

    $total = payroll_inclusive_day_count($standardStart, $standardEnd);
    if ($total <= 0) {
        return 0.0;
    }
    $employed = payroll_inclusive_day_count($effectiveStart, $effectiveEnd);
    return round($employed / $total, 6);
}

/**
 * รายได้เพิ่มช่วง 26→สิ้นเดือน เมื่อลาออกสิ้นเดือน (÷30 × วัน)
 *
 * @param array{end: string, standard_end?: string, is_resignation_month_end?: bool} $period
 * @return array{gross_add: float, allowances_add: float, tail_days: int}
 */
function payroll_resignation_tail_income_add(?string $termination_date, array $period, float $base_gross, float $base_allowances): array
{
    $empty = ['gross_add' => 0.0, 'allowances_add' => 0.0, 'tail_days' => 0];
    if (empty($period['is_resignation_month_end']) || $termination_date === null || $termination_date === '') {
        return $empty;
    }
    if (!payroll_is_month_end_date($termination_date)) {
        return $empty;
    }

    $standardEnd = $period['standard_end'] ?? null;
    if ($standardEnd === null || $standardEnd === '') {
        return $empty;
    }

    $tailStart = date('Y-m-d', strtotime($standardEnd . ' +1 day'));
    $tailEnd = $period['end'];
    if ($tailStart > $tailEnd) {
        return $empty;
    }

    $tailDays = payroll_inclusive_day_count($tailStart, $tailEnd);
    if ($tailDays <= 0) {
        return $empty;
    }

    $divisor = payroll_first_hire_daily_divisor();
    return [
        'gross_add' => $base_gross > 0 ? round($tailDays * ($base_gross / $divisor), 2) : 0.0,
        'allowances_add' => $base_allowances > 0 ? round($tailDays * ($base_allowances / $divisor), 2) : 0.0,
        'tail_days' => $tailDays,
    ];
}

/** ตัวหารรายวันเดือนแรก (มาตรฐาน HR ไทย = 30) */
function payroll_first_hire_daily_divisor(): int
{
    $v = (int)getSetting('payroll_first_hire_daily_divisor', '30');
    return max(1, min(31, $v));
}

function payroll_effective_day_off_for_date(array $ctx, string $date): int
{
    $effectiveDayOff = (int)($ctx['day_off'] ?? 0);
    foreach ($ctx['dayoff_requests'] ?? [] as $request) {
        if ($date >= $request['week_start'] && $date <= $request['week_end']) {
            $effectiveDayOff = (int)$request['requested_day_off'];
            break;
        }
    }
    return $effectiveDayOff;
}

function payroll_is_scheduled_day_off(array $ctx, string $date): bool
{
    return (int)date('w', strtotime($date)) === payroll_effective_day_off_for_date($ctx, $date);
}

/** นับวันหยุดประจำ (เช่น อาทิตย์) ทั้งเดือนปฏิทิน — ใช้หักจากช่วงทำงานเดือนแรก */
function payroll_count_day_off_days_in_calendar_month(array $ctx, string $monthYm): int
{
    $monthStart = $monthYm . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    $count = 0;
    for ($ts = strtotime($monthStart); $ts !== false && $ts <= strtotime($monthEnd); $ts += 86400) {
        if (payroll_is_scheduled_day_off($ctx, date('Y-m-d', $ts))) {
            $count++;
        }
    }
    return $count;
}

/** วันจ่ายเดือนแรกต้องใช้ปฏิทินวันทำงานเดียวกับ HR ทีละวัน */
function payroll_first_hire_payable_days(PDO $pdo, int $user_id, array $period, string $hire_date): int
{
    $ctx = payroll_build_workday_context($pdo, $user_id, $period['start'], $period['end']);
    $payable = 0;
    for ($ts = strtotime($period['start']); $ts !== false && $ts <= strtotime($period['end']); $ts += 86400) {
        $date = date('Y-m-d', $ts);
        if (payroll_is_payroll_workday($ctx, $date)) {
            $payable++;
        }
    }

    return max(0, $payable);
}

function payroll_should_include_employee_in_run(PDO $pdo, int $user_id, ?string $hire_date, string $payroll_month, ?int $pay_day): bool
{
    if ($hire_date === null || $hire_date === '') {
        return false;
    }

    $termination_date = payroll_get_user_termination_date($pdo, $user_id);
    $period = payroll_effective_period_bounds($payroll_month, $pay_day, $hire_date, $termination_date);
    if (!empty($period['is_terminated_before_period'])) {
        return false;
    }
    if (!empty($period['is_first_hire_month'])) {
        return payroll_first_hire_payable_days($pdo, $user_id, $period, $hire_date) > 0;
    }

    return payroll_cycle_employment_factor($hire_date, $period) > 0;
}

function payroll_scale_income_other_json(?string $json, float $factor): ?string {
    if (!$json || $factor >= 1.0) {
        return $json;
    }
    if ($factor <= 0) {
        return null;
    }
    $arr = json_decode($json, true);
    if (!is_array($arr)) {
        return $json;
    }
    foreach ($arr as &$item) {
        $item['amount'] = round((float)($item['amount'] ?? 0) * $factor, 2);
    }
    unset($item);
    return json_encode($arr, JSON_UNESCAPED_UNICODE);
}

/**
 * ปรับรายได้ตาม hire_date — เดือนแรก: ÷30 × วันจ่าย (หักวันหยุดประจำทั้งเดือน), โบนัส/รายได้อื่นเต็มจำนวน
 *
 * @return float factor ที่ใช้สำหรับ SS (0–1)
 */
function payroll_apply_hire_date_income(
    PDO $pdo,
    int $user_id,
    string $month_first,
    ?int $pay_day,
    float &$gross,
    float &$bonus,
    float &$allowances,
    float &$income_other,
    ?string &$income_other_json
): float {
    $hire_date = payroll_get_user_hire_date($pdo, $user_id);
    $termination_date = payroll_get_user_termination_date($pdo, $user_id);
    $period = payroll_effective_period_bounds($month_first, $pay_day, $hire_date, $termination_date);

    if (!empty($period['is_first_hire_month'])) {
        $originalGross = $gross;
        $originalAllowances = $allowances;

        if (payroll_is_hire_on_month_first_day($hire_date, $month_first)) {
            return 1.0;
        }

        $payableDays = payroll_first_hire_payable_days($pdo, $user_id, $period, (string)$hire_date);
        $divisor = payroll_first_hire_daily_divisor();

        if ($payableDays <= 0) {
            $gross = $bonus = $allowances = $income_other = 0.0;
            $income_other_json = null;
            return 0.0;
        }

        if ($originalGross > 0) {
            $gross = round($payableDays * ($originalGross / $divisor), 2);
        } else {
            $gross = 0.0;
        }
        if ($originalAllowances > 0) {
            $allowances = round($payableDays * ($originalAllowances / $divisor), 2);
        }
        // bonus & income_other — จ่ายเต็ม ไม่ prorate เดือนแรก

        if ($originalGross > 0) {
            return round($gross / $originalGross, 6);
        }
        return round($payableDays / $divisor, 6);
    }

    $originalGross = $gross;
    $originalAllowances = $allowances;
    $factor = payroll_cycle_employment_factor($hire_date, $period);
    if ($factor <= 0) {
        $gross = $bonus = $allowances = $income_other = 0.0;
        $income_other_json = null;
        return 0.0;
    }
    if ($factor < 1.0) {
        $gross = round($gross * $factor, 2);
        $bonus = round($bonus * $factor, 2);
        $allowances = round($allowances * $factor, 2);
        $income_other = round($income_other * $factor, 2);
        $income_other_json = payroll_scale_income_other_json($income_other_json, $factor);
    }

    if (!empty($period['is_resignation_month_end'])) {
        $tail = payroll_resignation_tail_income_add($termination_date, $period, $originalGross, $originalAllowances);
        if ($tail['gross_add'] > 0) {
            $gross = round($gross + $tail['gross_add'], 2);
        }
        if ($tail['allowances_add'] > 0) {
            $allowances = round($allowances + $tail['allowances_add'], 2);
        }
        // bonus & income_other — จ่ายเต็มรอบ ไม่ prorate tail
        if ($originalGross > 0) {
            return round($gross / $originalGross, 6);
        }
        return 1.0;
    }

    return $factor;
}

function payroll_calc_social_security($wage_base, $employee_opt_out = false, $month_first = null) {
    if (!payroll_is_ss_enabled_for_month($month_first)) return 0;
    if ($employee_opt_out) return 0; // ส่งประกันสังคมที่บริษัทอื่นแล้ว
    $rate = 0.05;
    $min_base = 1650;
    $max_base = payroll_ss_wage_ceiling($month_first);
    if ($wage_base <= 0) return 0;
    $base = max($min_base, min($wage_base, $max_base));
    return round($base * $rate, 2);
}

/**
 * เลือกยอดหักประกันสังคม — อัตโนมัติ หรือค่าที่กำหนดเองใน setup
 *
 * @param array<string,mixed>|null $setup
 */
function payroll_resolve_social_security_amount(?array $setup, float $autoAmount): float
{
    if (!is_array($setup) || empty($setup['social_security_manual'])) {
        return round(max(0, $autoAmount), 2);
    }
    if (!empty($setup['ss_opt_out'])) {
        return 0.0;
    }
    return round(max(0, (float)($setup['social_security'] ?? 0)), 2);
}

/**
 * คำนวณ/รับยอด SS สำหรับบันทึก setup จาก POST
 *
 * @param array<string,mixed> $data
 */
function payroll_resolve_social_security_for_save(PDO $pdo, int $userId, array $data, string $effectiveFrom): array
{
    $ssOptOut = !empty($data['ss_opt_out']);
    $manual = !empty($data['social_security_manual']);
    $auto = payroll_calc_social_security_for_user(
        $pdo,
        $userId,
        payroll_ss_wage_base_from_setup([
            'base_salary' => (float)($data['base_salary'] ?? 0),
            'bonus_fixed' => max(0, (float)($data['bonus_fixed'] ?? 0)),
            'allowance_json' => $data['allowance_json'] ?? null,
            'income_other_json' => $data['income_other_json'] ?? null,
        ]),
        $ssOptOut,
        $effectiveFrom
    );
    if (!$manual) {
        return ['social_security' => $auto, 'social_security_manual' => 0];
    }
    $amount = $ssOptOut ? 0.0 : max(0, (float)($data['social_security'] ?? 0));
    if (!$ssOptOut && payroll_is_ss_enabled_for_month($effectiveFrom)) {
        $amount = min($amount, payroll_ss_max_contribution($effectiveFrom));
    }
    return ['social_security' => round($amount, 2), 'social_security_manual' => 1];
}

/**
 * คำนวณส่วนแบ่งพนักงานของสวัสดิการ (ประกันกลุ่ม / ประกันสุขภาพ)
 */
function payroll_calc_benefit_employee_share($total_monthly, $employer_pct) {
    $total = (float)$total_monthly;
    if ($total <= 0) return 0;
    $employer_pct = max(0, min(100, (float)$employer_pct));
    return round($total * (100 - $employer_pct) / 100, 2);
}

/**
 * คำนวณประกันกลุ่ม (ส่วนที่พนักงานจ่าย)
 */
function payroll_calc_group_insurance($total_monthly, $employer_pct) {
    return payroll_calc_benefit_employee_share($total_monthly, $employer_pct);
}

function payroll_calc_group_insurance_for_user(PDO $pdo, int $user_id, $total_monthly, $employer_pct, $opt_out = false, $month_first = null) {
    if (!payroll_is_group_insurance_enabled_for_month($month_first) || $opt_out || (float)$total_monthly <= 0) {
        return 0.0;
    }
    $month_first = $month_first ?: date('Y-m-01');
    if (!payroll_effective_withholding_applies(
        payroll_get_company_benefit_start('payroll_group_insurance_enabled_from'),
        payroll_get_user_group_insurance_start_date($pdo, $user_id),
        $month_first,
        false
    )) {
        return 0.0;
    }
    return payroll_calc_benefit_employee_share($total_monthly, $employer_pct);
}

function payroll_calc_health_insurance_for_user(PDO $pdo, int $user_id, $total_monthly, $employer_pct, $opt_out = false, $month_first = null) {
    if (!payroll_is_health_insurance_enabled_for_month($month_first) || $opt_out || (float)$total_monthly <= 0) {
        return 0.0;
    }
    $month_first = $month_first ?: date('Y-m-01');
    if (!payroll_effective_withholding_applies(
        payroll_get_company_benefit_start('payroll_health_insurance_enabled_from'),
        payroll_get_user_health_insurance_start_date($pdo, $user_id),
        $month_first,
        true
    )) {
        return 0.0;
    }
    return payroll_calc_benefit_employee_share($total_monthly, $employer_pct);
}

/**
 * Prorate fixed monthly deductions (PF/GI/HI) when employment factor is partial (< 1).
 */
function payroll_benefit_proration_factor(float $hire_factor): float
{
    if ($hire_factor <= 0) {
        return 0.0;
    }
    if ($hire_factor >= 1.0) {
        return 1.0;
    }
    return $hire_factor;
}

function payroll_user_has_other_employer_income(PDO $pdo, int $user_id): bool
{
    static $cache = [];
    if (array_key_exists($user_id, $cache)) {
        return $cache[$user_id];
    }
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(has_other_employer_income, 0) FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user_id]);
        $cache[$user_id] = (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        $cache[$user_id] = false;
    }
    return $cache[$user_id];
}

function payroll_calc_tax_for_user(PDO $pdo, int $user_id, $annual_income, $annual_ss = 0, $annual_pf = 0, $month_first = null, $opt_out = false) {
    if (!payroll_is_tax_enabled_for_month($month_first) || $opt_out || (float)$annual_income <= 0) {
        return 0.0;
    }
    $month_first = $month_first ?: date('Y-m-01');
    if (!payroll_tax_applies_for_month(payroll_get_user_tax_start_date($pdo, $user_id), $month_first)) {
        return 0.0;
    }
    $skip_personal = payroll_user_has_other_employer_income($pdo, $user_id);
    return payroll_tax_monthly($annual_income, $annual_ss, $annual_pf, $month_first, $skip_personal);
}

/**
 * Tax calculation (monthly withholding estimate) — Thai Progressive Tax
 * อ้างอิง: ประมวลรัษฎากร มาตรา 40(1), 42 ทวิ, 47
 *
 * ค่าใช้จ่าย:      50% ของเงินได้ สูงสุด 100,000 บาท/ปี
 * ลดหย่อนส่วนตัว: 60,000 บาท/ปี
 * ประกันสังคม:    ตามจำนวนจริง สูงสุดตามเพดานรายเดือน×12 (750×12 หรือ 875×12)
 * กองทุนสำรองเลี้ยงชีพ: 15% ของเงินได้ สูงสุด 500,000 บาท/ปี
 *                (รวมกับ RMF/SSF/เบี้ยบำนาญ แต่การคำนวณ withholding
 *                 ของนายจ้างใช้เฉพาะ PF อย่างเดียว)
 *
 * Tax Brackets (2568):
 *   0 - 150,000        = ยกเว้น
 *   150,001 - 300,000  = 5%
 *   300,001 - 500,000  = 10%
 *   500,001 - 750,000  = 15%
 *   750,001 - 1,000,000= 20%
 *   1,000,001 - 2M     = 25%
 *   2,000,001 - 5M     = 30%
 *   > 5M               = 35%
 */
function payroll_tax_monthly($annual_income, $annual_ss = 0, $annual_pf = 0, $month_first = null, bool $skip_personal_allowance = false) {
    $expense_allowance = min($annual_income * 0.5, 100000);
    $personal_allowance = $skip_personal_allowance ? 0 : 60000;
    $ss_cap = payroll_ss_annual_tax_deduction_cap($month_first);
    $ss_deduction = min(max(0, $annual_ss), $ss_cap);
    // กองทุนสำรองเลี้ยงชีพ หักได้ 15% ของเงินได้ สูงสุด 500,000/ปี
    $pf_cap = min($annual_income * 0.15, 500000);
    $pf_deduction = min(max(0, $annual_pf), $pf_cap);
    $taxable = $annual_income - $expense_allowance - $personal_allowance - $ss_deduction - $pf_deduction;
    if ($taxable <= 0) return 0;
    $brackets = [
        [150000, 0], [300000, 0.05], [500000, 0.10], [750000, 0.15],
        [1000000, 0.20], [2000000, 0.25], [5000000, 0.30], [PHP_INT_MAX, 0.35]
    ];
    $prev = 0;
    $tax = 0;
    foreach ($brackets as [$limit, $rate]) {
        $band = min($taxable, $limit) - $prev;
        if ($band <= 0) break;
        $tax += $band * $rate;
        $prev = min($taxable, $limit);
        if ($taxable <= $limit) break;
    }
    return round($tax / 12, 2);
}

// Load current salary setup for an employee for a given month
function get_salary_setup_for_month($pdo, $user_id, $month_first): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM employee_salary_setup WHERE user_id = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from DESC, id DESC LIMIT 1");
    $stmt->execute([$user_id, $month_first, $month_first]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** @return string|null YYYY-MM */
function payroll_normalize_scope_month(?string $raw): ?string
{
    $raw = trim((string)($raw ?? ''));
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
        return $raw;
    }
    if (preg_match('/^(\d{4}-\d{2})-\d{2}$/', $raw, $m)) {
        return $m[1];
    }
    return null;
}

/** @return array{from: string, to: string}|null */
function payroll_month_scope_bounds(?string $scopeMonth): ?array
{
    $ym = payroll_normalize_scope_month($scopeMonth);
    if ($ym === null) {
        return null;
    }
    $from = $ym . '-01';
    return ['from' => $from, 'to' => date('Y-m-t', strtotime($from))];
}

function payroll_is_month_scoped_setup_row(?array $row, string $monthFirst): bool
{
    if (!$row || empty($row['effective_from'])) {
        return false;
    }
    $monthEnd = date('Y-m-t', strtotime($monthFirst));
    return substr((string)$row['effective_from'], 0, 7) . '-01' === $monthFirst
        && !empty($row['effective_to'])
        && (string)$row['effective_to'] === $monthEnd;
}

/**
 * โหลด setup สำหรับหน้าแก้ไขจาก run detail — ใช้ overlay เฉพาะเดือนก่อน แล้วค่อย inherit ชุดถาวร
 */
function payroll_get_salary_setup_for_edit_month(PDO $pdo, int $user_id, string $scopeMonth): ?array
{
    $bounds = payroll_month_scope_bounds($scopeMonth);
    if ($bounds === null) {
        return get_salary_setup_for_month($pdo, $user_id, date('Y-m-01'));
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM employee_salary_setup
         WHERE user_id = ? AND effective_from = ? AND effective_to = ?
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$user_id, $bounds['from'], $bounds['to']]);
    $scoped = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($scoped) {
        return $scoped;
    }

    return get_salary_setup_for_month($pdo, $user_id, $bounds['from']) ?: null;
}

// Build JSON array [{label, amount, ss_exclude?},...] from form arrays
function payroll_build_json_from_arrays($labels, $amounts, $custom_labels = [], $ss_includes = null, $category = null) {
    if (!is_array($labels)) $labels = [];
    if (!is_array($amounts)) $amounts = [];
    if (!is_array($custom_labels)) $custom_labels = [];
    if (!is_array($ss_includes)) {
        $ss_includes = [];
    }
    $out = [];
    foreach ($labels as $i => $label) {
        $label = trim($label ?? '');
        if ($label === '__custom__' && isset($custom_labels[$i]) && trim($custom_labels[$i]) !== '') {
            $label = trim($custom_labels[$i]);
        }
        if ($label === '__custom__' || $label === '') {
            $label = '';
        }
        $amount = isset($amounts[$i]) ? (float)$amounts[$i] : 0;
        if ($label !== '' || $amount != 0) {
            $row = ['label' => $label ?: 'รายการ', 'amount' => $amount];
            $cat = is_string($category) ? $category : 'income';
            if (array_key_exists($i, $ss_includes)) {
                $includeSs = !empty($ss_includes[$i]) && (string)$ss_includes[$i] !== '0';
            } else {
                $includeSs = payroll_default_ss_include_for_label($cat, $label ?: 'รายการ') === 1;
            }
            if (!$includeSs) {
                $row['ss_exclude'] = 1;
            }
            $out[] = $row;
        }
    }
    return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : null;
}

/**
 * Unified salary setup save (legacy direct-DB path).
 *
 * @return array{action: string, id: int, recalculated_runs: int, warnings: list<string>}
 */
function payroll_save_salary_setup_bundle(PDO $pdo, int $userId, array $data): array
{
    payroll_ensure_social_security_manual_column($pdo);

    $scopeBounds = payroll_month_scope_bounds($data['scope_month'] ?? null);
    $monthScoped = $scopeBounds !== null;

    if ($monthScoped) {
        $effectiveFrom = $scopeBounds['from'];
        $effectiveTo = $scopeBounds['to'];
    } else {
        $rawEffective = trim((string)($data['effective_from'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}$/', $rawEffective)) {
            $effectiveFrom = $rawEffective . '-01';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawEffective)) {
            $effectiveFrom = substr($rawEffective, 0, 7) . '-01';
        } else {
            $effectiveFrom = '';
        }
        $effectiveTo = null;
    }
    if ($effectiveFrom === '') {
        throw new InvalidArgumentException('เดือนที่มีผลไม่ถูกต้อง');
    }

    $profileBase = payroll_resolve_profile_base_salary($pdo, $userId, $effectiveFrom);
    $baseSalary = payroll_resolve_setup_base_salary($profileBase, $data['base_salary'] ?? 0);

    $bonusFixed = max(0, (float)($data['bonus_fixed'] ?? 0));
    $providentFund = max(0, (float)($data['provident_fund'] ?? 0));
    $groupInsuranceTotal = max(0, (float)($data['group_insurance_total_monthly'] ?? 0));
    $groupInsuranceEmployerPct = max(0, min(100, (float)($data['group_insurance_employer_pct'] ?? 50)));
    $healthInsuranceTotal = max(0, (float)($data['health_insurance_total_monthly'] ?? 0));
    $healthInsuranceEmployerPct = max(0, min(100, (float)($data['health_insurance_employer_pct'] ?? 50)));

    $ssOptOut = !empty($data['ss_opt_out']) ? 1 : 0;
    $taxOptOut = !empty($data['tax_opt_out']) ? 1 : 0;
    $hiOptOut = !empty($data['hi_opt_out']) ? 1 : 0;
    $giOptOut = !empty($data['gi_opt_out']) ? 1 : 0;

    $additionalTax = max(0, (float)($data['additional_tax_withholding'] ?? 0));
    if ($taxOptOut) {
        $additionalTax = 0.0;
    } elseif ($additionalTax > $baseSalary && $baseSalary > 0) {
        throw new InvalidArgumentException(
            'จำนวนภาษีหักเพิ่มรายเดือนต้องไม่เกินฐานเงินเดือน (' . number_format($baseSalary, 2) . ' บาท)'
        );
    }

    $allowanceJson = $data['allowance_json'] ?? null;
    $incomeOtherJson = $data['income_other_json'] ?? null;
    $deductionOtherJson = $data['deduction_other_json'] ?? null;

    $socialSecurityResolved = payroll_resolve_social_security_for_save($pdo, $userId, [
        'base_salary' => $baseSalary,
        'bonus_fixed' => $bonusFixed,
        'allowance_json' => $allowanceJson,
        'income_other_json' => $incomeOtherJson,
        'ss_opt_out' => $ssOptOut,
        'social_security_manual' => !empty($data['social_security_manual']),
        'social_security' => $data['social_security'] ?? 0,
    ], $effectiveFrom);
    $socialSecurity = $socialSecurityResolved['social_security'];
    $socialSecurityManual = $socialSecurityResolved['social_security_manual'];

    $profile = [
        'has_other_employer_income' => !empty($data['has_other_employer_income']) ? 1 : 0,
        'social_security_start_date' => payroll_normalize_optional_date($data['social_security_start_date'] ?? null),
        'tax_withholding_start_date' => payroll_normalize_optional_date($data['tax_withholding_start_date'] ?? null),
        'health_insurance_start_date' => payroll_normalize_optional_date($data['health_insurance_start_date'] ?? null),
        'group_insurance_start_date' => payroll_normalize_optional_date($data['group_insurance_start_date'] ?? null),
    ];

    $warnings = [];
    if (
        payroll_is_health_insurance_enabled()
        && !$hiOptOut
        && $healthInsuranceTotal > 0
        && empty($profile['health_insurance_start_date'])
    ) {
        $warnings[] = 'hi_missing_start_date';
    }

    $notes = trim((string)($data['notes'] ?? ''));
    $setup = [
        'effective_from' => $effectiveFrom,
        'effective_to' => $effectiveTo,
        'month_scoped' => $monthScoped,
        'base_salary' => $baseSalary,
        'bonus_fixed' => $bonusFixed,
        'provident_fund' => $providentFund,
        'social_security' => $socialSecurity,
        'social_security_manual' => $socialSecurityManual,
        'group_insurance_total_monthly' => $groupInsuranceTotal,
        'group_insurance_employer_pct' => $groupInsuranceEmployerPct,
        'health_insurance_total_monthly' => $healthInsuranceTotal,
        'health_insurance_employer_pct' => $healthInsuranceEmployerPct,
        'ss_opt_out' => $ssOptOut,
        'tax_opt_out' => $taxOptOut,
        'hi_opt_out' => $hiOptOut,
        'gi_opt_out' => $giOptOut,
        'additional_tax_withholding' => $additionalTax,
        'allowance_json' => $allowanceJson,
        'income_other_json' => $incomeOtherJson,
        'deduction_other_json' => $deductionOtherJson,
        'notes' => $notes !== '' ? $notes : null,
        'created_by' => isset($data['created_by']) ? (int)$data['created_by'] : null,
    ];

    $pdo->beginTransaction();
    try {
        $setupResult = payroll_persist_salary_setup_row($pdo, $userId, $setup);
        payroll_update_user_benefit_profile($pdo, $userId, $profile);
        $recalcCount = $monthScoped
            ? payroll_recalculate_run_month_for_user($pdo, $userId, $effectiveFrom)
            : payroll_recalculate_open_runs_for_user($pdo, $userId, $effectiveFrom);
        $pdo->commit();
        return array_merge($setupResult, [
            'recalculated_runs' => $recalcCount,
            'month_scoped' => $monthScoped,
            'scope_month' => $monthScoped ? substr($effectiveFrom, 0, 7) : null,
            'warnings' => $warnings,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function payroll_normalize_optional_date($raw): ?string
{
    $raw = trim((string)($raw ?? ''));
    return $raw !== '' ? $raw : null;
}

function payroll_close_prior_salary_setup_versions(PDO $pdo, int $userId, string $effectiveFrom): void
{
    $prevEnd = date('Y-m-d', strtotime($effectiveFrom . ' -1 day'));
    $pdo->prepare(
        'UPDATE employee_salary_setup SET effective_to = ?
         WHERE user_id = ? AND effective_from < ? AND (effective_to IS NULL OR effective_to >= ?)'
    )->execute([$prevEnd, $userId, $effectiveFrom, $effectiveFrom]);
}

/**
 * Local create/recalculate payroll run (non-API mode).
 *
 * @return array{run_id: int, id?: int, is_recalculation?: bool}
 */
function payroll_create_run_local(PDO $pdo, string $month, int $createdBy, ?int $payDay = null): array
{
    @set_time_limit(300);
    payroll_ensure_social_security_manual_column($pdo);

    $monthFirst = preg_match('/^\d{4}-\d{2}-\d{2}$/', $month) ? $month : ($month . '-01');
    $monthKey = substr($monthFirst, 0, 7);
    $payDayIn = payroll_resolve_run_pay_day($monthKey, $payDay);

    $stmt = $pdo->prepare('SELECT id, status FROM payroll_runs WHERE payroll_month = ?');
    $stmt->execute([$monthFirst]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing && in_array($existing['status'], ['approved', 'paid'], true)) {
        throw new RuntimeException(
            'ไม่สามารถคำนวณใหม่ได้ — รอบเดือนนี้' . ($existing['status'] === 'approved' ? 'อนุมัติแล้ว' : 'จ่ายเงินแล้ว') . ' กรุณายกเลิกการอนุมัติก่อน'
        );
    }

    $pdo->beginTransaction();
    try {
        $runId = $existing ? (int)$existing['id'] : 0;
        if ($runId > 0) {
            $lockRun = $pdo->prepare('SELECT status FROM payroll_runs WHERE id=? FOR UPDATE');
            $lockRun->execute([$runId]);
            if (!in_array($lockRun->fetchColumn(), ['draft', 'calculated'], true)) {
                throw new \RuntimeException('สถานะรอบเปลี่ยนแล้ว กรุณาโหลดหน้าใหม่ก่อนคำนวณ');
            }
            if (payroll_employee_finance_link_table_exists($pdo)) {
                $pdo->prepare("UPDATE hr_employee_finance_payroll_links SET link_status='reversed',reversed_at=NOW(),settled_at=NULL WHERE payroll_run_id=? AND link_status='included'")
                    ->execute([$runId]);
            }
            $pdo->prepare('DELETE FROM payroll_slips WHERE payroll_run_id = ?')->execute([$runId]);
            $pdo->prepare('UPDATE payroll_runs SET pay_day = ? WHERE id = ?')->execute([$payDayIn, $runId]);
        } else {
            $pdo->prepare("INSERT INTO payroll_runs (payroll_month, pay_day, status, created_by) VALUES (?, ?, 'draft', ?)")
                ->execute([$monthFirst, $payDayIn, $createdBy]);
            $runId = (int)$pdo->lastInsertId();
        }

        $users = $pdo->query('SELECT id, hire_date FROM users u WHERE u.is_active = 1 AND ' . payroll_employee_filter_sql('u'))
            ->fetchAll(PDO::FETCH_ASSOC);
        $period = payroll_attendance_period_bounds($monthFirst, $payDayIn);
        if (date('Y-m-d') < $period['start']) {
            throw new RuntimeException('ยังไม่ถึงรอบเงินเดือนนี้ (ช่วงเวลาทำงานเริ่ม ' . $period['start'] . ') — กรุณาเลือกเดือนที่จ่ายที่ผ่านวันตัดรอบแล้ว');
        }

        $totalGross = $totalTax = $totalNet = 0;
        $includedCount = 0;
        foreach ($users as $userRow) {
            $uid = (int)$userRow['id'];
            if (!payroll_should_include_employee_in_run($pdo, $uid, $userRow['hire_date'] ?? null, $monthFirst, $payDayIn)) {
                continue;
            }
            $includedCount++;
            try {
                $calc = payroll_calculate_slip_for_user($pdo, $uid, $monthFirst, $payDayIn);
                payroll_persist_calculated_slip($pdo, $runId, $uid, $calc, 'insert');
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'คำนวณสลิปพนักงาน #' . $uid . ' ไม่สำเร็จ — ' . $e->getMessage(),
                    0,
                    $e
                );
            }
            $totalGross += $calc['total_income'];
            $totalTax += $calc['tax_withheld'];
            $totalNet += $calc['net_salary'];
        }

        $updateSql = "UPDATE payroll_runs SET employee_count = ?, total_gross = ?, total_tax = ?, total_net = ?, status = 'calculated'";
        $updateParams = [$includedCount, $totalGross, $totalTax, $totalNet];
        if ($existing) {
            $updateSql .= ', updated_at = NOW()';
        }
        $updateSql .= ' WHERE id = ?';
        $updateParams[] = $runId;
        try {
            $pdo->prepare($updateSql)->execute($updateParams);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'updated_at') !== false) {
                $pdo->prepare("UPDATE payroll_runs SET employee_count = ?, total_gross = ?, total_tax = ?, total_net = ?, status = 'calculated' WHERE id = ?")
                    ->execute([$includedCount, $totalGross, $totalTax, $totalNet, $runId]);
            } else {
                throw $e;
            }
        }

        $pdo->commit();
        return [
            'run_id' => $runId,
            'id' => $runId,
            'is_recalculation' => (bool)$existing,
            'month' => $monthKey,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @return list<string>
 */
function payroll_salary_setup_persist_cols(PDO $pdo): array
{
    static $cache = [];
    $key = spl_object_hash($pdo);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $cols = [
        'base_salary', 'bonus_fixed', 'provident_fund', 'social_security', 'social_security_manual',
        'group_insurance_total_monthly', 'group_insurance_employer_pct',
        'health_insurance_total_monthly', 'health_insurance_employer_pct',
        'ss_opt_out', 'tax_opt_out', 'hi_opt_out', 'gi_opt_out',
        'additional_tax_withholding',
        'allowance_json', 'income_other_json', 'deduction_other_json', 'notes', 'created_by',
    ];
    if (!payroll_table_has_column($pdo, 'employee_salary_setup', 'social_security_manual')) {
        $cols = array_values(array_filter($cols, static fn(string $c): bool => $c !== 'social_security_manual'));
    }
    $cache[$key] = $cols;
    return $cols;
}

function payroll_table_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            $stmt = $pdo->query('PRAGMA table_info(' . $safeTable . ')');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }
        $chk = $pdo->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $chk->execute([$table, $column]);
        return (bool)$chk->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function payroll_assert_social_security_manual_schema(PDO $pdo, array $data): void
{
    if (empty($data['social_security_manual'])) {
        return;
    }
    payroll_ensure_social_security_manual_column($pdo);
}

/**
 * Idempotent schema heal — migration may be skipped on deploy; ensure column before manual SS saves.
 */
function payroll_ensure_social_security_manual_column(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_hash($pdo);
    if (!empty($done[$key])) {
        return;
    }
    $done[$key] = true;

    if (payroll_table_has_column($pdo, 'employee_salary_setup', 'social_security_manual')) {
        return;
    }

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        return;
    }

    try {
        $pdo->exec(
            "ALTER TABLE employee_salary_setup
             ADD COLUMN social_security_manual TINYINT(1) NOT NULL DEFAULT 0
             COMMENT '1=ใช้ยอด social_security ที่กำหนดเอง, 0=คำนวณอัตโนมัติ'"
        );
    } catch (Throwable $e) {
        error_log('[payroll] social_security_manual column ensure failed: ' . $e->getMessage());
        throw new InvalidArgumentException(
            'ไม่สามารถอัปเดตฐานข้อมูลสำหรับกำหนดยอดประกันสังคมเองได้ — กรุณาแจ้งผู้ดูแลระบบ'
        );
    }
}

/**
 * @param array<string,mixed> $setup
 * @return array{action: string, id: int}
 */
function payroll_persist_salary_setup_row(PDO $pdo, int $userId, array $setup): array
{
    $effectiveFrom = $setup['effective_from'];
    $effectiveTo = $setup['effective_to'] ?? null;
    $monthScoped = !empty($setup['month_scoped']);
    $cols = payroll_salary_setup_persist_cols($pdo);

    if ($monthScoped && $effectiveTo) {
        return payroll_persist_month_scoped_salary_setup($pdo, $userId, $setup, $cols);
    }

    $chk = $pdo->prepare('SELECT id FROM employee_salary_setup WHERE user_id = ? AND effective_from = ? ORDER BY id DESC LIMIT 1');
    $chk->execute([$userId, $effectiveFrom]);
    $existing = $chk->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $sets = 'effective_to = ?, ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ', updated_at = NOW()';
        $pdo->prepare("UPDATE employee_salary_setup SET $sets WHERE id = ?")
            ->execute([$effectiveTo, ...array_map(fn($c) => $setup[$c] ?? null, $cols), $existing['id']]);
        return ['action' => 'updated', 'id' => (int)$existing['id']];
    }

    payroll_close_prior_salary_setup_versions($pdo, $userId, $effectiveFrom);

    $ph = implode(',', array_fill(0, count($cols) + 3, '?'));
    $pdo->prepare('INSERT INTO employee_salary_setup (user_id, effective_from, effective_to, ' . implode(',', $cols) . ") VALUES ($ph)")
        ->execute([$userId, $effectiveFrom, $effectiveTo, ...array_map(fn($c) => $setup[$c] ?? null, $cols)]);
    return ['action' => 'created', 'id' => (int)$pdo->lastInsertId()];
}

function payroll_salary_setup_row_is_open_ended(?string $effectiveTo): bool
{
    return $effectiveTo === null || trim($effectiveTo) === '';
}

/**
 * Month overlay uses the same effective_from as permanent row (uk_user_effective_from) — always UPDATE, never INSERT duplicate.
 *
 * @param list<string> $cols
 * @return array{action: string, id: int}
 */
function payroll_persist_month_scoped_salary_setup(PDO $pdo, int $userId, array $setup, array $cols): array
{
    $effectiveFrom = $setup['effective_from'];
    $effectiveTo = $setup['effective_to'];

    $exactStmt = $pdo->prepare(
        'SELECT id FROM employee_salary_setup
         WHERE user_id = ? AND effective_from = ? AND effective_to = ?
         ORDER BY id DESC LIMIT 1'
    );
    $exactStmt->execute([$userId, $effectiveFrom, $effectiveTo]);
    $exact = $exactStmt->fetch(PDO::FETCH_ASSOC);
    if ($exact) {
        $sets = 'effective_to = ?, ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ', updated_at = NOW()';
        $pdo->prepare("UPDATE employee_salary_setup SET $sets WHERE id = ?")
            ->execute([$effectiveTo, ...array_map(fn($c) => $setup[$c] ?? null, $cols), $exact['id']]);
        return ['action' => 'updated', 'id' => (int)$exact['id']];
    }

    $atFromStmt = $pdo->prepare(
        'SELECT * FROM employee_salary_setup WHERE user_id = ? AND effective_from = ? ORDER BY id DESC LIMIT 1'
    );
    $atFromStmt->execute([$userId, $effectiveFrom]);
    $atFrom = $atFromStmt->fetch(PDO::FETCH_ASSOC);

    if ($atFrom) {
        $action = 'overlay_update';
        if (payroll_salary_setup_row_is_open_ended($atFrom['effective_to'] ?? null)) {
            payroll_clone_permanent_salary_to_next_month_if_absent($pdo, $userId, $atFrom, $effectiveTo, $cols);
            $action = 'split_overlay';
        }
        $sets = 'effective_to = ?, ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ', updated_at = NOW()';
        $pdo->prepare("UPDATE employee_salary_setup SET $sets WHERE id = ?")
            ->execute([$effectiveTo, ...array_map(fn($c) => $setup[$c] ?? null, $cols), $atFrom['id']]);
        return ['action' => $action, 'id' => (int)$atFrom['id']];
    }

    $ph = implode(',', array_fill(0, count($cols) + 3, '?'));
    $pdo->prepare('INSERT INTO employee_salary_setup (user_id, effective_from, effective_to, ' . implode(',', $cols) . ") VALUES ($ph)")
        ->execute([$userId, $effectiveFrom, $effectiveTo, ...array_map(fn($c) => $setup[$c] ?? null, $cols)]);
    return ['action' => 'created', 'id' => (int)$pdo->lastInsertId()];
}

/**
 * Preserve open-ended permanent terms from month overlay split when next month row does not exist yet.
 *
 * @param array<string,mixed> $permanentRow
 * @param list<string> $cols
 */
function payroll_clone_permanent_salary_to_next_month_if_absent(PDO $pdo, int $userId, array $permanentRow, string $overlayEnd, array $cols): void
{
    $nextMonthStart = date('Y-m-d', strtotime($overlayEnd . ' +1 day'));
    $futureStmt = $pdo->prepare(
        'SELECT id FROM employee_salary_setup WHERE user_id = ? AND effective_from = ? ORDER BY id DESC LIMIT 1'
    );
    $futureStmt->execute([$userId, $nextMonthStart]);
    if ($futureStmt->fetch(PDO::FETCH_ASSOC)) {
        return;
    }
    $ph = implode(',', array_fill(0, count($cols) + 3, '?'));
    $pdo->prepare('INSERT INTO employee_salary_setup (user_id, effective_from, effective_to, ' . implode(',', $cols) . ") VALUES ($ph)")
        ->execute([
            $userId,
            $nextMonthStart,
            null,
            ...array_map(fn($c) => $permanentRow[$c] ?? null, $cols),
        ]);
}

/**
 * @param array<string,mixed> $profile
 */
function payroll_update_user_benefit_profile(PDO $pdo, int $userId, array $profile): void
{
    try {
        $pdo->prepare('UPDATE users SET has_other_employer_income = ?, social_security_start_date = ?, tax_withholding_start_date = ?, health_insurance_start_date = ?, group_insurance_start_date = ? WHERE id = ?')
            ->execute([
                $profile['has_other_employer_income'] ?? 0,
                $profile['social_security_start_date'],
                $profile['tax_withholding_start_date'],
                $profile['health_insurance_start_date'],
                $profile['group_insurance_start_date'],
                $userId,
            ]);
    } catch (Throwable $e) {
        try {
            $pdo->prepare('UPDATE users SET has_other_employer_income = ? WHERE id = ?')
                ->execute([$profile['has_other_employer_income'] ?? 0, $userId]);
        } catch (Throwable $e2) {
            /* legacy schema */
        }
    }
}

function payroll_recalculate_open_runs_for_user(PDO $pdo, int $userId, string $effectiveFrom): int
{
    $effMonth = substr($effectiveFrom, 0, 7) . '-01';
    $stmt = $pdo->prepare("SELECT id, payroll_month FROM payroll_runs WHERE status IN ('draft','calculated') AND payroll_month >= ?");
    $stmt->execute([$effMonth]);
    $count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        payroll_recalculate_employee_slip($pdo, (int)$row['id'], $userId, $row['payroll_month']);
        payroll_update_run_totals($pdo, (int)$row['id']);
        $count++;
    }
    return $count;
}

/** Recalculate only the payroll run for one calendar month (month-scoped setup save). */
function payroll_recalculate_run_month_for_user(PDO $pdo, int $userId, string $monthFirst): int
{
    $monthFirst = preg_match('/^\d{4}-\d{2}-\d{2}$/', $monthFirst) ? $monthFirst : (substr($monthFirst, 0, 7) . '-01');
    $stmt = $pdo->prepare("SELECT id, payroll_month FROM payroll_runs WHERE status IN ('draft','calculated') AND payroll_month = ? LIMIT 1");
    $stmt->execute([$monthFirst]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 0;
    }
    payroll_recalculate_employee_slip($pdo, (int)$row['id'], $userId, $row['payroll_month']);
    payroll_update_run_totals($pdo, (int)$row['id']);
    return 1;
}

// Format employee code
function payroll_format_employee_code($code) {
    return $code ?: '';
}

/**
 * Compute the actual payment date for a run.
 *  - caps pay_day to the last day of the month (e.g. Feb)
 *  - returns Y-m-d ('' on invalid input)
 */
function payroll_format_pay_date($payroll_month, $pay_day): string {
    if (!$payroll_month) return '';
    $ts = strtotime($payroll_month);
    if (!$ts) return '';
    $day = max(1, min(31, (int)$pay_day));
    $day = min($day, (int)date('t', $ts));
    return date('Y-m-', $ts) . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
}

/** Full date for an employee, including dates outside the payroll month. */
function payroll_slip_payment_date(PDO $pdo, array $slip): string {
    return \TpCommon\Hr\PayrollPaymentDate::resolve($pdo, $slip);
}

function payroll_payment_date_label(string $ymd): string {
    static $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $ts = strtotime($ymd);
    return (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . ((int)date('Y', $ts) + 543);
}

/** Canonical salary date, kept local so isolated CRM tests do not depend on a deployed tp-common version. */
function payroll_canonical_payment_date(string $month): string {
    if (!preg_match('/^(\d{4})-(\d{2})(?:-\d{2})?$/', trim($month), $m)) {
        throw new \InvalidArgumentException('Invalid payroll month');
    }
    $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1] . '-' . $m[2] . '-01');
    if (!$first) {
        throw new \InvalidArgumentException('Invalid payroll month');
    }
    $payment = $first->modify('last day of this month');
    if ($payment->format('w') === '0') {
        $payment = $payment->modify('-1 day');
    }
    return $payment->format('Y-m-d');
}

/**
 * Resolve a run's payment day. An omitted value follows the company policy
 * (month end, or the preceding Saturday when month end is Sunday). A supplied
 * value is an explicit one-run exception and must exist in that calendar month.
 */
function payroll_resolve_run_pay_day(string $month, ?int $pay_day = null): int {
    $canonical = payroll_canonical_payment_date($month);
    if ($pay_day === null) {
        return (int) substr($canonical, 8, 2);
    }
    $monthTs = strtotime(substr($month, 0, 7) . '-01');
    if (!$monthTs) {
        throw new InvalidArgumentException('เดือนเงินเดือนไม่ถูกต้อง');
    }
    $lastDay = (int) date('t', $monthTs);
    if ($pay_day < 1 || $pay_day > $lastDay) {
        throw new InvalidArgumentException('วันที่จ่ายต้องอยู่ระหว่าง 1–' . $lastDay . ' สำหรับเดือนที่เลือก');
    }
    return $pay_day;
}

/**
 * Return a Thai-formatted label for a pay date, e.g. "25 เม.ย. 2569".
 * Returns '' if no date.
 */
function payroll_format_pay_date_thai($payroll_month, $pay_day): string {
    $ymd = payroll_format_pay_date($payroll_month, $pay_day);
    if ($ymd === '') return '';
    static $thai_abbr = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $ts = strtotime($ymd);
    return (int) date('j', $ts) . ' ' . $thai_abbr[(int) date('n', $ts)] . ' ' . ((int) date('Y', $ts) + 543);
}

/**
 * Current default pay day (1..31) from system_settings; fallback 25.
 * Used for payment date on slips only — not for calculation period bounds.
 */
function payroll_get_default_pay_day(): int {
    return 31;
}

/**
 * วันตัดรอบเงินเดือน (1..31) — default 25 → ช่วงคำนวณ 26 เดือนก่อน ถึง 25 เดือนรอบ
 */
function payroll_get_cutoff_day(): int {
    $raw = function_exists('getSetting') ? (int) getSetting('payroll_cutoff_day', '25') : 25;
    return ($raw >= 1 && $raw <= 31) ? $raw : 25;
}

/**
 * ช่วงคำนวณรอบปกติ: (วันตัด+1) ของเดือนก่อน → วันตัดของเดือน payroll_month
 * cutoff=25 → 26 ของเดือนก่อน ถึง 25 ของเดือนนั้น · ไม่ใช้ pay_day (วันจ่าย)
 *
 * @return array{start: string, end: string, cutoff_day: int, pay_day: int}
 */
function payroll_attendance_period_bounds(string $payroll_month, ?int $pay_day = null): array {
    $cutoff = payroll_get_cutoff_day();

    $ts = strtotime($payroll_month);
    if (!$ts) {
        $today = date('Y-m-d');
        return ['start' => $today, 'end' => $today, 'cutoff_day' => $cutoff, 'pay_day' => $cutoff];
    }

    $period_end = payroll_format_pay_date($payroll_month, $cutoff);
    if ($period_end === '') {
        $period_end = date('Y-m-d', $ts);
    }

    $monthFirstTs = strtotime(date('Y-m-01', $ts));
    $prevTs = strtotime('-1 month', $monthFirstTs);
    $prevLast = (int) date('t', $prevTs);
    $prevCutoff = min($cutoff, $prevLast);
    $startDay = $prevCutoff + 1;

    if ($startDay > $prevLast) {
        $period_start = date('Y-m-01', $monthFirstTs);
    } else {
        $period_start = date('Y-m-', $prevTs) . str_pad((string) $startDay, 2, '0', STR_PAD_LEFT);
    }

    return [
        'start' => $period_start,
        'end' => $period_end,
        'cutoff_day' => $cutoff,
        'pay_day' => $cutoff,
    ];
}

/**
 * วันสุดท้ายที่นับขาด/สรุปได้ — เฉพาะวันที่ทำงานจบแล้ว (ไม่รวมวันนี้ถ้ารอบยังไม่ปิด)
 * ป้องกัน false absent วันที่ 26 เมื่อคำนวณรอบถัดไป หรือคำนวณกลางวัน
 *
 * @return string Y-m-d หรือ '' ถ้ายังไม่มีวันทำงานครบในรอบ
 */
function payroll_attendance_closed_scan_end(string $period_start, string $period_end, ?string $as_of = null): string {
    $as_of = $as_of ?? date('Y-m-d');
    if ($as_of > $period_end) {
        return $period_end;
    }
    $yesterday = date('Y-m-d', strtotime($as_of . ' -1 day'));
    if ($yesterday < $period_start) {
        return '';
    }
    return $yesterday;
}

/** วันนี้ปิดรอบแล้วหรือยัง — ใช้กรองหักขาด/มาสายจาก hr_attendances */
function payroll_is_attendance_deductible_date(string $date, string $missing_scan_end): bool
{
    if ($missing_scan_end === '') {
        return false;
    }
    return $date <= $missing_scan_end;
}

/**
 * เดือนที่จ่าย (YYYY-MM) ที่ควรคำนวณ ณ วันที่ $as_of
 * หลังวันตัดรอบ → เดือนปัจจุบัน · ก่อนหรือเท่าวันตัด → เดือนก่อน
 */
function payroll_suggest_payroll_month(?int $pay_day = null, ?string $as_of = null): string {
    $cutoff = payroll_get_cutoff_day();
    $as_of = $as_of ?? date('Y-m-d');
    $day = (int) date('j', strtotime($as_of));
    $monthFirstTs = strtotime(date('Y-m-01', strtotime($as_of)));
    if ($day > $cutoff) {
        return date('Y-m', $monthFirstTs);
    }
    return date('Y-m', strtotime('-1 month', $monthFirstTs));
}

/** รอบเงินเดือนปิดแล้วหรือยัง (ผ่านวันตัดรอบของเดือนนั้นแล้ว) */
function payroll_is_period_closed(string $payroll_month, ?int $pay_day = null, ?string $as_of = null): bool {
    $period = payroll_attendance_period_bounds($payroll_month);
    $as_of = $as_of ?? date('Y-m-d');
    return $as_of > $period['end'];
}

/**
 * ป้ายช่วงรอบเงินเดือน เช่น "26 เม.ย. – 25 พ.ค. 2569"
 */
function payroll_format_period_range_thai(string $payroll_month, ?int $pay_day = null, ?string $hire_date = null, ?string $termination_date = null): string {
    $period = ($hire_date !== null && $hire_date !== '') || ($termination_date !== null && $termination_date !== '')
        ? payroll_effective_period_bounds($payroll_month, $pay_day, $hire_date, $termination_date)
        : payroll_attendance_period_bounds($payroll_month);
    static $abbr = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $startTs = strtotime($period['start']);
    $endTs = strtotime($period['end']);
    if (!$startTs || !$endTs) {
        return '';
    }
    return sprintf(
        '%d %s – %d %s %d',
        (int) date('j', $startTs),
        $abbr[(int) date('n', $startTs)],
        (int) date('j', $endTs),
        $abbr[(int) date('n', $endTs)],
        (int) date('Y', $endTs) + 543
    );
}

/**
 * Shared filter for payroll-eligible employees.
 * Customer/contractor accounts use CR* employee codes; blank codes are still
 * internal users and must not disappear from payroll aggregates or reports.
 */
function payroll_employee_filter_sql(string $alias = 'u'): string {
    $person = function_exists('tp_crm_person_user_condition_sql')
        ? tp_crm_person_user_condition_sql($alias)
        : "{$alias}.username NOT IN ('admin','line_official','ci_staff','ci_chairman','ci_ceo')";
    return "(({$alias}.employee_code IS NULL OR {$alias}.employee_code NOT LIKE 'CR%') AND {$person})";
}

/** นาทีขั้นต่ำของ tier มาสาย 150 บาท (default 20 = 20–30 นาที) */
function payroll_late_tier1_min_minutes(): int {
    $min = (int) getSetting('payroll_late_tier1_min_minutes', '20');
    return max(1, min(59, $min));
}

/**
 * จัด bucket มาสายสำหรับหักเงิน
 *
 * @return 'late_30'|'late_60'|'late_over60'|null null = ยังไม่ถึงเกณฑ์หัก
 */
function payroll_classify_late_minutes(int $late_minutes, ?int $tier1_min = null): ?string {
    $tier1 = $tier1_min ?? payroll_late_tier1_min_minutes();
    if ($late_minutes < $tier1) {
        return null;
    }
    if ($late_minutes <= 30) {
        return 'late_30';
    }
    if ($late_minutes <= 60) {
        return 'late_60';
    }
    return 'late_over60';
}

/**
 * @param array<string,mixed> $result
 */
function payroll_apply_late_minutes_deduction(
    array &$result,
    string $date,
    int $late_min,
    float $rate_absent,
    float $rate_late30,
    float $rate_late60,
    bool $late_over60_as_absent,
    ?int $tier1_min = null,
    string $note_suffix = ''
): void {
    $tier = payroll_classify_late_minutes($late_min, $tier1_min);
    if ($tier === null) {
        return;
    }
    $note = "มาสาย {$late_min} นาที{$note_suffix}";
    if ($tier === 'late_30') {
        $result['late_count_30']++;
        $result['lateness_deduction'] += $rate_late30;
        $result['breakdown'][] = ['date' => $date, 'kind' => 'late_30', 'amount' => $rate_late30, 'note' => $note];
        return;
    }
    if ($tier === 'late_60') {
        $result['late_count_60']++;
        $result['lateness_deduction'] += $rate_late60;
        $result['breakdown'][] = ['date' => $date, 'kind' => 'late_60', 'amount' => $rate_late60, 'note' => $note];
        return;
    }
    if ($late_over60_as_absent) {
        $result['absent_days'] += 1;
        $result['breakdown'][] = ['date' => $date, 'kind' => 'late_over60_absent', 'amount' => $rate_absent, 'note' => $note . ' (ตีเป็นขาด)'];
        return;
    }
    $result['lateness_deduction'] += $rate_late60;
    $result['breakdown'][] = ['date' => $date, 'kind' => 'late_over60', 'amount' => $rate_late60, 'note' => $note];
}

/**
 * คำนวณการหักเงินตามกฎ HR (ขาด/ลา/มาสาย)
 *
 * กฎ (ตามที่ตกลงไว้):
 *   1. ขาด                          หัก payroll_absent_rate (default 600)
 *   2. ลาป่วยอนุมัติ                 ไม่หักเงินเดือน (ตาม พ.ร.บ. คุ้มครองแรงงาน) — ไม่มีใบรับรอง = แจ้งเตือนเท่านั้น
 *   3. มาสาย 20-30 นาที (default)   หัก payroll_late_30_rate (default 150) — ต่ำกว่า tier1 ไม่หัก
 *   4. มาสาย 31-60 นาที             หัก payroll_late_60_rate (default 300)
 *   5. มาสาย > 60 นาที              ตีเป็นขาด (ถ้า payroll_late_over60_as_absent=1)
 *   6. การลาต้องแจ้งล่วงหน้า 7 วัน  (advance_notice_days)
 *   7. แจ้งเข้างานสายล่วงหน้า (OT คืนก่อน) — มาตามเวลาที่แจ้ง ± grace ไม่หัก
 *
 * อ่านจาก hr_attendances + hr_leave_requests ของเดือนนั้น
 *
 * @return array {
 *   absent_days: float,
 *   late_count_30: int,
 *   late_count_60: int,
 *   absence_deduction: float,
 *   lateness_deduction: float,
 *   total_deduction: float,
 *   breakdown: array (รายการรายวัน)
 * }
 */
function payroll_compute_attendance_deductions($pdo, $user_id, $month_first, $pay_day = null) {
    $result = [
        'absent_days' => 0.0,
        'late_count_30' => 0,
        'late_count_60' => 0,
        'absence_deduction' => 0.0,
        'lateness_deduction' => 0.0,
        'total_deduction' => 0.0,
        'breakdown' => [],
        'warnings' => [],
    ];

    if (\TpCommon\Hr\AttendanceScope::isUserExemptById($pdo, (int)$user_id)) {
        return $result;
    }

    // Feature flag
    if ((int)getSetting('payroll_attendance_enabled', '1') !== 1) {
        return $result;
    }

    // Rate config
    $rate_absent = (float)getSetting('payroll_absent_rate', '600');
    $rate_late30 = (float)getSetting('payroll_late_30_rate', '150');
    $rate_late60 = (float)getSetting('payroll_late_60_rate', '300');
    $late_over60_as_absent = (int)getSetting('payroll_late_over60_as_absent', '1') === 1;
    $leave_advance_days = (int)getSetting('payroll_leave_advance_days', '7');

    // S2: feature flag — ถ้าตั้งเป็น 0 จะกลับไปใช้ behavior เดิม (ignore planned_start_time)
    //     ใช้เป็น kill-switch ฉุกเฉินถ้าเจอ regression
    $use_planned_start = (int) getSetting('payroll_use_planned_start', '1') === 1;
    $planned_grace_min = (int) getSetting('payroll_planned_grace_minutes', '30');

    $hire_date = payroll_get_user_hire_date($pdo, $user_id);
    $termination_date = payroll_get_user_termination_date($pdo, $user_id);
    $period = payroll_effective_period_bounds($month_first, $pay_day, $hire_date, $termination_date);
    $month_start = $period['start'];
    $month_end = $period['end'];
    if ($hire_date !== null && $hire_date > $month_end) {
        return $result;
    }
    if (!empty($period['is_terminated_before_period'])) {
        return $result;
    }
    $missing_scan_end = payroll_attendance_closed_scan_end($month_start, $month_end);
    if ($termination_date !== null && $termination_date !== '' && ($missing_scan_end === '' || $termination_date < $missing_scan_end)) {
        $missing_scan_end = $termination_date;
    }
    $workday_ctx = payroll_build_workday_context($pdo, $user_id, $month_start, $month_end);

    // ========== 1. hr_attendances: ขาด + มาสาย ==========
    // S2: ดึง check_in_time + planned_start_time มาด้วย เพื่อ recompute late_min
    //     เมื่อพนักงานแจ้งเข้างานสายล่วงหน้า (override shift start)
    try {
        $stmt = $pdo->prepare("
            SELECT attendance_date, status, late_minutes, late_excused, late_notified_at, remarks,
                   check_in_time, planned_start_time, planned_reason, planned_status
            FROM hr_attendances
            WHERE user_id = ? AND attendance_date BETWEEN ? AND ?
            ORDER BY attendance_date
        ");
        $stmt->execute([$user_id, $month_start, $month_end]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Older schema (ไม่มี planned_start_time) → fallback to legacy SELECT
        try {
            $stmt = $pdo->prepare("
                SELECT attendance_date, status, late_minutes, late_excused, late_notified_at, remarks,
                       check_in_time, NULL AS planned_start_time, NULL AS planned_reason, NULL AS planned_status
                FROM hr_attendances
                WHERE user_id = ? AND attendance_date BETWEEN ? AND ?
                ORDER BY attendance_date
            ");
            $stmt->execute([$user_id, $month_start, $month_end]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
            return $result;
        }
    }

    // Track which dates are already counted as absent (via hr_attendances)
    $absent_dates = [];
    $logged_dates = [];

    foreach ($logs as $log) {
        $date = $log['attendance_date'];
        $logged_dates[$date] = true;

        // วันหยุดประจำ / นักขัตฤกษ์ / ลา — ไม่หักแม้มี record (เช่น check-in วันอาทิตย์)
        if (!payroll_is_payroll_workday($workday_ctx, $date)) {
            continue;
        }

        // รอบยังไม่ปิดถึงวันนี้ — ไม่หักขาด/มาสายล่วงหน้า (sync กับ missing_scan_end / HR summary)
        if (!payroll_is_attendance_deductible_date($date, $missing_scan_end)) {
            continue;
        }

        $status = $log['status'] ?? 'PRESENT';
        $late_min = (int)($log['late_minutes'] ?? 0);
        $excused = (int)($log['late_excused'] ?? 0);
        $planned_start = (($log['planned_status'] ?? null) === 'APPROVED') ? ($log['planned_start_time'] ?? null) : null;
        $check_in = $log['check_in_time'] ?? null;

        // ขาด — ข้ามถ้ามีใบลาอนุมัติครอบคลุม (รวมลาป่วย)
        if ($status === 'ABSENT') {
            try {
                $chk = $pdo->prepare("
                    SELECT 1 FROM hr_leave_requests
                    WHERE user_id = ? AND status = 'APPROVED'
                      AND ? BETWEEN start_date AND end_date
                    LIMIT 1
                ");
                $chk->execute([$user_id, $date]);
                if ($chk->fetchColumn()) {
                    continue;
                }
            } catch (Throwable $e) {
                /* continue with absent */
            }
            $result['absent_days'] += 1;
            $absent_dates[$date] = true;
            $result['breakdown'][] = ['date' => $date, 'kind' => 'absent', 'amount' => $rate_absent, 'note' => 'ขาดงาน'];
            continue;
        }

        // S2: ถ้ามี planned_start_time + เปิด feature → recompute late_min จาก planned_start
        //     - ถ้าเช็คอินภายใน planned_start + grace → ถือว่าตรงเวลา (skip การหัก)
        //     - ถ้าเลย grace → ใช้ค่าใหม่ในการ bucket 30/60/>60
        //     หมายเหตุ: late_excused = 1 ยังคงข้ามทั้งหมดเหมือนเดิม (override สูงสุด)
        $planned_used = false;
        if ($use_planned_start && $excused !== 1 && !empty($planned_start) && !empty($check_in)) {
            $ci_ts = strtotime($check_in);
            $ps_ts = strtotime($date . ' ' . $planned_start);
            if ($ci_ts && $ps_ts) {
                $delta_min = ($ci_ts - $ps_ts) / 60;
                if ($delta_min <= $planned_grace_min) {
                    // มาทันตามที่แจ้ง → ไม่หัก
                    $result['breakdown'][] = [
                        'date'   => $date,
                        'kind'   => 'late_planned_ok',
                        'amount' => 0,
                        'note'   => sprintf('แจ้งเข้างานสาย %s — มาตามนัด ไม่หัก', substr($planned_start, 0, 5)),
                    ];
                    continue;
                }
                // เลย grace → ใช้นาทีที่สาย "เกินเวลาที่แจ้งไว้" เป็น bucket key
                $late_min = (int) round($delta_min);
                $planned_used = true;
            }
        }

        // มาสาย (PRESENT/LATE/WFH/HALF_DAY with late_minutes>0) + ไม่ได้รับยกเว้น
        if (in_array($status, ['PRESENT','LATE','WFH','HALF_DAY'], true) && $late_min > 0 && $excused !== 1) {
            $planned_label = $planned_used ? sprintf(' (แจ้งไว้ %s)', substr($planned_start, 0, 5)) : '';
            $before_absent = (float) ($result['absent_days'] ?? 0);
            payroll_apply_late_minutes_deduction(
                $result,
                $date,
                $late_min,
                $rate_absent,
                $rate_late30,
                $rate_late60,
                $late_over60_as_absent,
                null,
                $planned_label
            );
            if ((float) ($result['absent_days'] ?? 0) > $before_absent) {
                $absent_dates[$date] = true;
            }
        }
    }

    // ========== 1b. วันทำงานที่ไม่มี record เลย (sync กับ tp-hr PayrollService) ==========
    $missing_dates = ($missing_scan_end !== '' && $missing_scan_end >= $month_start)
        ? payroll_find_missing_absent_dates($pdo, $user_id, $month_start, $missing_scan_end, $logged_dates)
        : [];
    foreach ($missing_dates as $date) {
        if (!empty($absent_dates[$date])) {
            continue;
        }
        $result['absent_days'] += 1;
        $absent_dates[$date] = true;
        $result['breakdown'][] = [
            'date' => $date,
            'kind' => 'missing_attendance_absent',
            'amount' => $rate_absent,
            'note' => 'ไม่พบการลงเวลาในวันทำงาน',
        ];
    }

    // ========== 2. hr_leave_requests: ลาป่วยไม่มีเอกสาร → ตีเป็นขาด ==========
    try {
        $stmt = $pdo->prepare("
            SELECT lr.id, lt.code AS leave_code, lt.requires_document, lt.name AS leave_type_name,
                   lr.start_date, lr.end_date, lr.total_days AS leave_days,
                   CASE WHEN lr.document_path IS NOT NULL THEN 1 ELSE 0 END AS has_medical_cert,
                   lr.status, lr.created_at,
                   DATEDIFF(lr.start_date, DATE(lr.created_at)) AS notice_days
            FROM hr_leave_requests lr
            JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
            WHERE lr.user_id = ?
              AND lr.status = 'APPROVED'
              AND lr.start_date <= ? AND lr.end_date >= ?
        ");
        $stmt->execute([$user_id, $month_end, $month_start]);
        $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $leaves = [];
    }

    foreach ($leaves as $lv) {
        // ลาป่วยอนุมัติ: ตาม พ.ร.บ. คุ้มครองแรงงาน ไม่หักเงินเดือน (แม้ไม่มีใบรับรอง)
        if ($lv['leave_code'] === 'SICK' && (int)$lv['has_medical_cert'] !== 1) {
            $result['warnings'][] = [
                'kind' => 'sick_missing_cert',
                'leave_id' => $lv['id'],
                'note' => 'ลาป่วยอนุมัติแต่ยังไม่มีใบรับรองแพทย์ — ไม่หักเงินเดือน (ควรแนบใบรับรองเมื่อลา 3 วันขึ้นไป)',
            ];
        }

        // Warning: แจ้งลาล่วงหน้าไม่ถึงเกณฑ์ (ยกเว้นลาป่วย)
        if ($lv['leave_code'] !== 'SICK' && (int)$lv['notice_days'] < $leave_advance_days) {
            $result['warnings'][] = [
                'kind' => 'insufficient_notice',
                'leave_id' => $lv['id'],
                'note' => "แจ้ง{$lv['leave_type_name']}ล่วงหน้าเพียง {$lv['notice_days']} วัน (ต้องการ {$leave_advance_days} วัน)",
            ];
        }
    }

    $result['absence_deduction'] = $result['absent_days'] * $rate_absent;
    $result['total_deduction'] = $result['absence_deduction'] + $result['lateness_deduction'];

    return $result;
}

/**
 * บริบทวันทำงานสำหรับ payroll (หยุดประจำ / หยุดนักขัตฤกษ์ / ลา / สลับวันหยุด)
 *
 * @return array{day_off:int, skip_missing:bool, holidays:array<string,true>, leave_dates:array<string,true>, dayoff_requests:list<array>}
 */
function payroll_build_workday_context(PDO $pdo, int $user_id, string $period_start, string $period_end): array
{
    return \TpCommon\Hr\WorkdayCalculator::buildContext($pdo, $user_id, $period_start, $period_end);
}

/** วันนี้เป็นวันที่ payroll ควรหักขาด/มาสายได้หรือไม่ */
function payroll_is_payroll_workday(array $ctx, string $date): bool
{
    return \TpCommon\Hr\WorkdayCalculator::isExpectedWorkday($ctx, $date);
}

/**
 * วันทำงานที่ไม่มี hr_attendances (ไม่รวมวันหยุด / ลา / day-off swap)
 *
 * @return list<string> Y-m-d dates
 */
function payroll_find_missing_absent_dates(PDO $pdo, int $user_id, string $period_start, string $scan_end, array $logged_dates): array
{
    $ctx = payroll_build_workday_context($pdo, $user_id, $period_start, $scan_end);
    if ($ctx['skip_missing']) {
        return [];
    }

    $missing = [];
    for ($ts = strtotime($period_start); $ts !== false && $ts <= strtotime($scan_end); $ts += 86400) {
        $date = date('Y-m-d', $ts);
        if (!empty($logged_dates[$date]) || !payroll_is_payroll_workday($ctx, $date)) {
            continue;
        }
        $missing[] = $date;
    }

    return $missing;
}

/**
 * Merge draft form values onto saved salary setup for preview calc.
 *
 * @param array<string,mixed>|null $setup
 * @param array<string,mixed>|null $override
 * @return array<string,mixed>|null
 */
function payroll_merge_setup_override(?array $setup, ?array $override): ?array
{
    if (!$override) {
        return is_array($setup) ? $setup : null;
    }
    $merged = is_array($setup) ? $setup : [];
    foreach ($override as $key => $value) {
        if ($value === null) {
            continue;
        }
        $merged[$key] = $value;
    }
    return $merged;
}

/**
 * Canonical slip calculation (CRM legacy path — mirrors tp-hr PayrollService::calculateSlip).
 *
 * @param array<string,mixed>|null $setup_override Draft values from salary form preview
 * @return array<string,mixed>
 */
function payroll_calculate_slip_for_user(PDO $pdo, int $user_id, string $month_first, int $pay_day, ?array $setup_override = null): array
{
    $setup = get_salary_setup_for_month($pdo, $user_id, $month_first);
    $explicit_base = $setup_override !== null && array_key_exists('base_salary', $setup_override);
    $setup = payroll_merge_setup_override($setup, $setup_override);

    $gross = payroll_resolve_base_salary_for_month($pdo, $user_id, $month_first, $setup, $explicit_base);
    if (is_array($setup)) {
        $setup['base_salary'] = $gross;
    } elseif ($gross > 0) {
        $setup = ['base_salary' => $gross];
    }
    $bonus = $setup ? (float)($setup['bonus_fixed'] ?? 0) : 0;
    $allowances = 0;
    if ($setup && !empty($setup['allowance_json'])) {
        $arr = json_decode($setup['allowance_json'], true);
        if (is_array($arr)) {
            foreach ($arr as $a) {
                $allowances += (float)($a['amount'] ?? 0);
            }
        }
    }
    $income_other = 0;
    $income_other_json = null;
    if ($setup && !empty($setup['income_other_json'])) {
        $arr = json_decode($setup['income_other_json'], true);
        if (is_array($arr)) {
            foreach ($arr as $a) {
                $income_other += (float)($a['amount'] ?? 0);
            }
            $income_other_json = $setup['income_other_json'];
        }
    }
    $hire_factor = payroll_apply_hire_date_income(
        $pdo,
        $user_id,
        $month_first,
        $pay_day,
        $gross,
        $bonus,
        $allowances,
        $income_other,
        $income_other_json
    );
    $total_income = $gross + $bonus + $allowances + $income_other;
    $annual_est = $total_income * 12;
    $ss_opt_out = $setup && !empty($setup['ss_opt_out']);
    $tax_opt_out = $setup && !empty($setup['tax_opt_out']);
    $hi_opt_out = $setup && !empty($setup['hi_opt_out']);
    $gi_opt_out = $setup && !empty($setup['gi_opt_out']);
    $ss_wage_base = round(payroll_ss_wage_base_from_setup($setup) * max(0, $hire_factor), 2);
    $ssAuto = payroll_calc_social_security_for_user($pdo, $user_id, $ss_wage_base, $ss_opt_out, $month_first);
    $ssStart = payroll_get_user_ss_start_date($pdo, $user_id);
    $ssApplies = payroll_ss_applies_for_month($ssStart, $month_first)
        && payroll_is_ss_enabled_for_month($month_first)
        && !$ss_opt_out;
    $ss = $ssApplies ? payroll_resolve_social_security_amount($setup, $ssAuto) : 0.0;
    $benefit_factor = payroll_benefit_proration_factor($hire_factor);
    $pf = $setup ? round((float)($setup['provident_fund'] ?? 0) * $benefit_factor, 2) : 0;
    $tax_base = payroll_calc_tax_for_user($pdo, $user_id, $annual_est, $ss * 12, $pf * 12, $month_first, $tax_opt_out);
    $extra_tax_req = payroll_resolve_extra_tax_request($setup, $tax_opt_out, $month_first);
    $gi_total = $setup && isset($setup['group_insurance_total_monthly']) ? (float)$setup['group_insurance_total_monthly'] : 0;
    $gi_emp_pct = $setup && isset($setup['group_insurance_employer_pct']) ? (float)$setup['group_insurance_employer_pct'] : 50;
    $group_insurance = payroll_calc_group_insurance_for_user($pdo, $user_id, round($gi_total * $benefit_factor, 2), $gi_emp_pct, $gi_opt_out, $month_first);
    $hi_total = $setup && isset($setup['health_insurance_total_monthly']) ? (float)$setup['health_insurance_total_monthly'] : 0;
    $hi_emp_pct = $setup && isset($setup['health_insurance_employer_pct']) ? (float)$setup['health_insurance_employer_pct'] : 50;
    $health_insurance = payroll_calc_health_insurance_for_user($pdo, $user_id, round($hi_total * $benefit_factor, 2), $hi_emp_pct, $hi_opt_out, $month_first);
    $ded_other = 0;
    $deduction_other_json = null;
    if ($setup && !empty($setup['deduction_other_json'])) {
        $arr = json_decode($setup['deduction_other_json'], true);
        if (is_array($arr)) {
            foreach ($arr as $a) {
                $ded_other += (float)($a['amount'] ?? 0);
            }
            $deduction_other_json = $setup['deduction_other_json'];
        }
    }
    $financeItems = payroll_employee_finance_deductions($pdo, $user_id, substr($month_first, 0, 7));
    if ($financeItems) {
        $existing = $deduction_other_json ? json_decode($deduction_other_json, true) : [];
        $existing = is_array($existing) ? $existing : [];
        foreach ($financeItems as $item) {
            $ded_other += (float)$item['amount'];
            $existing[] = $item;
        }
        $deduction_other_json = json_encode($existing, JSON_UNESCAPED_UNICODE);
    }
    $att = payroll_compute_attendance_deductions($pdo, $user_id, $month_first, $pay_day);
    $absence_ded = (float)$att['absence_deduction'];
    $lateness_ded = (float)$att['lateness_deduction'];
    $attendance_detail_json = !empty($att['breakdown']) || !empty($att['warnings'])
        ? json_encode(['breakdown' => $att['breakdown'], 'warnings' => $att['warnings']], JSON_UNESCAPED_UNICODE)
        : null;
    $max_extra = max(0, $total_income - ($tax_base + $pf + $ss + $group_insurance + $health_insurance + $ded_other + $absence_ded + $lateness_ded));
    $extra_tax = min($extra_tax_req, $max_extra);
    $tax = $tax_base + $extra_tax;
    $total_ded = $tax + $pf + $ss + $group_insurance + $health_insurance + $ded_other + $absence_ded + $lateness_ded;
    $net = max(0, $total_income - $total_ded);

    return [
        'user_id' => $user_id,
        'gross_salary' => $gross,
        'bonus' => $bonus,
        'allowances' => $allowances,
        'income_other_json' => $income_other_json,
        'total_income' => $total_income,
        'tax_base' => $tax_base,
        'extra_tax_req' => $extra_tax_req,
        'extra_tax' => $extra_tax,
        'tax_withheld' => $tax,
        'provident_fund' => $pf,
        'social_security' => $ss,
        'group_insurance' => $group_insurance,
        'health_insurance' => $health_insurance,
        'deduction_other_json' => $deduction_other_json,
        'deduction_other' => $ded_other,
        'absent_days' => $att['absent_days'],
        'late_count_30' => $att['late_count_30'],
        'late_count_60' => $att['late_count_60'],
        'absence_deduction' => $absence_ded,
        'lateness_deduction' => $lateness_ded,
        'attendance_detail_json' => $attendance_detail_json,
        'total_deductions' => $total_ded,
        'net_salary' => $net,
    ];
}

/** @return array<int,array{label:string,name:string,amount:float,source_type:string,source_id:int}> */
function payroll_employee_finance_deductions(PDO $pdo, int $userId, string $month): array
{
    try {
        $items = [];
        $advance = $pdo->prepare(
            "SELECT id, amount FROM hr_salary_advances
             WHERE user_id = ? AND deduction_month = ? AND repayment_method = 'payroll'
               AND status = 'pending_deduction'"
        );
        $advance->execute([$userId, $month]);
        foreach ($advance->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $label = 'หักเงินเบิกล่วงหน้า';
            $items[] = ['label' => $label, 'name' => $label, 'amount' => (float)$row['amount'], 'source_type' => 'salary_advance', 'source_id' => (int)$row['id']];
        }
        $loan = $pdo->prepare(
            "SELECT r.id, r.installment_no, r.due_amount, l.term_months
             FROM hr_loan_repayments r JOIN hr_employee_loans l ON l.id = r.loan_id
             WHERE l.user_id = ? AND l.status = 'active' AND l.repayment_method = 'payroll'
               AND DATE_FORMAT(r.due_date, '%Y-%m') = ? AND r.status = 'scheduled'"
        );
        $loan->execute([$userId, $month]);
        foreach ($loan->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $label = 'เงินกู้บริษัท งวดที่ ' . (int)$row['installment_no'] . ' / ' . (int)$row['term_months'];
            $items[] = ['label' => $label, 'name' => $label, 'amount' => (float)$row['due_amount'], 'source_type' => 'employee_loan_repayment', 'source_id' => (int)$row['id'], 'installment_no' => (int)$row['installment_no'], 'installment_total' => (int)$row['term_months']];
        }
        return $items;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Explain every salary-advance candidate for a payroll run. This is deliberately
 * read-only: ERP/HR lifecycle state remains the authority for whether a deduction
 * may enter a slip.
 *
 * @return array{total:int,included:int,waiting:int,excluded:int,rows:list<array<string,mixed>>}
 */
function payroll_salary_advance_sync_summary(PDO $pdo, int $runId): array
{
    $run = $pdo->prepare("SELECT DATE_FORMAT(payroll_month, '%Y-%m') FROM payroll_runs WHERE id=? LIMIT 1");
    $run->execute([$runId]);
    $month = (string)$run->fetchColumn();
    if ($month === '') return ['total'=>0,'included'=>0,'waiting'=>0,'excluded'=>0,'rows'=>[]];

    $stmt = $pdo->prepare(
        "SELECT a.id,a.user_id,a.amount,a.repayment_method,a.status,a.deduction_month,
                a.expense_request_id,r.request_code,r.status expense_request_status,
                CONCAT(COALESCE(u.first_name_th,''),' ',COALESCE(u.last_name_th,'')) employee_name,
                s.id payroll_slip_id,x.link_status
           FROM hr_salary_advances a
           LEFT JOIN line_expense_requests r ON r.id=a.expense_request_id
           LEFT JOIN users u ON u.id=a.user_id
           LEFT JOIN payroll_slips s ON s.payroll_run_id=? AND s.user_id=a.user_id
           LEFT JOIN hr_employee_finance_payroll_links x
             ON x.payroll_run_id=? AND x.source_type='salary_advance' AND x.source_id=a.id
            AND x.link_status IN ('included','settled')
          WHERE a.deduction_month=?
          ORDER BY a.id"
    );
    $stmt->execute([$runId,$runId,$month]);
    $summary = ['total'=>0,'included'=>0,'waiting'=>0,'excluded'=>0,'rows'=>[]];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $bucket = 'excluded';
        $reason = 'ไม่เข้าเงื่อนไขการหักเงินเดือน';
        if (in_array((string)($row['expense_request_status'] ?? ''), ['rejected','cancelled'], true)) {
            $reason = 'คำขอต้นทางถูกยกเลิกหรือไม่อนุมัติ';
        } elseif ((string)$row['repayment_method'] !== 'payroll') {
            $reason = 'เลือกชำระคืนด้วยการโอน ไม่หักผ่านสลิป';
        } elseif (in_array((string)$row['status'], ['cancelled','rejected'], true)) {
            $reason = 'รายการถูกยกเลิกหรือไม่อนุมัติ';
        } elseif ((string)$row['status'] === 'pending_disbursement') {
            $bucket = 'waiting';
            $reason = 'รอ ERP ยืนยันจ่ายเงินจริง';
        } elseif (empty($row['payroll_slip_id'])) {
            $reason = 'พนักงานไม่มีสลิปในรอบนี้';
        } elseif (in_array((string)$row['status'], ['pending_deduction','deducted'], true) && !empty($row['link_status'])) {
            $bucket = 'included';
            $reason = (string)$row['link_status'] === 'settled' ? 'หักและปิดยอดแล้ว' : 'ซิงค์เข้าสลิปแล้ว';
        } elseif (in_array((string)$row['status'], ['pending_deduction','deducted'], true)) {
            $reason = 'พร้อมหัก แต่สลิปยังไม่ได้คำนวณใหม่';
        }
        $summary[$bucket]++;
        $summary['total']++;
        $row['bucket'] = $bucket;
        $row['reason'] = $reason;
        $summary['rows'][] = $row;
    }
    return $summary;
}

/**
 * Explain employee-loan installments due in a payroll run. Kept separate from
 * salary advances because one loan can span several payroll months.
 *
 * @return array{total:int,included:int,waiting:int,excluded:int,rows:list<array<string,mixed>>}
 */
function payroll_employee_loan_sync_summary(PDO $pdo, int $runId): array
{
    $run = $pdo->prepare("SELECT DATE_FORMAT(payroll_month, '%Y-%m') FROM payroll_runs WHERE id=? LIMIT 1");
    $run->execute([$runId]);
    $month = (string)$run->fetchColumn();
    if ($month === '') return ['total'=>0,'included'=>0,'waiting'=>0,'excluded'=>0,'rows'=>[]];

    $stmt = $pdo->prepare(
        "SELECT p.id repayment_id,p.installment_no,p.due_amount,p.status repayment_status,
                l.id loan_id,l.user_id,l.status loan_status,l.repayment_method,l.term_months,
                l.expense_request_id,r.request_code,r.status expense_request_status,
                CONCAT(COALESCE(u.first_name_th,''),' ',COALESCE(u.last_name_th,'')) employee_name,
                s.id payroll_slip_id,x.link_status
           FROM hr_loan_repayments p
           JOIN hr_employee_loans l ON l.id=p.loan_id
           LEFT JOIN line_expense_requests r ON r.id=l.expense_request_id
           LEFT JOIN users u ON u.id=l.user_id
           LEFT JOIN payroll_slips s ON s.payroll_run_id=? AND s.user_id=l.user_id
           LEFT JOIN hr_employee_finance_payroll_links x
             ON x.payroll_run_id=? AND x.source_type='employee_loan_repayment' AND x.source_id=p.id
            AND x.link_status IN ('included','settled')
          WHERE DATE_FORMAT(p.due_date, '%Y-%m')=?
          ORDER BY p.id"
    );
    $stmt->execute([$runId,$runId,$month]);
    $summary = ['total'=>0,'included'=>0,'waiting'=>0,'excluded'=>0,'rows'=>[]];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $bucket = 'excluded';
        $reason = 'ไม่เข้าเงื่อนไขการหักเงินเดือน';
        if (in_array((string)($row['expense_request_status'] ?? ''), ['rejected','cancelled'], true)) {
            $reason = 'คำขอต้นทางถูกยกเลิกหรือไม่อนุมัติ';
        } elseif ((string)$row['repayment_method'] !== 'payroll') {
            $reason = 'เลือกชำระคืนภายนอกสลิปเงินเดือน';
        } elseif ((string)$row['loan_status'] === 'pending_disbursement') {
            $bucket = 'waiting';
            $reason = 'รอ ERP ยืนยันจ่ายเงินจริง';
        } elseif ((string)$row['loan_status'] === 'cancelled') {
            $reason = 'เงินกู้ถูกยกเลิก';
        } elseif (empty($row['payroll_slip_id'])) {
            $reason = 'พนักงานไม่มีสลิปในรอบนี้';
        } elseif (!empty($row['link_status'])) {
            $bucket = 'included';
            $reason = (string)$row['link_status'] === 'settled' ? 'หักและปิดงวดแล้ว' : 'ซิงค์เข้าสลิปแล้ว';
        } elseif ((string)$row['loan_status'] === 'active' && (string)$row['repayment_status'] === 'scheduled') {
            $reason = 'พร้อมหัก แต่สลิปยังไม่ได้คำนวณใหม่';
        }
        $summary[$bucket]++;
        $summary['total']++;
        $row['bucket'] = $bucket;
        $row['reason'] = $reason;
        $summary['rows'][] = $row;
    }
    return $summary;
}

/** @return array{installment_no:int,installment_total:int}|null */
function payroll_employee_loan_installment_context(PDO $pdo, int $repaymentId): ?array
{
    if ($repaymentId <= 0) return null;
    $stmt = $pdo->prepare(
        'SELECT r.installment_no, l.term_months
           FROM hr_loan_repayments r
           JOIN hr_employee_loans l ON l.id = r.loan_id
          WHERE r.id = ? LIMIT 1'
    );
    $stmt->execute([$repaymentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? ['installment_no' => (int)$row['installment_no'], 'installment_total' => (int)$row['term_months']] : null;
}

/** @return array{user_id?:int,first_due_month?:string,run_id?:int,run_status?:string} */
function payroll_staff_finance_run_context(PDO $pdo, int $expenseRequestId): array
{
    $stmt = $pdo->prepare(
        "SELECT l.user_id, l.first_due_month, pr.id AS run_id, pr.status AS run_status
           FROM hr_employee_loans l
           LEFT JOIN payroll_runs pr ON DATE_FORMAT(pr.payroll_month, '%Y-%m') = l.first_due_month
          WHERE l.expense_request_id = ?
          LIMIT 1"
    );
    $stmt->execute([$expenseRequestId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function payroll_employee_finance_link_table_exists(PDO $pdo): bool
{
    static $cache = [];
    $key = spl_object_hash($pdo);
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='hr_employee_finance_payroll_links'");
    $stmt->execute();
    return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
}

/** @return list<array{source_type:string,source_id:int,amount:float}> */
function payroll_employee_finance_items_from_json(?string $json): array
{
    $decoded = json_decode((string)$json, true);
    if (!is_array($decoded)) return [];
    $items = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) continue;
        $type = (string)($item['source_type'] ?? '');
        $id = (int)($item['source_id'] ?? 0);
        if (!in_array($type, ['salary_advance', 'employee_loan_repayment'], true) || $id <= 0) continue;
        $items[] = ['source_type' => $type, 'source_id' => $id, 'amount' => round((float)($item['amount'] ?? 0), 2)];
    }
    return $items;
}

function payroll_sync_employee_finance_links_for_slip(PDO $pdo, int $runId, int $slipId, int $userId, ?string $json): void
{
    if (!payroll_employee_finance_link_table_exists($pdo)) {
        throw new RuntimeException('ยังไม่ได้ติดตั้งตารางเชื่อมรายการเงินกู้กับสลิป');
    }
    $items = payroll_employee_finance_items_from_json($json);
    $present = [];
    $upsert = $pdo->prepare(
        "INSERT INTO hr_employee_finance_payroll_links
             (source_type,source_id,user_id,payroll_run_id,payroll_slip_id,amount,link_status,included_at,settled_at,reversed_at)
         VALUES (?,?,?,?,?,?,'included',NOW(),NULL,NULL)
         ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),payroll_run_id=VALUES(payroll_run_id),
             payroll_slip_id=VALUES(payroll_slip_id),amount=VALUES(amount),link_status='included',
             included_at=NOW(),settled_at=NULL,reversed_at=NULL"
    );
    foreach ($items as $item) {
        $key = $item['source_type'] . ':' . $item['source_id'];
        if (isset($present[$key])) {
            throw new RuntimeException('พบ source รายการหักซ้ำในสลิป #' . $slipId . ': ' . $key);
        }
        $present[$key] = true;
        $upsert->execute([$item['source_type'], $item['source_id'], $userId, $runId, $slipId, $item['amount']]);
    }
    $stale = $pdo->prepare("SELECT id,source_type,source_id FROM hr_employee_finance_payroll_links WHERE payroll_slip_id=? AND link_status='included'");
    $stale->execute([$slipId]);
    $reverse = $pdo->prepare("UPDATE hr_employee_finance_payroll_links SET link_status='reversed',reversed_at=NOW() WHERE id=? AND link_status='included'");
    foreach ($stale->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!isset($present[(string)$row['source_type'] . ':' . (int)$row['source_id']])) {
            $reverse->execute([(int)$row['id']]);
        }
    }
}

function payroll_sync_employee_finance_links_for_run(PDO $pdo, int $runId): int
{
    $stmt = $pdo->prepare('SELECT id,user_id,deduction_other_json FROM payroll_slips WHERE payroll_run_id=? ORDER BY id');
    $stmt->execute([$runId]);
    $count = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $slip) {
        payroll_sync_employee_finance_links_for_slip($pdo, $runId, (int)$slip['id'], (int)$slip['user_id'], $slip['deduction_other_json'] ?? null);
        $count++;
    }
    return $count;
}

/**
 * Evaluate whether aggregate deductions leave a reasonable take-home amount.
 * A zero take-home is always blocking. Ratio warnings are configurable and can
 * be promoted to blocking without changing payroll code.
 *
 * @return array{warning:bool,blocking:bool,deduction_pct:float,net_pct:float,reasons:list<string>}
 */
function payroll_employee_finance_affordability(
    float $totalIncome,
    float $totalDeductions,
    float $netSalary,
    float $warningDeductionPct = 50.0,
    float $warningMinNetPct = 40.0,
    string $mode = 'warn'
): array {
    $deductionPct = $totalIncome > 0 ? ($totalDeductions / $totalIncome) * 100 : 0.0;
    $netPct = $totalIncome > 0 ? ($netSalary / $totalIncome) * 100 : 0.0;
    $reasons = [];
    if ($totalIncome > 0 && $deductionPct >= $warningDeductionPct) $reasons[] = 'DEDUCTION_RATIO';
    if ($totalIncome > 0 && $netPct <= $warningMinNetPct) $reasons[] = 'LOW_NET_RATIO';
    if ($totalIncome > 0 && $netSalary <= 0.0) $reasons[] = 'NO_TAKE_HOME';
    $reasons = array_values(array_unique($reasons));
    $ratioWarning = in_array('DEDUCTION_RATIO', $reasons, true) || in_array('LOW_NET_RATIO', $reasons, true);
    return [
        'warning' => $reasons !== [],
        'blocking' => in_array('NO_TAKE_HOME', $reasons, true) || ($ratioWarning && strtolower($mode) === 'block'),
        'deduction_pct' => round($deductionPct, 2),
        'net_pct' => round($netPct, 2),
        'reasons' => $reasons,
    ];
}

/** @return array{ok:bool,errors:list<string>,warnings:list<string>,affordability:list<array<string,mixed>>,expected:int,actual:int,links:int} */
function payroll_reconcile_employee_finance_run(PDO $pdo, int $runId): array
{
    if (!payroll_employee_finance_link_table_exists($pdo)) {
        return ['ok' => false, 'errors' => ['SCHEMA_MISSING'], 'warnings' => [], 'affordability' => [], 'expected' => 0, 'actual' => 0, 'links' => 0];
    }
    $run = $pdo->prepare('SELECT DATE_FORMAT(payroll_month,\'%Y-%m\') FROM payroll_runs WHERE id=?');
    $run->execute([$runId]);
    $month = (string)$run->fetchColumn();
    if ($month === '') return ['ok' => false, 'errors' => ['RUN_NOT_FOUND'], 'warnings' => [], 'affordability' => [], 'expected' => 0, 'actual' => 0, 'links' => 0];

    $slips = $pdo->prepare('SELECT id,user_id,total_income,total_deductions,net_salary,deduction_other_json FROM payroll_slips WHERE payroll_run_id=?');
    $slips->execute([$runId]);
    $actual = [];
    $errors = [];
    $warnings = [];
    $affordability = [];
    $warningDeductionPct = max(0.0, min(100.0, (float)getSetting('payroll_employee_finance_warning_deduction_pct', 50)));
    $warningMinNetPct = max(0.0, min(100.0, (float)getSetting('payroll_employee_finance_warning_min_net_pct', 40)));
    $affordabilityMode = strtolower((string)getSetting('payroll_employee_finance_affordability_mode', 'block')) === 'warn' ? 'warn' : 'block';
    foreach ($slips->fetchAll(PDO::FETCH_ASSOC) as $slip) {
        $financeItems = payroll_employee_finance_items_from_json($slip['deduction_other_json'] ?? null);
        if ($financeItems !== []) {
            $check = payroll_employee_finance_affordability(
                (float)$slip['total_income'], (float)$slip['total_deductions'], (float)$slip['net_salary'],
                $warningDeductionPct, $warningMinNetPct, $affordabilityMode
            );
            if ($check['warning']) {
                $entry = $check + ['slip_id' => (int)$slip['id'], 'user_id' => (int)$slip['user_id']];
                $affordability[] = $entry;
                $code = 'AFFORDABILITY:SLIP:' . (int)$slip['id'] . ':' . implode('+', $check['reasons']);
                if ($check['blocking']) $errors[] = $code; else $warnings[] = $code;
            }
        }
        foreach ($financeItems as $item) {
            $key = $item['source_type'] . ':' . $item['source_id'];
            if (isset($actual[$key])) $errors[] = 'DUPLICATE_SOURCE:' . $key;
            $actual[$key] = ['slip_id' => (int)$slip['id'], 'user_id' => (int)$slip['user_id'], 'amount' => $item['amount']];
        }
    }

    $expected = [];
    $advance = $pdo->prepare("SELECT a.id,a.user_id,a.amount FROM hr_salary_advances a JOIN payroll_slips s ON s.user_id=a.user_id AND s.payroll_run_id=? WHERE a.deduction_month=? AND a.repayment_method='payroll' AND a.status IN ('pending_deduction','deducted')");
    $advance->execute([$runId, $month]);
    foreach ($advance->fetchAll(PDO::FETCH_ASSOC) as $row) $expected['salary_advance:' . (int)$row['id']] = ['user_id'=>(int)$row['user_id'],'amount'=>round((float)$row['amount'],2)];
    $loan = $pdo->prepare("SELECT r.id,l.user_id,r.due_amount FROM hr_loan_repayments r JOIN hr_employee_loans l ON l.id=r.loan_id JOIN payroll_slips s ON s.user_id=l.user_id AND s.payroll_run_id=? WHERE DATE_FORMAT(r.due_date,'%Y-%m')=? AND l.repayment_method='payroll' AND r.status IN ('scheduled','paid')");
    $loan->execute([$runId, $month]);
    foreach ($loan->fetchAll(PDO::FETCH_ASSOC) as $row) $expected['employee_loan_repayment:' . (int)$row['id']] = ['user_id'=>(int)$row['user_id'],'amount'=>round((float)$row['due_amount'],2)];

    foreach ($expected as $key => $item) {
        if (!isset($actual[$key])) { $errors[] = 'MISSING_SOURCE:' . $key; continue; }
        if ($actual[$key]['user_id'] !== $item['user_id']) $errors[] = 'WRONG_USER:' . $key;
        if (abs($actual[$key]['amount'] - $item['amount']) > 0.009) $errors[] = 'AMOUNT_MISMATCH:' . $key;
    }
    foreach ($actual as $key => $item) if (!isset($expected[$key])) $errors[] = 'UNEXPECTED_SOURCE:' . $key;

    $linkStmt = $pdo->prepare("SELECT source_type,source_id,user_id,payroll_slip_id,amount,link_status FROM hr_employee_finance_payroll_links WHERE payroll_run_id=? AND link_status IN ('included','settled')");
    $linkStmt->execute([$runId]);
    $links = [];
    foreach ($linkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $links[(string)$row['source_type'] . ':' . (int)$row['source_id']] = $row;
    foreach ($actual as $key => $item) {
        if (!isset($links[$key])) { $errors[] = 'LINK_MISSING:' . $key; continue; }
        if ((int)$links[$key]['payroll_slip_id'] !== $item['slip_id'] || abs((float)$links[$key]['amount'] - $item['amount']) > 0.009) $errors[] = 'LINK_MISMATCH:' . $key;
    }
    foreach ($links as $key => $_) if (!isset($actual[$key])) $errors[] = 'STALE_LINK:' . $key;
    $errors = array_values(array_unique($errors));
    $warnings = array_values(array_unique($warnings));
    return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => $warnings, 'affordability' => $affordability, 'expected' => count($expected), 'actual' => count($actual), 'links' => count($links)];
}

/** @param array<string,mixed> $result */
function payroll_employee_finance_reconciliation_fingerprint(array $result): string
{
    $snapshot = [];
    foreach (($result['affordability'] ?? []) as $item) {
        $snapshot[] = [
            'slip_id' => (int)($item['slip_id'] ?? 0),
            'user_id' => (int)($item['user_id'] ?? 0),
            'deduction_pct' => round((float)($item['deduction_pct'] ?? 0), 2),
            'net_pct' => round((float)($item['net_pct'] ?? 0), 2),
            'reasons' => array_values($item['reasons'] ?? []),
        ];
    }
    usort($snapshot, static fn(array $a, array $b): int => $a['slip_id'] <=> $b['slip_id']);
    return hash('sha256', json_encode([
        'errors' => array_values($result['errors'] ?? []),
        'expected' => (int)($result['expected'] ?? 0),
        'actual' => (int)($result['actual'] ?? 0),
        'links' => (int)($result['links'] ?? 0),
        'affordability' => $snapshot,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** @param array<string,mixed> $result */
function payroll_employee_finance_reconciliation_is_overrideable(array $result): bool
{
    $errors = array_values($result['errors'] ?? []);
    if ($errors === []) return false;
    foreach ($errors as $error) {
        if (!str_starts_with((string)$error, 'AFFORDABILITY:SLIP:')) return false;
        if (str_contains((string)$error, 'NO_TAKE_HOME')) return false;
    }
    return true;
}

function payroll_employee_finance_override_role_allowed(string $role): bool
{
    return in_array(strtolower(trim($role)), ['admin', 'ceo', 'chairman'], true);
}

/** @param array<string,mixed> $result */
function payroll_record_employee_finance_affordability_override(PDO $pdo, int $runId, array $result, int $actorId, string $actorRole, string $reason): void
{
    $reason = trim($reason);
    if ($actorId <= 0 || !payroll_employee_finance_override_role_allowed($actorRole)) {
        throw new RuntimeException('เฉพาะ Admin, CEO หรือ Chairman เท่านั้นที่ยืนยัน override ได้');
    }
    if (mb_strlen($reason) < 10) {
        throw new RuntimeException('กรุณาระบุเหตุผล override อย่างน้อย 10 ตัวอักษร');
    }
    if (!payroll_employee_finance_reconciliation_is_overrideable($result)) {
        throw new RuntimeException('ข้อผิดพลาดนี้ไม่สามารถ override ได้');
    }
    $stmt = $pdo->prepare("INSERT INTO payroll_affordability_overrides
        (payroll_run_id,actor_user_id,actor_role,reason,reconciliation_fingerprint,affordability_snapshot,status)
        VALUES (?,?,?,?,?,?, 'active')");
    $stmt->execute([
        $runId, $actorId, $actorRole, $reason,
        payroll_employee_finance_reconciliation_fingerprint($result),
        json_encode($result['affordability'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

/** @param array<string,mixed> $result */
function payroll_has_valid_employee_finance_affordability_override(PDO $pdo, int $runId, array $result): bool
{
    if (!payroll_employee_finance_reconciliation_is_overrideable($result)) return false;
    $stmt = $pdo->prepare("SELECT reconciliation_fingerprint FROM payroll_affordability_overrides
        WHERE payroll_run_id=? AND status='active' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$runId]);
    $fingerprint = $stmt->fetchColumn();
    return is_string($fingerprint) && hash_equals($fingerprint, payroll_employee_finance_reconciliation_fingerprint($result));
}

/** @param array{actor_id?:int,actor_role?:string,reason?:string}|null $override */
function payroll_assert_employee_finance_run_reconciled(PDO $pdo, int $runId, ?array $override = null): void
{
    $result = payroll_reconcile_employee_finance_run($pdo, $runId);
    if ($result['ok']) return;
    if ($override !== null && !empty($override['reason'])) {
        payroll_record_employee_finance_affordability_override(
            $pdo, $runId, $result, (int)($override['actor_id'] ?? 0),
            (string)($override['actor_role'] ?? ''), (string)$override['reason']
        );
        return;
    }
    if (payroll_has_valid_employee_finance_affordability_override($pdo, $runId, $result)) return;
    throw new RuntimeException('รายการเงินกู้/เงินล่วงหน้าในสลิปไม่ตรงกับ HR: ' . implode(', ', $result['errors']));
}

function payroll_revoke_employee_finance_affordability_overrides(PDO $pdo, int $runId, ?int $actorId = null): void
{
    $stmt = $pdo->prepare("UPDATE payroll_affordability_overrides
        SET status='revoked',revoked_at=NOW(),revoked_by=? WHERE payroll_run_id=? AND status='active'");
    $stmt->execute([$actorId, $runId]);
}

function payroll_settle_employee_finance_for_run(PDO $pdo, int $runId): void
{
    payroll_assert_employee_finance_run_reconciled($pdo, $runId);
    $pdo->prepare("UPDATE hr_salary_advances a JOIN hr_employee_finance_payroll_links x ON x.source_type='salary_advance' AND x.source_id=a.id SET a.status='deducted',a.payroll_run_id=? WHERE x.payroll_run_id=? AND x.link_status='included' AND a.status='pending_deduction'")->execute([$runId,$runId]);
    $pdo->prepare("UPDATE hr_loan_repayments r JOIN hr_employee_finance_payroll_links x ON x.source_type='employee_loan_repayment' AND x.source_id=r.id SET r.status='paid',r.paid_amount=x.amount,r.paid_at=NOW(),r.payroll_run_id=? WHERE x.payroll_run_id=? AND x.link_status='included' AND r.status='scheduled'")->execute([$runId,$runId]);
    $pdo->prepare("UPDATE hr_employee_finance_payroll_links SET link_status='settled',settled_at=NOW(),reversed_at=NULL WHERE payroll_run_id=? AND link_status='included'")->execute([$runId]);
    $pdo->prepare("UPDATE hr_employee_loans l SET l.status='closed',l.closed_at=CURDATE() WHERE l.status='active' AND NOT EXISTS (SELECT 1 FROM hr_loan_repayments r WHERE r.loan_id=l.id AND r.status<>'paid')")->execute();
}

function payroll_reverse_employee_finance_for_run(PDO $pdo, int $runId): void
{
    $pdo->prepare("UPDATE hr_salary_advances a JOIN hr_employee_finance_payroll_links x ON x.source_type='salary_advance' AND x.source_id=a.id SET a.status='pending_deduction',a.payroll_run_id=NULL WHERE x.payroll_run_id=? AND x.link_status='settled' AND a.status='deducted'")->execute([$runId]);
    $pdo->prepare("UPDATE hr_loan_repayments r JOIN hr_employee_finance_payroll_links x ON x.source_type='employee_loan_repayment' AND x.source_id=r.id SET r.status='scheduled',r.paid_amount=NULL,r.paid_at=NULL,r.payroll_run_id=NULL WHERE x.payroll_run_id=? AND x.link_status='settled' AND r.status='paid'")->execute([$runId]);
    $pdo->prepare("UPDATE hr_employee_finance_payroll_links SET link_status='reversed',reversed_at=NOW(),settled_at=NULL WHERE payroll_run_id=? AND link_status='settled'")->execute([$runId]);
    $pdo->prepare("UPDATE hr_employee_loans l SET l.status='active',l.closed_at=NULL WHERE l.status='closed' AND EXISTS (SELECT 1 FROM hr_loan_repayments r WHERE r.loan_id=l.id AND r.status='scheduled')")->execute();
}

/**
 * @return list<string>
 */
function payroll_slip_persist_cols(PDO $pdo): array
{
    static $cache = [];
    $key = spl_object_hash($pdo);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $candidates = [
        'gross_salary', 'bonus', 'allowances', 'income_other_json', 'total_income',
        'tax_withheld', 'provident_fund', 'social_security', 'group_insurance', 'health_insurance',
        'deduction_other_json', 'absent_days', 'late_count_30', 'late_count_60',
        'absence_deduction', 'lateness_deduction', 'attendance_detail_json',
        'total_deductions', 'net_salary',
    ];
    $cols = array_values(array_filter(
        $candidates,
        static fn(string $col): bool => payroll_table_has_column($pdo, 'payroll_slips', $col)
    ));
    if ($cols === []) {
        $cols = array_values(array_filter(
            ['gross_salary', 'total_income', 'total_deductions', 'net_salary'],
            static fn(string $col): bool => payroll_table_has_column($pdo, 'payroll_slips', $col)
        ));
    }
    $cache[$key] = $cols;
    return $cols;
}

/**
 * @param array<string,mixed> $calc
 * @return array<string,mixed>
 */
function payroll_slip_values_from_calc(array $calc): array
{
    return [
        'gross_salary' => $calc['gross_salary'],
        'bonus' => $calc['bonus'],
        'allowances' => $calc['allowances'],
        'income_other_json' => $calc['income_other_json'],
        'total_income' => $calc['total_income'],
        'tax_withheld' => $calc['tax_withheld'],
        'provident_fund' => $calc['provident_fund'],
        'social_security' => $calc['social_security'],
        'group_insurance' => $calc['group_insurance'],
        'health_insurance' => $calc['health_insurance'],
        'deduction_other_json' => $calc['deduction_other_json'],
        'absent_days' => $calc['absent_days'],
        'late_count_30' => $calc['late_count_30'],
        'late_count_60' => $calc['late_count_60'],
        'absence_deduction' => $calc['absence_deduction'],
        'lateness_deduction' => $calc['lateness_deduction'],
        'attendance_detail_json' => $calc['attendance_detail_json'],
        'total_deductions' => $calc['total_deductions'],
        'net_salary' => $calc['net_salary'],
    ];
}

/**
 * Persist calculated slip row (INSERT or UPDATE) with schema fallbacks.
 *
 * @param array<string,mixed> $calc from payroll_calculate_slip_for_user()
 */
function payroll_persist_calculated_slip(PDO $pdo, int $run_id, int $user_id, array $calc, string $mode = 'update'): bool
{
    $cols = payroll_slip_persist_cols($pdo);
    if ($cols === []) {
        throw new RuntimeException('ตาราง payroll_slips ไม่มีคอลัมน์ที่รองรับการบันทึกสลิป');
    }

    $values = payroll_slip_values_from_calc($calc);

    if ($mode === 'insert') {
        $fields = array_merge(['payroll_run_id', 'user_id'], $cols);
        $params = array_merge(
            [$run_id, $user_id],
            array_map(static fn(string $col) => $values[$col] ?? null, $cols)
        );
        $ph = implode(',', array_fill(0, count($fields), '?'));
        try {
            $pdo->prepare('INSERT INTO payroll_slips (' . implode(',', $fields) . ") VALUES ($ph)")
                ->execute($params);
            $slipId = (int)$pdo->lastInsertId();
            payroll_sync_employee_finance_links_for_slip(
                $pdo, $run_id, $slipId, $user_id, $values['deduction_other_json'] ?? null
            );
            return true;
        } catch (PDOException $e) {
            error_log("payroll persist slip INSERT failed for user {$user_id}: " . $e->getMessage());
            throw new RuntimeException(
                'บันทึกสลิปพนักงาน #' . $user_id . ' ไม่สำเร็จ — กรุณาติดต่อผู้ดูแลระบบ',
                0,
                $e
            );
        }
    }

    $stmt = $pdo->prepare('SELECT id FROM payroll_slips WHERE payroll_run_id = ? AND user_id = ?');
    $stmt->execute([$run_id, $user_id]);
    $slip = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$slip) {
        return false;
    }

    $sets = implode(', ', array_map(static fn(string $col): string => "$col = ?", $cols));
    $params = array_map(static fn(string $col) => $values[$col] ?? null, $cols);
    $params[] = $slip['id'];
    try {
        $pdo->prepare("UPDATE payroll_slips SET $sets WHERE id = ?")->execute($params);
        payroll_sync_employee_finance_links_for_slip(
            $pdo, $run_id, (int)$slip['id'], $user_id, $values['deduction_other_json'] ?? null
        );
    } catch (PDOException $e) {
        error_log("payroll persist slip UPDATE failed for user {$user_id}: " . $e->getMessage());
        throw new RuntimeException(
            'อัปเดตสลิปพนักงาน #' . $user_id . ' ไม่สำเร็จ — กรุณาติดต่อผู้ดูแลระบบ',
            0,
            $e
        );
    }
    return true;
}

/**
 * YTD cumulative figures for an employee (through optional payroll month inclusive).
 *
 * @return array{ytd_income: float, ytd_tax: float, ytd_ss: float, ytd_pf: float, ytd_gi: float, ytd_hi: float, ytd_deductions: float, ytd_net: float}
 */
function payroll_fetch_ytd_for_user(PDO $pdo, int $user_id, int $year, ?string $through_month = null): array
{
    $sql = "
        SELECT
            COALESCE(SUM(s.total_income), 0) as ytd_income,
            COALESCE(SUM(s.tax_withheld), 0) as ytd_tax,
            COALESCE(SUM(s.social_security), 0) as ytd_ss,
            COALESCE(SUM(s.provident_fund), 0) as ytd_pf,
            COALESCE(SUM(s.group_insurance), 0) as ytd_gi,
            COALESCE(SUM(s.health_insurance), 0) as ytd_hi,
            COALESCE(SUM(s.total_deductions), 0) as ytd_deductions,
            COALESCE(SUM(s.net_salary), 0) as ytd_net
        FROM payroll_slips s
        JOIN payroll_runs r ON s.payroll_run_id = r.id
        WHERE s.user_id = ? AND YEAR(r.payroll_month) = ?
    ";
    $params = [$user_id, $year];
    if ($through_month !== null && $through_month !== '') {
        $sql .= ' AND r.payroll_month <= ?';
        $params[] = $through_month;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'ytd_income' => (float)($row['ytd_income'] ?? 0),
        'ytd_tax' => (float)($row['ytd_tax'] ?? 0),
        'ytd_ss' => (float)($row['ytd_ss'] ?? 0),
        'ytd_pf' => (float)($row['ytd_pf'] ?? 0),
        'ytd_gi' => (float)($row['ytd_gi'] ?? 0),
        'ytd_hi' => (float)($row['ytd_hi'] ?? 0),
        'ytd_deductions' => (float)($row['ytd_deductions'] ?? 0),
        'ytd_net' => (float)($row['ytd_net'] ?? 0),
    ];
}

/**
 * Format YTD array for LINE Flex / notifications.
 *
 * @param array<string,float> $ytd from payroll_fetch_ytd_for_user()
 * @return array{year: int, income: float, tax: float, ss: float, pf: float, gi: float, hi: float, net: float}
 */
function payroll_format_ytd_payload(array $ytd, int $year): array
{
    return [
        'year' => $year,
        'income' => $ytd['ytd_income'] ?? 0,
        'tax' => $ytd['ytd_tax'] ?? 0,
        'ss' => $ytd['ytd_ss'] ?? 0,
        'pf' => $ytd['ytd_pf'] ?? 0,
        'gi' => $ytd['ytd_gi'] ?? 0,
        'hi' => $ytd['ytd_hi'] ?? 0,
        'net' => $ytd['ytd_net'] ?? 0,
    ];
}

/**
 * Build salary setup override from preview API / form draft POST.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function payroll_build_setup_override_from_input(array $input): array
{
    $override = [];
    if (array_key_exists('base_salary', $input)) {
        $override['base_salary'] = (float)$input['base_salary'];
    }
    if (array_key_exists('bonus_fixed', $input)) {
        $override['bonus_fixed'] = max(0, (float)$input['bonus_fixed']);
    }
    if (array_key_exists('provident_fund', $input)) {
        $override['provident_fund'] = max(0, (float)$input['provident_fund']);
    }
    if (array_key_exists('group_insurance_total_monthly', $input)) {
        $override['group_insurance_total_monthly'] = max(0, (float)$input['group_insurance_total_monthly']);
    }
    if (array_key_exists('group_insurance_employer_pct', $input)) {
        $override['group_insurance_employer_pct'] = (float)$input['group_insurance_employer_pct'];
    }
    if (array_key_exists('health_insurance_total_monthly', $input)) {
        $override['health_insurance_total_monthly'] = max(0, (float)$input['health_insurance_total_monthly']);
    }
    if (array_key_exists('health_insurance_employer_pct', $input)) {
        $override['health_insurance_employer_pct'] = (float)$input['health_insurance_employer_pct'];
    }
    if (!empty($input['ss_opt_out'])) {
        $override['ss_opt_out'] = 1;
    }
    if (!empty($input['tax_opt_out'])) {
        $override['tax_opt_out'] = 1;
    }
    if (!empty($input['hi_opt_out'])) {
        $override['hi_opt_out'] = 1;
    }
    if (!empty($input['gi_opt_out'])) {
        $override['gi_opt_out'] = 1;
    }
    if (array_key_exists('additional_tax_withholding', $input)) {
        $override['additional_tax_withholding'] = max(0, (float)$input['additional_tax_withholding']);
    }
    if (array_key_exists('social_security', $input)) {
        $override['social_security'] = max(0, (float)$input['social_security']);
    }
    if (array_key_exists('social_security_manual', $input)) {
        $override['social_security_manual'] = !empty($input['social_security_manual']) ? 1 : 0;
    }
    if (isset($input['allowance_label']) || isset($input['allowance_amount'])) {
        $override['allowance_json'] = payroll_build_json_from_arrays(
            $input['allowance_label'] ?? [],
            $input['allowance_amount'] ?? [],
            $input['allowance_custom_label'] ?? [],
            $input['allowance_ss_include'] ?? [],
            'allowance'
        );
    }
    if (isset($input['income_other_label']) || isset($input['income_other_amount'])) {
        $override['income_other_json'] = payroll_build_json_from_arrays(
            $input['income_other_label'] ?? [],
            $input['income_other_amount'] ?? [],
            $input['income_other_custom_label'] ?? [],
            $input['income_other_ss_include'] ?? [],
            'income'
        );
    }
    if (isset($input['deduction_other_label']) || isset($input['deduction_other_amount'])) {
        $override['deduction_other_json'] = payroll_build_json_from_arrays(
            $input['deduction_other_label'] ?? [],
            $input['deduction_other_amount'] ?? [],
            $input['deduction_other_custom_label'] ?? []
        );
    }
    return $override;
}

/**
 * Recalculate a single employee's slip in an existing payroll run.
 * Returns true if slip was updated.
 */
function payroll_recalculate_employee_slip($pdo, $run_id, $user_id, $month_first) {
    $runPayDay = payroll_get_default_pay_day();
    try {
        $runStmt = $pdo->prepare('SELECT pay_day FROM payroll_runs WHERE id = ? LIMIT 1');
        $runStmt->execute([$run_id]);
        $runRow = $runStmt->fetch(PDO::FETCH_ASSOC);
        if ($runRow && isset($runRow['pay_day'])) {
            $runPayDay = (int) $runRow['pay_day'];
        }
    } catch (Throwable $e) {
        /* use default */
    }

    $calc = payroll_calculate_slip_for_user($pdo, $user_id, $month_first, $runPayDay);
    $updated = payroll_persist_calculated_slip($pdo, $run_id, $user_id, $calc, 'update');
    if (!$updated) {
        $updated = payroll_persist_calculated_slip($pdo, $run_id, $user_id, $calc, 'insert');
    }
    if ($updated) {
        payroll_update_run_totals($pdo, $run_id);
    }
    return $updated;
}

/**
 * Recalculate payroll_runs aggregate totals from its slips.
 */
function payroll_update_run_totals($pdo, $run_id) {
    $employee_filter = payroll_employee_filter_sql('u');
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(s.total_income),0) as gross, COALESCE(SUM(s.tax_withheld),0) as tax, COALESCE(SUM(s.net_salary),0) as net FROM payroll_slips s JOIN users u ON s.user_id = u.id WHERE s.payroll_run_id = ? AND {$employee_filter}");
    $stmt->execute([$run_id]);
    $agg = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare("UPDATE payroll_runs SET employee_count=?, total_gross=?, total_tax=?, total_net=? WHERE id=?");
    $stmt->execute([(int)$agg['cnt'], (float)$agg['gross'], (float)$agg['tax'], (float)$agg['net'], $run_id]);
}

function payroll_validate_slip_line_event(PDO $pdo, ?string &$reason = null): bool {
    if (!class_exists('LineNotifier')) {
        require_once HR_OWNER_PATH . '/core/Services/LineNotifier.php';
    }

    if (!LineNotifier::enabled()) {
        $reason = 'LINE Notifications ถูกปิดหรือยังไม่ได้ตั้งค่า Channel Access Token';
        return false;
    }

    $cfg = LineNotifier::loadEventConfig($pdo, 'payroll.slip_issued');
    if (!$cfg) {
        $reason = 'ไม่พบ event payroll.slip_issued กรุณารัน migration LINE Notifications';
        return false;
    }
    if (empty($cfg['enabled'])) {
        $reason = 'event payroll.slip_issued ถูกปิดใช้งาน';
        return false;
    }
    if (($cfg['recipient_mode'] ?? '') !== 'triggering_user') {
        $reason = 'เพื่อความปลอดภัยของสลิปเงินเดือน event payroll.slip_issued ต้องตั้ง Recipient Mode เป็น Triggering user เท่านั้น';
        return false;
    }

    return true;
}

/**
 * Load data for the current payroll action.
 * Returns an associative array of variables to extract in the controller.
 */
function payroll_load_data($pdo, $action, $run_id, $user_id_setup, $for_month = '') {
    $runs = [];
    $run = null;
    $slips = [];
    $employees = [];
    $setup_user = null;
    $salary_setup = null;
    $departments = [];
    $dept_filter = '';
    $flag_filter = '';
    $user_has_salary = [];
    $item_presets = ['income' => [], 'deduction' => [], 'allowance' => []];
    $latest_run = null;

    if ($action === 'runs' || $action === '' || $run_id > 0) {
        $stmt = $pdo->query("SELECT r.*, u.first_name_th as approver_first, u.last_name_th as approver_last FROM payroll_runs r LEFT JOIN users u ON r.approved_by = u.id ORDER BY r.payroll_month DESC LIMIT 50");
        $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $latest_run = !empty($runs) ? $runs[0] : null;

    if ($run_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM payroll_runs WHERE id = ?");
        $stmt->execute([$run_id]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($run) {
            $stmt = $pdo->prepare("SELECT s.*, u.employee_code, u.first_name_th, u.last_name_th,
                       (u.line_user_id IS NOT NULL AND u.line_user_id <> '') AS has_line,
                       (SELECT COUNT(*) FROM line_notification_log l
                          WHERE l.module = 'payroll' AND l.event = 'slip_issued'
                            AND l.target_user_id = u.id AND l.status = 'sent'
                            AND l.created_at >= r.created_at) AS line_sent_count,
                       (SELECT MAX(l.created_at) FROM line_notification_log l
                          WHERE l.module = 'payroll' AND l.event = 'slip_issued'
                            AND l.target_user_id = u.id AND l.status = 'sent'
                            AND l.created_at >= r.created_at) AS line_last_sent_at,
                       (SELECT COUNT(*) FROM payroll_slip_tokens t
                          WHERE t.slip_id = s.id AND t.revoked_at IS NULL AND t.expires_at > NOW()) AS active_tokens,
                       (SELECT COALESCE(SUM(t.access_count),0) FROM payroll_slip_tokens t
                          WHERE t.slip_id = s.id) AS total_downloads
                FROM payroll_slips s
                JOIN users u ON s.user_id = u.id
                JOIN payroll_runs r ON s.payroll_run_id = r.id
                WHERE s.payroll_run_id = ? AND " . payroll_employee_filter_sql('u') . "
                ORDER BY u.employee_code");
            $stmt->execute([$run_id]);
            $slips = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $runMonthFirst = date('Y-m-01', strtotime((string)$run['payroll_month']));
            $runPayDay = (int)$run['pay_day'];
            $dateChanges = \TpCommon\Hr\PayrollPaymentDate::changes($pdo, $run_id);
            foreach ($slips as &$slipRow) {
                $dateChange = $dateChanges[(int)$slipRow['user_id']] ?? [];
                $slipRow['payment_date'] = $dateChange['payment_date'] ?? payroll_format_pay_date($run['payroll_month'], $runPayDay);
                $slipRow['payment_date_revision'] = (int)($dateChange['id'] ?? 0);
                $slipRow['payment_date_override'] = !empty($dateChange['payment_date']);
                $slipRow['payment_date_reason'] = (string)($dateChange['reason'] ?? '');
                $slipRow['payment_date_changed_at'] = (string)($dateChange['created_at'] ?? '');
                $profileBase = payroll_resolve_profile_base_salary($pdo, (int)$slipRow['user_id'], $runMonthFirst);
                $slipRow['hr_profile_base'] = $profileBase;
                if ($profileBase === null || $profileBase <= 0) {
                    $slipRow['hr_base_sync_status'] = 'missing';
                    continue;
                }
                try {
                    $freshSlip = payroll_calculate_slip_for_user($pdo, (int)$slipRow['user_id'], $runMonthFirst, $runPayDay);
                    $slipRow['hr_base_sync_status'] = payroll_salary_snapshot_status(
                        $profileBase,
                        (float)$slipRow['gross_salary'],
                        (float)$freshSlip['gross_salary']
                    );
                } catch (Throwable $e) {
                    $slipRow['hr_base_sync_status'] = 'unknown';
                }
            }
            unset($slipRow);
        }
    }

    if ($action === 'employees' || $action === 'salary_setup') {
        $dept_filter = trim($_GET['department'] ?? '');
        $flag_filter = trim($_GET['flag'] ?? ''); // 'multi' | 'ss_optout' | ''
        $sql = "SELECT u.id, u.employee_code, u.first_name_th, u.last_name_th, u.department, u.position,
                       COALESCE(u.has_other_employer_income, 0) AS has_other_employer_income,
                       (SELECT COALESCE(ss.ss_opt_out,0) FROM employee_salary_setup ss
                        WHERE ss.user_id = u.id ORDER BY ss.effective_from DESC, ss.id DESC LIMIT 1) AS ss_opt_out
                FROM users u WHERE u.is_active = 1 AND " . payroll_employee_filter_sql('u');
        $params = [];
        if ($dept_filter !== '') {
            $sql .= " AND u.department = ?";
            $params[] = $dept_filter;
        }
        if ($flag_filter === 'multi') {
            $sql .= " AND COALESCE(u.has_other_employer_income,0) = 1";
        } elseif ($flag_filter === 'ss_optout') {
            $sql .= " AND EXISTS(SELECT 1 FROM employee_salary_setup ss WHERE ss.user_id = u.id AND ss.ss_opt_out = 1)";
        }
        $sql .= " ORDER BY u.employee_code";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Fallback if has_other_employer_income / ss_opt_out columns missing
            $sql_fb = "SELECT u.id, u.employee_code, u.first_name_th, u.last_name_th, u.department, u.position,
                              0 AS has_other_employer_income, 0 AS ss_opt_out
                       FROM users u WHERE u.is_active = 1 AND " . payroll_employee_filter_sql('u');
            $params_fb = [];
            if ($dept_filter !== '') { $sql_fb .= " AND u.department = ?"; $params_fb[] = $dept_filter; }
            $sql_fb .= " ORDER BY u.employee_code";
            $stmt = $pdo->prepare($sql_fb);
            $stmt->execute($params_fb);
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $pdo->query("SELECT DISTINCT department FROM users u WHERE u.is_active = 1 AND " . payroll_employee_filter_sql('u') . " AND u.department IS NOT NULL AND u.department != '' ORDER BY u.department");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $departments[] = $row['department']; }

        $user_has_salary = [];
        if (!empty($employees)) {
            $ids = array_column($employees, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT user_id, MAX(effective_from) as last_from FROM employee_salary_setup WHERE user_id IN ($placeholders) GROUP BY user_id");
            $stmt->execute($ids);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $user_has_salary[(int)$row['user_id']] = true; }
        }

        if ($action === 'salary_setup' && $user_id_setup > 0) {
            payroll_ensure_social_security_manual_column($pdo);
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
            $stmt->execute([$user_id_setup]);
            $setup_user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($setup_user) {
                if (preg_match('/^\d{4}-\d{2}$/', $for_month)) {
                    $salary_setup = payroll_get_salary_setup_for_edit_month($pdo, $user_id_setup, $for_month);
                } else {
                    $salary_setup = get_salary_setup_for_month($pdo, $user_id_setup, date('Y-m-01'));
                }
            }
        }

        // Load preset items for income/deduction/allowance
        try {
            $stmt = $pdo->query("SELECT id, category, label, default_amount, ss_include FROM payroll_item_presets WHERE is_active = 1 ORDER BY category, sort_order, label");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!isset($row['ss_include'])) {
                    $row['ss_include'] = payroll_default_ss_include_for_label((string)$row['category'], (string)$row['label']);
                }
                $item_presets[$row['category']][] = $row;
            }
        } catch (Exception $e) {
            try {
                $stmt = $pdo->query("SELECT id, category, label, default_amount FROM payroll_item_presets WHERE is_active = 1 ORDER BY category, sort_order, label");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $row['ss_include'] = payroll_default_ss_include_for_label((string)$row['category'], (string)$row['label']);
                    $item_presets[$row['category']][] = $row;
                }
            } catch (Exception $e2) { /* table may not exist yet */ }
        }
    }

    return compact('runs', 'run', 'slips', 'employees', 'setup_user', 'salary_setup',
                   'departments', 'user_has_salary', 'item_presets', 'latest_run',
                   'dept_filter', 'flag_filter');
}
