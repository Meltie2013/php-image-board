<?php

/**
 * Background maintenance server heartbeat and runtime control helper.
 *
 * Provides a bridge between the public site and server.php so the frontend can
 * detect whether the required background process is alive, while also allowing
 * the long-running maintenance server to be controlled securely at runtime.
 */
class MaintenanceServer
{
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
     * Default relative path used to store the heartbeat file.
     */
    private const DEFAULT_HEARTBEAT_FILE = 'cache/maintenance_server_heartbeat.json';

    /**
     * Default relative path used to store the runtime state file.
     */
    private const DEFAULT_STATE_FILE = 'cache/maintenance_server_state.json';

    /**
     * Determine the absolute heartbeat file path.
     *
     * @param array $config Application configuration
     * @return string Absolute heartbeat file path
     */
    public static function heartbeatPath(array $config): string
    {
        $relativePath = TypeHelper::toString($config['maintenance_server']['heartbeat_file'] ?? '', allowEmpty: true)
            ?? self::DEFAULT_HEARTBEAT_FILE;

        if ($relativePath === '')
        {
            $relativePath = self::DEFAULT_HEARTBEAT_FILE;
        }

        return dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    }

    /**
     * Determine the absolute runtime state file path.
     *
     * @param array $config Application configuration
     * @return string Absolute runtime state file path
     */
    public static function statePath(array $config): string
    {
        $relativePath = TypeHelper::toString($config['maintenance_server']['state_file'] ?? '', allowEmpty: true)
            ?? self::DEFAULT_STATE_FILE;

        if ($relativePath === '')
        {
            $relativePath = self::DEFAULT_STATE_FILE;
        }

        return dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    }

    /**
     * Determine how long a heartbeat remains valid.
     *
     * @param array $config Application configuration
     * @return int Heartbeat timeout in seconds
     */
    public static function heartbeatTimeout(array $config): int
    {
        $timeout = TypeHelper::toInt($config['maintenance_server']['heartbeat_timeout_seconds'] ?? null) ?? 5;
        return max(1, $timeout);
    }

    /**
     * Determine whether the public site requires the maintenance server.
     *
     * @param array $config Application configuration
     * @return bool True when the site should enter maintenance mode without the server
     */
    public static function isRequired(array $config): bool
    {
        return !empty($config['maintenance_server']['required']);
    }

    /**
     * Determine whether the local control socket is enabled.
     *
     * @param array $config Application configuration
     * @return bool True when runtime control is enabled
     */
    public static function controlEnabled(array $config): bool
    {
        return !empty($config['maintenance_server']['control']['enabled']);
    }

    /**
     * Determine the control server bind address.
     *
     * @param array $config Application configuration
     * @return string Bind address
     */
    public static function controlBindAddress(array $config): string
    {
        $address = TypeHelper::toString($config['maintenance_server']['control']['bind_address'] ?? '127.0.0.1', allowEmpty: true) ?? '127.0.0.1';
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
        $port = TypeHelper::toInt($config['maintenance_server']['control']['port'] ?? null) ?? 37991;
        return max(1, min(65535, $port));
    }

    /**
     * Return the list of allowed remote IPs for the control socket.
     *
     * @param array $config Application configuration
     * @return array<int, string> Allowed IP addresses
     */
    public static function controlAllowedIps(array $config): array
    {
        $allowed = $config['maintenance_server']['control']['allowed_ips'] ?? ['127.0.0.1', '::1'];
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
        return trim((string)($config['maintenance_server']['control']['auth_token'] ?? ''));
    }

    /**
     * Build the default runtime state for the maintenance server.
     *
     * @param array $config Application configuration
     * @return array<string, mixed>
     */
    public static function defaultRuntimeState(array $config): array
    {
        $jobs = $config['maintenance_server']['jobs'] ?? [];
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
            'maintenance_mode' => false,
            'verbose_logging' => !empty($config['maintenance_server']['verbose_logging']),
            'tick_interval_seconds' => max(1, TypeHelper::toInt($config['maintenance_server']['tick_interval_seconds'] ?? null) ?? 1),
            'log_retention_days' => max(1, TypeHelper::toInt($config['security']['log_retention_days'] ?? null) ?? 30),
            'jobs' => $normalizedJobs,
            'run_cleanup_now' => false,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
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
            $state['tick_interval_seconds'] = max(1, (int)$decoded['tick_interval_seconds']);
        }

