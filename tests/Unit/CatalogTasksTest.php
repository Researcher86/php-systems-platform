<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpSystemsPlatform\Workers\CatalogTasks;
use PHPUnit\Framework\TestCase;
use PhpWorkerPool\Protocol\Request;

/**
 * The worker side of the concurrent snapshot load. The happy path needs a
 * running database and is covered in ServeIntegrationTest; what matters here
 * is that an unusable request never reaches one: a worker answers it as an
 * application error, so the pool keeps the process and the caller sees a
 * failed part instead of a crashed worker.
 */
final class CatalogTasksTest extends TestCase
{
    public function testUnknownActionIsAnError(): void
    {
        $response = $this->tasks()(new Request('catalog.unknown', []));

        self::assertFalse($response->successful);
        self::assertSame('unknown_action', $response->payload['error']);
    }

    public function testAPartWithoutItsKeyIsRejectedBeforeAnyDatabaseWork(): void
    {
        $response = $this->tasks()(new Request('catalog.customer', []));

        self::assertFalse($response->successful);
        self::assertSame('bad_params', $response->payload['error']);
    }

    public function testAnOutOfRangeSimulatedLatencyIsRejected(): void
    {
        $response = $this->tasks()(new Request('catalog.product', [
            'sku' => 'SKU-STANDARD',
            'delay_ms' => 10_000,
        ]));

        self::assertFalse($response->successful);
        self::assertSame('bad_params', $response->payload['error']);
    }

    /**
     * Tasks pointed at a port nothing listens on: every request under test
     * must be refused before a connection is ever attempted.
     *
     * @return \Closure(Request): \PhpWorkerPool\Protocol\Response
     */
    private function tasks(): \Closure
    {
        return new CatalogTasks([
            'host' => '127.0.0.1',
            'port' => 1,
            'timeout' => 0.01,
        ])->handler();
    }
}
