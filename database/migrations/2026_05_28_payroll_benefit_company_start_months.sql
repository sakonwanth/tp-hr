-- Company-level benefit withholding start months (optional)

INSERT INTO system_settings (setting_key, setting_value, category, description)
VALUES
    ('payroll_ss_enabled_from', '', 'HR', 'เดือนเริ่มหักประกันสังคมระดับบริษัท (YYYY-MM ว่าง=ทันทีเมื่อเปิด)'),
    ('payroll_tax_enabled_from', '', 'HR', 'เดือนเริ่มหักภาษีระดับบริษัท (YYYY-MM ว่าง=ทันทีเมื่อเปิด)'),
    ('payroll_health_insurance_enabled_from', '', 'HR', 'เดือนเริ่มหักประกันสุขภาพระดับบริษัท (YYYY-MM ว่าง=ทันทีเมื่อเปิด)'),
    ('payroll_group_insurance_enabled_from', '', 'HR', 'เดือนเริ่มหักประกันกลุ่มระดับบริษัท (YYYY-MM ว่าง=ทันทีเมื่อเปิด)')
ON DUPLICATE KEY UPDATE description = VALUES(description);
