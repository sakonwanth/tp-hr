<?php
/**
 * Issue an tp-hr API key for machine clients (tp-crm PayrollService, tp-checkin).
 *
 * Usage:
 *   php scripts/issue_hr_api_key.php "tp-crm payroll"
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$name = $argv[1] ?? 'tp-crm integration';
// Optional arg 2: comma-separated scopes (least-privilege per consumer; default = full
// cross-system set). e.g. "attendance.read,attendance.write,attendance.write_all" for tp-checkin.
$scopes = isset($argv[2]) && trim($argv[2]) !== ''
    ? array_values(array_filter(array_map('trim', explode(',', $argv[2]))))
    : [
        'payroll.read',
        'payroll.read_all',
        'payroll.write',
        'payroll.approve',
        'employees.read',
        'attendance.read',
        'attendance.write',
        'attendance.write_all',
    ];

$issued = ApiAuth::issue([
    'name' => $name,
    'scopes' => $scopes,
    'rate_limit_per_min' => 120,
    'created_by' => 1,
    'notes' => 'Issued via scripts/issue_hr_api_key.php for cross-system integration',
]);

echo "Created HR API key for: {$name}\n";
echo "Scopes: " . implode(', ', $scopes) . "\n";
echo "Prefix: {$issued['prefix']}\n";
echo "Key (save now — shown once):\n{$issued['key']}\n";
echo "\nSet in tp-crm .env:\n";
echo "TP_HR_API_URL=https://hr.tp-asset.com/api/v1\n";
echo "TP_HR_API_KEY={$issued['key']}\n";
