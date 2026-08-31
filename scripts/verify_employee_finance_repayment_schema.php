#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
$pdo = getDB();
$required = [
    'hr_employee_finance_repayments_received',
    'hr_employee_finance_repayment_allocations',
    'hr_employee_finance_accounting_outbox',
];
$missing = [];
foreach ($required as $table) {
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    if (!$stmt->fetchColumn()) $missing[] = $table;
}
$method = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='hr_salary_advances' AND COLUMN_NAME='repayment_method'"
)->fetchColumn();
if (!str_contains((string)$method, "'cash'")) $missing[] = 'hr_salary_advances.repayment_method:cash';
foreach (['hr_salary_advances', 'hr_employee_loans'] as $table) {
    $stmt = $pdo->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='disbursement_method'"
    );
    $stmt->execute([$table]);
    $columnType = (string)$stmt->fetchColumn();
    foreach (['cash', 'cheque'] as $requiredMethod) {
        if (!str_contains($columnType, "'{$requiredMethod}'")) {
            $missing[] = "{$table}.disbursement_method:{$requiredMethod}";
        }
    }
}
echo json_encode(['ok' => $missing === [], 'missing' => $missing], JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($missing === [] ? 0 : 1);
