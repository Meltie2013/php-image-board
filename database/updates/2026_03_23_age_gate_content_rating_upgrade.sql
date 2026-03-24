-- Age gate, content rating, and birthday badge foundation.

ALTER TABLE `app_images`
    ADD COLUMN `content_rating` enum('standard','sensitive','explicit') NOT NULL DEFAULT 'standard' AFTER `age_sensitive`;

UPDATE `app_images`
SET `content_rating` = CASE
    WHEN `age_sensitive` = 1 THEN 'sensitive'
    ELSE 'standard'
END
WHERE `content_rating` = 'standard';

ALTER TABLE `app_users`
    ADD COLUMN `age_gate_status` enum('not_started','self_served','forced_review','verified','restricted_minor') NOT NULL DEFAULT 'not_started' AFTER `age_verified_at`,
    ADD COLUMN `age_gate_method` enum('none','self_serve','dob_forced','dob_optional','admin_restricted') NOT NULL DEFAULT 'none' AFTER `age_gate_status`,
    ADD COLUMN `mature_content_acknowledged_at` datetime DEFAULT NULL AFTER `age_gate_method`,
    ADD COLUMN `age_gate_forced_by` bigint(20) UNSIGNED DEFAULT NULL AFTER `mature_content_acknowledged_at`,
    ADD COLUMN `age_gate_forced_at` datetime DEFAULT NULL AFTER `age_gate_forced_by`,
    ADD COLUMN `age_gate_force_reason` varchar(255) DEFAULT NULL AFTER `age_gate_forced_at`;

UPDATE `app_users`
SET
    `age_gate_status` = CASE
        WHEN `date_of_birth` IS NOT NULL AND `age_verified_at` IS NOT NULL THEN 'verified'
        ELSE 'not_started'
    END,
    `age_gate_method` = CASE
        WHEN `date_of_birth` IS NOT NULL AND `age_verified_at` IS NOT NULL THEN 'dob_optional'
        ELSE 'none'
    END,
    `mature_content_acknowledged_at` = CASE
        WHEN `date_of_birth` IS NOT NULL AND `age_verified_at` IS NOT NULL THEN COALESCE(`age_verified_at`, NOW())
        ELSE NULL
    END;
