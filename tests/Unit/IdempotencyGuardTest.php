<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpJobQueue\Idempotency\IdempotencyGuard;
use PhpJobQueue\Persistence\FileStorage;
use PHPUnit\Framework\TestCase;

/**
 * The platform's side of PLAN Step 20 in isolation: the commit-chain of
 * operations the queue-side guard reads when a redelivery arrives. No server,
 * no HTTP - a scratch JSONL store and the same two classes the JobExecutor
 * wires, so "a fresh guard over the same store" models "a fresh worker after
 * a restart" with nothing else in the way.
 */
final class IdempotencyGuardTest extends TestCase
{
    private string $storePath;

    protected function setUp(): void
    {
        $this->storePath = tempnam(sys_get_temp_dir(), 'idempotency-test-');
        @unlink($this->storePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->storePath);
    }

    public function testRecordedOperationSurvivesARestart(): void
    {
        // The first worker's lifetime: the settle happened and the key was
        // recorded before it died.
        $first = new IdempotencyGuard(new FileStorage($this->storePath));
        $first->markProcessed('order.process:1001');
        self::assertTrue($first->isProcessed('order.process:1001'));

        // A fresh guard over the same store is a fresh worker (or a restarted
        // one) reading the append-only journal - the operation is still done.
        $restarted = new IdempotencyGuard(new FileStorage($this->storePath));
        self::assertTrue($restarted->isProcessed('order.process:1001'));
        self::assertSame(['order.process:1001'], $restarted->getProcessedKeys());
    }

    public function testOnlyRecordedOperationsAreProcessed(): void
    {
        $guard = new IdempotencyGuard(new FileStorage($this->storePath));
        $guard->markProcessed('order.process:2001');

        self::assertFalse($guard->isProcessed('order.process:2002'));
        self::assertFalse($guard->isProcessed('order.created:2001'));
    }

    public function testUnrelatedJournalRecordsAreNotReadAsOperations(): void
    {
        // The idempotency store shares FileStorage's JSONL contract with the
        // queue journal itself; records of another kind must stay invisible.
        file_put_contents(
            $this->storePath,
            json_encode(['key' => 'order.process:3001', 'data' => ['kind' => 'queue', 'state' => 'COMPLETED']], JSON_THROW_ON_ERROR) . "\n",
            FILE_APPEND,
        );

        $guard = new IdempotencyGuard(new FileStorage($this->storePath));

        self::assertFalse($guard->isProcessed('order.process:3001'));
        self::assertSame([], $guard->getProcessedKeys());
    }
}
