-- Add fingerprint and first_ip columns for enhanced session tracking
ALTER TABLE `app_sessions`
    ADD COLUMN `fingerprint` CHAR(64) DEFAULT NULL AFTER `ua`,
    ADD COLUMN `first_ip` VARBINARY(16) DEFAULT NULL AFTER `ip`;

-- Add temporary jail / suspicious activity log
CREATE TABLE `user_security_events` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT(20) UNSIGNED DEFAULT NULL,
  `ip` VARBINARY(16) DEFAULT NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `blocked_until` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add UA → fingerprint monitoring
CREATE TABLE `security_events` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `ua` TEXT,
  `fingerprints` INT,
  `first_seen` DATETIME,
  `last_seen` DATETIME,
  `flagged_at` DATETIME NULL,
  `notes` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
