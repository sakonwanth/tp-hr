<?php
if (!defined('BASE_PATH')) {http_response_code(403);exit;}
/**
 * Payroll LINE notification helpers — Flex builders for payroll events.
 *
 * Follows the same visual language as hr_flex_card() (logo hero, accent bar, rows).
 * Depends on: modules/hr/notifications.php (for hr_flex_card + hr_fmt_month)
 */

if (!function_exists('hr_flex_card')) {
    require_once __DIR__ . '/../hr/notifications.php';
}

/**
 * Build attendance deduction rules snapshot from system_settings.
 * ใช้แสดงในกล่อง "เงื่อนไขการหักเงิน (เข้าทำงาน)" บน Flex เพื่อให้พนักงาน
 * เข้าใจว่ายอดหัก ขาด/ลา/มาสาย มาจากกติกาอะไร
 *
 * @return array ['enabled'=>bool, 'absent'=>float, 'late30'=>float, 'late60'=>float,
 *                'over60_as_absent'=>bool, 'leave_advance_days'=>int]
 */
function payroll_get_attendance_rules_snapshot(): array {
    $g = fn($k, $d) => function_exists('getSetting') ? getSetting($k, $d) : $d;
    return [
        'enabled'              => (int)$g('payroll_attendance_enabled', '1') === 1,
        'absent'               => (float)$g('payroll_absent_rate', '600'),
        'late30'               => (float)$g('payroll_late_30_rate', '150'),
        'late60'               => (float)$g('payroll_late_60_rate', '300'),
        'over60_as_absent'     => (int)$g('payroll_late_over60_as_absent', '1') === 1,
        'leave_advance_days'   => (int)$g('payroll_leave_advance_days', '7'),
        'late_tier1_min'       => max(1, min(59, (int)$g('payroll_late_tier1_min_minutes', '20'))),
        // S2: ข้อมูล "แจ้งเข้างานสายล่วงหน้า"
        'planned_enabled'      => (int)$g('payroll_use_planned_start', '1') === 1,
        'planned_grace_min'    => (int)$g('payroll_planned_grace_minutes', '30'),
        'planned_cutoff_hour'  => (int)$g('hr_late_request_cutoff_hour', '7'),
    ];
}

/**
 * Build a deduction breakdown array (label/amount) from a payroll_slips row.
 *
 * จะแยก "ภาษีหัก ณ ที่จ่าย" กับ "ภาษีหักเพิ่มตามคำขอ" หาก additional_tax_withholding มีค่า
 * และเพิ่มรายละเอียด ขาดงาน (กี่วัน) / มาสาย (กี่ครั้ง) เพื่อให้พนักงานเห็นที่มาของการหักชัด
 *
 * @param array      $slip     row จาก payroll_slips (ต้องมี tax_withheld, provident_fund, social_security,
 *                             group_insurance, absence_deduction, lateness_deduction,
 *                             absent_days, late_count_30, late_count_60, deduction_other_json)
 * @param float|null $extra_tax  ภาษีหักเพิ่ม (จาก employee_salary_setup.additional_tax_withholding) ถ้าทราบ
 * @return array     list ของ ['label'=>string,'amount'=>float] เฉพาะรายการที่ amount > 0
 */
