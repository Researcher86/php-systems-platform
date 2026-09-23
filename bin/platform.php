<?php

declare(strict_types=1);

use PhpSystemsPlatform\Cli\PlatformCli;

require __DIR__ . '/../vendor/autoload.php';

exit(new PlatformCli()->run($argv ?? []));
