<?php
/**
 * HR — LINE notification helpers
 *
 * Depends on:
 *   - config/line.php :: sendLineMessage($lineUserId, $text)
 *   - users.line_user_id
 *
 * All functions are no-ops (return false) when:
 *   - setting hr_line_notifications_enabled = '0'
 *   - LINE_CHANNEL_ACCESS_TOKEN is empty
 *   - target user has no line_user_id
 *
 * Never throw — always log via error_log() and return false.
 */

function hr_line_enabled() {
    // Lazy-load config/line.php ถ้ายังไม่ถูก include (hr.php ไม่ได้ include ผ่าน bootstrap)
    if (!defined('LINE_CHANNEL_ACCESS_TOKEN') && defined('HR_OWNER_PATH') && is_file(HR_OWNER_PATH . '/config/line.php')) {
        require_once HR_OWNER_PATH . '/config/line.php';
    }
    if (!function_exists('getSetting')) return false;
    if (!defined('LINE_CHANNEL_ACCESS_TOKEN') || empty(LINE_CHANNEL_ACCESS_TOKEN)) return false;
    return getSetting('hr_line_notifications_enabled', '1') === '1';
}

/**
 * ส่ง LINE ให้ user_id หนึ่งคน (lookup line_user_id จาก DB)
 *
 * @param PDO $pdo
 * @param int $user_id
 * @param string|array $payload — ถ้าเป็น string ส่ง text, ถ้าเป็น array ที่มี key 'flex' ส่ง flex
 *   รูปแบบ flex: ['flex' => <bubble array>, 'alt' => 'ข้อความสรุป']
 */
function hr_notify_user($pdo, $user_id, $payload, $event = 'unknown') {
    if (!hr_line_enabled() || !$user_id) return false;
    if (!class_exists('LineNotifier')) require_once HR_OWNER_PATH . '/core/Services/LineNotifier.php';
    return LineNotifier::sendToUser($pdo, (int)$user_id, $payload, [
        'module' => 'hr', 'event' => $event, 'target_type' => 'user',
    ]);
}

/**
 * ส่ง LINE ให้ผู้ดูแลระบบงาน HR (HR / Admin) — ใช้สำหรับเรื่องที่ต้องดำเนินการ เช่น อนุมัติใบลา
 * ไม่รวม Chairman/CEO เพื่อรักษาความเป็นส่วนตัวของข้อมูลพนักงานรายบุคคล
 * @param string|array $payload — string (text) หรือ ['flex'=>..., 'alt'=>...]
 * @param string $event — ชื่อเหตุการณ์สำหรับ logging
 * @return int จำนวนที่ส่งสำเร็จ
 */
function hr_notify_admins($pdo, $payload, $event = 'unknown') {
    if (!hr_line_enabled()) return 0;
    if (!class_exists('LineNotifier')) require_once HR_OWNER_PATH . '/core/Services/LineNotifier.php';
    return LineNotifier::sendToRoles($pdo, ['HR', 'Admin'], $payload, [
        'module' => 'hr', 'event' => $event, 'target_type' => 'admins',
    ]);
}

/**
 * ส่ง LINE ให้ผู้บริหารระดับสูงเท่านั้น (Chairman = ประธานกรรมการบริษัท, CEO = ประธานบริษัท)
 * ใช้สำหรับรายงานสรุปภาพรวม เพื่อรักษาความเป็นส่วนตัวของข้อมูลพนักงาน
 * @param string|array $payload
 * @param string $event — ชื่อเหตุการณ์สำหรับ logging
 * @return int จำนวนที่ส่งสำเร็จ
 */
function hr_notify_executives($pdo, $payload, $event = 'unknown') {
    if (!hr_line_enabled()) return 0;
    if (!class_exists('LineNotifier')) require_once HR_OWNER_PATH . '/core/Services/LineNotifier.php';
    return LineNotifier::sendToRoles($pdo, ['Chairman', 'CEO'], $payload, [
        'module' => 'hr', 'event' => $event, 'target_type' => 'executives',
    ]);
}

/**
 * ตัวช่วยเลือกส่ง text หรือ flex ตามประเภท payload (legacy — ใช้ LineNotifier แทน)
 * เก็บไว้เพื่อ backward compatibility กับ script เก่าที่เรียกโดยตรง
 */
function hr_line_dispatch($lineId, $payload) {
    if (!class_exists('LineNotifier')) require_once HR_OWNER_PATH . '/core/Services/LineNotifier.php';
    return LineNotifier::send($lineId, $payload, [
        'module' => 'hr', 'event' => 'legacy_dispatch', 'target_type' => 'direct',
    ]) ? true : false;
}

