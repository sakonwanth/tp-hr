<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = (string)file_get_contents($root . '/database/migrations/2026_07_30_employee_finance_payroll_links.sql');
$ensure = (string)file_get_contents($root . '/scripts/ensure_employee_finance_payroll_link_schema.php');
$checks = [
    'migration is additive' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS hr_employee_finance_payroll_links'),
    'source identity is unique' => str_contains($migration, 'UNIQUE KEY uk_hr_finance_payroll_source (source_type, source_id)'),
    'slip source identity is unique' => str_contains($migration, 'UNIQUE KEY uk_hr_finance_payroll_slip_source'),
    'lifecycle supports inclusion settlement reversal' => str_contains($migration, "ENUM('included','settled','reversed')"),
    'amount is fixed precision' => str_contains($migration, 'amount DECIMAL(12,2) NOT NULL'),
    'ensure supports read-only check' => str_contains($ensure, "in_array('--check', \$argv, true)"),
    'ensure verifies columns' => str_contains($ensure, 'information_schema.columns'),
    'ensure verifies indexes' => str_contains($ensure, 'information_schema.statistics'),
];
$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failed++;
}
exit($failed === 0 ? 0 : 1);
