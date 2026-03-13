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
     * Return one nested configuration value from the active Control Server block.
     *
     * @param array $config Application configuration
     * @param array<int, string> $path Nested key path
     * @param mixed $default Default value when the path is missing
     * @return mixed
     */
    private static function configValue(array $config, array $path, mixed $default = null): mixed
    {
        $value = self::configBlock($config);

        foreach ($path as $segment)
        {
            if (!is_array($value) || !array_key_exists($segment, $value))
            {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Return one nested configuration value normalized as a string.
     *
     * @param array $config Application configuration
     * @param array<int, string> $path Nested key path
     * @param string $default Default value when the path is missing
     * @return string Normalized string value
     */
    private static function configString(array $config, array $path, string $default = ''): string
    {
        $value = TypeHelper::toString(self::configValue($config, $path, $default), allowEmpty: true) ?? $default;
        return $value !== '' ? $value : $default;
    }

    /**
     * Return one nested configuration value normalized as an integer.
     *
     * @param array $config Application configuration
     * @param array<int, string> $path Nested key path
     * @param int $default Default value when the path is missing
     * @return int Normalized integer value
     */
    private static function configInt(array $config, array $path, int $default): int
    {
        return TypeHelper::toInt(self::configValue($config, $path, $default)) ?? $default;
    }

    /**
     * Build and open one non-blocking socket server endpoint.
     *
     * @param string $address Bind address
     * @param int $port Bind port
     * @param string $serverLabel Human-readable server label for error messages
     * @return resource|false Socket server resource on success; otherwise false
     */
    private static function createSocketServer(string $address, int $port, string $serverLabel)
    {
        $endpoint = self::buildSocketEndpoint($address, $port);
        if ($endpoint === null)
        {
            error_log($serverLabel . ' not opened: invalid bind address or port.');
            return false;
        }

        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server($endpoint, $errno, $errstr);
        if ($server === false)
        {
            error_log($serverLabel . ' bind failed for ' . $endpoint . ' (' . $errno . '): ' . $errstr);
            return false;
        }

        stream_set_blocking($server, false);
        return $server;
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
     * Maximum accepted control line length for interactive socket clients.
     */
    private const MAX_CONTROL_LINE_BYTES = 8192;

    /**
     * Maximum accepted WebSocket handshake header size.
     */
    private const MAX_WEBSOCKET_HEADER_BYTES = 16384;

    /**
     * Maximum accepted WebSocket frame payload size.
     */
    private const MAX_WEBSOCKET_FRAME_BYTES = 65535;

    /**
     * Maximum accepted image hash length for live subscriptions.
     */
    private const MAX_IMAGE_HASH_LENGTH = 128;

    /**
     * Socket timeout used by one-off CLI control clients.
     */
    private const CLIENT_SOCKET_TIMEOUT_SECONDS = 3;

    /**
     * Determine the absolute heartbeat file path.
     *
     * @param array $config Application configuration
     * @return string Absolute heartbeat file path
     */
    public static function heartbeatPath(array $config): string
    {
        return APP_ROOT . '/' . ltrim(self::configString($config, ['heartbeat_file'], self::DEFAULT_HEARTBEAT_FILE), '/');
    }

    /**
     * Determine the absolute runtime state file path.
     *
     * @param array $config Application configuration
     * @return string Absolute runtime state file path
     */
    public static function statePath(array $config): string
    {
        return APP_ROOT . '/' . ltrim(self::configString($config, ['state_file'], self::DEFAULT_STATE_FILE), '/');
    }

    /**
     * Determine how long a heartbeat remains valid.
     *
     * @param array $config Application configuration
     * @return int Heartbeat timeout in seconds
     */
    public static function heartbeatTimeout(array $config): int
    {
        return max(1, self::configInt($config, ['heartbeat_timeout_seconds'], 5));
    }

    /**
     * Determine whether the public site requires the Control Server.
     *
     * @param array $config Application configuration
     * @return bool True when the site should enter maintenance mode without the server
     */
    public static function isRequired(array $config): bool
    {
        return !empty(self::configValue($config, ['required']));
    }

    /**
     * Determine whether the local control socket is enabled.
     *
     * @param array $config Application configuration
     * @return bool True when runtime control is enabled
     */
    public static function controlEnabled(array $config): bool
    {
        return !empty(self::configValue($config, ['control', 'enabled']));
    }

    /**
     * Determine the control server bind address.
     *
     * @param array $config Application configuration
     * @return string Bind address
     */
    public static function controlBindAddress(array $config): string
    {
        return self::configString($config, ['control', 'bind_address'], '127.0.0.1');
    }

    /**
     * Determine the control server port.
     *
     * @param array $config Application configuration
     * @return int Port number
     */
    public static function controlPort(array $config): int
    {
        return max(1, min(65535, self::configInt($config, ['control', 'port'], 37991)));
    }

    /**
     * Determine whether the WebSocket server is enabled.
     *
     * @param array $config Application configuration
     * @return bool True when WebSocket support is enabled
     */
    public static function webSocketEnabled(array $config): bool
    {
        $webSocket = self::configValue($config, ['websocket'], []);
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
        $allowRemoteClients = !empty(self::configValue($config, ['websocket', 'allow_remote_clients']));
        $defaultAddress = $allowRemoteClients ? '0.0.0.0' : '127.0.0.1';
        return self::configString($config, ['websocket', 'bind_address'], $defaultAddress);
    }

    /**
     * Determine the WebSocket server port.
     *
     * @param array $config Application configuration
     * @return int Port number
     */
    public static function webSocketPort(array $config): int
    {
        $defaultPort = self::controlPort($config) + 1;
        return max(1, min(65535, self::configInt($config, ['websocket', 'port'], $defaultPort)));
    }

    /**
     * Determine whether remote browser WebSocket clients are allowed.
     *
     * @param array $config Application configuration
     * @return bool True when remote clients are allowed
     */
    public static function webSocketAllowRemoteClients(array $config): bool
    {
        return !empty(self::configValue($config, ['websocket', 'allow_remote_clients']));
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
            if ($value === '' || filter_var($value, FILTER_VALIDATE_IP) === false)
            {
                continue;
            }

            $normalized[] = $value;
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
        return self::configString($config, ['control', 'auth_token'], '');
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
     * Normalize a client host override for control socket and WebSocket calls.
     *
     * Accepts IPv4, IPv6, and standard DNS host names.
     *
     * @param string $host Raw host value
     * @return string|null Normalized host on success; otherwise null
     */
    public static function normalizeClientHost(string $host): ?string
    {
        $host = trim($host);
        if ($host === '')
        {
            return null;
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']'))
        {
            $host = substr($host, 1, -1);
        }

        if ($host === '')
        {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false)
        {
            return $host;
        }

        if (preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?))*$/', $host) === 1)
        {
            return strtolower($host);
        }

        return null;
    }

    /**
     * Determine the public browser WebSocket path.
     *
     * @param array $config Application configuration
     * @return string Public WebSocket path beginning with /
     */
    public static function webSocketPublicPath(array $config): string
    {
        $path = TypeHelper::toString(self::configBlock($config)['websocket']['public_path'] ?? '/gallery-live', allowEmpty: true)
            ?? '/gallery-live';

        if ($path === '')
        {
            $path = '/gallery-live';
        }

        if (!str_starts_with($path, '/'))
        {
            $path = '/' . $path;
        }

        return $path;
    }

    /**
     * Forward one wrapper CLI invocation to bin/server.php.
     *
     * @param array<int, string> $args CLI arguments excluding the wrapper script name
     * @param string $serverScriptPath Absolute or relative path to bin/server.php
     * @return int Process exit code
     */
    public static function forwardServerCommand(array $args, string $serverScriptPath): int
    {
        $scriptPath = realpath($serverScriptPath) ?: $serverScriptPath;
        if (!is_file($scriptPath))
        {
            fwrite(STDERR, "Unable to locate server.php.\n");
            return 1;
        }

        $phpBinary = TypeHelper::toString(PHP_BINARY, allowEmpty: true) ?? 'php';
        $command = array_merge([$phpBinary, $scriptPath], $args);
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => STDIN,
                1 => STDOUT,
                2 => STDERR,
            ],
            $pipes,
            dirname($scriptPath)
        );

        if (!is_resource($process))
        {
            fwrite(STDERR, "Unable to forward command to server.php.\n");
            return 1;
        }

        return proc_close($process);
    }

    /**
     * Send one control payload to the Control Server socket.
     *
     * @param string $host Control server host
     * @param int $port Control server port
     * @param array<string, mixed> $payload JSON payload to transmit
     * @return array<string, mixed> Decoded response payload
     */
    public static function sendSocketCommand(string $host, int $port, array $payload): array
    {
        $endpoint = self::buildSocketEndpoint($host, $port);
        if ($endpoint === null)
        {
            return [
                'ok' => false,
                'message' => 'Invalid Control Server socket endpoint.',
            ];
        }

        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client($endpoint, $errno, $errstr, self::CLIENT_SOCKET_TIMEOUT_SECONDS);
        if ($client === false)
        {
            return [
                'ok' => false,
                'message' => 'Unable to connect to Control Server socket: ' . $errstr,
            ];
        }

        stream_set_timeout($client, self::CLIENT_SOCKET_TIMEOUT_SECONDS);

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false)
        {
            fclose($client);

            return [
                'ok' => false,
                'message' => 'Unable to encode Control Server socket payload.',
            ];
        }

        if (@fwrite($client, $json . "") === false)
        {
            fclose($client);

            return [
                'ok' => false,
                'message' => 'Unable to write to Control Server socket.',
            ];
        }

        $line = fgets($client, self::MAX_CONTROL_LINE_BYTES + 1);
        fclose($client);

        if ($line === false)
        {
            return [
                'ok' => false,
                'message' => 'No response received from Control Server socket.',
            ];
        }

        $response = json_decode(trim($line), true);
        if (!is_array($response))
        {
            return [
                'ok' => false,
                'message' => 'Invalid response received from Control Server socket.',
            ];
        }

        return $response;
    }

    /**
     * Send one control payload to the Control Server WebSocket endpoint.
     *
     * @param string $host WebSocket host
     * @param int $port WebSocket port
     * @param array<string, mixed> $payload JSON payload to transmit
     * @return array<string, mixed> Decoded response payload
     */
    public static function sendWebSocketCommand(string $host, int $port, array $payload): array
    {
        $endpoint = self::buildSocketEndpoint($host, $port);
        if ($endpoint === null)
        {
            return [
                'ok' => false,
                'message' => 'Invalid Control Server WebSocket endpoint.',
            ];
        }

        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client($endpoint, $errno, $errstr, self::CLIENT_SOCKET_TIMEOUT_SECONDS);
        if ($client === false)
        {
            return [
                'ok' => false,
                'message' => 'Unable to connect to Control Server WebSocket: ' . $errstr,
            ];
        }

        stream_set_timeout($client, self::CLIENT_SOCKET_TIMEOUT_SECONDS);

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false)
        {
            fclose($client);

            return [
                'ok' => false,
                'message' => 'Unable to encode WebSocket control payload.',
            ];
        }

        $normalizedHost = self::normalizeClientHost($host);
        if ($normalizedHost === null)
        {
            fclose($client);

            return [
                'ok' => false,
                'message' => 'Invalid Control Server WebSocket host.',
            ];
        }

        $key = base64_encode(random_bytes(16));
        $request = "GET /control HTTP/1.1\r\n"
            . 'Host: ' . self::formatHostHeader($normalizedHost, $port) . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . $key . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";

        if (@fwrite($client, $request) === false)
        {
            fclose($client);

            return [
                'ok' => false,
                'message' => 'Unable to send WebSocket handshake request to Control Server.',
            ];
        }

        $handshakeBuffer = '';
        while (!str_contains($handshakeBuffer, "\r\n\r\n"))
        {
            $chunk = fread($client, 2048);
            if ($chunk === false || $chunk === '')
            {
                fclose($client);

                return [
                    'ok' => false,
                    'message' => 'No WebSocket handshake response received from Control Server.',
                ];
            }

            $handshakeBuffer .= $chunk;
            if (strlen($handshakeBuffer) > self::MAX_WEBSOCKET_HEADER_BYTES)
            {
                fclose($client);

                return [
                    'ok' => false,
                    'message' => 'WebSocket handshake response from Control Server is too large.',
                ];
            }
        }

        [$responseHeaders, $responseRemainder] = array_pad(explode("\r\n\r\n", $handshakeBuffer, 2), 2, '');
        if (!self::isValidWebSocketHandshakeResponse($responseHeaders, $key))
        {
            fclose($client);

            return [
                'ok' => false,
                'message' => 'Control Server WebSocket handshake failed.',
            ];
        }

        $length = strlen($json);
        $mask = random_bytes(4);
        $frame = chr(0x81);

        if ($length <= 125)
        {
            $frame .= chr(0x80 | $length);
        }
        else if ($length <= 65535)
        {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        }
        else
        {
            $frame .= chr(0x80 | 127) . pack('NN', 0, $length);
        }

        $maskedPayload = '';
        for ($i = 0; $i < $length; $i++)
        {
            $maskedPayload .= $json[$i] ^ $mask[$i % 4];
        }

        if (@fwrite($client, $frame . $mask . $maskedPayload) === false)
        {
            fclose($client);

            return [
                'ok' => false,
                'message' => 'Unable to write WebSocket frame to Control Server.',
            ];
        }

        $responseBuffer = $responseRemainder;
        while (true)
        {
            $frame = self::decodeWebSocketFrame($responseBuffer);
            if ($frame !== null)
            {
                $opcode = TypeHelper::toInt($frame['opcode'] ?? null) ?? 1;
                if ($opcode !== 1)
                {
                    fclose($client);

                    return [
                        'ok' => false,
                        'message' => 'Unexpected WebSocket opcode received from Control Server.',
                    ];
                }

                $response = json_decode(trim(TypeHelper::toString($frame['payload'] ?? '', allowEmpty: true) ?? ''), true);
                fclose($client);

                if (!is_array($response))
                {
                    return [
                        'ok' => false,
                        'message' => 'Invalid WebSocket response received from Control Server.',
                    ];
                }

                return $response;
            }

            $chunk = fread($client, 2048);
            if ($chunk === false || $chunk === '')
            {
                fclose($client);

                return [
                    'ok' => false,
                    'message' => 'No WebSocket frame received from Control Server.',
                ];
            }

            $responseBuffer .= $chunk;
            if (strlen($responseBuffer) > self::MAX_WEBSOCKET_FRAME_BYTES + self::MAX_WEBSOCKET_HEADER_BYTES)
            {
                fclose($client);

                return [
                    'ok' => false,
                    'message' => 'WebSocket response from Control Server is too large.',
                ];
            }
        }
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
            'gallery_page_tokens' => !isset($jobs['gallery_page_tokens']) || !empty($jobs['gallery_page_tokens']),
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
        return APP_ROOT . '/' . ltrim(self::configString($config, ['live_events_file'], self::DEFAULT_LIVE_EVENTS_FILE), '/');
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

        $address = self::controlBindAddress($config);
        $port = self::controlPort($config);
        $allowRemoteControl = !empty(self::configValue($config, ['control', 'allow_remote_control']));
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

        return self::createSocketServer($address, $port, 'Control Server socket');
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

            $line = fgets($client, self::MAX_CONTROL_LINE_BYTES + 1);
            if ($line === false)
            {
                continue;
            }

            if (strlen($line) > self::MAX_CONTROL_LINE_BYTES || (strlen($line) === self::MAX_CONTROL_LINE_BYTES && !str_ends_with($line, "\n")))
            {
                @fwrite($client, "Input exceeded the maximum allowed command length.\n");
                self::closeControlClient($clientId);
                $responses[] = [
                    'ok' => false,
                    'message' => 'Interactive control input exceeded the maximum allowed length.',
                ];
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
                else if ($command === 'set_job')
                {
                    $enabled = !empty($request['enabled']);
                }

                if (!self::isKnownJob($job, $config, $state))
                {
                    return [
                        'ok' => false,
                        'message' => 'Unknown job name.',
                    ];
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
                else if ($command === 'set_service')
                {
                    $enabled = !empty($request['enabled']);
                }

                if (!self::isKnownService($service, $config, $state))
                {
                    return [
                        'ok' => false,
                        'message' => 'Unknown service name.',
                    ];
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

        return self::createSocketServer($address, $port, 'Control Server WebSocket');
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
                if (strlen($clientState['buffer']) > self::MAX_WEBSOCKET_HEADER_BYTES)
                {
                    self::closeWebSocketClient($clientId);
                    continue;
                }

                if (!str_contains($clientState['buffer'], "\r\n\r\n") && !str_contains($clientState['buffer'], "\n\n"))
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
                $masked = !empty($frame['masked']);

                if (!$masked)
                {
                    self::closeWebSocketClient($clientId);
                    continue 2;
                }

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
        $normalizedRequest = str_replace("\r\n", "\n", $request);
        [$headerBlock, $remaining] = array_pad(explode("\n\n", $normalizedRequest, 2), 2, '');
        $lines = preg_split("/\n/", $headerBlock) ?: [];
        $requestLine = TypeHelper::toString($lines[0] ?? '', allowEmpty: true) ?? '';
        if ($requestLine === '')
        {
            return false;
        }

        if (!preg_match('#^GET\s+([^\s]+)\s+HTTP/1\.[01]$#i', $requestLine, $matches))
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

        $upgrade = strtolower(TypeHelper::toString($headers['upgrade'] ?? '', allowEmpty: true) ?? '');
        $connection = strtolower(TypeHelper::toString($headers['connection'] ?? '', allowEmpty: true) ?? '');
        $version = TypeHelper::toString($headers['sec-websocket-version'] ?? '', allowEmpty: true) ?? '';
        $key = TypeHelper::toString($headers['sec-websocket-key'] ?? '', allowEmpty: true) ?? '';
        if ($upgrade !== 'websocket' || !str_contains($connection, 'upgrade') || $version !== '13' || !self::isValidWebSocketKey($key))
        {
            return false;
        }

        $peerIp = TypeHelper::toString($clientState['peer_ip'] ?? '', allowEmpty: true) ?? '';
        $publicPath = self::webSocketPublicPath($config);
        $mode = $path === '/control' ? 'control' : 'browser';
        if (!in_array($path, ['/control', $publicPath], true))
        {
            return false;
        }

        if ($mode === 'control' && !self::isAllowedControlIp($peerIp, $config))
        {
            return false;
        }

        if ($mode === 'browser' && !self::webSocketAllowRemoteClients($config) && !in_array($peerIp, ['127.0.0.1', '::1'], true))
        {
            return false;
        }

        if ($mode === 'browser' && !self::isAllowedWebSocketOrigin(TypeHelper::toString($headers['origin'] ?? '', allowEmpty: true) ?? '', $headers, $config))
        {
            return false;
        }

        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n\r\n";

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
        else if ($payloadLength === 127)
        {
            if ($length < 10)
            {
                return null;
            }

            $parts = unpack('N2', substr($buffer, 2, 8));
            $payloadLength = ((int)$parts[1] << 32) + (int)$parts[2];
            $offset = 10;
        }

        if ($payloadLength > self::MAX_WEBSOCKET_FRAME_BYTES)
        {
            return [
                'opcode' => 8,
                'payload' => '',
                'remaining' => '',
                'masked' => $masked,
            ];
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
            'masked' => $masked,
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
        else if ($length <= 65535)
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

            if (!self::isValidImageHash($imageHash))
            {
                return [
                    'ok' => false,
                    'message' => 'Invalid image subscription hash.',
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
     * Build a TCP socket endpoint for IPv4, IPv6, or DNS host names.
     *
     * @param string $host Host name or IP address
     * @param int $port TCP port
     * @return string|null Endpoint on success; otherwise null
     */
    private static function buildSocketEndpoint(string $host, int $port): ?string
    {
        $normalizedHost = self::normalizeClientHost($host);
        if ($normalizedHost === null || $port < 1 || $port > 65535)
        {
            return null;
        }

        return 'tcp://' . self::formatSocketEndpointHost($normalizedHost) . ':' . $port;
    }

    /**
     * Format one normalized host for a TCP endpoint.
     *
     * @param string $host Normalized host
     * @return string Endpoint-safe host value
     */
    private static function formatSocketEndpointHost(string $host): string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '[' . $host . ']'
            : $host;
    }

    /**
     * Format one normalized host for an HTTP Host header.
     *
     * @param string $host Normalized host
     * @param int $port TCP port
     * @return string Host header value
     */
    private static function formatHostHeader(string $host, int $port): string
    {
        return self::formatSocketEndpointHost($host) . ':' . $port;
    }

    /**
     * Validate one WebSocket response handshake block.
     *
     * @param string $headerBlock Raw response headers without the trailing separator
     * @param string $key Original Sec-WebSocket-Key used by the client
     * @return bool True when the response is valid
     */
    private static function isValidWebSocketHandshakeResponse(string $headerBlock, string $key): bool
    {
        $normalized = str_replace("\r\n", "\n", $headerBlock);
        $lines = preg_split("/\n/", $normalized) ?: [];
        $statusLine = TypeHelper::toString($lines[0] ?? '', allowEmpty: true) ?? '';
        if (!str_starts_with($statusLine, 'HTTP/1.1 101') && !str_starts_with($statusLine, 'HTTP/1.0 101'))
        {
            return false;
        }

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

        $accept = TypeHelper::toString($headers['sec-websocket-accept'] ?? '', allowEmpty: true) ?? '';
        $upgrade = strtolower(TypeHelper::toString($headers['upgrade'] ?? '', allowEmpty: true) ?? '');
        $connection = strtolower(TypeHelper::toString($headers['connection'] ?? '', allowEmpty: true) ?? '');
        $expectedAccept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        return $upgrade === 'websocket'
            && str_contains($connection, 'upgrade')
            && $accept !== ''
            && hash_equals($expectedAccept, $accept);
    }

    /**
     * Browser-origin policy for the public WebSocket endpoint.
     *
     * The Origin header must be present and must match either the configured
     * public host or, when that is not set, the Host header used for the
     * handshake. This keeps browser subscriptions same-origin by default while
     * still allowing explicit public_host overrides.
     *
     * @param string $origin Raw Origin header value
     * @param array $headers Parsed request headers
     * @param array $config Application configuration
     * @return bool True when the origin is allowed
     */
    private static function isAllowedWebSocketOrigin(string $origin, array $headers, array $config): bool
    {
        $origin = trim($origin);
        if ($origin === '')
        {
            return false;
        }

        $originHost = self::normalizeClientHost(TypeHelper::toString(parse_url($origin, PHP_URL_HOST) ?? '', allowEmpty: true) ?? '');
        if ($originHost === null)
        {
            return false;
        }

        $allowedHosts = [];

        $configuredHost = TypeHelper::toString(self::configBlock($config)['websocket']['public_host'] ?? '', allowEmpty: true) ?? '';
        if ($configuredHost !== '')
        {
            $normalizedConfiguredHost = self::normalizeClientHost($configuredHost);
            if ($normalizedConfiguredHost !== null)
            {
                $allowedHosts[$normalizedConfiguredHost] = true;
            }
        }

        $requestHost = TypeHelper::toString($headers['host'] ?? '', allowEmpty: true) ?? '';
        if ($requestHost !== '')
        {
            $requestHost = preg_replace('/:\d+$/', '', $requestHost) ?: '';
            $normalizedRequestHost = self::normalizeClientHost($requestHost);
            if ($normalizedRequestHost !== null)
            {
                $allowedHosts[$normalizedRequestHost] = true;
            }
        }

        if (empty($allowedHosts))
        {
            return false;
        }

        return isset($allowedHosts[$originHost]);
    }

    /**
     * Determine whether a Sec-WebSocket-Key header is valid.
     *
     * @param string $key Raw header value
     * @return bool True when the key is a valid 16-byte base64 value
     */
    private static function isValidWebSocketKey(string $key): bool
    {
        if ($key === '')
        {
            return false;
        }

        $decoded = base64_decode($key, true);
        return is_string($decoded) && strlen($decoded) === 16;
    }

    /**
     * Determine whether one image hash is safe to use for live subscriptions.
     *
     * @param string $imageHash Raw image hash
     * @return bool True when the hash uses the expected character set and length
     */
    private static function isValidImageHash(string $imageHash): bool
    {
        $imageHash = trim($imageHash);
        return $imageHash !== ''
            && strlen($imageHash) <= self::MAX_IMAGE_HASH_LENGTH
            && preg_match('/^[A-Fa-f0-9]{32,128}$/', $imageHash) === 1;
    }

    /**
     * Determine whether one job name exists in the runtime state.
     *
     * @param string $job Job name
     * @param array $config Application configuration
     * @param array $state Runtime state
     * @return bool True when the job exists
     */
    private static function isKnownJob(string $job, array $config, array $state): bool
    {
        $job = TypeHelper::toString($job, allowEmpty: true) ?? '';
        if ($job === '')
        {
            return false;
        }

        $jobs = $state['jobs'] ?? self::defaultRuntimeState($config)['jobs'];
        return is_array($jobs) && array_key_exists($job, $jobs);
    }

    /**
     * Determine whether one service name exists in the runtime state.
     *
     * @param string $service Service name
     * @param array $config Application configuration
     * @param array $state Runtime state
     * @return bool True when the service exists
     */
    private static function isKnownService(string $service, array $config, array $state): bool
    {
        $service = TypeHelper::toString($service, allowEmpty: true) ?? '';
        if ($service === '')
        {
            return false;
        }

        $services = $state['services'] ?? self::defaultServices($config);
        return is_array($services) && array_key_exists($service, $services);
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
