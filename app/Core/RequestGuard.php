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
 * - Uses SessionManager device fingerprint + request fingerprint as the primary client key.
 * - Tracks guest/member state, source IP churn, and automation tooling signals.
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
        $global = self::getConfigArray('request_guard.global', []);
        $enabled = !empty($global['enabled']);
        if ($enabled)
        {
            $limit = TypeHelper::toInt($global['limit'] ?? 300);
            $window = TypeHelper::toInt($global['window_seconds'] ?? 60);
            $cooldown = TypeHelper::toInt($global['cooldown_seconds'] ?? 300);

            $globalKey = self::isAuthenticated() ? self::actorKey() : self::clientKey();
            if (self::hitLimit('global', $globalKey, $window, $limit))
            {
                self::applyDecision('rate_limited', self::isAuthenticated() ? 'global_rate_limit_user' : 'global_rate_limit_guest', $cooldown);
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

        $imgCfg = self::getConfigArray('request_guard.images', []);
        $enabled = !array_key_exists('enabled', $imgCfg) || !empty($imgCfg['enabled']);

        if (!$enabled)
        {
            return;
        }

        $window = TypeHelper::toInt($imgCfg['window_seconds'] ?? 60);
        $limitUser = TypeHelper::toInt($imgCfg['limit_per_fingerprint'] ?? 120);
        $limitMember = TypeHelper::toInt($imgCfg['limit_per_user'] ?? $limitUser);
        $limitGuest = TypeHelper::toInt($imgCfg['limit_per_guest'] ?? $limitUser);
        $limitBot = TypeHelper::toInt($imgCfg['limit_per_fingerprint_bot'] ?? 40);
        $cooldown = TypeHelper::toInt($imgCfg['cooldown_seconds'] ?? 600);
        $ipEscalationFingerprints = TypeHelper::toInt($imgCfg['ip_escalation_fingerprints'] ?? 10);
        $ipEscalationWindow = TypeHelper::toInt($imgCfg['ip_escalation_window_seconds'] ?? 300);
        $ipLimit = TypeHelper::toInt($imgCfg['ip_limit_when_escalated'] ?? 200);
        $multiIpWindow = TypeHelper::toInt($imgCfg['device_multi_ip_window_seconds'] ?? 600);
        $multiIpThreshold = TypeHelper::toInt($imgCfg['device_multi_ip_threshold'] ?? 5);
        $toolLimit = TypeHelper::toInt($imgCfg['limit_per_fingerprint_tool'] ?? $limitBot);

        $clientKey = self::clientKey();
        $actorKey = self::actorKey();
        $toolName = self::detectAutomationToolName($ua);

        // Bots that announce themselves are allowed but should still behave.
        if ($isAllowedBot)
        {
            $botLimit = TypeHelper::toInt($imgCfg['limit_per_fingerprint_search_bot'] ?? 120);
            if (self::hitLimit('img_bot', $clientKey, $window, $botLimit))
            {
                self::applyDecision('rate_limited', 'image_rate_limit_search_bot', $cooldown, $hash);
                self::renderDenied('rate_limited');
                exit;
            }

            return;
        }

        // Likely bots and obvious tooling get tighter per-client limits.
        if ($toolName !== null)
        {
            if (self::hitLimit('img_tool', $clientKey, $window, $toolLimit))
            {
                self::applyDecision('jailed', 'image_rate_limit_tool|' . $toolName, $cooldown, $hash);
                self::renderDenied('rate_limited');
                exit;
            }
        }

        $limit = $isLikelyBot ? $limitBot : (self::isAuthenticated() ? $limitMember : $limitGuest);
        if (self::hitLimit('img', $clientKey, $window, $limit))
        {
            $reason = 'image_rate_limit';
            if ($isLikelyBot)
            {
                $reason = 'image_rate_limit_likely_bot';
            }
            else if (self::isAuthenticated())
            {
                $reason = 'image_rate_limit_user';
            }
            else
            {
                $reason = 'image_rate_limit_guest';
            }

            self::applyDecision('rate_limited', $reason, $cooldown, $hash);
            self::renderDenied('rate_limited');
            exit;
        }

        if (self::isAuthenticated() && self::hitLimit('img_user', $actorKey, $window, $limitMember))
        {
            self::applyDecision('rate_limited', 'image_rate_limit_user_account', $cooldown, $hash);
            self::renderDenied('rate_limited');
            exit;
        }

        if (!self::isAuthenticated() && self::hitLimit('img_guest', $actorKey, $window, $limitGuest))
        {
            self::applyDecision('rate_limited', 'image_rate_limit_guest_actor', $cooldown, $hash);
            self::renderDenied('rate_limited');
            exit;
        }

        if (self::deviceFingerprint() !== '' && self::distinctIpsForDeviceFingerprint(self::deviceFingerprint(), $multiIpWindow) >= $multiIpThreshold)
        {
            self::applyDecision('jailed', 'image_device_multi_ip', $cooldown, $hash);
            self::renderDenied('rate_limited');
            exit;
        }

        // Escalate to IP-level limiting if this IP is churning fingerprints
        $ip = self::ip();
        if ($ip !== '' && self::distinctFingerprintsForIp($ip, $ipEscalationWindow) >= $ipEscalationFingerprints)
        {
            $ipKey = 'ip|' . IpHelper::normalizeForGrouping($ip);
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

        $cfg = self::getConfigArray('request_guard.auth', []);
        $enabled = !array_key_exists('enabled', $cfg) || !empty($cfg['enabled']);
        if (!$enabled)
        {
            return;
        }

        $window = TypeHelper::toInt($cfg['window_seconds'] ?? 600);
        $limit = TypeHelper::toInt($cfg['limit_per_fingerprint'] ?? 12);
        $limitUser = TypeHelper::toInt($cfg['limit_per_user'] ?? $limit);
        $limitGuest = TypeHelper::toInt($cfg['limit_per_guest'] ?? $limit);
        $cooldown = TypeHelper::toInt($cfg['cooldown_seconds'] ?? 600);
        $ipLimit = TypeHelper::toInt($cfg['limit_per_ip'] ?? 30);
        $deviceIpThreshold = TypeHelper::toInt($cfg['device_multi_ip_threshold'] ?? 4);
        $deviceIpWindow = TypeHelper::toInt($cfg['device_multi_ip_window_seconds'] ?? 900);
        $singleIpFloodThreshold = TypeHelper::toInt($cfg['single_ip_flood_threshold'] ?? max($ipLimit, $limitGuest));
        $toolLimit = TypeHelper::toInt($cfg['limit_per_tool'] ?? max(3, (int)floor($limitGuest / 2)));

        $clientKey = self::clientKey();
        $actorKey = self::actorKey();
        $toolName = self::detectAutomationToolName(self::userAgent());
        $actorLimit = self::isAuthenticated() ? $limitUser : $limitGuest;

        if (self::hitLimit('auth_' . $action, $clientKey, $window, $actorLimit))
        {
            self::applyDecision('jailed', self::isAuthenticated() ? ('auth_rate_limit_' . $action . '_user') : ('auth_rate_limit_' . $action . '_guest'), $cooldown);
            self::renderDenied('rate_limited');
            exit;
        }

        if (self::hitLimit('auth_' . $action . '_actor', $actorKey, $window, $actorLimit))
        {
            self::applyDecision('jailed', self::isAuthenticated() ? ('auth_rate_limit_' . $action . '_actor_user') : ('auth_rate_limit_' . $action . '_actor_guest'), $cooldown);
            self::renderDenied('rate_limited');
            exit;
        }

        if ($toolName !== null && self::hitLimit('auth_' . $action . '_tool', $clientKey, $window, $toolLimit))
        {
            self::applyDecision('jailed', 'auth_rate_limit_' . $action . '_tool|' . $toolName, $cooldown);
            self::renderDenied('rate_limited');
            exit;
        }

        if (self::deviceFingerprint() !== '' && self::distinctIpsForDeviceFingerprint(self::deviceFingerprint(), $deviceIpWindow) >= $deviceIpThreshold)
        {
            self::applyDecision('jailed', 'auth_rate_limit_' . $action . '_device_multi_ip', $cooldown);
            self::renderDenied('rate_limited');
            exit;
        }

        $ip = self::ip();
        if ($ip !== '')
        {
            $ipKey = 'ip|' . IpHelper::normalizeForGrouping($ip);
            if (self::hitLimit('auth_' . $action . '_ip', $ipKey, $window, $ipLimit))
            {
                $reason = 'auth_rate_limit_' . $action . '_ip';
                if (self::hitCount('auth_' . $action . '_ip_flood', $ipKey, $window) >= $singleIpFloodThreshold)
                {
                    $reason = 'auth_rate_limit_' . $action . '_single_ip_flood';
                }

                self::applyDecision('jailed', $reason, $cooldown, '', true);
                self::renderDenied('rate_limited');
                exit;
            }
        }
    }

    /**
     * Check whether one gallery page request should be rate limited.
     *
     * The gallery page authorizes one page of thumbnail/image requests at once,
     * so limiting is performed on the page request instead of every image tile.
     *
     * @return bool True when the gallery page request should be denied.
     */
    public static function isGalleryPageRateLimited(): bool
    {
        $cfg = self::getConfigArray('request_guard.gallery_pages', []);
        $enabled = !array_key_exists('enabled', $cfg) || !empty($cfg['enabled']);
        if (!$enabled)
        {
            return false;
        }

        $window = TypeHelper::toInt($cfg['window_seconds'] ?? 60);
        $limitFingerprint = TypeHelper::toInt($cfg['limit_per_fingerprint'] ?? 60);
        $limitUser = TypeHelper::toInt($cfg['limit_per_user'] ?? $limitFingerprint);
        $limitGuest = TypeHelper::toInt($cfg['limit_per_guest'] ?? $limitFingerprint);
        $limitIp = TypeHelper::toInt($cfg['limit_per_ip'] ?? max($limitUser, $limitGuest));
        $cooldown = TypeHelper::toInt($cfg['cooldown_seconds'] ?? 300);

        $clientKey = self::clientKey();
        $actorKey = self::actorKey();
        $actorLimit = self::isAuthenticated() ? $limitUser : $limitGuest;

        if (self::hitLimit('gallery_page', $clientKey, $window, $limitFingerprint))
        {
            self::applyDecision('rate_limited', self::isAuthenticated() ? 'gallery_page_rate_limit_user' : 'gallery_page_rate_limit_guest', $cooldown);
            return true;
        }

        if (self::hitLimit('gallery_page_actor', $actorKey, $window, $actorLimit))
        {
            self::applyDecision('rate_limited', self::isAuthenticated() ? 'gallery_page_rate_limit_user_account' : 'gallery_page_rate_limit_guest_actor', $cooldown);
            return true;
        }

        $ip = self::ip();
        if ($ip !== '')
        {
            $ipKey = 'ip|' . IpHelper::normalizeForGrouping($ip);
            if (self::hitLimit('gallery_page_ip', $ipKey, $window, $limitIp))
            {
                self::applyDecision('rate_limited', 'gallery_page_rate_limit_ip', $cooldown, '', true);
                return true;
            }
        }

        return false;
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
        $cfg = self::getConfigArray('request_guard.auth', []);
        $window = TypeHelper::toInt($cfg['failure_window_seconds'] ?? 600);
        $threshold = TypeHelper::toInt($cfg['failure_threshold'] ?? 10);
        $cooldown = TypeHelper::toInt($cfg['failure_cooldown_seconds'] ?? 900);

        $clientKey = self::clientKey();
        $actorKey = self::actorKey();
        $hits = self::hitCount('auth_fail_' . $action, $clientKey, $window);
        $actorHits = self::hitCount('auth_fail_' . $action . '_actor', $actorKey, $window);
        $deviceFingerprint = self::deviceFingerprint();

        if ($hits >= $threshold || $actorHits >= $threshold)
        {
            $reasonKey = $hits >= $threshold ? 'auth_fail_threshold_' . $action : 'auth_fail_threshold_actor_' . $action;
            self::applyDecision('jailed', $reasonKey, $cooldown);
        }
        else
        {
            $multiIpThreshold = TypeHelper::toInt($cfg['device_multi_ip_threshold'] ?? 4);
            $multiIpWindow = TypeHelper::toInt($cfg['device_multi_ip_window_seconds'] ?? 900);

            if ($deviceFingerprint !== '' && self::distinctIpsForDeviceFingerprint($deviceFingerprint, $multiIpWindow) >= $multiIpThreshold)
            {
                self::applyDecision('jailed', 'auth_fail_multi_ip_device_' . $action, $cooldown);
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
    }

    /**
     * Check whether one authenticated interactive action should be rate limited.
     *
     * This is intended for low-cost but abuse-prone member actions such as
     * uploads, comments, votes, favorites, and metadata edits.
     *
     * @param string $action Action name key from request_guard.actions
     * @return bool True when the action should be denied for now
     */
    public static function isInteractiveActionRateLimited(string $action): bool
    {
        $action = strtolower(trim($action));
        if ($action === '')
        {
            return false;
        }

        $cfg = self::getConfigArray('request_guard.actions.' . $action, []);
        if (empty($cfg))
        {
            $cfg = self::getConfigArray('request_guard.actions.default', []);
        }

        $enabled = !array_key_exists('enabled', $cfg) || !empty($cfg['enabled']);
        if (!$enabled)
        {
            return false;
        }

        $window = TypeHelper::toInt($cfg['window_seconds'] ?? 300);
        $limitFingerprint = TypeHelper::toInt($cfg['limit_per_fingerprint'] ?? 20);
        $limitUser = TypeHelper::toInt($cfg['limit_per_user'] ?? $limitFingerprint);
        $limitIp = TypeHelper::toInt($cfg['limit_per_ip'] ?? max($limitUser, $limitFingerprint));
        $cooldown = TypeHelper::toInt($cfg['cooldown_seconds'] ?? 600);

        $clientKey = self::clientKey();
        $actorKey = self::actorKey();

        if (self::hitLimit('act_' . $action, $clientKey, $window, $limitFingerprint))
        {
            self::applyDecision('rate_limited', 'interactive_action_' . $action . '_fingerprint', $cooldown);
            return true;
        }

        if (self::hitLimit('act_' . $action . '_actor', $actorKey, $window, $limitUser))
        {
            self::applyDecision('rate_limited', 'interactive_action_' . $action . '_actor', $cooldown);
            return true;
        }

        $ip = self::ip();
        if ($ip !== '')
        {
            $ipKey = 'ip|' . IpHelper::normalizeForGrouping($ip);
            if (self::hitLimit('act_' . $action . '_ip', $ipKey, $window, $limitIp))
            {
                self::applyDecision('rate_limited', 'interactive_action_' . $action . '_ip', $cooldown, '', true);
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a specific user_id currently has an active deny decision.
     *
     * Used during login flows before a session user_id is established.
     *
     * @param int $userId User identifier to check.
     * @return bool True when an active decision exists for the user.
     */
    public static function hasActiveUserDecision(int $userId): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        $row = Database::fetch("SELECT status FROM app_block_list WHERE scope = 'user_id' AND user_id = :uid AND (expires_at IS NULL OR expires_at > :now) ORDER BY id DESC LIMIT 1",
            ['uid' => $userId, 'now' => $now]
        );

        return !empty($row);
    }

    /**
     * Jail a specific user_id for a number of seconds.
     *
     * This is used for account-specific lockouts (e.g. repeated failed login attempts).
     *
     * @param int $userId User identifier to jail.
     * @param string $reason Reason key or short description.
     * @param int $seconds Number of seconds the jail should remain active.
     * @return void
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
     * Ban a specific user_id permanently (no expiry).
     *
     * @param int $userId User identifier to ban.
     * @param string $reason Reason key or short description.
     * @return void
     */
    public static function banUser(int $userId, string $reason): void
    {
        if ($userId <= 0)
        {
            return;
        }

        self::applyDecisionForUser($userId, 'banned', $reason, 0);
    }

    /**
     * Jail the current client (fingerprint by default, or IP when requested).
     *
     * Used for guest protection (brute force, scraping, fingerprint mismatch, etc.)
     *
     * @param string $reason Reason key or short description.
     * @param int $seconds Number of seconds the jail should remain active.
     * @param bool $preferIp When true, prefer IP scope instead of fingerprint for guests.
     * @return void
     */
    public static function jailClient(string $reason, int $seconds, bool $preferIp = false): void
    {
        self::applyDecision('jailed', $reason, $seconds, '', $preferIp);
    }

    /**
     * Log a security message for the current request context.
     *
     * @param string $category Log category.
     * @param string $message Log message content.
     * @param int|null $userId Optional user identifier override.
     * @param string|null $expiresAt Optional expiry timestamp for the log entry.
     * @return void
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
     * Resolution priority:
     * - user_id
     * - device_fingerprint
     * - fingerprint
     * - ip
     * - user agent hash
     *
     * @return array|null Active decision row when found, otherwise null.
     */
    private static function getActiveDecision(): ?array
    {
        $now = gmdate('Y-m-d H:i:s');

        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        $fingerprint = TypeHelper::toString(SessionManager::get('fingerprint'), allowEmpty: true) ?? '';
        $deviceFingerprint = self::deviceFingerprint();
        $ua = self::userAgent();
        $ip = self::ip();

        // Priority order: user_id -> device_fingerprint -> fingerprint -> ip -> ua
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

        if ($deviceFingerprint !== '')
        {
            $row = Database::fetch("SELECT status, expires_at FROM app_block_list WHERE scope = 'device_fingerprint' AND device_fingerprint = :dfp AND (expires_at IS NULL OR expires_at > :now) ORDER BY id DESC LIMIT 1",
                ['dfp' => $deviceFingerprint, 'now' => $now]
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
            $uaHash = hash('sha256', mb_strtolower(TypeHelper::toString($ua)));
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
     * Scope selection rules:
     * - user_id when a logged-in user is available
     * - ip when preferIp is requested and an IP is available
     * - device_fingerprint when a stable device identifier is available
     * - fingerprint otherwise
     *
     * @param string $status blocked|banned|rate_limited|jailed
     * @param string $reason Short reason key.
     * @param int $seconds Expiry window in seconds. Use 0 for no expiry.
     * @param string $context Optional context suffix (e.g. image hash).
     * @param bool $preferIp Whether guest enforcement should prefer IP scope.
     * @return void
     */
    private static function applyDecision(string $status, string $reason, int $seconds, string $context = '', bool $preferIp = false): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $expiresAt = $seconds > 0 ? gmdate('Y-m-d H:i:s', time() + $seconds) : null;

        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        $fingerprint = TypeHelper::toString(SessionManager::get('fingerprint'), allowEmpty: true) ?? '';
        $deviceFingerprint = self::deviceFingerprint();
        $ua = self::userAgent();
        $ip = self::ip();

        // Choose scope: user_id if known; otherwise IP when requested, then device fingerprint, then request fingerprint.
        $scope = $deviceFingerprint !== '' ? 'device_fingerprint' : 'fingerprint';
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
        $dfpStore = null;
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
            $valueHash = hash('sha256', 'ip|' . IpHelper::normalizeForGrouping($ip));
        }
        else if ($scope === 'device_fingerprint')
        {
            $dfpStore = $deviceFingerprint;
            $fpStore = $fingerprint !== '' ? $fingerprint : null;
            $valueHash = hash('sha256', 'dfp|' . $deviceFingerprint);
        }
        else
        {
            $fpStore = $fingerprint;
            $dfpStore = $deviceFingerprint !== '' ? $deviceFingerprint : null;
            $valueHash = hash('sha256', 'fp|' . $fingerprint);
        }

        // Preserve the raw user agent for moderation / auditing,
        // but cap it to a safe storage length.
        if ($ua !== '')
        {
            $uaStore = mb_substr($ua, 0, 255);
        }

        Database::query("INSERT INTO app_block_list (scope, value_hash, user_id, ip, ua, fingerprint, device_fingerprint, status, reason, created_at, last_seen, expires_at)
             VALUES (:scope, :h, :uid, :ip, :ua, :fp, :dfp, :status, :reason, :created, :seen, :expires)
             ON DUPLICATE KEY UPDATE status = :status_upd, reason = :reason_upd, last_seen = :seen_upd, expires_at = :expires_upd",
            [
                'scope' => $scope,
                'h' => $valueHash,
                'uid' => $uidStore,
                'ip' => $ipStore,
                'ua' => $uaStore,
                'fp' => $fpStore,
                'dfp' => $dfpStore,
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
     *
     * This is primarily used for account-level enforcement during authentication
     * flows where the user record is known but the session is not yet established.
     *
     * @param int $userId User identifier to target.
     * @param string $status blocked|banned|rate_limited|jailed
     * @param string $reason Short reason key.
     * @param int $seconds Expiry window in seconds. Use 0 for no expiry.
     * @return void
     */
    private static function applyDecisionForUser(int $userId, string $status, string $reason, int $seconds): void
    {
        $expiresAt = $seconds > 0 ? gmdate('Y-m-d H:i:s', time() + $seconds) : null;

        $ua = self::userAgent();
        $ip = self::ip();
        $fingerprint = TypeHelper::toString(SessionManager::get('fingerprint'), allowEmpty: true) ?? '';
        $deviceFingerprint = self::deviceFingerprint();

        $valueHash = hash('sha256', 'user|' . $userId);

        Database::query(
            "INSERT INTO app_block_list (scope, value_hash, user_id, ip, ua, fingerprint, device_fingerprint, status, reason, created_at, last_seen, expires_at)
             VALUES ('user_id', :vh, :uid, :ip, :ua, :fp, :dfp, :st, :rs, NOW(), NOW(), :exp)",
            [
                'vh' => $valueHash,
                'uid' => $userId,
                'ip' => $ip !== '' ? inet_pton($ip) : null,
                'ua' => $ua !== '' ? mb_substr($ua, 0, 255) : null,
                'fp' => $fingerprint !== '' ? $fingerprint : null,
                'dfp' => $deviceFingerprint !== '' ? $deviceFingerprint : null,
                'st' => $status,
                'rs' => mb_substr($reason, 0, 255),
                'exp' => $expiresAt,
            ]
        );

        self::logAction('decision', $status . ':' . $reason, $expiresAt, $userId);
    }

    /**
     * Delete expired request guard counters and temporary blocks.
     *
     * Intended for periodic housekeeping driven by the background maintenance
     * server so normal page requests do not perform cleanup work.
     *
     * @return array{counters:int, blocks:int} Number of expired rows removed
     */
    public static function cleanExpired(): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $countersRemoved = 0;
        $blocksRemoved = 0;

        try
        {
            $countersRemoved = Database::execute("DELETE FROM app_rate_counters WHERE expires_at IS NOT NULL AND expires_at < :now",
                ['now' => $now]
            );
        }
        catch (Throwable $e)
        {
            // ignore
        }

        try
        {
            $blocksRemoved = Database::execute("DELETE FROM app_block_list WHERE expires_at IS NOT NULL AND expires_at < :now",
                ['now' => $now]
            );
        }
        catch (Throwable $e)
        {
            // ignore
        }

        return [
            'counters' => $countersRemoved,
            'blocks' => $blocksRemoved,
        ];
    }

    /**
     * Log a guard action.
     *
     * This writes to the security audit table when available and silently
     * returns when the table is not yet present (fresh installs / early bootstrap).
     *
     * @param string $category Event category.
     * @param string $message Event message.
     * @param string|null $expiresAt Optional expiry timestamp associated with the event.
     * @param int|null $userIdOverride Optional user identifier override.
     * @return void
     */
    private static function logAction(string $category, string $message, ?string $expiresAt, ?int $userIdOverride = null): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $userId = $userIdOverride !== null ? $userIdOverride : (TypeHelper::toInt(SessionManager::get('user_id')) ?? null);
        $sessionId = session_id();
        $ip = inet_pton(self::ip());
        $ua = self::userAgent();
        $fingerprint = TypeHelper::toString(SessionManager::get('fingerprint'), allowEmpty: true) ?? '';
        $deviceFingerprint = self::deviceFingerprint();

        Database::query("INSERT INTO app_security_logs (user_id, session_id, ip, ua, fingerprint, device_fingerprint, category, message, created_at)
             VALUES (:uid, :sid, :ip, :ua, :fp, :dfp, :cat, :msg, :created)",
            [
                'uid' => $userId,
                'sid' => $sessionId,
                'ip' => $ip,
                'ua' => mb_substr($ua, 0, 255),
                'fp' => $fingerprint !== '' ? $fingerprint : null,
                'dfp' => $deviceFingerprint !== '' ? $deviceFingerprint : null,
                'cat' => $category,
                'msg' => mb_substr($message, 0, 255),
                'created' => $now
            ]
        );
    }

    /**
     * Hit a rate limit counter and return whether the configured limit is exceeded.
     *
     * @param string $scope Counter namespace.
     * @param string $key Client or resource key.
     * @param int $windowSeconds Window size in seconds.
     * @param int $limit Maximum allowed hits in the window.
     * @return bool True when the limit has been exceeded.
     */
    private static function hitLimit(string $scope, string $key, int $windowSeconds, int $limit): bool
    {
        $hits = self::hitCount($scope, $key, $windowSeconds);
        return $hits > $limit;
    }

    /**
     * Increment a rate counter and return the new hit count.
     *
     * Counters are bucketed by a fixed window start timestamp so repeated
     * requests within the same window accumulate on the same row.
     *
     * @param string $scope Counter namespace.
     * @param string $key Client or resource key.
     * @param int $windowSeconds Window size in seconds.
     * @return int Current hit count for the active bucket.
     */
    private static function hitCount(string $scope, string $key, int $windowSeconds): int
    {
        $windowSeconds = max(1, $windowSeconds);
        $bucketStartTs = floor(time() / $windowSeconds) * $windowSeconds;
        $bucketStart = gmdate('Y-m-d H:i:s', $bucketStartTs);
        $expiresAt = gmdate('Y-m-d H:i:s', $bucketStartTs + $windowSeconds + 5);

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

        return TypeHelper::toInt($row['hits'] ?? 0);
    }

    /**
     * Count distinct fingerprints observed for an IP in a time window.
     *
     * Used to detect IPs rapidly churning fingerprints, which can indicate
     * botnets, spoofing, or other evasive scraping behavior.
     *
     * @param string $ip Source IP address.
     * @param int $windowSeconds Lookback window in seconds.
     * @return int Number of distinct fingerprints seen for the IP.
     */
    private static function distinctFingerprintsForIp(string $ip, int $windowSeconds): int
    {
        $windowSeconds = max(1, $windowSeconds);
        $now = gmdate('Y-m-d H:i:s');

        $row = Database::fetch("SELECT COUNT(DISTINCT COALESCE(device_fingerprint, fingerprint)) AS total
             FROM app_sessions
             WHERE first_ip = :ip AND last_activity > DATE_SUB(:now, INTERVAL {$windowSeconds} SECOND)",
            [
                'ip' => inet_pton($ip),
                'now' => $now
            ]
        );

        return TypeHelper::toInt($row['total'] ?? 0);
    }

    /**
     * Count distinct IP addresses recently observed for one device fingerprint.
     *
     * This helps spot tooling or session replay activity where the same device
     * identifier appears across multiple source IP addresses in a short period.
     *
     * @param string $deviceFingerprint Stable device fingerprint.
     * @param int $windowSeconds Lookback window in seconds.
     * @return int Number of distinct IP addresses seen.
     */
    private static function distinctIpsForDeviceFingerprint(string $deviceFingerprint, int $windowSeconds): int
    {
        if ($deviceFingerprint === '')
        {
            return 0;
        }

        $windowSeconds = max(1, $windowSeconds);
        $now = gmdate('Y-m-d H:i:s');

        $row = Database::fetch("SELECT COUNT(DISTINCT ip) AS total
             FROM app_sessions
             WHERE device_fingerprint = :dfp AND last_activity > DATE_SUB(:now, INTERVAL {$windowSeconds} SECOND)",
            [
                'dfp' => $deviceFingerprint,
                'now' => $now
            ]
        );

        return TypeHelper::toInt($row['total'] ?? 0);
    }

    /**
     * Render a consistent deny page for blocked, banned, jailed, or rate-limited requests.
     *
     * Response codes:
     * - 403 for blocked / banned
     * - 429 for jailed / rate limited
     *
     * @param string $status Enforcement status key.
     * @return void
     */
    private static function renderDenied(string $status): void
    {
        $config = (class_exists('SettingsManager') && SettingsManager::isInitialized())
            ? SettingsManager::getConfig()
            : (self::$config ?: (require CONFIG_PATH . '/config.php'));

        $template = new TemplateEngine(TEMPLATE_PATH, CACHE_TEMPLATE_PATH, $config);
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

    /**
     * Build the primary client key used for rate limiting.
     *
     * Preference order:
     * - Stable device fingerprint
     * - Session fingerprint
     * - Fallback hashed user agent
     *
     * @return string Normalized client key.
     */
    private static function clientKey(): string
    {
        $dfp = self::deviceFingerprint();
        $fp = TypeHelper::toString(SessionManager::get('fingerprint'), allowEmpty: true) ?? '';
        $ua = self::userAgent();

        // Prefer the stable device fingerprint; request fingerprint and UA are supplemental.
        if ($dfp !== '')
        {
            return 'dfp|' . $dfp;
        }

        if ($fp !== '')
        {
            return 'fp|' . $fp;
        }

        return 'ua|' . hash('sha256', mb_strtolower(trim($ua)));
    }

    /**
     * Build an actor key split between authenticated users and guests.
     *
     * This lets the guard apply separate rate-limit buckets for members and guests
     * without forcing everything to collapse onto a single fingerprint key.
     *
     * @return string Actor key.
     */
    private static function actorKey(): string
    {
        $userId = TypeHelper::toInt(SessionManager::get('user_id')) ?? 0;
        if ($userId > 0)
        {
            return 'user|' . $userId;
        }

        return 'guest|' . self::clientKey();
    }

    /**
     * Determine whether the current request is authenticated.
     *
     * @return bool True when a logged-in user_id is available.
     */
    private static function isAuthenticated(): bool
    {
        return (TypeHelper::toInt(SessionManager::get('user_id')) ?? 0) > 0;
    }

    /**
     * Return the current stable device fingerprint when available.
     *
     * @return string Device fingerprint or empty string.
     */
    private static function deviceFingerprint(): string
    {
        if (class_exists('SessionManager') && method_exists('SessionManager', 'getDeviceFingerprint'))
        {
            return TypeHelper::toString(SessionManager::getDeviceFingerprint(), allowEmpty: true) ?? '';
        }

        return TypeHelper::toString(SessionManager::get('device_fingerprint'), allowEmpty: true) ?? '';
    }

    /**
     * Return the current request user agent string.
     *
     * @return string User agent string or empty string when unavailable.
     */
    private static function userAgent(): string
    {
        return TypeHelper::toString($_SERVER['HTTP_USER_AGENT'] ?? '', allowEmpty: true) ?? '';
    }

    /**
     * Return the current request remote IP address.
     *
     * @return string IP address or empty string when unavailable.
     */
    private static function ip(): string
    {
        return TypeHelper::toString($_SERVER['REMOTE_ADDR'] ?? '', allowEmpty: true) ?? '';
    }


    /**
     * Detect obvious automation tooling names from the user agent.
     *
     * This intentionally stays lightweight and only labels clear signatures that
     * are useful for logs and stricter rate limits.
     *
     * @param string $ua User agent string to inspect.
     * @return string|null Tool label when detected; otherwise null.
     */
    private static function detectAutomationToolName(string $ua): ?string
    {
        $ua = mb_strtolower(TypeHelper::toString($ua));
        if ($ua === '')
        {
            return 'empty_ua';
        }

        $map = [
            'python-requests' => 'python_requests',
            'python-httpx' => 'python_httpx',
            'curl/' => 'curl',
            'wget/' => 'wget',
            'scrapy' => 'scrapy',
            'aiohttp' => 'aiohttp',
            'go-http-client' => 'go_http_client',
            'postmanruntime' => 'postman',
            'insomnia' => 'insomnia',
            'headlesschrome' => 'headless_chrome',
            'phantomjs' => 'phantomjs',
            'selenium' => 'selenium',
            'playwright' => 'playwright',
            'puppeteer' => 'puppeteer',
        ];

        foreach ($map as $needle => $label)
        {
            if (strpos($ua, $needle) !== false)
            {
                return $label;
            }
        }

        return null;
    }

    /**
     * Basic bot heuristic.
     *
     * This is intentionally lightweight and conservative:
     * - Empty user agents are treated as suspicious
     * - Common scripted client signatures are matched directly
     * - Very short user agents are treated as likely non-browser traffic
     *
     * @param string $ua User agent string to inspect.
     * @return bool True when the user agent appears bot-like or automated.
     */
    private static function isLikelyBot(string $ua): bool
    {
        $ua = mb_strtolower(TypeHelper::toString($ua));
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
            'postmanruntime',
            'insomnia',
            'selenium',
            'playwright',
            'puppeteer',
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
     *
     * This does not fully verify bot authenticity by reverse DNS or ASN; it only
     * checks the user agent string and is meant as a lightweight allow-list.
     *
     * @param string $ua User agent string to inspect.
     * @return bool True when the user agent matches an allowed search bot token.
     */
    private static function isAllowedSearchBot(string $ua): bool
    {
        $ua = mb_strtolower(TypeHelper::toString($ua));
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

    /**
     * Retrieve a nested config value and guarantee an array result.
     *
     * @param string $path Dot-notation config path.
     * @param array $default Default array to return when the value is missing or not an array.
     * @return array
     */
    private static function getConfigArray(string $path, array $default): array
    {
        $val = self::getFromArray(self::$config, $path, $default);
        return is_array($val) ? $val : $default;
    }

    /**
     * Retrieve a nested value from an array using dot notation.
     *
     * Example:
     * - path "request_guard.auth.window_seconds" resolves nested array keys
     *
     * @param array $arr Source array.
     * @param string $path Dot-notation path.
     * @param mixed $default Default value returned when the path is missing.
     * @return mixed
     */
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