/** Send an immutable attendance warning to the affected employee for LIFF acknowledgement. */
function hr_send_discipline_warning(PDO $pdo, array $case, string $token): bool {
    if (!hr_line_enabled() || !function_exists('getLiffUrl') || !isLiffConfigured()) return false;
    $url = rtrim(getLiffUrl(), '/') . '/?page=hr_warning_sign&token=' . rawurlencode($token);
    $employee = trim((string)($case['first_name_th'] ?? '') . ' ' . (string)($case['last_name_th'] ?? ''));
    $bubble = [
        'type'=>'bubble','size'=>'giga',
        'header'=>['type'=>'box','layout'=>'vertical','backgroundColor'=>'#7C2D12','paddingAll'=>'20px','contents'=>[
            ['type'=>'text','text'=>'TP-ASSET · HUMAN RESOURCES','size'=>'xs','weight'=>'bold','color'=>'#FED7AA'],
            ['type'=>'text','text'=>'หนังสือเตือนการเข้างาน','size'=>'xl','weight'=>'bold','color'=>'#FFFFFF','margin'=>'sm'],
            ['type'=>'text','text'=>'กรุณาเปิดอ่านและตอบรับภายในเวลาที่กำหนด','size'=>'sm','color'=>'#FFEDD5','wrap'=>true,'margin'=>'sm'],
        ]],
        'body'=>['type'=>'box','layout'=>'vertical','spacing'=>'md','contents'=>[
            ['type'=>'box','layout'=>'horizontal','contents'=>[['type'=>'text','text'=>'เลขที่','color'=>'#6B7280','size'=>'sm','flex'=>2],['type'=>'text','text'=>(string)($case['warning_no']??'-'),'weight'=>'bold','size'=>'sm','flex'=>3,'align'=>'end']]],
            ['type'=>'box','layout'=>'horizontal','contents'=>[['type'=>'text','text'=>'พนักงาน','color'=>'#6B7280','size'=>'sm','flex'=>2],['type'=>'text','text'=>$employee,'weight'=>'bold','size'=>'sm','flex'=>3,'align'=>'end','wrap'=>true]]],
            ['type'=>'box','layout'=>'horizontal','contents'=>[['type'=>'text','text'=>'เหตุ','color'=>'#6B7280','size'=>'sm','flex'=>2],['type'=>'text','text'=>(string)($case['name_th']??'-'),'weight'=>'bold','size'=>'sm','flex'=>3,'align'=>'end','wrap'=>true]]],
            ['type'=>'separator','margin'=>'md'],
            ['type'=>'text','text'=>(string)($case['subject']??'หนังสือเตือน'),'weight'=>'bold','wrap'=>true],
            ['type'=>'text','text'=>'การกดเปิดเอกสารยังไม่ถือเป็นการยอมรับข้อกล่าวหา พนักงานสามารถรับทราบพร้อมคำชี้แจงหรือเลือกคัดค้านได้','size'=>'xs','color'=>'#6B7280','wrap'=>true],
        ]],
        'footer'=>['type'=>'box','layout'=>'vertical','spacing'=>'sm','contents'=>[
            ['type'=>'button','style'=>'primary','height'=>'sm','color'=>'#7C3AED','action'=>['type'=>'uri','label'=>'เปิดอ่านและเซ็นรับทราบ','uri'=>$url]],
            ['type'=>'text','text'=>'หากข้อมูลไม่ถูกต้อง กรุณาเลือก “คัดค้าน/ชี้แจง” ในหน้าเอกสาร','size'=>'xxs','color'=>'#6B7280','wrap'=>true,'align'=>'center'],
        ]],
    ];
    return LineNotifier::sendEvent($pdo,'hr.warning_issued',['flex'=>$bubble,'alt'=>'หนังสือเตือนการเข้างาน '.$case['warning_no']],['triggering_user_id'=>(int)$case['user_id'],'bypass_schedule'=>true]) > 0;
}

// ============================================================
// Flex Message Builders — HR notifications
// ============================================================

/**
 * Base bubble factory — giga size (full-width), logo header, professional layout
 *
 * @param string $icon emoji
 * @param string $title หัวข้อ
 * @param string $subtitle คำบรรยายใต้หัวข้อ
 * @param string $headerColor สี header (hex)
 * @param array $rows [[label, value, optional color], ...]
 * @param string|null $note ข้อความเพิ่มเติมใต้ rows (เช่นเหตุผล) — แสดงในกล่องสีเทาอ่อน
 * @param string|null $footerNote ข้อความล่างสุด (เช่น "ติดต่อ HR หากมีข้อสงสัย")
 * @param string|null $statusBadge ข้อความ badge (เช่น "รอดำเนินการ") — ถ้าไม่มีไม่แสดง
 * @param string|null $badgeColor สี badge bg
 */
/**
 * Public HTTPS base URL for assets embedded in LINE Flex (logo).
 * When HR/check-in bridge loads this file, PHP constants may still point at localhost.
 */
function hr_flex_public_base_url(): string {
    $candidates = [];
    foreach (['CRM_BASE_URL', 'APP_URL'] as $key) {
        if (defined($key)) {
            $candidates[] = rtrim((string) constant($key), '/');
        }
        $env = $_ENV[$key] ?? getenv($key);
        if (is_string($env) && $env !== '') {
            $candidates[] = rtrim($env, '/');
        }
    }
    $candidates[] = 'https://crm.tp-asset.com';

    $public = [];
    foreach ($candidates as $url) {
        if ($url === '' || preg_match('#localhost|127\.0\.0\.1#i', $url)) {
            continue;
        }
        if (!in_array($url, $public, true)) {
            $public[] = $url;
        }
    }

    foreach ($public as $url) {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        if ($host !== '' && str_contains($host, 'crm.')) {
            return $url;
        }
    }

    return $public[0] ?? 'https://crm.tp-asset.com';
}

