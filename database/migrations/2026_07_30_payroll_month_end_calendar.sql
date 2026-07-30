-- Canonical payroll calendar: pay at month end; when month end is Sunday, pay one day earlier.
INSERT INTO system_settings (`key`, `value`, category, description)
VALUES ('payroll_default_pay_day', '31', 'HR', 'จ่ายเงินเดือนวันสิ้นเดือน หากตรงวันอาทิตย์ให้จ่ายก่อนหนึ่งวัน')
ON DUPLICATE KEY UPDATE value = VALUES(value), description = VALUES(description);

UPDATE payroll_runs
SET pay_day = DAY(
    CASE WHEN DAYOFWEEK(LAST_DAY(payroll_month)) = 1
         THEN DATE_SUB(LAST_DAY(payroll_month), INTERVAL 1 DAY)
         ELSE LAST_DAY(payroll_month)
    END
)
WHERE status <> 'paid';

UPDATE hr_loan_repayments
SET due_date = CASE WHEN DAYOFWEEK(LAST_DAY(due_date)) = 1
                    THEN DATE_SUB(LAST_DAY(due_date), INTERVAL 1 DAY)
                    ELSE LAST_DAY(due_date)
               END
WHERE status = 'scheduled';
