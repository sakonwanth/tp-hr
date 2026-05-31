<?php
/**
 * Annual holidays — print / save as PDF (browser print dialog, A4 portrait).
 */

require_once __DIR__ . '/bootstrap.php';
Auth::requireLogin();

$pdo = getDB();
$holidayYear = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
if ($holidayYear < 2000 || $holidayYear > 2100) {
    $holidayYear = (int) date('Y');
}
$holidayYearTh = $holidayYear + 543;

$stmt = $pdo->prepare("
    SELECT date, name, name_en, type, description
    FROM hr_holidays
    WHERE YEAR(date) = ? AND is_active = 1
    ORDER BY date
");
$stmt->execute([$holidayYear]);
$holidays = $stmt->fetchAll();

$holidaysByMonth = [];
foreach ($holidays as $holiday) {
    $month = (int) date('n', strtotime($holiday['date']));
    $holidaysByMonth[$month][] = $holiday;
}

$companyName = 'บริษัท ทีพี-แอสเสท ดีเวลลอปเม้นท์ จำกัด';
$companyNameEn = 'TP-ASSET DEVELOPMENT CO., LTD.';
$companyTaxId = '0135569010741';

try {
    $st = $pdo->prepare("
        SELECT setting_key, setting_value
        FROM system_settings
        WHERE setting_key IN ('company_name', 'company_name_en', 'company_tax_id')
    ");
    $st->execute();
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $v = trim((string) ($row['setting_value'] ?? ''));
        if ($v === '') {
            continue;
        }
        match ($row['setting_key']) {
            'company_name' => $companyName = $v,
            'company_name_en' => $companyNameEn = $v,
            'company_tax_id' => $companyTaxId = $v,
            default => null,
        };
    }
} catch (Throwable $e) {
    // fall back to defaults
}

try {
    $settingsService = new SettingsService($pdo);
    $fromHr = trim((string) $settingsService->get('company_name', ''));
    if ($fromHr !== '') {
        $companyName = $fromHr;
    }
} catch (Throwable $e) {
    // keep default
}

$holidayTypeLabel = static function (string $type): string {
    return match ($type) {
        'PUBLIC' => 'วันหยุดราชการ',
        'COMPANY' => 'วันหยุดบริษัท',
        'SPECIAL' => 'วันหยุดพิเศษ',
        'SUBSTITUTE' => 'วันหยุดชดเชย',
        default => 'วันหยุด',
    };
};

$dayNames = THAI_DAY_NAMES;
$printedAt = formatDateThai(date('Y-m-d'));
$docRef = sprintf('HOL-%d/%d', $holidayYear, $holidayYearTh);
$logoSrc = tp_hr_brand_logo_url('LOGO TP-ASSET - 6.png');
$watermarkSrc = tp_hr_brand_logo_url('LOGO TP-ASSET - 5.png');
$holidayCount = count($holidays);

$typeCounts = [];
foreach ($holidays as $holiday) {
    $label = $holidayTypeLabel((string) $holiday['type']);
    $typeCounts[$label] = ($typeCounts[$label] ?? 0) + 1;
}

$summaryParts = ['รวม ' . $holidayCount . ' วันหยุด'];
foreach ($typeCounts as $typeLabel => $count) {
    $summaryParts[] = $typeLabel . ' ' . $count . ' วัน';
}
$summaryLine = implode(' · ', $summaryParts);

$rowNo = 0;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>ตารางวันหยุดประจำปี <?php echo (int) $holidayYearTh; ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/icons/tphr-app-icon.svg">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html {
            -webkit-text-size-adjust: 100%;
        }

        body {
            font-family: 'Sarabun', sans-serif;
            font-size: 13.5px;
            line-height: 1.55;
            color: #1e293b;
            background: #fff;
            max-width: 210mm;
            width: 100%;
            margin: 0 auto;
        }

        /* ---------- Screen chrome (hidden on print) ---------- */
        @media screen {
            body {
                background: linear-gradient(160deg, #0f172a 0%, #1e1b4b 55%, #0f172a 100%);
                background-attachment: fixed;
                padding: max(16px, env(safe-area-inset-top, 0px)) max(12px, env(safe-area-inset-right, 0px)) max(24px, env(safe-area-inset-bottom, 0px)) max(12px, env(safe-area-inset-left, 0px));
            }
            .screen-wrap {
                max-width: calc(210mm + 32px);
                margin: 0 auto;
            }
            .toolbar {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                margin-bottom: 12px;
                padding: 12px 14px;
                border-radius: 14px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                background: rgba(30, 41, 59, 0.88);
                font-family: system-ui, -apple-system, sans-serif;
            }
            .toolbar-actions { display: flex; flex-wrap: wrap; gap: 8px; }
            .toolbar a, .toolbar button {
                min-height: 48px;
                padding: 0 16px;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                border-radius: 12px;
                border: 1px solid rgba(255, 255, 255, 0.14);
                background: rgba(15, 23, 42, 0.55);
                color: #e2e8f0;
                font: inherit;
                font-size: 13px;
                font-weight: 500;
                text-decoration: none;
                cursor: pointer;
            }
            .toolbar .btn-print {
                background: linear-gradient(135deg, #7c3aed, #6d28d9);
                border-color: rgba(255, 255, 255, 0.18);
                color: #fff;
                font-weight: 600;
            }
            .toolbar-note {
                font-size: 11.5px;
                color: rgba(226, 232, 240, 0.62);
                max-width: 15rem;
                line-height: 1.45;
            }
            .page {
                background: #fff;
                box-shadow: 0 8px 40px rgba(0, 0, 0, 0.28);
                border-radius: 2px;
            }
        }

        /* ---------- A4 document body ---------- */
        .page {
            position: relative;
            width: 100%;
            max-width: 210mm;
            margin: 0 auto;
            padding: 18mm 16mm 16mm;
            overflow: hidden;
        }

        .watermark {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            z-index: 0;
        }
        .watermark img {
            width: 42%;
            opacity: 0.035;
            filter: grayscale(100%);
        }
        .page-inner { position: relative; z-index: 1; }

        /* Header */
        .doc-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            padding-bottom: 14px;
            border-bottom: 1.5px solid #1a365d;
            margin-bottom: 18px;
        }
        .doc-header-logo img { height: 64px; width: auto; display: block; }
        .doc-header-meta { text-align: right; max-width: 62%; }
        .doc-header-meta .co-th {
            font-size: 14.5px;
            font-weight: 700;
            color: #1a365d;
            line-height: 1.35;
        }
        .doc-header-meta .co-en {
            margin-top: 2px;
            font-size: 10.5px;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #64748b;
        }
        .doc-header-meta .co-tax {
            margin-top: 5px;
            font-size: 10.5px;
            color: #64748b;
        }
        .doc-header-meta .co-tax b { color: #334155; font-weight: 600; }

        .doc-meta-row {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 4px 16px;
            margin-bottom: 20px;
            font-size: 11px;
            color: #64748b;
        }
        .doc-meta-row b { color: #334155; font-weight: 600; }

        .doc-title-block { text-align: center; margin-bottom: 18px; }
        .doc-title-block h1 {
            font-size: 20px;
            font-weight: 700;
            color: #1a365d;
            letter-spacing: 0.01em;
            line-height: 1.3;
        }
        .doc-title-block .sub {
            margin-top: 4px;
            font-size: 11.5px;
            font-weight: 500;
            color: #64748b;
            letter-spacing: 0.04em;
        }
        .doc-title-block .rule {
            width: 56px;
            height: 3px;
            margin: 12px auto 0;
            border-radius: 99px;
            background: linear-gradient(90deg, #1a365d, #94a3b8);
        }

        .doc-summary {
            text-align: center;
            font-size: 12px;
            color: #475569;
            margin-bottom: 22px;
            padding-bottom: 18px;
            border-bottom: 1px solid #e2e8f0;
        }
        .doc-summary strong { color: #1a365d; font-weight: 700; }

        /* ---------- Schedule list (not grid table) ---------- */
        .schedule { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .schedule thead { display: table-header-group; }
        .schedule thead th {
            padding: 0 4px 10px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.07em;
            text-transform: uppercase;
            color: #94a3b8;
            text-align: left;
            border-bottom: 1.5px solid #1a365d;
            vertical-align: bottom;
        }
        .schedule thead th.col-no { width: 8%; text-align: center; }
        .schedule thead th.col-date { width: 22%; }
        .schedule thead th.col-name { width: 52%; }
        .schedule thead th.col-type { width: 18%; text-align: right; }

        .schedule tbody tr { page-break-inside: avoid; }
        .schedule tbody td {
            padding: 11px 4px;
            vertical-align: top;
            border-bottom: 1px solid #eef2f7;
        }
        .schedule tbody tr:last-child td { border-bottom: none; }

        .month-divider td {
            padding: 18px 0 8px;
            border-bottom: none;
        }
        .month-divider:first-child td { padding-top: 4px; }
        .month-divider__line {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .month-divider__line::before,
        .month-divider__line::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }
        .month-divider__label {
            font-size: 10.5px;
            font-weight: 600;
            letter-spacing: 0.06em;
            color: #64748b;
            white-space: nowrap;
        }

        .cell-no {
            text-align: center;
            font-size: 12px;
            font-weight: 600;
            color: #94a3b8;
            font-variant-numeric: tabular-nums;
            padding-top: 13px !important;
        }

        .cell-date .day-num {
            font-size: 18px;
            font-weight: 700;
            color: #1a365d;
            line-height: 1;
            font-variant-numeric: tabular-nums;
        }
        .cell-date .day-meta {
            margin-top: 3px;
            font-size: 11px;
            color: #64748b;
            line-height: 1.35;
        }

        .cell-name .name-th {
            font-size: 13.5px;
            font-weight: 600;
            color: #0f172a;
            line-height: 1.4;
        }
        .cell-name .name-en {
            margin-top: 2px;
            font-size: 11px;
            color: #94a3b8;
            line-height: 1.35;
        }

        .cell-type {
            text-align: right;
            font-size: 11px;
            color: #64748b;
            padding-top: 13px !important;
        }

        .doc-footer {
            margin-top: 28px;
            padding-top: 14px;
            border-top: 1px solid #e2e8f0;
            font-size: 10.5px;
            color: #94a3b8;
            line-height: 1.6;
        }
        .doc-footer p + p { margin-top: 3px; }

        .empty-state {
            text-align: center;
            padding: 40px 16px;
            color: #64748b;
            font-size: 14px;
        }

        /* ---------- Print: A4 lock (same pattern as payroll_print) ---------- */
        @media print {
            @page {
                size: A4 portrait;
                margin: 12mm 10mm;
            }

            html, body {
                width: 210mm;
                max-width: 210mm;
                min-height: 0;
                height: auto;
                margin: 0 auto;
                padding: 0;
                background: #fff !important;
                overflow: visible;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .screen-wrap { max-width: none; margin: 0; padding: 0; }
            .toolbar { display: none !important; }

            .page {
                width: auto;
                max-width: none;
                margin: 0;
                padding: 0;
                box-shadow: none;
                border-radius: 0;
                overflow: visible;
                page-break-after: avoid;
            }

            .watermark img { opacity: 0.03; width: 40%; }

            .schedule thead th { border-bottom-color: #1a365d !important; }
            .schedule tbody td { border-bottom-color: #eef2f7 !important; }
            .month-divider__line::before,
            .month-divider__line::after { background: #e2e8f0 !important; }
        }
    </style>
</head>
<body>

<div class="screen-wrap">
    <div class="toolbar screen-only" role="toolbar" aria-label="ตัวเลือกพิมพ์">
        <div class="toolbar-actions">
            <button type="button" class="btn-print" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
            <a href="holidays.php?year=<?php echo (int) $holidayYear; ?>">← กลับ</a>
        </div>
        <p class="toolbar-note">กระดาษ A4 แนวตั้ง · Margins: Default · Scale: 100%</p>
    </div>

    <div class="page">
        <div class="watermark" aria-hidden="true">
            <img src="<?php echo htmlspecialchars($watermarkSrc); ?>" alt="">
        </div>

        <div class="page-inner">
            <header class="doc-header">
                <div class="doc-header-logo">
                    <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="">
                </div>
                <div class="doc-header-meta">
                    <p class="co-th"><?php echo htmlspecialchars($companyName); ?></p>
                    <p class="co-en"><?php echo htmlspecialchars($companyNameEn); ?></p>
                    <?php if ($companyTaxId !== ''): ?>
                    <p class="co-tax">Tax ID <b><?php echo htmlspecialchars($companyTaxId); ?></b></p>
                    <?php endif; ?>
                </div>
            </header>

            <div class="doc-meta-row">
                <span>เลขที่ <b><?php echo htmlspecialchars($docRef); ?></b></span>
                <span>พิมพ์เมื่อ <b><?php echo htmlspecialchars($printedAt); ?></b></span>
            </div>

            <div class="doc-title-block">
                <h1>ตารางวันหยุดประจำปี พ.ศ. <?php echo (int) $holidayYearTh; ?></h1>
                <p class="sub">Annual Holiday Schedule · <?php echo (int) $holidayYear; ?></p>
                <div class="rule" aria-hidden="true"></div>
            </div>

            <?php if ($holidayCount > 0): ?>
            <p class="doc-summary"><?php echo htmlspecialchars($summaryLine); ?></p>

            <table class="schedule">
                <thead>
                    <tr>
                        <th class="col-no">ลำดับ</th>
                        <th class="col-date">วันที่</th>
                        <th class="col-name">รายการวันหยุด</th>
                        <th class="col-type">ประเภท</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($m = 1; $m <= 12; $m++):
                        if (empty($holidaysByMonth[$m])) {
                            continue;
                        }
                    ?>
                    <tr class="month-divider">
                        <td colspan="4">
                            <div class="month-divider__line">
                                <span class="month-divider__label"><?php echo thaiMonth($m); ?> <?php echo (int) $holidayYearTh; ?></span>
                            </div>
                        </td>
                    </tr>
                    <?php foreach ($holidaysByMonth[$m] as $holiday):
                        $rowNo++;
                        $dow = (int) date('w', strtotime($holiday['date']));
                        $dayNum = (int) date('j', strtotime($holiday['date']));
                        $nameEn = trim((string) ($holiday['name_en'] ?? ''));
                    ?>
                    <tr>
                        <td class="cell-no"><?php echo (int) $rowNo; ?></td>
                        <td class="cell-date">
                            <div class="day-num"><?php echo $dayNum; ?></div>
                            <div class="day-meta">
                                <?php echo thaiMonthShort($m); ?> <?php echo (int) $holidayYearTh; ?><br>
                                วัน<?php echo htmlspecialchars($dayNames[$dow] ?? ''); ?>
                            </div>
                        </td>
                        <td class="cell-name">
                            <div class="name-th"><?php echo htmlspecialchars($holiday['name']); ?></div>
                            <?php if ($nameEn !== ''): ?>
                            <div class="name-en"><?php echo htmlspecialchars($nameEn); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="cell-type"><?php echo htmlspecialchars($holidayTypeLabel((string) $holiday['type'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endfor; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">ยังไม่มีข้อมูลวันหยุดสำหรับปี พ.ศ. <?php echo (int) $holidayYearTh; ?></div>
            <?php endif; ?>

            <footer class="doc-footer">
                <p>หมายเหตุ: ตารางนี้แสดงวันหยุดนักขัตฤกษ์และวันหยุดบริษัทในระบบ TP-HR วันหยุดประจำสัปดาห์ของพนักงานอาจแตกต่างกัน</p>
                <p><?php echo htmlspecialchars($companyName); ?> · TP-HR · <?php echo htmlspecialchars($printedAt); ?></p>
            </footer>
        </div>
    </div>
</div>

</body>
</html>
