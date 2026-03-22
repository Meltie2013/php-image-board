-- --------------------------------------------------------
-- Retire legacy app_settings registry
-- --------------------------------------------------------
-- Finalizes the settings registry migration by:
--   - refreshing built-in category presentation rows
--   - migrating any leftover legacy keys into app_settings_data
--   - dropping the legacy app_settings table
-- --------------------------------------------------------

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

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'gallery.max_width', 'Maximum Upload Width', 'Largest image width accepted during upload validation, measured in pixels.', '8000', 'int', 'number', 60, 1
FROM `app_settings_categories` c
WHERE c.`slug` = 'gallery'
ON DUPLICATE KEY UPDATE
    `category_id` = VALUES(`category_id`),
    `title` = VALUES(`title`),
    `description` = VALUES(`description`),
    `type` = VALUES(`type`),
    `input_type` = VALUES(`input_type`),
    `sort_order` = VALUES(`sort_order`),
    `is_system` = VALUES(`is_system`);

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'gallery.max_height', 'Maximum Upload Height', 'Largest image height accepted during upload validation, measured in pixels.', '8000', 'int', 'number', 70, 1
FROM `app_settings_categories` c
WHERE c.`slug` = 'gallery'
ON DUPLICATE KEY UPDATE
    `category_id` = VALUES(`category_id`),
    `title` = VALUES(`title`),
    `description` = VALUES(`description`),
    `type` = VALUES(`type`),
    `input_type` = VALUES(`input_type`),
    `sort_order` = VALUES(`sort_order`),
    `is_system` = VALUES(`is_system`);

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'gallery.max_pixels', 'Maximum Upload Pixel Count', 'Maximum total pixel count allowed for one uploaded image.', '40000000', 'int', 'number', 80, 1
FROM `app_settings_categories` c
WHERE c.`slug` = 'gallery'
ON DUPLICATE KEY UPDATE
    `category_id` = VALUES(`category_id`),
    `title` = VALUES(`title`),
    `description` = VALUES(`description`),
    `type` = VALUES(`type`),
    `input_type` = VALUES(`input_type`),
    `sort_order` = VALUES(`sort_order`),
    `is_system` = VALUES(`is_system`);

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'profile.avatar_max_pixels', 'Maximum Avatar Pixel Count', 'Maximum total pixel count allowed for uploaded avatar images.', '16000000', 'int', 'number', 30, 1
FROM `app_settings_categories` c
WHERE c.`slug` = 'profile'
ON DUPLICATE KEY UPDATE
    `category_id` = VALUES(`category_id`),
    `title` = VALUES(`title`),
    `description` = VALUES(`description`),
    `type` = VALUES(`type`),
    `input_type` = VALUES(`input_type`),
    `sort_order` = VALUES(`sort_order`),
    `is_system` = VALUES(`is_system`);

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT c.`id`, 'profile.avatar_max_upload_size_mb', 'Maximum Avatar Upload Size (MB)', 'Largest avatar file size accepted during avatar uploads, measured in megabytes.', '5', 'int', 'number', 40, 1
FROM `app_settings_categories` c
WHERE c.`slug` = 'profile'
ON DUPLICATE KEY UPDATE
    `category_id` = VALUES(`category_id`),
    `title` = VALUES(`title`),
    `description` = VALUES(`description`),
    `type` = VALUES(`type`),
    `input_type` = VALUES(`input_type`),
    `sort_order` = VALUES(`sort_order`),
    `is_system` = VALUES(`is_system`);

SET @legacy_settings_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'app_settings'
);

SET @migrate_categories_sql := IF(
    @legacy_settings_exists > 0,
    'INSERT INTO `app_settings_categories` (`slug`, `title`, `description`, `icon`, `sort_order`, `is_system`)
     SELECT DISTINCT
         SUBSTRING_INDEX(s.`key`, ''.'', 1) AS slug,
         CONCAT(UCASE(LEFT(REPLACE(SUBSTRING_INDEX(s.`key`, ''.'', 1), ''_'', '' ''), 1)), SUBSTRING(REPLACE(SUBSTRING_INDEX(s.`key`, ''.'', 1), ''_'', '' ''), 2)) AS title,
         ''Custom settings migrated during legacy registry retirement.'',
         ''fa-sliders'',
         9999,
         0
     FROM `app_settings` s
     LEFT JOIN `app_settings_categories` c ON c.`slug` = SUBSTRING_INDEX(s.`key`, ''.'', 1)
     WHERE c.`id` IS NULL
       AND s.`key` LIKE ''%.%''',
    'SELECT 1'
);
PREPARE migrate_categories_stmt FROM @migrate_categories_sql;
EXECUTE migrate_categories_stmt;
DEALLOCATE PREPARE migrate_categories_stmt;

SET @migrate_settings_sql := IF(
    @legacy_settings_exists > 0,
    'INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
     SELECT
         c.`id`,
         s.`key`,
         CONCAT(UCASE(LEFT(REPLACE(SUBSTRING_INDEX(s.`key`, ''.'', -1), ''_'', '' ''), 1)), SUBSTRING(REPLACE(SUBSTRING_INDEX(s.`key`, ''.'', -1), ''_'', '' ''), 2)) AS title,
         ''Migrated during legacy registry retirement.'',
         s.`value`,
         CASE
             WHEN LOWER(TRIM(s.`type`)) IN (''bool'', ''boolean'') THEN ''bool''
             WHEN LOWER(TRIM(s.`type`)) IN (''int'', ''integer'') THEN ''int''
             WHEN LOWER(TRIM(s.`type`)) IN (''json'', ''array'') THEN ''json''
             ELSE ''string''
         END AS type,
         CASE
             WHEN LOWER(TRIM(s.`type`)) IN (''bool'', ''boolean'') THEN ''bool''
             WHEN LOWER(TRIM(s.`type`)) IN (''int'', ''integer'') THEN ''number''
             WHEN LOWER(TRIM(s.`type`)) IN (''json'', ''array'') THEN ''json''
             ELSE ''text''
         END AS input_type,
         9999,
         0
     FROM `app_settings` s
     LEFT JOIN `app_settings_data` d ON d.`key` = s.`key`
     LEFT JOIN `app_settings_categories` c ON c.`slug` = SUBSTRING_INDEX(s.`key`, ''.'', 1)
     WHERE d.`id` IS NULL',
    'SELECT 1'
);
PREPARE migrate_settings_stmt FROM @migrate_settings_sql;
EXECUTE migrate_settings_stmt;
DEALLOCATE PREPARE migrate_settings_stmt;

SET @drop_legacy_sql := IF(
    @legacy_settings_exists > 0,
    'DROP TABLE `app_settings`',
    'SELECT 1'
);
PREPARE drop_legacy_stmt FROM @drop_legacy_sql;
EXECUTE drop_legacy_stmt;
DEALLOCATE PREPARE drop_legacy_stmt;
