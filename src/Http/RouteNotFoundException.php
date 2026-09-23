<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Http;

use RuntimeException;

/**
 * No route matches the request path at all - the answer is 404.
 */
final class RouteNotFoundException extends RuntimeException
{
}
