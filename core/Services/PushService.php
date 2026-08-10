<?php

/**
 * Web Push delivery for the TP-HR PWA.
 *
 * Complements the LINE bridge rather than replacing it: LINE reaches everyone,
 * push only reaches employees who installed the PWA and granted permission.
 * Both are best-effort — a push failure must never break the HR workflow that
 * triggered it, so every public method swallows its errors into the log.
 *
 * Requires VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY / VAPID_SUBJECT in .env.
 * Generate them once with: php scripts/generate_vapid_keys.php
 *
 * hr_push_subscriptions is shared with tp-checkin, which subscribes from its
 * own origin. Every query here is scoped to APP so a leave decision never
 * reaches a checkin install, whose service worker would resolve the payload's
 * path against the wrong host. The canonical implementation of all of this now
 * lives in TpCommon\Push\WebPushService; this class stays standalone only
 * because scripts/qa_pwa_push_contract.php runs in CI without vendor/.
 */
class PushService
{
    /** Value written to, and filtered on, the `app` column. */
    private const APP = 'tp-hr';

    /** Push services drop payloads larger than this. */
    private const MAX_PAYLOAD_BYTES = 3000;

    /** Consecutive failures before a subscription is considered dead. */
    private const MAX_FAILURES = 5;

    /**
     * Installs kept per user. A few phones plus a desktop browser is normal;
     * beyond that it is churn, or an authenticated client posting fabricated
     * endpoints. Oldest rows are dropped rather than refusing the newest
     * device, so a real re-install always wins.
     */
    private const MAX_SUBSCRIPTIONS_PER_USER = 10;

    private PDO $pdo;
    private ?bool $available = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * True when the table exists, the library is installed, and VAPID keys are
     * configured. Callers use this to hide the whole feature when unset up.
     */
    public function isConfigured(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        $this->available = class_exists(\Minishlink\WebPush\WebPush::class)
            && $this->publicKey() !== ''
            && $this->privateKey() !== ''
            && $this->tableExists();

        return $this->available;
    }

    /** VAPID public key, safe to hand to the browser. */
    public function publicKey(): string
    {
        return trim((string)($_ENV['VAPID_PUBLIC_KEY'] ?? getenv('VAPID_PUBLIC_KEY') ?: ''));
    }

    private function privateKey(): string
    {
        return trim((string)($_ENV['VAPID_PRIVATE_KEY'] ?? getenv('VAPID_PRIVATE_KEY') ?: ''));
    }

    private function subject(): string
    {
        $subject = trim((string)($_ENV['VAPID_SUBJECT'] ?? getenv('VAPID_SUBJECT') ?: ''));
        return $subject !== '' ? $subject : (defined('APP_URL') ? (string)APP_URL : 'https://hr.tp-asset.com');
    }

