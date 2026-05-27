<?php
/**
 * External API v1 — router
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$prefix = '/api/v1/';
$pos = strpos($path, $prefix);
$route = $pos === false ? '' : trim(substr($path, $pos + strlen($prefix)), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($route === 'ping' && $method === 'GET') {
    ApiAuth::start();
    ApiAuth::success(['message' => 'pong', 'time' => date('c')]);
}

$segments = $route === '' ? [] : explode('/', $route);
$resource = $segments[0] ?? '';

$metaResources = [
    'departments', 'positions', 'holidays', 'leave-types',
    'employee-schedules', 'announcements', 'leave-entitlements',
];

try {
    switch ($resource) {
        case 'employees':
            require __DIR__ . '/employees.php';
            break;
        case 'attendance':
            require __DIR__ . '/attendance.php';
            break;
        case 'leave':
            require __DIR__ . '/leave.php';
            break;
        case 'dayoff-requests':
            require __DIR__ . '/dayoff.php';
            break;
        case 'overtime':
            require __DIR__ . '/overtime.php';
            break;
        case 'outside-attendance':
            require __DIR__ . '/outside.php';
            break;
        case 'attendance-adjustments':
            require __DIR__ . '/adjustments.php';
            break;
        case 'payroll-runs':
        case 'payslips':
            if ($method === 'GET') {
                require __DIR__ . '/payroll.php';
            } else {
                require __DIR__ . '/payroll_write.php';
            }
            break;
        case 'salary-setup':
            require __DIR__ . '/payroll_write.php';
            break;
        case 'payroll-preview':
            require __DIR__ . '/payroll_write.php';
            break;
        default:
            if (in_array($resource, $metaResources, true)) {
                require __DIR__ . '/hr_meta.php';
            } else {
                ApiAuth::start();
                ApiAuth::fail(404, 'Endpoint not found');
            }
    }
} catch (Throwable $e) {
    tpHrLogException($e, 'api/v1/index');
    ApiAuth::fail(500, 'Internal server error');
}
