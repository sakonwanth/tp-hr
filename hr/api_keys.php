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

$availableScopes = [
    'employees.read'       => 'อ่านข้อมูลพนักงาน (รายคน / คีย์ผูกพนักงาน)',
    'employees.read_all'   => 'อ่านรายชื่อพนักงานทั้งหมด (GET /employees แบบลิสต์)',
    'attendance.read'      => 'อ่านลงเวลา (คีย์ผูกพนักงาน หรือร่วม attendance.read_all)',
    'attendance.read_all'  => 'อ่านลงเวลาทุกคน/กรอง user_id (คีย์ไม่ผูกพนักงาน)',
    'attendance.write'     => 'เช็คอิน/เช็คเอาต์ผ่าน API',
    'leave.read'           => 'อ่านใบลา (คีย์ผูกพนักงาน หรือร่วม leave.read_all)',
    'leave.read_all'       => 'อ่านใบลาทุกคน / ตาม id (คีย์ไม่ผูกพนักงาน)',
    'leave.write'          => 'สร้าง/ยกเลิกใบลา',
    'leave.approve'        => 'อนุมัติ/ปฏิเสธใบลา',
    'dayoff.read'          => 'อ่านคำขอเปลี่ยนวันหยุด (คีย์ผูกพนักงาน หรือร่วม dayoff.read_all)',
    'dayoff.read_all'      => 'อ่านคำขอเปลี่ยนวันหยุดทุกคน (คีย์ไม่ผูกพนักงาน)',
    'dayoff.write'         => 'สร้างคำขอเปลี่ยนวันหยุด',
    'dayoff.approve'       => 'อนุมัติ/ปฏิเสธเปลี่ยนวันหยุด',
    'overtime.read'        => 'อ่านคำขอ OT (คีย์ผูกพนักงาน หรือร่วม overtime.read_all)',
    'overtime.read_all'    => 'อ่านคำขอ OT ทุกคน (คีย์ไม่ผูกพนักงาน)',
    'overtime.write'       => 'สร้างคำขอ OT',
    'overtime.approve'     => 'อนุมัติ/ปฏิเสธ OT',
    'outside.read'         => 'อ่านคำขอลงเวลานอกสถานที่ (คีย์ผูกพนักงาน หรือร่วม outside.read_all)',
    'outside.read_all'     => 'อ่านคำขอนอกสถานที่ทุกคน (คีย์ไม่ผูกพนักงาน)',
    'outside.approve'      => 'อนุมัติ/ปฏิเสธลงเวลานอกสถานที่',
    'adjustments.read'     => 'อ่านคำขอปรับปรุงเวลา (คีย์ผูกพนักงาน หรือร่วม adjustments.read_all)',
    'adjustments.read_all' => 'อ่านคำขอปรับเวลาทุกคน (คีย์ไม่ผูกพนักงาน)',
    'adjustments.approve'  => 'อนุมัติ/ปฏิเสธปรับปรุงเวลา',
    'payroll.read'         => 'อ่านสลิป/รอบเงินเดือนของตน (คีย์ผูกพนักงาน) หรือร่วมกับ payroll.read_all',
    'payroll.read_all'     => 'อ่านสลิปและรอบเงินเดือนทั้งหมด (คีย์ไม่ผูกพนักงาน)',
    'hr.read'              => 'อ่าน metadata ทั่วไป (แผนก/ตำแหน่ง/วันหยุด/ประเภทลา/ประกาศ)',
    'hr.read_all'          => 'อ่านกะรายคนและสิทธิ์ลา (employee-schedules, leave-entitlements) เมื่อคีย์ไม่ผูกพนักงาน',
    '*'                    => 'เข้าถึงทั้งหมด (ระวัง)',
];

