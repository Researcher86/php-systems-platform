<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Http;

use Closure;

/**
 * One compiled pattern route: the registered path, its handler, and the
 * regex the router dispatches on. Only the Router constructs it.
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
     * A {name} placeholder becomes a named group matching one path segment;
     * every literal run between placeholders goes through preg_quote(), so
     * `/hea.th` matches only itself and regex punctuation in a path is just
     * part of the path.
     */
    public static function compile(string $path, Closure $handler): self
    {
        $pattern = '';

        foreach (preg_split('/(\{\w+\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [''] as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^\{\w+\}$/', $part) === 1) {
                $pattern .= '(?P<' . substr($part, 1, -1) . '>[^/]+)';

                continue;
            }

            $pattern .= preg_quote($part, '#');
        }

        return new self($path, $handler, '#^' . $pattern . '$#');
    }
}
