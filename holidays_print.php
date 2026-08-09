<?php
/**
 * Annual holidays — export sheet (single A4, balanced 2-column month cards).
 * PDF via the browser print pipeline; PNG via foreignObject rasterisation of the
 * same sheet, so both exports come from one layout.
 */

require_once __DIR__ . '/bootstrap.php';
Auth::requireLogin();

$pdo = getDB();
$holidayYear = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
if ($holidayYear < 2000 || $holidayYear > 2100) {
    $holidayYear = (int) date('Y');
}
$holidayYearTh = $holidayYear + 543;

/** `auto=pdf|png` lets other pages deep-link straight into an export. */
$autoAction = $_GET['auto'] ?? '';
if (!in_array($autoAction, ['pdf', 'png'], true)) {
    $autoAction = '';
}

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
/** Holidays landing Mon–Fri — the ones that actually remove a working day. */
$workdayHolidayCount = 0;
foreach ($holidays as $holiday) {
    $label = $holidayTypeLabel((string) $holiday['type']);
    $typeCounts[$label] = ($typeCounts[$label] ?? 0) + 1;
    if ((int) date('N', strtotime($holiday['date'])) <= 5) {
        $workdayHolidayCount++;
    }
}

/**
 * Payload for the PNG renderer. Chromium taints a canvas as soon as the SVG it
 * rasterises contains a <foreignObject>, so the image export cannot re-use the DOM
 * — it redraws this same document with the Canvas 2D API from the data below.
 */
$posterMonth = static function (int $m, int $themeIndex) use ($holidaysByMonth, $holidayTypeLabel, $holidayTypeClass): array {
    $items = [];
    foreach ($holidaysByMonth[$m] as $holiday) {
        $items[] = [
            'day' => (int) date('j', strtotime($holiday['date'])),
            'nameTh' => (string) $holiday['name'],
            'nameEn' => trim((string) ($holiday['name_en'] ?? '')),
            'typeLabel' => $holidayTypeLabel((string) $holiday['type']),
            'typeClass' => $holidayTypeClass((string) $holiday['type']),
        ];
    }
    return [
        'name' => thaiMonth($m),
        'theme' => $themeIndex % 3,
        'count' => count($items),
        'items' => $items,
    ];
};

$posterLeft = [];
foreach ($leftMonths as $i => $m) {
    $posterLeft[] = $posterMonth($m, $i);
}
$posterRight = [];
foreach ($rightMonths as $i => $m) {
    $posterRight[] = $posterMonth($m, $i + count($leftMonths));
}

$posterStats = [
    ['num' => (string) $holidayCount, 'label' => 'วันหยุดทั้งปี', 'primary' => true],
    ['num' => (string) $workdayHolidayCount, 'label' => 'ตรงวันทำงาน (จ.–ศ.)', 'primary' => false],
    ['num' => (string) count($monthsWithHolidays), 'label' => 'เดือนที่มีวันหยุด', 'primary' => false],
];
if (count($typeCounts) > 1) {
    foreach ($typeCounts as $label => $count) {
        $posterStats[] = ['num' => (string) $count, 'label' => $label, 'primary' => false];
    }
}

