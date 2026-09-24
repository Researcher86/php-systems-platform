<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpSystemsPlatform\Workers\WorkerFailureTasks;
use PHPUnit\Framework\TestCase;
use PhpWorkerPool\Protocol\Request;

/**
 * The pool-side half of PLAN Step 22, upside down: the armed crash itself
 * must NOT be unit tested here, because it SIGKILLs the process answering it
 * - a unit test that ran it would kill the very PHPUnit process it runs in.
 * The kill-and-replace sequence is therefore exercised not through this
 * class but through the real pool in ServeIntegrationTest, where a crashed
 * test worker is a fixture, not the test runner. What stays here is the half
 * that must not ever have to reach a signal: the door can be refused.
 */
final class WorkerFailureTasksTest extends TestCase
{
    public function testUnknownWorkerActionIsAnError(): void
    {
        $response = new WorkerFailureTasks(true)->handler()(new Request('worker.unknown', []));

        self::assertFalse($response->successful);
        self::assertSame('unknown_action', $response->payload['error']);
    }

    public function testCrashRefusedWhenInjectionIsDisabled(): void
    {
        $response = new WorkerFailureTasks(false)->handler()(new Request('worker.crash', []));

        self::assertFalse($response->successful);
        self::assertSame('failure_injection_disabled', $response->payload['error']);
    }
}
