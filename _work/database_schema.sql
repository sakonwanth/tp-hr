-- =============================================
-- TP-HR Database Schema
-- ระบบบริหารทรัพยากรบุคคล
-- Created: 2026-04-06
-- =============================================

-- ใช้ฐานข้อมูลเดียวกับ TP-CRM
USE tp_crm;

-- =============================================
-- 1. ตารางโครงสร้างองค์กร
-- =============================================

-- ตารางแผนก
CREATE TABLE IF NOT EXISTS hr_departments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    name_en VARCHAR(100),
    parent_id INT NULL,
    manager_id INT NULL,
    description TEXT,
    cost_center VARCHAR(50),
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES hr_departments(id) ON DELETE SET NULL,
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_parent (parent_id),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='แผนก/ฝ่าย';

-- ตารางตำแหน่งงาน
CREATE TABLE IF NOT EXISTS hr_positions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    title VARCHAR(100) NOT NULL,
    title_en VARCHAR(100),
    department_id INT,
    level INT DEFAULT 1 COMMENT '1=Staff, 2=Senior, 3=Lead, 4=Manager, 5=Director, 6=Executive',
    min_salary DECIMAL(12,2),
    max_salary DECIMAL(12,2),
    job_description TEXT,
    requirements TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES hr_departments(id) ON DELETE SET NULL,
    INDEX idx_department (department_id),
    INDEX idx_level (level),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ตำแหน่งงาน';

-- =============================================
-- 2. ตารางกะทำงานและสถานที่
-- =============================================

-- ตารางกะทำงาน
CREATE TABLE IF NOT EXISTS hr_work_shifts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    break_start TIME,
    break_end TIME,
    break_minutes INT DEFAULT 60,
    work_hours_per_day DECIMAL(4,2) DEFAULT 8.00,
    grace_period_minutes INT DEFAULT 15 COMMENT 'ผ่อนผันมาสาย (นาที)',
    is_overnight BOOLEAN DEFAULT FALSE COMMENT 'ข้ามวัน',
    is_flexible BOOLEAN DEFAULT FALSE COMMENT 'เวลายืดหยุ่น',
    is_default BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='กะทำงาน';

-- ตารางจุดลงเวลา
CREATE TABLE IF NOT EXISTS hr_checkin_locations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) UNIQUE,
    address TEXT,
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    radius_meters INT DEFAULT 100 COMMENT 'รัศมีที่อนุญาต',
    wifi_ssid VARCHAR(100) COMMENT 'ชื่อ WiFi',
    wifi_bssid VARCHAR(50) COMMENT 'MAC Address WiFi',
    qr_code VARCHAR(255),
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='สถานที่ลงเวลา';

-- =============================================
-- 3. ตารางลงเวลาเข้า-ออก
-- =============================================

