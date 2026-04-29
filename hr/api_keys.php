<?php
/**
 * External API Keys Management (HR Admin)
 * CEO-level only
 */
require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Auth::requireHR();
if (!isCEOOrAbove()) {
    flash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    redirect('/hr/', 302);
}

$pdo = getDB();
$user = Auth::user();
$employeeOptions = $pdo->query("
    SELECT u.id, u.employee_code, u.first_name_th, u.last_name_th
    FROM users u
    WHERE u.id NOT IN (" . SYSTEM_USER_IDS_SQL . ") AND u.is_active = 1
    ORDER BY u.employee_code ASC
")->fetchAll(PDO::FETCH_ASSOC);
$page_title = 'External API Keys';
$current_page = 'hr-api-keys';

$plainKey = null; // shown once after issue
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('CSRF token ไม่ถูกต้อง');
        }
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new Exception('กรุณาระบุชื่อ');
            $scopesRaw = $_POST['scopes'] ?? [];
            $scopes = is_array($scopesRaw) ? array_values(array_filter(array_map('trim', $scopesRaw))) : [];

            $ipsTxt = trim($_POST['allowed_ips'] ?? '');
            $ips = $ipsTxt === '' ? null : array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $ipsTxt))));

            $originsTxt = trim($_POST['allowed_origins'] ?? '');
            $origins = $originsTxt === '' ? null : array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $originsTxt))));

            $rate = max(1, min(1000, (int)($_POST['rate_limit_per_min'] ?? 60)));
            $expires = trim($_POST['expires_at'] ?? '');
            $expires = $expires !== '' ? $expires . ' 23:59:59' : null;
            $notes = trim($_POST['notes'] ?? '') ?: null;

            $serviceUserId = (int) ($_POST['service_user_id'] ?? 0);
            if ($serviceUserId > 0) {
                $chk = $pdo->prepare('SELECT id FROM users WHERE id = ? AND id NOT IN (' . SYSTEM_USER_IDS_SQL . ') AND is_active = 1 LIMIT 1');
                $chk->execute([$serviceUserId]);
                if (!$chk->fetchColumn()) {
                    throw new Exception('พนักงานสำหรับผูกคีย์ไม่ถูกต้องหรือไม่ active');
                }
            } else {
                $serviceUserId = 0;
            }

            $issued = ApiAuth::issue([
                'name' => $name,
                'scopes' => $scopes,
                'allowed_ips' => $ips,
                'allowed_origins' => $origins,
                'rate_limit_per_min' => $rate,
                'expires_at' => $expires,
                'created_by' => (int)$user['id'],
                'notes' => $notes,
                'service_user_id' => $serviceUserId > 0 ? $serviceUserId : null,
            ]);
            $plainKey = $issued['key'];
            Auth::log('api_key_create', 'hr_api_keys', $issued['id'], null, ['name' => $name, 'scopes' => $scopes, 'service_user_id' => $serviceUserId > 0 ? $serviceUserId : null]);
            $success = 'สร้างคีย์สำเร็จ — บันทึกคีย์ด้านล่างทันที ระบบจะไม่แสดงอีก';
        } elseif ($action === 'revoke') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('invalid id');
            $pdo->prepare("UPDATE hr_api_keys SET is_active = 0, revoked_at = NOW(), revoked_by = ? WHERE id = ?")
                ->execute([(int)$user['id'], $id]);
            Auth::log('api_key_revoke', 'hr_api_keys', $id);
            $success = 'ยกเลิกคีย์แล้ว';
        } elseif ($action === 'activate') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE hr_api_keys SET is_active = 1, revoked_at = NULL, revoked_by = NULL WHERE id = ?")
                ->execute([$id]);
            Auth::log('api_key_activate', 'hr_api_keys', $id);
            $success = 'เปิดใช้งานคีย์แล้ว';
        }
    } catch (Throwable $e) {
        tpHrLogException($e, 'hr/api_keys POST');
        if ($e instanceof PDOException) {
            $error = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้งหรือติดต่อผู้ดูแลระบบ';
        } elseif ($e instanceof Exception) {
            $error = $e->getMessage();
        } else {
            $error = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้งหรือติดต่อผู้ดูแลระบบ';
        }
    }
}

