-- External API infrastructure
-- Run: mysql -u tpasset_db -p tp_crm < 2026_04_21_external_api.sql

-- ============================================
-- hr_api_keys: ทะเบียนคีย์สำหรับระบบภายนอก
-- ============================================
CREATE TABLE IF NOT EXISTS hr_api_keys (
    id              INT(11) NOT NULL AUTO_INCREMENT,
    name            VARCHAR(100) NOT NULL COMMENT 'ชื่อระบบ/ผู้ใช้คีย์',
    key_prefix      VARCHAR(12)  NOT NULL COMMENT 'prefix 8 ตัว แสดงในรายการ (tphr_xxxxxxxx)',
    key_hash        CHAR(64)     NOT NULL COMMENT 'SHA-256 ของคีย์เต็ม',
    scopes          TEXT         NULL     COMMENT 'JSON array: ["attendance.read","leave.read",...]',
    allowed_ips     TEXT         NULL     COMMENT 'JSON array ของ IP/CIDR; NULL = อนุญาตทุก IP',
    allowed_origins TEXT         NULL     COMMENT 'JSON array ของ origin สำหรับ CORS; NULL = ไม่เปิด CORS',
    rate_limit_per_min INT(11)   NOT NULL DEFAULT 60,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    expires_at      DATETIME     NULL,
    last_used_at    DATETIME     NULL,
    last_used_ip    VARCHAR(45)  NULL,
    created_by      INT(11)      NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NULL ON UPDATE CURRENT_TIMESTAMP,
    revoked_at      DATETIME     NULL,
    revoked_by      INT(11)      NULL,
    notes           TEXT         NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_key_hash (key_hash),
    KEY idx_prefix (key_prefix),
    KEY idx_active (is_active, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- hr_api_request_logs: audit log ทุก request
-- ============================================
CREATE TABLE IF NOT EXISTS hr_api_request_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_key_id      INT(11)      NULL,
    method          VARCHAR(10)  NOT NULL,
    path            VARCHAR(255) NOT NULL,
    query_string    TEXT         NULL,
    status_code     SMALLINT     NOT NULL,
    ip_address      VARCHAR(45)  NOT NULL,
    user_agent      VARCHAR(255) NULL,
    response_ms     INT(11)      NULL,
    error_message   VARCHAR(255) NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_api_key (api_key_id, created_at),
    KEY idx_created (created_at),
    KEY idx_status (status_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
