-- Optional: restrict an API key to a single employee (users.id) for kiosk / self-service integrations.
-- When set, list/detail endpoints ignore cross-user ?user_id= and only return that user's data.

ALTER TABLE hr_api_keys
    ADD COLUMN service_user_id INT(11) NULL DEFAULT NULL COMMENT 'If set, key may only access this users.id' AFTER created_by,
    ADD KEY idx_service_user (service_user_id);
