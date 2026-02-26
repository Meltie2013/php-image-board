<?php

/**
 * RequestGuard
 *
 * Central request security gate for both guests and authenticated users.
 *
 * Responsibilities:
 * - Enforce temporary jail / block / rate-limit decisions for the current request
 * - Apply rate limits for image delivery and auth endpoints
 * - Perform lightweight bot heuristics (strict by default, but allow known search bots)
 * - Log enforcement actions to the database for audit / moderation follow-up
 *
 * Notes:
 * - This class is intentionally conservative and avoids "hard" identity assumptions.
 * - Uses SessionManager fingerprint + persistent device cookie as the primary client key.
 * - Uses IP escalation when fingerprints change frequently (botnet / spoofing patterns).
 */
class RequestGuard
{
    /**
     * Cached config for guard usage.
     *
     * @var array
     */
    private static array $config = [];

    /**
     * Initialize the guard and enforce global deny rules.
     *
     * Call this once during bootstrap (after SessionManager::init).
     *
     * @param array $config Full merged config tree.
     * @return void
     */
    public static function init(array $config): void
    {
        self::$config = $config;

        // Global deny checks (blocklist/jail)
        $decision = self::getActiveDecision();
        if ($decision)
        {
            self::renderDenied($decision['status'] ?? 'blocked');
            exit;
        }

        // Optional: very light global rate limit for abusive clients (all endpoints)
        $global = self::getConfigArray('security.request_guard.global', []);
        $enabled = !empty($global['enabled']);
        if ($enabled)
        {
            $limit = (int)($global['limit'] ?? 300);
            $window = (int)($global['window_seconds'] ?? 60);
            $cooldown = (int)($global['cooldown_seconds'] ?? 300);

            if (self::hitLimit('global', self::clientKey(), $window, $limit))
            {
                self::applyDecision('rate_limited', 'global_rate_limit', $cooldown);
                self::renderDenied('rate_limited');
                exit;
            }
        }
    }

    /**
     * Enforce rate limiting + bot heuristics for the image delivery endpoint.
     *
     * @param string $hash Image hash (used only for audit context)
     * @return void
     */
    public static function enforceImageRequest(string $hash = ''): void
    {
        // Check deny rules first
        $decision = self::getActiveDecision();
        if ($decision)
        {
            self::renderDenied($decision['status'] ?? 'blocked');
            exit;
        }

        $ua = self::userAgent();
        $isAllowedBot = self::isAllowedSearchBot($ua);
        $isLikelyBot = self::isLikelyBot($ua);

        // Strict bot policy:
        // - Allow well-known search bots that announce themselves.
        // - Everyone else is treated as a normal browser, but "likely bot" gets tighter limits.

        $imgCfg = self::getConfigArray('security.request_guard.images', []);
        $enabled = !array_key_exists('enabled', $imgCfg) || !empty($imgCfg['enabled']);

        if (!$enabled)
        {
            return;
        }

        $window = (int)($imgCfg['window_seconds'] ?? 60);
        $limitUser = (int)($imgCfg['limit_per_fingerprint'] ?? 120);
        $limitBot = (int)($imgCfg['limit_per_fingerprint_bot'] ?? 40);
        $cooldown = (int)($imgCfg['cooldown_seconds'] ?? 600);
        $ipEscalationFingerprints = (int)($imgCfg['ip_escalation_fingerprints'] ?? 10);
        $ipEscalationWindow = (int)($imgCfg['ip_escalation_window_seconds'] ?? 300);
        $ipLimit = (int)($imgCfg['ip_limit_when_escalated'] ?? 200);

        $clientKey = self::clientKey();

        // Bots that announce themselves are allowed but should still behave.
        if ($isAllowedBot)
        {
            $botLimit = (int)($imgCfg['limit_per_fingerprint_search_bot'] ?? 120);
            if (self::hitLimit('img_bot', $clientKey, $window, $botLimit))
            {
                self::applyDecision('rate_limited', 'image_rate_limit_search_bot', $cooldown, $hash);
                self::renderDenied('rate_limited');
                exit;
            }

            return;
        }

        // Likely bots get tighter per-fingerprint limits
        $limit = $isLikelyBot ? $limitBot : $limitUser;
        if (self::hitLimit('img', $clientKey, $window, $limit))
        {
            self::applyDecision('rate_limited', $isLikelyBot ? 'image_rate_limit_likely_bot' : 'image_rate_limit', $cooldown, $hash);
            self::renderDenied('rate_limited');
            exit;
        }

        // Escalate to IP-level limiting if this IP is churning fingerprints
        $ip = self::ip();
        if ($ip !== '' && self::distinctFingerprintsForIp($ip, $ipEscalationWindow) >= $ipEscalationFingerprints)
        {
            $ipKey = 'ip|' . self::normalizeIp($ip);
            if (self::hitLimit('img_ip', $ipKey, $window, $ipLimit))
            {
                self::applyDecision('jailed', 'image_rate_limit_ip_escalation', $cooldown, $hash, true);
                self::renderDenied('rate_limited');
                exit;
            }
        }
    }

