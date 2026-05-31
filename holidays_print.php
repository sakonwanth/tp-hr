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

$holidayTypeClass = static function (string $type): string {
    return match ($type) {
        'PUBLIC' => 'is-public',
        'COMPANY' => 'is-company',
        'SPECIAL' => 'is-special',
        'SUBSTITUTE' => 'is-substitute',
        default => 'is-default',
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
            --margin-y: 11mm;
            --margin-x: 13mm;
            --sheet-h: calc(var(--a4-h) - (var(--margin-y) * 2));
            --ink: #1a365d;
            --ink-soft: #475569;
            --ink-muted: #94a3b8;
            --gold: #c8a951;
            --line: #e8edf2;
            --wash: #f7f9fc;
        }

        html { -webkit-text-size-adjust: 100%; }

        body {
            font-family: 'Sarabun', sans-serif;
            font-size: 12px;
            line-height: 1.45;
            color: #1e293b;
            background: #fff;
        }

        @media screen {
            body {
                background: linear-gradient(165deg, #0f172a 0%, #1e293b 48%, #0f172a 100%);
                background-attachment: fixed;
                padding: max(16px, env(safe-area-inset-top, 0px)) 12px max(28px, env(safe-area-inset-bottom, 0px));
            }
            .screen-wrap { max-width: calc(var(--a4-w) + 36px); margin: 0 auto; }
            .toolbar {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                margin-bottom: 14px;
                padding: 12px 16px;
                border-radius: 14px;
                border: 1px solid rgba(255, 255, 255, 0.08);
                background: rgba(15, 23, 42, 0.82);
                backdrop-filter: blur(12px);
                font-family: system-ui, sans-serif;
            }
            .toolbar-actions { display: flex; gap: 8px; flex-wrap: wrap; }
            .toolbar a, .toolbar button {
                min-height: 48px;
                padding: 0 18px;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                border-radius: 10px;
                border: 1px solid rgba(255, 255, 255, 0.12);
                background: rgba(255, 255, 255, 0.06);
                color: #e2e8f0;
                font: inherit;
                font-size: 13px;
                text-decoration: none;
                cursor: pointer;
                transition: background 0.15s ease;
            }
            .toolbar a:hover { background: rgba(255, 255, 255, 0.1); }
            .toolbar .btn-print {
                background: var(--ink);
                border-color: rgba(255, 255, 255, 0.15);
                color: #fff;
                font-weight: 600;
            }
            .toolbar .btn-print:hover { background: #234876; }
            .toolbar-note { font-size: 11px; color: rgba(226, 232, 240, 0.55); line-height: 1.4; }
            .a4-sheet {
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.06);
                border-radius: 2px;
            }
        }

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
            top: var(--margin-y);
            left: var(--margin-x);
            width: calc(var(--a4-w) - (var(--margin-x) * 2));
            min-height: var(--sheet-h);
            display: flex;
            flex-direction: column;
            transform-origin: top left;
        }

        .watermark {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
        }
        .watermark img { width: 36%; opacity: 0.028; filter: grayscale(100%); }

        /* ---- Header ---- */
        .doc-header {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 12px;
            margin-bottom: 16px;
            border-bottom: 2px solid var(--ink);
        }
        .doc-header::after {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            bottom: -5px;
            height: 1px;
            background: var(--gold);
        }
        .doc-header img { height: 52px; width: auto; display: block; }
        .doc-header-meta { text-align: right; max-width: 58%; }
        .doc-header-meta .co-th {
            font-size: 13px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1.3;
        }
        .doc-header-meta .co-en {
            margin-top: 2px;
            font-size: 9.5px;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--ink-soft);
        }
        .doc-header-meta .co-tax {
            margin-top: 4px;
            font-size: 9.5px;
            color: var(--ink-muted);
        }

        /* ---- Title ---- */
        .doc-headline {
            text-align: center;
            margin-bottom: 14px;
        }
        .doc-headline h1 {
            font-size: 18px;
            font-weight: 700;
            color: var(--ink);
            letter-spacing: 0.01em;
            line-height: 1.25;
        }
        .doc-headline .sub {
            margin-top: 4px;
            font-size: 10.5px;
            font-weight: 500;
            color: var(--ink-soft);
            letter-spacing: 0.05em;
        }
        .doc-headline .accent {
            width: 52px;
            height: 2px;
            margin: 10px auto 0;
            border-radius: 99px;
            background: linear-gradient(90deg, transparent, var(--gold) 30%, var(--gold) 70%, transparent);
        }
        .doc-meta {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 6px 20px;
            margin-top: 10px;
            font-size: 9.5px;
            color: var(--ink-muted);
        }
        .doc-meta b { color: var(--ink-soft); font-weight: 600; }

        /* ---- Stats ---- */
        .stat-row {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 16px;
        }
        .stat-chip {
            min-width: 88px;
            padding: 8px 14px;
            text-align: center;
            border-radius: 10px;
            background: var(--wash);
            border: 1px solid var(--line);
        }
        .stat-chip .num {
            display: block;
            font-size: 20px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1;
            font-variant-numeric: tabular-nums;
        }
        .stat-chip .lbl {
            display: block;
            margin-top: 3px;
            font-size: 9px;
            font-weight: 500;
            color: var(--ink-soft);
            line-height: 1.25;
        }

        /* ---- Schedule table ---- */
        .schedule-wrap { flex: 1 1 auto; }

        table.schedule {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        table.schedule thead th {
            padding: 0 6px 9px;
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--ink-muted);
            text-align: left;
            border: none;
            border-bottom: 1.5px solid var(--ink);
            vertical-align: bottom;
        }
        table.schedule thead th.col-no { width: 6%; text-align: center; }
        table.schedule thead th.col-date { width: 14%; }
        table.schedule thead th.col-name { width: 58%; }
        table.schedule thead th.col-type { width: 22%; text-align: right; }

        table.schedule tbody td {
            padding: 9px 6px;
            vertical-align: middle;
            border: none;
            border-bottom: 1px solid var(--line);
        }
        table.schedule tbody tr:last-child td { border-bottom: none; }

        .cell-no {
            text-align: center;
            font-size: 11px;
            font-weight: 600;
            color: var(--ink-muted);
            font-variant-numeric: tabular-nums;
        }

        .date-chip {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-width: 44px;
            padding: 5px 7px;
            border-radius: 9px;
            background: linear-gradient(180deg, #fff 0%, var(--wash) 100%);
            border: 1px solid var(--line);
            box-shadow: 0 1px 2px rgba(26, 54, 93, 0.04);
        }
        .date-chip .num {
            font-size: 17px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1;
            font-variant-numeric: tabular-nums;
        }
        .date-chip .mon {
            margin-top: 2px;
            font-size: 8.5px;
            font-weight: 600;
            color: var(--ink-soft);
            line-height: 1.2;
        }
        .date-chip .dow {
            margin-top: 1px;
            font-size: 8px;
            color: var(--ink-muted);
        }

        .cell-name .name-th {
            font-size: 12.5px;
            font-weight: 600;
            color: #0f172a;
            line-height: 1.35;
        }
        .cell-name .name-en {
            margin-top: 2px;
            font-size: 10px;
            font-weight: 400;
            color: var(--ink-muted);
            line-height: 1.3;
        }

        .type-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 9.5px;
            font-weight: 600;
            line-height: 1.3;
            white-space: nowrap;
        }
        .type-pill.is-public { background: #eef2ff; color: #4338ca; }
        .type-pill.is-company { background: #f0fdf4; color: #15803d; }
        .type-pill.is-special { background: #fdf4ff; color: #9333ea; }
        .type-pill.is-substitute { background: #fffbeb; color: #b45309; }
        .type-pill.is-default { background: var(--wash); color: var(--ink-soft); }

        .cell-type { text-align: right; }

        /* ---- Footer ---- */
        .doc-footer {
            margin-top: auto;
            padding-top: 12px;
            border-top: 1px solid var(--line);
            font-size: 9px;
            color: var(--ink-muted);
            line-height: 1.55;
        }
        .doc-footer .note-label {
            font-weight: 600;
            color: var(--ink-soft);
        }

        .empty-state {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: var(--ink-soft);
            font-size: 13px;
            padding: 32px;
        }

        @media print {
            @page { size: 210mm 297mm; margin: 0; }

            html, body {
                width: var(--a4-w);
                height: var(--a4-h);
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
                overflow: hidden;
                box-shadow: none;
                page-break-after: avoid;
                break-inside: avoid;
            }

            .toolbar { display: none !important; }

            .date-chip {
                box-shadow: none;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .type-pill, .stat-chip {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
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
        <p class="toolbar-note">A4 แนวตั้ง · 1 หน้า</p>
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
                <div class="accent" aria-hidden="true"></div>
                <p class="doc-meta">
                    <span>เลขที่เอกสาร <b><?php echo htmlspecialchars($docRef); ?></b></span>
                    <span>พิมพ์เมื่อ <b><?php echo htmlspecialchars($printedAt); ?></b></span>
                </p>
            </div>

            <?php if ($holidayCount > 0): ?>
            <div class="stat-row" aria-label="สรุปจำนวนวันหยุด">
                <div class="stat-chip">
                    <span class="num"><?php echo (int) $holidayCount; ?></span>
                    <span class="lbl">วันหยุดทั้งปี</span>
                </div>
                <?php foreach ($typeCounts as $typeLabel => $count): ?>
                <div class="stat-chip">
                    <span class="num"><?php echo (int) $count; ?></span>
                    <span class="lbl"><?php echo htmlspecialchars($typeLabel); ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="schedule-wrap">
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
                            $typeClass = $holidayTypeClass((string) $holiday['type']);
                        ?>
                        <tr>
                            <td class="cell-no"><?php echo $i + 1; ?></td>
                            <td class="cell-date">
                                <div class="date-chip">
                                    <span class="num"><?php echo $dayNum; ?></span>
                                    <span class="mon"><?php echo thaiMonthShort($m); ?> <?php echo $holidayYearTh; ?></span>
                                    <span class="dow"><?php echo htmlspecialchars($dayNames[$dow] ?? ''); ?></span>
                                </div>
                            </td>
                            <td class="cell-name">
                                <div class="name-th"><?php echo htmlspecialchars($holiday['name']); ?></div>
                                <?php if ($nameEn !== ''): ?>
                                <div class="name-en"><?php echo htmlspecialchars($nameEn); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="cell-type">
                                <span class="type-pill <?php echo htmlspecialchars($typeClass); ?>">
                                    <?php echo htmlspecialchars($holidayTypeLabel((string) $holiday['type'])); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">ยังไม่มีข้อมูลวันหยุดสำหรับปี พ.ศ. <?php echo (int) $holidayYearTh; ?></div>
            <?php endif; ?>

            <footer class="doc-footer">
                <p><span class="note-label">หมายเหตุ</span> ตารางนี้แสดงวันหยุดนักขัตฤกษ์และวันหยุดบริษัทในระบบ TP-HR — วันหยุดประจำสัปดาห์ของพนักงานแต่ละคนอาจแตกต่างกัน</p>
                <p><?php echo htmlspecialchars($companyName); ?> · เอกสารจากระบบ TP-HR · <?php echo htmlspecialchars($printedAt); ?></p>
            </footer>
        </div>
    </div>
</div>

<script>
(function() {
    var USABLE_MM_H = 297 - 22;

    function mmToPx(mm) {
        var probe = document.createElement('div');
        probe.style.cssText = 'width:1mm;position:absolute;visibility:hidden;';
        document.body.appendChild(probe);
        var px = probe.getBoundingClientRect().width || (96 / 25.4);
        document.body.removeChild(probe);
        return mm * px;
    }

    window.tpHolidayFitA4 = function() {
        var content = document.getElementById('a4-content');
        if (!content) return;
        content.style.transform = 'none';
        content.style.width = '';
        var maxH = mmToPx(USABLE_MM_H);
        var h = content.scrollHeight;
        if (h > maxH) {
            var s = maxH / h;
            content.style.transform = 'scale(' + s + ')';
            content.style.width = (100 / s).toFixed(4) + '%';
        }
    };

    window.tpHolidayResetA4 = function() {
        var c = document.getElementById('a4-content');
        if (c) { c.style.transform = ''; c.style.width = ''; }
    };

    window.tpHolidayPrint = function() {
        tpHolidayFitA4();
        window.print();
    };

    window.addEventListener('beforeprint', tpHolidayFitA4);
    window.addEventListener('afterprint', tpHolidayResetA4);
    window.addEventListener('load', tpHolidayFitA4);
})();
</script>

</body>
</html>
