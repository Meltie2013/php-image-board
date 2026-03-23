-- Group + RBAC migration for legacy role-based installs.
--
-- This upgrade assumes the older schema shipped with:
--   - app_roles(id, name, description)
--   - app_users.role_id
--
-- What it does:
--   1) Preserves the old role table as app_roles_legacy_backup
--   2) Creates app_groups with the new built-in group catalog
--   3) Converts app_users.role_id -> app_users.group_id
--   4) Maps old roles into the new group ids
--   5) Creates app_group_permissions and seeds built-in RBAC defaults
--   6) Stores Member as the default registration group
--
-- Legacy role mapping used by this migration:
--   old Administrator (1) -> new Administrator (2)
--   old Moderator     (2) -> new Site Moderator (3)
--   old Member        (3) -> new Member (6)
--
-- IMPORTANT:
-- The new Site Administrator (group id 1) is owner-only and cannot be inferred
-- automatically. After this migration, manually promote your owner account:
--
--   UPDATE app_users SET group_id = 1 WHERE username = 'your-owner-username';
--
-- Run this once on an older install before deploying the new PHP code.

START TRANSACTION;
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `app_users`
    DROP FOREIGN KEY `fk_users_role`;

RENAME TABLE `app_roles` TO `app_roles_legacy_backup`;