function payroll_build_deduction_breakdown(array $slip, ?float $extra_tax = null): array {
    $items = [];
    $tax_total = (float)($slip['tax_withheld'] ?? 0);
    $extra = max(0, (float)($extra_tax ?? 0));
    if ($extra > $tax_total) $extra = $tax_total;
    $base_tax = max(0, $tax_total - $extra);

    if ($base_tax > 0) {
        $items[] = ['label' => 'ภาษีหัก ณ ที่จ่าย', 'amount' => $base_tax];
    }
    if ($extra > 0) {
        $items[] = ['label' => 'ภาษีหักเพิ่มตามคำขอ', 'amount' => $extra];
    }
    if ((float)($slip['provident_fund'] ?? 0) > 0) {
        $items[] = ['label' => 'กองทุนสำรองเลี้ยงชีพ', 'amount' => (float)$slip['provident_fund']];
    }
    if ((float)($slip['social_security'] ?? 0) > 0) {
        $items[] = ['label' => 'ประกันสังคม', 'amount' => (float)$slip['social_security']];
    }
    if ((float)($slip['group_insurance'] ?? 0) > 0) {
        $items[] = ['label' => 'ประกันกลุ่ม (ส่วนพนักงาน)', 'amount' => (float)$slip['group_insurance']];
    }
    if ((float)($slip['health_insurance'] ?? 0) > 0) {
        $items[] = ['label' => 'ประกันสุขภาพ (ส่วนพนักงาน)', 'amount' => (float)$slip['health_insurance']];
    }
    if ((float)($slip['absence_deduction'] ?? 0) > 0) {
        $days = (float)($slip['absent_days'] ?? 0);
        $label = 'ค่าขาดงาน';
        if ($days > 0) {
            $days_txt = rtrim(rtrim(number_format($days, 1), '0'), '.');
            $label .= " ({$days_txt} วัน)";
        }
        $items[] = ['label' => $label, 'amount' => (float)$slip['absence_deduction']];
    }
    if ((float)($slip['lateness_deduction'] ?? 0) > 0) {
        $l30 = (int)($slip['late_count_30'] ?? 0);
        $l60 = (int)($slip['late_count_60'] ?? 0);
        $tier1 = function_exists('payroll_late_tier1_min_minutes') ? payroll_late_tier1_min_minutes() : 20;
        $parts = [];
        if ($l30 > 0) $parts[] = "{$tier1}-30น × {$l30}";
        if ($l60 > 0) $parts[] = "31-60น × {$l60}";
        $label = 'ค่ามาสาย' . ($parts ? ' (' . implode(', ', $parts) . ')' : '');
        $items[] = ['label' => $label, 'amount' => (float)$slip['lateness_deduction']];
    }

    // รายการหักอื่นๆ (custom) — ข้าม label ที่ซ้ำกับด้านบน
    $reserved = ['ค่าขาดงาน', 'ค่าสาย', 'ค่ามาสาย'];
    $other = !empty($slip['deduction_other_json']) ? json_decode($slip['deduction_other_json'], true) : [];
    if (is_array($other)) {
        foreach ($other as $o) {
            $label = trim((string)($o['label'] ?? $o['name'] ?? ''));
            $sourceType = (string)($o['source_type'] ?? '');
            if ($sourceType === 'employee_loan_repayment') {
                $installment = (int)($o['installment_no'] ?? 0);
                $installmentTotal = (int)($o['installment_total'] ?? 0);
                if ($installment <= 0 && preg_match('/งวด\s*(\d+)/u', $label, $m)) $installment = (int)$m[1];
                $label = 'เงินกู้บริษัท งวดที่ ' . $installment . ($installmentTotal > 0 ? ' / ' . $installmentTotal : '');
            } elseif ($sourceType === 'salary_advance') {
                $label = 'หักเงินเบิกล่วงหน้า';
            }
            $amount = (float)($o['amount'] ?? 0);
            if ($label === '' || $amount <= 0) continue;
            if (in_array($label, $reserved, true)) continue;
            $items[] = ['label' => $label, 'amount' => $amount];
        }
    }

    return $items;
}

/**
 * Flex: payslip issued — with "Download" button linking to tokenized viewer.
 *
 * @param string      $employee_name        Display name
 * @param string      $month_label          e.g. 'เมษายน 2569' (already formatted)
 * @param float       $gross                total income
 * @param float       $deductions           total deductions
 * @param float       $net                  net pay
 * @param string      $download_url         Full HTTPS url to payroll_slip.php?token=...
 * @param array|null  $ytd                  Optional YTD figures ['year','income','tax','ss','pf','gi','hi','net']
 * @param string|null $pay_date_label       Optional Thai-formatted pay date e.g. '25 เม.ย. 2569'
 * @param array|null  $deduction_breakdown  Optional list of ['label'=>string,'amount'=>float]
 *                                          ที่จะแสดงเป็นรายการย่อยใต้ "รายการหัก"
 *                                          (เฉพาะรายการที่ amount > 0)
 * @param array|null  $attendance_rules     Optional snapshot จาก payroll_get_attendance_rules_snapshot()
 *                                          จะถูกใส่กล่อง "เงื่อนไขการหักเงิน (เข้าทำงาน)" เข้า Flex
 * @return array      payload with ['flex' => bubble, 'alt' => string]
 */