        if (array_key_exists('log_retention_days', $decoded))
        {
            $state['log_retention_days'] = max(1, (int)$decoded['log_retention_days']);
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
            'maintenance_mode' => !empty($state['maintenance_mode']),
            'jobs' => is_array($state['jobs'] ?? null) ? $state['jobs'] : [],
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
     * Mark the maintenance server as stopped.
     *
     * Removing the heartbeat file allows the frontend to enter maintenance mode
     * immediately after a graceful shutdown.
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
     * Determine whether the maintenance server heartbeat is currently healthy.
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

        $lastHeartbeat = (int)$data['unix_time'];
        $timeout = self::heartbeatTimeout($config);

        return (time() - $lastHeartbeat) <= $timeout;
    }

    /**
     * Open the local control socket for the maintenance server.
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

        $address = self::controlBindAddress($config);
        $port = self::controlPort($config);
        $endpoint = 'tcp://' . $address . ':' . $port;
        $errno = 0;
        $errstr = '';

        $server = @stream_socket_server($endpoint, $errno, $errstr);
        if ($server === false)
        {
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

            $clientId = (int)$client;
            self::$controlClients[$clientId] = $client;
            self::$controlClientStates[$clientId] = [
                'peer_ip' => self::extractPeerIp((string)$peerName),
                'phase' => 'username',
                'username' => '',
                'authenticated' => false,
            ];

            @fwrite($client, "Image Gallery Maintenance Control\n");
            @fwrite($client, "Use JSON for automation or log in below for interactive commands.\n");
            @fwrite($client, "Username or JSON payload: ");
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
            $peerIp = (string)($clientState['peer_ip'] ?? '');

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
                    @fwrite($client, "serverctl> ");
                    @fflush($client);
                }

                continue;
            }

            $phase = (string)($clientState['phase'] ?? 'username');

            if ($phase === 'username')
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

                $clientState['username'] = $input;
                $clientState['phase'] = 'token';
                self::$controlClientStates[$clientId] = $clientState;

                @fwrite($client, "Token: ");
                @fflush($client);
                continue;
            }

            if ($phase === 'token')
            {
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

                $displayName = $clientState['username'] !== '' ? $clientState['username'] : 'operator';
                @fwrite($client, "Login successful. Welcome, {$displayName}.\n");
                @fwrite($client, "Type 'help' for commands. Type 'quit' to disconnect.\n\n");
                @fwrite($client, "serverctl> ");
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
                @fwrite($client, "serverctl> ");
                @fflush($client);
                continue;
            }

            $request = self::buildTextControlRequest($input, self::controlAuthToken($config));
            if ($request === null)
            {
                @fwrite($client, "Unknown or invalid command. Type 'help' for commands.\n");
                @fwrite($client, "serverctl> ");
                @fflush($client);
                continue;
            }

            $response = self::handleControlCommand($request, $config, $state);
            $payload = self::formatInteractiveControlResponse($response);

            @fwrite($client, $payload);
            @fwrite($client, "serverctl> ");
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
        $providedToken = trim((string)($request['token'] ?? ''));
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
        return implode("
", [
            'Available commands:',
            '  status',
            '  pause',
            '  resume',
            '  run-cleanup-now',
            '  maintenance-on',
            '  maintenance-off',
            '  set-maintenance-mode <0|1>',
            '  enable-job <job>',
            '  disable-job <job>',
            '  set-job <job> <0|1>',
            '  set-verbose <0|1>',
            '  set-tick-interval <seconds>',
            '  set-log-retention-days <days>',
            '  reload-defaults',
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
     * Interactive shell clients should receive aligned terminal output instead of
     * raw JSON so operators can quickly inspect runtime state.
     *
     * @param array<string, mixed> $response Control response payload
     * @return string Formatted response text
     */
    private static function formatInteractiveControlResponse(array $response): string
    {
        $lines = [];
        $lines[] = !empty($response['ok']) ? '[OK]' : '[ERROR]';

        $message = trim((string)($response['message'] ?? ''));
        if ($message !== '')
        {
            self::appendFormattedRow($lines, 'Message', $message);
        }

        $state = $response['state'] ?? null;
        if (!is_array($state))
        {
            return implode("
", $lines) . "
";
        }

        $lines[] = '';
        $lines[] = '  Runtime State';
        $lines[] = '  -------------';
        self::appendFormattedRow($lines, 'Paused', self::formatBooleanLabel($state['paused'] ?? false));
        self::appendFormattedRow($lines, 'Maintenance Mode', self::formatBooleanLabel($state['maintenance_mode'] ?? false));
        self::appendFormattedRow($lines, 'Verbose Logging', self::formatBooleanLabel($state['verbose_logging'] ?? false));
        self::appendFormattedRow($lines, 'Tick Interval', (string)((int)($state['tick_interval_seconds'] ?? 0)) . ' second(s)');
        self::appendFormattedRow($lines, 'Log Retention', (string)((int)($state['log_retention_days'] ?? 0)) . ' day(s)');
        self::appendFormattedRow($lines, 'Run Cleanup Now', self::formatBooleanLabel($state['run_cleanup_now'] ?? false));

        if (!empty($state['updated_at']))
        {
            self::appendFormattedRow($lines, 'Updated At', (string)$state['updated_at']);
        }

        $jobs = $state['jobs'] ?? [];
        if (is_array($jobs) && !empty($jobs))
        {
            $lines[] = '';
            $lines[] = '  Jobs:';

            foreach ($jobs as $job => $enabled)
            {
                self::appendFormattedRow($lines, (string)$job, self::formatBooleanLabel($enabled));
            }
        }

        return implode("
", $lines) . "
";
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
        $parts = str_getcsv($commandLine, ' ');
        if (empty($parts))
        {
            return null;
        }

        $command = strtolower(trim((string)($parts[0] ?? '')));
        $request = [
            'token' => $token,
            'command' => 'status',
        ];

        switch ($command)
        {
            case 'status':
                $request['command'] = 'status';
                break;

            case 'pause':
                $request['command'] = 'pause';
                break;

            case 'resume':
                $request['command'] = 'resume';
                break;

            case 'run-cleanup-now':
            case 'run_cleanup_now':
                $request['command'] = 'run_cleanup_now';
                break;

            case 'maintenance-on':
            case 'maintenance_on':
                $request['command'] = 'maintenance_on';
                break;

            case 'maintenance-off':
            case 'maintenance_off':
                $request['command'] = 'maintenance_off';
                break;

            case 'set-maintenance-mode':
            case 'set_maintenance_mode':
                if (!isset($parts[1]))
                {
                    return null;
                }

                $request['command'] = 'set_maintenance_mode';
                $request['enabled'] = self::textValueToBool((string)$parts[1]);
                break;

            case 'enable-job':
            case 'enable_job':
                if (empty($parts[1]))
                {
                    return null;
                }

                $request['command'] = 'enable_job';
                $request['job'] = trim((string)$parts[1]);
                break;

            case 'disable-job':
            case 'disable_job':
                if (empty($parts[1]))
                {
                    return null;
                }

                $request['command'] = 'disable_job';
                $request['job'] = trim((string)$parts[1]);
                break;

            case 'set-job':
            case 'set_job':
                if (empty($parts[1]) || !isset($parts[2]))
                {
                    return null;
                }

                $request['command'] = 'set_job';
                $request['job'] = trim((string)$parts[1]);
                $request['enabled'] = self::textValueToBool((string)$parts[2]);
                break;

            case 'set-verbose':
            case 'set_verbose':
                if (!isset($parts[1]))
                {
                    return null;
                }

                $request['command'] = 'set_verbose';
                $request['enabled'] = self::textValueToBool((string)$parts[1]);
                break;

            case 'set-tick-interval':
            case 'set_tick_interval':
                if (!isset($parts[1]))
                {
                    return null;
                }

                $request['command'] = 'set_tick_interval';
                $request['seconds'] = max(1, (int)$parts[1]);
                break;

            case 'set-log-retention-days':
            case 'set_log_retention_days':
                if (!isset($parts[1]))
                {
                    return null;
                }

                $request['command'] = 'set_log_retention_days';
                $request['days'] = max(1, (int)$parts[1]);
                break;

            case 'reload-defaults':
            case 'reload_defaults':
                $request['command'] = 'reload_defaults';
                break;

            default:
                return null;
        }

        return $request;
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

    private static function handleControlCommand(array $request, array $config, array &$state): array
    {
        $command = strtolower(trim((string)($request['command'] ?? 'status')));
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
                $response['message'] = 'Maintenance jobs paused.';
                break;

            case 'resume':
                $state['paused'] = false;
                $response['message'] = 'Maintenance jobs resumed.';
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

            case 'run_cleanup_now':
                $state['run_cleanup_now'] = true;
                $response['message'] = 'Cleanup run scheduled.';
                break;

            case 'set_verbose':
                $state['verbose_logging'] = !empty($request['enabled']);
                $response['message'] = 'Verbose logging updated.';
                break;

            case 'set_tick_interval':
                $state['tick_interval_seconds'] = max(1, (int)($request['seconds'] ?? 1));
                $response['message'] = 'Tick interval updated.';
                break;

            case 'set_log_retention_days':
                $state['log_retention_days'] = max(1, (int)($request['days'] ?? 30));
                $response['message'] = 'Log retention updated.';
                break;

            case 'enable_job':
            case 'disable_job':
            case 'set_job':
                $job = trim((string)($request['job'] ?? ''));
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
