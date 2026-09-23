<?php

declare(strict_types=1);

use PhpMiniDatabase\Cli\ServerApplication;

require __DIR__ . '/../vendor/autoload.php';

/*
 * The platform's face to the php-mini-database server process.
 *
 * The component ships its own bin/minidb-server, but that script requires
 * __DIR__.'/../vendor/autoload.php' - which resolves to the component's own
 * vendor directory and breaks the moment the component is consumed as a
 * Composer dependency. This entry is the whole workaround: it boots the
 * platform's autoloader and hands the arguments to the component's server
 * application, exactly as its own bin script would have. Everything the DB
 * process does is the component's; this file only points at it.
 */

exit(new ServerApplication()->run(array_slice($argv ?? [], 1)));
