<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

/**
 * PLAN Step 19's "do not retry every possible error": the half of the
 * question a job type can answer without I/O - is this payload even shaped
 * like something that could work?
 *
 * Nothing validates before dispatch. The job's own execute() fails on a bad
 * payload, and JobRegistry::shouldRetry() consults validate() to refuse a
 * retry that could never succeed, so one delivery is spent instead of the
 * whole attempts budget. The check sees only the payload, which keeps it
 * honest: a missing field, never "does this row exist right now" - that
 * needs I/O, may be transient, and stays on the normal retry path.
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
