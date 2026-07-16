-- Prevent concurrent duplicate pending offsite requests while retaining full history.
ALTER TABLE hr_attendance_outside_requests
    ADD COLUMN IF NOT EXISTS pending_request_key VARCHAR(96)
    GENERATED ALWAYS AS (
        CASE WHEN status = 'PENDING'
             THEN CONCAT(user_id, ':', request_date, ':', request_type)
             ELSE NULL END
    ) STORED;

CREATE UNIQUE INDEX IF NOT EXISTS uk_outside_pending_request
    ON hr_attendance_outside_requests (pending_request_key);
