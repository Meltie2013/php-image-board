-- Rules System + Notifications Migration
--
-- Creates the rules-management schema used by the Control Panel and the
-- notifications inbox used for rules update notices.

CREATE TABLE IF NOT EXISTS `app_rule_categories` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` varchar(150) NOT NULL,
    `slug` varchar(80) NOT NULL,
    `description` varchar(255) DEFAULT NULL,
    `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_by` bigint(20) UNSIGNED DEFAULT NULL,
    `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_app_rule_categories_slug` (`slug`),
    KEY `idx_app_rule_categories_sort` (`sort_order`),
    KEY `idx_app_rule_categories_created_by` (`created_by`),
    KEY `idx_app_rule_categories_updated_by` (`updated_by`),
    CONSTRAINT `fk_rule_categories_created_by` FOREIGN KEY (`created_by`) REFERENCES `app_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_rule_categories_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `app_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `app_rules` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id` bigint(20) UNSIGNED NOT NULL,
    `title` varchar(180) NOT NULL,
    `slug` varchar(80) NOT NULL,
    `body` text NOT NULL,
    `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_by` bigint(20) UNSIGNED DEFAULT NULL,
    `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_app_rules_slug` (`slug`),
    KEY `idx_app_rules_category` (`category_id`),
    KEY `idx_app_rules_sort` (`sort_order`),
    KEY `idx_app_rules_created_by` (`created_by`),
    KEY `idx_app_rules_updated_by` (`updated_by`),
    CONSTRAINT `fk_rules_category` FOREIGN KEY (`category_id`) REFERENCES `app_rule_categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_rules_created_by` FOREIGN KEY (`created_by`) REFERENCES `app_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_rules_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `app_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `app_rule_releases` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `version_label` varchar(150) NOT NULL,
    `summary` varchar(255) DEFAULT NULL,
    `grace_days` int(10) UNSIGNED NOT NULL DEFAULT 14,
    `published_by` bigint(20) UNSIGNED DEFAULT NULL,
    `published_at` datetime NOT NULL DEFAULT current_timestamp(),
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_app_rule_releases_published_at` (`published_at`),
    KEY `idx_app_rule_releases_published_by` (`published_by`),
    CONSTRAINT `fk_rule_releases_published_by` FOREIGN KEY (`published_by`) REFERENCES `app_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `app_rule_acceptances` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `release_id` bigint(20) UNSIGNED NOT NULL,
    `user_id` bigint(20) UNSIGNED NOT NULL,
    `status` enum('pending','accepted') NOT NULL DEFAULT 'pending',
    `enforce_after` datetime DEFAULT NULL,
    `accepted_at` datetime DEFAULT NULL,
    `notified_at` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_app_rule_acceptances_release_user` (`release_id`,`user_id`),
    KEY `idx_app_rule_acceptances_user` (`user_id`),
    KEY `idx_app_rule_acceptances_status` (`status`),
    KEY `idx_app_rule_acceptances_enforce_after` (`enforce_after`),
    CONSTRAINT `fk_rule_acceptances_release` FOREIGN KEY (`release_id`) REFERENCES `app_rule_releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_rule_acceptances_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `app_notifications` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` bigint(20) UNSIGNED NOT NULL,
    `notification_type` varchar(80) NOT NULL DEFAULT 'general',
    `title` varchar(180) NOT NULL,
    `message` text NOT NULL,
    `link_url` varchar(255) DEFAULT NULL,
    `is_read` tinyint(1) NOT NULL DEFAULT 0,
    `read_at` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_app_notifications_user` (`user_id`),
    KEY `idx_app_notifications_unread` (`user_id`,`is_read`),
    KEY `idx_app_notifications_type` (`notification_type`),
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


INSERT INTO `app_group_permissions` (`group_id`, `permission_token`, `permission_value`)
SELECT g.id, 'manage_rules', IF(g.slug IN ('site-administrator', 'administrator'), 1, 0)
FROM `app_groups` g
WHERE NOT EXISTS (
    SELECT 1
    FROM `app_group_permissions` gp
    WHERE gp.`group_id` = g.`id`
      AND gp.`permission_token` = 'manage_rules'
);

