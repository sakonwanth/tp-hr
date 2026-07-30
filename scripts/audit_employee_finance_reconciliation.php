<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

/**
 * Read-only reconciliation for employee finance -> payroll.
 *
 * Usage:
 *   php scripts/audit_employee_finance_reconciliation.php [--run-id=307] [--loan-id=7] [--json] [--strict]
 *
 * This script never writes data and intentionally reports only internal IDs.
 */

$options = getopt('', ['run-id::', 'loan-id::', 'json', 'strict']);
$runId = max(0, (int)($options['run-id'] ?? 0));
$loanId = max(0, (int)($options['loan-id'] ?? 0));
$asJson = array_key_exists('json', $options);
$strict = array_key_exists('strict', $options);
$pdo = Database::getInstance()->getConnection();

$issues = [];
$addIssue = static function (string $severity, string $code, array $context = []) use (&$issues): void {
    $issues[] = ['severity' => $severity, 'code' => $code, 'context' => $context];
};
$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
};

$requiredTables = [
    'payroll_runs', 'payroll_slips', 'hr_employee_loans',
    'hr_loan_repayments', 'hr_salary_advances',
];
$missingTables = array_values(array_filter(
    $requiredTables,
    static fn(string $table): bool => !$tableExists($pdo, $table)
));
if ($missingTables) {
    $result = [
        'filters' => ['run_id' => $runId ?: null, 'loan_id' => $loanId ?: null],
        'scanned' => ['slips' => 0, 'loan_repayments' => 0, 'salary_advances' => 0, 'payroll_sources' => 0],
        'summary' => ['critical' => 1, 'warning' => 0, 'total' => 1],
        'issues' => [[
            'severity' => 'critical',
            'code' => 'SCHEMA_MISSING',
            'context' => ['tables' => $missingTables],
        ]],
    ];
    if ($asJson) {
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo 'Employee finance reconciliation (READ ONLY)' . PHP_EOL;
        echo 'CRITICAL SCHEMA_MISSING ' . json_encode(['tables' => $missingTables], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        echo 'Result: critical=1 warning=0 total=1' . PHP_EOL;
    }
    exit(1);
}

$runWhere = $runId > 0 ? ' WHERE r.id = ?' : '';
$stmt = $pdo->prepare(
    "SELECT s.id slip_id,s.user_id,s.payroll_run_id,r.payroll_month,r.status run_status,
            s.deduction_other_json
       FROM payroll_slips s
       JOIN payroll_runs r ON r.id=s.payroll_run_id{$runWhere}
      ORDER BY s.payroll_run_id,s.id"
);
$stmt->execute($runId > 0 ? [$runId] : []);
$slips = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sourceOccurrences = [];
$slipsByUserMonth = [];
foreach ($slips as $slip) {
    $month = substr((string)$slip['payroll_month'], 0, 7);
    $userMonthKey = (int)$slip['user_id'] . ':' . $month;
    $slipsByUserMonth[$userMonthKey][] = $slip;
    $items = json_decode((string)($slip['deduction_other_json'] ?? ''), true);
    if (!is_array($items)) {
        if (trim((string)($slip['deduction_other_json'] ?? '')) !== '') {
            $addIssue('critical', 'INVALID_DEDUCTION_JSON', [
                'slip_id' => (int)$slip['slip_id'],
                'run_id' => (int)$slip['payroll_run_id'],
            ]);
        }
        continue;
    }
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $sourceType = (string)($item['source_type'] ?? '');
        $sourceId = (int)($item['source_id'] ?? 0);
        if ($sourceType === '' || $sourceId <= 0) continue;
        $key = $sourceType . ':' . $sourceId;
        $sourceOccurrences[$key][] = [
            'slip_id' => (int)$slip['slip_id'],
            'run_id' => (int)$slip['payroll_run_id'],
            'run_status' => (string)$slip['run_status'],
            'user_id' => (int)$slip['user_id'],
            'month' => $month,
            'amount' => round((float)($item['amount'] ?? 0), 2),
        ];
    }
}

foreach ($sourceOccurrences as $sourceKey => $occurrences) {
    if (count($occurrences) > 1) {
        $addIssue('critical', 'DUPLICATE_PAYROLL_SOURCE', [
            'source' => $sourceKey,
            'occurrences' => $occurrences,
        ]);
    }
}

