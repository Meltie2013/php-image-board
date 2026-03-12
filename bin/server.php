<?php

require __DIR__ . '/../bootstrap/app.php';

/**
 * Background maintenance server and CLI control entry point for the Image Gallery.
 *
 * Server mode performs near-real-time housekeeping tasks:
 * - Remove expired application sessions from the database
 * - Remove expired request-guard counters and temporary blocks
 * - Remove old security logs based on retention days
 * - Remove expired image cache files from storage/cache/images
 * - Keep the required maintenance heartbeat alive for the public site
 * - Accept secure runtime control commands without restarting the process
 *
 * Control mode sends an authorized command to the running maintenance server.
 *
 * Usage examples:
 *   php server.php
 *   php server.php --interval=1
 *   php server.php --once
 *   php server.php --log-retention-days=30
 *   php server.php status
 *   php server.php pause
 *   php server.php resume
 *   php server.php set-tick-interval 2
 *   php server.php generate-token
 */

if (PHP_SAPI !== 'cli')
{
    fwrite(STDERR, "This script must be run from the command line.
");
    exit(1);
}

$rootDir = APP_ROOT;
$rawArgs = array_slice($argv, 1);
$configPath = CONFIG_PATH . '/config.php';

if (!is_file($configPath))
{
    $firstArgument = strtolower(trim($rawArgs[0] ?? ''));
    if (in_array($firstArgument, ['generate-token', 'help', '--help', '-h'], true))
    {
        if ($firstArgument === 'generate-token')
        {
            fwrite(STDOUT, substr(bin2hex(random_bytes(32)), 0, 64) . "
");
        }
        else
        {
            fwrite(STDOUT, "Usage:
  php server.php generate-token
  php server.php help

Create config/config.php from config/config.php.dist before starting server mode or sending control commands.
");
        }

        exit(0);
    }

    fwrite(STDERR, "Missing configuration file: {$configPath}
");
    exit(1);
}

$config = require $configPath;

DateHelper::init($config['timezone'] ?? 'UTC');
Database::init($config['db']);
SettingsManager::init($config);
$config = SettingsManager::getConfig();
Security::init($config['security'] ?? []);

/**
 * Print command usage information.
 *
 * @return void
 */
function server_print_usage(): void
{
    $usage = <<<TXT
Usage:
  php server.php
  php server.php --interval=1 [--once] [--log-retention-days=30]
  php server.php <command> [arguments] [--host=IP] [--port=PORT] [--token=TOKEN]
  php server.php generate-token

Commands:
  status

  jobs <job> <on|off>
  service <service> <on|off>
  task cleanup run

  maintenance pause
  maintenance resume
  maintenance mode <on|off>
  maintenance offline <on|off>
  maintenance verbose <on|off>
  maintenance interval <seconds>
  maintenance retention <days>
  maintenance reload-defaults

  Legacy commands are still accepted.
  generate-token

Examples:
  php server.php status
  php server.php jobs image_cache off
  php server.php service register off
  php server.php maintenance offline on
  php server.php maintenance mode on
  php server.php task cleanup run
TXT;

    fwrite(STDOUT, $usage . "
");
}

/**
 * Determine whether the provided arguments should run in control mode.
 *
 * @param array<int, string> $args Raw CLI arguments excluding script name
 * @return bool True when the arguments target a control command
 */
function server_should_run_control_mode(array $args): bool
{
    foreach ($args as $arg)
    {
        if ($arg === '')
        {
            continue;
        }

        if (str_starts_with($arg, '--'))
        {
            continue;
        }

        return true;
    }

    return false;
}

/**
 * Convert common user input values into a boolean state.
 *
 * @param string $value Raw value
 * @return bool Normalized boolean state
 */
function server_to_bool(string $value): bool
{
    return TypeHelper::toBool($value, false) ?? false;
}

/**
 * Send one control payload to the Control Server WebSocket endpoint.
 *
 * @param string $host WebSocket host
 * @param int $port WebSocket port
 * @param array $payload JSON payload to transmit
 * @return array<string, mixed> Decoded response payload
 */
function server_send_websocket_command(string $host, int $port, array $payload): array
{
    $endpoint = 'tcp://' . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $client = @stream_socket_client($endpoint, $errno, $errstr, 3);
    if ($client === false)
    {
        return [
            'ok' => false,
            'message' => 'Unable to connect to Control Server WebSocket: ' . $errstr,
        ];
    }

    stream_set_timeout($client, 3);
    $key = base64_encode(random_bytes(16));
    $request = "GET /control HTTP/1.1
"
        . 'Host: ' . $host . ':' . $port . "
"
        . "Upgrade: websocket
"
        . "Connection: Upgrade
"
        . 'Sec-WebSocket-Key: ' . $key . "
"
        . "Sec-WebSocket-Version: 13

";

    fwrite($client, $request);

    $responseHeaders = '';
    while (!str_contains($responseHeaders, "

"))
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

        $responseHeaders .= $chunk;
    }

    if (!str_starts_with($responseHeaders, 'HTTP/1.1 101'))
    {
        fclose($client);
        return [
            'ok' => false,
            'message' => 'Control Server WebSocket handshake failed.',
        ];
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false)
    {
        fclose($client);
        return [
            'ok' => false,
            'message' => 'Unable to encode WebSocket control payload.',
        ];
    }

    $length = strlen($json);
    $mask = random_bytes(4);
    $frame = chr(0x81);
    if ($length <= 125)
    {
        $frame .= chr(0x80 | $length);
    }
    elseif ($length <= 65535)
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

    fwrite($client, $frame . $mask . $maskedPayload);

    $firstTwo = fread($client, 2);
    if ($firstTwo === false || strlen($firstTwo) < 2)
    {
        fclose($client);
        return [
            'ok' => false,
            'message' => 'No WebSocket frame received from Control Server.',
        ];
    }

    $first = ord($firstTwo[0]);
    $second = ord($firstTwo[1]);
    $opcode = $first & 0x0F;
    $payloadLength = $second & 0x7F;
    if ($payloadLength === 126)
    {
        $extended = fread($client, 2);
        $payloadLength = is_string($extended) && strlen($extended) === 2 ? unpack('n', $extended)[1] : 0;
    }
    elseif ($payloadLength === 127)
    {
        $extended = fread($client, 8);
        $parts = is_string($extended) && strlen($extended) === 8 ? unpack('N2', $extended) : [1 => 0, 2 => 0];
        $payloadLength = ((int)$parts[1] << 32) + (int)$parts[2];
    }

    $responsePayload = '';
    while (strlen($responsePayload) < $payloadLength)
    {
        $chunk = fread($client, $payloadLength - strlen($responsePayload));
        if ($chunk === false || $chunk === '')
        {
            break;
        }

        $responsePayload .= $chunk;
    }

    fclose($client);

    if ($opcode !== 1)
    {
        return [
            'ok' => false,
            'message' => 'Unexpected WebSocket opcode received from Control Server.',
        ];
    }

    $response = json_decode(trim($responsePayload), true);
    if (!is_array($response))
    {
        return [
            'ok' => false,
            'message' => 'Invalid WebSocket response received from Control Server.',
        ];
    }

    return $response;
}

/**
 * Send one control payload to the maintenance server socket.
 *
 * @param string $host Control server host
 * @param int $port Control server port
 * @param array $payload JSON payload to transmit
 * @return array<string, mixed> Decoded response payload
 */
function server_send_command(string $host, int $port, array $payload): array
{
    $endpoint = 'tcp://' . $host . ':' . $port;
    $errno = 0;
    $errstr = '';

    $client = @stream_socket_client($endpoint, $errno, $errstr, 3);
    if ($client === false)
    {
        return [
            'ok' => false,
            'message' => 'Unable to connect to Control Server socket: ' . $errstr,
        ];
    }

    stream_set_timeout($client, 3);
    fwrite($client, json_encode($payload, JSON_UNESCAPED_SLASHES) . "
");
    $line = fgets($client, 65535);
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
 * Build a Control Server control payload from parsed command arguments.
 *
 * @param array<int, string> $positionals Command arguments
 * @param string $token Control authorization token
 * @return array<string, mixed>|null Payload on success; otherwise null
 */
function server_build_payload(array $positionals, string $token): ?array
{
    if (empty($positionals))
    {
        return null;
    }

    $command = strtolower(TypeHelper::toString($positionals[0] ?? '', allowEmpty: true) ?? 'status');
    $payload = [
        'token' => $token,
        'command' => 'status',
    ];

    switch ($command)
    {
        case 'status':
            return $payload;

        case 'jobs':
            $job = TypeHelper::toString($positionals[1] ?? '', allowEmpty: true) ?? '';
            $enabled = TypeHelper::toString($positionals[2] ?? '', allowEmpty: true) ?? '';
            if ($job === '' || $enabled === '')
            {
                fwrite(STDERR, "Missing job name or state.
");
                return null;
            }

            $payload['command'] = 'set_job';
            $payload['job'] = $job;
            $payload['enabled'] = server_to_bool($enabled);
            return $payload;

        case 'service':
            $service = TypeHelper::toString($positionals[1] ?? '', allowEmpty: true) ?? '';
            $enabled = TypeHelper::toString($positionals[2] ?? '', allowEmpty: true) ?? '';
            if ($service === '' || $enabled === '')
            {
                fwrite(STDERR, "Missing service name or state.
");
                return null;
            }

            $payload['command'] = 'set_service';
            $payload['service'] = $service;
            $payload['enabled'] = server_to_bool($enabled);
            return $payload;

        case 'task':
            $taskName = strtolower(TypeHelper::toString($positionals[1] ?? '', allowEmpty: true) ?? '');
            if ($taskName !== 'cleanup')
            {
                fwrite(STDERR, "Unknown task.
");
                return null;
            }

            $payload['command'] = 'run_cleanup_now';
            return $payload;

        case 'maintenance':
            $action = strtolower(TypeHelper::toString($positionals[1] ?? '', allowEmpty: true) ?? '');
            $value = TypeHelper::toString($positionals[2] ?? '', allowEmpty: true) ?? '';

            if ($action === 'pause')
            {
                $payload['command'] = 'pause';
                return $payload;
            }

            if ($action === 'resume')
            {
                $payload['command'] = 'resume';
                return $payload;
            }

            if ($action === 'mode' && $value !== '')
            {
                $payload['command'] = 'set_maintenance_mode';
                $payload['enabled'] = server_to_bool($value);
                return $payload;
            }

            if ($action === 'offline' && $value !== '')
            {
                $payload['command'] = 'set_site_online';
                $payload['enabled'] = !server_to_bool($value);
                return $payload;
            }

            if ($action === 'verbose' && $value !== '')
            {
                $payload['command'] = 'set_verbose';
                $payload['enabled'] = server_to_bool($value);
                return $payload;
            }

            if ($action === 'interval')
            {
                $seconds = TypeHelper::toInt($positionals[2] ?? null) ?? 0;
                if ($seconds <= 0)
                {
                    fwrite(STDERR, "Missing or invalid tick interval.
");
                    return null;
                }

                $payload['command'] = 'set_tick_interval';
                $payload['seconds'] = $seconds;
                return $payload;
            }

            if ($action === 'retention')
            {
                $days = TypeHelper::toInt($positionals[2] ?? null) ?? 0;
                if ($days <= 0)
                {
                    fwrite(STDERR, "Missing or invalid retention days.
");
                    return null;
                }

                $payload['command'] = 'set_log_retention_days';
                $payload['days'] = $days;
                return $payload;
            }

            if (in_array($action, ['reload-defaults', 'reload_defaults'], true))
            {
                $payload['command'] = 'reload_defaults';
                return $payload;
            }

            break;
    }

    switch ($command)
    {
        case 'pause':
        case 'resume':
            $payload['command'] = $command;
            return $payload;
        case 'run-cleanup-now':
        case 'run_cleanup_now':
            $payload['command'] = 'run_cleanup_now';
            return $payload;
        case 'maintenance-on':
        case 'maintenance_on':
            $payload['command'] = 'maintenance_on';
            return $payload;
        case 'maintenance-off':
        case 'maintenance_off':
            $payload['command'] = 'maintenance_off';
            return $payload;
        case 'set-maintenance-mode':
        case 'set_maintenance_mode':
            $payload['command'] = 'set_maintenance_mode';
            $payload['enabled'] = server_to_bool(TypeHelper::toString($positionals[1] ?? '', allowEmpty: true) ?? '');
            return $payload;
        case 'enable-job':
        case 'enable_job':
            $payload['command'] = 'enable_job';
            $payload['job'] = TypeHelper::toString($positionals[1] ?? '', allowEmpty: true) ?? '';
            return $payload;
        case 'disable-job':
        case 'disable_job':
            $payload['command'] = 'disable_job';
            $payload['job'] = TypeHelper::toString($positionals[1] ?? '', allowEmpty: true) ?? '';
            return $payload;
        case 'set-job':
        case 'set_job':
            $payload['command'] = 'set_job';
            $payload['job'] = TypeHelper::toString($positionals[1] ?? '', allowEmpty: true) ?? '';
            $payload['enabled'] = server_to_bool(TypeHelper::toString($positionals[2] ?? '', allowEmpty: true) ?? '');
            return $payload;
        case 'set-verbose':
        case 'set_verbose':
            $payload['command'] = 'set_verbose';
            $payload['enabled'] = server_to_bool(TypeHelper::toString($positionals[1] ?? '', allowEmpty: true) ?? '');
            return $payload;
        case 'set-tick-interval':
        case 'set_tick_interval':
            $payload['command'] = 'set_tick_interval';
            $payload['seconds'] = TypeHelper::toInt($positionals[1] ?? null) ?? 1;
            return $payload;
        case 'set-log-retention-days':
        case 'set_log_retention_days':
            $payload['command'] = 'set_log_retention_days';
            $payload['days'] = TypeHelper::toInt($positionals[1] ?? null) ?? 30;
            return $payload;
        case 'reload-defaults':
        case 'reload_defaults':
            $payload['command'] = 'reload_defaults';
            return $payload;
    }

    fwrite(STDERR, "Unknown command: {$command}
");
    return null;
}

/**
 * Convert a boolean-like value into a human-readable enabled/disabled string.
 *
 * @param mixed $value Raw value
 * @return string Human-readable value
 */
function server_format_bool(mixed $value): string
{
    return !empty($value) ? 'Enabled' : 'Disabled';
}

/**
 * Print one aligned label/value row to the console.
 *
 * @param string $label Row label
 * @param string $value Row value
 * @param int $labelWidth Fixed label width
 * @return void
 */
function server_print_row(string $label, string $value, int $labelWidth = 22): void
{
    fwrite(STDOUT, '  ' . str_pad($label . ':', $labelWidth, ' ', STR_PAD_RIGHT) . $value . "
");
}

/**
 * Print aligned job states to the console.
 *
 * @param array<string, mixed> $jobs Job state map
 * @return void
 */
function server_print_jobs(array $jobs): void
{
    fwrite(STDOUT, "  Jobs:
");

    foreach ($jobs as $job => $enabled)
    {
        server_print_row(TypeHelper::toString($job, allowEmpty: true) ?? '', server_format_bool($enabled), 24);
    }
}

/**
 * Print a formatted Control Server response payload.
 *
 * @param array<string, mixed> $response Control response payload
 * @return void
 */
function server_print_response(array $response): void
{
    $ok = !empty($response['ok']);
    $message = TypeHelper::toString($response['message'] ?? '', allowEmpty: true) ?? '';

    fwrite(STDOUT, "
");
    fwrite(STDOUT, $ok ? "[OK]
" : "[ERROR]
");

    if ($message !== '')
    {
        server_print_row('Message', $message);
    }

    $state = $response['state'] ?? null;
    if (!is_array($state))
    {
        fwrite(STDOUT, "
");
        return;
    }

    fwrite(STDOUT, "
");
    fwrite(STDOUT, "  Runtime State
");
    fwrite(STDOUT, "  -------------
");

    server_print_row('Paused', server_format_bool($state['paused'] ?? false));
    server_print_row('Site Online', server_format_bool($state['site_online'] ?? true));
    server_print_row('Maintenance Mode', server_format_bool($state['maintenance_mode'] ?? false));
    server_print_row('Verbose Logging', server_format_bool($state['verbose_logging'] ?? false));
    server_print_row('Tick Interval', (string)(TypeHelper::toInt($state['tick_interval_seconds'] ?? null) ?? 0) . ' second(s)');
    server_print_row('Log Retention', (string)(TypeHelper::toInt($state['log_retention_days'] ?? null) ?? 0) . ' day(s)');
    server_print_row('Run Cleanup Now', server_format_bool($state['run_cleanup_now'] ?? false));

    $updatedAt = TypeHelper::toString($state['updated_at'] ?? '', allowEmpty: true) ?? '';
    if ($updatedAt !== '')
    {
        server_print_row('Updated At', $updatedAt);
    }

    $services = $state['services'] ?? [];
    if (is_array($services) && !empty($services))
    {
        fwrite(STDOUT, "
");
        fwrite(STDOUT, "  Services:
");
        foreach ($services as $service => $enabled)
        {
            server_print_row((string)$service, server_format_bool($enabled));
        }
    }

    $jobs = $state['jobs'] ?? [];
    if (is_array($jobs) && !empty($jobs))
    {
        fwrite(STDOUT, "
");
        server_print_jobs($jobs);
    }

    fwrite(STDOUT, "
");
}

/**
 * Run control mode from the merged server.php entry point.
 *
 * @param array $config Application configuration
 * @param array<int, string> $args Raw CLI arguments excluding script name
 * @return int Process exit code
 */
function server_run_control_mode(array $config, array $args): int
{
    $host = ControlServer::webSocketEnabled($config) ? ControlServer::webSocketBindAddress($config) : ControlServer::controlBindAddress($config);
    if (in_array($host, ['0.0.0.0', '::', ''], true))
    {
        $host = '127.0.0.1';
    }

    $port = ControlServer::webSocketEnabled($config) ? ControlServer::webSocketPort($config) : ControlServer::controlPort($config);
    $token = ControlServer::controlAuthToken($config);
    $positionals = [];

    foreach ($args as $arg)
    {
        if (str_starts_with($arg, '--host='))
        {
            $value = TypeHelper::toString(substr($arg, strlen('--host=')), allowEmpty: true) ?? '';
            if ($value !== '')
            {
                $host = $value;
            }

            continue;
        }

        if (str_starts_with($arg, '--port='))
        {
            $value = TypeHelper::toInt(substr($arg, strlen('--port='))) ?? 0;
            if ($value > 0)
            {
                $port = $value;
            }

            continue;
        }

        if (str_starts_with($arg, '--token='))
        {
            $token = TypeHelper::toString(substr($arg, strlen('--token=')), allowEmpty: true) ?? '';
            continue;
        }

        $positionals[] = $arg;
    }

    if (!empty($positionals) && in_array(strtolower($positionals[0]), ['help', '--help', '-h'], true))
    {
        server_print_usage();
        return 0;
    }

    if (!empty($positionals) && strtolower($positionals[0]) === 'generate-token')
    {
        fwrite(STDOUT, ControlServer::generateControlToken() . "
");
        return 0;
    }

    $payload = server_build_payload($positionals, $token);
    if ($payload === null)
    {
        server_print_usage();
        return 1;
    }

    if ($token === '')
    {
        fwrite(STDERR, "Missing Control Server auth token in config or --token override.
");
        return 1;
    }

    $response = ControlServer::webSocketEnabled($config)
        ? server_send_websocket_command($host, $port, $payload)
        : server_send_command($host, $port, $payload);
    server_print_response($response);
    return !empty($response['ok']) ? 0 : 1;
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
 * }
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
        $rateCountersRemoved = TypeHelper::toInt($guardResult['counters'] ?? null) ?? 0;
        $blocksRemoved = TypeHelper::toInt($guardResult['blocks'] ?? null) ?? 0;
    }

    if (!empty($state['jobs']['security_logs']))
    {
        $logsRemoved = Security::cleanExpired(TypeHelper::toInt($state['log_retention_days'] ?? null) ?? 30);
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

$args = $rawArgs;
if (server_should_run_control_mode($args))
{
    exit(server_run_control_mode($config, $args));
}

$interval = 1;
$runOnce = false;
$logRetentionDays = TypeHelper::toInt($config['security']['log_retention_days'] ?? null) ?? 30;
foreach ($args as $arg)
{
    if ($arg === '--once')
    {
        $runOnce = true;
        continue;
    }

    if (str_starts_with($arg, '--interval='))
    {
        $value = TypeHelper::toInt(substr($arg, strlen('--interval='))) ?? 0;
        if ($value > 0)
        {
            $interval = $value;
        }

        continue;
    }

    if (str_starts_with($arg, '--log-retention-days='))
    {
        $value = TypeHelper::toInt(substr($arg, strlen('--log-retention-days='))) ?? 0;
        if ($value > 0)
        {
            $logRetentionDays = $value;
        }
    }
}

$state = ControlServer::loadRuntimeState($config);
$state['tick_interval_seconds'] = max(1, TypeHelper::toInt($state['tick_interval_seconds'] ?? null) ?? $interval);
$state['log_retention_days'] = max(1, TypeHelper::toInt($state['log_retention_days'] ?? null) ?? $logRetentionDays);
ControlServer::saveRuntimeState($config, $state);
ControlServer::writeHeartbeat($config, $state);
ControlServer::writeLiveEventsHeartbeat($config, $state, false);
ControlServer::broadcastLiveEventChanges($config);

$controlServer = ControlServer::openControlSocket($config);
if (ControlServer::controlEnabled($config) && $controlServer === false)
{
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . "] Failed to bind Control Server socket.
");
}

$webSocketServer = ControlServer::openWebSocketServer($config);
if (ControlServer::webSocketEnabled($config) && $webSocketServer === false)
{
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . "] Failed to bind Control Server WebSocket server.
");
}

$running = true;
if (function_exists('pcntl_async_signals'))
{
    pcntl_async_signals(true);

    $stop = function (int $signal) use (&$running, $config): void
    {
        $running = false;
        $timestamp = gmdate('Y-m-d H:i:s');
        ControlServer::clearHeartbeat($config);
        ControlServer::clearLiveEvents($config);
        fwrite(STDOUT, "[{$timestamp}] Received signal {$signal}; shutting down.
");
    };

    pcntl_signal(SIGINT, $stop);
    pcntl_signal(SIGTERM, $stop);
}

fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . "] Control Server started (interval: {$state['tick_interval_seconds']}s, log retention: {$state['log_retention_days']} days, websocket port: " . (ControlServer::webSocketEnabled($config) ? (string)ControlServer::webSocketPort($config) : 'disabled') . ").
");

do
{
    try
    {
        $responses = ControlServer::processControlCommands($controlServer, $config, $state);
        $responses = array_merge($responses, ControlServer::processWebSocketConnections($webSocketServer, $config, $state));

        /*
         * Write the heartbeat immediately after processing control commands so
         * maintenance-mode toggles and other runtime state changes are visible
         * to the public site without waiting for the next loop tick.
         */
        if (!empty($responses))
        {
            ControlServer::writeHeartbeat($config, $state);
            ControlServer::writeLiveEventsHeartbeat($config, $state);
            ControlServer::broadcastLiveEventChanges($config);
        }

        if (!empty($responses) && !empty($state['verbose_logging']))
        {
            foreach ($responses as $response)
            {
                $message = TypeHelper::toString($response['message'] ?? 'OK', allowEmpty: true) ?? 'OK';
                fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . '] Control command processed: ' . $message . "
");
            }
        }

        /*
         * Keep the heartbeat fresh every loop, even when no commands were
         * received, so the site can reliably detect that the backend process is
         * still alive.
         */
        ControlServer::writeHeartbeat($config, $state);
        ControlServer::writeLiveEventsHeartbeat($config, $state);
        ControlServer::broadcastLiveEventChanges($config);

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
                    ', expired cache files removed: ' . $result['cache_removed'] . "
"
                );
            }

            if (!empty($state['run_cleanup_now']))
            {
                $state['run_cleanup_now'] = false;
                ControlServer::saveRuntimeState($config, $state);
                ControlServer::writeHeartbeat($config, $state);
                ControlServer::writeLiveEventsHeartbeat($config, $state);
                ControlServer::broadcastLiveEventChanges($config);
            }
        }
    }
    catch (Throwable $e)
    {
        fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . '] Control Server pass failed: ' . $e->getMessage() . "
");
    }

    if ($runOnce || !$running)
    {
        break;
    }

    $sleepSeconds = max(1, TypeHelper::toInt($state['tick_interval_seconds'] ?? null) ?? 1);
    sleep($sleepSeconds);
} while ($running);

if (is_resource($controlServer))
{
    fclose($controlServer);
}

if (isset($webSocketServer) && is_resource($webSocketServer))
{
    fclose($webSocketServer);
}

ControlServer::clearHeartbeat($config);
ControlServer::clearLiveEvents($config);
fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . "] Control Server stopped.
");
