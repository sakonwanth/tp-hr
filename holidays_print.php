<?php
/**
 * Annual holidays — print / save as PDF (single A4 portrait page).
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

$holidayTypeShort = static function (string $type): string {
    return match ($type) {
        'PUBLIC' => 'ราชการ',
        'COMPANY' => 'บริษัท',
        'SPECIAL' => 'พิเศษ',
        'SUBSTITUTE' => 'ชดเชย',
        default => 'อื่นๆ',
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

        :root {
            --a4-w: 210mm;
            --a4-h: 297mm;
            --page-margin-y: 10mm;
            --page-margin-x: 9mm;
            --sheet-h: calc(var(--a4-h) - (var(--page-margin-y) * 2));
        }

        html { -webkit-text-size-adjust: 100%; }

        body {
            font-family: 'Sarabun', sans-serif;
            font-size: 11.5px;
            line-height: 1.35;
            color: #1e293b;
            background: #fff;
            margin: 0 auto;
        }

        @media screen {
            body {
                background: linear-gradient(160deg, #0f172a 0%, #1e1b4b 55%, #0f172a 100%);
                background-attachment: fixed;
                padding: max(16px, env(safe-area-inset-top, 0px)) 12px max(24px, env(safe-area-inset-bottom, 0px));
            }
            .screen-wrap { max-width: calc(var(--a4-w) + 32px); margin: 0 auto; }
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
                font-family: system-ui, sans-serif;
            }
            .toolbar-actions { display: flex; gap: 8px; flex-wrap: wrap; }
            .toolbar a, .toolbar button {
                min-height: 48px;
                padding: 0 16px;
                display: inline-flex;
                align-items: center;
                border-radius: 12px;
                border: 1px solid rgba(255, 255, 255, 0.14);
                background: rgba(15, 23, 42, 0.55);
                color: #e2e8f0;
                font: inherit;
                font-size: 13px;
                text-decoration: none;
                cursor: pointer;
            }
            .toolbar .btn-print {
                background: linear-gradient(135deg, #7c3aed, #6d28d9);
                color: #fff;
                font-weight: 600;
            }
            .toolbar-note { font-size: 11px; color: rgba(226, 232, 240, 0.62); max-width: 14rem; line-height: 1.4; }
            .a4-sheet {
                background: #fff;
                box-shadow: 0 8px 40px rgba(0, 0, 0, 0.28);
            }
        }

        /* Fixed A4 canvas */
        .a4-sheet {
            position: relative;
            width: var(--a4-w);
            height: var(--a4-h);
            max-width: var(--a4-w);
            margin: 0 auto;
            overflow: hidden;
            background: #fff;
        }

        .a4-content {
            position: absolute;
            top: var(--page-margin-y);
            left: var(--page-margin-x);
            right: var(--page-margin-x);
            width: calc(var(--a4-w) - (var(--page-margin-x) * 2));
            transform-origin: top left;
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
        .watermark img { width: 38%; opacity: 0.03; filter: grayscale(100%); }

        .doc-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-bottom: 8px;
            border-bottom: 1.5px solid #1a365d;
            margin-bottom: 8px;
        }
        .doc-header img { height: 46px; width: auto; display: block; }
        .doc-header-meta { text-align: right; max-width: 64%; }
        .doc-header-meta .co-th { font-size: 12.5px; font-weight: 700; color: #1a365d; line-height: 1.25; }
        .doc-header-meta .co-en { font-size: 9px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: #64748b; margin-top: 1px; }
        .doc-header-meta .co-tax { font-size: 9px; color: #64748b; margin-top: 2px; }

        .doc-headline { text-align: center; margin-bottom: 8px; }
        .doc-headline h1 { font-size: 15px; font-weight: 700; color: #1a365d; line-height: 1.25; }
        .doc-headline .sub { font-size: 9.5px; color: #64748b; margin-top: 2px; }
        .doc-headline .meta {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 4px 14px;
            margin-top: 5px;
            font-size: 9px;
            color: #64748b;
        }
        .doc-headline .meta b { color: #334155; font-weight: 600; }
        .doc-summary {
            text-align: center;
            font-size: 9.5px;
            color: #475569;
            margin-bottom: 7px;
            padding-bottom: 6px;
            border-bottom: 1px solid #e2e8f0;
        }

        table.schedule {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 10.5px;
        }
        table.schedule thead th {
            padding: 4px 5px;
            font-size: 8.5px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #fff;
            background: #1a365d;
            text-align: left;
            border: 1px solid #1a365d;
        }
        table.schedule thead th.col-no { width: 7%; text-align: center; }
        table.schedule thead th.col-date { width: 21%; }
        table.schedule thead th.col-name { width: 54%; }
        table.schedule thead th.col-type { width: 18%; text-align: center; }

        table.schedule tbody td {
            padding: 3px 5px;
            vertical-align: middle;
            border: 1px solid #dbe3ec;
            line-height: 1.3;
        }
        table.schedule tbody tr:nth-child(even) { background: #f8fafc; }

        .cell-no { text-align: center; font-weight: 600; color: #64748b; font-variant-numeric: tabular-nums; }
        .cell-date { font-weight: 600; color: #1a365d; white-space: nowrap; }
        .cell-date .dow { display: block; font-size: 9px; font-weight: 500; color: #64748b; }
        .cell-name .name-th { font-weight: 600; color: #0f172a; }
        .cell-name .name-en { font-size: 9px; color: #94a3b8; margin-top: 1px; }
        .cell-type { text-align: center; font-size: 9.5px; color: #475569; }

        .doc-footer {
            margin-top: 7px;
            padding-top: 5px;
            border-top: 1px solid #e2e8f0;
            font-size: 8.5px;
            color: #94a3b8;
            line-height: 1.4;
        }

        .empty-state { text-align: center; padding: 24px 12px; color: #64748b; font-size: 12px; }

        @media print {
            @page {
                size: 210mm 297mm;
                margin: 0;
            }

            html, body {
                width: var(--a4-w);
                height: var(--a4-h);
                max-width: var(--a4-w);
                max-height: var(--a4-h);
                margin: 0;
                padding: 0;
                overflow: hidden;
                background: #fff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .screen-wrap, .a4-sheet {
                width: var(--a4-w);
                height: var(--a4-h);
                margin: 0;
                padding: 0;
                overflow: hidden;
                box-shadow: none;
                page-break-after: avoid;
                page-break-before: avoid;
                break-after: avoid;
                break-inside: avoid;
            }

            .toolbar { display: none !important; }

            .a4-content {
                position: absolute;
                top: var(--page-margin-y);
                left: var(--page-margin-x);
            }

            table.schedule thead th { background: #1a365d !important; color: #fff !important; }
            table.schedule tbody tr:nth-child(even) { background: #f8fafc !important; }
        }
    </style>
</head>
<body>

<div class="screen-wrap">
    <div class="toolbar">
        <div class="toolbar-actions">
            <button type="button" class="btn-print" onclick="tpHolidayPrint()">พิมพ์ / บันทึกเป็น PDF</button>
            <a href="holidays.php?year=<?php echo (int) $holidayYear; ?>">← กลับ</a>
        </div>
        <p class="toolbar-note">A4 แนวตั้ง · 1 หน้า · Scale 100%</p>
    </div>

    <div class="a4-sheet" id="a4-sheet">
        <div class="watermark" aria-hidden="true">
            <img src="<?php echo htmlspecialchars($watermarkSrc); ?>" alt="">
        </div>

        <div class="a4-content" id="a4-content">
            <header class="doc-header">
                <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="">
                <div class="doc-header-meta">
                    <p class="co-th"><?php echo htmlspecialchars($companyName); ?></p>
                    <p class="co-en"><?php echo htmlspecialchars($companyNameEn); ?></p>
                    <?php if ($companyTaxId !== ''): ?>
                    <p class="co-tax">Tax ID <?php echo htmlspecialchars($companyTaxId); ?></p>
                    <?php endif; ?>
                </div>
            </header>

            <div class="doc-headline">
                <h1>ตารางวันหยุดประจำปี พ.ศ. <?php echo (int) $holidayYearTh; ?></h1>
                <p class="sub">Annual Holiday Schedule · <?php echo (int) $holidayYear; ?></p>
                <p class="meta">
                    <span>เลขที่ <b><?php echo htmlspecialchars($docRef); ?></b></span>
                    <span>พิมพ์ <b><?php echo htmlspecialchars($printedAt); ?></b></span>
                </p>
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
                    <?php foreach ($holidays as $i => $holiday):
                        $dow = (int) date('w', strtotime($holiday['date']));
                        $m = (int) date('n', strtotime($holiday['date']));
                        $dayNum = (int) date('j', strtotime($holiday['date']));
                        $nameEn = trim((string) ($holiday['name_en'] ?? ''));
                    ?>
                    <tr>
                        <td class="cell-no"><?php echo $i + 1; ?></td>
                        <td class="cell-date">
                            <?php echo $dayNum . ' ' . thaiMonthShort($m) . ' ' . $holidayYearTh; ?>
                            <span class="dow">วัน<?php echo htmlspecialchars($dayNames[$dow] ?? ''); ?></span>
                        </td>
                        <td class="cell-name">
                            <div class="name-th"><?php echo htmlspecialchars($holiday['name']); ?></div>
                            <?php if ($nameEn !== ''): ?>
                            <div class="name-en"><?php echo htmlspecialchars($nameEn); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="cell-type"><?php echo htmlspecialchars($holidayTypeShort((string) $holiday['type'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">ยังไม่มีข้อมูลวันหยุดสำหรับปี พ.ศ. <?php echo (int) $holidayYearTh; ?></div>
            <?php endif; ?>

            <footer class="doc-footer">
                หมายเหตุ: วันหยุดประจำสัปดาห์ของพนักงานแต่ละคนอาจแตกต่างกัน · <?php echo htmlspecialchars($companyName); ?> · TP-HR
            </footer>
        </div>
    </div>
</div>

<script>
(function() {
    var SHEET_MM_H = 297;
    var MARGIN_MM_Y = 10;
    var USABLE_MM_H = SHEET_MM_H - (MARGIN_MM_Y * 2);

    function mmToPx(mm) {
        var probe = document.createElement('div');
        probe.style.width = '1mm';
        probe.style.position = 'absolute';
        probe.style.visibility = 'hidden';
        document.body.appendChild(probe);
        var pxPerMm = probe.getBoundingClientRect().width || (96 / 25.4);
        document.body.removeChild(probe);
        return mm * pxPerMm;
    }

    window.tpHolidayFitA4 = function() {
        var content = document.getElementById('a4-content');
        if (!content) return;

        content.style.transform = 'none';
        content.style.width = '';

        var maxHeight = mmToPx(USABLE_MM_H);
        var contentHeight = content.scrollHeight;

        if (contentHeight > maxHeight) {
            var scale = maxHeight / contentHeight;
            content.style.transform = 'scale(' + scale + ')';
            content.style.width = ((100 / scale)).toFixed(4) + '%';
        }
    };

    window.tpHolidayResetA4 = function() {
        var content = document.getElementById('a4-content');
        if (!content) return;
        content.style.transform = 'none';
        content.style.width = '';
    };

    window.tpHolidayPrint = function() {
        tpHolidayFitA4();
        window.print();
    };

    window.addEventListener('beforeprint', tpHolidayFitA4);
    window.addEventListener('afterprint', tpHolidayResetA4);
    window.addEventListener('load', tpHolidayFitA4);
    window.addEventListener('resize', tpHolidayFitA4);
})();
</script>

</body>
</html>