function hr_flex_card($title, $subtitle, $accentColor, $rows, $note = null, $statusBadge = null) {
    // โลโก้แนวนอนบนพื้นขาว (ต้องเป็น public URL สำหรับ LINE CDN)
    $logoUrl = hr_flex_public_base_url() . '/asset/logo/tp-brand.png';

    // ===== HERO: โลโก้ (ซ้าย) + Status Badge (ขวา) =====
    $heroContents = [
        [
            'type' => 'image',
            'url' => $logoUrl,
            'size' => 'full',
            'aspectMode' => 'fit',
            'aspectRatio' => '3:1',
            'align' => 'start',
            'gravity' => 'center',
            'flex' => 2,
        ],
        [
            'type' => 'filler',
        ],
    ];
    if ($statusBadge !== null && $statusBadge !== '') {
        $heroContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'backgroundColor' => $accentColor,
            'cornerRadius' => '4px',
            'paddingAll' => '6px',
            'paddingStart' => '10px',
            'paddingEnd' => '10px',
            'justifyContent' => 'center',
            'flex' => 0,
            'contents' => [[
                'type' => 'text',
                'text' => $statusBadge,
                'color' => '#FFFFFF',
                'size' => 'xs',
                'weight' => 'bold',
                'align' => 'center',
            ]],
        ];
    }
    $hero = [
        'type' => 'box',
        'layout' => 'horizontal',
        'backgroundColor' => '#FFFFFF',
        'paddingTop' => '18px',
        'paddingBottom' => '14px',
        'paddingStart' => '20px',
        'paddingEnd' => '20px',
        'alignItems' => 'center',
        'spacing' => 'md',
        'contents' => $heroContents,
    ];

    // แถบสี accent 4px
    $accentBar = [
        'type' => 'box',
        'layout' => 'vertical',
        'height' => '4px',
        'backgroundColor' => $accentColor,
        'contents' => [],
    ];

    // ===== BODY =====
    $bodyContents = [];

    // Title — ใต้โลโก้ ขนาดเล็กลง ไม่ตกบรรทัด
    $bodyContents[] = [
        'type' => 'text',
        'text' => $title,
        'weight' => 'bold',
        'size' => 'md',
        'color' => '#111827',
        'wrap' => true,
    ];

    if ($subtitle !== null && $subtitle !== '') {
        $bodyContents[] = [
            'type' => 'text',
            'text' => $subtitle,
            'size' => 'sm',
            'color' => '#6B7280',
            'margin' => 'xs',
            'wrap' => true,
        ];
    }

    $bodyContents[] = ['type' => 'separator', 'margin' => 'lg'];

    // Rows
    $rowBox = [
        'type' => 'box',
        'layout' => 'vertical',
        'margin' => 'lg',
        'spacing' => 'sm',
        'contents' => [],
    ];
    foreach ($rows as $row) {
        $label = $row[0] ?? '';
        $value = (string)($row[1] ?? '');
        $color = $row[2] ?? '#111827';
        $rowBox['contents'][] = [
            'type' => 'box',
            'layout' => 'baseline',
            'spacing' => 'sm',
            'contents' => [
                ['type' => 'text', 'text' => $label, 'color' => '#6B7280', 'size' => 'sm', 'flex' => 3],
                ['type' => 'text', 'text' => $value, 'wrap' => true, 'color' => $color, 'size' => 'sm', 'flex' => 5, 'weight' => 'bold', 'align' => 'end'],
            ],
        ];
    }
    $bodyContents[] = $rowBox;

    // Note (ถ้ามี)
    if ($note !== null && $note !== '') {
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'backgroundColor' => '#F9FAFB',
            'borderColor' => '#E5E7EB',
            'borderWidth' => '1px',
            'cornerRadius' => '6px',
            'paddingAll' => '12px',
            'margin' => 'lg',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => $note,
                    'wrap' => true,
                    'size' => 'sm',
                    'color' => '#374151',
                    'align' => 'center',
                ],
            ],
        ];
    }

    // ===== FOOTER — ทางการ มีชื่อเต็ม + เลขที่ + วันเวลา =====
    $footer = [
        'type' => 'box',
        'layout' => 'vertical',
        'backgroundColor' => '#F9FAFB',
        'paddingAll' => '14px',
        'spacing' => 'xs',
        'contents' => [
            [
                'type' => 'text',
                'text' => 'TP-ASSET DEVELOPMENT CO., LTD.',
                'size' => 'xs',
                'color' => '#374151',
                'weight' => 'bold',
                'align' => 'center',
            ],
            [
                'type' => 'text',
                'text' => 'ระบบบริหารงานทรัพยากรบุคคล',
                'size' => 'xxs',
                'color' => '#9CA3AF',
                'align' => 'center',
            ],
            [
                'type' => 'text',
                'text' => date('วันที่ d/m/Y เวลา H:i น.'),
                'size' => 'xxs',
                'color' => '#9CA3AF',
                'align' => 'center',
                'margin' => 'sm',
            ],
        ],
    ];

    return [
        'type' => 'bubble',
        'size' => 'mega',
        'hero' => $hero,
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '0px',
            'spacing' => 'none',
            'contents' => [
                $accentBar,
                [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'paddingAll' => '20px',
                    'contents' => $bodyContents,
                ],
            ],
        ],
        'footer' => $footer,
    ];
}

function hr_flex_approval_footer(string $workflow, int $requestId): ?array {
    if ($requestId <= 0) return null;
    return ['type'=>'box','layout'=>'horizontal','spacing'=>'sm','contents'=>[
        ['type'=>'button','style'=>'primary','color'=>'#059669','height'=>'sm','action'=>['type'=>'postback','label'=>'อนุมัติ','data'=>'action=hr_approval&workflow='.$workflow.'&decision=approve&id='.$requestId,'displayText'=>'อนุมัติคำขอ']],
        ['type'=>'button','style'=>'primary','color'=>'#DC2626','height'=>'sm','action'=>['type'=>'postback','label'=>'ไม่อนุมัติ','data'=>'action=hr_approval&workflow='.$workflow.'&decision=reject&id='.$requestId,'displayText'=>'ไม่อนุมัติคำขอ']],
    ]];
}

function hr_flex_attach_approval_footer(array $payload, string $workflow, int $requestId): array {
    $footer = hr_flex_approval_footer($workflow, $requestId);
    if ($footer && isset($payload['flex']) && is_array($payload['flex'])) $payload['flex']['footer'] = $footer;
    return $payload;
}

function hr_flex_approval_action_result(string $title, array $rows, string $decision, string $reviewer, ?string $reason = null): array {
    $approved = strtolower($decision) === 'approve' || strtoupper($decision) === 'APPROVED';
    $label = $approved ? 'อนุมัติแล้ว' : 'ไม่อนุมัติ';
    $color = $approved ? '#059669' : '#DC2626';
    $rows[] = ['ผลการพิจารณา', $label, $color];
    $rows[] = ['ผู้พิจารณา', $reviewer];
    $rows[] = ['ดำเนินการเมื่อ', hr_fmt_datetime(date('Y-m-d H:i:s'))];
    $bubble = hr_flex_card('ยืนยันผล'.$title, 'ระบบบันทึกผลการพิจารณาเรียบร้อยแล้ว', $color, $rows, $reason ? 'เหตุผล: '.$reason : null, $label);
    return ['flex'=>$bubble,'alt'=>'ยืนยันผล'.$title.': '.$label];
}

/** Flow 1 — ใบลาใหม่ รออนุมัติ (→ admin) */
function hr_flex_new_leave($employee_name, $leave_type, $start, $end, $days, $notice_days, $reason, $below_threshold = false, $threshold_days = 7, $request_id = null) {
    $rows = [
        ['พนักงาน',    $employee_name],
        ['ประเภท',     $leave_type],
        ['วันที่',     hr_fmt_range($start, $end)],
        ['จำนวน',      number_format((float)$days, 1) . ' วัน'],
        ['แจ้งล่วงหน้า', (int)$notice_days . ' วัน' . ($below_threshold ? " ⚠️ ต่ำกว่าเกณฑ์ {$threshold_days}" : ''), $below_threshold ? '#D97706' : '#111827'],
    ];
    $bubble = hr_flex_card('ใบลาใหม่ รออนุมัติ', 'ได้รับคำร้องขอลาจากพนักงาน กรุณาเข้าระบบเพื่อพิจารณา', '#1D4ED8', $rows, 'เหตุผล: ' . $reason, 'รอดำเนินการ');
    return hr_flex_attach_approval_footer(['flex' => $bubble, 'alt' => "ใบลาใหม่ — {$employee_name} ({$leave_type}) " . hr_fmt_range($start, $end)], 'leave', (int)$request_id);
}

