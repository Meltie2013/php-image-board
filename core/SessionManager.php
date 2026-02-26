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

        // Ensure a stable per-browser device identifier cookie exists (guest + member).
        // This is a safer and more reliable signal than pure UA/IP fingerprinting.
        self::ensureDeviceCookie();

        // Verify or set fingerprint
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
            header("Location: /login?security=invalid_session");
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
     * - User-Agent (stable per browser/device)
     * - Normalized IP (reduced precision to tolerate common IP churn)
     *
     * This is not meant to be "perfect identity"—only a best-effort signal to
     * detect obvious hijacking while minimizing false positives.
     */
    private static function generateFingerprint(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $normalizedIp = self::normalizeIp($ip);

        // Stable per-browser id (cookie) to reduce false positives and improve guest tracking.
        $deviceId = self::getDeviceId();

        return hash('sha256', $deviceId . '|' . $ua . '|' . $normalizedIp);
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
     * Normalize an IP address for fingerprinting (intentionally lenient).
     *
     * - IPv4: zeros the last octet (e.g., 203.0.113.42 -> 203.0.113.0)
     * - IPv6: keeps only the first 4 hextets (e.g., 2001:db8:abcd:0012::)
     *
     * This reduces false positives for users behind NAT, carriers, or networks
     * that rotate addresses frequently.
     */
    private static function normalizeIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
        {
            $parts = explode('.', $ip);
            return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0";
        }
        else if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
        {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 4)) . '::';
        }

        return $ip;
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
        $seconds = $blockMinutes * 60;

        if (class_exists('RequestGuard'))
        {
            if ($userId)
            {
                RequestGuard::jailUser($userId, $eventType, $seconds);
            }
            else
            {
                RequestGuard::jailClient($eventType, $seconds);
            }

            RequestGuard::log('session', $eventType, $userId);
        }
    }

    /**
     * Monitor User-Agent fingerprint diversity for suspicious patterns.
     *
     * Detection only – this method does not block requests.
     * It flags cases where the same User-Agent string is associated with many
     * distinct fingerprints in a short time window (a common bot/attack signal).
     */
    private static function monitorFingerprintPatterns(): void
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $fingerprint = $_SESSION['fingerprint'] ?? self::generateFingerprint();
        $now = date('Y-m-d H:i:s');

        // Count distinct fingerprints using this UA in the last 5 minutes
        $row = Database::fetch("SELECT COUNT(DISTINCT fingerprint) AS total FROM app_sessions WHERE ua = :ua AND last_activity > DATE_SUB(:now, INTERVAL 5 MINUTE)",
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
                RequestGuard::log('ua_fingerprint_diversity', 'High fingerprint diversity (' . $count . ') – Possible Bot or DDoS/DoS Pattern');
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
        $firstIp = $_SESSION['first_ip'] ?? $ip;
        $lastActivity = date('Y-m-d H:i:s');
        $expiresAt = isset(self::$config['timeout_minutes'])
            ? date('Y-m-d H:i:s', time() + (self::$config['timeout_minutes'] * 60))
            : null;

        $_SESSION['first_ip'] = $firstIp;

        // Serialize session data
        $data = serialize($_SESSION);

        // Check if session already exists
        $exists = Database::fetch("SELECT session_id FROM app_sessions WHERE session_id = :sid LIMIT 1",
            ['sid' => $sessionId]
        );

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

        self::$dbSessionId = $sessionId;
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
     * Intended for periodic housekeeping (cron job or occasional on-request cleanup).
     */
    public static function cleanExpired(): void
    {
        $now = date('Y-m-d H:i:s');

        Database::query(
            "DELETE FROM app_sessions WHERE expires_at IS NOT NULL AND expires_at < :now",
            ['now' => $now]
        );

        $tables = [
            'app_rate_counters',
            'app_block_list',
            'app_security_logs',
        ];

        foreach ($tables as $table)
        {
            try
            {
                Database::query(
                    "DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < :now",
                    ['now' => $now]
                );
            }
            catch (Throwable $e)
            {
                // ignore
            }
        }
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
