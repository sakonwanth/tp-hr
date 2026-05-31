-- Per-employee approval to work on a company holiday with optional compensation day off.
-- Writer: tp-hr | Readers: WorkdayCalculator (tp-common), payroll, check-in, CRM cron

CREATE TABLE IF NOT EXISTS hr_holiday_work_exceptions (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    holiday_date DATE NOT NULL COMMENT 'Company holiday the employee must work',
    comp_date DATE NULL COMMENT 'Compensation day off in lieu of working the holiday',
    holiday_name VARCHAR(255) NULL COMMENT 'Snapshot of holiday name at request time',
    reason TEXT NULL,
    status ENUM('PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id),
    UNIQUE KEY uk_user_holiday (user_id, holiday_date),
    INDEX idx_status (status),
    INDEX idx_holiday_date (holiday_date),
    INDEX idx_comp_date (comp_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
