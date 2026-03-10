<?php

/**
 * Background maintenance server for the Image Gallery.
 *
 * Run from the command line to perform near-real-time housekeeping tasks:
 * - Remove expired application sessions from the database
 * - Remove expired request-guard counters and temporary blocks
 * - Remove old security logs based on retention days
 * - Remove expired image cache files from cache/images
 * - Keep the required maintenance heartbeat alive for the public site
 * - Accept secure local runtime control commands without restarting the process
 *
 * Usage examples:
 *   php server.php
 *   php server.php --interval=1
 *   php server.php --once
 *   php server.php --log-retention-days=30
 */

if (PHP_SAPI !== 'cli')
{
    fwrite(STDERR, "This script must be run from the command line.");
    exit(1);
}

$rootDir = __DIR__;
$configPath = $rootDir . '/config/config.php';
if (!is_file($configPath))
{
    fwrite(STDERR, "Missing configuration file: {$configPath}");
    exit(1);
}

$config = require $configPath;

spl_autoload_register(function (string $class) use ($rootDir): void
{
    $paths = [
        $rootDir . '/core/' . $class . '.php',
        $rootDir . '/controllers/' . $class . '.php',
        $rootDir . '/helpers/' . $class . '.php',
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

DateHelper::init($config['timezone'] ?? 'UTC');
Database::init($config['db']);
SettingsManager::init($config);
$config = SettingsManager::getConfig();
Security::init($config['security'] ?? []);

$interval = 1;
$runOnce = false;
$logRetentionDays = (int)($config['security']['log_retention_days'] ?? 30);
foreach (array_slice($argv, 1) as $arg)
{
    if ($arg === '--once')
    {
        $runOnce = true;
        continue;
    }

    if (str_starts_with($arg, '--interval='))
    {
        $value = (int)substr($arg, strlen('--interval='));
        if ($value > 0)
        {
            $interval = $value;
        }

        continue;
    }

    if (str_starts_with($arg, '--log-retention-days='))
    {
        $value = (int)substr($arg, strlen('--log-retention-days='));
        if ($value > 0)
        {
            $logRetentionDays = $value;
        }
    }
}

$state = MaintenanceServer::loadRuntimeState($config);
$state['tick_interval_seconds'] = max(1, (int)($state['tick_interval_seconds'] ?? $interval));
$state['log_retention_days'] = max(1, (int)($state['log_retention_days'] ?? $logRetentionDays));
MaintenanceServer::saveRuntimeState($config, $state);
MaintenanceServer::writeHeartbeat($config, $state);

$controlServer = MaintenanceServer::openControlSocket($config);
if (MaintenanceServer::controlEnabled($config) && $controlServer === false)
{
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . "] Failed to bind maintenance control socket.\n");
}

$running = true;
if (function_exists('pcntl_async_signals'))
{
    pcntl_async_signals(true);

    $stop = function (int $signal) use (&$running, $config): void
    {
        $running = false;
        $timestamp = gmdate('Y-m-d H:i:s');
        MaintenanceServer::clearHeartbeat($config);
        fwrite(STDOUT, "[{$timestamp}] Received signal {$signal}; shutting down.\n");
    };

    pcntl_signal(SIGINT, $stop);
    pcntl_signal(SIGTERM, $stop);
}

/**
 * Execute one housekeeping pass.
 *
 * @param array $state Runtime state
 * @return array{
 *     sessions_removed:int,
 *     rate_counters_removed:int,
 *     blocks_removed:int,
 *     logs_removed:int,
 *     cache_removed:int,
 *     total_removed:int
 *
 */
function server_run_maintenance_pass(array $state): array
{
    $sessionsRemoved = 0;
    $rateCountersRemoved = 0;
    $blocksRemoved = 0;
    $logsRemoved = 0;
    $deletedCacheFiles = 0;

    if (!empty($state['jobs']['sessions']))
    {
        $sessionsRemoved = SessionManager::cleanExpired();
    }

    if (!empty($state['jobs']['request_guard']))
    {
        $guardResult = RequestGuard::cleanExpired();
        $rateCountersRemoved = (int)($guardResult['counters'] ?? 0);
        $blocksRemoved = (int)($guardResult['blocks'] ?? 0);
    }

    if (!empty($state['jobs']['security_logs']))
    {
        $logsRemoved = Security::cleanExpired((int)($state['log_retention_days'] ?? 30));
    }

    if (!empty($state['jobs']['image_cache']))
    {
        $deletedCacheFiles = ImageCacheEngine::cleanExpired();
    }

    $totalRemoved = $sessionsRemoved + $rateCountersRemoved + $blocksRemoved + $logsRemoved + $deletedCacheFiles;

    return [
        'sessions_removed' => $sessionsRemoved,
        'rate_counters_removed' => $rateCountersRemoved,
        'blocks_removed' => $blocksRemoved,
        'logs_removed' => $logsRemoved,
        'cache_removed' => $deletedCacheFiles,
        'total_removed' => $totalRemoved,
    ];
}

fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . "] Maintenance server started (interval: {$state['tick_interval_seconds']}s, log retention: {$state['log_retention_days']} days).\n");

do
{
    try
    {
        $responses = MaintenanceServer::processControlCommands($controlServer, $config, $state);

        /*
         * Write the heartbeat immediately after processing control commands so
         * maintenance-mode toggles and other runtime state changes are visible
         * to the public site without waiting for the next loop tick.
         */
        if (!empty($responses))
        {
            MaintenanceServer::writeHeartbeat($config, $state);
        }

        if (!empty($responses) && !empty($state['verbose_logging']))
        {
            foreach ($responses as $response)
            {
                fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . '] Control command processed: ' . (string)($response['message'] ?? 'OK') . "\n");
            }
        }

        /*
         * Keep the heartbeat fresh every loop, even when no commands were
         * received, so the site can reliably detect that the backend process is
         * still alive.
         */
        MaintenanceServer::writeHeartbeat($config, $state);

        $shouldRunPass = !$state['paused'] || !empty($state['run_cleanup_now']);
        if ($shouldRunPass)
        {
            $result = server_run_maintenance_pass($state);

            if ($result['total_removed'] > 0)
            {
                fwrite(
                    STDOUT,
                    '[' . gmdate('Y-m-d H:i:s') . '] Cleanup pass completed; sessions removed: ' . $result['sessions_removed'] .
                    ', rate counters removed: ' . $result['rate_counters_removed'] .
                    ', temporary blocks removed: ' . $result['blocks_removed'] .
                    ', security logs removed: ' . $result['logs_removed'] .
                    ', expired cache files removed: ' . $result['cache_removed'] . "\n"
                );
            }

            if (!empty($state['run_cleanup_now']))
            {
                $state['run_cleanup_now'] = false;
                MaintenanceServer::saveRuntimeState($config, $state);
                MaintenanceServer::writeHeartbeat($config, $state);
            }
        }
    }
    catch (Throwable $e)
    {
        fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . '] Maintenance pass failed: ' . $e->getMessage() . "\n");
    }

    if ($runOnce || !$running)
    {
        break;
    }

    $sleepSeconds = max(1, (int)($state['tick_interval_seconds'] ?? 1));
    sleep($sleepSeconds);
} while ($running);

if (is_resource($controlServer))
{
    fclose($controlServer);
}

MaintenanceServer::clearHeartbeat($config);
fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . "] Maintenance server stopped.\n");
