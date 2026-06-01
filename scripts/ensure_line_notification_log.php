#!/usr/bin/env php
<?php
/**
 * Idempotent ensure line_notification_log (shared with tp-crm LineNotifier).
 *
 * Usage: php scripts/ensure_line_notification_log.php
 */

if (PHP_SAPI !== 'cli') {
    die("CLI only\n");
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
    WHERE table_schema = DATABASE() AND table_name = 'line_notification_log'
")->fetchColumn();

if ($exists > 0) {
    echo "line_notification_log already exists. Skip.\n";
    exit(0);
}

$candidates = [
    dirname(__DIR__) . '/database/migrations/2026_04_22_line_notification_log.sql',
    dirname(__DIR__, 2) . '/tp-crm/migrations/2026_04_22_line_notification_log.sql',
];
if (defined('TP_CRM_PATH') && TP_CRM_PATH) {
    $candidates[] = TP_CRM_PATH . '/migrations/2026_04_22_line_notification_log.sql';
}

$sql = '';
foreach ($candidates as $path) {
    if (is_readable($path)) {
        $sql = (string) file_get_contents($path);
        break;
    }
}

if ($sql === '') {
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS line_notification_log (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module        VARCHAR(32)  NOT NULL DEFAULT 'hr',
    event         VARCHAR(64)  NOT NULL,
    target_type   VARCHAR(16)  NOT NULL,
    target_user_id INT UNSIGNED NULL,
    line_user_id  VARCHAR(64)  NULL,
    payload_type  ENUM('text','flex') NOT NULL DEFAULT 'text',
    alt_text      VARCHAR(255) NULL,
    status        ENUM('sent','failed','skipped') NOT NULL DEFAULT 'sent',
    error_message VARCHAR(500) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lnl_created (created_at),
    KEY idx_lnl_module_event (module, event),
    KEY idx_lnl_user (target_user_id),
    KEY idx_lnl_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
}

try {
    $pdo->exec($sql);
    echo "OK: created line_notification_log\n";
    exit(0);
} catch (Throwable $e) {
    echo 'FAIL: ' . $e->getMessage() . "\n";
    exit(1);
}
