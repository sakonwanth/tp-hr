<?php
/**
 * PWA + Web Push contract checks.
 *
 * Runs in CI with no database and no vendor/ — it covers the pure guards and
 * the wiring that is easy to break silently:
 *
 *   - the same-origin clamp on notification click-through URLs
 *   - subscription key-material validation (a malformed key aborts the whole
 *     send batch inside the push library, silencing every other device)
 *   - push actually being called at every leave-decision site
 *   - the service worker not re-introducing the cache-buster bug
 *
 * Usage: php scripts/qa_pwa_push_contract.php
 */

$root = dirname(__DIR__);
require_once $root . '/core/Services/PushService.php';

$failures = [];
$checks = 0;

function check(string $label, $actual, $expected): void
{
    global $failures, $checks;
    $checks++;
    if ($actual !== $expected) {
        $failures[] = sprintf('%s — got %s, want %s', $label, var_export($actual, true), var_export($expected, true));
    }
}

function checkContains(string $label, string $haystack, string $needle, bool $shouldContain = true): void
{
    check($label, str_contains($haystack, $needle), $shouldContain);
}

// ---------------------------------------------------------------- URL guard

$push = (new ReflectionClass(PushService::class))->newInstanceWithoutConstructor();
$normalise = new ReflectionMethod(PushService::class, 'normalisePayload');
$normalise->setAccessible(true);

$url = fn(array $payload) => $normalise->invoke($push, $payload)['url'];

check('external https url is rejected', $url(['url' => 'https://evil.test/steal']), '/');
check('protocol-relative url is rejected', $url(['url' => '//evil.test']), '/');
check('scheme-only url is rejected', $url(['url' => 'javascript:alert(1)']), '/');
check('empty url falls back to root', $url([]), '/');
check('same-origin path is preserved', $url(['url' => '/leave_history.php']), '/leave_history.php');
check('title falls back to app name', $normalise->invoke($push, [])['title'], 'TP-HR');

// ------------------------------------------------------- key material guard

$validKeys = new ReflectionMethod(PushService::class, 'isValidKeyMaterial');
$validKeys->setAccessible(true);

$b64u = fn(string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
$goodKey = $b64u("\x04" . str_repeat('a', 64)); // uncompressed P-256 point
$goodAuth = $b64u(str_repeat('b', 16));

check('valid key material accepted', $validKeys->invoke(null, $goodKey, $goodAuth), true);
check('garbage key rejected', $validKeys->invoke(null, 'NOT-A-KEY', $goodAuth), false);
check('short key rejected', $validKeys->invoke(null, $b64u(str_repeat('a', 32)), $goodAuth), false);
check('compressed point rejected', $validKeys->invoke(null, $b64u("\x02" . str_repeat('a', 64)), $goodAuth), false);
check('short auth secret rejected', $validKeys->invoke(null, $goodKey, $b64u(str_repeat('b', 8))), false);
check('empty values rejected', $validKeys->invoke(null, '', ''), false);

// ------------------------------------------------------------ push wiring

$essLeave = (string)file_get_contents($root . '/api/leave.php');
$apiLeave = (string)file_get_contents($root . '/api/v1/leave.php');

// Every place that notifies LINE about a decision must notify push too,
// otherwise PWA users silently stop hearing about approvals.
check(
    'api/leave.php pushes on every LINE leave decision',
    substr_count($essLeave, 'tp_hr_push_leave_decision('),
    substr_count($essLeave, 'crm_line_notify_leave_decision(')
);
check(
    'api/v1/leave.php pushes on every LINE leave decision',
    substr_count($apiLeave, 'tp_hr_push_leave_decision('),
    substr_count($apiLeave, 'crm_line_notify_leave_decision(')
);

$helpers = (string)file_get_contents($root . '/core/Helpers.php');
checkContains('push helper is defined', $helpers, 'function tp_hr_push_leave_decision');

$bootstrap = (string)file_get_contents($root . '/bootstrap.php');
checkContains('PushService is loaded by bootstrap', $bootstrap, 'core/Services/PushService.php');

// ------------------------------------------------------------- client push

$pwaJs = (string)file_get_contents($root . '/assets/js/pwa.js');

// Safari ties Notification.requestPermission() to user activation, and
// activation does not survive an await. The opt-in card must therefore hand
// its already-fetched config to enablePush() so the prompt fires on the tap;
// fetching inside the handler makes the prompt silently never appear on iOS.
checkContains('enablePush accepts a preloaded config', $pwaJs, 'function enablePush(preloadedConfig)');
checkContains('opt-in card passes its config through', $pwaJs, 'enablePush(config)');

// A pruned or rotated subscription leaves permission granted with nothing
// registered server-side, and nothing would ever prompt again.
checkContains('silent re-subscribe path exists', $pwaJs, 'function repairPushSubscription');

// -------------------------------------------------------- service worker

$sw = (string)file_get_contents($root . '/sw.js');

checkContains('sw handles push', $sw, "addEventListener('push'");
checkContains('sw handles notification clicks', $sw, "addEventListener('notificationclick'");
checkContains('sw keeps the API out of the cache', $sw, "'api/'");

// Regression guard: matching assets with ignoreSearch silently defeats the
// `?v=` cache-bust convention in DEPLOY_CHECKLIST.md — a bumped stylesheet
// would keep serving the previous body on the first load after a deploy.
// ignoreSearch is legitimate only in the offline fallback further down.
$assetLookup = substr($sw, (int)strpos($sw, 'async function handleAsset'));
$assetLookup = substr($assetLookup, 0, (int)strpos($assetLookup, 'if (cached) return cached;'));
check(
    'asset lookup does not ignoreSearch before hitting the network',
    str_contains($assetLookup, 'ignoreSearch'),
    false
);
checkContains('offline fallback still allows a stale variant', $sw, 'ignoreSearch: true');

// HTML must never be cached — payslips and employee records would land in
// CacheStorage and outlive the session.
check(
    'navigations are never written to the cache',
    str_contains($sw, 'async function handleNavigate') && !str_contains(
        substr($sw, (int)strpos($sw, 'async function handleNavigate'), 600),
        'cache.put'
    ),
    true
);

// ------------------------------------------------------------------ report

if ($failures !== []) {
    fwrite(STDERR, "FAIL — PWA/push contract:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }
    exit(1);
}

echo "OK — PWA/push contract ($checks checks)\n";
