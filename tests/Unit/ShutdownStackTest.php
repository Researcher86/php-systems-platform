<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use LogicException;
use PhpSystemsPlatform\Support\ShutdownStack;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The ownership record every long-running command uses, on its own.
 *
 * The properties that matter are the ones the copy-pasted cleanup in serve()
 * and queue:consume kept getting wrong: newest step first, a throwing step
 * does not skip the ones behind it, and running twice is not running twice.
 * Each of those is a whole process leaking or being stopped twice, so they are
 * pinned here rather than left to be re-derived from reading the call site.
 */
final class ShutdownStackTest extends TestCase
{
    public function testReleasesInReverseOrderOfAcquisition(): void
    {
        $order = [];
        $stack = new ShutdownStack('test');

        $stack->push(static function () use (&$order): void {
            $order[] = 'database';
        }, 'database');
        $stack->push(static function () use (&$order): void {
            $order[] = 'cache';
        }, 'cache');
        $stack->push(static function () use (&$order): void {
            $order[] = 'pool';
        }, 'pool');

        $stack->run();

        self::assertSame(['pool', 'cache', 'database'], $order);
    }

    public function testRunningTwiceDoesNotReleaseTwice(): void
    {
        $releases = 0;
        $stack = new ShutdownStack('test');
        $stack->push(static function () use (&$releases): void {
            ++$releases;
        }, 'cache server');

        $stack->run();
        $stack->run();

        self::assertSame(1, $releases);
    }

    /**
     * The failure mode the class exists for: a cleanup that gives up halfway.
     * A pool that refuses to stop must not leave the cache server and the
     * database connection running - those are exactly the two steps behind it
     * in the stack.
     */
    public function testAThrowingStepDoesNotSkipTheOnesBehindIt(): void
    {
        $releases = [];
        $errors = fopen('php://memory', 'w+');
        $stack = new ShutdownStack('test', $errors);

        $stack->push(static function () use (&$releases): void {
            $releases[] = 'database';
        }, 'database');
        $stack->push(static function () use (&$releases): void {
            $releases[] = 'cache';
        }, 'cache');
        $stack->push(static function (): void {
            throw new RuntimeException('pool refused to stop');
        }, 'worker pool');

        $stack->run();

        self::assertSame(['cache', 'database'], $releases);
        rewind($errors);
        self::assertSame(
            "[test] could not release the worker pool: pool refused to stop\n",
            stream_get_contents($errors),
        );
    }

    public function testRunIsIdempotentAfterATruncatedRun(): void
    {
        $releases = [];
        $errors = fopen('php://memory', 'w+');
        $stack = new ShutdownStack('test', $errors);
        $stack->push(static function () use (&$releases): void {
            $releases[] = 'database';
        }, 'database');
        $stack->push(static function (): void {
            throw new RuntimeException('cache refused to stop');
        }, 'cache server');

        $stack->run();
        $stack->run();

        self::assertSame(['database'], $releases);
        rewind($errors);
        self::assertSame(
            "[test] could not release the cache server: cache refused to stop\n",
            stream_get_contents($errors),
            'the failure is reported once, not once per run()',
        );
    }

    public function testRegisteringAfterRunIsRefused(): void
    {
        $stack = new ShutdownStack('test');
        $stack->run();

        $this->expectException(LogicException::class);

        $stack->push(static function (): void {
        }, 'too late');
    }
}
