-- ----------------------------------------------------------------------
-- Merge legacy security tables into unified RequestGuard system
--
-- This migration:
-- - Ensures RequestGuard tables exist
-- - Migrates data from legacy tables (security_events, user_security_events)
-- - Renames legacy tables to *_legacy to avoid future writes
--
-- Safe to run multiple times.
-- ----------------------------------------------------------------------

START TRANSACTION;

-- Ensure new tables exist (idempotent)
CREATE TABLE IF NOT EXISTS `app_rate_counters` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `scope` varchar(64) NOT NULL,
  `key_hash` char(64) NOT NULL,
  `window_start` datetime NOT NULL,
  `hits` int(11) NOT NULL DEFAULT 0,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_scope_key_window` (`scope`,`key_hash`,`window_start`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `app_block_list` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `scope` enum('ip','fingerprint','ua','user_id') NOT NULL,
  `value_hash` char(64) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ip` varbinary(16) DEFAULT NULL,
  `ua` varchar(255) DEFAULT NULL,
  `fingerprint` char(64) DEFAULT NULL,
  `status` enum('blocked','banned','rate_limited','jailed') NOT NULL DEFAULT 'blocked',
  `reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `last_seen` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_scope_hash` (`scope`,`value_hash`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_ip` (`ip`),
  KEY `idx_fingerprint` (`fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `app_security_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `session_id` varchar(128) DEFAULT NULL,
  `ip` varbinary(16) DEFAULT NULL,
  `ua` varchar(255) DEFAULT NULL,
  `fingerprint` char(64) DEFAULT NULL,
  `category` varchar(64) NOT NULL,
  `message` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- (Tables are created with AUTO_INCREMENT where missing.)

-- ----------------------------------------------------------------------
-- Migrate legacy security_events -> app_security_logs
-- ----------------------------------------------------------------------

-- Only migrate if the legacy table exists
SET @has_security_events := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'security_events');

-- Use a prepared statement so the migration is safe even if the table is missing.
SET @sql := IF(@has_security_events > 0,
    "INSERT INTO app_security_logs (user_id, session_id, ip, ua, fingerprint, category, message, created_at, expires_at)\n\
     SELECT NULL, NULL, NULL, LEFT(COALESCE(ua, ''), 255), NULL,\n\
            'legacy_security_events',\n\
            LEFT(COALESCE(notes, 'UA fingerprint diversity flagged'), 255),\n\
            COALESCE(flagged_at, last_seen, first_seen, NOW()),\n\
            NULL\n\
     FROM security_events\n\
     WHERE (SELECT COUNT(*) FROM app_security_logs WHERE category = 'legacy_security_events') = 0;",
    "SELECT 1;"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------
-- Migrate legacy user_security_events -> app_block_list + app_security_logs
-- ----------------------------------------------------------------------

SET @has_user_security_events := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_security_events');

SET @sql := IF(@has_user_security_events > 0,
    "INSERT INTO app_block_list (scope, value_hash, user_id, ip, ua, fingerprint, status, reason, created_at, last_seen, expires_at)\n\
     SELECT 'user_id',\n\
            SHA2(CONCAT('user|', COALESCE(user_id, 0)), 256),\n\
            user_id,\n\
            ip,\n\
            NULL,\n\
            NULL,\n\
            'jailed',\n\
            LEFT(event_type, 255),\n\
            NOW(),\n\
            NOW(),\n\
            blocked_until\n\
     FROM user_security_events\n\
     WHERE user_id IS NOT NULL\n\
       AND blocked_until > NOW()\n\
       AND NOT EXISTS (\n\
            SELECT 1 FROM app_block_list bl\n\
             WHERE bl.scope='user_id' AND bl.user_id = user_security_events.user_id\n\
               AND bl.status='jailed' AND bl.expires_at = user_security_events.blocked_until\n\
       );",
    "SELECT 1;"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_user_security_events > 0,
    "INSERT INTO app_security_logs (user_id, session_id, ip, ua, fingerprint, category, message, created_at, expires_at)\n\
     SELECT user_id, NULL, ip, NULL, NULL,\n\
            'legacy_user_security_events',\n\
            LEFT(event_type, 255),\n\
            NOW(),\n\
            blocked_until\n\
     FROM user_security_events\n\
     WHERE (SELECT COUNT(*) FROM app_security_logs WHERE category = 'legacy_user_security_events') = 0;",
    "SELECT 1;"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------
-- Rename legacy tables to *_legacy to prevent further usage
-- ----------------------------------------------------------------------

SET @has_security_events := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'security_events');
SET @has_security_events_legacy := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'security_events_legacy');

SET @sql := IF(@has_security_events > 0 AND @has_security_events_legacy = 0,
    "RENAME TABLE security_events TO security_events_legacy;",
    "SELECT 1;"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_user_security_events := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_security_events');
SET @has_user_security_events_legacy := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_security_events_legacy');

SET @sql := IF(@has_user_security_events > 0 AND @has_user_security_events_legacy = 0,
    "RENAME TABLE user_security_events TO user_security_events_legacy;",
    "SELECT 1;"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