/** Flow 2 — อนุมัติใบลา (→ พนักงาน) */
function hr_flex_leave_approved($leave_type, $start, $end, $days, $note = '') {
    $rows = [
        ['ประเภท', $leave_type],
        ['วันที่',  hr_fmt_range($start, $end)],
        ['จำนวน',  number_format((float)$days, 1) . ' วัน'],
        ['สถานะ',  'อนุมัติ ✓', '#059669'],
    ];
    $bubble = hr_flex_card('ใบลาของคุณได้รับการอนุมัติ', 'ระบบได้บันทึกการอนุมัติใบลาของคุณเรียบร้อยแล้ว', '#047857', $rows, $note !== '' ? 'หมายเหตุจากผู้อนุมัติ: ' . $note : null, 'อนุมัติแล้ว');
    return ['flex' => $bubble, 'alt' => "ใบลาอนุมัติ — {$leave_type} " . hr_fmt_range($start, $end)];
}

/** Flow 3 — ไม่อนุมัติใบลา (→ พนักงาน) */
function hr_flex_leave_rejected($leave_type, $start, $end, $reason = '') {
    $rows = [
        ['ประเภท', $leave_type],
        ['วันที่',  hr_fmt_range($start, $end)],
        ['สถานะ',  'ไม่อนุมัติ ✗', '#DC2626'],
    ];
    $bubble = hr_flex_card('ใบลาของคุณไม่ได้รับการอนุมัติ', 'ระบบได้บันทึกคำสั่งไม่อนุมัติจากผู้มีอำนาจ', '#B91C1C', $rows, $reason !== '' ? 'เหตุผล: ' . $reason : null, 'ไม่อนุมัติ');
    return ['flex' => $bubble, 'alt' => "ใบลาไม่อนุมัติ — {$leave_type} " . hr_fmt_range($start, $end)];
}

/** Flow 4 — Cron มาร์คขาดงาน (→ พนักงาน) */
function hr_flex_auto_absent($date) {
    $rows = [
        ['วันที่', hr_fmt_date($date)],
        ['สถานะ', 'ขาดงาน', '#DC2626'],
        ['สาเหตุ', 'ไม่มีการเช็คอินและไม่ได้แจ้งล่วงหน้า'],
    ];
    $bubble = hr_flex_card('บันทึกขาดงานอัตโนมัติ', 'ระบบตรวจพบว่าคุณไม่ได้เช็คอินและไม่ได้แจ้งล่วงหน้า', '#B45309', $rows, 'หากมีเหตุจำเป็น กรุณาติดต่อ HR โดยเร็วเพื่อแก้ไขสถานะ — อาจมีผลต่อการคำนวณเงินเดือน', 'ต้องดำเนินการ');
    return ['flex' => $bubble, 'alt' => "บันทึกขาดงาน {$date}"];
}

/**
 * Flow 6 — พนักงานแจ้งเข้างานสายล่วงหน้า (→ HR + Executives)
 * Context: feature Planned Late Start — ให้ HR+Chairman+CEO ทราบทันทีที่พนักงานยื่นคำขอ
 *
 * @param string $employee_name
 * @param string $target_date       วันที่จะเข้างานสาย (YYYY-MM-DD)
 * @param string $planned_time      เวลาเข้าที่แจ้งไว้ (HH:MM[:SS])
 * @param string $reason
 * @param string|null $requested_at เวลายื่นคำขอ
 */
function hr_flex_planned_late_request($employee_name, $target_date, $planned_time, $reason, $requested_at = null, $attendance_id = null) {
    $rows = [
        ['พนักงาน',  $employee_name],
        ['วันที่',   hr_fmt_date($target_date)],
        ['เวลาเข้า', substr($planned_time, 0, 5) . ' น.', '#D97706'],
    ];
    if ($requested_at) {
        $rows[] = ['ยื่นเมื่อ', hr_fmt_datetime($requested_at)];
    }
    $subtitle = 'คำขอรอผู้มีสิทธิ์อนุมัติ — ยังไม่มีผลต่อเวลาเข้างานหรือเงินเดือน';
    $bubble = hr_flex_card('คำขอเข้างานสายล่วงหน้า', $subtitle, '#D97706', $rows, 'เหตุผล: ' . $reason, 'รออนุมัติ');
    if ((int)$attendance_id > 0) {
        $bubble['footer'] = ['type'=>'box','layout'=>'horizontal','spacing'=>'sm','contents'=>[
            ['type'=>'button','style'=>'primary','color'=>'#059669','height'=>'sm','action'=>['type'=>'postback','label'=>'อนุมัติ','data'=>'action=hr_planned_late_approve&id='.(int)$attendance_id,'displayText'=>'อนุมัติเข้างานสาย']],
            ['type'=>'button','style'=>'primary','color'=>'#DC2626','height'=>'sm','action'=>['type'=>'postback','label'=>'ไม่อนุมัติ','data'=>'action=hr_planned_late_reject&id='.(int)$attendance_id,'displayText'=>'ไม่อนุมัติเข้างานสาย']],
        ]];
    }
    return ['flex' => $bubble, 'alt' => "แจ้งเข้างานสาย — {$employee_name} " . hr_fmt_date($target_date) . ' ' . substr($planned_time, 0, 5)];
}

function hr_flex_planned_late_pending($target_date, $planned_time) {
    $rows = [['วันที่',hr_fmt_date($target_date)],['เวลาเข้า',substr($planned_time,0,5).' น.','#D97706'],['สถานะ','รออนุมัติ','#D97706']];
    $bubble = hr_flex_card('ส่งคำขอเข้างานสายแล้ว','ระบบจะใช้เวลาเข้างานปกติจนกว่าผู้อนุมัติจะอนุมัติ','#D97706',$rows,null,'รออนุมัติ');
    return ['flex'=>$bubble,'alt'=>'คำขอเข้างานสายกำลังรออนุมัติ'];
}

