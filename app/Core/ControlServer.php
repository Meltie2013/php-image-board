<?php

/**
 * Background Control Server heartbeat and runtime control helper.
 *
 * Provides a bridge between the public site and server.php so the frontend can
 * detect whether the required background process is alive, while also allowing
 * the long-running Control Server to be controlled securely at runtime.
 */
class ControlServer
{
    /**
     * Default relative path used to store the live event tick file.
     */
    private const DEFAULT_LIVE_EVENTS_FILE = 'json/control_server_events.json';

    /**
     * Return the active Control Server configuration block.
     *
     * New installs should use control_server. The legacy maintenance_server
     * key is still accepted so existing deployments do not break.
     *
     * @param array $config Application configuration
     * @return array<string, mixed>
     */
    private static function configBlock(array $config): array
    {
        $block = $config['control_server'] ?? ($config['maintenance_server'] ?? []);
        return is_array($block) ? $block : [];
    }

    /**
     * Connected control clients keyed by stream ID.
     *
     * @var array<int, resource>
     */
    private static array $controlClients = [];

    /**
     * Per-client interactive session state.
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $controlClientStates = [];

    /**
     * Connected WebSocket clients keyed by stream ID.
     *
     * @var array<int, resource>
     */
    private static array $webSocketClients = [];

    /**
     * Browser WebSocket image subscriptions keyed by image hash, then client ID.
     *
     * @var array<string, array<int, bool>>
     */
    private static array $webSocketImageSubscriptions = [];

    /**
     * Per-WebSocket client state.
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $webSocketClientStates = [];

    /**
     * Last broadcast image ticks keyed by image hash.
     *
     * @var array<string, int>
     */
    private static array $lastBroadcastImageTicks = [];

    /**
     * Default relative path used to store the heartbeat file.
     */
    private const DEFAULT_HEARTBEAT_FILE = 'json/control_server_heartbeat.json';

    /**
     * Default relative path used to store the runtime state file.
     */
    private const DEFAULT_STATE_FILE = 'json/control_server_state.json';

    /**
     * Determine the absolute heartbeat file path.
     *
     * @param array $config Application configuration
     * @return string Absolute heartbeat file path
     */
    public static function heartbeatPath(array $config): string
    {
        $relativePath = TypeHelper::toString(self::configBlock($config)['heartbeat_file'] ?? '', allowEmpty: true)
            ?? self::DEFAULT_HEARTBEAT_FILE;

        if ($relativePath === '')
        {
            $relativePath = self::DEFAULT_HEARTBEAT_FILE;
        }

        return APP_ROOT . '/' . ltrim($relativePath, '/');
    }

    /**
     * Determine the absolute runtime state file path.
     *
     * @param array $config Application configuration
     * @return string Absolute runtime state file path
     */
    public static function statePath(array $config): string
    {
        $relativePath = TypeHelper::toString(self::configBlock($config)['state_file'] ?? '', allowEmpty: true)
            ?? self::DEFAULT_STATE_FILE;

        if ($relativePath === '')
        {
            $relativePath = self::DEFAULT_STATE_FILE;
        }

        return APP_ROOT . '/' . ltrim($relativePath, '/');
    }

    /**
     * Determine how long a heartbeat remains valid.
     *
     * @param array $config Application configuration
     * @return int Heartbeat timeout in seconds
     */
    public static function heartbeatTimeout(array $config): int
    {
        $timeout = TypeHelper::toInt(self::configBlock($config)['heartbeat_timeout_seconds'] ?? null) ?? 5;
        return max(1, $timeout);
    }

    /**
     * Determine whether the public site requires the Control Server.
     *
     * @param array $config Application configuration
     * @return bool True when the site should enter maintenance mode without the server
     */
    public static function isRequired(array $config): bool
    {
        return !empty(self::configBlock($config)['required']);
    }

    /**
     * Determine whether the local control socket is enabled.
     *
     * @param array $config Application configuration
     * @return bool True when runtime control is enabled
     */
    public static function controlEnabled(array $config): bool
    {
        return !empty(self::configBlock($config)['control']['enabled']);
    }

    /**
     * Determine the control server bind address.
     *
     * @param array $config Application configuration
     * @return string Bind address
     */
    public static function controlBindAddress(array $config): string
    {
        $address = TypeHelper::toString(self::configBlock($config)['control']['bind_address'] ?? '127.0.0.1', allowEmpty: true) ?? '127.0.0.1';
        return $address !== '' ? $address : '127.0.0.1';
    }

    /**
     * Determine the control server port.
     *
     * @param array $config Application configuration
     * @return int Port number
     */
    public static function controlPort(array $config): int
    {
        $port = TypeHelper::toInt(self::configBlock($config)['control']['port'] ?? null) ?? 37991;
        return max(1, min(65535, $port));
    }


    /**
     * Determine whether the WebSocket server is enabled.
     *
     * @param array $config Application configuration
     * @return bool True when WebSocket support is enabled
     */
    public static function webSocketEnabled(array $config): bool
    {
        $webSocket = self::configBlock($config)['websocket'] ?? [];
        if (!is_array($webSocket))
        {
            return false;
        }

        return !array_key_exists('enabled', $webSocket) || !empty($webSocket['enabled']);
    }

    /**
     * Determine the WebSocket bind address.
     *
     * @param array $config Application configuration
     * @return string Bind address
     */
    public static function webSocketBindAddress(array $config): string
    {
        $webSocket = self::configBlock($config)['websocket'] ?? [];
        $defaultAddress = !empty($webSocket['allow_remote_clients']) ? '0.0.0.0' : '127.0.0.1';
        $address = TypeHelper::toString($webSocket['bind_address'] ?? $defaultAddress, allowEmpty: true) ?? $defaultAddress;
        return $address !== '' ? $address : $defaultAddress;
    }

    /**
     * Determine the WebSocket server port.
     *
     * @param array $config Application configuration
     * @return int Port number
     */
    public static function webSocketPort(array $config): int
    {
        $webSocket = self::configBlock($config)['websocket'] ?? [];
        $defaultPort = self::controlPort($config) + 1;
        $port = TypeHelper::toInt($webSocket['port'] ?? null) ?? $defaultPort;
        return max(1, min(65535, $port));
    }

    /**
     * Determine whether remote browser WebSocket clients are allowed.
     *
     * @param array $config Application configuration
     * @return bool True when remote clients are allowed
     */
    public static function webSocketAllowRemoteClients(array $config): bool
    {
        return !empty(self::configBlock($config)['websocket']['allow_remote_clients']);
    }

