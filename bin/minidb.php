<?php

declare(strict_types=1);

use PhpMiniDatabase\Cli\ServerApplication;

require __DIR__ . '/../vendor/autoload.php';

/*
 * Entry point for the php-mini-database server: boots the platform's
 * autoloader and hands the arguments to the component's own application.
 * It was written because the component's bin/minidb-server used to load the
 * component's own vendor/autoload.php, which does not exist once it is
 * installed as a dependency. v1.0.0 fixed that (its bin/bootstrap.php walks
 * up to the consuming project's autoloader); this entry stays as the one
 * stable path the platform spawns, running exactly the same application.
 */

exit(new ServerApplication()->run(array_slice($argv ?? [], 1)));
