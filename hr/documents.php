<?php
/**
 * HR Document Management
 * จัดการเอกสาร - สำหรับ HR
 */

$page_title = 'จัดการคำขอเอกสาร';
require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();

if (!hr_can_access_hr_dashboard()) {
    redirect('/', 302);
}

$pdo = Database::getInstance()->getConnection();

// Filters
$status = $_GET['status'] ?? 'PENDING';
$type = (int)($_GET['type'] ?? 0);
$month = $_GET['month'] ?? date('Y-m');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = DEFAULT_PER_PAGE;
$offset = ($page - 1) * $limit;

// Get document templates
$stmtTemplates = $pdo->query("SELECT id, name FROM hr_document_templates WHERE is_active = 1 ORDER BY sort_order");
$templates = $stmtTemplates->fetchAll();

// Build query
$sql = "
    SELECT dr.*, dt.name as template_name,
           u.first_name_th, u.last_name_th, u.employee_code, u.department,
           processor.first_name_th as processor_first, processor.last_name_th as processor_last
    FROM hr_document_requests dr
    JOIN hr_document_templates dt ON dr.template_id = dt.id
    JOIN users u ON dr.user_id = u.id
    LEFT JOIN users processor ON dr.processed_by = processor.id
    WHERE 1=1
";
$params = [];

if ($status && $status !== 'ALL') {
    $sql .= " AND dr.status = ?";
    $params[] = $status;
}

if ($type > 0) {
    $sql .= " AND dr.template_id = ?";
    $params[] = $type;
}

if ($month) {
    $sql .= " AND DATE_FORMAT(dr.created_at, '%Y-%m') = ?";
    $params[] = $month;
}

// Count
$countSql = "SELECT COUNT(*) FROM (" . str_replace("dr.*, dt.name as template_name,\n           u.first_name_th, u.last_name_th, u.employee_code, u.department,\n           processor.first_name_th as processor_first, processor.last_name_th as processor_last", "1", $sql) . ") t";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// Get records
$sql .= " ORDER BY " . ($status === 'PENDING' ? "dr.created_at ASC" : "dr.created_at DESC");
$sql .= " LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// Stats
$stmtStats = $pdo->query("
    SELECT 
        SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'PROCESSING' THEN 1 ELSE 0 END) as processing,
        SUM(CASE WHEN status = 'READY' THEN 1 ELSE 0 END) as completed,
        COUNT(*) as total
    FROM hr_document_requests
    WHERE DATE_FORMAT(created_at, '%Y-%m') = '" . ($month ?: date('Y-m')) . "'
");
$stats = $stmtStats->fetch();

$filterBase = ['month' => $month];
if ($type > 0) {
    $filterBase['type'] = (string)$type;
}

$current_page = 'hr-documents';
include dirname(__DIR__) . '/templates/header.php';
?>

<div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="tp-tap-48 hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <span class="text-white">จัดการคำขอเอกสาร</span>
    </nav>
    <div class="min-w-0">
        <h1 class="tp-ios-page-title">จัดการคำขอเอกสาร</h1>
        <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">ติดตามสถานะคำขอ กรองตามเดือนและประเภทเอกสาร</p>
    </div>
</header>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-2 sm:gap-4 mb-6 min-w-0 max-w-full">
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'PENDING']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $status === 'PENDING' ? 'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">รอดำเนินการ</p>
        <p class="text-2xl font-bold text-amber-400 tabular-nums mt-1"><?php echo (int)($stats['pending'] ?? 0); ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'PROCESSING']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $status === 'PROCESSING' ? 'ring-2 ring-sky-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">กำลังจัดทำ</p>
        <p class="text-2xl font-bold text-sky-400 tabular-nums mt-1"><?php echo (int)($stats['processing'] ?? 0); ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'READY']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $status === 'READY' ? 'ring-2 ring-emerald-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">จัดทำแล้ว</p>
        <p class="text-2xl font-bold text-emerald-400 tabular-nums mt-1"><?php echo (int)($stats['completed'] ?? 0); ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'ALL']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $status === 'ALL' ? 'ring-2 ring-violet-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">ทั้งหมด</p>
        <p class="text-2xl font-bold text-violet-400 tabular-nums mt-1"><?php echo (int)($stats['total'] ?? 0); ?></p>
    </a>
