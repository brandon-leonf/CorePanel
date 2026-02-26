CREATE DATABASE IF NOT EXISTS corepanel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE corepanel;

CREATE TABLE IF NOT EXISTS tenants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT IGNORE INTO tenants (id, name) VALUES (1, 'Default Tenant');

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  totp_secret TEXT NULL,
  twofa_enabled_at DATETIME NULL,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_users_tenant_id (tenant_id),
  CONSTRAINT fk_users_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
  title VARCHAR(160) NOT NULL,
  description TEXT NULL,
  image_path VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  INDEX idx_items_tenant_id (tenant_id),
  CONSTRAINT fk_items_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_items_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  UNIQUE KEY uniq_pw_reset_token_hash (token_hash),
  CONSTRAINT fk_pwresets_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_user_audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id INT UNSIGNED NOT NULL,
  target_user_id INT UNSIGNED NULL,
  action VARCHAR(64) NOT NULL,
  summary VARCHAR(255) NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_created_at (created_at),
  INDEX idx_audit_actor (actor_user_id),
  INDEX idx_audit_target (target_user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS security_rate_limits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(64) NOT NULL,
  key_hash CHAR(64) NOT NULL,
  key_label VARCHAR(255) NOT NULL,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  first_attempt_at DATETIME NULL,
  last_attempt_at DATETIME NULL,
  lock_until DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_security_rate_key (action, key_hash),
  INDEX idx_security_rate_lock (lock_until),
  INDEX idx_security_rate_action_updated (action, updated_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS security_event_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(64) NOT NULL,
  action VARCHAR(64) NOT NULL,
  subject VARCHAR(190) NULL,
  key_label VARCHAR(255) NULL,
  ip_address VARCHAR(45) NULL,
  details VARCHAR(255) NULL,
  level ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  source VARCHAR(32) NOT NULL DEFAULT 'app',
  actor_user_id INT UNSIGNED NULL,
  tenant_id INT UNSIGNED NULL,
  prev_hash CHAR(64) NULL,
  event_hash CHAR(64) NULL,
  context_json TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_security_event_action (action, created_at),
  INDEX idx_security_event_ip (ip_address, created_at),
  INDEX idx_security_event_subject (subject, created_at),
  INDEX idx_security_event_level_created (level, created_at),
  INDEX idx_security_event_type_created (event_type, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_key VARCHAR(80) NOT NULL UNIQUE,
  role_name VARCHAR(120) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  permission_key VARCHAR(120) NOT NULL UNIQUE,
  permission_description VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_role_permissions_role
    FOREIGN KEY (role_id) REFERENCES roles(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission
    FOREIGN KEY (permission_id) REFERENCES permissions(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_user_roles_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role
    FOREIGN KEY (role_id) REFERENCES roles(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;
