<?php

class ImageCacheEngine
{
    // Cache directory for processed images
    private static string $cacheDir = __DIR__ . '/../cache/images/';

    // Cache lifetime in seconds (24 hours)
    private static int $cacheLifetime = 86400;

    /**
     * Ensures cache directory exists.
     */
    private static function ensureCacheDir(): void
    {
        if (!is_dir(self::$cacheDir))
        {
            mkdir(self::$cacheDir, 0775, true);
        }
    }

    /**
     * Generates the cache file path for an image hash.
     * Optionally includes user ID for restricted per-user caching.
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
     * Checks if cached file exists and is still valid.
     * Supports both global and per-user cache.
     */
    public static function getCachedImage(string $hash, ?int $userId = null): ?string
    {
        self::ensureCacheDir();
        $cachePath = self::getCachePath($hash, $userId);

        if (file_exists($cachePath))
        {
            // If expired, remove the cache file
            if (time() - filemtime($cachePath) > self::$cacheLifetime)
            {
                unlink($cachePath);
                return null;
            }

            // Cache is valid – extend its life (sliding expiration)
            touch($cachePath);
            return $cachePath;
        }

        return null;
    }

    /**
     * Stores a new cached copy of the image.
     * Supports both global and per-user cache.
     */
    public static function storeImage(string $hash, string $fullPath, ?int $userId = null): string
    {
        self::ensureCacheDir();
        $cachePath = self::getCachePath($hash, $userId);

        // Copy original file into cache
        copy($fullPath, $cachePath);

        return $cachePath;
    }
}
