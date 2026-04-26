<?php
/**
 * GitHub Webhook for Auto-Deploy
 * Triggers git pull when code is pushed to GitHub
 *
 * Requires WEBHOOK_SECRET in .env / server env (same value as GitHub webhook "Secret").
 * Every request must send a valid X-Hub-Signature-256 (sha256 HMAC of raw body).
 */

require_once __DIR__ . '/bootstrap.php';

$payload = file_get_contents('php://input');
$signature = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');

$secret = trim((string)($_ENV['WEBHOOK_SECRET'] ?? (getenv('WEBHOOK_SECRET') !== false ? getenv('WEBHOOK_SECRET') : '')));
if ($secret === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    die('Webhook not configured: set WEBHOOK_SECRET');
}

if ($signature === '' || !str_starts_with($signature, 'sha256=')) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    die('Missing signature');
}

$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    die('Invalid signature');
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
