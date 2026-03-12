<?php

/**
 * DevicePolicy
 *
 * Records a stable device fingerprint per user and optionally enforces
 * multiple-account policies per device fingerprint.
 *
 * Notes:
 * - Uses SessionManager::getDeviceFingerprint() as the stable per-browser key.
 * - Enforcement is intentionally configurable and OFF by default.
 * - This policy is designed to be used by AuthController on login/register.
 */
class DevicePolicy
{
    /**
     * Record the current device fingerprint for a user.
     *
     * This creates or updates the device association row for the given user
     * so the application can track which device fingerprints have accessed
     * which accounts over time.
     *
     * Stored details include:
     * - Stable device fingerprint
     * - First/last seen timestamps
     * - First/last observed IP address
     * - User agent hash
     *
     * @param int $userId User identifier to associate with the current device.
     * @return void
     */
    public static function recordForUser(int $userId): void
    {
        if ($userId <= 0)
        {
            return;
        }

        $dfp = SessionManager::getDeviceFingerprint();
        if ($dfp === '')
        {
            return;
        }

        $ip = inet_pton($_SERVER['REMOTE_ADDR'] ?? '') ?: null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $uaHash = hash('sha256', TypeHelper::toString($ua));

        $now = gmdate('Y-m-d H:i:s');

        // Upsert (unique on user_id + device_fingerprint)
        try
        {
            Database::query(
                "INSERT INTO app_user_devices (user_id, device_fingerprint, first_seen_at, last_seen_at, first_ip, last_ip, user_agent_hash)
                 VALUES (:uid, :dfp, :now, :now, :ip, :ip, :uah)
                 ON DUPLICATE KEY UPDATE last_seen_at = :now2, last_ip = :ip2, user_agent_hash = :uah2",
                [
                    'uid' => $userId,
                    'dfp' => $dfp,
                    'now' => $now,
                    'now2' => $now,
                    'ip' => $ip,
                    'ip2' => $ip,
                    'uah' => $uaHash,
                    'uah2' => $uaHash,
                ]
            );
        }
        catch (Throwable $e)
        {
            // Ignore write failures so device tracking never breaks login flow.
        }
    }

    /**
     * Enforce device policy during login.
     *
     * This checks the configured device policy rules and returns a user-facing
     * error string when the login should be blocked or restricted.
     *
     * @param int $userId User identifier attempting to log in.
     * @return string|null Error string when denied; otherwise null.
     */
    public static function enforceLogin(int $userId): ?string
    {
        return self::enforce('login', $userId);
    }

    /**
     * Enforce device policy during registration.
     *
     * Registration may not yet have a permanent user_id, so this method allows
     * a pending user identifier to be passed when available. Otherwise, policy
     * enforcement is performed using device-level context only.
     *
     * @param int|null $pendingUserId Optional pending user identifier.
     * @return string|null Error string when denied; otherwise null.
     */
    public static function enforceRegister(?int $pendingUserId = null): ?string
    {
        // On register we may not have a user_id yet. We still enforce per-device.
        return self::enforce('register', $pendingUserId ?? 0);
    }

    /**
     * Core device-policy enforcement logic.
     *
     * Enforcement flow:
     * - Load device policy config
     * - Verify the feature is enabled
     * - Check whether enforcement applies to the current phase
     * - Resolve the current device fingerprint
     * - Count how many distinct accounts are already tied to this device
     * - Allow the action when the current user is already associated
     * - Apply configured action (deny, jail, or ban) when threshold is exceeded
     *
     * Supported phases:
     * - login
     * - register
     *
     * Supported actions:
     * - deny
     * - jail
     * - ban
     *
     * @param string $phase Current auth phase ('login' or 'register').
     * @param int $userId Current or pending user identifier, or 0 when unavailable.
     * @return string|null Error string when denied; otherwise null.
     */
    private static function enforce(string $phase, int $userId): ?string
    {
        $config = SettingsManager::isInitialized() ? SettingsManager::getConfig() : [];
        $policy = $config['security']['device_policy'] ?? [];

        $enabled = !empty($policy['enabled']);
        if (!$enabled)
        {
            return null;
        }

        $enforceOn = TypeHelper::toString($policy['enforce_on'] ?? 'both');
        if ($enforceOn !== 'both' && $enforceOn !== $phase)
        {
            return null;
        }

        $dfp = SessionManager::getDeviceFingerprint();
        if ($dfp === '')
        {
            return null;
        }

        $max = TypeHelper::toInt($policy['max_accounts_per_device'] ?? 0);
        if ($max <= 0)
        {
            return null;
        }

        // Count distinct users on this device fingerprint.
        $row = Database::fetch("SELECT COUNT(DISTINCT user_id) AS total FROM app_user_devices WHERE device_fingerprint = :dfp", ['dfp' => $dfp]);
        $total = TypeHelper::toInt($row['total'] ?? 0);

        // If userId is known and already associated with this device, allow.
        if ($userId > 0)
        {
            $existing = Database::fetch("SELECT 1 FROM app_user_devices WHERE user_id = :uid AND device_fingerprint = :dfp LIMIT 1", ['uid' => $userId, 'dfp' => $dfp]);
            if ($existing)
            {
                return null;
            }
        }

        if ($total >= $max)
        {
            $action = TypeHelper::toString($policy['action'] ?? 'deny');

            // Log for audit.
            RequestGuard::log('device_policy', "Multiple accounts detected for device fingerprint", $userId > 0 ? $userId : null);

            if ($action === 'ban' && $userId > 0)
            {
                RequestGuard::banUser($userId, 'device_policy_multiple_accounts');
                return 'This account has been banned.';
            }

            if ($action === 'jail')
            {
                $seconds = TypeHelper::toInt($policy['jail_seconds'] ?? 900);
                if ($userId > 0)
                {
                    RequestGuard::jailUser($userId, 'device_policy_multiple_accounts', $seconds);
                }
                else
                {
                    RequestGuard::jailClient('device_policy_multiple_accounts', $seconds);
                }

                return 'Try again later.';
            }

            // Default: deny (no ban/jail), but still prevents account actions.
            return 'Multiple accounts are not permitted.';
        }

        return null;
    }
}
