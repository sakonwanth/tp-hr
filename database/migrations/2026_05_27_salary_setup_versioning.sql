-- Salary setup versioning (shared tp_crm schema)
DELETE e1 FROM employee_salary_setup e1
INNER JOIN employee_salary_setup e2
  ON e1.user_id = e2.user_id
 AND e1.effective_from = e2.effective_from
 AND e1.id < e2.id;

UPDATE employee_salary_setup ess
INNER JOIN (
    SELECT e1.id,
           (SELECT MIN(e2.effective_from)
              FROM employee_salary_setup e2
             WHERE e2.user_id = e1.user_id
               AND e2.effective_from > e1.effective_from) AS next_from
    FROM employee_salary_setup e1
) calc ON calc.id = ess.id
SET ess.effective_to = DATE_SUB(calc.next_from, INTERVAL 1 DAY)
WHERE calc.next_from IS NOT NULL
  AND (ess.effective_to IS NULL OR ess.effective_to >= calc.next_from);

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'employee_salary_setup'
      AND index_name = 'uk_user_effective_from'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE employee_salary_setup ADD UNIQUE KEY uk_user_effective_from (user_id, effective_from)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
