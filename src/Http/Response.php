<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Http;

use RuntimeException;

/**
 * A response in the platform's own vocabulary; the Application converts it
 * into the component's HttpResponse. The factories set Content-Type and
 * Content-Length, so a handler cannot produce a body without saying what
 * it is.
 */
final readonly class Response
{
    /**
     * @param array<string, string> $headers header name => value
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new RuntimeException(sprintf('Cannot encode response body as JSON: %s', json_last_error_msg()));
        }

        return self::body($body, 'application/json; charset=utf-8', $status, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function text(string $body, int $status = 200, array $headers = []): self
    {
        return self::body($body, 'text/plain; charset=utf-8', $status, $headers);
    }

    /**
     * A response with no body at all - status only. No Content-Type is
     * claimed, mirroring the component's own empty() factory.
     *
     * @param array<string, string> $headers
     */
    public static function empty(int $status = 204, array $headers = []): self
    {
        $headers['Content-Length'] = '0';

        return new self($status, $headers, '');
    }

    /** A header's value by case-insensitive name, or null when absent. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $existing => $value) {
            if (strcasecmp($existing, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function body(string $body, string $contentType, int $status, array $headers): self
    {
        $headers['Content-Type'] = $contentType;
        $headers['Content-Length'] = (string) strlen($body);

        return new self($status, $headers, $body);
    }
}
