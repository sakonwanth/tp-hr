<?php
/**
 * Annual holidays — print / save as PDF (browser print dialog).
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

$companyName = 'TP Asset';
try {
    $settingsService = new SettingsService($pdo);
    $companyName = $settingsService->get('company_name', $companyName) ?: $companyName;
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
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตารางวันหยุดประจำปี <?php echo (int) $holidayYearTh; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Kanit', sans-serif;
            font-size: 14px;
            color: #1e293b;
            margin: 0;
            padding: 24px;
            background: #f8fafc;
        }
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }
        .btn-print {
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            padding: 12px 20px;
            border: none;
            border-radius: 12px;
            background: #7c3aed;
            color: #fff;
            cursor: pointer;
        }
        .btn-back {
            font-family: inherit;
            font-size: 14px;
            padding: 12px 20px;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        .sheet {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.08);
        }
        h1 {
            font-size: 22px;
            margin: 0 0 4px;
            color: #0f172a;
        }
        .meta {
            color: #64748b;
            font-size: 13px;
            margin-bottom: 24px;
        }
        .summary {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .summary-box {
            flex: 1;
            min-width: 120px;
            padding: 12px 16px;
            border-radius: 12px;
            background: #f1f5f9;
            text-align: center;
        }
        .summary-box strong {
            display: block;
            font-size: 24px;
            color: #7c3aed;
        }
        .summary-box span {
            font-size: 12px;
            color: #64748b;
        }
        h2 {
            font-size: 15px;
            color: #475569;
            margin: 24px 0 8px;
            padding-bottom: 6px;
            border-bottom: 2px solid #e2e8f0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        th, td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        th {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            font-weight: 600;
        }
        td { vertical-align: top; }
        .type {
            font-size: 12px;
            color: #7c3aed;
            white-space: nowrap;
        }
        .footer-note {
            margin-top: 32px;
            padding-top: 16px;
            border-top: 1px solid #e2e8f0;
            font-size: 11px;
            color: #94a3b8;
        }
        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none !important; }
            .sheet { box-shadow: none; border-radius: 0; padding: 0; max-width: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" class="btn-print" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
        <a href="holidays.php?year=<?php echo (int) $holidayYear; ?>" class="btn-back">← กลับหน้าวันหยุด</a>
    </div>

    <div class="sheet">
        <h1>ตารางวันหยุดประจำปี <?php echo (int) $holidayYearTh; ?></h1>
        <p class="meta">
            <?php echo htmlspecialchars($companyName); ?>
            · ค.ศ. <?php echo (int) $holidayYear; ?>
            · พิมพ์เมื่อ <?php echo htmlspecialchars($printedAt); ?>
        </p>

        <div class="summary">
            <div class="summary-box">
                <strong><?php echo count($holidays); ?></strong>
                <span>วันหยุดทั้งปี</span>
            </div>
        </div>

        <?php if ($holidays): ?>
            <?php for ($m = 1; $m <= 12; $m++):
                if (empty($holidaysByMonth[$m])) {
                    continue;
                }
            ?>
            <h2><?php echo thaiMonth($m); ?> <?php echo (int) $holidayYearTh; ?></h2>
            <table>
                <thead>
                    <tr>
                        <th style="width:28%">วันที่</th>
                        <th>ชื่อวันหยุด</th>
                        <th style="width:22%">ประเภท</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($holidaysByMonth[$m] as $holiday):
                        $dow = (int) date('w', strtotime($holiday['date']));
                    ?>
                    <tr>
                        <td>
                            <?php echo formatDateThai($holiday['date']); ?>
                            <br><span style="font-size:12px;color:#94a3b8">วัน<?php echo htmlspecialchars($dayNames[$dow] ?? ''); ?></span>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($holiday['name']); ?>
                            <?php if (!empty($holiday['name_en'])): ?>
                            <br><span style="font-size:12px;color:#94a3b8"><?php echo htmlspecialchars($holiday['name_en']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="type"><?php echo htmlspecialchars($holidayTypeLabel((string) $holiday['type'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endfor; ?>
        <?php else: ?>
            <p style="color:#64748b">ยังไม่มีข้อมูลวันหยุดสำหรับปีนี้</p>
        <?php endif; ?>

        <p class="footer-note">
            เอกสารจากระบบ TP-HR · วันหยุดประจำสัปดาห์แยกจากตารางนี้
        </p>
    </div>
</body>
</html>