    /**
     * Return the list of allowed remote IPs for the control socket.
     *
     * @param array $config Application configuration
     * @return array<int, string> Allowed IP addresses
     */
    public static function controlAllowedIps(array $config): array
    {
        $allowed = self::configBlock($config)['control']['allowed_ips'] ?? ['127.0.0.1', '::1'];
        if (!is_array($allowed))
        {
            return ['127.0.0.1', '::1'];
        }

        $normalized = [];
        foreach ($allowed as $ip)
        {
            if (!is_string($ip))
            {
                continue;
            }

            $value = trim($ip);
            if ($value !== '')
            {
                $normalized[] = $value;
            }
        }

        if (empty($normalized))
        {
            $normalized = ['127.0.0.1', '::1'];
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Return the configured control authorization token.
     *
     * @param array $config Application configuration
     * @return string Control authorization token
     */
    public static function controlAuthToken(array $config): string
    {
        return TypeHelper::toString(self::configBlock($config)['control']['auth_token'] ?? '', allowEmpty: true) ?? '';
    }

    /**
     * Determine whether the configured control token is valid.
     *
     * Local-only control allows any non-empty token. Public-facing control must
     * use a uniquely generated token no longer than 64 characters.
     *
     * @param string $token Control authorization token
     * @param bool $remoteAccessible Whether remote control is enabled
     * @return bool True when the token is acceptable
     */
    public static function isValidControlToken(string $token, bool $remoteAccessible = false): bool
    {
        $token = TypeHelper::toString($token, allowEmpty: true) ?? '';
        if ($token === '' || $token === 'change-this-to-a-long-random-token')
        {
            return false;
        }

        $length = strlen($token);
        if ($remoteAccessible)
        {
            return $length >= 32
                && $length <= 64
                && preg_match('/^[A-Za-z0-9._~\-]+$/', $token) === 1;
        }

        return $length <= 64;
    }

    /**
     * Generate a cryptographically secure control token.
     *
     * @param int $length Desired output length, capped at 64 characters
     * @return string Random token
     */
    public static function generateControlToken(int $length = 64): string
    {
        $length = max(16, min(64, $length));
        $bytesNeeded = (int)ceil($length / 2);
        return substr(bin2hex(random_bytes($bytesNeeded)), 0, $length);
    }

    /**
     * Build the default runtime state for the Control Server.
     *
     * @param array $config Application configuration
     * @return array<string, mixed>
     */
    public static function defaultRuntimeState(array $config): array
    {
        $jobs = self::configBlock($config)['jobs'] ?? [];
        if (!is_array($jobs))
        {
            $jobs = [];
        }

        $normalizedJobs = [
            'sessions' => !isset($jobs['sessions']) || !empty($jobs['sessions']),
            'request_guard' => !isset($jobs['request_guard']) || !empty($jobs['request_guard']),
            'security_logs' => !isset($jobs['security_logs']) || !empty($jobs['security_logs']),
            'image_cache' => !isset($jobs['image_cache']) || !empty($jobs['image_cache']),
        ];

        foreach ($jobs as $name => $enabled)
        {
            if (!is_string($name) || $name === '' || isset($normalizedJobs[$name]))
            {
                continue;
            }

            $normalizedJobs[$name] = !empty($enabled);
        }

        return [
            'paused' => false,
            'site_online' => true,
            'maintenance_mode' => false,
            'verbose_logging' => !empty(self::configBlock($config)['verbose_logging']),
            'tick_interval_seconds' => max(1, TypeHelper::toInt(self::configBlock($config)['tick_interval_seconds'] ?? null) ?? 1),
            'log_retention_days' => max(1, TypeHelper::toInt($config['security']['log_retention_days'] ?? null) ?? 30),
            'jobs' => $normalizedJobs,
            'services' => self::defaultServices($config),
            'run_cleanup_now' => false,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * Build the default service state for the public application.
     *
     * @param array $config Application configuration
     * @return array<string, bool> Normalized service map
     */
    public static function defaultServices(array $config): array
    {
        $services = self::configBlock($config)['services'] ?? [];
        if (!is_array($services))
        {
            $services = [];
        }

        $normalizedServices = [
            'site' => !isset($services['site']) || !empty($services['site']),
            'register' => !isset($services['register']) || !empty($services['register']),
            'forums' => !isset($services['forums']) || !empty($services['forums']),
            'live_chat' => !isset($services['live_chat']) || !empty($services['live_chat']),
        ];

        foreach ($services as $name => $enabled)
        {
            if (!is_string($name) || $name === '' || isset($normalizedServices[$name]))
            {
                continue;
            }

            $normalizedServices[$name] = !empty($enabled);
        }

        return $normalizedServices;
    }

    /**
     * Determine whether one named site service is enabled.
     *
     * @param array $config Application configuration
     * @param string $service Service name
     * @param array|null $state Optional runtime state
     * @return bool True when enabled
     */
    public static function serviceEnabled(array $config, string $service, ?array $state = null): bool
    {
        $service = TypeHelper::toString($service, allowEmpty: true) ?? '';
        if ($service === '')
        {
            return false;
        }

        $state = $state ?? self::loadRuntimeState($config);
        $services = $state['services'] ?? self::defaultServices($config);

        return !empty($services[$service]);
    }

    /**
     * Load the persisted runtime state from disk.
     *
     * @param array $config Application configuration
     * @return array<string, mixed>
     */
    public static function loadRuntimeState(array $config): array
    {
        $defaultState = self::defaultRuntimeState($config);
        $path = self::statePath($config);

        if (!is_file($path))
        {
            return $defaultState;
        }

        $contents = @file_get_contents($path);
        if ($contents === false || $contents === '')
        {
            return $defaultState;
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded))
        {
            return $defaultState;
        }

        $state = $defaultState;
        if (array_key_exists('paused', $decoded))
        {
            $state['paused'] = !empty($decoded['paused']);
        }

        if (array_key_exists('site_online', $decoded))
        {
            $state['site_online'] = !empty($decoded['site_online']);
        }

        if (array_key_exists('maintenance_mode', $decoded))
        {
            $state['maintenance_mode'] = !empty($decoded['maintenance_mode']);
        }

        if (array_key_exists('verbose_logging', $decoded))
        {
            $state['verbose_logging'] = !empty($decoded['verbose_logging']);
        }

        if (array_key_exists('tick_interval_seconds', $decoded))
        {
            $state['tick_interval_seconds'] = max(1, TypeHelper::toInt($decoded['tick_interval_seconds'] ?? null) ?? 1);
        }

        if (array_key_exists('log_retention_days', $decoded))
        {
            $state['log_retention_days'] = max(1, TypeHelper::toInt($decoded['log_retention_days'] ?? null) ?? 1);
        }

        if (!empty($decoded['jobs']) && is_array($decoded['jobs']))
        {
            foreach ($decoded['jobs'] as $name => $enabled)
            {
                if (!is_string($name) || $name === '')
                {
                    continue;
                }

                $state['jobs'][$name] = !empty($enabled);
            }
        }

        if (!empty($decoded['services']) && is_array($decoded['services']))
        {
            foreach ($decoded['services'] as $name => $enabled)
            {
                if (!is_string($name) || $name === '')
                {
                    continue;
                }

                $state['services'][$name] = !empty($enabled);
            }
        }

        if (array_key_exists('run_cleanup_now', $decoded))
        {
            $state['run_cleanup_now'] = !empty($decoded['run_cleanup_now']);
        }

        if (!empty($decoded['updated_at']) && is_string($decoded['updated_at']))
        {
            $state['updated_at'] = $decoded['updated_at'];
        }

        return $state;
    }

    /**
     * Persist the current runtime state to disk.
     *
     * @param array $config Application configuration
     * @param array $state Runtime state
     * @return void
     */
    public static function saveRuntimeState(array $config, array $state): void
    {
        $path = self::statePath($config);
        $directory = dirname($path);

        if (!is_dir($directory))
        {
            mkdir($directory, 0755, true);
        }

        $state['updated_at'] = gmdate('Y-m-d H:i:s');
        $payload = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($payload === false)
        {
            return;
        }

        $temporaryPath = $path . '.tmp';
        file_put_contents($temporaryPath, $payload, LOCK_EX);
        rename($temporaryPath, $path);
    }

    /**
     * Determine the absolute live event tick file path.
     *
     * @param array $config Application configuration
     * @return string Absolute live event tick file path
     */
    public static function liveEventsPath(array $config): string
    {
        $relativePath = TypeHelper::toString(self::configBlock($config)['live_events_file'] ?? '', allowEmpty: true)
            ?? self::DEFAULT_LIVE_EVENTS_FILE;

        if ($relativePath === '')
        {
            $relativePath = self::DEFAULT_LIVE_EVENTS_FILE;
        }

        return APP_ROOT . '/' . ltrim($relativePath, '/');
    }

    /**
     * Build the default live event tick state.
     *
     * @param array $state Optional runtime state
     * @return array<string, mixed>
     */
    private static function defaultLiveEventsState(array $state = []): array
    {
        return [
            'status' => 'running',
            'pid' => getmypid(),
            'timestamp' => gmdate('Y-m-d H:i:s'),
            'unix_time' => time(),
            'global_tick' => 0,
            'paused' => !empty($state['paused']),
            'site_online' => !empty($state['site_online']),
            'maintenance_mode' => !empty($state['maintenance_mode']),
            'jobs' => is_array($state['jobs'] ?? null) ? $state['jobs'] : [],
            'services' => is_array($state['services'] ?? null) ? $state['services'] : [],
            'images' => [],
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * Load the persisted live event tick state from disk.
     *
     * @param array $config Application configuration
     * @return array<string, mixed>
     */
    public static function loadLiveEvents(array $config): array
    {
        $defaultEvents = self::defaultLiveEventsState();
        $path = self::liveEventsPath($config);

        if (!is_file($path))
        {
            return $defaultEvents;
        }

        $contents = @file_get_contents($path);
        if ($contents === false || $contents === '')
        {
            return $defaultEvents;
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded))
        {
            return $defaultEvents;
        }

        $events = $defaultEvents;

        if (array_key_exists('status', $decoded))
        {
            $events['status'] = TypeHelper::toString($decoded['status'] ?? '', allowEmpty: true) ?? 'running';
        }

        if (array_key_exists('pid', $decoded))
        {
            $events['pid'] = max(0, TypeHelper::toInt($decoded['pid'] ?? null) ?? 0);
        }

        if (array_key_exists('timestamp', $decoded))
        {
            $events['timestamp'] = TypeHelper::toString($decoded['timestamp'] ?? '', allowEmpty: true) ?? gmdate('Y-m-d H:i:s');
        }

        if (array_key_exists('unix_time', $decoded))
        {
            $events['unix_time'] = max(0, TypeHelper::toInt($decoded['unix_time'] ?? null) ?? time());
        }

        if (array_key_exists('global_tick', $decoded))
        {
            $events['global_tick'] = max(0, TypeHelper::toInt($decoded['global_tick'] ?? null) ?? 0);
        }

        if (array_key_exists('paused', $decoded))
        {
            $events['paused'] = !empty($decoded['paused']);
        }

        if (array_key_exists('site_online', $decoded))
        {
            $events['site_online'] = !empty($decoded['site_online']);
        }

        if (array_key_exists('maintenance_mode', $decoded))
        {
            $events['maintenance_mode'] = !empty($decoded['maintenance_mode']);
        }

        if (!empty($decoded['jobs']) && is_array($decoded['jobs']))
        {
            $events['jobs'] = $decoded['jobs'];
        }

        if (!empty($decoded['services']) && is_array($decoded['services']))
        {
            $events['services'] = $decoded['services'];
        }

        $images = [];
        if (!empty($decoded['images']) && is_array($decoded['images']))
        {
            foreach ($decoded['images'] as $hash => $tick)
            {
                $hash = TypeHelper::toString($hash, allowEmpty: true) ?? '';
                if ($hash === '')
                {
                    continue;
                }

                $images[$hash] = max(0, TypeHelper::toInt($tick) ?? 0);
            }
        }

        $events['images'] = $images;
        $events['updated_at'] = TypeHelper::toString($decoded['updated_at'] ?? '', allowEmpty: true) ?? gmdate('Y-m-d H:i:s');

        return $events;
    }

    /**
     * Persist the current live event tick state to disk.
     *
     * @param array $config Application configuration
     * @param array $events Live event state
     * @return void
     */
    public static function saveLiveEvents(array $config, array $events): void
    {
        $path = self::liveEventsPath($config);
        $directory = dirname($path);

        if (!is_dir($directory))
        {
            mkdir($directory, 0755, true);
        }

        $normalized = self::defaultLiveEventsState();

        $normalized['status'] = TypeHelper::toString($events['status'] ?? 'running', allowEmpty: true) ?? 'running';
        $normalized['pid'] = max(0, TypeHelper::toInt($events['pid'] ?? null) ?? getmypid());
        $normalized['timestamp'] = TypeHelper::toString($events['timestamp'] ?? '', allowEmpty: true) ?? gmdate('Y-m-d H:i:s');
        $normalized['unix_time'] = max(0, TypeHelper::toInt($events['unix_time'] ?? null) ?? time());
        $normalized['global_tick'] = max(0, TypeHelper::toInt($events['global_tick'] ?? null) ?? 0);
        $normalized['paused'] = !empty($events['paused']);
        $normalized['site_online'] = !empty($events['site_online']);
        $normalized['maintenance_mode'] = !empty($events['maintenance_mode']);
        $normalized['jobs'] = is_array($events['jobs'] ?? null) ? $events['jobs'] : [];
        $normalized['services'] = is_array($events['services'] ?? null) ? $events['services'] : [];
        $normalized['updated_at'] = gmdate('Y-m-d H:i:s');

        $normalizedImages = [];
        if (!empty($events['images']) && is_array($events['images']))
        {
            foreach ($events['images'] as $hash => $tick)
            {
                $hash = TypeHelper::toString($hash, allowEmpty: true) ?? '';
                if ($hash === '')
                {
                    continue;
                }

                $normalizedImages[$hash] = max(0, TypeHelper::toInt($tick) ?? 0);
            }
        }

        $normalized['images'] = $normalizedImages;

        $payload = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($payload === false)
        {
            return;
        }

        $temporaryPath = $path . '.tmp';
        file_put_contents($temporaryPath, $payload, LOCK_EX);
        rename($temporaryPath, $path);
    }

    /**
     * Write the live event state on the same cadence as the heartbeat.
     *
     * @param array $config Application configuration
     * @param array $state Runtime state
     * @param bool $incrementGlobalTick Whether to increment the global tick
     * @return array<string, mixed> Persisted event state
     */
    public static function writeLiveEventsHeartbeat(array $config, array $state = [], bool $incrementGlobalTick = true): array
    {
        $events = self::loadLiveEvents($config);

        $events['status'] = 'running';
        $events['pid'] = getmypid();
        $events['timestamp'] = gmdate('Y-m-d H:i:s');
        $events['unix_time'] = time();
        $events['paused'] = !empty($state['paused']);
        $events['site_online'] = !empty($state['site_online']);
        $events['maintenance_mode'] = !empty($state['maintenance_mode']);
        $events['jobs'] = is_array($state['jobs'] ?? null) ? $state['jobs'] : [];
        $events['services'] = is_array($state['services'] ?? null) ? $state['services'] : [];

        $currentGlobalTick = max(0, TypeHelper::toInt($events['global_tick'] ?? null) ?? 0);
        $events['global_tick'] = $incrementGlobalTick ? ($currentGlobalTick + 1) : $currentGlobalTick;

        if (!isset($events['images']) || !is_array($events['images']))
        {
            $events['images'] = [];
        }

        self::saveLiveEvents($config, $events);
        return $events;
    }

    /**
     * Increment the live event tick for one gallery image.
     *
     * @param array $config Application configuration
     * @param string $imageHash Unique image hash
     * @return int Updated image tick value
     */
    public static function bumpImageLiveTick(array $config, string $imageHash): int
    {
        $imageHash = TypeHelper::toString($imageHash, allowEmpty: true) ?? '';
        if ($imageHash === '')
        {
            return 0;
        }

        $events = self::loadLiveEvents($config);
        $events['status'] = 'running';
        $events['pid'] = getmypid();
        $events['timestamp'] = gmdate('Y-m-d H:i:s');
        $events['unix_time'] = time();
        $events['global_tick'] = max(0, TypeHelper::toInt($events['global_tick'] ?? null) ?? 0) + 1;

        if (!isset($events['images']) || !is_array($events['images']))
        {
            $events['images'] = [];
        }

        $currentImageTick = max(0, TypeHelper::toInt($events['images'][$imageHash] ?? null) ?? 0) + 1;
        $events['images'][$imageHash] = $currentImageTick;
        self::saveLiveEvents($config, $events);

        return $currentImageTick;
    }

    /**
     * Read the current live event tick for one gallery image.
     *
     * @param array $config Application configuration
     * @param string $imageHash Unique image hash
     * @return int Image tick value
     */
    public static function imageLiveTick(array $config, string $imageHash): int
    {
        $imageHash = TypeHelper::toString($imageHash, allowEmpty: true) ?? '';
        if ($imageHash === '')
        {
            return 0;
        }

        $events = self::loadLiveEvents($config);
        return max(0, TypeHelper::toInt($events['images'][$imageHash] ?? null) ?? 0);
    }

    /**
     * Write the current heartbeat state to disk.
     *
     * The file is written atomically to reduce the chance of partial reads.
     *
     * @param array $config Application configuration
     * @param array $state Runtime state
     * @return void
     */
    public static function writeHeartbeat(array $config, array $state = []): void
    {
        $path = self::heartbeatPath($config);
        $directory = dirname($path);

        if (!is_dir($directory))
        {
            mkdir($directory, 0755, true);
        }

        $payload = json_encode([
            'status' => 'running',
            'pid' => getmypid(),
            'timestamp' => gmdate('Y-m-d H:i:s'),
            'unix_time' => time(),
            'paused' => !empty($state['paused']),
            'site_online' => !empty($state['site_online']),
            'maintenance_mode' => !empty($state['maintenance_mode']),
            'jobs' => is_array($state['jobs'] ?? null) ? $state['jobs'] : [],
            'services' => is_array($state['services'] ?? null) ? $state['services'] : [],
            'global_tick' => max(0, TypeHelper::toInt(self::loadLiveEvents($config)['global_tick'] ?? null) ?? 0),
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false)
        {
            return;
        }

        $temporaryPath = $path . '.tmp';
        file_put_contents($temporaryPath, $payload, LOCK_EX);
        rename($temporaryPath, $path);
    }

    /**
     * Mark the Control Server as stopped.
     *
     * @param array $config Application configuration
     * @return void
     */
    public static function clearHeartbeat(array $config): void
    {
        $path = self::heartbeatPath($config);

        if (is_file($path))
        {
            @unlink($path);
        }
    }

    /**
     * Mark the live event state as stopped and remove the file.
     *
     * @param array $config Application configuration
     * @return void
     */
    public static function clearLiveEvents(array $config): void
    {
        $path = self::liveEventsPath($config);

        if (is_file($path))
        {
            @unlink($path);
        }
    }

    /**
     * Read the current heartbeat status from disk.
     *
     * @param array $config Application configuration
     * @return array<string, mixed>
     */
    public static function readHeartbeat(array $config): array
    {
        $path = self::heartbeatPath($config);
        if (!is_file($path))
        {
            return [];
        }

        $contents = @file_get_contents($path);
        if ($contents === false || $contents === '')
        {
            return [];
        }

        $data = json_decode($contents, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Determine whether the Control Server heartbeat is currently healthy.
     *
     * @param array $config Application configuration
     * @return bool True when the heartbeat is fresh; otherwise false
     */
    public static function isAlive(array $config): bool
    {
        $data = self::readHeartbeat($config);
        if (empty($data['unix_time']))
        {
            return false;
        }

        $lastHeartbeat = TypeHelper::toInt($data['unix_time'] ?? null) ?? 0;
        $timeout = self::heartbeatTimeout($config);

        return (time() - $lastHeartbeat) <= $timeout;
    }

    /**
     * Open the local control socket for the Control Server.
     *
     * @param array $config Application configuration
     * @return resource|false Socket server resource on success; otherwise false
     */
    public static function openControlSocket(array $config)
    {
        if (!self::controlEnabled($config))
        {
            return false;
        }

        $block = self::configBlock($config);
        $address = self::controlBindAddress($config);
        $port = self::controlPort($config);
        $allowRemoteControl = !empty($block['control']['allow_remote_control']);
        $expectedToken = self::controlAuthToken($config);

        if (!self::isValidControlToken($expectedToken, $allowRemoteControl))
        {
            error_log('Control Server socket not opened: invalid or missing control auth token.');
            return false;
        }

        if (!$allowRemoteControl && !in_array($address, ['127.0.0.1', '::1'], true))
        {
            error_log('Control Server socket not opened: remote bind address is not allowed while allow_remote_control is disabled.');
            return false;
        }

        $endpoint = 'tcp://' . $address . ':' . $port;
        $errno = 0;
        $errstr = '';

        $server = @stream_socket_server($endpoint, $errno, $errstr);
        if ($server === false)
        {
            error_log('Control Server socket bind failed for ' . $endpoint . ' (' . $errno . '): ' . $errstr);
            return false;
        }

        stream_set_blocking($server, false);
        return $server;
    }

    /**
     * Process pending control socket commands without blocking the main loop.
     *
     * @param resource|false $server Socket server resource
     * @param array $config Application configuration
     * @param array $state Runtime state
     * @return array<int, array<string, mixed>>
     */
    public static function processControlCommands($server, array $config, array &$state): array
    {
        $responses = [];
        if (!is_resource($server))
        {
            return $responses;
        }

        for ($i = 0; $i < 10; $i++)
        {
            $client = @stream_socket_accept($server, 0, $peerName);
            if ($client === false)
            {
                break;
            }

            stream_set_blocking($client, false);

            $clientId = intval($client);
            self::$controlClients[$clientId] = $client;
            self::$controlClientStates[$clientId] = [
                'peer_ip' => self::extractPeerIp(TypeHelper::toString($peerName ?? '', allowEmpty: true) ?? ''),
                'phase' => 'token',
                'authenticated' => false,
            ];

            @fwrite($client, "Image Gallery Maintenance Control\n");
            @fwrite($client, "Use JSON for automation or enter the control token below.\n");
            @fwrite($client, "Token or JSON payload: ");
            @fflush($client);
        }

        foreach (self::$controlClients as $clientId => $client)
        {
            if (!is_resource($client))
            {
                unset(self::$controlClients[$clientId], self::$controlClientStates[$clientId]);
                continue;
            }

            if (feof($client))
            {
                self::closeControlClient($clientId);
                continue;
            }

            $clientState = self::$controlClientStates[$clientId] ?? [];
            $peerIp = TypeHelper::toString($clientState['peer_ip'] ?? '', allowEmpty: true) ?? '';

            if (!self::isAllowedControlIp($peerIp, $config))
            {
                @fwrite($client, '{"ok":false,"message":"Control access denied for remote address."}' . "\n");
                self::closeControlClient($clientId);
                $responses[] = [
                    'ok' => false,
                    'message' => 'Control access denied for remote address.',
                ];
                continue;
            }

            $line = fgets($client, 8192);
            if ($line === false)
            {
                continue;
            }

            $input = trim($line);
            if ($input === '')
            {
                if (($clientState['phase'] ?? '') === 'command')
                {
                    @fwrite($client, "controlserver> ");
                    @fflush($client);
                }

                continue;
            }

            $phase = TypeHelper::toString($clientState['phase'] ?? 'token', allowEmpty: true) ?? 'token';

            if ($phase === 'token')
            {
                $request = json_decode($input, true);
                if (is_array($request))
                {
                    $response = self::handleJsonControlRequest($request, $config, $state);
                    $payload = json_encode($response, JSON_UNESCAPED_SLASHES);
                    if ($payload !== false)
                    {
                        @fwrite($client, $payload . "\n");
                    }

                    $responses[] = $response;
                    self::closeControlClient($clientId);
                    continue;
                }

                $expectedToken = self::controlAuthToken($config);
                if ($expectedToken === '' || !hash_equals($expectedToken, $input))
                {
                    @fwrite($client, "Authentication failed.\n");
                    self::closeControlClient($clientId);
                    $responses[] = [
                        'ok' => false,
                        'message' => 'Invalid interactive control authorization token.',
                    ];
                    continue;
                }

                $clientState['authenticated'] = true;
                $clientState['phase'] = 'command';
                self::$controlClientStates[$clientId] = $clientState;

                @fwrite($client, "Login successful.\n");
                @fwrite($client, "Type 'help' for commands. Type 'quit' to disconnect.\n\n");
                @fwrite($client, "controlserver> ");
                @fflush($client);

                $responses[] = [
                    'ok' => true,
                    'message' => 'Interactive control session authenticated.',
                ];
                continue;
            }

            $normalized = strtolower($input);
            if (in_array($normalized, ['quit', 'exit', 'logout'], true))
            {
                @fwrite($client, "Goodbye.\n");
                self::closeControlClient($clientId);
                $responses[] = [
                    'ok' => true,
                    'message' => 'Interactive control session closed.',
                ];
                continue;
            }

            if ($normalized === 'help')
            {
                @fwrite($client, self::interactiveHelpText() . "\n");
                @fwrite($client, "controlserver> ");
                @fflush($client);
                continue;
            }

            $request = self::buildTextControlRequest($input, self::controlAuthToken($config));
            if ($request === null)
            {
                @fwrite($client, "Unknown or invalid command. Type 'help' for commands.\n");
                @fwrite($client, "controlserver> ");
                @fflush($client);
                continue;
            }

            $response = self::handleControlCommand($request, $config, $state);
            $payload = self::formatInteractiveControlResponse($response);

            @fwrite($client, $payload);
            @fwrite($client, "controlserver> ");
            @fflush($client);

            $responses[] = $response;
        }

        return $responses;
    }

    /**
     * Validate and process a JSON control request.
     *
     * @param array $request Control request payload
     * @param array $config Application configuration
     * @param array $state Runtime state
     * @return array<string, mixed> Control response
     */
    private static function handleJsonControlRequest(array $request, array $config, array &$state): array
    {
        $expectedToken = self::controlAuthToken($config);
        $providedToken = TypeHelper::toString($request['token'] ?? '', allowEmpty: true) ?? '';
        if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken))
        {
            return [
                'ok' => false,
                'message' => 'Invalid control authorization token.',
            ];
        }

        return self::handleControlCommand($request, $config, $state);
    }

    /**
     * Return the interactive control shell help text.
     *
     * @return string Help text
     */
    private static function interactiveHelpText(): string
    {
        return implode("\n", [
            'Available commands:',
            '  status',
            '',
            '  Jobs',
            '    jobs <job> <on|off>',
            '    jobs sessions on',
            '    jobs request_guard off',
            '',
            '  Services',
            '    service <service> <on|off>',
            '    service site off',
            '    service register off',
            '    service forums off',
            '    service live_chat off',
            '',
            '  Tasks',
            '    task cleanup run',
            '',
            '  Maintenance',
            '    maintenance pause',
            '    maintenance resume',
            '    maintenance mode on',
            '    maintenance mode off',
            '    maintenance offline on',
            '    maintenance offline off',
            '    maintenance verbose <on|off>',
            '    maintenance interval <seconds>',
            '    maintenance retention <days>',
            '    maintenance reload-defaults',
            '',
            '  Legacy commands are still accepted.',
            '  help',
            '  quit',
        ]);
    }

    /**
     * Convert a boolean-like value into a human-readable enabled or disabled label.
     *
     * @param mixed $value Raw boolean-like value
     * @return string Human-readable state label
     */
    private static function formatBooleanLabel(mixed $value): string
    {
        return !empty($value) ? 'Enabled' : 'Disabled';
    }

    /**
     * Append one aligned label/value row to a text response buffer.
     *
     * @param array<int, string> $lines Output line buffer
     * @param string $label Row label
     * @param string $value Row value
     * @param int $labelWidth Fixed label width including the trailing colon
     * @return void
     */
    private static function appendFormattedRow(array &$lines, string $label, string $value, int $labelWidth = 22): void
    {
        $lines[] = '  ' . str_pad($label . ':', $labelWidth, ' ', STR_PAD_RIGHT) . $value;
    }

    /**
     * Build a human-readable interactive control response.
     *
     * @param array<string, mixed> $response Control response payload
     * @return string Formatted response text
     */
    private static function formatInteractiveControlResponse(array $response): string
    {
        $lines = [];
        $lines[] = !empty($response['ok']) ? '[OK]' : '[ERROR]';

        $message = TypeHelper::toString($response['message'] ?? '', allowEmpty: true) ?? '';
        if ($message !== '')
        {
            self::appendFormattedRow($lines, 'Message', $message);
        }

        $state = $response['state'] ?? null;
        if (!is_array($state))
        {
            return implode("\n", $lines) . "\n";
        }

        $lines[] = '';
        $lines[] = '  Runtime State';
        $lines[] = '  -------------';
        self::appendFormattedRow($lines, 'Paused', self::formatBooleanLabel($state['paused'] ?? false));
        self::appendFormattedRow($lines, 'Site Online', self::formatBooleanLabel($state['site_online'] ?? true));
        self::appendFormattedRow($lines, 'Maintenance Mode', self::formatBooleanLabel($state['maintenance_mode'] ?? false));
        self::appendFormattedRow($lines, 'Verbose Logging', self::formatBooleanLabel($state['verbose_logging'] ?? false));
        self::appendFormattedRow($lines, 'Tick Interval', (string)(TypeHelper::toInt($state['tick_interval_seconds'] ?? null) ?? 0) . ' second(s)');
        self::appendFormattedRow($lines, 'Log Retention', (string)(TypeHelper::toInt($state['log_retention_days'] ?? null) ?? 0) . ' day(s)');
        self::appendFormattedRow($lines, 'Run Cleanup Now', self::formatBooleanLabel($state['run_cleanup_now'] ?? false));

        if (!empty($state['updated_at']))
        {
            self::appendFormattedRow($lines, 'Updated At', TypeHelper::toString($state['updated_at'] ?? '', allowEmpty: true) ?? '');
        }

        $services = $state['services'] ?? [];
        if (is_array($services) && !empty($services))
        {
            $lines[] = '';
            $lines[] = '  Services:';

            foreach ($services as $service => $enabled)
            {
                self::appendFormattedRow($lines, TypeHelper::toString($service, allowEmpty: true) ?? '', self::formatBooleanLabel($enabled));
            }
        }

        $jobs = $state['jobs'] ?? [];
        if (is_array($jobs) && !empty($jobs))
        {
            $lines[] = '';
            $lines[] = '  Jobs:';

            foreach ($jobs as $job => $enabled)
            {
                self::appendFormattedRow($lines, TypeHelper::toString($job, allowEmpty: true) ?? '', self::formatBooleanLabel($enabled));
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Convert one interactive text command into a control request payload.
     *
     * @param string $commandLine Raw command line
     * @param string $token Authorized control token
     * @return array<string, mixed>|null Parsed request on success; otherwise null
     */
    private static function buildTextControlRequest(string $commandLine, string $token): ?array
    {
        $parts = preg_split('/\s+/', trim($commandLine)) ?: [];
        if (empty($parts))
        {
            return null;
        }

        $command = strtolower(TypeHelper::toString($parts[0] ?? '', allowEmpty: true) ?? '');
        $request = [
            'token' => $token,
            'command' => 'status',
        ];

        switch ($command)
        {
            case 'status':
                return $request;

            case 'jobs':
                if (empty($parts[1]) || !isset($parts[2]))
                {
                    return null;
                }

                $request['command'] = 'set_job';
                $request['job'] = TypeHelper::toString($parts[1] ?? '', allowEmpty: true) ?? '';
                $request['enabled'] = self::textValueToBool(TypeHelper::toString($parts[2] ?? '', allowEmpty: true) ?? '');
                return $request;

            case 'service':
                if (empty($parts[1]) || !isset($parts[2]))
                {
                    return null;
                }

                $request['command'] = 'set_service';
                $request['service'] = TypeHelper::toString($parts[1] ?? '', allowEmpty: true) ?? '';
                $request['enabled'] = self::textValueToBool(TypeHelper::toString($parts[2] ?? '', allowEmpty: true) ?? '');
                return $request;

            case 'task':
                $task = strtolower(TypeHelper::toString($parts[1] ?? '', allowEmpty: true) ?? '');
                $arg = strtolower(TypeHelper::toString($parts[2] ?? '', allowEmpty: true) ?? '');
                if ($task === 'cleanup' && in_array($arg, ['run', 'now'], true))
                {
                    $request['command'] = 'run_cleanup_now';
                    return $request;
                }

                return null;

            case 'maintenance':
                $action = strtolower(TypeHelper::toString($parts[1] ?? '', allowEmpty: true) ?? '');
                $arg = strtolower(TypeHelper::toString($parts[2] ?? '', allowEmpty: true) ?? '');

                if ($action === 'pause')
                {
                    $request['command'] = 'pause';
                    return $request;
                }

                if ($action === 'resume')
                {
                    $request['command'] = 'resume';
                    return $request;
                }

                if ($action === 'mode' && $arg !== '')
                {
                    $request['command'] = 'set_maintenance_mode';
                    $request['enabled'] = self::textValueToBool($arg);
                    return $request;
                }

                if ($action === 'offline' && $arg !== '')
                {
                    $request['command'] = 'set_site_online';
                    $request['enabled'] = !self::textValueToBool($arg);
                    return $request;
                }

                if ($action === 'verbose' && $arg !== '')
                {
                    $request['command'] = 'set_verbose';
                    $request['enabled'] = self::textValueToBool($arg);
                    return $request;
                }

                if ($action === 'interval' && isset($parts[2]))
                {
                    $request['command'] = 'set_tick_interval';
                    $request['seconds'] = max(1, TypeHelper::toInt($parts[2] ?? null) ?? 1);
                    return $request;
                }

                if ($action === 'retention' && isset($parts[2]))
                {
                    $request['command'] = 'set_log_retention_days';
                    $request['days'] = max(1, TypeHelper::toInt($parts[2] ?? null) ?? 1);
                    return $request;
                }

                if (in_array($action, ['reload-defaults', 'reload_defaults'], true))
                {
                    $request['command'] = 'reload_defaults';
                    return $request;
                }

                return null;

            default:
                return self::buildLegacyTextControlRequest($parts, $token);
        }
    }

    /**
     * Convert legacy interactive commands into control request payloads.
     *
     * @param array<int, string> $parts Parsed command tokens
     * @param string $token Authorized control token
     * @return array<string, mixed>|null Parsed request on success; otherwise null
     */
    private static function buildLegacyTextControlRequest(array $parts, string $token): ?array
    {
        $command = strtolower(TypeHelper::toString($parts[0] ?? '', allowEmpty: true) ?? '');
        $request = [
            'token' => $token,
            'command' => 'status',
        ];

        switch ($command)
        {
            case 'pause':
            case 'resume':
                $request['command'] = $command;
                return $request;

            case 'run-cleanup-now':
            case 'run_cleanup_now':
                $request['command'] = 'run_cleanup_now';
                return $request;

            case 'maintenance-on':
            case 'maintenance_on':
                $request['command'] = 'maintenance_on';
                return $request;

            case 'maintenance-off':
            case 'maintenance_off':
                $request['command'] = 'maintenance_off';
                return $request;

            case 'set-maintenance-mode':
            case 'set_maintenance_mode':
                if (!isset($parts[1]))
                {
                    return null;
                }

                $request['command'] = 'set_maintenance_mode';
                $request['enabled'] = self::textValueToBool(TypeHelper::toString($parts[1] ?? '', allowEmpty: true) ?? '');
                return $request;

            case 'enable-job':
            case 'enable_job':
                if (empty($parts[1]))
                {
                    return null;
                }

                $request['command'] = 'enable_job';
                $request['job'] = TypeHelper::toString($parts[1] ?? '', allowEmpty: true) ?? '';
                return $request;

            case 'disable-job':
            case 'disable_job':
                if (empty($parts[1]))
                {
                    return null;
                }

                $request['command'] = 'disable_job';
                $request['job'] = TypeHelper::toString($parts[1] ?? '', allowEmpty: true) ?? '';
                return $request;

            case 'set-job':
            case 'set_job':
                if (empty($parts[1]) || !isset($parts[2]))
                {
                    return null;
                }

                $request['command'] = 'set_job';
                $request['job'] = TypeHelper::toString($parts[1] ?? '', allowEmpty: true) ?? '';
                $request['enabled'] = self::textValueToBool(TypeHelper::toString($parts[2] ?? '', allowEmpty: true) ?? '');
                return $request;

            case 'set-verbose':
            case 'set_verbose':
                if (!isset($parts[1]))
                {
                    return null;
                }

                $request['command'] = 'set_verbose';
                $request['enabled'] = self::textValueToBool(TypeHelper::toString($parts[1] ?? '', allowEmpty: true) ?? '');
                return $request;

            case 'set-tick-interval':
            case 'set_tick_interval':
                if (!isset($parts[1]))
                {
                    return null;
                }

                $request['command'] = 'set_tick_interval';
                $request['seconds'] = max(1, TypeHelper::toInt($parts[1] ?? null) ?? 1);
                return $request;

            case 'set-log-retention-days':
            case 'set_log_retention_days':
                if (!isset($parts[1]))
                {
                    return null;
                }

                $request['command'] = 'set_log_retention_days';
                $request['days'] = max(1, TypeHelper::toInt($parts[1] ?? null) ?? 1);
                return $request;

            case 'reload-defaults':
            case 'reload_defaults':
                $request['command'] = 'reload_defaults';
                return $request;

            default:
                return null;
        }
    }

    /**
     * Normalize a text control value into a boolean state.
     *
     * @param string $value Raw value
     * @return bool Normalized boolean state
     */
    private static function textValueToBool(string $value): bool
    {
        $normalized = strtolower(trim($value));
        return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    /**
     * Apply one validated control command to the current runtime state.
     *
     * @param array $request Control request payload
     * @param array $config Application configuration
     * @param array $state Runtime state
     * @return array<string, mixed>
     */
    private static function handleControlCommand(array $request, array $config, array &$state): array
    {
        $command = strtolower(TypeHelper::toString($request['command'] ?? 'status', allowEmpty: true) ?? 'status');
        $response = [
            'ok' => true,
            'message' => 'Command applied.',
        ];

        switch ($command)
        {
            case 'status':
                $response['message'] = 'Server status loaded.';
                break;

            case 'pause':
                $state['paused'] = true;
                $response['message'] = 'Control Server jobs paused.';
                break;

            case 'resume':
                $state['paused'] = false;
                $response['message'] = 'Control Server jobs resumed.';
                break;

            case 'maintenance_on':
                $state['maintenance_mode'] = true;
                $response['message'] = 'Site maintenance mode enabled.';
                break;

            case 'maintenance_off':
                $state['maintenance_mode'] = false;
                $response['message'] = 'Site maintenance mode disabled.';
                break;

            case 'set_maintenance_mode':
                $state['maintenance_mode'] = !empty($request['enabled']);
                $response['message'] = 'Site maintenance mode updated.';
                break;

            case 'set_site_online':
                $state['site_online'] = !empty($request['enabled']);
                $state['services']['site'] = !empty($request['enabled']);
                $response['message'] = !empty($request['enabled'])
                    ? 'Public site brought online.'
                    : 'Public site placed offline.';
                break;

            case 'run_cleanup_now':
                $state['run_cleanup_now'] = true;
                $response['message'] = 'Cleanup run scheduled.';
                break;

            case 'set_verbose':
                $state['verbose_logging'] = !empty($request['enabled']);
                $response['message'] = 'Verbose logging updated.';
                break;

            case 'set_tick_interval':
                $state['tick_interval_seconds'] = max(1, TypeHelper::toInt($request['seconds'] ?? null) ?? 1);
                $response['message'] = 'Tick interval updated.';
                break;

            case 'set_log_retention_days':
                $state['log_retention_days'] = max(1, TypeHelper::toInt($request['days'] ?? null) ?? 30);
                $response['message'] = 'Log retention updated.';
                break;

            case 'enable_job':
            case 'disable_job':
            case 'set_job':
                $job = TypeHelper::toString($request['job'] ?? '', allowEmpty: true) ?? '';
                if ($job === '')
                {
                    return [
                        'ok' => false,
                        'message' => 'Missing job name.',
                    ];
                }

                $enabled = $command === 'enable_job';
                if ($command === 'disable_job')
                {
                    $enabled = false;
                }
                elseif ($command === 'set_job')
                {
                    $enabled = !empty($request['enabled']);
                }

                $state['jobs'][$job] = $enabled;
                $response['message'] = 'Job state updated.';
                break;

            case 'enable_service':
            case 'disable_service':
            case 'set_service':
                $service = TypeHelper::toString($request['service'] ?? '', allowEmpty: true) ?? '';
                if ($service === '')
                {
                    return [
                        'ok' => false,
                        'message' => 'Missing service name.',
                    ];
                }

                $enabled = $command === 'enable_service';
                if ($command === 'disable_service')
                {
                    $enabled = false;
                }
                elseif ($command === 'set_service')
                {
                    $enabled = !empty($request['enabled']);
                }

                $state['services'][$service] = $enabled;
                if ($service === 'site')
                {
                    $state['site_online'] = $enabled;
                }

                $response['message'] = 'Service state updated.';
                break;

            case 'reload_defaults':
                $state = self::defaultRuntimeState($config);
                $response['message'] = 'Runtime state reset to defaults.';
                break;

            default:
                return [
                    'ok' => false,
                    'message' => 'Unknown control command.',
                ];
        }

        self::saveRuntimeState($config, $state);
        $response['state'] = $state;
        return $response;
    }


    /**
     * Open the WebSocket server for browser live updates and CLI control.
     *
     * @param array $config Application configuration
     * @return resource|false WebSocket server resource on success; otherwise false
     */
    public static function openWebSocketServer(array $config)
    {
        if (!self::webSocketEnabled($config))
        {
            return false;
        }

        $address = self::webSocketBindAddress($config);
        $port = self::webSocketPort($config);
        if (!self::webSocketAllowRemoteClients($config) && !in_array($address, ['127.0.0.1', '::1'], true))
        {
            error_log('Control Server WebSocket not opened: remote bind address is not allowed while allow_remote_clients is disabled.');
            return false;
        }

        $endpoint = 'tcp://' . $address . ':' . $port;
        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server($endpoint, $errno, $errstr);
        if ($server === false)
        {
            error_log('Control Server WebSocket bind failed for ' . $endpoint . ' (' . $errno . '): ' . $errstr);
            return false;
        }

        stream_set_blocking($server, false);
        return $server;
    }

    /**
     * Process pending WebSocket traffic without blocking the main loop.
     *
     * @param resource|false $server WebSocket server resource
     * @param array $config Application configuration
     * @param array $state Runtime state
     * @return array<int, array<string, mixed>> Control responses generated by CLI WebSocket clients
     */
    public static function processWebSocketConnections($server, array $config, array &$state): array
    {
        $responses = [];
        if (!is_resource($server))
        {
            return $responses;
        }

        for ($i = 0; $i < 10; $i++)
        {
            $client = @stream_socket_accept($server, 0, $peerName);
            if ($client === false)
            {
                break;
            }

            stream_set_blocking($client, false);
            $clientId = intval($client);
            self::$webSocketClients[$clientId] = $client;
            self::$webSocketClientStates[$clientId] = [
                'peer_ip' => self::extractPeerIp(TypeHelper::toString($peerName ?? '', allowEmpty: true) ?? ''),
                'handshake_complete' => false,
                'buffer' => '',
                'path' => '/',
                'mode' => 'browser',
                'authenticated' => false,
                'subscriptions' => [],
            ];
        }

        foreach (self::$webSocketClients as $clientId => $client)
        {
            if (!is_resource($client))
            {
                unset(self::$webSocketClients[$clientId], self::$webSocketClientStates[$clientId]);
                continue;
            }

            if (feof($client))
            {
                self::closeWebSocketClient($clientId);
                continue;
            }

            $chunk = @fread($client, 8192);
            if ($chunk === false || $chunk === '')
            {
                continue;
            }

            $clientState = self::$webSocketClientStates[$clientId] ?? [];
            $clientState['buffer'] = TypeHelper::toString($clientState['buffer'] ?? '', allowEmpty: true) ?? '';
            $clientState['buffer'] .= $chunk;

            if (empty($clientState['handshake_complete']))
            {
                if (strpos($clientState['buffer'], "

") === false)
                {
                    self::$webSocketClientStates[$clientId] = $clientState;
                    continue;
                }

                if (!self::performWebSocketHandshake($client, $clientState, $config))
                {
                    self::closeWebSocketClient($clientId);
                    continue;
                }
            }

            while (true)
            {
                $frame = self::decodeWebSocketFrame($clientState['buffer']);
                if ($frame === null)
                {
                    break;
                }

                $clientState['buffer'] = $frame['remaining'];
                $opcode = TypeHelper::toInt($frame['opcode'] ?? null) ?? 1;
                $payload = TypeHelper::toString($frame['payload'] ?? '', allowEmpty: true) ?? '';

                if ($opcode === 8)
                {
                    self::closeWebSocketClient($clientId);
                    continue 2;
                }

                if ($opcode === 9)
                {
                    self::sendWebSocketFrame($client, $payload, 10);
                    continue;
                }

                if ($opcode !== 1)
                {
                    continue;
                }

                $response = self::handleWebSocketMessage($clientId, $payload, $config, $state);
                self::$webSocketClientStates[$clientId] = $clientState;

                if ($response !== null)
                {
                    self::sendWebSocketJson($client, $response);
                    if (($clientState['mode'] ?? 'browser') === 'control')
                    {
                        $responses[] = $response;
                    }
                }

                $clientState = self::$webSocketClientStates[$clientId] ?? $clientState;
            }

            self::$webSocketClientStates[$clientId] = $clientState;
        }

        return $responses;
    }

    /**
     * Broadcast image tick changes to subscribed WebSocket clients.
     *
     * @param array $config Application configuration
     * @return void
     */
    public static function broadcastLiveEventChanges(array $config): void
    {
        $events = self::loadLiveEvents($config);
        $images = is_array($events['images'] ?? null) ? $events['images'] : [];

        foreach ($images as $imageHash => $tick)
        {
            $imageHash = TypeHelper::toString($imageHash, allowEmpty: true) ?? '';
            if ($imageHash === '')
            {
                continue;
            }

            $tick = max(0, TypeHelper::toInt($tick) ?? 0);
            $previousTick = self::$lastBroadcastImageTicks[$imageHash] ?? 0;
            if ($tick <= $previousTick)
            {
                continue;
            }

            $subscribers = self::$webSocketImageSubscriptions[$imageHash] ?? [];
            if (!is_array($subscribers) || empty($subscribers))
            {
                self::$lastBroadcastImageTicks[$imageHash] = $tick;
                continue;
            }

            foreach (array_keys($subscribers) as $clientId)
            {
                $client = self::$webSocketClients[$clientId] ?? null;
                if (!is_resource($client))
                {
                    unset(self::$webSocketImageSubscriptions[$imageHash][$clientId]);
                    continue;
                }

                $clientState = self::$webSocketClientStates[$clientId] ?? [];
                if (empty($clientState['handshake_complete']) || ($clientState['mode'] ?? 'browser') !== 'browser')
                {
                    unset(self::$webSocketImageSubscriptions[$imageHash][$clientId]);
                    continue;
                }

                self::sendWebSocketJson($client, [
                    'ok' => true,
                    'type' => 'image_update',
                    'image' => $imageHash,
                    'tick' => $tick,
                    'global_tick' => max(0, TypeHelper::toInt($events['global_tick'] ?? null) ?? 0),
                ]);
            }

            if (empty(self::$webSocketImageSubscriptions[$imageHash]))
            {
                unset(self::$webSocketImageSubscriptions[$imageHash]);
            }

            self::$lastBroadcastImageTicks[$imageHash] = $tick;
        }
    }

    /**
     * Perform the HTTP Upgrade handshake for one WebSocket client.
     *
     * @param resource $client Connected client resource
     * @param array $clientState Mutable client state
     * @param array $config Application configuration
     * @return bool True on success
     */
    private static function performWebSocketHandshake($client, array &$clientState, array $config): bool
    {
        $request = TypeHelper::toString($clientState['buffer'] ?? '', allowEmpty: true) ?? '';
        [$headerBlock, $remaining] = array_pad(explode("

", $request, 2), 2, '');
        $lines = preg_split("/
/", $headerBlock) ?: [];
        $requestLine = TypeHelper::toString($lines[0] ?? '', allowEmpty: true) ?? '';
        if ($requestLine === '')
        {
            return false;
        }

        if (!preg_match('#^GET\s+([^\s]+)#i', $requestLine, $matches))
        {
            return false;
        }

        $path = TypeHelper::toString(parse_url($matches[1], PHP_URL_PATH) ?? '/', allowEmpty: true) ?? '/';
        $headers = [];
        foreach (array_slice($lines, 1) as $line)
        {
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2)
            {
                continue;
            }

            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }

        $key = TypeHelper::toString($headers['sec-websocket-key'] ?? '', allowEmpty: true) ?? '';
        if ($key === '')
        {
            return false;
        }

        $peerIp = TypeHelper::toString($clientState['peer_ip'] ?? '', allowEmpty: true) ?? '';
        $mode = str_starts_with($path, '/control') ? 'control' : 'browser';
        if ($mode === 'control' && !self::isAllowedControlIp($peerIp, $config))
        {
            return false;
        }

        if ($mode === 'browser' && !self::webSocketAllowRemoteClients($config) && !in_array($peerIp, ['127.0.0.1', '::1'], true))
        {
            return false;
        }

        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $response = "HTTP/1.1 101 Switching Protocols
"
            . "Upgrade: websocket
"
            . "Connection: Upgrade
"
            . "Sec-WebSocket-Accept: {$accept}

";

        @fwrite($client, $response);
        @fflush($client);

        $clientState['handshake_complete'] = true;
        $clientState['buffer'] = $remaining;
        $clientState['path'] = $path;
        $clientState['mode'] = $mode;
        $clientState['authenticated'] = false;
        $clientState['subscriptions'] = [];
        return true;
    }

    /**
     * Decode one WebSocket frame from the provided buffer.
     *
     * @param string $buffer Raw buffered client data
     * @return array<string, mixed>|null Decoded frame when complete; otherwise null
     */
    private static function decodeWebSocketFrame(string $buffer): ?array
    {
        $length = strlen($buffer);
        if ($length < 2)
        {
            return null;
        }

        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        $opcode = $first & 0x0F;
        $masked = ($second & 0x80) === 0x80;
        $payloadLength = $second & 0x7F;
        $offset = 2;

        if ($payloadLength === 126)
        {
            if ($length < 4)
            {
                return null;
            }

            $payloadLength = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        }
        elseif ($payloadLength === 127)
        {
            if ($length < 10)
            {
                return null;
            }

            $parts = unpack('N2', substr($buffer, 2, 8));
            $payloadLength = ((int)$parts[1] << 32) + (int)$parts[2];
            $offset = 10;
        }

        $mask = '';
        if ($masked)
        {
            if ($length < $offset + 4)
            {
                return null;
            }

            $mask = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if ($length < $offset + $payloadLength)
        {
            return null;
        }

        $payload = substr($buffer, $offset, $payloadLength);
        $remaining = substr($buffer, $offset + $payloadLength);

        if ($masked)
        {
            $decoded = '';
            for ($i = 0; $i < $payloadLength; $i++)
            {
                $decoded .= $payload[$i] ^ $mask[$i % 4];
            }

            $payload = $decoded;
        }

        return [
            'opcode' => $opcode,
            'payload' => $payload,
            'remaining' => $remaining,
        ];
    }

    /**
     * Send one WebSocket text frame.
     *
     * @param resource $client Connected client resource
     * @param string $payload Payload to send
     * @param int $opcode WebSocket opcode
     * @return void
     */
    private static function sendWebSocketFrame($client, string $payload, int $opcode = 1): void
    {
        $length = strlen($payload);
        $header = chr(0x80 | ($opcode & 0x0F));
        if ($length <= 125)
        {
            $header .= chr($length);
        }
        elseif ($length <= 65535)
        {
            $header .= chr(126) . pack('n', $length);
        }
        else
        {
            $header .= chr(127) . pack('NN', 0, $length);
        }

        @fwrite($client, $header . $payload);
        @fflush($client);
    }

    /**
     * Send one JSON WebSocket frame.
     *
     * @param resource $client Connected client resource
     * @param array $payload Payload to send
     * @return void
     */
    private static function sendWebSocketJson($client, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false)
        {
            return;
        }

        self::sendWebSocketFrame($client, $json, 1);
    }

    /**
     * Handle one WebSocket text message.
     *
     * @param int $clientId Client stream ID
     * @param string $payload Text frame payload
     * @param array $config Application configuration
     * @param array $state Runtime state
     * @return array<string, mixed>|null Response payload when applicable
     */
    private static function handleWebSocketMessage(int $clientId, string $payload, array $config, array &$state): ?array
    {
        $clientState = self::$webSocketClientStates[$clientId] ?? [];
        $mode = TypeHelper::toString($clientState['mode'] ?? 'browser', allowEmpty: true) ?? 'browser';
        $request = json_decode($payload, true);
        if (!is_array($request))
        {
            return [
                'ok' => false,
                'message' => 'Invalid WebSocket payload.',
            ];
        }

        if ($mode === 'control')
        {
            $response = self::handleJsonControlRequest($request, $config, $state);
            return $response;
        }

        $action = strtolower(TypeHelper::toString($request['action'] ?? '', allowEmpty: true) ?? '');
        if ($action === 'subscribe')
        {
            $imageHash = TypeHelper::toString($request['image'] ?? '', allowEmpty: true) ?? '';
            if ($imageHash === '')
            {
                return [
                    'ok' => false,
                    'message' => 'Missing image subscription hash.',
                ];
            }

            self::removeWebSocketClientSubscriptions($clientId);

            if (!isset(self::$webSocketImageSubscriptions[$imageHash]) || !is_array(self::$webSocketImageSubscriptions[$imageHash]))
            {
                self::$webSocketImageSubscriptions[$imageHash] = [];
            }

            self::$webSocketImageSubscriptions[$imageHash][$clientId] = true;
            $clientState['subscriptions'] = [$imageHash];
            self::$webSocketClientStates[$clientId] = $clientState;
            return [
                'ok' => true,
                'type' => 'subscribed',
                'image' => $imageHash,
                'tick' => self::imageLiveTick($config, $imageHash),
            ];
        }

        if ($action === 'ping')
        {
            return [
                'ok' => true,
                'type' => 'pong',
            ];
        }

        return [
            'ok' => false,
            'message' => 'Unknown WebSocket action.',
        ];
    }

    /**
     * Close one connected WebSocket client and remove its session state.
     *
     * @param int $clientId Internal client stream ID
     * @return void
     */
    private static function closeWebSocketClient(int $clientId): void
    {
        if (isset(self::$webSocketClients[$clientId]) && is_resource(self::$webSocketClients[$clientId]))
        {
            @fclose(self::$webSocketClients[$clientId]);
        }

        self::removeWebSocketClientSubscriptions($clientId);
        unset(self::$webSocketClients[$clientId], self::$webSocketClientStates[$clientId]);
    }

    /**
     * Remove one WebSocket client from all image subscription lists.
     *
     * @param int $clientId Internal client stream ID
     * @return void
     */
    private static function removeWebSocketClientSubscriptions(int $clientId): void
    {
        foreach (self::$webSocketImageSubscriptions as $imageHash => $subscribers)
        {
            if (!is_array($subscribers))
            {
                continue;
            }

            unset(self::$webSocketImageSubscriptions[$imageHash][$clientId]);
            if (empty(self::$webSocketImageSubscriptions[$imageHash]))
            {
                unset(self::$webSocketImageSubscriptions[$imageHash]);
            }
        }
    }

    /**
     * Determine whether a control client IP is permitted.
     *
     * @param string $peerIp Client IP address
     * @param array $config Application configuration
     * @return bool True when the IP is allowed
     */
    private static function isAllowedControlIp(string $peerIp, array $config): bool
    {
        if ($peerIp === '')
        {
            return false;
        }

        return in_array($peerIp, self::controlAllowedIps($config), true);
    }

    /**
     * Extract the remote IP address from a stream socket peer string.
     *
     * @param string $peerName Stream socket peer name
     * @return string Remote IP address
     */
    private static function extractPeerIp(string $peerName): string
    {
        $peerName = trim($peerName);
        if ($peerName === '')
        {
            return '';
        }

        if (preg_match('/^\[(.+)\]:(\d+)$/', $peerName, $matches))
        {
            return $matches[1];
        }

        $parts = explode(':', $peerName);
        if (count($parts) >= 2)
        {
            array_pop($parts);
            return implode(':', $parts);
        }

        return $peerName;
    }

    /**
     * Close one connected control client and remove its session state.
     *
     * @param int $clientId Internal client stream ID
     * @return void
     */
    private static function closeControlClient(int $clientId): void
    {
        if (isset(self::$controlClients[$clientId]) && is_resource(self::$controlClients[$clientId]))
        {
            @fclose(self::$controlClients[$clientId]);
        }

        unset(self::$controlClients[$clientId], self::$controlClientStates[$clientId]);
    }
}
