-- Add the image report / ticket queue used by gallery reporting and staff review.
-- This update is safe for both fresh installs and systems that already have the
-- first report-system rollout applied.

CREATE TABLE IF NOT EXISTS `app_image_reports` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `image_id` bigint(20) UNSIGNED NOT NULL,
    `reporter_user_id` bigint(20) UNSIGNED DEFAULT NULL,
    `report_category` varchar(64) NOT NULL DEFAULT 'other',
    `report_subject` varchar(150) NOT NULL,
    `report_message` text NOT NULL,
    `status` enum('open','closed') NOT NULL DEFAULT 'open',
    `session_id` varchar(128) DEFAULT NULL,
    `ip` varbinary(16) DEFAULT NULL,
    `ua` varchar(255) DEFAULT NULL,
    `assigned_to_user_id` bigint(20) UNSIGNED DEFAULT NULL,
    `assigned_at` datetime DEFAULT NULL,
    `resolved_by` bigint(20) UNSIGNED DEFAULT NULL,
    `resolved_at` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_image_id` (`image_id`),
    KEY `idx_reporter_user_id` (`reporter_user_id`),
    KEY `idx_status` (`status`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_assigned_to_user_id` (`assigned_to_user_id`),
    KEY `idx_resolved_by` (`resolved_by`),
    CONSTRAINT `fk_image_reports_image` FOREIGN KEY (`image_id`) REFERENCES `app_images` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_image_reports_reporter` FOREIGN KEY (`reporter_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_image_reports_assigned_to` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_image_reports_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `app_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `app_image_report_comments` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `report_id` bigint(20) UNSIGNED NOT NULL,
    `user_id` bigint(20) UNSIGNED DEFAULT NULL,
    `comment_body` text NOT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_report_id` (`report_id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_image_report_comments_report` FOREIGN KEY (`report_id`) REFERENCES `app_image_reports` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_image_report_comments_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @database_name := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*)
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @database_name
       AND TABLE_NAME = 'app_image_reports'
       AND COLUMN_NAME = 'assigned_to_user_id') = 0,
    'ALTER TABLE `app_image_reports` ADD COLUMN `assigned_to_user_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `ua`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*)
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @database_name
       AND TABLE_NAME = 'app_image_reports'
       AND COLUMN_NAME = 'assigned_at') = 0,
    'ALTER TABLE `app_image_reports` ADD COLUMN `assigned_at` datetime DEFAULT NULL AFTER `assigned_to_user_id`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*)
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @database_name
       AND TABLE_NAME = 'app_image_reports'
       AND INDEX_NAME = 'idx_assigned_to_user_id') = 0,
    'ALTER TABLE `app_image_reports` ADD KEY `idx_assigned_to_user_id` (`assigned_to_user_id`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*)
     FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = @database_name
       AND TABLE_NAME = 'app_image_reports'
       AND CONSTRAINT_NAME = 'fk_image_reports_assigned_to') = 0,
    'ALTER TABLE `app_image_reports` ADD CONSTRAINT `fk_image_reports_assigned_to` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
