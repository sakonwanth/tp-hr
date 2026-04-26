<?php
/**
 * ApiAuth
 * Bearer-token based auth for external API (/api/v1/*)
 *
 * Responsibilities:
 *  - Validate Authorization: Bearer <key>
 *  - Enforce IP allowlist (optional)
 *  - Enforce scopes
 *  - Rate-limit per key (in-memory file lock)
 *  - CORS based on key's allowed_origins
 *  - Log every request to hr_api_request_logs
 */
class ApiAuth {
    private static ?array $key = null;
    private static float $tStart = 0.0;

    /** Format of issued key: "tphr_<32 hex>". Prefix = first 12 chars of full key. */
    public const KEY_PREFIX = 'tphr_';

    public static function start(): void {
        if (self::$tStart > 0.0) return;
        self::$tStart = microtime(true);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');

        // Handle CORS preflight BEFORE requiring auth.
        // Preflight carries no Authorization header; respond 204 immediately
        // if origin matches any registered key's allowed_origins.
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            self::handlePreflight();
        }
    }

    private static function handlePreflight(): void {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '') { http_response_code(204); exit; }
        try {
            $pdo = getDB();
            $stmt = $pdo->query("SELECT allowed_origins FROM hr_api_keys WHERE is_active = 1 AND allowed_origins IS NOT NULL");
            $allowed = false;
            foreach ($stmt as $r) {
                $list = json_decode($r['allowed_origins'], true) ?: [];
                if (in_array($origin, $list, true) || in_array('*', $list, true)) { $allowed = true; break; }
            }
            if ($allowed) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
                header('Access-Control-Allow-Headers: Authorization, Content-Type');
                header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
                header('Access-Control-Max-Age: 600');
            }
        } catch (Throwable $e) { /* ignore */ }
        http_response_code(204);
        exit;
    }

    /**
     * Require a valid API key with the given scopes.
     * Call at the top of each endpoint.
     */
    public static function require(array $requiredScopes = []): array {
        self::start();
        $key = self::resolveKey();
        if (!$key) {
            self::fail(401, 'Missing or invalid API key');
        }
        self::enforceIp($key);
        self::enforceRateLimit($key);
        self::enforceScopes($key, $requiredScopes);
        self::applyCors($key);
        self::$key = $key;

        // Update last-used (fire-and-forget; failure ignored)
        try {
            $pdo = getDB();
            $pdo->prepare("UPDATE hr_api_keys SET last_used_at = NOW(), last_used_ip = ? WHERE id = ?")
                ->execute([self::clientIp(), (int)$key['id']]);
        } catch (Throwable $e) { /* ignore */ }

        return $key;
    }

    public static function currentKey(): ?array {
        return self::$key;
    }

    /** Parse JSON body or urlencoded form; returns array. */
    public static function input(): array {
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($ct, 'application/json') !== false) {
            $raw = file_get_contents('php://input') ?: '';
            $data = json_decode($raw, true);
            return is_array($data) ? $data : [];
        }
        return $_POST ?: [];
    }

    /** Require method to be one of the given verbs. */
    public static function requireMethod(array $allowed): string {
        self::start();
        $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($m, $allowed, true)) self::fail(405, 'Method not allowed');
        return $m;
    }

    // =========================================================
    // Response helpers — always log before exit.
    // =========================================================
    public static function success(array $data = [], int $status = 200): void {
        self::log($status);
        http_response_code($status);
        echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function fail(int $status, string $message): void {
        self::start(); // ensure Content-Type is set even for pre-auth failures
        self::log($status, $message);
        http_response_code($status);
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================
    // Internals
    // =========================================================
    private static function resolveKey(): ?array {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!$header && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $k => $v) if (strcasecmp($k, 'Authorization') === 0) { $header = $v; break; }
        }
        if (stripos($header, 'Bearer ') !== 0) return null;
        $token = trim(substr($header, 7));
        if ($token === '' || strlen($token) > 100) return null;

        $hash = hash('sha256', $token);
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM hr_api_keys WHERE key_hash = ? LIMIT 1");
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if ((int)$row['is_active'] !== 1) return null;
        if (!empty($row['revoked_at'])) return null;
        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) return null;
        return $row;
    }

    private static function enforceIp(array $key): void {
        if (empty($key['allowed_ips'])) return;
        $list = json_decode($key['allowed_ips'], true);
        if (!is_array($list) || !$list) return;
        $ip = self::clientIp();
        foreach ($list as $allowed) {
            $allowed = trim((string)$allowed);
            if ($allowed === '') continue;
            if ($allowed === $ip) return;
            if (strpos($allowed, '/') !== false && self::ipInCidr($ip, $allowed)) return;
        }
        self::fail(403, 'IP not allowed');
    }

    private static function enforceScopes(array $key, array $required): void {
        if (!$required) return;
        $scopes = json_decode($key['scopes'] ?? '[]', true) ?: [];
        if (in_array('*', $scopes, true)) return;
        foreach ($required as $s) {
            if (!in_array($s, $scopes, true)) {
                self::fail(403, 'Scope not granted: ' . $s);
            }
        }
    }

    private static function enforceRateLimit(array $key): void {
        $limit = max(1, (int)$key['rate_limit_per_min']);
        $bucketDir = BASE_PATH . '/storage/api_ratelimit';
        if (!is_dir($bucketDir)) @mkdir($bucketDir, 0775, true);
        $minute = (int)floor(time() / 60);
        $file = $bucketDir . '/key_' . (int)$key['id'] . '_' . $minute . '.json';

        // Opportunistic cleanup: 1% chance per request to sweep stale files (>5 min old)
        if (random_int(1, 100) === 1) {
            $cutoff = time() - 300;
            foreach (glob($bucketDir . '/key_*.json') ?: [] as $old) {
                if (@filemtime($old) < $cutoff) @unlink($old);
            }
        }

        $fp = @fopen($file, 'c+');
        if (!$fp) return; // fail open if fs issue
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $count = $raw !== false && $raw !== '' ? (int)$raw : 0;
        $count++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string)$count);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        header('X-RateLimit-Limit: ' . $limit);
        header('X-RateLimit-Remaining: ' . max(0, $limit - $count));
        header('X-RateLimit-Reset: ' . (($minute + 1) * 60));

        if ($count > $limit) {
            header('Retry-After: ' . (60 - (time() % 60)));
            self::fail(429, 'Rate limit exceeded');
        }
    }

    private static function applyCors(array $key): void {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '' || empty($key['allowed_origins'])) return;
        $list = json_decode($key['allowed_origins'], true);
        if (!is_array($list)) return;
        if (!in_array($origin, $list, true) && !in_array('*', $list, true)) return;
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    private static function log(int $status, ?string $err = null): void {
        try {
            $pdo = getDB();
            $pdo->prepare("
                INSERT INTO hr_api_request_logs
                  (api_key_id, method, path, query_string, status_code, ip_address, user_agent, response_ms, error_message)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([
                self::$key['id'] ?? null,
                substr($_SERVER['REQUEST_METHOD'] ?? '', 0, 10),
                substr($_SERVER['REQUEST_URI'] ?? '', 0, 255),
                isset($_SERVER['QUERY_STRING']) ? substr($_SERVER['QUERY_STRING'], 0, 1000) : null,
                $status,
                self::clientIp(),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                (int)((microtime(true) - self::$tStart) * 1000),
                $err ? substr($err, 0, 255) : null,
            ]);
        } catch (Throwable $e) { /* ignore */ }
    }

    private static function clientIp(): string {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private static function ipInCidr(string $ip, string $cidr): bool {
        [$subnet, $bits] = explode('/', $cidr, 2) + [null, null];
        if (!$subnet || $bits === null) return false;
        $bits = (int)$bits;
        $ipPack = @inet_pton($ip);
        $subPack = @inet_pton($subnet);
        if (!$ipPack || !$subPack || strlen($ipPack) !== strlen($subPack)) return false;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if (substr($ipPack, 0, $bytes) !== substr($subPack, 0, $bytes)) return false;
        if ($remainder === 0) return true;
        $mask = chr(0xFF << (8 - $remainder) & 0xFF);
        return (ord($ipPack[$bytes]) & ord($mask)) === (ord($subPack[$bytes]) & ord($mask));
    }

    // =========================================================
    // Issue a new key (used by admin UI). Returns full plain key ONCE.
    // =========================================================
    public static function issue(array $opts): array {
        $raw = self::KEY_PREFIX . bin2hex(random_bytes(16));
        $prefix = substr($raw, 0, 12);
        $hash = hash('sha256', $raw);
        $pdo = getDB();
        $serviceUserId = isset($opts['service_user_id']) ? (int) $opts['service_user_id'] : 0;
        $serviceUserId = $serviceUserId > 0 ? $serviceUserId : null;
        $stmt = $pdo->prepare("
            INSERT INTO hr_api_keys (name, key_prefix, key_hash, scopes, allowed_ips, allowed_origins,
                                     rate_limit_per_min, is_active, expires_at, created_by, notes, service_user_id)
            VALUES (?,?,?,?,?,?,?,1,?,?,?,?)
        ");
        $stmt->execute([
            $opts['name'],
            $prefix,
            $hash,
            isset($opts['scopes']) ? json_encode(array_values($opts['scopes']), JSON_UNESCAPED_UNICODE) : null,
            isset($opts['allowed_ips']) && $opts['allowed_ips'] ? json_encode(array_values($opts['allowed_ips'])) : null,
            isset($opts['allowed_origins']) && $opts['allowed_origins'] ? json_encode(array_values($opts['allowed_origins'])) : null,
            (int)($opts['rate_limit_per_min'] ?? 60),
            $opts['expires_at'] ?? null,
            $opts['created_by'] ?? null,
            $opts['notes'] ?? null,
            $serviceUserId,
        ]);
        return ['id' => (int)$pdo->lastInsertId(), 'key' => $raw, 'prefix' => $prefix];
    }
}
