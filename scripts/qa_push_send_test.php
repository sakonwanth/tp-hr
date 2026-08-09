<?php
/**
 * Send a real test notification and report exactly why it did or did not
 * arrive.
 *
 *   php scripts/qa_push_send_test.php            # every stored subscription
 *   php scripts/qa_push_send_test.php 42         # only user_id 42
 *
 * PushService deliberately swallows delivery errors so a push problem can
 * never fail the HR action that triggered it. That is right for production
 * and useless for diagnosis, so this talks to the library directly and prints
 * the push service's actual response.
 *
 * Read-only apart from sending the notification. CLI only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap.php';

$pdo = Database::getInstance()->getConnection();
$userId = isset($argv[1]) ? (int)$argv[1] : 0;

echo "TP-HR — push send test\n";
echo str_repeat('-', 60) . "\n";

// 1. What is stored?
try {
    $total = (int)$pdo->query('SELECT COUNT(*) FROM hr_push_subscriptions')->fetchColumn();
} catch (Throwable $e) {
    exit("Cannot read hr_push_subscriptions: " . $e->getMessage() . "\n");
}

echo "subscriptions stored (all users): $total\n";

if ($total === 0) {
    echo "\nNothing stored — the browser never registered.\n";
    echo "That points at the subscribe call, not at delivery:\n";
    echo "  - notification permission was not actually granted, or\n";
    echo "  - POST /api/push.php failed (CSRF token, session, or 503)\n";
    exit(1);
}

$sql = 'SELECT id, user_id, endpoint, p256dh, auth_secret, failure_count, last_used_at, last_failed_at
        FROM hr_push_subscriptions';
$params = [];
if ($userId > 0) {
    $sql .= ' WHERE user_id = :user_id';
    $params['user_id'] = $userId;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($rows === []) {
    exit("No subscription for user_id $userId (but $total exist for other users).\n");
}

foreach ($rows as $row) {
    printf(
        "  id=%d user_id=%d host=%s failures=%d last_ok=%s last_fail=%s\n",
        $row['id'],
        $row['user_id'],
        parse_url($row['endpoint'], PHP_URL_HOST) ?: '?',
        $row['failure_count'],
        $row['last_used_at'] ?: 'never',
        $row['last_failed_at'] ?: 'never'
    );
}

// 2. Can this server even reach the push service?
echo "\nOutbound connectivity:\n";
foreach (array_unique(array_map(fn($r) => parse_url($r['endpoint'], PHP_URL_HOST), $rows)) as $host) {
    $start = microtime(true);
    $socket = @fsockopen('ssl://' . $host, 443, $errno, $errstr, 8);
    $ms = (int)round((microtime(true) - $start) * 1000);
    if ($socket) {
        fclose($socket);
        echo "  $host:443 reachable ({$ms}ms)\n";
    } else {
        echo "  $host:443 UNREACHABLE — $errstr (errno $errno)\n";
        echo "    An outbound firewall here would explain silent delivery failure.\n";
    }
}

// 3. Send for real, with full reporting.
if (!class_exists(\Minishlink\WebPush\WebPush::class)) {
    exit("\nminishlink/web-push not installed.\n");
}

$publicKey = trim((string)($_ENV['VAPID_PUBLIC_KEY'] ?? getenv('VAPID_PUBLIC_KEY') ?: ''));
$privateKey = trim((string)($_ENV['VAPID_PRIVATE_KEY'] ?? getenv('VAPID_PRIVATE_KEY') ?: ''));
$subject = trim((string)($_ENV['VAPID_SUBJECT'] ?? getenv('VAPID_SUBJECT') ?: ''));

if ($publicKey === '' || $privateKey === '') {
    exit("\nVAPID keys missing.\n");
}

$webPush = new \Minishlink\WebPush\WebPush([
    'VAPID' => ['subject' => $subject ?: 'https://hr.tp-asset.com', 'publicKey' => $publicKey, 'privateKey' => $privateKey],
]);
$webPush->setDefaultOptions(['TTL' => 300, 'urgency' => 'high']);

$payload = json_encode([
    'title' => 'ทดสอบระบบแจ้งเตือน',
    'body'  => 'ถ้าเห็นข้อความนี้ แปลว่า Web Push ทำงานแล้ว',
    'url'   => '/',
    'tag'   => 'tp-hr-test',
], JSON_UNESCAPED_UNICODE);

foreach ($rows as $row) {
    $webPush->queueNotification(
        \Minishlink\WebPush\Subscription::create([
            'endpoint'        => $row['endpoint'],
            'publicKey'       => $row['p256dh'],
            'authToken'       => $row['auth_secret'],
            'contentEncoding' => 'aes128gcm',
        ]),
        $payload
    );
}

echo "\nSending:\n";
$ok = 0;
$failed = 0;

foreach ($webPush->flush() as $report) {
    $endpoint = $report->getRequest()->getUri()->__toString();
    $host = parse_url($endpoint, PHP_URL_HOST) ?: '?';

    if ($report->isSuccess()) {
        $ok++;
        echo "  [SENT] $host — accepted by the push service\n";
        continue;
    }

    $failed++;
    echo "  [FAIL] $host\n";
    echo '         reason: ' . trim($report->getReason()) . "\n";
    echo '         subscription expired (404/410): ' . ($report->isSubscriptionExpired() ? 'yes' : 'no') . "\n";

    $response = $report->getResponse();
    if ($response !== null) {
        echo '         http status: ' . $response->getStatusCode() . "\n";
        $body = trim((string)$response->getBody());
        if ($body !== '') {
            echo '         body: ' . mb_substr($body, 0, 300) . "\n";
        }
    }
}

echo str_repeat('-', 60) . "\n";
echo "accepted: $ok   failed: $failed\n";

if ($ok > 0) {
    echo "\nThe push service accepted it. If nothing appeared on the phone:\n";
    echo "  - the app must have been installed to the Home Screen (iOS 16.4+)\n";
    echo "  - check Settings > TP-HR > Notifications is still allowed\n";
}

exit($failed === 0 ? 0 : 1);
