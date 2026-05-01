-- Additive schema reconciliation for local/prod preflight baseline.
-- Safe-only: create missing tables/columns/indexes/settings, no destructive migration.

-- -------------------------------------------------------------------
-- users compatibility columns
-- -------------------------------------------------------------------
ALTER TABLE users ADD COLUMN IF NOT EXISTS employment_type VARCHAR(100) NULL AFTER hire_date;
ALTER TABLE users ADD COLUMN IF NOT EXISTS salary DECIMAL(12,2) NULL AFTER employment_type;
ALTER TABLE users ADD COLUMN IF NOT EXISTS probation_salary DECIMAL(12,2) NULL AFTER salary;
ALTER TABLE users ADD COLUMN IF NOT EXISTS work_mode ENUM('OFFICE','WFH') NOT NULL DEFAULT 'OFFICE' AFTER employment_type;
ALTER TABLE users ADD COLUMN IF NOT EXISTS bank_name VARCHAR(100) NULL AFTER salary;
ALTER TABLE users ADD COLUMN IF NOT EXISTS bank_account VARCHAR(100) NULL AFTER bank_name;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_ip VARCHAR(45) NULL AFTER last_login;
CREATE INDEX IF NOT EXISTS idx_work_mode ON users (work_mode);

-- -------------------------------------------------------------------
-- hr_attendances compatibility columns/index
-- -------------------------------------------------------------------
ALTER TABLE hr_attendances ADD COLUMN IF NOT EXISTS late_excused TINYINT(1) NOT NULL DEFAULT 0 AFTER late_minutes;
ALTER TABLE hr_attendances ADD COLUMN IF NOT EXISTS late_excused_reason TEXT NULL AFTER late_excused;
ALTER TABLE hr_attendances ADD COLUMN IF NOT EXISTS late_notified_at DATETIME NULL AFTER late_excused_reason;
ALTER TABLE hr_attendances ADD COLUMN IF NOT EXISTS is_offsite TINYINT(1) NOT NULL DEFAULT 0 AFTER check_out_ip;
ALTER TABLE hr_attendances ADD COLUMN IF NOT EXISTS offsite_status ENUM('PENDING','APPROVED','REJECTED') NULL AFTER is_offsite;
ALTER TABLE hr_attendances ADD COLUMN IF NOT EXISTS offsite_reason TEXT NULL AFTER offsite_status;
ALTER TABLE hr_attendances ADD COLUMN IF NOT EXISTS offsite_approved_by INT NULL AFTER offsite_reason;
ALTER TABLE hr_attendances ADD COLUMN IF NOT EXISTS offsite_approved_at DATETIME NULL AFTER offsite_approved_by;
ALTER TABLE hr_attendances ADD COLUMN IF NOT EXISTS offsite_remarks TEXT NULL AFTER offsite_approved_at;
CREATE INDEX IF NOT EXISTS idx_hr_att_planned ON hr_attendances (user_id, attendance_date, planned_start_time);

