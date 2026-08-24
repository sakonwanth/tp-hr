-- Employee-finance repayments received outside payroll.
-- HR owns the receivable balance; ERP consumes the accounting outbox.

ALTER TABLE hr_salary_advances
    MODIFY COLUMN repayment_method ENUM('payroll','transfer','cash') NOT NULL DEFAULT 'payroll',
    MODIFY COLUMN status ENUM('pending_disbursement','pending_deduction','deducted','partial','repaid','cancelled')
        NOT NULL DEFAULT 'pending_disbursement';

ALTER TABLE hr_employee_loans
    MODIFY COLUMN repayment_method ENUM('payroll','transfer','cash') NOT NULL DEFAULT 'payroll';

CREATE TABLE IF NOT EXISTS hr_employee_finance_receipt_sequences (
    receipt_year SMALLINT UNSIGNED PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_employee_finance_repayments_received (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_number VARCHAR(30) NOT NULL,
    finance_type ENUM('salary_advance','employee_loan') NOT NULL,
    finance_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method ENUM('cash','transfer') NOT NULL,
    received_at DATETIME NOT NULL,
    received_by INT NOT NULL,
    reference_number VARCHAR(100) NULL,
    evidence_path VARCHAR(500) NULL,
    notes VARCHAR(1000) NULL,
    idempotency_key CHAR(64) NOT NULL,
    status ENUM('posted','void') NOT NULL DEFAULT 'posted',
    voided_at DATETIME NULL,
    voided_by INT NULL,
    void_reason VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_hr_finance_receipt_number (receipt_number),
    UNIQUE KEY uk_hr_finance_repayment_idempotency (idempotency_key),
    INDEX idx_hr_finance_repayment_entity (finance_type, finance_id, status),
    INDEX idx_hr_finance_repayment_user (user_id, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_employee_finance_repayment_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    repayment_received_id BIGINT UNSIGNED NOT NULL,
    loan_repayment_id INT UNSIGNED NULL,
    allocated_amount DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_hr_finance_repayment_allocation (repayment_received_id, loan_repayment_id),
    INDEX idx_hr_finance_allocation_installment (loan_repayment_id),
    CONSTRAINT fk_hr_finance_allocation_receipt
        FOREIGN KEY (repayment_received_id) REFERENCES hr_employee_finance_repayments_received(id),
    CONSTRAINT fk_hr_finance_allocation_installment
        FOREIGN KEY (loan_repayment_id) REFERENCES hr_loan_repayments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_employee_finance_accounting_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    repayment_received_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(50) NOT NULL DEFAULT 'employee_finance_repayment_received',
    payload_json LONGTEXT NOT NULL,
    status ENUM('pending','processing','processed','failed') NOT NULL DEFAULT 'pending',
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    erp_transaction_id BIGINT UNSIGNED NULL,
    last_error VARCHAR(1000) NULL,
    available_at DATETIME NOT NULL,
    processed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_hr_finance_accounting_receipt (repayment_received_id),
    INDEX idx_hr_finance_accounting_queue (status, available_at),
    CONSTRAINT fk_hr_finance_accounting_receipt
        FOREIGN KEY (repayment_received_id) REFERENCES hr_employee_finance_repayments_received(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
