ALTER TABLE hr_salary_advances
    MODIFY COLUMN disbursement_method ENUM('transfer','cash','cheque','payroll') NOT NULL DEFAULT 'transfer';

ALTER TABLE hr_employee_loans
    MODIFY COLUMN disbursement_method ENUM('transfer','cash','cheque','payroll') NOT NULL DEFAULT 'transfer';