function payroll_flex_slip_issued(
    string  $employee_name,
    string  $month_label,
    float   $gross,
    float   $deductions,
    float   $net,
    string  $download_url,
    ?array  $ytd = null,
    ?string $pay_date_label = null,
    ?array  $deduction_breakdown = null,
    ?array  $attendance_rules = null
): array {
    $fmt = fn($n) => number_format((float)$n, 2);

    $rows = [
        ['พนักงาน',         $employee_name],
        ['งวดเงินเดือน',     $month_label],
    ];
    if ($pay_date_label !== null && $pay_date_label !== '') {
        $rows[] = ['วันที่จ่ายเงิน', $pay_date_label, '#0F766E'];
    }
    $rows[] = ['รายได้รวม',     $fmt($gross) . ' บาท',       '#059669'];
    $rows[] = ['รายการหัก',     '-' . $fmt($deductions) . ' บาท', '#B91C1C'];
    $rows[] = ['สุทธิที่ได้รับ', $fmt($net) . ' บาท',         '#1D4ED8'];

    // Note — split onto two lines (no em-dash bridge)
    $note = "ลิงก์ดาวน์โหลดเป็นลับเฉพาะคุณ ห้ามส่งต่อผู้อื่น\nลิงก์จะหมดอายุใน 90 วัน";

    $bubble = hr_flex_card(
        'สลิปเงินเดือน',
        'สลิปเงินเดือนของคุณพร้อมให้ดาวน์โหลด กรุณาเก็บไว้เป็นหลักฐาน',
        '#059669',
        $rows,
        $note,
        'พร้อมดาวน์โหลด'
    );

    // ===== Build content to inject into body (before footer) =====
    $injectContents = [];

    // Deduction breakdown — แสดงเป็นรายการย่อย (เฉพาะที่มียอด > 0)
    if (is_array($deduction_breakdown)) {
        $items = [];
        foreach ($deduction_breakdown as $d) {
            $label = trim((string)($d['label'] ?? ''));
            $amount = (float)($d['amount'] ?? 0);
            if ($label === '' || $amount <= 0) continue;
            $items[] = ['label' => $label, 'amount' => $amount];
        }
        if (!empty($items)) {
            $detailRowBoxes = [];
            foreach ($items as $it) {
                $detailRowBoxes[] = [
                    'type' => 'box',
                    'layout' => 'baseline',
                    'spacing' => 'sm',
                    'contents' => [
                        ['type' => 'text', 'text' => $it['label'], 'color' => '#6B7280', 'size' => 'sm', 'flex' => 5, 'wrap' => true],
                        ['type' => 'text', 'text' => '-' . $fmt($it['amount']), 'color' => '#B91C1C', 'size' => 'sm', 'flex' => 4, 'weight' => 'bold', 'align' => 'end'],
                    ],
                ];
            }
            $injectContents[] = [
                'type' => 'box',
                'layout' => 'vertical',
                'margin' => 'lg',
                'spacing' => 'sm',
                'contents' => array_merge(
                    [
                        [
                            'type' => 'text',
                            'text' => 'รายละเอียดรายการหัก',
                            'size' => 'xs',
                            'color' => '#374151',
                            'weight' => 'bold',
                        ],
                        ['type' => 'separator', 'margin' => 'sm', 'color' => '#E5E7EB'],
                    ],
                    $detailRowBoxes
                ),
            ];
        }
    }

    // เงื่อนไขการหักเงินด้านเข้าทำงาน — แสดงเฉพาะเมื่อระบบเปิดใช้
    if (is_array($attendance_rules) && !empty($attendance_rules['enabled'])) {
        $absent_fmt = $fmt($attendance_rules['absent']);
        $late30_fmt = $fmt($attendance_rules['late30']);
        $late60_fmt = $fmt($attendance_rules['late60']);
        $adv_days   = (int)($attendance_rules['leave_advance_days'] ?? 7);
        $tier1_min  = (int)($attendance_rules['late_tier1_min'] ?? 20);
        $over60_as_absent = !empty($attendance_rules['over60_as_absent']);

        $ruleLines = [
            ['ขาดงาน',                     'หัก ' . $absent_fmt . ' บาท/วัน'],
            ["มาสาย {$tier1_min}–30 นาที", 'หัก ' . $late30_fmt . ' บาท/ครั้ง'],
            ['มาสาย 31–60 นาที',           'หัก ' . $late60_fmt . ' บาท/ครั้ง'],
            ['มาสาย > 60 นาที',            $over60_as_absent ? 'ตีเป็นขาด (หัก ' . $absent_fmt . ' บาท)' : 'หัก ' . $late60_fmt . ' บาท/ครั้ง'],
            ['ลาป่วยไม่มีใบรับรองแพทย์',    'ไม่หักเงินเดือน (แจ้งเตือน HR ให้แนบใบรับรอง)'],
            ['การลาอื่นๆ',                 'แจ้งล่วงหน้า ≥ ' . $adv_days . ' วัน'],
            ['ยกเว้นการหัก',               'ได้รับอนุมัติ late_excused จากหัวหน้า'],
        ];

        // S2: แสดงกฎการ "แจ้งเข้างานสายล่วงหน้า" เฉพาะเมื่อเปิดใช้งาน
        if (!empty($attendance_rules['planned_enabled'])) {
            $grace_m = (int)($attendance_rules['planned_grace_min'] ?? 30);
            $cutoff_h = (int)($attendance_rules['planned_cutoff_hour'] ?? 7);
            $ruleLines[] = [
                'แจ้งเข้างานสายล่วงหน้า',
                sprintf('เข้าในเวลาที่แจ้ง ± %d นาที ไม่หัก', $grace_m),
            ];
            $ruleLines[] = [
                'กำหนดแจ้งก่อน',
                sprintf('ก่อนวันทำงาน หรือก่อน %02d:00 ของวันนั้น', $cutoff_h),
            ];
        }

        $ruleBoxes = [];
        foreach ($ruleLines as $r) {
            $ruleBoxes[] = [
                'type' => 'box',
                'layout' => 'baseline',
                'spacing' => 'sm',
                'contents' => [
                    ['type' => 'text', 'text' => $r[0], 'color' => '#6B7280', 'size' => 'xs', 'flex' => 4, 'wrap' => true],
                    ['type' => 'text', 'text' => $r[1], 'color' => '#111827', 'size' => 'xs', 'flex' => 5, 'weight' => 'bold', 'align' => 'end', 'wrap' => true],
                ],
            ];
        }

        $injectContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'lg',
            'spacing' => 'sm',
            'backgroundColor' => '#FEF3C7',
            'cornerRadius' => '8px',
            'paddingAll' => '12px',
            'contents' => array_merge(
                [
                    [
                        'type' => 'box',
                        'layout' => 'baseline',
                        'spacing' => 'sm',
                        'contents' => [
                            ['type' => 'text', 'text' => 'เงื่อนไขการหักเงิน (เข้าทำงาน)', 'size' => 'xs', 'color' => '#92400E', 'weight' => 'bold', 'flex' => 5],
                        ],
                    ],
                    ['type' => 'separator', 'margin' => 'sm', 'color' => '#FCD34D'],
                ],
                $ruleBoxes,
                [
                    [
                        'type' => 'text',
                        'text' => 'อ้างอิงจากนโยบาย HR — หากพบความคลาดเคลื่อนกรุณาติดต่อฝ่ายบุคคล',
                        'size' => 'xxs',
                        'color' => '#92400E',
                        'wrap' => true,
                        'margin' => 'md',
                    ],
                ]
            ),
        ];
    }

    if (is_array($ytd)) {
        $year = (int)($ytd['year'] ?? (int)date('Y'));
        $ytdRows = [];
        $ytdRows[] = ['รายได้รวมสะสม',   $fmt($ytd['income'] ?? 0) . ' บาท', '#059669'];
        if ((float)($ytd['tax'] ?? 0) > 0) {
            $ytdRows[] = ['ภาษีหัก ณ ที่จ่ายสะสม', $fmt($ytd['tax']) . ' บาท', '#B91C1C'];
        }
        if ((float)($ytd['ss'] ?? 0) > 0) {
            $ytdRows[] = ['ประกันสังคมสะสม', $fmt($ytd['ss']) . ' บาท', '#B91C1C'];
        }
        if ((float)($ytd['pf'] ?? 0) > 0) {
            $ytdRows[] = ['กองทุนสำรองฯ สะสม', $fmt($ytd['pf']) . ' บาท', '#B91C1C'];
        }
        if ((float)($ytd['gi'] ?? 0) > 0) {
            $ytdRows[] = ['ประกันกลุ่มสะสม', $fmt($ytd['gi']) . ' บาท', '#B91C1C'];
        }
        if ((float)($ytd['hi'] ?? 0) > 0) {
            $ytdRows[] = ['ประกันสุขภาพสะสม', $fmt($ytd['hi']) . ' บาท', '#B91C1C'];
        }
        $ytdRows[] = ['สุทธิสะสม', $fmt($ytd['net'] ?? 0) . ' บาท', '#1D4ED8'];

        $ytdRowBoxes = [];
        foreach ($ytdRows as $r) {
            $ytdRowBoxes[] = [
                'type' => 'box',
                'layout' => 'baseline',
                'spacing' => 'sm',
                'contents' => [
                    ['type' => 'text', 'text' => $r[0], 'color' => '#6B7280', 'size' => 'sm', 'flex' => 3],
                    ['type' => 'text', 'text' => $r[1], 'color' => $r[2] ?? '#111827', 'size' => 'sm', 'flex' => 5, 'weight' => 'bold', 'align' => 'end'],
                ],
            ];
        }

        $injectContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'lg',
            'spacing' => 'sm',
            'contents' => array_merge(
                [
                    [
                        'type' => 'text',
                        'text' => 'รายได้สะสมตั้งแต่ต้นปี (' . $year . ')',
                        'size' => 'xs',
                        'color' => '#374151',
                        'weight' => 'bold',
                    ],
                    ['type' => 'separator', 'margin' => 'sm', 'color' => '#E5E7EB'],
                ],
                $ytdRowBoxes
            ),
        ];
    }

    // Prominent download button, well separated from both content above and footer below
    $injectContents[] = [
        'type' => 'button',
        'style' => 'primary',
        'color' => '#059669',
        'height' => 'sm',
        'margin' => 'xl',
        'action' => [
            'type' => 'uri',
            'label' => 'ดาวน์โหลดสลิปเงินเดือน',
            'uri'   => $download_url,
        ],
    ];

    // Locate the inner body content box (paddingAll 20px from hr_flex_card) and append
    if (isset($bubble['body']['contents']) && is_array($bubble['body']['contents'])) {
        foreach ($bubble['body']['contents'] as &$child) {
            if (isset($child['paddingAll']) && $child['paddingAll'] === '20px' && isset($child['contents'])) {
                foreach ($injectContents as $c) { $child['contents'][] = $c; }
                break;
            }
        }
        unset($child);
    }

    // Give the footer comfortable vertical breathing room
    if (isset($bubble['footer'])) {
        $bubble['footer']['paddingTop']    = '16px';
        $bubble['footer']['paddingBottom'] = '16px';
        $bubble['footer']['spacing']       = 'sm';
    }

    $alt = "สลิปเงินเดือน {$month_label}";
    if ($pay_date_label !== null && $pay_date_label !== '') {
        $alt .= " (จ่าย {$pay_date_label})";
    }
    $alt .= ' สุทธิ ' . $fmt($net) . ' บาท';

    return [
        'flex' => $bubble,
        'alt'  => $alt,
    ];
}