function hr_flex_planned_late_decision($target_date, $planned_time, $decision, $note = '') {
    $approved = strtoupper((string)$decision) === 'APPROVED';
    $rows = [['วันที่',hr_fmt_date($target_date)],['เวลาเข้า',substr($planned_time,0,5).' น.'],['ผล',$approved?'อนุมัติแล้ว':'ไม่อนุมัติ',$approved?'#059669':'#DC2626']];
    $bubble = hr_flex_card('ผลคำขอเข้างานสาย',$approved?'เวลาที่อนุมัติจะถูกใช้เป็นฐานการลงเวลา':'ระบบจะใช้เวลาเข้างานปกติ',$approved?'#059669':'#DC2626',$rows,$note!==''?'หมายเหตุ: '.$note:null,$approved?'อนุมัติแล้ว':'ไม่อนุมัติ');
    return ['flex'=>$bubble,'alt'=>'ผลคำขอเข้างานสาย: '.($approved?'อนุมัติ':'ไม่อนุมัติ')];
}

/** Flex confirmation shown to the approver immediately after a LINE postback. */
function hr_flex_planned_late_action_result($employee_name, $target_date, $planned_time, $reason, $decision, $reviewer_name, $reviewed_at = null) {
    $approved = strtoupper((string)$decision) === 'APPROVED';
    $result = $approved ? 'อนุมัติแล้ว' : 'ไม่อนุมัติ';
    $color = $approved ? '#059669' : '#DC2626';
    $rows = [
        ['พนักงาน', (string)$employee_name],
        ['วันที่', hr_fmt_date($target_date)],
        ['เวลาเข้า', substr((string)$planned_time, 0, 5) . ' น.'],
        ['ผลการพิจารณา', $result, $color],
        ['ผู้พิจารณา', (string)$reviewer_name],
    ];
    if ($reviewed_at) {
        $rows[] = ['ดำเนินการเมื่อ', hr_fmt_datetime($reviewed_at)];
    }
    $note = trim((string)$reason) !== '' ? 'เหตุผลของพนักงาน: ' . mb_substr(trim((string)$reason), 0, 200) : null;
    $bubble = hr_flex_card(
        'ยืนยันผลคำขอเข้างานสาย',
        $approved ? 'อนุมัติสำเร็จ — เวลาเข้างานที่แจ้งมีผลแล้ว' : 'บันทึกผลไม่อนุมัติเรียบร้อยแล้ว',
        $color,
        $rows,
        $note,
        $result
    );
    return ['flex'=>$bubble, 'alt'=>'ยืนยันผลคำขอเข้างานสาย: '.$result.' — '.$employee_name];
}

/**
 * Flow 6d — พนักงานแจ้งมาสายล่วงหน้า (CRM notify_late / late_excused) → HR + ผู้บริหาร
 */
function hr_flex_notify_late_admin($employee_name, $target_date, $reason, $notified_at = null) {
    $rows = [
        ['พนักงาน', $employee_name],
        ['วันที่',  hr_fmt_date($target_date)],
        ['สถานะ',  'แจ้งมาสายล่วงหน้า', '#D97706'],
    ];
    if ($notified_at) {
        $rows[] = ['แจ้งเมื่อ', hr_fmt_datetime($notified_at)];
    }
    if ($reason !== '') {
        $rows[] = ['เหตุผล', mb_substr((string)$reason, 0, 120)];
    }
    $bubble = hr_flex_card(
        'พนักงานแจ้งมาสายล่วงหน้า',
        'พนักงานแจ้งล่วงหน้าว่าจะมาสาย — ระบบตั้ง late_excused ไว้แล้ว',
        '#D97706',
        $rows,
        'หากไม่มาตามที่แจ้ง อาจถูกมาร์คขาดงานและมีผลต่อเงินเดือน',
        'แจ้งแล้ว'
    );
    return ['flex' => $bubble, 'alt' => "แจ้งมาสาย — {$employee_name} " . hr_fmt_date($target_date)];
}

/**
 * Flow 6b — ยกเลิกการแจ้งเข้างานสายล่วงหน้า (→ HR + Executives)
 */
function hr_flex_planned_late_cancelled($employee_name, $target_date, $previous_time = null) {
    $rows = [
        ['พนักงาน', $employee_name],
        ['วันที่',  hr_fmt_date($target_date)],
    ];
    if ($previous_time) {
        $rows[] = ['เวลาที่เคยแจ้ง', substr($previous_time, 0, 5) . ' น.', '#6B7280'];
    }
    $rows[] = ['สถานะ', 'ยกเลิก ✗', '#DC2626'];
    $subtitle = 'พนักงานยกเลิกคำขอเข้างานสายล่วงหน้า — กลับสู่เวลาเข้างานปกติ';
    $bubble = hr_flex_card('ยกเลิกแจ้งเข้างานสาย', $subtitle, '#6B7280', $rows, null, 'ยกเลิกแล้ว');
    return ['flex' => $bubble, 'alt' => "ยกเลิกแจ้งเข้างานสาย — {$employee_name} " . hr_fmt_date($target_date)];
}

/**
 * Flow 6c — Confirm ถึงพนักงานว่าแจ้งสำเร็จ (→ Employee ตัวเอง)
 */
function hr_flex_planned_late_confirmed($target_date, $planned_time, $grace_min = 30) {
    $rows = [
        ['วันที่',    hr_fmt_date($target_date)],
        ['เวลาเข้า',  substr($planned_time, 0, 5) . ' น.', '#D97706'],
        ['Grace',    "± {$grace_min} นาที", '#6B7280'],
        ['สถานะ',    'บันทึกแล้ว ✓', '#059669'],
    ];
    $note = 'หากเข้างานภายในเวลาที่แจ้งไว้ (± ' . (int)$grace_min . ' นาที) จะไม่ถูกหักเงิน';
    $bubble = hr_flex_card('ยืนยันการแจ้งเข้างานสาย', 'ระบบได้บันทึกเวลาเข้างานที่คุณแจ้งไว้เรียบร้อยแล้ว', '#047857', $rows, $note, 'ยืนยัน');
    return ['flex' => $bubble, 'alt' => "ยืนยันแจ้งเข้างานสาย " . hr_fmt_date($target_date) . ' ' . substr($planned_time, 0, 5)];
}

