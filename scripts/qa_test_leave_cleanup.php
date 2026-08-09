<?php
/**
 * Find and remove leave requests that were created to test the system.
 *
 *   php scripts/qa_test_leave_cleanup.php                # list candidates only
 *   php scripts/qa_test_leave_cleanup.php --delete 12,15 # delete those ids
 *
 * Listing is the default and never changes anything. Deletion takes explicit
 * ids — never a pattern — because "looks like a test" is a guess and these are
 * real employee records.
 *
 * Only REJECTED and CANCELLED requests can be deleted. Their leave balance was
 * already settled when the decision was recorded, so removing the row leaves
 * the balance correct. PENDING still holds reserved days, and APPROVED has
 * both consumed days and synced attendance rows behind it — deleting either
 * would silently corrupt someone's entitlement.
 *
 * CLI only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap.php';

const DELETABLE_STATUSES = ['REJECTED', 'CANCELLED'];

/**
 * Words that suggest a request was made to exercise the system.
 *
 * 'ตรวจสอบ' earns its place: a real test request used it and the first version
 * of this list missed the row entirely, which is exactly the failure mode that
 * makes a pattern search untrustworthy on its own — hence deletion by id.
 */
const TEST_PATTERNS = ['ทดสอบ', 'ตรวจสอบ', 'เทส', 'test', 'Test', 'TEST', 'ลองระบบ'];

$pdo = Database::getInstance()->getConnection();
$mode = $argv[1] ?? '';

function fetchRequests(PDO $pdo, string $where, array $params): array
{
    $sql = "SELECT lr.id, lr.request_number, lr.user_id, lr.status, lr.reason,
                   lr.start_date, lr.end_date, lr.total_days, lr.created_at,
                   lt.name AS leave_type_name,
                   TRIM(CONCAT(COALESCE(u.first_name_th,''), ' ', COALESCE(u.last_name_th,''))) AS employee
            FROM hr_leave_requests lr
            LEFT JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
            LEFT JOIN users u ON lr.user_id = u.id
            WHERE $where
            ORDER BY lr.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function printRequest(array $r): void
{
    printf(
        "  id=%-6d %-14s %-10s %s\n",
        $r['id'],
        $r['request_number'] ?: '-',
        $r['status'],
        in_array($r['status'], DELETABLE_STATUSES, true) ? '(deletable)' : '(NOT deletable — balance still held)'
    );
    printf(
        "         %s · %s · %s → %s (%s วัน)\n",
        $r['employee'] ?: ('user_id ' . $r['user_id']),
        $r['leave_type_name'] ?: '-',
        $r['start_date'],
        $r['end_date'],
        rtrim(rtrim((string)$r['total_days'], '0'), '.')
    );
    printf("         reason: %s\n", mb_substr(trim((string)$r['reason']), 0, 90) ?: '-');
    printf("         created: %s\n\n", $r['created_at']);
}

// ------------------------------------------------------------------ delete

if ($mode === '--delete') {
    $idArg = $argv[2] ?? '';
    $ids = array_values(array_filter(array_map('intval', explode(',', $idArg)), fn($i) => $i > 0));

    if ($ids === []) {
        fwrite(STDERR, "Usage: php scripts/qa_test_leave_cleanup.php --delete 12,15\n");
        fwrite(STDERR, "Run without arguments first to see the ids.\n");
        exit(1);
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = fetchRequests($pdo, "lr.id IN ($in)", $ids);

    $found = array_column($rows, 'id');
    $missing = array_diff($ids, array_map('intval', $found));
    if ($missing !== []) {
        echo 'Not found (already gone?): ' . implode(', ', $missing) . "\n\n";
    }

    $deletable = [];
    $blocked = [];
    foreach ($rows as $r) {
        if (in_array($r['status'], DELETABLE_STATUSES, true)) {
            $deletable[] = (int)$r['id'];
        } else {
            $blocked[] = $r;
        }
    }

    echo "About to delete:\n\n";
    foreach ($rows as $r) {
        if (in_array((int)$r['id'], $deletable, true)) {
            printRequest($r);
        }
    }

    if ($blocked !== []) {
        echo "REFUSED — these are not REJECTED or CANCELLED:\n\n";
        foreach ($blocked as $r) {
            printRequest($r);
        }
        echo "  A PENDING request still reserves days in hr_leave_entitlements, and an\n";
        echo "  APPROVED one has consumed days plus synced hr_attendances rows. Cancel or\n";
        echo "  reject them through the app first — that releases the balance properly —\n";
        echo "  then delete them here.\n\n";
    }

    if ($deletable === []) {
        echo "Nothing deleted.\n";
        exit(1);
    }

    $in = implode(',', array_fill(0, count($deletable), '?'));
    $stmt = $pdo->prepare("DELETE FROM hr_leave_requests WHERE id IN ($in) AND status IN ('REJECTED','CANCELLED')");
    $stmt->execute($deletable);

    printf("Deleted %d request(s).\n", $stmt->rowCount());
    exit(0);
}

// -------------------------------------------------------------------- list

$where = [];
$params = [];
foreach (TEST_PATTERNS as $i => $pattern) {
    $where[] = "lr.reason LIKE :p$i";
    $params["p$i"] = '%' . $pattern . '%';
}

$rows = fetchRequests($pdo, '(' . implode(' OR ', $where) . ')', $params);

echo "TP-HR — leave requests that look like tests (read only)\n";
echo str_repeat('=', 66) . "\n\n";

if ($rows === []) {
    echo "None found. Searched the reason field for: " . implode(', ', TEST_PATTERNS) . "\n";
    exit(0);
}

$deletable = array_values(array_filter($rows, fn($r) => in_array($r['status'], DELETABLE_STATUSES, true)));

// The command goes FIRST. Plesk's task output is truncated, and putting the
// one line the operator actually needs at the bottom means they never see it.
printf("%d found, %d deletable.\n\n", count($rows), count($deletable));

if ($deletable !== []) {
    echo ">>> To delete, set the task arguments to:\n\n";
    echo '      --delete ' . implode(',', array_column($deletable, 'id')) . "\n\n";
    echo "Check the list below first — a genuine request whose reason happens to\n";
    echo "mention a test matches this search too.\n\n";
}

echo str_repeat('-', 66) . "\n\n";

foreach ($rows as $r) {
    printRequest($r);
}
