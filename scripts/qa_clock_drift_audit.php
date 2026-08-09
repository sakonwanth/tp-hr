<?php
/**
 * Find records whose timestamps were written while the server clock was wrong.
 *
 *   php scripts/qa_clock_drift_audit.php
 *   php scripts/qa_clock_drift_audit.php hr_attendances
 *
 * READ ONLY — this never modifies a row.
 *
 * The server clock ran ~23.5 hours behind on 2026-08-09 (see the runbook).
 * Anything written during that window carries a timestamp about a day early,
 * which matters most for attendance: a check-in can land on the wrong day and
 * quietly distort payroll.
 *
 * Detection does not need to know when the drift started. Auto-increment ids
 * only ever go up, so created_at should too. Where created_at moves *backwards*
 * as id increases, the clock was set back at that moment — that row is where
 * the bad window opens, and it closes where created_at catches up again.
 *
 * CLI only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap.php';

/** Ignore jitter; only a real clock change moves time back this far. */
const BACKWARD_TOLERANCE_SECONDS = 3600;

$tables = [
    'hr_attendances'     => ['date_column' => 'attendance_date', 'extra' => 'check_in_time, check_out_time'],
    'hr_leave_requests'  => ['date_column' => 'start_date',      'extra' => 'status'],
];

$only = $argv[1] ?? '';
if ($only !== '') {
    if (!isset($tables[$only])) {
        fwrite(STDERR, "Unknown table: $only\nKnown: " . implode(', ', array_keys($tables)) . "\n");
        exit(1);
    }
    $tables = [$only => $tables[$only]];
}

$pdo = Database::getInstance()->getConnection();

echo "TP-HR — clock drift audit (read only)\n";
echo 'server time now: ' . date('Y-m-d H:i:s') . "\n";
echo str_repeat('=', 66) . "\n";

$grandTotal = 0;

foreach ($tables as $table => $meta) {
    echo "\n$table\n" . str_repeat('-', 66) . "\n";

    try {
        $stmt = $pdo->query("SELECT id, created_at, {$meta['date_column']} AS d, {$meta['extra']}
                             FROM $table
                             WHERE created_at IS NOT NULL
                             ORDER BY id ASC");
    } catch (Throwable $e) {
        echo "  cannot read: " . $e->getMessage() . "\n";
        continue;
    }

    $highWater = null;      // latest created_at seen so far
    $highWaterId = null;
    $suspect = [];          // rows written while time was behind the high-water mark
    $rowCount = 0;

    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        $rowCount++;
        $ts = strtotime((string)$row['created_at']);
        if ($ts === false) continue;

        if ($highWater === null || $ts >= $highWater) {
            $highWater = $ts;
            $highWaterId = (int)$row['id'];
            continue;
        }

        $behind = $highWater - $ts;
        if ($behind >= BACKWARD_TOLERANCE_SECONDS) {
            $suspect[] = [
                'id'      => (int)$row['id'],
                'created' => (string)$row['created_at'],
                'date'    => (string)$row['d'],
                'behind'  => $behind,
                'after'   => $highWaterId,
                'row'     => $row,
            ];
        }
    }

    echo "  rows scanned: $rowCount\n";

    if ($suspect === []) {
        echo "  no backwards time jumps — nothing here was written under a wrong clock\n";
        continue;
    }

    $grandTotal += count($suspect);
    printf("  SUSPECT ROWS: %d\n\n", count($suspect));

    $worst = 0;
    foreach ($suspect as $s) {
        $worst = max($worst, $s['behind']);
    }
    printf("  largest backwards jump: %.1f hours\n\n", $worst / 3600);

    foreach (array_slice($suspect, 0, 40) as $s) {
        printf(
            "    id=%-8d created=%s  %s=%s  (%.1fh behind id=%d)\n",
            $s['id'],
            $s['created'],
            $meta['date_column'],
            $s['date'],
            $s['behind'] / 3600,
            $s['after']
        );
        if ($table === 'hr_attendances') {
            printf(
                "             check_in=%s  check_out=%s\n",
                $s['row']['check_in_time'] ?: '-',
                $s['row']['check_out_time'] ?: '-'
            );
        }
    }

    if (count($suspect) > 40) {
        printf("    ... and %d more (showing the first 40)\n", count($suspect) - 40);
    }
}

echo "\n" . str_repeat('=', 66) . "\n";

if ($grandTotal === 0) {
    echo "Clean — no row was written while the clock was behind.\n";
    exit(0);
}

printf("%d row(s) need a human to look at them.\n\n", $grandTotal);
echo "What to do: compare each row's date against what actually happened that\n";
echo "day. A check-in stamped a day early shifts that person's attendance and\n";
echo "can follow through into payroll. Nothing here has been changed — fixing\n";
echo "dates is a decision about real employee records, not a script's call.\n";

exit(1);
