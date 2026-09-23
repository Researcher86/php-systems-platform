<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Http;

/**
 * HTTP methods the platform's router routes on.
 *
 * Values are the RFC 7230 wire tokens (case-sensitive, RFC says `get` is not
 * GET), so a request method read off the component request maps onto this
 * enum with a plain from(), and the router keys stay honest on the wire.
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
