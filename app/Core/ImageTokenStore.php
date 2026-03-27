<?php

/**
 * Lightweight file-backed token store for gallery page image authorization.
 *
 * Responsibilities:
 * - Issues short-lived page tokens for the current gallery result set
 * - Stores only trusted image metadata needed for fast image delivery
 * - Validates token ownership using the current session/device cookies
 * - Allows the Control Server to remove expired token files centrally
 *
 * Notes:
 * - This store is intentionally independent from the normal PHP session flow so
 *   gallery image requests can avoid expensive session/database persistence.
 * - Tokens are bound to the current session cookie value and stable device cookie
 *   to reduce reuse outside the original browsing context.
 */
class ImageTokenStore
{
    /**
     * Directory used to persist short-lived gallery page tokens.
     */
    private static string $cacheDir = CACHE_PATH . '/tokens';

    /**
     * Maximum lifetime for one page token in seconds.
     */
    private static int $tokenLifetime = 600;

    /**
     * Ensure the token cache directory exists.
     *
     * @return void
     */
    private static function ensureCacheDir(): void
    {
        if (!is_dir(self::$cacheDir))
        {
            mkdir(self::$cacheDir, 0775, true);
        }
    }

    /**
     * Build the storage path for one token.
     *
     * @param string $token Token identifier.
     * @return string Absolute token file path.
     */
    private static function getTokenPath(string $token): string
    {
        return self::$cacheDir . '/' . $token . '.json';
    }

    /**
     * Normalize the stable device cookie value for lightweight token binding.
     *
     * @param array $config Full configuration array.
     * @return string Device cookie value for this request, or empty string.
     */
    public static function getCurrentDeviceId(array $config): string
    {
        $cookieName = TypeHelper::toString($config['session']['device_cookie_name'] ?? 'pg_device', allowEmpty: false) ?? 'pg_device';
        return TypeHelper::toString($_COOKIE[$cookieName] ?? '', allowEmpty: true) ?? '';
    }

    /**
     * Build a stable token for the current page payload.
     *
     * A deterministic token allows normal browser caching/back-navigation to reuse
     * the same gallery image URLs while the page contents remain unchanged.
     *
     * @param array $images Page image rows.
     * @param string $sessionId Current PHP session ID.
     * @param string $deviceId Stable device cookie value.
     * @return string Deterministic 32-character hex token.
     */
    private static function buildToken(array $images, string $sessionId, string $deviceId): string
    {
        $hashes = [];
        foreach ($images as $img)
        {
            $imageHash = TypeHelper::toString($img['image_hash'] ?? null, allowEmpty: false) ?? '';
            if ($imageHash !== '')
            {
                $hashes[] = $imageHash;
            }
        }

        return substr(hash('sha256', $sessionId . '|' . $deviceId . '|' . implode('|', $hashes)), 0, 32);
    }

    /**
     * Remove expired or unreadable token files from the cache directory.
     *
     * This is intended to run from the background maintenance server so normal
     * gallery page requests do not spend time scanning the token cache.
     *
     * @return int Number of token files removed
     */
    public static function cleanExpired(): int
    {
        self::ensureCacheDir();

        $threshold = time();
        $removed = 0;
        $files = glob(self::$cacheDir . '/*.json');
        if (!is_array($files))
        {
            return 0;
        }

        foreach ($files as $file)
        {
            if (!is_file($file))
            {
                continue;
            }

            $shouldDelete = false;
            $contents = @file_get_contents($file);
            if (!is_string($contents) || $contents === '')
            {
                $shouldDelete = true;
            }
            else
            {
                $payload = json_decode($contents, true);
                if (!is_array($payload))
                {
                    $shouldDelete = true;
                }
                else
                {
                    $expiresAt = TypeHelper::toInt($payload['expires_at'] ?? 0) ?? 0;
                    if ($expiresAt <= $threshold)
                    {
                        $shouldDelete = true;
                    }
                }
            }

            if ($shouldDelete && @unlink($file))
            {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Issue or refresh one gallery page token.
     *
     * @param array $images Page image rows containing hash/path/mime metadata.
     * @param int $userId Current authenticated user ID, or 0 for guests.
     * @param string $sessionId Current PHP session ID.
     * @param string $deviceId Stable device cookie value.
     * @return string Token identifier to embed into gallery image URLs.
     */
    public static function issue(array $images, int $userId, string $sessionId, string $deviceId): string
    {
        self::ensureCacheDir();

        $token = self::buildToken($images, $sessionId, $deviceId);
        $payload = [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'device_id' => $deviceId,
            'expires_at' => time() + self::$tokenLifetime,
            'images' => [],
        ];

        foreach ($images as $img)
        {
            $imageHash = TypeHelper::toString($img['image_hash'] ?? null, allowEmpty: false) ?? '';
            $originalPath = TypeHelper::toString($img['original_path'] ?? null, allowEmpty: false) ?? '';
            if ($imageHash === '' || $originalPath === '')
            {
                continue;
            }

            $payload['images'][$imageHash] = [
                'original_path' => $originalPath,
                'mime_type' => TypeHelper::toString($img['mime_type'] ?? null, allowEmpty: false) ?? 'application/octet-stream',
                'age_sensitive' => TypeHelper::toInt($img['age_sensitive'] ?? 0) ?? 0,
            ];
        }

        @file_put_contents(self::getTokenPath($token), json_encode($payload, JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $token;
    }

    /**
     * Retrieve one authorized image entry for the current token/cookie context.
     *
     * @param string $token Token identifier.
     * @param string $hash Image hash identifier.
     * @param string $sessionId Current PHP session ID.
     * @param string $deviceId Stable device cookie value.
     * @return array|null Authorized image metadata, or null when unavailable.
     */
    public static function getAuthorizedImage(string $token, string $hash, string $sessionId, string $deviceId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token))
        {
            return null;
        }

        $tokenPath = self::getTokenPath($token);
        if (!is_file($tokenPath))
        {
            return null;
        }

        $contents = @file_get_contents($tokenPath);
        if (!is_string($contents) || $contents === '')
        {
            return null;
        }

        $payload = json_decode($contents, true);
        if (!is_array($payload))
        {
            return null;
        }

        $expiresAt = TypeHelper::toInt($payload['expires_at'] ?? 0) ?? 0;
        if ($expiresAt <= time())
        {
            @unlink($tokenPath);
            return null;
        }

        $storedSessionId = TypeHelper::toString($payload['session_id'] ?? '', allowEmpty: true) ?? '';
        if ($storedSessionId === '' || $sessionId === '' || !hash_equals($storedSessionId, $sessionId))
        {
            return null;
        }

        $storedDeviceId = TypeHelper::toString($payload['device_id'] ?? '', allowEmpty: true) ?? '';
        if ($storedDeviceId !== '' && $deviceId !== '' && !hash_equals($storedDeviceId, $deviceId))
        {
            return null;
        }

        $images = $payload['images'] ?? [];
        if (!is_array($images) || !isset($images[$hash]) || !is_array($images[$hash]))
        {
            return null;
        }

        return $images[$hash];
    }
}
