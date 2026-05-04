#!/usr/bin/env php
<?php
/**
 * Sync Thailand public holidays from the configured API.
 *
 * Usage:
 *   php cron/sync_thai_holidays.php
 *   php cron/sync_thai_holidays.php --from=2024 --to=2027
 *
 * Default range: previous year through next year.
 */

if (PHP_SAPI !== 'cli') {
    die('Run from CLI only');
}

require_once __DIR__ . '/../bootstrap.php';

$currentYear = (int)date('Y');
$from = $currentYear - 1;
$to = $currentYear + 1;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--from=')) {
        $from = (int)substr($arg, 7);
    } elseif (str_starts_with($arg, '--to=')) {
        $to = (int)substr($arg, 5);
    }
}

$from = max(2000, min(2100, $from));
$to = max(2000, min(2100, $to));

$service = new ThaiHolidaySyncService(getDB());
$result = $service->syncRange($from, $to);

foreach ($result as $year => $count) {
    echo "{$year}: {$count} holiday source row(s) synced\n";
}
