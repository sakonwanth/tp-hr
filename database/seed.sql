-- =============================================
-- TP-HR Initial Data
-- ข้อมูลเริ่มต้นระบบ HR
-- =============================================

USE tp_crm;

-- =============================================
-- 1. ข้อมูลกะทำงาน
-- =============================================

INSERT INTO hr_work_shifts (code, name, start_time, end_time, break_start, break_end, break_minutes, work_hours_per_day, grace_period_minutes, is_default) VALUES
-- ชื่อ base เท่านั้น — ช่วงเวลา (HH:MM-HH:MM) จะคำนวณจาก start_time/end_time ตอน render
-- ผ่าน shift_display_label() ใน core/Helpers.php (ไม่ hardcoded อีกแล้ว)
('REGULAR', 'กะปกติ',        '08:30:00', '17:30:00', '12:00:00', '13:00:00', 60, 8.00, 15, TRUE),
('EARLY',   'กะเช้า',         '07:00:00', '16:00:00', '12:00:00', '13:00:00', 60, 8.00, 15, FALSE),
('LATE',    'กะบ่าย',         '10:00:00', '19:00:00', '12:00:00', '13:00:00', 60, 8.00, 15, FALSE),
('FLEX',    'เวลายืดหยุ่น',   '08:00:00', '17:00:00', '12:00:00', '13:00:00', 60, 8.00, 0,  FALSE);

-- =============================================
-- 2. ข้อมูลประเภทการลา
-- =============================================

INSERT INTO hr_leave_types (code, name, name_en, description, color, default_days_per_year, is_paid, requires_document, document_after_days, min_days_advance, gender_restriction, min_months_employed, sort_order) VALUES
('SICK', 'ลาป่วย', 'Sick Leave', 'ลาป่วยตามกฎหมายแรงงาน ได้รับค่าจ้างไม่เกิน 30 วันต่อปี', '#EF4444', 30.00, TRUE, FALSE, 3, 0, 'ALL', 0, 1),
('PERSONAL', 'ลากิจ', 'Personal Leave', 'ลากิจส่วนตัวตามความจำเป็น', '#F59E0B', 6.00, TRUE, FALSE, NULL, 3, 'ALL', 0, 2),
('ANNUAL', 'ลาพักร้อน', 'Annual Leave', 'ลาพักร้อนประจำปี สำหรับพนักงานที่ทำงานครบ 1 ปี', '#10B981', 6.00, TRUE, FALSE, NULL, 7, 'ALL', 12, 3),
('MATERNITY', 'ลาคลอด', 'Maternity Leave', 'ลาคลอดบุตร ได้รับค่าจ้างไม่เกิน 45 วัน', '#EC4899', 98.00, TRUE, TRUE, NULL, 30, 'FEMALE', 0, 4),
('ORDINATION', 'ลาบวช', 'Ordination Leave', 'ลาบวชสำหรับพนักงานชาย ไม่เกิน 15 วัน ใช้ได้ครั้งเดียว', '#8B5CF6', 15.00, TRUE, TRUE, NULL, 30, 'MALE', 12, 5),
('MARRIAGE', 'ลาแต่งงาน', 'Marriage Leave', 'ลาแต่งงาน ใช้ได้ครั้งเดียวตลอดการทำงาน', '#F472B6', 5.00, TRUE, TRUE, NULL, 7, 'ALL', 6, 6),
('BEREAVEMENT', 'ลากรณีญาติเสีย', 'Bereavement Leave', 'ลากรณีบุคคลในครอบครัวเสียชีวิต', '#6B7280', 3.00, TRUE, TRUE, NULL, 0, 'ALL', 0, 7),
('MILITARY', 'ลารับราชการทหาร', 'Military Leave', 'ลาเพื่อรับราชการทหารตามกฎหมาย', '#374151', 60.00, TRUE, TRUE, NULL, 14, 'MALE', 0, 8),
('TRAINING', 'ลาฝึกอบรม', 'Training Leave', 'ลาเพื่อเข้าร่วมการฝึกอบรมที่บริษัทอนุมัติ', '#3B82F6', 10.00, TRUE, FALSE, NULL, 7, 'ALL', 3, 9),
('UNPAID', 'ลาไม่รับค่าจ้าง', 'Leave Without Pay', 'ลาโดยไม่ได้รับค่าจ้าง', '#9CA3AF', 365.00, FALSE, FALSE, NULL, 7, 'ALL', 0, 10);

