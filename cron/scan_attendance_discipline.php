#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/core/Services/AttendanceDisciplineService.php';

try {
    $month = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__ ? ($argv[1] ?? null) : null;
    $result = (new AttendanceDisciplineService(Database::getInstance()->getConnection()))->detect($month ?: null);
    echo 'attendance_discipline created=' . $result['created'] . ' existing=' . $result['existing'] . "\n";
} catch (Throwable $e) {
    fwrite(STDERR,'attendance_discipline failed: '.$e->getMessage()."\n");
    exit(1);
}
