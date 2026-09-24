<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

/**
 * PLAN Step 19's "do not retry every possible error", the half of the
 * question a job type can answer cheaply, without any I/O: is this payload
 * even shaped like something that could work?
 *
 * A job class implements this to opt into a pre-flight check, run before it
 * is ever dispatched to a worker (see ValidatingQueue). It deliberately
 * cannot see a database or a cache - only the payload - which is what keeps
 * the check cheap enough to run on every pop() and honest about what it can
 * actually rule out: a missing field, never "does this row exist right now".
 * The latter needs I/O to answer and can genuinely be transient in a
 * different topology, so it stays on the normal retry path.
 */
interface ValidatesPayload
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return non-empty-string|null a reason this payload can never succeed,
     *                               or null if it might
     */
    public static function validate(array $payload): ?string;
}