</div>

<!-- Filters -->
<div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 mb-6 min-w-0 overflow-hidden rounded-[var(--tp-ios-card-radius)]">
    <h2 class="section-title mb-4 text-white text-lg">
        <i class="fas fa-filter text-violet-400 text-xl mr-2" aria-hidden="true"></i>
        กรองคำขอเอกสาร
    </h2>
    <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
        <div class="tp-native-form-group mb-0">
            <label for="hr-docs-month" class="text-white/70 text-sm font-medium">เดือน</label>
            <input type="month" id="hr-docs-month" name="month" value="<?php echo htmlspecialchars($month); ?>" class="input-field tp-native-input w-full" onchange="this.form.submit()">
        </div>
        <div class="tp-native-form-group mb-0 sm:col-span-2 lg:col-span-1">
            <label for="hr-docs-type" class="text-white/70 text-sm font-medium">ประเภทเอกสาร</label>
            <select id="hr-docs-type" name="type" class="input-field tp-native-select w-full" onchange="this.form.submit()">
                <option value="">ทั้งหมด</option>
                <?php foreach ($templates as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>" <?php echo $type === (int)$t['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($t['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex items-end">
            <a href="documents.php" class="w-full min-h-[48px] py-2.5 bg-white/10 hover:bg-white/20 text-white text-center rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation inline-flex items-center justify-center gap-2 font-medium">
                <i class="fas fa-redo" aria-hidden="true"></i>รีเซ็ต
            </a>
        </div>
    </form>
</div>

<!-- Results -->
<div class="native-card tp-native-card tp-native-data-card overflow-hidden min-w-0 rounded-[var(--tp-ios-card-radius)]">
    <?php if (empty($requests)): ?>
    <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-4 my-4">
        <i class="fas fa-file-alt text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
        <p class="text-slate-400 text-sm">ไม่พบคำขอเอกสาร</p>
    </div>
    <?php else: ?>
    <?php
    $statusColors = [
        'PENDING' => 'border border-amber-500/30 bg-amber-500/15 text-amber-300',
        'PROCESSING' => 'border border-sky-500/30 bg-sky-500/15 text-sky-300',
        'READY' => 'border border-emerald-500/30 bg-emerald-500/15 text-emerald-300',
        'DELIVERED' => 'border border-teal-500/30 bg-teal-500/15 text-teal-300',
        'COMPLETED' => 'border border-emerald-500/30 bg-emerald-500/15 text-emerald-300',
        'REJECTED' => 'border border-red-500/30 bg-red-500/15 text-red-300',
        'CANCELLED' => 'border border-slate-500/30 bg-slate-500/15 text-slate-300'
    ];
    $statusText = [
        'PENDING' => 'รอดำเนินการ',
        'PROCESSING' => 'กำลังจัดทำ',
        'READY' => 'จัดทำแล้ว',
        'DELIVERED' => 'รับแล้ว',
        'COMPLETED' => 'จัดทำแล้ว',
        'REJECTED' => 'ปฏิเสธ',
        'CANCELLED' => 'ยกเลิก'
    ];
    ?>
    <div class="md:hidden p-5 space-y-4">
        <?php foreach ($requests as $req): ?>
        <?php
        $reqId = (int)$req['id'];
        $reqNo = trim((string)($req['request_number'] ?? ''));
        if ($reqNo === '') {
            $reqNo = '#' . str_pad((string)$reqId, 6, '0', STR_PAD_LEFT);
        }
        $st = (string)($req['status'] ?? '');
        $chipCls = $statusColors[$st] ?? 'border border-white/15 bg-white/5 text-white/70';
        $chipLbl = $statusText[$st] ?? $st;
        ?>
        <div class="tp-ios-attendance-panel p-5 space-y-3">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-white/50 text-xs uppercase tracking-wide">รหัสคำขอ</p>
                    <p class="text-white font-mono text-sm"><?php echo htmlspecialchars($reqNo); ?></p>
                </div>
                <span class="shrink-0 px-2.5 py-1 rounded-[var(--tp-ios-card-radius)] text-xs font-semibold <?php echo $chipCls; ?>">
                    <?php echo htmlspecialchars($chipLbl); ?>
                </span>
            </div>
            <div>
                <p class="text-white/50 text-xs">พนักงาน</p>
                <p class="text-white font-medium"><?php echo htmlspecialchars($req['first_name_th'] . ' ' . $req['last_name_th']); ?></p>
                <p class="text-white/40 text-xs mt-0.5"><?php echo htmlspecialchars($req['employee_code'] ?? ''); ?></p>
            </div>
            <div>
                <p class="text-white/50 text-xs">ประเภทเอกสาร</p>
                <p class="text-white text-sm"><?php echo htmlspecialchars($req['template_name']); ?>
                    <?php if (!empty($req['language'])): ?>
                    <span class="text-white/50 text-xs">(<?php echo $req['language'] === 'TH' ? 'ไทย' : 'อังกฤษ'; ?>)</span>
                    <?php endif; ?>
                </p>
            </div>
            <div>
                <p class="text-white/50 text-xs">วัตถุประสงค์</p>
                <p class="text-white/80 text-sm line-clamp-3"><?php echo htmlspecialchars($req['purpose'] ?? '-'); ?></p>
            </div>
            <div>
                <p class="text-white/50 text-xs">วันที่ขอ</p>
                <p class="text-white/80 text-sm"><?php echo formatDateThai($req['created_at']); ?></p>
            </div>

            <?php
            tpHrCertificatePrintForm(
                $reqId,
                'flex w-full',
                'flex min-h-[56px] w-full items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-600 hover:bg-violet-700 text-white text-sm font-semibold transition-colors touch-manipulation',
                '<i class="fas fa-print mr-2" aria-hidden="true"></i>ดู / พิมพ์เอกสาร',
                true
            );
            ?>

            <?php if ($st === 'PENDING'): ?>
            <div class="grid grid-cols-2 gap-2">
                <button type="button" onclick="updateDocStatus(<?php echo $reqId; ?>, 'PROCESSING')"
                        class="min-h-[48px] rounded-[var(--tp-ios-card-radius)] bg-sky-600 hover:bg-sky-700 text-white text-sm font-semibold touch-manipulation whitespace-nowrap">
                    <i class="fas fa-play mr-2" aria-hidden="true"></i>เริ่มจัดทำ
                </button>
                <button type="button" onclick="rejectDoc(<?php echo $reqId; ?>)"
                        class="min-h-[48px] rounded-[var(--tp-ios-card-radius)] bg-red-500/15 hover:bg-red-500/25 border border-red-500/35 text-red-200 text-sm font-semibold touch-manipulation whitespace-nowrap">
                    <i class="fas fa-times mr-2" aria-hidden="true"></i>ปฏิเสธ
                </button>
            </div>
            <?php elseif ($st === 'PROCESSING'): ?>
            <button type="button" onclick="completeDoc(<?php echo $reqId; ?>)"
                    class="w-full min-h-[56px] rounded-[var(--tp-ios-card-radius)] bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold touch-manipulation whitespace-nowrap">
                <i class="fas fa-check mr-2" aria-hidden="true"></i>จัดทำเสร็จ
            </button>
            <?php elseif (in_array($st, ['COMPLETED', 'READY', 'DELIVERED'], true) && !empty($req['document_url'])): ?>
            <a href="<?php echo htmlspecialchars($req['document_url']); ?>" target="_blank" rel="noopener noreferrer"
               class="flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-white/10 hover:bg-white/20 text-white text-sm font-semibold touch-manipulation">
                <i class="fas fa-download mr-2" aria-hidden="true"></i>ดาวน์โหลดเอกสาร
            </a>
            <?php else: ?>
            <button type="button" onclick="viewDocDetail(<?php echo $reqId; ?>)"
                    class="w-full min-h-[48px] rounded-[var(--tp-ios-card-radius)] bg-white/10 hover:bg-white/20 text-white text-sm font-semibold touch-manipulation whitespace-nowrap">
                <i class="fas fa-eye mr-2" aria-hidden="true"></i>ดูรายละเอียด
            </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain -mx-1 px-1 pb-px">
        <table class="w-full" style="min-width:1000px">
            <thead class="bg-white/5">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">รหัสคำขอ</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">พนักงาน</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ประเภทเอกสาร</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">วัตถุประสงค์</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">วันที่ขอ</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($requests as $req): ?>
                <tr class="hover:bg-white/[0.04]">
                    <td class="px-4 py-3 text-white font-mono text-sm">
                        #<?php echo str_pad($req['id'], 6, '0', STR_PAD_LEFT); ?>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-white font-medium"><?php echo htmlspecialchars($req['first_name_th'] . ' ' . $req['last_name_th']); ?></p>
                        <p class="text-white/50 text-xs"><?php echo htmlspecialchars($req['employee_code'] ?? ''); ?></p>
                    </td>
                    <td class="px-4 py-3 text-white">
                        <?php echo htmlspecialchars($req['template_name']); ?>
                        <?php if ($req['language']): ?>
                        <span class="text-white/50 text-xs">(<?php echo $req['language'] === 'TH' ? 'ไทย' : 'อังกฤษ'; ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-white/70 text-sm max-w-xs">
                        <p class="truncate" title="<?php echo htmlspecialchars($req['purpose'] ?? ''); ?>">
                            <?php echo htmlspecialchars($req['purpose'] ?? '-'); ?>
                        </p>
                    </td>
                    <td class="px-4 py-3 text-white/80 text-sm">
                        <?php echo formatDateThai($req['created_at']); ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php $rs = (string)($req['status'] ?? ''); ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-[var(--tp-ios-card-radius)] text-xs <?php echo $statusColors[$rs] ?? 'border border-white/15 bg-white/5 text-white/70'; ?>">
                            <?php echo htmlspecialchars($statusText[$rs] ?? $rs); ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex flex-wrap items-center justify-center gap-2">
                        <?php
                        tpHrCertificatePrintForm(
                            (int)$req['id'],
                            'inline-flex items-center',
                            'inline-flex min-h-[48px] min-w-[48px] items-center justify-center px-3 py-2 bg-violet-600 hover:bg-violet-700 text-white text-xs font-medium rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation',
                            '<i class="fas fa-print" aria-hidden="true"></i>',
                            true,
                            true,
                            null,
                            'ดู / พิมพ์เอกสาร'
                        );
                        ?>
                        <?php if ($req['status'] === 'PENDING'): ?>
                        <button type="button" onclick="updateDocStatus(<?php echo (int)$req['id']; ?>, 'PROCESSING')" 
                                class="inline-flex min-h-[48px] items-center gap-1.5 px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-xs font-semibold rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation whitespace-nowrap" title="เริ่มจัดทำ">
                            <i class="fas fa-play" aria-hidden="true"></i><span class="hidden xl:inline">เริ่ม</span>
                        </button>
                        <button type="button" onclick="rejectDoc(<?php echo (int)$req['id']; ?>)"
                                class="inline-flex min-h-[48px] items-center gap-1.5 px-3 py-2 bg-red-500/15 hover:bg-red-500/25 border border-red-500/35 text-red-200 text-xs font-medium rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation whitespace-nowrap" title="ปฏิเสธ">
                            <i class="fas fa-times" aria-hidden="true"></i><span class="hidden xl:inline">ปฏิเสธ</span>
                        </button>
                        <?php elseif ($req['status'] === 'PROCESSING'): ?>
                        <button type="button" onclick="completeDoc(<?php echo (int)$req['id']; ?>)" 
                                class="inline-flex min-h-[48px] items-center gap-1.5 px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation whitespace-nowrap" title="จัดทำเสร็จ">
                            <i class="fas fa-check" aria-hidden="true"></i><span class="hidden xl:inline">เสร็จ</span>
                        </button>
                        <?php elseif (in_array($req['status'], ['COMPLETED', 'READY', 'DELIVERED'], true) && $req['document_url']): ?>
                        <a href="<?php echo htmlspecialchars($req['document_url']); ?>" target="_blank" rel="noopener noreferrer"
                           class="inline-flex min-h-[48px] items-center gap-1.5 px-3 py-2 bg-white/10 hover:bg-white/20 text-white text-xs rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation" title="ดาวน์โหลด">
                            <i class="fas fa-download" aria-hidden="true"></i><span class="hidden xl:inline">ดาวน์โหลด</span>
                        </a>
                        <?php else: ?>
                        <button type="button" onclick="viewDocDetail(<?php echo (int)$req['id']; ?>)" class="inline-flex min-h-[48px] items-center gap-1.5 px-3 py-2 bg-white/10 hover:bg-white/20 text-white text-xs rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation whitespace-nowrap" title="ดูรายละเอียด">
                            <i class="fas fa-eye" aria-hidden="true"></i><span class="hidden xl:inline">ดู</span>
                        </button>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="px-6 py-4 border-t border-white/10 flex items-center justify-between">
        <p class="text-white/60 text-sm">
            แสดง <?php echo $offset + 1; ?> - <?php echo min($offset + $limit, $totalRecords); ?> 
            จาก <?php echo $totalRecords; ?> รายการ
        </p>
        <div class="flex gap-2">
            <?php if ($page > 1): ?>
            <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1]))); ?>" 
               class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center px-3 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation" aria-label="หน้าก่อน">
                <i class="fas fa-chevron-left" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $i]))); ?>" 
               class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center px-3 <?php echo $i === $page ? 'bg-violet-600 text-white' : 'bg-white/10 hover:bg-white/20 text-white'; ?> rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation">
                <?php echo (int)$i; ?>
            </a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page + 1]))); ?>" 
               class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center px-3 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation" aria-label="หน้าถัดไป">
                <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
