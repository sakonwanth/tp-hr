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
        if (!$this->isSsEnabled() || $optOut || $wageBase <= 0) return 0;
        $ceiling = $this->ssWageCeiling($monthFirst);
        $base = max(1650, min($wageBase, $ceiling));
        return round($base * 0.05, 2);
    }

    /**
     * ฐานค่าจ้างสำหรับเงินสมทบประกันสังคม ม.33 — รวมรายได้ประจำทุกเดือน
     * (ฐานเงินเดือน + โบนัสประจำ + เบี้ยเลี้ยง + รายได้อื่นที่จ่ายทุกเดือน)
     * ข้ามรายการที่ตั้ง ss_exclude=1
     *
     * @param array<string,mixed>|null $setup
     */
    public function socialSecurityWageBase($setup): float
    {
        if (!is_array($setup)) {
            return 0.0;
        }
        $base = (float)($setup['base_salary'] ?? 0);
        $base += (float)($setup['bonus_fixed'] ?? 0);

        foreach (['allowance_json', 'income_other_json'] as $jsonField) {
            if (empty($setup[$jsonField])) {
                continue;
            }
            $items = json_decode((string)$setup[$jsonField], true);
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (!empty($item['ss_exclude'])) {
                    continue;
                }
                $base += (float)($item['amount'] ?? 0);
            }
        }

        return round(max(0, $base), 2);
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
        return $this->benefitAppliesForMonth($ssStartDate, $monthFirst, true);
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

    /**
     * Effective payroll period per hire date — first calendar month: hire_date→month-end; then normal pay cycle without overlap.
     *
     * @return array{start: string, end: string, pay_day: int, is_first_hire_month?: bool, is_hire_transition?: bool, standard_start?: string, standard_end?: string}
     */
    public function effectivePeriodBounds(string $payrollMonth, ?int $payDay, ?string $hireDate): array
    {
        $bounds = $this->attendancePeriodBounds($payrollMonth, $payDay);
        if ($hireDate === null || $hireDate === '') {
            return $bounds;
        }

        $hireYm = substr($hireDate, 0, 7);
        $payrollYm = substr($payrollMonth, 0, 7);
        $hireMonthEnd = date('Y-m-t', strtotime($hireYm . '-01'));

        if ($hireYm === $payrollYm) {
            $bounds['start'] = $hireDate;
            $bounds['end'] = $hireMonthEnd;
            $bounds['is_first_hire_month'] = true;
            return $bounds;
        }

        if ($payrollYm > $hireYm && $bounds['start'] <= $hireMonthEnd) {
            $dayAfterHireMonth = date('Y-m-d', strtotime($hireMonthEnd . ' +1 day'));
            if ($bounds['start'] < $dayAfterHireMonth) {
                $bounds['standard_start'] = $bounds['start'];
                $bounds['standard_end'] = $bounds['end'];
                $bounds['start'] = $dayAfterHireMonth;
                $bounds['is_hire_transition'] = true;
            }
        }

        return $bounds;
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

        if (!empty($period['is_hire_transition'])
            && !empty($period['standard_start'])
            && !empty($period['standard_end'])) {
            $stdTotal = $this->inclusiveDayCount($period['standard_start'], $period['standard_end']);
            $actual = $this->inclusiveDayCount($period['start'], $period['end']);
            if ($stdTotal > 0 && $actual < $stdTotal) {
                return round($actual / $stdTotal, 6);
            }
        }

        return $this->hireProrateFactor($hireDate, $period['start'], $period['end']);
    }

    public function shouldIncludeEmployeeInRun(?string $hireDate, string $payrollMonth, ?int $payDay): bool
    {
        if ($hireDate === null || $hireDate === '') {
            return false;
        }

        $period = $this->effectivePeriodBounds($payrollMonth, $payDay, $hireDate);
        return $this->hireIncomeProrateFactor($hireDate, $period) > 0;
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
        $period = $this->effectivePeriodBounds($monthFirst, $payDay, $hireDate);
        $factor = $this->hireIncomeProrateFactor($hireDate, $period);
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

    public function calcTaxForUser(
        int $userId,
        float $annualIncome,
        float $annualSs = 0,
        float $annualPf = 0,
        ?string $monthFirst = null,
        bool $optOut = false
    ): float {
        if (!$this->isTaxEnabled() || $optOut || $annualIncome <= 0) {
            return 0.0;
        }
        $monthFirst = $monthFirst ?: date('Y-m-01');
        if (!$this->benefitAppliesForMonth($this->getUserTaxWithholdingStartDate($userId), $monthFirst, false)) {
            return 0.0;
        }
        return $this->calcTaxMonthly($annualIncome, $annualSs, $annualPf, $monthFirst);
    }

    public function calcHealthInsuranceForUser(
        int $userId,
        float $totalMonthly,
        float $employerPct,
        bool $optOut = false,
        ?string $monthFirst = null
    ): float {
        if (!$this->isHealthInsuranceEnabled() || $optOut || $totalMonthly <= 0) {
            return 0.0;
        }
        $monthFirst = $monthFirst ?: date('Y-m-01');
        if (!$this->benefitAppliesForMonth($this->getUserHealthInsuranceStartDate($userId), $monthFirst, true)) {
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
        if (!$this->isGroupInsuranceEnabled() || $optOut || $totalMonthly <= 0) {
            return 0.0;
        }
        $monthFirst = $monthFirst ?: date('Y-m-01');
        if (!$this->benefitAppliesForMonth($this->getUserGroupInsuranceStartDate($userId), $monthFirst, false)) {
            return 0.0;
        }
        return $this->calcBenefitEmployeeShare($totalMonthly, $employerPct);
    }

    /**
     * Thai progressive tax (monthly withholding estimate).
     * Reference: Revenue Code §40(1), 42bis, 47
     */
    public function calcTaxMonthly(float $annualIncome, float $annualSs = 0, float $annualPf = 0, ?string $monthFirst = null): float
    {
        $expenseAllowance = min($annualIncome * 0.5, 100000);
        $personalAllowance = 60000;
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
        $raw = (int)$this->getSetting('payroll_default_pay_day', '25');
        return ($raw >= 1 && $raw <= 31) ? $raw : 25;
    }

    /**
     * Payroll attendance period: (pay_day+1) of previous month → pay_day of payroll month.
     * Default pay_day 25 → 26th prev month through 25th current month.
     *
     * @return array{start: string, end: string, pay_day: int}
     */
    public function attendancePeriodBounds(string $payrollMonth, ?int $payDay = null): array
    {
        if ($payDay === null) {
            $payDay = $this->getDefaultPayDay();
        }
        $payDay = max(1, min(31, $payDay));

        $ts = strtotime($payrollMonth);
        if (!$ts) {
            $today = date('Y-m-d');
            return ['start' => $today, 'end' => $today, 'pay_day' => $payDay];
        }

        $lastDay = (int)date('t', $ts);
        $endDay = min($payDay, $lastDay);
        $periodEnd = date('Y-m-', $ts) . str_pad((string)$endDay, 2, '0', STR_PAD_LEFT);

        $monthFirstTs = strtotime(date('Y-m-01', $ts));
        $prevTs = strtotime('-1 month', $monthFirstTs);
        $prevLast = (int)date('t', $prevTs);
        $prevPayDay = min($payDay, $prevLast);
        $startDay = $prevPayDay + 1;

        if ($startDay > $prevLast) {
            $periodStart = date('Y-m-01', $monthFirstTs);
        } else {
            $periodStart = date('Y-m-', $prevTs) . str_pad((string)$startDay, 2, '0', STR_PAD_LEFT);
        }

        return ['start' => $periodStart, 'end' => $periodEnd, 'pay_day' => $payDay];
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

    /** Payroll month (YYYY-MM) to calculate as of $asOf. After pay_day → current month; on/before → previous. */
    public function suggestPayrollMonth(?int $payDay = null, ?string $asOf = null): string
    {
        if ($payDay === null) {
            $payDay = $this->getDefaultPayDay();
        }
        $asOf = $asOf ?? date('Y-m-d');
        $day = (int)date('j', strtotime($asOf));
        $monthFirstTs = strtotime(date('Y-m-01', strtotime($asOf)));
        if ($day > $payDay) {
            return date('Y-m', $monthFirstTs);
        }
        return date('Y-m', strtotime('-1 month', $monthFirstTs));
    }

    public function isPeriodClosed(string $payrollMonth, ?int $payDay = null, ?string $asOf = null): bool
    {
        $period = $this->attendancePeriodBounds($payrollMonth, $payDay);
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
        $period = $this->effectivePeriodBounds($monthFirst, $payDay, $hireDate);
        $periodStart = $period['start'];
        $periodEnd = $period['end'];
        if ($hireDate !== null && $hireDate > $periodEnd) {
            return $result;
        }
        $missingScanEnd = $this->attendanceClosedScanEnd($periodStart, $periodEnd);
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
        $ctx = [
            'day_off' => 0,
            'skip_missing' => false,
            'holidays' => [],
            'leave_dates' => [],
            'dayoff_requests' => [],
        ];

        try {
            $stmt = $this->pdo->prepare("
                SELECT u.work_mode, COALESCE(s.day_off, 0) AS day_off
                FROM users u
                LEFT JOIN hr_employee_schedules s ON s.user_id = u.id
                WHERE u.id = ? AND u.is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $ctx['skip_missing'] = true;
                return $ctx;
            }
            $ctx['day_off'] = (int)($user['day_off'] ?? 0);
            if (($user['work_mode'] ?? 'OFFICE') === 'WFH') {
                $ctx['skip_missing'] = true;
            }
        } catch (Throwable $e) {
            $ctx['skip_missing'] = true;
            return $ctx;
        }

        try {
            $stmt = $this->pdo->prepare("SELECT date FROM hr_holidays WHERE is_active = 1 AND date BETWEEN ? AND ?");
            $stmt->execute([$periodStart, $periodEnd]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $date) {
                $ctx['holidays'][$date] = true;
            }
        } catch (Throwable $e) {
            /* ignore */
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT start_date, end_date
                FROM hr_leave_requests
                WHERE user_id = ?
                  AND status NOT IN ('REJECTED','CANCELLED')
                  AND start_date <= ? AND end_date >= ?
            ");
            $stmt->execute([$userId, $periodEnd, $periodStart]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $leave) {
                $start = max($periodStart, (string)$leave['start_date']);
                $end = min($periodEnd, (string)$leave['end_date']);
                for ($ts = strtotime($start); $ts !== false && $ts <= strtotime($end); $ts += 86400) {
                    $ctx['leave_dates'][date('Y-m-d', $ts)] = true;
                }
            }
        } catch (Throwable $e) {
            /* ignore */
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT week_start, week_end, requested_day_off
                FROM hr_dayoff_requests
                WHERE user_id = ? AND status = 'APPROVED'
                  AND week_start <= ? AND week_end >= ?
            ");
            $stmt->execute([$userId, $periodEnd, $periodStart]);
            $ctx['dayoff_requests'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $ctx['dayoff_requests'] = [];
        }

        return $ctx;
    }

    public function isPayrollWorkday(array $ctx, string $date): bool
    {
        if (!empty($ctx['holidays'][$date]) || !empty($ctx['leave_dates'][$date])) {
            return false;
        }
        $effectiveDayOff = (int)($ctx['day_off'] ?? 0);
        foreach ($ctx['dayoff_requests'] ?? [] as $request) {
            if ($date >= $request['week_start'] && $date <= $request['week_end']) {
                $effectiveDayOff = (int)$request['requested_day_off'];
                break;
            }
        }
        return (int)date('w', strtotime($date)) !== $effectiveDayOff;
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
    public function resolveExtraTaxRequest(?array $setup, bool $taxOptOut = false): float
    {
        if ($taxOptOut || !$this->isTaxEnabled()) {
            return 0.0;
        }
        if (!$setup || !isset($setup['additional_tax_withholding'])) {
            return 0.0;
        }
        return max(0, (float)$setup['additional_tax_withholding']);
    }

    public function saveSalarySetup(int $userId, array $data): array
    {
        $bundle = $this->prepareSalarySetupBundle($userId, $data);

        $this->pdo->beginTransaction();
        try {
            $setupResult = $this->persistSalarySetupRow($userId, $bundle['setup']);
            $this->updateUserBenefitProfile($userId, $bundle['profile']);
            $recalcCount = $this->recalculateOpenRunsForUser($userId, $bundle['setup']['effective_from']);
            $this->pdo->commit();

            return array_merge($setupResult, [
                'recalculated_runs' => $recalcCount,
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
        $effectiveFrom = $this->normalizeEffectiveMonth($data['effective_from'] ?? '');
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

        $ssWageBase = $this->socialSecurityWageBase([
            'base_salary' => $baseSalary,
            'bonus_fixed' => $bonusFixed,
            'allowance_json' => $allowanceJson,
            'income_other_json' => $incomeOtherJson,
        ]);
        $socialSecurity = $this->calcSocialSecurityForUser(
            $userId,
            $ssWageBase,
            (bool)$ssOptOut,
            $effectiveFrom
        );

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
            'base_salary' => $baseSalary,
            'bonus_fixed' => $bonusFixed,
            'provident_fund' => $providentFund,
            'social_security' => $socialSecurity,
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

    private function closePriorSalarySetupVersions(int $userId, string $effectiveFrom): void
    {
        $prevEnd = date('Y-m-d', strtotime($effectiveFrom . ' -1 day'));
        $this->pdo->prepare(
            'UPDATE employee_salary_setup SET effective_to = ?
             WHERE user_id = ? AND effective_from < ? AND (effective_to IS NULL OR effective_to >= ?)'
        )->execute([$prevEnd, $userId, $effectiveFrom, $effectiveFrom]);
    }

    /**
     * @param array<string,mixed> $setup
     */
    private function persistSalarySetupRow(int $userId, array $setup): array
    {
        $effectiveFrom = $setup['effective_from'];
        $chk = $this->pdo->prepare('SELECT id FROM employee_salary_setup WHERE user_id = ? AND effective_from = ? ORDER BY id DESC LIMIT 1');
        $chk->execute([$userId, $effectiveFrom]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);

        $cols = ['base_salary', 'bonus_fixed', 'provident_fund', 'social_security',
            'group_insurance_total_monthly', 'group_insurance_employer_pct',
            'health_insurance_total_monthly', 'health_insurance_employer_pct',
            'ss_opt_out', 'tax_opt_out', 'hi_opt_out', 'gi_opt_out',
            'additional_tax_withholding',
            'allowance_json', 'income_other_json', 'deduction_other_json', 'notes', 'created_by'];

        if ($existing) {
            $sets = implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ', updated_at = NOW()';
            $this->pdo->prepare("UPDATE employee_salary_setup SET $sets WHERE id = ?")
                ->execute([...array_map(fn($c) => $setup[$c] ?? null, $cols), $existing['id']]);
            return ['action' => 'updated', 'id' => (int)$existing['id']];
        }

        $this->closePriorSalarySetupVersions($userId, $effectiveFrom);

        $ph = implode(',', array_fill(0, count($cols) + 2, '?'));
        $this->pdo->prepare('INSERT INTO employee_salary_setup (user_id, effective_from, ' . implode(',', $cols) . ") VALUES ($ph)")
            ->execute([$userId, $effectiveFrom, ...array_map(fn($c) => $setup[$c] ?? null, $cols)]);
        return ['action' => 'created', 'id' => (int)$this->pdo->lastInsertId()];
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
        if ($setupOverride) {
            $setup = is_array($setup) ? array_merge($setup, $setupOverride) : $setupOverride;
        }
        $gross = $setup ? (float)$setup['base_salary'] : 0;
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
        $ss = $this->calcSocialSecurityForUser($userId, $ssWageBase, $ssOptOut, $monthFirst);
        $pf = $setup ? (float)$setup['provident_fund'] : 0;
        $taxBase = $this->calcTaxForUser($userId, $annualEst, $ss * 12, $pf * 12, $monthFirst, $taxOptOut);
        $extraTaxReq = $this->resolveExtraTaxRequest($setup, $taxOptOut);

        $giTotal = (float)(is_array($setup) ? ($setup['group_insurance_total_monthly'] ?? 0) : 0);
        $giEmpPct = (float)(is_array($setup) ? ($setup['group_insurance_employer_pct'] ?? 50) : 50);
        $groupInsurance = $this->calcGroupInsuranceForUser($userId, (float)$giTotal, (float)$giEmpPct, $giOptOut, $monthFirst);

        $hiTotal = (float)(is_array($setup) ? ($setup['health_insurance_total_monthly'] ?? 0) : 0);
        $hiEmpPct = (float)(is_array($setup) ? ($setup['health_insurance_employer_pct'] ?? 50) : 50);
        $healthInsurance = $this->calcHealthInsuranceForUser($userId, (float)$hiTotal, (float)$hiEmpPct, $hiOptOut, $monthFirst);

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
            $payDay = ($payDay !== null && $payDay >= 1 && $payDay <= 31) ? $payDay : $this->getDefaultPayDay();
            $runId = $existing ? (int)$existing['id'] : 0;
            if ($runId > 0) {
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
                if (!$this->shouldIncludeEmployeeInRun($userRow['hire_date'] ?? null, $monthFirst, $payDay)) {
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
        $stmt = $this->pdo->prepare("UPDATE payroll_runs SET status = 'paid' WHERE id = ? AND status = 'approved'");
        $stmt->execute([$runId]);
        if (!$stmt->rowCount()) {
            throw new \RuntimeException('Run not found or not in approved status');
        }
        // หมายเหตุ: รายจ่าย ERP (erp_company_transactions) สร้างจาก TP-CRM เท่านั้น
    }

    public function cancelPaid(int $runId): void
    {
        $stmt = $this->pdo->prepare("UPDATE payroll_runs SET status = 'approved' WHERE id = ? AND status = 'paid'");
        $stmt->execute([$runId]);
        if (!$stmt->rowCount()) {
            throw new \RuntimeException('Run not found or not in paid status');
        }
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
        if (!$row) return false;

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
