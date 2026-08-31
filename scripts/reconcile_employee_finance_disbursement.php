#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/core/Services/PayrollService.php';

$options = getopt('', ['expense-request-id::', 'actor-id::', 'apply', 'json']);
$expenseRequestId = max(0, (int)($options['expense-request-id'] ?? 0));
$actorId = max(0, (int)($options['actor-id'] ?? 0));
$apply = array_key_exists('apply', $options);
$asJson = array_key_exists('json', $options);
if ($apply && $expenseRequestId <= 0) {
    fwrite(STDERR, "--apply requires --expense-request-id\n");
    exit(2);
}

$pdo = getDB();
$where = $expenseRequestId > 0 ? ' AND r.id=?' : '';
$sql = "SELECT r.id expense_request_id,r.request_code,r.payment_method,r.status expense_status,
               f.finance_type,f.finance_id,f.repayment_method,f.disbursement_method,f.finance_status
          FROM line_expense_requests r
          JOIN (
                SELECT 'salary_advance' finance_type,id finance_id,expense_request_id,
                       repayment_method,disbursement_method,status finance_status
                  FROM hr_salary_advances
                UNION ALL
                SELECT 'employee_loan',id,expense_request_id,repayment_method,disbursement_method,status
                  FROM hr_employee_loans
          ) f ON f.expense_request_id=r.id
         WHERE r.request_kind='staff_finance'
           AND r.status IN ('paid','confirmed','completed'){$where}
         ORDER BY r.id";
$stmt = $pdo->prepare($sql);
$stmt->execute($expenseRequestId > 0 ? [$expenseRequestId] : []);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$issues = [];
foreach ($rows as $row) {
    $actual = in_array((string)$row['payment_method'], ['cash', 'cheque'], true)
        ? (string)$row['payment_method'] : 'transfer';
    $rowIssues = [];
    if ((string)$row['disbursement_method'] !== $actual) {
        $rowIssues[] = 'DISBURSEMENT_METHOD_MISMATCH';
    }
    if ((string)$row['finance_type'] === 'salary_advance') {
        $status = (string)$row['finance_status'];
        if ((string)$row['repayment_method'] === 'payroll'
            && !in_array($status, ['pending_deduction', 'deducted'], true)) {
            $rowIssues[] = 'PAYROLL_REPAYMENT_STATUS_MISMATCH';
        }
        if ((string)$row['repayment_method'] !== 'payroll'
            && !in_array($status, ['partial', 'deducted'], true)) {
            $rowIssues[] = 'MANUAL_REPAYMENT_STATUS_MISMATCH';
        }
    }
    if ($rowIssues) {
        $issues[] = [
            'expense_request_id' => (int)$row['expense_request_id'],
            'request_code' => (string)$row['request_code'],
            'finance_type' => (string)$row['finance_type'],
            'finance_id' => (int)$row['finance_id'],
            'payment_method' => (string)$row['payment_method'],
            'repayment_method' => (string)$row['repayment_method'],
            'disbursement_method' => (string)$row['disbursement_method'],
            'finance_status' => (string)$row['finance_status'],
            'issues' => $rowIssues,
        ];
    }
}

$result = ['mode'=>$apply ? 'apply' : 'preview','scanned'=>count($rows),'issues'=>$issues,'repaired'=>[]];
if ($apply && $issues) {
    if (count($issues) !== 1 || (int)$issues[0]['expense_request_id'] !== $expenseRequestId) {
        throw new RuntimeException('Apply must target exactly one matching expense request');
    }
    $result['repaired'][] = (new PayrollService($pdo))->activateEmployeeFinanceForExpense(
        $expenseRequestId,
        $actorId,
        (string)$issues[0]['payment_method']
    );
}

if ($asJson) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} else {
    echo strtoupper($result['mode']) . ' scanned=' . $result['scanned'] . ' issues=' . count($issues) . PHP_EOL;
    foreach ($issues as $issue) echo json_encode($issue, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    if ($result['repaired']) echo 'REPAIRED expense_request_id=' . $expenseRequestId . PHP_EOL;
}
