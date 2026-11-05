-- Additive only. Existing users, positions, locations and attendances are not rewritten.
CREATE TABLE IF NOT EXISTS hr_workplaces (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(20) NOT NULL UNIQUE,
 name VARCHAR(100) NOT NULL,
 address TEXT NULL,
 description TEXT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS hr_location_workplaces (
 location_id INT NOT NULL PRIMARY KEY,
 workplace_id INT NOT NULL,
 INDEX idx_workplace (workplace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS hr_org_assignments (
 user_id INT NOT NULL PRIMARY KEY,
 position_id INT NULL,
 workplace_id INT NULL,
 INDEX idx_position (position_id),
 INDEX idx_workplace (workplace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS hr_org_history (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 actor_id INT NOT NULL,
 entity_type VARCHAR(30) NOT NULL,
 entity_id INT NOT NULL,
 old_values LONGTEXT NULL,
 new_values LONGTEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_entity (entity_type, entity_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS hr_org_mutex (
 id INT NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO hr_org_mutex (id) VALUES (1);
