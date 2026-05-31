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

$rowNo = 0;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>ตารางวันหยุดประจำปี <?php echo (int) $holidayYearTh; ?> — <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/icons/tphr-app-icon.svg">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
        html, body { overflow: visible; }

        body {
            font-family: 'Sarabun', sans-serif;
            background: #e2e8f0;
            color: #1a1a1a;
            padding: 20px 0;
            min-height: 100vh;
            min-height: 100dvh;
        }

        @media screen {
            body {
                background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 52%, #0f172a 100%);
                background-attachment: fixed;
                color: #e2e8f0;
                padding-top: max(16px, env(safe-area-inset-top, 0px));
                padding-bottom: max(16px, env(safe-area-inset-bottom, 0px));
                padding-left: max(12px, env(safe-area-inset-left, 0px));
                padding-right: max(12px, env(safe-area-inset-right, 0px));
            }
            .screen-shell { max-width: calc(210mm + 40px); margin: 0 auto; }
            .pages-stack {
                border-radius: 16px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                background: rgba(30, 41, 59, 0.45);
                padding: 16px 12px 20px;
                margin-top: 4px;
            }
        }

        /* ---------- A4 page (screen preview = print size) ---------- */
        .page {
            width: 210mm;
            min-height: 297mm;
            max-width: 210mm;
            margin: 0 auto 20px;
            background: #fff;
            padding: 14mm 12mm 12mm;
            position: relative;
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.12);
            page-break-after: always;
            display: flex;
            flex-direction: column;
            font-family: 'Sarabun', sans-serif;
            color: #1a1a1a;
        }
        .page:last-child { page-break-after: auto; margin-bottom: 0; }

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
            width: 46%;
            height: auto;
            opacity: 0.04;
            filter: grayscale(100%) brightness(1.2);
        }
        .page > *:not(.watermark) { position: relative; z-index: 1; }

        /* ---------- Toolbar (screen only) ---------- */
        .toolbar {
            max-width: 210mm;
            margin: 0 auto 12px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(30, 41, 59, 0.85);
            font-family: system-ui, -apple-system, sans-serif;
        }
        .toolbar-left { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .toolbar a, .toolbar button {
            padding: 10px 16px;
            min-height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            background: rgba(15, 23, 42, 0.6);
            color: #e2e8f0;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            font-family: inherit;
        }
        .toolbar a:hover, .toolbar button:hover { background: rgba(51, 65, 85, 0.75); }
        .toolbar .btn-print {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: #fff;
            border-color: rgba(255, 255, 255, 0.2);
            font-weight: 600;
            box-shadow: 0 4px 20px rgba(124, 58, 237, 0.35);
        }
        .toolbar .btn-print:hover { background: linear-gradient(135deg, #6d28d9 0%, #5b21b6 100%); }
        .toolbar-hint {
            font-size: 12px;
            color: rgba(226, 232, 240, 0.65);
            max-width: 14rem;
            line-height: 1.4;
        }

        /* ---------- Document header ---------- */
        .doc-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding-bottom: 10px;
            border-bottom: 2.5px solid #1a365d;
            margin-bottom: 10px;
        }
        .doc-header-logo img { height: 72px; width: auto; display: block; }
        .doc-header-right { text-align: right; max-width: 68%; }
        .doc-header-right .company-name {
            font-size: 15px;
            font-weight: 700;
            color: #1a365d;
            line-height: 1.35;
        }
        .doc-header-right .company-name-en {
            font-size: 11px;
            font-weight: 600;
            color: #334155;
            margin-top: 2px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .doc-header-right .company-tax {
            font-size: 11px;
            color: #475569;
            margin-top: 4px;
        }
        .doc-header-right .company-tax b { color: #1a365d; font-weight: 600; }

        .doc-ref {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            flex-wrap: wrap;
            gap: 6px 16px;
            margin: 4px 0 8px;
            font-size: 12px;
            color: #334155;
        }
        .doc-ref .no { font-weight: 600; color: #1a365d; }
        .doc-ref .date { font-weight: 500; }

        .doc-title {
            text-align: center;
            font-size: 18px;
            font-weight: 700;
            color: #1a365d;
            margin: 6px 0 4px;
            line-height: 1.35;
        }
        .doc-title .sub {
            display: block;
            font-size: 12px;
            font-weight: 500;
            color: #475569;
            margin-top: 2px;
            letter-spacing: 0.03em;
        }

        .summary-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 12px 0 14px;
            padding: 10px 12px;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
        }
        .summary-item {
            flex: 1 1 auto;
            min-width: 7rem;
            text-align: center;
            padding: 4px 8px;
            border-right: 1px solid #cbd5e1;
        }
        .summary-item:last-child { border-right: none; }
        .summary-item strong {
            display: block;
            font-size: 20px;
            font-weight: 700;
            color: #1a365d;
            font-variant-numeric: tabular-nums;
            line-height: 1.1;
        }
        .summary-item span {
            font-size: 11px;
            color: #64748b;
            font-weight: 500;
        }

        /* ---------- Holiday table ---------- */
        .holiday-table-wrap { flex: 1 1 auto; margin-top: 4px; }

        table.holiday-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 12px;
            line-height: 1.45;
        }
        table.holiday-table thead { display: table-header-group; }
        table.holiday-table th {
            background: #1a365d;
            color: #fff;
            font-weight: 600;
            font-size: 11px;
            letter-spacing: 0.02em;
            padding: 8px 7px;
            border: 1px solid #1a365d;
            text-align: center;
            vertical-align: middle;
        }
        table.holiday-table th.col-left { text-align: left; }
        table.holiday-table td {
            padding: 7px 7px;
            border: 1px solid #cbd5e1;
            vertical-align: top;
            word-break: break-word;
        }
        table.holiday-table tbody tr:nth-child(even):not(.month-row) { background: #f8fafc; }
        table.holiday-table tbody tr { page-break-inside: avoid; }

        table.holiday-table .col-no {
            width: 7%;
            text-align: center;
            font-weight: 600;
            color: #334155;
            font-variant-numeric: tabular-nums;
        }
        table.holiday-table .col-date {
            width: 17%;
            text-align: center;
            font-weight: 600;
            color: #1a365d;
            white-space: nowrap;
        }
        table.holiday-table .col-dow {
            width: 11%;
            text-align: center;
            color: #475569;
        }
        table.holiday-table .col-name { width: 32%; font-weight: 600; color: #0f172a; }
        table.holiday-table .col-name-en {
            width: 22%;
            font-size: 11px;
            color: #64748b;
            font-style: italic;
        }
        table.holiday-table .col-type {
            width: 11%;
            text-align: center;
            font-size: 10.5px;
            font-weight: 600;
            color: #4338ca;
        }

        tr.month-row td {
            background: #e2e8f0 !important;
            border-color: #94a3b8;
            font-weight: 700;
            font-size: 12px;
            color: #1a365d;
            padding: 6px 10px;
            letter-spacing: 0.02em;
        }

        .doc-footer {
            margin-top: auto;
            padding-top: 14px;
            border-top: 1px solid #cbd5e1;
            font-size: 10.5px;
            color: #64748b;
            line-height: 1.55;
        }
        .doc-footer p + p { margin-top: 4px; }
        .doc-footer .system-tag {
            font-weight: 600;
            color: #475569;
        }

        .empty-state {
            text-align: center;
            padding: 32px 16px;
            color: #64748b;
            font-size: 14px;
            border: 1px dashed #cbd5e1;
            border-radius: 6px;
            background: #f8fafc;
        }

        /* ---------- Print: lock A4 portrait ---------- */
        @media print {
            html, body {
                width: 210mm;
                margin: 0;
                padding: 0;
                background: #fff !important;
                color: #1a1a1a;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .screen-shell { max-width: none; margin: 0; }
            .pages-stack {
                border: none;
                background: transparent;
                padding: 0;
                margin: 0;
                border-radius: 0;
            }
            .toolbar { display: none !important; }
            .page {
                width: 210mm;
                min-height: 297mm;
                max-width: 210mm;
                max-height: none;
                margin: 0;
                padding: 12mm 10mm 10mm;
                box-shadow: none;
                page-break-after: always;
                overflow: visible;
            }
            .page:last-child { page-break-after: auto; }
            .watermark img { opacity: 0.035; width: 44%; }
            table.holiday-table thead th { background: #1a365d !important; color: #fff !important; }
            tr.month-row td { background: #e2e8f0 !important; }
            table.holiday-table tbody tr:nth-child(even):not(.month-row) { background: #f8fafc !important; }
            @page {
                size: A4 portrait;
                margin: 0;
            }
        }
    </style>
</head>
<body>

<div class="screen-shell">
    <div class="toolbar" role="toolbar" aria-label="ตัวเลือกพิมพ์ตารางวันหยุด">
        <div class="toolbar-left">
            <button type="button" class="btn-print" onclick="window.print()">
                พิมพ์ / บันทึกเป็น PDF
            </button>
            <a href="holidays.php?year=<?php echo (int) $holidayYear; ?>">← กลับหน้าวันหยุด</a>
        </div>
        <p class="toolbar-hint">ขนาดกระดาษ A4 แนวตั้ง · ใน PDF เลือก Fit to page / 100%</p>
    </div>

    <div class="pages-stack">
        <div class="page">
            <div class="watermark" aria-hidden="true">
                <img src="<?php echo htmlspecialchars($watermarkSrc); ?>" alt="">
            </div>

            <header class="doc-header">
                <div class="doc-header-logo">
                    <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="<?php echo htmlspecialchars($companyName); ?>">
                </div>
                <div class="doc-header-right">
                    <p class="company-name"><?php echo htmlspecialchars($companyName); ?></p>
                    <p class="company-name-en"><?php echo htmlspecialchars($companyNameEn); ?></p>
                    <?php if ($companyTaxId !== ''): ?>
                    <p class="company-tax">เลขประจำตัวผู้เสียภาษี <b><?php echo htmlspecialchars($companyTaxId); ?></b></p>
                    <?php endif; ?>
                </div>
            </header>

            <div class="doc-ref">
                <span class="no">เลขที่เอกสาร <?php echo htmlspecialchars($docRef); ?></span>
                <span class="date">วันที่พิมพ์ <?php echo htmlspecialchars($printedAt); ?></span>
            </div>

            <h1 class="doc-title">
                ตารางวันหยุดประจำปี พ.ศ. <?php echo (int) $holidayYearTh; ?>
                <span class="sub">ANNUAL COMPANY HOLIDAY SCHEDULE · A.D. <?php echo (int) $holidayYear; ?></span>
            </h1>

            <?php if ($holidayCount > 0): ?>
            <div class="summary-bar" aria-label="สรุปจำนวนวันหยุด">
                <div class="summary-item">
                    <strong><?php echo (int) $holidayCount; ?></strong>
                    <span>วันหยุดทั้งปี</span>
                </div>
                <?php foreach ($typeCounts as $typeLabel => $count): ?>
                <div class="summary-item">
                    <strong><?php echo (int) $count; ?></strong>
                    <span><?php echo htmlspecialchars($typeLabel); ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="holiday-table-wrap">
                <table class="holiday-table">
                    <thead>
                        <tr>
                            <th class="col-no">ลำดับ</th>
                            <th class="col-date">วันที่</th>
                            <th class="col-dow">วัน</th>
                            <th class="col-name col-left">ชื่อวันหยุด</th>
                            <th class="col-name-en col-left">ชื่อ (English)</th>
                            <th class="col-type">ประเภท</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($m = 1; $m <= 12; $m++):
                            if (empty($holidaysByMonth[$m])) {
                                continue;
                            }
                        ?>
                        <tr class="month-row">
                            <td colspan="6"><?php echo thaiMonth($m); ?> พ.ศ. <?php echo (int) $holidayYearTh; ?></td>
                        </tr>
                        <?php foreach ($holidaysByMonth[$m] as $holiday):
                            $rowNo++;
                            $dow = (int) date('w', strtotime($holiday['date']));
                        ?>
                        <tr>
                            <td class="col-no"><?php echo (int) $rowNo; ?></td>
                            <td class="col-date"><?php echo formatDateThai($holiday['date']); ?></td>
                            <td class="col-dow">วัน<?php echo htmlspecialchars($dayNames[$dow] ?? ''); ?></td>
                            <td class="col-name"><?php echo htmlspecialchars($holiday['name']); ?></td>
                            <td class="col-name-en"><?php echo htmlspecialchars($holiday['name_en'] ?? '—'); ?></td>
                            <td class="col-type"><?php echo htmlspecialchars($holidayTypeLabel((string) $holiday['type'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">ยังไม่มีข้อมูลวันหยุดสำหรับปี พ.ศ. <?php echo (int) $holidayYearTh; ?></div>
            <?php endif; ?>

            <footer class="doc-footer">
                <p class="system-tag">หมายเหตุ</p>
                <p>ตารางนี้แสดงเฉพาะวันหยุดนักขัตฤกษ์และวันหยุดบริษัทที่บันทึกในระบบ TP-HR วันหยุดประจำสัปดาห์ของพนักงานแต่ละคนอาจแตกต่างกัน</p>
                <p>เอกสารจากระบบ TP-HR · <?php echo htmlspecialchars($companyName); ?> · พิมพ์เมื่อ <?php echo htmlspecialchars($printedAt); ?></p>
            </footer>
        </div>
    </div>
</div>

</body>
</html>
