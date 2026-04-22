<?php
/**
 * Cron: auto-stamp WFH attendance for all active WFH users for today.
 * Runs daily (recommended: 00:05). Idempotent & safe to re-run.
 *
 * Usage (Plesk cron):
 *   /opt/plesk/php/8.4/bin/php /var/www/vhosts/tp-asset.com/hr.tp-asset.com/cron/stamp_wfh.php
 */
require_once dirname(__DIR__) . '/bootstrap.php';

$date = $argv[1] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fwrite(STDERR, "Invalid date: $date\n");
    exit(1);
}

$count = WfhStamp::ensureAllForDate($date);
echo "[" . date('c') . "] WFH auto-stamped: {$count} users for {$date}\n";
