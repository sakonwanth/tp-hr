<?php
/**
 * PayslipTokenService — secure token issuance & verification for LINE-delivered payslips
 *
 * Usage:
 *   $token = PayslipTokenService::issue($pdo, $slipId, $userId, $issuedBy, 90);
 *   $url   = PayslipTokenService::buildUrl($token);
 *   $info  = PayslipTokenService::verify($pdo, $token);  // returns row or null
 *
 * Security:
 *   - Token = 32-byte cryptographic random, hex-encoded (64 chars)
 *   - Expires (default 90 days; configurable via settings.payroll_slip_link_ttl_days)
 *   - Per-access logging (count, ip, user-agent)
 *   - Revocable via revoked_at column
 */
class PayslipTokenService
{
    /**
     * Issue a new token for (slip, user). Returns 64-char hex.
     * If an existing non-expired, non-revoked token exists for the pair, REUSE it
     * so that re-sending the Flex card does not explode the token table.
     */
    public static function issue(PDO $pdo, int $slipId, int $userId, ?int $issuedBy = null, int $ttlDays = 90): string
    {
        $ttlDays = max(1, min(365, $ttlDays));

        // Verify slip belongs to user
        $chk = $pdo->prepare("SELECT id FROM payroll_slips WHERE id = ? AND user_id = ? LIMIT 1");
        $chk->execute([$slipId, $userId]);
        if (!$chk->fetchColumn()) {
            throw new RuntimeException('slip not found or does not belong to user');
        }

        // Reuse active token if exists
        $sel = $pdo->prepare("
            SELECT token FROM payroll_slip_tokens
            WHERE slip_id = ? AND user_id = ?
              AND revoked_at IS NULL
              AND expires_at > NOW()
            ORDER BY id DESC LIMIT 1
        ");
        $sel->execute([$slipId, $userId]);
        $existing = $sel->fetchColumn();
        if ($existing) return $existing;

        // Create new
        $token = bin2hex(random_bytes(32));
        $expires = (new DateTime())->modify("+{$ttlDays} days")->format('Y-m-d H:i:s');

        $ins = $pdo->prepare("
            INSERT INTO payroll_slip_tokens (token, slip_id, user_id, expires_at, issued_by, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $ins->execute([$token, $slipId, $userId, $expires, $issuedBy]);
        return $token;
    }

    /**
     * Verify token and return the DB row (+slip info) or null if invalid/expired/revoked.
     * Side-effect: increments access_count and updates last_* fields.
     */
    public static function verify(PDO $pdo, string $token): ?array
    {
        if (strlen($token) !== 64 || !ctype_xdigit($token)) return null;

        $sel = $pdo->prepare("
            SELECT t.*, s.payroll_run_id, s.user_id AS slip_user_id
            FROM payroll_slip_tokens t
            JOIN payroll_slips s ON t.slip_id = s.id
            WHERE t.token = ?
              AND t.revoked_at IS NULL
              AND t.expires_at > NOW()
            LIMIT 1
        ");
        $sel->execute([$token]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        // Log access (best-effort — never fail the request because of logging)
        try {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
            if ($ip && strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
            $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $upd = $pdo->prepare("
                UPDATE payroll_slip_tokens
                SET access_count = access_count + 1,
                    last_accessed_at = NOW(),
                    last_ip = ?,
                    last_user_agent = ?
                WHERE id = ?
            ");
            $upd->execute([$ip, $ua, $row['id']]);
        } catch (Throwable $e) {
            error_log('PayslipTokenService::verify log error: ' . $e->getMessage());
        }

        return $row;
    }

    /**
     * Revoke a token (e.g. employee reports compromise).
     */
    public static function revoke(PDO $pdo, string $token): bool
    {
        $stmt = $pdo->prepare("UPDATE payroll_slip_tokens SET revoked_at = NOW() WHERE token = ? AND revoked_at IS NULL");
        $stmt->execute([$token]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Build public download URL.
     */
    public static function buildUrl(string $token): string
    {
        $base = 'https://hr.tp-asset.com';
        return $base . '/payroll_slip.php?token=' . urlencode($token);
    }
}