    /**
     * Enforce jail/rate-limits for auth attempts (login/register).
     *
     * @param string $action 'login' or 'register'
     * @return void
     */
    public static function enforceAuthAttempt(string $action): void
    {
        $decision = self::getActiveDecision();
        if ($decision)
        {
            self::renderDenied($decision['status'] ?? 'blocked');
            exit;
        }

        $cfg = self::getConfigArray('security.request_guard.auth', []);
        $enabled = !array_key_exists('enabled', $cfg) || !empty($cfg['enabled']);
        if (!$enabled)
        {
            return;
        }

        $window = (int)($cfg['window_seconds'] ?? 600);
        $limit = (int)($cfg['limit_per_fingerprint'] ?? 12);
        $cooldown = (int)($cfg['cooldown_seconds'] ?? 600);
        $ipLimit = (int)($cfg['limit_per_ip'] ?? 30);

        $clientKey = self::clientKey();
        if (self::hitLimit('auth_' . $action, $clientKey, $window, $limit))
        {
            self::applyDecision('jailed', 'auth_rate_limit_' . $action, $cooldown);
            self::renderDenied('rate_limited');
            exit;
        }

        $ip = self::ip();
        if ($ip !== '')
        {
            $ipKey = 'ip|' . self::normalizeIp($ip);
            if (self::hitLimit('auth_' . $action . '_ip', $ipKey, $window, $ipLimit))
            {
                self::applyDecision('jailed', 'auth_rate_limit_' . $action . '_ip', $cooldown, '', true);
                self::renderDenied('rate_limited');
                exit;
            }
        }
    }

    /**
     * Record an auth failure (used for brute force against unknown accounts).
     *
     * This does not reveal whether an account exists; it simply increments counters.
     *
     * @param string $action 'login' or 'register'
     * @param string $reason Short reason key for logging
     * @return void
     */
    public static function recordAuthFailure(string $action, string $reason = 'failed'): void
    {
        $cfg = self::getConfigArray('security.request_guard.auth', []);
        $window = (int)($cfg['failure_window_seconds'] ?? 600);
        $threshold = (int)($cfg['failure_threshold'] ?? 10);
        $cooldown = (int)($cfg['failure_cooldown_seconds'] ?? 900);

        $clientKey = self::clientKey();
        $hits = self::hitCount('auth_fail_' . $action, $clientKey, $window);

        if ($hits >= $threshold)
        {
            self::applyDecision('jailed', 'auth_fail_threshold_' . $action, $cooldown);
        }
        else
        {
            // Only log every few hits to reduce noise
            if (($hits % 3) === 0)
            {
                self::logAction('auth_fail_' . $action, $reason, null);
            }
        }
    }

