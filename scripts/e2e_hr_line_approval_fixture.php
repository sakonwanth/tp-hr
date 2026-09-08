<?php

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once dirname(__DIR__).'/bootstrap.php';

$pdo = Database::getInstance()->getConnection();
$action = (string)($argv[1] ?? '');
$marker = '[CODEX-E2E-HR-LINE-20260908]';

if ($action === '--create') {
    $created = false;
    $existing = $pdo->prepare("SELECT id FROM hr_dayoff_requests WHERE reason LIKE ? AND status='PENDING' ORDER BY id DESC LIMIT 1");
    $existing->execute([$marker.'%']);
    $id = (int)($existing->fetchColumn() ?: 0);
    if ($id <= 0) {
        $user = $pdo->query("SELECT u.id,COALESCE(s.day_off,0) day_off FROM users u LEFT JOIN hr_employee_schedules s ON s.user_id=u.id JOIN roles r ON r.id=u.role_id WHERE u.is_active=1 AND r.name NOT IN ('Admin','Chairman','CEO') ORDER BY u.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$user) { fwrite(STDERR,"No eligible fixture employee\n"); exit(1); }
        $original = (int)$user['day_off'];
        $requested = ($original + 1) % 7;
        $stmt = $pdo->prepare("INSERT INTO hr_dayoff_requests(user_id,week_start,week_end,original_day_off,requested_day_off,reason,status) VALUES (?,'2099-12-28','2100-01-03',?,?,?,'PENDING')");
        $stmt->execute([(int)$user['id'],$original,$requested,$marker.' isolated production acceptance fixture']);
        $id = (int)$pdo->lastInsertId();
        $created = true;
    }
    if ($created) crm_line_notify_dayoff_requested($pdo,$id);
    $log = $pdo->query("SELECT status,payload_type,created_at FROM line_notification_log WHERE event='hr.dayoff_requested' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    echo "E2E_FIXTURE_ID={$id}; status=PENDING; notification=".($created ? 'dispatched' : 'kept').'; delivery_status='.($log['status'] ?? 'missing').'; payload_type='.($log['payload_type'] ?? 'missing')."\n";
    exit(0);
}

if ($action === '--cleanup') {
    $id = (int)($argv[2] ?? 0);
    $stmt = $pdo->prepare("UPDATE hr_dayoff_requests SET status='CANCELLED',review_note=CONCAT_WS(' | ',NULLIF(review_note,''),?),updated_at=NOW() WHERE id=? AND reason LIKE ?");
    $stmt->execute(['E2E completed and neutralized',$id,$marker.'%']);
    echo "E2E_FIXTURE_ID={$id}; cleanup_rows={$stmt->rowCount()}\n";
    exit($stmt->rowCount() === 1 ? 0 : 1);
}

fwrite(STDERR,"Usage: php scripts/e2e_hr_line_approval_fixture.php --create|--cleanup ID\n");
exit(2);
