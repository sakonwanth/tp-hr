-- Attendance discipline and LINE acknowledgement workflow.
-- Additive and idempotent; TP-HR owns all tables in this migration.

CREATE TABLE IF NOT EXISTS hr_discipline_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_code VARCHAR(64) NOT NULL,
    name_th VARCHAR(255) NOT NULL,
    rule_type ENUM('LATE_COUNT','CONSECUTIVE_ABSENCE') NOT NULL,
    threshold_value DECIMAL(8,2) NOT NULL,
    period_type ENUM('PAYROLL_CYCLE','ROLLING_WORKDAYS') NOT NULL,
    severity ENUM('NORMAL','URGENT') NOT NULL DEFAULT 'NORMAL',
    config_json JSON NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_hr_discipline_rule_code (rule_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_discipline_cases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_no VARCHAR(40) NOT NULL,
    rule_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    payroll_month CHAR(7) NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    occurrence_count DECIMAL(8,2) NOT NULL,
    evidence_fingerprint CHAR(64) NOT NULL,
    status ENUM('DETECTED','UNDER_REVIEW','AWAITING_EXPLANATION','DRAFT','APPROVED','DELIVERED','ACKNOWLEDGED','DISPUTED','CANCELLED','EXPIRED') NOT NULL DEFAULT 'DETECTED',
    employee_explanation TEXT NULL,
    hr_note TEXT NULL,
    detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    cancelled_by INT NULL,
    cancelled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_hr_discipline_case_no (case_no),
    UNIQUE KEY uk_hr_discipline_case_fingerprint (user_id,rule_id,evidence_fingerprint),
    UNIQUE KEY uk_hr_discipline_case_period (user_id,rule_id,period_start,period_end),
    KEY idx_hr_discipline_case_status (status,detected_at),
    KEY idx_hr_discipline_case_user (user_id,period_start,period_end),
    CONSTRAINT fk_hr_discipline_case_rule FOREIGN KEY (rule_id) REFERENCES hr_discipline_rules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_discipline_case_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id BIGINT UNSIGNED NOT NULL,
    attendance_id INT NULL,
    event_date DATE NOT NULL,
    event_type ENUM('LATE','ABSENT','MISSING_ATTENDANCE') NOT NULL,
    late_minutes INT NULL,
    detail_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_hr_discipline_case_event (case_id,event_date,event_type),
    CONSTRAINT fk_hr_discipline_event_case FOREIGN KEY (case_id) REFERENCES hr_discipline_cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_warning_letters (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id BIGINT UNSIGNED NOT NULL,
    warning_no VARCHAR(40) NOT NULL,
    warning_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    subject VARCHAR(255) NOT NULL,
    body_text MEDIUMTEXT NOT NULL,
    policy_reference VARCHAR(500) NULL,
    consent_version VARCHAR(40) NOT NULL DEFAULT 'hr-warning-v1',
    document_sha256 CHAR(64) NOT NULL,
    public_id CHAR(36) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    issued_by INT NOT NULL,
    issued_at DATETIME NOT NULL,
    valid_until DATE NOT NULL,
    expires_at DATETIME NOT NULL,
    delivered_at DATETIME NULL,
    acknowledged_at DATETIME NULL,
    disputed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_hr_warning_no (warning_no),
    UNIQUE KEY uk_hr_warning_case (case_id),
    UNIQUE KEY uk_hr_warning_public_id (public_id),
    CONSTRAINT fk_hr_warning_case FOREIGN KEY (case_id) REFERENCES hr_discipline_cases(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_warning_acknowledgements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warning_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    action ENUM('ACKNOWLEDGED','DISPUTED') NOT NULL,
    employee_note TEXT NULL,
    signature_png MEDIUMBLOB NULL,
    signature_sha256 CHAR(64) NULL,
    document_sha256 CHAR(64) NOT NULL,
    line_user_id_hash CHAR(64) NOT NULL,
    consent_text TEXT NOT NULL,
    consent_version VARCHAR(40) NOT NULL,
    ip_address_hash CHAR(64) NOT NULL,
    user_agent_hash CHAR(64) NOT NULL,
    acknowledged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_hr_warning_ack (warning_id),
    CONSTRAINT fk_hr_warning_ack_letter FOREIGN KEY (warning_id) REFERENCES hr_warning_letters(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_discipline_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id BIGINT UNSIGNED NOT NULL,
    warning_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(64) NOT NULL,
    actor_user_id INT NULL,
    actor_line_user_id_hash CHAR(64) NULL,
    payload_json JSON NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_hr_discipline_audit_idempotency (idempotency_key),
    KEY idx_hr_discipline_audit_case (case_id,created_at),
    CONSTRAINT fk_hr_discipline_audit_case FOREIGN KEY (case_id) REFERENCES hr_discipline_cases(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO hr_discipline_rules
    (rule_code,name_th,rule_type,threshold_value,period_type,severity,config_json,is_active)
VALUES
    ('LATE_3_PER_PAYROLL','มาสายครบ 3 ครั้งต่อรอบเงินเดือน','LATE_COUNT',3,'PAYROLL_CYCLE','NORMAL',JSON_OBJECT('exclude_excused',true),1),
    ('ABSENT_3_CONSECUTIVE_WORKDAYS','ขาดงาน 3 วันทำงานติดต่อกันโดยไม่มีเหตุอันสมควร','CONSECUTIVE_ABSENCE',3,'ROLLING_WORKDAYS','URGENT',JSON_OBJECT('requires_hr_legal_review',true),1)
ON DUPLICATE KEY UPDATE
    name_th=VALUES(name_th), threshold_value=VALUES(threshold_value), severity=VALUES(severity), config_json=VALUES(config_json);
