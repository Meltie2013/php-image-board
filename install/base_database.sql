-- PHP Image Board
-- Base Database Schema
--
-- Reformatted for cleaner project use.
-- Notes:
-- - Preserves the original schema structure and install order.
-- - Includes seed data for roles and the database-backed settings registry.
-- - Seeds app_settings_categories and app_settings_data with the built-in ACP settings metadata.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Table structure for table `app_block_list`
--

CREATE TABLE `app_block_list` (
    `id` bigint(20) NOT NULL,
    `scope` enum('ip','fingerprint','device_fingerprint','browser_fingerprint','ua','user_id') NOT NULL,
    `value_hash` char(64) NOT NULL,
    `user_id` bigint(20) UNSIGNED DEFAULT NULL,
    `ip` varbinary(16) DEFAULT NULL,
    `ua` varchar(255) DEFAULT NULL,
    `fingerprint` char(64) DEFAULT NULL,
    `device_fingerprint` char(64) DEFAULT NULL,
    `browser_fingerprint` char(64) DEFAULT NULL,
    `status` enum('blocked','banned','rate_limited','jailed') NOT NULL DEFAULT 'blocked',
    `reason` varchar(255) DEFAULT NULL,
    `created_at` datetime NOT NULL,
    `last_seen` datetime NOT NULL,
    `expires_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_images`
--

CREATE TABLE `app_images` (
    `id` bigint(20) UNSIGNED NOT NULL,
    `image_hash` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `user_id` bigint(20) UNSIGNED NOT NULL,
    `description` text DEFAULT NULL,
    `status` enum('pending','approved','rejected','deleted') NOT NULL DEFAULT 'pending',
    `age_sensitive` tinyint(1) NOT NULL DEFAULT 0,
    `content_rating` enum('standard','sensitive','explicit') NOT NULL DEFAULT 'standard',
    `original_path` varchar(255) NOT NULL,
    `mime_type` varchar(100) NOT NULL,
    `width` int(10) UNSIGNED DEFAULT NULL,
    `height` int(10) UNSIGNED DEFAULT NULL,
    `size_bytes` bigint(20) UNSIGNED DEFAULT 0,
    `md5` char(32) DEFAULT NULL,
    `sha1` char(40) DEFAULT NULL,
    `sha256` char(64) DEFAULT NULL,
    `sha512` char(128) DEFAULT NULL,
    `rehashed` tinyint(1) NOT NULL DEFAULT 0,
    `rehashed_on` datetime DEFAULT NULL,
    `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
    `rejected_by` bigint(20) UNSIGNED DEFAULT NULL,
    `moderated_at` datetime DEFAULT NULL,
    `reject_reason` text DEFAULT NULL,
    `views` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_image_comments`
--

CREATE TABLE `app_image_comments` (
    `id` bigint(20) UNSIGNED NOT NULL,
    `image_id` bigint(20) UNSIGNED NOT NULL,
    `user_id` bigint(20) UNSIGNED NOT NULL,
    `comment_body` text NOT NULL,
    `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
    `deleted_at` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_image_favorites`
--

CREATE TABLE `app_image_favorites` (
    `id` bigint(20) UNSIGNED NOT NULL,
    `user_id` bigint(20) UNSIGNED NOT NULL,
    `image_id` bigint(20) UNSIGNED NOT NULL,
    `favorited_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_image_reports`
--

CREATE TABLE `app_image_reports` (
    `id` bigint(20) UNSIGNED NOT NULL,
    `image_id` bigint(20) UNSIGNED NOT NULL,
    `reporter_user_id` bigint(20) UNSIGNED DEFAULT NULL,
    `report_category` varchar(64) NOT NULL DEFAULT 'other',
    `report_subject` varchar(150) NOT NULL,
    `report_message` text NOT NULL,
    `status` enum('open','closed') NOT NULL DEFAULT 'open',
    `session_id` varchar(128) DEFAULT NULL,
    `ip` varbinary(16) DEFAULT NULL,
    `ua` varchar(255) DEFAULT NULL,
    `resolved_by` bigint(20) UNSIGNED DEFAULT NULL,
    `resolved_at` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_image_hashes`
--

CREATE TABLE `app_image_hashes` (
    `image_hash` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `ahash` char(64) NOT NULL,
    `dhash` char(64) NOT NULL,
    `phash` char(64) NOT NULL,
    `phash_block_0` char(4) DEFAULT NULL,
    `phash_block_1` char(4) DEFAULT NULL,
    `phash_block_2` char(4) DEFAULT NULL,
    `phash_block_3` char(4) DEFAULT NULL,
    `phash_block_4` char(4) DEFAULT NULL,
    `phash_block_5` char(4) DEFAULT NULL,
    `phash_block_6` char(4) DEFAULT NULL,
    `phash_block_7` char(4) DEFAULT NULL,
    `phash_block_8` char(4) DEFAULT NULL,
    `phash_block_9` char(4) DEFAULT NULL,
    `phash_block_10` char(4) DEFAULT NULL,
    `phash_block_11` char(4) DEFAULT NULL,
    `phash_block_12` char(4) DEFAULT NULL,
    `phash_block_13` char(4) DEFAULT NULL,
    `phash_block_14` char(4) DEFAULT NULL,
    `phash_block_15` char(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_image_upload_logs`
--

CREATE TABLE `app_image_upload_logs` (
    `id` bigint(20) UNSIGNED NOT NULL,
    `user_id` bigint(20) UNSIGNED DEFAULT NULL,
    `ip_address` varchar(45) NOT NULL,
    `filename_original` varchar(255) NOT NULL,
    `mime_reported` varchar(100) DEFAULT NULL,
    `mime_detected` varchar(100) DEFAULT NULL,
    `file_extension` varchar(10) DEFAULT NULL,
    `file_size` bigint(20) UNSIGNED NOT NULL,
    `status` enum('success','failed') NOT NULL,
    `failure_reason` text DEFAULT NULL,
    `stored_path` varchar(255) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_image_votes`
--

CREATE TABLE `app_image_votes` (
    `id` bigint(20) UNSIGNED NOT NULL,
    `user_id` bigint(20) UNSIGNED NOT NULL,
    `image_id` bigint(20) UNSIGNED NOT NULL,
    `upvoted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_rate_counters`
--

CREATE TABLE `app_rate_counters` (
    `scope` varchar(64) NOT NULL,
    `key_hash` char(64) NOT NULL,
    `window_start` datetime NOT NULL,
    `hits` int(11) NOT NULL DEFAULT 0,
    `expires_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_groups`
--

CREATE TABLE `app_groups` (
    `id` tinyint(3) UNSIGNED NOT NULL,
    `name` varchar(80) NOT NULL,
    `slug` varchar(80) NOT NULL,
    `description` varchar(255) DEFAULT NULL,
    `is_built_in` tinyint(1) NOT NULL DEFAULT 0,
    `is_assignable` tinyint(1) NOT NULL DEFAULT 1,
    `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `app_groups`
--

INSERT INTO `app_groups` (`id`, `name`, `slug`, `description`, `is_built_in`, `is_assignable`, `sort_order`) VALUES
    (1, 'Site Administrator', 'site-administrator', 'Full owner-level access to all ACP modules, groups, and permissions.', 1, 1, 10),
    (2, 'Administrator', 'administrator', 'Administrative access for normal site administrators without owner-only group controls.', 1, 1, 20),
    (3, 'Site Moderator', 'site-moderator', 'Site-wide moderation group covering all moderation areas.', 1, 1, 30),
    (4, 'Forum Moderator', 'forum-moderator', 'Forum-specific moderation group reserved for forum moderation tools.', 1, 1, 40),
    (5, 'Image Moderator', 'image-moderator', 'Gallery-specific moderation group for uploads, reports, and image actions.', 1, 1, 50),
    (6, 'Member', 'member', 'Standard member access for normal user accounts.', 1, 1, 60),
    (7, 'Banned', 'banned', 'Reserved non-assignable group used for banned accounts.', 1, 0, 70);

-- --------------------------------------------------------

--
-- Table structure for table `app_group_permissions`
--

CREATE TABLE `app_group_permissions` (
    `id` bigint(20) UNSIGNED NOT NULL,
    `group_id` tinyint(3) UNSIGNED NOT NULL,
    `permission_token` varchar(80) NOT NULL,
    `permission_value` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `app_group_permissions`
--

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
    (6, 'edit_own_image', 1),
    (7, 'view_gallery', 0),
    (7, 'upload_images', 0),
    (7, 'comment_images', 0),
    (7, 'report_images', 0),
    (7, 'vote_images', 0),
    (7, 'favorite_images', 0),
    (7, 'edit_own_image', 0),
    (7, 'edit_any_image', 0),
    (7, 'access_control_panel', 0),
    (7, 'manage_users', 0),
    (7, 'manage_groups', 0),
    (7, 'manage_group_permissions', 0),
    (7, 'manage_settings', 0),
    (7, 'view_security', 0),
    (7, 'manage_block_list', 0),
    (7, 'moderate_site', 0),
    (7, 'moderate_forums', 0),
    (7, 'moderate_gallery', 0),
    (7, 'moderate_image_queue', 0),
    (7, 'manage_image_reports', 0),
    (7, 'compare_images', 0),
    (7, 'rehash_images', 0);

-- --------------------------------------------------------

--
-- Table structure for table `app_security_logs`
--

CREATE TABLE `app_security_logs` (
    `id` bigint(20) NOT NULL,
    `user_id` bigint(20) UNSIGNED DEFAULT NULL,
    `session_id` varchar(128) DEFAULT NULL,
    `ip` varbinary(16) DEFAULT NULL,
    `ua` varchar(255) DEFAULT NULL,
    `fingerprint` char(64) DEFAULT NULL,
    `device_fingerprint` char(64) DEFAULT NULL,
    `browser_fingerprint` char(64) DEFAULT NULL,
    `category` varchar(64) NOT NULL,
    `message` varchar(255) NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_sessions`
--

CREATE TABLE `app_sessions` (
    `session_id` varchar(128) NOT NULL,
    `user_id` bigint(20) UNSIGNED DEFAULT NULL,
    `ip` varbinary(16) DEFAULT NULL,
    `first_ip` varbinary(16) DEFAULT NULL,
    `ua` varchar(255) DEFAULT NULL,
    `fingerprint` char(64) DEFAULT NULL,
    `device_fingerprint` char(64) DEFAULT NULL,
    `browser_fingerprint` char(64) DEFAULT NULL,
    `session_binding_hash` char(64) DEFAULT NULL,
    `last_activity` datetime NOT NULL,
    `expires_at` datetime DEFAULT NULL,
    `data` mediumblob DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_settings_categories`
--

CREATE TABLE `app_settings_categories` (
    `id` bigint(20) UNSIGNED NOT NULL,
    `slug` varchar(64) NOT NULL,
    `title` varchar(80) NOT NULL,
    `description` varchar(255) NOT NULL DEFAULT '',
    `icon` varchar(32) NOT NULL DEFAULT 'fa-sliders',
    `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
    `is_system` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `app_settings_categories`
--

INSERT INTO `app_settings_categories` (`id`, `slug`, `title`, `description`, `icon`, `sort_order`, `is_system`) VALUES
    (1, 'debugging', 'Debugging', 'Developer-only switches used while troubleshooting uploads, rendering, and runtime issues.', 'fa-bug', 10, 1),
    (2, 'gallery', 'Gallery', 'Controls gallery page sizing, pagination, comments, and upload storage limits.', 'fa-images', 20, 1),
    (3, 'profile', 'User Profile', 'Profile presentation defaults, avatar sizing, and age-gate requirements.', 'fa-id-card', 30, 1),
    (4, 'site', 'Site', 'Board identity, naming, and build metadata exposed across the public interface.', 'fa-window-maximize', 40, 1),
    (5, 'template', 'Template', 'Template engine behavior, cache handling, and approved helper functions.', 'fa-code', 50, 1),
    (6, 'upload', 'Upload', 'Controls how new upload hashes are generated and how upload behavior is presented.', 'fa-upload', 60, 1);

-- --------------------------------------------------------

--
-- Table structure for table `app_settings_data`
--

CREATE TABLE `app_settings_data` (
    `id` bigint(20) UNSIGNED NOT NULL,
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
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `app_settings_data`
--

INSERT INTO `app_settings_data` (`id`, `category_id`, `key`, `title`, `description`, `value`, `type`, `input_type`, `sort_order`, `is_system`) VALUES
    (1, 1, 'debugging.allow_approve_uploads', 'Allow Upload Auto Approval', 'Lets uploads skip the normal review flow. Keep this disabled unless you are intentionally testing approval behavior on a trusted environment.', '0', 'bool', 'bool', 10, 1),
    (2, 1, 'debugging.allow_error_outputs', 'Display PHP Errors', 'Shows PHP errors directly in the browser while debugging application issues.', '0', 'bool', 'bool', 20, 1),
    (3, 2, 'gallery.comments_per_page', 'Comments Per Page', 'Controls how many comments appear at once on the gallery image view page before pagination is used.', '5', 'int', 'number', 10, 1),
    (4, 2, 'gallery.images_displayed', 'Images Per Gallery Page', 'Sets how many images are shown on each gallery page before the next page is created.', '24', 'int', 'number', 20, 1),
    (5, 2, 'gallery.pagination_range', 'Pagination Range', 'Determines how many page links appear on each side of the current page in gallery pagination.', '3', 'int', 'number', 30, 1),
    (6, 2, 'gallery.upload_max_image_size', 'Maximum Upload Size (MB)', 'Sets the largest image file size allowed for a single upload, measured in megabytes.', '3', 'int', 'number', 40, 1),
    (7, 2, 'gallery.upload_max_storage', 'Maximum Gallery Storage', 'Defines the total storage available for uploaded images using shorthand values such as 500mb, 10gb, or 1tb.', '10gb', 'string', 'text', 50, 1),
    (8, 3, 'profile.avatar_size', 'Default Avatar Size', 'Sets the default avatar display size in pixels for user profile areas.', '250', 'int', 'number', 10, 1),
    (9, 3, 'profile.years', 'Sensitive Content Age Requirement', 'Defines the minimum age required for users to view content protected by the board age gate.', '13', 'int', 'number', 20, 1),
    (10, 4, 'site.name', 'Site Name', 'Primary board name shown in the header, footer, page titles, and shared templates.', 'PHP Image Board', 'string', 'text', 10, 1),
    (11, 4, 'site.version', 'Build Version', 'Version or build string shown in the interface for release tracking and support reference.', '0.2.3', 'string', 'text', 20, 1),
    (12, 5, 'template.allowed_functions', 'Allowed Template Functions', 'JSON array of trusted PHP function names that templates may call. This list should stay minimal and only contain simple, safe helpers.', '["strtoupper","strtolower","ucfirst","lcfirst"]', 'json', 'json', 10, 1),
    (13, 5, 'template.disable_cache', 'Disable Template Cache', 'Turns off compiled template caching so visual and template updates appear immediately during development.', '1', 'bool', 'bool', 20, 1),
    (14, 6, 'upload.hash_type', 'Generated Image Hash Format', 'Chooses the character format used when generating new image hashes for uploads.', 'mixed_lower', 'string', 'select', 10, 1);

-- --------------------------------------------------------

--
-- Table structure for table `app_client_signals`
--

CREATE TABLE `app_client_signals` (
    `id` bigint(20) UNSIGNED NOT NULL,
    `user_id` bigint(20) UNSIGNED DEFAULT NULL,
    `session_id` varchar(128) DEFAULT NULL,
    `ip` varbinary(16) DEFAULT NULL,
    `device_fingerprint` char(64) DEFAULT NULL,
    `browser_fingerprint` char(64) DEFAULT NULL,
    `signal_hash` char(64) NOT NULL,
    `signal_payload` text DEFAULT NULL,
    `event_type` varchar(32) NOT NULL DEFAULT 'seen',
    `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_device_overrides`
--

CREATE TABLE `app_device_overrides` (
    `id` bigint(20) UNSIGNED NOT NULL,
    `device_fingerprint` char(64) NOT NULL,
    `browser_fingerprint` char(64) DEFAULT NULL,
    `label` varchar(100) DEFAULT NULL,
    `allow_multi_account` tinyint(1) NOT NULL DEFAULT 1,
    `max_accounts` int(10) UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_updates`
--

CREATE TABLE `app_updates` (
    `id` int(11) NOT NULL,
    `filename` varchar(255) NOT NULL,
    `applied_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_users`
--

CREATE TABLE `app_users` (
    `id` bigint(20) UNSIGNED NOT NULL,
    `group_id` tinyint(3) UNSIGNED NOT NULL DEFAULT 6,
    `username` varchar(50) NOT NULL,
    `display_name` varchar(100) DEFAULT NULL,
    `email` varchar(191) NOT NULL,
    `date_of_birth` date DEFAULT NULL,
    `password_hash` varchar(255) NOT NULL,
    `avatar_path` varchar(255) DEFAULT NULL,
    `age_verified_at` datetime DEFAULT NULL,
    `age_gate_status` enum('not_started','self_served','forced_review','verified','restricted_minor') NOT NULL DEFAULT 'not_started',
    `age_gate_method` enum('none','self_serve','dob_forced','dob_optional','admin_restricted') NOT NULL DEFAULT 'none',
    `mature_content_acknowledged_at` datetime DEFAULT NULL,
    `age_gate_forced_by` bigint(20) UNSIGNED DEFAULT NULL,
    `age_gate_forced_at` datetime DEFAULT NULL,
    `age_gate_force_reason` varchar(255) DEFAULT NULL,
    `status` enum('active','pending','suspended','deleted') NOT NULL DEFAULT 'pending',
    `failed_logins` int(10) UNSIGNED NOT NULL DEFAULT 0,
    `last_failed_login` datetime DEFAULT NULL,
    `last_login` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_user_devices`
--

CREATE TABLE `app_user_devices` (
    `id` bigint(20) UNSIGNED NOT NULL,
    `user_id` bigint(20) UNSIGNED NOT NULL,
    `device_fingerprint` char(64) NOT NULL,
    `browser_fingerprint` char(64) DEFAULT NULL,
    `first_seen_at` datetime NOT NULL,
    `last_seen_at` datetime NOT NULL,
    `first_ip` varbinary(16) DEFAULT NULL,
    `last_ip` varbinary(16) DEFAULT NULL,
    `user_agent_hash` char(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `app_block_list`
--
ALTER TABLE `app_block_list`
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `uniq_scope_hash` (`scope`,`value_hash`),
    ADD KEY `idx_expires_at` (`expires_at`),
    ADD KEY `idx_user_id` (`user_id`),
    ADD KEY `idx_ip` (`ip`),
    ADD KEY `idx_fingerprint` (`fingerprint`),
    ADD KEY `idx_device_fingerprint` (`device_fingerprint`),
    ADD KEY `idx_browser_fingerprint` (`browser_fingerprint`);

--
-- Indexes for table `app_images`
--
ALTER TABLE `app_images`
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `image_hash` (`image_hash`),
    ADD KEY `fk_images_user` (`user_id`),
    ADD KEY `image_hash_idx` (`image_hash`);

--
-- Indexes for table `app_image_comments`
--
ALTER TABLE `app_image_comments`
    ADD PRIMARY KEY (`id`),
    ADD KEY `idx_comments_image_id` (`image_id`),
    ADD KEY `idx_comments_user_id` (`user_id`),
    ADD KEY `idx_comments_created_at` (`created_at`);

--
-- Indexes for table `app_image_favorites`
--
ALTER TABLE `app_image_favorites`
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `unique_favorite` (`user_id`,`image_id`),
    ADD KEY `fk_favorite_image` (`image_id`);

--
-- Indexes for table `app_image_reports`
--
ALTER TABLE `app_image_reports`
    ADD PRIMARY KEY (`id`),
    ADD KEY `idx_image_id` (`image_id`),
    ADD KEY `idx_reporter_user_id` (`reporter_user_id`),
    ADD KEY `idx_status` (`status`),
    ADD KEY `idx_created_at` (`created_at`),
    ADD KEY `idx_resolved_by` (`resolved_by`);

--
-- Indexes for table `app_image_hashes`
--
ALTER TABLE `app_image_hashes`
    ADD PRIMARY KEY (`image_hash`) USING BTREE;

--
-- Indexes for table `app_image_upload_logs`
--
ALTER TABLE `app_image_upload_logs`
    ADD PRIMARY KEY (`id`),
    ADD KEY `idx_user_id` (`user_id`),
    ADD KEY `idx_ip_address` (`ip_address`),
    ADD KEY `idx_status` (`status`),
    ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `app_image_votes`
--
ALTER TABLE `app_image_votes`
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `unique_vote` (`user_id`,`image_id`),
    ADD KEY `fk_votes_image` (`image_id`);

--
-- Indexes for table `app_rate_counters`
--
ALTER TABLE `app_rate_counters`
    ADD UNIQUE KEY `uniq_scope_key_window` (`scope`,`key_hash`,`window_start`),
    ADD KEY `idx_expires_at` (`expires_at`);

--
-- Indexes for table `app_groups`
--
ALTER TABLE `app_groups`
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `uniq_app_groups_name` (`name`),
    ADD UNIQUE KEY `uniq_app_groups_slug` (`slug`);

--
-- Indexes for table `app_group_permissions`
--
ALTER TABLE `app_group_permissions`
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `uniq_group_permission` (`group_id`,`permission_token`),
    ADD KEY `idx_permission_token` (`permission_token`);

--
-- Indexes for table `app_security_logs`
--
ALTER TABLE `app_security_logs`
    ADD PRIMARY KEY (`id`),
    ADD KEY `idx_user_id` (`user_id`),
    ADD KEY `idx_created_at` (`created_at`),
    ADD KEY `idx_category` (`category`),
    ADD KEY `idx_device_fingerprint` (`device_fingerprint`),
    ADD KEY `idx_browser_fingerprint` (`browser_fingerprint`);

--
-- Indexes for table `app_sessions`
--
ALTER TABLE `app_sessions`
    ADD PRIMARY KEY (`session_id`),
    ADD KEY `fk_sessions_user` (`user_id`),
    ADD KEY `idx_sessions_device_fingerprint` (`device_fingerprint`),
    ADD KEY `idx_sessions_browser_fingerprint` (`browser_fingerprint`);

--
-- Indexes for table `app_settings_categories`
--
ALTER TABLE `app_settings_categories`
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `uniq_settings_categories_slug` (`slug`);

--
-- Indexes for table `app_settings_data`
--
ALTER TABLE `app_settings_data`
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `uniq_settings_data_key` (`key`),
    ADD KEY `idx_settings_data_category_id` (`category_id`),
    ADD KEY `idx_settings_data_category_sort` (`category_id`,`sort_order`);

--
-- Indexes for table `app_updates`
--
ALTER TABLE `app_updates`
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `filename` (`filename`);

--
-- Indexes for table `app_users`
--
ALTER TABLE `app_users`
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `username` (`username`),
    ADD UNIQUE KEY `email` (`email`),
    ADD KEY `fk_users_group` (`group_id`);

--
-- Indexes for table `app_user_devices`
--
ALTER TABLE `app_user_devices`
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `uniq_user_device` (`user_id`,`device_fingerprint`),
    ADD KEY `idx_device_fingerprint` (`device_fingerprint`),
    ADD KEY `idx_browser_fingerprint` (`browser_fingerprint`),
    ADD KEY `idx_device_browser_fingerprint` (`device_fingerprint`,`browser_fingerprint`),
    ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `app_client_signals`
--
ALTER TABLE `app_client_signals`
    ADD PRIMARY KEY (`id`),
    ADD KEY `idx_user_id` (`user_id`),
    ADD KEY `idx_session_id` (`session_id`),
    ADD KEY `idx_device_fingerprint` (`device_fingerprint`),
    ADD KEY `idx_browser_fingerprint` (`browser_fingerprint`),
    ADD KEY `idx_signal_hash` (`signal_hash`),
    ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `app_device_overrides`
--
ALTER TABLE `app_device_overrides`
    ADD PRIMARY KEY (`id`),
    ADD KEY `idx_device_fingerprint` (`device_fingerprint`),
    ADD KEY `idx_browser_fingerprint` (`browser_fingerprint`),
    ADD KEY `idx_expires_at` (`expires_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `app_block_list`
--
ALTER TABLE `app_block_list`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `app_images`
--
ALTER TABLE `app_images`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `app_image_comments`
--
ALTER TABLE `app_image_comments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `app_image_favorites`
--
ALTER TABLE `app_image_favorites`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `app_image_reports`
--
ALTER TABLE `app_image_reports`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `app_image_upload_logs`
--
ALTER TABLE `app_image_upload_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `app_image_votes`
--
ALTER TABLE `app_image_votes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `app_groups`
--
ALTER TABLE `app_groups`
  MODIFY `id` tinyint(3) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `app_group_permissions`
--
ALTER TABLE `app_group_permissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `app_security_logs`
--
ALTER TABLE `app_security_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `app_updates`
--
ALTER TABLE `app_client_signals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `app_device_overrides`
--
ALTER TABLE `app_device_overrides`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `app_settings_categories`
--
ALTER TABLE `app_settings_categories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `app_settings_data`
--
ALTER TABLE `app_settings_data`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `app_updates`
--
ALTER TABLE `app_updates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `app_users`
--
ALTER TABLE `app_users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `app_user_devices`
--
ALTER TABLE `app_user_devices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `app_settings_data`
--
ALTER TABLE `app_settings_data`
    ADD CONSTRAINT `fk_settings_data_category` FOREIGN KEY (`category_id`) REFERENCES `app_settings_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `app_images`
--
ALTER TABLE `app_images`
    ADD CONSTRAINT `fk_images_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `app_image_comments`
--
ALTER TABLE `app_image_comments`
    ADD CONSTRAINT `fk_comments_image_id` FOREIGN KEY (`image_id`) REFERENCES `app_images` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_comments_user_id` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `app_image_favorites`
--
ALTER TABLE `app_image_favorites`
    ADD CONSTRAINT `fk_favorite_image` FOREIGN KEY (`image_id`) REFERENCES `app_images` (`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `fk_favorite_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `app_image_reports`
--
ALTER TABLE `app_image_reports`
    ADD CONSTRAINT `fk_image_reports_image` FOREIGN KEY (`image_id`) REFERENCES `app_images` (`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `fk_image_reports_reporter` FOREIGN KEY (`reporter_user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_image_reports_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `app_users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `app_image_hashes`
--
ALTER TABLE `app_image_hashes`
    ADD CONSTRAINT `app_image_hashes_ibfk_1` FOREIGN KEY (`image_hash`) REFERENCES `app_images` (`image_hash`) ON DELETE CASCADE;

--
-- Constraints for table `app_image_upload_logs`
--
ALTER TABLE `app_image_upload_logs`
    ADD CONSTRAINT `fk_account_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `app_image_votes`
--
ALTER TABLE `app_image_votes`
    ADD CONSTRAINT `fk_votes_image` FOREIGN KEY (`image_id`) REFERENCES `app_images` (`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `fk_votes_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `app_sessions`
--
ALTER TABLE `app_sessions`
    ADD CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `app_users`
--
ALTER TABLE `app_users`
    ADD CONSTRAINT `fk_users_group` FOREIGN KEY (`group_id`) REFERENCES `app_groups` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `app_group_permissions`
--
ALTER TABLE `app_group_permissions`
    ADD CONSTRAINT `fk_group_permissions_group` FOREIGN KEY (`group_id`) REFERENCES `app_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `app_user_devices`
--
ALTER TABLE `app_client_signals`
    ADD CONSTRAINT `fk_client_signals_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `app_user_devices`
--
ALTER TABLE `app_user_devices`
    ADD CONSTRAINT `fk_user_devices_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