    /**
     * Check if a specific user_id currently has an active deny decision.
     *
     * Used during login flows before a session user_id is established.
     */
    public static function hasActiveUserDecision(int $userId): bool
    {
        if ($userId <= 0 || !self::tableExists('app_block_list'))
        {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $row = Database::fetch("SELECT status FROM app_block_list WHERE scope = 'user_id' AND user_id = :uid AND (expires_at IS NULL OR expires_at > :now) ORDER BY id DESC LIMIT 1",
            ['uid' => $userId, 'now' => $now]
        );

        return !empty($row);
    }

    /**
     * Jail a specific user_id for a number of seconds.
     *
     * This is used for account-specific lockouts (e.g. repeated failed login attempts).
     */
    public static function jailUser(int $userId, string $reason, int $seconds): void
    {
        if ($userId <= 0)
        {
            return;
        }

        self::applyDecisionForUser($userId, 'jailed', $reason, $seconds);
    }

    /**
     * Jail the current client (fingerprint by default, or IP when requested).
     *
     * Used for guest protection (brute force, scraping, fingerprint mismatch, etc.)
     */
    public static function jailClient(string $reason, int $seconds, bool $preferIp = false): void
    {
        self::applyDecision('jailed', $reason, $seconds, '', $preferIp);
    }

    /**
     * Log a security message for the current request context.
     */
    public static function log(string $category, string $message, ?int $userId = null, ?string $expiresAt = null): void
    {
        self::logAction($category, $message, $expiresAt, $userId);
    }

    // ---------------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------------

    /**
     * Return an active deny/rate decision for this client.
     *
     * @return array|null
     */
    private static function getActiveDecision(): ?array
    {
        // If tables are missing (fresh install not yet migrated), fail open.
        if (!self::tableExists('app_block_list'))
        {
            return null;
        }

        $now = date('Y-m-d H:i:s');

        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        $fingerprint = TypeHelper::toString(SessionManager::get('fingerprint'), allowEmpty: true) ?? '';
        $ua = self::userAgent();
        $ip = self::ip();

        // Priority order: user_id -> fingerprint -> ip -> ua
        if ($userId > 0)
        {
            $row = Database::fetch("SELECT status, expires_at FROM app_block_list WHERE scope = 'user_id' AND user_id = :uid AND (expires_at IS NULL OR expires_at > :now) ORDER BY id DESC LIMIT 1",
                ['uid' => $userId, 'now' => $now]
            );
            if ($row)
            {
                return $row;
            }
        }

        if ($fingerprint !== '')
        {
            $row = Database::fetch("SELECT status, expires_at FROM app_block_list WHERE scope = 'fingerprint' AND fingerprint = :fp AND (expires_at IS NULL OR expires_at > :now) ORDER BY id DESC LIMIT 1",
                ['fp' => $fingerprint, 'now' => $now]
            );
            if ($row)
            {
                return $row;
            }
        }

        if ($ip !== '')
        {
            $row = Database::fetch("SELECT status, expires_at FROM app_block_list WHERE scope = 'ip' AND ip = :ip AND (expires_at IS NULL OR expires_at > :now) ORDER BY id DESC LIMIT 1",
                ['ip' => inet_pton($ip), 'now' => $now]
            );
            if ($row)
            {
                return $row;
            }
        }

        if ($ua !== '')
        {
            $uaHash = hash('sha256', mb_strtolower(trim($ua)));
            $row = Database::fetch("SELECT status, expires_at FROM app_block_list WHERE scope = 'ua' AND value_hash = :h AND (expires_at IS NULL OR expires_at > :now) ORDER BY id DESC LIMIT 1",
                ['h' => $uaHash, 'now' => $now]
            );
            if ($row)
            {
                return $row;
            }
        }

        return null;
    }

    /**
     * Apply a deny/rate decision for the current client.
     *
     * @param string $status blocked|banned|rate_limited|jailed
     * @param string $reason
     * @param int $seconds
     * @param string $context
     * @param bool $preferIp
     * @return void
     */
    private static function applyDecision(string $status, string $reason, int $seconds, string $context = '', bool $preferIp = false): void
    {
        if (!self::tableExists('app_block_list'))
        {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $expiresAt = $seconds > 0 ? date('Y-m-d H:i:s', time() + $seconds) : null;

        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        $fingerprint = TypeHelper::toString(SessionManager::get('fingerprint'), allowEmpty: true) ?? '';
        $ua = self::userAgent();
        $ip = self::ip();

        // Choose scope: user_id if known; otherwise fingerprint, unless preferIp is requested.
        $scope = 'fingerprint';
        if ($userId > 0)
        {
            $scope = 'user_id';
        }
        else if ($preferIp && $ip !== '')
        {
            $scope = 'ip';
        }

        $valueHash = null;
        $uaStore = null;
        $fpStore = null;
        $ipStore = null;
        $uidStore = null;

        if ($scope === 'user_id')
        {
            $uidStore = $userId;
            $valueHash = hash('sha256', 'user|' . $userId);
        }
        else if ($scope === 'ip')
        {
            $ipStore = inet_pton($ip);
            $valueHash = hash('sha256', 'ip|' . self::normalizeIp($ip));
        }
        else
        {
            $fpStore = $fingerprint;
            $valueHash = hash('sha256', 'fp|' . $fingerprint);
        }

        if ($ua !== '')
        {
            $uaStore = mb_substr($ua, 0, 255);
        }

        Database::query("INSERT INTO app_block_list (scope, value_hash, user_id, ip, ua, fingerprint, status, reason, created_at, last_seen, expires_at)
             VALUES (:scope, :h, :uid, :ip, :ua, :fp, :status, :reason, :created, :seen, :expires)
             ON DUPLICATE KEY UPDATE status = :status_upd, reason = :reason_upd, last_seen = :seen_upd, expires_at = :expires_upd",
            [
                'scope' => $scope,
                'h' => $valueHash,
                'uid' => $uidStore,
                'ip' => $ipStore,
                'ua' => $uaStore,
                'fp' => $fpStore,
                'status' => $status,
                'reason' => $reason . ($context !== '' ? ('|' . $context) : ''),
                'created' => $now,
                'seen' => $now,
                'expires' => $expiresAt,
                'status_upd' => $status,
                'reason_upd' => $reason . ($context !== '' ? ('|' . $context) : ''),
                'seen_upd' => $now,
                'expires_upd' => $expiresAt,
            ]
        );

        self::logAction('decision', $status . ':' . $reason, $expiresAt);
    }

    /**
     * Apply a decision for a specific user_id (even when not logged in yet).
     */
    private static function applyDecisionForUser(int $userId, string $status, string $reason, int $seconds): void
    {
        if (!self::tableExists('app_block_list'))
        {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $expiresAt = $seconds > 0 ? date('Y-m-d H:i:s', time() + $seconds) : null;

        $ua = self::userAgent();
        $ip = self::ip();
        $fingerprint = TypeHelper::toString(SessionManager::get('fingerprint'), allowEmpty: true) ?? '';

        $valueHash = hash('sha256', 'user|' . $userId);

        Database::query(
            "INSERT INTO app_block_list (scope, value_hash, user_id, ip, ua, fingerprint, status, reason, created_at, last_seen, expires_at)
             VALUES ('user_id', :vh, :uid, :ip, :ua, :fp, :st, :rs, :now, :now, :exp)",
            [
                'vh' => $valueHash,
                'uid' => $userId,
                'ip' => $ip !== '' ? inet_pton($ip) : null,
                'ua' => $ua !== '' ? mb_substr($ua, 0, 255) : null,
                'fp' => $fingerprint !== '' ? $fingerprint : null,
                'st' => $status,
                'rs' => mb_substr($reason, 0, 255),
                'now' => $now,
                'exp' => $expiresAt,
            ]
        );

        self::logAction('decision', $status . ':' . $reason, $expiresAt, $userId);
    }

    /**
     * Log a guard action.
     *
     * @param string $category
     * @param string $message
     * @param string|null $expiresAt
     * @return void
     */
    private static function logAction(string $category, string $message, ?string $expiresAt, ?int $userIdOverride = null): void
    {
        if (!self::tableExists('app_security_logs'))
        {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $userId = $userIdOverride !== null ? $userIdOverride : (TypeHelper::toInt(SessionManager::get('user_id')) ?? null);
        $sessionId = session_id();
        $ip = inet_pton(self::ip());
        $ua = self::userAgent();
        $fingerprint = TypeHelper::toString(SessionManager::get('fingerprint'), allowEmpty: true) ?? '';

        Database::query("INSERT INTO app_security_logs (user_id, session_id, ip, ua, fingerprint, category, message, created_at, expires_at)
             VALUES (:uid, :sid, :ip, :ua, :fp, :cat, :msg, :created, :expires)",
            [
                'uid' => $userId,
                'sid' => $sessionId,
                'ip' => $ip,
                'ua' => mb_substr($ua, 0, 255),
                'fp' => $fingerprint !== '' ? $fingerprint : null,
                'cat' => $category,
                'msg' => mb_substr($message, 0, 255),
                'created' => $now,
                'expires' => $expiresAt
            ]
        );
    }

    /**
     * Hit a rate limit counter and return whether limit exceeded.
     */
    private static function hitLimit(string $scope, string $key, int $windowSeconds, int $limit): bool
    {
        $hits = self::hitCount($scope, $key, $windowSeconds);
        return $hits > $limit;
    }

    /**
     * Increment a rate counter and return the new hit count.
     */
    private static function hitCount(string $scope, string $key, int $windowSeconds): int
    {
        if (!self::tableExists('app_rate_counters'))
        {
            return 0;
        }

        $windowSeconds = max(1, $windowSeconds);
        $bucketStartTs = (int)(floor(time() / $windowSeconds) * $windowSeconds);
        $bucketStart = date('Y-m-d H:i:s', $bucketStartTs);
        $expiresAt = date('Y-m-d H:i:s', $bucketStartTs + $windowSeconds + 5);

        $keyHash = hash('sha256', $scope . '|' . $key);

        Database::query("INSERT INTO app_rate_counters (scope, key_hash, window_start, hits, expires_at)
             VALUES (:scope, :h, :ws, 1, :expires)
             ON DUPLICATE KEY UPDATE hits = hits + 1",
            [
                'scope' => $scope,
                'h' => $keyHash,
                'ws' => $bucketStart,
                'expires' => $expiresAt
            ]
        );

        $row = Database::fetch("SELECT hits FROM app_rate_counters WHERE scope = :scope AND key_hash = :h AND window_start = :ws LIMIT 1",
            ['scope' => $scope, 'h' => $keyHash, 'ws' => $bucketStart]
        );

        return (int)($row['hits'] ?? 0);
    }

    /**
     * Count distinct fingerprints observed for an IP in a time window.
     */
    private static function distinctFingerprintsForIp(string $ip, int $windowSeconds): int
    {
        $windowSeconds = max(1, $windowSeconds);
        $now = date('Y-m-d H:i:s');

        $row = Database::fetch("SELECT COUNT(DISTINCT fingerprint) AS total
             FROM app_sessions
             WHERE first_ip = :ip AND last_activity > DATE_SUB(:now, INTERVAL {$windowSeconds} SECOND)",
            [
                'ip' => inet_pton($ip),
                'now' => $now
            ]
        );

        return (int)($row['total'] ?? 0);
    }

    /**
     * Render a consistent deny page for blocked/banned/rate-limited.
     */
    private static function renderDenied(string $status): void
    {
        $config = (class_exists('SettingsManager') && SettingsManager::isInitialized())
            ? SettingsManager::getConfig()
            : (self::$config ?: (require __DIR__ . '/../config/config.php'));

        $template = new TemplateEngine(dirname(__DIR__) . '/templates', dirname(__DIR__) . '/cache/templates', $config);
        if (!empty($config['template']['disable_cache']))
        {
            $template->clearCache();
        }

        $status = mb_strtolower(trim($status));
        $title = 'Access Restricted';
        $message = 'Your access to this resource is restricted.';

        if ($status === 'banned')
        {
            http_response_code(403);
            $title = 'Banned';
            $message = 'Your access has been banned.';
        }
        else if ($status === 'rate_limited')
        {
            http_response_code(429);
            $title = 'Rate Limited';
            $message = 'Too many requests. Please slow down and try again later.';
        }
        else if ($status === 'jailed')
        {
            http_response_code(429);
            $title = 'Temporarily Restricted';
            $message = 'Too many failed attempts. Please wait and try again later.';
        }
        else
        {
            http_response_code(403);
            $title = 'Blocked';
            $message = 'Your access has been blocked.';
        }

        $template->assign('title', $title);
        $template->assign('message', $message);
        $template->render('errors/error_page.html');
    }

    private static function clientKey(): string
    {
        $fp = TypeHelper::toString(SessionManager::get('fingerprint'), allowEmpty: true) ?? '';
        $ua = self::userAgent();

        // Prefer fingerprint; UA is only supplemental.
        if ($fp !== '')
        {
            return 'fp|' . $fp;
        }

        return 'ua|' . hash('sha256', mb_strtolower(trim($ua)));
    }

    private static function userAgent(): string
    {
        return TypeHelper::toString($_SERVER['HTTP_USER_AGENT'] ?? '', allowEmpty: true) ?? '';
    }

    private static function ip(): string
    {
        return TypeHelper::toString($_SERVER['REMOTE_ADDR'] ?? '', allowEmpty: true) ?? '';
    }

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
     * Basic bot heuristic.
     */
    private static function isLikelyBot(string $ua): bool
    {
        $ua = mb_strtolower(trim($ua));
        if ($ua === '')
        {
            return true;
        }

        // Extremely short UA strings, curl, wget, python-requests, etc.
        $signals = [
            'curl/',
            'wget/',
            'python-requests',
            'httpclient',
            'java/',
            'libwww',
            'scrapy',
            'aiohttp',
            'go-http-client',
            'headless',
            'phantomjs',
        ];

        foreach ($signals as $sig)
        {
            if (strpos($ua, $sig) !== false)
            {
                return true;
            }
        }

        return (strlen($ua) < 20);
    }

    /**
     * Allow-list of common search engine bots that announce themselves.
     */
    private static function isAllowedSearchBot(string $ua): bool
    {
        $ua = mb_strtolower(trim($ua));
        if ($ua === '')
        {
            return false;
        }

        $allowed = [
            'googlebot',
            'bingbot',
            'duckduckbot',
            'yandexbot',
            'baiduspider',
            'slurp',
            'ia_archiver',
        ];

        foreach ($allowed as $needle)
        {
            if (strpos($ua, $needle) !== false)
            {
                return true;
            }
        }

        return false;
    }

    private static function tableExists(string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache))
        {
            return $cache[$table];
        }

        try
        {
            // NOTE:
            // - Some PDO drivers do not reliably support prepared statements with
            //   SHOW TABLES LIKE ?, which can cause false negatives.
            // - information_schema is consistent and safe to parameterize.
            $row = Database::fetch("SELECT 1 AS ok
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :t
                 LIMIT 1",
                ['t' => $table]
            );

            $cache[$table] = !empty($row);
        }
        catch (Throwable $e)
        {
            $cache[$table] = false;
        }

        return $cache[$table];
    }

    private static function getConfigArray(string $path, array $default): array
    {
        $val = self::getFromArray(self::$config, $path, $default);
        return is_array($val) ? $val : $default;
    }

    private static function getFromArray(array $arr, string $path, $default = null)
    {
        $path = trim($path);
        if ($path === '')
        {
            return $default;
        }

        $parts = explode('.', $path);
        $cur = $arr;
        foreach ($parts as $p)
        {
            if (!is_array($cur) || !array_key_exists($p, $cur))
            {
                return $default;
            }

            $cur = $cur[$p];
        }

        return $cur;
    }
}