CREATE TABLE `app_groups` (
    `id` tinyint(3) UNSIGNED NOT NULL,
    `name` varchar(80) NOT NULL,
    `slug` varchar(80) NOT NULL,
    `description` varchar(255) DEFAULT NULL,
    `is_built_in` tinyint(1) NOT NULL DEFAULT 0,
    `is_assignable` tinyint(1) NOT NULL DEFAULT 1,
    `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_app_groups_name` (`name`),
    UNIQUE KEY `uniq_app_groups_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `app_groups` (`id`, `name`, `slug`, `description`, `is_built_in`, `is_assignable`, `sort_order`) VALUES
    (1, 'Site Administrator', 'site-administrator', 'Full owner-level access to all ACP modules, groups, and permissions.', 1, 1, 10),
    (2, 'Administrator', 'administrator', 'Administrative access for normal site administrators without owner-only group controls.', 1, 1, 20),
    (3, 'Site Moderator', 'site-moderator', 'Site-wide moderation group covering all moderation areas.', 1, 1, 30),
    (4, 'Forum Moderator', 'forum-moderator', 'Forum-specific moderation group reserved for forum moderation tools.', 1, 1, 40),
    (5, 'Image Moderator', 'image-moderator', 'Gallery-specific moderation group for uploads, reports, and image actions.', 1, 1, 50),
    (6, 'Member', 'member', 'Standard member access for normal user accounts.', 1, 1, 60),
    (7, 'Banned', 'banned', 'Reserved non-assignable group used for banned accounts.', 1, 0, 70);

UPDATE `app_users`
SET `role_id` = CASE `role_id`
    WHEN 1 THEN 2
    WHEN 2 THEN 3
    WHEN 3 THEN 6
    ELSE 6
END;

ALTER TABLE `app_users`
    CHANGE COLUMN `role_id` `group_id` tinyint(3) UNSIGNED NOT NULL DEFAULT 6;

ALTER TABLE `app_users`
    DROP INDEX `fk_users_role`,
    ADD KEY `fk_users_group` (`group_id`);

ALTER TABLE `app_users`
    ADD CONSTRAINT `fk_users_group` FOREIGN KEY (`group_id`) REFERENCES `app_groups` (`id`) ON UPDATE CASCADE;

CREATE TABLE `app_group_permissions` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `group_id` tinyint(3) UNSIGNED NOT NULL,
    `permission_token` varchar(80) NOT NULL,
    `permission_value` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_group_permission` (`group_id`, `permission_token`),
    KEY `idx_permission_token` (`permission_token`),
    CONSTRAINT `fk_group_permissions_group` FOREIGN KEY (`group_id`) REFERENCES `app_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `app_group_permissions` (`group_id`, `permission_token`, `permission_value`) VALUES
    (1, 'view_gallery', 1),
    (1, 'upload_images', 1),
    (1, 'comment_images', 1),
    (1, 'report_images', 1),
    (1, 'vote_images', 1),
    (1, 'favorite_images', 1),
    (1, 'edit_own_image', 1),
    (1, 'edit_any_image', 1),
    (1, 'access_control_panel', 1),
    (1, 'manage_users', 1),
    (1, 'manage_groups', 1),
    (1, 'manage_group_permissions', 1),
    (1, 'manage_settings', 1),
    (1, 'view_security', 1),
    (1, 'manage_block_list', 1),
    (1, 'moderate_site', 1),
    (1, 'moderate_forums', 1),
    (1, 'moderate_gallery', 1),
    (1, 'moderate_image_queue', 1),
    (1, 'manage_image_reports', 1),
    (1, 'compare_images', 1),
    (1, 'rehash_images', 1),

    (2, 'view_gallery', 1),
    (2, 'upload_images', 1),
    (2, 'comment_images', 1),
    (2, 'report_images', 1),
    (2, 'vote_images', 1),
    (2, 'favorite_images', 1),
    (2, 'edit_own_image', 1),
    (2, 'edit_any_image', 1),
    (2, 'access_control_panel', 1),
    (2, 'manage_users', 1),
    (2, 'manage_settings', 1),
    (2, 'view_security', 1),
    (2, 'manage_block_list', 1),
    (2, 'moderate_site', 1),
    (2, 'moderate_forums', 1),
    (2, 'moderate_gallery', 1),
    (2, 'moderate_image_queue', 1),
    (2, 'manage_image_reports', 1),
    (2, 'compare_images', 1),
    (2, 'rehash_images', 1),

    (3, 'view_gallery', 1),
    (3, 'upload_images', 1),
    (3, 'comment_images', 1),
    (3, 'report_images', 1),
    (3, 'vote_images', 1),
    (3, 'favorite_images', 1),
    (3, 'edit_own_image', 1),
    (3, 'edit_any_image', 1),
    (3, 'access_control_panel', 1),
    (3, 'moderate_site', 1),
    (3, 'moderate_forums', 1),
    (3, 'moderate_gallery', 1),
    (3, 'moderate_image_queue', 1),
    (3, 'manage_image_reports', 1),
    (3, 'compare_images', 1),

    (4, 'view_gallery', 1),
    (4, 'comment_images', 1),
    (4, 'report_images', 1),
    (4, 'vote_images', 1),
    (4, 'favorite_images', 1),
    (4, 'edit_own_image', 1),
    (4, 'access_control_panel', 1),
    (4, 'moderate_forums', 1),

    (5, 'view_gallery', 1),
    (5, 'upload_images', 1),
    (5, 'comment_images', 1),
    (5, 'report_images', 1),
    (5, 'vote_images', 1),
    (5, 'favorite_images', 1),
    (5, 'edit_own_image', 1),
    (5, 'edit_any_image', 1),
    (5, 'access_control_panel', 1),
    (5, 'moderate_gallery', 1),
    (5, 'moderate_image_queue', 1),
    (5, 'manage_image_reports', 1),
    (5, 'compare_images', 1),

    (6, 'view_gallery', 1),
    (6, 'upload_images', 1),
    (6, 'comment_images', 1),
    (6, 'report_images', 1),
    (6, 'vote_images', 1),
    (6, 'favorite_images', 1),
    (6, 'edit_own_image', 1);

INSERT INTO `app_settings_categories` (`slug`, `title`, `description`, `icon`, `sort_order`, `is_system`)
VALUES ('accounts', 'Accounts', 'Account workflow defaults used by the group and user management features.', 'fa-users-gear', 70, 1)
ON DUPLICATE KEY UPDATE
    `title` = VALUES(`title`),
    `description` = VALUES(`description`),
    `icon` = VALUES(`icon`),
    `sort_order` = VALUES(`sort_order`),
    `is_system` = VALUES(`is_system`);

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`)
SELECT `id`, 'accounts.registration_default_group_id', 'Default Registration Group', 'Determines which assignable group newly registered accounts are created under before approval.', '6', 'int', 'select', 10, 1
FROM `app_settings_categories`
WHERE `slug` = 'accounts'
LIMIT 1
ON DUPLICATE KEY UPDATE
    `category_id` = VALUES(`category_id`),
    `title` = VALUES(`title`),
    `description` = VALUES(`description`),
    `value` = VALUES(`value`),
    `type` = VALUES(`type`),
    `input_type` = VALUES(`input_type`),
    `sort_order` = VALUES(`sort_order`),
    `is_system` = VALUES(`is_system`);

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
COMMIT;
