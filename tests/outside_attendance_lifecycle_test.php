<?php
/**
 * Isolated SQLite lifecycle regression. No application bootstrap, real users,
 * photos, HTTP calls or notifications. FOR UPDATE is stripped by the adapter;
 * MySQL concurrency is not simulated. Uses the real service/calculator code.
 */
require __DIR__ . '/attendance_service_test.php';
class PayrollService {
    public function __construct(PDO $pdo) {}
    public function buildWorkdayContext(...$args): array { return []; }
    public function isPayrollWorkday(array $ctx, string $date): bool { return true; }
}
class FixturePDO extends PDO {
    public function __construct() {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->sqliteCreateFunction('NOW', fn() => '2026-10-23 20:00:00');
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
function apiError(string $message, int $status = 400): never { throw new RuntimeException($message, $status); }
// Load the production class body without its optional notification bridge.
$serviceSource = file_get_contents(dirname(__DIR__) . '/core/Services/OutsideAttendanceService.php');
eval(substr($serviceSource, strpos($serviceSource, 'class OutsideAttendanceException')));
$sources = [dirname(__DIR__) . '/api/attendance.php' => 'stampPendingOutsideAttendance'];
$checkinSource = dirname(__DIR__, 2) . '/tp-checkin/api/attendance.php';
if (is_file($checkinSource)) $sources[$checkinSource] = 'checkinStampPendingOutsideAttendance';
foreach ($sources as $path => $function) {
    $source = file_get_contents($path);
    $start = strpos($source, 'function ' . $function . '(');
    $end = strpos($source, "\n/**", $start);
    eval(substr($source, $start, $end - $start));
    if (str_contains($source, 'ยังรออนุมัติ จึงยังลงเวลาออกไม่ได้')) throw new RuntimeException('Pending checkout blocker returned');
    if (!str_contains($source, "'request_time' => \$requestTime")) throw new RuntimeException('Missing captured response time');
}
function fixture(): FixturePDO {
    $pdo = new FixturePDO();
    $pdo->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT);
        CREATE TABLE users (id INTEGER PRIMARY KEY, employee_code TEXT, work_mode TEXT, attendance_exempt INTEGER, role_id INTEGER, is_active INTEGER);
        INSERT INTO roles VALUES (1,'employee');
        INSERT INTO users VALUES (1,'TEST','OFFICE',0,1,1);
        CREATE TABLE hr_work_shifts (id INTEGER PRIMARY KEY, start_time TEXT, end_time TEXT, grace_period_minutes INTEGER, break_minutes INTEGER, work_hours_per_day INTEGER, is_default INTEGER, is_active INTEGER);
        INSERT INTO hr_work_shifts VALUES (1,'08:30:00','17:30:00',15,60,8,1,1);
        CREATE TABLE hr_attendances (
            id INTEGER PRIMARY KEY, user_id INTEGER, attendance_date TEXT, shift_id INTEGER,
            check_in_time TEXT, check_out_time TEXT, check_in_type TEXT, check_out_type TEXT,
            check_in_latitude REAL, check_in_longitude REAL, check_out_latitude REAL, check_out_longitude REAL,
            check_in_location_id INTEGER, check_out_location_id INTEGER, check_in_photo TEXT, check_out_photo TEXT,
            check_in_ip TEXT, check_out_ip TEXT, is_offsite INTEGER, offsite_status TEXT, offsite_reason TEXT,
            check_in_outside_status TEXT, check_outside_status TEXT, status TEXT, late_minutes INTEGER DEFAULT 0,
            work_minutes INTEGER DEFAULT 0, break_minutes INTEGER DEFAULT 0, ot_minutes INTEGER DEFAULT 0,
            early_leave_minutes INTEGER DEFAULT 0, planned_status TEXT, planned_start_time TEXT,
            offsite_approved_by INTEGER, offsite_approved_at TEXT, offsite_remarks TEXT, updated_at TEXT,
            UNIQUE(user_id,attendance_date));
        CREATE TABLE hr_attendance_outside_requests (
            id INTEGER PRIMARY KEY, user_id INTEGER, attendance_id INTEGER, request_type TEXT,
            request_date TEXT, request_time TEXT, latitude REAL, longitude REAL, photo_path TEXT,
            reason TEXT, request_ip TEXT, status TEXT, reviewed_by INTEGER, reviewed_at TEXT,
            review_remarks TEXT, updated_at TEXT)");
    return $pdo;
}
function row(PDO $pdo): array { return $pdo->query('SELECT * FROM hr_attendances WHERE id=1')->fetch(); }
function submit(PDO $pdo, string $stamp, string $type, string $time): int {
    $pdo->beginTransaction();
    try {
        $id = $stamp($pdo, ['id'=>1], $type, $type === 'CHECK_OUT' ? 1 : null, '2026-10-22', $time, 13.75, 100.5, 'fixture.jpg', 'fixture offsite');
        $pdo->prepare("INSERT INTO hr_attendance_outside_requests (user_id,attendance_id,request_type,request_date,request_time,status) VALUES (1,?,?, '2026-10-22',?,'PENDING')")
            ->execute([$id, $type, $time]);
        $requestId = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $requestId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
function blocked(callable $action, string $label): void {
    try { $action(); } catch (RuntimeException $e) { echo "[OK] $label\n"; return; }
    throw new RuntimeException("Expected rejection: $label");
}
foreach ($sources as $stamp) {
    echo "Testing $stamp\n";
    foreach (['in-first','out-first','reject-in-first','reject-in-after-out','reject-out-first','reject-out-after-in'] as $order) {
        $pdo = fixture();
        $service = new OutsideAttendanceService($pdo);
        blocked(fn() => submit($pdo,$stamp,'CHECK_OUT','2026-10-22 17:30:00'), 'no checkout without any entry');
        $in = submit($pdo,$stamp,'CHECK_IN','2026-10-22 08:30:00');
        $out = submit($pdo,$stamp,'CHECK_OUT','2026-10-22 17:30:00');
        assertSameValue('PENDING', row($pdo)['check_in_outside_status'], 'entry still pending after checkout');
        assertSameValue('2026-10-22 17:30:00', row($pdo)['check_out_time'], 'checkout stamped immediately');
        blocked(fn() => submit($pdo,$stamp,'CHECK_OUT','2026-10-22 18:00:00'), 'duplicate checkout cannot overwrite capture');
        if ($order === 'in-first' || $order === 'reject-out-after-in') {
            $service->approve($in, 99);
        } elseif ($order === 'out-first' || $order === 'reject-in-after-out') {
            $service->approve($out, 99);
        } elseif ($order === 'reject-in-first') {
            $service->reject($in, 99, 'fixture rejection');
        } else {
            $service->reject($out, 99, 'fixture rejection');
        }
        assertSameValue(0, row($pdo)['work_minutes'], "$order waits for confirmed pair");
        assertSameValue('PENDING', row($pdo)['offsite_status'], "$order retains remaining pending state");
        match ($order) {
            'in-first','reject-in-first' => $service->approve($out,99),
            'out-first','reject-out-first' => $service->approve($in,99),
            'reject-in-after-out' => $service->reject($in,99,'fixture rejection'),
            'reject-out-after-in' => $service->reject($out,99,'fixture rejection'),
        };
        $r = row($pdo);
        $rejected = str_starts_with($order,'reject');
        assertSameValue($rejected ? 0 : 480, $r['work_minutes'], "$order final work minutes");
        assertSameValue($rejected ? 'REJECTED' : 'APPROVED', $r['offsite_status'], "$order final status");
        assertSameValue(0, $r['late_minutes'], "$order not late due to next-day approval");
        assertSameValue(str_starts_with($order,'reject-in') ? null : '2026-10-22 08:30:00', $r['check_in_time'], "$order retains accepted entry");
        assertSameValue(str_starts_with($order,'reject-out') ? null : '2026-10-22 17:30:00', $r['check_out_time'], "$order retains accepted exit");
        assertSameValue(['2026-10-22 08:30:00','2026-10-22 17:30:00'], $pdo->query('SELECT request_time FROM hr_attendance_outside_requests ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), "$order audit capture remains unchanged");
        blocked(fn() => $service->approve($out,99), 'duplicate approval blocked');
        if (str_starts_with($order,'reject-in')) blocked(fn() => submit($pdo,$stamp,'CHECK_IN','2026-10-22 19:00:00'), 'rejected entry with exit needs correction, not a later entry');
    }
    $pdo = fixture();
    $service = new OutsideAttendanceService($pdo);
    $in = submit($pdo,$stamp,'CHECK_IN','2026-10-22 08:30:00');
    // Simulate an ordinary in-office checkout, which does not require approval.
    $pdo->exec("UPDATE hr_attendances SET check_out_time='2026-10-22 17:30:00'");
    $service->approve($in,99);
    assertSameValue(480,row($pdo)['work_minutes'],'entry approval recalculates ordinary checkout');
    $pdo = fixture();
    $service = new OutsideAttendanceService($pdo);
    $in = submit($pdo,$stamp,'CHECK_IN','2026-10-22 08:30:00');
    $pdo->exec("UPDATE hr_attendances SET check_in_time='2026-10-22 09:00:00'");
    blocked(fn() => $service->approve($in,99),'approval cannot overwrite later HR correction');
    assertSameValue('2026-10-22 09:00:00',row($pdo)['check_in_time'],'HR correction preserved');
}
echo "Outside attendance lifecycle fixtures passed.\n";
