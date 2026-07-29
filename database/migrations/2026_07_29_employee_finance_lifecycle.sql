-- Employee finance lifecycle: additive and backward-compatible.
ALTER TABLE hr_employee_loans
    ADD COLUMN IF NOT EXISTS first_due_month CHAR(7) NULL AFTER term_months,
    ADD COLUMN IF NOT EXISTS repayment_method ENUM('payroll','transfer') NOT NULL DEFAULT 'payroll' AFTER first_due_month,
    ADD COLUMN IF NOT EXISTS disbursement_method ENUM('transfer','payroll') NOT NULL DEFAULT 'transfer' AFTER repayment_method,
    ADD COLUMN IF NOT EXISTS consent_version VARCHAR(40) NULL AFTER disbursement_method,
    ADD COLUMN IF NOT EXISTS consented_at DATETIME NULL AFTER consent_version,
    ADD COLUMN IF NOT EXISTS schedule_snapshot_json LONGTEXT NULL AFTER consented_at;

ALTER TABLE hr_salary_advances
    ADD COLUMN IF NOT EXISTS deduction_month CHAR(7) NULL AFTER advance_for_month,
    ADD COLUMN IF NOT EXISTS repayment_method ENUM('payroll','transfer') NOT NULL DEFAULT 'payroll' AFTER deduction_month,
    ADD COLUMN IF NOT EXISTS disbursement_method ENUM('transfer','payroll') NOT NULL DEFAULT 'transfer' AFTER repayment_method,
    ADD COLUMN IF NOT EXISTS consent_version VARCHAR(40) NULL AFTER disbursement_method,
    ADD COLUMN IF NOT EXISTS consented_at DATETIME NULL AFTER consent_version;

ALTER TABLE hr_loan_repayments
    ADD COLUMN IF NOT EXISTS installment_no INT NULL AFTER loan_id,
    ADD UNIQUE KEY IF NOT EXISTS uk_hr_loan_installment (loan_id, installment_no);

CREATE TABLE IF NOT EXISTS hr_employee_finance_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    finance_type ENUM('salary_advance','employee_loan') NOT NULL,
    finance_id INT UNSIGNED NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    actor_user_id INT NULL,
    payload_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hr_finance_audit_entity (finance_type, finance_id),
    INDEX idx_hr_finance_audit_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