require_once __DIR__ . '/../templates/header.php';
?>
<div class="min-w-0 max-w-full">
    <div class="mb-6 min-w-0">
        <nav class="text-sm text-white/60 mb-3" aria-label="Breadcrumb">
            <a href="/hr/index.php" class="hover:text-white touch-manipulation">แดชบอร์ด HR</a>
            <span class="mx-2">/</span>
            <span class="text-white">External API Keys</span>
        </nav>
        <h1 class="text-2xl font-bold text-white tracking-tight">External API Keys</h1>
        <p class="text-slate-300 text-sm mt-1.5 leading-relaxed">จัดการคีย์ API สำหรับระบบภายนอก</p>
    </div>

    <?php if ($error): ?>
    <div class="mb-4 p-4 rounded-xl bg-rose-500/20 border border-rose-500/30 text-rose-200"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="mb-4 p-4 rounded-xl bg-emerald-500/20 border border-emerald-500/30 text-emerald-200"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($plainKey): ?>
    <div class="mb-6 p-5 rounded-xl bg-amber-500/15 border-2 border-amber-400/50">
        <div class="text-amber-200 font-semibold mb-2"><i class="fas fa-key mr-2"></i>API Key ใหม่ — แสดงครั้งเดียว</div>
        <div class="bg-black/40 p-3 rounded-lg font-mono text-amber-100 text-sm break-all select-all"><?= htmlspecialchars($plainKey) ?></div>
        <p class="text-amber-200/80 text-xs mt-2">คัดลอกและเก็บไว้ในที่ปลอดภัย — ระบบเก็บเฉพาะ hash ไม่สามารถแสดงได้อีก</p>
    </div>
    <?php endif; ?>

    <!-- Create form -->
    <div class="glass-card rounded-xl p-6 mb-6">
        <h2 class="text-lg font-semibold text-white mb-4">ออกคีย์ใหม่</h2>
        <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="create">

            <div>
                <label class="text-white/70 text-sm block mb-1">ชื่อระบบ/ผู้ใช้ *</label>
                <input name="name" required class="input-field w-full" placeholder="เช่น Accounting Sync">
            </div>
            <div>
                <label class="text-white/70 text-sm block mb-1">Rate limit (req/min)</label>
                <input name="rate_limit_per_min" type="number" value="60" min="1" max="1000" class="input-field w-full">
            </div>

            <div class="md:col-span-2">
                <label class="text-white/70 text-sm block mb-2">Scopes</label>
                <div class="flex flex-wrap gap-3">
                    <?php foreach ($availableScopes as $sv => $sl): ?>
                    <label class="inline-flex items-center gap-2 text-white/80 text-sm bg-white/5 px-3 py-2 rounded-lg">
                        <input type="checkbox" name="scopes[]" value="<?= htmlspecialchars($sv) ?>">
                        <span><?= htmlspecialchars($sl) ?> <code class="text-white/50">(<?= htmlspecialchars($sv) ?>)</code></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <label class="text-white/70 text-sm block mb-1">Allowed IPs (คั่นด้วย comma/space, CIDR ได้)</label>
                <input name="allowed_ips" class="input-field w-full" placeholder="เช่น 203.0.113.10, 198.51.100.0/24">
            </div>
            <div>
                <label class="text-white/70 text-sm block mb-1">Allowed CORS Origins</label>
                <input name="allowed_origins" class="input-field w-full" placeholder="https://app.example.com">
            </div>

            <div>
                <label class="text-white/70 text-sm block mb-1">วันหมดอายุ</label>
                <input name="expires_at" type="date" class="input-field w-full">
            </div>
            <div>
                <label class="text-white/70 text-sm block mb-1">หมายเหตุ</label>
                <input name="notes" class="input-field w-full">
            </div>
            <div class="md:col-span-2">
                <label class="text-white/70 text-sm block mb-1">ผูกกับพนักงาน (ถ้าเลือก คีย์จะเข้าถึงได้เฉพาะข้อมูลของพนักงานนี้ — ไม่สามารถอนุมัติ/แก้ payroll แอดมิน)</label>
                <select name="service_user_id" class="input-field w-full">
                    <option value="0">— ไม่จำกัด (คีย์ระบบทั่วไป) —</option>
                    <?php foreach ($employeeOptions as $eo): ?>
                    <option value="<?= (int)$eo['id'] ?>"><?= htmlspecialchars(trim(($eo['employee_code'] ?? '') . ' ' . ($eo['first_name_th'] ?? '') . ' ' . ($eo['last_name_th'] ?? ''))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="md:col-span-2">
                <button type="submit" class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg">
                    <i class="fas fa-plus mr-2"></i>ออกคีย์
                </button>
            </div>
        </form>
    </div>

    <!-- Keys list -->
    <div class="glass-card rounded-xl overflow-hidden mb-6">
        <div class="p-4 border-b border-white/10">
            <h2 class="text-lg font-semibold text-white">คีย์ทั้งหมด (<?= count($keys) ?>)</h2>
        </div>
        <div class="md:hidden p-3 space-y-3">
            <?php foreach ($keys as $k): $scopes = json_decode($k['scopes'] ?? '[]', true) ?: []; ?>
                <div class="rounded-2xl bg-white/5 border border-white/10 p-4 space-y-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-white font-semibold break-words"><?= htmlspecialchars($k['name']) ?></p>
                            <?php if (!empty($k['notes'])): ?><p class="text-white/45 text-xs mt-1 break-words"><?= htmlspecialchars($k['notes']) ?></p><?php endif; ?>
                            <p class="text-white/50 text-xs font-mono mt-1"><?= htmlspecialchars($k['key_prefix']) ?>…</p>
                        </div>
                        <?php if ((int)$k['is_active'] === 1 && (empty($k['expires_at']) || strtotime($k['expires_at']) > time())): ?>
                            <span class="shrink-0 px-2 py-1 rounded-full text-xs bg-emerald-500/20 text-emerald-300">Active</span>
                        <?php elseif (!empty($k['revoked_at'])): ?>
                            <span class="shrink-0 px-2 py-1 rounded-full text-xs bg-rose-500/20 text-rose-300">Revoked</span>
                        <?php else: ?>
                            <span class="shrink-0 px-2 py-1 rounded-full text-xs bg-yellow-500/20 text-yellow-300">Expired</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <p class="text-white/45 text-[10px] uppercase tracking-wide">Scopes</p>
                        <p class="text-white/75 text-xs break-words line-clamp-4"><?= htmlspecialchars(implode(', ', $scopes) ?: '—') ?></p>
                    </div>
                    <?php if (!empty($k['service_user_id'])): ?>
                    <div class="rounded-lg bg-amber-500/10 border border-amber-400/30 px-2 py-2 text-xs text-amber-100">
                        <span class="text-white/45">ผูกพนักงาน:</span>
                        <?= htmlspecialchars(trim(($k['su_code'] ?? '') . ' ' . ($k['su_first'] ?? '') . ' ' . ($k['su_last'] ?? ''))) ?>
                        <span class="text-white/40">(id <?= (int)$k['service_user_id'] ?>)</span>
                    </div>
                    <?php endif; ?>
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div class="rounded-lg bg-black/20 border border-white/10 px-2 py-2">
                            <span class="text-white/45">Rate</span>
                            <p class="text-white font-medium"><?= (int)$k['rate_limit_per_min'] ?>/min</p>
                        </div>
                        <div class="rounded-lg bg-black/20 border border-white/10 px-2 py-2">
                            <span class="text-white/45">หมดอายุ</span>
                            <p class="text-white/80"><?= htmlspecialchars($k['expires_at'] ?? '—') ?></p>
                        </div>
                    </div>
                    <div class="text-xs text-white/50">
                        <span class="text-white/45">ล่าสุด:</span> <?= htmlspecialchars($k['last_used_at'] ?? '—') ?>
                        <?php if (!empty($k['last_used_ip'])): ?><span class="text-white/35"> · <?= htmlspecialchars($k['last_used_ip']) ?></span><?php endif; ?>
                    </div>
                    <form method="POST" class="block" onsubmit="return confirm('ยืนยันการดำเนินการ?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                        <?php if ((int)$k['is_active'] === 1): ?>
                            <button name="action" value="revoke" type="submit" class="w-full min-h-[44px] rounded-xl bg-rose-500/20 hover:bg-rose-500/30 text-rose-200 text-sm font-semibold touch-manipulation">Revoke</button>
                        <?php else: ?>
                            <button name="action" value="activate" type="submit" class="w-full min-h-[44px] rounded-xl bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-200 text-sm font-semibold touch-manipulation">Activate</button>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if (!$keys): ?>
                <div class="p-8 text-center text-white/40">ยังไม่มีคีย์</div>
            <?php endif; ?>
        </div>
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-white/5 text-white/60">
                <tr>
                    <th class="p-3 text-left">ชื่อ</th>
                    <th class="p-3 text-left">Prefix</th>
                    <th class="p-3 text-left">Scopes</th>
                    <th class="p-3 text-left">ผูกพนักงาน</th>
                    <th class="p-3 text-left">Rate</th>
                    <th class="p-3 text-left">ล่าสุด</th>
                    <th class="p-3 text-left">หมดอายุ</th>
                    <th class="p-3 text-left">สถานะ</th>
                    <th class="p-3 text-left"></th>
                </tr>
            </thead>
            <tbody class="text-white/80">
            <?php foreach ($keys as $k): $scopes = json_decode($k['scopes'] ?? '[]', true) ?: []; ?>
                <tr class="border-t border-white/5">
                    <td class="p-3"><?= htmlspecialchars($k['name']) ?><div class="text-xs text-white/40"><?= htmlspecialchars($k['notes'] ?? '') ?></div></td>
                    <td class="p-3 font-mono text-xs"><?= htmlspecialchars($k['key_prefix']) ?>…</td>
                    <td class="p-3 text-xs"><?= htmlspecialchars(implode(', ', $scopes) ?: '—') ?></td>
                    <td class="p-3 text-xs"><?php if (!empty($k['service_user_id'])): ?><?= htmlspecialchars(trim(($k['su_code'] ?? '') . ' ' . ($k['su_first'] ?? '') . ' ' . ($k['su_last'] ?? ''))) ?><div class="text-white/40">#<?= (int)$k['service_user_id'] ?></div><?php else: ?><span class="text-white/35">—</span><?php endif; ?></td>
                    <td class="p-3"><?= (int)$k['rate_limit_per_min'] ?>/min</td>
                    <td class="p-3 text-xs"><?= htmlspecialchars($k['last_used_at'] ?? '—') ?><div class="text-white/40"><?= htmlspecialchars($k['last_used_ip'] ?? '') ?></div></td>
                    <td class="p-3 text-xs"><?= htmlspecialchars($k['expires_at'] ?? '—') ?></td>
                    <td class="p-3">
                        <?php if ((int)$k['is_active'] === 1 && (empty($k['expires_at']) || strtotime($k['expires_at']) > time())): ?>
                            <span class="px-2 py-1 rounded-full text-xs bg-emerald-500/20 text-emerald-300">Active</span>
                        <?php elseif (!empty($k['revoked_at'])): ?>
                            <span class="px-2 py-1 rounded-full text-xs bg-rose-500/20 text-rose-300">Revoked</span>
                        <?php else: ?>
                            <span class="px-2 py-1 rounded-full text-xs bg-yellow-500/20 text-yellow-300">Expired</span>
                        <?php endif; ?>
                    </td>
                    <td class="p-3">
                        <form method="POST" class="inline" onsubmit="return confirm('ยืนยันการดำเนินการ?');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                            <?php if ((int)$k['is_active'] === 1): ?>
                                <button name="action" value="revoke" class="text-rose-300 hover:text-rose-100 text-xs">Revoke</button>
                            <?php else: ?>
                                <button name="action" value="activate" class="text-emerald-300 hover:text-emerald-100 text-xs">Activate</button>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$keys): ?>
                <tr><td colspan="9" class="p-6 text-center text-white/40">ยังไม่มีคีย์</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Recent logs -->
    <div class="glass-card rounded-xl overflow-hidden">
        <div class="p-4 border-b border-white/10">
            <h2 class="text-lg font-semibold text-white">Request Log (20 ล่าสุด)</h2>
        </div>
        <div class="md:hidden p-3 space-y-2">
            <?php foreach ($recentLogs as $l): ?>
                <div class="rounded-xl bg-white/5 border border-white/10 p-3 text-xs">
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
                <div class="p-6 text-center text-white/40">ยังไม่มี log</div>
            <?php endif; ?>
        </div>
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-white/5 text-white/60">
                <tr>
                    <th class="p-3 text-left">เวลา</th>
                    <th class="p-3 text-left">คีย์</th>
                    <th class="p-3 text-left">Method</th>
                    <th class="p-3 text-left">Path</th>
                    <th class="p-3 text-left">Status</th>
                    <th class="p-3 text-left">IP</th>
                    <th class="p-3 text-left">ms</th>
                    <th class="p-3 text-left">Error</th>
                </tr>
            </thead>
            <tbody class="text-white/80">
            <?php foreach ($recentLogs as $l): ?>
                <tr class="border-t border-white/5">
                    <td class="p-2"><?= htmlspecialchars($l['created_at']) ?></td>
                    <td class="p-2"><?= htmlspecialchars($l['key_name'] ?? '—') ?></td>
                    <td class="p-2"><?= htmlspecialchars($l['method']) ?></td>
                    <td class="p-2 font-mono break-all"><?= htmlspecialchars($l['path']) ?></td>
                    <td class="p-2 <?= ((int)$l['status_code'] >= 400) ? 'text-rose-300' : 'text-emerald-300' ?>"><?= (int)$l['status_code'] ?></td>
                    <td class="p-2"><?= htmlspecialchars($l['ip_address']) ?></td>
                    <td class="p-2"><?= (int)$l['response_ms'] ?></td>
                    <td class="p-2 text-rose-300"><?= htmlspecialchars($l['error_message'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
