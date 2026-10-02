<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpSystemsPlatform\Application\Handlers\ParallelHandler;
use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\RequestMethod;
use PHPUnit\Framework\TestCase;

/**
 * GET /parallel's input checks run before the pool is involved, so they are
 * pinned here without one; the fan-out itself is ServeIntegrationTest's.
 */
final class ParallelHandlerTest extends TestCase
{
    public function testMoreChunksThanIterationsIsABadRequest(): void
    {
        // 3 iterations over 4 chunks used to send three 0-iteration chunks,
        // which the worker refuses, so a well-formed request came back 206
        // "degraded" with no worker at fault.
        $response = new ParallelHandler()(new Request(RequestMethod::GET, '/parallel', ['work' => '3', 'split' => '4']), []);

        self::assertSame(400, $response->status);
        self::assertSame('{"error":"split must not exceed work."}', $response->body);
    }

    public function testEveryChunkGetsWorkWhenSplitEqualsWork(): void
    {
        // Valid input gets past validation to the pool check - here, the
        // missing pool's 503.
        $response = new ParallelHandler()(new Request(RequestMethod::GET, '/parallel', ['work' => '4', 'split' => '4']), []);

        self::assertSame(503, $response->status);
    }
}
