<?php
/**
 * Run one SQL migration using the application's own database credentials.
 *
 *   php scripts/run_migration.php 2026_08_07_hr_push_subscriptions.sql
 *   php scripts/run_migration.php --list
 *
 * Exists because the shared tp_crm database is not always reachable through
 * the hosting panel — Plesk's stored phpMyAdmin password can drift out of
 * sync with MySQL while the app itself keeps connecting fine. This runs the
 * file through the same PDO connection the app uses, so nobody has to handle
 * a password to apply a migration.
 *
 * CLI only; scripts/ is denied over HTTP by .htaccess and
 * deploy/nginx-deny-internal-paths.conf.
 *
 * No rollback and no state tracking: migrations here are written to be
 * additive and re-runnable (CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT
 * EXISTS). Running one twice is a no-op; that is the safety model.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap.php';

$migrationsDir = BASE_PATH . '/database/migrations';
$arg = $argv[1] ?? '';

if ($arg === '' || $arg === '--list') {
    $files = glob($migrationsDir . '/*.sql') ?: [];
    sort($files);
    echo "Migrations in database/migrations:\n";
    foreach ($files as $file) {
        echo '  ' . basename($file) . "\n";
    }
    echo "\nUsage: php scripts/run_migration.php <filename.sql>\n";
    exit($arg === '--list' ? 0 : 1);
}

// basename() so a filename can never escape the migrations directory.
$name = basename($arg);
$path = $migrationsDir . '/' . $name;

if (substr($name, -4) !== '.sql' || !is_file($path)) {
    fwrite(STDERR, "Migration not found: $name\n");
    fwrite(STDERR, "Run with --list to see what is available.\n");
    exit(1);
}

$sql = (string)file_get_contents($path);

/**
 * Split into statements. Quote-aware so a semicolon inside a string literal
 * or a COMMENT '...' does not split a statement in half.
 */
function tp_hr_split_sql(string $sql): array
{
    $statements = [];
    $current = '';
    $quote = null;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];

        if ($quote !== null) {
            $current .= $char;
            if ($char === '\\' && $i + 1 < $length) {
                $current .= $sql[++$i]; // escaped char, cannot close the quote
            } elseif ($char === $quote) {
                $quote = null;
            }
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $current .= $char;
            continue;
        }

        // Line comment: `--` followed by whitespace or end of line. The
        // trailing-space form alone misses the bare `--` separator lines our
        // migrations use, which then leak into the statement text.
        if ($char === '-' && substr($sql, $i, 2) === '--'
            && ($i + 2 >= $length || preg_match('/\s/', $sql[$i + 2]) === 1)) {
            $newline = strpos($sql, "\n", $i);
            if ($newline === false) break;
            $i = $newline;
            $current .= "\n";
            continue;
        }

        if ($char === ';') {
            $statements[] = $current;
            $current = '';
            continue;
        }

        $current .= $char;
    }

    if (trim($current) !== '') {
        $statements[] = $current;
    }

    return array_values(array_filter($statements, fn($s) => trim($s) !== ''));
}

$statements = tp_hr_split_sql($sql);

if ($statements === []) {
    fwrite(STDERR, "No statements found in $name\n");
    exit(1);
}

echo "Running $name (" . count($statements) . " statement(s))\n";

$pdo = Database::getInstance()->getConnection();
$applied = 0;

foreach ($statements as $index => $statement) {
    $preview = preg_replace('/\s+/', ' ', trim($statement));
    $preview = mb_substr($preview, 0, 90);

    try {
        $pdo->exec($statement);
        $applied++;
        echo '  [ok]   ' . ($index + 1) . '. ' . $preview . "\n";
    } catch (Throwable $e) {
        echo '  [FAIL] ' . ($index + 1) . '. ' . $preview . "\n";
        fwrite(STDERR, "\nMigration aborted: " . $e->getMessage() . "\n");
        fwrite(STDERR, "Statements already applied are NOT rolled back — DDL is not transactional in MySQL.\n");
        exit(1);
    }
}

echo "\nDone — $applied statement(s) applied.\n";