-- ตารางลงเวลาเข้า-ออก
CREATE TABLE IF NOT EXISTS hr_attendances (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    shift_id INT,
    
    -- เวลาเข้า
    check_in_time DATETIME,
    check_in_type ENUM('GPS','WIFI','QR','FACE','FINGERPRINT','MANUAL') DEFAULT 'GPS',
    check_in_latitude DECIMAL(10,8),
    check_in_longitude DECIMAL(11,8),
    check_in_location_id INT,
    check_in_photo VARCHAR(255),
    check_in_device_info TEXT,
    check_in_ip VARCHAR(45),
    
    -- เวลาออก
    check_out_time DATETIME,
    check_out_type ENUM('GPS','WIFI','QR','FACE','FINGERPRINT','MANUAL'),
    check_out_latitude DECIMAL(10,8),
    check_out_longitude DECIMAL(11,8),
    check_out_location_id INT,
    check_out_photo VARCHAR(255),
    check_out_device_info TEXT,
    check_out_ip VARCHAR(45),
    
    -- สรุป
    work_minutes INT DEFAULT 0,
    break_minutes INT DEFAULT 0,
    late_minutes INT DEFAULT 0,
    early_leave_minutes INT DEFAULT 0,
    ot_minutes INT DEFAULT 0,
    
    status ENUM('PENDING','PRESENT','LATE','ABSENT','LEAVE','HOLIDAY','HALF_DAY','WFH') DEFAULT 'PENDING',
    
    remarks TEXT,
    adjusted_by INT,
    adjusted_at DATETIME,
    adjustment_reason TEXT,
    approved_by INT,
    approved_at DATETIME,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (shift_id) REFERENCES hr_work_shifts(id),
    FOREIGN KEY (check_in_location_id) REFERENCES hr_checkin_locations(id),
    FOREIGN KEY (check_out_location_id) REFERENCES hr_checkin_locations(id),
    FOREIGN KEY (adjusted_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    
    UNIQUE KEY uk_user_date (user_id, attendance_date),
    INDEX idx_attendance_date (attendance_date),
    INDEX idx_status (status),
    INDEX idx_user_month (user_id, attendance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='บันทึกการลงเวลา';

-- ตารางคำขอแก้ไขเวลา
CREATE TABLE IF NOT EXISTS hr_attendance_adjustments (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    attendance_id BIGINT NOT NULL,
    user_id INT NOT NULL,
    
    original_check_in DATETIME,
    original_check_out DATETIME,
    requested_check_in DATETIME,
    requested_check_out DATETIME,
    
    reason TEXT NOT NULL,
    document_path VARCHAR(255),
    
    status ENUM('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING',
    reviewed_by INT,
    reviewed_at DATETIME,
    review_remarks TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (attendance_id) REFERENCES hr_attendances(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id),
    INDEX idx_status (status),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='คำขอแก้ไขเวลา';

-- ตารางคำขอลงเวลานอกสถานที่ (รออนุมัติก่อนบันทึกเวลา)
CREATE TABLE IF NOT EXISTS hr_attendance_outside_requests (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    attendance_id BIGINT NULL,

    request_type ENUM('CHECK_IN','CHECK_OUT') NOT NULL,
    request_date DATE NOT NULL,
    request_time DATETIME NOT NULL,

    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    photo_path VARCHAR(255),
    reason TEXT NOT NULL,

    status ENUM('PENDING','APPROVED','REJECTED','CANCELLED') DEFAULT 'PENDING',
    reviewed_by INT,
    reviewed_at DATETIME,
    review_remarks TEXT,

    request_ip VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (attendance_id) REFERENCES hr_attendances(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id),
    INDEX idx_status (status),
    INDEX idx_user_type_date (user_id, request_type, request_date),
    INDEX idx_attendance_id (attendance_id),
    INDEX idx_request_time (request_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='คำขอลงเวลานอกสถานที่';

-- =============================================
-- 4. ตารางการลา
-- =============================================

-- ตารางประเภทการลา
CREATE TABLE IF NOT EXISTS hr_leave_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    name_en VARCHAR(100),
    description TEXT,
    color VARCHAR(7) DEFAULT '#6B7280' COMMENT 'สีสำหรับแสดงในปฏิทิน',
    icon VARCHAR(50) DEFAULT 'calendar',
    
    -- เงื่อนไข
    default_days_per_year DECIMAL(5,2) DEFAULT 0,
    is_paid BOOLEAN DEFAULT TRUE,
    is_accumulative BOOLEAN DEFAULT FALSE COMMENT 'สะสมได้หรือไม่',
    max_accumulative_days DECIMAL(5,2) COMMENT 'สะสมได้สูงสุด',
    requires_document BOOLEAN DEFAULT FALSE,
    document_after_days INT COMMENT 'ต้องมีใบรับรองแพทย์ถ้าลาเกินกี่วัน',
    min_days_advance INT DEFAULT 0 COMMENT 'ต้องขอล่วงหน้ากี่วัน',
    max_consecutive_days INT COMMENT 'ลาติดต่อกันได้สูงสุดกี่วัน',
    gender_restriction ENUM('MALE','FEMALE','ALL') DEFAULT 'ALL',
    min_months_employed INT DEFAULT 0 COMMENT 'ต้องทำงานมาอย่างน้อยกี่เดือน',
    
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ประเภทการลา';

-- ตารางสิทธิ์การลาของพนักงาน
CREATE TABLE IF NOT EXISTS hr_leave_entitlements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    leave_type_id INT NOT NULL,
    year INT NOT NULL,
    entitled_days DECIMAL(5,2) NOT NULL DEFAULT 0,
    carried_over_days DECIMAL(5,2) DEFAULT 0,
    additional_days DECIMAL(5,2) DEFAULT 0 COMMENT 'วันเพิ่มพิเศษ',
    used_days DECIMAL(5,2) DEFAULT 0,
    pending_days DECIMAL(5,2) DEFAULT 0,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (leave_type_id) REFERENCES hr_leave_types(id),
    UNIQUE KEY uk_user_type_year (user_id, leave_type_id, year),
    INDEX idx_year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='สิทธิ์การลาของพนักงาน';

-- ตารางคำขอลา
CREATE TABLE IF NOT EXISTS hr_leave_requests (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    request_number VARCHAR(20) UNIQUE COMMENT 'เลขที่คำขอ',
    user_id INT NOT NULL,
    leave_type_id INT NOT NULL,
    
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    start_period ENUM('FULL','AM','PM') DEFAULT 'FULL',
    end_period ENUM('FULL','AM','PM') DEFAULT 'FULL',
    total_days DECIMAL(5,2) NOT NULL,
    
    reason TEXT,
    contact_number VARCHAR(20) COMMENT 'เบอร์ติดต่อระหว่างลา',
    document_path VARCHAR(255),
    
    status ENUM('DRAFT','PENDING','APPROVED','REJECTED','CANCELLED') DEFAULT 'PENDING',
    
    -- Level 1: หัวหน้าโดยตรง
    approver_1_id INT,
    approver_1_status ENUM('PENDING','APPROVED','REJECTED'),
    approver_1_date DATETIME,
    approver_1_remarks TEXT,
    
    -- Level 2: Manager/HR
    approver_2_id INT,
    approver_2_status ENUM('PENDING','APPROVED','REJECTED'),
    approver_2_date DATETIME,
    approver_2_remarks TEXT,
    
    -- Level 3: Director (ถ้าจำเป็น)
    approver_3_id INT,
    approver_3_status ENUM('PENDING','APPROVED','REJECTED'),
    approver_3_date DATETIME,
    approver_3_remarks TEXT,
    
    final_approved_by INT,
    final_approved_at DATETIME,
    
    cancelled_by INT,
    cancelled_at DATETIME,
    cancel_reason TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (leave_type_id) REFERENCES hr_leave_types(id),
    FOREIGN KEY (approver_1_id) REFERENCES users(id),
    FOREIGN KEY (approver_2_id) REFERENCES users(id),
    FOREIGN KEY (approver_3_id) REFERENCES users(id),
    FOREIGN KEY (final_approved_by) REFERENCES users(id),
    FOREIGN KEY (cancelled_by) REFERENCES users(id),
    
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_dates (start_date, end_date),
    INDEX idx_year_month (start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='คำขอลา';

-- =============================================
-- 5. ตารางเอกสารและใบรับรอง
-- =============================================

-- ตารางเทมเพลตเอกสาร
CREATE TABLE IF NOT EXISTS hr_document_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    name_en VARCHAR(100),
    category ENUM('CERTIFICATE','CONTRACT','LETTER','FORM','OTHER') DEFAULT 'CERTIFICATE',
    description TEXT,
    
    template_th LONGTEXT COMMENT 'HTML template ภาษาไทย',
    template_en LONGTEXT COMMENT 'HTML template ภาษาอังกฤษ',
    variables JSON COMMENT 'ตัวแปรที่ใช้ในเอกสาร',
    
    requires_approval BOOLEAN DEFAULT FALSE,
    approval_roles JSON COMMENT 'roles ที่อนุมัติได้',
    auto_generate BOOLEAN DEFAULT FALSE COMMENT 'สร้างอัตโนมัติ',
    processing_days INT DEFAULT 3 COMMENT 'วันทำการที่ใช้',
    
    header_logo VARCHAR(255),
    footer_text TEXT,
    signatory_name VARCHAR(100),
    signatory_position VARCHAR(100),
    
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='เทมเพลตเอกสาร';

-- ตารางคำขอเอกสาร
CREATE TABLE IF NOT EXISTS hr_document_requests (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    request_number VARCHAR(20) UNIQUE COMMENT 'เลขที่คำขอ',
    user_id INT NOT NULL,
    template_id INT NOT NULL,
    
    purpose TEXT COMMENT 'วัตถุประสงค์',
    purpose_detail TEXT,
    recipient VARCHAR(200) COMMENT 'ส่งถึง',
    language ENUM('TH','EN','BOTH') DEFAULT 'TH',
    copies INT DEFAULT 1,
    
    -- ข้อมูลเพิ่มเติมที่ต้องการ
    additional_data JSON,
    
    status ENUM('PENDING','PROCESSING','READY','DELIVERED','CANCELLED','REJECTED') DEFAULT 'PENDING',
    
    assigned_to INT COMMENT 'HR ที่รับผิดชอบ',
    processed_by INT,
    processed_at DATETIME,
    
    document_number VARCHAR(50) COMMENT 'เลขที่เอกสาร',
    document_date DATE COMMENT 'วันที่ออกเอกสาร',
    document_path VARCHAR(255) COMMENT 'ไฟล์ PDF ที่สร้าง',
    qr_verification_code VARCHAR(100),
    
    expected_date DATE COMMENT 'วันที่คาดว่าจะได้รับ',
    pickup_date DATE,
    delivered_at DATETIME,
    delivery_method ENUM('PICKUP','EMAIL','POST') DEFAULT 'PICKUP',
    
    rejection_reason TEXT,
    remarks TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (template_id) REFERENCES hr_document_templates(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    FOREIGN KEY (processed_by) REFERENCES users(id),
    
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='คำขอเอกสาร';

-- ตารางเอกสารที่ออกให้
CREATE TABLE IF NOT EXISTS hr_issued_documents (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    request_id BIGINT,
    user_id INT NOT NULL,
    template_id INT NOT NULL,
    
    document_number VARCHAR(50) UNIQUE NOT NULL,
    document_date DATE NOT NULL,
    document_path VARCHAR(255) NOT NULL,
    
    qr_code VARCHAR(255),
    verification_code VARCHAR(100) UNIQUE,
    
    issued_by INT NOT NULL,
    
    download_count INT DEFAULT 0,
    last_downloaded_at DATETIME,
    
    expires_at DATE COMMENT 'วันหมดอายุ',
    is_revoked BOOLEAN DEFAULT FALSE,
    revoked_by INT,
    revoked_at DATETIME,
    revoke_reason TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (request_id) REFERENCES hr_document_requests(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (template_id) REFERENCES hr_document_templates(id),
    FOREIGN KEY (issued_by) REFERENCES users(id),
    FOREIGN KEY (revoked_by) REFERENCES users(id),
    
    INDEX idx_user_id (user_id),
    INDEX idx_verification_code (verification_code),
    INDEX idx_document_date (document_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='เอกสารที่ออกให้';

-- =============================================
-- 6. ตารางข้อมูลเพิ่มเติมพนักงาน
-- =============================================

-- ตารางข้อมูลครอบครัว
CREATE TABLE IF NOT EXISTS hr_employee_family (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    relationship ENUM('SPOUSE','CHILD','FATHER','MOTHER','SIBLING','OTHER') NOT NULL,
    name VARCHAR(100) NOT NULL,
    id_card_number VARCHAR(255),
    birth_date DATE,
    occupation VARCHAR(100),
    phone VARCHAR(20),
    is_dependent BOOLEAN DEFAULT FALSE COMMENT 'เป็นผู้อยู่ในความอุปการะ',
    is_emergency_contact BOOLEAN DEFAULT FALSE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ข้อมูลครอบครัว';

-- ตารางผู้ติดต่อฉุกเฉิน
CREATE TABLE IF NOT EXISTS hr_emergency_contacts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    relationship VARCHAR(50),
    phone VARCHAR(20) NOT NULL,
    phone_2 VARCHAR(20),
    address TEXT,
    is_primary BOOLEAN DEFAULT FALSE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ผู้ติดต่อฉุกเฉิน';

-- ตารางประวัติการศึกษา
CREATE TABLE IF NOT EXISTS hr_employee_education (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    level ENUM('PRIMARY','SECONDARY','HIGH_SCHOOL','DIPLOMA','BACHELOR','MASTER','DOCTORATE','OTHER') NOT NULL,
    institution VARCHAR(200) NOT NULL,
    faculty VARCHAR(100),
    major VARCHAR(100),
    graduation_year INT,
    gpa DECIMAL(3,2),
    certificate_path VARCHAR(255),
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ประวัติการศึกษา';

-- ตารางประวัติการทำงาน
CREATE TABLE IF NOT EXISTS hr_employee_work_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    company_name VARCHAR(200) NOT NULL,
    position VARCHAR(100),
    start_date DATE,
    end_date DATE,
    last_salary DECIMAL(12,2),
    responsibilities TEXT,
    reason_for_leaving TEXT,
    reference_name VARCHAR(100),
    reference_phone VARCHAR(20),
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ประวัติการทำงาน';

-- =============================================
-- 7. ตารางวันหยุดและประกาศ
-- =============================================

-- ตารางวันหยุดประจำปี
CREATE TABLE IF NOT EXISTS hr_holidays (
    id INT PRIMARY KEY AUTO_INCREMENT,
    date DATE NOT NULL,
    name VARCHAR(100) NOT NULL,
    name_en VARCHAR(100),
    type ENUM('PUBLIC','COMPANY','SPECIAL','SUBSTITUTE') DEFAULT 'PUBLIC',
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id),
    UNIQUE KEY uk_date (date),
    INDEX idx_year (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='วันหยุดประจำปี';

-- ตารางประกาศ
CREATE TABLE IF NOT EXISTS hr_announcements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    content LONGTEXT,
    excerpt TEXT COMMENT 'เนื้อหาย่อ',
    type ENUM('GENERAL','POLICY','URGENT','EVENT','TRAINING') DEFAULT 'GENERAL',
    
    target_type ENUM('ALL','DEPARTMENT','POSITION','SPECIFIC') DEFAULT 'ALL',
    target_departments JSON COMMENT 'แผนกที่เห็น',
    target_positions JSON COMMENT 'ตำแหน่งที่เห็น',
    target_users JSON COMMENT 'user ids ที่เห็น',
    
    publish_date DATETIME,
    expire_date DATETIME,
    is_pinned BOOLEAN DEFAULT FALSE,
    requires_acknowledgement BOOLEAN DEFAULT FALSE,
    
    attachment_path VARCHAR(255),
    cover_image VARCHAR(255),
    
    view_count INT DEFAULT 0,
    created_by INT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_publish_date (publish_date),
    INDEX idx_is_active (is_active),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ประกาศ';

-- ตารางการรับทราบประกาศ
CREATE TABLE IF NOT EXISTS hr_announcement_acknowledgements (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    announcement_id INT NOT NULL,
    user_id INT NOT NULL,
    acknowledged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    
    FOREIGN KEY (announcement_id) REFERENCES hr_announcements(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY uk_announcement_user (announcement_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='การรับทราบประกาศ';

-- =============================================
-- 8. ตารางตั้งค่าและ Audit
-- =============================================

-- ตารางตั้งค่าระบบ HR
CREATE TABLE IF NOT EXISTS hr_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    `key` VARCHAR(100) UNIQUE NOT NULL,
    `value` TEXT,
    type ENUM('STRING','NUMBER','BOOLEAN','JSON') DEFAULT 'STRING',
    description TEXT,
    category VARCHAR(50),
    updated_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (updated_by) REFERENCES users(id),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ตั้งค่าระบบ HR';

-- ตาราง Audit Log
CREATE TABLE IF NOT EXISTS hr_audit_logs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(50) NOT NULL COMMENT 'CREATE, UPDATE, DELETE, LOGIN, CHECKOUT, etc.',
    module VARCHAR(50) COMMENT 'attendance, leave, document, etc.',
    table_name VARCHAR(100),
    record_id BIGINT,
    old_values JSON,
    new_values JSON,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_module (module),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit Log';

-- =============================================
-- 9. Views สำหรับรายงาน
-- =============================================

-- View: สรุปการลงเวลารายเดือน
CREATE OR REPLACE VIEW v_monthly_attendance_summary AS
SELECT 
    a.user_id,
    u.first_name_th,
    u.last_name_th,
    u.department,
    DATE_FORMAT(a.attendance_date, '%Y-%m') AS month,
    COUNT(*) AS total_days,
    SUM(CASE WHEN a.status = 'PRESENT' THEN 1 ELSE 0 END) AS present_days,
    SUM(CASE WHEN a.status = 'LATE' THEN 1 ELSE 0 END) AS late_days,
    SUM(CASE WHEN a.status = 'ABSENT' THEN 1 ELSE 0 END) AS absent_days,
    SUM(CASE WHEN a.status = 'LEAVE' THEN 1 ELSE 0 END) AS leave_days,
    SUM(a.work_minutes) AS total_work_minutes,
    SUM(a.late_minutes) AS total_late_minutes,
    SUM(a.ot_minutes) AS total_ot_minutes
FROM hr_attendances a
JOIN users u ON a.user_id = u.id
GROUP BY a.user_id, DATE_FORMAT(a.attendance_date, '%Y-%m');

-- View: สรุปสิทธิ์วันลาคงเหลือ
CREATE OR REPLACE VIEW v_leave_balance AS
SELECT 
    e.user_id,
    u.first_name_th,
    u.last_name_th,
    u.department,
    e.year,
    lt.code AS leave_type_code,
    lt.name AS leave_type_name,
    e.entitled_days,
    e.carried_over_days,
    e.additional_days,
    e.used_days,
    e.pending_days,
    (e.entitled_days + e.carried_over_days + e.additional_days - e.used_days - e.pending_days) AS remaining_days
FROM hr_leave_entitlements e
JOIN users u ON e.user_id = u.id
JOIN hr_leave_types lt ON e.leave_type_id = lt.id
WHERE e.year = YEAR(CURDATE());
