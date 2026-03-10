-- --------------------------------------------------------
-- Device fingerprinting support
--
-- Adds:
-- - app_user_devices table (maps user accounts to stable device fingerprints)
-- - device_fingerprint column to app_sessions (optional, used for debugging/monitoring)
--
-- Safe to run multiple times.
-- --------------------------------------------------------

START TRANSACTION;

-- Add device_fingerprint column to app_sessions if missing
SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'app_sessions'
      AND COLUMN_NAME = 'device_fingerprint'
);

SET @sql := IF(@col_exists = 0,
    'ALTER TABLE app_sessions ADD COLUMN device_fingerprint CHAR(64) DEFAULT NULL AFTER fingerprint',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create app_user_devices if missing
CREATE TABLE IF NOT EXISTS app_user_devices (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  device_fingerprint char(64) NOT NULL,
  first_seen_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  first_ip varbinary(16) DEFAULT NULL,
  last_ip varbinary(16) DEFAULT NULL,
  user_agent_hash char(64) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_user_device (user_id, device_fingerprint),
  KEY idx_device_fingerprint (device_fingerprint),
  KEY idx_user_id (user_id),
  CONSTRAINT fk_user_devices_user FOREIGN KEY (user_id) REFERENCES app_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