$loanWhere = [];
$loanParams = [];
if ($loanId > 0) {
    $loanWhere[] = 'l.id=?';
    $loanParams[] = $loanId;
}
if ($runId > 0) {
    $loanWhere[] = '(r.payroll_run_id=? OR DATE_FORMAT(r.due_date,\'%Y-%m\') IN (SELECT DATE_FORMAT(payroll_month,\'%Y-%m\') FROM payroll_runs WHERE id=?))';
    $loanParams[] = $runId;
    $loanParams[] = $runId;
}
$loanSqlWhere = $loanWhere ? ' WHERE ' . implode(' AND ', $loanWhere) : '';
$stmt = $pdo->prepare(
    "SELECT r.id repayment_id,r.loan_id,r.installment_no,r.due_date,r.due_amount,
            r.status repayment_status,r.payroll_run_id,l.user_id,l.status loan_status,
            l.repayment_method,l.expense_request_id
       FROM hr_loan_repayments r
       JOIN hr_employee_loans l ON l.id=r.loan_id{$loanSqlWhere}
      ORDER BY r.loan_id,r.installment_no,r.id"
);
$stmt->execute($loanParams);
$repayments = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($repayments as $repayment) {
    $repaymentId = (int)$repayment['repayment_id'];
    $sourceKey = 'employee_loan_repayment:' . $repaymentId;
    $occurrences = $sourceOccurrences[$sourceKey] ?? [];
    $expectedAmount = round((float)$repayment['due_amount'], 2);
    $dueMonth = substr((string)$repayment['due_date'], 0, 7);
    $expectedDueDate = (new DateTimeImmutable($dueMonth . '-01'))
        ->modify('last day of this month');
    if ($expectedDueDate->format('w') === '0') {
        $expectedDueDate = $expectedDueDate->modify('-1 day');
    }
    if ((string)$repayment['repayment_status'] === 'scheduled'
        && (string)$repayment['due_date'] !== $expectedDueDate->format('Y-m-d')) {
        $addIssue('warning', 'NON_CANONICAL_DUE_DATE', [
            'repayment_id' => $repaymentId,
            'actual' => (string)$repayment['due_date'],
            'expected' => $expectedDueDate->format('Y-m-d'),
        ]);
    }
    foreach ($occurrences as $occurrence) {
        if ((int)$occurrence['user_id'] !== (int)$repayment['user_id']) {
            $addIssue('critical', 'SOURCE_ASSIGNED_TO_WRONG_USER', [
                'repayment_id' => $repaymentId,
                'slip_id' => (int)$occurrence['slip_id'],
            ]);
        }
        if (abs((float)$occurrence['amount'] - $expectedAmount) > 0.009) {
            $addIssue('critical', 'REPAYMENT_AMOUNT_MISMATCH', [
                'repayment_id' => $repaymentId,
                'slip_id' => (int)$occurrence['slip_id'],
                'expected' => $expectedAmount,
                'actual' => (float)$occurrence['amount'],
            ]);
        }
    }
    if ((string)$repayment['repayment_status'] === 'paid') {
        $linkedRunId = (int)($repayment['payroll_run_id'] ?? 0);
        $foundInLinkedRun = false;
        foreach ($occurrences as $occurrence) {
            if ((int)$occurrence['run_id'] === $linkedRunId) $foundInLinkedRun = true;
        }
        if ($linkedRunId <= 0 || !$foundInLinkedRun) {
            $addIssue('critical', 'PAID_REPAYMENT_WITHOUT_SLIP_SOURCE', [
                'repayment_id' => $repaymentId,
                'payroll_run_id' => $linkedRunId,
            ]);
        }
    }
    if ((string)$repayment['repayment_status'] === 'scheduled') {
        $monthSlips = $slipsByUserMonth[(int)$repayment['user_id'] . ':' . $dueMonth] ?? [];
        $hasApprovedOrPaidRun = false;
        foreach ($monthSlips as $monthSlip) {
            if (in_array((string)$monthSlip['run_status'], ['approved', 'paid'], true)) {
                $hasApprovedOrPaidRun = true;
            }
        }
        if ($hasApprovedOrPaidRun && !$occurrences) {
            $addIssue('critical', 'DUE_REPAYMENT_MISSING_FROM_FINALIZED_SLIP', [
                'repayment_id' => $repaymentId,
                'due_month' => $dueMonth,
            ]);
        } elseif ($occurrences) {
            foreach ($occurrences as $occurrence) {
                if ((string)$occurrence['run_status'] === 'paid') {
                    $addIssue('critical', 'PAID_RUN_LEFT_REPAYMENT_SCHEDULED', [
                        'repayment_id' => $repaymentId,
                        'run_id' => (int)$occurrence['run_id'],
                    ]);
                }
            }
        }
    }
}

