<?php

/**
 * Simple file-based image cache for serving processed or frequently-accessed images.
 *
 * Responsibilities:
 * - Ensures a writable cache directory exists
 * - Stores cached image copies by a deterministic cache key (hash + optional user scope)
 * - Returns cached paths when valid, with sliding expiration to keep hot items alive
 *
 * Notes:
 * - This cache stores files on disk (not in-memory) for fast reuse across requests.
 * - Cache keys can be scoped per-user to avoid leaking restricted images between users.
 * - Expiration is time-based and enforced lazily on access.
 */
class ImageCacheEngine
{
    // Cache directory for processed images (must be writable by the web server)
    private static string $cacheDir = __DIR__ . '/../cache/images/';

    // Cache lifetime in seconds (24 hours) for unused entries
    private static int $cacheLifetime = 86400;

    /**
     * Ensure the cache directory exists before attempting to read/write cached files.
     *
     * Creates the directory recursively when missing. Permissions are set to allow
     * the web server to write while remaining reasonably restrictive.
     */
    private static function ensureCacheDir(): void
    {
        if (!is_dir(self::$cacheDir))
        {
            mkdir(self::$cacheDir, 0775, true);
        }
    }

    /**
     * Build the cache file path for a given image hash.
     *
     * If a user ID is provided, the cache key is namespaced to the user so restricted
     * or personalized images cannot be served from another user's cache.
     *
     * @param string $hash Image hash or unique cache key component
     * @param int|null $userId Optional user scope for per-user caching
     * @return string Absolute path to the cached file on disk
     */
    private static function getCachePath(string $hash, ?int $userId = null): string
    {
        $key = $hash;
        if ($userId !== null)
        {
            $key .= '_u' . $userId;
        }

        return self::$cacheDir . $key . '.cache';
    }

    /**
     * Retrieve a cached image file path if it exists and has not expired.
     *
     * Behavior:
     * - If the cached file is older than the configured lifetime, it is deleted and null is returned.
     * - If valid, the file's mtime is updated (touch) to implement sliding expiration.
     *
     * @param string $hash Image hash or unique cache key component
     * @param int|null $userId Optional user scope for per-user caching
     * @return string|null Cache file path when valid; otherwise null
     */
    public static function getCachedImage(string $hash, ?int $userId = null): ?string
    {
        self::ensureCacheDir();
        $cachePath = self::getCachePath($hash, $userId);

        if (file_exists($cachePath))
        {
            // If expired, remove the cache file to prevent stale content from being served
            if (time() - filemtime($cachePath) > self::$cacheLifetime)
            {
                unlink($cachePath);
                return null;
            }

            // Cache hit: keep the entry "warm" by extending its effective lifetime
            touch($cachePath);
            return $cachePath;
        }

        return null;
    }

    /**
     * Store a cached copy of an image on disk.
     *
     * Copies the original file into the cache location derived from the hash (+ optional user scope).
     * This allows later requests to serve from the cached copy without re-processing.
     *
     * @param string $hash Image hash or unique cache key component
     * @param string $fullPath Source image path to cache
     * @param int|null $userId Optional user scope for per-user caching
     * @return string Cache file path that was written
     */
    public static function storeImage(string $hash, string $fullPath, ?int $userId = null): string
    {
        self::ensureCacheDir();
        $cachePath = self::getCachePath($hash, $userId);

        // Copy original file into cache for fast reuse across requests
        copy($fullPath, $cachePath);

        return $cachePath;
    }
}
