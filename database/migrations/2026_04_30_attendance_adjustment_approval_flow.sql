-- Add/fix attendance adjustment approval table used by tp-checkin and tp-hr CEO approval UI.

CREATE TABLE IF NOT EXISTS hr_attendance_adjustments (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    attendance_id BIGINT NOT NULL,
    user_id INT NOT NULL,
    adjustment_type ENUM('check_in','check_out','both') NOT NULL DEFAULT 'both',
    original_check_in DATETIME NULL,
    original_check_out DATETIME NULL,
    requested_check_in DATETIME NULL,
    requested_check_out DATETIME NULL,
    reason TEXT NOT NULL,
    document_path VARCHAR(255) NULL,
    status ENUM('PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (attendance_id) REFERENCES hr_attendances(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id),
    INDEX idx_status (status),
    INDEX idx_attendance_id (attendance_id),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='คำขอแก้ไขเวลา';

ALTER TABLE hr_attendance_adjustments
    ADD COLUMN IF NOT EXISTS adjustment_type ENUM('check_in','check_out','both') NOT NULL DEFAULT 'both' AFTER user_id,
    ADD COLUMN IF NOT EXISTS review_remarks TEXT NULL AFTER reviewed_at,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE hr_attendance_adjustments
    MODIFY COLUMN status ENUM('PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING';

CREATE INDEX IF NOT EXISTS idx_attendance_id ON hr_attendance_adjustments (attendance_id);
CREATE INDEX IF NOT EXISTS idx_att_adj_created_at ON hr_attendance_adjustments (created_at);
