<?php

/**
 * Maintenance server control client for the Image Gallery.
 *
 * Run from the command line to send authorized control commands to the
 * long-running server.php process without restarting it.
 *
 * Usage examples:
 *   php serverctl.php
 *   php serverctl.php status
 *   php serverctl.php pause
 *   php serverctl.php resume
 *   php serverctl.php run-cleanup-now
 *   php serverctl.php enable-job image_cache
 *   php serverctl.php disable-job security_logs
 *   php serverctl.php set-job live_chat 1
 *   php serverctl.php set-verbose 1
 *   php serverctl.php set-tick-interval 2
 *   php serverctl.php set-log-retention-days 30
 *   php serverctl.php maintenance-on
 *   php serverctl.php maintenance-off
 *
 * Optional overrides:
 *   php serverctl.php status --host=127.0.0.1 --port=37991 --token=your-token
 *
 * Interactive shell:
 *   Run without a command to open an authenticated console session.
 */

if (PHP_SAPI !== 'cli')
{
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$rootDir = __DIR__;
$configPath = $rootDir . '/config/config.php';
if (!is_file($configPath))
{
    fwrite(STDERR, "Missing configuration file: {$configPath}\n");
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
SettingsManager::init($config);
$config = SettingsManager::getConfig();

/**
 * Print command usage information.
 *
 * @return void
 */
function serverctl_print_usage(): void
{
    $usage = <<<TXT
Usage:
  php serverctl.php
  php serverctl.php <command> [arguments] [--host=IP] [--port=PORT] [--token=TOKEN]

Commands:
  status
  pause
  resume
  run-cleanup-now
  enable-job <job>
  disable-job <job>
  set-job <job> <0|1>
  set-verbose <0|1>
  set-tick-interval <seconds>
  set-log-retention-days <days>
  maintenance-on
  maintenance-off
  set-maintenance-mode <0|1>
  reload-defaults

Interactive shell commands:
  help
  status
  pause
  resume
  run-cleanup-now
  enable-job <job>
  disable-job <job>
  set-job <job> <0|1>
  set-verbose <0|1>
  set-tick-interval <seconds>
  set-log-retention-days <days>
  maintenance-on
  maintenance-off
  set-maintenance-mode <0|1>
  reload-defaults
  quit
  exit

Examples:
  php serverctl.php
  php serverctl.php status
  php serverctl.php enable-job image_cache
  php serverctl.php disable-job security_logs
  php serverctl.php set-job live_chat 1
  php serverctl.php set-verbose 1
  php serverctl.php set-tick-interval 2
  php serverctl.php set-log-retention-days 30
  php serverctl.php maintenance-on
  php serverctl.php maintenance-off
TXT;

    fwrite(STDOUT, $usage . "\n");
}

/**
 * Convert common user input values into a boolean state.
 *
 * @param string $value Raw value
 * @return bool Normalized boolean state
 */
function serverctl_to_bool(string $value): bool
{
    $value = strtolower(trim($value));
    return in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true);
}

/**
 * Read one line of input from the console.
 *
 * @param string $prompt Prompt text
 * @param bool $allowEmpty Whether an empty value should be accepted
 * @return string Entered value
 */
function serverctl_read_line(string $prompt, bool $allowEmpty = false): string
{
    do
    {
        fwrite(STDOUT, $prompt);
        $line = fgets(STDIN);
        if ($line === false)
        {
            return '';
        }

        $value = trim($line);
        if ($allowEmpty || $value !== '')
        {
            return $value;
        }
    } while (true);
}

/**
 * Read a sensitive value from the console without echoing when supported.
 *
 * @param string $prompt Prompt text
 * @return string Entered value
 */
function serverctl_read_secret(string $prompt): string
{
    if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec'))
    {
        fwrite(STDOUT, $prompt);
        @shell_exec('stty -echo');
        $line = fgets(STDIN);
        @shell_exec('stty echo');
        fwrite(STDOUT, "\n");

        return $line !== false ? trim($line) : '';
    }

    return serverctl_read_line($prompt);
}

/**
 * Send one control payload to the maintenance server socket.
 *
 * @param string $host Control server host
 * @param int $port Control server port
 * @param array $payload JSON payload to transmit
 * @return array<string, mixed> Decoded response payload
 */
function serverctl_send_command(string $host, int $port, array $payload): array
{
    $endpoint = 'tcp://' . $host . ':' . $port;
    $errno = 0;
    $errstr = '';

    $client = @stream_socket_client($endpoint, $errno, $errstr, 3);
    if ($client === false)
    {
        return [
            'ok' => false,
            'message' => 'Unable to connect to maintenance control socket: ' . $errstr,
        ];
    }

    stream_set_timeout($client, 3);
    fwrite($client, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n");
    $line = fgets($client, 65535);
    fclose($client);

    if ($line === false)
    {
        return [
            'ok' => false,
            'message' => 'No response received from maintenance control socket.',
        ];
    }

    $response = json_decode(trim($line), true);
    if (!is_array($response))
    {
        return [
            'ok' => false,
            'message' => 'Invalid response received from maintenance control socket.',
        ];
    }

    return $response;
}

/**
 * Build a maintenance server control payload from parsed command arguments.
 *
 * @param array<int, string> $positionals Command arguments
 * @param string $token Control authorization token
 * @return array<string, mixed>|null Payload on success; otherwise null
 */
function serverctl_build_payload(array $positionals, string $token): ?array
{
    if (empty($positionals))
    {
        return null;
    }

    $command = strtolower(trim((string)($positionals[0] ?? 'status')));
    $payload = [
        'token' => $token,
        'command' => 'status',
    ];

    switch ($command)
    {
        case 'status':
            $payload['command'] = 'status';
            break;

        case 'pause':
            $payload['command'] = 'pause';
            break;

        case 'resume':
            $payload['command'] = 'resume';
            break;

        case 'run-cleanup-now':
        case 'run_cleanup_now':
            $payload['command'] = 'run_cleanup_now';
            break;

        case 'enable-job':
        case 'enable_job':
            $job = trim((string)($positionals[1] ?? ''));
            if ($job === '')
            {
                fwrite(STDERR, "Missing job name.\n");
                return null;
            }

            $payload['command'] = 'enable_job';
            $payload['job'] = $job;
            break;

        case 'disable-job':
        case 'disable_job':
            $job = trim((string)($positionals[1] ?? ''));
            if ($job === '')
            {
                fwrite(STDERR, "Missing job name.\n");
                return null;
            }

            $payload['command'] = 'disable_job';
            $payload['job'] = $job;
            break;

        case 'set-job':
        case 'set_job':
            $job = trim((string)($positionals[1] ?? ''));
            $enabled = trim((string)($positionals[2] ?? ''));
            if ($job === '' || $enabled === '')
            {
                fwrite(STDERR, "Missing job name or enabled value.\n");
                return null;
            }

            $payload['command'] = 'set_job';
            $payload['job'] = $job;
            $payload['enabled'] = serverctl_to_bool($enabled);
            break;

        case 'set-verbose':
        case 'set_verbose':
            $enabled = trim((string)($positionals[1] ?? ''));
            if ($enabled === '')
            {
                fwrite(STDERR, "Missing verbose enabled value.\n");
                return null;
            }

            $payload['command'] = 'set_verbose';
            $payload['enabled'] = serverctl_to_bool($enabled);
            break;

        case 'set-tick-interval':
        case 'set_tick_interval':
            $seconds = (int)($positionals[1] ?? 0);
            if ($seconds <= 0)
            {
                fwrite(STDERR, "Missing or invalid tick interval.\n");
                return null;
            }

            $payload['command'] = 'set_tick_interval';
            $payload['seconds'] = $seconds;
            break;

        case 'set-log-retention-days':
        case 'set_log_retention_days':
            $days = (int)($positionals[1] ?? 0);
            if ($days <= 0)
            {
                fwrite(STDERR, "Missing or invalid retention days.\n");
                return null;
            }

            $payload['command'] = 'set_log_retention_days';
            $payload['days'] = $days;
            break;

        case 'maintenance-on':
        case 'maintenance_on':
            $payload['command'] = 'maintenance_on';
            break;

        case 'maintenance-off':
        case 'maintenance_off':
            $payload['command'] = 'maintenance_off';
            break;

        case 'set-maintenance-mode':
        case 'set_maintenance_mode':
            $enabled = trim((string)($positionals[1] ?? ''));
            if ($enabled === '')
            {
                fwrite(STDERR, "Missing maintenance mode enabled value.\n");
                return null;
            }

            $payload['command'] = 'set_maintenance_mode';
            $payload['enabled'] = serverctl_to_bool($enabled);
            break;

        case 'reload-defaults':
        case 'reload_defaults':
            $payload['command'] = 'reload_defaults';
            break;

        default:
            fwrite(STDERR, "Unknown command: {$command}\n");
            return null;
    }

    return $payload;
}

/**
 * Convert a boolean-like value into a human-readable enabled/disabled string.
 *
 * @param mixed $value Raw value
 * @return string Human-readable value
 */
function serverctl_format_bool(mixed $value): string
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
function serverctl_print_row(string $label, string $value, int $labelWidth = 22): void
{
    fwrite(STDOUT, '  ' . str_pad($label . ':', $labelWidth, ' ', STR_PAD_RIGHT) . $value . "\n");
}

/**
 * Print aligned job states to the console.
 *
 * @param array<string, mixed> $jobs Job state map
 * @return void
 */
function serverctl_print_jobs(array $jobs): void
{
    fwrite(STDOUT, "  Jobs:\n");

    foreach ($jobs as $job => $enabled)
    {
        serverctl_print_row((string)$job, serverctl_format_bool($enabled), 24);
    }
}

/**
 * Print a formatted maintenance control response payload.
 *
 * @param array<string, mixed> $response Control response payload
 * @return void
 */
function serverctl_print_response(array $response): void
{
    $ok = !empty($response['ok']);
    $message = trim((string)($response['message'] ?? ''));

    fwrite(STDOUT, "\n");
    fwrite(STDOUT, $ok ? "[OK]\n" : "[ERROR]\n");

    if ($message !== '')
    {
        serverctl_print_row('Message', $message);
    }

    $state = $response['state'] ?? null;
    if (!is_array($state))
    {
        fwrite(STDOUT, "\n");
        return;
    }

    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "  Runtime State\n");
    fwrite(STDOUT, "  -------------\n");

    serverctl_print_row('Paused', serverctl_format_bool($state['paused'] ?? false));
    serverctl_print_row('Maintenance Mode', serverctl_format_bool($state['maintenance_mode'] ?? false));
    serverctl_print_row('Verbose Logging', serverctl_format_bool($state['verbose_logging'] ?? false));
    serverctl_print_row('Tick Interval', (string)((int)($state['tick_interval_seconds'] ?? 0)) . ' second(s)');
    serverctl_print_row('Log Retention', (string)((int)($state['log_retention_days'] ?? 0)) . ' day(s)');
    serverctl_print_row('Run Cleanup Now', serverctl_format_bool($state['run_cleanup_now'] ?? false));

    if (!empty($state['updated_at']))
    {
        serverctl_print_row('Updated At', (string)$state['updated_at']);
    }

    $jobs = $state['jobs'] ?? [];
    if (is_array($jobs) && !empty($jobs))
    {
        fwrite(STDOUT, "\n");
        serverctl_print_jobs($jobs);
    }

    fwrite(STDOUT, "\n");
}

/**
 * Run the interactive maintenance control shell.
 *
 * @param string $host Control server host
 * @param int $port Control server port
 * @param string $token Control authorization token
 * @return int Process exit code
 */
function serverctl_run_interactive_shell(string $host, int $port, string $token): int
{
    fwrite(STDOUT, "Interactive maintenance control shell\n");
    fwrite(STDOUT, "Connected target: {$host}:{$port}\n");

    if ($token === '')
    {
        $token = serverctl_read_secret('Token: ');
    }
    else
    {
        $reuseToken = serverctl_read_line('Use configured token for this session? [Y/n]: ', true);
        if ($reuseToken !== '' && in_array(strtolower($reuseToken), ['n', 'no'], true))
        {
            $token = serverctl_read_secret('Token: ');
        }
    }

    if ($token === '')
    {
        fwrite(STDERR, "Missing maintenance control auth token.\n");
        return 1;
    }

    $authResponse = serverctl_send_command($host, $port, [
        'token' => $token,
        'command' => 'status',
    ]);

    if (empty($authResponse['ok']))
    {
        fwrite(STDERR, "Login failed: " . (string)($authResponse['message'] ?? 'Unknown error.') . "\n");
        return 1;
    }

    fwrite(STDOUT, "Login successful.\n");
    fwrite(STDOUT, "Type 'help' for a list of commands.\n\n");

    while (true)
    {
        $line = serverctl_read_line('serverctl> ', true);
        if ($line === '')
        {
            continue;
        }

        $command = strtolower(trim($line));
        if (in_array($command, ['quit', 'exit', 'logout'], true))
        {
            fwrite(STDOUT, "Goodbye.\n");
            return 0;
        }

        if ($command === 'help')
        {
            serverctl_print_usage();
            continue;
        }

        $positionals = preg_split('/\s+/', trim($line));
        if (!is_array($positionals) || empty($positionals))
        {
            continue;
        }

        $payload = serverctl_build_payload($positionals, $token);
        if ($payload === null)
        {
            fwrite(STDOUT, "Type 'help' for usage.\n");
            continue;
        }

        $response = serverctl_send_command($host, $port, $payload);
        serverctl_print_response($response);
    }
}

$args = array_slice($argv, 1);
$host = MaintenanceServer::controlBindAddress($config);
$port = MaintenanceServer::controlPort($config);
$token = MaintenanceServer::controlAuthToken($config);

$positionals = [];
foreach ($args as $arg)
{
    if (str_starts_with($arg, '--host='))
    {
        $value = trim(substr($arg, strlen('--host=')));
        if ($value !== '')
        {
            $host = $value;
        }

        continue;
    }

    if (str_starts_with($arg, '--port='))
    {
        $value = (int)substr($arg, strlen('--port='));
        if ($value > 0)
        {
            $port = $value;
        }

        continue;
    }

    if (str_starts_with($arg, '--token='))
    {
        $token = trim(substr($arg, strlen('--token=')));
        continue;
    }

    $positionals[] = $arg;
}

if (!empty($positionals) && in_array($positionals[0], ['help', '--help', '-h'], true))
{
    serverctl_print_usage();
    exit(0);
}

if (empty($positionals))
{
    exit(serverctl_run_interactive_shell($host, $port, $token));
}

$payload = serverctl_build_payload($positionals, $token);
if ($payload === null)
{
    serverctl_print_usage();
    exit(1);
}

if ($token === '')
{
    fwrite(STDERR, "Missing maintenance control auth token in config or --token override.\n");
    exit(1);
}

$response = serverctl_send_command($host, $port, $payload);
serverctl_print_response($response);
exit(!empty($response['ok']) ? 0 : 1);