</div>

<!-- Complete Modal -->
<div id="complete-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="complete-modal-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden rounded-[var(--tp-ios-card-radius)] pb-[calc(env(safe-area-inset-bottom,0px)+1rem)]">
        <form id="complete-form" class="p-6" enctype="multipart/form-data">
            <h3 id="complete-modal-title" class="text-xl font-bold text-white mb-4">จัดทำเอกสารเสร็จสิ้น</h3>
            <input type="hidden" name="request_id" id="complete-request-id">
            
            <div class="tp-native-form-group mb-4">
                <label for="complete-file" class="block text-white/80 text-sm mb-2">ไฟล์เอกสาร (PDF)</label>
                <input type="file" name="document" id="complete-file" accept=".pdf" class="input-field tp-native-input !py-2 w-full">
                <p class="text-white/50 text-xs mt-1">อัปโหลดไฟล์เอกสารที่จัดทำแล้ว</p>
            </div>
            
            <div class="tp-native-form-group mb-4">
                <label for="complete-note" class="block text-white/80 text-sm mb-2">หมายเหตุ</label>
                <textarea name="note" id="complete-note" rows="2" class="input-field tp-native-textarea w-full" placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)"></textarea>
            </div>
            
            <div class="flex flex-col sm:flex-row gap-4">
                <button type="button" onclick="closeCompleteModal()" class="flex-1 min-h-[48px] py-2 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-medium whitespace-nowrap">ยกเลิก</button>
                <button type="submit" class="flex-1 min-h-[56px] py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold whitespace-nowrap">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="reject-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="reject-modal-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden rounded-[var(--tp-ios-card-radius)] pb-[calc(env(safe-area-inset-bottom,0px)+1rem)]">
        <form id="reject-form" class="p-6">
            <h3 id="reject-modal-title" class="text-xl font-bold text-white mb-4">ปฏิเสธคำขอเอกสาร</h3>
            <input type="hidden" name="request_id" id="reject-request-id">
            <div class="tp-native-form-group mb-4">
                <label for="reject-reason" class="block text-white/80 text-sm mb-2">เหตุผล <span class="text-red-400" aria-hidden="true">*</span></label>
                <textarea name="reason" id="reject-reason" required rows="3" class="input-field tp-native-textarea w-full"></textarea>
            </div>
            <div class="flex flex-col sm:flex-row gap-4">
                <button type="button" onclick="closeRejectModal()" class="flex-1 min-h-[48px] py-2 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-medium whitespace-nowrap">ยกเลิก</button>
                <button type="submit" class="flex-1 min-h-[56px] py-2 bg-red-600 hover:bg-red-700 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold whitespace-nowrap">ปฏิเสธ</button>
            </div>
        </form>
    </div>