-- =============================================
-- 3. ข้อมูลเทมเพลตเอกสาร
-- =============================================

INSERT INTO hr_document_templates (code, name, name_en, category, description, requires_approval, auto_generate, processing_days, signatory_name, signatory_position, sort_order) VALUES
('CERT_WORK_TH', 'หนังสือรับรองการทำงาน (ภาษาไทย)', 'Employment Certificate (Thai)', 'CERTIFICATE', 'หนังสือรับรองการทำงานภาษาไทย สำหรับยื่นหน่วยงานราชการ', FALSE, TRUE, 1, 'นายทดสอบ ตัวอย่าง', 'ผู้จัดการฝ่ายทรัพยากรบุคคล', 1),
('CERT_WORK_EN', 'หนังสือรับรองการทำงาน (ภาษาอังกฤษ)', 'Employment Certificate (English)', 'CERTIFICATE', 'หนังสือรับรองการทำงานภาษาอังกฤษ', FALSE, TRUE, 1, 'Mr.Test Example', 'Human Resources Manager', 2),
('CERT_SALARY_TH', 'หนังสือรับรองเงินเดือน (ภาษาไทย)', 'Salary Certificate (Thai)', 'CERTIFICATE', 'หนังสือรับรองเงินเดือนภาษาไทย', TRUE, TRUE, 2, 'นายทดสอบ ตัวอย่าง', 'ผู้จัดการฝ่ายทรัพยากรบุคคล', 3),
('CERT_SALARY_EN', 'หนังสือรับรองเงินเดือน (ภาษาอังกฤษ)', 'Salary Certificate (English)', 'CERTIFICATE', 'หนังสือรับรองเงินเดือนภาษาอังกฤษ', TRUE, TRUE, 2, 'Mr.Test Example', 'Human Resources Manager', 4),
('CERT_SALARY_BANK', 'หนังสือรับรองเงินเดือน (สำหรับธนาคาร)', 'Salary Certificate for Bank', 'CERTIFICATE', 'หนังสือรับรองเงินเดือนพร้อมรายละเอียดสำหรับสถาบันการเงิน', TRUE, TRUE, 2, 'นายทดสอบ ตัวอย่าง', 'ผู้จัดการฝ่ายทรัพยากรบุคคล', 5),
('TAX_50TAWI', 'หนังสือรับรองหักภาษี ณ ที่จ่าย (50 ทวิ)', 'Withholding Tax Certificate', 'CERTIFICATE', 'หนังสือรับรองการหักภาษี ณ ที่จ่าย ประจำปี', FALSE, TRUE, 3, 'นายทดสอบ ตัวอย่าง', 'ผู้จัดการฝ่ายบัญชี', 6);

-- =============================================
-- 4. ข้อมูลวันหยุดปี 2026
-- Company minimum traditional holidays: 13 days/year, including National Labour Day.
-- =============================================

INSERT INTO hr_holidays (date, name, name_en, type) VALUES
('2026-01-01', 'วันขึ้นปีใหม่', 'New Year\'s Day', 'PUBLIC'),
('2026-03-03', 'วันมาฆบูชา', 'Makha Bucha Day', 'PUBLIC'),
('2026-04-06', 'วันจักรี', 'Chakri Memorial Day', 'PUBLIC'),
('2026-04-13', 'วันสงกรานต์', 'Songkran Day 1', 'PUBLIC'),
('2026-04-14', 'วันสงกรานต์', 'Songkran Day 2', 'PUBLIC'),
('2026-04-15', 'วันสงกรานต์', 'Songkran Day 3', 'PUBLIC'),
('2026-05-01', 'วันแรงงานแห่งชาติ', 'National Labour Day', 'PUBLIC'),
('2026-05-04', 'วันฉัตรมงคล', 'Coronation Day', 'PUBLIC'),
('2026-06-03', 'วันเฉลิมพระชนมพรรษาสมเด็จพระนางเจ้าฯ', 'Queen Suthida\'s Birthday', 'PUBLIC'),
('2026-07-28', 'วันเฉลิมพระชนมพรรษา ร.10', 'King Vajiralongkorn\'s Birthday', 'PUBLIC'),
('2026-08-12', 'วันเฉลิมพระชนมพรรษา สมเด็จพระบรมราชชนนี', 'Queen Sirikit\'s Birthday', 'PUBLIC'),
('2026-10-13', 'วันคล้ายวันสวรรคต ร.9', 'King Bhumibol Memorial Day', 'PUBLIC'),
('2026-12-10', 'วันรัฐธรรมนูญ', 'Constitution Day', 'PUBLIC');

