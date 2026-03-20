-- Image Board fingerprint / browser fingerprint / session binding upgrade
-- Apply this once to existing installations.

ALTER TABLE `app_sessions`
    ADD COLUMN IF NOT EXISTS `browser_fingerprint` char(64) DEFAULT NULL AFTER `device_fingerprint`,
    ADD COLUMN IF NOT EXISTS `session_binding_hash` char(64) DEFAULT NULL AFTER `browser_fingerprint`,
    ADD KEY `idx_sessions_device_fingerprint` (`device_fingerprint`),
    ADD KEY `idx_sessions_browser_fingerprint` (`browser_fingerprint`);

ALTER TABLE `app_security_logs`
    ADD COLUMN IF NOT EXISTS `browser_fingerprint` char(64) DEFAULT NULL AFTER `device_fingerprint`,
    ADD KEY `idx_browser_fingerprint` (`browser_fingerprint`);

ALTER TABLE `app_user_devices`
    ADD COLUMN IF NOT EXISTS `browser_fingerprint` char(64) DEFAULT NULL AFTER `device_fingerprint`,
    ADD KEY `idx_browser_fingerprint` (`browser_fingerprint`),
    ADD KEY `idx_device_browser_fingerprint` (`device_fingerprint`, `browser_fingerprint`);

CREATE TABLE IF NOT EXISTS `app_client_signals` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` bigint(20) UNSIGNED DEFAULT NULL,
    `session_id` varchar(128) DEFAULT NULL,
    `ip` varbinary(16) DEFAULT NULL,
    `device_fingerprint` char(64) DEFAULT NULL,
    `browser_fingerprint` char(64) DEFAULT NULL,
    `signal_hash` char(64) NOT NULL,
    `signal_payload` text DEFAULT NULL,
    `event_type` varchar(32) NOT NULL DEFAULT 'seen',
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_session_id` (`session_id`),
    KEY `idx_device_fingerprint` (`device_fingerprint`),
    KEY `idx_browser_fingerprint` (`browser_fingerprint`),
    KEY `idx_signal_hash` (`signal_hash`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_client_signals_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `app_device_overrides` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_fingerprint` char(64) NOT NULL,
    `browser_fingerprint` char(64) DEFAULT NULL,
    `label` varchar(100) DEFAULT NULL,
    `allow_multi_account` tinyint(1) NOT NULL DEFAULT 1,
    `max_accounts` int(10) UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_device_fingerprint` (`device_fingerprint`),
    KEY `idx_browser_fingerprint` (`browser_fingerprint`),
    KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