$keys = $pdo->query("
    SELECT k.*, c.first_name_th AS c_first, c.last_name_th AS c_last,
           su.employee_code AS su_code, su.first_name_th AS su_first, su.last_name_th AS su_last
    FROM hr_api_keys k
    LEFT JOIN users c ON k.created_by = c.id
    LEFT JOIN users su ON k.service_user_id = su.id
    ORDER BY k.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$recentLogs = $pdo->query("
    SELECT l.*, k.name AS key_name
    FROM hr_api_request_logs l
    LEFT JOIN hr_api_keys k ON k.id = l.api_key_id
    ORDER BY l.id DESC LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

/** @var array<string, array<string, string>> */
$scopeGroups = [
    'พนักงาน' => [
        'employees.read'       => 'อ่านข้อมูลพนักงาน (รายคน / คีย์ผูกพนักงาน)',
        'employees.read_all'   => 'อ่านรายชื่อพนักงานทั้งหมด (ลิสต์ — คีย์ไม่ผูก)',
    ],
    'ลงเวลา' => [
        'attendance.read'      => 'อ่านลงเวลา (ผูกพนักงาน หรือ + read_all)',
        'attendance.read_all'  => 'อ่านลงเวลาทุกคน / กรอง user_id',
        'attendance.write'     => 'เช็คอิน/เอาต์ (ผูกพนักงาน หรือ + write_all)',
        'attendance.write_all' => 'เช็คอิน/เอาต์แทนผู้อื่น',
    ],
    'การลา' => [
        'leave.read'      => 'อ่านใบลา (ผูกพนักงาน หรือ + read_all)',
        'leave.read_all'  => 'อ่านใบลาทุกคน / ตาม id',
        'leave.write'     => 'สร้าง/ยกเลิกใบลา (ผูกพนักงาน หรือ + write_all)',
        'leave.write_all' => 'สร้าง/ยกเลิกแทนผู้อื่น',
        'leave.approve'   => 'อนุมัติ/ปฏิเสธใบลา',
    ],
    'เปลี่ยนวันหยุดประจำสัปดาห์' => [
        'dayoff.read'      => 'อ่านคำขอ (ผูกพนักงาน หรือ + read_all)',
        'dayoff.read_all'  => 'อ่านคำขอทุกคน',
        'dayoff.write'     => 'สร้างคำขอ (ผูกพนักงาน หรือ + write_all)',
        'dayoff.write_all' => 'สร้างคำขอแทนผู้อื่น',
        'dayoff.approve'   => 'อนุมัติ/ปฏิเสธ',
    ],
    'OT' => [
        'overtime.read'      => 'อ่านคำขอ OT (ผูกพนักงาน หรือ + read_all)',
        'overtime.read_all'  => 'อ่านทุกคน',
        'overtime.write'     => 'สร้างคำขอ (ผูกพนักงาน หรือ + write_all)',
        'overtime.write_all' => 'สร้างแทนผู้อื่น',
        'overtime.approve'   => 'อนุมัติ/ปฏิเสธ',
    ],
    'ลงเวลานอกสถานที่' => [
        'outside.read'     => 'อ่านคำขอ (ผูกพนักงาน หรือ + read_all)',
        'outside.read_all' => 'อ่านทุกคน',
        'outside.approve'  => 'อนุมัติ/ปฏิเสธ',
    ],
    'ปรับเวลา' => [
        'adjustments.read'     => 'อ่านคำขอปรับเวลา (ผูกพนักงาน หรือ + read_all)',
        'adjustments.read_all' => 'อ่านทุกคน',
        'adjustments.approve'  => 'อนุมัติ/ปฏิเสธ (ผู้ออกคีย์ต้องเป็น CEO+)',
    ],
    'เงินเดือน' => [
        'payroll.read'     => 'อ่านสลิป/รอบ (ผูกพนักงาน หรือ + read_all)',
        'payroll.read_all' => 'อ่านสลิปและรอบทั้งหมด',
        'payroll.write'    => 'สร้างรอบ คำนวณสลิป ตั้งเงินเดือน (POST)',
        'payroll.approve'  => 'อนุมัติรอบ / บันทึกจ่าย',
    ],
    'Metadata (แผนก วันหยุด ประเภทลา ฯลฯ)' => [
        'hr.read'     => 'อ่านข้อมูลทั่วไป',
        'hr.read_all' => 'กะรายคน + สิทธิ์ลา (คีย์ไม่ผูก)',
    ],
    'พิเศษ' => [
        '*' => 'เข้าถึงทุก scope (ระวัง — ใช้เฉพาะระบบที่ไว้ใจ)',
    ],
];

require_once __DIR__ . '/../templates/header.php';
?>
<div class="tp-hr-admin-stack tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
    <div class="mb-5 md:mb-8 min-w-0">
        <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
            <a href="/hr/index.php" class="hover:text-white touch-manipulation">แดชบอร์ด HR</a>
            <span class="mx-2">/</span>
            <span class="text-white">External API Keys</span>
        </nav>
        <h1 class="tp-ios-page-title flex flex-wrap items-center gap-2">
            <i class="fas fa-key text-violet-400 shrink-0" aria-hidden="true"></i>
            <span>External API Keys</span>
        </h1>
        <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">จัดการคีย์ API สำหรับระบบภายนอก</p>
    </div>

    <?php if ($error): ?>
    <div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-red-500/30 bg-red-500/15 px-4 py-3 text-red-200 text-sm" role="alert">
        <i class="fas fa-exclamation-circle mr-2" aria-hidden="true"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-emerald-200 text-sm" role="status">
        <i class="fas fa-check-circle mr-2" aria-hidden="true"></i><?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <?php if ($plainKey): ?>
    <div class="mb-6 rounded-[var(--tp-ios-card-radius)] border-2 border-amber-400/40 bg-amber-500/10 p-5" role="region" aria-label="คีย์ที่ออกใหม่">
        <div class="text-amber-200 font-semibold mb-3 flex flex-wrap items-center gap-2">
            <i class="fas fa-key" aria-hidden="true"></i>
            <span>API Key ใหม่ — แสดงครั้งเดียว</span>
        </div>
        <div class="flex flex-col sm:flex-row sm:items-stretch gap-2">
            <div id="hr-api-plain-key"
                class="flex-1 bg-black/40 p-3 rounded-[var(--tp-ios-card-radius)] font-mono text-amber-100 text-sm break-all border border-amber-500/20 select-none tracking-wider"
                data-revealed="0"
                aria-label="ค่าคีย์ถูกซ่อน กดแสดงเพื่อดู"><?php $__pl = function_exists('mb_strlen') ? mb_strlen($plainKey, 'UTF-8') : strlen($plainKey); echo htmlspecialchars(str_repeat('●', max(12, min(64, $__pl)))); ?></div>
            <div class="flex flex-col sm:flex-row gap-2 shrink-0">
                <button type="button" id="hr-api-toggle-key-btn" class="inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] border border-amber-400/40 bg-amber-500/15 px-4 text-sm font-semibold text-amber-100 hover:bg-amber-500/25 touch-manipulation gap-2">
                    <i class="fas fa-eye" aria-hidden="true"></i><span id="hr-api-toggle-key-label">แสดงคีย์</span>
                </button>
                <button type="button" id="hr-api-copy-key-btn" class="inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] border border-amber-400/40 bg-amber-500/20 px-4 text-sm font-semibold text-amber-100 hover:bg-amber-500/30 touch-manipulation gap-2">
                    <i class="fas fa-copy" aria-hidden="true"></i>คัดลอก
                </button>
            </div>
        </div>
        <p id="hr-api-copy-feedback" class="text-emerald-300/90 text-xs mt-2 min-h-[1.25rem]" role="status" aria-live="polite"></p>
        <p class="text-amber-200/80 text-xs mt-1">คัดลอกและเก็บไว้ในที่ปลอดภัย — ระบบเก็บเฉพาะ hash ไม่สามารถแสดงได้อีก</p>
    </div>
    <script>
    window.__HR_API_ISSUED_KEY = <?= json_encode($plainKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <?php endif; ?>

    <!-- Create form -->
    <div class="native-card tp-native-card tp-native-data-card overflow-hidden rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6 mb-6 min-w-0 border border-white/10">
        <h2 class="text-lg font-semibold text-white mb-4">ออกคีย์ใหม่</h2>
        <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="create">

            <div class="tp-native-form-group mb-0">
                <label class="text-white/70 text-sm block mb-2" for="api-key-name">ชื่อระบบ/ผู้ใช้ *</label>
                <input id="api-key-name" name="name" required class="input-field tp-native-input w-full min-h-[48px]" placeholder="เช่น Accounting Sync">
            </div>
            <div class="tp-native-form-group mb-0">
                <label class="text-white/70 text-sm block mb-2" for="api-key-rate">Rate limit (req/min)</label>
                <input id="api-key-rate" name="rate_limit_per_min" type="number" value="60" min="1" max="1000" class="input-field tp-native-input w-full min-h-[48px]">
            </div>

            <div class="md:col-span-2">
                <span class="block text-white/70 text-sm mb-1">Scopes</span>
                <p class="text-white/50 text-xs mb-3 leading-relaxed">
                    คีย์ที่<strong class="text-white/70">ผูกพนักงาน</strong>มักเลือกเฉพาะ <code class="text-violet-300/90">*.read</code> / <code class="text-violet-300/90">*.write</code> ตามฟีเจอร์
                    — คีย์<strong class="text-white/70">ไม่ผูก</strong> (ระบบภายนอก/HR) ต้องเพิ่ม <code class="text-amber-200/90">*.read_all</code> / <code class="text-amber-200/90">*.write_all</code> หากดึงหรือสร้างแทนหลายคน
                </p>
                <div class="space-y-4">
                    <?php foreach ($scopeGroups as $groupTitle => $scopes): ?>
                    <div class="rounded-[var(--tp-ios-card-radius)] border border-white/10 bg-white/[0.04] p-4">
                        <div class="text-white/90 text-sm font-semibold mb-3 pb-2 border-b border-white/10"><?= htmlspecialchars($groupTitle) ?></div>
                        <div class="flex flex-wrap gap-2.5">
                            <?php foreach ($scopes as $sv => $sl): ?>
                            <label class="inline-flex items-center gap-2 text-white/80 text-sm bg-white/5 px-3 py-2 rounded-[var(--tp-ios-card-radius)] border border-transparent hover:border-violet-500/30 cursor-pointer touch-manipulation min-h-[44px]">
                                <input type="checkbox" name="scopes[]" value="<?= htmlspecialchars($sv) ?>" class="rounded border-white/20 text-violet-600 focus:ring-violet-500 shrink-0">
                                <span><?= htmlspecialchars($sl) ?> <code class="text-white/45 text-xs">(<?= htmlspecialchars($sv) ?>)</code></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="tp-native-form-group mb-0">
                <label class="text-white/70 text-sm block mb-2" for="api-key-ips">Allowed IPs (คั่นด้วย comma/space, CIDR ได้)</label>
                <input id="api-key-ips" name="allowed_ips" class="input-field tp-native-input w-full min-h-[48px]" placeholder="เช่น 203.0.113.10, 198.51.100.0/24">
            </div>
            <div class="tp-native-form-group mb-0">
                <label class="text-white/70 text-sm block mb-2" for="api-key-origins">Allowed CORS Origins</label>
                <input id="api-key-origins" name="allowed_origins" class="input-field tp-native-input w-full min-h-[48px]" placeholder="https://app.example.com">
            </div>

            <div class="tp-native-form-group mb-0">
                <label class="text-white/70 text-sm block mb-2" for="api-key-exp">วันหมดอายุ</label>
                <input id="api-key-exp" name="expires_at" type="date" class="input-field tp-native-input w-full min-h-[48px]">
            </div>
            <div class="tp-native-form-group mb-0">
                <label class="text-white/70 text-sm block mb-2" for="api-key-notes">หมายเหตุ</label>
                <input id="api-key-notes" name="notes" class="input-field tp-native-input w-full min-h-[48px]">
            </div>
            <div class="md:col-span-2 tp-native-form-group mb-0">
                <label class="text-white/70 text-sm block mb-2" for="api-key-service-user">ผูกกับพนักงาน (ถ้าเลือก คีย์จะเข้าถึงได้เฉพาะข้อมูลของพนักงานนี้ — ไม่สามารถอนุมัติ/แก้ payroll แอดมิน)</label>
                <select id="api-key-service-user" name="service_user_id" class="input-field tp-native-select w-full min-h-[48px]">
                    <option value="0">— ไม่จำกัด (คีย์ระบบทั่วไป) —</option>
                    <?php foreach ($employeeOptions as $eo): ?>
                    <option value="<?= (int)$eo['id'] ?>"><?= htmlspecialchars(trim(($eo['employee_code'] ?? '') . ' ' . ($eo['first_name_th'] ?? '') . ' ' . ($eo['last_name_th'] ?? ''))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="md:col-span-2">
                <button type="submit" class="inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-600 hover:bg-violet-700 px-6 text-sm font-semibold text-white touch-manipulation gap-2">
                    <i class="fas fa-plus" aria-hidden="true"></i>ออกคีย์
                </button>
            </div>
        </form>
    </div>

    <!-- Keys list -->
    <div class="native-card tp-native-card tp-native-data-card overflow-hidden rounded-[var(--tp-ios-card-radius)] mb-6 min-w-0 border border-white/10">
        <div class="p-4 border-b border-white/10">
            <h2 class="text-lg font-semibold text-white">คีย์ทั้งหมด (<span role="status"><?= count($keys) ?></span>)</h2>
        </div>
        <div class="md:hidden p-3 space-y-3">
            <?php foreach ($keys as $k): $scopes = json_decode($k['scopes'] ?? '[]', true) ?: []; ?>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-4 space-y-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-white font-semibold break-words"><?= htmlspecialchars($k['name']) ?></p>
                            <?php if (!empty($k['notes'])): ?><p class="text-white/45 text-xs mt-1 break-words"><?= htmlspecialchars($k['notes']) ?></p><?php endif; ?>
                            <p class="text-white/50 text-xs font-mono mt-1"><?= htmlspecialchars($k['key_prefix']) ?>…</p>
                        </div>
                        <?php if ((int)$k['is_active'] === 1 && (empty($k['expires_at']) || strtotime($k['expires_at']) > time())): ?>
                            <span class="shrink-0 px-2 py-1 rounded-[var(--tp-ios-card-radius)] text-xs bg-emerald-500/20 text-emerald-300">Active</span>
                        <?php elseif (!empty($k['revoked_at'])): ?>
                            <span class="shrink-0 px-2 py-1 rounded-[var(--tp-ios-card-radius)] text-xs bg-rose-500/20 text-rose-300">Revoked</span>
                        <?php else: ?>
                            <span class="shrink-0 px-2 py-1 rounded-[var(--tp-ios-card-radius)] text-xs bg-yellow-500/20 text-yellow-300">Expired</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <p class="text-white/45 text-[10px] uppercase tracking-wide">Scopes</p>
                        <p class="text-white/75 text-xs break-words line-clamp-4"><?= htmlspecialchars(implode(', ', $scopes) ?: '—') ?></p>
                    </div>
                    <?php if (!empty($k['service_user_id'])): ?>
                    <div class="rounded-[var(--tp-ios-card-radius)] bg-amber-500/10 border border-amber-400/30 px-2 py-2 text-xs text-amber-100">
                        <span class="text-white/45">ผูกพนักงาน:</span>
                        <?= htmlspecialchars(trim(($k['su_code'] ?? '') . ' ' . ($k['su_first'] ?? '') . ' ' . ($k['su_last'] ?? ''))) ?>
                        <span class="text-white/40">(id <?= (int)$k['service_user_id'] ?>)</span>
                    </div>
                    <?php endif; ?>
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 px-2 py-2">
                            <span class="text-white/45">Rate</span>
                            <p class="text-white font-medium"><?= (int)$k['rate_limit_per_min'] ?>/min</p>
                        </div>
                        <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 px-2 py-2">
                            <span class="text-white/45">หมดอายุ</span>
                            <p class="text-white/80"><?= htmlspecialchars($k['expires_at'] ?? '—') ?></p>
                        </div>
                    </div>
                    <div class="text-xs text-white/50">
                        <span class="text-white/45">ล่าสุด:</span> <?= htmlspecialchars($k['last_used_at'] ?? '—') ?>
                        <?php if (!empty($k['last_used_ip'])): ?><span class="text-white/35"> · <?= htmlspecialchars($k['last_used_ip']) ?></span><?php endif; ?>
                    </div>
                    <?php if ((int)$k['is_active'] === 1): ?>
                    <button type="button"
                        class="w-full min-h-[48px] rounded-[var(--tp-ios-card-radius)] bg-rose-500/20 hover:bg-rose-500/30 text-rose-200 text-sm font-semibold touch-manipulation"
                        data-ak-act="revoke"
                        data-ak-id="<?= (int)$k['id'] ?>"
                        data-ak-name="<?= htmlspecialchars($k['name'], ENT_QUOTES, 'UTF-8') ?>"
                        onclick="hrApiKeysOpenActionModal(this)">
                        Revoke</button>
                    <?php else: ?>
                    <button type="button"
                        class="w-full min-h-[48px] rounded-[var(--tp-ios-card-radius)] bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-200 text-sm font-semibold touch-manipulation"
                        data-ak-act="activate"
                        data-ak-id="<?= (int)$k['id'] ?>"
                        data-ak-name="<?= htmlspecialchars($k['name'], ENT_QUOTES, 'UTF-8') ?>"
                        onclick="hrApiKeysOpenActionModal(this)">
                        Activate</button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$keys): ?>
                <div class="tp-native-empty-state text-center py-10 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15">
                    <i class="fas fa-key text-slate-500 text-3xl mb-2 block" aria-hidden="true"></i>
                    <p class="text-white/50 text-sm">ยังไม่มีคีย์</p>
                </div>
            <?php endif; ?>
        </div>
        <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain -mx-1 px-1 pb-px">
            <table class="w-full text-sm" style="min-width:1100px">
            <thead class="bg-white/5">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ชื่อ</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">Prefix</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">Scopes</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ผูกพนักงาน</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">Rate</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ล่าสุด</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">หมดอายุ</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10 text-white/80">
            <?php foreach ($keys as $k): $scopes = json_decode($k['scopes'] ?? '[]', true) ?: []; ?>
                <tr class="hover:bg-white/[0.04]">
                    <td class="px-4 py-3"><?= htmlspecialchars($k['name']) ?><div class="text-xs text-white/40"><?= htmlspecialchars($k['notes'] ?? '') ?></div></td>
                    <td class="px-4 py-3 font-mono text-xs"><?= htmlspecialchars($k['key_prefix']) ?>…</td>
                    <td class="px-4 py-3 text-xs max-w-xs"><?= htmlspecialchars(implode(', ', $scopes) ?: '—') ?></td>
                    <td class="px-4 py-3 text-xs"><?php if (!empty($k['service_user_id'])): ?><?= htmlspecialchars(trim(($k['su_code'] ?? '') . ' ' . ($k['su_first'] ?? '') . ' ' . ($k['su_last'] ?? ''))) ?><div class="text-white/40">#<?= (int)$k['service_user_id'] ?></div><?php else: ?><span class="text-white/35">—</span><?php endif; ?></td>
                    <td class="px-4 py-3"><?= (int)$k['rate_limit_per_min'] ?>/min</td>
                    <td class="px-4 py-3 text-xs"><?= htmlspecialchars($k['last_used_at'] ?? '—') ?><div class="text-white/40"><?= htmlspecialchars($k['last_used_ip'] ?? '') ?></div></td>
                    <td class="px-4 py-3 text-xs"><?= htmlspecialchars($k['expires_at'] ?? '—') ?></td>
                    <td class="px-4 py-3">
                        <?php if ((int)$k['is_active'] === 1 && (empty($k['expires_at']) || strtotime($k['expires_at']) > time())): ?>
                            <span class="inline-flex px-2 py-1 rounded-[var(--tp-ios-card-radius)] text-xs bg-emerald-500/20 text-emerald-300">Active</span>
                        <?php elseif (!empty($k['revoked_at'])): ?>
                            <span class="inline-flex px-2 py-1 rounded-[var(--tp-ios-card-radius)] text-xs bg-rose-500/20 text-rose-300">Revoked</span>
                        <?php else: ?>
                            <span class="inline-flex px-2 py-1 rounded-[var(--tp-ios-card-radius)] text-xs bg-yellow-500/20 text-yellow-300">Expired</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                        <?php if ((int)$k['is_active'] === 1): ?>
                        <button type="button"
                            class="inline-flex min-h-[44px] min-w-[72px] items-center justify-center rounded-[var(--tp-ios-card-radius)] text-rose-300 hover:bg-rose-500/15 text-xs font-medium touch-manipulation"
                            data-ak-act="revoke"
                            data-ak-id="<?= (int)$k['id'] ?>"
                            data-ak-name="<?= htmlspecialchars($k['name'], ENT_QUOTES, 'UTF-8') ?>"
                            onclick="hrApiKeysOpenActionModal(this)">Revoke</button>
                        <?php else: ?>
                        <button type="button"
                            class="inline-flex min-h-[44px] min-w-[72px] items-center justify-center rounded-[var(--tp-ios-card-radius)] text-emerald-300 hover:bg-emerald-500/15 text-xs font-medium touch-manipulation"
                            data-ak-act="activate"
                            data-ak-id="<?= (int)$k['id'] ?>"
                            data-ak-name="<?= htmlspecialchars($k['name'], ENT_QUOTES, 'UTF-8') ?>"
                            onclick="hrApiKeysOpenActionModal(this)">Activate</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$keys): ?>
                <tr><td colspan="9" class="px-4 py-10 text-center text-white/45">ยังไม่มีคีย์</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Recent logs -->
    <div class="native-card tp-native-card tp-native-data-card overflow-hidden rounded-[var(--tp-ios-card-radius)] min-w-0 border border-white/10">
        <div class="p-4 border-b border-white/10">
            <h2 class="text-lg font-semibold text-white">Request Log (20 ล่าสุด)</h2>
        </div>
        <div class="md:hidden p-3 space-y-2">
            <?php foreach ($recentLogs as $l): ?>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-3 text-xs">
                    <div class="flex items-start justify-between gap-2">
                        <span class="text-white/55 shrink-0"><?= htmlspecialchars($l['created_at']) ?></span>
                        <span class="<?= ((int)$l['status_code'] >= 400) ? 'text-rose-300' : 'text-emerald-300' ?> font-mono font-semibold"><?= (int)$l['status_code'] ?></span>
                    </div>
                    <p class="text-white/80 mt-1 font-medium truncate"><?= htmlspecialchars($l['key_name'] ?? '—') ?></p>
                    <p class="text-white/50 mt-0.5"><span class="font-mono"><?= htmlspecialchars($l['method']) ?></span> · <?= (int)$l['response_ms'] ?> ms · <?= htmlspecialchars($l['ip_address']) ?></p>
                    <p class="text-white/60 font-mono break-all mt-2 text-[11px]"><?= htmlspecialchars($l['path']) ?></p>
                    <?php if (!empty($l['error_message'])): ?><p class="text-rose-300 mt-2 break-words"><?= htmlspecialchars($l['error_message']) ?></p><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$recentLogs): ?>
                <div class="tp-native-empty-state text-center py-10 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15">
                    <i class="fas fa-scroll text-slate-500 text-3xl mb-2 block" aria-hidden="true"></i>
                    <p class="text-white/50 text-sm">ยังไม่มี log</p>
                </div>
            <?php endif; ?>
        </div>
        <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain -mx-1 px-1 pb-px">
        <table class="w-full text-xs" style="min-width:900px">
            <thead class="bg-white/5">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left font-medium text-white/60 uppercase">เวลา</th>
                    <th scope="col" class="px-4 py-3 text-left font-medium text-white/60 uppercase">คีย์</th>
                    <th scope="col" class="px-4 py-3 text-left font-medium text-white/60 uppercase">Method</th>
                    <th scope="col" class="px-4 py-3 text-left font-medium text-white/60 uppercase">Path</th>
                    <th scope="col" class="px-4 py-3 text-left font-medium text-white/60 uppercase">Status</th>
                    <th scope="col" class="px-4 py-3 text-left font-medium text-white/60 uppercase">IP</th>
                    <th scope="col" class="px-4 py-3 text-left font-medium text-white/60 uppercase">ms</th>
                    <th scope="col" class="px-4 py-3 text-left font-medium text-white/60 uppercase">Error</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10 text-white/80">
            <?php foreach ($recentLogs as $l): ?>
                <tr class="hover:bg-white/[0.04]">
                    <td class="px-4 py-2"><?= htmlspecialchars($l['created_at']) ?></td>
                    <td class="px-4 py-2"><?= htmlspecialchars($l['key_name'] ?? '—') ?></td>
                    <td class="px-4 py-2"><?= htmlspecialchars($l['method']) ?></td>
                    <td class="px-4 py-2 font-mono break-all"><?= htmlspecialchars($l['path']) ?></td>
                    <td class="px-4 py-2 <?= ((int)$l['status_code'] >= 400) ? 'text-rose-300' : 'text-emerald-300' ?>"><?= (int)$l['status_code'] ?></td>
                    <td class="px-4 py-2"><?= htmlspecialchars($l['ip_address']) ?></td>
                    <td class="px-4 py-2"><?= (int)$l['response_ms'] ?></td>
                    <td class="px-4 py-2 text-rose-300"><?= htmlspecialchars($l['error_message'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentLogs): ?>
                <tr><td colspan="8" class="px-4 py-10 text-center text-white/45">ยังไม่มี log</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Revoke / Activate confirmation (replaces window.confirm) -->
<div id="hr-ak-action-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="hr-ak-action-modal-title">
    <form id="hr-ak-action-form" method="POST" class="native-card tp-native-card w-full max-w-md my-auto rounded-[var(--tp-ios-card-radius)] p-6 pb-[calc(env(safe-area-inset-bottom,0px)+1.5rem)] border border-white/10">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <input type="hidden" name="action" id="hr-ak-modal-action" value="">
        <input type="hidden" name="id" id="hr-ak-modal-id" value="">
        <div class="flex items-start gap-3 mb-4">
            <div id="hr-ak-modal-icon-wrap" class="w-12 h-12 rounded-full bg-rose-500/20 flex items-center justify-center text-rose-400 shrink-0">
                <i id="hr-ak-modal-icon" class="fas fa-ban text-xl" aria-hidden="true"></i>
            </div>
            <div class="min-w-0">
                <h3 id="hr-ak-action-modal-title" class="text-xl font-bold text-white leading-tight">ยืนยันการดำเนินการ</h3>
                <div id="hr-ak-modal-desc" class="text-white/65 text-sm mt-1 break-words"></div>
            </div>
        </div>
        <div class="flex flex-col sm:flex-row gap-3 pt-2">
            <button type="button" id="hr-ak-modal-cancel" class="flex-1 min-h-[48px] py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-medium">ยกเลิก</button>
            <button type="submit" id="hr-ak-modal-confirm" class="flex-1 min-h-[56px] py-2.5 rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold text-white bg-rose-600 hover:bg-rose-700">ยืนยัน</button>
        </div>
    </form>
</div>

<script>
(function () {
    var k = typeof window.__HR_API_ISSUED_KEY === 'string' ? window.__HR_API_ISSUED_KEY : '';
    var displayEl = document.getElementById('hr-api-plain-key');
    var toggleBtn = document.getElementById('hr-api-toggle-key-btn');
    var toggleLabel = document.getElementById('hr-api-toggle-key-label');
    var copyBtn = document.getElementById('hr-api-copy-key-btn');
    var feedbackEl = document.getElementById('hr-api-copy-feedback');

    function setFeedback(msg) {
        if (feedbackEl) feedbackEl.textContent = msg || '';
    }

    if (k && displayEl && toggleBtn) {
        masked = new Array(Math.min(64, Math.max(12, k.length)) + 1).join('●');
        var masked = new Array(Math.min(64, Math.max(12, k.length)) + 1).join('●');
        function applyView() {
            var revealed = displayEl.getAttribute('data-revealed') === '1';
            displayEl.textContent = revealed ? k : masked;
            displayEl.classList.toggle('select-all', revealed);
            displayEl.classList.toggle('select-none', !revealed);
            displayEl.classList.toggle('tracking-wider', !revealed);
            displayEl.setAttribute('aria-label', revealed ? 'ค่าคีย์แบบเต็ม' : 'ค่าคีย์ถูกซ่อน กดแสดงเพื่อดู');
            if (toggleLabel) toggleLabel.textContent = revealed ? 'ซ่อนคีย์' : 'แสดงคีย์';
            var ic = toggleBtn.querySelector('i');
            if (ic) {
                ic.className = revealed ? 'fas fa-eye-slash' : 'fas fa-eye';
                ic.setAttribute('aria-hidden', 'true');
            }
        }
        toggleBtn.addEventListener('click', function () {
            var cur = displayEl.getAttribute('data-revealed') === '1';
            displayEl.setAttribute('data-revealed', cur ? '0' : '1');
            applyView();
        });
        applyView();
    }

    async function copyPlain() {
        var text = typeof window.__HR_API_ISSUED_KEY === 'string' ? window.__HR_API_ISSUED_KEY : '';
        if (!text && displayEl) text = displayEl.textContent.trim();
        if (!text) return;
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            }
            setFeedback('คัดลอกแล้ว');
            if (typeof showToast === 'function') showToast('success', 'คัดลอกแล้ว', 'นำไปเก็บในที่ปลอดภัย');
            setTimeout(function () { setFeedback(''); }, 4000);
        } catch (e) {
            setFeedback('คัดลอกไม่สำเร็จ — ลองเลือกข้อความแล้วคัดลอกด้วยมือ');
        }
    }

    if (copyBtn) copyBtn.addEventListener('click', copyPlain);
})();