</div>

<!-- รายละเอียด (placeholder — แทน alert) -->
<div id="detail-stub-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="detail-stub-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto rounded-[var(--tp-ios-card-radius)] p-6 pb-[calc(env(safe-area-inset-bottom,0px)+1.5rem)]">
        <h3 id="detail-stub-title" class="text-xl font-bold text-white mb-2">รายละเอียดคำขอ</h3>
        <p class="text-white/65 text-sm mb-6">คำขอเอกสาร <span id="detail-stub-id" class="font-mono text-white"></span> — ยังไม่มีหน้ารายละเอียดแยกในรุ่นนี้ ใช้ปุ่มดู/พิมพ์หรือข้อมูลในตารางแทน</p>
        <button type="button" onclick="closeDetailStubModal()" class="w-full min-h-[48px] py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-medium whitespace-nowrap">ปิด</button>
    </div>
</div>

<script>
async function updateDocStatus(id, status) {
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('request_id', id);
    formData.append('status', status);
    formData.append('_token', '<?php echo csrfToken(); ?>');
    
    const response = await fetch('/api/certificate.php', { method: 'POST', body: formData });
    const result = await response.json();
    
    if (result.success) {
        showToast('อัปเดตสถานะสำเร็จ', 'success');
        setTimeout(() => location.reload(), 1000);
    } else {
        showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
    }
}

