<?php
/**
 * Find records whose timestamps were written while the server clock was wrong.
 *
 *   php scripts/qa_clock_drift_audit.php
 *   php scripts/qa_clock_drift_audit.php --days=14
 *   php scripts/qa_clock_drift_audit.php --fixed-at="2026-08-09 17:00:00" --drift-hours=23.5
 *
 * READ ONLY — never modifies a row.
 *
 * Background: the server ran ~23.5 hours behind until 2026-08-09. Anything
 * written in that window carries a timestamp about a day early, which matters
 * most for attendance, where a check-in on the wrong day feeds payroll.
 *
 * WHY YOU HAVE TO SUPPLY --fixed-at
 *
 * Two earlier attempts at detecting this automatically both failed, and the
 * reasons are worth keeping.
 *
 * Looking for created_at moving *backwards* as id increases finds the moment
 * the clock was set back — but only if a row exists on each side of it. When
 * the preceding row is older than the already-drifted timestamp, nothing looks
 * out of order and the audit reports "clean" with bad rows sitting right
 * there. That is what happened here.
 *
 * Anchoring on the opposite edge — the forward jump when the clock is
 * corrected — fails differently and more quietly: a quiet weekend produces a
 * gap of exactly the same shape and size. Tested against data with a real
 * 23.5h correction, the largest forward jump was a 48h lull two days earlier.
 * The heuristic picked the lull, confidently, and would have pointed at the
 * wrong rows.
 *
 * created_at alone cannot tell a clock change from an absence of activity, so
 * this asks for the correction time instead of guessing. Auto-detection is
 * still offered, clearly labelled as candidates, to help you find it.
 *
 * Known values for the 2026-08-09 incident:
 *   --fixed-at="2026-08-09 16:51:00" --drift-hours=23.5
 *
 * CLI only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap.php';

/** Minimum forward gap worth offering as a candidate — see the header. */
const FORWARD_JUMP_SECONDS = 12 * 3600;

/** Ignore jitter when looking for backwards movement. */
const BACKWARD_TOLERANCE_SECONDS = 3600;

$options = getopt('', ['days::', 'fixed-at::', 'drift-hours::', 'table::']);
$days = isset($options['days']) ? max(1, (int)$options['days']) : 7;
$fixedAt = isset($options['fixed-at']) ? strtotime((string)$options['fixed-at']) : null;
$driftHours = isset($options['drift-hours']) ? (float)$options['drift-hours'] : null;

$tables = [
    'hr_attendances'    => ['date_column' => 'attendance_date', 'extra' => 'check_in_time, check_out_time'],
    'hr_leave_requests' => ['date_column' => 'start_date',      'extra' => 'status, reason'],
];

if (isset($options['table'])) {
    $only = (string)$options['table'];
    if (!isset($tables[$only])) {
        fwrite(STDERR, "Unknown table: $only\nKnown: " . implode(', ', array_keys($tables)) . "\n");
        exit(1);
    }
    $tables = [$only => $tables[$only]];
}

$pdo = Database::getInstance()->getConnection();

echo "TP-HR — clock drift audit (read only)\n";
echo 'server time now: ' . date('Y-m-d H:i:s') . "\n";
printf("lookback window: %d day(s) before the clock was corrected\n", $days);
echo str_repeat('=', 72) . "\n";

$grandTotal = 0;

