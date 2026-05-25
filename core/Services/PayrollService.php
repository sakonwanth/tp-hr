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

    /**
     * Group insurance (employee portion).
     */
    public function calcGroupInsurance(float $totalMonthly, float $employerPct): float
    {
        if ($totalMonthly <= 0) return 0;
        $employerPct = max(0, min(100, $employerPct));
        return round($totalMonthly * (100 - $employerPct) / 100, 2);
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

        $period = $this->attendancePeriodBounds($monthFirst, $payDay);
        $periodStart = $period['start'];
        $periodEnd = $period['end'];
        $missingScanEnd = min($periodEnd, date('Y-m-d'));

        try {
            $stmt = $this->pdo->prepare("
                SELECT attendance_date, status, late_minutes, late_excused, late_notified_at, remarks
                FROM hr_attendances WHERE user_id = ? AND attendance_date BETWEEN ? AND ?
                ORDER BY attendance_date
            ");
            $stmt->execute([$userId, $periodStart, $periodEnd]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return $result;
        }

        $absentDates = [];
        $loggedDates = [];
        foreach ($logs as $log) {
            $date = $log['attendance_date'];
            $loggedDates[$date] = true;
            $status = $log['status'] ?? 'PRESENT';
            $lateMin = (int)($log['late_minutes'] ?? 0);
            $excused = (int)($log['late_excused'] ?? 0);

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

            if (in_array($status, ['PRESENT','LATE','WFH','HALF_DAY'], true) && $lateMin > 0 && $excused !== 1) {
                if ($lateMin <= 30) {
                    $result['late_count_30']++;
                    $result['lateness_deduction'] += $rateLate30;
                    $result['breakdown'][] = ['date' => $date, 'kind' => 'late_30', 'amount' => $rateLate30, 'note' => "มาสาย {$lateMin} นาที"];
                } elseif ($lateMin <= 60) {
                    $result['late_count_60']++;
                    $result['lateness_deduction'] += $rateLate60;
                    $result['breakdown'][] = ['date' => $date, 'kind' => 'late_60', 'amount' => $rateLate60, 'note' => "มาสาย {$lateMin} นาที"];
                } else {
                    if ($lateOver60AsAbsent) {
                        $result['absent_days'] += 1;
                        $absentDates[$date] = true;
                        $result['breakdown'][] = ['date' => $date, 'kind' => 'late_over60_absent', 'amount' => $rateAbsent, 'note' => "มาสาย {$lateMin} นาที (ตีเป็นขาด)"];
                    } else {
                        $result['lateness_deduction'] += $rateLate60;
                        $result['breakdown'][] = ['date' => $date, 'kind' => 'late_over60', 'amount' => $rateLate60, 'note' => "มาสาย {$lateMin} นาที"];
                    }
                }
            }
        }

        foreach ($this->findMissingAbsentDates($userId, $periodStart, $missingScanEnd, $loggedDates) as $date) {
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
     * Calculate missing workdays directly from calendars/schedules, so payroll
     * does not silently under-deduct when the absence backfill cron has not run.
     */
    private function findMissingAbsentDates(int $userId, string $periodStart, string $scanEnd, array $loggedDates): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT u.id, u.work_mode, COALESCE(s.day_off, 0) AS day_off
                FROM users u
                LEFT JOIN hr_employee_schedules s ON s.user_id = u.id
                WHERE u.id = ? AND u.is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) return [];
            if (($user['work_mode'] ?? 'OFFICE') === 'WFH') return [];
        } catch (Throwable $e) {
            return [];
        }

        $holidays = [];
        try {
            $stmt = $this->pdo->prepare("SELECT date FROM hr_holidays WHERE is_active = 1 AND date BETWEEN ? AND ?");
            $stmt->execute([$periodStart, $scanEnd]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $date) {
                $holidays[$date] = true;
            }
        } catch (Throwable $e) {
            $holidays = [];
        }

        $leaveDates = [];
        try {
            $stmt = $this->pdo->prepare("
                SELECT start_date, end_date
                FROM hr_leave_requests
                WHERE user_id = ?
                  AND status NOT IN ('REJECTED','CANCELLED')
                  AND start_date <= ? AND end_date >= ?
            ");
            $stmt->execute([$userId, $scanEnd, $periodStart]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $leave) {
                $start = max($periodStart, (string)$leave['start_date']);
                $end = min($scanEnd, (string)$leave['end_date']);
                for ($ts = strtotime($start); $ts !== false && $ts <= strtotime($end); $ts += 86400) {
                    $leaveDates[date('Y-m-d', $ts)] = true;
                }
            }
        } catch (Throwable $e) {
            $leaveDates = [];
        }

        $dayoffRequests = [];
        try {
            $stmt = $this->pdo->prepare("
                SELECT week_start, week_end, requested_day_off
                FROM hr_dayoff_requests
                WHERE user_id = ? AND status = 'APPROVED'
                  AND week_start <= ? AND week_end >= ?
            ");
            $stmt->execute([$userId, $scanEnd, $periodStart]);
            $dayoffRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $dayoffRequests = [];
        }

        $missingAbsentDates = [];
        $defaultDayOff = (int)($user['day_off'] ?? 0);
        for ($ts = strtotime($periodStart); $ts !== false && $ts <= strtotime($scanEnd); $ts += 86400) {
            $date = date('Y-m-d', $ts);
            if (!empty($loggedDates[$date])) continue;
            if (!empty($holidays[$date])) continue;
            if (!empty($leaveDates[$date])) continue;

            $effectiveDayOff = $defaultDayOff;
            foreach ($dayoffRequests as $request) {
                if ($date >= $request['week_start'] && $date <= $request['week_end']) {
                    $effectiveDayOff = (int)$request['requested_day_off'];
                    break;
                }
            }
            if ((int)date('w', $ts) === $effectiveDayOff) continue;

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

    public function saveSalarySetup(int $userId, array $data): array
    {
        $effectiveFrom = $data['effective_from'];
        $chk = $this->pdo->prepare("SELECT id FROM employee_salary_setup WHERE user_id = ? AND effective_from = ? ORDER BY id DESC LIMIT 1");
        $chk->execute([$userId, $effectiveFrom]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);

        $cols = ['base_salary', 'bonus_fixed', 'provident_fund', 'social_security',
            'group_insurance_total_monthly', 'group_insurance_employer_pct',
            'ss_opt_out', 'additional_tax_withholding',
            'allowance_json', 'income_other_json', 'deduction_other_json', 'notes', 'created_by'];

        if ($existing) {
            $sets = implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ', updated_at = NOW()';
            $this->pdo->prepare("UPDATE employee_salary_setup SET $sets WHERE id = ?")
                ->execute([...array_map(fn($c) => $data[$c] ?? null, $cols), $existing['id']]);
            return ['action' => 'updated', 'id' => (int)$existing['id']];
        }

        $ph = implode(',', array_fill(0, count($cols) + 2, '?'));
        $this->pdo->prepare("INSERT INTO employee_salary_setup (user_id, effective_from, " . implode(',', $cols) . ") VALUES ($ph)")
            ->execute([$userId, $effectiveFrom, ...array_map(fn($c) => $data[$c] ?? null, $cols)]);
        return ['action' => 'created', 'id' => (int)$this->pdo->lastInsertId()];
    }

    // ──────────────── Slip Calculation ────────────────

    /**
     * Calculate a single employee's payroll for a given month.
     * Returns the full slip data array without persisting.
     */
    public function calculateSlip(int $userId, string $monthFirst, ?int $payDay = null): array
    {
        if ($payDay === null) {
            $payDay = $this->getDefaultPayDay();
        }
        $setup = $this->getSalarySetup($userId, $monthFirst);
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

        $totalIncome = $gross + $bonus + $allowances + $incomeOther;
        $annualEst = $totalIncome * 12;
        $ssOptOut = $setup && !empty($setup['ss_opt_out']);
        $ssWageBase = $this->socialSecurityWageBase($setup);
        $ss = $this->calcSocialSecurity($ssWageBase, $ssOptOut, $monthFirst);
        $pf = $setup ? (float)$setup['provident_fund'] : 0;
        $taxBase = $this->calcTaxMonthly($annualEst, $ss * 12, $pf * 12, $monthFirst);
        $extraTaxReq = $setup && isset($setup['additional_tax_withholding']) ? max(0, (float)$setup['additional_tax_withholding']) : 0;

        $giTotal = $setup['group_insurance_total_monthly'] ?? 0;
        $giEmpPct = $setup['group_insurance_employer_pct'] ?? 50;
        $groupInsurance = $this->calcGroupInsurance((float)$giTotal, (float)$giEmpPct);

        $att = $this->computeAttendanceDeductions($userId, $monthFirst, $payDay);
        $absenceDed = (float)$att['absence_deduction'];
        $latenessDed = (float)$att['lateness_deduction'];
        $attDetailJson = (!empty($att['breakdown']) || !empty($att['warnings']))
            ? json_encode(['breakdown' => $att['breakdown'], 'warnings' => $att['warnings']], JSON_UNESCAPED_UNICODE)
            : null;

        $maxExtra = max(0, $totalIncome - ($taxBase + $pf + $ss + $groupInsurance + $dedOther + $absenceDed + $latenessDed));
        $extraTax = min($extraTaxReq, $maxExtra);
        $tax = $taxBase + $extraTax;
        $totalDed = $tax + $pf + $ss + $groupInsurance + $dedOther + $absenceDed + $latenessDed;
        $net = max(0, $totalIncome - $totalDed);

        return [
            'user_id' => $userId,
            'gross_salary' => $gross,
            'bonus' => $bonus,
            'allowances' => $allowances,
            'income_other_json' => $incomeOtherJson,
            'total_income' => $totalIncome,
            'tax_withheld' => $tax,
            'provident_fund' => $pf,
            'social_security' => $ss,
            'group_insurance' => $groupInsurance,
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
    public function createRun(string $month, int $createdBy): array
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
            $payDay = $this->getDefaultPayDay();
            $runId = $existing ? (int)$existing['id'] : 0;
            if ($runId > 0) {
                $this->pdo->prepare("DELETE FROM payroll_slips WHERE payroll_run_id = ?")->execute([$runId]);
                $pd = $this->pdo->prepare("SELECT pay_day FROM payroll_runs WHERE id = ?");
                $pd->execute([$runId]);
                $payDay = (int)($pd->fetchColumn() ?: $payDay);
            } else {
                $this->pdo->prepare("INSERT INTO payroll_runs (payroll_month, pay_day, status, created_by) VALUES (?, ?, 'draft', ?)")
                    ->execute([$monthFirst, $payDay, $createdBy]);
                $runId = (int)$this->pdo->lastInsertId();
            }

            $users = $this->pdo->query("SELECT id FROM users WHERE is_active = 1 AND employee_code NOT LIKE 'CR%'")
                ->fetchAll(PDO::FETCH_COLUMN);

            $totalGross = $totalTax = $totalNet = 0;
            $ins = $this->pdo->prepare("INSERT INTO payroll_slips (payroll_run_id, user_id, gross_salary, bonus, allowances, income_other_json, total_income, tax_withheld, provident_fund, social_security, group_insurance, deduction_other_json, absent_days, late_count_30, late_count_60, absence_deduction, lateness_deduction, attendance_detail_json, total_deductions, net_salary) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

            foreach ($users as $uid) {
                $slip = $this->calculateSlip((int)$uid, $monthFirst, $payDay);
                $ins->execute([
                    $runId, $uid,
                    $slip['gross_salary'], $slip['bonus'], $slip['allowances'],
                    $slip['income_other_json'], $slip['total_income'],
                    $slip['tax_withheld'], $slip['provident_fund'], $slip['social_security'],
                    $slip['group_insurance'], $slip['deduction_other_json'],
                    $slip['absent_days'], $slip['late_count_30'], $slip['late_count_60'],
                    $slip['absence_deduction'], $slip['lateness_deduction'],
                    $slip['attendance_detail_json'], $slip['total_deductions'], $slip['net_salary'],
                ]);
                $totalGross += $slip['total_income'];
                $totalTax += $slip['tax_withheld'];
                $totalNet += $slip['net_salary'];
            }

            $this->pdo->prepare("UPDATE payroll_runs SET employee_count = ?, total_gross = ?, total_tax = ?, total_net = ?, status = 'calculated' WHERE id = ?")
                ->execute([count($users), $totalGross, $totalTax, $totalNet, $runId]);

            $this->pdo->commit();
            return ['run_id' => $runId, 'employee_count' => count($users), 'total_gross' => $totalGross, 'total_net' => $totalNet, 'is_recalculation' => (bool)$existing];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function approveRun(int $runId, int $approvedBy): void
    {
        $this->pdo->prepare("UPDATE payroll_runs SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?")
            ->execute([$approvedBy, $runId]);
    }

    public function markPaid(int $runId): void
    {
        $stmt = $this->pdo->prepare("UPDATE payroll_runs SET status = 'paid' WHERE id = ? AND status = 'approved'");
        $stmt->execute([$runId]);
        if (!$stmt->rowCount()) {
            throw new \RuntimeException('Run not found or not in approved status');
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

        $this->pdo->prepare("UPDATE payroll_slips SET gross_salary=?, bonus=?, allowances=?, income_other_json=?, total_income=?, tax_withheld=?, provident_fund=?, social_security=?, group_insurance=?, deduction_other_json=?, absent_days=?, late_count_30=?, late_count_60=?, absence_deduction=?, lateness_deduction=?, attendance_detail_json=?, total_deductions=?, net_salary=? WHERE id=?")
            ->execute([
                $slip['gross_salary'], $slip['bonus'], $slip['allowances'],
                $slip['income_other_json'], $slip['total_income'],
                $slip['tax_withheld'], $slip['provident_fund'], $slip['social_security'],
                $slip['group_insurance'], $slip['deduction_other_json'],
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
            WHERE s.payroll_run_id = ? AND u.employee_code NOT LIKE 'CR%'
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
            WHERE s.payroll_run_id = ? AND u.employee_code NOT LIKE 'CR%'
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
