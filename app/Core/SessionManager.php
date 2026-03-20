<?php

/**
 * Session bootstrapper and persistence layer for the application.
 *
 * Responsibilities:
 * - Starts PHP sessions with hardened cookie settings (HttpOnly, SameSite, Secure when HTTPS)
 * - Enforces a lightweight session fingerprint (User-Agent + normalized IP) to reduce hijacking risk
 * - Tracks inactivity timeout and performs automatic logout when exceeded
 * - Persists session state to the database for auditing, multi-device management, and cleanup
 *
 * Notes:
 * - Call SessionManager::init() early during bootstrap before reading/writing session data.
 * - Fingerprinting is intentionally "lenient" to reduce false positives (e.g., mobile networks).
 * - Database persistence assumes the app_sessions table exists and is writable.
 */
class SessionManager
{
    // Session configuration loaded from config.php (cookie name, timeout settings, etc.)
    private static array $config = [];

    // Tracks the active session_id stored in the database for this request lifecycle
    private static ?string $dbSessionId = null;

    /**
     * Initialize the PHP session with secure defaults and persistence.
     *
     * Process:
     * - Applies session name and hardened cookie parameters
     * - Starts the PHP session (if not already started)
     * - Verifies/sets a fingerprint to detect suspicious session reuse
     * - Enforces inactivity timeout
     * - Persists the session to the database for tracking and logout control
     * - Performs passive fingerprint diversity monitoring (detection only)
     *
     * @param array $config Configuration array from config.php
     */
    public static function init(array $config): void
    {
        self::$config = $config;

        $isHttps = self::isHttps();

        if (session_status() === PHP_SESSION_NONE)
        {
            session_name($config['name'] ?? 'cms_session');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');

            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                // Only set Secure cookies when HTTPS is actually active.
                // Some servers set HTTPS="off" which would incorrectly trip !empty().
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);

            session_start();
        }

        // Ask supporting browsers for a small set of User-Agent Client Hint values.
        // These are optional signals and are used only as one input into the softer
        // browser fingerprint.
        self::sendClientHintHeaders();

        // Ensure a stable per-browser device identifier cookie exists (guest + member).
        // This is a safer and more reliable signal than pure UA/IP fingerprinting.
        self::ensureDeviceCookie();

        // Ensure the session has a per-session binding secret available for future
        // action tokens tied to this authenticated session state.
        self::ensureSessionBindingSecret();

        // Store a stable device fingerprint (derived from the device cookie + server secret).
        // This is intentionally independent of IP so it remains stable across IP churn.
        $deviceFingerprint = self::generateDeviceFingerprint();
        if (!isset($_SESSION['device_fingerprint']))
        {
            $_SESSION['device_fingerprint'] = $deviceFingerprint;
        }
        else if ($_SESSION['device_fingerprint'] !== $deviceFingerprint)
        {
            // Device cookie changed unexpectedly – log for review.
            self::logSuspiciousActivity($_SESSION['user_id'] ?? null, 'device_fingerprint_changed');
            $_SESSION['device_fingerprint'] = $deviceFingerprint;
        }

        // Store a softer browser fingerprint beside the stronger browser-instance fingerprint.
        $browserFingerprint = self::generateBrowserFingerprint();
        if (!isset($_SESSION['browser_fingerprint']))
        {
            $_SESSION['browser_fingerprint'] = $browserFingerprint;
        }
        else if ($_SESSION['browser_fingerprint'] !== $browserFingerprint)
        {
            // Browser signals drift sometimes (updates, resized screens, etc.).
            // Refresh the stored value and record it for later review instead of
            // immediately forcing a logout.
            self::logSuspiciousActivity($_SESSION['user_id'] ?? null, 'browser_fingerprint_changed', 0);
            $_SESSION['browser_fingerprint'] = $browserFingerprint;
        }

        // Verify or set the session fingerprint. This combines the stronger
        // browser-instance cookie with the softer browser fingerprint, but avoids
        // using IP so sessions remain stable on normal network churn.
        $fingerprint = self::generateFingerprint();
        if (!isset($_SESSION['fingerprint']))
        {
            $_SESSION['fingerprint'] = $fingerprint;
        }
        else if ($_SESSION['fingerprint'] !== $fingerprint)
        {
            // Possible hijack attempt – log/jail and destroy session
            self::logSuspiciousActivity($_SESSION['user_id'] ?? null, 'fingerprint_mismatch');
            self::destroy();
            header("Location: /user/login?security=invalid_session");
            exit();
        }

