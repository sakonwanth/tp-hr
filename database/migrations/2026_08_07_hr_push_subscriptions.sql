-- Web Push subscriptions for the TP-HR PWA. Additive: one new table, no
-- changes to existing tables, safe to run on the shared tp_crm database.
--
-- One row per (user, browser install). The endpoint is the push service URL
-- issued by Apple/Google and is globally unique, so it is the natural key —
-- re-subscribing from the same install updates the row instead of piling up.
--
-- p256dh / auth are the subscription's public key material, not secrets of
-- ours: they are useless without the VAPID private key held in .env.
CREATE TABLE IF NOT EXISTS hr_push_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    endpoint VARCHAR(500) NOT NULL,
    endpoint_hash CHAR(64) NOT NULL COMMENT 'sha256(endpoint) — endpoints exceed the InnoDB unique-key length',
    p256dh VARCHAR(255) NOT NULL,
    auth_secret VARCHAR(255) NOT NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL COMMENT 'last successful delivery',
    last_failed_at DATETIME NULL,
    failure_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uk_hr_push_endpoint (endpoint_hash),
    INDEX idx_hr_push_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
