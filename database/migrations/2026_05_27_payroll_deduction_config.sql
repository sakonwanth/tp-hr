-- Payroll deduction config (sync with tp-crm add_payroll_deduction_config.sql)
-- Date: 2026-05-27

INSERT INTO system_settings (setting_key, setting_value, category, description)
VALUES
    ('payroll_tax_enabled', '1', 'HR', 'เปิดใช้การหักภาษี ณ ที่จ่าย (0=ปิด, 1=เปิด)'),
    ('payroll_health_insurance_enabled', '0', 'HR', 'เปิดใช้การหักประกันสุขภาพพนักงาน (0=ปิด, 1=เปิด)'),
    ('payroll_group_insurance_enabled', '1', 'HR', 'เปิดใช้การหักประกันกลุ่ม (0=ปิด, 1=เปิด)')
ON DUPLICATE KEY UPDATE description = VALUES(description);

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS tax_withholding_start_date DATE NULL
        COMMENT 'วันเริ่มหักภาษี ณ ที่จ่าย (ว่าง=หักทันทีเมื่อเปิดใช้)',
    ADD COLUMN IF NOT EXISTS health_insurance_start_date DATE NULL
        COMMENT 'วันเริ่มหักประกันสุขภาพ (ว่าง=ยังไม่หัก)',
    ADD COLUMN IF NOT EXISTS group_insurance_start_date DATE NULL
        COMMENT 'วันเริ่มหักประกันกลุ่ม (ว่าง=หักทันทีเมื่อมีเบี้ย)';

ALTER TABLE employee_salary_setup
    ADD COLUMN IF NOT EXISTS tax_opt_out TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1=ไม่หักภาษี ณ ที่จ่ายสำหรับพนักงานคนนี้',
    ADD COLUMN IF NOT EXISTS health_insurance_total_monthly DECIMAL(12,2) NOT NULL DEFAULT 0
        COMMENT 'เบี้ยประกันสุขภาพเต็มจำนวน/เดือน',
    ADD COLUMN IF NOT EXISTS health_insurance_employer_pct DECIMAL(5,2) NOT NULL DEFAULT 50.00
        COMMENT '% ประกันสุขภาพที่บริษัทออกให้',
    ADD COLUMN IF NOT EXISTS hi_opt_out TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1=ไม่หักประกันสุขภาพสำหรับพนักงานคนนี้',
    ADD COLUMN IF NOT EXISTS gi_opt_out TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1=ไม่หักประกันกลุ่มสำหรับพนักงานคนนี้';

ALTER TABLE payroll_slips
    ADD COLUMN IF NOT EXISTS health_insurance DECIMAL(12,2) NOT NULL DEFAULT 0
        COMMENT 'ประกันสุขภาพ (ส่วนที่พนักงานจ่าย)';
