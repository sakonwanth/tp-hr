#!/usr/bin/env php
<?php
/**
 * Run pending SQL migrations from database/migrations/
 *
 * Uses TpCommon\Database\MigrationRunner when available,
 * falls back to inline logic otherwise.
 *
 * Usage: php scripts/run_migrations.php [--dry-run] [--pending] [--status]
 */

if (php_sapi_name() !== 'cli') {
    die('Run from CLI only: php scripts/run_migrations.php');
}

$baseDir = dirname(__DIR__);
require_once $baseDir . '/bootstrap.php';

if (!defined('DB_HOST') || !defined('DB_NAME')) {
    echo "DB not configured. Skip migrations.\n";
    exit(0);
}

$migrationsDir = $baseDir . '/database/migrations';
if (!is_dir($migrationsDir)) {
    echo "No database/migrations folder. Skip.\n";
    exit(0);
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . (defined('DB_PORT') ? DB_PORT : 3306) . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    echo "DB connection failed: " . $e->getMessage() . " Skip migrations.\n";
    exit(0);
}

$dryRun  = in_array('--dry-run', $argv);
$pending = in_array('--pending', $argv);
$status  = in_array('--status', $argv);

// TpCommon path
if (class_exists('TpCommon\\Database\\MigrationRunner')) {
    $runner = new TpCommon\Database\MigrationRunner($pdo, $migrationsDir);

    if ($pending) {
        $list = $runner->pending();
        echo count($list) . " pending migration(s):\n";
        foreach ($list as $f) { echo "  - $f\n"; }
        exit(0);
    }
    if ($status) {
        $list = $runner->applied();
        echo count($list) . " applied migration(s):\n";
        foreach ($list as $row) { echo "  {$row['filename']}  ({$row['run_at']})\n"; }
        exit(0);
    }

    $ok = $runner->run($dryRun);
    exit($ok ? 0 : 1);
}

// Fallback: inline runner
$pdo->exec("CREATE TABLE IF NOT EXISTS _migrations_run (
    filename VARCHAR(255) PRIMARY KEY,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$check = $pdo->prepare("SELECT 1 FROM _migrations_run WHERE filename = ?");
$stmt  = $pdo->prepare("INSERT IGNORE INTO _migrations_run (filename) VALUES (?)");

$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files);
$run = 0;
$skipped = 0;

foreach ($files as $path) {
    $filename = basename($path);
    $check->execute([$filename]);
    if ($check->fetchColumn()) { $skipped++; continue; }

    $sql = file_get_contents($path);
    if (trim($sql) === '') { $stmt->execute([$filename]); continue; }

    $sql = preg_replace('/--[^\n]*\n/', "\n", $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)), fn($s) => strlen($s) > 5);

    try {
        foreach ($statements as $one) {
            if (strlen(trim($one)) < 5) continue;
            $pdo->exec($one . ';');
        }
        $stmt->execute([$filename]);
        $run++;
        echo "OK: $filename\n";
    } catch (Throwable $e) {
        echo "FAIL: $filename - " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "Migrations: $run run, $skipped already applied.\n";
exit(0);