function hrApiKeysOpenActionModal(btn) {
    if (!btn) return;
    var act = btn.getAttribute('data-ak-act') || '';
    var id = btn.getAttribute('data-ak-id') || '';
    var name = btn.getAttribute('data-ak-name') || '';
    var form = document.getElementById('hr-ak-action-form');
    var inpAct = document.getElementById('hr-ak-modal-action');
    var inpId = document.getElementById('hr-ak-modal-id');
    var titleEl = document.getElementById('hr-ak-action-modal-title');
    var descEl = document.getElementById('hr-ak-modal-desc');
    var iconWrap = document.getElementById('hr-ak-modal-icon-wrap');
    var iconEl = document.getElementById('hr-ak-modal-icon');
    var confirmBtn = document.getElementById('hr-ak-modal-confirm');

    if (!form || !inpAct || !inpId) return;
    inpAct.value = act;
    inpId.value = id;

    if (act === 'revoke') {
        titleEl.textContent = 'ยกเลิกคีย์';
        descEl.textContent = '';
        var lineRv = document.createElement('p');
        lineRv.className = 'text-white/65 text-sm mt-1 break-words';
        lineRv.appendChild(document.createTextNode('ยืนยันการ revoke คีย์ '));
        var boldRv = document.createElement('strong');
        boldRv.className = 'text-white/90';
        boldRv.textContent = name || ('#' + id);
        lineRv.appendChild(boldRv);
        lineRv.appendChild(document.createTextNode('? ผู้เรียก API จะใช้คีย์นี้ไม่ได้อีก'));
        descEl.appendChild(lineRv);
        if (iconWrap) {
            iconWrap.className = 'w-12 h-12 rounded-full bg-rose-500/20 flex items-center justify-center text-rose-400 shrink-0';
        }
        if (iconEl) iconEl.className = 'fas fa-ban text-xl';
        if (confirmBtn) {
            confirmBtn.className = 'flex-1 min-h-[56px] py-2.5 rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold text-white bg-rose-600 hover:bg-rose-700';
            confirmBtn.textContent = 'ยืนยัน revoke';
        }
    } else {
        titleEl.textContent = 'เปิดใช้งานคีย์';
        descEl.textContent = '';
        var lineAct = document.createElement('p');
        lineAct.className = 'text-white/65 text-sm mt-1 break-words';
        lineAct.appendChild(document.createTextNode('เปิดการใช้งานใหม่คีย์ '));
        var boldAct = document.createElement('strong');
        boldAct.className = 'text-white/90';
        boldAct.textContent = name || ('#' + id);
        lineAct.appendChild(boldAct);
        lineAct.appendChild(document.createTextNode(' ?'));
        descEl.appendChild(lineAct);
        if (iconWrap) {
            iconWrap.className = 'w-12 h-12 rounded-full bg-emerald-500/20 flex items-center justify-center text-emerald-400 shrink-0';
        }
        if (iconEl) iconEl.className = 'fas fa-play text-xl';
        if (confirmBtn) {
            confirmBtn.className = 'flex-1 min-h-[56px] py-2.5 rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold text-white bg-emerald-600 hover:bg-emerald-700';
            confirmBtn.textContent = 'ยืนยัน activate';
        }
    }

    if (typeof uiOpenModal === 'function') uiOpenModal('hr-ak-action-modal');
    else {
        var m = document.getElementById('hr-ak-action-modal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}

(function () {
    var modal = document.getElementById('hr-ak-action-modal');
    var cancel = document.getElementById('hr-ak-modal-cancel');
    function close() {
        if (typeof uiCloseModal === 'function') uiCloseModal('hr-ak-action-modal');
        else if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    }
    if (cancel) cancel.addEventListener('click', close);
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) close();
        });
    }
})();
</script>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
