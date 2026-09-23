<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Http;

/**
 * A parsed request in the platform's own vocabulary.
 *
 * The component HTTP server hands the application its own HttpRequest value
 * object; the application converts it into this shape exactly once, at the
 * boundary, so controllers and the router never see the component's type.
 * Immutable, no live wire state - a handler has everything it needs and
 * nothing it can accidentally write back.
 */
final readonly class Request
{
    /**
     * @param array<string, mixed>   $query   query string parsed into key/value pairs
     * @param array<string, string>  $headers lowercased header name => value
     */
    public function __construct(
        public RequestMethod $method,
        public string $path,
        public array $query = [],
        public array $headers = [],
        public string $body = '',
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
