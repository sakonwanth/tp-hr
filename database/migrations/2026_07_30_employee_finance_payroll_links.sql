-- Canonical, idempotent link between an employee-finance source and a payroll slip.
-- The table is additive: existing JSON payloads remain readable during rollout.
CREATE TABLE IF NOT EXISTS hr_employee_finance_payroll_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_type ENUM('salary_advance','employee_loan_repayment') NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    payroll_run_id INT NOT NULL,
    payroll_slip_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    link_status ENUM('included','settled','reversed') NOT NULL DEFAULT 'included',
    included_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    settled_at DATETIME NULL,
    reversed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_hr_finance_payroll_source (source_type, source_id),
    UNIQUE KEY uk_hr_finance_payroll_slip_source (payroll_slip_id, source_type, source_id),
    KEY idx_hr_finance_payroll_run (payroll_run_id, link_status),
    KEY idx_hr_finance_payroll_user (user_id, payroll_run_id),
    KEY idx_hr_finance_payroll_slip (payroll_slip_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
