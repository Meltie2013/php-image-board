-- --------------------------------------------------------
-- Request Guard hardening
-- Date: 2026-03-09
--
-- Adds:
--  - device_fingerprint scope support to app_block_list
--  - device_fingerprint indexes for block/log tables
--  - removes expires_at from app_security_logs
--
-- Safe to run multiple times.
-- --------------------------------------------------------

START TRANSACTION;

SET @has_block_list := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'app_block_list'
);

SET @has_security_logs := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'app_security_logs'
);

SET @scope_is_new := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'app_block_list'
      AND COLUMN_NAME = 'scope'
      AND COLUMN_TYPE = "enum('ip','fingerprint','device_fingerprint','ua','user_id')"
);

SET @sql := IF(@has_block_list > 0 AND @scope_is_new = 0,
    "ALTER TABLE app_block_list MODIFY scope ENUM('ip','fingerprint','device_fingerprint','ua','user_id') NOT NULL",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_block_dfp_col := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'app_block_list'
      AND COLUMN_NAME = 'device_fingerprint'
);

SET @sql := IF(@has_block_list > 0 AND @has_block_dfp_col = 0,
    'ALTER TABLE app_block_list ADD COLUMN device_fingerprint CHAR(64) DEFAULT NULL AFTER fingerprint',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_log_dfp_col := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'app_security_logs'
      AND COLUMN_NAME = 'device_fingerprint'
);

SET @sql := IF(@has_security_logs > 0 AND @has_log_dfp_col = 0,
    'ALTER TABLE app_security_logs ADD COLUMN device_fingerprint CHAR(64) DEFAULT NULL AFTER fingerprint',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_block_dfp_idx := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'app_block_list'
      AND INDEX_NAME = 'idx_device_fingerprint'
);

SET @sql := IF(@has_block_list > 0 AND @has_block_dfp_idx = 0,
    'ALTER TABLE app_block_list ADD KEY idx_device_fingerprint (device_fingerprint)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_log_dfp_idx := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'app_security_logs'
      AND INDEX_NAME = 'idx_device_fingerprint'
);

SET @sql := IF(@has_security_logs > 0 AND @has_log_dfp_idx = 0,
    'ALTER TABLE app_security_logs ADD KEY idx_device_fingerprint (device_fingerprint)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_log_expires_col := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'app_security_logs'
      AND COLUMN_NAME = 'expires_at'
);

SET @sql := IF(@has_security_logs > 0 AND @has_log_expires_col > 0,
    'ALTER TABLE app_security_logs DROP COLUMN expires_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
