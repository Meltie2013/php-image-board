<?php

class StorageHelper
{
    /**
     * Cached config for controller usage.
     *
     * @var array
     */
    private static array $config;

    /**
     * Load and cache config once per request.
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
                self::$config['gallery']['upload_max_storage'] =
                    self::parseSize(self::$config['gallery']['upload_max_storage']);
            }
        }

        return self::$config;
    }

    /**
     * Convert shorthand size notation (e.g., "100GB", "500MB") to bytes.
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
     */
    public static function getUsedStorage(): int
    {
        $sql = "SELECT SUM(size_bytes) AS total_used FROM app_images";
        $result = Database::fetch($sql);

        return (int)($result['total_used'] ?? 0);
    }

    /**
     * Get remaining storage in bytes.
     */
    public static function getRemainingStorage(): int
    {
        $config = self::getConfig();
        $maxStorage = $config['gallery']['upload_max_storage'];
        return $maxStorage - self::getUsedStorage();
    }

    /**
     * Check if a file can be stored within the limit.
     */
    public static function canStoreFile(int $fileSize): bool
    {
        return $fileSize <= self::getRemainingStorage();
    }

    /**
     * Format file size to human readable form.
     *
     * @param int $bytes
     * @param int $decimals
     * @return string
     */
    public static function formatFileSize(int $bytes, int $decimals = 2): string
    {
        if ($bytes < 0)
        {
            return '0 B';
        }

        $sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $factor = floor((strlen((string) $bytes) - 1) / 3);

        return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $factor), $sizes[$factor]);
    }

    /**
     * Get human readable used storage.
     */
    public static function getUsedStorageReadable(): string
    {
        return self::formatFileSize(self::getUsedStorage());
    }

    /**
     * Get human readable remaining storage.
     */
    public static function getRemainingStorageReadable(): string
    {
        return self::formatFileSize(self::getRemainingStorage());
    }

    /**
     * Get human readable max storage limit.
     */
    public static function getMaxStorageReadable(): string
    {
        $config = self::getConfig();
        return self::formatFileSize($config['gallery']['upload_max_storage']);
    }

    /**
     * Get storage usage percentage (0–100).
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
