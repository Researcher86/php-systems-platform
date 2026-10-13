<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Search;

use RuntimeException;

/**
 * php-mini-search could not answer: refused, timed out, a 5xx, or a 503 while
 * it waits for a full reindex. A job that hits this fails its attempt and the
 * queue retries it; a request that hits this answers 503.
 */
final class SearchUnavailable extends RuntimeException
{
}