function completeDoc(id) {
    document.getElementById('complete-request-id').value = id;
    document.getElementById('complete-file').value = '';
    document.getElementById('complete-note').value = '';
    if (typeof uiOpenModal === 'function') uiOpenModal('complete-modal');
    else {
        const m = document.getElementById('complete-modal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}

function closeCompleteModal() {
    if (typeof uiCloseModal === 'function') uiCloseModal('complete-modal');
    else {
        const m = document.getElementById('complete-modal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

document.getElementById('complete-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'complete');
    formData.append('_token', '<?php echo csrfToken(); ?>');
    
    const response = await fetch('/api/certificate.php', { method: 'POST', body: formData });
    const result = await response.json();
    
    if (result.success) {
        showToast('บันทึกสำเร็จ', 'success');
        setTimeout(() => location.reload(), 1000);
    } else {
        showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
    }
});

function rejectDoc(id) {
    document.getElementById('reject-request-id').value = id;
    document.getElementById('reject-reason').value = '';
    if (typeof uiOpenModal === 'function') uiOpenModal('reject-modal');
    else {
        const m = document.getElementById('reject-modal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}

function closeRejectModal() {
    if (typeof uiCloseModal === 'function') uiCloseModal('reject-modal');
    else {
        const m = document.getElementById('reject-modal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

document.getElementById('reject-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData();
    formData.append('action', 'reject');
    formData.append('request_id', document.getElementById('reject-request-id').value);
    formData.append('reason', document.getElementById('reject-reason').value);
    formData.append('_token', '<?php echo csrfToken(); ?>');
    
    const response = await fetch('/api/certificate.php', { method: 'POST', body: formData });
    const result = await response.json();
    
    if (result.success) {
        showToast('บันทึกสำเร็จ', 'success');
        setTimeout(() => location.reload(), 1000);
    } else {
        showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
    }
});

document.getElementById('complete-modal').addEventListener('click', function(e) {
    if (e.target === this) closeCompleteModal();
});
document.getElementById('reject-modal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});

async function viewDocDetail(id) {
    document.getElementById('detail-stub-id').textContent = '#' + String(id).padStart(6, '0');
    if (typeof uiOpenModal === 'function') uiOpenModal('detail-stub-modal');
    else {
        const m = document.getElementById('detail-stub-modal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}

function closeDetailStubModal() {
    if (typeof uiCloseModal === 'function') uiCloseModal('detail-stub-modal');
    else {
        const m = document.getElementById('detail-stub-modal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

document.getElementById('detail-stub-modal').addEventListener('click', function(e) {
    if (e.target === this) closeDetailStubModal();
});
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
