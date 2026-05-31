#!/usr/bin/env php
<?php
/**
 * Idempotent seed for company annual holidays when a year has none.
 *
 * Usage:
 *   php scripts/ensure_company_holidays.php
 *   php scripts/ensure_company_holidays.php --year=2026
 */

if (PHP_SAPI !== 'cli') {
    die('Run from CLI only');
}

require_once dirname(__DIR__) . '/bootstrap.php';

$year = (int) date('Y');
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--year=')) {
        $year = (int) substr($arg, 7);
    }
}
$year = max(2000, min(2100, $year));

/** @var array<int, array{date:string,name:string,name_en:string}> */
$canonicalByYear = [
    2026 => [
        ['date' => '2026-01-01', 'name' => 'วันขึ้นปีใหม่', 'name_en' => "New Year's Day"],
        ['date' => '2026-03-03', 'name' => 'วันมาฆบูชา', 'name_en' => 'Makha Bucha Day'],
        ['date' => '2026-04-06', 'name' => 'วันจักรี', 'name_en' => 'Chakri Memorial Day'],
        ['date' => '2026-04-13', 'name' => 'วันสงกรานต์', 'name_en' => 'Songkran Day 1'],
        ['date' => '2026-04-14', 'name' => 'วันสงกรานต์', 'name_en' => 'Songkran Day 2'],
        ['date' => '2026-04-15', 'name' => 'วันสงกรานต์', 'name_en' => 'Songkran Day 3'],
        ['date' => '2026-05-01', 'name' => 'วันแรงงานแห่งชาติ', 'name_en' => 'National Labour Day'],
        ['date' => '2026-05-04', 'name' => 'วันฉัตรมงคล', 'name_en' => 'Coronation Day'],
        ['date' => '2026-06-03', 'name' => 'วันเฉลิมพระชนมพรรษาสมเด็จพระนางเจ้าฯ', 'name_en' => "Queen Suthida's Birthday"],
        ['date' => '2026-07-28', 'name' => 'วันเฉลิมพระชนมพรรษา ร.10', 'name_en' => "King Vajiralongkorn's Birthday"],
        ['date' => '2026-08-12', 'name' => 'วันเฉลิมพระชนมพรรษา สมเด็จพระบรมราชชนนี', 'name_en' => "Queen Sirikit's Birthday"],
        ['date' => '2026-10-13', 'name' => 'วันคล้ายวันสวรรคต ร.9', 'name_en' => 'King Bhumibol Memorial Day'],
        ['date' => '2026-12-10', 'name' => 'วันรัฐธรรมนูญ', 'name_en' => 'Constitution Day'],
    ],
];

if (!isset($canonicalByYear[$year])) {
    echo "No canonical holiday list for year {$year}. Skip.\n";
    exit(0);
}

try {
    $pdo = getDB();
} catch (Throwable $e) {
    echo 'DB connection failed: ' . $e->getMessage() . "\n";
    exit(1);
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM hr_holidays WHERE YEAR(`date`) = ? AND is_active = 1');
$countStmt->execute([$year]);
$existing = (int) $countStmt->fetchColumn();

if ($existing > 0) {
    echo "Year {$year}: {$existing} active holiday(s) already present. Skip seed.\n";
    exit(0);
}

$insert = $pdo->prepare("
    INSERT INTO hr_holidays (`date`, name, name_en, type, description, is_active)
    VALUES (?, ?, ?, 'PUBLIC', ?, 1)
    ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        name_en = VALUES(name_en),
        type = VALUES(type),
        description = VALUES(description),
        is_active = 1
");

$desc = "Company annual holiday {$year}";
$inserted = 0;
foreach ($canonicalByYear[$year] as $row) {
    $insert->execute([$row['date'], $row['name'], $row['name_en'], $desc]);
    $inserted++;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS _migrations_run (
    filename VARCHAR(255) PRIMARY KEY,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->prepare('INSERT IGNORE INTO _migrations_run (filename) VALUES (?)')
    ->execute(['2026_05_04_company_holidays_2026_minimum_13.sql']);

echo "Year {$year}: seeded {$inserted} company holiday(s).\n";
exit(0);
