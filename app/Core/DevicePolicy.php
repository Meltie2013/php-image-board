<?php

/**
 * DevicePolicy
 *
 * Records stable browser/device linkage signals per user and optionally enforces
 * multiple-account policies using both the stable browser-instance fingerprint
 * and a softer browser fingerprint.
 *
 * Notes:
 * - SessionManager::getDeviceFingerprint() is the strongest per-browser-instance signal.
 * - SessionManager::getBrowserFingerprint() is a softer browser/profile signal.
 * - Enforcement is intentionally configurable and OFF by default.
 * - This policy is designed to be used by AuthController on login/register.
 */
class DevicePolicy
{
    /**
     * Record the current device and browser fingerprints for a user.
     *
     * This creates or updates the device association row for the given user so
     * the application can track which browser-instance and browser-profile
     * signals have accessed which accounts over time.
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
        $bfp = SessionManager::getBrowserFingerprint();
        if ($dfp === '' && $bfp === '')
        {
            return;
        }

        $ip = inet_pton($_SERVER['REMOTE_ADDR'] ?? '') ?: null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $uaHash = hash('sha256', TypeHelper::toString($ua));

        $now = gmdate('Y-m-d H:i:s');

        try
        {
            Database::query(
                "INSERT INTO app_user_devices (user_id, device_fingerprint, browser_fingerprint, first_seen_at, last_seen_at, first_ip, last_ip, user_agent_hash)
                 VALUES (:uid, :dfp, :bfp, :now, :now, :ip, :ip, :uah)
                 ON DUPLICATE KEY UPDATE browser_fingerprint = VALUES(browser_fingerprint), last_seen_at = :now2, last_ip = :ip2, user_agent_hash = :uah2",
                [
                    'uid' => $userId,
                    'dfp' => $dfp !== '' ? $dfp : hash('sha256', 'fallback-device|' . $userId . '|' . $bfp),
                    'bfp' => $bfp !== '' ? $bfp : null,
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
            try
            {
                Database::query(
                    "INSERT INTO app_user_devices (user_id, device_fingerprint, first_seen_at, last_seen_at, first_ip, last_ip, user_agent_hash)
                     VALUES (:uid, :dfp, :now, :now, :ip, :ip, :uah)
                     ON DUPLICATE KEY UPDATE last_seen_at = :now2, last_ip = :ip2, user_agent_hash = :uah2",
                    [
                        'uid' => $userId,
                        'dfp' => $dfp !== '' ? $dfp : hash('sha256', 'fallback-device|' . $userId . '|' . $bfp),
                        'now' => $now,
                        'now2' => $now,
                        'ip' => $ip,
                        'ip2' => $ip,
                        'uah' => $uaHash,
                        'uah2' => $uaHash,
                    ]
                );
            }
            catch (Throwable $inner)
            {
                // Ignore write failures so device tracking never breaks auth flow.
            }
        }

        self::recordLinkageEvent($userId, 'seen');
    }

    /**
     * Enforce device policy during login.
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
     * @param int|null $pendingUserId Optional pending user identifier.
     * @return string|null Error string when denied; otherwise null.
     */
    public static function enforceRegister(?int $pendingUserId = null): ?string
    {
        return self::enforce('register', $pendingUserId ?? 0);
    }

