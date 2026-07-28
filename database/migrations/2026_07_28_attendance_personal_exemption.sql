-- Per-person attendance exemption. Role-based CEO/Chairman exemption remains canonical
-- in TpCommon\Hr\AttendanceScope; this flag covers approved individual exceptions.
ALTER TABLE users
    ADD COLUMN attendance_exempt TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = exempt from check-in/out and absence deductions'
    AFTER is_employee;

UPDATE users
SET attendance_exempt = 1
WHERE TRIM(COALESCE(first_name_th, '')) = 'เข็มทอง'
  AND TRIM(COALESCE(last_name_th, '')) = 'บำรุงจิตร';
