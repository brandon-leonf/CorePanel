-- CorePanel least-privilege accounts (MySQL 8+)
-- Replace hostnames/passwords/database name before applying.
-- Do NOT use GRANT ALL for runtime app traffic.

-- Runtime web application account (used by PHP app).
CREATE USER IF NOT EXISTS 'corepanel_app'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME_APP_PASSWORD';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'corepanel_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE
ON `corepanel`.*
TO 'corepanel_app'@'127.0.0.1';

-- Migration account (schema changes only, not web runtime).
CREATE USER IF NOT EXISTS 'corepanel_migrator'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME_MIGRATOR_PASSWORD';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'corepanel_migrator'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP
ON `corepanel`.*
TO 'corepanel_migrator'@'127.0.0.1';

-- Backup account (for mysqldump only).
CREATE USER IF NOT EXISTS 'corepanel_backup'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME_BACKUP_PASSWORD';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'corepanel_backup'@'127.0.0.1';
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES
ON `corepanel`.*
TO 'corepanel_backup'@'127.0.0.1';

FLUSH PRIVILEGES;

-- Audit grants:
SHOW GRANTS FOR 'corepanel_app'@'127.0.0.1';
SHOW GRANTS FOR 'corepanel_migrator'@'127.0.0.1';
SHOW GRANTS FOR 'corepanel_backup'@'127.0.0.1';
