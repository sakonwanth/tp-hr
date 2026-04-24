<?php
/**
 * One-shot cleanup: strip "(HH:MM-HH:MM)" suffix from hr_work_shifts.name
 *
 * ปัญหาเดิม: name ถูกเก็บแบบ "กะปกติ (08:30-17:30)" hardcoded
 *            ทำให้เวลา admin แก้ start_time/end_time แล้ว label ไม่ sync
 *
 * หลัง migration นี้:
 *  - name = "กะปกติ", "กะเช้า", ฯลฯ (base name เท่านั้น)
 *  - display layer ใช้ shift_display_label() เพื่อประกอบ "(start-end)" สด ๆ
 *
 * Usage:
 *   php scripts/cleanup_shift_names.php           # dry-run
 *   php scripts/cleanup_shift_names.php --apply   # apply changes
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/Helpers.php';

$apply = in_array('--apply', $argv ?? [], true);
$pdo = getDB();

echo "=== Cleanup hr_work_shifts.name ===\n";
echo $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (use --apply to commit)\n\n";

$rows = $pdo->query("SELECT id, code, name, start_time, end_time FROM hr_work_shifts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$changed = 0;

foreach ($rows as $r) {
    $clean = shift_base_name($r['name']);
    if ($clean === $r['name']) {
        printf("  id=%d %-10s name=%s (no change)\n", $r['id'], $r['code'], $r['name']);
        continue;
    }
    printf("  id=%d %-10s '%s' → '%s'\n", $r['id'], $r['code'], $r['name'], $clean);
    if ($apply) {
        $u = $pdo->prepare("UPDATE hr_work_shifts SET name = ? WHERE id = ?");
        $u->execute([$clean, $r['id']]);
    }
    $changed++;
}

echo "\nDone. Rows that need change: {$changed}";
echo $apply ? " (applied)\n" : " (not applied; add --apply)\n";
