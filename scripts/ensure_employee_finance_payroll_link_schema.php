#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/bootstrap.php';

$checkOnly = in_array('--check', $argv, true);
$pdo = getDB();
$table = 'hr_employee_finance_payroll_links';
$migration = dirname(__DIR__) . '/database/migrations/2026_07_30_employee_finance_payroll_links.sql';

$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
};

if (!$checkOnly && !$tableExists($pdo, $table)) {
    $sql = (string)file_get_contents($migration);
    if ($sql === '') {
        throw new RuntimeException('Employee-finance payroll-link migration is empty');
    }
    $pdo->exec($sql);
}

if (!$tableExists($pdo, $table)) {
    fwrite(STDERR, "FAIL: {$table} is missing\n");
    exit(1);
}

$expectedColumns = [
    'id', 'source_type', 'source_id', 'user_id', 'payroll_run_id', 'payroll_slip_id',
    'amount', 'link_status', 'included_at', 'settled_at', 'reversed_at', 'created_at', 'updated_at',
];
$columnStmt = $pdo->prepare(
    'SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?'
);
$columnStmt->execute([$table]);
$columns = $columnStmt->fetchAll(PDO::FETCH_COLUMN);
$missingColumns = array_values(array_diff($expectedColumns, $columns));

$expectedIndexes = [
    'PRIMARY', 'uk_hr_finance_payroll_source', 'uk_hr_finance_payroll_slip_source',
    'idx_hr_finance_payroll_run', 'idx_hr_finance_payroll_user', 'idx_hr_finance_payroll_slip',
];
$indexStmt = $pdo->prepare(
    'SELECT DISTINCT index_name FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=?'
);
$indexStmt->execute([$table]);
$indexes = $indexStmt->fetchAll(PDO::FETCH_COLUMN);
$missingIndexes = array_values(array_diff($expectedIndexes, $indexes));

if ($missingColumns || $missingIndexes) {
    fwrite(STDERR, 'FAIL: schema mismatch ' . json_encode([
        'missing_columns' => $missingColumns,
        'missing_indexes' => $missingIndexes,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}

echo 'OK: employee-finance payroll-link schema is ready'
    . ($checkOnly ? ' (check only)' : '') . PHP_EOL;
