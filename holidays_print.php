<?php
/**
 * Annual holidays — print / save as PDF (single A4, balanced 2-column month cards).
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

/** Balance months into two columns by holiday count (not CSS grid row order). */
$leftMonths = [];
$rightMonths = [];
$leftWeight = 0;
$rightWeight = 0;
foreach ($monthsWithHolidays as $m) {
    $weight = count($holidaysByMonth[$m]) + 1;
    if ($leftWeight <= $rightWeight) {
        $leftMonths[] = $m;
        $leftWeight += $weight;
    } else {
        $rightMonths[] = $m;
        $rightWeight += $weight;
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
        'PUBLIC' => 'ราชการ',
        'COMPANY' => 'บริษัท',
        'SPECIAL' => 'พิเศษ',
        'SUBSTITUTE' => 'ชดเชย',
        default => 'อื่นๆ',
    };
};

$holidayTypeClass = static function (string $type): string {
    return match ($type) {
        'PUBLIC' => 'type-public',
        'COMPANY' => 'type-company',
        'SPECIAL' => 'type-special',
        'SUBSTITUTE' => 'type-substitute',
        default => 'type-default',
    };
};

$monthCardThemes = ['theme-sky', 'theme-violet', 'theme-amber'];
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

$renderMonthCard = static function (int $m, int $themeIndex) use ($holidaysByMonth, $holidayYearTh, $holidayTypeLabel, $holidayTypeClass, $monthCardThemes): void {
    $theme = $monthCardThemes[$themeIndex % 3];
    $monthCount = count($holidaysByMonth[$m]);
    ?>
    <article class="month-card <?php echo $theme; ?>">
        <header class="month-card__head">
            <div class="month-card__title">
                <span class="month-card__name"><?php echo thaiMonth($m); ?></span>
                <span class="month-card__year"><?php echo (int) $holidayYearTh; ?></span>
            </div>
            <span class="month-card__count"><?php echo (int) $monthCount; ?> วัน</span>
        </header>
        <ul class="month-card__list">
            <?php foreach ($holidaysByMonth[$m] as $holiday):
                $dayNum = (int) date('j', strtotime($holiday['date']));
                $nameEn = trim((string) ($holiday['name_en'] ?? ''));
                $typeClass = $holidayTypeClass((string) $holiday['type']);
                $typeLabel = $holidayTypeLabel((string) $holiday['type']);
            ?>
            <li class="holiday-item">
                <span class="date-dot" aria-hidden="true"><?php echo $dayNum; ?></span>
                <div class="holiday-item__body">
                    <p class="name-th"><?php echo htmlspecialchars($holiday['name']); ?></p>
                    <?php if ($nameEn !== ''): ?>
                    <p class="name-en"><?php echo htmlspecialchars($nameEn); ?></p>
                    <?php endif; ?>
                </div>
                <span class="type-tag <?php echo htmlspecialchars($typeClass); ?>"><?php echo htmlspecialchars($typeLabel); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </article>
    <?php
};
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
            --margin-y: 9mm;
            --margin-x: 10mm;
            --sheet-h: calc(var(--a4-h) - (var(--margin-y) * 2));
            --ink: #1a365d;
            --ink-soft: #475569;
            --ink-muted: #94a3b8;
            --gold: #c8a951;
            --line: #e8edf2;
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
                font-family: system-ui, sans-serif;
            }
            .toolbar-actions { display: flex; gap: 8px; }
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
            .toolbar .btn-print { background: var(--ink); color: #fff; font-weight: 600; }
            .toolbar-note { font-size: 11px; color: rgba(226, 232, 240, 0.55); }
            .a4-sheet { box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35); border-radius: 3px; }
        }

        .a4-sheet {
            position: relative;
            width: var(--a4-w);
            height: var(--a4-h);
            max-width: var(--a4-w);
            margin: 0 auto;
            overflow: hidden;
            background: linear-gradient(180deg, #fff 0%, #fcfdfe 100%);
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
        .watermark img { width: 26%; opacity: 0.022; filter: grayscale(100%); }

        .doc-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-bottom: 9px;
            margin-bottom: 12px;
            border-bottom: 1.5px solid var(--ink);
        }
        .doc-header img { height: 48px; width: auto; display: block; }
        .doc-header-meta { text-align: right; max-width: 58%; }
        .doc-header-meta .co-th { font-size: 12px; font-weight: 700; color: var(--ink); line-height: 1.3; }
        .doc-header-meta .co-en { font-size: 9px; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: var(--ink-soft); margin-top: 2px; }
        .doc-header-meta .co-tax { font-size: 9px; color: var(--ink-muted); margin-top: 3px; }

        .doc-hero {
            text-align: center;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--line);
        }
        .doc-hero h1 {
            font-size: 19px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1.2;
        }
        .doc-hero .sub {
            margin-top: 4px;
            font-size: 10px;
            color: var(--ink-soft);
        }
        .doc-hero .meta {
            margin-top: 6px;
            font-size: 9px;
            color: var(--ink-muted);
        }

        .stat-row {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }
        .stat-chip {
            flex: 1;
            padding: 8px 10px;
            text-align: center;
            border-radius: 12px;
            background: #fff;
            border: 1px solid var(--line);
        }
        .stat-chip .num {
            display: block;
            font-size: 18px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1;
        }
        .stat-chip .lbl {
            display: block;
            margin-top: 3px;
            font-size: 8.5px;
            font-weight: 500;
            color: var(--ink-soft);
        }

        /* Balanced 2 columns (PHP split, not grid rows) */
        .calendar-columns {
            flex: 1 1 auto;
            display: flex;
            gap: 10px;
            align-items: stretch;
            min-height: 0;
        }
        .calendar-col {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .month-card {
            flex: 0 0 auto;
            border-radius: 12px;
            background: #fff;
            border: 1px solid #dde4ec;
            overflow: hidden;
        }

        .month-card__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 7px 11px;
        }
        .month-card__title { display: flex; align-items: baseline; gap: 6px; min-width: 0; }
        .month-card__name { font-size: 11px; font-weight: 700; line-height: 1.2; }
        .month-card__year { font-size: 9px; font-weight: 600; opacity: 0.75; }
        .month-card__count {
            font-size: 8.5px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.65);
            white-space: nowrap;
        }

        .theme-sky .month-card__head { background: #dbeafe; color: #1e40af; }
        .theme-sky .date-dot { background: #3b82f6; color: #fff; }
        .theme-violet .month-card__head { background: #ede9fe; color: #6d28d9; }
        .theme-violet .date-dot { background: #8b5cf6; color: #fff; }
        .theme-amber .month-card__head { background: #fef3c7; color: #b45309; }
        .theme-amber .date-dot { background: #f59e0b; color: #fff; }

        .month-card__list {
            list-style: none;
            padding: 6px 10px 8px;
        }

        .holiday-item {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            padding: 6px 0;
        }
        .holiday-item + .holiday-item {
            border-top: 1px dashed #eef2f6;
        }

        .date-dot {
            flex-shrink: 0;
            width: 24px;
            height: 24px;
            margin-top: 1px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10.5px;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            line-height: 1;
        }

        .holiday-item__body { flex: 1; min-width: 0; }
        .holiday-item__body .name-th {
            font-size: 11px;
            font-weight: 600;
            color: #0f172a;
            line-height: 1.35;
        }
        .holiday-item__body .name-en {
            margin-top: 1px;
            font-size: 9px;
            color: var(--ink-muted);
            line-height: 1.3;
        }

        .type-tag {
            flex-shrink: 0;
            margin-top: 2px;
            font-size: 7.5px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 999px;
            line-height: 1.2;
        }
        .type-tag.type-public { background: #eef2ff; color: #4338ca; }
        .type-tag.type-company { background: #ecfdf5; color: #047857; }
        .type-tag.type-special { background: #faf5ff; color: #7e22ce; }
        .type-tag.type-substitute { background: #fffbeb; color: #b45309; }
        .type-tag.type-default { background: #f1f5f9; color: #64748b; }

        .doc-footer {
            margin-top: auto;
            padding-top: 10px;
            border-top: 1px solid var(--line);
            font-size: 8px;
            color: var(--ink-muted);
            line-height: 1.5;
        }

        .empty-state {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
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
            }
            .toolbar { display: none !important; }
            .month-card,
            .stat-chip,
            .date-dot,
            .a4-sheet {
                box-shadow: none !important;
                filter: none !important;
            }
            .month-card__head, .date-dot, .type-tag, .stat-chip {
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
                <p class="sub">Annual Holiday Schedule · <?php echo (int) $holidayYear; ?> · 1 ม.ค. – 31 ธ.ค. <?php echo (int) $holidayYearTh; ?></p>
                <p class="meta"><?php echo htmlspecialchars($docRef); ?> · พิมพ์ <?php echo htmlspecialchars($printedAt); ?></p>
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

            <div class="calendar-columns">
                <div class="calendar-col">
                    <?php foreach ($leftMonths as $i => $m) {
                        $renderMonthCard($m, $i);
                    } ?>
                </div>
                <div class="calendar-col">
                    <?php foreach ($rightMonths as $i => $m) {
                        $renderMonthCard($m, $i + count($leftMonths));
                    } ?>
                </div>
            </div>
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
    var USABLE_MM_H = 297 - 18;
    var MIN_FILL = 0.9;
    var MAX_UPSCALE = 1.14;

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
        var scale = 1;

        if (h > maxH) {
            scale = maxH / h;
        } else if (h < maxH * MIN_FILL) {
            scale = Math.min(MAX_UPSCALE, (maxH * 0.96) / h);
        }

        if (Math.abs(scale - 1) > 0.008) {
            content.style.transform = 'scale(' + scale + ')';
            content.style.width = (100 / scale).toFixed(4) + '%';
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
