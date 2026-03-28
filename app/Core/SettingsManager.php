<?php

/**
 * SettingsManager
 *
 * Centralized application settings loader with safe database overrides.
 *
 * Responsibilities:
 * - Keep boot/runtime configuration (DB credentials, security keys, request guard, control server, etc.) in config.php
 * - Load application-facing board settings from the database-backed settings registry
 * - Provide typed getters and a merged config array for backwards compatibility
 *
 * Security considerations:
 * - Sensitive runtime sections remain on disk and are not replaced by database values
 * - Database-backed settings still use normalized dot-notation keys
 * - Fail-closed: if settings tables are missing or an error occurs, registry defaults are used
 *
 * Notes:
 * - Call SettingsManager::init($config) once during bootstrap AFTER Database::init()
 * - Controllers should use SettingsManager::getConfig() instead of re-requiring config.php
 * - Managed settings sections in config.php are ignored so database values remain authoritative
 */
class SettingsManager
{
    /**
     * Base configuration loaded from config.php (source of truth for security settings).
     *
     * @var array
     */
    private static array $baseConfig = [];

    /**
     * Merged configuration (base config + approved DB overrides).
     *
     * @var array|null
     */
    private static ?array $mergedConfig = null;

    /**
     * Loaded settings from the database (normalized key => parsed value).
     *
     * @var array
     */
    private static array $settings = [];

    /**
     * Whitelisted top-level config sections that may be overridden via database settings.
     *
     * Example:
     * - 'site' allows 'site.name', 'site.version', etc.
     * - 'template' allows 'template.disable_cache', 'template.allowed_functions', etc.
     *
     * @var array
     */
    private static array $overrideAllowList = [
        'site',
        'template',
        'gallery',
        'profile',
        'debugging',
        'upload',
        'rules',
        'blog'
    ];

    /**
     * Initialize SettingsManager with base config.
     *
     * @param array $config Base config loaded from config.php
     * @return void
     */
    public static function init(array $config): void
    {
        $runtimeDefaults = self::loadDefaultConfig();
        $registryDefaults = class_exists('SettingsRegistry') ? SettingsRegistry::getFallbackConfig() : [];
        $config = self::stripManagedSections($config);

        self::$baseConfig = self::deepMerge($runtimeDefaults, $config);
        self::$baseConfig = self::deepMerge(self::$baseConfig, $registryDefaults);

        if (!empty(self::$baseConfig['settings_manager']['override_allow_list']) && is_array(self::$baseConfig['settings_manager']['override_allow_list']))
        {
            self::$overrideAllowList = array_values(array_unique(array_filter(self::$baseConfig['settings_manager']['override_allow_list'], 'is_string')));
        }

        self::$settings = self::loadFromDatabase();
        self::$mergedConfig = self::applyOverrides(self::$baseConfig, self::$settings);
    }

    /**
     * Load default config tree from config.php.dist.
     *
     * This ensures moved-to-DB sections (gallery/debugging/upload/template/profile)
     * still have sane defaults even if config.php no longer contains them.
     *
     * @return array
     */
    private static function loadDefaultConfig(): array
    {
        $distPath = CONFIG_PATH . '/config.php.dist';

        if (file_exists($distPath))
        {
            $defaults = require $distPath;
            if (is_array($defaults))
            {
                return self::stripManagedSections($defaults);
            }
        }

        return [];
    }

