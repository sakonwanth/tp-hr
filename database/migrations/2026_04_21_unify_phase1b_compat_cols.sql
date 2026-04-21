-- Option A Phase 1b — add compatibility columns needed by CRM HR module
-- (late-notification / late-excuse workflow exists in CRM but not originally in hr_attendances).
-- Backward compatible: all nullable / default 0.

ALTER TABLE hr_attendances
    ADD COLUMN late_excused       TINYINT(1)   NOT NULL DEFAULT 0 AFTER late_minutes,
    ADD COLUMN late_excused_reason TEXT        NULL AFTER late_excused,
    ADD COLUMN late_notified_at   DATETIME     NULL AFTER late_excused_reason;
