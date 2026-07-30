<?php
/**
 * PayrollService — Canonical payroll business logic (Phase 6.1.2)
 *
 * Migrated from tp-crm/modules/payroll/queries.php + actions.php.
 * tp-hr is now the owner of payroll calculations; tp-crm consumes via API.
 */

class PayrollService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ──────────────── Calculation helpers ────────────────

    public function isSsEnabled(): bool
    {
        $val = $this->getSetting('payroll_ss_enabled', '0');
        return ($val === '1' || $val === 1 || $val === true);
    }

    public function isTaxEnabled(): bool
    {
        $val = $this->getSetting('payroll_tax_enabled', '1');
        return ($val === '1' || $val === 1 || $val === true);
    }

    public function isHealthInsuranceEnabled(): bool
    {
        $val = $this->getSetting('payroll_health_insurance_enabled', '0');
        return ($val === '1' || $val === 1 || $val === true);
    }

    public function isGroupInsuranceEnabled(): bool
    {
        $val = $this->getSetting('payroll_group_insurance_enabled', '1');
        return ($val === '1' || $val === 1 || $val === true);
    }

    /** @return string|null YYYY-MM-01 */
    public function getCompanyBenefitStart(string $settingKey): ?string
    {
        $val = trim((string)$this->getSetting($settingKey, ''));
        if ($val === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2})(-\d{2})?$/', $val, $m)) {
            return $m[1] . '-01';
        }
        return null;
    }

    public function normalizeSettingMonth(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2})/', trim($date), $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Effective withholding month = max(company start, employee start) when both apply.
     */
    public function effectiveWithholdingApplies(
        ?string $companyStartDate,
        ?string $employeeStartDate,
        string $monthFirst,
        bool $requireEmployeeStartDate
    ): bool {
        $monthYm = $this->normalizeSettingMonth($monthFirst);
        if ($monthYm === null) {
            return false;
        }

        $companyYm = $this->normalizeSettingMonth($companyStartDate);
        $employeeYm = $this->normalizeSettingMonth($employeeStartDate);

        if ($requireEmployeeStartDate && $employeeYm === null) {
            return false;
        }

        $effectiveYm = null;
        if ($requireEmployeeStartDate) {
            $effectiveYm = $employeeYm;
            if ($companyYm !== null && ($effectiveYm === null || $companyYm > $effectiveYm)) {
                $effectiveYm = $companyYm;
            }
        } elseif ($employeeYm !== null && $companyYm !== null) {
            $effectiveYm = max($employeeYm, $companyYm);
        } elseif ($employeeYm !== null) {
            $effectiveYm = $employeeYm;
        } elseif ($companyYm !== null) {
            $effectiveYm = $companyYm;
        } else {
            return true;
        }

        if ($effectiveYm === null) {
            return !$requireEmployeeStartDate;
        }

        return $monthYm >= $effectiveYm;
    }

    public function isSsEnabledForMonth(?string $monthFirst = null): bool
    {
        if (!$this->isSsEnabled()) {
            return false;
        }
        $monthFirst = $monthFirst ?: date('Y-m-01');
        return $this->effectiveWithholdingApplies(
            $this->getCompanyBenefitStart('payroll_ss_enabled_from'),
            null,
            $monthFirst,
            false
        );
    }

    public function isTaxEnabledForMonth(?string $monthFirst = null): bool
    {
        if (!$this->isTaxEnabled()) {
            return false;
        }
        $monthFirst = $monthFirst ?: date('Y-m-01');
        return $this->effectiveWithholdingApplies(
            $this->getCompanyBenefitStart('payroll_tax_enabled_from'),
            null,
            $monthFirst,
            false
        );
    }

    public function isHealthInsuranceEnabledForMonth(?string $monthFirst = null): bool
    {
        if (!$this->isHealthInsuranceEnabled()) {
            return false;
        }
        $monthFirst = $monthFirst ?: date('Y-m-01');
        return $this->effectiveWithholdingApplies(
            $this->getCompanyBenefitStart('payroll_health_insurance_enabled_from'),
            null,
            $monthFirst,
            false
        );
    }

    public function isGroupInsuranceEnabledForMonth(?string $monthFirst = null): bool
    {
        if (!$this->isGroupInsuranceEnabled()) {
            return false;
        }
        $monthFirst = $monthFirst ?: date('Y-m-01');
        return $this->effectiveWithholdingApplies(
            $this->getCompanyBenefitStart('payroll_group_insurance_enabled_from'),
            null,
            $monthFirst,
            false
        );
    }

    /**
     * Social security wage ceiling by payroll month.
     * Before 2026-01: 15,000 (cap 750). From 2026-01 (BE 2569): 17,500 (cap 875).
     */
    public function ssWageCeiling(?string $monthFirst = null): float
    {
        $override = $this->getSetting('payroll_ss_max_base', '');
        if ($override !== '' && (float)$override > 0) {
            return (float)$override;
        }
        $ref = $monthFirst ?: date('Y-m-01');
        return ($ref >= '2026-01-01') ? 17500.0 : 15000.0;
    }

    public function ssMaxContribution(?string $monthFirst = null): float
    {
        return round($this->ssWageCeiling($monthFirst) * 0.05, 2);
    }

    /**
     * Social security (employee portion).
     * Rate 5%, base 1,650–ceiling, cap per month per law.
     */
    public function calcSocialSecurity(float $wageBase, bool $optOut = false, ?string $monthFirst = null): float
    {
        if (!$this->isSsEnabledForMonth($monthFirst) || $optOut || $wageBase <= 0) return 0;
        $ceiling = $this->ssWageCeiling($monthFirst);
        $base = max(1650, min($wageBase, $ceiling));
        return round($base * 0.05, 2);
    }

    /**
     * ฐานค่าจ้างสำหรับเงินสมทบประกันสังคม ม.33 — รวมเฉพาะรายได้ประจำทุกเดือน
     * ข้ามรายการ ss_exclude=1 และรายได้ไม่ประจำตามชื่อ preset
     *
     * @param array<string,mixed>|null $setup
     */
    public function socialSecurityWageBase($setup): float
    {
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
                if (!is_array($item) || $this->itemSsExcluded($item, $category)) {
                    continue;
                }
                $base += (float)($item['amount'] ?? 0);
            }
        }

        return round(max(0, $base), 2);
    }

    /** @return list<string> */
    private function ssVariableIncomeLabels(): array
    {
        return [
            'เบี้ยขยัน',
            'ค่าคอมมิชชั่น',
            'ค่าล่วงเวลา (OT)',
            'เงินชดเชยวันทำงาน',
            'ค่าบริการ',
            'OT',
        ];
    }

    /** @return list<string> */
    private function ssFixedIncomeLabels(): array
    {
        return [
            'ค่าตำแหน่ง',
            'ค่าเดินทาง',
            'ค่าครองชีพ',
        ];
    }

    private function defaultSsIncludeForLabel(string $category, string $label): int
    {
        $label = trim($label);
        if ($label === '') {
            return $category === 'allowance' ? 1 : 0;
        }
        if ($category === 'allowance') {
            return 1;
        }
        if ($category === 'income') {
            if (in_array($label, $this->ssFixedIncomeLabels(), true)) {
                return 1;
            }
            if (in_array($label, $this->ssVariableIncomeLabels(), true)) {
                return 0;
            }
            return 0;
        }
        return 0;
    }

    /** @param array<string,mixed> $item */
    private function itemSsExcluded(array $item, string $category): bool
    {
        if (array_key_exists('ss_exclude', $item)) {
            return !empty($item['ss_exclude']);
        }
        return $this->defaultSsIncludeForLabel($category, (string)($item['label'] ?? '')) === 0;
    }

    /**
     * @param array<string,mixed>|null $setup
     */
    public function resolveSocialSecurityAmount($setup, float $autoAmount): float
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
     * @param array<string,mixed> $data
     * @return array{social_security: float, social_security_manual: int}
     */
    private function resolveSocialSecurityForSave(int $userId, array $data, string $effectiveFrom): array
    {
        $ssOptOut = !empty($data['ss_opt_out']);
        $manual = !empty($data['social_security_manual']);
        $auto = $this->calcSocialSecurityForUser(
            $userId,
            $this->socialSecurityWageBase([
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
        if (!$ssOptOut && $this->isSsEnabledForMonth($effectiveFrom)) {
            $amount = min($amount, $this->ssMaxContribution($effectiveFrom));
        }
        return ['social_security' => round($amount, 2), 'social_security_manual' => 1];
    }

    public function getUserSocialSecurityStartDate(int $userId): ?string
    {
        return $this->getUserDateColumn($userId, 'social_security_start_date');
    }

    public function getUserTaxWithholdingStartDate(int $userId): ?string
    {
        return $this->getUserDateColumn($userId, 'tax_withholding_start_date');
    }

    public function getUserHealthInsuranceStartDate(int $userId): ?string
    {
        return $this->getUserDateColumn($userId, 'health_insurance_start_date');
    }

    public function getUserGroupInsuranceStartDate(int $userId): ?string
    {
        return $this->getUserDateColumn($userId, 'group_insurance_start_date');
    }

    private function getUserDateColumn(int $userId, string $column): ?string
    {
        static $cache = [];
        $key = $userId . ':' . $column;
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
            $stmt = $this->pdo->prepare("SELECT {$column} FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $val = ($row && !empty($row[$column]))
                ? substr((string)$row[$column], 0, 10)
                : null;
            $cache[$key] = $val;
            return $val;
        } catch (\Throwable $e) {
            $cache[$key] = null;
            return null;
        }
    }

    /**
     * หักเมื่อ payroll_month >= เดือนของ start_date
     *
     * @param bool $requireStartDate true = ยังไม่ระบุวันเริ่มหัก → ไม่หัก (SS/ประกันสุขภาพ)
     *                               false = ยังไม่ระบุ → หักได้ทันที (ภาษี/ประกันกลุ่ม backward compat)
     */
    public function benefitAppliesForMonth(?string $startDate, string $monthFirst, bool $requireStartDate = true): bool
    {
        if ($startDate === null || $startDate === '') {
            return !$requireStartDate;
        }
        $startYm = substr($startDate, 0, 7);
        $monthYm = substr($monthFirst, 0, 7);
        if ($startYm === '' || $monthYm === '') {
            return false;
        }
        return $monthYm >= $startYm;
    }

    /**
     * หัก SS เมื่อ payroll_month >= เดือนของ social_security_start_date
     * ยังไม่ระบุวันเริ่มหัก → ไม่หัก (รอผ่านโปร)
     */
    public function ssAppliesForMonth(?string $ssStartDate, string $monthFirst): bool
    {
        return $this->effectiveWithholdingApplies(
            $this->getCompanyBenefitStart('payroll_ss_enabled_from'),
            $ssStartDate,
            $monthFirst,
            true
        );
    }

    /** ว่างพนักงาน = หักได้ทันทีเมื่อเปิด tax · มีวันที่ = หักเมื่อ payroll_month >= max(บริษัท, พนักงาน) */
    public function taxAppliesForMonth(?string $taxStartDate, string $monthFirst): bool
    {
        return $this->effectiveWithholdingApplies(
            $this->getCompanyBenefitStart('payroll_tax_enabled_from'),
            $taxStartDate,
            $monthFirst,
            false
        );
    }

    public function calcSocialSecurityForUser(int $userId, float $wageBase, bool $optOut = false, ?string $monthFirst = null): float
    {
        $start = $this->getUserSocialSecurityStartDate($userId);
        if (!$this->ssAppliesForMonth($start, $monthFirst ?: date('Y-m-01'))) {
            return 0.0;
        }
        return $this->calcSocialSecurity($wageBase, $optOut, $monthFirst);
    }

    public function getUserHireDate(int $userId): ?string
    {
        static $cache = [];
        if (array_key_exists($userId, $cache)) {
            return $cache[$userId];
        }
        try {
            $stmt = $this->pdo->prepare('SELECT hire_date FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $val = ($row && !empty($row['hire_date']))
                ? substr((string)$row['hire_date'], 0, 10)
                : null;
            $cache[$userId] = $val;
            return $val;
        } catch (\Throwable $e) {
            $cache[$userId] = null;
            return null;
        }
    }

    /**
     * @return array{probation_salary: ?float, salary: ?float, probation_passed_date: ?string}|null
     */
    public function getUserSalaryProfile(int $userId): ?array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT probation_salary, salary, probation_passed_date FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
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
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @param array{probation_salary: ?float, salary: ?float, probation_passed_date: ?string}|null $profile */
    public function isOnProbationForMonth(?array $profile, string $monthFirst): bool
    {
        if (!$profile) {
            return false;
        }
        $passedDate = $profile['probation_passed_date'] ?? null;
        if ($passedDate === null || $passedDate === '') {
            return true;
        }
        $periodEnd = $this->attendancePeriodBounds($monthFirst)['end'];
        return $passedDate > $periodEnd;
    }

    public function resolveProfileBaseSalary(int $userId, string $monthFirst): ?float
    {
        $profile = $this->getUserSalaryProfile($userId);
        if (!$profile) {
            return null;
        }
        $prob = $profile['probation_salary'];
        $salary = $profile['salary'];
        if ($this->isOnProbationForMonth($profile, $monthFirst)) {
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
     * @param array<string,mixed>|null $setup
     */
    public function resolveBaseSalaryForMonth(int $userId, string $monthFirst, ?array $setup, bool $explicitBaseOverride = false): float
    {
        if ($explicitBaseOverride && is_array($setup) && array_key_exists('base_salary', $setup)) {
            return (float)$setup['base_salary'];
        }
        $profileBase = $this->resolveProfileBaseSalary($userId, $monthFirst);
        if ($profileBase !== null && $profileBase > 0) {
            return $profileBase;
        }
        return is_array($setup) ? (float)($setup['base_salary'] ?? 0) : 0.0;
    }

    public function inclusiveDayCount(string $start, string $end): int
    {
        $a = strtotime($start);
        $b = strtotime($end);
        if (!$a || !$b || $b < $a) {
            return 0;
        }
        return (int)(($b - $a) / 86400) + 1;
    }

    public function hireProrateFactor(?string $hireDate, string $periodStart, string $periodEnd): float
    {
        if ($hireDate === null || $hireDate === '') {
            return 0.0;
        }
        if ($hireDate > $periodEnd) {
            return 0.0;
        }
        if ($hireDate <= $periodStart) {
            return 1.0;
        }
        $total = $this->inclusiveDayCount($periodStart, $periodEnd);
        $employed = $this->inclusiveDayCount($hireDate, $periodEnd);
        return $total > 0 ? round($employed / $total, 6) : 0.0;
    }

    public function isFirstHirePayrollMonth(?string $hireDate, string $payrollMonth): bool
    {
        if ($hireDate === null || $hireDate === '') {
            return false;
        }

        return substr($hireDate, 0, 7) === substr($payrollMonth, 0, 7);
    }

    /** เริ่มงานวันที่ 1 ของเดือน → จ่ายเต็มเดือน (ไม่ใช้ ÷30) */
    public function isHireOnMonthFirstDay(?string $hireDate, string $payrollMonth): bool
    {
        if ($hireDate === null || $hireDate === '') {
            return false;
        }

        return $hireDate === substr($payrollMonth, 0, 7) . '-01';
    }

    public function isMonthEndDate(?string $date): bool
    {
        if ($date === null || $date === '') {
            return false;
        }
        $monthEnd = date('Y-m-t', strtotime(substr($date, 0, 7) . '-01'));
        return $date === $monthEnd;
    }

    public function getUserTerminationDate(int $userId): ?string
    {
        static $cache = [];
        if (array_key_exists($userId, $cache)) {
            return $cache[$userId];
        }
        try {
            $stmt = $this->pdo->prepare('SELECT termination_date FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $val = ($row && !empty($row['termination_date']))
                ? substr((string)$row['termination_date'], 0, 10)
                : null;
            $cache[$userId] = $val;
            return $val;
        } catch (\Throwable $e) {
            $cache[$userId] = null;
            return null;
        }
    }

    /**
     * @return array{start: string, end: string, cutoff_day: int, pay_day: int, is_first_hire_month?: bool, is_hire_transition?: bool, is_resignation_month_end?: bool, is_terminated_before_period?: bool, standard_start?: string, standard_end?: string}
     */
    public function effectivePeriodBounds(string $payrollMonth, ?int $payDay, ?string $hireDate, ?string $terminationDate = null): array
    {
        $bounds = $this->attendancePeriodBounds($payrollMonth);
        if ($hireDate !== null && $hireDate !== '') {
            $hireYm = substr($hireDate, 0, 7);
            $payrollYm = substr($payrollMonth, 0, 7);
            $hireMonthEnd = date('Y-m-t', strtotime($hireYm . '-01'));

            if ($hireYm === $payrollYm) {
                $bounds['start'] = $hireDate;
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

        if ($terminationDate !== null && $terminationDate !== '' && $this->isMonthEndDate($terminationDate)) {
            $termYm = substr($terminationDate, 0, 7);
            $payrollYm = substr($payrollMonth, 0, 7);

            if ($termYm < $payrollYm) {
                $bounds['is_terminated_before_period'] = true;
            } elseif ($termYm === $payrollYm) {
                if (!isset($bounds['standard_end'])) {
                    $bounds['standard_end'] = $bounds['end'];
                }
                $bounds['end'] = $terminationDate;
                $bounds['is_resignation_month_end'] = true;
            }
        }

        return $bounds;
    }

    /**
     * @param array{start: string, end: string, standard_end?: string, is_first_hire_month?: bool, is_resignation_month_end?: bool, is_terminated_before_period?: bool} $period
     */
    public function cycleEmploymentFactor(?string $hireDate, array $period): float
    {
        if (!empty($period['is_terminated_before_period'])) {
            return 0.0;
        }
        if (!empty($period['is_first_hire_month'])) {
            return $this->hireIncomeProrateFactor($hireDate, $period);
        }
        if (!empty($period['is_resignation_month_end'])) {
            return 1.0;
        }

        $standardStart = $period['start'];
        $standardEnd = $period['standard_end'] ?? $period['end'];
        $effectiveStart = $standardStart;
        if ($hireDate !== null && $hireDate !== '' && $hireDate > $effectiveStart) {
            $effectiveStart = $hireDate;
        }
        $effectiveEnd = $standardEnd;

        if ($hireDate !== null && $hireDate !== '' && $hireDate > $effectiveEnd) {
            return 0.0;
        }

        $total = $this->inclusiveDayCount($standardStart, $standardEnd);
        if ($total <= 0) {
            return 0.0;
        }
        $employed = $this->inclusiveDayCount($effectiveStart, $effectiveEnd);
        return round($employed / $total, 6);
    }

    /**
     * @param array{end: string, standard_end?: string, is_resignation_month_end?: bool} $period
     * @return array{gross_add: float, allowances_add: float, tail_days: int}
     */
    public function resignationTailIncomeAdd(?string $terminationDate, array $period, float $baseGross, float $baseAllowances): array
    {
        $empty = ['gross_add' => 0.0, 'allowances_add' => 0.0, 'tail_days' => 0];
        if (empty($period['is_resignation_month_end']) || $terminationDate === null || $terminationDate === '') {
            return $empty;
        }
        if (!$this->isMonthEndDate($terminationDate)) {
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

        $tailDays = $this->inclusiveDayCount($tailStart, $tailEnd);
        if ($tailDays <= 0) {
            return $empty;
        }

        $divisor = $this->getFirstHireDailyDivisor();
        return [
            'gross_add' => $baseGross > 0 ? round($tailDays * ($baseGross / $divisor), 2) : 0.0,
            'allowances_add' => $baseAllowances > 0 ? round($tailDays * ($baseAllowances / $divisor), 2) : 0.0,
            'tail_days' => $tailDays,
        ];
    }

    /**
     * @param array{start: string, end: string, is_first_hire_month?: bool, is_hire_transition?: bool, standard_start?: string, standard_end?: string} $period
     */
    public function hireIncomeProrateFactor(?string $hireDate, array $period): float
    {
        if ($hireDate === null || $hireDate === '') {
            return 0.0;
        }
        if ($hireDate > $period['end']) {
            return 0.0;
        }

        if (!empty($period['is_first_hire_month'])) {
            $monthStart = substr($hireDate, 0, 7) . '-01';
            $monthEnd = date('Y-m-t', strtotime($monthStart));
            $total = $this->inclusiveDayCount($monthStart, $monthEnd);
            $employed = $this->inclusiveDayCount($period['start'], $period['end']);
            return $total > 0 ? round($employed / $total, 6) : 0.0;
        }

        return $this->hireProrateFactor($hireDate, $period['start'], $period['end']);
    }

    public function getFirstHireDailyDivisor(): int
    {
        $v = (int)$this->getSetting('payroll_first_hire_daily_divisor', '30');
        return max(1, min(31, $v));
    }

    public function effectiveDayOffForDate(array $ctx, string $date): int
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

    public function isScheduledDayOff(array $ctx, string $date): bool
    {
        return (int)date('w', strtotime($date)) === $this->effectiveDayOffForDate($ctx, $date);
    }

    public function countDayOffDaysInCalendarMonth(array $ctx, string $monthYm): int
    {
        $monthStart = $monthYm . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $count = 0;
        for ($ts = strtotime($monthStart); $ts !== false && $ts <= strtotime($monthEnd); $ts += 86400) {
            if ($this->isScheduledDayOff($ctx, date('Y-m-d', $ts))) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param array{start: string, end: string, is_first_hire_month?: bool} $period
     */
    public function firstHirePayableDays(int $userId, array $period, string $hireDate): int
    {
        $ctx = $this->buildWorkdayContext($userId, $period['start'], $period['end']);
        $payable = 0;
        for ($ts = strtotime($period['start']); $ts !== false && $ts <= strtotime($period['end']); $ts += 86400) {
            $date = date('Y-m-d', $ts);
            if ($this->isPayrollWorkday($ctx, $date)) {
                $payable++;
            }
        }

        return max(0, $payable);
    }

    public function shouldIncludeEmployeeInRun(int $userId, ?string $hireDate, string $payrollMonth, ?int $payDay): bool
    {
        if ($hireDate === null || $hireDate === '') {
            return false;
        }

        $terminationDate = $this->getUserTerminationDate($userId);
        $period = $this->effectivePeriodBounds($payrollMonth, $payDay, $hireDate, $terminationDate);
        if (!empty($period['is_terminated_before_period'])) {
            return false;
        }
        if (!empty($period['is_first_hire_month'])) {
            return $this->firstHirePayableDays($userId, $period, $hireDate) > 0;
        }

        return $this->cycleEmploymentFactor($hireDate, $period) > 0;
    }

    private function scaleIncomeOtherJson(?string $json, float $factor): ?string
    {
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
     * @return float prorate factor 0–1
     */
    public function applyHireDateIncome(
        int $userId,
        string $monthFirst,
        ?int $payDay,
        float &$gross,
        float &$bonus,
        float &$allowances,
        float &$incomeOther,
        ?string &$incomeOtherJson
    ): float {
        $hireDate = $this->getUserHireDate($userId);
        $terminationDate = $this->getUserTerminationDate($userId);
        $period = $this->effectivePeriodBounds($monthFirst, $payDay, $hireDate, $terminationDate);

        if (!empty($period['is_first_hire_month']) && $hireDate !== null && $hireDate !== '') {
            if ($this->isHireOnMonthFirstDay($hireDate, $monthFirst)) {
                return 1.0;
            }

            $originalGross = $gross;
            $originalAllowances = $allowances;
            $payableDays = $this->firstHirePayableDays($userId, $period, $hireDate);
            $divisor = $this->getFirstHireDailyDivisor();

            if ($payableDays <= 0) {
                $gross = $bonus = $allowances = $incomeOther = 0.0;
                $incomeOtherJson = null;
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

            if ($originalGross > 0) {
                return round($gross / $originalGross, 6);
            }
            return round($payableDays / $divisor, 6);
        }

        $originalGross = $gross;
        $originalAllowances = $allowances;
        $factor = $this->cycleEmploymentFactor($hireDate, $period);
        if ($factor <= 0) {
            $gross = $bonus = $allowances = $incomeOther = 0.0;
            $incomeOtherJson = null;
            return 0.0;
        }
        if ($factor < 1.0) {
            $gross = round($gross * $factor, 2);
            $bonus = round($bonus * $factor, 2);
            $allowances = round($allowances * $factor, 2);
            $incomeOther = round($incomeOther * $factor, 2);
            $incomeOtherJson = $this->scaleIncomeOtherJson($incomeOtherJson, $factor);
        }

        if (!empty($period['is_resignation_month_end'])) {
            $tail = $this->resignationTailIncomeAdd($terminationDate, $period, $originalGross, $originalAllowances);
            if ($tail['gross_add'] > 0) {
                $gross = round($gross + $tail['gross_add'], 2);
            }
            if ($tail['allowances_add'] > 0) {
                $allowances = round($allowances + $tail['allowances_add'], 2);
            }
            if ($originalGross > 0) {
                return round($gross / $originalGross, 6);
            }
            return 1.0;
        }

        return $factor;
    }

    /**
     * Split benefit premium (group insurance / health insurance) — employee portion.
     */
    public function calcBenefitEmployeeShare(float $totalMonthly, float $employerPct): float
    {
        if ($totalMonthly <= 0) return 0;
        $employerPct = max(0, min(100, $employerPct));
        return round($totalMonthly * (100 - $employerPct) / 100, 2);
    }

    /** @deprecated use calcBenefitEmployeeShare */
    public function calcGroupInsurance(float $totalMonthly, float $employerPct): float
    {
        return $this->calcBenefitEmployeeShare($totalMonthly, $employerPct);
    }

    /** Prorate fixed monthly deductions (PF/GI/HI) when employment factor is partial (< 1). */
    public function benefitProrationFactor(float $hireFactor): float
    {
        if ($hireFactor <= 0) {
            return 0.0;
        }
        if ($hireFactor >= 1.0) {
            return 1.0;
        }
        return $hireFactor;
    }

    public function userHasOtherEmployerIncome(int $userId): bool
    {
        static $cache = [];
        if (array_key_exists($userId, $cache)) {
            return $cache[$userId];
        }
        try {
            $stmt = $this->pdo->prepare('SELECT COALESCE(has_other_employer_income, 0) FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $cache[$userId] = (int)$stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            $cache[$userId] = false;
        }
        return $cache[$userId];
    }

    public function calcTaxForUser(
        int $userId,
        float $annualIncome,
        float $annualSs = 0,
        float $annualPf = 0,
        ?string $monthFirst = null,
        bool $optOut = false
    ): float {
        if (!$this->isTaxEnabledForMonth($monthFirst) || $optOut || $annualIncome <= 0) {
            return 0.0;
        }
        $monthFirst = $monthFirst ?: date('Y-m-01');
        if (!$this->taxAppliesForMonth($this->getUserTaxWithholdingStartDate($userId), $monthFirst)) {
            return 0.0;
        }
        $skipPersonal = $this->userHasOtherEmployerIncome($userId);
        return $this->calcTaxMonthly($annualIncome, $annualSs, $annualPf, $monthFirst, $skipPersonal);
    }

    public function calcHealthInsuranceForUser(
        int $userId,
        float $totalMonthly,
        float $employerPct,
        bool $optOut = false,
        ?string $monthFirst = null
    ): float {
        if (!$this->isHealthInsuranceEnabledForMonth($monthFirst) || $optOut || $totalMonthly <= 0) {
            return 0.0;
        }
        $monthFirst = $monthFirst ?: date('Y-m-01');
        if (!$this->effectiveWithholdingApplies(
            $this->getCompanyBenefitStart('payroll_health_insurance_enabled_from'),
            $this->getUserHealthInsuranceStartDate($userId),
            $monthFirst,
            true
        )) {
            return 0.0;
        }
        return $this->calcBenefitEmployeeShare($totalMonthly, $employerPct);
    }

    public function calcGroupInsuranceForUser(
        int $userId,
        float $totalMonthly,
        float $employerPct,
        bool $optOut = false,
        ?string $monthFirst = null
    ): float {
        if (!$this->isGroupInsuranceEnabledForMonth($monthFirst) || $optOut || $totalMonthly <= 0) {
            return 0.0;
        }
        $monthFirst = $monthFirst ?: date('Y-m-01');
        if (!$this->effectiveWithholdingApplies(
            $this->getCompanyBenefitStart('payroll_group_insurance_enabled_from'),
            $this->getUserGroupInsuranceStartDate($userId),
            $monthFirst,
            false
        )) {
            return 0.0;
        }
        return $this->calcBenefitEmployeeShare($totalMonthly, $employerPct);
    }

    /**
     * Thai progressive tax (monthly withholding estimate).
     * Reference: Revenue Code §40(1), 42bis, 47
     */
    public function calcTaxMonthly(float $annualIncome, float $annualSs = 0, float $annualPf = 0, ?string $monthFirst = null, bool $skipPersonalAllowance = false): float
    {
        $expenseAllowance = min($annualIncome * 0.5, 100000);
        $personalAllowance = $skipPersonalAllowance ? 0 : 60000;
        $ssAnnualCap = $this->ssMaxContribution($monthFirst) * 12;
        $ssDeduction = min(max(0, $annualSs), $ssAnnualCap);
        $pfCap = min($annualIncome * 0.15, 500000);
        $pfDeduction = min(max(0, $annualPf), $pfCap);
        $taxable = $annualIncome - $expenseAllowance - $personalAllowance - $ssDeduction - $pfDeduction;
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

    public function getDefaultPayDay(): int
    {
        return 31;
    }

    /** Payroll cycle cutoff day (default 25) — period runs (cutoff+1) prev month through cutoff. */
    public function getCutoffDay(): int
    {
        $raw = (int)$this->getSetting('payroll_cutoff_day', '25');
        return ($raw >= 1 && $raw <= 31) ? $raw : 25;
    }

    /**
     * Normal calculation period: (cutoff+1) of previous month → cutoff of payroll month.
     * pay_day (payment date) does not affect bounds.
     *
     * @return array{start: string, end: string, cutoff_day: int, pay_day: int}
     */
    public function attendancePeriodBounds(string $payrollMonth, ?int $payDay = null): array
    {
        $cutoff = $this->getCutoffDay();

        $ts = strtotime($payrollMonth);
        if (!$ts) {
            $today = date('Y-m-d');
            return ['start' => $today, 'end' => $today, 'cutoff_day' => $cutoff, 'pay_day' => $cutoff];
        }

        $lastDay = (int)date('t', $ts);
        $endDay = min($cutoff, $lastDay);
        $periodEnd = date('Y-m-', $ts) . str_pad((string)$endDay, 2, '0', STR_PAD_LEFT);

        $monthFirstTs = strtotime(date('Y-m-01', $ts));
        $prevTs = strtotime('-1 month', $monthFirstTs);
        $prevLast = (int)date('t', $prevTs);
        $prevCutoff = min($cutoff, $prevLast);
        $startDay = $prevCutoff + 1;

        if ($startDay > $prevLast) {
            $periodStart = date('Y-m-01', $monthFirstTs);
        } else {
            $periodStart = date('Y-m-', $prevTs) . str_pad((string)$startDay, 2, '0', STR_PAD_LEFT);
        }

        return ['start' => $periodStart, 'end' => $periodEnd, 'cutoff_day' => $cutoff, 'pay_day' => $cutoff];
    }

    /**
     * Last date to count absences / summaries — completed workdays only (excludes today if period open).
     */
    public function attendanceClosedScanEnd(string $periodStart, string $periodEnd, ?string $asOf = null): string
    {
        $asOf = $asOf ?? date('Y-m-d');
        if ($asOf > $periodEnd) {
            return $periodEnd;
        }
        $yesterday = date('Y-m-d', strtotime($asOf . ' -1 day'));
        if ($yesterday < $periodStart) {
            return '';
        }
        return $yesterday;
    }

    /** วันนี้ปิดรอบแล้วหรือยัง — ใช้กรองหักขาด/มาสายจาก hr_attendances */
    public function isAttendanceDeductibleDate(string $date, string $missingScanEnd): bool
    {
        if ($missingScanEnd === '') {
            return false;
        }
        return $date <= $missingScanEnd;
    }

    /** Payroll month (YYYY-MM) to calculate as of $asOf — after cutoff day → current month. */
    public function suggestPayrollMonth(?int $payDay = null, ?string $asOf = null): string
    {
        $cutoff = $this->getCutoffDay();
        $asOf = $asOf ?? date('Y-m-d');
        $day = (int)date('j', strtotime($asOf));
        $monthFirstTs = strtotime(date('Y-m-01', strtotime($asOf)));
        if ($day > $cutoff) {
            return date('Y-m', $monthFirstTs);
        }
        return date('Y-m', strtotime('-1 month', $monthFirstTs));
    }

    public function isPeriodClosed(string $payrollMonth, ?int $payDay = null, ?string $asOf = null): bool
    {
        $period = $this->attendancePeriodBounds($payrollMonth);
        $asOf = $asOf ?? date('Y-m-d');
        return $asOf > $period['end'];
    }

    /**
     * นาทีขั้นต่ำของ tier มาสาย 150 บาท (default 20 = 20–30 นาที)
     */
    public function lateTier1MinMinutes(): int
    {
        $min = (int) $this->getSetting('payroll_late_tier1_min_minutes', '20');
        return max(1, min(59, $min));
    }

    /** @return 'late_30'|'late_60'|'late_over60'|null */
    public function classifyLateMinutes(int $lateMinutes): ?string
    {
        $tier1 = $this->lateTier1MinMinutes();
        if ($lateMinutes < $tier1) {
            return null;
        }
        if ($lateMinutes <= 30) {
            return 'late_30';
        }
        if ($lateMinutes <= 60) {
            return 'late_60';
        }
        return 'late_over60';
    }

    /**
     * @param array<string,mixed> $result
     */
    private function applyLateMinutesDeduction(
        array &$result,
        string $date,
        int $lateMin,
        float $rateAbsent,
        float $rateLate30,
        float $rateLate60,
        bool $lateOver60AsAbsent,
        string $noteSuffix = ''
    ): bool {
        $tier = $this->classifyLateMinutes($lateMin);
        if ($tier === null) {
            return false;
        }
        $note = "มาสาย {$lateMin} นาที{$noteSuffix}";
        if ($tier === 'late_30') {
            $result['late_count_30']++;
            $result['lateness_deduction'] += $rateLate30;
            $result['breakdown'][] = ['date' => $date, 'kind' => 'late_30', 'amount' => $rateLate30, 'note' => $note];
            return false;
        }
        if ($tier === 'late_60') {
            $result['late_count_60']++;
            $result['lateness_deduction'] += $rateLate60;
            $result['breakdown'][] = ['date' => $date, 'kind' => 'late_60', 'amount' => $rateLate60, 'note' => $note];
            return false;
        }
        if ($lateOver60AsAbsent) {
            $result['absent_days'] += 1;
            $result['breakdown'][] = ['date' => $date, 'kind' => 'late_over60_absent', 'amount' => $rateAbsent, 'note' => $note . ' (ตีเป็นขาด)'];
            return true;
        }
        $result['lateness_deduction'] += $rateLate60;
        $result['breakdown'][] = ['date' => $date, 'kind' => 'late_over60', 'amount' => $rateLate60, 'note' => $note];
        return false;
    }

    /**
     * Compute attendance deductions (absent/late) for the payroll period.
     */
    public function computeAttendanceDeductions(int $userId, string $monthFirst, ?int $payDay = null): array
    {
        $result = [
            'absent_days' => 0.0, 'late_count_30' => 0, 'late_count_60' => 0,
            'absence_deduction' => 0.0, 'lateness_deduction' => 0.0,
            'total_deduction' => 0.0, 'breakdown' => [], 'warnings' => [],
        ];

        // Role-based and approved per-person exemptions must ignore both missing
        // days and historical ABSENT rows so attendance never reduces payroll.
        if (\TpCommon\Hr\AttendanceScope::isUserExemptById($this->pdo, $userId)) {
            return $result;
        }

        if ((int)$this->getSetting('payroll_attendance_enabled', '1') !== 1) {
            return $result;
        }

        $rateAbsent = (float)$this->getSetting('payroll_absent_rate', '600');
        $rateLate30 = (float)$this->getSetting('payroll_late_30_rate', '150');
        $rateLate60 = (float)$this->getSetting('payroll_late_60_rate', '300');
        $lateOver60AsAbsent = (int)$this->getSetting('payroll_late_over60_as_absent', '1') === 1;
        $leaveAdvanceDays = (int)$this->getSetting('payroll_leave_advance_days', '7');
        $usePlannedStart = (int) $this->getSetting('payroll_use_planned_start', '1') === 1;
        $plannedGraceMin = (int) $this->getSetting('payroll_planned_grace_minutes', '30');

        $hireDate = $this->getUserHireDate($userId);
        $terminationDate = $this->getUserTerminationDate($userId);
        $period = $this->effectivePeriodBounds($monthFirst, $payDay, $hireDate, $terminationDate);
        $periodStart = $period['start'];
        $periodEnd = $period['end'];
        if ($hireDate !== null && $hireDate > $periodEnd) {
            return $result;
        }
        if (!empty($period['is_terminated_before_period'])) {
            return $result;
        }
        $missingScanEnd = $this->attendanceClosedScanEnd($periodStart, $periodEnd);
        if ($terminationDate !== null && $terminationDate !== '' && ($missingScanEnd === '' || $terminationDate < $missingScanEnd)) {
            $missingScanEnd = $terminationDate;
        }
        $workdayCtx = $this->buildWorkdayContext($userId, $periodStart, $periodEnd);

        try {
            $stmt = $this->pdo->prepare("
                SELECT attendance_date, status, late_minutes, late_excused, late_notified_at, remarks,
                       check_in_time, planned_start_time
                FROM hr_attendances WHERE user_id = ? AND attendance_date BETWEEN ? AND ?
                ORDER BY attendance_date
            ");
            $stmt->execute([$userId, $periodStart, $periodEnd]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            try {
                $stmt = $this->pdo->prepare("
                    SELECT attendance_date, status, late_minutes, late_excused, late_notified_at, remarks,
                           NULL AS check_in_time, NULL AS planned_start_time
                    FROM hr_attendances WHERE user_id = ? AND attendance_date BETWEEN ? AND ?
                    ORDER BY attendance_date
                ");
                $stmt->execute([$userId, $periodStart, $periodEnd]);
                $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e2) {
                return $result;
            }
        }

        $absentDates = [];
        $loggedDates = [];
        foreach ($logs as $log) {
            $date = $log['attendance_date'];
            $loggedDates[$date] = true;

            if (!$this->isPayrollWorkday($workdayCtx, $date)) {
                continue;
            }

            if (!$this->isAttendanceDeductibleDate($date, $missingScanEnd)) {
                continue;
            }

            $status = $log['status'] ?? 'PRESENT';
            $lateMin = (int)($log['late_minutes'] ?? 0);
            $excused = (int)($log['late_excused'] ?? 0);
            $plannedStart = $log['planned_start_time'] ?? null;
            $checkIn = $log['check_in_time'] ?? null;

            if ($status === 'ABSENT') {
                try {
                    $chk = $this->pdo->prepare("
                        SELECT 1 FROM hr_leave_requests
                        WHERE user_id = ? AND status = 'APPROVED'
                          AND ? BETWEEN start_date AND end_date
                        LIMIT 1
                    ");
                    $chk->execute([$userId, $date]);
                    if ($chk->fetchColumn()) {
                        continue;
                    }
                } catch (Throwable $e) {
                    /* continue with absent */
                }
                $result['absent_days'] += 1;
                $absentDates[$date] = true;
                $result['breakdown'][] = ['date' => $date, 'kind' => 'absent', 'amount' => $rateAbsent, 'note' => 'ขาดงาน'];
                continue;
            }

            $plannedUsed = false;
            if ($usePlannedStart && $excused !== 1 && !empty($plannedStart) && !empty($checkIn)) {
                $ciTs = strtotime($checkIn);
                $psTs = strtotime($date . ' ' . $plannedStart);
                if ($ciTs && $psTs) {
                    $deltaMin = ($ciTs - $psTs) / 60;
                    if ($deltaMin <= $plannedGraceMin) {
                        $result['breakdown'][] = [
                            'date' => $date,
                            'kind' => 'late_planned_ok',
                            'amount' => 0,
                            'note' => sprintf('แจ้งเข้างานสาย %s — มาตามนัด ไม่หัก', substr((string) $plannedStart, 0, 5)),
                        ];
                        continue;
                    }
                    $lateMin = (int) round($deltaMin);
                    $plannedUsed = true;
                }
            }

            if (in_array($status, ['PRESENT','LATE','WFH','HALF_DAY'], true) && $lateMin > 0 && $excused !== 1) {
                $plannedLabel = $plannedUsed ? sprintf(' (แจ้งไว้ %s)', substr((string) $plannedStart, 0, 5)) : '';
                if ($this->applyLateMinutesDeduction($result, $date, $lateMin, $rateAbsent, $rateLate30, $rateLate60, $lateOver60AsAbsent, $plannedLabel)) {
                    $absentDates[$date] = true;
                }
            }
        }

        $missingDates = ($missingScanEnd !== '' && $missingScanEnd >= $periodStart)
            ? $this->findMissingAbsentDates($userId, $periodStart, $missingScanEnd, $loggedDates)
            : [];
        foreach ($missingDates as $date) {
            if (!empty($absentDates[$date])) continue;
            $result['absent_days'] += 1;
            $absentDates[$date] = true;
            $result['breakdown'][] = [
                'date' => $date,
                'kind' => 'missing_attendance_absent',
                'amount' => $rateAbsent,
                'note' => 'ไม่พบการลงเวลาในวันทำงาน',
            ];
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT lr.id, lt.code AS leave_code, lt.requires_document, lt.name AS leave_type_name,
                       lr.start_date, lr.end_date, lr.total_days AS leave_days,
                       CASE WHEN lr.document_path IS NOT NULL THEN 1 ELSE 0 END AS has_medical_cert,
                       lr.status, lr.created_at,
                       DATEDIFF(lr.start_date, DATE(lr.created_at)) AS notice_days
                FROM hr_leave_requests lr
                JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
                WHERE lr.user_id = ? AND lr.status = 'APPROVED'
                  AND lr.start_date <= ? AND lr.end_date >= ?
            ");
            $stmt->execute([$userId, $periodEnd, $periodStart]);
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
            if ($lv['leave_code'] !== 'SICK' && (int)$lv['notice_days'] < $leaveAdvanceDays) {
                $result['warnings'][] = [
                    'kind' => 'insufficient_notice', 'leave_id' => $lv['id'],
                    'note' => "แจ้ง{$lv['leave_type_name']}ล่วงหน้าเพียง {$lv['notice_days']} วัน (ต้องการ {$leaveAdvanceDays} วัน)",
                ];
            }
        }

        $result['absence_deduction'] = $result['absent_days'] * $rateAbsent;
        $result['total_deduction'] = $result['absence_deduction'] + $result['lateness_deduction'];
        return $result;
    }

    /**
     * @return array{day_off:int, skip_missing:bool, holidays:array<string,true>, leave_dates:array<string,true>, dayoff_requests:list<array>}
     */
    public function buildWorkdayContext(int $userId, string $periodStart, string $periodEnd): array
    {
        return \TpCommon\Hr\WorkdayCalculator::buildContext($this->pdo, $userId, $periodStart, $periodEnd);
    }

    public function isPayrollWorkday(array $ctx, string $date): bool
    {
        return \TpCommon\Hr\WorkdayCalculator::isExpectedWorkday($ctx, $date);
    }

    /**
     * Calculate missing workdays directly from calendars/schedules, so payroll
     * does not silently under-deduct when the absence backfill cron has not run.
     */
    private function findMissingAbsentDates(int $userId, string $periodStart, string $scanEnd, array $loggedDates): array
    {
        $ctx = $this->buildWorkdayContext($userId, $periodStart, $scanEnd);
        if ($ctx['skip_missing']) {
            return [];
        }

        $missingAbsentDates = [];
        for ($ts = strtotime($periodStart); $ts !== false && $ts <= strtotime($scanEnd); $ts += 86400) {
            $date = date('Y-m-d', $ts);
            if (!empty($loggedDates[$date]) || !$this->isPayrollWorkday($ctx, $date)) {
                continue;
            }
            $missingAbsentDates[] = $date;
        }

        return $missingAbsentDates;
    }

    // ──────────────── Salary Setup ────────────────

    public function getSalarySetup(int $userId, string $monthFirst): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM employee_salary_setup
            WHERE user_id = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
            ORDER BY effective_from DESC, id DESC LIMIT 1
        ");
        $stmt->execute([$userId, $monthFirst, $monthFirst]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Extra monthly WHT (ล.ย.01) — zero when tax opt-out or company tax disabled.
     *
     * @param array<string,mixed>|null $setup
     */
    public function resolveExtraTaxRequest(?array $setup, bool $taxOptOut = false, ?string $monthFirst = null): float
    {
        if ($taxOptOut || !$this->isTaxEnabledForMonth($monthFirst)) {
            return 0.0;
        }
        if (!$setup || !isset($setup['additional_tax_withholding'])) {
            return 0.0;
        }
        return max(0, (float)$setup['additional_tax_withholding']);
    }

    public function saveSalarySetup(int $userId, array $data): array
    {
        if (!empty($data['social_security_manual'])) {
            $this->ensureSocialSecurityManualColumn();
        }
        $bundle = $this->prepareSalarySetupBundle($userId, $data);

        $this->pdo->beginTransaction();
        try {
            $setupResult = $this->persistSalarySetupRow($userId, $bundle['setup']);
            $this->updateUserBenefitProfile($userId, $bundle['profile']);
            $recalcCount = !empty($bundle['setup']['month_scoped'])
                ? $this->recalculateRunMonthForUser($userId, $bundle['setup']['effective_from'])
                : $this->recalculateOpenRunsForUser($userId, $bundle['setup']['effective_from']);
            $this->pdo->commit();

            return array_merge($setupResult, [
                'recalculated_runs' => $recalcCount,
                'month_scoped' => !empty($bundle['setup']['month_scoped']),
                'scope_month' => !empty($bundle['setup']['month_scoped']) ? substr($bundle['setup']['effective_from'], 0, 7) : null,
                'warnings' => $bundle['warnings'],
            ]);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{setup: array<string,mixed>, profile: array<string,mixed>, warnings: list<string>}
     */
    public function prepareSalarySetupBundle(int $userId, array $data): array
    {
        $scopeBounds = $this->monthScopeBounds($data['scope_month'] ?? null);
        $monthScoped = $scopeBounds !== null;

        if ($monthScoped) {
            $effectiveFrom = $scopeBounds['from'];
            $effectiveTo = $scopeBounds['to'];
        } else {
            $effectiveFrom = $this->normalizeEffectiveMonth($data['effective_from'] ?? '');
            $effectiveTo = null;
        }
        if ($effectiveFrom === '') {
            throw new \InvalidArgumentException('เดือนที่มีผลไม่ถูกต้อง');
        }

        $baseSalary = (float)($data['base_salary'] ?? 0);
        if ($baseSalary < 0) {
            throw new \InvalidArgumentException('ฐานเงินเดือนต้องไม่ต่ำกว่า 0');
        }

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
            throw new \InvalidArgumentException(
                'จำนวนภาษีหักเพิ่มรายเดือนต้องไม่เกินฐานเงินเดือน (' . number_format($baseSalary, 2) . ' บาท)'
            );
        }

        $allowanceJson = $data['allowance_json'] ?? null;
        $incomeOtherJson = $data['income_other_json'] ?? null;
        $deductionOtherJson = $data['deduction_other_json'] ?? null;

        $ssResolved = $this->resolveSocialSecurityForSave($userId, [
            'base_salary' => $baseSalary,
            'bonus_fixed' => $bonusFixed,
            'allowance_json' => $allowanceJson,
            'income_other_json' => $incomeOtherJson,
            'ss_opt_out' => $ssOptOut,
            'social_security_manual' => !empty($data['social_security_manual']),
            'social_security' => $data['social_security'] ?? 0,
        ], $effectiveFrom);
        $socialSecurity = $ssResolved['social_security'];
        $socialSecurityManual = $ssResolved['social_security_manual'];

        $profile = [
            'has_other_employer_income' => !empty($data['has_other_employer_income']) ? 1 : 0,
            'social_security_start_date' => $this->normalizeOptionalDate($data['social_security_start_date'] ?? null),
            'tax_withholding_start_date' => $this->normalizeOptionalDate($data['tax_withholding_start_date'] ?? null),
            'health_insurance_start_date' => $this->normalizeOptionalDate($data['health_insurance_start_date'] ?? null),
            'group_insurance_start_date' => $this->normalizeOptionalDate($data['group_insurance_start_date'] ?? null),
        ];

        $warnings = [];
        if (
            $this->isHealthInsuranceEnabled()
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

        return ['setup' => $setup, 'profile' => $profile, 'warnings' => $warnings];
    }

    private function normalizeEffectiveMonth($raw): string
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return $raw . '-01';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return substr($raw, 0, 7) . '-01';
        }
        return '';
    }

    private function normalizeOptionalDate($raw): ?string
    {
        $raw = trim((string)($raw ?? ''));
        return $raw !== '' ? $raw : null;
    }

    /** @return array{from: string, to: string}|null */
    private function monthScopeBounds($raw): ?array
    {
        $raw = trim((string)($raw ?? ''));
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            $from = $raw . '-01';
            return ['from' => $from, 'to' => date('Y-m-t', strtotime($from))];
        }
        if (preg_match('/^(\d{4}-\d{2})-\d{2}$/', $raw, $m)) {
            $from = $m[1] . '-01';
            return ['from' => $from, 'to' => date('Y-m-t', strtotime($from))];
        }
        return null;
    }

    private function closePriorSalarySetupVersions(int $userId, string $effectiveFrom): void
    {
        $prevEnd = date('Y-m-d', strtotime($effectiveFrom . ' -1 day'));
        $this->pdo->prepare(
            'UPDATE employee_salary_setup SET effective_to = ?
             WHERE user_id = ? AND effective_from < ? AND (effective_to IS NULL OR effective_to >= ?)'
        )->execute([$prevEnd, $userId, $effectiveFrom, $effectiveFrom]);
    }

    private function ensureSocialSecurityManualColumn(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $chk = $this->pdo->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_salary_setup' AND COLUMN_NAME = 'social_security_manual'"
        );
        $chk->execute();
        if ($chk->fetchColumn()) {
            return;
        }

        try {
            $this->pdo->exec(
                "ALTER TABLE employee_salary_setup
                 ADD COLUMN social_security_manual TINYINT(1) NOT NULL DEFAULT 0
                 COMMENT '1=ใช้ยอด social_security ที่กำหนดเอง, 0=คำนวณอัตโนมัติ'"
            );
        } catch (\Throwable $e) {
            error_log('[PayrollService] social_security_manual column ensure failed: ' . $e->getMessage());
            throw new \InvalidArgumentException(
                'ไม่สามารถอัปเดตฐานข้อมูลสำหรับกำหนดยอดประกันสังคมเองได้ — กรุณาแจ้งผู้ดูแลระบบ'
            );
        }
    }

    /** @return list<string> */
    private function salarySetupPersistCols(): array
    {
        static $cols = null;
        if ($cols !== null) {
            return $cols;
        }
        $all = [
            'base_salary', 'bonus_fixed', 'provident_fund', 'social_security', 'social_security_manual',
            'group_insurance_total_monthly', 'group_insurance_employer_pct',
            'health_insurance_total_monthly', 'health_insurance_employer_pct',
            'ss_opt_out', 'tax_opt_out', 'hi_opt_out', 'gi_opt_out',
            'additional_tax_withholding',
            'allowance_json', 'income_other_json', 'deduction_other_json', 'notes', 'created_by',
        ];
        $chk = $this->pdo->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_salary_setup' AND COLUMN_NAME = 'social_security_manual'"
        );
        $chk->execute();
        if (!$chk->fetchColumn()) {
            $all = array_values(array_filter($all, static fn(string $c): bool => $c !== 'social_security_manual'));
        }
        $cols = $all;
        return $cols;
    }

    /**
     * @param array<string,mixed> $setup
     */
    private function persistSalarySetupRow(int $userId, array $setup): array
    {
        $effectiveFrom = $setup['effective_from'];
        $effectiveTo = $setup['effective_to'] ?? null;
        $monthScoped = !empty($setup['month_scoped']);
        $cols = $this->salarySetupPersistCols();

        if ($monthScoped && $effectiveTo) {
            return $this->persistMonthScopedSalarySetup($userId, $setup, $cols);
        }

        $chk = $this->pdo->prepare('SELECT id FROM employee_salary_setup WHERE user_id = ? AND effective_from = ? ORDER BY id DESC LIMIT 1');
        $chk->execute([$userId, $effectiveFrom]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $sets = 'effective_to = ?, ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ', updated_at = NOW()';
            $this->pdo->prepare("UPDATE employee_salary_setup SET $sets WHERE id = ?")
                ->execute([$effectiveTo, ...array_map(fn($c) => $setup[$c] ?? null, $cols), $existing['id']]);
            return ['action' => 'updated', 'id' => (int)$existing['id']];
        }

        $this->closePriorSalarySetupVersions($userId, $effectiveFrom);

        $ph = implode(',', array_fill(0, count($cols) + 3, '?'));
        $this->pdo->prepare('INSERT INTO employee_salary_setup (user_id, effective_from, effective_to, ' . implode(',', $cols) . ") VALUES ($ph)")
            ->execute([$userId, $effectiveFrom, $effectiveTo, ...array_map(fn($c) => $setup[$c] ?? null, $cols)]);
        return ['action' => 'created', 'id' => (int)$this->pdo->lastInsertId()];
    }

    /**
     * @param array<string,mixed> $setup
     * @param list<string> $cols
     * @return array{action: string, id: int}
     */
    private function persistMonthScopedSalarySetup(int $userId, array $setup, array $cols): array
    {
        $effectiveFrom = $setup['effective_from'];
        $effectiveTo = $setup['effective_to'];

        $exactStmt = $this->pdo->prepare(
            'SELECT id FROM employee_salary_setup
             WHERE user_id = ? AND effective_from = ? AND effective_to = ?
             ORDER BY id DESC LIMIT 1'
        );
        $exactStmt->execute([$userId, $effectiveFrom, $effectiveTo]);
        $exact = $exactStmt->fetch(PDO::FETCH_ASSOC);
        if ($exact) {
            $sets = 'effective_to = ?, ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ', updated_at = NOW()';
            $this->pdo->prepare("UPDATE employee_salary_setup SET $sets WHERE id = ?")
                ->execute([$effectiveTo, ...array_map(fn($c) => $setup[$c] ?? null, $cols), $exact['id']]);
            return ['action' => 'updated', 'id' => (int)$exact['id']];
        }

        $atFromStmt = $this->pdo->prepare(
            'SELECT * FROM employee_salary_setup WHERE user_id = ? AND effective_from = ? ORDER BY id DESC LIMIT 1'
        );
        $atFromStmt->execute([$userId, $effectiveFrom]);
        $atFrom = $atFromStmt->fetch(PDO::FETCH_ASSOC);

        if ($atFrom) {
            $action = 'overlay_update';
            if ($this->salarySetupRowIsOpenEnded($atFrom['effective_to'] ?? null)) {
                $this->clonePermanentSalaryToNextMonthIfAbsent($userId, $atFrom, $effectiveTo, $cols);
                $action = 'split_overlay';
            }
            $sets = 'effective_to = ?, ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ', updated_at = NOW()';
            $this->pdo->prepare("UPDATE employee_salary_setup SET $sets WHERE id = ?")
                ->execute([$effectiveTo, ...array_map(fn($c) => $setup[$c] ?? null, $cols), $atFrom['id']]);
            return ['action' => $action, 'id' => (int)$atFrom['id']];
        }

        $ph = implode(',', array_fill(0, count($cols) + 3, '?'));
        $this->pdo->prepare('INSERT INTO employee_salary_setup (user_id, effective_from, effective_to, ' . implode(',', $cols) . ") VALUES ($ph)")
            ->execute([$userId, $effectiveFrom, $effectiveTo, ...array_map(fn($c) => $setup[$c] ?? null, $cols)]);
        return ['action' => 'created', 'id' => (int)$this->pdo->lastInsertId()];
    }

    private function salarySetupRowIsOpenEnded(?string $effectiveTo): bool
    {
        return $effectiveTo === null || trim($effectiveTo) === '';
    }

    /**
     * @param array<string,mixed> $permanentRow
     * @param list<string> $cols
     */
    private function clonePermanentSalaryToNextMonthIfAbsent(int $userId, array $permanentRow, string $overlayEnd, array $cols): void
    {
        $nextMonthStart = date('Y-m-d', strtotime($overlayEnd . ' +1 day'));
        $futureStmt = $this->pdo->prepare(
            'SELECT id FROM employee_salary_setup WHERE user_id = ? AND effective_from = ? ORDER BY id DESC LIMIT 1'
        );
        $futureStmt->execute([$userId, $nextMonthStart]);
        if ($futureStmt->fetch(PDO::FETCH_ASSOC)) {
            return;
        }
        $ph = implode(',', array_fill(0, count($cols) + 3, '?'));
        $this->pdo->prepare('INSERT INTO employee_salary_setup (user_id, effective_from, effective_to, ' . implode(',', $cols) . ") VALUES ($ph)")
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
    private function updateUserBenefitProfile(int $userId, array $profile): void
    {
        try {
            $this->pdo->prepare('UPDATE users SET has_other_employer_income = ?, social_security_start_date = ?, tax_withholding_start_date = ?, health_insurance_start_date = ?, group_insurance_start_date = ? WHERE id = ?')
                ->execute([
                    $profile['has_other_employer_income'] ?? 0,
                    $profile['social_security_start_date'],
                    $profile['tax_withholding_start_date'],
                    $profile['health_insurance_start_date'],
                    $profile['group_insurance_start_date'],
                    $userId,
                ]);
        } catch (\Throwable $e) {
            try {
                $this->pdo->prepare('UPDATE users SET has_other_employer_income = ? WHERE id = ?')
                    ->execute([$profile['has_other_employer_income'] ?? 0, $userId]);
            } catch (\Throwable $e2) {
                /* legacy schema */
            }
        }
    }

    public function recalculateOpenRunsForUser(int $userId, string $effectiveFrom): int
    {
        $effMonth = substr($effectiveFrom, 0, 7) . '-01';
        $stmt = $this->pdo->prepare("SELECT id, payroll_month FROM payroll_runs WHERE status IN ('draft','calculated') AND payroll_month >= ?");
        $stmt->execute([$effMonth]);
        $count = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $runId = (int)$row['id'];
            if ($this->recalculateSlip($runId, $userId, $row['payroll_month'])) {
                $this->updateRunTotals($runId);
                $count++;
            }
        }
        return $count;
    }

    public function recalculateRunMonthForUser(int $userId, string $monthFirst): int
    {
        $monthFirst = preg_match('/^\d{4}-\d{2}-\d{2}$/', $monthFirst) ? $monthFirst : (substr($monthFirst, 0, 7) . '-01');
        $stmt = $this->pdo->prepare("SELECT id, payroll_month FROM payroll_runs WHERE status IN ('draft','calculated') AND payroll_month = ? LIMIT 1");
        $stmt->execute([$monthFirst]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 0;
        }
        $runId = (int)$row['id'];
        if ($this->recalculateSlip($runId, $userId, $row['payroll_month'])) {
            $this->updateRunTotals($runId);
            return 1;
        }
        return 0;
    }

    // ──────────────── Slip Calculation ────────────────

    /**
     * Calculate a single employee's payroll for a given month.
     * Returns the full slip data array without persisting.
     */
    public function calculateSlip(int $userId, string $monthFirst, ?int $payDay = null, ?array $setupOverride = null): array
    {
        if ($payDay === null) {
            $payDay = $this->getDefaultPayDay();
        }
        $setup = $this->getSalarySetup($userId, $monthFirst);
        $explicitBase = is_array($setupOverride) && array_key_exists('base_salary', $setupOverride);
        if ($setupOverride) {
            $setup = is_array($setup) ? array_merge($setup, $setupOverride) : $setupOverride;
        }
        $gross = $this->resolveBaseSalaryForMonth($userId, $monthFirst, is_array($setup) ? $setup : null, $explicitBase);
        if (is_array($setup)) {
            $setup['base_salary'] = $gross;
        } elseif ($gross > 0) {
            $setup = ['base_salary' => $gross];
        }
        $bonus = $setup ? (float)($setup['bonus_fixed'] ?? 0) : 0;
        $allowances = 0;
        $incomeOther = 0;
        $incomeOtherJson = null;
        $dedOther = 0;
        $dedOtherJson = null;

        if ($setup && !empty($setup['allowance_json'])) {
            $arr = json_decode($setup['allowance_json'], true);
            if (is_array($arr)) foreach ($arr as $a) $allowances += (float)($a['amount'] ?? 0);
        }
        if ($setup && !empty($setup['income_other_json'])) {
            $arr = json_decode($setup['income_other_json'], true);
            if (is_array($arr)) {
                foreach ($arr as $a) $incomeOther += (float)($a['amount'] ?? 0);
                $incomeOtherJson = $setup['income_other_json'];
            }
        }
        if ($setup && !empty($setup['deduction_other_json'])) {
            $arr = json_decode($setup['deduction_other_json'], true);
            if (is_array($arr)) {
                foreach ($arr as $a) $dedOther += (float)($a['amount'] ?? 0);
                $dedOtherJson = $setup['deduction_other_json'];
            }
        }
        $financeItems = $this->employeeFinanceDeductions($userId, substr($monthFirst, 0, 7));
        if ($financeItems) {
            $existing = $dedOtherJson ? json_decode($dedOtherJson, true) : [];
            $existing = is_array($existing) ? $existing : [];
            foreach ($financeItems as $item) {
                $dedOther += (float)$item['amount'];
                $existing[] = $item;
            }
            $dedOtherJson = json_encode($existing, JSON_UNESCAPED_UNICODE);
        }

        $hireFactor = $this->applyHireDateIncome(
            $userId,
            $monthFirst,
            $payDay,
            $gross,
            $bonus,
            $allowances,
            $incomeOther,
            $incomeOtherJson
        );

        $totalIncome = $gross + $bonus + $allowances + $incomeOther;
        $annualEst = $totalIncome * 12;
        $ssOptOut = $setup && !empty($setup['ss_opt_out']);
        $taxOptOut = $setup && !empty($setup['tax_opt_out']);
        $hiOptOut = $setup && !empty($setup['hi_opt_out']);
        $giOptOut = $setup && !empty($setup['gi_opt_out']);
        $ssWageBase = round($this->socialSecurityWageBase($setup) * max(0, $hireFactor), 2);
        $ssAuto = $this->calcSocialSecurityForUser($userId, $ssWageBase, $ssOptOut, $monthFirst);
        $ssStart = $this->getUserSocialSecurityStartDate($userId);
        $ssApplies = $this->ssAppliesForMonth($ssStart, $monthFirst)
            && $this->isSsEnabledForMonth($monthFirst)
            && !$ssOptOut;
        $ss = $ssApplies ? $this->resolveSocialSecurityAmount($setup, $ssAuto) : 0.0;
        $benefitFactor = $this->benefitProrationFactor($hireFactor);
        $pf = $setup ? round((float)($setup['provident_fund'] ?? 0) * $benefitFactor, 2) : 0;
        $taxBase = $this->calcTaxForUser($userId, $annualEst, $ss * 12, $pf * 12, $monthFirst, $taxOptOut);
        $extraTaxReq = $this->resolveExtraTaxRequest($setup, $taxOptOut, $monthFirst);

        $giTotal = (float)(is_array($setup) ? ($setup['group_insurance_total_monthly'] ?? 0) : 0);
        $giEmpPct = (float)(is_array($setup) ? ($setup['group_insurance_employer_pct'] ?? 50) : 50);
        $groupInsurance = $this->calcGroupInsuranceForUser($userId, round($giTotal * $benefitFactor, 2), (float)$giEmpPct, $giOptOut, $monthFirst);

        $hiTotal = (float)(is_array($setup) ? ($setup['health_insurance_total_monthly'] ?? 0) : 0);
        $hiEmpPct = (float)(is_array($setup) ? ($setup['health_insurance_employer_pct'] ?? 50) : 50);
        $healthInsurance = $this->calcHealthInsuranceForUser($userId, round($hiTotal * $benefitFactor, 2), (float)$hiEmpPct, $hiOptOut, $monthFirst);

        $att = $this->computeAttendanceDeductions($userId, $monthFirst, $payDay);
        $absenceDed = (float)$att['absence_deduction'];
        $latenessDed = (float)$att['lateness_deduction'];
        $attDetailJson = (!empty($att['breakdown']) || !empty($att['warnings']))
            ? json_encode(['breakdown' => $att['breakdown'], 'warnings' => $att['warnings']], JSON_UNESCAPED_UNICODE)
            : null;

        $maxExtra = max(0, $totalIncome - ($taxBase + $pf + $ss + $groupInsurance + $healthInsurance + $dedOther + $absenceDed + $latenessDed));
        $extraTax = min($extraTaxReq, $maxExtra);
        $tax = $taxBase + $extraTax;
        $totalDed = $tax + $pf + $ss + $groupInsurance + $healthInsurance + $dedOther + $absenceDed + $latenessDed;
        $net = max(0, $totalIncome - $totalDed);

        return [
            'user_id' => $userId,
            'gross_salary' => $gross,
            'bonus' => $bonus,
            'allowances' => $allowances,
            'income_other_json' => $incomeOtherJson,
            'total_income' => $totalIncome,
            'tax_base' => $taxBase,
            'extra_tax_req' => $extraTaxReq,
            'extra_tax' => $extraTax,
            'tax_withheld' => $tax,
            'provident_fund' => $pf,
            'social_security' => $ss,
            'group_insurance' => $groupInsurance,
            'health_insurance' => $healthInsurance,
            'deduction_other_json' => $dedOtherJson,
            'absent_days' => $att['absent_days'],
            'late_count_30' => $att['late_count_30'],
            'late_count_60' => $att['late_count_60'],
            'absence_deduction' => $absenceDed,
            'lateness_deduction' => $latenessDed,
            'attendance_detail_json' => $attDetailJson,
            'total_deductions' => $totalDed,
            'net_salary' => $net,
        ];
    }

    // ──────────────── Run Management ────────────────

    /**
     * Create or recalculate a payroll run for a given month.
     */
    public function createRun(string $month, int $createdBy, ?int $payDay = null): array
    {
        $monthFirst = $month . '-01';
        $stmt = $this->pdo->prepare("SELECT id, status FROM payroll_runs WHERE payroll_month = ?");
        $stmt->execute([$monthFirst]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing && in_array($existing['status'], ['approved', 'paid'])) {
            throw new \RuntimeException("ไม่สามารถคำนวณใหม่ — รอบนี้" . ($existing['status'] === 'approved' ? 'อนุมัติแล้ว' : 'จ่ายเงินแล้ว'));
        }

        $this->pdo->beginTransaction();
        try {
            $payDay = \TpCommon\Hr\PayrollCalendar::paymentDay($month);
            $runId = $existing ? (int)$existing['id'] : 0;
            if ($runId > 0) {
                $this->pdo->prepare("UPDATE hr_employee_finance_payroll_links SET link_status='reversed',reversed_at=NOW(),settled_at=NULL WHERE payroll_run_id=? AND link_status='included'")->execute([$runId]);
                $this->pdo->prepare("DELETE FROM payroll_slips WHERE payroll_run_id = ?")->execute([$runId]);
                $this->pdo->prepare("UPDATE payroll_runs SET pay_day = ? WHERE id = ?")->execute([$payDay, $runId]);
            } else {
                $this->pdo->prepare("INSERT INTO payroll_runs (payroll_month, pay_day, status, created_by) VALUES (?, ?, 'draft', ?)")
                    ->execute([$monthFirst, $payDay, $createdBy]);
                $runId = (int)$this->pdo->lastInsertId();
            }

            $users = $this->pdo->query("SELECT id, hire_date FROM users u WHERE u.is_active = 1 AND " . tp_hr_payroll_employee_filter_sql('u'))
                ->fetchAll(PDO::FETCH_ASSOC);

            $totalGross = $totalTax = $totalNet = 0;
            $includedCount = 0;
            $ins = $this->pdo->prepare("INSERT INTO payroll_slips (payroll_run_id, user_id, gross_salary, bonus, allowances, income_other_json, total_income, tax_withheld, provident_fund, social_security, group_insurance, health_insurance, deduction_other_json, absent_days, late_count_30, late_count_60, absence_deduction, lateness_deduction, attendance_detail_json, total_deductions, net_salary) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

            foreach ($users as $userRow) {
                $uid = (int)$userRow['id'];
                if (!$this->shouldIncludeEmployeeInRun($uid, $userRow['hire_date'] ?? null, $monthFirst, $payDay)) {
                    continue;
                }
                $includedCount++;
                $slip = $this->calculateSlip($uid, $monthFirst, $payDay);
                $ins->execute([
                    $runId, $uid,
                    $slip['gross_salary'], $slip['bonus'], $slip['allowances'],
                    $slip['income_other_json'], $slip['total_income'],
                    $slip['tax_withheld'], $slip['provident_fund'], $slip['social_security'],
                    $slip['group_insurance'], $slip['health_insurance'], $slip['deduction_other_json'],
                    $slip['absent_days'], $slip['late_count_30'], $slip['late_count_60'],
                    $slip['absence_deduction'], $slip['lateness_deduction'],
                    $slip['attendance_detail_json'], $slip['total_deductions'], $slip['net_salary'],
                ]);
                $this->syncEmployeeFinanceLinksForSlip($runId, (int)$this->pdo->lastInsertId(), $uid, $slip['deduction_other_json']);
                $totalGross += $slip['total_income'];
                $totalTax += $slip['tax_withheld'];
                $totalNet += $slip['net_salary'];
            }

            $this->pdo->prepare("UPDATE payroll_runs SET employee_count = ?, total_gross = ?, total_tax = ?, total_net = ?, status = 'calculated' WHERE id = ?")
                ->execute([$includedCount, $totalGross, $totalTax, $totalNet, $runId]);

            $this->pdo->commit();
            return ['run_id' => $runId, 'employee_count' => $includedCount, 'total_gross' => $totalGross, 'total_net' => $totalNet, 'is_recalculation' => (bool)$existing];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function approveRun(int $runId, int $approvedBy): void
    {
        $this->syncEmployeeFinanceLinksForRun($runId);
        $this->assertEmployeeFinanceReconciled($runId);
        $stmt = $this->pdo->prepare(
            "UPDATE payroll_runs SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'calculated'"
        );
        $stmt->execute([$approvedBy, $runId]);
        if (!$stmt->rowCount()) {
            throw new \RuntimeException('อนุมัติได้เฉพาะรอบที่คำนวณแล้วเท่านั้น');
        }
    }

    public function cancelApproval(int $runId): void
    {
        $stmt = $this->pdo->prepare("SELECT status FROM payroll_runs WHERE id = ? LIMIT 1");
        $stmt->execute([$runId]);
        $status = $stmt->fetchColumn();
        if (!$status) {
            throw new \RuntimeException('ไม่พบรอบเงินเดือน');
        }
        if ($status === 'paid') {
            throw new \RuntimeException('ไม่สามารถยกเลิกการอนุมัติได้ — รอบนี้ถูกบันทึกว่าจ่ายแล้ว');
        }
        if ($status !== 'approved') {
            throw new \RuntimeException('ยกเลิกการอนุมัติได้เฉพาะรอบที่อนุมัติแล้วเท่านั้น');
        }
        $upd = $this->pdo->prepare(
            "UPDATE payroll_runs SET status = 'calculated', approved_by = NULL, approved_at = NULL WHERE id = ? AND status = 'approved'"
        );
        $upd->execute([$runId]);
        if (!$upd->rowCount()) {
            throw new \RuntimeException('ยกเลิกการอนุมัติไม่สำเร็จ');
        }
    }

    public function markPaid(int $runId): void
    {
        $this->assertEmployeeFinanceReconciled($runId);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("UPDATE payroll_runs SET status = 'paid' WHERE id = ? AND status = 'approved'");
            $stmt->execute([$runId]);
            if (!$stmt->rowCount()) throw new \RuntimeException('Run not found or not in approved status');
            $this->settleEmployeeFinanceForRun($runId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        // หมายเหตุ: รายจ่าย ERP (erp_company_transactions) สร้างจาก TP-CRM เท่านั้น
    }

    public function cancelPaid(int $runId): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("UPDATE payroll_runs SET status = 'approved' WHERE id = ? AND status = 'paid'");
            $stmt->execute([$runId]);
            if (!$stmt->rowCount()) throw new \RuntimeException('Run not found or not in paid status');
            $this->reverseEmployeeFinanceForRun($runId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<int,array{name:string,amount:float,source_type:string,source_id:int}> */
    private function employeeFinanceDeductions(int $userId, string $month): array
    {
        try {
            $items = [];
            $advance = $this->pdo->prepare("SELECT id, amount FROM hr_salary_advances WHERE user_id=? AND deduction_month=? AND repayment_method='payroll' AND status='pending_deduction'");
            $advance->execute([$userId, $month]);
            foreach ($advance->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = ['name'=>'หักเงินเดือนที่เบิกล่วงหน้า','amount'=>(float)$row['amount'],'source_type'=>'salary_advance','source_id'=>(int)$row['id']];
            }
            $loan = $this->pdo->prepare("SELECT r.id,r.installment_no,r.due_amount,l.term_months FROM hr_loan_repayments r JOIN hr_employee_loans l ON l.id=r.loan_id WHERE l.user_id=? AND l.status='active' AND l.repayment_method='payroll' AND DATE_FORMAT(r.due_date,'%Y-%m')=? AND r.status='scheduled'");
            $loan->execute([$userId, $month]);
            foreach ($loan->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = ['name'=>'เงินกู้บริษัท งวดที่ '.(int)$row['installment_no'].' / '.(int)$row['term_months'],'amount'=>(float)$row['due_amount'],'source_type'=>'employee_loan_repayment','source_id'=>(int)$row['id'],'installment_no'=>(int)$row['installment_no'],'installment_total'=>(int)$row['term_months']];
            }
            return $items;
        } catch (Throwable $e) {
            return [];
        }
    }

    private function settleEmployeeFinanceForRun(int $runId): void
    {
        $this->assertEmployeeFinanceReconciled($runId);
        $this->pdo->prepare("UPDATE hr_salary_advances a JOIN hr_employee_finance_payroll_links x ON x.source_type='salary_advance' AND x.source_id=a.id SET a.status='deducted',a.payroll_run_id=? WHERE x.payroll_run_id=? AND x.link_status='included' AND a.status='pending_deduction'")->execute([$runId,$runId]);
        $this->pdo->prepare("UPDATE hr_loan_repayments r JOIN hr_employee_finance_payroll_links x ON x.source_type='employee_loan_repayment' AND x.source_id=r.id SET r.status='paid',r.paid_amount=x.amount,r.paid_at=NOW(),r.payroll_run_id=? WHERE x.payroll_run_id=? AND x.link_status='included' AND r.status='scheduled'")->execute([$runId,$runId]);
        $this->pdo->prepare("UPDATE hr_employee_finance_payroll_links SET link_status='settled',settled_at=NOW(),reversed_at=NULL WHERE payroll_run_id=? AND link_status='included'")->execute([$runId]);
        $this->pdo->prepare("UPDATE hr_employee_loans l SET l.status='closed',l.closed_at=CURDATE() WHERE l.status='active' AND NOT EXISTS (SELECT 1 FROM hr_loan_repayments r WHERE r.loan_id=l.id AND r.status<>'paid')")->execute();
    }

    /** @return array<int,array{source_type:string,source_id:int,amount:float}> */
    private function financeItems(?string $json): array
    {
        $decoded = json_decode((string)$json, true);
        if (!is_array($decoded)) return [];
        $items = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) continue;
            $type = (string)($item['source_type'] ?? '');
            $id = (int)($item['source_id'] ?? 0);
            if (!in_array($type, ['salary_advance','employee_loan_repayment'], true) || $id <= 0) continue;
            $items[] = ['source_type'=>$type,'source_id'=>$id,'amount'=>round((float)($item['amount'] ?? 0),2)];
        }
        return $items;
    }

    private function syncEmployeeFinanceLinksForSlip(int $runId, int $slipId, int $userId, ?string $json): void
    {
        $items = $this->financeItems($json);
        $seen = [];
        $upsert = $this->pdo->prepare("INSERT INTO hr_employee_finance_payroll_links (source_type,source_id,user_id,payroll_run_id,payroll_slip_id,amount,link_status,included_at,settled_at,reversed_at) VALUES (?,?,?,?,?,?,'included',NOW(),NULL,NULL) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),payroll_run_id=VALUES(payroll_run_id),payroll_slip_id=VALUES(payroll_slip_id),amount=VALUES(amount),link_status='included',included_at=NOW(),settled_at=NULL,reversed_at=NULL");
        foreach ($items as $item) {
            $key = $item['source_type'].':'.$item['source_id'];
            if (isset($seen[$key])) throw new RuntimeException('พบ source รายการหักซ้ำในสลิป #' . $slipId);
            $seen[$key] = true;
            $upsert->execute([$item['source_type'],$item['source_id'],$userId,$runId,$slipId,$item['amount']]);
        }
        $stmt = $this->pdo->prepare("SELECT id,source_type,source_id FROM hr_employee_finance_payroll_links WHERE payroll_slip_id=? AND link_status='included'");
        $stmt->execute([$slipId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!isset($seen[(string)$row['source_type'].':'.(int)$row['source_id']])) {
                $this->pdo->prepare("UPDATE hr_employee_finance_payroll_links SET link_status='reversed',reversed_at=NOW() WHERE id=? AND link_status='included'")->execute([(int)$row['id']]);
            }
        }
    }

    private function syncEmployeeFinanceLinksForRun(int $runId): void
    {
        $stmt = $this->pdo->prepare('SELECT id,user_id,deduction_other_json FROM payroll_slips WHERE payroll_run_id=?');
        $stmt->execute([$runId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $slip) {
            $this->syncEmployeeFinanceLinksForSlip($runId,(int)$slip['id'],(int)$slip['user_id'],$slip['deduction_other_json'] ?? null);
        }
    }

    private function assertEmployeeFinanceReconciled(int $runId): void
    {
        $run = $this->pdo->prepare("SELECT DATE_FORMAT(payroll_month,'%Y-%m') FROM payroll_runs WHERE id=?");
        $run->execute([$runId]);
        $month = (string)$run->fetchColumn();
        if ($month === '') throw new RuntimeException('ไม่พบรอบเงินเดือน');
        $stmt = $this->pdo->prepare('SELECT id,user_id,deduction_other_json FROM payroll_slips WHERE payroll_run_id=?');
        $stmt->execute([$runId]);
        $actual = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $slip) {
            foreach ($this->financeItems($slip['deduction_other_json'] ?? null) as $item) {
                $key = $item['source_type'].':'.$item['source_id'];
                if (isset($actual[$key])) throw new RuntimeException('พบ source รายการหักซ้ำ: '.$key);
                $actual[$key] = ['slip_id'=>(int)$slip['id'],'user_id'=>(int)$slip['user_id'],'amount'=>$item['amount']];
            }
        }
        $expected = [];
        $advance = $this->pdo->prepare("SELECT a.id,a.user_id,a.amount FROM hr_salary_advances a JOIN payroll_slips s ON s.user_id=a.user_id AND s.payroll_run_id=? WHERE a.deduction_month=? AND a.repayment_method='payroll' AND a.status IN ('pending_deduction','deducted')");
        $advance->execute([$runId,$month]);
        foreach ($advance->fetchAll(PDO::FETCH_ASSOC) as $row) $expected['salary_advance:'.(int)$row['id']] = ['user_id'=>(int)$row['user_id'],'amount'=>round((float)$row['amount'],2)];
        $loan = $this->pdo->prepare("SELECT r.id,l.user_id,r.due_amount FROM hr_loan_repayments r JOIN hr_employee_loans l ON l.id=r.loan_id JOIN payroll_slips s ON s.user_id=l.user_id AND s.payroll_run_id=? WHERE DATE_FORMAT(r.due_date,'%Y-%m')=? AND l.repayment_method='payroll' AND r.status IN ('scheduled','paid')");
        $loan->execute([$runId,$month]);
        foreach ($loan->fetchAll(PDO::FETCH_ASSOC) as $row) $expected['employee_loan_repayment:'.(int)$row['id']] = ['user_id'=>(int)$row['user_id'],'amount'=>round((float)$row['due_amount'],2)];
        if (count($expected) !== count($actual)) throw new RuntimeException('รายการเงินกู้/เงินล่วงหน้าที่ถึงกำหนดไม่ครบในสลิป');
        foreach ($expected as $key => $item) {
            if (!isset($actual[$key]) || $actual[$key]['user_id'] !== $item['user_id'] || abs($actual[$key]['amount']-$item['amount']) > 0.009) {
                throw new RuntimeException('รายการเงินกู้/เงินล่วงหน้าในสลิปไม่ตรงกับ HR: '.$key);
            }
        }
        $links = $this->pdo->prepare("SELECT source_type,source_id,user_id,payroll_slip_id,amount FROM hr_employee_finance_payroll_links WHERE payroll_run_id=? AND link_status IN ('included','settled')");
        $links->execute([$runId]);
        $linked = [];
        foreach ($links->fetchAll(PDO::FETCH_ASSOC) as $row) $linked[(string)$row['source_type'].':'.(int)$row['source_id']] = $row;
        if (count($actual) !== count($linked)) throw new RuntimeException('จำนวนรายการเงินกู้/เงินล่วงหน้าในสลิปไม่ตรงกับ link');
        foreach ($actual as $key => $item) {
            if (!isset($linked[$key]) || (int)$linked[$key]['payroll_slip_id'] !== $item['slip_id'] || (int)$linked[$key]['user_id'] !== $item['user_id'] || abs((float)$linked[$key]['amount']-$item['amount']) > 0.009) {
                throw new RuntimeException('รายการเงินกู้/เงินล่วงหน้าไม่ตรงกับ link: '.$key);
            }
        }
    }

    private function reverseEmployeeFinanceForRun(int $runId): void
    {
        $this->pdo->prepare("UPDATE hr_salary_advances a JOIN hr_employee_finance_payroll_links x ON x.source_type='salary_advance' AND x.source_id=a.id SET a.status='pending_deduction',a.payroll_run_id=NULL WHERE x.payroll_run_id=? AND x.link_status='settled' AND a.status='deducted'")->execute([$runId]);
        $this->pdo->prepare("UPDATE hr_loan_repayments r JOIN hr_employee_finance_payroll_links x ON x.source_type='employee_loan_repayment' AND x.source_id=r.id SET r.status='scheduled',r.paid_amount=NULL,r.paid_at=NULL,r.payroll_run_id=NULL WHERE x.payroll_run_id=? AND x.link_status='settled' AND r.status='paid'")->execute([$runId]);
        $this->pdo->prepare("UPDATE hr_employee_finance_payroll_links SET link_status='reversed',reversed_at=NOW(),settled_at=NULL WHERE payroll_run_id=? AND link_status='settled'")->execute([$runId]);
        $this->pdo->prepare("UPDATE hr_employee_loans l SET l.status='active',l.closed_at=NULL WHERE l.status='closed' AND EXISTS (SELECT 1 FROM hr_loan_repayments r WHERE r.loan_id=l.id AND r.status='scheduled')")->execute();
    }

    public function recalculateSlip(int $runId, int $userId, string $monthFirst): bool
    {
        $payDay = $this->getDefaultPayDay();
        $runStmt = $this->pdo->prepare('SELECT pay_day FROM payroll_runs WHERE id = ? LIMIT 1');
        $runStmt->execute([$runId]);
        $runRow = $runStmt->fetch(PDO::FETCH_ASSOC);
        if ($runRow && isset($runRow['pay_day'])) {
            $payDay = (int)$runRow['pay_day'];
        }

        $slip = $this->calculateSlip($userId, $monthFirst, $payDay);
        $stmt = $this->pdo->prepare("SELECT id FROM payroll_slips WHERE payroll_run_id = ? AND user_id = ?");
        $stmt->execute([$runId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->pdo->prepare("INSERT INTO payroll_slips (payroll_run_id, user_id, gross_salary, bonus, allowances, income_other_json, total_income, tax_withheld, provident_fund, social_security, group_insurance, health_insurance, deduction_other_json, absent_days, late_count_30, late_count_60, absence_deduction, lateness_deduction, attendance_detail_json, total_deductions, net_salary) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $runId, $userId,
                    $slip['gross_salary'], $slip['bonus'], $slip['allowances'],
                    $slip['income_other_json'], $slip['total_income'],
                    $slip['tax_withheld'], $slip['provident_fund'], $slip['social_security'],
                    $slip['group_insurance'], $slip['health_insurance'], $slip['deduction_other_json'],
                    $slip['absent_days'], $slip['late_count_30'], $slip['late_count_60'],
                    $slip['absence_deduction'], $slip['lateness_deduction'],
                    $slip['attendance_detail_json'], $slip['total_deductions'], $slip['net_salary'],
                ]);
            $this->syncEmployeeFinanceLinksForSlip($runId, (int)$this->pdo->lastInsertId(), $userId, $slip['deduction_other_json']);
            $this->updateRunTotals($runId);
            return true;
        }

        $this->pdo->prepare("UPDATE payroll_slips SET gross_salary=?, bonus=?, allowances=?, income_other_json=?, total_income=?, tax_withheld=?, provident_fund=?, social_security=?, group_insurance=?, health_insurance=?, deduction_other_json=?, absent_days=?, late_count_30=?, late_count_60=?, absence_deduction=?, lateness_deduction=?, attendance_detail_json=?, total_deductions=?, net_salary=? WHERE id=?")
            ->execute([
                $slip['gross_salary'], $slip['bonus'], $slip['allowances'],
                $slip['income_other_json'], $slip['total_income'],
                $slip['tax_withheld'], $slip['provident_fund'], $slip['social_security'],
                $slip['group_insurance'], $slip['health_insurance'], $slip['deduction_other_json'],
                $slip['absent_days'], $slip['late_count_30'], $slip['late_count_60'],
                $slip['absence_deduction'], $slip['lateness_deduction'],
                $slip['attendance_detail_json'], $slip['total_deductions'], $slip['net_salary'],
                $row['id'],
            ]);
        $this->syncEmployeeFinanceLinksForSlip($runId, (int)$row['id'], $userId, $slip['deduction_other_json']);
        $this->updateRunTotals($runId);
        return true;
    }

    public function updateRunTotals(int $runId): void
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as cnt, COALESCE(SUM(s.total_income),0) as gross,
                   COALESCE(SUM(s.tax_withheld),0) as tax, COALESCE(SUM(s.net_salary),0) as net
            FROM payroll_slips s JOIN users u ON s.user_id = u.id
            WHERE s.payroll_run_id = ? AND " . tp_hr_payroll_employee_filter_sql('u') . "
        ");
        $stmt->execute([$runId]);
        $agg = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->pdo->prepare("UPDATE payroll_runs SET employee_count=?, total_gross=?, total_tax=?, total_net=? WHERE id=?")
            ->execute([(int)$agg['cnt'], (float)$agg['gross'], (float)$agg['tax'], (float)$agg['net'], $runId]);
    }

    // ──────────────── Query helpers ────────────────

    public function getRun(int $runId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM payroll_runs WHERE id = ?");
        $stmt->execute([$runId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function listRuns(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare("
            SELECT r.*, u.first_name_th as approver_first, u.last_name_th as approver_last
            FROM payroll_runs r LEFT JOIN users u ON r.approved_by = u.id
            ORDER BY r.payroll_month DESC LIMIT ?
        ");
        $stmt->execute([$limit]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSlips(int $runId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*, u.employee_code, u.first_name_th, u.last_name_th
            FROM payroll_slips s JOIN users u ON s.user_id = u.id
            WHERE s.payroll_run_id = ? AND " . tp_hr_payroll_employee_filter_sql('u') . "
            ORDER BY u.employee_code
        ");
        $stmt->execute([$runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ──────────────── Internal ────────────────

    private function getSetting(string $key, string $default = ''): string
    {
        try {
            return (new SettingsService($this->pdo))->getSystem($key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
