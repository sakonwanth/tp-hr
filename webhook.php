<?php
/**
 * GitHub Webhook for Auto-Deploy
 * Triggers git pull when code is pushed to GitHub
 */

// Webhook secret (change this to a secure random string)
$secret = getenv('WEBHOOK_SECRET') ?: 'tp-hr-deploy-2024';

// Verify GitHub signature
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if ($signature) {
    $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    if (!hash_equals($expected, $signature)) {
        http_response_code(403);
        die('Invalid signature');
    }
}

// Only process push events
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event !== 'push') {
    echo 'Ignored event: ' . $event;
    exit;
}

// Parse payload
$data = json_decode($payload, true);
$branch = $data['ref'] ?? '';

// Only deploy main branch
if ($branch !== 'refs/heads/main') {
    echo 'Ignored branch: ' . $branch;
    exit;
}

// Log deployment
$logFile = __DIR__ . '/storage/logs/deploy.log';
$timestamp = date('Y-m-d H:i:s');

// Execute git pull
$repoDir = __DIR__;
$output = [];
$returnCode = 0;

// Use shell script for deployment
chdir($repoDir);
exec('git pull origin main 2>&1', $output, $returnCode);

// Log result
$logEntry = sprintf(
    "[%s] Deploy triggered by %s - Branch: %s - Status: %s\n%s\n---\n",
    $timestamp,
    $data['pusher']['name'] ?? 'unknown',
    $branch,
    $returnCode === 0 ? 'SUCCESS' : 'FAILED',
    implode("\n", $output)
);

file_put_contents($logFile, $logEntry, FILE_APPEND);

// Fix permissions after pull
exec('chown -R tpasset:psacln . 2>/dev/null');

// Response
http_response_code($returnCode === 0 ? 200 : 500);
echo json_encode([
    'status' => $returnCode === 0 ? 'success' : 'failed',
    'output' => $output,
    'timestamp' => $timestamp
]);
