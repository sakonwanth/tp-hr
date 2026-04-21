-- Unification migration: Option A (Phase 1 — data backfill only)
-- Backfill attendance_logs → hr_attendances. Idempotent via uk_user_date.
-- NOTE: legacy tables are NOT renamed here. Rename is in phase-2 migration
--       after CRM code paths are updated to read/write hr_* tables.

INSERT IGNORE INTO hr_attendances
    (user_id, attendance_date,
     check_in_time, check_in_type, check_in_latitude, check_in_longitude,
     check_out_time, check_out_type,
     break_minutes, work_minutes, late_minutes,
     status, remarks, created_at, updated_at)
SELECT
    al.user_id,
    al.attendance_date,
    CASE WHEN al.check_in_time IS NOT NULL
         THEN TIMESTAMP(al.attendance_date, al.check_in_time) ELSE NULL END,
    'MANUAL',
    al.check_in_lat, al.check_in_lng,
    CASE WHEN al.check_out_time IS NOT NULL
         THEN TIMESTAMP(al.attendance_date, al.check_out_time) ELSE NULL END,
    CASE WHEN al.check_out_time IS NOT NULL THEN 'MANUAL' ELSE NULL END,
    al.break_minutes,
    CAST(al.work_hours * 60 AS SIGNED),
    al.late_minutes,
    CASE al.attendance_type
        WHEN 'present'  THEN CASE WHEN al.is_late=1 AND al.late_excused=0 THEN 'LATE' ELSE 'PRESENT' END
        WHEN 'absent'   THEN 'ABSENT'
        WHEN 'on_leave' THEN 'LEAVE'
        WHEN 'holiday'  THEN 'HOLIDAY'
        WHEN 'weekend'  THEN 'HOLIDAY'
        ELSE 'PRESENT'
    END,
    CONCAT_WS(' | ',
        NULLIF(al.check_in_notes,''),
        NULLIF(al.notes,''),
        NULLIF(al.admin_notes,''),
        CASE WHEN al.late_excused=1
             THEN CONCAT('Late-excused: ', COALESCE(al.late_excused_reason,'')) END,
        '[Migrated from attendance_logs]'),
    al.created_at,
    al.created_at
FROM attendance_logs al
WHERE NOT EXISTS (
    SELECT 1 FROM hr_attendances ha
    WHERE ha.user_id = al.user_id AND ha.attendance_date = al.attendance_date
);