$advanceWhere = $runId > 0
    ? " WHERE a.payroll_run_id=? OR a.deduction_month IN (SELECT DATE_FORMAT(payroll_month,'%Y-%m') FROM payroll_runs WHERE id=?)"
    : '';
$stmt = $pdo->prepare(
    "SELECT a.id,a.user_id,a.amount,a.deduction_month,a.status,a.payroll_run_id
       FROM hr_salary_advances a{$advanceWhere}
      ORDER BY a.id"
);
$stmt->execute($runId > 0 ? [$runId, $runId] : []);
$advances = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($advances as $advance) {
    $sourceKey = 'salary_advance:' . (int)$advance['id'];
    $occurrences = $sourceOccurrences[$sourceKey] ?? [];
    foreach ($occurrences as $occurrence) {
        if ((int)$occurrence['user_id'] !== (int)$advance['user_id']) {
            $addIssue('critical', 'ADVANCE_ASSIGNED_TO_WRONG_USER', [
                'advance_id' => (int)$advance['id'],
                'slip_id' => (int)$occurrence['slip_id'],
            ]);
        }
        if (abs((float)$occurrence['amount'] - round((float)$advance['amount'], 2)) > 0.009) {
            $addIssue('critical', 'ADVANCE_AMOUNT_MISMATCH', [
                'advance_id' => (int)$advance['id'],
                'slip_id' => (int)$occurrence['slip_id'],
            ]);
        }
    }
    if ((string)$advance['status'] === 'deducted') {
        $linkedRunId = (int)($advance['payroll_run_id'] ?? 0);
        $foundInLinkedRun = false;
        foreach ($occurrences as $occurrence) {
            if ((int)$occurrence['run_id'] === $linkedRunId) $foundInLinkedRun = true;
        }
        if ($linkedRunId <= 0 || !$foundInLinkedRun) {
            $addIssue('critical', 'DEDUCTED_ADVANCE_WITHOUT_SLIP_SOURCE', [
                'advance_id' => (int)$advance['id'],
                'payroll_run_id' => $linkedRunId,
            ]);
        }
    }
}

$severityCounts = ['critical' => 0, 'warning' => 0];
foreach ($issues as $issue) {
    $severityCounts[$issue['severity']] = ($severityCounts[$issue['severity']] ?? 0) + 1;
}
$result = [
    'filters' => ['run_id' => $runId ?: null, 'loan_id' => $loanId ?: null],
    'scanned' => [
        'slips' => count($slips),
        'loan_repayments' => count($repayments),
        'salary_advances' => count($advances),
        'payroll_sources' => count($sourceOccurrences),
    ],
    'summary' => $severityCounts + ['total' => count($issues)],
    'issues' => $issues,
];

if ($asJson) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo "Employee finance reconciliation (READ ONLY)\n";
    echo 'Scanned: slips=' . count($slips)
        . ' repayments=' . count($repayments)
        . ' advances=' . count($advances)
        . ' sources=' . count($sourceOccurrences) . PHP_EOL;
    foreach ($issues as $issue) {
        echo strtoupper((string)$issue['severity']) . ' ' . $issue['code'] . ' '
            . json_encode($issue['context'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
    echo 'Result: critical=' . $severityCounts['critical']
        . ' warning=' . $severityCounts['warning']
        . ' total=' . count($issues) . PHP_EOL;
}

exit($strict && count($issues) > 0 ? 2 : ($severityCounts['critical'] > 0 ? 1 : 0));
