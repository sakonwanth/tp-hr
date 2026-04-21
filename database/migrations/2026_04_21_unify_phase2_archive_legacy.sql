-- Option A Phase 2 — archive legacy tables
-- Run only AFTER all CRM/HR code paths use hr_* tables (verified 2026-04-21 via grep).
-- Reversible: RENAME TABLE is instant; data preserved. To rollback: rename back.

RENAME TABLE attendance_logs            TO attendance_logs_legacy;
RENAME TABLE leave_requests             TO leave_requests_legacy;
RENAME TABLE leave_types                TO leave_types_legacy;
RENAME TABLE leave_balances             TO leave_balances_legacy;
RENAME TABLE attendance_monthly_summary TO attendance_monthly_summary_legacy;
