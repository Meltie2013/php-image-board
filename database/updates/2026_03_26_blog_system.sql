-- Blog System Migration
--
-- Creates staff-managed blog posts and the public comment thread shown under
-- each published post.

CREATE TABLE IF NOT EXISTS `app_blog_posts` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` bigint(20) UNSIGNED NOT NULL,
    `slug` varchar(120) NOT NULL,
    `title` varchar(180) NOT NULL,
    `excerpt` text DEFAULT NULL,
    `body` mediumtext NOT NULL,
    `status` enum('draft','published','hidden','deleted') NOT NULL DEFAULT 'draft',
    `allow_comments` tinyint(1) NOT NULL DEFAULT 1,
    `published_by` bigint(20) UNSIGNED DEFAULT NULL,
    `published_at` datetime DEFAULT NULL,
    `deleted_by` bigint(20) UNSIGNED DEFAULT NULL,
    `deleted_at` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_app_blog_posts_slug` (`slug`),
    KEY `idx_app_blog_posts_user` (`user_id`),
    KEY `idx_app_blog_posts_status` (`status`),
    KEY `idx_app_blog_posts_published_at` (`published_at`),
    KEY `idx_app_blog_posts_published_by` (`published_by`),
    KEY `idx_app_blog_posts_deleted_by` (`deleted_by`),
    CONSTRAINT `fk_blog_posts_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_blog_posts_published_by` FOREIGN KEY (`published_by`) REFERENCES `app_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_blog_posts_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `app_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `app_blog_comments` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `post_id` bigint(20) UNSIGNED NOT NULL,
    `user_id` bigint(20) UNSIGNED NOT NULL,
    `comment_body` text NOT NULL,
    `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
    `deleted_at` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_app_blog_comments_post` (`post_id`),
    KEY `idx_app_blog_comments_user` (`user_id`),
    KEY `idx_app_blog_comments_created_at` (`created_at`),
    CONSTRAINT `fk_blog_comments_post` FOREIGN KEY (`post_id`) REFERENCES `app_blog_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_blog_comments_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `app_group_permissions` (`group_id`, `permission_token`, `permission_value`)
SELECT g.id, 'manage_blog_posts', IF(g.slug IN ('site-administrator', 'administrator', 'site-moderator'), 1, 0)
FROM `app_groups` g
WHERE NOT EXISTS (
    SELECT 1
    FROM `app_group_permissions` gp
    WHERE gp.`group_id` = g.`id`
      AND gp.`permission_token` = 'manage_blog_posts'
);

INSERT INTO `app_group_permissions` (`group_id`, `permission_token`, `permission_value`)
SELECT g.id, 'comment_blog_posts', IF(g.slug IN ('site-administrator', 'administrator', 'site-moderator', 'forum-moderator', 'image-moderator', 'member'), 1, 0)
FROM `app_groups` g
WHERE NOT EXISTS (
    SELECT 1
    FROM `app_group_permissions` gp
    WHERE gp.`group_id` = g.`id`
      AND gp.`permission_token` = 'comment_blog_posts'
);

INSERT INTO `app_settings_categories` (`slug`, `title`, `description`, `icon`, `sort_order`, `is_system`, `created_at`, `updated_at`)
SELECT 'blog', 'Blog', 'Controls blog post pagination, comment sizing, and staff publishing defaults.', 'fa-newspaper', 35, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `app_settings_categories` WHERE `slug` = 'blog');

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`, `created_at`, `updated_at`)
SELECT c.`id`, 'blog.posts_per_page', 'Blog Posts Per Page', 'Sets how many published blog posts appear on each public blog page before pagination is used.', '10', 'int', 'number', 10, 1, NOW(), NOW()
FROM `app_settings_categories` c
WHERE c.`slug` = 'blog'
  AND NOT EXISTS (SELECT 1 FROM `app_settings_data` WHERE `key` = 'blog.posts_per_page');

INSERT INTO `app_settings_data` (`category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`, `created_at`, `updated_at`)
SELECT c.`id`, 'blog.comments_per_page', 'Blog Comments Per Page', 'Controls how many comments appear at once under a published blog post before pagination is used.', '10', 'int', 'number', 20, 1, NOW(), NOW()
FROM `app_settings_categories` c
WHERE c.`slug` = 'blog'
  AND NOT EXISTS (SELECT 1 FROM `app_settings_data` WHERE `key` = 'blog.comments_per_page');
