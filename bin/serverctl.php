<?php

/**
 * Backward-compatible wrapper for the merged maintenance control CLI.
 *
 * The control client now lives inside server.php so the maintenance server and
 * CLI entry point share one implementation.
 */

if (PHP_SAPI !== 'cli')
{
    fwrite(STDERR, "This script must be run from the command line.
");
    exit(1);
}

$args = array_slice($argv, 1);
$command = array_merge([PHP_BINARY, __DIR__ . '/server.php'], $args);

$process = proc_open(
    $command,
    [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ],
    $pipes,
    __DIR__
);

if (!is_resource($process))
{
    fwrite(STDERR, "Unable to forward command to server.php.
");
    exit(1);
}

exit(proc_close($process));
