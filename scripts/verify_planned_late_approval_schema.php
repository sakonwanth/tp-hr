<?php

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once dirname(__DIR__) . '/bootstrap.php';

$pdo = Database::getInstance()->getConnection();
$required = ['planned_status','planned_reviewed_by','planned_reviewed_at','planned_review_note'];
$stmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='hr_attendances'");
$columns = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
$missing = array_values(array_filter($required, static fn(string $name): bool => !isset($columns[$name])));
if ($missing) { fwrite(STDERR, 'Missing planned-late approval columns: '.implode(', ', $missing)."\n"); exit(1); }

$idx = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='hr_attendances' AND INDEX_NAME='idx_hr_att_planned_approval'")->fetchColumn();
if ((int)$idx < 1) { fwrite(STDERR, "Missing idx_hr_att_planned_approval\n"); exit(1); }

$invalid = (int)$pdo->query("SELECT COUNT(*) FROM hr_attendances WHERE planned_start_time IS NOT NULL AND planned_status IS NULL")->fetchColumn();
if ($invalid !== 0) { fwrite(STDERR, "Rows with planned time but no approval status: {$invalid}\n"); exit(1); }

echo "Planned-late approval schema verified; invalid_rows=0\n";
