<?php
/**
 * GitHub Webhook — deploy (hardened)
 *
 * - Verifies X-Hub-Signature-256 (HMAC-SHA256 of raw body) with WEBHOOK_SECRET.
 * - Requires X-GitHub-Delivery (replay guard via storage/cache).
 * - Optional WEBHOOK_GITHUB_REPO=owner/repo must match payload repository.full_name.
 * - Runs git pull only for push to refs/heads/main.
 * - Does NOT load full bootstrap.php (no session / no DB) to shrink attack surface.
 *
 * Env (see docs/AUDIT_PRE_PHASE_G1_2026-05-03.md):
 *   WEBHOOK_SECRET           — required (GitHub webhook secret)
 *   WEBHOOK_GITHUB_REPO      — optional allowlist, e.g. owner/tp-hr
 *   WEBHOOK_POST_PULL_CHOWN  — set to 1/true to run chown after pull (default: off)
 *   WEBHOOK_VERBOSE_JSON     — set to 1 to include git output lines in HTTP JSON (default: off)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

$baseDir = dirname(__FILE__);
$respond = static function (int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};

$autoload = $baseDir . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

if (class_exists('TpCommon\\Env\\Env')) {
    \TpCommon\Env\Env::load($baseDir . '/.env');
}

$secret = trim((string)($_ENV['WEBHOOK_SECRET'] ?? (getenv('WEBHOOK_SECRET') !== false ? getenv('WEBHOOK_SECRET') : '')));
if ($secret === '') {
    $respond(503, ['status' => 'error', 'message' => 'Webhook not configured']);
}

$payload = file_get_contents('php://input');
if ($payload === false) {
    $payload = '';
}

$signature = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
if ($signature === '' || !str_starts_with($signature, 'sha256=')) {
    $respond(401, ['status' => 'error', 'message' => 'Missing signature']);
}

$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expected, $signature)) {
    $respond(403, ['status' => 'error', 'message' => 'Invalid signature']);
}

$deliveryId = trim((string)($_SERVER['HTTP_X_GITHUB_DELIVERY'] ?? ''));
if ($deliveryId === '' || strlen($deliveryId) > 128 || !preg_match('/^[a-f0-9\\-]+$/i', $deliveryId)) {
    $respond(400, ['status' => 'error', 'message' => 'Missing or invalid X-GitHub-Delivery']);
}

$event = (string)($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '');
if ($event !== 'push') {
    $respond(200, ['status' => 'ignored', 'reason' => 'event', 'event' => $event]);
}

$data = json_decode($payload, true);
if (!is_array($data)) {
    $respond(400, ['status' => 'error', 'message' => 'Invalid JSON payload']);
}

$branch = (string)($data['ref'] ?? '');
if ($branch !== 'refs/heads/main') {
    $respond(200, ['status' => 'ignored', 'reason' => 'branch', 'ref' => $branch]);
}

$expectedRepo = trim((string)($_ENV['WEBHOOK_GITHUB_REPO'] ?? (getenv('WEBHOOK_GITHUB_REPO') !== false ? getenv('WEBHOOK_GITHUB_REPO') : '')));
if ($expectedRepo !== '') {
    $full = (string)($data['repository']['full_name'] ?? '');
    if (strcasecmp($full, $expectedRepo) !== 0) {
        $respond(403, ['status' => 'error', 'message' => 'Repository mismatch']);
    }
}

// --- Replay guard: remember recent delivery IDs (48h window) ---
$cacheDir = $baseDir . '/storage/cache';
if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0750, true)) {
    $respond(500, ['status' => 'error', 'message' => 'Cannot create cache dir']);
}
$seenFile = $cacheDir . '/github_webhook_deliveries.json';
$now = time();
$window = 48 * 3600;
$seen = [];
if (is_file($seenFile)) {
    $raw = file_get_contents($seenFile);
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (string)($row['id'] ?? '');
                $ts = (int)($row['ts'] ?? 0);
                if ($id !== '' && ($now - $ts) < $window) {
                    if ($id === $deliveryId) {
                        $respond(200, ['status' => 'ignored', 'reason' => 'replay', 'delivery' => $deliveryId]);
                    }
                    $seen[] = ['id' => $id, 'ts' => $ts];
                }
            }
        }
    }
}
$seen[] = ['id' => $deliveryId, 'ts' => $now];
// cap list size
if (count($seen) > 500) {
    $seen = array_slice($seen, -400);
}
@file_put_contents($seenFile, json_encode($seen, JSON_UNESCAPED_UNICODE), LOCK_EX);

$verboseJson = filter_var(
    $_ENV['WEBHOOK_VERBOSE_JSON'] ?? getenv('WEBHOOK_VERBOSE_JSON') ?: '0',
    FILTER_VALIDATE_BOOLEAN
);

$logFile = $baseDir . '/storage/logs/deploy.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}

$timestamp = date('Y-m-d H:i:s');
$pusher = is_array($data['pusher'] ?? null) ? ($data['pusher']['name'] ?? 'unknown') : 'unknown';

$lockFile = $cacheDir . '/deploy.lock';
$lockFh = fopen($lockFile, 'c+');
if ($lockFh === false) {
    $respond(500, ['status' => 'error', 'message' => 'Cannot open deploy lock']);
}
if (!flock($lockFh, LOCK_EX | LOCK_NB)) {
    fclose($lockFh);
    $respond(409, ['status' => 'error', 'message' => 'Deploy already in progress']);
}

$output = [];
$returnCode = 0;
try {
    chdir($baseDir);
    exec('git pull origin main 2>&1', $output, $returnCode);
} finally {
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
}

$runChown = filter_var(
    $_ENV['WEBHOOK_POST_PULL_CHOWN'] ?? getenv('WEBHOOK_POST_PULL_CHOWN') ?: '0',
    FILTER_VALIDATE_BOOLEAN
);
if ($runChown && $returnCode === 0) {
    exec('chown -R tpasset:psacln ' . escapeshellarg($baseDir) . ' 2>/dev/null');
}

$logEntry = sprintf(
    "[%s] delivery=%s pusher=%s branch=%s status=%s\n%s\n---\n",
    $timestamp,
    $deliveryId,
    (string)$pusher,
    $branch,
    $returnCode === 0 ? 'SUCCESS' : 'FAILED',
    implode("\n", $output)
);
@file_put_contents($logFile, $logEntry, FILE_APPEND);

$json = [
    'status' => $returnCode === 0 ? 'success' : 'failed',
    'timestamp' => $timestamp,
    'delivery' => $deliveryId,
];
if ($verboseJson) {
    $json['output'] = $output;
}
$respond($returnCode === 0 ? 200 : 500, $json);
