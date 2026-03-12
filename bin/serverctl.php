<?php

require __DIR__ . '/../bootstrap/app.php';

/**
 * Backward-compatible wrapper for the merged maintenance control CLI.
 *
 * The control client now lives inside server.php so the maintenance server and
 * CLI entry point share one implementation.
 */

if (PHP_SAPI !== 'cli')
{
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

exit(ControlServer::forwardServerCommand(array_slice($argv, 1), __DIR__ . '/server.php'));
