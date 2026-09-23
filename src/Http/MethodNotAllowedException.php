<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Http;

use RuntimeException;

/**
 * The request path is served, but not under the requested method. Carries
 * the allowed methods so the boundary can emit a correct Allow header with
 * the 405 response.
 */
final class MethodNotAllowedException extends RuntimeException
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(
        public readonly array $allowed,
    ) {
        parent::__construct(sprintf('Method not allowed; allowed: %s', implode(', ', $allowed)));
    }
}