/**
 * Flow 7 — สรุปการขาดงานของพนักงาน (→ HR + Admin + Executives)
 * ใช้คู่กับ hr_flex_auto_absent (ซึ่งส่งถึงพนักงาน) — ส่วนนี้แจ้ง HR/ผู้บริหาร
 */
function hr_flex_auto_absent_admin($employee_name, $date) {
    $rows = [
        ['พนักงาน', $employee_name],
        ['วันที่',  hr_fmt_date($date)],
        ['สถานะ',  'ขาดงาน', '#DC2626'],
        ['สาเหตุ', 'ไม่เช็คอินและไม่ได้แจ้งล่วงหน้า'],
    ];
    $bubble = hr_flex_card('พนักงานขาดงาน (ไม่แจ้งล่วงหน้า)', 'ระบบมาร์คพนักงานเป็นขาดงานอัตโนมัติ', '#DC2626', $rows, null, 'ต้องตรวจสอบ');
    return ['flex' => $bubble, 'alt' => "ขาดงาน — {$employee_name} " . hr_fmt_date($date)];
}

/** ป้ายประเภทคำขอแก้เวลา */
function hr_adjustment_type_label($type) {
    return match ((string)$type) {
        'check_in'  => 'เช็คอิน',
        'check_out' => 'เช็คเอาท์',
        'both'      => 'เช็คอิน + เช็คเอาท์',
        default     => (string)$type,
    };
}

/** เวลา HH:MM จาก datetime */
function hr_fmt_time_short($dt) {
    if ($dt === null || $dt === '') return '-';
    $t = strtotime((string)$dt);
    return $t ? date('H:i', $t) . ' น.' : substr((string)$dt, 11, 5) . ' น.';
}

/**
 * Flow 8a — คำขอแก้เวลาใหม่ (→ HR + Admin + Chairman + CEO)
 */
function hr_flex_attendance_adjustment_requested(
    $employee_name,
    $attendance_date,
    $adjustment_type,
    $original_check_in,
    $original_check_out,
    $requested_check_in,
    $requested_check_out,
    $reason,
    $request_id = null
) {
    $rows = [
        ['พนักงาน', $employee_name],
        ['วันที่',  hr_fmt_date($attendance_date)],
        ['ประเภท',  hr_adjustment_type_label($adjustment_type), '#4338CA'],
    ];
    if ($original_check_in || $requested_check_in) {
        $rows[] = ['เช็คอิน (เดิม → ขอ)', hr_fmt_time_short($original_check_in) . ' → ' . hr_fmt_time_short($requested_check_in)];
    }
    if ($original_check_out || $requested_check_out) {
        $rows[] = ['เช็คเอาท์ (เดิม → ขอ)', hr_fmt_time_short($original_check_out) . ' → ' . hr_fmt_time_short($requested_check_out)];
    }
    if ($reason !== '') {
        $rows[] = ['เหตุผล', mb_substr((string)$reason, 0, 120)];
    }
    $bubble = hr_flex_card(
        'คำขอแก้ไขเวลาลงงาน',
        'พนักงานยื่นคำขอแก้ไขเวลา — รอการอนุมัติจากผู้บริหาร',
        '#4338CA',
        $rows,
        'กรุณาตรวจสอบและอนุมัติในระบบ HR',
        'รออนุมัติ'
    );
    return hr_flex_attach_approval_footer(['flex' => $bubble, 'alt' => "คำขอแก้เวลา — {$employee_name} " . hr_fmt_date($attendance_date)], 'adjustment', (int)$request_id);
}

/**
 * Flow 8b — อนุมัติคำขอแก้เวลา (→ พนักงาน)
 */
function hr_flex_attendance_adjustment_approved($attendance_date, $adjustment_type, $note = '') {
    $rows = [
        ['วันที่',  hr_fmt_date($attendance_date)],
        ['ประเภท', hr_adjustment_type_label($adjustment_type), '#059669'],
        ['สถานะ',  'อนุมัติแล้ว ✓', '#059669'],
    ];
    if ($note !== '') {
        $rows[] = ['หมายเหตุ', mb_substr((string)$note, 0, 120)];
    }
    $bubble = hr_flex_card('อนุมัติคำขอแก้ไขเวลา', 'ระบบได้ปรับเวลาลงงานตามที่คุณขอแล้ว', '#059669', $rows, null, 'อนุมัติ');
    return ['flex' => $bubble, 'alt' => 'อนุมัติคำขอแก้ไขเวลา ' . hr_fmt_date($attendance_date)];
}

/**
 * Flow 8c — ปฏิเสธคำขอแก้เวลา (→ พนักงาน)
 */
function hr_flex_attendance_adjustment_rejected($attendance_date, $adjustment_type, $reason = '') {
    $rows = [
        ['วันที่',  hr_fmt_date($attendance_date)],
        ['ประเภท', hr_adjustment_type_label($adjustment_type), '#DC2626'],
        ['สถานะ',  'ไม่อนุมัติ ✗', '#DC2626'],
    ];
    if ($reason !== '') {
        $rows[] = ['เหตุผล', mb_substr((string)$reason, 0, 120)];
    }
    $bubble = hr_flex_card('ไม่อนุมัติคำขอแก้ไขเวลา', 'คำขอแก้ไขเวลาของคุณไม่ได้รับการอนุมัติ', '#DC2626', $rows, null, 'ไม่อนุมัติ');
    return ['flex' => $bubble, 'alt' => 'ไม่อนุมัติคำขอแก้ไขเวลา ' . hr_fmt_date($attendance_date)];
}

/** Flow 5 — Cron สรุปรายวัน (→ admin) */
function hr_flex_daily_summary($date, $absent, $on_leave, $present, $late = null, $total = null) {
    $rows = [
        ['วันที่',       hr_fmt_date($date)],
    ];
    if ($total !== null && (int) $total > 0) {
        $rows[] = ['พนักงานทั้งหมด', (int) $total . ' คน', '#4338CA'];
    }
    $rows = array_merge($rows, [
        ['มาทำงาน',     (int)$present . ' คน', '#059669'],
        ['มาสาย',       (int)($late ?? 0) . ' คน', ((int)($late ?? 0)) > 0 ? '#D97706' : '#111827'],
        ['ลางาน',       (int)$on_leave . ' คน', '#2563EB'],
        ['ขาดงาน',      (int)$absent . ' คน', $absent > 0 ? '#DC2626' : '#111827'],
    ]);
    $bubble = hr_flex_card('สรุปการเข้างานประจำวัน', 'ข้อมูลรวมการเข้างานของพนักงานทั้งหมด', '#4338CA', $rows, null, 'รายงานรายวัน');
    return ['flex' => $bubble, 'alt' => "สรุปการเข้างาน {$date} — มาสาย " . (int)($late ?? 0) . " ขาด {$absent} ลา {$on_leave}"];
}

