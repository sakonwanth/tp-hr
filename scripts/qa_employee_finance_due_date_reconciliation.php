<?php
declare(strict_types=1);
$script = (string)file_get_contents(__DIR__ . '/reconcile_employee_finance_due_dates.php');
$checks = [
    'defaults to preview' => str_contains($script, "in_array('--apply', \$argv, true)"),
    'only scheduled rows change' => substr_count($script, "status='scheduled'") >= 2,
    'uses month end' => str_contains($script, "modify('last day of this month')"),
    'moves Sunday earlier' => str_contains($script, "format('w') === '0'") && str_contains($script, "modify('-1 day')"),
    'apply is transactional' => str_contains($script, 'beginTransaction()') && str_contains($script, 'rollBack()'),
    'concurrency is guarded' => str_contains($script, 'AND due_date=?') && str_contains($script, 'Concurrent due-date change'),
];
$failed=0; foreach($checks as $label=>$ok){ echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL; if(!$ok)$failed++; } exit($failed?1:0);
