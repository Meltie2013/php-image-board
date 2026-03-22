-- --------------------------------------------------------
-- Settings registry revamp
-- --------------------------------------------------------
-- Moves the ACP settings system from the legacy app_settings key/value table
-- into category-aware tables:
--   - app_settings_categories
--   - app_settings_data
--
-- Notes:
-- - The legacy app_settings table is intentionally left in place for safety.
-- - Existing values are migrated forward into app_settings_data.
-- - Any unknown legacy keys are kept as custom migrated settings.
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `app_settings_categories` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` varchar(64) NOT NULL,
    `title` varchar(80) NOT NULL,
    `description` varchar(255) NOT NULL DEFAULT '',
    `icon` varchar(32) NOT NULL DEFAULT 'fa-sliders',
    `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
    `is_system` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_settings_category_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_settings_data` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id` bigint(20) UNSIGNED DEFAULT NULL,
    `key` varchar(128) NOT NULL,
    `title` varchar(120) NOT NULL,
    `description` text DEFAULT NULL,
    `value` text NOT NULL,
    `type` varchar(16) NOT NULL DEFAULT 'string',
    `input_type` varchar(16) NOT NULL DEFAULT 'text',
    `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
    `is_system` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_settings_data_key` (`key`),
    KEY `idx_settings_data_category` (`category_id`),
    CONSTRAINT `fk_settings_data_category` FOREIGN KEY (`category_id`) REFERENCES `app_settings_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `app_settings_categories` (`slug`, `title`, `description`, `icon`, `sort_order`, `is_system`) VALUES
    ('debugging', 'Debugging', 'Developer-only switches used while troubleshooting uploads, rendering, and runtime issues.', 'fa-bug', 10, 1),
    ('gallery', 'Gallery', 'Controls gallery page sizing, pagination, comments, and upload storage limits.', 'fa-images', 20, 1),
    ('profile', 'User Profile', 'Profile presentation defaults, avatar sizing, and age-gate requirements.', 'fa-id-card', 30, 1),
    ('site', 'Site', 'Board identity, naming, and build metadata exposed across the public interface.', 'fa-window-maximize', 40, 1),
    ('template', 'Template', 'Template engine behavior, cache handling, and approved helper functions.', 'fa-code', 50, 1),
    ('upload', 'Upload', 'Controls how new upload hashes are generated and how upload behavior is presented.', 'fa-upload', 60, 1)
ON DUPLICATE KEY UPDATE
    `title` = VALUES(`title`),
    `description` = VALUES(`description`),
    `icon` = VALUES(`icon`),
    `sort_order` = VALUES(`sort_order`),
    `is_system` = VALUES(`is_system`);

-- Create generic categories for any legacy prefixes that are not part of the built-in catalog.
INSERT INTO `app_settings_categories` (`slug`, `title`, `description`, `icon`, `sort_order`, `is_system`)
SELECT DISTINCT
    SUBSTRING_INDEX(s.`key`, '.', 1) AS slug,
    REPLACE(SUBSTRING_INDEX(s.`key`, '.', 1), '_', ' ') AS title,
    'Custom settings migrated from the legacy registry.',
    'fa-sliders',
    9999,
    0
FROM `app_settings` s
LEFT JOIN `app_settings_categories` c ON c.`slug` = SUBSTRING_INDEX(s.`key`, '.', 1)
WHERE c.`id` IS NULL
  AND s.`key` LIKE '%.%';

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'debugging.allow_approve_uploads', 'Allow Upload Auto Approval', 'Lets uploads skip the normal review flow. Keep this disabled unless you are intentionally testing approval behavior on a trusted environment.',
       COALESCE((SELECT `value` FROM `app_settings` WHERE `key` = 'debugging.allow_approve_uploads' LIMIT 1), '0'), 'bool', 'bool', 10, 1
FROM `app_settings_categories` c WHERE c.`slug` = 'debugging'
ON DUPLICATE KEY UPDATE `category_id` = VALUES(`category_id`), `title` = VALUES(`title`), `description` = VALUES(`description`), `value` = VALUES(`value`), `type` = VALUES(`type`), `input_type` = VALUES(`input_type`), `sort_order` = VALUES(`sort_order`), `is_system` = VALUES(`is_system`);

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'debugging.allow_error_outputs', 'Display PHP Errors', 'Shows PHP errors directly in the browser while debugging application issues.',
       COALESCE((SELECT `value` FROM `app_settings` WHERE `key` = 'debugging.allow_error_outputs' LIMIT 1), '0'), 'bool', 'bool', 20, 1
FROM `app_settings_categories` c WHERE c.`slug` = 'debugging'
ON DUPLICATE KEY UPDATE `category_id` = VALUES(`category_id`), `title` = VALUES(`title`), `description` = VALUES(`description`), `value` = VALUES(`value`), `type` = VALUES(`type`), `input_type` = VALUES(`input_type`), `sort_order` = VALUES(`sort_order`), `is_system` = VALUES(`is_system`);

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'gallery.comments_per_page', 'Comments Per Page', 'Controls how many comments appear at once on the gallery image view page before pagination is used.',
       COALESCE((SELECT `value` FROM `app_settings` WHERE `key` = 'gallery.comments_per_page' LIMIT 1), '5'), 'int', 'number', 10, 1
FROM `app_settings_categories` c WHERE c.`slug` = 'gallery'
ON DUPLICATE KEY UPDATE `category_id` = VALUES(`category_id`), `title` = VALUES(`title`), `description` = VALUES(`description`), `value` = VALUES(`value`), `type` = VALUES(`type`), `input_type` = VALUES(`input_type`), `sort_order` = VALUES(`sort_order`), `is_system` = VALUES(`is_system`);

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'gallery.images_displayed', 'Images Per Gallery Page', 'Sets how many images are shown on each gallery page before the next page is created.',
       COALESCE((SELECT `value` FROM `app_settings` WHERE `key` = 'gallery.images_displayed' LIMIT 1), '24'), 'int', 'number', 20, 1
FROM `app_settings_categories` c WHERE c.`slug` = 'gallery'
ON DUPLICATE KEY UPDATE `category_id` = VALUES(`category_id`), `title` = VALUES(`title`), `description` = VALUES(`description`), `value` = VALUES(`value`), `type` = VALUES(`type`), `input_type` = VALUES(`input_type`), `sort_order` = VALUES(`sort_order`), `is_system` = VALUES(`is_system`);

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'gallery.pagination_range', 'Pagination Range', 'Determines how many page links appear on each side of the current page in gallery pagination.',
       COALESCE((SELECT `value` FROM `app_settings` WHERE `key` = 'gallery.pagination_range' LIMIT 1), '3'), 'int', 'number', 30, 1
FROM `app_settings_categories` c WHERE c.`slug` = 'gallery'
ON DUPLICATE KEY UPDATE `category_id` = VALUES(`category_id`), `title` = VALUES(`title`), `description` = VALUES(`description`), `value` = VALUES(`value`), `type` = VALUES(`type`), `input_type` = VALUES(`input_type`), `sort_order` = VALUES(`sort_order`), `is_system` = VALUES(`is_system`);

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'gallery.upload_max_image_size', 'Maximum Upload Size (MB)', 'Sets the largest image file size allowed for a single upload, measured in megabytes.',
       COALESCE((SELECT `value` FROM `app_settings` WHERE `key` = 'gallery.upload_max_image_size' LIMIT 1), '3'), 'int', 'number', 40, 1
FROM `app_settings_categories` c WHERE c.`slug` = 'gallery'
ON DUPLICATE KEY UPDATE `category_id` = VALUES(`category_id`), `title` = VALUES(`title`), `description` = VALUES(`description`), `value` = VALUES(`value`), `type` = VALUES(`type`), `input_type` = VALUES(`input_type`), `sort_order` = VALUES(`sort_order`), `is_system` = VALUES(`is_system`);

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'gallery.upload_max_storage', 'Maximum Gallery Storage', 'Defines the total storage available for uploaded images using shorthand values such as 500mb, 10gb, or 1tb.',
       COALESCE((SELECT `value` FROM `app_settings` WHERE `key` = 'gallery.upload_max_storage' LIMIT 1), '10gb'), 'string', 'text', 50, 1
FROM `app_settings_categories` c WHERE c.`slug` = 'gallery'
ON DUPLICATE KEY UPDATE `category_id` = VALUES(`category_id`), `title` = VALUES(`title`), `description` = VALUES(`description`), `value` = VALUES(`value`), `type` = VALUES(`type`), `input_type` = VALUES(`input_type`), `sort_order` = VALUES(`sort_order`), `is_system` = VALUES(`is_system`);

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'profile.avatar_size', 'Default Avatar Size', 'Sets the default avatar display size in pixels for user profile areas.',
       COALESCE((SELECT `value` FROM `app_settings` WHERE `key` = 'profile.avatar_size' LIMIT 1), '250'), 'int', 'number', 10, 1
FROM `app_settings_categories` c WHERE c.`slug` = 'profile'
ON DUPLICATE KEY UPDATE `category_id` = VALUES(`category_id`), `title` = VALUES(`title`), `description` = VALUES(`description`), `value` = VALUES(`value`), `type` = VALUES(`type`), `input_type` = VALUES(`input_type`), `sort_order` = VALUES(`sort_order`), `is_system` = VALUES(`is_system`);

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'profile.years', 'Sensitive Content Age Requirement', 'Defines the minimum age required for users to view content protected by the board age gate.',
       COALESCE((SELECT `value` FROM `app_settings` WHERE `key` = 'profile.years' LIMIT 1), '13'), 'int', 'number', 20, 1
FROM `app_settings_categories` c WHERE c.`slug` = 'profile'
ON DUPLICATE KEY UPDATE `category_id` = VALUES(`category_id`), `title` = VALUES(`title`), `description` = VALUES(`description`), `value` = VALUES(`value`), `type` = VALUES(`type`), `input_type` = VALUES(`input_type`), `sort_order` = VALUES(`sort_order`), `is_system` = VALUES(`is_system`);

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'site.name', 'Site Name', 'Primary board name shown in the header, footer, page titles, and shared templates.',
       COALESCE((SELECT `value` FROM `app_settings` WHERE `key` = 'site.name' LIMIT 1), 'PHP Image Board'), 'string', 'text', 10, 1
FROM `app_settings_categories` c WHERE c.`slug` = 'site'
ON DUPLICATE KEY UPDATE `category_id` = VALUES(`category_id`), `title` = VALUES(`title`), `description` = VALUES(`description`), `value` = VALUES(`value`), `type` = VALUES(`type`), `input_type` = VALUES(`input_type`), `sort_order` = VALUES(`sort_order`), `is_system` = VALUES(`is_system`);

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'site.version', 'Build Version', 'Version or build string shown in the interface for release tracking and support reference.',
       COALESCE((SELECT `value` FROM `app_settings` WHERE `key` = 'site.version' LIMIT 1), '0.2.3'), 'string', 'text', 20, 1
FROM `app_settings_categories` c WHERE c.`slug` = 'site'
ON DUPLICATE KEY UPDATE `category_id` = VALUES(`category_id`), `title` = VALUES(`title`), `description` = VALUES(`description`), `value` = VALUES(`value`), `type` = VALUES(`type`), `input_type` = VALUES(`input_type`), `sort_order` = VALUES(`sort_order`), `is_system` = VALUES(`is_system`);

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'template.allowed_functions', 'Allowed Template Functions', 'JSON array of trusted PHP function names that templates may call. This list should stay minimal and only contain simple, safe helpers.',
       COALESCE((SELECT `value` FROM `app_settings` WHERE `key` = 'template.allowed_functions' LIMIT 1), '["strtoupper","strtolower","ucfirst","lcfirst"]'), 'json', 'json', 10, 1
FROM `app_settings_categories` c WHERE c.`slug` = 'template'
ON DUPLICATE KEY UPDATE `category_id` = VALUES(`category_id`), `title` = VALUES(`title`), `description` = VALUES(`description`), `value` = VALUES(`value`), `type` = VALUES(`type`), `input_type` = VALUES(`input_type`), `sort_order` = VALUES(`sort_order`), `is_system` = VALUES(`is_system`);

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'template.disable_cache', 'Disable Template Cache', 'Turns off compiled template caching so visual and template updates appear immediately during development.',
       COALESCE((SELECT `value` FROM `app_settings` WHERE `key` = 'template.disable_cache' LIMIT 1), '1'), 'bool', 'bool', 20, 1
FROM `app_settings_categories` c WHERE c.`slug` = 'template'
ON DUPLICATE KEY UPDATE `category_id` = VALUES(`category_id`), `title` = VALUES(`title`), `description` = VALUES(`description`), `value` = VALUES(`value`), `type` = VALUES(`type`), `input_type` = VALUES(`input_type`), `sort_order` = VALUES(`sort_order`), `is_system` = VALUES(`is_system`);

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'upload.hash_type', 'Generated Image Hash Format', 'Chooses the character format used when generating new image hashes for uploads.',
       COALESCE((SELECT `value` FROM `app_settings` WHERE `key` = 'upload.hash_type' LIMIT 1), 'mixed_lower'), 'string', 'select', 10, 1
FROM `app_settings_categories` c WHERE c.`slug` = 'upload'
ON DUPLICATE KEY UPDATE `category_id` = VALUES(`category_id`), `title` = VALUES(`title`), `description` = VALUES(`description`), `value` = VALUES(`value`), `type` = VALUES(`type`), `input_type` = VALUES(`input_type`), `sort_order` = VALUES(`sort_order`), `is_system` = VALUES(`is_system`);

-- Migrate any remaining legacy keys as custom settings.
INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT
    c.`id`,
    s.`key`,
    REPLACE(SUBSTRING_INDEX(s.`key`, '.', -1), '_', ' ') AS title,
    'Migrated from the legacy app_settings registry.',
    s.`value`,
    CASE
        WHEN LOWER(TRIM(s.`type`)) IN ('bool', 'boolean') THEN 'bool'
        WHEN LOWER(TRIM(s.`type`)) IN ('int', 'integer') THEN 'int'
        WHEN LOWER(TRIM(s.`type`)) IN ('json', 'array') THEN 'json'
        ELSE 'string'
    END AS type,
    CASE
        WHEN LOWER(TRIM(s.`type`)) IN ('bool', 'boolean') THEN 'bool'
        WHEN LOWER(TRIM(s.`type`)) IN ('int', 'integer') THEN 'number'
        WHEN LOWER(TRIM(s.`type`)) IN ('json', 'array') THEN 'json'
        ELSE 'text'
    END AS input_type,
    9999,
    0
FROM `app_settings` s
LEFT JOIN `app_settings_data` d ON d.`key` = s.`key`
LEFT JOIN `app_settings_categories` c ON c.`slug` = SUBSTRING_INDEX(s.`key`, '.', 1)
WHERE d.`id` IS NULL;
