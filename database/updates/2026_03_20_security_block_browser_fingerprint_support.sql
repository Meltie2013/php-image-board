-- Add browser fingerprint support to the block list so admin tools can create,
-- review, and enforce browser-level records alongside device fingerprints.

ALTER TABLE `app_block_list`
    MODIFY `scope` enum('ip','fingerprint','device_fingerprint','browser_fingerprint','ua','user_id') NOT NULL,
    ADD COLUMN `browser_fingerprint` char(64) DEFAULT NULL AFTER `device_fingerprint`;

ALTER TABLE `app_block_list`
    ADD KEY `idx_browser_fingerprint` (`browser_fingerprint`);
