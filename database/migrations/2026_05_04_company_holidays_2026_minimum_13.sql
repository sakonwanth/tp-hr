-- Set TP Asset company holidays for calendar year 2026 to the legal minimum:
-- at least 13 traditional holidays per year, including National Labour Day.

DELETE FROM hr_holidays
WHERE `date` BETWEEN '2026-01-01' AND '2026-12-31';

INSERT INTO hr_holidays (`date`, `name`, `name_en`, `type`, `description`, `is_active`) VALUES
('2026-01-01', 'วันขึ้นปีใหม่', 'New Year''s Day', 'PUBLIC', 'Company annual holiday 2026', 1),
('2026-03-03', 'วันมาฆบูชา', 'Makha Bucha Day', 'PUBLIC', 'Company annual holiday 2026', 1),
('2026-04-06', 'วันจักรี', 'Chakri Memorial Day', 'PUBLIC', 'Company annual holiday 2026', 1),
('2026-04-13', 'วันสงกรานต์', 'Songkran Day 1', 'PUBLIC', 'Company annual holiday 2026', 1),
('2026-04-14', 'วันสงกรานต์', 'Songkran Day 2', 'PUBLIC', 'Company annual holiday 2026', 1),
('2026-04-15', 'วันสงกรานต์', 'Songkran Day 3', 'PUBLIC', 'Company annual holiday 2026', 1),
('2026-05-01', 'วันแรงงานแห่งชาติ', 'National Labour Day', 'PUBLIC', 'Company annual holiday 2026', 1),
('2026-05-04', 'วันฉัตรมงคล', 'Coronation Day', 'PUBLIC', 'Company annual holiday 2026', 1),
('2026-06-03', 'วันเฉลิมพระชนมพรรษาสมเด็จพระนางเจ้าฯ', 'Queen Suthida''s Birthday', 'PUBLIC', 'Company annual holiday 2026', 1),
('2026-07-28', 'วันเฉลิมพระชนมพรรษา ร.10', 'King Vajiralongkorn''s Birthday', 'PUBLIC', 'Company annual holiday 2026', 1),
('2026-08-12', 'วันเฉลิมพระชนมพรรษา สมเด็จพระบรมราชชนนี', 'Queen Sirikit''s Birthday', 'PUBLIC', 'Company annual holiday 2026', 1),
('2026-10-13', 'วันคล้ายวันสวรรคต ร.9', 'King Bhumibol Memorial Day', 'PUBLIC', 'Company annual holiday 2026', 1),
('2026-12-10', 'วันรัฐธรรมนูญ', 'Constitution Day', 'PUBLIC', 'Company annual holiday 2026', 1);
