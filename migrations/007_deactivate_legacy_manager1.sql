SET NAMES utf8mb4;

UPDATE managers
SET is_active=0,
    is_working=0
WHERE login='manager1';
