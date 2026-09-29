<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Http;

/**
 * A parsed request in the platform's own vocabulary, built once by the
 * Application from the component's HttpRequest. Immutable and detached from
 * the connection - a handler cannot write anything back through it.
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
