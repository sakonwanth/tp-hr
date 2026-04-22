-- Add work_mode to users: OFFICE (ลงเวลาปกติ) / WFH (ไม่ต้องลงเวลา ระบบ stamp อัตโนมัติ)

ALTER TABLE users
  ADD COLUMN work_mode ENUM('OFFICE','WFH') NOT NULL DEFAULT 'OFFICE' AFTER employment_type,
  ADD INDEX idx_work_mode (work_mode);
