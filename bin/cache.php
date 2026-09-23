<?php

declare(strict_types=1);

use PhpMiniCache\Logging\ConsoleLogger;
use PhpMiniCache\Server\CacheServer;
use PhpMiniCache\Server\ServerConfig;

require __DIR__ . '/../vendor/autoload.php';

/*
 * The platform's face to the php-mini-cache server process.
 *
 * Same story as bin/minidb: the component's own bin/server.php requires
 * __DIR__.'/../vendor/autoload.php', which resolves to the component's own
 * vendor directory and breaks once it is consumed as a Composer dependency.
 * This entry boots the platform's autoloader and runs the component's server
 * with the same defaults - CACHE_HOST / CACHE_PORT - the component's script
 * would have used. Unlike the database, the cache has no daemon mode: this
 * is a foreground process owned by "php bin/platform serve", which spawns
 * it, waits for its port, and terminates it on shutdown. Everything the cache
 * does is the component's; this file only points at it.
 *
 * A CACHE_SNAPSHOT path enables persistence: the server loads it on boot,
 * writes a periodic snapshot, and saves a final one on SIGTERM, so a planned
 * restart keeps the entries written since the last snapshot.
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
