-- Planned late approval workflow. Existing rows predate approval and remain effective.
ALTER TABLE hr_attendances
    ADD COLUMN IF NOT EXISTS planned_status ENUM('PENDING','APPROVED','REJECTED','CANCELLED') NULL AFTER planned_requested_by,
    ADD COLUMN IF NOT EXISTS planned_reviewed_by INT NULL AFTER planned_status,
    ADD COLUMN IF NOT EXISTS planned_reviewed_at DATETIME NULL AFTER planned_reviewed_by,
    ADD COLUMN IF NOT EXISTS planned_review_note VARCHAR(500) NULL AFTER planned_reviewed_at;

UPDATE hr_attendances
SET planned_status = 'APPROVED',
    planned_review_note = COALESCE(planned_review_note, 'Legacy record migrated as approved')
WHERE planned_start_time IS NOT NULL
  AND planned_status IS NULL;

ALTER TABLE hr_attendances
    ADD INDEX IF NOT EXISTS idx_hr_att_planned_approval (planned_status, attendance_date, user_id);
