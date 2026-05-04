-- Imported Thailand holiday source data is kept separate from company holidays.

CREATE TABLE IF NOT EXISTS hr_thai_holiday_sources (
    id INT PRIMARY KEY AUTO_INCREMENT,
    source VARCHAR(50) NOT NULL,
    external_id VARCHAR(160) NOT NULL,
    slug VARCHAR(160) NULL,
    year SMALLINT NOT NULL,
    date DATE NOT NULL,
    name VARCHAR(160) NOT NULL,
    name_en VARCHAR(160) NULL,
    holiday_type VARCHAR(50) NULL,
    is_substitute TINYINT(1) NOT NULL DEFAULT 0,
    alcohol_ban TINYINT(1) NOT NULL DEFAULT 0,
    raw_json JSON NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    synced_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_source_external (source, external_id),
    UNIQUE KEY uk_source_date (source, date),
    INDEX idx_year_date (year, date),
    INDEX idx_active_year (is_active, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE hr_holidays ADD COLUMN IF NOT EXISTS source_holiday_id INT NULL AFTER created_by;
ALTER TABLE hr_holidays ADD COLUMN IF NOT EXISTS source VARCHAR(50) NULL AFTER source_holiday_id;
CREATE INDEX IF NOT EXISTS idx_hr_holidays_source_holiday ON hr_holidays (source_holiday_id);
