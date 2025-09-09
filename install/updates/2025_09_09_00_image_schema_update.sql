-- Disable foreign key checks to avoid temporary constraint issues
SET FOREIGN_KEY_CHECKS = 0;

-- Drop the foreign key constraint on app_image_hashes
ALTER TABLE `app_image_hashes`
  DROP FOREIGN KEY `app_image_hashes_ibfk_1`;

-- Alter parent table (app_images) column to case-sensitive
ALTER TABLE `app_images`
  MODIFY `image_hash` varchar(50) NOT NULL COLLATE utf8mb4_bin;

-- Alter child table (app_image_hashes) column to case-sensitive
ALTER TABLE `app_image_hashes`
  MODIFY `image_hash` varchar(50) NOT NULL COLLATE utf8mb4_bin;

-- Re-add the foreign key constraint
ALTER TABLE `app_image_hashes`
  ADD CONSTRAINT `app_image_hashes_ibfk_1`
    FOREIGN KEY (`image_hash`)
    REFERENCES `app_images`(`image_hash`)
    ON DELETE CASCADE;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;
