-- phpMyAdmin SQL Dump
-- version 5.1.1deb5ubuntu1
-- https://www.phpmyadmin.net/


SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Table structure for table `app_images`
--

CREATE TABLE `app_images` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `image_hash` varchar(50) NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','deleted') NOT NULL DEFAULT 'pending',
  `age_sensitive` tinyint(1) NOT NULL DEFAULT 0,
  `original_path` varchar(255) NOT NULL,
  `has_variants` tinyint(1) NOT NULL DEFAULT 0,
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
  `favorites` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_image_hashes`
--

CREATE TABLE `app_image_hashes` (
  `image_hash` varchar(50) NOT NULL,
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
-- Table structure for table `app_roles`
--

CREATE TABLE `app_roles` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `app_roles`
--

INSERT INTO `app_roles` (`id`, `name`, `description`) VALUES
(1, 'Administrator', ''),
(2, 'Moderator', ''),
(3, 'Member', '');

-- --------------------------------------------------------

--
-- Table structure for table `app_sessions`
--

CREATE TABLE `app_sessions` (
  `session_id` varchar(128) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ip` varbinary(16) DEFAULT NULL,
  `ua` varchar(255) DEFAULT NULL,
  `last_activity` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `data` mediumblob DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `app_users`
--

CREATE TABLE `app_users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `role_id` tinyint(3) UNSIGNED NOT NULL DEFAULT 3,
  `username` varchar(50) NOT NULL,
  `display_name` varchar(100) DEFAULT NULL,
  `email` varchar(191) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `age_verified_at` datetime DEFAULT NULL,
  `status` enum('active','pending','suspended','deleted') NOT NULL DEFAULT 'pending',
  `failed_logins` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_failed_login` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `app_images`
--
ALTER TABLE `app_images`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `image_hash` (`image_hash`),
  ADD KEY `fk_images_user` (`user_id`);

--
-- Indexes for table `app_image_hashes`
--
ALTER TABLE `app_image_hashes`
  ADD PRIMARY KEY (`image_hash`);

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
-- Indexes for table `app_roles`
--
ALTER TABLE `app_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `app_sessions`
--
ALTER TABLE `app_sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `fk_sessions_user` (`user_id`);

--
-- Indexes for table `app_users`
--
ALTER TABLE `app_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_users_role` (`role_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `app_images`
--
ALTER TABLE `app_images`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `app_image_upload_logs`
--
ALTER TABLE `app_image_upload_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `app_roles`
--
ALTER TABLE `app_roles`
  MODIFY `id` tinyint(3) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `app_users`
--
ALTER TABLE `app_users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `app_images`
--
ALTER TABLE `app_images`
  ADD CONSTRAINT `fk_images_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE;

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
-- Constraints for table `app_sessions`
--
ALTER TABLE `app_sessions`
  ADD CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `app_users`
--
ALTER TABLE `app_users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `app_roles` (`id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
