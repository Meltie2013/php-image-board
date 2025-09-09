-- Add votes column to app_images
ALTER TABLE `app_images`
    ADD COLUMN `votes` bigint(20) UNSIGNED NOT NULL DEFAULT 0 AFTER `favorites`;

-- Add new votes table
CREATE TABLE `app_image_votes` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `image_id` bigint(20) UNSIGNED NOT NULL,
  `upvoted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_vote` (`user_id`,`image_id`),
  CONSTRAINT `fk_votes_user` FOREIGN KEY (`user_id`) REFERENCES `app_users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_votes_image` FOREIGN KEY (`image_id`) REFERENCES `app_images`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add new favorite table
CREATE TABLE `app_image_favorites` (
    `id` BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT(20) UNSIGNED NOT NULL,
    `image_id` BIGINT(20) UNSIGNED NOT NULL,
    `favorited_at` datetime NOT NULL DEFAULT current_timestamp(),
    UNIQUE KEY `unique_favorite` (`user_id`, `image_id`),
    CONSTRAINT `fk_favorite_user` FOREIGN KEY (`user_id`) REFERENCES `app_users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_favorite_image` FOREIGN KEY (`image_id`) REFERENCES `app_images`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

