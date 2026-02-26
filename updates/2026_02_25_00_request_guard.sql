-- --------------------------------------------------------
-- Request Guard / Rate Limits / Block List
-- Date: 2026-02-25
--
-- Adds:
--  - app_rate_counters
--  - app_block_list
--  - app_security_logs
--
-- Notes:
--  - Safe to run multiple times (uses IF NOT EXISTS where possible).
--  - For existing installs, run this once and keep using base_database.sql for fresh installs.
-- --------------------------------------------------------

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
