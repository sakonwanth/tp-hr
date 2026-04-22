INSERT INTO hr_settings (`key`, `value`, `type`, `description`)
SELECT 'outside_location_requires_approval', '1', 'boolean', 'Require approval for outside location check-in/out'
WHERE NOT EXISTS (
    SELECT 1 FROM hr_settings WHERE `key` = 'outside_location_requires_approval'
);
