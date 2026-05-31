<?php
/**
 * Annual holidays — print / save as PDF (single A4, month-card calendar layout).
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

$monthsWithHolidays = [];
for ($m = 1; $m <= 12; $m++) {
    if (!empty($holidaysByMonth[$m])) {
        $monthsWithHolidays[] = $m;
    }
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

$holidayTypeDotClass = static function (string $type): string {
    return match ($type) {
        'PUBLIC' => 'dot-public',
        'COMPANY' => 'dot-company',
        'SPECIAL' => 'dot-special',
        'SUBSTITUTE' => 'dot-substitute',
        default => 'dot-default',
    };
};

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

$yearRangeLabel = '1 ม.ค. ' . $holidayYearTh . ' – 31 ธ.ค. ' . $holidayYearTh;
$monthCardIndex = 0;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>วันหยุดประจำปี <?php echo (int) $holidayYearTh; ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/icons/tphr-app-icon.svg">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --a4-w: 210mm;
            --a4-h: 297mm;
            --margin-y: 10mm;
            --margin-x: 11mm;
            --sheet-h: calc(var(--a4-h) - (var(--margin-y) * 2));
            --ink: #1a365d;
            --violet: #6d28d9;
            --ink-soft: #475569;
            --ink-muted: #94a3b8;
            --gold: #c8a951;
            --line: #e8edf2;
            --wash: #f7f9fc;
            --card-bg: #fafbfc;
        }

        html { -webkit-text-size-adjust: 100%; }

        body {
            font-family: 'Sarabun', sans-serif;
            font-size: 11px;
            line-height: 1.4;
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
                font-family: system-ui, sans-serif;
            }
            .toolbar-actions { display: flex; gap: 8px; flex-wrap: wrap; }
            .toolbar a, .toolbar button {
                min-height: 48px;
                padding: 0 18px;
                display: inline-flex;
                align-items: center;
                border-radius: 10px;
                border: 1px solid rgba(255, 255, 255, 0.12);
                background: rgba(255, 255, 255, 0.06);
                color: #e2e8f0;
                font: inherit;
                font-size: 13px;
                text-decoration: none;
                cursor: pointer;
            }
            .toolbar .btn-print {
                background: var(--ink);
                color: #fff;
                font-weight: 600;
            }
            .toolbar-note { font-size: 11px; color: rgba(226, 232, 240, 0.55); }
            .a4-sheet {
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
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
        .watermark img { width: 28%; opacity: 0.025; filter: grayscale(100%); }

        /* ---- Header ---- */
        .doc-header {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-bottom: 8px;
            margin-bottom: 10px;
            border-bottom: 2px solid var(--ink);
        }
        .doc-header::after {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            bottom: -4px;
            height: 1px;
            background: var(--gold);
        }
        .doc-header img { height: 44px; width: auto; display: block; }
        .doc-header-meta { text-align: right; max-width: 58%; }
        .doc-header-meta .co-th { font-size: 11.5px; font-weight: 700; color: var(--ink); line-height: 1.25; }
        .doc-header-meta .co-en { font-size: 8.5px; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: var(--ink-soft); margin-top: 1px; }
        .doc-header-meta .co-tax { font-size: 8.5px; color: var(--ink-muted); margin-top: 2px; }

        /* ---- Hero title ---- */
        .doc-hero { text-align: center; margin-bottom: 10px; }
        .doc-hero h1 {
            font-size: 17px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1.2;
        }
        .doc-hero .tagline {
            display: inline-block;
            margin-top: 5px;
            padding: 3px 12px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 600;
            color: var(--ink);
            background: linear-gradient(90deg, #eef2ff, #faf5ff);
            border: 1px solid #e2e8f0;
        }
        .doc-hero .sub {
            margin-top: 4px;
            font-size: 9.5px;
            color: var(--ink-soft);
            letter-spacing: 0.03em;
        }
        .doc-hero .range {
            margin-top: 3px;
            font-size: 9px;
            color: var(--ink-muted);
        }
        .doc-hero .meta {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 4px 14px;
            margin-top: 6px;
            font-size: 8.5px;
            color: var(--ink-muted);
        }
        .doc-hero .meta b { color: var(--ink-soft); font-weight: 600; }

        /* ---- Stats (full width) ---- */
        .stat-row {
            display: flex;
            gap: 6px;
            margin-bottom: 10px;
        }
        .stat-chip {
            flex: 1 1 0;
            min-width: 0;
            padding: 6px 8px;
            text-align: center;
            border-radius: 8px;
            background: var(--wash);
            border: 1px solid var(--line);
        }
        .stat-chip .num {
            display: block;
            font-size: 16px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1;
            font-variant-numeric: tabular-nums;
        }
        .stat-chip .lbl {
            display: block;
            margin-top: 2px;
            font-size: 7.5px;
            font-weight: 500;
            color: var(--ink-soft);
            line-height: 1.2;
        }

        /* ---- Month card grid (2 columns) ---- */
        .calendar-grid {
            flex: 1 1 auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 7px 8px;
            align-content: start;
            width: 100%;
        }

        .month-card {
            break-inside: avoid;
            page-break-inside: avoid;
            border-radius: 9px;
            overflow: hidden;
            background: var(--card-bg);
            border: 1px solid var(--line);
            box-shadow: 0 1px 3px rgba(26, 54, 93, 0.04);
        }

        .month-card__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            padding: 5px 9px;
            font-size: 9.5px;
            font-weight: 700;
            color: #fff;
            letter-spacing: 0.02em;
        }
        .month-card.is-navy .month-card__head { background: linear-gradient(135deg, #1a365d, #234876); }
        .month-card.is-violet .month-card__head { background: linear-gradient(135deg, #6d28d9, #7c3aed); }
        .month-card__count {
            font-size: 8px;
            font-weight: 600;
            opacity: 0.88;
            white-space: nowrap;
        }

        .month-card__list {
            list-style: none;
            padding: 4px 7px 5px;
        }

        .holiday-item {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 4px 0;
            min-height: 0;
        }
        .holiday-item + .holiday-item {
            border-top: 1px solid #eef2f6;
        }

        .date-dot {
            flex-shrink: 0;
            width: 21px;
            height: 21px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9.5px;
            font-weight: 700;
            color: #fff;
            font-variant-numeric: tabular-nums;
            line-height: 1;
        }
        .month-card.is-navy .date-dot { background: var(--ink); }
        .month-card.is-violet .date-dot { background: var(--violet); }

        .holiday-item__body {
            flex: 1;
            min-width: 0;
            line-height: 1.3;
        }
        .holiday-item__body .name-th {
            font-size: 10px;
            font-weight: 600;
            color: #0f172a;
        }
        .holiday-item__body .name-en {
            font-size: 8px;
            font-weight: 400;
            color: var(--ink-muted);
        }

        .type-dot {
            flex-shrink: 0;
            width: 7px;
            height: 7px;
            border-radius: 50%;
        }
        .type-dot.dot-public { background: #6366f1; }
        .type-dot.dot-company { background: #22c55e; }
        .type-dot.dot-special { background: #a855f7; }
        .type-dot.dot-substitute { background: #f59e0b; }
        .type-dot.dot-default { background: #94a3b8; }

        /* ---- Footer + legend ---- */
        .doc-footer {
            margin-top: auto;
            padding-top: 8px;
            border-top: 1px solid var(--line);
            font-size: 7.5px;
            color: var(--ink-muted);
            line-height: 1.45;
        }
        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 10px;
            margin-bottom: 4px;
        }
        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .legend-item .type-dot { width: 6px; height: 6px; }

        .empty-state {
            flex: 1;
            grid-column: 1 / -1;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: var(--ink-soft);
            font-size: 12px;
            padding: 24px;
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

            .month-card__head,
            .date-dot,
            .stat-chip,
            .doc-hero .tagline {
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
        <p class="toolbar-note">A4 · 1 หน้า</p>
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

            <div class="doc-hero">
                <h1>วันหยุดประจำปี พ.ศ. <?php echo (int) $holidayYearTh; ?></h1>
                <p class="tagline">วางแผนล่วงหน้าได้ตลอดทั้งปี</p>
                <p class="sub">Annual Holiday Schedule · <?php echo (int) $holidayYear; ?></p>
                <p class="range"><?php echo htmlspecialchars($yearRangeLabel); ?></p>
                <p class="meta">
                    <span><?php echo htmlspecialchars($docRef); ?></span>
                    <span>พิมพ์ <?php echo htmlspecialchars($printedAt); ?></span>
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

            <div class="calendar-grid">
                <?php foreach ($monthsWithHolidays as $m):
                    $monthCardIndex++;
                    $cardTheme = ($monthCardIndex % 2 === 1) ? 'is-navy' : 'is-violet';
                    $monthCount = count($holidaysByMonth[$m]);
                ?>
                <article class="month-card <?php echo $cardTheme; ?>">
                    <header class="month-card__head">
                        <span><?php echo thaiMonth($m); ?> <?php echo (int) $holidayYearTh; ?></span>
                        <span class="month-card__count"><?php echo (int) $monthCount; ?> วัน</span>
                    </header>
                    <ul class="month-card__list">
                        <?php foreach ($holidaysByMonth[$m] as $holiday):
                            $dayNum = (int) date('j', strtotime($holiday['date']));
                            $nameEn = trim((string) ($holiday['name_en'] ?? ''));
                            $dotClass = $holidayTypeDotClass((string) $holiday['type']);
                            $typeLabel = $holidayTypeLabel((string) $holiday['type']);
                        ?>
                        <li class="holiday-item">
                            <span class="date-dot" aria-hidden="true"><?php echo $dayNum; ?></span>
                            <div class="holiday-item__body">
                                <span class="name-th"><?php echo htmlspecialchars($holiday['name']); ?></span>
                                <?php if ($nameEn !== ''): ?>
                                <span class="name-en"> · <?php echo htmlspecialchars($nameEn); ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="type-dot <?php echo htmlspecialchars($dotClass); ?>"
                                  title="<?php echo htmlspecialchars($typeLabel); ?>"
                                  aria-label="<?php echo htmlspecialchars($typeLabel); ?>"></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </article>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">ยังไม่มีข้อมูลวันหยุดสำหรับปี พ.ศ. <?php echo (int) $holidayYearTh; ?></div>
            <?php endif; ?>

            <footer class="doc-footer">
                <div class="legend" aria-label="ประเภทวันหยุด">
                    <span class="legend-item"><span class="type-dot dot-public"></span> วันหยุดราชการ</span>
                    <span class="legend-item"><span class="type-dot dot-company"></span> วันหยุดบริษัท</span>
                    <span class="legend-item"><span class="type-dot dot-substitute"></span> วันหยุดชดเชย</span>
                    <span class="legend-item"><span class="type-dot dot-special"></span> วันหยุดพิเศษ</span>
                </div>
                <p>หมายเหตุ: วันหยุดประจำสัปดาห์ของพนักงานแต่ละคนอาจแตกต่างกัน · <?php echo htmlspecialchars($companyName); ?> · TP-HR</p>
            </footer>
        </div>
    </div>
</div>

<script>
(function() {
    var USABLE_MM_H = 297 - 20;

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
