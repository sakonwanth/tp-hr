-- Phase 1c (TP-EXPENSE v2 Spec): HR loans + salary advances + repayment plan
--
-- Per Spec v1.0 Q18/Q20/Q21:
--   - hr_employee_loans   : fixed 15% interest, no upper cap (gated by
--                            approval policy), auto-deducted monthly.
--   - hr_salary_advances  : capped at 40% of base_salary at request time.
--                            Anything above forces sub_type=employee_loan.
--   - hr_loan_repayments  : schedule of monthly deductions, linked to a
--                            payroll_runs row when the deduction actually
--                            happens.
--
-- Approval policy enforcement (40% cap, interest %) lives in app code so it
-- can be tuned without schema migration. Defaults below are guard-rails.

CREATE TABLE IF NOT EXISTS `hr_employee_loans` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `expense_request_id` INT NULL,
    `principal_amount` DECIMAL(12,2) NOT NULL,
    `interest_rate_pct` DECIMAL(5,2) NOT NULL DEFAULT 15.00,
    `term_months` INT NOT NULL DEFAULT 1,
    `monthly_installment` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `total_payable` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `status` ENUM('pending_disbursement','active','closed','defaulted','cancelled')
        NOT NULL DEFAULT 'pending_disbursement',
    `started_at` DATE NULL,
    `closed_at` DATE NULL,
    `notes` TEXT NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_employee_loan_user` (`user_id`),
    INDEX `idx_employee_loan_status` (`status`),
    INDEX `idx_employee_loan_expense` (`expense_request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hr_salary_advances` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `expense_request_id` INT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `advance_for_month` CHAR(7) NOT NULL COMMENT 'YYYY-MM of payroll period this advance maps to',
    `base_salary_at_request` DECIMAL(12,2) NOT NULL,
    `pct_of_base` DECIMAL(5,2) NOT NULL COMMENT '40% cap enforced in app layer',
    `status` ENUM('pending_disbursement','pending_deduction','deducted','cancelled')
        NOT NULL DEFAULT 'pending_disbursement',
    `payroll_run_id` INT NULL COMMENT 'payroll_runs.id where the deduction landed',
    `notes` TEXT NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_salary_advance_user` (`user_id`),
    INDEX `idx_salary_advance_status` (`status`),
    INDEX `idx_salary_advance_month` (`advance_for_month`),
    INDEX `idx_salary_advance_expense` (`expense_request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hr_loan_repayments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_id` INT UNSIGNED NOT NULL,
    `due_date` DATE NOT NULL,
    `due_amount` DECIMAL(12,2) NOT NULL,
    `principal_portion` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `interest_portion` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `paid_amount` DECIMAL(12,2) NULL,
    `paid_at` DATETIME NULL,
    `payroll_run_id` INT NULL,
    `status` ENUM('scheduled','paid','missed','partial','waived') NOT NULL DEFAULT 'scheduled',
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_loan_repayment_loan` (`loan_id`),
    INDEX `idx_loan_repayment_due` (`due_date`),
    INDEX `idx_loan_repayment_status` (`status`),
    INDEX `idx_loan_repayment_payroll` (`payroll_run_id`),
    FOREIGN KEY (`loan_id`) REFERENCES `hr_employee_loans`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
