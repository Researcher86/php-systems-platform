<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Http;

/**
 * HTTP methods the platform's router routes on. Values are the
 * case-sensitive RFC 7230 wire tokens and the same cases as the component's
 * HttpMethod, so the Application maps one onto the other with a plain from().
 */
enum RequestMethod: string
{
    case GET = 'GET';
    case HEAD = 'HEAD';
    case POST = 'POST';
    case PUT = 'PUT';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';
    case OPTIONS = 'OPTIONS';
}
