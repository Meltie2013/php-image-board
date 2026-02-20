<?php

/**
 * StorageHelper
 *
 * Utility class for tracking and enforcing gallery storage limits.
 *
 * Responsibilities:
 * - Load gallery storage configuration and normalize it into bytes
 * - Convert human-friendly size strings (e.g., "500MB", "100GB") into raw bytes
 * - Query the database for total stored image size (SUM(size_bytes))
 * - Calculate remaining storage and determine whether new uploads can be accepted
 * - Provide user-friendly formatting helpers for displaying storage values
 *
 * Design notes:
 * - Configuration is cached statically per request to avoid repeated disk reads.
 * - upload_max_storage is normalized once (on first config load) and then reused.
 */
class StorageHelper
{
    /**
     * Cached config for controller usage.
     *
     * Cached statically so configuration is loaded once per request and reused
     * across helper calls without repeated filesystem reads.
     *
     * @var array
     */
    private static array $config;

    /**
     * Load and cache config once per request.
     *
     * Also normalizes the configured max storage value into bytes so all internal
     * calculations operate on a consistent unit.
     *
     * @return array
     */
    private static function getConfig(): array
    {
        if (empty(self::$config))
        {
            self::$config = require __DIR__ . '/../config/config.php';

            // Normalize max storage from shorthand notation (e.g., "100GB") into bytes
            if (isset(self::$config['gallery']['upload_max_storage']))
            {
                self::$config['gallery']['upload_max_storage'] = self::parseSize(self::$config['gallery']['upload_max_storage']);
            }
        }

        return self::$config;
    }

    /**
     * Convert shorthand size notation (e.g., "100GB", "500MB") to bytes.
     *
     * Accepts either:
     * - An integer/float-like numeric value (assumed to already be bytes)
     * - A string with a supported suffix unit: KB, MB, GB, TB, PB (case-insensitive)
     *
     * Notes:
     * - Uses binary units (1 KB = 1024 bytes).
     * - If no recognized unit is provided, the numeric portion is treated as bytes.
     *
     * @param string|int $value
     * @return int
     */
    private static function parseSize($value): int
    {
        if (is_numeric($value))
        {
            return (int)$value;
        }

        $value = trim($value);
        $unit = strtolower(substr($value, -2));
        $number = (float)preg_replace('/[^0-9\.]/', '', $value);

        switch ($unit)
        {
            case 'kb':
                return (int)($number * 1024);

            case 'mb':
                return (int)($number * 1024 * 1024);

            case 'gb':
                return (int)($number * 1024 * 1024 * 1024);

            case 'tb':
                return (int)($number * 1024 * 1024 * 1024 * 1024);

            case 'pb':
                return (int)($number * 1024 * 1024 * 1024 * 1024 * 1024);

            default:
                return (int)$number; // assume raw bytes if no unit
        }
    }

    /**
     * Get total used storage in bytes from database.
     *
     * Uses SUM(size_bytes) across all image records to represent the application's
     * current storage footprint. Returns 0 if no images exist or the query yields null.
     *
     * @return int Total used storage in bytes.
     */
    public static function getUsedStorage(): int
    {
        $sql = "SELECT SUM(size_bytes) AS total_used FROM app_images";
        $result = Database::fetch($sql);

        return (int)($result['total_used'] ?? 0);
    }

    /**
     * Get remaining storage in bytes.
     *
     * Computes: max_configured_storage - total_used_storage
     *
     * @return int Remaining storage in bytes (may be negative if usage exceeds limit).
     */
    public static function getRemainingStorage(): int
    {
        $config = self::getConfig();
        $maxStorage = $config['gallery']['upload_max_storage'];

        return $maxStorage - self::getUsedStorage();
    }

    /**
     * Check if a file can be stored within the configured limit.
     *
     * This is a simple capacity check and should be used before accepting uploads
     * to avoid exceeding the configured storage quota.
     *
     * @param int $fileSize File size in bytes.
     * @return bool True if there is enough remaining space, otherwise false.
     */
    public static function canStoreFile(int $fileSize): bool
    {
        return $fileSize <= self::getRemainingStorage();
    }

    /**
     * Format file size to human readable form.
     *
     * Converts a byte count into a friendly string using binary units:
     * b, kb, mb, gb, tb, pb.
     *
     * Notes:
     * - Values below 0 are clamped to "0 b" to avoid confusing output.
     * - Uses a factor derived from the number of digits as a fast heuristic.
     *
     * @param int $bytes
     * @param int $decimals
     * @return string
     */
    public static function formatFileSize(int $bytes, int $decimals = 2): string
    {
        if ($bytes < 0)
        {
            return '0 b';
        }

        $sizes = ['b', 'kb', 'mb', 'gb', 'tb', 'pb'];
        $factor = floor((strlen((string) $bytes) - 1) / 3);

        return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $factor), $sizes[$factor]);
    }

    /**
     * Get human readable used storage.
     *
     * Convenience wrapper around getUsedStorage() + formatFileSize().
     *
     * @return string Used storage formatted for UI display.
     */
    public static function getUsedStorageReadable(): string
    {
        return self::formatFileSize(self::getUsedStorage());
    }

    /**
     * Get human readable remaining storage.
     *
     * Convenience wrapper around getRemainingStorage() + formatFileSize().
     *
     * @return string Remaining storage formatted for UI display.
     */
    public static function getRemainingStorageReadable(): string
    {
        return self::formatFileSize(self::getRemainingStorage());
    }

    /**
     * Get human readable max storage limit.
     *
     * Reads the configured max storage (already normalized to bytes) and formats it
     * for UI display.
     *
     * @return string Maximum storage limit formatted for UI display.
     */
    public static function getMaxStorageReadable(): string
    {
        $config = self::getConfig();
        return self::formatFileSize($config['gallery']['upload_max_storage']);
    }

    /**
     * Get storage usage percentage (0–100).
     *
     * Computes the fraction of used storage against the configured maximum and formats
     * the result as a percentage string with a configurable number of decimals.
     *
     * Edge cases:
     * - If max storage is 0 or missing, returns "0%" to avoid division by zero.
     *
     * @param int $decimals Number of decimal places to include.
     * @return string Percentage string (e.g., "42.50%").
     */
    public static function getStorageUsagePercent(int $decimals = 2): string
    {
        $config = self::getConfig();
        $used = self::getUsedStorage();
        $max = $config['gallery']['upload_max_storage'];

        if ($max <= 0)
        {
            return '0%';
        }

        $percent = ($used / $max) * 100;
        return number_format($percent, $decimals) . '%';
    }
}