-- =============================================
-- 5. ข้อมูลการตั้งค่าระบบ
-- =============================================

INSERT INTO hr_settings (`key`, `value`, type, description, category) VALUES
-- General Settings
('company_name', 'บริษัท ทีพี แอสเซท ดีเวลลอปเมนท์ จำกัด', 'STRING', 'ชื่อบริษัท', 'general'),
('company_name_en', 'TP Asset Development Co., Ltd.', 'STRING', 'ชื่อบริษัทภาษาอังกฤษ', 'general'),
('company_address', '123/456 ถนนสุขุมวิท แขวงคลองตัน เขตคลองเตย กรุงเทพฯ 10110', 'STRING', 'ที่อยู่บริษัท', 'general'),
('company_phone', '02-123-4567', 'STRING', 'เบอร์โทรบริษัท', 'general'),
('company_email', 'hr@tp-asset.com', 'STRING', 'อีเมล HR', 'general'),
('tax_id', '0105XXXXXXXXX', 'STRING', 'เลขประจำตัวผู้เสียภาษี', 'general'),

-- Attendance Settings
('attendance_grace_period', '15', 'NUMBER', 'ระยะเวลาผ่อนผันมาสาย (นาที)', 'attendance'),
('attendance_late_deduction', '50', 'NUMBER', 'หักเงินมาสายต่อครั้ง (บาท)', 'attendance'),
('attendance_min_work_hours', '8', 'NUMBER', 'ชั่วโมงทำงานขั้นต่ำต่อวัน', 'attendance'),
('attendance_ot_start_after', '17:30', 'STRING', 'เริ่มนับ OT หลังเวลา', 'attendance'),
('attendance_gps_radius', '100', 'NUMBER', 'รัศมีการ Check-in GPS (เมตร)', 'attendance'),

-- Leave Settings
('leave_annual_days', '6', 'NUMBER', 'วันลาพักร้อนเริ่มต้น (วัน)', 'leave'),
('leave_annual_increase_after_years', '3', 'NUMBER', 'เพิ่มวันลาพักร้อนหลังอายุงาน (ปี)', 'leave'),
('leave_annual_increase_days', '1', 'NUMBER', 'จำนวนวันที่เพิ่มต่อปี', 'leave'),
('leave_max_annual_days', '15', 'NUMBER', 'วันลาพักร้อนสูงสุด (วัน)', 'leave'),
('leave_carryover_max_days', '5', 'NUMBER', 'ยกยอดวันลาได้สูงสุด (วัน)', 'leave'),
('leave_carryover_expire_months', '3', 'NUMBER', 'วันลายกยอดหมดอายุภายใน (เดือน)', 'leave'),

-- Document Settings
('document_running_prefix', 'HR', 'STRING', 'Prefix เลขที่เอกสาร', 'document'),
('document_running_format', 'HR-{YEAR}-{RUNNING:5}', 'STRING', 'รูปแบบเลขที่เอกสาร', 'document'),
('document_processing_days', '3', 'NUMBER', 'วันทำการในการออกเอกสาร', 'document'),

-- Notification Settings
('notification_email_enabled', 'true', 'BOOLEAN', 'เปิดใช้การแจ้งเตือนทาง Email', 'notification'),
('notification_line_enabled', 'true', 'BOOLEAN', 'เปิดใช้การแจ้งเตือนทาง LINE', 'notification'),
('notification_leave_remind_days', '1', 'NUMBER', 'แจ้งเตือนผู้อนุมัติก่อนวันลา (วัน)', 'notification');

-- =============================================
-- 6. สถานที่ลงเวลา (ตัวอย่าง)
-- =============================================

INSERT INTO hr_checkin_locations (name, code, address, latitude, longitude, radius_meters, wifi_ssid) VALUES
('สำนักงานใหญ่', 'HQ', '123/456 ถนนสุขุมวิท แขวงคลองตัน เขตคลองเตย กรุงเทพฯ 10110', 13.7234582, 100.5566273, 100, 'TP-ASSET-OFFICE'),
('สาขาสาทร', 'SATHORN', '789 ถนนสาทร แขวงยานนาวา เขตสาทร กรุงเทพฯ 10120', 13.7126483, 100.5241594, 100, 'TP-SATHORN'),
('Work From Home', 'WFH', 'ทำงานจากที่พักอาศัย', NULL, NULL, NULL, NULL);
