#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
require_once dirname(__DIR__) . '/bootstrap.php';

$apply = in_array('--apply', $argv, true);
$pdo = getDB();
$rows = $pdo->query("SELECT id,due_date FROM hr_loan_repayments WHERE status='scheduled' ORDER BY id FOR SHARE")->fetchAll(PDO::FETCH_ASSOC);
$changes = [];
foreach ($rows as $row) {
    $date = new DateTimeImmutable((string)$row['due_date']);
    $expected = $date->modify('last day of this month');
    if ($expected->format('w') === '0') $expected = $expected->modify('-1 day');
    if ($expected->format('Y-m-d') !== (string)$row['due_date']) {
        $changes[] = ['id'=>(int)$row['id'],'from'=>(string)$row['due_date'],'to'=>$expected->format('Y-m-d')];
    }
}
echo ($apply ? 'APPLY' : 'PREVIEW') . ' changes=' . count($changes) . PHP_EOL;
foreach ($changes as $change) echo json_encode($change, JSON_UNESCAPED_UNICODE) . PHP_EOL;
if (!$apply || !$changes) exit(0);

$pdo->beginTransaction();
try {
    $update = $pdo->prepare("UPDATE hr_loan_repayments SET due_date=? WHERE id=? AND due_date=? AND status='scheduled'");
    foreach ($changes as $change) {
        $update->execute([$change['to'],$change['id'],$change['from']]);
        if ($update->rowCount() !== 1) throw new RuntimeException('Concurrent due-date change detected for repayment '.$change['id']);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
echo 'OK updated=' . count($changes) . PHP_EOL;
