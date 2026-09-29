<?php

declare(strict_types=1);

use PhpMiniDatabase\Cli\ServerApplication;

require __DIR__ . '/../vendor/autoload.php';

/*
 * Entry point for the php-mini-database server. The component's own
 * bin/minidb-server loads the component's vendor/autoload.php, which does
 * not exist once it is installed as a dependency, so this boots the
 * platform's autoloader and hands the arguments to the same application.
 */

exit(new ServerApplication()->run(array_slice($argv ?? [], 1)));