/**
 * Load payroll run row for LINE notifications.
 */
function payroll_load_run_for_notify(PDO $pdo, int $runId): ?array {
    if ($runId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM payroll_runs WHERE id = ? LIMIT 1");
    $stmt->execute([$runId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function payroll_fmt_money($amount): string {
    return number_format((float)$amount, 2) . ' บาท';
}

function payroll_flex_run_calculated(array $run): array {
    $month = function_exists('hr_fmt_month') ? hr_fmt_month($run['payroll_month'] ?? '') : (string)($run['payroll_month'] ?? '');
    $rows = [
        ['เดือน', $month, '#4338CA'],
        ['พนักงาน', (int)($run['employee_count'] ?? 0) . ' คน'],
        ['รวมสุทธิ', payroll_fmt_money($run['total_net'] ?? 0), '#059669'],
        ['สถานะ', 'คำนวณแล้ว — รออนุมัติ', '#D97706'],
    ];
    $bubble = hr_flex_card('คำนวณเงินเดือนแล้ว', 'รอบเงินเดือนพร้อมให้ผู้บริหารตรวจสอบและอนุมัติ', '#4338CA', $rows, null, 'รออนุมัติ');
    return ['flex' => $bubble, 'alt' => "คำนวณเงินเดือน {$month} สุทธิ " . payroll_fmt_money($run['total_net'] ?? 0)];
}

function payroll_flex_run_approved(array $run): array {
    $month = function_exists('hr_fmt_month') ? hr_fmt_month($run['payroll_month'] ?? '') : (string)($run['payroll_month'] ?? '');
    $rows = [
        ['เดือน', $month, '#4338CA'],
        ['พนักงาน', (int)($run['employee_count'] ?? 0) . ' คน'],
        ['รวมสุทธิ', payroll_fmt_money($run['total_net'] ?? 0), '#059669'],
        ['สถานะ', 'อนุมัติแล้ว — พร้อมบันทึกจ่าย', '#059669'],
    ];
    $bubble = hr_flex_card('อนุมัติรอบเงินเดือน', 'สามารถบันทึกจ่ายและส่งสลิปให้พนักงานได้', '#059669', $rows, null, 'อนุมัติแล้ว');
    return ['flex' => $bubble, 'alt' => "อนุมัติเงินเดือน {$month}"];
}

function payroll_flex_run_paid(array $run): array {
    $month = function_exists('hr_fmt_month') ? hr_fmt_month($run['payroll_month'] ?? '') : (string)($run['payroll_month'] ?? '');
    $rows = [
        ['เดือน', $month, '#4338CA'],
        ['พนักงาน', (int)($run['employee_count'] ?? 0) . ' คน'],
        ['รวมสุทธิ', payroll_fmt_money($run['total_net'] ?? 0), '#059669'],
        ['สถานะ', 'บันทึกจ่ายแล้ว ✓', '#059669'],
    ];
    $bubble = hr_flex_card('บันทึกจ่ายเงินเดือนแล้ว', 'รอบนี้ถูกบันทึกว่าจ่ายเงินเรียบร้อย', '#047857', $rows, null, 'จ่ายแล้ว');
    return ['flex' => $bubble, 'alt' => "จ่ายเงินเดือน {$month} แล้ว"];
}

/**
 * Dispatch payroll run lifecycle LINE event to executives.
 */
function payroll_line_notify_run_event(PDO $pdo, int $runId, string $eventKey): void {
    if ($runId <= 0) {
        return;
    }
    if (!class_exists('LineNotifier')) {
        $base = defined('HR_OWNER_PATH') ? HR_OWNER_PATH : dirname(__DIR__, 2);
        if (is_file($base . '/core/Services/LineNotifier.php')) {
            require_once $base . '/core/Services/LineNotifier.php';
        }
    }
    if (!class_exists('LineNotifier')) {
        return;
    }
    try {
        $run = payroll_load_run_for_notify($pdo, $runId);
        if (!$run) {
            return;
        }
        $flex = match ($eventKey) {
            'payroll.run_calculated' => payroll_flex_run_calculated($run),
            'payroll.run_approved'   => payroll_flex_run_approved($run),
            'payroll.run_paid'       => payroll_flex_run_paid($run),
            default                  => null,
        };
        if ($flex === null) {
            return;
        }
        LineNotifier::sendEvent($pdo, $eventKey, $flex);
    } catch (Throwable $e) {
        error_log('payroll_line_notify_run_event error: ' . $e->getMessage());
    }
}