    /**
     * Recursively merge two arrays.
     *
     * - Scalar values in $override replace values in $base.
     * - Nested arrays are merged recursively.
     *
     * @param array $base
     * @param array $override
     * @return array
     */
    private static function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value)
        {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]))
            {
                $base[$key] = self::deepMerge($base[$key], $value);
            }
            else
            {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Remove database-managed settings sections from a config array.
     *
     * @param array $config
     * @return array
     */
    private static function stripManagedSections(array $config): array
    {
        foreach (self::$overrideAllowList as $section)
        {
            if (array_key_exists($section, $config))
            {
                unset($config[$section]);
            }
        }

        return $config;
    }

    /**
     * Returns true if SettingsManager has been initialized.
     *
     * @return bool
     */
    public static function isInitialized(): bool
    {
        return self::$mergedConfig !== null;
    }

    /**
     * Get merged config (base config + DB overrides).
     *
     * @return array
     */
    public static function getConfig(): array
    {
        if (self::$mergedConfig === null)
        {
            // Fail-closed fallback: return base config if init() wasn't called
            return self::$baseConfig;
        }

        return self::$mergedConfig;
    }

    /**
     * Get a raw setting by key (dot-notation supported).
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $key = self::normalizeKey($key);
        if ($key === '')
        {
            return $default;
        }

        if (array_key_exists($key, self::$settings))
        {
            return self::$settings[$key];
        }

        // Fallback to merged config (supports nested lookups)
        return self::getFromArray(self::getConfig(), $key, $default);
    }

    /**
     * Get a string setting.
     *
     * @param string $key
     * @param string $default
     * @param int $maxLen
     * @return string
     */
    public static function getString(string $key, string $default = '', int $maxLen = 2048): string
    {
        $value = self::get($key, $default);

        if (is_string($value))
        {
            $value = trim($value);
            if ($maxLen > 0 && strlen($value) > $maxLen)
            {
                $value = substr($value, 0, $maxLen);
            }

            return $value;
        }

        if (is_numeric($value) || is_bool($value))
        {
            return (string)$value;
        }

        return $default;
    }

    /**
     * Get an integer setting.
     *
     * @param string $key
     * @param int $default
     * @param int|null $min
     * @param int|null $max
     * @return int
     */
    public static function getInt(string $key, int $default = 0, ?int $min = null, ?int $max = null): int
    {
        $value = self::get($key, $default);

        if (is_int($value))
        {
            $intVal = $value;
        }
        elseif (is_numeric($value))
        {
            $intVal = (int)$value;
        }
        else
        {
            $intVal = $default;
        }

        if ($min !== null && $intVal < $min)
        {
            $intVal = $min;
        }

        if ($max !== null && $intVal > $max)
        {
            $intVal = $max;
        }

        return $intVal;
    }

    /**
     * Get a boolean setting.
     *
     * Accepts: true/false, 1/0, "1"/"0", "true"/"false", "yes"/"no", "on"/"off".
     *
     * @param string $key
     * @param bool $default
     * @return bool
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        if (is_bool($value))
        {
            return $value;
        }

        if (is_int($value))
        {
            return $value === 1;
        }

        if (is_string($value))
        {
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 'yes', 'on'], true))
            {
                return true;
            }

            if (in_array($v, ['0', 'false', 'no', 'off'], true))
            {
                return false;
            }
        }

        return (bool)$default;
    }

    /**
     * Get an array setting (supports JSON stored values).
     *
     * @param string $key
     * @param array $default
     * @return array
     */
    public static function getArray(string $key, array $default = []): array
    {
        $value = self::get($key, $default);

        if (is_array($value))
        {
            return $value;
        }

        if (is_string($value))
        {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
            {
                return $decoded;
            }
        }

        return $default;
    }

    /**
     * Load settings from the database.
     *
     * Table expected: app_settings_data (key/value/type).
     *
     * @return array
     */
    private static function loadFromDatabase(): array
    {
        try
        {
            $exists = Database::fetch("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'app_settings_data' LIMIT 1");
            if (empty($exists))
            {
                return [];
            }

            $rows = Database::fetchAll("SELECT `key`, `value`, `type` FROM app_settings_data");

            $out = [];
            foreach ($rows as $row)
            {
                $rawKey = isset($row['key']) ? (string) $row['key'] : '';
                $key = self::normalizeKey($rawKey);

                if ($key === '')
                {
                    continue;
                }

                $type = isset($row['type']) ? strtolower(trim((string) $row['type'])) : 'string';
                $value = $row['value'] ?? '';
                $out[$key] = self::parseValueByType($value, $type);
            }

            return $out;
        }
        catch (Throwable $e)
        {
            return [];
        }
    }

    /**
     * Parse a stored value using the provided type.
     *
     * @param mixed $value
     * @param string $type
     * @return mixed
     */
    private static function parseValueByType($value, string $type)
    {
        switch ($type)
        {
            case 'int':
            case 'integer':
                return is_numeric($value) ? (int)$value : 0;

            case 'bool':
            case 'boolean':
                $v = strtolower(trim((string)$value));
                return in_array($v, ['1', 'true', 'yes', 'on'], true);

            case 'float':
            case 'double':
                return is_numeric($value) ? (float)$value : 0.0;

            case 'json':
            case 'array':
                $decoded = json_decode((string)$value, true);
                if (json_last_error() === JSON_ERROR_NONE)
                {
                    return $decoded;
                }
                return [];

            case 'string':
            default:
                return (string)$value;
        }
    }

    /**
     * Apply dot-notation settings to the base config array.
     *
     * @param array $base
     * @param array $settings
     * @return array
     */
    private static function applyOverrides(array $base, array $settings): array
    {
        foreach ($settings as $key => $value)
        {
            $key = self::normalizeKey((string) $key);
            if ($key === '')
            {
                continue;
            }

            if (!self::isOverrideAllowed($key))
            {
                continue;
            }

            $base = self::setInArray($base, $key, $value);
        }

        return $base;
    }

    /**
     * Check if the top-level segment of a dot-notation key is allowed to override.
     *
     * @param string $key
     * @return bool
     */
    private static function isOverrideAllowed(string $key): bool
    {
        $parts = explode('.', $key);
        $top = $parts[0] ?? '';

        return $top !== '' && in_array($top, self::$overrideAllowList, true);
    }

    /**
     * Normalize a settings key to a safe dot-notation format.
     *
     * Allowed characters: a-z A-Z 0-9 _ . -
     *
     * @param string $key
     * @return string
     */
    private static function normalizeKey(string $key): string
    {
        $key = trim($key);
        if ($key === '')
        {
            return '';
        }

        // Convert common separators to dot-notation
        $key = str_replace(['/', '\\', ':'], '.', $key);

        // Strip unsupported chars
        $key = preg_replace('/[^a-zA-Z0-9_.-]/', '', $key);

        // Collapse repeated dots
        $key = preg_replace('/\.+/', '.', $key);

        // Trim leading/trailing dots
        $key = trim($key, '.');

        // Limit length to avoid abuse
        if (strlen($key) > 128)
        {
            $key = substr($key, 0, 128);
        }

        return $key;
    }

    /**
     * Get a nested value from an array using dot-notation.
     *
     * @param array $array
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private static function getFromArray(array $array, string $key, $default = null)
    {
        $parts = explode('.', $key);
        $cur = $array;

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

    /**
     * Set a nested value in an array using dot-notation.
     *
     * @param array $array
     * @param string $key
     * @param mixed $value
     * @return array
     */
    private static function setInArray(array $array, string $key, $value): array
    {
        $parts = explode('.', $key);
        $ref = &$array;

        foreach ($parts as $idx => $p)
        {
            if ($p === '')
                continue;

            if ($idx === count($parts) - 1)
            {
                $ref[$p] = $value;
                break;
            }

            if (!isset($ref[$p]) || !is_array($ref[$p]))
            {
                $ref[$p] = [];
            }

            $ref = &$ref[$p];
        }

        return $array;
    }
}