    /**
     * Core device-policy enforcement logic.
     *
     * This policy intentionally avoids treating one signal as perfect identity.
     * The browser-instance fingerprint is stronger than the softer browser
     * fingerprint, so the enforcement path uses both before taking action.
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
        $bfp = SessionManager::getBrowserFingerprint();
        if ($dfp === '' && $bfp === '')
        {
            return null;
        }

        $maxDevice = TypeHelper::toInt($policy['max_accounts_per_device'] ?? 0);
        $maxBrowser = TypeHelper::toInt($policy['max_accounts_per_browser_fingerprint'] ?? 0);
        $softReviewThreshold = TypeHelper::toInt($policy['shared_device_review_threshold'] ?? 0);

        if ($maxDevice <= 0 && $maxBrowser <= 0)
        {
            return null;
        }

        if ($userId > 0)
        {
            $existing = self::fetchAssociation($userId, $dfp, $bfp);
            if ($existing)
            {
                return null;
            }
        }

        $override = self::getOverride($dfp, $bfp);
        $overrideMax = TypeHelper::toInt($override['max_accounts'] ?? 0);
        if (!empty($override['allow_multi_account']) && $overrideMax > 0)
        {
            $maxDevice = max($maxDevice, $overrideMax);
            $maxBrowser = max($maxBrowser, $overrideMax);
        }

        $deviceTotal = $dfp !== '' ? self::countDistinctUsersByDevice($dfp) : 0;
        $browserTotal = $bfp !== '' ? self::countDistinctUsersByBrowser($bfp) : 0;
        $linkedTotal = ($dfp !== '' && $bfp !== '') ? self::countDistinctUsersByLinkedSignals($dfp, $bfp) : max($deviceTotal, $browserTotal);

        $shouldReview = false;
        if ($softReviewThreshold > 0)
        {
            $shouldReview = $deviceTotal >= $softReviewThreshold || $browserTotal >= $softReviewThreshold || $linkedTotal >= $softReviewThreshold;
        }
        else
        {
            $shouldReview = $deviceTotal > 1 || $browserTotal > 1 || $linkedTotal > 1;
        }

        if ($shouldReview)
        {
            self::recordLinkageEvent($userId > 0 ? $userId : null, 'review', [
                'phase' => $phase,
                'device_total' => $deviceTotal,
                'browser_total' => $browserTotal,
                'linked_total' => $linkedTotal,
            ]);

            RequestGuard::log(
                'device_policy_review',
                'Shared device/browser linkage detected. device=' . $deviceTotal . ', browser=' . $browserTotal . ', linked=' . $linkedTotal,
                $userId > 0 ? $userId : null
            );
        }

        $deviceExceeded = $maxDevice > 0 && $deviceTotal >= $maxDevice;
        $browserExceeded = $maxBrowser > 0 && $browserTotal >= $maxBrowser;

        if (!$deviceExceeded && !$browserExceeded)
        {
            return null;
        }

        $shouldDeny = false;
        if ($deviceExceeded && $browserExceeded)
        {
            $shouldDeny = true;
        }
        else if (!empty($policy['block_on_device_fingerprint_only']) && $deviceExceeded)
        {
            $shouldDeny = true;
        }
        else if (!empty($policy['block_on_browser_fingerprint_only']) && $browserExceeded)
        {
            $shouldDeny = true;
        }

        if (!$shouldDeny)
        {
            return null;
        }

        $action = TypeHelper::toString($policy['action'] ?? 'deny');

        RequestGuard::log(
            'device_policy',
            'Multiple accounts detected across browser-instance and browser fingerprint signals.',
            $userId > 0 ? $userId : null
        );

        self::recordLinkageEvent($userId > 0 ? $userId : null, 'blocked', [
            'phase' => $phase,
            'device_total' => $deviceTotal,
            'browser_total' => $browserTotal,
            'linked_total' => $linkedTotal,
            'action' => $action,
        ]);

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

        return 'Too many accounts are already linked to this browser environment.';
    }

    /**
     * Fetch one existing user association for the current signal set.
     */
    private static function fetchAssociation(int $userId, string $deviceFingerprint, string $browserFingerprint): ?array
    {
        try
        {
            if ($deviceFingerprint !== '' && $browserFingerprint !== '')
            {
                return Database::fetch(
                    "SELECT 1 FROM app_user_devices WHERE user_id = :uid AND (device_fingerprint = :dfp OR browser_fingerprint = :bfp) LIMIT 1",
                    ['uid' => $userId, 'dfp' => $deviceFingerprint, 'bfp' => $browserFingerprint]
                );
            }

            if ($deviceFingerprint !== '')
            {
                return Database::fetch(
                    "SELECT 1 FROM app_user_devices WHERE user_id = :uid AND device_fingerprint = :dfp LIMIT 1",
                    ['uid' => $userId, 'dfp' => $deviceFingerprint]
                );
            }

            if ($browserFingerprint !== '')
            {
                return Database::fetch(
                    "SELECT 1 FROM app_user_devices WHERE user_id = :uid AND browser_fingerprint = :bfp LIMIT 1",
                    ['uid' => $userId, 'bfp' => $browserFingerprint]
                );
            }
        }
        catch (Throwable $e)
        {
            if ($deviceFingerprint !== '')
            {
                return Database::fetch(
                    "SELECT 1 FROM app_user_devices WHERE user_id = :uid AND device_fingerprint = :dfp LIMIT 1",
                    ['uid' => $userId, 'dfp' => $deviceFingerprint]
                );
            }
        }

        return null;
    }