INSERT INTO `app_settings_categories` (`slug`, `title`, `description`, `icon`, `sort_order`, `is_system`, `created_at`, `updated_at`)
SELECT 'rules', 'Rules', 'Controls community rules behavior, release notices, and the enforcement window for updated rules.', 'fa-book-open', 80, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `app_settings_categories` WHERE `slug` = 'rules');

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`, `created_at`, `updated_at`)
SELECT c.`id`, 'rules.enforcement_window_days', 'Rules Enforcement Window (Days)', 'Defines how many days existing active members have to accept an updated rules release before account interaction becomes blocked.', '14', 'int', 'number', 10, 1, NOW(), NOW()
FROM `app_settings_categories` c
WHERE c.`slug` = 'rules'
  AND NOT EXISTS (SELECT 1 FROM `app_settings_data` WHERE `key` = 'rules.enforcement_window_days');

INSERT INTO `app_rule_categories` (`title`, `slug`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`)
SELECT 'Account Rules', 'account-rules', 'Rules that apply to account conduct, registration, and identity.', 10, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `app_rule_categories` WHERE `slug` = 'account-rules');

INSERT INTO `app_rule_categories` (`title`, `slug`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`)
SELECT 'Content Rules', 'content-rules', 'Rules that apply to uploads, tagging, and content safety expectations.', 20, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `app_rule_categories` WHERE `slug` = 'content-rules');

INSERT INTO `app_rule_categories` (`title`, `slug`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`)
SELECT 'Community Conduct', 'community-conduct', 'Rules that apply to respectful behavior across the site.', 30, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `app_rule_categories` WHERE `slug` = 'community-conduct');

INSERT INTO `app_rules` (`category_id`, `title`, `slug`, `body`, `sort_order`, `is_active`, `created_at`, `updated_at`)
SELECT c.`id`, 'Keep your account information accurate', 'keep-your-account-information-accurate', 'Use a valid email address, keep your account details current, and do not share your account with other users.', 10, 1, NOW(), NOW()
FROM `app_rule_categories` c
WHERE c.`slug` = 'account-rules'
  AND NOT EXISTS (SELECT 1 FROM `app_rules` WHERE `slug` = 'keep-your-account-information-accurate');

INSERT INTO `app_rules` (`category_id`, `title`, `slug`, `body`, `sort_order`, `is_active`, `created_at`, `updated_at`)
SELECT c.`id`, 'Upload only content you are allowed to share', 'upload-only-content-you-are-allowed-to-share', 'Do not upload content you do not have permission to post. Follow all content ratings, moderation requests, and tagging requirements.', 10, 1, NOW(), NOW()
FROM `app_rule_categories` c
WHERE c.`slug` = 'content-rules'
  AND NOT EXISTS (SELECT 1 FROM `app_rules` WHERE `slug` = 'upload-only-content-you-are-allowed-to-share');

INSERT INTO `app_rules` (`category_id`, `title`, `slug`, `body`, `sort_order`, `is_active`, `created_at`, `updated_at`)
SELECT c.`id`, 'Treat other members respectfully', 'treat-other-members-respectfully', 'Harassment, targeted abuse, and repeated disruptive behavior are not allowed. Follow moderator instructions and use the report system in good faith.', 10, 1, NOW(), NOW()
FROM `app_rule_categories` c
WHERE c.`slug` = 'community-conduct'
  AND NOT EXISTS (SELECT 1 FROM `app_rules` WHERE `slug` = 'treat-other-members-respectfully');

INSERT INTO `app_rule_releases` (`version_label`, `summary`, `grace_days`, `published_by`, `published_at`, `created_at`, `updated_at`)
SELECT 'Initial Rules Release', 'Initial site rules release created during the rules-system rollout.', CAST(COALESCE((SELECT `value` FROM `app_settings_data` WHERE `key` = 'rules.enforcement_window_days' LIMIT 1), '14') AS UNSIGNED), NULL, NOW(), NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `app_rule_releases` LIMIT 1);
