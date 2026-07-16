-- Track offsite approval independently for check-in and check-out.
ALTER TABLE hr_attendances
    ADD COLUMN IF NOT EXISTS check_in_outside_status ENUM('PENDING','APPROVED','REJECTED') NULL AFTER offsite_status,
    ADD COLUMN IF NOT EXISTS check_outside_status ENUM('PENDING','APPROVED','REJECTED') NULL AFTER check_in_outside_status;

UPDATE hr_attendances
SET check_in_outside_status = COALESCE(
    (SELECT o.status FROM hr_attendance_outside_requests o
     WHERE o.attendance_id=hr_attendances.id AND o.request_type='CHECK_IN'
       AND o.status IN ('PENDING','APPROVED','REJECTED')
     ORDER BY o.id DESC LIMIT 1),
    offsite_status
)
WHERE is_offsite = 1
  AND check_in_outside_status IS NULL;

UPDATE hr_attendances
SET check_outside_status = COALESCE(
    (SELECT o.status FROM hr_attendance_outside_requests o
     WHERE o.attendance_id=hr_attendances.id AND o.request_type='CHECK_OUT'
       AND o.status IN ('PENDING','APPROVED','REJECTED')
     ORDER BY o.id DESC LIMIT 1),
    offsite_status
)
WHERE is_offsite = 1
  AND check_outside_status IS NULL;
