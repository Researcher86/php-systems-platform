<?php

declare(strict_types=1);

use PhpMiniCache\Logging\ConsoleLogger;
use PhpMiniCache\Server\CacheServer;
use PhpMiniCache\Server\ServerConfig;

require __DIR__ . '/../vendor/autoload.php';

/*
 * Entry point for the php-mini-cache server: boots the platform's autoloader
 * and runs the component's server with the same CACHE_HOST / CACHE_PORT
 * defaults as the component's own bin/server.php, plus the CACHE_SNAPSHOT
 * persistence that script does not offer. It runs in the foreground, owned
 * by `serve`.
 *
 * CACHE_SNAPSHOT enables persistence: loaded on boot, saved every 30s and
 * once more on SIGTERM.
 */

$host = getenv('CACHE_HOST') ?: '127.0.0.1';
$port = (int) (getenv('CACHE_PORT') ?: 6380);
$snapshot = getenv('CACHE_SNAPSHOT') ?: null;

$server = new CacheServer(
    new ServerConfig(host: $host, port: $port),
    logger: new ConsoleLogger(),
    snapshotPath: $snapshot,
    snapshotIntervalSeconds: $snapshot === null ? null : 30.0,
);

$server->run();

exit(0);