    /**
     * Count distinct users linked to one stable browser-instance fingerprint.
     */
    private static function countDistinctUsersByDevice(string $deviceFingerprint): int
    {
        $row = Database::fetch(
            "SELECT COUNT(DISTINCT user_id) AS total FROM app_user_devices WHERE device_fingerprint = :dfp",
            ['dfp' => $deviceFingerprint]
        );

        return TypeHelper::toInt($row['total'] ?? 0);
    }

    /**
     * Count distinct users linked to one softer browser fingerprint.
     */
    private static function countDistinctUsersByBrowser(string $browserFingerprint): int
    {
        try
        {
            $row = Database::fetch(
                "SELECT COUNT(DISTINCT user_id) AS total FROM app_user_devices WHERE browser_fingerprint = :bfp",
                ['bfp' => $browserFingerprint]
            );

            return TypeHelper::toInt($row['total'] ?? 0);
        }
        catch (Throwable $e)
        {
            return 0;
        }
    }

    /**
     * Count distinct users when both fingerprints line up together.
     */
    private static function countDistinctUsersByLinkedSignals(string $deviceFingerprint, string $browserFingerprint): int
    {
        try
        {
            $row = Database::fetch(
                "SELECT COUNT(DISTINCT user_id) AS total
                 FROM app_user_devices
                 WHERE device_fingerprint = :dfp AND browser_fingerprint = :bfp",
                ['dfp' => $deviceFingerprint, 'bfp' => $browserFingerprint]
            );

            return TypeHelper::toInt($row['total'] ?? 0);
        }
        catch (Throwable $e)
        {
            return self::countDistinctUsersByDevice($deviceFingerprint);
        }
    }

    /**
     * Resolve an optional shared-device override entry.
     */
    private static function getOverride(string $deviceFingerprint, string $browserFingerprint): ?array
    {
        if ($deviceFingerprint === '' && $browserFingerprint === '')
        {
            return null;
        }

        $now = gmdate('Y-m-d H:i:s');

        try
        {
            if ($deviceFingerprint !== '' && $browserFingerprint !== '')
            {
                $row = Database::fetch(
                    "SELECT allow_multi_account, max_accounts
                     FROM app_device_overrides
                     WHERE device_fingerprint = :dfp
                       AND (browser_fingerprint IS NULL OR browser_fingerprint = :bfp)
                       AND (expires_at IS NULL OR expires_at > :now)
                     ORDER BY browser_fingerprint DESC, id DESC
                     LIMIT 1",
                    ['dfp' => $deviceFingerprint, 'bfp' => $browserFingerprint, 'now' => $now]
                );

                if ($row)
                {
                    return $row;
                }
            }

            if ($deviceFingerprint !== '')
            {
                return Database::fetch(
                    "SELECT allow_multi_account, max_accounts
                     FROM app_device_overrides
                     WHERE device_fingerprint = :dfp
                       AND (expires_at IS NULL OR expires_at > :now)
                     ORDER BY id DESC
                     LIMIT 1",
                    ['dfp' => $deviceFingerprint, 'now' => $now]
                );
            }
        }
        catch (Throwable $e)
        {
            return null;
        }

        return null;
    }

    /**
     * Record a linkage/review event without breaking the auth flow.
     */
    private static function recordLinkageEvent(?int $userId, string $eventType, array $extra = []): void
    {
        $dfp = SessionManager::getDeviceFingerprint();
        $bfp = SessionManager::getBrowserFingerprint();
        $signalHash = hash('sha256', $dfp . '|' . $bfp);
        $payload = [
            'device_fingerprint' => $dfp,
            'browser_fingerprint' => $bfp,
            'signals' => SessionManager::getClientSignalPayload(),
            'extra' => $extra,
        ];

        try
        {
            Database::query(
                "INSERT INTO app_client_signals (user_id, session_id, ip, device_fingerprint, browser_fingerprint, signal_hash, signal_payload, event_type, created_at)
                 VALUES (:uid, :sid, :ip, :dfp, :bfp, :sig, :payload, :event, :created)",
                [
                    'uid' => $userId,
                    'sid' => session_id(),
                    'ip' => inet_pton($_SERVER['REMOTE_ADDR'] ?? '') ?: null,
                    'dfp' => $dfp !== '' ? $dfp : null,
                    'bfp' => $bfp !== '' ? $bfp : null,
                    'sig' => $signalHash,
                    'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                    'event' => mb_substr($eventType, 0, 32),
                    'created' => gmdate('Y-m-d H:i:s'),
                ]
            );
        }
        catch (Throwable $e)
        {
            // Optional table – never break authentication flow.
        }
    }
}
