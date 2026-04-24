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
     * Social security (employee portion).
     * Rate 5%, base 1,650–15,000, cap 750/month.
     */
    public function calcSocialSecurity(float $baseSalary, bool $optOut = false): float
    {
        if (!$this->isSsEnabled() || $optOut || $baseSalary <= 0) return 0;
        $base = max(1650, min($baseSalary, 15000));
        return round($base * 0.05, 2);
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
    public function calcTaxMonthly(float $annualIncome, float $annualSs = 0, float $annualPf = 0): float
    {
        $expenseAllowance = min($annualIncome * 0.5, 100000);
        $personalAllowance = 60000;
        $ssDeduction = min(max(0, $annualSs), 9000);
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

    /**
     * Compute attendance deductions (absent/late/sick-no-cert).
     */
    public function computeAttendanceDeductions(int $userId, string $monthFirst): array
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

        $monthStart = date('Y-m-01', strtotime($monthFirst));
        $monthEnd = date('Y-m-t', strtotime($monthFirst));

        try {
            $stmt = $this->pdo->prepare("
                SELECT attendance_date, status, late_minutes, late_excused, late_notified_at, remarks
                FROM hr_attendances WHERE user_id = ? AND attendance_date BETWEEN ? AND ?
                ORDER BY attendance_date
            ");
            $stmt->execute([$userId, $monthStart, $monthEnd]);
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

        foreach ($this->findMissingAbsentDates($userId, $monthStart, $monthEnd, $loggedDates) as $date) {
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
            $stmt->execute([$userId, $monthEnd, $monthStart]);
            $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $leaves = [];
        }

        foreach ($leaves as $lv) {
            if ($lv['leave_code'] === 'SICK' && (int)$lv['has_medical_cert'] !== 1) {
                $lvStart = max($lv['start_date'], $monthStart);
                $lvEnd = min($lv['end_date'], $monthEnd);
                $calDays = (strtotime($lvEnd) - strtotime($lvStart)) / 86400 + 1;
                if ($calDays <= 0) continue;
                $totalCal = max(1, (strtotime($lv['end_date']) - strtotime($lv['start_date'])) / 86400 + 1);
                $prorated = (float)$lv['leave_days'] * ($calDays / $totalCal);
                $dupDays = 0;
                for ($t = strtotime($lvStart); $t <= strtotime($lvEnd); $t += 86400) {
                    if (!empty($absentDates[date('Y-m-d', $t)])) $dupDays++;
                }
                if ($dupDays > 0) $prorated = max(0, $prorated - ($prorated * $dupDays / $calDays));
                $prorated = round(max(0, $prorated), 1);
                if ($prorated > 0) {
                    $result['absent_days'] += $prorated;
                    $result['breakdown'][] = [
                        'date' => $lvStart, 'kind' => 'sick_no_cert',
                        'amount' => $rateAbsent * $prorated,
                        'note' => "ลาป่วยไม่มีใบรับรองแพทย์ ({$prorated} วัน)",
                    ];
                }
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
    private function findMissingAbsentDates(int $userId, string $monthStart, string $monthEnd, array $loggedDates): array
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
            $stmt->execute([$monthStart, $monthEnd]);
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
            $stmt->execute([$userId, $monthEnd, $monthStart]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $leave) {
                $start = max($monthStart, (string)$leave['start_date']);
                $end = min($monthEnd, (string)$leave['end_date']);
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
            $stmt->execute([$userId, $monthEnd, $monthStart]);
            $dayoffRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $dayoffRequests = [];
        }

        $missingAbsentDates = [];
        $defaultDayOff = (int)($user['day_off'] ?? 0);
        for ($ts = strtotime($monthStart); $ts !== false && $ts <= strtotime($monthEnd); $ts += 86400) {
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
    public function calculateSlip(int $userId, string $monthFirst): array
    {
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
        $ss = $this->calcSocialSecurity($gross, $ssOptOut);
        $pf = $setup ? (float)$setup['provident_fund'] : 0;
        $taxBase = $this->calcTaxMonthly($annualEst, $ss * 12, $pf * 12);
        $extraTaxReq = $setup && isset($setup['additional_tax_withholding']) ? max(0, (float)$setup['additional_tax_withholding']) : 0;

        $giTotal = $setup['group_insurance_total_monthly'] ?? 0;
        $giEmpPct = $setup['group_insurance_employer_pct'] ?? 50;
        $groupInsurance = $this->calcGroupInsurance((float)$giTotal, (float)$giEmpPct);

        $att = $this->computeAttendanceDeductions($userId, $monthFirst);
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
            $runId = $existing ? (int)$existing['id'] : 0;
            if ($runId > 0) {
                $this->pdo->prepare("DELETE FROM payroll_slips WHERE payroll_run_id = ?")->execute([$runId]);
            } else {
                $this->pdo->prepare("INSERT INTO payroll_runs (payroll_month, pay_day, status, created_by) VALUES (?, 25, 'draft', ?)")
                    ->execute([$monthFirst, $createdBy]);
                $runId = (int)$this->pdo->lastInsertId();
            }

            $users = $this->pdo->query("SELECT id FROM users WHERE is_active = 1 AND employee_code NOT LIKE 'CR%'")
                ->fetchAll(PDO::FETCH_COLUMN);

            $totalGross = $totalTax = $totalNet = 0;
            $ins = $this->pdo->prepare("INSERT INTO payroll_slips (payroll_run_id, user_id, gross_salary, bonus, allowances, income_other_json, total_income, tax_withheld, provident_fund, social_security, group_insurance, deduction_other_json, absent_days, late_count_30, late_count_60, absence_deduction, lateness_deduction, attendance_detail_json, total_deductions, net_salary) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

            foreach ($users as $uid) {
                $slip = $this->calculateSlip((int)$uid, $monthFirst);
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
        $slip = $this->calculateSlip($userId, $monthFirst);
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
        return $this->pdo->query("
            SELECT r.*, u.first_name_th as approver_first, u.last_name_th as approver_last
            FROM payroll_runs r LEFT JOIN users u ON r.approved_by = u.id
            ORDER BY r.payroll_month DESC LIMIT {$limit}
        ")->fetchAll(PDO::FETCH_ASSOC);
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