/** วันที่แบบไทย dd/mm/YYYY */
function hr_fmt_date($d) {
    $t = strtotime($d);
    return $t ? date('d/m/Y', $t) : (string)$d;
}

/** วันที่+เวลา แบบไทย dd/mm/YYYY HH:MM */
function hr_fmt_datetime($d) {
    $t = strtotime($d);
    return $t ? date('d/m/Y H:i', $t) : (string)$d;
}

/** ชื่อวันในสัปดาห์ (0=อาทิตย์ … 6=เสาร์) */
function hr_thai_dow(int $dow): string {
    static $names = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
    return $names[$dow] ?? (string)$dow;
}

/**
 * Flow 9a — คำขอสลับวันหยุด (→ ผู้บริหาร)
 */
function hr_flex_dayoff_requested($employee_name, $week_start, $week_end, $original_dow, $requested_dow, $reason, $request_id = null) {
    $rows = [
        ['พนักงาน', $employee_name],
        ['สัปดาห์', hr_fmt_date($week_start) . ' – ' . hr_fmt_date($week_end)],
        ['หยุดเดิม', hr_thai_dow((int)$original_dow), '#6B7280'],
        ['ขอหยุดแทน', hr_thai_dow((int)$requested_dow), '#4338CA'],
    ];
    if ($reason !== '') {
        $rows[] = ['เหตุผล', mb_substr((string)$reason, 0, 120)];
    }
    $bubble = hr_flex_card('คำขอสลับวันหยุด', 'รอผู้บริหารอนุมัติ', '#4338CA', $rows, null, 'รออนุมัติ');
    return hr_flex_attach_approval_footer(['flex' => $bubble, 'alt' => "สลับวันหยุด — {$employee_name} " . hr_thai_dow((int)$requested_dow)], 'dayoff', (int)$request_id);
}

/** Flow 9b — อนุมัติสลับวันหยุด (→ พนักงาน) */
function hr_flex_dayoff_approved($week_start, $week_end, $requested_dow, $note = '') {
    $rows = [
        ['สัปดาห์', hr_fmt_date($week_start) . ' – ' . hr_fmt_date($week_end)],
        ['วันหยุดใหม่', hr_thai_dow((int)$requested_dow), '#059669'],
        ['สถานะ', 'อนุมัติแล้ว ✓', '#059669'],
    ];
    if ($note !== '') {
        $rows[] = ['หมายเหตุ', mb_substr((string)$note, 0, 120)];
    }
    $bubble = hr_flex_card('อนุมัติสลับวันหยุด', 'คำขอของคุณได้รับการอนุมัติแล้ว', '#059669', $rows, null, 'อนุมัติ');
    return ['flex' => $bubble, 'alt' => 'อนุมัติสลับวันหยุด ' . hr_fmt_date($week_start)];
}

/** Flow 9c — ปฏิเสธสลับวันหยุด (→ พนักงาน) */
function hr_flex_dayoff_rejected($week_start, $week_end, $reason = '') {
    $rows = [
        ['สัปดาห์', hr_fmt_date($week_start) . ' – ' . hr_fmt_date($week_end)],
        ['สถานะ', 'ไม่อนุมัติ ✗', '#DC2626'],
    ];
    if ($reason !== '') {
        $rows[] = ['เหตุผล', mb_substr((string)$reason, 0, 120)];
    }
    $bubble = hr_flex_card('ไม่อนุมัติสลับวันหยุด', 'คำขอของคุณไม่ได้รับการอนุมัติ', '#DC2626', $rows, null, 'ไม่อนุมัติ');
    return ['flex' => $bubble, 'alt' => 'ไม่อนุมัติสลับวันหยุด ' . hr_fmt_date($week_start)];
}

/** Flow 9d — พนักงานยกเลิกการสลับที่อนุมัติแล้ว (→ ผู้บริหาร) */
function hr_flex_dayoff_cancelled($employee_name, $week_start, $week_end, $approved_dow, $restored_dow) {
    $rows = [
        ['พนักงาน', $employee_name],
        ['สัปดาห์', hr_fmt_date($week_start) . ' – ' . hr_fmt_date($week_end)],
        ['วันที่เคยอนุมัติ', hr_thai_dow((int)$approved_dow), '#D97706'],
        ['กลับไปหยุดประจำ', hr_thai_dow((int)$restored_dow), '#0284C7'],
        ['สถานะ', 'ยกเลิกการสลับแล้ว', '#0284C7'],
    ];
    $bubble = hr_flex_card('ยกเลิกการสลับวันหยุด', 'แจ้งผู้บริหารเพื่อรับทราบ', '#0284C7', $rows, null, 'ยกเลิกแล้ว');
    return ['flex' => $bubble, 'alt' => "ยกเลิกสลับวันหยุด — {$employee_name} กลับไปหยุด" . hr_thai_dow((int)$restored_dow)];
}

/**
 * Flow 9e — คำขอมาทำงานวันหยุดประจำปี (→ ผู้บริหาร)
 */
function hr_flex_holiday_work_requested($employee_name, $holiday_date, $comp_date, $holiday_name, $reason, $request_id = null) {
    $rows = [
        ['พนักงาน', $employee_name],
        ['วันหยุด (มาทำงาน)', hr_fmt_date($holiday_date), '#D97706'],
    ];
    if ($holiday_name !== '') {
        $rows[] = ['ชื่อวันหยุด', mb_substr((string)$holiday_name, 0, 80)];
    }
    if ($comp_date) {
        $rows[] = ['หยุดชดเชย', hr_fmt_date($comp_date), '#4338CA'];
    }
    if ($reason !== '') {
        $rows[] = ['เหตุผล', mb_substr((string)$reason, 0, 120)];
    }
    $bubble = hr_flex_card('คำขอมาทำงานวันหยุด', 'รอผู้บริหารอนุมัติ', '#D97706', $rows, null, 'รออนุมัติ');
    return hr_flex_attach_approval_footer(['flex' => $bubble, 'alt' => "มาทำงานวันหยุด — {$employee_name} " . hr_fmt_date($holiday_date)], 'holiday_work', (int)$request_id);
}

