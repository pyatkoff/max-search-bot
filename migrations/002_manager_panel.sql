ALTER TABLE managers ADD COLUMN password_hash VARCHAR(255) NULL AFTER login;
ALTER TABLE managers ADD COLUMN last_login_at DATETIME NULL AFTER is_active;