    private function tableExists(): bool
    {
        try {
            // Probes the `app` column, not just the table: this code can reach
            // production before 2026_08_11_hr_push_subscriptions_app.sql runs,
            // and hiding the feature for those few minutes beats every
            // subscribe and send throwing on an unknown column.
            $this->pdo->query('SELECT app FROM hr_push_subscriptions LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Store (or refresh) a browser subscription for a user.
     *
     * Keyed on the endpoint, so reinstalling the PWA replaces the old row and
     * moving an install between accounts reassigns it instead of leaking
     * notifications to the previous owner.
     */
    public function subscribe(int $userId, array $subscription, ?string $userAgent = null): bool
    {
        $endpoint = trim((string)($subscription['endpoint'] ?? ''));
        $p256dh   = trim((string)($subscription['keys']['p256dh'] ?? ''));
        $auth     = trim((string)($subscription['keys']['auth'] ?? ''));

        if ($userId <= 0 || $endpoint === '' || $p256dh === '' || $auth === '') {
            return false;
        }
        if (!preg_match('#^https://#i', $endpoint) || strlen($endpoint) > 500) {
            return false;
        }
        if (!self::isValidKeyMaterial($p256dh, $auth)) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO hr_push_subscriptions
                (user_id, app, endpoint, endpoint_hash, p256dh, auth_secret, user_agent, last_used_at)
             VALUES (:user_id, :app, :endpoint, :endpoint_hash, :p256dh, :auth_secret, :user_agent, NULL)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                app = VALUES(app),
                p256dh = VALUES(p256dh),
                auth_secret = VALUES(auth_secret),
                user_agent = VALUES(user_agent),
                failure_count = 0,
                last_failed_at = NULL'
        );

        $ok = $stmt->execute([
            'user_id'       => $userId,
            'app'           => self::APP,
            'endpoint'      => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'p256dh'        => $p256dh,
            'auth_secret'   => $auth,
            'user_agent'    => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
        ]);

        if ($ok) {
            $this->trimToLimit($userId);
        }

        return $ok;
    }

    /** Keep only the newest MAX_SUBSCRIPTIONS_PER_USER rows for a user. */
    private function trimToLimit(int $userId): void
    {
        try {
            // LIMIT is not allowed in a subquery on the same table in MySQL,
            // so pick the survivors first and delete by id.
            $stmt = $this->pdo->prepare(
                'SELECT id FROM hr_push_subscriptions
                 WHERE user_id = :user_id AND app = :app
                 ORDER BY created_at DESC, id DESC
                 LIMIT ' . self::MAX_SUBSCRIPTIONS_PER_USER
            );
            $stmt->execute(['user_id' => $userId, 'app' => self::APP]);
            $keep = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            if (count($keep) < self::MAX_SUBSCRIPTIONS_PER_USER) {
                return;
            }

            $in = implode(',', array_fill(0, count($keep), '?'));
            $delete = $this->pdo->prepare(
                "DELETE FROM hr_push_subscriptions WHERE user_id = ? AND app = ? AND id NOT IN ($in)"
            );
            $delete->execute(array_merge([$userId, self::APP], $keep));
        } catch (Throwable $e) {
            $this->logFailure($e);
        }
    }

    /**
     * A real PushSubscription always carries an uncompressed P-256 point (65
     * bytes, 0x04 prefix) and a 16-byte auth secret. Anything else blows up
     * inside the library's payload encryption during flush() — which aborts
     * the entire batch, silencing every other device the employee owns. Cheap
     * to check here; impossible to recover from there.
     */
    private static function isValidKeyMaterial(string $p256dh, string $auth): bool
    {
        $key = self::base64UrlDecode($p256dh);
        $secret = self::base64UrlDecode($auth);

        return $key !== null && strlen($key) === 65 && $key[0] === "\x04"
            && $secret !== null && strlen($secret) === 16;
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $padded .= str_repeat('=', (4 - (strlen($padded) % 4)) % 4);
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    /** Remove a subscription. Scoped to the user so one account can't unsubscribe another. */
    public function unsubscribe(int $userId, string $endpoint): bool
    {
        if ($userId <= 0 || $endpoint === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM hr_push_subscriptions
             WHERE user_id = :user_id AND app = :app AND endpoint_hash = :endpoint_hash'
        );

        return $stmt->execute([
            'user_id'       => $userId,
            'app'           => self::APP,
            'endpoint_hash' => hash('sha256', $endpoint),
        ]);
    }

    public function countForUser(int $userId): int
    {
        if (!$this->isConfigured() || $userId <= 0) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM hr_push_subscriptions WHERE user_id = :user_id AND app = :app'
        );
        $stmt->execute(['user_id' => $userId, 'app' => self::APP]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Send a notification to every install belonging to a user.
     *
     * @param array $payload  title, body, and optional url — rendered by the
     *                        'push' handler in sw.js.
     * @return int            number of installs the push service accepted
     */
    public function sendToUser(int $userId, array $payload): int
    {
        return $this->sendToUsers([$userId], $payload);
    }

    /**
     * @param int[] $userIds
     */
    public function sendToUsers(array $userIds, array $payload): int
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($id) => $id > 0)));

        if (!$this->isConfigured() || $userIds === []) {
            return 0;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT id, endpoint, p256dh, auth_secret
                 FROM hr_push_subscriptions
                 WHERE app = ? AND user_id IN ($placeholders)"
            );
            $stmt->execute(array_merge([self::APP], $userIds));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if ($rows === []) {
                return 0;
            }

            return $this->dispatch($rows, $payload);
        } catch (Throwable $e) {
            $this->logFailure($e);
            return 0;
        }
    }

    /**
     * @param array<int, array{id:int, endpoint:string, p256dh:string, auth_secret:string}> $rows
     */
    private function dispatch(array $rows, array $payload): int
    {
        $body = json_encode($this->normalisePayload($payload), JSON_UNESCAPED_UNICODE);

        if ($body === false || strlen($body) > self::MAX_PAYLOAD_BYTES) {
            $this->logFailure(new RuntimeException('push payload too large or unencodable'));
            return 0;
        }

        $webPush = new \Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject'    => $this->subject(),
                'publicKey'  => $this->publicKey(),
                'privateKey' => $this->privateKey(),
            ],
        ]);
        // TTL: a leave decision is stale by the next working day.
        $webPush->setDefaultOptions(['TTL' => 86400, 'urgency' => 'normal']);

        $byEndpoint = [];
        $queued = [];
        $failed = [];

        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $byEndpoint[$row['endpoint']] = $id;

            // Rows predating subscribe()'s validation could still hold junk.
            // Skipping them here keeps a poison row out of flush(), which
            // would otherwise throw and silence the employee's other devices.
            if (!self::isValidKeyMaterial((string)$row['p256dh'], (string)$row['auth_secret'])) {
                $failed[] = $id;
                continue;
            }

            try {
                $webPush->queueNotification(
                    \Minishlink\WebPush\Subscription::create([
                        'endpoint'        => $row['endpoint'],
                        'publicKey'       => $row['p256dh'],
                        'authToken'       => $row['auth_secret'],
                        'contentEncoding' => 'aes128gcm',
                    ]),
                    $body
                );
                $queued[] = $id;
            } catch (Throwable $e) {
                $failed[] = $id;
                $this->logFailure($e);
            }
        }

        if ($queued === []) {
            $this->markSent(array_values($byEndpoint), [], $failed);
            return 0;
        }

        $sent = 0;
        $expired = [];

        try {
            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getRequest()->getUri()->__toString();
                $id = $byEndpoint[$endpoint] ?? null;

                if ($report->isSuccess()) {
                    $sent++;
                    continue;
                }
                if ($id === null) {
                    continue;
                }
                // 404/410 means the browser threw the subscription away — the
                // row will never work again, so drop it rather than retrying.
                if ($report->isSubscriptionExpired()) {
                    $expired[] = $id;
                } else {
                    $failed[] = $id;
                }
            }
        } catch (Throwable $e) {
            // flush() aborts the remaining reports when it throws, so anything
            // still unaccounted for is charged a failure and eventually pruned.
            $this->logFailure($e);
            $accounted = array_merge($expired, $failed);
            $failed = array_merge($failed, array_values(array_diff($queued, $accounted)));
        }

        $this->markSent(array_values($byEndpoint), $expired, $failed);
        $this->deleteById($expired);

        return $sent;
    }

    private function normalisePayload(array $payload): array
    {
        return [
            'title' => mb_substr(trim((string)($payload['title'] ?? 'TP-HR')), 0, 120),
            'body'  => mb_substr(trim((string)($payload['body'] ?? '')), 0, 400),
            'url'   => $this->safeUrl((string)($payload['url'] ?? '/')),
            'tag'   => mb_substr(trim((string)($payload['tag'] ?? 'tp-hr')), 0, 60),
        ];
    }

    /**
     * Only same-app paths may be opened by a notification click, so a bad
     * payload can never send an employee to an external site.
     */
    private function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')) {
            return '/';
        }
        return mb_substr($url, 0, 300);
    }

    /**
     * @param int[] $allIds
     * @param int[] $expired
     * @param int[] $failed
     */
    private function markSent(array $allIds, array $expired, array $failed): void
    {
        $problem = array_merge($expired, $failed);
        $ok = array_values(array_diff($allIds, $problem));

        try {
            if ($ok !== []) {
                $in = implode(',', array_fill(0, count($ok), '?'));
                $this->pdo
                    ->prepare("UPDATE hr_push_subscriptions SET last_used_at = NOW(), failure_count = 0, last_failed_at = NULL WHERE id IN ($in)")
                    ->execute($ok);
            }
            if ($failed !== []) {
                $in = implode(',', array_fill(0, count($failed), '?'));
                $this->pdo
                    ->prepare("UPDATE hr_push_subscriptions SET last_failed_at = NOW(), failure_count = failure_count + 1 WHERE id IN ($in)")
                    ->execute($failed);
                // Give up on installs that keep failing for non-expiry reasons.
                // Scoped to this app: another app's rows are not ours to prune.
                $this->pdo
                    ->prepare('DELETE FROM hr_push_subscriptions WHERE app = ? AND failure_count >= ' . self::MAX_FAILURES)
                    ->execute([self::APP]);
            }
        } catch (Throwable $e) {
            $this->logFailure($e);
        }
    }

    /** @param int[] $ids */
    private function deleteById(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $this->pdo->prepare("DELETE FROM hr_push_subscriptions WHERE id IN ($in)")->execute($ids);
        } catch (Throwable $e) {
            $this->logFailure($e);
        }
    }

    private function logFailure(Throwable $e): void
    {
        if (function_exists('tpHrLogException')) {
            tpHrLogException($e, 'PushService');
            return;
        }
        error_log('[PushService] ' . $e->getMessage());
    }
}