$posterData = [
    'yearTh' => $holidayYearTh,
    'year' => $holidayYear,
    'companyTh' => $companyName,
    'companyEn' => $companyNameEn,
    'taxId' => $companyTaxId,
    'logo' => $logoSrc,
    'watermark' => $watermarkSrc,
    'title' => 'วันหยุดประจำปี พ.ศ. ' . $holidayYearTh,
    'subtitle' => 'Annual Holiday Schedule · ' . $holidayYear . ' · 1 ม.ค. – 31 ธ.ค. ' . $holidayYearTh,
    'meta' => $docRef . ' · พิมพ์ ' . $printedAt,
    'footer' => 'หมายเหตุ: วันหยุดประจำสัปดาห์ของพนักงานแต่ละคนอาจแตกต่างกัน · ' . $companyName . ' · TP-HR',
    'stats' => $posterStats,
    'columns' => [$posterLeft, $posterRight],
];

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
    <link rel="icon" type="image/png" href="/assets/icons/icon-192-v3.png">
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
            .toolbar .btn-png { background: var(--gold); border-color: var(--gold); color: #2a2005; font-weight: 600; }
            .toolbar button[disabled] { opacity: 0.55; cursor: progress; }
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

        /* Navy rule with a gold hairline under it — the TP-ASSET document signature. */
        .doc-header {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-bottom: 9px;
            margin-bottom: 14px;
            border-bottom: 1.5px solid var(--ink);
        }
        .doc-header::after {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            bottom: -3px;
            height: 1px;
            background: var(--gold);
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
            font-size: 20px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1.2;
        }
        .doc-hero h1::after {
            content: '';
            display: block;
            width: 46px;
            height: 2px;
            margin: 6px auto 0;
            border-radius: 2px;
            background: var(--gold);
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
        .stat-chip.is-primary {
            background: var(--ink);
            border-color: var(--ink);
        }
        .stat-chip.is-primary .num { color: #fff; }
        .stat-chip.is-primary .lbl { color: rgba(255, 255, 255, 0.72); }
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
        .theme-violet .month-card__head { background: #ede9fe; color: #8c6d4d; }
        .theme-violet .date-dot { background: #c7a989; color: #fff; }
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
            <button type="button" class="btn-png" id="btn-png" onclick="tpHolidayDownloadPng()">ดาวน์โหลด PNG</button>
            <a href="holidays.php?year=<?php echo (int) $holidayYear; ?>">← กลับ</a>
        </div>
        <p class="toolbar-note" id="toolbar-note">A4 · 1 หน้า</p>
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
                <div class="stat-chip is-primary">
                    <span class="num"><?php echo (int) $holidayCount; ?></span>
                    <span class="lbl">วันหยุดทั้งปี</span>
                </div>
                <div class="stat-chip">
                    <span class="num"><?php echo (int) $workdayHolidayCount; ?></span>
                    <span class="lbl">ตรงวันทำงาน (จ.–ศ.)</span>
                </div>
                <div class="stat-chip">
                    <span class="num"><?php echo count($monthsWithHolidays); ?></span>
                    <span class="lbl">เดือนที่มีวันหยุด</span>
                </div>
                <?php /* A single type across the board would just restate the total. */ ?>
                <?php if (count($typeCounts) > 1): ?>
                <?php foreach ($typeCounts as $typeLabel => $count): ?>
                <div class="stat-chip">
                    <span class="num"><?php echo (int) $count; ?></span>
                    <span class="lbl"><?php echo htmlspecialchars($typeLabel); ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
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
    var MIN_FILL = 0.92;
    var MAX_UPSCALE = 1.5;
    var PNG_SCALE = 2; /* 2x of 210mm@96dpi ≈ 1587x2245 — sharp on screen and in chat */
    var PNG_FILENAME = 'TP-ASSET_Holidays_<?php echo (int) $holidayYearTh; ?>.png';

    function mmToPx(mm) {
        var probe = document.createElement('div');
        probe.style.cssText = 'width:1mm;position:absolute;visibility:hidden;';
        document.body.appendChild(probe);
        var px = probe.getBoundingClientRect().width || (96 / 25.4);
        document.body.removeChild(probe);
        return mm * px;
    }

    /* .a4-content is a full-height flex column, so its scrollHeight always reports the
       whole sheet and never revealed how short the real content was. Collapse it for
       the measurement, then scale so the content fills the page. */
    window.tpHolidayFitA4 = function() {
        var content = document.getElementById('a4-content');
        if (!content) return;

        var columns = content.querySelector('.calendar-columns');
        content.style.transform = 'none';
        content.style.width = '';
        content.style.minHeight = '0';
        if (columns) columns.style.flex = '0 0 auto';

        var maxH = mmToPx(USABLE_MM_H);
        var scale = 1;

        for (var pass = 0; pass < 4; pass++) {
            var h = content.scrollHeight * scale;
            var next = scale;
            if (h > maxH) {
                next = scale * (maxH / h);
            } else if (h < maxH * MIN_FILL) {
                next = Math.min(MAX_UPSCALE, scale * ((maxH * 0.97) / h));
            }
            if (Math.abs(next - scale) < 0.004) {
                scale = next;
                break;
            }
            scale = next;
            setBoxScale(content, scale);
        }

        if (columns) columns.style.flex = '';
        setBoxScale(content, scale);
    };

    function setBoxScale(content, scale) {
        if (Math.abs(scale - 1) <= 0.008) {
            content.style.transform = 'none';
            content.style.width = '';
            content.style.minHeight = '';
            return;
        }
        var divisor = scale.toFixed(4);
        content.style.transform = 'scale(' + divisor + ')';
        content.style.width = 'calc((var(--a4-w) - (var(--margin-x) * 2)) / ' + divisor + ')';
        /* Pre-divide so the scaled box lands exactly on the printable height. */
        content.style.minHeight = 'calc((var(--a4-h) - (var(--margin-y) * 2)) / ' + divisor + ')';
    }

    window.tpHolidayResetA4 = function() {
        var c = document.getElementById('a4-content');
        if (c) { c.style.transform = ''; c.style.width = ''; c.style.minHeight = ''; }
    };

    window.tpHolidayPrint = function() {
        tpHolidayFitA4();
        window.print();
    };

    /* ---- PNG poster ------------------------------------------------------------
       Canvas 2D redraw of the sheet above. The obvious route — serialise the DOM
       into an <svg><foreignObject> and drawImage it — is unusable: Chromium marks
       the canvas as tainted for any foreignObject SVG, so toBlob() throws. Layout
       constants here mirror the print CSS; change both together. */
    var P = <?php echo json_encode($posterData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    var SHEET_W = 794;   /* A4 at 96dpi */
    var SHEET_H = 1123;
    var PAD_X = 38;
    var PAD_Y = 34;
    var COL_GAP = 12;

    var INK = '#1a365d';
    var INK_SOFT = '#475569';
    var INK_MUTED = '#94a3b8';
    var GOLD = '#c8a951';
    var LINE = '#e8edf2';

    var THEMES = [
        { head: '#dbeafe', fg: '#1e40af', dot: '#3b82f6' },
        { head: '#ede9fe', fg: '#8c6d4d', dot: '#c7a989' },
        { head: '#fef3c7', fg: '#b45309', dot: '#f59e0b' }
    ];
    var TAGS = {
        'type-public': ['#eef2ff', '#4338ca'],
        'type-company': ['#ecfdf5', '#047857'],
        'type-special': ['#faf5ff', '#7e22ce'],
        'type-substitute': ['#fffbeb', '#b45309'],
        'type-default': ['#f1f5f9', '#64748b']
    };

    function font(ctx, weight, size) {
        ctx.font = weight + ' ' + size + 'px Sarabun, "IBM Plex Sans Thai", sans-serif';
    }

    function roundRect(ctx, x, y, w, h, r) {
        var rr = Math.min(r, w / 2, h / 2);
        ctx.beginPath();
        ctx.moveTo(x + rr, y);
        ctx.arcTo(x + w, y, x + w, y + h, rr);
        ctx.arcTo(x + w, y + h, x, y + h, rr);
        ctx.arcTo(x, y + h, x, y, rr);
        ctx.arcTo(x, y, x + w, y, rr);
        ctx.closePath();
    }

    function wrapLines(ctx, text, maxWidth) {
        var words = String(text).split(/\s+/).filter(Boolean);
        if (!words.length) return [''];
        var lines = [];
        var line = words[0];
        for (var i = 1; i < words.length; i++) {
            var candidate = line + ' ' + words[i];
            if (ctx.measureText(candidate).width <= maxWidth) {
                line = candidate;
            } else {
                lines.push(line);
                line = words[i];
            }
        }
        lines.push(line);
        /* Thai has no spaces — break the overlong run by character. */
        var out = [];
        for (var j = 0; j < lines.length; j++) {
            var chunk = lines[j];
            while (ctx.measureText(chunk).width > maxWidth && chunk.length > 1) {
                var cut = chunk.length - 1;
                while (cut > 1 && ctx.measureText(chunk.slice(0, cut)).width > maxWidth) cut--;
                out.push(chunk.slice(0, cut));
                chunk = chunk.slice(cut);
            }
            out.push(chunk);
        }
        return out;
    }

    /** Height of one month card, and the wrapped lines each item needs. */
    function measureCard(ctx, month, cardW) {
        var innerW = cardW - 20;
        var tagW = 0;
        font(ctx, '600', 7.5);
        month.items.forEach(function(item) {
            tagW = Math.max(tagW, ctx.measureText(item.typeLabel).width + 12);
        });
        var bodyW = innerW - 24 - 9 - tagW - 8;

        var itemsH = 0;
        var measured = month.items.map(function(item) {
            font(ctx, '600', 11);
            var thLines = wrapLines(ctx, item.nameTh, bodyW);
            var enLines = [];
            if (item.nameEn) {
                font(ctx, '400', 9);
                enLines = wrapLines(ctx, item.nameEn, bodyW).slice(0, 1);
            }
            var h = Math.max(26, thLines.length * 15 + (enLines.length ? enLines.length * 12 + 1 : 0)) + 12;
            itemsH += h;
            return { item: item, thLines: thLines, enLines: enLines, h: h };
        });

        return { head: 24, listPad: 14, itemsH: itemsH, tagW: tagW, bodyW: bodyW, rows: measured,
                 total: 24 + 14 + itemsH };
    }

    function drawCard(ctx, month, card, x, y, cardW) {
        var theme = THEMES[month.theme % THEMES.length];

        ctx.save();
        roundRect(ctx, x, y, cardW, card.total, 12);
        ctx.clip();

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(x, y, cardW, card.total);
        ctx.fillStyle = theme.head;
        ctx.fillRect(x, y, cardW, card.head);

        ctx.textBaseline = 'middle';
        font(ctx, '700', 11);
        ctx.fillStyle = theme.fg;
        ctx.textAlign = 'left';
        ctx.fillText(month.name, x + 11, y + card.head / 2 + 0.5);
        var nameW = ctx.measureText(month.name).width;
        font(ctx, '600', 9);
        ctx.globalAlpha = 0.75;
        ctx.fillText(String(P.yearTh), x + 11 + nameW + 6, y + card.head / 2 + 1);
        ctx.globalAlpha = 1;

        var pill = month.count + ' วัน';
        font(ctx, '600', 8.5);
        var pillW = ctx.measureText(pill).width + 16;
        ctx.fillStyle = 'rgba(255,255,255,0.65)';
        roundRect(ctx, x + cardW - 11 - pillW, y + card.head / 2 - 7, pillW, 14, 7);
        ctx.fill();
        ctx.fillStyle = theme.fg;
        ctx.textAlign = 'center';
        ctx.fillText(pill, x + cardW - 11 - pillW / 2, y + card.head / 2 + 0.5);

        var rowY = y + card.head + 6;
        card.rows.forEach(function(row, index) {
            if (index > 0) {
                ctx.strokeStyle = '#eef2f6';
                ctx.lineWidth = 1;
                ctx.setLineDash([2, 2]);
                ctx.beginPath();
                ctx.moveTo(x + 10, rowY + 0.5);
                ctx.lineTo(x + cardW - 10, rowY + 0.5);
                ctx.stroke();
                ctx.setLineDash([]);
            }

            var top = rowY + 6;

            ctx.fillStyle = theme.dot;
            ctx.beginPath();
            ctx.arc(x + 10 + 12, top + 12, 12, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = '#ffffff';
            font(ctx, '700', 10.5);
            ctx.textAlign = 'center';
            ctx.fillText(String(row.item.day), x + 10 + 12, top + 12.5);

            var bodyX = x + 10 + 24 + 9;
            ctx.textAlign = 'left';
            ctx.fillStyle = '#0f172a';
            font(ctx, '600', 11);
            row.thLines.forEach(function(line, i) {
                ctx.fillText(line, bodyX, top + 7 + i * 15);
            });
            if (row.enLines.length) {
                ctx.fillStyle = INK_MUTED;
                font(ctx, '400', 9);
                ctx.fillText(row.enLines[0], bodyX, top + 7 + row.thLines.length * 15 + 5);
            }

            var tag = TAGS[row.item.typeClass] || TAGS['type-default'];
            font(ctx, '600', 7.5);
            var tagW = ctx.measureText(row.item.typeLabel).width + 12;
            ctx.fillStyle = tag[0];
            roundRect(ctx, x + cardW - 10 - tagW, top + 2, tagW, 13, 6.5);
            ctx.fill();
            ctx.fillStyle = tag[1];
            ctx.textAlign = 'center';
            ctx.fillText(row.item.typeLabel, x + cardW - 10 - tagW / 2, top + 9);
            ctx.textAlign = 'left';

            rowY += row.h;
        });

        ctx.restore();

        ctx.strokeStyle = '#dde4ec';
        ctx.lineWidth = 1;
        roundRect(ctx, x + 0.5, y + 0.5, cardW - 1, card.total - 1, 12);
        ctx.stroke();
    }

    function drawPoster(ctx, logo, watermark) {
        var contentW = SHEET_W - PAD_X * 2;
        var x0 = PAD_X;
        var y = PAD_Y;

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, SHEET_W, SHEET_H);

        if (watermark) {
            var wmW = SHEET_W * 0.26;
            var wmH = wmW * (watermark.naturalHeight / watermark.naturalWidth || 1);
            ctx.save();
            ctx.globalAlpha = 0.035;
            ctx.drawImage(watermark, (SHEET_W - wmW) / 2, (SHEET_H - wmH) / 2, wmW, wmH);
            ctx.restore();
        }

        /* Header */
        ctx.textBaseline = 'alphabetic';
        var headBottom = y + 48;
        if (logo) {
            var lh = 46;
            var lw = lh * (logo.naturalWidth / logo.naturalHeight || 3);
            ctx.drawImage(logo, x0, y, lw, lh);
        }
        ctx.textAlign = 'right';
        ctx.fillStyle = INK;
        font(ctx, '700', 12);
        ctx.fillText(P.companyTh, x0 + contentW, y + 16);
        ctx.fillStyle = INK_SOFT;
        font(ctx, '600', 9);
        ctx.fillText(String(P.companyEn).toUpperCase(), x0 + contentW, y + 29);
        if (P.taxId) {
            ctx.fillStyle = INK_MUTED;
            font(ctx, '400', 9);
            ctx.fillText('Tax ID ' + P.taxId, x0 + contentW, y + 41);
        }

        ctx.fillStyle = INK;
        ctx.fillRect(x0, headBottom + 6, contentW, 1.5);
        ctx.fillStyle = GOLD;
        ctx.fillRect(x0, headBottom + 10, contentW, 1);
        y = headBottom + 26;

        /* Hero */
        ctx.textAlign = 'center';
        ctx.fillStyle = INK;
        font(ctx, '700', 20);
        ctx.fillText(P.title, SHEET_W / 2, y + 16);
        ctx.fillStyle = GOLD;
        roundRect(ctx, SHEET_W / 2 - 23, y + 24, 46, 2, 1);
        ctx.fill();
        ctx.fillStyle = INK_SOFT;
        font(ctx, '400', 10);
        ctx.fillText(P.subtitle, SHEET_W / 2, y + 41);
        ctx.fillStyle = INK_MUTED;
        font(ctx, '400', 9);
        ctx.fillText(P.meta, SHEET_W / 2, y + 55);
        y += 66;
        ctx.fillStyle = LINE;
        ctx.fillRect(x0, y, contentW, 1);
        y += 14;

        /* Stat chips */
        var chipGap = 8;
        var chipW = (contentW - chipGap * (P.stats.length - 1)) / P.stats.length;
        var chipH = 42;
        P.stats.forEach(function(stat, i) {
            var cx = x0 + i * (chipW + chipGap);
            ctx.fillStyle = stat.primary ? INK : '#ffffff';
            roundRect(ctx, cx, y, chipW, chipH, 12);
            ctx.fill();
            ctx.strokeStyle = stat.primary ? INK : LINE;
            ctx.lineWidth = 1;
            roundRect(ctx, cx + 0.5, y + 0.5, chipW - 1, chipH - 1, 12);
            ctx.stroke();

            ctx.textAlign = 'center';
            ctx.fillStyle = stat.primary ? '#ffffff' : INK;
            font(ctx, '700', 18);
            ctx.fillText(stat.num, cx + chipW / 2, y + 24);
            ctx.fillStyle = stat.primary ? 'rgba(255,255,255,0.72)' : INK_SOFT;
            font(ctx, '500', 8.5);
            ctx.fillText(stat.label, cx + chipW / 2, y + 36);
        });
        y += chipH + 12;

        /* Month columns — measured first so the block can be scaled to fill the page. */
        var cardW = (contentW - COL_GAP) / 2;
        var footerH = 26;
        var availH = SHEET_H - PAD_Y - footerH - y;

        /* Cards are laid out in design units then scaled to fill the sheet — a short
           year would otherwise leave the bottom third of the page blank. Re-measure
           at the chosen scale because a wider card box re-wraps the names. */
        function layout(scale) {
            var designW = cardW / scale;
            var cols = P.columns.map(function(months) {
                var cards = months.map(function(month) { return measureCard(ctx, month, designW); });
                var h = cards.reduce(function(sum, c) { return sum + c.total; }, 0) * scale
                    + Math.max(0, cards.length - 1) * 10;
                return { months: months, cards: cards, h: h };
            });
            return { scale: scale, cols: cols, tallest: Math.max(cols[0].h, cols[1].h) || 1 };
        }

        var plan = layout(1);
        var target = Math.max(0.62, Math.min(1.45, availH / plan.tallest));
        if (Math.abs(target - 1) > 0.02) {
            plan = layout(target);
            /* One correction pass: re-wrapping shifts the height slightly. */
            var corrected = Math.max(0.62, Math.min(1.45, plan.scale * (availH / plan.tallest)));
            if (Math.abs(corrected - plan.scale) > 0.02) plan = layout(corrected);
        }

        var slack = Math.max(0, availH - plan.tallest);

        ctx.save();
        plan.cols.forEach(function(col, ci) {
            var cx = x0 + ci * (cardW + COL_GAP);
            var gapCount = Math.max(1, col.months.length - 1);
            var gap = 10 + (col.months.length > 1 ? Math.min(22, slack / gapCount) : 0);
            var cy = y;
            col.months.forEach(function(month, mi) {
                ctx.save();
                ctx.translate(cx, cy);
                ctx.scale(plan.scale, plan.scale);
                drawCard(ctx, month, col.cards[mi], 0, 0, cardW / plan.scale);
                ctx.restore();
                cy += col.cards[mi].total * plan.scale + gap;
            });
        });
        ctx.restore();

        /* Footer */
        var fy = SHEET_H - PAD_Y - 12;
        ctx.fillStyle = LINE;
        ctx.fillRect(x0, fy - 10, contentW, 1);
        ctx.textAlign = 'left';
        ctx.fillStyle = INK_MUTED;
        font(ctx, '400', 8);
        ctx.fillText(P.footer, x0, fy + 2);
    }

    function loadImage(src) {
        return new Promise(function(resolve) {
            var img = new Image();
            img.onload = function() { resolve(img); };
            img.onerror = function() { resolve(null); };
            img.src = src;
        });
    }

    window.tpHolidayDownloadPng = function() {
        var btn = document.getElementById('btn-png');
        var note = document.getElementById('toolbar-note');
        var noteText = note && note.dataset.base ? note.dataset.base : (note ? note.textContent : '');
        if (note) note.dataset.base = noteText;
        if (btn) btn.disabled = true;
        if (note) note.textContent = 'กำลังสร้างรูปภาพ…';

        function done(message) {
            if (btn) btn.disabled = false;
            if (note) note.textContent = message || noteText;
        }

        var fontsReady = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();

        Promise.all([fontsReady, loadImage(P.logo), loadImage(P.watermark)]).then(function(res) {
            var canvas = document.createElement('canvas');
            canvas.width = Math.round(SHEET_W * PNG_SCALE);
            canvas.height = Math.round(SHEET_H * PNG_SCALE);
            var ctx = canvas.getContext('2d');
            ctx.scale(PNG_SCALE, PNG_SCALE);
            drawPoster(ctx, res[1], res[2]);

            canvas.toBlob(function(blob) {
                if (!blob) { done('สร้างรูปภาพไม่สำเร็จ — ลองใช้ปุ่ม PDF แทน'); return; }
                var link = document.createElement('a');
                var objectUrl = URL.createObjectURL(blob);
                link.href = objectUrl;
                link.download = PNG_FILENAME;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                setTimeout(function() { URL.revokeObjectURL(objectUrl); }, 2000);
                done();
            }, 'image/png');
        }).catch(function() {
            done('สร้างรูปภาพไม่สำเร็จ — ลองใช้ปุ่ม PDF แทน');
        });
    };

    window.addEventListener('beforeprint', tpHolidayFitA4);
    window.addEventListener('afterprint', tpHolidayResetA4);
    window.addEventListener('load', function() {
        tpHolidayFitA4();
        var auto = <?php echo json_encode($autoAction); ?>;
        if (auto === 'png') {
            setTimeout(tpHolidayDownloadPng, 250);
        } else if (auto === 'pdf') {
            setTimeout(function() { window.print(); }, 250);
        }
    });
})();
</script>

</body>
</html>
