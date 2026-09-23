<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Http;

use Closure;

/**
 * One compiled route: the path pattern it was registered with, the handler
 * that answers it, and the regex the router dispatches on.
 *
 * Lives in its own file for PSR-4 autoloading; nothing outside the router
 * ever constructs it.
 */
final readonly class Route
{
    private function __construct(
        public string $path,
        public Closure $handler,
        public string $regex,
    ) {
    }

    /**
     * A {name} placeholder becomes a named group matching one path segment.
     */
    public static function compile(string $path, Closure $handler): self
    {
        $regex = '#^' . preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $path) . '$#';

        return new self($path, $handler, $regex);
    }
}
