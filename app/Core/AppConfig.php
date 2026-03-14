<?php

/**
 * AppConfig
 *
 * Small shared configuration accessor used by controllers, helpers, and core
 * services that only need the already-merged runtime configuration.
 *
 * Responsibilities:
 * - Prefer SettingsManager merged config when initialized
 * - Fall back safely to config/config.php during early bootstrap paths
 * - Cache the resolved configuration per request to avoid repeated disk reads
 */
class AppConfig
{
    /**
     * Cached configuration for the current request.
     *
     * @var array|null
     */
    private static ?array $config = null;

    /**
     * Get application configuration.
     *
     * @return array
     */
    public static function get(): array
    {
        if (self::$config !== null)
        {
            return self::$config;
        }

        if (class_exists('SettingsManager') && SettingsManager::isInitialized())
        {
            self::$config = SettingsManager::getConfig();
        }
        else
        {
            $config = require CONFIG_PATH . '/config.php';
            self::$config = is_array($config) ? $config : [];
        }

        return self::$config;
    }
}
