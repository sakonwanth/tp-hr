<?php
/** Ensure concurrency protection for pending offsite attendance requests. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/bootstrap.php';
$pdo = getDB();
$dbName = defined('DB_NAME') ? DB_NAME : (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

foreach (['check_in_outside_status', 'check_outside_status'] as $statusColumn) {
    $statusCheck = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='hr_attendances' AND COLUMN_NAME=?");
    $statusCheck->execute([$dbName, $statusColumn]);
    if (!$statusCheck->fetchColumn()) {
        $after = $statusColumn === 'check_in_outside_status' ? 'offsite_status' : 'check_in_outside_status';
        $pdo->exec("ALTER TABLE hr_attendances ADD COLUMN {$statusColumn} ENUM('PENDING','APPROVED','REJECTED') NULL AFTER {$after}");
        echo "Added {$statusColumn}.\n";
    }
}

$pdo->exec("UPDATE hr_attendances SET check_in_outside_status=COALESCE((SELECT o.status FROM hr_attendance_outside_requests o WHERE o.attendance_id=hr_attendances.id AND o.request_type='CHECK_IN' AND o.status IN ('PENDING','APPROVED','REJECTED') ORDER BY o.id DESC LIMIT 1),offsite_status) WHERE is_offsite=1 AND check_in_outside_status IS NULL");
$pdo->exec("UPDATE hr_attendances SET check_outside_status=COALESCE((SELECT o.status FROM hr_attendance_outside_requests o WHERE o.attendance_id=hr_attendances.id AND o.request_type='CHECK_OUT' AND o.status IN ('PENDING','APPROVED','REJECTED') ORDER BY o.id DESC LIMIT 1),offsite_status) WHERE is_offsite=1 AND check_outside_status IS NULL");

$column = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='hr_attendance_outside_requests' AND COLUMN_NAME='pending_request_key'");
$column->execute([$dbName]);
if (!$column->fetchColumn()) {
    $pdo->exec("
        ALTER TABLE hr_attendance_outside_requests
        ADD COLUMN pending_request_key VARCHAR(96)
        GENERATED ALWAYS AS (
            CASE WHEN status = 'PENDING'
                 THEN CONCAT(user_id, ':', request_date, ':', request_type)
                 ELSE NULL END
        ) STORED
    ");
    echo "Added pending_request_key.\n";
}

$index = $pdo->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME='hr_attendance_outside_requests' AND INDEX_NAME='uk_outside_pending_request'");
$index->execute([$dbName]);
if (!$index->fetchColumn()) {
    $duplicates = $pdo->query("
        SELECT user_id, request_date, request_type, COUNT(*) AS total
        FROM hr_attendance_outside_requests
        WHERE status='PENDING'
        GROUP BY user_id, request_date, request_type
        HAVING COUNT(*) > 1
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    if ($duplicates) {
        throw new RuntimeException('Cannot create pending request unique index: duplicate PENDING requests exist: ' . json_encode($duplicates, JSON_UNESCAPED_UNICODE));
    }
    $pdo->exec("CREATE UNIQUE INDEX uk_outside_pending_request ON hr_attendance_outside_requests (pending_request_key)");
    echo "Added uk_outside_pending_request.\n";
}

echo "Outside attendance schema ready.\n";
