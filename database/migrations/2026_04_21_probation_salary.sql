-- Add probation_salary to users table.
-- salary            = post-probation / permanent salary (also used as default)
-- probation_salary  = salary rate during probation (NULL = use salary for both periods)
-- Effective salary selection happens via getEffectiveSalary() helper based on probation_passed_date.

ALTER TABLE users
  ADD COLUMN probation_salary DECIMAL(12,2) NULL AFTER salary;
