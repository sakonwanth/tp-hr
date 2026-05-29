<?php
/**
 * Output a single slip calculation as JSON (tp-hr PayrollService::calculateSlip).
 *
 * Usage: php scripts/payroll_calc_single.php USER_ID YYYY-MM-DD PAY_DAY
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/Services/PayrollService.php';

$userId = (int)($argv[1] ?? 0);
$monthFirst = trim((string)($argv[2] ?? ''));
$payDay = (int)($argv[3] ?? 0);

if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $monthFirst) || $payDay < 1 || $payDay > 31) {
    fwrite(STDERR, "Usage: php scripts/payroll_calc_single.php USER_ID YYYY-MM-DD PAY_DAY\n");
    exit(2);
}

try {
    $pdo = Database::getInstance()->getConnection();
    $service = new PayrollService($pdo);
    $slip = $service->calculateSlip($userId, $monthFirst, $payDay);
    echo json_encode($slip, JSON_UNESCAPED_UNICODE);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
