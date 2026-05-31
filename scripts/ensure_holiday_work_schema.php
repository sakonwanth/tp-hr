#!/usr/bin/env php
<?php
/**
 * Idempotent schema ensure for hr_holiday_work_exceptions.
 *
 * Usage: php scripts/ensure_holiday_work_schema.php
 */

if (PHP_SAPI !== 'cli') {
    die('Run from CLI only');
}

require_once dirname(__DIR__) . '/bootstrap.php';

try {
    $pdo = getDB();
} catch (Throwable $e) {
    echo 'DB connection failed: ' . $e->getMessage() . "\n";
    exit(1);
}

$exists = (int) $pdo->query("
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'hr_holiday_work_exceptions'
")->fetchColumn();

if ($exists > 0) {
    echo "hr_holiday_work_exceptions already exists. Skip.\n";
    exit(0);
}

$migration = dirname(__DIR__) . '/database/migrations/2026_05_31_hr_holiday_work_exceptions.sql';
if (!is_file($migration)) {
    echo "Migration file missing: {$migration}\n";
    exit(1);
}

$sql = file_get_contents($migration);
try {
    $pdo->exec($sql);
    echo "OK: created hr_holiday_work_exceptions\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS _migrations_run (
        filename VARCHAR(255) PRIMARY KEY,
        run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->prepare('INSERT IGNORE INTO _migrations_run (filename) VALUES (?)')
        ->execute(['2026_05_31_hr_holiday_work_exceptions.sql']);
    exit(0);
} catch (Throwable $e) {
    echo 'FAIL: ' . $e->getMessage() . "\n";
    exit(1);
}