/** Flow 9e — อนุมัติมาทำงานวันหยุด (→ พนักงาน) */
function hr_flex_holiday_work_approved($holiday_date, $comp_date, $note = '') {
    $rows = [
        ['วันหยุด (มาทำงาน)', hr_fmt_date($holiday_date), '#059669'],
        ['สถานะ', 'อนุมัติแล้ว ✓', '#059669'],
    ];
    if ($comp_date) {
        $rows[] = ['หยุดชดเชย', hr_fmt_date($comp_date), '#4338CA'];
    }
    if ($note !== '') {
        $rows[] = ['หมายเหตุ', mb_substr((string)$note, 0, 120)];
    }
    $bubble = hr_flex_card('อนุมัติมาทำงานวันหยุด', 'คำขอของคุณได้รับการอนุมัติแล้ว', '#059669', $rows, null, 'อนุมัติ');
    return ['flex' => $bubble, 'alt' => 'อนุมัติมาทำงานวันหยุด ' . hr_fmt_date($holiday_date)];
}

/** Flow 9f — ปฏิเสธมาทำงานวันหยุด (→ พนักงาน) */
function hr_flex_holiday_work_rejected($holiday_date, $reason = '') {
    $rows = [
        ['วันหยุด', hr_fmt_date($holiday_date)],
        ['สถานะ', 'ไม่อนุมัติ ✗', '#DC2626'],
    ];
    if ($reason !== '') {
        $rows[] = ['เหตุผล', mb_substr((string)$reason, 0, 120)];
    }
    $bubble = hr_flex_card('ไม่อนุมัติมาทำงานวันหยุด', 'คำขอของคุณไม่ได้รับการอนุมัติ', '#DC2626', $rows, null, 'ไม่อนุมัติ');
    return ['flex' => $bubble, 'alt' => 'ไม่อนุมัติมาทำงานวันหยุด ' . hr_fmt_date($holiday_date)];
}

/** ป้ายประเภทลงเวลานอกสถานที่ */
function hr_outside_request_type_label(string $type): string {
    return strtoupper($type) === 'CHECK_OUT' ? 'เช็คเอาท์' : 'เช็คอิน';
}

/**
 * Flow 10a — คำขอลงเวลานอกสถานที่ (→ ผู้บริหาร)
 */
function hr_flex_outside_attendance_requested($employee_name, $request_date, $request_type, $request_time, $reason, $request_id = null) {
    $rows = [
        ['พนักงาน', $employee_name],
        ['วันที่', hr_fmt_date($request_date)],
        ['ประเภท', hr_outside_request_type_label($request_type), '#4338CA'],
        ['เวลา', hr_fmt_time_short($request_time)],
    ];
    if ($reason !== '') {
        $rows[] = ['เหตุผล', mb_substr((string)$reason, 0, 120)];
    }
    $bubble = hr_flex_card('ลงเวลานอกสถานที่', 'รอผู้บริหารอนุมัติ', '#4338CA', $rows, null, 'รออนุมัติ');
    return hr_flex_attach_approval_footer(['flex' => $bubble, 'alt' => "นอกสถานที่ — {$employee_name} " . hr_fmt_date($request_date)], 'outside', (int)$request_id);
}

/** Flow 10b — อนุมัติลงเวลานอกสถานที่ (→ พนักงาน) */
function hr_flex_outside_attendance_approved($request_date, $request_type, $note = '') {
    $rows = [
        ['วันที่', hr_fmt_date($request_date)],
        ['ประเภท', hr_outside_request_type_label($request_type), '#059669'],
        ['สถานะ', 'อนุมัติแล้ว ✓', '#059669'],
    ];
    if ($note !== '') {
        $rows[] = ['หมายเหตุ', mb_substr((string)$note, 0, 120)];
    }
    $bubble = hr_flex_card('อนุมัติลงเวลานอกสถานที่', 'ระบบบันทึกเวลาตามที่ขอแล้ว', '#059669', $rows, null, 'อนุมัติ');
    return ['flex' => $bubble, 'alt' => 'อนุมัติลงเวลานอกสถานที่ ' . hr_fmt_date($request_date)];
}

/** Flow 10c — ปฏิเสธลงเวลานอกสถานที่ (→ พนักงาน) */
function hr_flex_outside_attendance_rejected($request_date, $request_type, $reason = '') {
    $rows = [
        ['วันที่', hr_fmt_date($request_date)],
        ['ประเภท', hr_outside_request_type_label($request_type), '#DC2626'],
        ['สถานะ', 'ไม่อนุมัติ ✗', '#DC2626'],
    ];
    if ($reason !== '') {
        $rows[] = ['เหตุผล', mb_substr((string)$reason, 0, 120)];
    }
    $bubble = hr_flex_card('ไม่อนุมัติลงเวลานอกสถานที่', 'คำขอของคุณไม่ได้รับการอนุมัติ', '#DC2626', $rows, null, 'ไม่อนุมัติ');
    return ['flex' => $bubble, 'alt' => 'ไม่อนุมัติลงเวลานอกสถานที่ ' . hr_fmt_date($request_date)];
}

/** ช่วงวันที่ */
function hr_fmt_range($start, $end) {
    if ($start === $end) return hr_fmt_date($start);
    return hr_fmt_date($start) . ' – ' . hr_fmt_date($end);
}

/**
 * ตรวจว่าวันนี้ (หรือวันที่ระบุ) เป็นวันหยุดนักขัตฤกษ์/บริษัทใน hr_holidays
 */
function hr_is_company_holiday($pdo, $date) {
    if (!class_exists('HrOperationsService', false)) {
        require_once dirname(__DIR__, 2) . '/core/Services/HrOperationsService.php';
    }
    try {
        return (new HrOperationsService($pdo))->isHoliday($date);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @deprecated ใช้ hr_is_company_holiday() สำหรับวันหยุดบริษัท — ไม่ hardcode อาทิตย์
 */
function hr_is_holiday($pdo, $date) {
    return hr_is_company_holiday($pdo, $date);
}
