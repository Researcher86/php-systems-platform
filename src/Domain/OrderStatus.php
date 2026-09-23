<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Domain;

/**
 * The lifecycle of an order as the platform's synchronous and background
 * work walks it: created on POST, moved to processing by the first queued
 * job, then to completed or cancelled by whatever job finishes or aborts it.
 * Stored as the lowercase string, which is exactly what the API and the
 * demo speak.
 */
enum OrderStatus: string
{
    case CREATED = 'created';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
