#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/bootstrap.php';

$pdo = getDB();
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$column = $pdo->prepare(
    "SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME = 'attendance_exempt'
     LIMIT 1"
);
$column->execute([$dbName]);

if (!$column->fetchColumn()) {
    $pdo->exec(
        "ALTER TABLE users
         ADD COLUMN attendance_exempt TINYINT(1) NOT NULL DEFAULT 0
         COMMENT '1 = exempt from check-in/out and absence deductions'
         AFTER is_employee"
    );
    echo "Added users.attendance_exempt\n";
}

$stmt = $pdo->prepare(
    "UPDATE users
     SET attendance_exempt = 1
     WHERE TRIM(COALESCE(first_name_th, '')) = ?
       AND TRIM(COALESCE(last_name_th, '')) = ?
       AND attendance_exempt <> 1"
);
$stmt->execute(['เข็มทอง', 'บำรุงจิตร']);

echo "Attendance exemption schema ready; designated exceptions updated=" . $stmt->rowCount() . "\n";
