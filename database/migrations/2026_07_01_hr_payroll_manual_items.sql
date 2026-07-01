-- Per-employee, per-month manual payroll adjustments that have no formula (entered by HR each month).
-- Writer: hr/salary_report.php | Readers: hr/salary_report.php (report + xlsx export)

CREATE TABLE IF NOT EXISTS hr_payroll_manual_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    period_month DATE NOT NULL COMMENT 'First day of the payroll month, e.g. 2026-05-01',
    admin_fee DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'ค่าบริหาร',
    holiday_compensation DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'ชดเชยวันหยุด',
    student_loan_deduction DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'กยศ.',
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id),
    UNIQUE KEY uk_user_month (user_id, period_month),
    INDEX idx_period_month (period_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
