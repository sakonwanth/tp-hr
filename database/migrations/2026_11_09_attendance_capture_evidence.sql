-- Additive metadata only. No UPDATE/backfill of historical attendance.
ALTER TABLE hr_attendances
 ADD COLUMN IF NOT EXISTS check_in_gps_captured_at DATETIME NULL,
 ADD COLUMN IF NOT EXISTS check_out_gps_captured_at DATETIME NULL,
 ADD COLUMN IF NOT EXISTS check_in_gps_accuracy DECIMAL(10,2) NULL,
 ADD COLUMN IF NOT EXISTS check_out_gps_accuracy DECIMAL(10,2) NULL,
 ADD COLUMN IF NOT EXISTS check_in_server_received_at DATETIME NULL,
 ADD COLUMN IF NOT EXISTS check_out_server_received_at DATETIME NULL,
 ALGORITHM=INSTANT, LOCK=NONE;