-- -------------------------------------------------------------------
-- outside attendance requests
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hr_attendance_outside_requests (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    attendance_id BIGINT NULL,
    request_type ENUM('CHECK_IN','CHECK_OUT') NOT NULL,
    request_date DATE NOT NULL,
    request_time DATETIME NOT NULL,
    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,
    photo_path VARCHAR(255) NULL,
    reason TEXT NULL,
    status ENUM('PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    request_ip VARCHAR(45) NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (attendance_id) REFERENCES hr_attendances(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id),
    INDEX idx_user_date_type (user_id, request_date, request_type),
    INDEX idx_att_type_status (attendance_id, request_type, status),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- employee schedules / dayoff requests
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hr_employee_schedules (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    shift_id INT NULL,
    day_off TINYINT NOT NULL DEFAULT 0 COMMENT '0=Sunday ... 6=Saturday',
    effective_date DATE NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (shift_id) REFERENCES hr_work_shifts(id),
    FOREIGN KEY (updated_by) REFERENCES users(id),
    UNIQUE KEY uk_user (user_id),
    INDEX idx_day_off (day_off)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_dayoff_requests (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    week_start DATE NOT NULL,
    week_end DATE NOT NULL,
    original_day_off TINYINT NOT NULL,
    requested_day_off TINYINT NOT NULL,
    reason TEXT NULL,
    status ENUM('PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id),
    UNIQUE KEY uk_user_week (user_id, week_start),
    INDEX idx_status (status),
    INDEX idx_week (week_start, week_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- External API tables (hr_api_keys / hr_api_request_logs)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hr_api_keys (
    id INT(11) NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    key_prefix VARCHAR(12) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    scopes TEXT NULL,
    allowed_ips TEXT NULL,
    allowed_origins TEXT NULL,
    rate_limit_per_min INT(11) NOT NULL DEFAULT 60,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    expires_at DATETIME NULL,
    last_used_at DATETIME NULL,
    last_used_ip VARCHAR(45) NULL,
    created_by INT(11) NULL,
    service_user_id INT(11) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    revoked_by INT(11) NULL,
    notes TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_key_hash (key_hash),
    KEY idx_prefix (key_prefix),
    KEY idx_active (is_active, expires_at),
    KEY idx_service_user (service_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE hr_api_keys ADD COLUMN IF NOT EXISTS service_user_id INT(11) NULL AFTER created_by;
CREATE INDEX IF NOT EXISTS idx_service_user ON hr_api_keys (service_user_id);

CREATE TABLE IF NOT EXISTS hr_api_request_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_key_id INT(11) NULL,
    method VARCHAR(10) NOT NULL,
    path VARCHAR(255) NOT NULL,
    query_string TEXT NULL,
    status_code SMALLINT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NULL,
    response_ms INT(11) NULL,
    error_message VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_api_key (api_key_id, created_at),
    KEY idx_created (created_at),
    KEY idx_status (status_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- OT requests
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ot_requests (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    work_date DATE NOT NULL,
    planned_start TIME NOT NULL,
    planned_end TIME NOT NULL,
    actual_hours DECIMAL(5,2) NULL,
    ot_type ENUM('normal','holiday','day_off') NOT NULL DEFAULT 'normal',
    rate_multiplier DECIMAL(4,2) NOT NULL DEFAULT 1.50,
    reason TEXT NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    approved_by INT NULL,
    approved_at DATETIME NULL,
    reject_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    INDEX idx_user_work_date (user_id, work_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- payroll tables (latest compatible superset used by TP-HR services)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS employee_salary_setup (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    base_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    bonus_fixed DECIMAL(12,2) NOT NULL DEFAULT 0,
    provident_fund DECIMAL(12,2) NOT NULL DEFAULT 0,
    social_security DECIMAL(12,2) NOT NULL DEFAULT 0,
    group_insurance_total_monthly DECIMAL(12,2) NOT NULL DEFAULT 0,
    group_insurance_employer_pct DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    ss_opt_out TINYINT(1) NOT NULL DEFAULT 0,
    additional_tax_withholding DECIMAL(12,2) NOT NULL DEFAULT 0,
    allowance_json JSON NULL,
    income_other_json JSON NULL,
    deduction_other_json JSON NULL,
    tax_calculation_method VARCHAR(50) DEFAULT 'progressive_monthly',
    notes TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_effective (user_id, effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    payroll_month DATE NOT NULL,
    pay_day TINYINT NOT NULL DEFAULT 25,
    status ENUM('draft','calculated','approved','paid') NOT NULL DEFAULT 'draft',
    total_gross DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_tax DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_net DECIMAL(14,2) NOT NULL DEFAULT 0,
    employee_count INT NOT NULL DEFAULT 0,
    approved_by INT NULL,
    approved_at TIMESTAMP NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uk_payroll_month (payroll_month),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_slips (
    id INT PRIMARY KEY AUTO_INCREMENT,
    payroll_run_id INT NOT NULL,
    user_id INT NOT NULL,
    gross_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    bonus DECIMAL(12,2) NOT NULL DEFAULT 0,
    bonus_breakdown_json JSON NULL,
    allowances DECIMAL(12,2) NOT NULL DEFAULT 0,
    income_other_json JSON NULL,
    total_income DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_withheld DECIMAL(12,2) NOT NULL DEFAULT 0,
    provident_fund DECIMAL(12,2) NOT NULL DEFAULT 0,
    social_security DECIMAL(12,2) NOT NULL DEFAULT 0,
    group_insurance DECIMAL(12,2) NOT NULL DEFAULT 0,
    deduction_other_json JSON NULL,
    absent_days DECIMAL(6,2) NOT NULL DEFAULT 0,
    late_count_30 INT NOT NULL DEFAULT 0,
    late_count_60 INT NOT NULL DEFAULT 0,
    absence_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
    lateness_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
    attendance_detail_json JSON NULL,
    total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    attendance_summary_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uk_run_user (payroll_run_id, user_id),
    INDEX idx_run (payroll_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_slip_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    token CHAR(64) NOT NULL,
    slip_id INT NOT NULL,
    user_id INT NOT NULL,
    expires_at DATETIME NOT NULL,
    access_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_accessed_at DATETIME NULL,
    last_ip VARCHAR(45) NULL,
    last_user_agent VARCHAR(255) NULL,
    revoked_at DATETIME NULL,
    issued_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_token (token),
    KEY idx_slip (slip_id),
    KEY idx_user (user_id),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- cross-domain login token table
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cross_domain_tokens (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    token CHAR(64) NOT NULL,
    user_id INT NOT NULL,
    source_system VARCHAR(32) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_token (token),
    KEY idx_source_expires (source_system, expires_at),
    KEY idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------
-- Seed required settings for preflight (only when missing)
-- -------------------------------------------------------------------
INSERT INTO system_settings (setting_key, setting_value, category, description)
SELECT 'payroll_attendance_enabled', '1', 'payroll', 'Enable payroll attendance rules'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'payroll_attendance_enabled');

INSERT INTO system_settings (setting_key, setting_value, category, description)
SELECT 'payroll_ss_enabled', '1', 'payroll', 'Enable social security deduction'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'payroll_ss_enabled');

INSERT INTO system_settings (setting_key, setting_value, category, description)
SELECT 'payroll_absent_rate', '1', 'payroll', 'Absent deduction daily rate multiplier'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'payroll_absent_rate');

INSERT INTO system_settings (setting_key, setting_value, category, description)
SELECT 'payroll_late_30_rate', '0.5', 'payroll', 'Late <=30min deduction multiplier'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'payroll_late_30_rate');

INSERT INTO system_settings (setting_key, setting_value, category, description)
SELECT 'payroll_late_60_rate', '1', 'payroll', 'Late >30<=60min deduction multiplier'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'payroll_late_60_rate');

INSERT INTO system_settings (setting_key, setting_value, category, description)
SELECT 'payroll_late_over60_as_absent', '1', 'payroll', 'Late >60min counts as absent'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'payroll_late_over60_as_absent');

INSERT INTO system_settings (setting_key, setting_value, category, description)
SELECT 'payroll_leave_advance_days', '0', 'payroll', 'Advance leave day deduction'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'payroll_leave_advance_days');

INSERT INTO system_settings (setting_key, setting_value, category, description)
SELECT 'USE_TPHR_PAYROLL', '0', 'payroll', 'Feature flag for TP-HR payroll mode'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'USE_TPHR_PAYROLL');

INSERT INTO system_settings (setting_key, setting_value, category, description)
SELECT 'hr_late_request_cutoff_hour', '10', 'hr', 'Cutoff hour for late-start requests'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'hr_late_request_cutoff_hour');

INSERT INTO system_settings (setting_key, setting_value, category, description)
SELECT 'payroll_planned_grace_minutes', '30', 'payroll', 'Planned start grace minutes'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'payroll_planned_grace_minutes');

INSERT INTO hr_settings (`key`, `value`, type, description, category)
SELECT 'default_work_start', '08:30', 'STRING', 'Default work start', 'attendance'
WHERE NOT EXISTS (SELECT 1 FROM hr_settings WHERE `key` = 'default_work_start');

INSERT INTO hr_settings (`key`, `value`, type, description, category)
SELECT 'default_work_end', '17:30', 'STRING', 'Default work end', 'attendance'
WHERE NOT EXISTS (SELECT 1 FROM hr_settings WHERE `key` = 'default_work_end');

INSERT INTO hr_settings (`key`, `value`, type, description, category)
SELECT 'grace_period_minutes', '15', 'NUMBER', 'Grace period before late', 'attendance'
WHERE NOT EXISTS (SELECT 1 FROM hr_settings WHERE `key` = 'grace_period_minutes');
