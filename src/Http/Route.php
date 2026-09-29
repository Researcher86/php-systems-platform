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
     * A {name} placeholder becomes a named group matching one path segment;
     * everything else is matched literally.
     *
     * "Literally" is the part that has to be earned. The literal segments used
     * to be pasted into the pattern unescaped, so a registered path was also a
     * piece of regex: `/health` and `/hea.th` compiled to the same pattern,
     * and a path carrying a `+` or a `(` would not compile at all - preg would
     * reject the pattern, or worse, match something the author never wrote.
     * Splitting on the placeholder and quoting each literal run with
     * preg_quote() makes the pattern mean what the path says: `/hea.th` now
     * matches only that path, and a path with regex punctuation in it is
     * simply a path.
     */
    public static function compile(string $path, Closure $handler): self
    {
        $pattern = '';

        // Split with the placeholders kept, so the literal runs between them
        // can each be quoted on their own.
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
