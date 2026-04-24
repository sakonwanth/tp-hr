<?php

require_once dirname(__DIR__) . '/core/Services/SettingsService.php';

function assertSameValue($expected, $actual, string $label): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "[FAIL] {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
    echo "[OK] {$label}" . PHP_EOL;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("
    CREATE TABLE hr_settings (
        key TEXT PRIMARY KEY,
        value TEXT,
        type TEXT DEFAULT 'STRING'
    );
    CREATE TABLE system_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    );
");

$pdo->exec("
    INSERT INTO hr_settings (key, value, type) VALUES
        ('default_work_start', '08:45', 'STRING'),
        ('grace_period_minutes', '20', 'NUMBER'),
        ('enforce_location_checkin', 'true', 'BOOLEAN'),
        ('work_policy_json', '{\"mode\":\"strict\"}', 'JSON');
    INSERT INTO system_settings (setting_key, setting_value) VALUES
        ('work_start_time', '09:00'),
        ('work_end_time', '18:00'),
        ('payroll_absent_rate', '750'),
        ('company_name', 'TP Asset');
");

$service = new SettingsService($pdo);

assertSameValue('08:45', $service->get('default_work_start', '08:30'), 'HR setting wins for canonical work start');
assertSameValue('08:45', $service->get('work_start_time', '08:30'), 'legacy work start alias resolves to HR canonical key');
assertSameValue('18:00', $service->get('default_work_end', '17:30'), 'HR setting falls back to system alias');
assertSameValue(20, $service->get('grace_period_minutes', 15), 'NUMBER HR setting casts to int');
assertSameValue(true, $service->get('enforce_location_checkin', false), 'BOOLEAN HR setting casts to bool');
assertSameValue(['mode' => 'strict'], $service->get('work_policy_json', []), 'JSON HR setting decodes');
assertSameValue('750', $service->getSystem('payroll_absent_rate', '600'), 'payroll setting reads from system_settings');
assertSameValue('TP Asset', $service->get('company_name', 'fallback'), 'company setting is system-owned');
assertSameValue('fallback', $service->get('missing_key', 'fallback'), 'missing key returns default');

$many = $service->getSystemMany(['company_name', 'missing_key', 'payroll_absent_rate']);
assertSameValue(['company_name' => 'TP Asset', 'payroll_absent_rate' => '750'], $many, 'getSystemMany omits empty defaults');

echo "SettingsService regression fixtures passed." . PHP_EOL;
