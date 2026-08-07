<?php
/**
 * Generate the VAPID keypair for Web Push. Run once, then paste the output
 * into .env on the server:
 *
 *   php scripts/generate_vapid_keys.php
 *
 * The private key identifies TP-HR to Apple's and Google's push services.
 * Treat it like any other secret: never commit it, and note that regenerating
 * it invalidates every existing subscription (all employees would have to
 * re-enable notifications).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found — run composer install first.\n");
    exit(1);
}
require_once $autoload;

if (!class_exists(\Minishlink\WebPush\VAPID::class)) {
    fwrite(STDERR, "minishlink/web-push not installed — run composer install.\n");
    exit(1);
}

$keys = \Minishlink\WebPush\VAPID::createVapidKeys();

echo "Add these to .env (and keep the private key out of git):\n\n";
echo 'VAPID_PUBLIC_KEY=' . $keys['publicKey'] . "\n";
echo 'VAPID_PRIVATE_KEY=' . $keys['privateKey'] . "\n";
echo "VAPID_SUBJECT=mailto:it@tp-asset.com\n";
