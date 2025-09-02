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
     */
    private static function getCachePath(string $hash): string
    {
        return self::$cacheDir . $hash . '.cache';
    }

    /**
     * Checks if cached file exists and is still valid.
     */
    public static function getCachedImage(string $hash): ?string
    {
        self::ensureCacheDir();
        $cachePath = self::getCachePath($hash);

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
     */
    public static function storeImage(string $hash, string $fullPath): string
    {
        self::ensureCacheDir();
        $cachePath = self::getCachePath($hash);

        // Copy original file into cache
        copy($fullPath, $cachePath);

        return $cachePath;
    }
}
