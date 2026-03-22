<?php

/**
 * Project bootstrap for filesystem paths and autoloading.
 *
 * This file centralizes directory constants so the application can be moved
 * around more safely without repeating fragile relative paths throughout the
 * codebase.
 */

if (!defined('APP_ROOT'))
{
    define('APP_ROOT', dirname(__DIR__));
    define('APP_PATH', APP_ROOT . '/app');
    define('APP_CONTROLLER_PATH', APP_PATH . '/Controllers');
    define('APP_CORE_PATH', APP_PATH . '/Core');
    define('APP_HELPER_PATH', APP_PATH . '/Helpers');
    define('APP_MODEL_PATH', APP_PATH . '/Models');

    define('BOOTSTRAP_PATH', APP_ROOT . '/bootstrap');
    define('CONFIG_PATH', APP_ROOT . '/config');
    define('RESOURCE_PATH', APP_ROOT . '/resources');
    define('TEMPLATE_PATH', RESOURCE_PATH . '/templates');
    define('STORAGE_PATH', APP_ROOT . '/storage');
    define('CACHE_PATH', STORAGE_PATH . '/cache');
    define('CACHE_IMAGE_PATH', CACHE_PATH . '/images');
    define('CACHE_TEMPLATE_PATH', CACHE_PATH . '/templates');
    define('LOG_PATH', STORAGE_PATH . '/logs');
    define('DATABASE_PATH', APP_ROOT . '/database');

    define('ASSET_PATH', APP_ROOT . '/assets');
    define('IMAGE_PATH', APP_ROOT . '/images');
    define('UPLOAD_PATH', APP_ROOT . '/uploads');
    define('JSON_PATH', APP_ROOT . '/json');
}

spl_autoload_register(function (string $class): void
{
    $paths = [
        APP_CORE_PATH . '/' . $class . '.php',
        APP_CONTROLLER_PATH . '/' . $class . '.php',
        APP_HELPER_PATH . '/' . $class . '.php',
        APP_MODEL_PATH . '/' . $class . '.php',
    ];

    foreach ($paths as $file)
    {
        if (is_file($file))
        {
            require $file;
            return;
        }
    }
});
