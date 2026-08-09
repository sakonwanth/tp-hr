<?php
/**
 * Report whether Web Push is wired up on this environment.
 *
 *   php scripts/qa_push_status.php
 *
 * Read-only. Never prints the private key — only whether it is present and
 * the right length, so the output is safe to paste into a chat or a ticket.
 *
 * CLI only; scripts/ is denied over HTTP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap.php';

function tp_hr_b64u_len(string $value): int
{
    $padded = strtr($value, '-_', '+/');
    $padded .= str_repeat('=', (4 - (strlen($padded) % 4)) % 4);
    $raw = base64_decode($padded, true);

    return $raw === false ? -1 : strlen($raw);
}

$rows = [];

// 1. PHP + library
$rows[] = ['PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.2', '>=')];
$rows[] = [
    'minishlink/web-push',
    class_exists(\Minishlink\WebPush\WebPush::class) ? 'installed' : 'MISSING (composer install)',
    class_exists(\Minishlink\WebPush\WebPush::class),
];
$rows[] = [
    'PSR-18 HTTP client',
    class_exists(\GuzzleHttp\Client::class) ? 'installed' : 'MISSING — sends will fail',
    class_exists(\GuzzleHttp\Client::class),
];

// 2. VAPID keys — presence and shape only, never the values
$public = trim((string)($_ENV['VAPID_PUBLIC_KEY'] ?? getenv('VAPID_PUBLIC_KEY') ?: ''));
$private = trim((string)($_ENV['VAPID_PRIVATE_KEY'] ?? getenv('VAPID_PRIVATE_KEY') ?: ''));
$subject = trim((string)($_ENV['VAPID_SUBJECT'] ?? getenv('VAPID_SUBJECT') ?: ''));

$publicLen = $public === '' ? -1 : tp_hr_b64u_len($public);
$privateLen = $private === '' ? -1 : tp_hr_b64u_len($private);

$rows[] = [
    'VAPID_PUBLIC_KEY',
    $public === '' ? 'NOT SET' : sprintf('set, %d bytes (want 65)', $publicLen),
    $publicLen === 65,
];
$rows[] = [
    'VAPID_PRIVATE_KEY',
    $private === '' ? 'NOT SET' : sprintf('set, %d bytes (want 32)', $privateLen),
    $privateLen === 32,
];
$rows[] = ['VAPID_SUBJECT', $subject !== '' ? $subject : 'not set (falls back to APP_URL)', true];

// 3. Database
$tableExists = false;
$subscriptionCount = 0;
try {
    $pdo = Database::getInstance()->getConnection();
    $pdo->query('SELECT 1 FROM hr_push_subscriptions LIMIT 1');
    $tableExists = true;
    $subscriptionCount = (int)$pdo->query('SELECT COUNT(*) FROM hr_push_subscriptions')->fetchColumn();
} catch (Throwable $e) {
    // reported below
}

$rows[] = [
    'hr_push_subscriptions',
    $tableExists ? 'exists' : 'MISSING — run scripts/run_migration.php',
    $tableExists,
];
$rows[] = ['subscriptions stored', (string)$subscriptionCount, true];

// 4. The verdict the app itself uses
$configured = false;
try {
    $configured = (new PushService(Database::getInstance()->getConnection()))->isConfigured();
} catch (Throwable $e) {
    // leave false
}

echo "TP-HR — Web Push status\n";
echo str_repeat('-', 58) . "\n";
foreach ($rows as [$label, $value, $ok]) {
    printf("  %-22s %-28s %s\n", $label, $value, $ok ? 'OK' : 'FAIL');
}
echo str_repeat('-', 58) . "\n";
echo 'PushService::isConfigured() = ' . ($configured ? 'TRUE — push is live' : 'FALSE — push stays hidden') . "\n";

exit($configured ? 0 : 1);