        self::checkTimeout();

        // Save session to database
        self::persistSession();

        // Monitor fingerprint patterns for suspicious activity (detection only)
        self::monitorFingerprintPatterns();
    }

    /**
     * Generate a lenient fingerprint hash for the current request.
     *
     * Fingerprint inputs:
     * - Stable device cookie identifier
     * - User-Agent (stable per browser/device)
     * - Accept-Language (lightweight browser trait)
     * - Optional client hints when available
     * - Normalized IP (reduced precision to tolerate common IP churn)
     *
     * This is not meant to be "perfect identity"—only a best-effort signal to
     * detect obvious hijacking while minimizing false positives.
     */
    private static function generateFingerprint(): string
    {
        // Stable per-browser id (cookie) to reduce false positives and improve guest tracking.
        $deviceId = self::getDeviceId();
        $browserFingerprint = self::generateBrowserFingerprint();

        return hash('sha256', $deviceId . '|' . $browserFingerprint);
    }

    /**
     * Generate a stable device fingerprint.
     *
     * This is derived from the per-browser device cookie and an optional server-side secret.
     * It intentionally does NOT include IP so it remains stable across IP changes.
     */
    private static function generateDeviceFingerprint(): string
    {
        $deviceId = self::getDeviceId();
        $secret = '';

        try
        {
            $cfg = SettingsManager::isInitialized() ? SettingsManager::getConfig() : [];
            $secret = TypeHelper::toString($cfg['security']['device_fingerprint_secret'] ?? '');
        }
        catch (Throwable $e)
        {
            $secret = '';
        }

        return hash('sha256', $deviceId . '|' . $secret);
    }

    /**
     * Get the current request's stable device fingerprint.
     */
    public static function getDeviceFingerprint(): string
    {
        $val = $_SESSION['device_fingerprint'] ?? '';
        if (is_string($val) && $val !== '')
        {
            return $val;
        }

        return self::generateDeviceFingerprint();
    }

    /**
     * Get the current request's softer browser fingerprint.
     */
    public static function getBrowserFingerprint(): string
    {
        $val = $_SESSION['browser_fingerprint'] ?? '';
        if (is_string($val) && $val !== '')
        {
            return $val;
        }

        return self::generateBrowserFingerprint();
    }

    /**
     * Return the current parsed client-signal payload used to build the browser fingerprint.
     *
     * @return array<string, string>
     */
    public static function getClientSignalPayload(): array
    {
        return self::getClientSignals();
    }

    /**
     * Return the current session binding secret, creating it when needed.
     */
    public static function getSessionBindingSecret(): string
    {
        self::ensureSessionBindingSecret();
        $secret = $_SESSION['session_binding_secret'] ?? '';

        return is_string($secret) ? $secret : '';
    }

    /**
     * Ensure a stable device cookie exists.
     *
     * This cookie is NOT used to uniquely identify a person; it is used only
     * to stabilize rate limits and guest session monitoring per browser.
     */
    private static function ensureDeviceCookie(): void
    {
        $cookieName = self::$config['device_cookie_name'] ?? 'pg_device';

        $isHttps = self::isHttps();

        if (!isset($_COOKIE[$cookieName]) || !is_string($_COOKIE[$cookieName]) || strlen($_COOKIE[$cookieName]) < 32)
        {
            try
            {
                $device = bin2hex(random_bytes(32));
            }
            catch (Throwable $e)
            {
                $device = hash('sha256', uniqid('', true));
            }

            // One-year cookie
            setcookie($cookieName, $device, [
                'expires' => time() + 31536000,
                'path' => '/',
                'domain' => '',
                // Only set Secure cookies when HTTPS is actually active.
                // Some servers set HTTPS="off" which would incorrectly trip !empty().
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            $_COOKIE[$cookieName] = $device;
        }
    }

    /**
     * Get device cookie value (or an empty string when not available).
     */
    private static function getDeviceId(): string
    {
        $cookieName = self::$config['device_cookie_name'] ?? 'pg_device';
        $val = $_COOKIE[$cookieName] ?? '';

        if (!is_string($val))
        {
            return '';
        }

        $val = trim($val);
        if ($val === '' || strlen($val) > 256)
        {
            return '';
        }

        return $val;
    }


    /**
     * Generate a softer browser fingerprint from normalized request headers and
     * optional lightweight client-side signals.
     */
    private static function generateBrowserFingerprint(): string
    {
        $ua = self::normalizeUserAgent(TypeHelper::toString($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $acceptLanguage = self::normalizeAcceptLanguage(TypeHelper::toString($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        $platformHint = self::normalizeHeaderValue(TypeHelper::toString($_SERVER['HTTP_SEC_CH_UA_PLATFORM'] ?? ''));
        $platformVersionHint = self::normalizeHeaderValue(TypeHelper::toString($_SERVER['HTTP_SEC_CH_UA_PLATFORM_VERSION'] ?? ''));
        $mobileHint = self::normalizeHeaderValue(TypeHelper::toString($_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? ''));
        $archHint = self::normalizeHeaderValue(TypeHelper::toString($_SERVER['HTTP_SEC_CH_UA_ARCH'] ?? ''));
        $bitnessHint = self::normalizeHeaderValue(TypeHelper::toString($_SERVER['HTTP_SEC_CH_UA_BITNESS'] ?? ''));
        $browserHint = self::normalizeHeaderValue(TypeHelper::toString($_SERVER['HTTP_SEC_CH_UA'] ?? ''));
        $clientSignals = self::getClientSignals();

        ksort($clientSignals);
        $signalParts = [];
        foreach ($clientSignals as $key => $value)
        {
            $signalParts[] = $key . '=' . $value;
        }

        return hash('sha256', implode('|', [
            $ua,
            $acceptLanguage,
            $platformHint,
            $platformVersionHint,
            $mobileHint,
            $archHint,
            $bitnessHint,
            $browserHint,
            implode(';', $signalParts),
        ]));
    }

    /**
     * Parse lightweight client signals collected by the first-party script.
     *
     * @return array<string, string>
     */
    private static function getClientSignals(): array
    {
        $cookieName = self::getClientSignalCookieName();
        $raw = $_COOKIE[$cookieName] ?? '';
        if (!is_string($raw) || $raw === '')
        {
            return [];
        }

        $decoded = rawurldecode($raw);
        $pairs = preg_split('/\|/', $decoded) ?: [];
        $allowedKeys = [
            'tz',
            'tzoff',
            'sw',
            'sh',
            'dpr',
            'touch',
            'langs',
            'pl',
            'hw',
            'cd',
            'mobile',
        ];

        $signals = [];
        foreach ($pairs as $pair)
        {
            if ($pair === '' || !str_contains($pair, '='))
            {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = trim($key);
            if ($key === '' || !in_array($key, $allowedKeys, true))
            {
                continue;
            }

            $value = self::normalizeHeaderValue($value);
            if ($value === '')
            {
                continue;
            }

            $signals[$key] = $value;
        }

        return $signals;
    }

    /**
     * Normalize User-Agent data to reduce false positives from patch-level updates.
     */
    private static function normalizeUserAgent(?string $value): string
    {
        if (!is_string($value) || $value === '')
        {
            return '';
        }

        $value = preg_replace('/\s+/', ' ', trim($value)) ?: '';
        if ($value === '')
        {
            return '';
        }

        $value = preg_replace('/([A-Za-z]+)\/(\d+)(?:\.[0-9A-Za-z._-]+)*/', '$1/$2', $value) ?: $value;
        $value = preg_replace('/(\d+)\.(\d+)(?:\.\d+)*/', '$1.$2', $value) ?: $value;

        return mb_substr($value, 0, 255);
    }

    /**
     * Normalize Accept-Language so noisy q-values do not cause session churn.
     */
    private static function normalizeAcceptLanguage(?string $value): string
    {
        if (!is_string($value) || $value === '')
        {
            return '';
        }

        $value = strtolower(trim($value));
        if ($value === '')
        {
            return '';
        }

        $parts = preg_split('/,/', $value) ?: [];
        $clean = [];
        foreach ($parts as $part)
        {
            $lang = trim((string) preg_replace('/;q=.*$/', '', $part));
            if ($lang === '')
            {
                continue;
            }

            $clean[] = preg_replace('/[^a-z0-9\-]/', '', $lang) ?: '';
            if (count($clean) >= 3)
            {
                break;
            }
        }

        return implode(',', array_filter($clean, static fn($item) => $item !== ''));
    }

    /**
     * Normalize lightweight header/cookie values used in fingerprint material.
     */
    private static function normalizeHeaderValue(?string $value): string
    {
        if (!is_string($value) || $value === '')
        {
            return '';
        }

        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?: '';
        $value = preg_replace('/[^a-z0-9\-_.;,=:+]/', '', $value) ?: '';
        return mb_substr($value, 0, 120);
    }

    /**
     * Ensure a per-session binding secret exists for future session-bound action tokens.
     */
    private static function ensureSessionBindingSecret(): void
    {
        $secret = $_SESSION['session_binding_secret'] ?? '';
        if (is_string($secret) && strlen($secret) >= 32)
        {
            return;
        }

        try
        {
            $_SESSION['session_binding_secret'] = bin2hex(random_bytes(32));
        }
        catch (Throwable $e)
        {
            $_SESSION['session_binding_secret'] = hash('sha256', uniqid('', true) . '|' . session_id());
        }
    }

    /**
     * Return the client-signal cookie name.
     */
    private static function getClientSignalCookieName(): string
    {
        try
        {
            $cfg = SettingsManager::isInitialized() ? SettingsManager::getConfig() : [];
            $cookieName = TypeHelper::toString($cfg['security']['client_signal_cookie_name'] ?? 'pg_client_signals');
            if ($cookieName !== '')
            {
                return $cookieName;
            }
        }
        catch (Throwable $e)
        {
            // fall through
        }

        return 'pg_client_signals';
    }

    /**
     * Ask the browser to send a small set of User-Agent Client Hints when supported.
     */
    private static function sendClientHintHeaders(): void
    {
        if (headers_sent())
        {
            return;
        }

        header('Accept-CH: Sec-CH-UA, Sec-CH-UA-Mobile, Sec-CH-UA-Platform, Sec-CH-UA-Platform-Version, Sec-CH-UA-Arch, Sec-CH-UA-Bitness', false);
        header('Vary: Sec-CH-UA, Sec-CH-UA-Mobile, Sec-CH-UA-Platform, Sec-CH-UA-Platform-Version, Sec-CH-UA-Arch, Sec-CH-UA-Bitness, Accept-Language, User-Agent', false);
    }


    /**
     * Determine whether the current request is being served over HTTPS.
     *
     * Notes:
     * - $_SERVER['HTTPS'] may be set to "on" or "off" depending on the server.
     * - When behind a proxy, X-Forwarded-Proto may be present.
     */
    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        {
            return true;
        }

        if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        {
            return true;
        }

        // Proxy-aware (best effort)
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        {
            return true;
        }

        return false;
    }

    /**
     * Record suspicious behavior for auditing and potential temporary jail logic.
     *
     * Uses RequestGuard (new unified system) to record/jail suspicious activity.
     *
     * @param int|null $userId User ID when known; null for anonymous sessions
     * @param string $eventType Short event key describing the incident
     * @param int $blockMinutes Suggested block window length (default: 10 minutes)
     */
    private static function logSuspiciousActivity(?int $userId, string $eventType, int $blockMinutes = 10): void
    {
        $seconds = max(0, $blockMinutes * 60);

        if (class_exists('RequestGuard'))
        {
            if ($seconds > 0)
            {
                if ($userId)
                {
                    RequestGuard::jailUser($userId, $eventType, $seconds);
                }
                else
                {
                    RequestGuard::jailClient($eventType, $seconds);
                }
            }

            RequestGuard::log('session', $eventType, $userId);
        }
    }

    /**
     * Monitor User-Agent fingerprint diversity for suspicious patterns.
     *
     * Detection only – this method does not block requests.
     * It flags cases where the same User-Agent string is associated with many
     * distinct device/request fingerprints in a short time window (a common bot/attack signal).
     */
    private static function monitorFingerprintPatterns(): void
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $fingerprint = $_SESSION['fingerprint'] ?? self::generateFingerprint();
        $deviceFingerprint = self::getDeviceFingerprint();
        $now = gmdate('Y-m-d H:i:s');

        // Count distinct device/request fingerprints using this UA in the last 5 minutes.
        $row = Database::fetch("SELECT COUNT(DISTINCT COALESCE(device_fingerprint, fingerprint)) AS total FROM app_sessions WHERE ua = :ua AND last_activity > DATE_SUB(:now, INTERVAL 5 MINUTE)",
            [
                'ua' => $ua,
                'now' => $now
            ]
        );

        $count = (int) ($row['total'] ?? 0);

        // Threshold for suspicious activity (tune as needed)
        $threshold = 10;

        if ($count >= $threshold)
        {
            if (class_exists('RequestGuard'))
            {
                $label = $deviceFingerprint !== '' ? $deviceFingerprint : $fingerprint;
                RequestGuard::log('ua_fingerprint_diversity', 'High fingerprint diversity (' . $count . ') for client ' . mb_substr($label, 0, 16) . ' – Possible Bot or DDoS/DoS Pattern');
            }
        }
    }

    /**
     * Enforce inactivity timeout for the current session.
     *
     * If the session has been idle longer than the configured timeout,
     * the session is destroyed and the user is redirected to login.
     *
     * This method also updates the "last_activity" timestamp on each request.
     */
    private static function checkTimeout(): void
    {
        $timeout = (self::$config['timeout_minutes']) * 60;

        if (isset($_SESSION['last_activity']))
        {
            $inactive = time() - $_SESSION['last_activity'];

            if ($inactive > $timeout)
            {
                self::destroy(); // Auto logout
                header("Location: /user/login");
                exit();
            }
        }

        $_SESSION['last_activity'] = time();
    }

    /**
     * Persist the current session state to the database.
     *
     * Stores metadata for auditing and security analysis:
     * - user_id (when authenticated)
     * - session_id, IP (binary), User-Agent, fingerprint
     * - first_ip (captures the initial IP seen for the session)
     * - last_activity and optional expires_at
     *
     * Session contents are serialized and stored to support recovery/debugging
     * and administrative session management (use with care).
     */
    private static function persistSession(): void
    {
        $userId = $_SESSION['user_id'] ?? null;
        $sessionId = session_id();
        $ip = inet_pton($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $fingerprint = $_SESSION['fingerprint'] ?? self::generateFingerprint();
        $deviceFingerprint = self::getDeviceFingerprint();
        $firstIp = $_SESSION['first_ip'] ?? $ip;
        $lastActivity = gmdate('Y-m-d H:i:s');
        $expiresAt = isset(self::$config['timeout_minutes'])
            ? gmdate('Y-m-d H:i:s', time() + (self::$config['timeout_minutes'] * 60))
            : null;

        $_SESSION['first_ip'] = $firstIp;

        // Serialize session data
        $data = serialize($_SESSION);

        // Check if session already exists
        $exists = Database::fetch("SELECT session_id FROM app_sessions WHERE session_id = :sid LIMIT 1",
            ['sid' => $sessionId]
        );

        try
        {
            if ($exists)
            {
                Database::query("UPDATE app_sessions SET user_id = :uid, ip = :ip, first_ip = :first_ip, ua = :ua, fingerprint = :fp, device_fingerprint = :dfp, browser_fingerprint = :bfp, session_binding_hash = :sbh,
                         last_activity = :last, expires_at = :expires, data = :data WHERE session_id = :sid",
                    [
                        'uid' => $userId,
                        'ip' => $ip,
                        'first_ip' => $firstIp,
                        'ua' => $ua,
                        'fp' => $fingerprint,
                        'dfp' => $deviceFingerprint,
                        'bfp' => $browserFingerprint !== '' ? $browserFingerprint : null,
                        'sbh' => $bindingHash,
                        'last' => $lastActivity,
                        'expires' => $expiresAt,
                        'data' => $data,
                        'sid' => $sessionId
                    ]
                );
            }
            else
            {
                Database::query("INSERT INTO app_sessions (session_id, user_id, ip, first_ip, ua, fingerprint, device_fingerprint, browser_fingerprint, session_binding_hash, last_activity, expires_at, data)
                     VALUES (:sid, :uid, :ip, :first_ip, :ua, :fp, :dfp, :bfp, :sbh, :last, :expires, :data)",
                    [
                        'sid' => $sessionId,
                        'uid' => $userId,
                        'ip' => $ip,
                        'first_ip' => $firstIp,
                        'ua' => $ua,
                        'fp' => $fingerprint,
                        'dfp' => $deviceFingerprint,
                        'bfp' => $browserFingerprint !== '' ? $browserFingerprint : null,
                        'sbh' => $bindingHash,
                        'last' => $lastActivity,
                        'expires' => $expiresAt,
                        'data' => $data
                    ]
                );
            }
        }
        catch (Throwable $e)
        {
            // Backwards compatible fallback when device_fingerprint column is not present.
            if ($exists)
            {
                Database::query("UPDATE app_sessions SET user_id = :uid, ip = :ip, first_ip = :first_ip, ua = :ua, fingerprint = :fp,
                         last_activity = :last, expires_at = :expires, data = :data WHERE session_id = :sid",
                    [
                        'uid' => $userId,
                        'ip' => $ip,
                        'first_ip' => $firstIp,
                        'ua' => $ua,
                        'fp' => $fingerprint,
                        'last' => $lastActivity,
                        'expires' => $expiresAt,
                        'data' => $data,
                        'sid' => $sessionId
                    ]
                );
            }
            else
            {
                Database::query("INSERT INTO app_sessions (session_id, user_id, ip, first_ip, ua, fingerprint, last_activity, expires_at, data)
                     VALUES (:sid, :uid, :ip, :first_ip, :ua, :fp, :last, :expires, :data)",
                    [
                        'sid' => $sessionId,
                        'uid' => $userId,
                        'ip' => $ip,
                        'first_ip' => $firstIp,
                        'ua' => $ua,
                        'fp' => $fingerprint,
                        'last' => $lastActivity,
                        'expires' => $expiresAt,
                        'data' => $data
                    ]
                );
            }
        }

        self::$dbSessionId = $sessionId;

        // Persist lightweight client signals for linkage review when the optional
        // table exists. This is throttled so normal browsing does not spam rows.
        self::persistClientSignals();

        // Persist user's device fingerprint
        self::persistUserDevice();
    }

    /**
     * Persist the current user's device fingerprint.
     *
     * This allows auditing and optional enforcement of "multiple accounts per device" rules.
     * Runs only for authenticated sessions.
     */
    private static function persistUserDevice(): void
    {
        $userId = TypeHelper::toInt($_SESSION['user_id'] ?? null);
        if ($userId <= 0)
        {
            return;
        }

        $dfp = self::getDeviceFingerprint();
        $bfp = self::getBrowserFingerprint();
        if ($dfp === '' && $bfp === '')
        {
            return;
        }

        $ip = TypeHelper::toString($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = TypeHelper::toString($_SERVER['HTTP_USER_AGENT'] ?? '');

        $ipBin = null;
        if ($ip !== '')
        {
            $packedIp = @inet_pton($ip);
            if ($packedIp !== false)
            {
                $ipBin = $packedIp;
            }
        }

        $uaHash = hash('sha256', $ua);

        // Best-effort: if table isn't present yet, do not break requests.
        try
        {
            $existing = Database::query("SELECT id FROM app_user_devices WHERE user_id = :uid AND device_fingerprint = :dfp LIMIT 1",
                ['uid' => $userId, 'dfp' => $dfp !== '' ? $dfp : hash('sha256', 'fallback-device|' . $userId . '|' . $bfp)])->fetch();
            if ($existing)
            {
                try
                {
                    Database::query("UPDATE app_user_devices SET browser_fingerprint = :bfp, last_seen_at = NOW(), last_ip = :last_ip, user_agent_hash = :ua_hash WHERE id = :id",
                        ['bfp' => $bfp !== '' ? $bfp : null, 'last_ip' => $ipBin, 'ua_hash' => $uaHash, 'id' => TypeHelper::toInt($existing['id'] ?? 0)]);
                }
                catch (Throwable $e)
                {
                    Database::query("UPDATE app_user_devices SET last_seen_at = NOW(), last_ip = :last_ip, user_agent_hash = :ua_hash WHERE id = :id",
                        ['last_ip' => $ipBin, 'ua_hash' => $uaHash, 'id' => TypeHelper::toInt($existing['id'] ?? 0)]);
                }
                return;
            }

            try
            {
                Database::query("INSERT INTO app_user_devices (user_id, device_fingerprint, browser_fingerprint, first_seen_at, last_seen_at, first_ip, last_ip, user_agent_hash)
                        VALUES (:uid, :dfp, :bfp, NOW(), NOW(), :first_ip, :last_ip, :ua_hash )",
                        ['uid' => $userId, 'dfp' => $dfp !== '' ? $dfp : hash('sha256', 'fallback-device|' . $userId . '|' . $bfp), 'bfp' => $bfp !== '' ? $bfp : null, 'first_ip' => $ipBin, 'last_ip' => $ipBin, 'ua_hash' => $uaHash]
                );
            }
            catch (Throwable $e)
            {
                Database::query("INSERT INTO app_user_devices (user_id, device_fingerprint, first_seen_at, last_seen_at, first_ip, last_ip, user_agent_hash)
                        VALUES (:uid, :dfp, NOW(), NOW(), :first_ip, :last_ip, :ua_hash )",
                        ['uid' => $userId, 'dfp' => $dfp !== '' ? $dfp : hash('sha256', 'fallback-device|' . $userId . '|' . $bfp), 'first_ip' => $ipBin, 'last_ip' => $ipBin, 'ua_hash' => $uaHash]
                );
            }
        }
        catch (Throwable $e)
        {
            error_log('persistUserDevice() failed: ' . $e->getMessage());
        }
    }

    /**
     * Persist the current client-signal payload for later linkage review.
     *
     * This is throttled per session so normal navigation does not generate one
     * row per request.
     */
    private static function persistClientSignals(): void
    {
        $signals = self::getClientSignals();
        if ($signals === [])
        {
            return;
        }

        $payload = json_encode($signals, JSON_UNESCAPED_SLASHES);
        if (!is_string($payload) || $payload === '')
        {
            return;
        }

        $signalHash = hash('sha256', $payload);
        $lastHash = TypeHelper::toString($_SESSION['last_client_signal_hash'] ?? '', allowEmpty: true) ?? '';
        $lastWrite = TypeHelper::toInt($_SESSION['last_client_signal_write'] ?? 0);
        $nowTs = time();

        if ($lastHash === $signalHash && $lastWrite > 0 && ($nowTs - $lastWrite) < 900)
        {
            return;
        }

        try
        {
            Database::query(
                "INSERT INTO app_client_signals (user_id, session_id, ip, device_fingerprint, browser_fingerprint, signal_hash, signal_payload, event_type, created_at)
                 VALUES (:uid, :sid, :ip, :dfp, :bfp, :sig, :payload, :event, :created)",
                [
                    'uid' => TypeHelper::toInt($_SESSION['user_id'] ?? null) ?: null,
                    'sid' => session_id(),
                    'ip' => inet_pton($_SERVER['REMOTE_ADDR'] ?? '') ?: null,
                    'dfp' => self::getDeviceFingerprint() !== '' ? self::getDeviceFingerprint() : null,
                    'bfp' => self::getBrowserFingerprint() !== '' ? self::getBrowserFingerprint() : null,
                    'sig' => $signalHash,
                    'payload' => $payload,
                    'event' => 'seen',
                    'created' => gmdate('Y-m-d H:i:s'),
                ]
            );

            $_SESSION['last_client_signal_hash'] = $signalHash;
            $_SESSION['last_client_signal_write'] = $nowTs;
        }
        catch (Throwable $e)
        {
            // Optional table – ignore when not available.
        }
    }

    /**
     * Generate a token bound to the current session, user, and intended purpose.
     *
     * This is useful for future one-time or short-lived action tokens without
     * embedding user identity directly into the session identifier.
     */
    public static function issueBoundToken(string $purpose, int $ttlSeconds = 900, array $claims = []): string
    {
        $purpose = trim($purpose);
        if ($purpose === '')
        {
            throw new InvalidArgumentException('Token purpose is required.');
        }

        $issuedAt = time();
        $payload = [
            'p' => $purpose,
            'sid' => session_id(),
            'uid' => TypeHelper::toInt($_SESSION['user_id'] ?? 0),
            'iat' => $issuedAt,
            'exp' => $issuedAt + max(60, $ttlSeconds),
            'c' => $claims,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '')
        {
            throw new RuntimeException('Unable to encode token payload.');
        }

        $data = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $data, self::getSessionBindingSecret());

        return $data . '.' . $sig;
    }

    /**
     * Verify a bound token generated by issueBoundToken().
     *
     * @return array<string, mixed>|null Verified payload when valid; otherwise null.
     */
    public static function verifyBoundToken(string $token, string $purpose): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2)
        {
            return null;
        }

        [$data, $sig] = $parts;
        $expected = hash_hmac('sha256', $data, self::getSessionBindingSecret());
        if (!hash_equals($expected, $sig))
        {
            return null;
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if (!is_string($decoded) || $decoded === '')
        {
            return null;
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload))
        {
            return null;
        }

        if ((string) ($payload['p'] ?? '') !== $purpose)
        {
            return null;
        }

        if ((string) ($payload['sid'] ?? '') !== session_id())
        {
            return null;
        }

        if (TypeHelper::toInt($payload['uid'] ?? 0) !== TypeHelper::toInt($_SESSION['user_id'] ?? 0))
        {
            return null;
        }

        if (TypeHelper::toInt($payload['exp'] ?? 0) < time())
        {
            return null;
        }

        return $payload;
    }

    /**
     * Regenerate the session ID and keep the database record in sync.
     *
     * This should be called after authentication and other security-sensitive
     * events to reduce session fixation risk.
     */
    public static function regenerate(): void
    {
        $oldSession = session_id();
        session_regenerate_id(true);
        self::$dbSessionId = session_id();

        // Update DB to new session ID
        Database::query("UPDATE app_sessions SET session_id = :new WHERE session_id = :old",
            ['new' => self::$dbSessionId, 'old' => $oldSession]
        );

        try
        {
            $_SESSION['session_binding_secret'] = bin2hex(random_bytes(32));
        }
        catch (Throwable $e)
        {
            $_SESSION['session_binding_secret'] = hash('sha256', uniqid('', true) . '|' . session_id());
        }

        self::persistSession();
    }

    /**
     * Destroy the current session and remove associated persisted data.
     *
     * Deletes the app_sessions record, expires the session cookie, and clears
     * the PHP session from memory to fully log the user out.
     */
    public static function destroy(): void
    {
        // Delete DB record
        if (self::$dbSessionId)
        {
            Database::query("DELETE FROM app_sessions WHERE session_id = :sid",
                ['sid' => self::$dbSessionId]
            );

            self::$dbSessionId = null;
        }

        if (ini_get("session.use_cookies"))
        {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Set a session value and persist the updated session to the database.
     *
     * @param string $key Session key name
     * @param mixed $value Value to store
     */
    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
        self::persistSession();
    }

    /**
     * Get a session value with an optional default when the key is missing.
     *
     * @param string $key Session key name
     * @param mixed $default Value returned when the key is not set
     * @return mixed Stored session value or the provided default
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Determine whether a session key is present.
     *
     * @param string $key Session key name
     * @return bool True if the key exists; otherwise false
     */
    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Remove a session key and persist the updated session to the database.
     *
     * @param string $key Session key name to remove
     */
    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
        self::persistSession();
    }

    /**
     * Delete expired sessions from the database.
     *
     * Intended for periodic housekeeping driven by the background maintenance
     * server so normal page requests do not perform cleanup work.
     *
     * @return int Number of expired sessions removed
     */
    public static function cleanExpired(): int
    {
        $now = gmdate('Y-m-d H:i:s');

        return Database::execute("DELETE FROM app_sessions WHERE expires_at IS NOT NULL AND expires_at < :now",
            ['now' => $now]
        );
    }

    /**
     * Invalidate all sessions for a user except the current one.
     *
     * Useful for "Log out other devices" functionality after a password change
     * or when the user wants to forcibly revoke other logins.
     */
    public static function logoutOtherDevices(int $userId, string $currentSessionId): void
    {
        Database::query("DELETE FROM app_sessions WHERE user_id = :id AND session_id != :sid",
            ['id' => $userId, 'sid' => $currentSessionId]
        );
    }
}
