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

        self::checkTimeout();

        // Save session to database
        self::persistSession();
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
        $lastActivity = date('Y-m-d H:i:s');
        $expiresAt = isset(self::$config['timeout_minutes'])
            ? date('Y-m-d H:i:s', time() + (self::$config['timeout_minutes'] * 60))
            : null;

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
                 SET user_id = :uid, ip = :ip, ua = :ua, last_activity = :last, expires_at = :expires, data = :data
                 WHERE session_id = :sid",
                [
                    'uid' => $userId,
                    'ip' => $ip,
                    'ua' => $ua,
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
                "INSERT INTO app_sessions (session_id, user_id, ip, ua, last_activity, expires_at, data)
                 VALUES (:sid, :uid, :ip, :ua, :last, :expires, :data)",
                [
                    'sid' => $sessionId,
                    'uid' => $userId,
                    'ip' => $ip,
                    'ua' => $ua,
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