foreach ($tables as $table => $meta) {
    echo "\n$table\n" . str_repeat('-', 72) . "\n";

    try {
        $stmt = $pdo->query("SELECT id, created_at, {$meta['date_column']} AS d, {$meta['extra']}
                             FROM $table
                             WHERE created_at IS NOT NULL
                             ORDER BY id ASC");
    } catch (Throwable $e) {
        echo '  cannot read: ' . $e->getMessage() . "\n";
        continue;
    }

    $rows = [];
    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        $ts = strtotime((string)$row['created_at']);
        if ($ts === false) continue;
        $row['_ts'] = $ts;
        $rows[] = $row;
    }

    printf("  rows scanned: %d\n", count($rows));

    if ($rows === []) {
        continue;
    }

    // 1. Where was the clock corrected? Only the operator can say for sure —
    //    a forward jump and a quiet weekend are indistinguishable here.
    $correctionTs = $fixedAt;
    $candidates = [];
    for ($i = 1; $i < count($rows); $i++) {
        $gap = $rows[$i]['_ts'] - $rows[$i - 1]['_ts'];
        if ($gap >= FORWARD_JUMP_SECONDS) {
            $candidates[] = ['id' => (int)$rows[$i]['id'], 'ts' => $rows[$i]['_ts'], 'gap' => $gap];
        }
    }
    usort($candidates, fn($a, $b) => $b['gap'] <=> $a['gap']);

    // 2. Backwards movement — the other edge, when it happens to be visible.
    $highWater = null;
    $backwards = [];
    foreach ($rows as $row) {
        if ($highWater === null || $row['_ts'] >= $highWater) {
            $highWater = $row['_ts'];
            continue;
        }
        if (($highWater - $row['_ts']) >= BACKWARD_TOLERANCE_SECONDS) {
            $backwards[] = $row;
        }
    }

    if ($backwards !== []) {
        printf("  backwards time jumps: %d row(s) — the clock was set back mid-stream\n", count($backwards));
    }

    if ($correctionTs === null) {
        if ($candidates === []) {
            echo "  no forward time jumps at all — no sign of a correction here.\n";
            continue;
        }

        echo "\n  CANDIDATE jump points — these are GUESSES, not findings.\n";
        echo "  A weekend with no activity looks exactly like a clock correction:\n\n";
        foreach (array_slice($candidates, 0, 5) as $c) {
            printf("    id=%-8d %s   forward jump %.1fh\n", $c['id'], date('Y-m-d H:i:s', $c['ts']), $c['gap'] / 3600);
        }
        echo "\n  Pick the real one and re-run, e.g.:\n";
        printf("    php scripts/qa_clock_drift_audit.php --fixed-at=\"%s\" --drift-hours=23.5\n",
            date('Y-m-d H:i:s', $candidates[0]['ts']));
        continue;
    }

    printf("  clock correction: %s (supplied)\n", date('Y-m-d H:i:s', $correctionTs));

    $drift = $driftHours;
    $windowStart = $correctionTs - ($days * 86400);

    $suspect = array_values(array_filter(
        $rows,
        fn($r) => $r['_ts'] < $correctionTs && $r['_ts'] >= $windowStart
    ));

    if ($suspect === []) {
        echo "  nothing was written in the window before the correction.\n";
        continue;
    }

    $grandTotal += count($suspect);
    printf("\n  SUSPECT: %d row(s) written before the correction\n", count($suspect));
    if ($drift !== null) {
        printf("  (stored timestamps are likely ~%.1fh early)\n", $drift);
    }
    echo "\n";

    foreach (array_slice($suspect, 0, 50) as $r) {
        printf(
            "    id=%-8d created=%s  %s=%s\n",
            $r['id'],
            $r['created_at'],
            $meta['date_column'],
            $r['d']
        );
        if ($drift !== null) {
            printf("             likely actually %s\n", date('Y-m-d H:i:s', $r['_ts'] + (int)round($drift * 3600)));
        }
        if ($table === 'hr_attendances') {
            printf(
                "             check_in=%s  check_out=%s\n",
                $r['check_in_time'] ?: '-',
                $r['check_out_time'] ?: '-'
            );
        } elseif ($table === 'hr_leave_requests') {
            printf("             %s · %s\n", $r['status'], mb_substr(trim((string)$r['reason']), 0, 50) ?: '-');
        }
    }

    if (count($suspect) > 50) {
        printf("    ... and %d more (showing the first 50)\n", count($suspect) - 50);
    }
}

echo "\n" . str_repeat('=', 72) . "\n";

if ($grandTotal === 0) {
    echo "Nothing landed in the suspect window.\n";
    echo "Widen it with --days=N if the drift started earlier than assumed.\n";
    exit(0);
}

printf("%d row(s) need a human to look at them.\n\n", $grandTotal);
echo "Nothing has been changed. Compare each row against what actually happened\n";
echo "that day: a check-in stamped a day early shifts that person's attendance\n";
echo "and can follow through into payroll. Correcting real employee records is\n";
echo "a decision, not something a script should make.\n";

exit(1);
