<?php

class SessionManager
{
    // Configuration array
    private static array $config = [];
    private static ?string $dbSessionId = null;

    /**
     * Initialize session with secure settings
     *
     * @param array $config Configuration array from config.php
     */
    public static function init(array $config): void
    {
        self::$config = $config;

        if (session_status() === PHP_SESSION_NONE)
        {
            session_name($config['name'] ?? 'cms_session');

            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);

            session_start();
        }

        // Verify or set fingerprint
        $fingerprint = self::generateFingerprint();
        if (!isset($_SESSION['fingerprint']))
        {
            $_SESSION['fingerprint'] = $fingerprint;
        }
        elseif ($_SESSION['fingerprint'] !== $fingerprint)
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
     * Generate a lenient fingerprint hash for the current request
     */
    private static function generateFingerprint(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $normalizedIp = self::normalizeIp($ip);

        return hash('sha256', $ua . '|' . $normalizedIp);
    }

    /**
     * Normalize IP for fingerprinting (lenient)
     */
    private static function normalizeIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
        {
            $parts = explode('.', $ip);
            return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0";
        }
        elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
        {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 4)) . '::';
        }
        return $ip;
    }

    /**
     * Log suspicious activity for auditing / temporary jail
     */
    private static function logSuspiciousActivity(?int $userId, string $eventType, int $blockMinutes = 10): void
    {
        $blockedUntil = date('Y-m-d H:i:s', time() + ($blockMinutes * 60));
        Database::query(
            "INSERT INTO user_security_events (user_id, ip, event_type, blocked_until)
             VALUES (:uid, :ip, :event, :blocked)",
            [
                'uid' => $userId,
                'ip' => inet_pton($_SERVER['REMOTE_ADDR'] ?? ''),
                'event' => $eventType,
                'blocked' => $blockedUntil
            ]
        );
    }

    /**
     * Monitor User-Agent fingerprint diversity for suspicious patterns
     * Detection only – no blocking
     */
    private static function monitorFingerprintPatterns(): void
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $fingerprint = $_SESSION['fingerprint'] ?? self::generateFingerprint();
        $now = date('Y-m-d H:i:s');

        // Count distinct fingerprints using this UA in the last 5 minutes
        $row = Database::fetch(
            "SELECT COUNT(DISTINCT fingerprint) AS total
             FROM app_sessions
             WHERE ua = :ua AND last_activity > DATE_SUB(:now, INTERVAL 5 MINUTE)",
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
            // Insert or update security_events table
            Database::query(
                "INSERT INTO security_events (ua, fingerprints, first_seen, last_seen, flagged_at, notes)
                 VALUES (:ua, :fingerprints, :first_seen, :last_seen, :flagged_at, :notes)
                 ON DUPLICATE KEY UPDATE fingerprints = :fingerprints_upd, last_seen = :last_seen_upd",
                [
                    'ua' => $ua,
                    'fingerprints' => $count,
                    'first_seen' => $now,
                    'last_seen' => $now,
                    'flagged_at' => $now,
                    'notes' => 'High fingerprint diversity – Possible Bot or DDoS/DoS Pattern',
                    'fingerprints_upd' => $count,
                    'last_seen_upd' => $now
                ]
            );
        }
    }

    /**
     * Check if user session has timed out due to inactivity
     * Logs out user automatically if timeout reached
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
                header("Location: /login");
                exit();
            }
        }

        $_SESSION['last_activity'] = time();
    }

    /**
     * Persist session to database
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
        $exists = Database::fetch(
            "SELECT session_id FROM app_sessions WHERE session_id = :sid LIMIT 1",
            ['sid' => $sessionId]
        );

        if ($exists)
        {
            Database::query(
                "UPDATE app_sessions
                 SET user_id = :uid, ip = :ip, first_ip = :first_ip, ua = :ua, fingerprint = :fp,
                     last_activity = :last, expires_at = :expires, data = :data
                 WHERE session_id = :sid",
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
            Database::query(
                "INSERT INTO app_sessions (session_id, user_id, ip, first_ip, ua, fingerprint, last_activity, expires_at, data)
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
     * Regenerate session ID (for login or security sensitive actions)
     */
    public static function regenerate(): void
    {
        $oldSession = session_id();
        session_regenerate_id(true);
        self::$dbSessionId = session_id();

        // Update DB to new session ID
        Database::query(
            "UPDATE app_sessions SET session_id = :new WHERE session_id = :old",
            ['new' => self::$dbSessionId, 'old' => $oldSession]
        );

        self::persistSession();
    }

    /**
     * Destroy current session and all data
     */
    public static function destroy(): void
    {
        // Delete DB record
        if (self::$dbSessionId)
        {
            Database::query(
                "DELETE FROM app_sessions WHERE session_id = :sid",
                ['sid' => self::$dbSessionId]
            );

            self::$dbSessionId = null;
        }

        $_SESSION = [];

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
     * Set session variable
     */
    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
        self::persistSession();
    }

    /**
     * Get session variable
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Check if session variable exists
     */
    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Remove session variable
     */
    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
        self::persistSession();
    }

    /**
     * Clean expired sessions
     */
    public static function cleanExpired(): void
    {
        $now = date('Y-m-d H:i:s');
        Database::query(
            "DELETE FROM app_sessions WHERE expires_at IS NOT NULL AND expires_at < :now",
            ['now' => $now]
        );
    }

    /**
     * Invalidate all sessions for a user except the current one.
     */
    public static function logoutOtherDevices(int $userId, string $currentSessionId): void
    {
        Database::query(
            "DELETE FROM app_sessions WHERE user_id = :id AND session_id != :sid",
            ['id' => $userId, 'sid' => $currentSessionId]
        );
    }
}
